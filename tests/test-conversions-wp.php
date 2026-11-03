<?php
/** Integrationstest Statistik Stufe 3a – Conversion-Verteiler, Matomo, Einwilligungs-Nachweis. Netz nachgebildet (pre_http_request).
 *  wp eval-file …/tests/test-conversions-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Conversions') || !class_exists('TIX_Matomo')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cv_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function cv_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create(); TIX_Conversions::maybe_create(); TIX_Consent_Log::maybe_create(); TIX_Ad_Accounts::maybe_create();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$keep = []; foreach (['tix_conv_targets', 'tix_marketing_enabled', 'tix_matomo', 'tix_matomo_site', 'tix_marketing_trial_until'] as $o) $keep[$o] = get_option($o, null);
delete_option('tix_conv_targets'); delete_option('tix_matomo'); delete_option('tix_matomo_site'); delete_option('tix_marketing_trial_until');
if (!$multi) update_option('tix_marketing_enabled', '1'); else delete_option('tix_marketing_enabled');
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $orders = [];
$owner = wp_insert_user(['user_login' => "cv_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "cv_owner_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner;
$org = 0;
if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CV Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org; }
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'CV Party', 'meta_input' => ['_tix_organizer_id' => $org]]); $posts[] = $ev;

$GLOBALS['cv'] = ['calls' => [], 'meta_code' => 200];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $J = function ($code, $body) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => 'x'], 'cookies' => [], 'filename' => null]; };
    $body = $args['body'] ?? '';
    $GLOBALS['cv']['calls'][] = ['url' => $url, 'body' => is_string($body) ? json_decode($body, true) : $body, 'headers' => $args['headers'] ?? []];
    if (strpos($url, 'https://graph.facebook.com/v21.0/') === 0) return $GLOBALS['cv']['meta_code'] === 200 ? $J(200, ['events_received' => 1]) : $J($GLOBALS['cv']['meta_code'], ['error' => ['message' => 'Kaputt']]);
    if (strpos($url, 'https://business-api.tiktok.com/open_api/v1.3/event/track/') === 0) return $J(200, ['code' => 0, 'message' => 'OK']);
    if (strpos($url, 'https://www.google-analytics.com/debug/mp/collect') === 0) return $J(200, ['validationMessages' => []]);
    if (strpos($url, 'https://www.google-analytics.com/mp/collect') === 0) return $J(204, []);
    if (strpos($url, 'https://stats.test/index.php') === 0) {
        $m = $body['method'] ?? '';
        if ($m === 'SitesManager.addSite') return $J(200, ['value' => 7]);
        if ($m === 'VisitsSummary.get') return $J(200, ['nb_visits' => 120, 'nb_uniq_visitors' => 90, 'nb_actions' => 400, 'bounce_rate' => '41%', 'avg_time_on_site' => 95, 'nb_actions_per_visit' => 3.3]);
        if ($m === 'Goals.get') return $J(200, ['nb_conversions' => 4, 'revenue' => 80, 'conversion_rate' => '3.3%']);
        return $J(200, [['label' => 'Suchmaschinen', 'nb_visits' => 50], ['label' => 'Direkt', 'nb_visits' => 40]]);
    }
    if (strpos($url, 'https://stats.test/matomo.php') === 0) return $J(204, []);
    return $pre;
}, 10, 3);
$calls = function ($prefix) { return array_values(array_filter($GLOBALS['cv']['calls'], function ($c) use ($prefix) { return strpos($c['url'], $prefix) === 0; })); };

// ── Einstellungen ──
wp_set_current_user($owner);
[$st, $d, $code] = cv_rest('POST', '/marketing/tracking', ['meta' => ['pixel_id' => 'abc']]);
cv_eq('Pixel-ID von Meta geprüft', 'tix_invalid_field', $code);
[$st, $d, $code] = cv_rest('POST', '/marketing/tracking', ['ga4' => ['measurement_id' => 'G-ABCD1234']]);
cv_eq($multi ? 'GA4 auf der Plattform nicht möglich' : 'GA4 im eigenen Shop speicherbar', $multi ? 'tix_invalid_field' : '', $code);
[$st, $r] = cv_rest('POST', '/marketing/tracking', ['meta' => ['pixel_id' => '123456789012345', 'token' => 'EAAG' . str_repeat('x', 40), 'test_code' => 'TEST123', 'enabled' => true], 'tiktok' => ['pixel_id' => 'C0ABCDEFGHIJ12345', 'token' => str_repeat('t', 40), 'enabled' => true]]
    + ($multi ? [] : ['ga4' => ['measurement_id' => 'G-ABCD1234', 'secret' => 'secretABC123', 'enabled' => true], 'gads' => ['conversion_id' => 'AW-123456789', 'label' => 'AbC-dEf', 'enabled' => true]]));
cv_eq('Gespeichert, Token nur als „gesetzt“ + 4 Zeichen', [200, true, 'xxxx', false], [$st, $r['targets']['meta']['token_set'] ?? null, $r['targets']['meta']['token_last4'] ?? null, strpos(wp_json_encode($r), 'EAAG') !== false]);
cv_eq('Token verschlüsselt abgelegt', false, strpos(wp_json_encode($multi ? get_post_meta($org, '_tix_conv_targets', true) : get_option('tix_conv_targets')), 'EAAG') !== false);
if ($multi) {
    // Plattform-eigener Pixel (Admin, Bereich 0)
    wp_set_current_user(1);
    cv_rest('POST', '/marketing/tracking', ['meta' => ['pixel_id' => '999999999999', 'token' => 'EAAP' . str_repeat('p', 40), 'enabled' => true]]);
    wp_set_current_user($owner);
} else {
    $sc = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/public/site-config'))->get_data();
    cv_eq('site-config: Browser-IDs (Meta, TikTok, GA4, Google Ads)', ['123456789012345', 'C0ABCDEFGHIJ12345', 'G-ABCD1234', 'AW-123456789'], [$sc['tracking']['meta_pixel_id'] ?? null, $sc['tracking']['tiktok_pixel_id'] ?? null, $sc['tracking']['ga4_id'] ?? null, $sc['tracking']['google_ads']['id'] ?? null]);
    cv_eq('Alter Meta-Weg schweigt', true, TIX_Conversions::handles_meta_site());
}

// ── Kauf mit Einwilligung ──
$ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name();
$mk = function ($attr, $pm = 'mollie') use ($wpdb, $ot, $oi, $ev, &$orders) {
    $wpdb->insert($ot, ['order_number' => 'CV' . wp_rand(10000, 99999), 'status' => 'completed', 'order_key' => wp_generate_password(12, false), 'total' => 30, 'payment_method' => $pm, 'billing_email' => ' Gast@Example.test ', 'billing_first_name' => 'Gast', 'billing_phone' => '0171 1234567', 'date_created' => current_time('mysql')]);
    $id = intval($wpdb->insert_id); $orders[] = $id;
    $wpdb->insert($oi, ['order_id' => $id, 'event_id' => $ev, 'quantity' => 2, 'total' => 30, 'name' => 'CV – Regulär', 'cat_name' => 'Regulär']);
    if ($attr) TIX_Order_Attribution::set($id, $attr);
    return $id;
};
$o1 = $mk(['channel' => 'web', 'consent_marketing' => 1, 'fbp' => 'fb.1.111.222', 'client_ip' => '203.0.113.9', 'client_ua' => 'Mozilla/5.0', 'click_ids' => ['ttclid' => 'tt1', 'ttp' => 'ttp1'], 'consent_analytics' => 1, 'analytics_ids' => ['ga_cid' => '123456789.987654321', 'pk_vid' => 'abcdef0123456789']]);
$num = $wpdb->get_var($wpdb->prepare("SELECT order_number FROM $ot WHERE id = %d", $o1));
_get_cron_array(); // Cron geplant?
TIX_Conversions::on_paid($o1);
$sched = (array) _get_cron_array(); $plan = 0;
foreach ($sched as $ts => $hooks) foreach ((array) ($hooks['tix_conv_send'] ?? []) as $e) if (intval($e['args'][0]) === $o1) $plan++;
cv_eq('Aufträge geplant (je Bereich und Ziel)', $multi ? 3 : 3, $plan);
foreach (TIX_Conversions::scopes($o1) as $sc) foreach (['meta', 'tiktok', 'ga4'] as $t) TIX_Conversions::send($o1, $sc, $t, 1);
$m = $calls('https://graph.facebook.com/v21.0/123456789012345/events');
$p = $m[0]['body']['data'][0] ?? [];
cv_eq('Meta: Purchase mit event_id = Bestellnummer, Wert, Test-Code', ['Purchase', 'tix_' . $num, 30.0, 'TEST123'], [$p['event_name'] ?? '', $p['event_id'] ?? '', floatval($p['custom_data']['value'] ?? 0), $m[0]['body']['test_event_code'] ?? '']);
cv_eq('Meta: E-Mail und Telefon gehasht, fbp/IP dabei', [hash('sha256', 'gast@example.test'), hash('sha256', '491711234567'), 'fb.1.111.222', '203.0.113.9'], [$p['user_data']['em'][0] ?? '', $p['user_data']['ph'][0] ?? '', $p['user_data']['fbp'] ?? '', $p['user_data']['client_ip_address'] ?? '']);
cv_eq('Meta: kein Klartext', false, strpos(wp_json_encode($m[0]['body']), 'Gast@') !== false || strpos(wp_json_encode($m[0]['body']), 'gast@') !== false);
$tt = $calls('https://business-api.tiktok.com/open_api/v1.3/event/track/');
cv_eq('TikTok: CompletePayment, Access-Token, ttclid', ['CompletePayment', str_repeat('t', 40), 'tt1', 'tix_' . $num], [$tt[0]['body']['data'][0]['event'] ?? '', $tt[0]['headers']['Access-Token'] ?? '', $tt[0]['body']['data'][0]['user']['ttclid'] ?? '', $tt[0]['body']['data'][0]['event_id'] ?? '']);
if (!$multi) {
    $ga = $calls('https://www.google-analytics.com/mp/collect');
    cv_eq('GA4: purchase mit Client-ID und transaction_id', ['purchase', '123456789.987654321', 'tix_' . $num], [$ga[0]['body']['events'][0]['name'] ?? '', $ga[0]['body']['client_id'] ?? '', $ga[0]['body']['events'][0]['params']['transaction_id'] ?? '']);
} else {
    $pl = $calls('https://graph.facebook.com/v21.0/999999999999/events');
    cv_eq('Plattform bekommt den Kauf zusätzlich (eigener Pixel)', 1, count($pl));
}
$sent = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Conversions::table() . " WHERE order_id = %d AND status = 'sent'", $o1)));
cv_eq('Protokoll: gesendet', $multi ? 3 : 3, $sent);
$before = count($GLOBALS['cv']['calls']);
TIX_Conversions::send($o1, $org, 'meta', 1);
cv_eq('Nie doppelt senden', $before, count($GLOBALS['cv']['calls']));

// ── ohne Einwilligung, Fehler, Wiederholung ──
$o2 = $mk(['channel' => 'web', 'consent_marketing' => 0]);
TIX_Conversions::send($o2, $org, 'meta', 1);
cv_eq('Ohne Einwilligung: nichts an Meta, Protokoll „skipped“', 'skipped', (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM " . TIX_Conversions::table() . " WHERE order_id = %d", $o2)));
$o3 = $mk([], 'pos_cash');
TIX_Conversions::send($o3, $org, 'meta', 1);
cv_eq('Abendkasse: still, kein Eintrag', null, $wpdb->get_var($wpdb->prepare("SELECT status FROM " . TIX_Conversions::table() . " WHERE order_id = %d", $o3)));
$o4 = $mk(['channel' => 'web', 'consent_marketing' => 1]);
$GLOBALS['cv']['meta_code'] = 500;
TIX_Conversions::send($o4, $org, 'meta', 1);
cv_eq('Fehler 500: nochmal versuchen', 'retry', (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM " . TIX_Conversions::table() . " WHERE order_id = %d ORDER BY id DESC LIMIT 1", $o4)));
$GLOBALS['cv']['meta_code'] = 400;
TIX_Conversions::send($o4, $org, 'meta', 2);
cv_eq('Fehler 400: endgültig', 'failed', (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM " . TIX_Conversions::table() . " WHERE order_id = %d ORDER BY id DESC LIMIT 1", $o4)));
$GLOBALS['cv']['meta_code'] = 200;

// ── Testereignis + Protokoll im Studio ──
[$st, $r] = cv_rest('POST', '/marketing/tracking/test', ['target' => 'meta']);
cv_eq('Testereignis Meta', [200, true], [$st, $r['ok'] ?? null]);
[$st, $r] = cv_rest('GET', '/marketing/tracking');
cv_eq('Protokoll im Studio, ohne E-Mail', [true, false], [count($r['log'] ?? []) >= 4, strpos(wp_json_encode($r['log']), 'example.test') !== false]);

// ── Modul aus → nichts ──
if (!$multi) {
    update_option('tix_marketing_enabled', '0');
    $GLOBALS['cv']['calls'] = [];
    $o5 = $mk(['channel' => 'web', 'consent_marketing' => 1]);
    TIX_Conversions::on_paid($o5); TIX_Conversions::send($o5, 0, 'meta', 1);
    cv_eq('Modul aus: keine Server-Meldung', 0, count($calls('https://graph.facebook.com')));
    $sc = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/public/site-config'))->get_data();
    cv_eq('Browser-Pixel im eigenen Shop bleibt frei', '123456789012345', $sc['tracking']['meta_pixel_id'] ?? null);
    update_option('tix_marketing_enabled', '1');
}

// ── Matomo ──
update_option('tix_matomo', ['url' => 'https://stats.test', 'token' => str_repeat('a', 32)]);
cv_eq('Matomo-Token wird verschlüsselt', [true, false], [TIX_Matomo::instance()['token'] === str_repeat('a', 32), isset(get_option('tix_matomo')['token'])]);
[$st, $r] = cv_rest('POST', '/marketing/matomo/activate');
cv_eq('Matomo: Site angelegt', [200, 7, true], [$st, $r['site_id'] ?? null, $r['active'] ?? null]);
$add = $calls('https://stats.test/index.php');
cv_eq('addSite mit E-Commerce, Berlin, EUR', ['SitesManager.addSite', 1, 'Europe/Berlin', 'EUR'], [$add[0]['body']['method'] ?? '', intval($add[0]['body']['ecommerce'] ?? 0), $add[0]['body']['timezone'] ?? '', $add[0]['body']['currency'] ?? '']);
[$st, $r] = cv_rest('GET', '/marketing/matomo/stats', ['from' => wp_date('Y-m-d', time() - 6 * DAY_IN_SECONDS), 'to' => wp_date('Y-m-d')]);
cv_eq('Matomo-Zahlen im Studio', [120, 4, 'Suchmaschinen'], [$r['summary']['visits'] ?? null, $r['ecommerce']['orders'] ?? null, $r['referrers'][0]['label'] ?? null]);
[$st, $pub] = cv_rest('GET', '/public/tracking', ['organizer' => (string) $org]);
cv_eq('Browser bekommt nur URL und Site-ID', ['https://stats.test', 7, false], [$pub['matomo']['url'] ?? null, $pub['matomo']['site_id'] ?? null, strpos(wp_json_encode($pub), str_repeat('a', 32)) !== false]);
TIX_Matomo::send_order($o1, $org);
$mp = $calls('https://stats.test/matomo.php');
cv_eq('Matomo-Kauf: Besucher-ID, ec_id, Umsatz', ['abcdef0123456789', 'tix_' . $num, 30.0], [$mp[0]['body']['_id'] ?? '', $mp[0]['body']['ec_id'] ?? '', floatval($mp[0]['body']['revenue'] ?? 0)]);
$n = count($calls('https://stats.test/matomo.php'));
TIX_Matomo::send_order($o2, $org);
cv_eq('Matomo-Kauf nur mit Einwilligung Statistik', $n, count($calls('https://stats.test/matomo.php')));

// ── Einwilligungs-Nachweis ──
$cid = wp_generate_uuid4();
$q = new WP_REST_Request('POST', '/tixomat/v1/public/consent'); $q->set_body_params(['id' => $cid, 'action' => 'accept', 'categories' => ['analytics', 'marketing', 'evil'], 'revision' => 3, 'host' => 'kitchenklub.de']);
cv_eq('Nachweis: 204', 204, rest_do_request($q)->get_status());
$hist = TIX_Consent_Log::history($cid);
cv_eq('Nachweis gespeichert, nur bekannte Kategorien, ohne IP', ['accept', 'necessary,analytics,marketing', '3'], [$hist[0]['action'] ?? '', $hist[0]['categories'] ?? '', (string) ($hist[0]['revision'] ?? '')]);
$q = new WP_REST_Request('POST', '/tixomat/v1/public/consent'); $q->set_body_params(['id' => 'kaputt', 'action' => 'accept']); rest_do_request($q);
cv_eq('Ungültige ID wird verworfen', 0, intval($wpdb->get_var("SELECT COUNT(*) FROM " . TIX_Consent_Log::table() . " WHERE consent_id = 'kaputt'")));

// Aufräumen
wp_set_current_user(1);
foreach ($orders as $o) { $wpdb->delete($ot, ['id' => $o]); $wpdb->delete($oi, ['order_id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ['order_id' => $o]); $wpdb->delete(TIX_Conversions::table(), ['order_id' => $o]); }
$wpdb->query("DELETE FROM " . TIX_Conversions::table() . " WHERE order_id = 0");
$wpdb->delete(TIX_Consent_Log::table(), ['consent_id' => $cid]);
foreach ($keep as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v); }
wp_clear_scheduled_hook('tix_conv_send'); wp_clear_scheduled_hook('tix_matomo_order');
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
