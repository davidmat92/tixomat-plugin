<?php
/**
 * Angebote & Newsletter (1.38.806): B11 Vorbestellungen per REST, B19 Brevo-Listen-Abgleich (Zeitpunkt nach Bestätigung),
 * B6 Gutscheine je Event (?event_id), B8 Kampagnen je Event (by_campaign, Links ?event_id).
 *   wp eval-file …/test-offers-newsletter-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !method_exists('TIX_Quotes', 'quote_payload')) { echo "Tixomat ab 1.38.806 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$posts = []; $users = []; $codes = []; $links = [];
$opt_before = get_option('tix_settings', []);
$http = [];
$hook = function ($pre, $args, $url) use (&$http) { if (strpos($url, 'api.brevo.com') !== false) { $http[] = [$args['method'] ?? 'GET', $url]; return ['headers' => [], 'body' => '{}', 'response' => ['code' => 201, 'message' => 'Created'], 'cookies' => [], 'filename' => null]; } return $pre; };
add_filter('pre_http_request', $hook, 10, 3);
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $cats = [['name' => 'Normal', 'price' => 20, 'qty' => 100], ['name' => 'VIP', 'price' => 50, 'qty' => 10]];
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AN Event', 'meta_input' => ['_tix_date_start' => '2027-03-01', '_tix_ticket_categories' => $cats]]); $posts[] = $ev;
    $ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AN Anderes', 'meta_input' => ['_tix_date_start' => '2027-03-02', '_tix_ticket_categories' => $cats]]); $posts[] = $ev2;
    if ($multi && class_exists('TIX_App_Scope')) { $o = intval($wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tix_organizer' AND post_status = 'publish' LIMIT 1")); if ($o) { update_post_meta($ev, '_tix_organizer_id', $o); update_post_meta($ev2, '_tix_organizer_id', $o); } }

    // B11 Vorbestellungen
    [$st, $d] = $rest('POST', '/studio/quotes', ['items' => [['event_id' => $ev, 'cat_index' => 1, 'qty' => 2, 'custom_price' => '45,50'], ['event_id' => $ev, 'cat_index' => 0, 'qty' => 1]],
        'customer' => ['first_name' => 'Kim', 'last_name' => 'Test', 'email' => 'kim@example.test'], 'note' => 'Firmenfeier', 'expiry_days' => 14]);
    $q = $d['quote'] ?? []; if (!empty($q['id'])) $posts[] = $q['id'];
    $eq('Vorbestellung anlegen: 201, Summe mit Sonderpreis, offen, Link', [201, 111.0, 'open', true, 'VIP'], [$st, $q['total'] ?? null, $q['status'] ?? '', strpos((string) ($q['url'] ?? ''), 'tix_quote=') !== false, $q['items'][0]['cat_name'] ?? '']);
    [$st, $d] = $rest('POST', '/studio/quotes', ['items' => [['event_id' => $ev, 'cat_index' => 9, 'qty' => 1]]]);
    $eq('Unbekannte Ticketart → 400 items.0.cat_index', [400, 'items.0.cat_index'], [$st, $d['data']['field'] ?? '']);
    [$st, $d] = $rest('POST', '/studio/quotes', ['items' => [['event_id' => $ev, 'cat_index' => 0, 'qty' => 1]], 'customer' => ['email' => 'kaputt']]);
    $eq('Ungültige E-Mail → 400 customer.email', [400, 'customer.email'], [$st, $d['data']['field'] ?? '']);
    update_post_meta($q['id'], '_tix_quote_expires', time() - 60);
    [$st, $d] = $rest('GET', '/studio/quotes', null, ['status' => 'expired']);
    $eq('Liste: abgelaufen gefiltert', true, in_array($q['id'], array_column((array) ($d['quotes'] ?? []), 'id'), true));
    [$st] = $rest('DELETE', '/studio/quotes/' . $q['id']);
    $eq('Löschen', [200, false], [$st, (bool) get_post($q['id'])]);

    // B19 Listen-Abgleich
    $s = (array) get_option('tix_settings', []); unset($s['brevo_api_key']); $s['brevo_enabled'] = 0; $s['brevo_list_id'] = 0; $s['brevo_mappings'] = []; update_option('tix_settings', $s);
    $nl_had_key = class_exists('TIX_Newsletter') && TIX_Newsletter::settings(0)['api_key'] !== '';
    [$st, $d] = $rest('GET', '/marketing/newsletter/sync');
    $eq('Abgleich lesen: Brevo, aus, sofort', [200, 'brevo', false, 'immediate'], [$st, $d['provider'] ?? '', $d['enabled'] ?? null, $d['timing'] ?? '']);
    if (!$nl_had_key) {
        [$st, $d] = $rest('POST', '/marketing/newsletter/sync', ['enabled' => true, 'list_id' => 5]);
        $eq('Ohne Brevo-Schlüssel nicht einschaltbar', [400, 'enabled'], [$st, $d['data']['field'] ?? '']);
    }
    $s = (array) get_option('tix_settings', []); $s['brevo_api_key'] = 'xkeysib-test-' . str_repeat('a', 30); update_option('tix_settings', $s);
    [$st, $d] = $rest('POST', '/marketing/newsletter/sync', ['enabled' => true, 'list_id' => 5, 'mappings' => [['event_id' => $ev, 'cat_name' => 'VIP', 'list_id' => 7]], 'timing' => 'after_confirm']);
    $eq('Speichern: an, Liste, Regel, nach Bestätigung', [200, true, 5, 7, 'AN Event', 'after_confirm'], [$st, $d['active'] ?? null, $d['list_id'] ?? null, $d['mappings'][0]['list_id'] ?? null, $d['mappings'][0]['event_title'] ?? '', $d['timing'] ?? '']);
    [$st, $d] = $rest('POST', '/marketing/newsletter/sync', ['timing' => 'sofort']);
    $eq('Unbekannter Zeitpunkt → 400', [400, 'timing'], [$st, $d['data']['field'] ?? '']);
    [$st, $d] = $rest('POST', '/marketing/newsletter/sync', ['mappings' => [['event_id' => $ev, 'list_id' => 0]]]);
    $eq('Regel ohne Liste → 400', [400, 'mappings.0.list_id'], [$st, $d['data']['field'] ?? '']);
    $http = [];
    do_action('tix_newsletter_optin', 0, 'nl-an@example.test', 'Nl', 'Test');
    $eq('Nach Bestätigung: Kauf-Häkchen überträgt noch nichts', 0, count($http));
    do_action('tix_nl_confirmed', 0, 'nl-an@example.test', 'Nl', 0);
    $eq('Bestätigung überträgt an Brevo', true, count($http) >= 1 && strpos($http[0][1], '/contacts') !== false);
    $rest('POST', '/marketing/newsletter/sync', ['timing' => 'immediate']);
    $http = [];
    do_action('tix_newsletter_optin', 0, 'nl-an2@example.test', 'Nl', 'Test');
    $eq('Sofort: Kauf-Häkchen überträgt direkt', true, count($http) >= 1);
    $staff = wp_insert_user(['user_login' => 'ans' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'ans' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_staff']); $users[] = $staff;
    wp_set_current_user($staff);
    $eq('Mitarbeiter: Abgleich und Vorbestellungen verboten', [true, true], [in_array($rest('GET', '/marketing/newsletter/sync')[0], [401, 403], true), in_array($rest('GET', '/studio/quotes')[0], [401, 403], true)]);
    wp_set_current_user($adm);

    // B6 Gutscheine je Event
    $c1 = 'ANEV' . wp_rand(1000, 9999); $c2 = 'ANALL' . wp_rand(1000, 9999); $c3 = 'ANOTH' . wp_rand(1000, 9999);
    foreach ([[$c1, [$ev]], [$c2, []], [$c3, [$ev2]]] as [$code, $evs]) { [$st] = $rest('POST', '/organizer/coupons', ['code' => $code, 'discount_type' => 'percent', 'value' => 10, 'allowed_events' => $evs]); if ($st === 201) $codes[] = $code; }
    [$st, $d] = $rest('GET', '/organizer/coupons', null, ['event_id' => $ev]);
    $got = array_column((array) ($d['coupons'] ?? []), 'for_all_events', 'code');
    $eq('Gutscheine für dieses Event: eigener + alle, nicht fremder', [true, false, true, false], [isset($got[$c1]), $got[$c1] ?? null, $got[$c2] ?? null, isset($got[$c3])]);

    // B8 Kampagnen je Event
    if (class_exists('TIX_Web_Stats')) {
        $dt = TIX_Web_Stats::dim_table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $dt)) === $dt) {
            $wpdb->query($wpdb->prepare("INSERT INTO $dt (day, step, org_id, event_id, channel, source, medium, campaign, utm_id, n) VALUES (%s, 'event', 0, %d, 'web', 'instagram', 'social', 'fruehling', '', 7) ON DUPLICATE KEY UPDATE n = 7", current_time('Y-m-d'), $ev));
            [$st, $d] = $rest('GET', "/events/$ev/statistics");
            $row = array_values(array_filter((array) ($d['statistics']['by_campaign'] ?? []), function ($r) { return $r['source'] === 'instagram'; }))[0] ?? [];
            $eq('Kampagnen je Event: Aufrufe nach Quelle/Medium/Kampagne', [200, 7, 'social', 'fruehling'], [$st, $row['views'] ?? 0, $row['medium'] ?? '', $row['campaign'] ?? '']);
            $wpdb->delete($dt, ['event_id' => $ev]);
        }
    }
    [$st, $d] = $rest('POST', '/marketing/links', ['label' => 'AN Insta', 'event_id' => $ev, 'source' => 'instagram']);
    if (!empty($d['link']['id'])) $links[] = $d['link']['id'];
    [, $d2] = $rest('POST', '/marketing/links', ['label' => 'AN Anderes', 'event_id' => $ev2]);
    if (!empty($d2['link']['id'])) $links[] = $d2['link']['id'];
    if ($links) {
        [$st, $d] = $rest('GET', '/marketing/links', null, ['event_id' => $ev]);
        $ids = array_column((array) ($d['links'] ?? []), 'id');
        $eq('Links nur für dieses Event', [true, false], [in_array($links[0], $ids, true), isset($links[1]) && in_array($links[1], $ids, true)]);
    } else { echo "skip  Links (Modul Marketing aus: $st)\n"; }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    remove_filter('pre_http_request', $hook, 10);
    update_option('tix_settings', $opt_before);
    if ($codes && class_exists('TIX_App_Coupons')) foreach ($codes as $c) $rest('DELETE', '/organizer/coupons/' . $c);
    foreach ($links as $l) $rest('DELETE', '/marketing/links/' . $l);
    foreach ($posts as $p) wp_delete_post($p, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
