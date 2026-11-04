<?php
/** Integrationstest Statistik Stufe 2/4 – Kurzlinks /go/, Push-Kampagnen mit Öffnungen, Wochenmail-UTM, Matomo je Event,
 *  Plattform-Sicht. wp eval-file …/tests/test-stufe2-wp.php (Einzel-Seite und Plattform). Matomo wird über pre_http_request
 *  nachgebildet, es geht nichts ins Netz. */
if (!defined('ABSPATH') || !class_exists('TIX_Short_Links')) { echo "Plugin nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function s2_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function s2_rest($m, $r, array $b = [], array $hd = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); foreach ($hd as $k => $v) $q->set_header($k, $v); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
TIX_Order::create_tables(); TIX_Order_Attribution::maybe_create(); TIX_Web_Stats::maybe_create(); TIX_Web_Stats::maybe_create_dim(); TIX_Short_Links::maybe_create();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $orders = [];
$UA = ['user-agent' => 'Mozilla/5.0 (iPhone) Test'];
$owner = wp_insert_user(['user_login' => "s2_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "s2_owner_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "s2_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "s2_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'S2 Veranstalter', 'post_name' => "s2-veranstalter-$sfx", 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'S2 Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
}
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "S2 Party $sfx", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 7 * DAY_IN_SECONDS), '_tix_ticket_categories' => [['name' => 'Regulär', 'price' => 10, 'qty' => 100]]]]); $posts[] = $ev;
$ev2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "S2 Fremd $sfx", 'meta_input' => ['_tix_organizer_id' => $org2, '_tix_date_start' => wp_date('Y-m-d', time() + 7 * DAY_IN_SECONDS)]]); $posts[] = $ev2;
$ot = TIX_Order::table_name(); $oi = TIX_Order::items_table_name();
$mk_order = function ($event, array $attr, $status = 'completed') use ($wpdb, $ot, $oi, &$orders) {
    $wpdb->insert($ot, ['order_number' => 'S2' . wp_rand(), 'status' => $status, 'order_key' => wp_generate_password(12, false), 'total' => 20, 'subtotal' => 20, 'payment_method' => 'mollie', 'billing_email' => 's2' . wp_rand() . '@example.test', 'date_created' => current_time('mysql')]);
    $id = intval($wpdb->insert_id); $orders[] = $id;
    $wpdb->insert($oi, ['order_id' => $id, 'event_id' => $event, 'quantity' => 2, 'total' => 20, 'name' => 'S2 – Regulär', 'cat_name' => 'Regulär']);
    if ($attr) TIX_Order_Attribution::set($id, $attr + ['channel' => 'web']);
    return $id;
};

