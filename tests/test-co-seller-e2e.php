<?php
/**
 * Zwei-Seiten-Test Paket 3, Fall D (1.38.713): Plattform-Event, Kunden-Seite (kk) des Co verkauft, Plattform vermittelt über echtes HTTP.
 *   plat eval-file … setup-plat <kunden-host> <key_in> <key_out>  → JSON {ev}
 *   kk   eval-file … sync-kk                                     (Abgleich: verkaufsfähiger Spiegel + Meldung)
 *   plat eval-file … run-plat <json>                             (Kasse der Plattform → Broker → Kunden-Seite)
 *   kk   eval-file … check-kk                                    (Bestellung + Tickets liegen auf der Kunden-Seite)
 *   kk   eval-file … cleanup-kk  ·  plat eval-file … cleanup-plat
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Seller')) { echo "Tixomat ab 1.38.713 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = (string) ($args[0] ?? '');
global $wpdb;
$GLOBALS['cs_f'] = 0; $GLOBALS['cs_n'] = 0;
function cse_eq($label, $exp, $act) {
    $GLOBALS['cs_n']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['cs_f']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function cse_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
$done = function () { echo ($GLOBALS['cs_f'] ? "FEHLER: {$GLOBALS['cs_f']}" : 'ALLES OK') . " ({$GLOBALS['cs_n']} Prüfungen)\n"; };
$billing = ['first_name' => 'Cs', 'last_name' => 'Test', 'email' => 'cse@example.test', 'address_1' => 'Weg 1', 'postcode' => '12345', 'city' => 'Ort', 'country' => 'DE'];

if ($mode === 'setup-plat') {
    update_option('tix_cse_backup', ['partners' => get_option(TIX_Partners::OPTION, null)], false);
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CSETEST Ersteller']);
    $co = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CSETEST Kunden-Seite', 'meta_input' => ['_tix_org_kind' => 'site']]);
    $all = (array) get_option(TIX_Partners::OPTION, []);
    $all['csetest'] = ['id' => 'csetest', 'name' => 'CSETEST Kunden-Seite', 'api_base' => 'http://' . $args[1] . '/wp-json/tixomat/v1', 'key_in' => (string) $args[2], 'key_out' => (string) $args[3], 'organizer_id' => $co];
    update_option(TIX_Partners::OPTION, $all, false);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CSETEST Party D', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => '2027-09-01', '_tix_time_start' => '20:00',
        '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => [['name' => 'Gratis', 'price' => 0, 'qty' => 40, 'stock' => 40]]]]);
    TIX_Co_Organizer::set($ev, $co, true, 0);
    TIX_Co_Money::apply($ev, ['owner' => 'co', 'share' => 25]);
    echo wp_json_encode(['ev' => $ev, 'seller' => TIX_Co_Seller::seller($ev)]) . "\n"; return;
}
if ($mode === 'sync-kk') {
    echo "== Kunden-Seite: Abgleich\n";
    $r = TIX_Site_Move::sync_co_events();
    cse_eq('Abgleich ok', true, !is_wp_error($r));
    $m = get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_platform_co', 's' => 'CSETEST']);
    $m = $m ? intval($m[0]) : 0;
    cse_eq('Spiegel verkauft selbst, Kategorien, Anteil', [true, '1', 1, 25.0], [TIX_Co_Seller::sells_local($m), get_post_meta($m, '_tix_tickets_enabled', true), count((array) get_post_meta($m, '_tix_ticket_categories', true)), TIX_Co_Money::share($m)]);
    $done(); return;
}
if ($mode === 'run-plat') {
    $s = json_decode((string) $args[1], true); $ev = intval($s['ev']);
    echo "== Plattform: Kasse über die Kunden-Seite\n";
    cse_eq('Lokale ID gemeldet, Broker an die Kunden-Seite', [true, 'csetest'], [intval(get_post_meta($ev, TIX_Co_Seller::M_SOURCE, true)) > 0, (string) (TIX_Partner_Broker::partner_for_event($ev)['id'] ?? '')]);
    [$st, $d, $c] = cse_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 2]]]);
    cse_eq('Angebot kommt von der Kunden-Seite', 200, $st);
    if ($st !== 200) echo "      $c " . ($d['message'] ?? '') . "\n";
    [$st, $d, $c] = cse_rest('POST', '/customer/orders', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 2]], 'accept_terms' => 1, 'idempotency_token' => 'cse' . wp_rand(100000, 999999), 'billing' => $billing]);
    $bid = intval($d['order']['id'] ?? 0);
    cse_eq('Bestellung vermittelt (Broker-ID)', [true, true], [in_array($st, [200, 201], true), TIX_Partner_Broker::exists($bid)]);
    if ($st >= 400) echo "      $c " . ($d['message'] ?? '') . "\n";
    [$st, $d2] = cse_rest('GET', "/customer/orders/$bid", ['key' => (string) ($d['order']['key'] ?? '')]);
    cse_eq('Status: bezahlt (gratis), 2 Tickets, Verkäufer Kunden-Seite', [200, true, 2], [$st, !empty($d2['order']['paid']), count($d2['order']['tickets'] ?? [])]);
    $done(); return;
}
if ($mode === 'check-kk') {
    echo "== Kunden-Seite: Bestellung liegt hier\n";
    $m = get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_platform_co', 's' => 'CSETEST']);
    $m = $m ? intval($m[0]) : 0;
    $n = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_order_items WHERE event_id = %d", $m)));
    $t = get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_event_id', 'meta_value' => $m]);
    cse_eq('Bestellung und echte Tickets auf der Kunden-Seite', [1, 2], [$n, count($t)]);
    cse_eq('Gratis-Bestellung ist kein bezahlter Verkauf (keine Sperre)', false, TIX_Co_Money::sold($m));
    $done(); return;
}
if ($mode === 'cleanup-kk') {
    foreach (get_posts(['post_type' => 'event', 'post_status' => ['any', 'trash'], 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_platform_co']) as $id) {
        foreach ($wpdb->get_col($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}tix_order_items WHERE event_id = %d", $id)) as $oid) {
            $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $oid]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $oid)); delete_option('_tix_order_partner_' . $oid);
        }
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_event_id', 'meta_value' => $id]) as $t) wp_delete_post($t, true);
        wp_delete_post($id, true);
    }
    delete_option('tix_platform_co_sync'); delete_option(TIX_Partner_API::QUEUE);
    echo "cleanup-kk ok\n"; return;
}
if ($mode === 'cleanup-plat') {
    $b = get_option('tix_cse_backup');
    foreach (get_posts(['post_type' => ['event', 'tix_organizer'], 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 's' => 'CSETEST']) as $id) {
        if (get_post_type($id) === 'event') {
            foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tix_partner_orders WHERE event_id = %d", $id)) as $bid) {
                foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $bid]) as $t) wp_delete_post($t, true);
                $wpdb->delete($wpdb->prefix . 'tix_partner_orders', ['id' => $bid]);
            }
        }
        wp_delete_post($id, true);
    }
    if (is_array($b)) { if ($b['partners'] === null) delete_option(TIX_Partners::OPTION); else update_option(TIX_Partners::OPTION, $b['partners'], false); }
    delete_option('tix_cse_backup');
    echo "cleanup-plat ok\n"; return;
}
echo "Modus fehlt\n";
