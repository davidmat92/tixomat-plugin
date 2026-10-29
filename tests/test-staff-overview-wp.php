<?php
/**
 * Integrationstest Startseite des Veranstalter-Bereichs (1.38.590): GET /staff/overview –
 * Tageswerte (Tickets, Umsatz), Vorwochen-Vergleich, Live-Einlässe je 15 Minuten. Räumt auf.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-staff-overview-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Staff_Overview')) { echo "WordPress mit Tixomat (TIX_Staff_Overview) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function so_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
global $wpdb;
$admin = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($admin ? $admin[0]->ID : 0);
$now = current_time('timestamp');
$eid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SO Test', 'meta_input' => ['_tix_date_start' => gmdate('Y-m-d', $now)]]);
$before = TIX_Staff_Overview::days(14, $now);
$o1 = TIX_Order::create_legacy(['status' => 'completed', 'total' => 40, 'date_created' => gmdate('Y-m-d H:i:s', $now)], [['event_id' => $eid, 'qty' => 2, 'price' => 20]]);
$o2 = TIX_Order::create_legacy(['status' => 'completed', 'total' => 30, 'date_created' => gmdate('Y-m-d H:i:s', $now - 8 * DAY_IN_SECONDS)], [['event_id' => $eid, 'qty' => 3, 'price' => 10]]);
$o3 = TIX_Order::create_legacy(['status' => 'cancelled', 'total' => 99, 'date_created' => gmdate('Y-m-d H:i:s', $now)], [['event_id' => $eid, 'qty' => 9, 'price' => 11]]);
$after = TIX_Staff_Overview::days(14, $now);
so_eq('14 Tage ohne Lücken', 14, count($after['days']));
so_eq('letzter Tag = heute', gmdate('Y-m-d', $now), end($after['days'])['date']);
so_eq('heute +2 Tickets', 2, end($after['days'])['tickets'] - end($before['days'])['tickets']);
so_eq('heute +40 € Umsatz', 40.0, round(end($after['days'])['revenue'] - end($before['days'])['revenue'], 2));
so_eq('Vorwoche +3 Tickets', 3, $after['prev7']['tickets'] - $before['prev7']['tickets']);
so_eq('letzte 7 Tage +2', 2, $after['last7']['tickets'] - $before['last7']['tickets']);
so_eq('stornierte Bestellung zählt nicht', true, end($after['days'])['tickets'] - end($before['days'])['tickets'] < 9);

// Live: drei Tickets, zwei eingelassen (vor 5 und vor 40 Minuten)
$tids = [];
foreach ([5, 40, null] as $i => $ago) {
    $t = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'SO-T' . $i, 'meta_input' => ['_tix_ticket_event_id' => $eid, '_tix_ticket_code' => 'SO' . $i . wp_generate_password(6, false)]]);
    if ($ago !== null) {
        update_post_meta($t, '_tix_ticket_checked_in', 1);
        update_post_meta($t, '_tix_ticket_checkin_time', gmdate('Y-m-d\TH:i:s', $now - $ago * MINUTE_IN_SECONDS) . '+00:00');
    }
    $tids[] = $t;
}
update_post_meta($eid, '_tix_guest_list', [['id' => 'g1', 'name' => 'Gast', 'plus' => 1, 'checked_in_count' => 1]]);
$l = TIX_Staff_Overview::live($eid, $now);
so_eq('Live: eingelassen 2 Tickets + 1 Gast', 3, $l['checked_in']);
so_eq('Live: gesamt 3 Tickets + 2 Gäste', 5, $l['total']);
so_eq('Live: letzte 15 Min. 1', 1, $l['last15']);
so_eq('Live: Viertelstunden', [0, 0, 1, 0, 1], $l['slots']);
so_eq('Live: letzter Einlass vor 5 Min.', 300, $l['last_ago']);

// REST
$r = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/staff/overview'));
so_eq('REST ohne Event: 200, ohne live', [200, false], [$r->get_status(), isset($r->get_data()['live'])]);
$q = new WP_REST_Request('GET', '/tixomat/v1/staff/overview'); $q->set_query_params(['event_id' => $eid]);
$r = rest_do_request($q);
so_eq('REST mit Event: live dabei', $eid, $r->get_data()['live']['event_id'] ?? null);
wp_set_current_user(0);
$r = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/staff/overview'));
so_eq('ohne Anmeldung gesperrt', true, $r->get_status() >= 401);

// Aufräumen
foreach ([$o1, $o2, $o3] as $o) { $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]); }
foreach (array_merge($tids, [$eid]) as $p) wp_delete_post($p, true);
echo $GLOBALS['t_fails'] === 0 ? "\nALLES GRÜN ({$GLOBALS['t_count']} Prüfungen)\n" : "\n{$GLOBALS['t_fails']} von {$GLOBALS['t_count']} FEHLGESCHLAGEN\n";