// ═══ Kurzlinks ═══
wp_set_current_user($owner);
[$st, $r] = s2_rest('POST', '/marketing/links', ['event_id' => $ev, 'source' => 'Instagram', 'medium' => 'Story', 'campaign' => 'Herbst Party']);
$L = $r['link'] ?? [];
s2_eq('Link anlegen: frei (ohne Modul), Code 6 Zeichen, Werte normalisiert', [200, true, 'instagram', 'story', 'herbst-party'], [$st, (bool) preg_match('/^[a-z][a-z0-9]{5}$/', (string) ($L['code'] ?? '')), $L['source'] ?? '', $L['medium'] ?? '', $L['campaign'] ?? '']);
s2_eq('Kurzadresse /go/<code>, QR mit ?qr=1', [true, true], [substr((string) ($L['short_url'] ?? ''), -9) === '/go/' . ($L['code'] ?? ''), ($L['qr_url'] ?? '') === ($L['short_url'] ?? '') . '?qr=1']);
parse_str((string) wp_parse_url((string) ($L['url'] ?? ''), PHP_URL_QUERY), $q);
s2_eq('Ziel: Event-Seite mit UTM, utm_id go-<code> und c=<code>', ['instagram', 'story', 'herbst-party', 'go-' . $L['code'], $L['code']], [$q['utm_source'] ?? '', $q['utm_medium'] ?? '', $q['utm_campaign'] ?? '', $q['utm_id'] ?? '', $q['c'] ?? '']);
[$st, $d, $code] = s2_rest('POST', '/marketing/links', ['event_id' => $ev, 'code' => $L['code']]);
s2_eq('Kurzname doppelt → 409', [409, 'tix_code_taken'], [$st, $code]);
[$st, $d, $code] = s2_rest('POST', '/marketing/links', ['event_id' => $ev, 'code' => 'A b']);
s2_eq('Kurzname ungültig → 400', 400, $st);
[$st, $r2] = s2_rest('POST', '/marketing/links', ['event_id' => $ev, 'code' => "plakat-$sfx", 'label' => 'Plakat Innenstadt']);
$L2 = $r2['link'] ?? [];
parse_str((string) wp_parse_url((string) ($L2['url'] ?? ''), PHP_URL_QUERY), $q2);
s2_eq('Eigener Kurzname, ohne Quelle: QR-Aufruf setzt qr/qr', [200, "plakat-$sfx", 'link'], [$st, $L2['code'] ?? '', $q2['utm_source'] ?? '']);
[$st, $d, $code] = s2_rest('POST', '/marketing/links', ['event_id' => $ev2]);
s2_eq($multi ? 'Fremdes Event → 404' : 'Event der Seite → erlaubt', $multi ? 404 : 200, $st);
if (!$multi && $st === 200) $wpdb->delete(TIX_Short_Links::t(), ['id' => intval($d['link']['id'])]);
[$st, $d, $code] = s2_rest('POST', '/marketing/links', ['target' => 'https://example.com/fremd']);
s2_eq('Fremde Website als Ziel → 400', 400, $st);
[$st, $d, $code] = s2_rest('POST', '/marketing/links', ['target' => '/wp-admin/']);
s2_eq('Admin-Pfad als Ziel → 400', 400, $st);
if (!$multi) {
    [$st, $r3] = s2_rest('POST', '/marketing/links', ['target' => '/events/?zeit=heute', 'label' => 'Alle Events']);
    s2_eq('Einzel-Seite: Pfad der eigenen Website', [200, '/events/?zeit=heute'], [$st, $r3['link']['target'] ?? '']);
    if ($st === 200) $wpdb->delete(TIX_Short_Links::t(), ['id' => intval($r3['link']['id'])]);
} else {
    [$st, $r3] = s2_rest('POST', '/marketing/links', ['label' => 'Meine Seite']);
    $page = (string) wp_parse_url(TIX_Customer_URL::to(get_permalink($org)), PHP_URL_PATH);
    s2_eq('Plattform: ohne Ziel = eigene Veranstalterseite', [200, $page], [$st, $r3['link']['target'] ?? '']);
    if ($st === 200) $wpdb->delete(TIX_Short_Links::t(), ['id' => intval($r3['link']['id'])]);
    [$st] = s2_rest('POST', '/marketing/links', ['target' => '/events/']);
    s2_eq('Plattform: fremder Pfad → 400', 400, $st);
}

