<?php
/**
 * Paket website folgt der Lizenz (1.38.720, Fund KMT): Lizenz-Abgleich setzt `tix_website_enabled`.
 *   kk eval-file …/test-website-license-wp.php   (Einzel-Seite; Lizenzserver per pre_http_request nachgestellt)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Website') || !method_exists('TIX_Website', 'sync_license')) { echo "Tixomat ab 1.38.720 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) { echo "Nur auf einer Einzel-Seite (kk)\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function wl_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
$keep = [];
foreach (['tix_license_key', 'tix_license_status', 'tix_license_mode', TIX_Website::OPT_ENABLED, TIX_Website::OPT_LIC_STATE] as $o) $keep[$o] = get_option($o, null);
$key = 'TIX-WLTEST-' . strtoupper(wp_generate_password(8, false));
update_option('tix_license_key', $key, false);
update_option('tix_license_mode', 'selfhosted', false);
delete_option(TIX_Website::OPT_LIC_STATE);
$host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
$GLOBALS['wl_mods'] = [];
$GLOBALS['wl_signed'] = true;
add_filter('pre_http_request', function ($pre, $a, $url) use ($key, $host) {
    if (strpos($url, '/license/check') === false) return $pre;
    $d = ['valid' => true, 'status' => 'active', 'stage' => 'active', 'enforce' => false, 'expires_at' => null, 'last_event_date' => '', 'basis_url' => '',
        'modules' => $GLOBALS['wl_mods'], 'modules_v' => 2, 'issued_at' => time()];
    $d['signature'] = $GLOBALS['wl_signed'] ? TIX_License_Server::signature($key, $d + ['host' => $host]) : 'falsch';
    return ['headers' => [], 'body' => wp_json_encode($d), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
}, 10, 3);
$check = function ($mods, $signed = true) { $GLOBALS['wl_mods'] = $mods; $GLOBALS['wl_signed'] = $signed; TIX_License::check(); return get_option(TIX_Website::OPT_ENABLED, null); };

// Erster Abgleich mit ausdrücklicher Liste mit website → an (KMT: Plattform hatte Homepage an, Schalter stand auf 0)
update_option(TIX_Website::OPT_ENABLED, '0', false);
wl_eq('erster Abgleich, Liste mit website → an', ['1', 'on'], [$check(['website', 'seating']), get_option(TIX_Website::OPT_LIC_STATE)]);
wl_eq('Studio sieht das Paket', true, TIX_Website::package_payload()['enabled']);
// Schalter im WP-Admin dazwischen bleibt, solange die Lizenz gleich bleibt
update_option(TIX_Website::OPT_ENABLED, '0', false);
wl_eq('gleiche Lizenz: Hand-Schalter bleibt', '0', $check(['website', 'seating']));
// Plattform schaltet aus
update_option(TIX_Website::OPT_ENABLED, '1', false);
wl_eq('website aus der Lizenz → aus', ['0', 'off'], [$check(['seating']), get_option(TIX_Website::OPT_LIC_STATE)]);
// Plattform schaltet alles an (Liste wird null = alle)
wl_eq('Wechsel auf alle Module → an', '1', $check(null));
// unsignierte Antwort ändert nichts
wl_eq('ohne gültige Signatur keine Änderung', '1', $check(['seating'], false));
wl_eq('signiert wieder aus', '0', $check(['seating']));
// erster Abgleich mit „alle“ ändert nichts (nichts Bestimmtes verkauft)
delete_option(TIX_Website::OPT_LIC_STATE);
update_option(TIX_Website::OPT_ENABLED, '0', false);
wl_eq('erster Abgleich mit alle Modulen: Schalter bleibt, Stand gemerkt', ['0', 'on'], [$check(null), get_option(TIX_Website::OPT_LIC_STATE)]);
wl_eq('danach website weg → aus bleibt aus', '0', $check(['seating']));
wl_eq('und wieder dazu → an', '1', $check(['seating', 'website']));
// Listen vor dem Modul-Verkauf (v1) zählen wie alle
wl_eq('Stand v1-Liste = alle', 'all', TIX_Website::license_state(['valid' => true, 'modules' => ['loyalty'], 'modules_v' => 1]));
wl_eq('ungültige Lizenz: keine Angabe', null, TIX_Website::license_state(['valid' => false, 'modules' => ['website'], 'modules_v' => 2]));

foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
