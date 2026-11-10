<?php
/** Zweites Logo „für hellen Hintergrund“ (1.38.725). wp eval-file …/test-logo-on-light-wp.php (Einzel-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Studio') || !method_exists('TIX_Studio', 'logo_on_light')) { echo "Tixomat ab 1.38.725 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = rest_get_server()->response_to_data($res, false); return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : '']; };
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
$keep = get_option(TIX_Studio::BRAND, null);
$atts = [];
$mk = function ($w) use (&$atts) {
    $up = wp_upload_bits('lol-test-' . wp_generate_password(5, false) . '.png', null, (string) file_get_contents(ABSPATH . 'wp-admin/images/w-logo-blue.png'));
    $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'LOL Test', 'post_status' => 'inherit'], $up['file']);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $up['file']));
    $atts[] = $id; return $id;
};
try {
    $dark = $mk('dunkel'); $light = $mk('hell');
    [$s, $d] = $rest('POST', '/studio/config', ['logo_id' => $dark, 'logo_on_light_id' => $light]);
    $eq('POST /studio/config: beide Logos gespeichert', [200, true, true], [$s, (string) ($d['logo_on_light'] ?? '') !== '' && $d['logo_on_light'] === ($d['brand']['logo_on_light'] ?? null), (string) ($d['custom']['logo_on_light'] ?? '') !== '']);
    $eq('logo bleibt das Logo für dunklen Grund', true, strpos((string) ($d['logo'] ?? ''), (string) wp_basename(get_attached_file($dark), '.png')) !== false);
    [$s, $c] = $rest('GET', '/public/site-config');
    $eq('site-config: logo_on_light + logo.on_light, logo.dark unverändert', [200, true, true, true],
        [$s, ($c['logo_on_light'] ?? null) !== null, ($c['logo']['on_light'] ?? null) === ($c['logo_on_light'] ?? 'x'), ($c['logo']['dark'] ?? null) === TIX_Studio::logo()]);
    [$s, , $code] = $rest('POST', '/studio/config', ['logo_on_light_id' => 999999]);
    $eq('unbekanntes Bild 400', [400, 'tix_invalid_field'], [$s, $code]);
    [$s, $d] = $rest('POST', '/studio/config', ['logo_on_light_id' => 0]);
    $eq('entfernen mit 0: null, Logo für dunklen Grund bleibt', [200, null, true], [$s, array_key_exists('logo_on_light', $d) ? $d['logo_on_light'] : 'fehlt', ($d['logo'] ?? '') !== '']);
    [, $c] = $rest('GET', '/public/site-config');
    $eq('site-config ohne zweites Logo: null', [true, null, null], [array_key_exists('logo_on_light', $c), $c['logo_on_light'] ?? null, $c['logo']['on_light'] ?? null]);
    // Kunden-Dienst: import_logo + set_brand_logo (wie wp tixomat marke logo --hell=…)
    $imp = TIX_Studio::import_logo(ABSPATH . 'wp-admin/images/w-logo-blue.png');
    if (!is_wp_error($imp)) $atts[] = $imp;
    $eq('Import aus Datei', true, !is_wp_error($imp) && $imp > 0);
    if (!is_wp_error($imp)) TIX_Studio::set_brand_logo('logo_on_light', $imp);
    $eq('… als Logo für hellen Grund gesetzt', true, TIX_Studio::logo_on_light() !== '');
    $bad = TIX_Studio::import_logo(ABSPATH . 'wp-includes/version.php');
    $eq('Keine Bilddatei → Fehler (gleiche Regeln wie Upload)', true, is_wp_error($bad));
    $eq('Medien-ID geht auch', $light, TIX_Studio::import_logo((string) $light));
    TIX_Studio::set_brand_logo('logo_on_light', 0);
    $eq('entfernen', '', TIX_Studio::logo_on_light());
    wp_set_current_user(0);
    $eq('Gast darf nicht ändern', 401, $rest('POST', '/studio/config', ['logo_on_light_id' => $light])[0]);
} finally {
    if ($keep === null) delete_option(TIX_Studio::BRAND); else update_option(TIX_Studio::BRAND, $keep, false);
    foreach ($atts as $id) wp_delete_attachment($id, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
