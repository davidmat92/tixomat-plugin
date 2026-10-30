<?php
/**
 * Besucher → Kauf auf der Marken-Website, ohne Cookies und ohne Einwilligung (Vertrag uebergaben/vertrag-startseite.md §5).
 *
 * Gezählt werden nur Ereignisse je Tag, keine Personen: Seitenaufruf, Event-Seite, Kasse geöffnet, Kauf. Keine IP, kein
 * User-Agent, keine Kennung im Browser. Die Website (marken-web) schickt die Ereignisse serverseitig weiter, das Plugin sieht
 * also nur die Adresse des Website-Servers. Ein Kauf zählt nur einmal je Bestellung und nur, wenn die Bestellung mit
 * passendem Schlüssel existiert und bezahlt ist.
 *
 * Tabelle {prefix}tix_web_stats (day, step, ref, n): ref = Event-ID (event/checkout/purchase), 0 bei view, Bestell-ID bei
 * dem Merker „po“ (Tag 2000-01-01, verhindert doppelte Käufe). Aufbewahrung 400 Tage.
 */
if (!defined('ABSPATH')) exit;

class TIX_Web_Stats {
    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const STEPS = ['view', 'event', 'checkout', 'purchase'];
    const KEEP_DAYS = 400;
    const PER_MINUTE = 3000;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('init', [__CLASS__, 'maybe_create'], 20);
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'tix_web_stats'; }

    public static function maybe_create() {
        if (get_option('tix_web_stats_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta("CREATE TABLE $t (
            day DATE NOT NULL,
            step VARCHAR(10) NOT NULL,
            ref BIGINT UNSIGNED NOT NULL DEFAULT 0,
            n INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (day, step, ref)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_web_stats_db', self::DB_VERSION, true);
    }

    public static function routes() {
        register_rest_route(self::NS, '/public/stats/hit', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_hit'], 'permission_callback' => ['TIX_Website', 'perm_public']]);
        register_rest_route(self::NS, '/studio/stats/funnel', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_funnel'], 'permission_callback' => ['TIX_Website', 'perm']]);
    }

    private static function today() { return wp_date('Y-m-d'); }

    private static function bump($day, $step, $ref) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("INSERT INTO " . self::table() . " (day, step, ref, n) VALUES (%s, %s, %d, 1) ON DUPLICATE KEY UPDATE n = n + 1", $day, $step, $ref));
    }

    /** Grobe Bremse gegen Fluten: höchstens PER_MINUTE Ereignisse je Minute für die ganze Seite */
    private static function throttled() {
        $k = 'tix_ws_rate_' . gmdate('YmdHi');
        $n = (int) get_transient($k);
        if ($n >= self::PER_MINUTE) return true;
        set_transient($k, $n + 1, 120);
        return false;
    }

    /** POST /public/stats/hit {step, event_id?, order_id?, order_key?} → 204 (auch wenn nichts gezählt wurde) */
    public static function rest_hit(WP_REST_Request $req) {
        $done = new WP_REST_Response(null, 204);
        $step = (string) $req->get_param('step');
        if (!in_array($step, self::STEPS, true) || self::throttled()) return $done;
        $event = max(0, intval($req->get_param('event_id')));
        if ($event && get_post_type($event) !== 'event') $event = 0;
        $day = self::today();
        if ($step === 'purchase') {
            $oid = intval($req->get_param('order_id'));
            $key = (string) $req->get_param('order_key');
            if (!$oid || $key === '' || !class_exists('TIX_Order')) return $done;
            global $wpdb;
            $o = $wpdb->get_row($wpdb->prepare("SELECT id, status, order_key FROM " . TIX_Order::table_name() . " WHERE id = %d", $oid));
            if (!$o || !hash_equals((string) $o->order_key, $key) || !in_array($o->status, ['completed', 'processing'], true)) return $done;
            // einmal je Bestellung: Merker anlegen, nur wenn neu
            $new = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO " . self::table() . " (day, step, ref, n) VALUES ('2000-01-01', 'po', %d, 1)", $oid));
            if (!$new) return $done;
        }
        self::bump($day, $step, $step === 'view' ? 0 : $event);
        if (mt_rand(1, 500) === 1) self::purge();
        return $done;
    }

    private static function purge() {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " WHERE day < %s AND step <> 'po'", wp_date('Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS)));
        // Merker „po“ bleiben (eine Zeile je Bestellung), sonst zählte ein später erneut geöffneter Kauf doppelt
    }

    /** GET /studio/stats/funnel?days=30 → {from, to, totals, days[], events[]} */
    public static function rest_funnel(WP_REST_Request $req) {
        global $wpdb;
        $days = min(365, max(1, intval($req->get_param('days') ?: 30)));
        $to = self::today();
        $from = wp_date('Y-m-d', strtotime($to . ' 12:00:00') - ($days - 1) * DAY_IN_SECONDS);
        $t = self::table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT day, step, SUM(n) AS n FROM $t WHERE day BETWEEN %s AND %s AND step IN ('view','event','checkout','purchase') GROUP BY day, step", $from, $to));
        $byDay = [];
        $totals = array_fill_keys(self::STEPS, 0);
        foreach ((array) $rows as $r) {
            $byDay[$r->day][$r->step] = intval($r->n);
            $totals[$r->step] += intval($r->n);
        }
        $list = [];
        for ($i = 0; $i < $days; $i++) {
            $d = wp_date('Y-m-d', strtotime($from . ' 12:00:00') + $i * DAY_IN_SECONDS);
            $list[] = ['date' => $d] + array_merge(array_fill_keys(self::STEPS, 0), $byDay[$d] ?? []);
        }
        $ev = $wpdb->get_results($wpdb->prepare("SELECT ref, step, SUM(n) AS n FROM $t WHERE day BETWEEN %s AND %s AND step IN ('event','checkout','purchase') AND ref > 0 GROUP BY ref, step", $from, $to));
        $events = [];
        foreach ((array) $ev as $r) {
            $id = intval($r->ref);
            if (!isset($events[$id])) $events[$id] = ['id' => $id, 'title' => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($id, '_tix_date_start', true), 'event' => 0, 'checkout' => 0, 'purchase' => 0];
            $events[$id][$r->step] = intval($r->n);
        }
        usort($events, function ($a, $b) { return $b['event'] <=> $a['event'] ?: $b['purchase'] <=> $a['purchase']; });
        return rest_ensure_response(['from' => $from, 'to' => $to, 'totals' => $totals, 'days' => $list, 'events' => array_slice(array_values($events), 0, 20)]);
    }
}

TIX_Web_Stats::init();
