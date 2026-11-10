<?php
/**
 * Zwei-Seiten-Test Paket 2 (1.38.712): Kunden-Seite (kk) vermittelt ein Plattform-Event (plat) über echtes HTTP.
 *   plat eval-file … setup-plat <kunden-host> <schluessel-der-kunden-seite>   → JSON {ev, org, co}
 *   kk   eval-file … run-kk <json>
 *   kk   eval-file … cleanup-kk   ·   plat eval-file … cleanup-plat
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Sale')) { echo "Tixomat ab 1.38.712 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = (string) ($args[0] ?? '');
global $wpdb;
$GLOBALS['ce_f'] = 0; $GLOBALS['ce_n'] = 0;
function ce_eq($label, $exp, $act) {
    $GLOBALS['ce_n']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['ce_f']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function ce_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
if ($mode === 'setup-plat') {
    $host = (string) $args[1]; $key = (string) $args[2];
    update_option('tix_ce_backup', ['partners' => get_option(TIX_Partners::OPTION, null)], false);
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CETEST Ersteller']);
    $co = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CETEST Kunden-Seite']);
    $all = (array) get_option(TIX_Partners::OPTION, []);
    $all['cetest'] = ['id' => 'cetest', 'name' => 'CETEST Kunden-Seite', 'api_base' => 'http://' . $host . '/wp-json/tixomat/v1', 'key_in' => $key, 'key_out' => 'tixp_' . str_repeat('o', 32), 'organizer_id' => $co];
    update_option(TIX_Partners::OPTION, $all, false);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CETEST Party', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => '2027-08-01', '_tix_time_start' => '20:00',
        '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => [['name' => 'Gratis', 'price' => 0, 'qty' => 50, 'stock' => 50]]]]);
    TIX_Co_Organizer::set($ev, $co, true, 0);
    echo wp_json_encode(['ev' => $ev, 'org' => $org, 'co' => $co]) . "\n"; return;
}
if ($mode === 'cleanup-plat') {
    $b = get_option('tix_ce_backup');
    foreach (get_posts(['post_type' => ['event', 'tix_organizer'], 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 's' => 'CETEST']) as $id) {
        if (get_post_type($id) === 'event') foreach ($wpdb->get_col($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}tix_order_items WHERE event_id = %d", $id)) as $oid) {
            foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid]) as $t) wp_delete_post($t, true);
            $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $oid]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $oid));
        }
        wp_delete_post($id, true);
    }
    if (is_array($b)) { if ($b['partners'] === null) delete_option(TIX_Partners::OPTION); else update_option(TIX_Partners::OPTION, $b['partners'], false); }
    delete_option('tix_ce_backup');
    echo "cleanup-plat ok\n"; return;
}
if ($mode === 'cleanup-kk') {
    foreach (get_posts(['post_type' => 'event', 'post_status' => ['any', 'trash'], 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_platform_co']) as $id) wp_delete_post($id, true);
    foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_co_sale']) as $id) wp_delete_post($id, true);
    delete_option(TIX_Co_Sale::MAP); delete_option('tix_platform_co_sync');
    echo "cleanup-kk ok\n"; return;
}
if ($mode === 'run-kk') {
    $s = json_decode((string) $args[1], true);
    echo "== Kunden-Seite\n";
    $r = TIX_Site_Move::sync_co_events();
    ce_eq('Abgleich ok', true, !is_wp_error($r));
    if (is_wp_error($r)) { echo '      ' . $r->get_error_message() . "\n"; }
    $m = get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_platform_co', 'meta_value' => strval($s['ev'])]);
    $m = $m ? intval($m[0]) : 0;
    ce_eq('Spiegel verkauft über die Plattform', ['1', '1', 'CETEST Ersteller', 1], [get_post_meta($m, '_tix_platform_co_sale', true), get_post_meta($m, '_tix_tickets_enabled', true), get_post_meta($m, '_tix_platform_co_seller', true), count((array) get_post_meta($m, '_tix_ticket_categories', true))]);
    if (method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
    $e = ce_rest('GET', "/public/events/$m")[1]['event'] ?? [];
    ce_eq('Öffentlich: Verkauf an, sale_via_app, Verkäufer', [true, true, 'CETEST Ersteller'], [$e['tickets_enabled'] ?? null, $e['syndicated']['sale_via_app'] ?? null, $e['syndicated']['site'] ?? '']);
    [$st, $d] = ce_rest('POST', '/customer/cart/quote', ['event_id' => $m, 'items' => [['index' => 0, 'qty' => 2]]]);
    ce_eq('Kasse (Angebot) über die Plattform', [200, $m, true], [$st, intval($d['event_id'] ?? 0), !empty($d['syndicated']['brokered'])]);
    [$st, $d, $c] = ce_rest('POST', '/customer/orders', ['event_id' => $m, 'items' => [['index' => 0, 'qty' => 2]], 'accept_terms' => 1, 'idempotency_token' => 'ce' . wp_rand(100000, 999999),
        'billing' => ['first_name' => 'Ce', 'last_name' => 'Test', 'email' => 'ce@example.test', 'address_1' => 'Weg 1', 'postcode' => '12345', 'city' => 'Ort', 'country' => 'DE']]);
    $lid = intval($d['order']['id'] ?? 0);
    ce_eq('Bestellung: lokale ID, bezahlt (gratis)', [true, true, true], [in_array($st, [200, 201], true), $lid >= TIX_Co_Sale::ID_START, !empty($d['order']['paid'])]);
    if ($st >= 400) echo "      $c " . ($d['message'] ?? '') . "\n";
    [$st, $d2] = ce_rest('GET', "/customer/orders/$lid", ['key' => (string) ($d['order']['key'] ?? '')]);
    $codes = array_column($d2['order']['tickets'] ?? [], 'code');
    ce_eq('Status: bezahlt, 2 Tickets, Verkäufer', [200, true, 2, 'CETEST Ersteller'], [$st, !empty($d2['order']['paid']), count($codes), $d2['order']['seller']['name'] ?? '']);
    $mirrors = get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_co_sale', 'meta_value' => strval($lid)]);
    ce_eq('Ticket-Spiegel lokal (für „Meine Tickets“)', [2, $m, 'ce@example.test'], [count($mirrors), intval(get_post_meta($mirrors[0] ?? 0, '_tix_ticket_event_id', true)), (string) get_post_meta($mirrors[0] ?? 0, '_tix_ticket_owner_email', true)]);
    ce_eq('Status mit falschem Schlüssel: 404', 404, ce_rest('GET', "/customer/orders/$lid", ['key' => 'falsch'])[0]);
    [$st, $d3] = ce_rest('GET', "/customer/orders/$lid", ['key' => (string) ($d['order']['key'] ?? '')]);
    ce_eq('Zweiter Abruf: keine doppelten Spiegel', 2, count(get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_co_sale', 'meta_value' => strval($lid)])));
    $rd = class_exists('TIX_Owner_Access') ? TIX_Owner_Access::readiness() : [];
    ce_eq('Startklar kennt payments_needed', true, array_key_exists('payments_needed', $rd));
    echo ($GLOBALS['ce_f'] ? "FEHLER: {$GLOBALS['ce_f']}" : 'ALLES OK') . " ({$GLOBALS['ce_n']} Prüfungen)\n"; return;
}
echo "Modus fehlt\n";
