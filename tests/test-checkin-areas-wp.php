<?php
/**
 * Einlassbereiche (1.38.815). Pflicht: ohne Bereiche bzw. ohne area exakt wie bisher (Antworten, Zählung, Wiedereinlass,
 * Offline). Mit Bereichen: erlaubt/abgelehnt (tix_area_denied), voll (tix_area_full), Wiedereinlass je Bereich, Belegung,
 * Gäste zählen mit, Offline-Liste und -Abgleich mit Bereich.
 *   wp eval-file …/test-checkin-areas-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Checkin_Areas')) { echo "Tixomat ab 1.38.815 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$posts = []; $keep_opt = get_option(TIX_Sale_Settings::OPT, null);
$scan = function ($code, $eid, $area = null) use ($rest) { $b = ['code' => $code, 'event_id' => $eid]; if ($area !== null) $b['area'] = $area; return $rest('POST', '/checkin/scan', $b)[1]; };
$strip = function ($r) { unset($r['time']); return $r; }; // Zeitstempel unterscheiden sich
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    TIX_Checkin_Areas::maybe_install();
    $org = 0;
    if ($multi) { $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Area-Club']); $posts[] = $org; update_post_meta($org, '_tix_org_approval', 'approved'); }
    $q = $multi ? ['organizer_id' => $org] : [];
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Area-Event', 'meta_input' => ['_tix_date_start' => current_time('Y-m-d'), '_tix_time_start' => '20:00', '_tix_organizer_id' => $org,
        '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 10, 'qty' => 100], ['name' => 'VIP', 'price' => 30, 'qty' => 10]],
        '_tix_guest_list' => [['id' => 'GAST01', 'name' => 'Gast Eins', 'plus' => 1]]]]); $posts[] = $ev;
    $mk = function ($ci) use ($ev, &$posts) { $c = strtoupper(wp_generate_password(12, false, false)); $c = preg_replace('/[^A-Z0-9]/', 'X', $c); $t = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => $c, 'meta_input' => ['_tix_ticket_code' => $c, '_tix_ticket_event_id' => $ev, '_tix_ticket_cat_index' => $ci, '_tix_ticket_status' => 'valid', '_tix_ticket_owner_name' => 'Test ' . $ci]]); $posts[] = $t; return [$t, $c]; };
    // Bereich ohne angelegte Bereiche sicher leer
    $wpdb->delete(TIX_Checkin_Areas::t(), ['organizer_id' => $org]);
    if (!$multi) delete_option(TIX_Sale_Settings::OPT);

    // ── A: ohne Bereiche = unverändert ──
    [$t1, $c1] = $mk(0); [$t2, $c2] = $mk(0);
    $a1 = $scan($c1, $ev); $a2 = $scan($c2, $ev, 99);
    $eq('Ohne Bereiche: Erst-Scan mit und ohne area identisch (Status, Text, Felder)', [true, 'ok', 'Willkommen!', array_keys($strip($a1))], [$strip($a1) == array_merge($strip($a2), ['code' => $c1, 'name' => $a1['name']]), $a2['status'] ?? '', $a2['message'] ?? '', array_keys($strip($a2))]);
    $b1 = $scan($c1, $ev); $b2 = $scan($c2, $ev, 99);
    $eq('Ohne Bereiche: zweiter Scan (warn) identisch „Bereits eingecheckt.“', ['already', false, 'Bereits eingecheckt.', array_keys($strip($b1))], [$b2['status'] ?? '', $b2['block'] ?? null, $b2['message'] ?? '', array_keys($strip($b2))]);
    $eq('Ohne Bereiche: keine Zählung je Bereich', 0, intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TIX_Checkin_Areas::tl() . " WHERE event_id = %d", $ev))));
    $rest('POST', '/studio/checkout-settings', ['checkin' => ['duplicate' => 'reentry']], $q);
    $r1 = $scan($c1, $ev); $r2 = $scan($c2, $ev, 99);
    $eq('Ohne Bereiche: Wiedereinlass wie bisher (Zähler am Ticket)', ['reentry', 1, 'reentry', 1, 'Wiedereinlass (1.)'], [$r1['status'] ?? '', $r1['reentry_count'] ?? 0, $r2['status'] ?? '', $r2['reentry_count'] ?? 0, $r2['message'] ?? '']);
    $g = $rest('POST', '/checkin/scan', ['code' => 'GL-' . $ev . '-GAST01', 'event_id' => $ev, 'area' => 5])[1];
    $eq('Ohne Bereiche: Gast wie bisher (Gast + 1 auf einmal), ohne area-Feld', ['ok', 2, false], [$g['status'] ?? '', $g['checked_in_count'] ?? 0, array_key_exists('area', (array) $g)]);
    [, $off] = $rest('GET', "/checkin/$ev/offline");
    $eq('Ohne Bereiche: Offline-Liste ohne areas/ar', [false, false], [array_key_exists('areas', (array) $off), array_key_exists('ar', (array) ($off['tickets'][0] ?? []))]);
    [$t3, $c3] = $mk(0);
    [, $bt] = $rest('POST', '/checkin/batch', ['event_id' => $ev, 'list_id' => $off['list_id'], 'generated_at' => $off['generated_at'], 'sig' => $off['sig'], 'device' => 'A', 'items' => [['type' => 'ticket', 'id' => $t3, 'at' => 'x', 'area' => 7]]]);
    $eq('Ohne Bereiche: Offline-Abgleich mit area wie bisher', 'ok', $bt['results'][0]['status'] ?? '');

    // ── B: mit Bereichen ──
    [$st, $d] = $rest('POST', '/checkin/areas', ['name' => 'Haupteingang'], $q); $main = intval($d['area']['id'] ?? 0);
    [$st2, $d] = $rest('POST', '/checkin/areas', ['name' => 'VIP-Bereich', 'capacity' => 1], $q); $vip = intval($d['area']['id'] ?? 0);
    $eq('Bereiche anlegen (Name, Kapazität)', [201, 201, 1], [$st, $st2, $d['area']['capacity'] ?? 0]);
    [$st] = $rest('POST', '/checkin/areas', ['name' => 'vip-bereich'], $q);
    $eq('Doppelter Name → 409', 409, $st);
    [$st] = $rest('POST', "/events/$ev", ['categories' => [['index' => 0, 'name' => 'Normal', 'price' => 10, 'qty' => 100], ['index' => 1, 'name' => 'VIP', 'price' => 30, 'qty' => 10, 'areas' => [$vip]]]]);
    $cats = get_post_meta($ev, '_tix_ticket_categories', true);
    $eq('Kategorie VIP nur im VIP-Bereich, Normal überall', [[$vip], false], [$cats[1]['areas'] ?? null, isset($cats[0]['areas'])]);
    $rest('POST', '/studio/checkout-settings', ['checkin' => ['duplicate' => 'warn']], $q);
    [$t4, $c4] = $mk(1); [$t5, $c5] = $mk(0);
    $x = $scan($c4, $ev, $main);
    $eq('VIP-Ticket am Haupteingang → abgelehnt (tix_area_denied)', ['invalid', true, 'tix_area_denied', 'Kein Zutritt für diesen Bereich.', ['VIP-Bereich']], [$x['status'] ?? '', $x['area_denied'] ?? null, $x['error'] ?? '', $x['message'] ?? '', $x['allowed_areas'] ?? null]);
    $eq('Abgelehnt: Ticket bleibt uneingecheckt', '', (string) get_post_meta($t4, '_tix_ticket_checked_in', true));
    $x = $scan($c5, $ev, $vip);
    $eq('Normal-Ticket im VIP-Bereich (überall erlaubt) → ok, Belegung 1, eingecheckt', ['ok', 1, true], [$x['status'] ?? '', $x['area']['count'] ?? 0, (bool) get_post_meta($t5, '_tix_ticket_checked_in', true)]);
    $x = $scan($c4, $ev, $vip);
    $eq('VIP-Bereich voll (Kapazität 1) → tix_area_full', ['invalid', 'tix_area_full'], [$x['status'] ?? '', $x['error'] ?? '']);
    $rest('POST', "/checkin/areas/$vip", ['capacity' => 0], $q);
    $x = $scan($c4, $ev, $vip);
    $eq('Kapazität aufgehoben → VIP-Ticket ok', 'ok', $x['status'] ?? '');
    $x = $scan($c4, $ev, $vip);
    $eq('Erneut im selben Bereich (warn) → already', ['already', false], [$x['status'] ?? '', $x['block'] ?? null]);
    $x = $scan($c5, $ev, $main);
    $eq('Anderer Bereich (schon im Event eingecheckt) → ok, kein Doppel', 'ok', $x['status'] ?? '');
    $rest('POST', '/studio/checkout-settings', ['checkin' => ['duplicate' => 'reentry']], $q);
    $x = $scan($c4, $ev, $vip); $y = $scan($c4, $ev, $vip);
    $eq('Wiedereinlass je Bereich (1., 2.)', ['reentry', 1, 2], [$x['status'] ?? '', $x['reentry_count'] ?? 0, $y['reentry_count'] ?? 0]);
    $rest('POST', '/studio/checkout-settings', ['checkin' => ['duplicate' => 'block']], $q);
    $x = $scan($c5, $ev, $vip);
    $eq('block: erneut im Bereich → already + block', ['already', true], [$x['status'] ?? '', $x['block'] ?? null]);
    $x = $rest('POST', '/checkin/scan', ['code' => $c5, 'event_id' => $ev, 'area' => 99999999]);
    $eq('Unbekannter Bereich → 400 tix_area_unknown', [400, 'tix_area_unknown'], [$x[0], $x[2]]);
    $x = $scan($c5, $ev);
    $eq('Ohne area auch mit Bereichen wie bisher (block → already + block)', ['already', true, 'Bereits eingecheckt.'], [$x['status'] ?? '', $x['block'] ?? null, $x['message'] ?? '']);
    $g = $rest('POST', '/checkin/scan', ['code' => 'GL-' . $ev . '-GAST01', 'event_id' => $ev, 'area' => $main])[1];
    $eq('Gast im Bereich: zählt mit', [true, true], [in_array($g['status'] ?? '', ['ok', 'partial', 'already'], true), isset($g['area']) || ($g['status'] ?? '') === 'already']);
    [$st, $d] = $rest('GET', "/checkin/$ev/areas");
    $occ = array_column((array) ($d['areas'] ?? []), null, 'id');
    $eq('Belegung: VIP 2 Tickets mit 2 Wiedereinlässen, Haupteingang 1 Ticket', [200, 2, 2, 1], [$st, $occ[$vip]['tickets'] ?? 0, $occ[$vip]['reentries'] ?? 0, $occ[$main]['tickets'] ?? 0]);
    // Offline mit Bereichen
    [, $off] = $rest('GET', "/checkin/$ev/offline");
    $byid = array_column((array) ($off['tickets'] ?? []), null, 'id');
    $eq('Offline-Liste: Bereiche, erlaubt (ar) und schon drin (ain)', [2, [$vip], [$vip]], [count($off['areas'] ?? []), $byid[$t4]['ar'] ?? null, $byid[$t4]['ain'] ?? null]);
    [$t6, $c6] = $mk(1);
    $rest('POST', '/studio/checkout-settings', ['checkin' => ['duplicate' => 'warn']], $q);
    [, $bt] = $rest('POST', '/checkin/batch', ['event_id' => $ev, 'list_id' => $off['list_id'], 'generated_at' => $off['generated_at'], 'sig' => $off['sig'], 'device' => 'B',
        'items' => [['type' => 'ticket', 'id' => $t6, 'at' => 'a', 'area' => $main], ['type' => 'ticket', 'id' => $t6, 'at' => 'b', 'area' => $vip], ['type' => 'ticket', 'id' => $t6, 'at' => 'c', 'area' => $vip]]]);
    $eq('Offline: abgelehnt, ok, Wiederholung desselben Geräts ok', ['denied', 'ok', 'ok'], array_column((array) ($bt['results'] ?? []), 'status'));
    [, $bt] = $rest('POST', '/checkin/batch', ['event_id' => $ev, 'list_id' => $off['list_id'], 'generated_at' => $off['generated_at'], 'sig' => $off['sig'], 'device' => 'C', 'items' => [['type' => 'ticket', 'id' => $t6, 'at' => 'd', 'area' => $vip]]]);
    $eq('Offline: anderes Gerät im selben Bereich (warn) → conflict', 'conflict', $bt['results'][0]['status'] ?? '');
    $staff = intval(get_users(['role' => 'tix_staff', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);
    if ($staff && !$multi) { wp_set_current_user($staff); $eq('Mitarbeiter: Bereiche lesen ja, anlegen nein', [200, true], [$rest('GET', '/checkin/areas')[0], in_array($rest('POST', '/checkin/areas', ['name' => 'X'])[0], [401, 403], true)]); wp_set_current_user($adm); }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($posts as $p) { $wpdb->delete(TIX_Checkin_Areas::tl(), ['event_id' => $p]); }
    $wpdb->delete(TIX_Checkin_Areas::t(), ['organizer_id' => $org]);
    foreach (array_reverse($posts) as $p) { if (get_post_type($p) === 'tix_organizer' && class_exists('TIX_Object_Data')) TIX_Object_Data::remove(TIX_Sale_Settings::OBJ, $p); wp_delete_post($p, true); }
    if (!$multi) { if ($keep_opt === null) delete_option(TIX_Sale_Settings::OPT); else update_option(TIX_Sale_Settings::OPT, $keep_opt); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
