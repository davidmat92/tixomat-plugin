<?php
/**
 * TIX_Notif_Schedule – Mitteilungen planen (1.38.657, User 2026-11-05: „die sollen planbar sein“).
 *
 * POST /notifications/broadcast mit `send_at` (ISO 8601 bzw. „JJJJ-MM-TTTHH:MM“ in Europe/Berlin, 2 Minuten bis 90 Tage
 * in der Zukunft) oder `relative_hours` (1–168 Stunden vor Event-Beginn, braucht event_id; folgt einer Verschiebung des
 * Events) legt die Mitteilung im Plan ab (Option tix_notif_scheduled, je Eintrag Kampagne `push-…` schon beim Planen).
 * Ein Cron (alle 5 Minuten) sendet fällige Einträge über TIX_Notifications::send_broadcast – mit dem Konto, das geplant
 * hat (Bereich, Absender). Status scheduled → sent | cancelled | failed.
 * REST: GET /notifications/scheduled · POST /notifications/scheduled/{id} (ändern) · …/{id}/cancel · …/{id}/send (jetzt).
 */
if (!defined('ABSPATH')) exit;

class TIX_Notif_Schedule {

    const NS = 'tixomat/v1';
    const OPT = 'tix_notif_scheduled';
    const CRON = 'tix_notif_schedule_cron';
    const MAX = 300;

