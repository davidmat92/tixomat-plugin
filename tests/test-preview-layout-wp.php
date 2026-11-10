<?php
/** Vertriebs-Vorschau: Kopf, Fuß, Farben (1.38.729). plat eval-file …/test-preview-layout-wp.php */
if (!defined('ABSPATH') || !class_exists('TIX_Sales_Previews') || !method_exists('TIX_Website', 'layout')) { echo "Tixomat ab 1.38.729 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (!(class_exists('TIX_Sales') && TIX_Sales::multi())) { echo "Nur auf der Plattform\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : '']; };
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
TIX_Sales_Previews::install();
$ins = new ReflectionMethod('TIX_Sales_Previews', 'insert'); $ins->setAccessible(true);
$tok = new ReflectionMethod('TIX_Sales_Previews', 'new_token'); $tok->setAccessible(true);
$d = ['token' => $tok->invoke(null, 'p'), 'claim' => $tok->invoke(null, 'c'), 'owner' => get_current_user_id(), 'status' => 'active', 'name' => 'PLTEST Club',
    'created_at' => time(), 'expires_at' => time() + 7 * DAY_IN_SECONDS, 'opened_at' => 0, 'claimed_at' => 0, 'claimed_org' => 0, 'extended' => false, 'views' => 0,
    'last_view_at' => 0, 'claimed_by' => 0, 'template' => 'plakat', 'source_url' => '', 'read_mode' => 'direct', 'notice' => null, 'customer_email' => '', 'note' => '',
    'organizer' => ['name' => 'PLTEST Club', 'logo_id' => 0, 'accent' => ''], 'events' => []];
$id = intval($ins->invoke(null, $d));
global $wpdb;
try {
    $eq('Vorschau angelegt', true, $id > 0);
    [$s, $r] = $rest('PATCH', "/sales/previews/$id", ['header' => ['logo' => 'name', 'cta' => false], 'colors' => ['background' => '#FFFFFF', 'text' => '#DDDDDD']]);
    $p = $r['preview'] ?? $r;
    $eq('PATCH header/colors: gespeichert (Rohzustand), Warnung', [200, 'name', ['show' => false, 'label' => ''], '#DDDDDD', 'colors.text'],
        [$s, $p['header']['logo'] ?? '', $p['header']['cta'] ?? null, $p['colors']['text'] ?? '', $p['layout_warnings'][0]['field'] ?? '']);
    [$s, $r] = $rest('PATCH', "/sales/previews/$id", ['footer' => ['copyright' => 'PLTEST GmbH']]);
    $p = $r['preview'] ?? $r;
    $eq('PATCH nur footer: header/colors bleiben', [200, 'name', 'PLTEST GmbH', '#FFFFFF'], [$s, $p['header']['logo'] ?? '', $p['footer']['copyright'] ?? '', $p['colors']['background'] ?? '']);
    $eq('Ungültige Werte 400 mit Feld', [400, 'header.logo_size'], (function ($r) { return [$r[0], $r[1]['data']['field'] ?? '']; })($rest('PATCH', "/sales/previews/$id", ['header' => ['logo_size' => 'xxl']])));
    [$s, $r] = $rest('PATCH', "/sales/previews/$id", ['colors' => null]);
    $p = $r['preview'] ?? $r;
    $eq('colors: null = zurück auf Vorlage', [200, null], [$s, $p['colors']['background'] ?? null]);
    // öffentlich: wirksame Werte wie /public/website, Vorgaben der Vorlage plakat
    $l = TIX_Website::layout(['header' => ['logo' => 'name']], 'plakat', false);
    $eq('Layout: wirksame Werte + Vorlagen-Vorgaben, ohne Marke der Plattform', ['name', true, 'template'], [$l['header']['logo'], $l['footer']['logo'], $l['colors_source']['background']]);
    [$s, $pub] = $rest('GET', '/public/previews/' . $d['token']);
    if ($s === 200) $eq('Öffentliche Vorschau: website.header/footer/colors', ['plakat', 'name', 'PLTEST GmbH'], [$pub['website']['template'] ?? '', $pub['website']['header']['logo'] ?? '', $pub['website']['footer']['copyright'] ?? '']);
    else echo "      (öffentliche Vorschau ohne Events hier $s – nur Layout direkt geprüft)\n";
} finally {
    $wpdb->delete($wpdb->prefix . 'tix_previews', ['id' => $id]);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
