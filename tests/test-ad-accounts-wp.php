<?php
/** Integrationstest Statistik Stufe 2 – Werbekonten (TIX_Ad_Accounts). Weiche und Werbenetze werden über pre_http_request
 *  nachgebildet, es geht nichts ins Netz. wp eval-file …/tests/test-ad-accounts-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Ad_Accounts')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function ad_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function ad_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create(); TIX_Web_Stats::maybe_create(); TIX_Web_Stats::maybe_create_dim(); TIX_Ad_Accounts::maybe_create();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $orders = [];
$opt_before = ['c' => get_option('tix_connect', null), 'l' => get_option('tix_ads_live', null), 't' => get_option('tix_marketing_trial_until', null), 'm' => get_option('tix_marketing_enabled', null)];
$SECRET = str_repeat('ab', 32);
update_option('tix_connect', ['site_id' => 'testseite', 'secret' => $SECRET, 'base' => 'https://connect.test']);
delete_option('tix_ads_live'); delete_option('tix_marketing_trial_until'); delete_option('tix_marketing_enabled');
foreach (['meta', 'google', 'tiktok'] as $p) delete_transient('tix_ads_networks_' . md5('https://connect.test'));
$owner = wp_insert_user(['user_login' => "ad_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "ad_owner_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner;
$org = 0;
if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'AD Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org; }
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'AD Party', 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d'), '_tix_ticket_categories' => [['name' => 'Regulär', 'price' => 10, 'qty' => 100]]]]); $posts[] = $ev;

// ── Nachbildung Weiche + Netze ──
$GLOBALS['ad_mock'] = ['claims' => [], 'insights' => 'ok', 'calls' => []];
add_filter('pre_http_request', function ($pre, $args, $url) use ($SECRET) {
    $GLOBALS['ad_mock']['calls'][] = $url;
    $J = function ($code, $body) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => 'x'], 'cookies' => [], 'filename' => null]; };
    $b = json_decode((string) ($args['body'] ?? ''), true) ?: [];
    if (strpos($url, 'https://connect.test/api/connect/networks') === 0) return $J(200, ['meta' => ['configured' => true, 'mode' => 'test'], 'google' => ['configured' => true, 'mode' => 'test'], 'tiktok' => ['configured' => false]]);
    if (strpos($url, 'https://connect.test/api/connect/claim') === 0) {
        if (!hash_equals(hash_hmac('sha256', 'claim|' . $b['id'] . '|' . $b['ts'], $SECRET), (string) $b['sig']) || $b['site'] !== 'testseite') return $J(403, ['error' => 'sig']);
        $c = $GLOBALS['ad_mock']['claims'][$b['id']] ?? null;
        unset($GLOBALS['ad_mock']['claims'][$b['id']]);
        return $c ? $J(200, $c) : $J(410, ['error' => 'gone']);
    }
    if (strpos($url, 'https://connect.test/api/connect/google') === 0) {
        $ok = hash_equals(hash_hmac('sha256', 'google|' . $b['ts'] . '|' . $b['body_hash'], $SECRET), (string) $b['sig']);
        if (!$ok) return $J(403, ['error' => 'sig']);
        if ($b['op'] === 'list') return $J(200, ['accounts' => [['id' => '123-456-7890', 'name' => 'AD Google', 'currency' => 'EUR', 'manager' => false], ['id' => '999', 'name' => 'MCC', 'manager' => true]]]);
        return $J(200, ['rows' => [['segments' => ['date' => wp_date('Y-m-d')], 'campaign' => ['id' => '555', 'name' => 'Suche Herbst'], 'metrics' => ['costMicros' => '12340000', 'impressions' => '900', 'clicks' => '40'], 'customer' => ['currencyCode' => 'EUR']]]]);
    }
    if (strpos($url, 'https://connect.test/api/connect/refresh') === 0) return $J(200, ['access_token' => 'ya29.neu', 'expires_in' => 3599]);
    if (strpos($url, 'https://graph.facebook.com/v21.0/me/adaccounts') === 0) return $J(200, ['data' => [['account_id' => '111', 'name' => 'AD Meta', 'currency' => 'EUR']]]);
    if (strpos($url, 'https://graph.facebook.com/v21.0/act_111/insights') === 0) {
        if ($GLOBALS['ad_mock']['insights'] === 'revoked') return $J(400, ['error' => ['code' => 190, 'error_subcode' => 458, 'message' => 'App entfernt']]);
        if ($GLOBALS['ad_mock']['insights'] === 'expired') return $J(400, ['error' => ['code' => 190, 'error_subcode' => 463, 'message' => 'abgelaufen']]);
        $d = wp_date('Y-m-d');
        return $J(200, ['data' => [
            ['campaign_id' => '23850001', 'campaign_name' => 'Herbst 2026', 'spend' => '40.00', 'impressions' => '5000', 'clicks' => '120', 'account_currency' => 'EUR', 'date_start' => $d],
            ['campaign_id' => '23850002', 'campaign_name' => 'Retargeting', 'spend' => '10.00', 'impressions' => '800', 'clicks' => '15', 'account_currency' => 'EUR', 'date_start' => $d],
        ]]);
    }
    return $pre;
}, 10, 3);

// ── Modul und Test ──
wp_set_current_user($owner);
[$st, $r] = ad_rest('GET', '/marketing/ads');
ad_eq('Status: Modul ' . ($multi ? 'auf der Plattform frei' : 'aus, Test anbietbar'), $multi ? [true, true] : [false, true], $multi ? [$r['module']['on'], $r['module']['free']] : [$r['module']['on'], $r['module']['trial_available']]);
[$st] = ad_rest('POST', '/marketing/ads/start', ['platform' => 'meta']);
if (!$multi) {
    ad_eq('Ohne Modul: Start gesperrt', 403, $st);
    [$st, $r] = ad_rest('POST', '/marketing/trial');
    ad_eq('Test starten: 30 Tage', [200, true, true], [$st, $r['module']['on'], $r['module']['trial']]);
    [$st, $d, $code] = ad_rest('POST', '/marketing/trial');
    ad_eq('Test nur einmal', 'tix_trial_used', $code);
}
[$st, $r] = ad_rest('GET', '/marketing/ads');
$net = []; foreach ($r['networks'] as $n) $net[$n['key']] = [$n['available'], $n['reason']];
ad_eq('Netze: im Prüfbetrieb nur für Prüfer, TikTok nicht eingerichtet', ['meta' => [false, 'review'], 'google' => [false, 'review'], 'tiktok' => [false, 'not_configured']], $net);
[$st, $d, $code] = ad_rest('POST', '/marketing/ads/start', ['platform' => 'meta']);
ad_eq('Ohne Freigabe: bald verfügbar', 'tix_ads_soon', $code);
update_user_meta($owner, '_tix_ads_tester', '1');
[$st, $r] = ad_rest('POST', '/marketing/ads/start', ['platform' => 'meta']);
parse_str((string) wp_parse_url($r['url'] ?? '', PHP_URL_QUERY), $q);
list($pl, $sig) = array_pad(explode('.', (string) ($q['ticket'] ?? '')), 2, '');
$tk = json_decode(base64_decode(strtr($pl, '-_', '+/')), true);
ad_eq('Start: Weiche-Adresse mit Seite und signiertem Ticket', [true, 'testseite', true, $org, $owner], [strpos($r['url'], 'https://connect.test/oauth/meta/start?') === 0, $q['site'] ?? '', hash_equals(hash_hmac('sha256', $pl, $SECRET), $sig), $tk['o'] ?? null, $tk['u'] ?? null]);
update_option('tix_ads_live', ['meta' => true]);
ad_eq('Live-Schalter allein reicht nicht, solange die Weiche „test“ meldet', 'test', TIX_Ad_Accounts::network_state('meta')['reason']);
delete_option('tix_ads_live');

// ── Abholen, Konto wählen, Kosten ──
$GLOBALS['ad_mock']['claims']['claimid0000000000001'] = ['network' => 'meta', 'mode' => 'test', 'ticket' => ['o' => $org, 'u' => $owner + 1], 'token' => ['access_token' => 'EAAB-geheim', 'expires_in' => 5184000, 'graph' => 'v21.0']];
[$st, $d, $code] = ad_rest('POST', '/marketing/ads/claim', ['connect_id' => 'claimid0000000000001']);
ad_eq('Abholen: fremdes Ticket abgelehnt', 403, $st);
$GLOBALS['ad_mock']['claims']['claimid0000000000002'] = ['network' => 'meta', 'mode' => 'test', 'ticket' => ['o' => $org, 'u' => $owner], 'token' => ['access_token' => 'EAAB-geheim', 'expires_in' => 5184000, 'graph' => 'v21.0']];
[$st, $r] = ad_rest('POST', '/marketing/ads/claim', ['connect_id' => 'claimid0000000000002']);
ad_eq('Abholen: Werbekonten', [200, 'meta', '111'], [$st, $r['platform'] ?? '', $r['accounts'][0]['id'] ?? '']);
[$st, $d, $code] = ad_rest('POST', '/marketing/ads/claim', ['connect_id' => 'claimid0000000000002']);
ad_eq('Abholen nur einmal', 410, $st);
[$st, $r] = ad_rest('POST', '/marketing/ads/select', ['pending' => $r['pending'], 'account_id' => '111']);
$cid = intval($r['connection']['id'] ?? 0);
ad_eq('Verbunden + Vorlage für utm_id', [200, 'ok', true], [$st, $r['connection']['status'] ?? '', strpos((string) ($r['connection']['url_template']['value'] ?? ''), 'utm_id={{campaign.id}}') !== false]);
$row = TIX_Ad_Accounts::conn($cid);
ad_eq('Token verschlüsselt gespeichert', [false, 'EAAB-geheim'], [strpos((string) $row['token_enc'], 'EAAB') !== false, TIX_Ad_Accounts::dec($row['token_enc'])]);
$ct = TIX_Ad_Accounts::t('costs');
ad_eq('Kosten abgeholt (2 Kampagnen, 50 €)', [2, 50.0], [intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ct WHERE organizer_id = %d AND platform = 'meta'", $org))), floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(spend) FROM $ct WHERE organizer_id = %d AND platform = 'meta'", $org)))]);

// ── Zuordnung: utm_id an der Bestellung, Name = utm_campaign, Kosten von Hand ──
$ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name();
$mk_order = function ($attr) use ($wpdb, $ot, $oi, $ev, &$orders) {
    $wpdb->insert($ot, ['order_number' => 'AD' . wp_rand(), 'status' => 'completed', 'order_key' => wp_generate_password(12, false), 'total' => 20, 'subtotal' => 20, 'payment_method' => 'mollie', 'billing_email' => 'ad' . wp_rand() . '@example.test', 'date_created' => current_time('mysql')]);
    $id = intval($wpdb->insert_id); $orders[] = $id;
    $wpdb->insert($oi, ['order_id' => $id, 'event_id' => $ev, 'quantity' => 2, 'total' => 20, 'name' => 'AD Party – Regulär', 'cat_name' => 'Regulär']);
    TIX_Order_Attribution::set($id, $attr + ['channel' => 'web']);
    return $id;
};
$mk_order(['source' => 'facebook', 'campaign' => 'herbst-2026', 'utm_id' => '23850001']);
$mk_order(['source' => 'facebook', 'campaign' => 'herbst-2026', 'utm_id' => '23850001']);
$mk_order(['source' => 'facebook', 'campaign' => 'retargeting']);
$mk_order(['source' => 'plakat', 'campaign' => 'plakat-innenstadt']);
[$st, $r] = ad_rest('POST', '/marketing/costs', ['label' => 'Plakate Innenstadt', 'campaign' => 'Plakat Innenstadt', 'date_from' => wp_date('Y-m-d', time() - 9 * DAY_IN_SECONDS), 'date_to' => wp_date('Y-m-d'), 'amount' => '100,00']);
ad_eq('Kosten von Hand gespeichert', [200, 1], [$st, count($r['costs'] ?? [])]);
[$st, $d, $code] = ad_rest('POST', '/marketing/costs', ['label' => 'x', 'date_from' => '2026-01-02', 'date_to' => '2026-01-01', 'amount' => '5']);
ad_eq('Kosten von Hand: Zeitraum geprüft', 'tix_invalid_field', $code);
[$st, $r] = ad_rest('GET', '/marketing/campaigns', ['from' => wp_date('Y-m-d', time() - 4 * DAY_IN_SECONDS), 'to' => wp_date('Y-m-d')]);
$by = []; foreach ($r['rows'] ?? [] as $x) $by[$x['key']] = $x;
ad_eq('Herbst über utm_id: 40 €, 2 Käufe, CPA 20 €, ROAS 1', ['utm_id', 40.0, 2, 20.0, 1.0], [$by['meta:23850001']['mapped_by'] ?? '', $by['meta:23850001']['spend'] ?? null, $by['meta:23850001']['orders'] ?? null, $by['meta:23850001']['cpa'] ?? null, $by['meta:23850001']['roas'] ?? null]);
ad_eq('Retargeting über den Namen', ['name', 1], [$by['meta:23850002']['mapped_by'] ?? '', $by['meta:23850002']['orders'] ?? null]);
$man = array_values(array_filter($r['rows'] ?? [], function ($x) { return $x['platform'] === 'manual'; }))[0] ?? [];
ad_eq('Kosten von Hand anteilig (5 von 10 Tagen = 50 €), Kampagne zugeordnet', [50.0, 'manual', 1], [$man['spend'] ?? null, $man['mapped_by'] ?? '', $man['orders'] ?? null]);
ad_eq('Summe: 100 € Kosten, 4 Käufe', [100.0, 4], [$r['totals']['spend'] ?? null, $r['totals']['orders'] ?? null]);
[$st] = ad_rest('POST', '/marketing/ads/map', ['platform' => 'meta', 'campaign_id' => '23850002', 'campaign' => 'plakat-innenstadt']);
[$st, $r] = ad_rest('GET', '/marketing/campaigns', ['from' => wp_date('Y-m-d', time() - 4 * DAY_IN_SECONDS), 'to' => wp_date('Y-m-d')]);
$by = []; foreach ($r['rows'] ?? [] as $x) $by[$x['key']] = $x;
ad_eq('Von Hand zugeordnet geht vor dem Namen', 'map', $by['meta:23850002']['mapped_by'] ?? '');

// ── Google über den Proxy ──
$GLOBALS['ad_mock']['claims']['claimid0000000000003'] = ['network' => 'google', 'mode' => 'test', 'ticket' => ['o' => $org, 'u' => $owner], 'token' => ['access_token' => 'ya29.alt', 'refresh_token' => '1//rt', 'expires_in' => 3599]];
[$st, $r] = ad_rest('POST', '/marketing/ads/claim', ['connect_id' => 'claimid0000000000003']);
ad_eq('Google: nur Kundenkonten, keine Verwaltungskonten', ['1234567890'], array_map(function ($a) { return $a['id']; }, $r['accounts'] ?? []));
[$st, $r] = ad_rest('POST', '/marketing/ads/select', ['pending' => $r['pending'], 'account_id' => '1234567890']);
$gid = intval($r['connection']['id'] ?? 0);
ad_eq('Google: Kosten aus cost_micros', 12.34, floatval($wpdb->get_var($wpdb->prepare("SELECT SUM(spend) FROM $ct WHERE organizer_id = %d AND platform = 'google'", $org))));

// ── (b) abgelaufen behält Daten, entzogen löscht ──
$GLOBALS['ad_mock']['insights'] = 'expired';
TIX_Ad_Accounts::sync(TIX_Ad_Accounts::conn($cid), 1);
ad_eq('Abgelaufen: Status expired, Kosten bleiben', ['expired', 2], [TIX_Ad_Accounts::conn($cid)['status'] ?? '', intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ct WHERE organizer_id = %d AND platform = 'meta'", $org)))]);
$wpdb->update(TIX_Ad_Accounts::t('connections'), ['status' => 'ok'], ['id' => $cid]);
$GLOBALS['ad_mock']['insights'] = 'revoked';
TIX_Ad_Accounts::sync(TIX_Ad_Accounts::conn($cid), 1);
ad_eq('Entzogen: Verbindung und Kosten sofort gelöscht', [null, 0], [TIX_Ad_Accounts::conn($cid), intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ct WHERE organizer_id = %d AND platform = 'meta'", $org)))]);
[$st, $r] = ad_rest('GET', '/marketing/ads');
ad_eq('Studio erfährt es einmal', [['meta'], 1], [$r['revoked'] ?? [], count($r['connections'] ?? [])]);

// ── (a) Verbindung trennen ──
[$st, $r] = ad_rest('DELETE', "/marketing/ads/$gid");
ad_eq('Trennen: Token, Konto und Kennzahlen weg', [200, null, 0], [$st, TIX_Ad_Accounts::conn($gid), intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ct WHERE organizer_id = %d AND platform = 'google'", $org)))]);

// ── (c) Modul endet → nach 30 Tagen löschen ──
$wpdb->insert($ct, ['day' => wp_date('Y-m-d'), 'organizer_id' => $org, 'platform' => 'tiktok', 'account_id' => 'x', 'campaign_id' => 'y', 'spend' => 1]);
if ($multi) update_option('tix_marketing_enabled', '0'); else update_option('tix_marketing_trial_until', time() - 60);
TIX_Ad_Accounts::retention();
$off = get_option('tix_ads_off_since', []);
ad_eq('Modul aus: Merker gesetzt, Daten noch da', [true, true], [!empty($off[$org]), intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ct WHERE organizer_id = %d", $org))) > 0]);
$off[$org] = time() - 31 * DAY_IN_SECONDS; update_option('tix_ads_off_since', $off, false);
TIX_Ad_Accounts::retention();
ad_eq('Nach 30 Tagen alles gelöscht', [0, 0], [intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ct WHERE organizer_id = %d", $org))), intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Ad_Accounts::t('manual') . " WHERE organizer_id = %d", $org)))]);

// ── utm_id in Zählung und Bestellung ──
$wpdb->query($wpdb->prepare("DELETE FROM " . TIX_Web_Stats::dim_table() . " WHERE event_id = %d", $ev));
delete_transient('tix_ws_rate_' . gmdate('YmdHi'));
$q = new WP_REST_Request('POST', '/tixomat/v1/public/stats/hit'); $q->set_header('user-agent', 'Mozilla/5.0'); $q->set_body_params(['step' => 'event', 'event_id' => $ev, 'utm_source' => 'facebook', 'utm_campaign' => 'Herbst 2026', 'utm_id' => '23850001']); rest_do_request($q);
ad_eq('Zählung kennt utm_id', 1, intval($wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM " . TIX_Web_Stats::dim_table() . " WHERE event_id = %d AND utm_id = '23850001'", $ev))));
$ref = new ReflectionMethod('TIX_App_Checkout', 'apply_attribution'); $ref->setAccessible(true);
$o5 = $mk_order([]);
$ref->invoke(null, $o5, ['utm_source' => 'tiktok', 'utm_id' => '1790000000000'], []);
ad_eq('Kasse speichert utm_id', '1790000000000', (string) (TIX_Order_Attribution::get($o5)['utm_id'] ?? ''));

// Aufräumen
wp_set_current_user(1);
foreach ($orders as $o) { $wpdb->delete($ot, ['id' => $o]); $wpdb->delete($oi, ['order_id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ['order_id' => $o]); }
TIX_Ad_Accounts::purge_organizer($org);
$wpdb->query($wpdb->prepare("DELETE FROM " . TIX_Web_Stats::dim_table() . " WHERE event_id = %d", $ev));
foreach ($opt_before as $k => $v) { $name = ['c' => 'tix_connect', 'l' => 'tix_ads_live', 't' => 'tix_marketing_trial_until', 'm' => 'tix_marketing_enabled'][$k]; if ($v === null) delete_option($name); else update_option($name, $v); }
delete_option('tix_ads_off_since'); delete_option('tix_ads_revoked_notice');
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
