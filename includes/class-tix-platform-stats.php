<?php
/**
 * TIX_Platform_Stats – Plattform-Sicht für den evendis-Betreiber (Statistik Stufe 4, docs/plan-statistik-marketing.md §3.5,
 * F6 A: zuerst nur evendis.de über das Plugin, später alle Seiten über den Datenspiegel).
 *
 * GET /platform/stats?from=&to= (nur WordPress-Admins, nur im Mehr-Veranstalter-Modus) →
 *   {ok, range, totals (wie TIX_Sales_Figures: tickets, orders, revenue, revenue_gross, fees, refunds, by_day),
 *    organizers {active, selling, new}, events {selling}, top_organizers [{id, name, tickets, orders, revenue}] (10),
 *    funnel {view, visit, event, quote, checkout, purchase}, app {event, quote, checkout, push_open},
 *    channels [{source, orders, revenue}] (Gruppen unter 3 Bestellungen als „sonstige“)}
 * Zahlen nur als Summen, keine Kundendaten. Werbekosten der Veranstalter erscheinen hier nicht (Meta-Regel §12.3).
 */
if (!defined('ABSPATH')) exit;

class TIX_Platform_Stats {

    const NS = 'tixomat/v1';
    const MIN_GROUP = 3;

    public static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    public static function routes() {
        register_rest_route(self::NS, '/platform/stats', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_stats'], 'permission_callback' => [__CLASS__, 'perm']]);
    }

    public static function perm() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Die Plattform-Auswertung sehen nur Admins.', ['status' => 403]);
    }

    public static function rest_stats(WP_REST_Request $req) {
        if (!class_exists('TIX_App_Scope') || !TIX_App_Scope::multi()) return new WP_Error('tix_not_multi', 'Die Plattform-Auswertung gibt es nur auf evendis.de.', ['status' => 404]);
        global $wpdb;
        $to = (string) ($req->get_param('to') ?: current_time('Y-m-d'));
        $from = (string) ($req->get_param('from') ?: gmdate('Y-m-d', strtotime($to) - 29 * DAY_IN_SECONDS));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) return new WP_Error('tix_invalid_field', 'Bitte einen gültigen Zeitraum angeben.', ['status' => 400, 'field' => 'from']);
        if ((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS > 3660) return new WP_Error('tix_invalid_field', 'Höchstens zehn Jahre auf einmal.', ['status' => 400, 'field' => 'from']);
        $k = 'tix_platform_stats_' . md5("$from|$to");
        if (($c = get_transient($k)) && is_array($c)) return rest_ensure_response($c);

        $ids = array_values(array_filter(array_map('intval', get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => -1, 'fields' => 'ids'])), function ($id) {
            return get_post_meta($id, '_tix_system_giftcard_event', true) !== '1' && get_post_meta($id, '_tix_system_wallet_event', true) !== '1';
        }));
        $res = TIX_Sales_Figures::for_events($ids, $from, $to);
        $tot = $res['totals'];
        unset($tot['by_category']);

        // je Veranstalter
        $by_org = []; $selling_events = 0;
        foreach ($res['events'] as $eid => $f) {
            if (intval($f['orders'] ?? 0) <= 0) continue;
            $selling_events++;
            $o = intval(get_post_meta($eid, '_tix_organizer_id', true));
            if (!$o) continue;
            if (!isset($by_org[$o])) $by_org[$o] = ['id' => $o, 'name' => html_entity_decode(get_the_title($o), ENT_QUOTES, 'UTF-8'), 'tickets' => 0, 'orders' => 0, 'revenue' => 0.0];
            $by_org[$o]['tickets'] += intval($f['tickets']); $by_org[$o]['orders'] += intval($f['orders']); $by_org[$o]['revenue'] += floatval($f['revenue']);
        }
        usort($by_org, function ($a, $b) { return $b['revenue'] <=> $a['revenue'] ?: $b['tickets'] <=> $a['tickets']; });
        $top = array_map(function ($x) { $x['revenue'] = round($x['revenue'], 2); return $x; }, array_slice($by_org, 0, 10));
        $orgs = (array) $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) active, SUM(post_date BETWEEN %s AND %s) neu FROM {$wpdb->posts} WHERE post_type = 'tix_organizer' AND post_status = 'publish'", $from . ' 00:00:00', $to . ' 23:59:59'), ARRAY_A);

        // Website-Trichter (ganze Plattform) und App
        $funnel = array_fill_keys(['view', 'visit', 'event', 'quote', 'checkout', 'purchase'], 0);
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT step, SUM(n) n FROM " . TIX_Web_Stats::table() . " WHERE day BETWEEN %s AND %s AND step IN ('view','visit','event','quote','checkout','purchase') GROUP BY step", $from, $to)) as $r) $funnel[$r->step] = intval($r->n);
        $app = array_fill_keys(['event', 'quote', 'checkout', 'push_open'], 0);
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT step, SUM(n) n FROM " . TIX_Web_Stats::dim_table() . " WHERE day BETWEEN %s AND %s AND channel = 'app' AND step IN ('event','quote','checkout','push_open') GROUP BY step", $from, $to)) as $r) $app[$r->step] = intval($r->n);

        // Kanäle der bezahlten Bestellungen (Quelle), kleine Gruppen zusammengefasst
        $paid = []; $rev = [];
        foreach ($res['rows'] as $r) if ($r->money['paid']) { $paid[intval($r->id)] = 1; $rev[intval($r->id)] = ($rev[intval($r->id)] ?? 0.0) + $r->money['net']; }
        $ch = [];
        $attr = $paid ? TIX_Order_Attribution::many(array_keys($paid), ['source', 'channel']) : [];
        foreach (array_keys($paid) as $oid) {
            $a = $attr[$oid] ?? [];
            $s = TIX_Web_Stats::norm($a['source'] ?? '') ?: (TIX_Web_Stats::norm($a['channel'] ?? '') ?: 'direct');
            if (!isset($ch[$s])) $ch[$s] = ['source' => $s, 'orders' => 0, 'revenue' => 0.0];
            $ch[$s]['orders']++; $ch[$s]['revenue'] += floatval($rev[$oid] ?? 0);
        }
        $channels = []; $other = ['source' => 'sonstige', 'orders' => 0, 'revenue' => 0.0];
        foreach ($ch as $c) {
            if ($c['orders'] < self::MIN_GROUP && $c['source'] !== 'direct') { $other['orders'] += $c['orders']; $other['revenue'] += $c['revenue']; continue; }
            $c['revenue'] = round($c['revenue'], 2); $channels[] = $c;
        }
        if ($other['orders']) { $other['revenue'] = round($other['revenue'], 2); $channels[] = $other; }
        usort($channels, function ($a, $b) { return $b['orders'] <=> $a['orders']; });

        $out = ['ok' => true, 'range' => ['from' => $from, 'to' => $to], 'totals' => $tot,
            'organizers' => ['active' => intval($orgs['active'] ?? 0), 'selling' => count($by_org), 'new' => intval($orgs['neu'] ?? 0)],
            'events' => ['selling' => $selling_events], 'top_organizers' => $top, 'funnel' => $funnel, 'app' => $app, 'channels' => $channels, 'fetched' => gmdate('c')];
        set_transient($k, $out, 10 * MINUTE_IN_SECONDS);
        return rest_ensure_response($out);
    }
}

TIX_Platform_Stats::init();
