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

    // „Programm anzeigen“ aus = Zeiten nirgends öffentlich (1.38.781): eigene Seite und Kopie (Schnappschuss mit Zeiten)
    $leak = function ($id) { TIX_Public_Events::flush(); wp_cache_flush(); $j = wp_json_encode(rest_do_request(new WP_REST_Request('GET', '/tixomat/v1/public/events/' . $id))->get_data()); return substr_count($j, '21:17') + substr_count($j, '22:43'); };
    $own = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Zeiten Eigen']); $p[] = $own;
    update_post_meta($own, '_tix_date_start', '2027-03-01'); update_post_meta($own, '_tix_info_lineup', '<p>Honk</p>');
    update_post_meta($own, '_tix_timetable', ['2027-03-01' => [['time' => '21:17', 'end' => '22:43', 'stage' => 0, 'title' => 'Honk', 'desc' => '']]]);
    $cpy = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Zeiten Kopie']); $p[] = $cpy;
    update_post_meta($cpy, '_tix_date_start', '2027-03-01'); update_post_meta($cpy, '_tix_syndicated', '1');
    update_post_meta($cpy, '_tix_lineup_acts', [['position' => 1, 'name' => 'Honk', 'line' => 'Honk', 'time' => '21:17', 'end' => '22:43', 'start_at' => '2027-03-01T21:17:00+01:00', 'end_at' => '2027-03-01T22:43:00+01:00', 'stage_name' => 'Programm', 'role' => 'headliner']]);
    TIX_Site_Move::apply_program($cpy, ['timetable' => ['2027-03-01' => [['time' => '21:17', 'end' => '22:43', 'stage' => 0, 'title' => 'Honk']]], 'stages' => [], 'hidden' => false, 'times_tba' => false]);
    $eq('Programm sichtbar: Zeiten öffentlich (eigene Seite, Kopie)', [true, true], [$leak($own) > 0, $leak($cpy) > 0]);
    update_post_meta($own, '_tix_timetable_hidden', '1');
    TIX_Site_Move::apply_program($cpy, ['timetable' => ['2027-03-01' => [['time' => '21:17', 'end' => '22:43', 'stage' => 0, 'title' => 'Honk']]], 'stages' => [], 'hidden' => true, 'times_tba' => false]);
    $eq('Programm aus: keine Zeit in der ganzen öffentlichen Antwort (timetable, lineup_acts time/start_at/end, eigene Seite und Kopie)', [0, 0], [$leak($own), $leak($cpy)]);
    $acts = TIX_Lineup_Links::acts($cpy);
    $eq('Kopie: Line-up bleibt (ohne Zeiten)', ['Honk', null, null], [$acts[0]['name'] ?? null, array_key_exists('time', $acts[0]) ? $acts[0]['time'] : 'x', array_key_exists('start_at', $acts[0]) ? $acts[0]['start_at'] : 'x']);

    // 1.38.786: Kopie mit altem Schnappschuss in Studio-Reihenfolge → öffentlich Headliner zuerst; „Nach Auftrittszeit“ unverändert
    $hk = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Headliner Kopie']); $p[] = $hk;
    update_post_meta($hk, '_tix_syndicated', '1');
    update_post_meta($hk, '_tix_lineup_acts', [['position' => 1, 'name' => 'Monchi', 'role' => 'support', 'headliner' => false], ['position' => 2, 'name' => 'Honk', 'role' => 'headliner', 'headliner' => true], ['position' => 3, 'name' => 'Zipfelbube', 'role' => 'headliner', 'headliner' => true]]);
    $eq('Kopie: Headliner zuerst (untereinander wie die Quelle), Positionen neu', [['Honk', 'Zipfelbube', 'Monchi'], [1, 2, 3]], [array_column(TIX_Lineup_Links::acts($hk), 'name'), array_column(TIX_Lineup_Links::acts($hk), 'position')]);
    TIX_Site_Move::apply_program($hk, ['timetable' => [], 'stages' => [], 'hidden' => false, 'times_tba' => false, 'lineup_sort' => 'time']);
    $eq('Kopie mit „Nach Auftrittszeit“ der Quelle: Reihenfolge des Schnappschusses bleibt', ['Monchi', 'Honk', 'Zipfelbube'], array_column(TIX_Lineup_Links::acts($hk), 'name'));
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($p as $id) wp_delete_post($id, true);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
