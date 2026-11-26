<?php
/**
 * Erinnerung (24 h vor dem Event) und Nachbefragung (24 h danach) über EINEN Sammel-Cron (ab 1.38.919, Nachtschicht 26.11.).
 *
 * Vorher plante jede Bestellung je Event zwei WP-Cron-Einzel-Events (tix_send_reminder_email / tix_send_followup_email).
 * Auf evendis lagen so 6.321 Einträge in der Autoload-Option `cron` (665 KB), die WordPress bei jedem Aufruf liest und
 * bei jeder Planung komplett neu schreibt.
 *
 * Jetzt:
 * - Cron `tix_event_mails_cron` alle 15 Minuten. Er sucht Events im Zeitfenster und deren bezahlte Bestellungen ohne
 *   Merker und ruft TIX_Emails::send_reminder()/send_followup() auf.
 * - Fenster wie bisher berechnet (Event-Beginn aus _tix_date_start/_tix_time_start, gleiche Uhr wie vorher):
 *   Erinnerung ab Beginn − 24 h bis zum Beginn, nur für Bestellungen von vor Beginn − 24 h (so wie vorher nur geplant
 *   wurde, wenn der Zeitpunkt noch kommt); Nachbefragung ab Beginn + 24 h, höchstens 72 h verspätet.
 * - Merker je Bestellung und Event in der Bestell-Meta (`_tix_evmail_r_<event>` / `_tix_evmail_f_<event>`, gleiche
 *   Ablage wie `_tix_completed_email_sent`), gesetzt per add_post_meta(unique) in send_reminder/send_followup selbst:
 *   jede Mail höchstens einmal, egal ob Sammel-Cron, alter Einzel-Event oder beides.
 * - Migration (einmal, Option tix_evmail_migrated): alle alten Einzel-Events löschen. Was gerade im Fenster liegt und
 *   KEIN offenes Einzel-Event mehr hatte, ist schon versandt (bzw. war nie vorgesehen) → Merker „migrated“, damit nichts
 *   doppelt kommt. Offene, schon fällige Einzel-Events bleiben ohne Merker → der Sammel-Cron versendet sie.
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_Mails {
    const CRON          = 'tix_event_mails_cron';
    const OPT_MIGRATED  = 'tix_evmail_migrated';
    const HOOKS         = ['r' => 'tix_send_reminder_email', 'f' => 'tix_send_followup_email'];
    const LEAD          = DAY_IN_SECONDS;      // Erinnerung 24 h vorher, Nachbefragung 24 h danach
    const FOLLOWUP_LATE = 3 * DAY_IN_SECONDS;  // Nachbefragung höchstens so spät
    const PER_RUN       = 200;                 // Mails je Lauf, Rest beim nächsten Lauf
    public static $now = null;                 // Tests

    public static function init() {
        add_filter('cron_schedules', function ($s) { $s['tix_15min'] = ['interval' => 900, 'display' => 'Alle 15 Minuten']; return $s; });
        add_action(self::CRON, [__CLASS__, 'run']);
        add_action('init', function () {
            if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 120, 'tix_15min', self::CRON);
        });
    }

    private static function now() { return self::$now !== null ? intval(self::$now) : time(); }

    public static function marker_key($kind, $event_id) {
        return '_tix_evmail_' . ($kind === 'f' ? 'f' : 'r') . '_' . intval($event_id);
    }

    /** Merker setzen, falls noch keiner da ist → true = jetzt senden, false = gab es schon. */
    public static function claim($order_id, $event_id, $kind, $by = 'sent') {
        return (bool) add_post_meta(intval($order_id), self::marker_key($kind, $event_id), $by . '@' . self::now(), true);
    }

    /** Event-Beginn wie bisher (strtotime auf Datum + Uhrzeit). */
    public static function event_time($event_id) {
        $d = (string) get_post_meta($event_id, '_tix_date_start', true);
        if ($d === '') return 0;
        $t = (string) get_post_meta($event_id, '_tix_time_start', true);
        return intval(strtotime($d . ($t !== '' ? ' ' . $t : ' 00:00')));
    }

    private static function settings() {
        $s = function_exists('tix_get_settings') ? (array) tix_get_settings() : [];
        return ['r' => !empty($s['email_reminder']), 'f' => !empty($s['email_followup'])];
    }

    /** Events, deren Fenster für $kind gerade offen ist → [event_id => event_time] */
    public static function events_in_window($kind) {
        $now = self::now();
        // grob per Datum vorfiltern, genau per Uhrzeit prüfen
        $from = gmdate('Y-m-d', $now - ($kind === 'f' ? self::LEAD + self::FOLLOWUP_LATE + DAY_IN_SECONDS : DAY_IN_SECONDS));
        $to   = gmdate('Y-m-d', $now + ($kind === 'f' ? 0 : 2 * DAY_IN_SECONDS));
        $ids = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'private'], 'posts_per_page' => 500, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => '_tix_date_start', 'value' => [$from, $to], 'compare' => 'BETWEEN', 'type' => 'DATE']]]);
        $out = [];
        foreach ($ids as $eid) {
            $t = self::event_time($eid);
            if (!$t) continue;
            if ($kind === 'r' && $now >= $t - self::LEAD && $now < $t) $out[intval($eid)] = $t;
            if ($kind === 'f' && $now >= $t + self::LEAD && $now < $t + self::LEAD + self::FOLLOWUP_LATE) $out[intval($eid)] = $t;
        }
        return $out;
    }

    /** Bezahlte Bestellungen eines Events ohne Merker, die für $kind in Frage kommen → [order_id] */
    public static function due_orders($kind, $event_id, $event_time, $limit = 1000) {
        global $wpdb;
        if (!class_exists('TIX_Order')) return [];
        $O = TIX_Order::table_name(); $I = TIX_Order::items_table_name();
        // Bestellzeit (Ortszeit-Wanduhr wie das Event-Datum): Erinnerung nur für Bestellungen von vor Beginn − 24 h,
        // Nachbefragung für alle bis Beginn + 24 h – genau wie vorher geplant wurde
        $cut = gmdate('Y-m-d H:i:s', $kind === 'f' ? $event_time + self::LEAD : $event_time - self::LEAD);
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT o.id FROM $O o JOIN $I i ON i.order_id = o.id
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = o.id AND m.meta_key = %s
             WHERE i.event_id = %d AND o.status IN ('completed','processing') AND o.date_created < %s AND m.meta_id IS NULL
             ORDER BY o.id LIMIT %d",
            self::marker_key($kind, $event_id), intval($event_id), $cut, intval($limit)
        )));
    }

    /** Sammel-Cron → Zahl der Versandversuche */
    public static function run() {
        if (class_exists('TIX_Shadow') && TIX_Shadow::active()) return 0;
        if (!get_option(self::OPT_MIGRATED)) self::migrate();
        if (!class_exists('TIX_Emails')) return 0;
        $on = self::settings();
        $left = self::PER_RUN;
        foreach (['r', 'f'] as $kind) {
            if (!$on[$kind]) continue;
            foreach (self::events_in_window($kind) as $eid => $t) {
                foreach (self::due_orders($kind, $eid, $t, $left) as $oid) {
                    if ($left <= 0) return self::PER_RUN;
                    $kind === 'r' ? TIX_Emails::send_reminder($oid, $eid) : TIX_Emails::send_followup($oid, $eid);
                    $left--;
                }
            }
        }
        return self::PER_RUN - $left;
    }

    /**
     * Alte Einzel-Events ablösen (einmal). → ['removed' => n, 'pending_due' => n, 'marked' => n]
     */
    public static function migrate() {
        update_option(self::OPT_MIGRATED, gmdate('c'), false); // zuerst: parallele Läufe nicht doppelt
        $pending = ['r' => [], 'f' => []];
        $removed = 0;
        foreach ((array) _get_cron_array() as $ts => $hooks) {
            foreach (self::HOOKS as $k => $hook) {
                foreach ((array) ($hooks[$hook] ?? []) as $ev) {
                    $a = (array) ($ev['args'] ?? []);
                    if (isset($a[0], $a[1])) $pending[$k][intval($a[0]) . '|' . intval($a[1])] = true;
                    $removed++;
                }
            }
        }
        foreach (self::HOOKS as $hook) {
            if (function_exists('wp_unschedule_hook')) wp_unschedule_hook($hook);
            if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions($hook);
        }
        // Gerade offenes Fenster: ohne offenen Einzel-Event = schon versandt bzw. nie vorgesehen → Merker
        $marked = 0; $due = 0;
        foreach (['r', 'f'] as $kind) {
            foreach (self::events_in_window($kind) as $eid => $t) {
                foreach (self::due_orders($kind, $eid, $t, 100000) as $oid) {
                    if (isset($pending[$kind][$oid . '|' . $eid])) { $due++; continue; }
                    if (self::claim($oid, $eid, $kind, 'migrated')) $marked++;
                }
            }
        }
        $r = ['removed' => $removed, 'pending_due' => $due, 'marked' => $marked];
        update_option(self::OPT_MIGRATED, gmdate('c') . ' ' . wp_json_encode($r), false);
        return $r;
    }
}
