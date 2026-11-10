<?php
/** Kopf, Fuß, Farben der Homepage (1.38.728). wp eval-file …/test-website-layout-wp.php (Einzel-Seite: mit Speichern; Plattform: nur Prüfung) */
if (!defined('ABSPATH') || !class_exists('TIX_Website') || !method_exists('TIX_Website', 'layout_defaults')) { echo "Tixomat ab 1.38.728 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b && $m === 'GET') $q->set_query_params($b); elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = rest_get_server()->response_to_data($res, false); return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : '']; };
$err = function ($w) { $r = TIX_Website::clean($w); return is_wp_error($r) ? (string) ($r->get_error_data()['field'] ?? '') : 'ok'; };
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
$pkg = get_option(TIX_Website::OPT_ENABLED, null);
try {
    // Prüfen
    $c = TIX_Website::clean(['header' => ['logo' => false, 'logo_size' => 'l', 'nav' => false, 'cta' => ['show' => true, 'label' => 'Jetzt Tickets'], 'gift' => false, 'sticky' => '0'],
        'footer' => ['claim' => 'Party seit 2010', 'columns' => false, 'copyright' => 'XXL Events GmbH'], 'colors' => ['background' => '#123', 'text' => 'FFFFFF', 'accent' => null]]);
    $eq('Prüfung: logo false → none, cta Objekt, Bool aus Text, Hex normalisiert', ['none', 'l', false, ['show' => true, 'label' => 'Jetzt Tickets'], false, '#112233', '#FFFFFF', null],
        is_wp_error($c) ? [$c->get_error_message()] : [$c['header']['logo'], $c['header']['logo_size'], $c['header']['nav'], $c['header']['cta'], $c['header']['sticky'], $c['colors']['background'], $c['colors']['text'], $c['colors']['accent']]);
    $eq('nicht gesetzt = null (Vorlage)', [null, null], is_wp_error($c) ? [] : [$c['header']['account'], $c['footer']['social']]);
    $eq('cta: false', ['show' => false, 'label' => ''], (TIX_Website::clean(['header' => ['cta' => false]]))['header']['cta'] ?? null);
    $eq('Fehler: Logo-Größe, unbekannte Option, Farbe, zu langer Claim', ['header.logo_size', 'header.foo', 'colors.background', 'footer.claim', 'colors.glow'],
        [$err(['header' => ['logo_size' => 'xl']]), $err(['header' => ['foo' => true]]), $err(['colors' => ['background' => 'rot']]), $err(['footer' => ['claim' => str_repeat('x', 200)]]), $err(['colors' => ['glow' => '#000000']])]);
    // Aufbereiten: Vorgaben je Vorlage + gesetzte Werte
    $x = TIX_Website::expand($c + ['template' => 'plakat']);
    $eq('Ausgabe: wirksame Werte, Rest nach Vorgabe (heutiges Aussehen)', ['none', true, true, 'XXL Events GmbH', true, '#112233', 'website', 'template'],
        [$x['header']['logo'], $x['header']['account'], $x['header']['info_bar'], $x['footer']['copyright'], $x['footer']['app'], $x['colors']['background'], $x['colors_source']['background'], $x['colors_source']['surface']]);
    $d = TIX_Website::expand(['template' => 'klar']);
    $eq('Ohne Angaben: Vorgaben, Farben null', [TIX_Website::layout_defaults('klar')['header'], [null, null, null, null]], [$d['header'], array_values($d['colors'])]);
    $cat = TIX_Website::catalog();
    $eq('Katalog: Optionen, Vorgaben je Vorlage, Farb-Schlüssel', [true, true, true, ['background', 'surface', 'text', 'accent']],
        [isset($cat['header_options']['logo_size']), isset($cat['layout_defaults']['nacht']['footer']), ($cat['header_options']['logo'] ?? null) === ['logo', 'name', 'none'], $cat['color_keys'] ?? []]);
    // Kontrast
    $eq('Kontrast WCAG: schwarz/weiß 21, gleiche Farbe 1', [21.0, 1.0], [TIX_Website::contrast('#000000', '#FFFFFF'), TIX_Website::contrast('#777777', '#777777')]);
    $w = TIX_Website::color_warnings(['background' => '#FFFFFF', 'text' => '#BBBBBB', 'surface' => null, 'accent' => '#FFFF00']);
    $eq('Warnungen: Text und Akzent zu schwach', ['colors.text', 'colors.accent'], array_column($w, 'field'));
    $eq('keine Warnung bei gutem Kontrast', [], TIX_Website::color_warnings(['background' => '#111111', 'text' => '#FFFFFF', 'accent' => '#FC2F55']));
    if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) {
        update_option(TIX_Website::OPT_ENABLED, '1', false);
        [$s, $g] = $rest('GET', '/studio/website');
        $before = $g['website'] ?? null;
        [$s, $p] = $rest('POST', '/studio/website', ['updated_at' => (string) $g['updated_at'], 'website' => ['template' => 'klar', 'header' => ['logo' => 'name'], 'colors' => ['background' => '#FFFFFF', 'text' => '#CCCCCC']]]);
        $eq('Entwurf gespeichert, Warnung im Studio, veröffentlicht unverändert', [200, true, 'colors.text'], [$s, ($p['draft']['header']['logo'] ?? '') === 'name', $p['warnings'][0]['field'] ?? '']);
        [, $pub] = $rest('GET', '/public/website');
        $eq('… öffentlich erst nach Veröffentlichen', true, ($pub['website']['header']['logo'] ?? '') !== 'name' || ($before['header']['logo'] ?? null) === 'name');
        [$s, $p] = $rest('POST', '/studio/website', ['updated_at' => (string) $p['updated_at'], 'publish' => true]);
        [, $pub] = $rest('GET', '/public/website');
        $eq('nach Veröffentlichen: header/colors in /public/website', [200, 'name', '#CCCCCC'], [$s, $pub['website']['header']['logo'] ?? '', $pub['website']['colors']['text'] ?? '']);
        [, $now] = $rest('GET', '/studio/website');
        $rest('POST', '/studio/website', ['updated_at' => (string) $now['updated_at'], 'website' => (array) ($before ?: ['template' => '']), 'publish' => true]);
    }
} finally {
    if ($pkg === null) delete_option(TIX_Website::OPT_ENABLED); else update_option(TIX_Website::OPT_ENABLED, $pkg, false);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
