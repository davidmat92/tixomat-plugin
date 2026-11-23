<?php
/** Startseiten-Abschnitt lineup: Option photos (1.38.913, Auftrag „Plakat mit Fotos“). wp eval-file …/test-website-lineup-photos-wp.php */
if (!defined('ABSPATH') || !class_exists('TIX_Website')) { echo "TIX_Website nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$W = 'TIX_Website';
$m = new ReflectionMethod($W, 'catalog'); $m->setAccessible(true); $cat = $m->invoke(null);
$sec = array_values(array_filter((array) $cat['sections'], function ($s) { return ($s['key'] ?? '') === 'lineup'; }))[0] ?? [];
$eq('Katalog: lineup kennt max, title, photos', ['max', 'title', 'photos'], $sec['options'] ?? null);
$lu = function ($w) { foreach ($w['sections'] as $s) if ($s['key'] === 'lineup') return $s; return null; };
$c = $W::clean(['template' => 'plakat', 'sections' => [['key' => 'lineup', 'enabled' => true, 'photos' => true, 'max' => 12]]]);
$eq('Speichern: photos true bleibt true (bool), max bleibt', [true, 12], [$lu($c)['photos'] ?? null, $lu($c)['max'] ?? null]);
$eq('Speichern: "1" → true, false → false, "false" → false', [true, false, false],
    [$lu($W::clean(['sections' => [['key' => 'lineup', 'photos' => '1']]]))['photos'] ?? null, $lu($W::clean(['sections' => [['key' => 'lineup', 'photos' => false]]]))['photos'] ?? null, $lu($W::clean(['sections' => [['key' => 'lineup', 'photos' => 'false']]]))['photos'] ?? null]);
$e = $W::clean(['sections' => [['key' => 'lineup', 'photos' => 'ja']]]);
$eq('Ungültig → 400 sections.0.photos', ['tix_invalid_field', 'sections.0.photos'], [is_wp_error($e) ? $e->get_error_code() : '', is_wp_error($e) ? ($e->get_error_data()['field'] ?? '') : '']);
$e2 = $W::clean(['sections' => [['key' => 'next', 'photos' => true]]]);
$eq('photos an anderem Abschnitt wird ignoriert (keine Option dort)', false, is_wp_error($e2) ? true : array_key_exists('photos', $W::clean(['sections' => [['key' => 'next', 'photos' => true]]])['sections'][0]));
$eq('Ausgabe an marken-web: lineup.photos Vorgabe false, gespeichert true', [false, true], [$lu($W::expand(['template' => 'plakat', 'sections' => []]))['photos'] ?? null, $lu($W::expand($c))['photos'] ?? null]);
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
