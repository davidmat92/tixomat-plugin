<?php
/**
 * Programm auf Co-Kopien der Kunden-Seiten (1.38.778, Fund KMT #7: `timetable: null`): Plattform liefert `program` im Abgleich,
 * die Kopie übernimmt Programm, Bühnen, ausgeblendet/Zeiten folgen; `timetable` und Zeiten in `lineup_acts` wie die Quelle.
 *   wp eval-file …/test-co-program-wp.php
 */
if (!defined('ABSPATH') || !method_exists('TIX_Site_Move', 'apply_program')) { echo "Tixomat ab 1.38.778 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$p = [];
try {
    $src = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Programm Quelle']); $p[] = $src;
    update_post_meta($src, '_tix_date_start', '2027-02-27');
    update_post_meta($src, '_tix_timetable', ['2027-02-27' => [['time' => '18:00', 'end' => '19:00', 'stage' => 0, 'title' => 'Honk', 'desc' => 'Live'], ['time' => '23:30', 'end' => '00:30', 'stage' => 1, 'title' => 'Der Zipfelbube', 'desc' => '']]]);
    update_post_meta($src, '_tix_stages', [['name' => 'Hauptbühne', 'color' => '#ff5500'], ['name' => 'Zelt', 'color' => '#00aaff']]);
    update_post_meta($src, '_tix_timetable_times_tba', '0');
    $pl = TIX_Site_Move::program_payload($src);
    $eq('Payload: Tage, Bühnen, Schalter', [1, 2, false, false], [count($pl['timetable']), count($pl['stages']), $pl['hidden'], $pl['times_tba']]);
    $pl = json_decode(wp_json_encode($pl), true); // wie über HTTP
    $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Programm Kopie']); $p[] = $cp;
    update_post_meta($cp, '_tix_date_start', '2027-02-27');
    TIX_Site_Move::apply_program($cp, $pl);
    $a = TIX_Event_Extras::timetable($src); $b = TIX_Event_Extras::timetable($cp);
    $eq('Kopie: timetable gleich der Quelle (Tage, Slots, Bühnen, über Mitternacht)', wp_json_encode($a), wp_json_encode($b));
    $eq('Kopie: nicht null', true, is_array($b) && count($b['days'][0]['slots']) === 2);
    TIX_Site_Move::apply_program($cp, ['timetable' => $pl['timetable'], 'stages' => $pl['stages'], 'hidden' => true, 'times_tba' => true]);
    $eq('ausgeblendet an der Quelle → Kopie ausgeblendet (timetable null)', [null, '1'], [TIX_Event_Extras::timetable($cp), get_post_meta($cp, '_tix_timetable_times_tba', true)]);
    TIX_Site_Move::apply_program($cp, ['timetable' => [], 'stages' => [], 'hidden' => false, 'times_tba' => false]);
    $eq('Programm an der Quelle gelöscht → Kopie ohne Programm', [false, false], [metadata_exists('post', $cp, '_tix_timetable'), metadata_exists('post', $cp, '_tix_stages')]);
    TIX_Site_Move::apply_program($cp, ['timetable' => ['kaputt' => [['title' => '<script>x</script>Y']], '2027-02-27' => [['time' => '20:00', 'title' => '<b>Z</b>']]], 'stages' => [['name' => 'A', 'color' => 'rot']]]);
    $tt = get_post_meta($cp, '_tix_timetable', true);
    $eq('Säubern: ungültiges Datum weg, Titel ohne HTML, ungültige Farbe leer', [['2027-02-27'], 'Z', ''], [array_keys($tt), $tt['2027-02-27'][0]['title'], get_post_meta($cp, '_tix_stages', true)[0]['color']]);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($p as $id) wp_delete_post($id, true);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
