<?php
/**
 * Geteilte Events: Inhalte nur beim Ersteller ändern (1.38.787, Fund evendis-Spiegel #6453: „Zeiten öffentlich“ nur lokal aus).
 *   wp eval-file …/test-source-lock-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Source_Lock')) { echo "Tixomat ab 1.38.787 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); return [$res->get_status(), $res->get_data()]; };
$p = [];
try {
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SL Spiegel']); $p[] = $mir;
    update_post_meta($mir, '_tix_syndicated', '1'); update_post_meta($mir, '_tix_source_site', 'KitchenKlub'); update_post_meta($mir, '_tix_timetable_hidden', '0');
    $own = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SL Eigen']); $p[] = $own;
    [$st, $d] = $rest('POST', "/events/$mir", ['timetable' => ['hidden' => true]]);
    $eq('Spiegel: Programm/Zeiten ändern → 409 tix_source_owned mit Seite, nichts geändert', [409, 'tix_source_owned', 'KitchenKlub', '0'], [$st, $d['code'] ?? null, $d['data']['source_site'] ?? null, get_post_meta($mir, '_tix_timetable_hidden', true)]);
    [$st, $d] = $rest('POST', "/events/$mir", ['lineup_order' => ['A', 'B']]);
    $eq('Spiegel: Line-up-Reihenfolge → 409', 409, $st);
    $eq('Spiegel: Bild → 409', 409, $rest('POST', "/events/$mir/image", [])[0]);
    [$st] = $rest('POST', "/events/$mir", ['event_category' => null]);
    $eq('Spiegel: Sparte (plattform-eigen) bleibt erlaubt', true, $st !== 409);
    $eq('Spiegel: Verlauf meldet weiter tix_history_copy', true, in_array($rest('POST', "/events/$mir/history/1/restore", [])[1]['code'] ?? '', ['tix_history_copy', 'tix_not_found'], true));
    [$st, $d] = $rest('GET', "/events/$mir/edit");
    $ed = $d['event'] ?? $d;
    $eq('Editor: source_owned mit Hinweis', ['KitchenKlub', true], [$ed['source_owned']['site'] ?? null, strpos((string) ($ed['source_owned']['reason'] ?? ''), 'KitchenKlub') !== false]);
    [$st] = $rest('POST', "/events/$own", ['timetable' => ['hidden' => true]]);
    $eq('eigenes Event: wie bisher änderbar', [200, '1'], [$st, get_post_meta($own, '_tix_timetable_hidden', true)]);
    $ed = $rest('GET', "/events/$own/edit")[1]; $ed = $ed['event'] ?? $ed;
    $eq('eigenes Event: source_owned null', null, array_key_exists('source_owned', (array) $ed) ? $ed['source_owned'] : 'fehlt');
    $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'SL Kopie']); $p[] = $cp;
    update_post_meta($cp, '_tix_platform_co', '6453'); update_post_meta($cp, '_tix_platform_co_source', 'kitchenklub.de');
    $eq('Kopie auf Kunden-Seite: ebenfalls gesperrt', [409, 'kitchenklub.de'], (function () use ($rest, $cp) { [$s, $d] = $rest('POST', "/events/$cp", ['title' => 'X']); return [$s, $d['data']['source_site'] ?? null]; })());
    $eq('Spiegel vom Plattform-Angebot nehmen (DELETE) bleibt möglich', true, $rest('DELETE', "/events/$mir")[0] !== 409);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($p as $id) wp_delete_post($id, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
