<?php
/** Vorlagen „openair“ und „welle“ (1.38.789, Homepages-Chat). wp eval-file …/test-website-openair-welle-wp.php (Einzel-Seite: auch Speichern + site-config) */
if (!defined('ABSPATH') || !class_exists('TIX_Website')) { echo "TIX_Website nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = rest_get_server()->response_to_data($res, false); return [$res->get_status(), is_array($d) ? $d : []]; };
$want = [
    'openair' => ['stage', 'about', 'lineup', 'tickets', 'raffles', 'tips', 'arrival', 'more_dates', 'gallery', 'faq', 'newsletter', 'app_banner'],
    'welle'   => ['stage', 'lineup', 'tickets', 'more_dates', 'raffles', 'tips', 'about', 'arrival', 'gallery', 'faq', 'newsletter', 'app_banner'],
];
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
$pkg = get_option(TIX_Website::OPT_ENABLED, null);
try {
    [$st, $d] = $rest('GET', '/studio/website');
    $cat = $d['catalog'] ?? [];
    if ($st !== 200) { $m = new ReflectionMethod('TIX_Website', 'catalog'); $m->setAccessible(true); $cat = $m->invoke(null); echo "      (Route hier nicht freigeschaltet – Katalog direkt geprüft)\n"; }
    $eq('catalog.templates mit openair + welle, alte bleiben', [true, true, true], [in_array('openair', (array) ($cat['templates'] ?? []), true), in_array('welle', (array) ($cat['templates'] ?? []), true), in_array('einfach', (array) ($cat['templates'] ?? []), true)]);
    foreach ($want as $t => $list) {
        $def = [];
        foreach ((array) ($cat['sections'] ?? []) as $sec) if (!empty($sec['defaults'][$t])) $def[] = (string) ($sec['key'] ?? ($sec['id'] ?? ''));
        $exp = $list; sort($exp); sort($def);
        $eq("Vorgaben $t genau wie bestellt", $exp, $def);
        $eq("Reihenfolge $t = Startseite", $list, TIX_Website::DEFAULTS[$t]);
    }
    $eq('Prüfung nimmt openair/welle an, Unbekanntes nicht', [true, true, true], [!is_wp_error(TIX_Website::clean(['template' => 'openair'])), !is_wp_error(TIX_Website::clean(['template' => 'welle'])), is_wp_error(TIX_Website::clean(['template' => 'gibtsnicht']))]);
    $e = TIX_Website::clean(['template' => 'gibtsnicht']);
    $eq('Fehlertext nennt die neuen Vorlagen', true, is_wp_error($e) && strpos($e->get_error_message(), 'openair') !== false && strpos($e->get_error_message(), 'welle') !== false);
    $eq('Vertriebs-Vorschau kennt beide', [true, true], [in_array('openair', TIX_Sales_Previews::TEMPLATES, true), in_array('welle', TIX_Sales_Previews::TEMPLATES, true)]);
    if ($st === 200) {
        foreach (['openair', 'welle'] as $t) {
            [, $cur] = $rest('GET', '/studio/website');
            [$st2] = $rest('POST', '/studio/website', ['updated_at' => (string) $cur['updated_at'], 'website' => ['template' => $t] + (array) ($cur['draft'] ?? []), 'publish' => true]);
            [, $sc] = $rest('GET', '/public/site-config');
            $eq("POST template $t: 200, site-config liefert $t", [200, $t], [$st2, $sc['website']['template'] ?? '']);
        }
    }
} finally {
    if (isset($d['updated_at'])) { [, $now] = $rest('GET', '/studio/website'); $rest('POST', '/studio/website', ['updated_at' => (string) ($now['updated_at'] ?? ''), 'website' => (array) ($d['website'] ?: ['template' => '']), 'publish' => true]); }
    if ($pkg === null) delete_option(TIX_Website::OPT_ENABLED); else update_option(TIX_Website::OPT_ENABLED, $pkg, false);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
