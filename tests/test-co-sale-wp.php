<?php
/**
 * Vermittlung über die Plattform, Paket 2 (1.38.712) – Plattform-Teil mit nachgestellter Quelle (kk.test).
 *   plat eval-file …/test-co-sale-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Sale')) { echo "Tixomat ab 1.38.712 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) { echo "nur Plattform\n"; return; }
global $wpdb;
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cy_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function cy_rest($method, $route, array $body = [], array $headers = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    foreach ($headers as $k => $v) $r->set_header($k, $v);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
$GLOBALS['cy_calls'] = [];
add_filter('pre_http_request', function ($pre, $a, $url) {
    if (strpos($url, 'kk.test/') === false) return $pre;
    $body = json_decode((string) ($a['body'] ?? ''), true);
    $GLOBALS['cy_calls'][] = ['url' => $url, 'body' => $body];
    if (strpos($url, '/partner/quote') !== false) $r = ['ok' => true, 'event_id' => 1171, 'sale_open' => true, 'categories' => [['index' => 0, 'name' => 'VVK', 'price' => 20]], 'items' => [], 'totals' => ['total' => 20]];
    elseif (preg_match('#/partner/orders$#', $url)) $r = ['ok' => true, 'order' => ['id' => 555, 'key' => 'srckey', 'order_number' => 'KK-555', 'status' => 'pending', 'paid' => false, 'total' => 20, 'billing_email' => 'cy@example.test'], 'tickets' => [], 'payment_url' => 'https://pay.kk.test/x', 'legal' => []];
    else $r = ['ok' => true, 'order' => ['id' => 555, 'key' => 'srckey', 'status' => 'pending', 'paid' => false], 'tickets' => []];
    return ['headers' => [], 'body' => wp_json_encode($r), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, 10, 3);
add_filter('pre_wp_mail', function () { return true; });
$tmp_p = []; $tmp_u = [];
$p_backup = get_option(TIX_Partners::OPTION, null);
$orders = [];
try {
    $u = wp_insert_user(['user_login' => 'cy_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'cy_' . wp_rand(1000, 99999) . '@example.test', 'role' => 'tix_organizer']); $tmp_u[] = $u;
    $oa = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Cytest Ersteller', 'meta_input' => ['_tix_org_user_id' => $u]]); $tmp_p[] = $oa;
    $okmt = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Cytest KMT']); $tmp_p[] = $okmt;
    $okk = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Cytest KK']); $tmp_p[] = $okk;
    $kmt_key = 'tixp_' . str_repeat('m', 32);
    $parts = is_array($p_backup) ? $p_backup : [];
    $parts['cykmt'] = ['id' => 'cykmt', 'name' => 'Cytest KMT', 'api_base' => 'https://kmt.test/wp-json/tixomat/v1', 'key_in' => $kmt_key, 'key_out' => str_repeat('o', 32), 'organizer_id' => $okmt];
    $parts['cykk'] = ['id' => 'cykk', 'name' => 'Cytest KK', 'api_base' => 'https://kk.test/wp-json/tixomat/v1', 'key_in' => 'tixp_' . str_repeat('c', 32), 'key_out' => str_repeat('q', 32), 'organizer_id' => $okk,
        'sales_enabled' => 1, 'terms_url' => 'https://kk.test/agb/', 'channel_fee' => 1, 'channel_fee_percent' => '5'];
    update_option(TIX_Partners::OPTION, $parts, false);
    TIX_Platform_Customers::flush();
    $hk = ['X-Tix-Syndication-Key' => $kmt_key];

    // Fall D: eigenes Event, KMT ist Co (kostenlose Kategorie → Bestellung sofort bezahlt)
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cytest Party', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-06-01', '_tix_time_start' => '20:00',
        '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => [['name' => 'Gratis', 'price' => 0, 'qty' => 50, 'stock' => 50]]]]);
    $tmp_p[] = $ev;
    TIX_Co_Organizer::set($ev, $okmt, true, 0);
    [$st, $d] = cy_rest('GET', '/partner/co-events', [], $hk);
    $it = array_column($d['items'] ?? [], null, 'id')[$ev] ?? [];
    cy_eq('co-events: Verkauf bereit, Verkäufer, Kategorien', [200, true, 'Cytest Ersteller', 1], [$st, $it['sale'] ?? null, $it['seller'] ?? '', count($it['categories'] ?? [])]);
    [$st, $d] = cy_rest('POST', "/partner/co-events/$ev/sale/quote", ['items' => [['index' => 0, 'qty' => 2]]], $hk);
    cy_eq('quote über die Kunden-Seite', [200, $ev], [$st, intval($d['event_id'] ?? 0)]);
    cy_eq('quote ohne Schlüssel: 401', 401, cy_rest('POST', "/partner/co-events/$ev/sale/quote", ['items' => [['index' => 0, 'qty' => 1]]])[0]);
    [$st, , $c] = cy_rest('POST', "/partner/co-events/$ev/sale/orders", ['items' => [['index' => 0, 'qty' => 1]], 'billing' => ['first_name' => 'Cy', 'last_name' => 'Test', 'email' => 'cy@example.test', 'address_1' => 'Weg 1', 'postcode' => '12345', 'city' => 'Ort', 'country' => 'DE'],
        'accept_terms' => 1, 'idempotency_token' => 'cy' . wp_rand(100000, 999999), 'return_url' => 'https://fremd.test/danke'], $hk);
    cy_eq('Rücksprung auf fremde Seite: 400', [400, 'tix_partner_return_url'], [$st, $c]);
    [$st, $d, $c] = cy_rest('POST', "/partner/co-events/$ev/sale/orders", ['items' => [['index' => 0, 'qty' => 1]], 'billing' => ['first_name' => 'Cy', 'last_name' => 'Test', 'email' => 'cy@example.test', 'address_1' => 'Weg 1', 'postcode' => '12345', 'city' => 'Ort', 'country' => 'DE'],
        'accept_terms' => 1, 'idempotency_token' => 'cy' . wp_rand(100000, 999999), 'return_url' => 'https://kmt.test/danke'], $hk);
    $oid = intval($d['order']['id'] ?? 0); if ($oid) $orders[] = $oid;
    cy_eq('Bestellung über die Kunden-Seite', [true, true], [in_array($st, [200, 201], true), $oid > 0]);
    if ($st >= 400) echo "      $c " . ($d['message'] ?? '') . "\n";
    cy_eq('Rücksprung gespeichert + vertraut', ['https://kmt.test/danke', 'kmt.test'], [(string) get_post_meta($oid, TIX_Native_Checkout::META_RETURN_URL, true), (string) get_post_meta($oid, TIX_Native_Checkout::META_RETURN_TRUSTED, true)]);
    cy_eq('Rücksprung-Adresse wird gebaut', true, strpos(TIX_Native_Checkout::return_redirect_url($oid, (string) ($d['order']['key'] ?? '')), 'https://kmt.test/danke') === 0);
    { // eigene Bestell-IDs der Test-DB liegen im Broker-Bereich (ab 800000001) – Status muss trotzdem gehen (1.38.712)
        [$st, $d2] = cy_rest('GET', "/partner/co-events/$ev/sale/orders/$oid", ['key' => (string) ($d['order']['key'] ?? '')], $hk);
        cy_eq('Status über die Kunden-Seite: bezahlt, Tickets', [200, true, true], [$st, !empty($d2['order']['paid']), count($d2['order']['tickets'] ?? []) >= 1]);
        cy_eq('Status mit falschem Schlüssel: Fehler', true, cy_rest('GET', "/partner/co-events/$ev/sale/orders/$oid", ['key' => 'falsch'], $hk)[0] >= 400);
    }

    // Co nimmt ein → hier nicht (Paket 3)
    update_post_meta($ev, TIX_Co_Money::META_OWNER, 'co');
    [$st, , $c] = cy_rest('POST', "/partner/co-events/$ev/sale/quote", ['items' => [['index' => 0, 'qty' => 1]]], $hk);
    cy_eq('Co nimmt ein: 409 nicht bereit', [409, 'tix_co_sale_unavailable'], [$st, $c]);
    delete_post_meta($ev, TIX_Co_Money::META_OWNER);

    // Fall C: Spiegel eines KK-Events, KMT ist Co (Teil B), Broker an kk.test
    $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cytest Spiegel', 'meta_input' => ['_tix_organizer_id' => $okk, '_tix_date_start' => '2027-07-01',
        '_tix_syndicated' => '1', '_tix_source_partner' => 'cykk', '_tix_source_id' => 1171, '_tix_source_site' => 'Cytest KK', '_tix_partner_sales' => '1', '_tix_tickets_enabled' => '1',
        '_tix_co_remote_oid' => $okmt, '_tix_co_remote_access' => '1', '_tix_co_remote_token' => str_repeat('y', 48)]]);
    $tmp_p[] = $mir;
    [$st, $d] = cy_rest('GET', '/partner/co-events', [], $hk);
    $it = array_column($d['items'] ?? [], null, 'id')[$mir] ?? [];
    cy_eq('Fall C: Spiegel verkaufsbereit, Verkäufer KK', [true, 'Cytest KK'], [$it['sale'] ?? null, $it['seller'] ?? '']);
    $GLOBALS['cy_calls'] = [];
    [$st] = cy_rest('POST', "/partner/co-events/$mir/sale/quote", ['items' => [['index' => 0, 'qty' => 1]]], $hk);
    $q = array_values(array_filter($GLOBALS['cy_calls'], function ($c) { return strpos($c['url'], '/partner/quote') !== false; }));
    cy_eq('Fall C quote: an KK, ohne Kanalgebühr', [200, 1, null], [$st, count($q), $q[0]['body']['channel_fee'] ?? null]);
    // Gegenprobe: Verkauf auf der Plattform selbst trägt die Kanalgebühr
    $GLOBALS['cy_calls'] = [];
    cy_rest('POST', '/customer/cart/quote', ['event_id' => $mir, 'items' => [['index' => 0, 'qty' => 1]]]);
    $q2 = array_values(array_filter($GLOBALS['cy_calls'], function ($c) { return strpos($c['url'], '/partner/quote') !== false; }));
    cy_eq('Gegenprobe Plattform-Kasse: mit Kanalgebühr', true, is_array($q2[0]['body']['channel_fee'] ?? null));
    $GLOBALS['cy_calls'] = [];
    [$st, $d] = cy_rest('POST', "/partner/co-events/$mir/sale/orders", ['items' => [['index' => 0, 'qty' => 1]], 'billing' => ['first_name' => 'Cy', 'last_name' => 'Test', 'email' => 'cy@example.test', 'address_1' => 'Weg 1', 'postcode' => '12345', 'city' => 'Ort', 'country' => 'DE'],
        'accept_terms' => 1, 'idempotency_token' => 'cy' . wp_rand(100000, 999999), 'return_url' => 'https://kmt.test/danke'], $hk);
    $o = array_values(array_filter($GLOBALS['cy_calls'], function ($c) { return preg_match('#/partner/orders$#', $c['url']); }));
    cy_eq('Fall C Bestellung: Rücksprung an KK weitergereicht, ohne Gebühr', [true, 'https://kmt.test/danke', false], [in_array($st, [200, 201], true), (string) ($o[0]['body']['return_url'] ?? ''), is_array($o[0]['body']['channel_fee'] ?? null)]);
    cy_eq('Fall C: Zahlungsseite von KK', 'https://pay.kk.test/x', (string) ($d['order']['payment_url'] ?? ''));
    if (!empty($d['order']['id'])) $wpdb->delete($wpdb->prefix . 'tix_partner_orders', ['id' => intval($d['order']['id'])]);
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($orders as $oid) {
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_order_id', 'meta_value' => $oid]) as $t) wp_delete_post($t, true);
        $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $oid]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $oid]);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $oid));
    }
    if ($p_backup === null) delete_option(TIX_Partners::OPTION); else update_option(TIX_Partners::OPTION, $p_backup, false);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tmp_p as $id) if ($id) wp_delete_post($id, true);
    foreach ($tmp_u as $id) if ($id) wp_delete_user($id);
    TIX_Platform_Customers::flush();
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
