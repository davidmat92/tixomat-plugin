<?php
/**
 * Verkäufe der Quellseite im Plattform-Studio (1.38.719).
 *   kk   eval-file …/test-source-sales-wp.php setup | run | cleanup   (Quelle)
 *   plat eval-file …/test-source-sales-wp.php                          (Plattform, Quelle per pre_http_request)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Source_Sales')) { echo "Tixomat ab 1.38.719 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = isset($args[0]) ? (string) $args[0] : 'run';
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
global $wpdb;
if (!$multi && $mode === 'setup') {
    $s = get_option('tix_settings', []);
    update_option('tix_ss_backup', ['had' => is_array($s), 'settings' => $s, 'opt' => get_option(TIX_Source_Sales::OPT, null)], false);
    $s = array_merge(is_array($s) ? $s : [], ['syndication_enabled' => 1, 'syndication_api_url' => 'https://plat.test/wp-json/tixomat/v1', 'syndication_api_key' => str_repeat('s', 32),
        'partner_api_enabled' => 0, 'partner_api_key' => str_repeat('p', 32)]); // Verkauf über die Plattform aus: Zahlen trotzdem lesbar
    update_option('tix_settings', $s);
    delete_option(TIX_Source_Sales::OPT);
    echo "setup ok\n"; return;
}
if (!$multi && $mode === 'cleanup') {
    $b = get_option('tix_ss_backup');
    if (is_array($b)) {
        if ($b['had']) update_option('tix_settings', $b['settings']); else delete_option('tix_settings');
        if ($b['opt'] === null) delete_option(TIX_Source_Sales::OPT); else update_option(TIX_Source_Sales::OPT, $b['opt'], false);
    }
    delete_option('tix_ss_backup');
    echo "cleanup ok\n"; return;
}
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function ss_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function ss_rest($method, $route, array $body = [], array $headers = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    foreach ($headers as $k => $v) $r->set_header($k, $v);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = intval($admin[0]->ID);
$posts = []; $orders = [];

if (!$multi) {
    // ═══════════════ Quelle ═══════════════
    TIX_Order::create_tables();
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SSTEST Geteilt', 'meta_input' => ['_tix_date_start' => '2027-05-01', '_tix_syndicate' => '1', '_tix_tickets_enabled' => '1',
        '_tix_ticket_categories' => [['name' => 'Regulär', 'price' => 10, 'qty' => 100]]]]);
    $own = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SSTEST Nicht geteilt', 'meta_input' => ['_tix_date_start' => '2027-05-02', '_tix_syndicate' => '0']]);
    $posts = [$ev, $own];
    $ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name();
    $wpdb->insert($ot, ['order_number' => 'SS' . wp_rand(), 'status' => 'completed', 'order_key' => wp_generate_password(12, false), 'total' => 20, 'subtotal' => 20, 'payment_method' => 'mollie',
        'billing_email' => 'kaeufer-geheim@example.test', 'billing_first_name' => 'Kim', 'billing_last_name' => 'Geheimname', 'date_created' => current_time('mysql')]);
    $orders[] = intval($wpdb->insert_id);
    $wpdb->insert($oi, ['order_id' => $orders[0], 'event_id' => $ev, 'quantity' => 2, 'total' => 20, 'name' => 'SSTEST – Regulär', 'cat_name' => 'Regulär']);
    $hd = ['X-Tix-Partner-Id' => 'plat.test', 'X-Tix-Partner-Key' => str_repeat('p', 32)];
    wp_set_current_user(0);

    ss_eq('Schalter Vorgabe an', [true, true], [TIX_Source_Sales::enabled(), TIX_Source_Sales::setting()]);
    [$st, $d] = ss_rest('GET', "/partner/events/$ev/sales", [], $hd);
    $json = wp_json_encode($d);
    ss_eq('Zahlen geliefert, auch ohne „Verkauf über die Plattform“', [200, true, $ev], [$st, is_array($d['statistics'] ?? null), $d['event_id'] ?? 0]);
    ss_eq('Bestellung zählt (1 Bestellung, 20 €)', [1, 20.0], [intval($d['statistics']['total_orders'] ?? 0), floatval($d['statistics']['total_revenue'] ?? 0)]);
    ss_eq('nur erlaubte Felder', [], array_values(array_diff(array_keys((array) ($d['statistics'] ?? [])), TIX_Source_Sales::FIELDS)));
    ss_eq('keine Käuferdaten (E-Mail, Name)', [false, false, false], [strpos($json, 'kaeufer-geheim') !== false, strpos($json, 'Geheimname') !== false, strpos($json, '"email"') !== false]);
    ss_eq('Statistik-Felder wie im Studio vorhanden', [true, true, true, true], [array_key_exists('sales', $d['statistics']), array_key_exists('by_channel', $d['statistics']), array_key_exists('sales_by_day', $d['statistics']), array_key_exists('checkin_rate', $d['statistics'])]);
    ss_eq('danach wieder kein Nutzer angemeldet', 0, get_current_user_id());
    ss_eq('falscher Schlüssel 401', [401, 'tix_partner_auth'], (function ($r) { return [$r[0], $r[2]]; })(ss_rest('GET', "/partner/events/$ev/sales", [], ['X-Tix-Partner-Id' => 'plat.test', 'X-Tix-Partner-Key' => str_repeat('x', 32)])));
    ss_eq('fremde Plattform 401', 401, ss_rest('GET', "/partner/events/$ev/sales", [], ['X-Tix-Partner-Id' => 'boese.test', 'X-Tix-Partner-Key' => str_repeat('p', 32)])[0]);
    ss_eq('nicht geteiltes Event 404', [404, 'tix_event'], (function ($r) { return [$r[0], $r[2]]; })(ss_rest('GET', "/partner/events/$own/sales", [], $hd)));

    // Studio-Schalter
    wp_set_current_user($admin_id);
    [$st, $d] = ss_rest('GET', '/studio/config');
    ss_eq('/studio/config zeigt den Schalter (an)', [200, true], [$st, $d['syndication']['share_sales_with_platform'] ?? null]);
    [$st, $d] = ss_rest('POST', '/studio/config', ['share_sales_with_platform' => false]);
    ss_eq('ausschalten über /studio/config', [200, false, false], [$st, $d['syndication']['share_sales_with_platform'] ?? null, TIX_Source_Sales::enabled()]);
    wp_set_current_user(0);
    ss_eq('Schalter aus → 403 tix_sales_hidden', [403, 'tix_sales_hidden'], (function ($r) { return [$r[0], $r[2]]; })(ss_rest('GET', "/partner/events/$ev/sales", [], $hd)));
    wp_set_current_user($admin_id);
    [$st, $d] = ss_rest('POST', '/studio/config', ['share_sales_with_platform' => true]);
    ss_eq('wieder an', [200, true], [$st, TIX_Source_Sales::enabled()]);
    wp_set_current_user(0);
    ss_eq('Gast darf den Schalter nicht ändern', 401, ss_rest('POST', '/studio/config', ['share_sales_with_platform' => false])[0]);
    ss_eq('Schalter unverändert an', true, TIX_Source_Sales::enabled());
} else {
    // ═══════════════ Plattform ═══════════════
    $GLOBALS['ss_calls'] = []; $GLOBALS['ss_reply'] = null;
    add_filter('pre_http_request', function ($pre, $a, $url) {
        if (strpos($url, 'sskk.test/') === false) return $pre;
        $GLOBALS['ss_calls'][] = ['url' => $url, 'headers' => $a['headers'] ?? []];
        $r = is_callable($GLOBALS['ss_reply']) ? call_user_func($GLOBALS['ss_reply'], $url) : [200, ['ok' => true]];
        if ($r === null) return new WP_Error('http_request_failed', 'down');
        return ['headers' => [], 'body' => wp_json_encode($r[1]), 'response' => ['code' => $r[0], 'message' => ''], 'cookies' => [], 'filename' => null];
    }, 10, 3);
    $bak = get_option(TIX_Partners::OPTION, null);
    $all = (array) get_option(TIX_Partners::OPTION, []);
    $all['sskk'] = ['id' => 'sskk', 'name' => 'SSTEST Festival', 'api_base' => 'https://sskk.test/wp-json/tixomat/v1', 'key_in' => str_repeat('i', 32), 'key_out' => str_repeat('o', 32), 'organizer_id' => 0, 'sales_enabled' => 1];
    update_option(TIX_Partners::OPTION, $all, false);
    $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SSTEST Spiegel', 'meta_input' => ['_tix_syndicated' => '1', '_tix_source_partner' => 'sskk', '_tix_source_id' => 7430, '_tix_source_site' => 'SSTEST Festival']]);
    $nopart = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SSTEST Ohne Partner', 'meta_input' => ['_tix_syndicated' => '1', '_tix_source_site' => 'https://alt.test', '_tix_source_id' => 5]]);
    $local = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SSTEST Eigen', 'meta_input' => ['_tix_date_start' => '2027-05-01']]);
    $posts = [$mir, $nopart, $local];
    $stats = ['total_revenue' => 9340.0, 'total_tickets' => 467, 'total_orders' => 167, 'by_category' => ['Regulär' => ['revenue' => 9340.0, 'tickets' => 467]], 'checkin_rate' => 0];
    $GLOBALS['ss_reply'] = function () use ($stats) { return [200, ['ok' => true, 'event_id' => 7430, 'site' => ['name' => 'Mallorca Festival', 'host' => 'sskk.test'], 'statistics' => $stats]]; };

    wp_set_current_user(0);
    ss_eq('ohne Anmeldung 401', 401, ss_rest('GET', "/events/$mir/source-sales")[0]);
    $org_user = wp_insert_user(['user_login' => 'sstest_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'sstest' . wp_rand(1000, 99999) . '@example.test', 'role' => 'tix_organizer']);
    wp_set_current_user($org_user);
    ss_eq('Veranstalter 403 (nur Plattform-Admin)', 403, ss_rest('GET', "/events/$mir/source-sales")[0]);
    wp_set_current_user($admin_id);
    [$st, $d] = ss_rest('GET', "/events/$mir/source-sales");
    $c = $GLOBALS['ss_calls'][0] ?? [];
    ss_eq('Spiegel: Zahlen der Quelle', [200, true, 467, 'Mallorca Festival', 'sskk.test'], [$st, $d['available'] ?? null, $d['statistics']['total_tickets'] ?? 0, $d['site']['name'] ?? '', $d['site']['host'] ?? '']);
    ss_eq('Abruf mit Quell-ID und Partner-Schlüssel', [true, str_repeat('o', 32), TIX_Partners::platform_id()],
        [strpos((string) ($c['url'] ?? ''), '/partner/events/7430/sales') !== false, $c['headers']['X-Tix-Partner-Key'] ?? '', $c['headers']['X-Tix-Partner-Id'] ?? '']);
    ss_rest('GET', "/events/$mir/source-sales");
    ss_eq('60 s Cache: kein zweiter Abruf', 1, count($GLOBALS['ss_calls']));
    $GLOBALS['ss_reply'] = function () { return [403, ['code' => 'tix_sales_hidden', 'message' => 'aus', 'data' => ['status' => 403]]]; };
    [, $d] = ss_rest('GET', "/events/$mir/source-sales", ['fresh' => 1]);
    ss_eq('Quelle teilt nicht → hidden', [false, 'hidden', 'SSTEST Festival'], [$d['available'] ?? null, $d['reason'] ?? '', $d['site']['name'] ?? '']);
    $GLOBALS['ss_reply'] = function () { return null; };
    [, $d] = ss_rest('GET', "/events/$mir/source-sales", ['fresh' => 1]);
    ss_eq('Quelle nicht erreichbar → unreachable', [false, 'unreachable'], [$d['available'] ?? null, $d['reason'] ?? '']);
    $GLOBALS['ss_reply'] = function () { return [404, ['code' => 'rest_no_route', 'message' => 'x', 'data' => ['status' => 404]]]; };
    [, $d] = ss_rest('GET', "/events/$mir/source-sales", ['fresh' => 1]);
    ss_eq('alte Quelle ohne Route → unsupported', [false, 'unsupported'], [$d['available'] ?? null, $d['reason'] ?? '']);
    [, $d] = ss_rest('GET', "/events/$nopart/source-sales");
    ss_eq('Spiegel ohne Partner → no_partner', [false, 'no_partner', 'https://alt.test'], [$d['available'] ?? null, $d['reason'] ?? '', $d['site']['name'] ?? '']);
    [$st, , $code] = ss_rest('GET', "/events/$local/source-sales");
    ss_eq('eigenes Event → 409 tix_not_mirror', [409, 'tix_not_mirror'], [$st, $code]);
    $sy = (array) (ss_rest('GET', '/studio/config')[1]['syndication'] ?? []);
    ss_eq('Plattform: Schalter in /studio/config null', [true, null], [array_key_exists('share_sales_with_platform', $sy), $sy['share_sales_with_platform'] ?? null]);

    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($org_user);
    if ($bak === null) delete_option(TIX_Partners::OPTION); else update_option(TIX_Partners::OPTION, $bak, false);
    foreach ($posts as $p) delete_transient('tix_srcsales_' . $p);
}

foreach ($posts as $p) wp_delete_post($p, true);
if ($orders) { $wpdb->query('DELETE FROM ' . TIX_Order::items_table_name() . ' WHERE order_id IN (' . implode(',', array_map('intval', $orders)) . ')'); $wpdb->query('DELETE FROM ' . TIX_Order::table_name() . ' WHERE id IN (' . implode(',', array_map('intval', $orders)) . ')'); }
delete_transient('tix_srcsales_rl_' . md5('plat.test'));
wp_set_current_user(0);
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
