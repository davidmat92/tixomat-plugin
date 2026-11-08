<?php
/**
 * Integrationstest Programm (1.38.694, Rückmeldung Veranstalter Jan): Programm ausblenden, Uhrzeiten über
 * Mitternacht (start_at/end_at/next_day, Sortierung), Website-Ausgabe, mehrzeilige Ticket-Beschreibung.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-timetable-midnight-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Event_Extras')) { echo "WordPress mit Tixomat nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function tm_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function tm_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = $res->get_data(); $d = is_object($d) ? (array) $d : $d;
    return [$res->get_status(), $d];
}
$titles = function ($slots) { return array_map(function ($s) { return $s['title']; }, $slots); };
$off = function ($date, $hm) { return (new DateTime("$date $hm:00", wp_timezone()))->format('c'); };

// Jans Programm (Mallorca - Party Schwelm 2027, kitchenklub.de 1171 / evendis.de 6453): Almklausi 00:00 stand oben
$jan = [
    ['time' => '00:00', 'end' => '01:00', 'stage' => 0, 'title' => 'Almklausi', 'desc' => ''],
    ['time' => '12:30', 'end' => '03:00', 'stage' => 0, 'title' => 'Andreas Köhler', 'desc' => ''],
    ['time' => '15:00', 'end' => '15:30', 'stage' => 0, 'title' => 'Monchi', 'desc' => ''],
    ['time' => '22:00', 'end' => '23:00', 'stage' => 0, 'title' => 'Der Zipfelbube', 'desc' => ''],
    ['time' => '23:00', 'end' => '00:00', 'stage' => 0, 'title' => 'Ina Colada', 'desc' => ''],
];
$p = TIX_Event_Extras::place_slots('2027-02-27', $jan);
tm_eq('Jan: 00:00 nach 23:00', ['Andreas Köhler', 'Monchi', 'Der Zipfelbube', 'Ina Colada', 'Almklausi'], $titles($p));
tm_eq('Jan: Almklausi Folgetag', [true, $off('2027-02-28', '00:00'), $off('2027-02-28', '01:00')], [$p[4]['next_day'], $p[4]['start_at'], $p[4]['end_at']]);
tm_eq('Jan: 12:30–03:00 endet am Folgetag', [false, $off('2027-02-27', '12:30'), $off('2027-02-28', '03:00')], [$p[0]['next_day'], $p[0]['start_at'], $p[0]['end_at']]);
tm_eq('Jan: 23:00–00:00 endet um Mitternacht', $off('2027-02-28', '00:00'), $p[3]['end_at']);

$p = TIX_Event_Extras::place_slots('2026-12-05', [['time' => '01:00', 'title' => 'C'], ['time' => '22:00', 'title' => 'A'], ['time' => '02:00', 'title' => 'D'], ['time' => '23:00', 'title' => 'B']]);
tm_eq('Clubnacht 22–02', [['A', 'B', 'C', 'D'], [false, false, true, true]], [$titles($p), array_column($p, 'next_day')]);
$p = TIX_Event_Extras::place_slots('2026-12-05', [['time' => '19:00', 'title' => 'D'], ['time' => '10:00', 'title' => 'A'], ['time' => '18:00', 'title' => 'C'], ['time' => '11:00', 'title' => 'B']]);
tm_eq('Tagesprogramm bleibt am Tag', [['A', 'B', 'C', 'D'], [false, false, false, false]], [$titles($p), array_column($p, 'next_day')]);
$p = TIX_Event_Extras::place_slots('2026-12-05', [['time' => '20:00', 'title' => 'B'], ['time' => '08:00', 'title' => 'A'], ['time' => '23:00', 'title' => 'C']]);
tm_eq('08:00 zählt nicht als Folgetag', [['A', 'B', 'C'], false], [$titles($p), $p[0]['next_day']]);
$p = TIX_Event_Extras::place_slots('2026-12-05', [['time' => '', 'title' => 'ohne'], ['time' => '20:00', 'stage' => 1, 'title' => 'B2'], ['time' => '20:00', 'stage' => 0, 'title' => 'B1'], ['time' => '21:00', 'title' => 'C']]);
tm_eq('gleiche Zeit nach Bühne, ohne Zeit am Ende', [['B1', 'B2', 'C', 'ohne'], null], [$titles($p), $p[3]['start_at']]);
tm_eq('Einzelner Slot', [false, $off('2026-12-05', '21:00')], (function ($p) { return [$p[0]['next_day'], $p[0]['start_at']]; })(TIX_Event_Extras::place_slots('2026-12-05', [['time' => '21:00', 'title' => 'X']])));
tm_eq('sort_slots ohne berechnete Felder', ['time', 'end', 'stage', 'title', 'desc'], array_keys(TIX_Event_Extras::sort_slots('2027-02-27', $jan)[0]));

$admin = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($admin ? intval($admin[0]->ID) : 0);
$eid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'TM Programm-Test', 'meta_input' => [
    '_tix_date_start' => '2027-02-27', '_tix_time_start' => '15:00', '_tix_date_end' => '2027-02-28', '_tix_time_end' => '03:00',
    '_tix_timetable' => ['2027-02-27' => $jan],
    '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1',
    '_tix_ticket_categories' => [['name' => 'VIP', 'price' => 99, 'qty' => 10, 'desc' => "Getränke den ganzen Tag\nCatering inklusive", 'online' => '1']],
]]);
try {
    $api = function () use ($eid) { if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush(); wp_cache_flush(); $u = get_current_user_id(); wp_set_current_user(0); [, $d] = tm_rest('GET', '/public/events/' . $eid); wp_set_current_user($u); return $d['event'] ?? $d; };
    $e = $api();
    $s = $e['timetable']['days'][0]['slots'] ?? [];
    tm_eq('API: Reihenfolge über Mitternacht', ['Andreas Köhler', 'Monchi', 'Der Zipfelbube', 'Ina Colada', 'Almklausi'], $titles($s));
    tm_eq('API: start_at/end_at/next_day', [$off('2027-02-28', '00:00'), $off('2027-02-28', '01:00'), true], [$s[4]['start_at'] ?? null, $s[4]['end_at'] ?? null, $s[4]['next_day'] ?? null]);
    tm_eq('API: Ticket-Beschreibung mehrzeilig', "Getränke den ganzen Tag\nCatering inklusive", $e['categories'][0]['description'] ?? null);

    $html = do_shortcode('[tix_timetable id="' . $eid . '"]');
    $pa = strpos($html, 'Ina Colada'); $pb = strpos($html, 'Almklausi');
    tm_eq('Website: Almklausi nach Ina Colada', true, $pa !== false && $pb !== false && $pb > $pa);
    tm_eq('Website: Tages-Etikett So.', true, strpos($html, '<span class="tix-tt-nextday">So.</span>') !== false);
    $sel = do_shortcode('[tix_ticket_selector id="' . $eid . '"]');
    $ok = strpos($sel, 'Getränke den ganzen Tag<br />') !== false;
    if (!$ok) { $i = strpos($sel, 'Getränke'); echo "  (Auszug: " . ($i === false ? 'keine Beschreibung, ' . strlen($sel) . ' Zeichen' : substr($sel, max(0, $i - 80), 200)) . ")\n"; }
    tm_eq('Website: Beschreibung mit Zeilenumbruch', true, $ok);

    // Editor (Studio + Veranstalterbereich, gleiche Route)
    [$st, $d] = tm_rest('GET', '/events/' . $eid . '/edit');
    $tt = $d['timetable'] ?? ($d['event']['timetable'] ?? []);
    tm_eq('Editor: hidden aus, Folgetag markiert', [200, false, true], [$st, $tt['hidden'] ?? null, (((array) ($tt['days'] ?? []))['2027-02-27'][4]['next_day'] ?? null)]);
    [$st] = tm_rest('POST', '/events/' . $eid, ['timetable' => ['hidden' => 'vielleicht']]);
    tm_eq('Editor: ungültiger Wert 400', 400, $st);
    [$st] = tm_rest('POST', '/events/' . $eid, ['timetable' => ['hidden' => true]]);
    tm_eq('Editor: ausblenden 200 + Meta', [200, '1'], [$st, get_post_meta($eid, '_tix_timetable_hidden', true)]);
    tm_eq('Ausgeblendet: Programm bleibt gespeichert', 5, count(get_post_meta($eid, '_tix_timetable', true)['2027-02-27'] ?? []));
    tm_eq('Ausgeblendet: API ohne Programm', null, $api()['timetable'] ?? null);
    tm_eq('Ausgeblendet: Website leer', '', do_shortcode('[tix_timetable id="' . $eid . '"]'));
    tm_eq('Ausgeblendet: kein Reiter', false, TIX_Event_Extras::timetable_visible($eid));
    [, $d] = tm_rest('GET', '/events/' . $eid . '/edit');
    tm_eq('Editor zeigt hidden an', true, ($d['timetable'] ?? ($d['event']['timetable'] ?? []))['hidden'] ?? null);
    [$st] = tm_rest('POST', '/events/' . $eid, ['timetable' => ['hidden' => false, 'days' => ['2027-02-27' => $jan]]]);
    tm_eq('Editor: einblenden, Meta 0 (wird verteilt)', [200, '0'], [$st, get_post_meta($eid, '_tix_timetable_hidden', true)]);
    tm_eq('Gespeichert in Reihenfolge über Mitternacht', ['Andreas Köhler', 'Monchi', 'Der Zipfelbube', 'Ina Colada', 'Almklausi'], $titles(get_post_meta($eid, '_tix_timetable', true)['2027-02-27']));
    tm_eq('Wieder sichtbar in der API', 5, count($api()['timetable']['days'][0]['slots'] ?? []));
    [$st] = tm_rest('POST', '/events/' . $eid, ['categories' => [['index' => 0, 'name' => 'VIP', 'price' => 99, 'qty' => 10, 'desc' => "Zeile 1\nZeile 2"]]]);
    tm_eq('Veranstalterbereich: Beschreibung mehrzeilig gespeichert', "Zeile 1\nZeile 2", get_post_meta($eid, '_tix_ticket_categories', true)[0]['desc'] ?? null);
} finally {
    wp_delete_post($eid, true);
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
