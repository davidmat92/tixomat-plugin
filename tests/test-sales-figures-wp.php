<?php
/**
 * Integrationstest „Eine Quelle für alle Verkaufszahlen“ (TIX_Sales_Figures, 1.38.606).
 * Szenario: Rabatt-Gutschein + Kundengebühr, Geschenkgutschein als Zahlung, Teil-Erstattung, Kasse ohne E-Mail,
 * Extra (keine Tickets), stornierte Bestellung, über 200 Bestellungen. Alle Stellen müssen dieselben Zahlen zeigen.
 * Läuft auch gegen ältere Stände (nur Tabelle, ohne Prüfungen) – für den Vorher/Nachher-Vergleich. Räumt auf.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-sales-figures-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Order')) { echo "WordPress mit Tixomat nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$new = class_exists('TIX_Sales_Figures');
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function sf_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    $ok = is_float($exp) || is_float($act) ? abs(floatval($exp) - floatval($act)) < 0.011 : $exp === $act;
    if ($ok) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function sf_get($path, array $q = []) {
    if (class_exists('TIX_Sales_Figures')) TIX_Sales_Figures::reset();
    $r = new WP_REST_Request('GET', '/tixomat/v1' . $path);
    foreach ($q as $k => $v) $r->set_param($k, $v);
    $res = rest_do_request($r);
    $d = $res->get_data();
    if ($res->is_error()) echo "  (Fehler $path: " . json_encode($d) . ")\n";
    return json_decode(wp_json_encode($d), true);
}
global $wpdb;
$admin = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($admin ? $admin[0]->ID : 0);
$today = current_time('Y-m-d');
$now = current_time('mysql');
$eid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SF Test ' . wp_generate_password(4, false), 'meta_input' => [
    '_tix_date_start' => gmdate('Y-m-d', strtotime($today) + 20 * DAY_IN_SECONDS), '_tix_tickets_enabled' => '1',
    '_tix_ticket_categories' => [
        ['name' => 'Early', 'price' => 20, 'qty' => 100],
        ['name' => 'Abend', 'price' => 10, 'qty' => 0],
        ['name' => 'Gutschein', 'price' => 25, 'gift_card' => true],
    ],
]]);
$orders = []; $tickets = [];
$tk = function ($oid, $cat, $status = 'valid', $checked = false) use ($eid, &$tickets) {
    $tid = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'T', 'meta_input' => [
        '_tix_ticket_event_id' => $eid, '_tix_ticket_order_id' => $oid, '_tix_ticket_cat_index' => $cat, '_tix_ticket_status' => $status,
        '_tix_ticket_checked_in' => $checked ? '1' : '', '_tix_ticket_price' => $cat === 0 ? 20 : 10, '_tix_ticket_cat_name' => $cat === 0 ? 'Early' : 'Abend']]);
    $tickets[] = $tid;
};
$mk = function ($data, $items) use (&$orders, $now) {
    $id = TIX_Order::create_legacy($data + ['status' => 'completed', 'date_created' => $now], $items);
    $orders[] = $id;
    return $id;
};
// O1 Online: 2× Early + 1 Extra (5 €), Rabatt 10 €, Kundengebühr 2 € → gezahlt 37
$o1 = $mk(['total' => 37, 'discount' => 10, 'payment_method' => 'mollie', 'billing_email' => 'sf-a@example.test'], [
    ['event_id' => $eid, 'cat_index' => 0, 'qty' => 2, 'price' => 20, 'cat_name' => 'Early'],
    ['event_id' => $eid, 'cat_index' => -1, 'qty' => 1, 'price' => 5, 'cat_name' => 'Getränkemarke'],
]);
update_option('_tix_order_coupon_' . $o1, ['code' => 'SF10', 'discount' => 10, 'type' => 'fixed'], false);
update_option('_tix_order_fees_' . $o1, ['customer_fee_line' => 2, 'platform_fee' => 2, 'platform_fee_mode' => 'customer'], false);
$tk($o1, 0, 'valid', true); $tk($o1, 0);
// O2 mit Geschenkgutschein bezahlt (10 €), gezahlt 0
$o2 = $mk(['total' => 0, 'discount' => 10, 'payment_method' => 'free', 'billing_email' => 'sf-b@example.test'], [['event_id' => $eid, 'cat_index' => 1, 'qty' => 1, 'price' => 10, 'cat_name' => 'Abend']]);
update_option('_tix_order_coupon_' . $o2, ['code' => 'GS-SFTEST', 'discount' => 10, 'type' => 'giftcard'], false);
$tk($o2, 1);
// O3 3× Early = 60, Teil-Erstattung 20 (ein Ticket storniert)
$o3 = $mk(['total' => 60, 'payment_method' => 'mollie', 'billing_email' => 'sf-c@example.test'], [['event_id' => $eid, 'cat_index' => 0, 'qty' => 3, 'price' => 20, 'cat_name' => 'Early']]);
update_option('_tix_refund_total_' . $o3, 20, false);
$tk($o3, 0); $tk($o3, 0); $tk($o3, 0, 'cancelled');
// O4 Kasse ohne E-Mail: 1× Abend bar
$o4 = $mk(['total' => 10, 'payment_method' => 'pos_cash', 'billing_first_name' => 'Kasse'], [['event_id' => $eid, 'cat_index' => 1, 'qty' => 1, 'price' => 10, 'cat_name' => 'Abend']]);
update_option('_tix_pos_order_' . $o4, ['event_id' => $eid, 'staff_id' => get_current_user_id()], false);
$tk($o4, 1);
// O5 storniert
$o5 = $mk(['status' => 'cancelled', 'total' => 20, 'payment_method' => 'mollie', 'billing_email' => 'sf-d@example.test'], [['event_id' => $eid, 'cat_index' => 0, 'qty' => 1, 'price' => 20, 'cat_name' => 'Early']]);
$tk($o5, 0, 'cancelled');
// 210 weitere Bestellungen je 1× Abend (über das alte Limit von 200)
for ($i = 0; $i < 210; $i++) {
    $o = $mk(['total' => 10, 'payment_method' => 'mollie', 'billing_email' => 'sf-bulk' . $i . '@example.test',
        'date_created' => gmdate('Y-m-d H:i:s', strtotime($now) - ($i % 20) * DAY_IN_SECONDS)], [['event_id' => $eid, 'cat_index' => 1, 'qty' => 1, 'price' => 10, 'cat_name' => 'Abend']]);
    $tk($o, 1);
}
// Erwartet
$X = ['tickets' => 2 + 2 + 1 + 1 + 210, 'revenue' => 35 + 10 + 40 + 10 + 2100.0, 'orders' => 214, 'refunds' => 20.0, 'fees' => 2.0, 'discounts' => 10.0, 'capacity_unlimited' => true];

// ── Alle Stellen lesen ──
$rows = [];
$ev = sf_get("/events/$eid");
$e = $ev['event'] ?? [];
$rows['A /events/{id}'] = [$e['total_sold'] ?? null, $e['sales']['revenue'] ?? null, $e['sales']['orders'] ?? null];
$list = sf_get('/events', ['filter' => 'all', 'per_page' => 100]);
$le = null; foreach ((array) ($list['events'] ?? []) as $x) if (intval($x['id']) === $eid) $le = $x;
$rows['B /events (Liste)'] = [$le['total_sold'] ?? null, $le['sales']['revenue'] ?? null, $le['sales']['orders'] ?? null];
$st = sf_get("/events/$eid/statistics")['statistics'] ?? [];
$rows['D /events/{id}/statistics'] = [$st['total_tickets'] ?? null, $st['total_revenue'] ?? null, $st['total_orders'] ?? null];
$st_cat = array_sum(array_column((array) ($st['by_category'] ?? []), 'revenue'));
$eo = sf_get("/events/$eid/orders");
$f_sum = 0.0; $f_tix = 0; foreach ((array) ($eo['orders'] ?? []) as $o) if (in_array($o['status'], ['completed', 'processing'], true)) { $f_sum += $o['total']; foreach ($o['items'] as $it) $f_tix += $it['quantity']; }
$rows['F /events/{id}/orders (Studio-Summe)'] = [$f_tix, round($f_sum, 2), count(array_filter((array) ($eo['orders'] ?? []), function ($o) { return in_array($o['status'], ['completed', 'processing'], true); }))];
$rows['F /events/{id}/orders summary'] = [$eo['summary']['tickets'] ?? null, $eo['summary']['revenue'] ?? null, $eo['summary']['orders'] ?? null];
$os = sf_get('/organizer/stats', ['event_id' => $eid, 'from' => gmdate('Y-m-d', strtotime($today) - 365 * DAY_IN_SECONDS), 'to' => $today]);
$rows['H /organizer/stats (Event, 1 Jahr)'] = [$os['totals']['tickets'] ?? null, $os['totals']['revenue'] ?? null, $os['totals']['orders'] ?? null];
$os_cat = array_sum(array_column((array) ($os['by_category_top'] ?? []), 'revenue'));
$os_ser = array_sum(array_column((array) ($os['series'] ?? []), 'revenue'));
$osa = sf_get('/organizer/stats', ['from' => gmdate('Y-m-d', strtotime($today) - 3649 * DAY_IN_SECONDS), 'to' => $today]);
$be = null; foreach ((array) ($osa['by_event'] ?? []) as $x) if (intval($x['id']) === $eid) $be = $x;
$rows['H /organizer/stats „Alles“ by_event'] = [$be['tickets'] ?? null, $be['revenue'] ?? null, $be['orders'] ?? null];
$rep = sf_get("/events/$eid/report");
$rows['I /events/{id}/report'] = [$rep['kpis']['sold'] ?? null, $rep['kpis']['revenue_gross'] ?? null, $rep['kpis']['orders'] ?? null];
$rep_cat = array_sum(array_column((array) ($rep['categories'] ?? []), 'revenue'));
$pos = sf_get('/pos/report', ['date' => $today, 'event_id' => $eid])['report'] ?? [];
$rows['J /pos/report (nur Kasse)'] = [$pos['total_tickets'] ?? null, $pos['revenue'] ?? $pos['total_revenue'] ?? null, $pos['total_orders'] ?? null];
$pos_cat = array_sum(array_column((array) ($pos['by_category'] ?? []), 'revenue'));
$cu = sf_get('/customers', ['per_page' => 100, 'q' => 'sf-a@example.test']);
$ca = $cu['customers'][0] ?? [];
$rows['K /customers (Kunde O1)'] = [$ca['tickets'] ?? null, $ca['revenue'] ?? null, $ca['orders'] ?? null];
$so = sf_get('/staff/overview', ['event_id' => $eid]);
$rows['/staff/overview event'] = [$so['event']['tickets'] ?? null, $so['event']['revenue'] ?? null, null];

echo "\nStelle | Tickets | Umsatz | Bestellungen\n";
foreach ($rows as $k => $v) echo $k . ' | ' . implode(' | ', array_map(function ($x) { return $x === null ? '–' : (is_float($x) ? number_format($x, 2, ',', '') : (string) $x); }, $v)) . "\n";
echo 'Erwartet | ' . $X['tickets'] . ' | ' . number_format($X['revenue'], 2, ',', '') . ' | ' . $X['orders'] . "\n\n";

if ($new) {
    foreach (['A /events/{id}', 'B /events (Liste)', 'D /events/{id}/statistics', 'F /events/{id}/orders summary', 'H /organizer/stats (Event, 1 Jahr)', 'H /organizer/stats „Alles“ by_event', 'I /events/{id}/report'] as $k) {
        sf_eq("$k Tickets", $X['tickets'], intval($rows[$k][0]));
        sf_eq("$k Umsatz", $X['revenue'], floatval($rows[$k][1]));
        sf_eq("$k Bestellungen", $X['orders'], intval($rows[$k][2]));
    }
    sf_eq('/staff/overview Tickets', $X['tickets'], intval($rows['/staff/overview event'][0]));
    sf_eq('/staff/overview Umsatz', $X['revenue'], floatval($rows['/staff/overview event'][1]));
    $s = $e['sales'];
    sf_eq('Erstattungen', $X['refunds'], floatval($s['refunds']));
    sf_eq('Gebühren separat', $X['fees'], floatval($s['fees']));
    sf_eq('Rabatte', $X['discounts'], floatval($s['discounts']));
    sf_eq('Umsatz vor Erstattung', $X['revenue'] + $X['refunds'], floatval($s['revenue_gross']));
    sf_eq('Kapazität unbegrenzt (Abend ohne Kontingent)', true, (bool) $s['unlimited']);
    sf_eq('Kapazität wie /events total_capacity', intval($e['total_capacity']), intval($s['capacity']));
    sf_eq('Statistik: Kategorien summieren sich zum Umsatz', $X['revenue'], round($st_cat, 2));
    sf_eq('Statistik: Extra nicht als Ticket', true, intval($st['by_category']['Getränkemarke']['tickets'] ?? 0) === 1 && intval($st['total_tickets']) === $X['tickets']);
    sf_eq('Org-Stats: Kategorien summieren sich zum Umsatz', $X['revenue'], round($os_cat, 2));
    sf_eq('Org-Stats: Verlauf summiert sich zum Umsatz', $X['revenue'], round($os_ser, 2));
    sf_eq('Org-Stats „Alles“ (10 Jahre) erlaubt, monatlich', 'month', $osa['range']['group'] ?? null);
    sf_eq('Org-Stats: Gesamttickets des Events', $X['tickets'], intval($be['tickets_total'] ?? 0));
    sf_eq('Bericht: Kategorien summieren sich zum Umsatz', $X['revenue'], round($rep_cat, 2));
    sf_eq('Bericht: Rabatte', $X['discounts'], floatval($rep['kpis']['discounts'] ?? 0));
    sf_eq('Kasse: 1 Ticket, 10 €', [1, 10.0], [intval($pos['total_tickets']), floatval($pos['revenue'])]);
    sf_eq('Kasse: Kategorien summieren sich zum Umsatz', 10.0, round($pos_cat, 2));
    sf_eq('Kunde O1: 2 Tickets (Extra zählt nicht), 35 € (nach Rabatt, ohne Gebühr)', [2, 35.0], [intval($ca['tickets'] ?? -1), floatval($ca['revenue'] ?? -1)]);
    $ct = sf_get('/customers', ['per_page' => 1])['totals'] ?? [];
    sf_eq('Kunden: Umsatz ohne Kunde ≥ Kasse ohne E-Mail', true, floatval($ct['revenue_without_customer'] ?? -1) >= 10.0);
    $ao = sf_get('/orders', ['per_page' => 10]);
    sf_eq('/orders summary 30 Tage enthält das Event', true, floatval($ao['summary']['days30']['revenue'] ?? 0) >= $X['revenue']);
    // Einlass-Rolle sieht Tickets, aber keine Geldwerte in /events (Einzel-Seite; mit mehreren Veranstaltern braucht das Konto einen Veranstalter)
    $u = class_exists('TIX_App_Scope') && TIX_App_Scope::multi() ? new WP_Error('skip', '') : wp_insert_user(['user_login' => 'sf_staff_' . wp_generate_password(4, false), 'user_pass' => wp_generate_password(), 'role' => 'tix_staff']);
    if (!is_wp_error($u)) {
        wp_set_current_user($u);
        $se = sf_get("/events/$eid")['event']['sales'] ?? null;
        sf_eq('Mitarbeiter: Tickets ja, Umsatz nein', [$X['tickets'], null], [intval($se['tickets'] ?? -1), is_array($se) && array_key_exists('revenue', $se) ? $se['revenue'] : 'x']);
        wp_set_current_user($admin ? $admin[0]->ID : 0);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($u);
    }
}

// ── Aufräumen ──
foreach ($tickets as $t) wp_delete_post($t, true);
foreach ($orders as $o) {
    $wpdb->delete(TIX_Order::items_table_name(), ['order_id' => $o]);
    $wpdb->delete(TIX_Order::table_name(), ['id' => $o]);
    foreach (['_tix_order_coupon_', '_tix_order_fees_', '_tix_refund_total_', '_tix_pos_order_'] as $p) delete_option($p . $o);
}
wp_delete_post($eid, true);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_sov\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tix\\_sov\\_%'");
echo $new ? "\n{$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n" : "\n(alter Stand: nur Tabelle)\n";
