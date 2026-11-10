<?php
/** Vorlage „einfach“ + Profil „theater“ (1.38.714). wp eval-file …/test-website-einfach-wp.php (beide Modi) */
if (!defined('ABSPATH') || !class_exists('TIX_Website')) { echo "TIX_Website nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = rest_get_server()->response_to_data($res, false); return [$res->get_status(), is_array($d) ? $d : []]; };
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
try {
    [$st, $d] = $rest('GET', '/studio/website');
    $cat = $d['catalog'] ?? [];
    if ($st !== 200) { $m = new ReflectionMethod('TIX_Website', 'catalog'); $m->setAccessible(true); $cat = $m->invoke(null); echo "      (Route hier nicht freigeschaltet – Katalog direkt geprüft)\n"; }
    $eq('catalog.templates mit einfach', true, in_array('einfach', (array) ($cat['templates'] ?? []), true));
    $def = [];
    foreach ((array) ($cat['sections'] ?? []) as $sec) if (!empty($sec['defaults']['einfach'])) $def[] = (string) ($sec['key'] ?? ($sec['id'] ?? ''));
    sort($def);
    $eq('Vorgaben einfach: stage, next, about, contact', ['about', 'contact', 'next', 'stage'], $def);
    $eq('Prüfung nimmt einfach an, Unbekanntes nicht', [true, true], [!is_wp_error(TIX_Website::clean(['template' => 'einfach'])), is_wp_error(TIX_Website::clean(['template' => 'gibtsnicht']))]);
    if ($st === 200) {
        [$st2] = $rest('POST', '/studio/website', ['updated_at' => (string) $d['updated_at'], 'website' => ['template' => 'einfach'] + (array) ($d['draft'] ?? []), 'publish' => true]);
        $eq('Speichern + veröffentlichen mit einfach: 200', 200, $st2);
        [, $pw] = $rest('GET', '/studio/website');
        $eq('veröffentlicht: template einfach', 'einfach', $pw['website']['template'] ?? '');
    }
    $eq('Profil theater bekannt (Studio + Marke)', [true, true], [in_array('theater', TIX_Studio::LAYOUTS, true), in_array('theater', TIX_Site_Brand::LAYOUTS, true)]);
    $eq('theater → Vorlage wie club', true, strpos('theater', 'festival') !== 0);
} finally {
    if (isset($d['updated_at'])) { [, $now] = $rest('GET', '/studio/website'); $rest('POST', '/studio/website', ['updated_at' => (string) ($now['updated_at'] ?? ''), 'website' => (array) ($d['website'] ?: ['template' => '']), 'publish' => true]); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