    public static function init() {
        add_filter('cron_schedules', function ($s) { $s['tix_5min'] = ['interval' => 300, 'display' => 'Alle 5 Minuten (evendis)']; return $s; });
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 120, 'tix_5min', self::CRON); }, 54);
        add_action(self::CRON, [__CLASS__, 'run']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }
    private static function all() { $v = get_option(self::OPT, []); return is_array($v) ? $v : []; }
    private static function save(array $v) { update_option(self::OPT, array_slice(array_values($v), 0, self::MAX), false); }
    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }
    private static function my_org() { return self::multi() && !current_user_can('manage_options') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0; }

    /** Event-Beginn als Zeitstempel (0 = unbekannt) */
    private static function event_start($eid) {
        $d = (string) get_post_meta($eid, '_tix_date_start', true);
        if ($d === '') return 0;
        try { return (new DateTime($d . ' ' . ((string) get_post_meta($eid, '_tix_time_start', true) ?: '00:00'), wp_timezone()))->getTimestamp(); } catch (\Throwable $e) { return 0; }
    }

    /** Wann wird gesendet? (relativ: aus dem aktuellen Event-Beginn) */
    public static function due_at(array $it) {
        if (!empty($it['relative_hours']) && !empty($it['event_id'])) { $s = self::event_start(intval($it['event_id'])); return $s ? $s - intval($it['relative_hours']) * HOUR_IN_SECONDS : 0; }
        return intval($it['send_at'] ?? 0);
    }

    private static function parse_time($v) {
        $v = trim((string) $v);
        if ($v === '') return 0;
        try {
            $has_tz = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v);
            $d = $has_tz ? new DateTime($v) : new DateTime(str_replace('T', ' ', $v), wp_timezone());
            return $d->getTimestamp();
        } catch (\Throwable $e) { return -1; }
    }

    /** Eingaben prüfen → [send_at, relative_hours] | WP_Error */
    private static function timing($send_at, $rel, $event_id) {
        $rel = intval($rel);
        if ($rel > 0) {
            if ($rel > 168) return self::bad('relative_hours', 'Höchstens 168 Stunden (7 Tage) vor dem Event.');
            if (!$event_id) return self::bad('event_id', '„Stunden vor dem Event“ braucht ein Event.');
            $s = self::event_start($event_id);
            if (!$s) return self::bad('event_id', 'Das Event hat kein Datum.');
            if ($s - $rel * HOUR_IN_SECONDS < time() + 120) return self::bad('relative_hours', 'Dieser Zeitpunkt liegt schon in der Vergangenheit.');
            return [0, $rel];
        }
        $t = self::parse_time($send_at);
        if ($t < 0) return self::bad('send_at', 'Bitte Datum und Uhrzeit angeben (JJJJ-MM-TT HH:MM).');
        if ($t < time() + 120) return self::bad('send_at', 'Bitte einen Zeitpunkt mindestens 2 Minuten in der Zukunft wählen.');
        if ($t > time() + 90 * DAY_IN_SECONDS) return self::bad('send_at', 'Höchstens 90 Tage im Voraus.');
        return [$t, 0];
    }

    private static function check_event($event_id) {
        if (!$event_id) return null;
        if (get_post_type($event_id) !== 'event') return self::bad('event_id', 'Event nicht gefunden.', 'not_found', 404);
        if (self::multi() && class_exists('TIX_App_Scope') && TIX_App_Scope::scoped() && !TIX_App_Scope::event_allowed($event_id)) return TIX_App_Scope::deny('Kein Zugriff auf dieses Event.');
        return null;
    }

    public static function item(array $it) {
        $eid = intval($it['event_id'] ?? 0);
        $due = self::due_at($it);
        return ['id' => (string) $it['id'], 'title' => (string) $it['title'], 'body' => (string) $it['body'], 'target' => (string) ($it['target'] ?: 'all'),
            'event' => $eid ? ['id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8')] : null,
            'send_at' => $due ? wp_date('c', $due) : null, 'relative_hours' => intval($it['relative_hours'] ?? 0) ?: null, 'status' => (string) $it['status'],
            'campaign' => (string) $it['campaign'], 'created' => wp_date('c', intval($it['created'])), 'sender' => ['id' => intval($it['by']), 'name' => (string) ($it['by_name'] ?? '')],
            'sent_at' => !empty($it['sent_at']) ? wp_date('c', intval($it['sent_at'])) : null, 'recipients' => isset($it['recipients']) ? intval($it['recipients']) : null, 'error' => $it['error'] ?? null];
    }

    /** Aus POST /notifications/broadcast: planen */
    public static function create(array $p) {
        $eid = intval($p['event_id'] ?? 0);
        if ($e = self::check_event($eid)) return $e;
        $t = self::timing($p['send_at'] ?? '', $p['relative_hours'] ?? 0, $eid);
        if (is_wp_error($t)) return $t;
        $u = wp_get_current_user();
        $it = ['id' => 's_' . wp_generate_uuid4(), 'title' => (string) $p['title'], 'body' => (string) $p['body'], 'event_id' => $eid, 'target' => sanitize_key((string) ($p['target'] ?? '')), 'action' => (string) ($p['action'] ?? ''),
            'send_at' => $t[0], 'relative_hours' => $t[1], 'status' => 'scheduled', 'campaign' => TIX_Notifications::new_campaign(), 'created' => time(),
            'by' => intval($u->ID), 'by_name' => (string) ($u->display_name ?: $u->user_login), 'organizer_id' => self::multi() ? intval(TIX_App_Scope::organizer_id_for_user($u->ID)) : 0];
        $all = self::all();
        array_unshift($all, $it);
        self::save($all);
        return new WP_REST_Response(['ok' => true, 'scheduled' => self::item($it), 'campaign' => $it['campaign']], 201);
    }

    // ═══════════════ Versand ═══════════════

    /** Einen Eintrag senden (Cron bzw. „jetzt senden“) */
    private static function deliver(array &$it) {
        $prev = get_current_user_id();
        wp_set_current_user(intval($it['by']));
        $r = (class_exists('TIX_Features') && !TIX_Features::on('notifications'))
            ? new WP_Error('tix_feature_off', 'Mitteilungen sind ausgeschaltet.')
            : TIX_Notifications::send_broadcast((string) $it['title'], (string) $it['body'], intval($it['event_id']), (string) $it['target'], (string) $it['action'], (string) $it['campaign']);
        wp_set_current_user($prev);
        if (is_wp_error($r)) { $it['status'] = 'failed'; $it['error'] = $r->get_error_message(); return; }
        $d = $r instanceof WP_REST_Response ? $r->get_data() : (array) $r;
        $it['status'] = 'sent';
        $it['sent_at'] = time();
        $it['recipients'] = intval($d['recipients'] ?? 0);
        $it['error'] = null;
    }

    /** Cron: fällige Einträge senden (Sperre gegen doppelten Versand) */
    public static function run() {
        if (!add_option('tix_notif_sched_lock', time(), '', 'no')) {
            if (time() - intval(get_option('tix_notif_sched_lock')) < 240) return;
            update_option('tix_notif_sched_lock', time(), false);
        }
        $all = self::all();
        $n = 0;
        foreach ($all as &$it) {
            if (($it['status'] ?? '') !== 'scheduled') continue;
            $due = self::due_at($it);
            if (!$due || $due > time()) continue;
            // Event inzwischen vorbei bzw. gelöscht: nicht mehr senden
            if (!empty($it['event_id']) && get_post_status(intval($it['event_id'])) !== 'publish') { $it['status'] = 'failed'; $it['error'] = 'Das Event ist nicht mehr veröffentlicht.'; continue; }
            if ($due < time() - 6 * HOUR_IN_SECONDS) { $it['status'] = 'failed'; $it['error'] = 'Zu spät – der Zeitpunkt lag mehr als 6 Stunden zurück.'; continue; }
            self::deliver($it);
            if (++$n >= 20) break;
        }
        unset($it);
        self::save($all);
        delete_option('tix_notif_sched_lock');
        return $n;
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $perm = ['TIX_Notifications', 'check_log'];
        $r = function ($path, $m, $cb) use ($perm) { register_rest_route(self::NS, $path, ['methods' => $m, 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm]); };
        $r('/notifications/scheduled', 'GET', 'rest_list');
        $r('/notifications/scheduled/(?P<id>s_[a-f0-9-]{36})', 'POST', 'rest_update');
        $r('/notifications/scheduled/(?P<id>s_[a-f0-9-]{36})/cancel', 'POST', 'rest_cancel');
        $r('/notifications/scheduled/(?P<id>s_[a-f0-9-]{36})/send', 'POST', 'rest_send_now');
    }

    private static function visible(array $it) {
        if (!self::multi() || current_user_can('manage_options')) return true;
        $o = self::my_org();
        return $o && intval($it['organizer_id'] ?? 0) === $o;
    }

    private static function find_idx($id) {
        foreach (self::all() as $i => $it) if (($it['id'] ?? '') === $id && self::visible($it)) return $i;
        return -1;
    }

    /** GET /notifications/scheduled?status=scheduled|sent|cancelled|failed|all */
    public static function rest_list(WP_REST_Request $req) {
        $st = (string) ($req->get_param('status') ?: 'scheduled');
        $rows = [];
        foreach (self::all() as $it) {
            if (!self::visible($it) || ($st !== 'all' && ($it['status'] ?? '') !== $st)) continue;
            $rows[] = self::item($it);
        }
        if ($st === 'scheduled') usort($rows, function ($a, $b) { return strcmp((string) $a['send_at'], (string) $b['send_at']); });
        $r = rest_ensure_response(['ok' => true, 'scheduled' => $rows, 'timezone' => wp_timezone_string()]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** POST …/{id} {title?, body?, send_at?, relative_hours?, event_id?, target?} – nur geplante */
    public static function rest_update(WP_REST_Request $req) {
        $all = self::all();
        $i = self::find_idx((string) $req['id']);
        if ($i < 0) return new WP_Error('not_found', 'Geplante Mitteilung nicht gefunden.', ['status' => 404]);
        $it = $all[$i];
        if ($it['status'] !== 'scheduled') return self::bad('status', 'Nur geplante Mitteilungen lassen sich ändern.', 'tix_locked', 409);
        $clean = function ($v) { return trim(wp_strip_all_tags((string) $v)); };
        if ($req->has_param('title')) $it['title'] = mb_substr($clean($req->get_param('title')), 0, 120);
        if ($req->has_param('body')) $it['body'] = mb_substr($clean($req->get_param('body')), 0, 1000);
        if ($it['title'] === '' && $it['body'] === '') return self::bad('title', 'Titel oder Text erforderlich.');
        if ($req->has_param('event_id')) { $it['event_id'] = absint($req->get_param('event_id')); if ($e = self::check_event($it['event_id'])) return $e; }
        if ($req->has_param('target')) $it['target'] = sanitize_key((string) $req->get_param('target'));
        if ($req->has_param('send_at') || $req->has_param('relative_hours')) {
            $t = self::timing($req->get_param('send_at') ?? '', $req->get_param('relative_hours') ?? 0, intval($it['event_id']));
            if (is_wp_error($t)) return $t;
            list($it['send_at'], $it['relative_hours']) = $t;
        }
        $all[$i] = $it;
        self::save($all);
        return rest_ensure_response(['ok' => true, 'scheduled' => self::item($it)]);
    }

    public static function rest_cancel(WP_REST_Request $req) {
        $all = self::all();
        $i = self::find_idx((string) $req['id']);
        if ($i < 0) return new WP_Error('not_found', 'Geplante Mitteilung nicht gefunden.', ['status' => 404]);
        if ($all[$i]['status'] !== 'scheduled') return self::bad('status', 'Nur geplante Mitteilungen lassen sich abbrechen.', 'tix_locked', 409);
        $all[$i]['status'] = 'cancelled';
        $all[$i]['cancelled_at'] = time();
        self::save($all);
        return rest_ensure_response(['ok' => true, 'scheduled' => self::item($all[$i])]);
    }

    public static function rest_send_now(WP_REST_Request $req) {
        $all = self::all();
        $i = self::find_idx((string) $req['id']);
        if ($i < 0) return new WP_Error('not_found', 'Geplante Mitteilung nicht gefunden.', ['status' => 404]);
        if ($all[$i]['status'] !== 'scheduled') return self::bad('status', 'Diese Mitteilung ist nicht mehr geplant.', 'tix_locked', 409);
        self::deliver($all[$i]);
        self::save($all);
        return rest_ensure_response(['ok' => $all[$i]['status'] === 'sent', 'scheduled' => self::item($all[$i])]);
    }
}

TIX_Notif_Schedule::init();
