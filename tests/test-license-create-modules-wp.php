<?php
/** Lizenz-Server: Module beim Anlegen (§97). wp eval-file …/tests/test-license-create-modules-wp.php. Nur lokale Test-Seiten. */
if (!defined('ABSPATH') || !class_exists('TIX_License_Server') || !class_exists('TIX_Modules')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function lm_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function lm_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
$keep = get_option('tix_license_server', null);
update_option('tix_license_server', '1');
TIX_License_Server::maybe_install();
global $wpdb; $ids = [];
$keys = TIX_Modules::keys();
$two = array_slice($keys, 0, 2);

$a = TIX_License_Server::create(['customer' => 'LM alle', 'email' => 'lm@example.test']); $ids[] = $a->id;
lm_eq('Ohne modules: alle (NULL)', null, TIX_License_Server::license_modules($a));
$b = TIX_License_Server::create(['customer' => 'LM zwei', 'email' => 'lm@example.test', 'modules' => $two]); $ids[] = $b->id;
lm_eq('Liste wird übernommen', $two, TIX_License_Server::license_modules($b));
$c = TIX_License_Server::create(['customer' => 'LM text', 'email' => 'lm@example.test', 'modules' => implode(',', $two) . ',quatsch']); $ids[] = $c->id;
lm_eq('Komma-Text, Unbekanntes fällt weg (Skript-Weg)', $two, TIX_License_Server::license_modules($c));
$d = TIX_License_Server::create(['customer' => 'LM leer', 'email' => 'lm@example.test', 'modules' => []]); $ids[] = $d->id;
lm_eq('Leere Liste = nur Grundfunktionen', [], TIX_License_Server::license_modules($d));
$e = TIX_License_Server::create(['customer' => 'LM stern', 'email' => 'lm@example.test', 'modules' => '*']); $ids[] = $e->id;
lm_eq('„*“ = alle', null, TIX_License_Server::license_modules($e));
$log = $wpdb->get_var($wpdb->prepare("SELECT data FROM {$wpdb->prefix}tix_license_log WHERE license_id = %d AND action = 'create'", $b->id));
lm_eq('Verlauf nennt die Module', $two, json_decode((string) $log, true)['modules'] ?? null);

$adm = wp_insert_user(['user_login' => 'lm_adm_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'lmadm' . wp_rand() . '@example.test', 'role' => 'administrator']);
wp_set_current_user($adm);
if (!isset(rest_get_server()->get_routes()['/tixomat/v1/license/admin/licenses'])) { echo "--    REST-Teil übersprungen (Lizenz-Server war beim Start aus)\n"; } else {
[$st, , $code] = lm_rest('POST', '/license/admin/licenses', ['customer' => 'LM rest', 'email' => 'lm@example.test', 'modules' => ['quatsch']]);
lm_eq('REST: unbekanntes Modul → 400', [400, 'tix_invalid_field'], [$st, $code]);
[$st, $r] = lm_rest('POST', '/license/admin/licenses', ['customer' => 'LM rest', 'email' => 'lm@example.test', 'modules' => $two]);
$ids[] = $r['license']['id'] ?? 0;
lm_eq('REST: Anlage mit Modulen, Antwort nennt sie', [200, $two], [$st, $r['license']['modules'] ?? 'fehlt']);
// Kundenseite bekommt die Module über /license/check
[$st, $chk] = lm_rest('POST', '/license/check', ['key' => $r['key'], 'site' => home_url(), 'version' => TIXOMAT_VERSION]);
lm_eq('/license/check liefert die Module an die Seite', [200, $two], [$st, $chk['modules'] ?? 'fehlt']);
}

foreach (array_filter($ids) as $id) { $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $id]); $wpdb->delete($wpdb->prefix . 'tix_license_sites', ['license_id' => $id]); $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $id]); }
require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($adm); wp_set_current_user(0);
if ($keep === null) delete_option('tix_license_server'); else update_option('tix_license_server', $keep);
echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n";