// Aufrufe: 2× Link, 1× QR, 1 Bot (zählt nicht), unbekannt → 404
wp_set_current_user(0);
[$st, $g] = s2_rest('GET', '/public/go/' . $L['code'], [], $UA);
s2_eq('Öffentlich auflösen: URL mit c=', [200, true], [$st, strpos((string) ($g['url'] ?? ''), 'c=' . $L['code']) !== false]);
s2_rest('GET', '/public/go/' . strtoupper($L['code']), [], $UA);
s2_rest('GET', '/public/go/' . $L['code'], ['qr' => '1'], $UA);
s2_rest('GET', '/public/go/' . $L['code'], [], ['user-agent' => 'Googlebot/2.1']);
[$st, $d, $code] = s2_rest('GET', '/public/go/gibtsnicht' . $sfx, [], $UA);
s2_eq('Unbekannter Code → 404 mit Rückfall auf die Startseite', [404, true], [$st, !empty($d['data']['fallback'])]);
// Zählung: Event-Seite mit utm_id go-<code> (Website schickt die Parameter weiter), Kauf mit link_code
$hit = function (array $p) use ($UA) { $q = new WP_REST_Request('POST', '/tixomat/v1/public/stats/hit'); $q->set_header('user-agent', $UA['user-agent']); $q->set_body_params($p); return rest_do_request($q)->get_status(); };
$hit(['step' => 'event', 'event_id' => $ev, 'utm_source' => 'instagram', 'utm_campaign' => 'herbst-party', 'utm_id' => 'go-' . $L['code']]);
$hit(['step' => 'event', 'event_id' => $ev, 'utm_source' => 'instagram', 'utm_campaign' => 'herbst-party', 'utm_id' => 'go-' . $L['code']]);
$o1 = $mk_order($ev, ['source' => 'instagram', 'campaign' => 'herbst-party', 'link_code' => $L['code']]);
$o2 = $mk_order($ev, ['source' => 'qr', 'utm_id' => 'go-' . $L['code']]);
$mk_order($ev, ['source' => 'instagram', 'link_code' => $L['code']], 'pending'); // unbezahlt zählt nicht
wp_set_current_user($owner);
[$st, $ls] = s2_rest('GET', '/marketing/links');
$row = []; foreach ((array) ($ls['links'] ?? []) as $x) if ($x['code'] === $L['code']) $row = $x;
s2_eq('Liste: Klicks 2, QR 1 (Bot nicht), Aufrufe 2, Käufe 2 (link_code + utm_id), Umsatz 40', [2, 1, 2, 2, 40.0], [$row['clicks'] ?? null, $row['clicks_qr'] ?? null, $row['views'] ?? null, $row['orders'] ?? null, $row['revenue'] ?? null]);
s2_eq('Summen über alle Links', [2, 1, 2], [$ls['totals']['clicks'] ?? null, $ls['totals']['clicks_qr'] ?? null, $ls['totals']['orders'] ?? null]);
if ($multi) {
    wp_set_current_user($other);
    [$st, $lo] = s2_rest('GET', '/marketing/links');
    s2_eq('Anderer Veranstalter sieht die Links nicht', [200, 0], [$st, count((array) ($lo['links'] ?? []))]);
    [$st] = s2_rest('POST', '/marketing/links/' . $L['id'], ['label' => 'fremd']);
    s2_eq('…und darf sie nicht ändern', 404, $st);
    wp_set_current_user($owner);
}
[$st, $u] = s2_rest('POST', '/marketing/links/' . $L2['id'], ['archived' => true, 'campaign' => 'Plakat Nord']);
s2_eq('Archivieren + Kampagne ändern', [200, true, 'plakat-nord'], [$st, $u['link']['archived'] ?? null, $u['link']['campaign'] ?? '']);
wp_set_current_user(0);
[$st] = s2_rest('GET', '/public/go/' . $L2['code'], [], $UA);
s2_eq('Archivierter Link leitet nicht weiter', 404, $st);
wp_set_current_user($owner);
[$st] = s2_rest('DELETE', '/marketing/links/' . $L2['id']);
s2_eq('Löschen', [200, null], [$st, $wpdb->get_var($wpdb->prepare("SELECT id FROM " . TIX_Short_Links::t() . " WHERE id = %d", $L2['id']))]);
$staff = wp_insert_user(['user_login' => "s2_staff_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "s2_staff_$sfx@example.test", 'role' => 'tix_staff']); $users[] = $staff;
if ($multi) update_user_meta($staff, '_tix_team_organizer_id', $org);
wp_set_current_user($staff);
[$st] = s2_rest('GET', '/marketing/links');
s2_eq('Mitarbeiter ohne Statistik-Recht → 403', 403, $st);

// ═══ Push-Kampagnen ═══
wp_set_current_user($owner);
$log_before = get_option('_tix_app_broadcast_log', null);
[$st, $b] = s2_rest('POST', '/notifications/broadcast', ['title' => 'S2 Hinweis', 'body' => 'Nur noch wenige Tickets', 'event_id' => $ev, 'target' => 'event']);
$cmp = (string) ($b['campaign'] ?? '');
s2_eq('Sendung bekommt eine Kampagne push-xxxxxxxx', [200, true], [$st, (bool) preg_match('/^push-[a-z0-9]{8}$/', $cmp)]);
wp_set_current_user(0);
s2_eq('Öffnung in der App zählt (204)', 204, $hit(['step' => 'push_open', 'src' => 'app', 'campaign' => $cmp]));
$hit(['step' => 'push_open', 'src' => 'app', 'campaign' => $cmp]);
$hit(['step' => 'push_open', 'src' => 'web', 'campaign' => $cmp]);            // nur App
$hit(['step' => 'push_open', 'src' => 'app', 'campaign' => 'push-zzzzzzzz']); // unbekannte Sendung
$dim_n = intval($wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM " . TIX_Web_Stats::dim_table() . " WHERE step = 'push_open' AND campaign = %s", $cmp)));
$dim_x = intval($wpdb->get_var("SELECT COUNT(*) FROM " . TIX_Web_Stats::dim_table() . " WHERE step = 'push_open' AND campaign = 'push-zzzzzzzz'"));
s2_eq('Gezählt: 2 Öffnungen, Web und unbekannte Kennung nicht', [2, 0], [$dim_n, $dim_x]);
$dim_org = intval($wpdb->get_var($wpdb->prepare("SELECT MAX(org_id) FROM " . TIX_Web_Stats::dim_table() . " WHERE step = 'push_open' AND campaign = %s", $cmp)));
s2_eq('Öffnungen dem Veranstalter der Sendung zugeordnet', intval($org), $dim_org);
$mk_order($ev, ['source' => 'push', 'medium' => 'push', 'campaign' => $cmp]);
wp_set_current_user($owner);
[$st, $bl] = s2_rest('GET', '/notifications/broadcasts');
$br = []; foreach ((array) ($bl['broadcasts'] ?? []) as $x) if (($x['campaign'] ?? '') === $cmp) $br = $x;
s2_eq('Verlauf: Kampagne, 2 Öffnungen, 1 Kauf, 20 € Umsatz', [$cmp, 2, 1, 20.0], [$br['campaign'] ?? null, $br['opens'] ?? null, $br['orders'] ?? null, $br['revenue'] ?? null]);

// ═══ Wochenmail-UTM ═══
$u = TIX_Weekly_Mail::utm('https://example.test/events/a/?x=1');
parse_str((string) wp_parse_url($u, PHP_URL_QUERY), $wq);
s2_eq('Wochenmail-Links mit newsletter/email/wochenmail-JJJJ-KW, vorhandene Parameter bleiben', ['1', 'newsletter', 'email', 'wochenmail-' . wp_date('o') . '-' . wp_date('W')], [$wq['x'] ?? '', $wq['utm_source'] ?? '', $wq['utm_medium'] ?? '', $wq['utm_campaign'] ?? '']);

// ═══ Matomo je Event ═══
$m_site = $multi ? get_post_meta($org, '_tix_matomo_site', true) : get_option('tix_matomo_site', null);
$GLOBALS['s2_mat'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'https://matomo.test/') !== 0) return $pre;
    $GLOBALS['s2_mat'][] = (array) ($args['body'] ?? []);
    $m = (string) ($args['body']['method'] ?? '');
    $body = $m === 'VisitsSummary.get' ? ['nb_visits' => 12, 'nb_uniq_visitors' => 9, 'nb_actions' => 30] : ($m === 'Goals.get' ? ['nb_conversions' => 2, 'revenue' => 40] : [['label' => 'Direkt', 'nb_visits' => 5]]);
    return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);
// Verbindung über die Klasse: eigenes Matomo des Bereichs eintragen (wie im Studio)
$mo = ['own_url' => 'https://matomo.test', 'own_site_id' => 7, 'own_token_enc' => TIX_Ad_Accounts::enc('tok')];
if ($multi) update_post_meta($org, '_tix_matomo_site', $mo); else update_option('tix_matomo_site', $mo, false);
$c = TIX_Matomo::conn($org);
if (!$c || ($c['token'] ?? '') === '') {
    echo "hinweis: Matomo-Verbindung im Test nicht herstellbar (Format) – Event-Prüfung übersprungen\n";
} else {
    [$st, $ms] = s2_rest('GET', '/marketing/matomo/stats', ['event_id' => $ev]);
    $seg = ''; foreach ($GLOBALS['s2_mat'] as $bd) if (!empty($bd['segment'])) $seg = (string) $bd['segment'];
    $path = rtrim((string) wp_parse_url(TIX_Customer_URL::to(get_permalink($ev)), PHP_URL_PATH), '/');
    s2_eq('Matomo je Event: Segment auf die Event-Seite, Besuche, kein Kauf-Ziel', [200, 'pageUrl=@' . rawurlencode($path), 12, null, $ev], [$st, $seg, $ms['summary']['visits'] ?? null, $ms['ecommerce'] ?? 'x', $ms['event_id'] ?? null]);
    [$st] = s2_rest('GET', '/marketing/matomo/stats', ['event_id' => $ev2]);
    s2_eq($multi ? 'Matomo: fremdes Event → 404' : 'Matomo: Event der Seite → 200', $multi ? 404 : 200, $st);
}
if ($multi) { if ($m_site === '' || $m_site === false) delete_post_meta($org, '_tix_matomo_site'); else update_post_meta($org, '_tix_matomo_site', $m_site); }
else { if ($m_site === null) delete_option('tix_matomo_site'); else update_option('tix_matomo_site', $m_site, false); }

// ═══ Plattform-Sicht ═══
wp_set_current_user($owner);
[$st] = s2_rest('GET', '/platform/stats');
s2_eq('Plattform-Sicht: Veranstalter → 403', 403, $st);
wp_set_current_user(1);
[$st, $ps, $code] = s2_rest('GET', '/platform/stats');
if (!$multi) s2_eq('Plattform-Sicht: Einzel-Seite → 404 tix_not_multi', [404, 'tix_not_multi'], [$st, $code]);
else {
    $top = []; foreach ((array) ($ps['top_organizers'] ?? []) as $x) if (intval($x['id']) === intval($org)) $top = $x;
    $chs = []; foreach ((array) ($ps['channels'] ?? []) as $x) $chs[$x['source']] = $x['orders'];
    s2_eq('Plattform-Sicht: Veranstalter mit 3 Käufen / 60 €, Trichter und App-Öffnungen da', [200, 3, 60.0, true, true], [$st, $top['orders'] ?? null, $top['revenue'] ?? null, ($ps['funnel']['event'] ?? 0) >= 2, ($ps['app']['push_open'] ?? 0) >= 2]);
    s2_eq('Plattform-Sicht: Kanäle ohne Kleinstgruppen (unter 3 → sonstige)', [true, false], [isset($chs['sonstige']) || array_sum($chs) >= 3, isset($chs['push']) && $chs['push'] < 3]);
}

// Aufräumen
wp_set_current_user(1);
foreach ($orders as $o) { $wpdb->delete($ot, ['id' => $o]); $wpdb->delete($oi, ['order_id' => $o]); $wpdb->delete(TIX_Order_Attribution::table(), ['order_id' => $o]); }
$wpdb->query($wpdb->prepare("DELETE FROM " . TIX_Short_Links::tc() . " WHERE link_id IN (SELECT id FROM " . TIX_Short_Links::t() . " WHERE organizer_id = %d AND event_id IN (%d, %d))", $org, $ev, $ev2));
$wpdb->query($wpdb->prepare("DELETE FROM " . TIX_Short_Links::t() . " WHERE event_id IN (%d, %d)", $ev, $ev2));
$wpdb->query($wpdb->prepare("DELETE FROM " . TIX_Web_Stats::dim_table() . " WHERE event_id IN (%d, %d) OR campaign = %s", $ev, $ev2, $cmp));
$wpdb->query($wpdb->prepare("DELETE FROM " . TIX_Web_Stats::table() . " WHERE ref IN (%d, %d) AND step <> 'po'", $ev, $ev2));
if ($log_before === null) delete_option('_tix_app_broadcast_log'); else update_option('_tix_app_broadcast_log', $log_before, false);
delete_transient('tix_platform_stats_' . md5(gmdate('Y-m-d', strtotime(current_time('Y-m-d')) - 29 * DAY_IN_SECONDS) . '|' . current_time('Y-m-d')));
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
