<?php
/** Homepage-Marke zur Laufzeit (1.38.673): site-config.brand. wp eval-file …/tests/test-site-brand-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Site_Brand')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function sb_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function sb_rest($m, $r, $b = null) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); return [$res->get_status(), json_decode(wp_json_encode($res->get_data()), true)]; }
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$backup = get_option(TIX_Site_Config::OPTION, null);
$o = is_array($backup) ? $backup : []; unset($o['brand']); update_option(TIX_Site_Config::OPTION, $o, false);
$before = TIX_Site_Config::payload(); unset($before['brand'], $before['overrides']);

// ═══ Lesen ohne gespeicherte Marke ═══
wp_set_current_user(0);
[$st, $d] = sb_rest('GET', '/public/site-config');
$b = $d['brand'] ?? [];
sb_eq('öffentlich 200, brand vorhanden, ready=false', [200, true, false], [$st, is_array($b) && $b !== [], $b['ready'] ?? null]);
sb_eq('alle Felder wie brands.ts', [], array_values(array_diff(['key', 'name', 'short_name', 'in_venue', 'site_host', 'api_base', 'layout', 'single_venue', 'display', 'logo', 'icon', 'placeholder', 'hashtag', 'instagram', 'nav', 'nav_cta', 'contact', 'social', 'about', 'description', 'tickets_path', 'page_slugs', 'wp_paths', 'redirects', 'store', 'no_app', 'colors'], array_keys($b))));
sb_eq('Rückfälle: Name, Profil, Grundwerte', [$d['name'], $d['profile'], '/tickets/', 'manrope', !$multi], [$b['name'], $b['layout'], $b['tickets_path'], $b['display'], $b['single_venue']]);
sb_eq('in_venue aus Profil', ($d['profile'] === 'club' ? 'im ' : 'beim ') . $d['name'], $b['in_venue']);
sb_eq('api_base auf eigenem Host', 'https://' . $b['site_host'] . '/wp-json/tixomat/v1', $b['api_base']);
sb_eq('13 Farben, Akzent als Signal/Knopf', [13, strtoupper($d['colors']['accent'] ?: '#FC2F55'), strtoupper($d['colors']['accent'] ?: '#FC2F55')], [count($b['colors']), $b['colors']['--signal'], $b['colors']['--cta-bg']]);
sb_eq('no_app = ohne App', empty($d['app']['has_app']), $b['no_app']);
sb_eq('bestehende Bereiche unverändert', $before, (function () { $p = TIX_Site_Config::payload(); unset($p['brand'], $p['overrides']); return $p; })());

// ═══ Farben ableiten ═══
$c = TIX_Site_Brand::colors('#FEEA01', []);
sb_eq('heller Akzent → dunkle Schrift auf dem Knopf', '#131020', $c['--cta-fg']);
$c = TIX_Site_Brand::colors('#E11283', ['--bg' => '#14111C', '--spotlight' => '#FEEA01']);
sb_eq('dunkler Akzent → weiße Schrift, eigener Grund und Spotlight', ['#FFFFFF', '#14111C', '#FEEA01'], [$c['--cta-fg'], $c['--bg'], $c['--spotlight']]);
sb_eq('Verlauf aus Grund und Akzent', true, strpos($c['--bg-light'], 'radial-gradient(') === 0 && strpos($c['--bg-light'], '#14111C 100%') !== false);

// ═══ Schreiben ═══
[$st] = sb_rest('POST', '/studio/site-config', ['brand' => ['name' => 'X']]);
sb_eq('ohne Anmeldung 401', 401, $st);
wp_set_current_user(intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));
$in = ['brand' => [
    'key' => 'xxlschlagerparty', 'name' => 'XXL Schlagerparty', 'short_name' => 'XXL', 'in_venue' => 'bei der XXL Schlagerparty',
    'layout' => 'festivalTour', 'display' => 'anton', 'no_app' => true, 'single_venue' => true, 'hashtag' => '#xxl',
    'logo' => 'https://cdn.example.test/logo.png', 'nav' => [['label' => 'Line-up', 'href' => '/line-up/'], ['label' => 'FAQ', 'href' => '/#faq']],
    'nav_cta' => ['label' => 'Tickets', 'href' => '/#tickets'], 'contact' => ['email' => 'mail@xxl.test', 'whatsapp' => '+49 170 123456'],
    'about' => ['eyebrow' => 'Die Party', 'text' => 'Mallorca-Feeling'], 'tickets_path' => '/meine-tickets', 'page_slugs' => ['datenschutz' => 'privacy-policy'],
    'wp_paths' => ['/checkin/', '/promoter/', '/checkin/'], 'redirects' => ['/shop/*' => '/#tickets', '/tc-events/*' => '/events/'],
    'colors' => ['--bg' => '#14111c', '--signal' => '#E11283', '--bg-light' => 'radial-gradient(135% 95% at 50% -12%, #5A1648 0%, #14111C 100%)'],
]];
[$st, $d] = sb_rest('POST', '/studio/site-config', $in);
$b = $d['brand'] ?? [];
sb_eq('speichern 200, ready', [200, true], [$st, $b['ready'] ?? null]);
sb_eq('Texte und Schalter', ['xxlschlagerparty', 'XXL Schlagerparty', 'XXL', 'bei der XXL Schlagerparty', 'festivalTour', 'anton', true, '#xxl'],
    [$b['key'], $b['name'], $b['short_name'], $b['in_venue'], $b['layout'], $b['display'], $b['no_app'], $b['hashtag']]);
sb_eq('Navigation, Knopf, Pfade', [2, '/#faq', ['label' => 'Tickets', 'href' => '/#tickets'], '/meine-tickets/', ['/checkin/', '/promoter/'], ['/shop/*' => '/#tickets', '/tc-events/*' => '/events/'], ['datenschutz' => 'privacy-policy']],
    [count($b['nav']), $b['nav'][1]['href'], $b['nav_cta'], $b['tickets_path'], $b['wp_paths'], $b['redirects'], $b['page_slugs']]);
sb_eq('Kontakt gemischt (WhatsApp nur Ziffern), Über uns mit Rückfall-Titel', ['mail@xxl.test', '49170123456', 'Die Party', 'XXL Schlagerparty'],
    [$b['contact']['email'], $b['contact']['whatsapp'], $b['about']['eyebrow'], $b['about']['title']]);
sb_eq('Farben: gespeicherte gewinnen, Rest abgeleitet', ['#14111C', '#E11283', 13, true], [$b['colors']['--bg'], $b['colors']['--signal'], count($b['colors']), strpos($b['colors']['--bg-light'], '#5A1648') !== false]);
sb_eq('Logo-URL, Icon fällt aufs Logo zurück, wenn kein Site-Icon', 'https://cdn.example.test/logo.png', $b['logo']);
sb_eq('Profil oben bleibt unabhängig', $before['profile'], $d['profile']);
sb_eq('overrides nennt brand', true, in_array('brand', $d['overrides'], true));

// Teil-Update und Felder zurücksetzen
[$st, $d] = sb_rest('POST', '/studio/site-config', ['brand' => ['short_name' => null, 'colors' => ['--signal' => null], 'contact' => ['whatsapp' => '']]]);
$b = $d['brand'];
sb_eq('Teil-Update: Rest bleibt, null = Rückfall', [200, 'XXL Schlagerparty', 'XXL Schlagerparty', '#14111C', null, 'mail@xxl.test'],
    [$st, $b['name'], $b['short_name'], $b['colors']['--bg'], $b['contact']['whatsapp'] ?? null, $b['contact']['email']]);

// Fehler
foreach ([
    'Unbekanntes Feld' => [['foo' => 1], 'brand.foo'],
    'Profil' => [['layout' => 'theater'], 'brand.layout'],
    'Farbe' => [['colors' => ['--bg' => 'red']], 'brand.colors.--bg'],
    'Verlauf mit url()' => [['colors' => ['--bg-light' => 'url(https://x.test/a.png)']], 'brand.colors.--bg-light'],
    'Pfad' => [['wp_paths' => ['checkin']], 'brand.wp_paths[0]'],
    'Nav-Ziel' => [['nav' => [['label' => 'A', 'href' => 'javascript:alert(1)']]], 'brand.nav[0]'],
    'E-Mail' => [['contact' => ['email' => 'kein']], 'brand.contact.email'],
    'Name leer' => [['name' => ' '], 'brand.name'],
] as $l => [$body, $field]) {
    [$st, $d] = sb_rest('POST', '/studio/site-config', ['brand' => $body]);
    sb_eq("400 $l", [400, $field], [$st, $d['data']['field'] ?? null]);
}
sb_eq('nach Fehlern unverändert', 'XXL Schlagerparty', TIX_Site_Config::payload()['brand']['name']);

// ganz zurück
[$st, $d] = sb_rest('POST', '/studio/site-config', ['brand' => null]);
sb_eq('brand: null → ready=false, Name wieder berechnet', [200, false, $before['name']], [$st, $d['brand']['ready'], $d['brand']['name']]);

// leeres brand {} = nur „eingerichtet“
[$st, $d] = sb_rest('POST', '/studio/site-config', ['brand' => ['ready' => true]]);
sb_eq('brand {ready:true} markiert eingerichtet', [200, true], [$st, $d['brand']['ready']]);

// Aufräumen
if ($backup === null) delete_option(TIX_Site_Config::OPTION); else update_option(TIX_Site_Config::OPTION, $backup, false);
echo ($GLOBALS['t_fails'] ? 'ROT ' : 'GRÜN ') . $GLOBALS['t_count'] . ' Prüfungen, ' . $GLOBALS['t_fails'] . " Fehler\n";
