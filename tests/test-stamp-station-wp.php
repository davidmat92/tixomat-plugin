<?php
/**
 * 1.38.869: Stempel-Station – Rolle tix_stamp_station, GET /loyalty/station/token (Herzschlag, 15 s), POST /loyalty/claim,
 * gemeinsame Regel „1 Stempel je Event bzw. Tag“ mit dem Personal-Scan, Option station_events_only.
 * Plan uebergaben/plan-stempel-station-2026-11-17.md.   wp eval-file …/test-stamp-station-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Loyalty') || !method_exists('TIX_Loyalty', 'station_parse')) { echo "Tixomat ab 1.38.869 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$L = 'TIX_Loyalty';
global $wpdb;
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$req = function ($m, $route, $body = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $route); if ($q) $x->set_query_params($q); if ($body !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($body)); } $r = rest_do_request($x); return [$r->get_status(), $r->get_data()]; };
$clear = function () use ($wpdb) { $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_loyalty%'"); wp_cache_flush(); };
$clear();
if (class_exists('TIX_REST_API')) TIX_REST_API::ensure_staff_role();

// Rein: Code bauen/prüfen
$now = time();
$tok = $L::station_make(0, 0, 'wand-1', $now);
$p = $L::station_parse('evendis-stamp:' . $tok, $now + 3);
$eq('Code: Präfix erlaubt, Felder zurück; nach 16 s abgelaufen (410); verändert ungültig (400)', [['org' => 0, 'event' => 0, 'station' => 'wand-1', 'iat' => $now], 'tix_station_token_expired', 410, 'tix_station_token_invalid'],
    [$p, $L::station_parse($tok, $now + 16)->get_error_code(), $L::station_parse($tok, $now + 16)->get_error_data()['status'], $L::station_parse(rtrim(strtr(base64_encode(str_replace('.wand-2.', '.wand-9.', base64_decode(strtr($L::station_make(0, 0, 'wand-2', $now), '-_', '+/')))), '+/', '-_'), '='), $now)->get_error_code()]);
$eq('Rolle und Cap; Team-Rolle station; /me app_role', [true, true, 'tix_stamp_station'], [(bool) get_role('tix_stamp_station'), get_role('tix_stamp_station') ? get_role('tix_stamp_station')->has_cap('tix_app_stamp_station') : false, TIX_Team::APP_ROLES['station'] ?? '']);

if ($multi) {
    $sub = wp_insert_user(['user_login' => 'stsub' . wp_rand(), 'user_pass' => wp_generate_password(20), 'user_email' => 'stsub' . wp_rand() . '@example.test', 'role' => 'subscriber']);
    $st = wp_insert_user(['user_login' => 'stst' . wp_rand(), 'user_pass' => wp_generate_password(20), 'user_email' => 'stst' . wp_rand() . '@example.test', 'role' => 'tix_stamp_station']);
    wp_set_current_user($sub); [$s1] = $req('GET', '/loyalty/station/token');
    wp_set_current_user($st); [$s2, $d2] = $req('GET', '/loyalty/station/token');
    $eq('Plattform: Gast 403; Station ohne Veranstalter 403', [403, 403], [$s1, $s2]);
    wp_set_current_user(0);
    require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($sub); wp_delete_user($st);
    echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
    return;
}

$bak = []; foreach (['_tix_loyalty_enabled', '_tix_loyalty_station_events_only', '_tix_loyalty_stamps_per_visit'] as $o) $bak[$o] = get_option($o, null);
update_option('_tix_loyalty_enabled', '1'); update_option('_tix_loyalty_station_events_only', '0', false); update_option('_tix_loyalty_stamps_per_visit', 1);
$stu = wp_insert_user(['user_login' => 'station' . wp_rand(), 'user_pass' => wp_generate_password(20), 'user_email' => 'station' . wp_rand() . '@example.test', 'role' => 'tix_stamp_station']);
$guest = wp_insert_user(['user_login' => 'gast' . wp_rand(), 'user_pass' => wp_generate_password(20), 'user_email' => 'gast' . wp_rand() . '@example.test', 'role' => 'subscriber']);
$guest2 = wp_insert_user(['user_login' => 'gast2' . wp_rand(), 'user_pass' => wp_generate_password(20), 'user_email' => 'gast2' . wp_rand() . '@example.test', 'role' => 'subscriber']);
$adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);

wp_set_current_user($stu);
$me = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/me'))->get_data();
[$s0, $d0] = $req('GET', '/loyalty/scan');
[$st, $d] = $req('GET', '/loyalty/station/token', null, ['device' => 'Wand-1!']);
$eq('Station: /me app_role station; Personal-Route verboten; Code mit Präfix, 15 s, alle 10 s, Gerät, Marke, Prämien, Schritte', ['station', true, 200, 0, 15, 10, 'wand-1', true, true, 3],
    [$me['user']['app_role'] ?? ($me['app_role'] ?? ''), in_array($s0, [403, 404], true), $st, strpos((string) ($d['qr'] ?? ''), 'evendis-stamp:'), $d['ttl'] ?? 0, $d['refresh_in'] ?? 0, $d['station_id'] ?? '', isset($d['brand']['name']), !empty($d['rewards']), count($d['steps'] ?? [])]);
$mode = $d['mode'] ?? ''; $eid = intval($d['event']['id'] ?? 0);

// Gast scannt
wp_set_current_user($guest);
$p0 = $L::points($guest);
[$st, $c] = $req('POST', '/loyalty/claim', ['token' => $d['qr']]);
$eq('Gast scannt: Stempel erhalten, Punkte +1', [200, 1, $p0 + 1, 'Stempel erhalten'], [$st, $c['awarded'] ?? 0, $c['points'] ?? -1, $c['message'] ?? '']);
wp_set_current_user($stu); [, $d2] = $req('GET', '/loyalty/station/token', null, ['device' => 'wand-1']);
wp_set_current_user($guest);
[$st, $c2] = $req('POST', '/loyalty/claim', ['token' => $d2['token']]);
$eq('Zweiter Scan (neuer Code): 409 tix_already_stamped mit Stand', [409, 'tix_already_stamped', $p0 + 1], [$st, $c2['code'] ?? '', $c2['data']['points'] ?? -1]);
// Personal-Scan danach am selben Tag/Event: gesperrt (gemeinsame Regel)
wp_set_current_user($adm);
[$st, $aw] = $req('POST', '/loyalty/award', ['token' => $L::make_token($guest), 'event_id' => $eid]);
[$st0, $aw0] = $req('POST', '/loyalty/award', ['token' => $L::make_token($guest), 'event_id' => 0]);
$eq('Personal-Scan danach: für Event und Tag schon gestempelt', [true, true], [!empty($aw['already']), !empty($aw0['already'])]);
// Umgekehrt: Personal-Scan zuerst sperrt den Code
$req('POST', '/loyalty/award', ['token' => $L::make_token($guest2), 'event_id' => 0]);
wp_set_current_user($stu); [, $d3] = $req('GET', '/loyalty/station/token', null, ['device' => 'wand-1']);
wp_set_current_user($guest2);
[$st, $c3] = $req('POST', '/loyalty/claim', ['token' => $d3['token']]);
$eq('Personal-Scan zuerst → Code-Stempel heute gesperrt', [409, 'tix_already_stamped'], [$st, $c3['code'] ?? '']);
// Abgelaufen, gefälscht, Station aus
[$sx, $cx] = $req('POST', '/loyalty/claim', ['token' => $L::station_make(0, 0, 'wand-1', time() - 20)]);
[$sy, $cy] = $req('POST', '/loyalty/claim', ['token' => 'evendis-stamp:quatsch']);
[$sz, $cz] = $req('POST', '/loyalty/claim', ['token' => $L::station_make(0, 0, 'nie-an', time())]);
$eq('Abgelaufen 410, gefälscht 400, Station ohne Herzschlag 409 offline', [410, 'tix_station_token_expired', 400, 'tix_station_token_invalid', 409, 'tix_station_offline'], [$sx, $cx['code'] ?? '', $sy, $cy['code'] ?? '', $sz, $cz['code'] ?? '']);
// Gast darf keine Codes holen
[$sg] = $req('GET', '/loyalty/station/token');
$eq('Gast holt keinen Stations-Code (403)', 403, $sg);
// Option „nur während Events“
update_option('_tix_loyalty_station_events_only', '1', false);
wp_set_current_user($stu);
[$se, $de] = $req('GET', '/loyalty/station/token', null, ['device' => 'wand-1']);
$cfg = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/loyalty/config'))->get_data();
if ($L::station_event(0)) $eq('nur während Events: Event läuft → Code mit mode event', [200, 'event'], [$se, $de['mode'] ?? '']);
else $eq('nur während Events, kein Event → 409 tix_station_no_event mit next_event', [409, 'tix_station_no_event', true], [$se, $de['code'] ?? '', array_key_exists('next_event', (array) ($de['data'] ?? []))]);
$eq('GET /loyalty/config zeigt station_events_only', true, $cfg['station_events_only'] ?? null);
// Prämien aus → Station 403
update_option('_tix_loyalty_enabled', '0');
[$sd, $dd] = $req('GET', '/loyalty/station/token', null, ['device' => 'wand-1']);
$eq('Prämien aus → 403 tix_loyalty_disabled', [403, 'tix_loyalty_disabled'], [$sd, $dd['code'] ?? '']);

// 1.38.870: Station nur mit Code verlassen
update_option('_tix_loyalty_enabled', '1');
update_option('_tix_loyalty_station_events_only', '0', false);
$pinbak = get_option('_tix_loyalty_station_exit_pin', null);
wp_set_current_user($adm);
[$sp0, $dp0] = $req('POST', '/loyalty/config', ['station_exit_pin' => '12a']);
[$sp1, $dp1] = $req('POST', '/loyalty/config', ['station_exit_pin' => '4711']);
$cfg = rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/loyalty/config'))->get_data();
$eq('PIN setzen: ungültig 400 station_exit_pin; gültig nur gehasht gespeichert, GET nur station_exit_pin_set', [400, 'station_exit_pin', 200, true, false, false],
    [$sp0, $dp0['data']['field'] ?? '', $sp1, $cfg['station_exit_pin_set'] ?? null, get_option('_tix_loyalty_station_exit_pin') === '4711', strpos(wp_json_encode($cfg), '4711') !== false]);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_station\\_exit%'"); wp_cache_flush();
wp_set_current_user($stu);
[, $dtk] = $req('GET', '/loyalty/station/token', null, ['device' => 'wand-1']);
[$e1, $x1] = $req('POST', '/loyalty/station/exit', ['pin' => '0000', 'device' => 'wand-1']);
[$e2, $x2] = $req('POST', '/loyalty/station/exit', ['pin' => '4711', 'device' => 'wand-1']);
wp_set_current_user($guest2);
[$eo, $xo] = $req('POST', '/loyalty/claim', ['token' => $dtk['token']]);
wp_set_current_user($stu);
$eq('Token zeigt exit_pin; falsch 403 tix_station_pin_wrong mit Restversuchen; richtig {ok} und Station sofort aus (claim 409 offline)', [true, 403, 'tix_station_pin_wrong', 4, 200, true, 409, 'tix_station_offline'],
    [$dtk['exit_pin'] ?? null, $e1, $x1['code'] ?? '', $x1['data']['remaining'] ?? null, $e2, $x2['ok'] ?? false, $eo, $xo['code'] ?? '']);
$codes = []; for ($i = 0; $i < 5; $i++) $codes[] = $req('POST', '/loyalty/station/exit', ['pin' => '9999', 'device' => 'wand-1'])[0];
[$e3, $x3] = $req('POST', '/loyalty/station/exit', ['pin' => '4711', 'device' => 'wand-1']);
[$e3b] = $req('POST', '/loyalty/station/exit', ['pin' => '4711', 'device' => 'wand-2']);
$eq('5 Fehlversuche → 1 min Sperre je Gerät (auch der richtige Code wartet), anderes Gerät nicht gesperrt', [[403, 403, 403, 403, 429], 429, 'tix_station_pin_locked', true, 200], [$codes, $e3, $x3['code'] ?? '', ($x3['data']['retry_after'] ?? 0) > 0, $e3b]);
wp_set_current_user($guest);
[$eg] = $req('POST', '/loyalty/station/exit', ['pin' => '4711']);
wp_set_current_user($stu);
[$ec] = $req('POST', '/loyalty/config', ['station_exit_pin' => '']);
wp_set_current_user($adm);
$req('POST', '/loyalty/config', ['station_exit_pin' => '']);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_station\\_exit%'"); wp_cache_flush();
wp_set_current_user($stu);
[$e4, $x4] = $req('POST', '/loyalty/station/exit', ['pin' => '']);
$eq('Gast darf nicht prüfen (403); Station darf den PIN nicht ändern; ohne PIN → 409 tix_station_pin_unset', [403, true, 409, 'tix_station_pin_unset'], [$eg, in_array($ec, [401, 403], true), $e4, $x4['code'] ?? '']);
if ($pinbak === null) delete_option('_tix_loyalty_station_exit_pin'); else update_option('_tix_loyalty_station_exit_pin', $pinbak, false);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_station\\_exit%'");

// Aufräumen
wp_set_current_user(0);
foreach ($bak as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v); }
delete_transient('tix_stamp_station_' . md5('0|wand-1'));
$clear();
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$stu, $guest, $guest2] as $u) wp_delete_user($u);
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
