<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Price_Watch – Preis-Wächter (Strategie evendis, Paket 1 → 2): Umsatz und Tickets je Monat,
 * daraus „mit eigenem Shop hättest du X € gespart“. Nur Mehr-Veranstalter-Modus.
 *
 *   GET /organizer/price-watch[?months=12]  (Veranstalter, wie /organizer/fee-mode)
 *
 * Grundlage: eigene Positionen (Events mit `_tix_organizer_id`) in bezahlten nativen Bestellungen,
 * Freitickets ohne Gebühr. Gebühr = Satz des Veranstalters (zentral bzw. eigene Höhe); solange die Gebühr
 * auf 0 steht, rechnet der Wächter mit dem veröffentlichten Satz (Einstellungen `price_watch_ref_*`).
 * Anzeige erst nach Freigabe: Einstellung `price_watch_enabled` (Vorgabe aus) → `enabled` in der Antwort.
 */
class TIX_Price_Watch {

    const NS = 'tixomat/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/organizer/price-watch', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => ['TIX_Payout_Details', 'check_perm'],
        ]);
    }

    public static function settings() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        return [
            'enabled'      => !empty($s['price_watch_enabled']),
            'shop_monthly' => max(0.0, floatval($s['price_watch_shop_monthly'] ?? 79)),
            'ref_percent'  => max(0.0, floatval($s['price_watch_ref_percent'] ?? 2.5)),
            'ref_fixed'    => max(0.0, floatval($s['price_watch_ref_fixed'] ?? 0.59)),
        ];
    }

    /** Gebührensatz für die Rechnung: tatsächlicher Satz, sonst Referenz. */
    public static function rate($oid) {
        $cfg = TIX_Fees::get_fee_config($oid);
        $pct = floatval($cfg['fee_percent']); $fix = floatval($cfg['fee_fixed']);
        if ($pct > 0 || $fix > 0) return ['percent' => $pct, 'fixed' => $fix, 'source' => !empty($cfg['fee_custom']) ? 'custom' : 'current'];
        $ps = self::settings();
        return ['percent' => $ps['ref_percent'], 'fixed' => $ps['ref_fixed'], 'source' => 'reference'];
    }

    public static function rest_get(WP_REST_Request $req) {
        $oid = TIX_App_Scope::organizer_id_for_user();
        if (!$oid) return new WP_Error('tix_no_organizer', 'Kein Veranstalter-Profil gefunden.', ['status' => 404]);
        $months = max(1, min(24, intval($req->get_param('months') ?: 12)));
        return rest_ensure_response(self::payload(intval($oid), $months));
    }

    /** Monate (Y-m) rückwärts ab dem aktuellen Monat, älteste zuerst. */
    private static function month_keys($n) {
        $keys = [];
        $base = strtotime(current_time('Y-m-01'));
        for ($i = $n - 1; $i >= 0; $i--) $keys[] = gmdate('Y-m', strtotime("-$i months", $base));
        return $keys;
    }

    public static function payload($oid, $months = 12) {
        global $wpdb;
        $ps   = self::settings();
        $rate = self::rate($oid);
        $keys = self::month_keys($months);
        $from = $keys[0] . '-01 00:00:00';
        $events = get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => [['key' => '_tix_organizer_id', 'value' => $oid, 'type' => 'NUMERIC']]]);
        $rows = [];
        if ($events && class_exists('TIX_Order')) {
            $t  = TIX_Order::table_name();
            $ti = TIX_Order::items_table_name();
            $ph = implode(',', array_fill(0, count($events), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE_FORMAT(o.date_created, '%%Y-%%m') AS ym,
                        COUNT(DISTINCT o.id) AS orders,
                        COALESCE(SUM(i.quantity), 0) AS tickets,
                        COALESCE(SUM(CASE WHEN i.total > 0 THEN i.quantity ELSE 0 END), 0) AS paid_tickets,
                        COALESCE(SUM(i.total), 0) AS revenue
                 FROM $t o INNER JOIN $ti i ON i.order_id = o.id
                 WHERE o.status IN ('completed','processing') AND o.date_created >= %s AND i.event_id IN ($ph)
                 GROUP BY ym",
                ...array_merge([$from], array_map('intval', $events))
            ), OBJECT_K);
        }
        $out = []; $sum = ['orders' => 0, 'tickets' => 0, 'paid_tickets' => 0, 'revenue' => 0.0, 'fee' => 0.0];
        foreach ($keys as $ym) {
            $r = $rows[$ym] ?? null;
            $m = [
                'month'        => $ym,
                'orders'       => $r ? intval($r->orders) : 0,
                'tickets'      => $r ? intval($r->tickets) : 0,
                'paid_tickets' => $r ? intval($r->paid_tickets) : 0,
                'revenue'      => $r ? round(floatval($r->revenue), 2) : 0.0,
            ];
            $m['fee']     = round($m['revenue'] * $rate['percent'] / 100 + $m['paid_tickets'] * $rate['fixed'], 2);
            $m['shop']    = round($ps['shop_monthly'], 2);
            $m['savings'] = round($m['fee'] - $m['shop'], 2);
            foreach (['orders', 'tickets', 'paid_tickets', 'revenue', 'fee'] as $k) $sum[$k] += $m[$k];
            $out[] = $m;
        }
        $avg_price = $sum['paid_tickets'] > 0 ? $sum['revenue'] / $sum['paid_tickets'] : 20.0;
        $per_ticket = $avg_price * $rate['percent'] / 100 + $rate['fixed'];
        $shop_total = round($ps['shop_monthly'] * count($keys), 2);
        $savings = round($sum['fee'] - $shop_total, 2);
        return [
            'ok'           => true,
            'enabled'      => $ps['enabled'],
            'fee'          => ['percent' => round($rate['percent'], 2), 'fixed' => round($rate['fixed'], 2), 'source' => $rate['source']],
            'shop_monthly' => round($ps['shop_monthly'], 2),
            'months'       => $out,
            'total'        => [
                'months'       => count($keys),
                'orders'       => $sum['orders'],
                'tickets'      => $sum['tickets'],
                'paid_tickets' => $sum['paid_tickets'],
                'revenue'      => round($sum['revenue'], 2),
                'fee'          => round($sum['fee'], 2),
                'shop'         => $shop_total,
                'savings'      => $savings,
            ],
            'avg_ticket_price'          => round($avg_price, 2),
            'break_even_tickets_month'  => $per_ticket > 0 ? (int) ceil($ps['shop_monthly'] / $per_ticket) : null,
            'recommend_shop'            => $savings > 0,
            'message'                   => $savings > 0
                ? sprintf('Mit eigenem Shop hättest du in den letzten %d Monaten %s € gespart.', count($keys), number_format($savings, 2, ',', '.'))
                : sprintf('Die Plattform ist für dich günstiger. Ein eigener Shop lohnt sich ab etwa %d Tickets im Monat.', $per_ticket > 0 ? (int) ceil($ps['shop_monthly'] / $per_ticket) : 0),
        ];
    }
}
