<?php
/** Studio: Menü-Ansicht je Konto (TIX_Studio_Prefs, 1.38.655). wp eval-file …/tests/test-studio-prefs-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Studio_Prefs')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function sp_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function sp_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '', $res->get_headers()]; }
global $wpdb;
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$sfx = wp_rand(1000, 9999); $users = [];
$cut_before = get_option(TIX_Studio_Prefs::OPT_CUTOFF, null);
update_option(TIX_Studio_Prefs::OPT_CUTOFF, gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS), false);
$mk = function ($login, $role, $reg = '') use (&$users) { $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => "$login@example.test", 'role' => $role]); $users[] = $id; if ($reg) $GLOBALS['wpdb']->update($GLOBALS['wpdb']->users, ['user_registered' => $reg], ['ID' => $id]); clean_user_cache($id); return $id; };
$new = $mk("sp_neu_$sfx", 'tix_organizer');
$old = $mk("sp_alt_$sfx", 'tix_organizer', '2020-01-01 10:00:00');
$adm = $mk("sp_adm_$sfx", 'administrator');
$ent = $mk("sp_ein_$sfx", 'tix_entrance');
sp_eq('Vorgabe: neues Veranstalter-Konto kompakt, bestehendes komplett, Admin komplett', ['compact', 'full', 'full'], [TIX_Studio_Prefs::default_for(get_userdata($new)), TIX_Studio_Prefs::default_for(get_userdata($old)), TIX_Studio_Prefs::default_for(get_userdata($adm))]);
if (class_exists('TIX_Sales') && defined('TIX_Sales::ROLE')) {
    $sal = $mk("sp_ver_$sfx", TIX_Sales::ROLE);
    sp_eq('Vertrieb: komplett', 'full', TIX_Studio_Prefs::default_for(get_userdata($sal)));
}
wp_set_current_user($new);
[$st, $me] = sp_rest('GET', '/me');
sp_eq('GET /me: studio_prefs ohne eigene Wahl', [200, null, 'compact'], [$st, array_key_exists('menu_mode', (array) ($me['studio_prefs'] ?? [])) ? $me['studio_prefs']['menu_mode'] : 'fehlt', $me['studio_prefs']['menu_mode_default'] ?? '']);
[$st, $r, , $hd] = sp_rest('POST', '/studio/prefs', ['menu_mode' => 'full']);
sp_eq('Speichern: full, no-store', [200, 'full', 'no-store'], [$st, $r['studio_prefs']['menu_mode'] ?? '', $hd['Cache-Control'] ?? '']);
[$st, $me2] = sp_rest('GET', '/me');
sp_eq('… /me liefert die Wahl, User-Meta gesetzt', ['full', 'full'], [$me2['studio_prefs']['menu_mode'] ?? '', get_user_meta($new, TIX_Studio_Prefs::META, true)]);
[$st, $d, $code] = sp_rest('POST', '/studio/prefs', ['menu_mode' => 'riesig']);
sp_eq('Ungültiger Wert → 400 tix_invalid_field', [400, 'tix_invalid_field'], [$st, $code]);
[$st, $r] = sp_rest('POST', '/studio/prefs', ['menu_mode' => null]);
sp_eq('null setzt auf die Vorgabe zurück', [200, null, ''], [$st, array_key_exists('menu_mode', (array) ($r['studio_prefs'] ?? [])) ? $r['studio_prefs']['menu_mode'] : 'fehlt', (string) get_user_meta($new, TIX_Studio_Prefs::META, true)]);
wp_set_current_user($ent);
[$st, $r] = sp_rest('POST', '/studio/prefs', ['menu_mode' => 'compact']);
sp_eq('Auch Einlass-Konten dürfen speichern', [200, 'compact'], [$st, $r['studio_prefs']['menu_mode'] ?? '']);
wp_set_current_user(0);
[$st] = sp_rest('POST', '/studio/prefs', ['menu_mode' => 'full']);
sp_eq('Ohne Anmeldung → 401', 401, $st);
// Aufräumen
wp_set_current_user(1);
if ($cut_before === null) delete_option(TIX_Studio_Prefs::OPT_CUTOFF); else update_option(TIX_Studio_Prefs::OPT_CUTOFF, $cut_before, false);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
