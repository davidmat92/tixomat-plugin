<?php
/**
 * Co als Verkaufsinstanz, Paket 3 (1.38.713). Gegenseiten per pre_http_request nachgestellt.
 *   plat eval-file …/test-co-seller-wp.php
 *   kk   eval-file …/test-co-seller-wp.php setup | run | cleanup
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Seller')) { echo "Tixomat ab 1.38.713 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = isset($args[0]) ? (string) $args[0] : 'run';
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
global $wpdb;
if (!$multi && $mode === 'setup') {
    $s = get_option('tix_settings', []);
    update_option('tix_cz_backup', ['had' => is_array($s), 'settings' => $s], false);
    $s = array_merge(is_array($s) ? $s : [], ['syndication_enabled' => 1, 'syndication_api_url' => 'https://plat.test/wp-json/tixomat/v1', 'syndication_api_key' => str_repeat('s', 32),
        'partner_api_enabled' => 1, 'partner_api_key' => str_repeat('p', 32), 'co_remote_enabled' => 1]);
    update_option('tix_settings', $s);
    echo "setup ok\n"; return;
}
if (!$multi && $mode === 'cleanup') {
    $b = get_option('tix_cz_backup');
    if (is_array($b)) { if ($b['had']) update_option('tix_settings', $b['settings']); else delete_option('tix_settings'); }
    delete_option('tix_cz_backup');
    echo "cleanup ok\n"; return;
}
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cz_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function cz_rest($method, $route, array $body = [], array $headers = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    foreach ($headers as $k => $v) $r->set_header($k, $v);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
$GLOBALS['cz_calls'] = []; $GLOBALS['cz_reply'] = null;
add_filter('pre_http_request', function ($pre, $a, $url) {
    if (strpos($url, '.test/') === false) return $pre;
    $GLOBALS['cz_calls'][] = ['url' => $url, 'body' => json_decode((string) ($a['body'] ?? ''), true), 'headers' => $a['headers'] ?? []];
    $r = is_callable($GLOBALS['cz_reply']) ? call_user_func($GLOBALS['cz_reply'], $url, $a) : [200, ['ok' => true]];
    return ['headers' => [], 'body' => wp_json_encode($r[1]), 'response' => ['code' => $r[0], 'message' => ''], 'cookies' => [], 'filename' => null];
}, 10, 3);
add_filter('pre_wp_mail', function () { return true; });
function cz_last($needle) { foreach (array_reverse($GLOBALS['cz_calls']) as $c) if (strpos($c['url'], $needle) !== false) return $c; return null; }
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = intval($admin[0]->ID);
$tmp_p = []; $tmp_u = []; $orders = [];

if (!$multi) {
    if (!get_option('tix_cz_backup')) { echo "Erst `setup` ausführen.\n"; return; }
    echo "== Lizenzseite / Kunden-Seite\n";
    try {
        wp_set_current_user($admin_id);
        // Lizenzseite: eigenes Event, Co verkauft
        $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cztest KK-Event', 'meta_input' => ['_tix_date_start' => '2027-02-27', '_tix_syndicate' => '1', '_tix_syndicate_remote_id' => 555,
            '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => [['name' => 'VVK', 'price' => 20, 'qty' => 100, 'stock' => 100]]]]);
        $tmp_p[] = $ev;
        update_post_meta($ev, '_tix_co_remote', ['organizer_id' => 88, 'name' => 'KMT Test', 'access' => true, 'status' => 'active', 'since' => gmdate('c'), 'site' => ['key' => 'h-kmt', 'host' => 'kmt.test', 'name' => 'KMT Test']]);
        $m = (cz_rest('GET', "/events/$ev/edit")[1]['event'] ?? [])['co_money'] ?? [];
        cz_eq('Lizenzseite: Co wählbar', ['creator', 'co'], $m['choosable'] ?? []);
        $GLOBALS['cz_reply'] = function ($url) { return [409, ['code' => 'tix_co_money_unsupported', 'message' => 'Die Seite des Co-Veranstalters ist noch nicht mit der Plattform verbunden.', 'data' => ['status' => 409, 'reason' => 'site_not_ready']]]; };
        [$st, , $c] = cz_rest('POST', "/events/$ev", ['co_money' => ['owner' => 'co', 'share' => 10]]);
        cz_eq('Plattform lehnt ab: 409, nichts gespeichert', [409, 'tix_co_money_unsupported', 'creator'], [$st, $c, TIX_Co_Money::owner($ev)]);
        $GLOBALS['cz_reply'] = function ($url) {
            if (strpos($url, '/syndicate/555/co-organizer') !== false) return [200, ['ok' => true, 'seller' => 'site']];
            if (strpos($url, '/partner/sale/555/quote') !== false) return [200, ['ok' => true, 'event_id' => 6453, 'sale_open' => true, 'categories' => [['index' => 0, 'name' => 'VVK', 'price' => 20]], 'syndicated' => null]];
            if (strpos($url, '/partner/sale/555/balance') !== false) return [200, ['ok' => true, 'source' => 'seller', 'net' => 500.0, 'share' => 10, 'share_amount' => 50.0]];
            return [200, ['ok' => true]];
        };
        [$st] = cz_rest('POST', "/events/$ev", ['co_money' => ['owner' => 'co', 'share' => 10]]);
        $c = cz_last('/syndicate/555/co-organizer');
        cz_eq('Co nimmt ein: an die Plattform gemeldet + gespeichert', [200, 'money', 'co', 10.0], [$st, $c['body']['action'] ?? '', TIX_Co_Money::owner($ev), TIX_Co_Money::share($ev)]);
        cz_eq('Kasse vermittelt an die Plattform', '/partner/sale/555', TIX_Co_Sale::target($ev));
        [$st, $d] = cz_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]]]);
        cz_eq('Angebot über die Plattform, Verkäufer KMT', [200, $ev, true, 'KMT Test'], [$st, intval($d['event_id'] ?? 0), !empty($d['syndicated']['brokered']), $d['syndicated']['site'] ?? '']);
        [$st, , $c] = cz_rest('POST', "/events/$ev", ['categories' => [['name' => 'VVK', 'price' => 25, 'qty' => 100]]]);
        cz_eq('Ticket-Felder gesperrt', [409, 'tix_co_money_seller'], [$st, $c]);
        [$st] = cz_rest('POST', "/events/$ev", ['title' => 'Cztest KK-Event neu']);
        cz_eq('Andere Felder bleiben änderbar', 200, $st);
        $b = cz_rest('GET', "/events/$ev/co-balance")[1];
        cz_eq('Ausgleich von der Verkaufsinstanz', ['seller', 50.0], [$b['source'] ?? '', floatval($b['share_amount'] ?? 0)]);
        $map = (array) get_option(TIX_Co_Sale::MAP, []);
        $map[999999999] = ['pid' => 1, 'key' => 'k', 'event' => $ev, 'base' => '/partner/sale/555', 'paid' => 1];
        update_option(TIX_Co_Sale::MAP, $map, false);
        cz_eq('Verkauf über die Vermittlung zählt als verkauft', true, TIX_Co_Money::sold($ev));
        unset($map[999999999]); update_option(TIX_Co_Sale::MAP, $map, false);

        // Kunden-Seite: verkaufsfähiger Spiegel
        $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cztest Spiegel', 'meta_input' => ['_tix_platform_co' => 4242, '_tix_platform_co_by' => 'KitchenKlub', '_tix_tickets_enabled' => '0', '_tix_date_start' => '2027-02-27']]);
        $tmp_p[] = $mir;
        TIX_Co_Seller::apply_sync($mir, ['sell' => true, 'share' => 15, 'categories' => [['name' => 'VVK', 'price' => 20, 'qty' => 100, 'stock' => 100]], 'presale' => ['_tix_presale_active' => '1']]);
        cz_eq('Spiegel verkauft selbst', [true, '1', 1, 'co', 15.0], [TIX_Co_Seller::sells_local($mir), get_post_meta($mir, '_tix_tickets_enabled', true), count((array) get_post_meta($mir, '_tix_ticket_categories', true)), TIX_Co_Money::owner($mir), TIX_Co_Money::share($mir)]);
        TIX_Co_Seller::apply_sync($mir, ['sell' => true, 'share' => 15, 'categories' => [], 'presale' => []]);
        cz_eq('Zweiter Abgleich überschreibt Kategorien nicht', 1, count((array) get_post_meta($mir, '_tix_ticket_categories', true)));
        [$st] = cz_rest('POST', "/events/$mir", ['categories' => [['name' => 'VVK', 'price' => 22, 'qty' => 100]]]);
        cz_eq('Ticket-Felder hier änderbar', 200, $st);
        [$st, , $c] = cz_rest('POST', "/events/$mir", ['title' => 'anders']);
        cz_eq('Andere Felder bleiben Spiegel: 409', [409, 'tix_co_platform_event'], [$st, $c]);
        $chk = new ReflectionMethod('TIX_Partner_API', 'check_event'); $chk->setAccessible(true);
        cz_eq('Partner-API nimmt das Event an', $mir, $chk->invoke(null, $mir));
        $GLOBALS['cz_calls'] = [];
        TIX_Co_Seller::report($mir);
        $c = cz_last('/partner/co-events/4242/local');
        cz_eq('Meldung an die Plattform: lokale ID + Ausgleich', [$mir, true], [intval($c['body']['local_id'] ?? 0), is_array($c['body']['balance'] ?? null)]);
        cz_eq('Kasse vermittelt nicht (eigener Verkauf)', '', TIX_Co_Sale::target($mir));
    } catch (Throwable $e) { $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n"; }
    finally { foreach ($tmp_p as $id) wp_delete_post($id, true); wp_set_current_user(0); }
    echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
    return;
}

echo "== Plattform\n";
$p_backup = get_option(TIX_Partners::OPTION, null);
try {
    $mku = function () use (&$tmp_u) { $id = wp_insert_user(['user_login' => 'cz_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'cz_' . wp_rand(1000, 999999) . '@example.test', 'role' => 'tix_organizer']); $tmp_u[] = $id; return $id; };
    $mko = function ($uid, $name, $kind = '') use (&$tmp_p) { $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $name, 'meta_input' => array_filter(['_tix_org_user_id' => $uid, '_tix_org_kind' => $kind])]); $tmp_p[] = $id; return $id; };
    $ua = $mku(); $oa = $mko($ua, 'Cztest Ersteller');
    $ub = $mku(); $ob = $mko($ub, 'Cztest Co evendis');
    $okmt = $mko(0, 'Cztest KMT', 'site');
    $okk = $mko(0, 'Cztest KK');
    $kk_key = 'tixp_' . str_repeat('c', 32); $kmt_key = 'tixp_' . str_repeat('m', 32);
    $parts = is_array($p_backup) ? $p_backup : [];
    $parts['czkk'] = ['id' => 'czkk', 'name' => 'Cztest KK', 'api_base' => 'https://kk.test/wp-json/tixomat/v1', 'key_in' => $kk_key, 'key_out' => str_repeat('q', 32), 'organizer_id' => $okk, 'sales_enabled' => 1, 'channel_fee' => 1, 'channel_fee_percent' => '5'];
    $parts['czkmt'] = ['id' => 'czkmt', 'name' => 'Cztest KMT', 'api_base' => 'https://kmt.test/wp-json/tixomat/v1', 'key_in' => $kmt_key, 'key_out' => str_repeat('o', 32), 'organizer_id' => $okmt];
    update_option(TIX_Partners::OPTION, $parts, false);
    TIX_Platform_Customers::flush();

    // ── Fall B: KK-Event, Co auf evendis.de verkauft
    $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cztest Spiegel B', 'meta_input' => ['_tix_organizer_id' => $okk, '_tix_date_start' => '2027-07-01', '_tix_time_start' => '20:00',
        '_tix_syndicated' => '1', '_tix_source_partner' => 'czkk', '_tix_source_id' => 1171, '_tix_source_site' => 'Cztest KK', '_tix_partner_sales' => '1', '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1',
        '_tix_ticket_categories' => [['name' => 'VVK', 'price' => 20, 'qty' => 100, 'stock' => 100]], '_tix_co_remote_oid' => $ob, '_tix_co_remote_access' => '0', '_tix_co_remote_token' => str_repeat('y', 48)]]);
    $tmp_p[] = $mir;
    $hk = ['X-Tix-Syndication-Key' => $kk_key];
    [$st, , $c] = cz_rest('POST', "/syndicate/$mir/co-organizer", ['action' => 'money', 'owner' => 'co'], $hk);
    cz_eq('B: ohne Zugriff 409', [409, 'tix_co_money_unsupported'], [$st, $c]);
    update_post_meta($mir, '_tix_co_remote_access', '1');
    [$st, $d] = cz_rest('POST', "/syndicate/$mir/co-organizer", ['action' => 'money', 'owner' => 'co', 'share' => 10], $hk);
    cz_eq('B: umgestellt, Plattform verkauft, eingefroren', [200, 'self', 'co', '1'], [$st, $d['seller'] ?? '', TIX_Co_Money::owner($mir), get_post_meta($mir, TIX_Co_Seller::M_FROZEN, true)]);
    cz_eq('B: kein Broker, eigene Kasse, kein „Tickets über“', [null, true, null], [TIX_Partner_Broker::partner_for_event($mir), TIX_Co_Seller::sells_here($mir), TIX_App_Checkout::syndicated_info($mir)]);
    $GLOBALS['cz_calls'] = [];
    [$st, $d] = cz_rest('POST', '/customer/cart/quote', ['event_id' => $mir, 'items' => [['index' => 0, 'qty' => 1]]]);
    cz_eq('B: Angebot der Plattform-Kasse, keine Anfrage an KK', [200, true, 0], [$st, !empty($d['sale_open']), count(array_filter($GLOBALS['cz_calls'], function ($c) { return strpos($c['url'], 'kk.test') !== false; }))]);
    [$st, $d] = cz_rest('POST', "/partner/sale/$mir/quote", ['items' => [['index' => 0, 'qty' => 1]]], $hk);
    cz_eq('B: Lizenzseite vermittelt an die Plattform', [200, $mir], [$st, intval($d['event_id'] ?? 0)]);
    cz_eq('B: falscher Partner 401/404', true, cz_rest('POST', "/partner/sale/$mir/quote", ['items' => []], ['X-Tix-Syndication-Key' => $kmt_key])[0] >= 400);
    cz_eq('B: Abrechnung an den Co', $ob, TIX_Co_Money::settlement_org($mir));
    $wpdb->insert($wpdb->prefix . 'tix_orders', ['order_number' => 'CZT-' . wp_rand(1000, 99999), 'status' => 'completed', 'total' => 40, 'subtotal' => 40, 'tax' => 0, 'discount' => 0, 'payment_method' => 'test_gateway', 'payment_method_title' => 'Test',
        'billing_email' => 'cz@example.test', 'customer_id' => 0, 'wc_order_id' => 0, 'order_key' => 'k' . wp_rand(1000, 9999), 'checked_in' => 0, 'date_created' => current_time('mysql'), 'date_modified' => current_time('mysql')]);
    $o = intval($wpdb->insert_id); $orders[] = $o;
    $wpdb->insert($wpdb->prefix . 'tix_order_items', ['order_id' => $o, 'product_id' => 0, 'event_id' => $mir, 'quantity' => 2, 'total' => 40, 'tax' => 0, 'name' => 'VVK', 'cat_name' => 'VVK', 'meta' => '']);
    $sid = TIX_Settlement::create_for_event($mir, true);
    cz_eq('B: Event-Abrechnung für den Co (geteiltes Event)', [true, $ob], [$sid > 0, intval(TIX_Settlement::get($sid)->organizer_id ?? 0)]);
    if ($sid) { $wpdb->delete(TIX_Settlement::items_table(), ['settlement_id' => $sid]); $wpdb->delete(TIX_Settlement::table(), ['id' => $sid]); }
    $b = cz_rest('GET', "/partner/sale/$mir/balance", [], $hk)[1];
    cz_eq('B: Ausgleich für die Lizenzseite', ['seller', 40.0, 4.0], [$b['source'] ?? '', $b['net'] ?? null, $b['share_amount'] ?? null]);
    [$st, , $c] = cz_rest('POST', "/syndicate/$mir/co-organizer", ['action' => 'money', 'owner' => 'creator'], $hk);
    cz_eq('B: nach Verkauf gesperrt', [409, 'tix_co_money_locked'], [$st, $c]);

    // ── Fall D: Plattform-Event, KMT (Kunden-Seite) verkauft
    wp_set_current_user($ua);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cztest D', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-08-01', '_tix_time_start' => '20:00',
        '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => [['name' => 'VVK', 'price' => 30, 'qty' => 80, 'stock' => 80]]]]);
    $tmp_p[] = $ev;
    TIX_Co_Organizer::set($ev, $okmt, true, $ua);
    $parts = get_option(TIX_Partners::OPTION); $parts['czkmt']['pending'] = 1; update_option(TIX_Partners::OPTION, $parts, false);
    cz_eq('D: Kunden-Seite nicht bereit → nur Ersteller', ['creator'], TIX_Co_Money::payload($ev)['choosable'] ?? []);
    unset($parts['czkmt']['pending']); update_option(TIX_Partners::OPTION, $parts, false);
    [$st] = cz_rest('POST', "/events/$ev", ['co_money' => ['owner' => 'co', 'share' => 20]]);
    cz_eq('D: Co nimmt ein, Verkaufsinstanz Kunden-Seite', [200, 'site'], [$st, TIX_Co_Seller::seller($ev)]);
    [$st, , $c] = cz_rest('POST', "/events/$ev", ['categories' => [['name' => 'VVK', 'price' => 35, 'qty' => 80]]]);
    cz_eq('D: Ticket-Felder beim Ersteller gesperrt', [409, 'tix_co_money_seller'], [$st, $c]);
    $hm = ['X-Tix-Syndication-Key' => $kmt_key];
    [, $d] = cz_rest('GET', '/partner/co-events', [], $hm);
    $it = array_column($d['items'] ?? [], null, 'id')[$ev] ?? [];
    cz_eq('D: Abgleich für KMT: sell, Kategorien, Anteil', [true, 1, 20.0, false], [$it['sell'] ?? null, count($it['categories'] ?? []), floatval($it['share'] ?? 0), !empty($it['sale'])]);
    cz_eq('D: vor der Meldung kein Broker', null, TIX_Partner_Broker::partner_for_event($ev));
    [$st] = cz_rest('POST', "/partner/co-events/$ev/local", ['local_id' => 77, 'sold' => false, 'balance' => ['net' => 300.0, 'share' => 20, 'share_amount' => 60.0]], $hm);
    cz_eq('D: KMT meldet lokale ID', [200, 77, 'czkmt'], [$st, intval(get_post_meta($ev, TIX_Co_Seller::M_SOURCE, true)), (string) get_post_meta($ev, TIX_Co_Seller::M_PARTNER, true)]);
    cz_eq('D: Broker an KMT', 'czkmt', (string) (TIX_Partner_Broker::partner_for_event($ev)['id'] ?? ''));
    $GLOBALS['cz_reply'] = function ($url) { return [200, ['ok' => true, 'event_id' => 77, 'sale_open' => true, 'categories' => [['index' => 0, 'name' => 'VVK', 'price' => 30]]]]; };
    $GLOBALS['cz_calls'] = [];
    [$st] = cz_rest('POST', '/customer/cart/quote', ['event_id' => $ev, 'items' => [['index' => 0, 'qty' => 1]]]);
    $q = cz_last('kmt.test/wp-json/tixomat/v1/partner/quote');
    cz_eq('D: Plattform-Kasse fragt KMT, Event 77, ohne Gebühr', [200, 77, null], [$st, intval($q['body']['event_id'] ?? 0), $q['body']['channel_fee'] ?? null]);
    [$st, , $c] = cz_rest('POST', "/partner/co-events/$ev/local", ['local_id' => 78], ['X-Tix-Syndication-Key' => $kk_key]);
    cz_eq('D: fremde Seite darf nicht melden', true, $st >= 400);
    [$st] = cz_rest('POST', "/partner/co-events/$ev/local", ['local_id' => 77, 'sold' => true], $hm);
    cz_eq('D: verkauft gemeldet → gesperrt', [true, 'sold'], [TIX_Co_Money::sold($ev), TIX_Co_Money::payload($ev)['locked_reason'] ?? '']);
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($orders as $o) { $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]); }
    if ($p_backup === null) delete_option(TIX_Partners::OPTION); else update_option(TIX_Partners::OPTION, $p_backup, false);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tmp_p as $id) if ($id) { $wpdb->query($wpdb->prepare("DELETE FROM " . TIX_Settlement::items_table() . " WHERE organizer_id = %d OR event_id = %d", $id, $id)); wp_delete_post($id, true); }
    foreach ($tmp_u as $id) if ($id) wp_delete_user($id);
    TIX_Platform_Customers::flush();
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
