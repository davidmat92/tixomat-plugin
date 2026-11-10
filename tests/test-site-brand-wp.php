<?php
/** Homepage zur Laufzeit (1.38.673): site-config.homepage. wp eval-file …/tests/test-site-brand-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Site_Brand')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function sb_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function sb_rest($m, $r, $b = null, $raw = false) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($raw || $b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); return [$res->get_status(), json_decode(wp_json_encode($res->get_data()), true)]; }
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$backup = get_option(TIX_Site_Config::OPTION, null);
$had_app = get_option('tix_studio_has_app', null);
$o = is_array($backup) ? $backup : []; unset($o['homepage']); update_option(TIX_Site_Config::OPTION, $o, false);
$strip = function ($p) { unset($p['homepage'], $p['overrides']); return $p; };
$before = $strip(TIX_Site_Config::payload());

// ═══ Ohne Speicherung ═══
wp_set_current_user(0);
[$st, $d] = sb_rest('GET', '/public/site-config');
sb_eq('öffentlich 200, homepage = null (KitchenKlub/Mallorca bleiben bei brands.ts)', [200, true, null], [$st, array_key_exists('homepage', $d), $d['homepage']]);
sb_eq('bestehende Bereiche unverändert', $before, $strip($d));

// ═══ Schreiben ═══
[$st] = sb_rest('POST', '/studio/site-config', ['homepage' => ['key' => 'x']]);
sb_eq('ohne Anmeldung 401', 401, $st);
wp_set_current_user(intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));
[$st, $d] = sb_rest('POST', '/studio/site-config', ['homepage' => ['key' => 'minimal']]);
$h = $d['homepage'] ?? [];
sb_eq('nur key → enabled, Rückfälle', [200, true, 'minimal', $d['profile'], 'manrope', '/tickets/', !$multi, ($d['profile'] === 'club' ? 'im ' : 'beim ') . $d['name'], null, (string) ($d['seo']['description'] ?? '')],
    [$st, $h['enabled'], $h['key'], $h['layout'], $h['display_font'], $h['tickets_path'], $h['single_venue'], $h['in_venue'], $h['short_name'], $h['description']]);
sb_eq('alle Felder nach Absprache vorhanden', [], array_values(array_diff(['enabled', 'key', 'short_name', 'in_venue', 'layout', 'display_font', 'single_venue', 'hashtag', 'tickets_path', 'wp_paths', 'redirects', 'about', 'description', 'colors'], array_keys($h))));
sb_eq('leere Sammlungen als Objekt/Liste', [[], [], []], [$h['wp_paths'], $h['redirects'], $h['colors']]);

$in = ['homepage' => [
    'key' => 'xxlschlagerparty', 'short_name' => 'XXL', 'in_venue' => 'bei der XXL Schlagerparty',
    'layout' => 'festivalTour', 'display_font' => 'anton', 'single_venue' => true, 'hashtag' => '#xxl',
    'tickets_path' => '/meine-tickets', 'wp_paths' => ['/checkin/', '/promoter/', '/checkin/'], 'redirects' => ['/shop/*' => '/#tickets', '/tc-events/*' => '/events/'],
    'about' => ['eyebrow' => 'Die Party', 'text' => 'Mallorca-Feeling'], 'description' => 'Die Party.',
    'colors' => ['background' => '#14111c', 'surface' => '#221D2C', 'cta_bg' => '#E11283', 'cta_fg' => '#ffffff', 'glow' => 'radial-gradient(135% 95% at 50% -12%, #5A1648 0%, #14111C 100%)'],
    'page_slugs' => ['datenschutz' => 'privacy-policy'], 'nav_cta' => ['label' => 'Tickets', 'href' => '/#tickets'],
]];
[$st, $d] = sb_rest('POST', '/studio/site-config', $in);
$h = $d['homepage'] ?? [];
sb_eq('Texte und Schalter', [200, 'xxlschlagerparty', 'XXL', 'bei der XXL Schlagerparty', 'festivalTour', 'anton', true, '#xxl', 'Die Party.'],
    [$st, $h['key'], $h['short_name'], $h['in_venue'], $h['layout'], $h['display_font'], $h['single_venue'], $h['hashtag'], $h['description']]);
sb_eq('Pfade, Weiterleitungen, Zusätze', ['/meine-tickets/', ['/checkin/', '/promoter/'], ['/shop/*' => '/#tickets', '/tc-events/*' => '/events/'], ['datenschutz' => 'privacy-policy'], ['label' => 'Tickets', 'href' => '/#tickets']],
    [$h['tickets_path'], $h['wp_paths'], $h['redirects'], $h['page_slugs'], $h['nav_cta']]);
sb_eq('Über uns gemischt mit leeren Vorgaben', ['eyebrow' => 'Die Party', 'title' => '', 'text' => 'Mallorca-Feeling'], $h['about']);
sb_eq('Farben in homepage', ['#14111C', '#221D2C', '#E11283', '#FFFFFF'], [$h['colors']['background'], $h['colors']['surface'], $h['colors']['cta_bg'], $h['colors']['cta_fg']]);
sb_eq('background/glow auch oben in colors, Akzent bleibt', ['#14111C', true, $before['colors']['accent']], [$d['colors']['background'], strpos((string) $d['colors']['glow'], '#5A1648') !== false, $d['colors']['accent']]);
sb_eq('Profil oben bleibt unabhängig', $before['profile'], $d['profile']);
sb_eq('overrides nennt homepage', true, in_array('homepage', $d['overrides'], true));
wp_set_current_user(0);
[$st, $pd] = sb_rest('GET', '/public/site-config');
sb_eq('öffentlich sichtbar', 'xxlschlagerparty', $pd['homepage']['key'] ?? null);
wp_set_current_user(intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1));

// Teil-Update und Felder entfernen
[$st, $d] = sb_rest('POST', '/studio/site-config', ['homepage' => ['short_name' => null, 'colors' => ['cta_fg' => null], 'enabled' => false]]);
$h = $d['homepage'];
sb_eq('Teil-Update: Rest bleibt, null entfernt, enabled aus', [200, null, 'XXL Schlagerparty' === '' ? '' : 'bei der XXL Schlagerparty', false, false, '#E11283'],
    [$st, $h['short_name'], $h['in_venue'], $h['enabled'], isset($h['colors']['cta_fg']), $h['colors']['cta_bg']]);

// Fehler
foreach ([
    'Unbekanntes Feld' => [['logo' => 'x'], 'homepage.logo'],
    'Kurzname' => [['key' => 'XXL Party!'], 'homepage.key'],
    'Profil' => [['layout' => 'zirkus'], 'homepage.layout'],
    'Schrift' => [['display_font' => 'comic'], 'homepage.display_font'],
    'Farbe' => [['colors' => ['surface' => 'red']], 'homepage.colors.surface'],
    'Farbname' => [['colors' => ['--bg' => '#000000']], 'homepage.colors.--bg'],
    'Verlauf mit url()' => [['colors' => ['glow' => 'url(https://x.test/a.png)']], 'homepage.colors.glow'],
    'Pfad' => [['wp_paths' => ['checkin']], 'homepage.wp_paths[0]'],
    'Weiterleitung' => [['redirects' => ['/a/' => 'javascript:alert(1)']], 'homepage.redirects./a/'],
] as $l => [$body, $field]) {
    [$st, $d] = sb_rest('POST', '/studio/site-config', ['homepage' => $body]);
    sb_eq("400 $l", [400, $field], [$st, $d['data']['field'] ?? null]);
}
sb_eq('nach Fehlern unverändert', 'xxlschlagerparty', TIX_Site_Config::payload()['homepage']['key']);

// ganz zurück
[$st, $d] = sb_rest('POST', '/studio/site-config', ['homepage' => null], true);
sb_eq('homepage: null → null, oben wieder wie vorher', [200, null, $before['colors']], [$st, $d['homepage'], $d['colors']]);

// WP-CLI-Weg ohne CLI: clean + has_app
$r = TIX_Site_Brand::clean(['layout' => 'festival'], []);
sb_eq('clean liefert Stand ohne Fehler', ['layout' => 'festival'], $r);

// Aufräumen
if ($backup === null) delete_option(TIX_Site_Config::OPTION); else update_option(TIX_Site_Config::OPTION, $backup, false);
if ($had_app === null) delete_option('tix_studio_has_app'); else update_option('tix_studio_has_app', $had_app, false);
echo ($GLOBALS['t_fails'] ? 'ROT ' : 'GRÜN ') . $GLOBALS['t_count'] . ' Prüfungen, ' . $GLOBALS['t_fails'] . " Fehler\n";
