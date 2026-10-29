<?php
/** Integrationstest QR-Login „In der App anmelden“ (P7, 1.38.576). wp eval-file …/tests/test-staff-login-code-wp.php */
if (!defined('ABSPATH') || !class_exists('TIX_Staff_Login_Code')) { echo "TIX_Staff_Login_Code nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur lokal!\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function lc_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function lc_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$mk = function ($role) use (&$users, $sfx) { $id = wp_insert_user(['user_login' => "lc_{$role}_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "lc_{$role}_$sfx@example.test", 'role' => $role]); $users[] = $id; return $id; };
$owner = $mk('tix_organizer'); $helper = $mk('tix_entrance'); $other = $mk('tix_staff'); $guest = $mk('subscriber');
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'LC Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    update_user_meta($helper, '_tix_team_organizer_id', $org);
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'LC Fremd']); $posts[] = $org2;
    update_user_meta($other, '_tix_team_organizer_id', $org2);
}
wp_set_current_user(0);
[$st] = lc_rest('POST', '/staff/login-code');
lc_eq('ohne Anmeldung 401', 401, $st);
wp_set_current_user($guest);
[$st] = lc_rest('POST', '/staff/login-code');
lc_eq('Gast 403', 403, $st);
wp_set_current_user($owner);
[$st, $d] = lc_rest('POST', '/staff/login-code', ['user_id' => $helper]);
lc_eq('Inhaber für Einlass-Helfer: 200', 200, $st);
lc_eq('Code 10 Zeichen', 10, strlen($d['code'] ?? ''));
lc_eq('QR-Adresse mit Code', true, strpos($d['url'] ?? '', 'tix_app_login=' . ($d['code'] ?? 'x')) !== false);
$code = $d['code'];
if ($multi) {
    [$st] = lc_rest('POST', '/staff/login-code', ['user_id' => $other]);
    lc_eq('Plattform: fremder Veranstalter 403', 403, $st);
}
wp_set_current_user($helper);
[$st] = lc_rest('POST', '/staff/login-code', ['user_id' => $owner]);
lc_eq('Helfer darf nicht für andere', 403, $st);
wp_set_current_user(0);
[$st, $d] = lc_rest('POST', '/auth/login-code', ['code' => strtolower($code), 'device' => 'test']);
lc_eq('einlösen (Groß/klein egal) 200', 200, $st);
lc_eq('Token geliefert', true, strlen($d['token'] ?? '') > 20);
lc_eq('Konto = Helfer', $helper, intval($d['user']['id'] ?? 0));
[$st, , $c] = lc_rest('POST', '/auth/login-code', ['code' => $code]);
lc_eq('zweites Mal ungültig', 'tix_code_invalid', $c);
[$st, , $c] = lc_rest('POST', '/auth/login-code', ['code' => 'FALSCH1234']);
lc_eq('falscher Code', 'tix_code_invalid', $c);
// abgelaufen
wp_set_current_user($owner);
[, $d] = lc_rest('POST', '/staff/login-code');
$k = 'tix_slc_' . hash_hmac('sha256', $d['code'], wp_salt('auth'));
set_transient($k, ['user' => $owner, 'by' => $owner, 'exp' => time() - 1], 60);
wp_set_current_user(0);
[, , $c] = lc_rest('POST', '/auth/login-code', ['code' => $d['code']]);
lc_eq('abgelaufen', 'tix_code_invalid', $c);
foreach ($posts as $p) wp_delete_post($p, true);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($users as $u) wp_delete_user($u);
echo "\n" . ($GLOBALS['t_fails'] ? $GLOBALS['t_fails'] . ' FEHLER' : 'ALLES GRÜN') . " ({$GLOBALS['t_count']} Prüfungen)\n";
