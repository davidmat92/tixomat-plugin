<?php
/**
 * timetable.mode (1.38.782): Studio-Auswahl times|tba|none wird unverändert gespeichert und in GET /events/{id}/edit zurückgegeben
 * (sonst erschien „Keine Zeiten“ nach dem Neuladen als „Mit Zeiten, nicht öffentlich“).   wp eval-file …/test-timetable-mode-wp.php
 */
if (!defined('ABSPATH') || !defined('TIX_Studio_Editor::TT_MODES')) { echo "Tixomat ab 1.38.782 nötig\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); return [$res->get_status(), $res->get_data()]; };
$tt = function ($ev) use ($rest) { [$st, $d] = $rest('GET', "/events/$ev/edit"); return [$st, ($d['event'] ?? $d)['timetable'] ?? null]; };
$ev = 0;
try {
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Modus Test']);
    update_post_meta($ev, '_tix_date_start', '2027-03-01');
    [$st, $t] = $tt($ev);
    $eq('ohne Angabe und ohne Programm: mode none (1.38.783)', [200, 'none'], [$st, $t['mode'] ?? null]);
    update_post_meta($ev, '_tix_timetable', ['2027-03-01' => [['time' => '', 'end' => '', 'stage' => 0, 'title' => 'Honk', 'desc' => '']]]);
    $eq('Programm nur ohne Uhrzeiten: none', 'none', $tt($ev)[1]['mode'] ?? null);
    update_post_meta($ev, '_tix_timetable', ['2027-03-01' => [['time' => '', 'stage' => 0, 'title' => 'Honk'], ['time' => '22:00', 'stage' => 0, 'title' => 'Monchi']]]);
    $eq('mit Uhrzeit: times', 'times', $tt($ev)[1]['mode'] ?? null);
    update_post_meta($ev, '_tix_timetable_times_tba', '1');
    $eq('alt: times_tba → mode tba', 'tba', $tt($ev)[1]['mode'] ?? null);
    delete_post_meta($ev, '_tix_timetable'); delete_post_meta($ev, '_tix_timetable_times_tba');
    $days = ['2027-03-01' => [['time' => '', 'end' => '', 'stage' => 0, 'title' => 'Honk', 'desc' => '']]];
    [$st] = $rest('POST', "/events/$ev", ['timetable' => ['mode' => 'none', 'hidden' => true, 'times_tba' => false, 'days' => $days]]);
    [, $t] = $tt($ev);
    $eq('„Keine Zeiten“ gespeichert und unverändert zurück (nicht als „Mit Zeiten, nicht öffentlich“)', [200, 'none', true, false], [$st, $t['mode'] ?? null, $t['hidden'] ?? null, $t['times_tba'] ?? null]);
    [$st, $d] = $rest('POST', "/events/$ev", ['timetable' => ['mode' => 'irgendwie']]);
    $eq('ungültig: 400 timetable.mode', [400, 'timetable.mode'], [$st, $d['data']['field'] ?? null]);
    $eq('nach Fehler unverändert', 'none', $tt($ev)[1]['mode'] ?? null);
    [$st] = $rest('POST', "/events/$ev", ['timetable' => ['mode' => 'tba', 'times_tba' => true]]);
    $eq('Wechsel auf tba', [200, 'tba'], [$st, $tt($ev)[1]['mode'] ?? null]);
    $eq('Formular für neue Events: mode none', 'none', (($rest('GET', '/events/form')[1]['event'] ?? $rest('GET', '/events/form')[1])['timetable']['mode'] ?? null));
    [$st] = $rest('POST', "/events/$ev", ['timetable' => null]);
    $eq('timetable null löscht auch mode', [200, false], [$st, metadata_exists('post', $ev, '_tix_timetable_mode')]);
    $pl = TIX_Site_Move::program_payload($ev);
    update_post_meta($ev, '_tix_timetable_mode', 'none');
    $cp = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Modus Kopie']);
    TIX_Site_Move::apply_program($cp, TIX_Site_Move::program_payload($ev));
    $eq('Co-Kopie übernimmt mode', 'none', get_post_meta($cp, '_tix_timetable_mode', true));
    wp_delete_post($cp, true);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    if ($ev) wp_delete_post($ev, true);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
