<?php
/** Saalplan Phase 1 (1.38.659): Geometrie, Modul, Grenzen, Wettlauf, Doppelverkauf, Schnappschuss, Mandanten, Studio, Bild → Saalplan.
 *  wp eval-file …/tests/test-seatmap-phase1-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Seating') || !class_exists('TIX_Seatmap_Geometry')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function sp_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function sp_rest($m, $r, array $b = [], array $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$T = $wpdb->prefix . TIX_Seatmap::TABLE_SUFFIX;
TIX_Seatmap::maybe_upgrade();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_seat\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_app\\_rl\\_seat\\_%'");
$keep = [];
foreach ([TIX_Seating::OPT_BOOKED, TIX_Seating::OPT_TRIAL, TIX_Seating::OPT_LEGACY, TIX_Seating::OPT_AI_USAGE, TIX_AI_Provider::OPT, TIX_AI_Provider::OPT_USAGE] as $o) $keep[$o] = get_option($o, null);
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $orders = [];
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$owner = wp_insert_user(['user_login' => "sp_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "sp_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$other = wp_insert_user(['user_login' => "sp_other_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "sp_other_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $other;
$org = 0; $org2 = 0; $org3 = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SP Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $org2 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SP Andere', 'meta_input' => ['_tix_org_user_id' => $other]]); $posts[] = $org2;
    $org3 = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SP Neu']); $posts[] = $org3;
    TIX_Plan::save_org($org, ['seating' => true]);
    TIX_Plan::save_org($org2, ['seating' => true]);
} else {
    update_option(TIX_Seating::OPT_BOOKED, '1', false);
}
$mkev = function ($t, $o) use (&$posts) { $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "SP $t", 'meta_input' => ['_tix_organizer_id' => $o, '_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS)]]); $posts[] = $id; return $id; };
$mkplan = function ($title, $data, $o) use (&$posts, $multi) { $id = wp_insert_post(['post_type' => 'tix_seatmap', 'post_status' => 'publish', 'post_title' => $title]); $posts[] = $id; if ($multi) update_post_meta($id, TIX_Seatmap::META_ORG, $o); TIX_Seatmap::store($id, $data); return $id; };
$seats_of = function ($data) { $o = []; foreach ($data['sections'] as $s) foreach ($s['rows'] as $r) foreach ($r['seats'] as $x) $o[] = $x; return $o; };
$mkorder = function ($event_id, $seats, $sm) use ($wpdb, &$orders, $sfx) {
    static $n = 0; $n++;
    $wpdb->insert($wpdb->prefix . 'tix_orders', ['order_number' => "SP$sfx-$n", 'status' => 'pending', 'billing_email' => "sp_kunde_{$sfx}_$n@example.test", 'billing_first_name' => 'Kim']);
    $oid = intval($wpdb->insert_id); $orders[] = $oid;
    $wpdb->insert($wpdb->prefix . 'tix_order_items', ['order_id' => $oid, 'event_id' => $event_id, 'quantity' => count($seats), 'meta' => wp_json_encode(['seats' => $seats, 'seatmap_id' => $sm])]);
    return $oid;
};
$row = function ($ev, $seat, $st = null) use ($wpdb, $T) { return $wpdb->get_row($wpdb->prepare("SELECT * FROM $T WHERE event_id = %d AND seat_id = %s" . ($st ? " AND status = '" . esc_sql($st) . "'" : '') . ' LIMIT 1', $ev, $seat)); };
$mails = []; add_filter('pre_wp_mail', function ($r, $a) use (&$mails) { $mails[] = $a; return true; }, 10, 2);

// ═══ 1. Geometrie ═══
$plan = TIX_Seatmap::sanitize_seatmap(['sections' => [
    ['id' => 'parkett', 'label' => 'Parkett', 'price' => 30, 'block' => ['rows' => 3, 'seats' => 10, 'curve' => 0.4, 'gaps' => [['after' => 5, 'width' => 2]], 'types' => ['parkett_A_1' => 'wheelchair']]],
    ['id' => 'tische', 'label' => 'Tische', 'price' => 50, 'block' => ['shape' => 'tables', 'tables' => 2, 'seats_per_table' => 6]],
]]);
$all = $seats_of($plan);
$xs = array_unique(array_map(function ($s) { return $s['x'] . '|' . $s['y']; }, $all));
$chk = TIX_Seatmap_Geometry::check($plan);
sp_eq('Block erzeugt 30 + 12 Plätze mit eigenen Koordinaten, IDs Sektion_Reihe_Platz', [42, 42, 'parkett_A_1', 'parkett_C_10', 'tische_T2_6', 0, 0],
    [count($all), count($xs), $all[0]['id'], $plan['sections'][0]['rows'][2]['seats'][9]['id'], $plan['sections'][1]['rows'][1]['seats'][5]['id'], $chk['overlaps'], count($chk['duplicates'])]);
sp_eq('Pinsel: Rollstuhlplatz; Bogen um die Bühne (Rand näher an der Bühnenlinie); Gang verbreitert', ['wheelchair', true, true],
    [$all[0]['type'], $plan['sections'][0]['rows'][0]['seats'][0]['y'] < $plan['sections'][0]['rows'][0]['seats'][4]['y'],
     ($plan['sections'][0]['rows'][0]['seats'][5]['x'] - $plan['sections'][0]['rows'][0]['seats'][4]['x']) > 2 * ($plan['sections'][0]['rows'][0]['seats'][1]['x'] - $plan['sections'][0]['rows'][0]['seats'][0]['x'])]);
sp_eq('Bühne ergänzt, Plan normalisiert (Rand 40), Tische als Objekte', [true, true, 2], [($plan['objects'][0]['type'] ?? '') === 'stage', min(array_column($all, 'x')) >= 40 - 0.01 && $plan['width'] > 100, count($plan['sections'][1]['tables'] ?? [])]);
// Alter Plan (WP-Editor speicherte x:0,y:0)
$legacy = ['layout' => 'theater', 'sections' => [
    ['id' => 'section_1', 'label' => 'Sektion 1', 'price' => 20, 'rows' => [['id' => 'A', 'seats' => array_map(function ($i) { return ['id' => "section_1_A$i", 'x' => 0, 'y' => 0]; }, range(1, 8))], ['id' => 'B', 'seats' => array_map(function ($i) { return ['id' => "section_1_B$i", 'x' => 0, 'y' => 0]; }, range(1, 8))]]],
    ['id' => 'section_10', 'label' => 'Sektion 10', 'price' => 25, 'rows' => [['id' => 'A', 'seats' => array_map(function ($i) { return ['id' => "section_10_A$i", 'x' => 0, 'y' => 0]; }, range(1, 6))]]],
]];
$lc = TIX_Seatmap::sanitize_seatmap($legacy);
$lcs = $seats_of($lc);
$lchk = TIX_Seatmap_Geometry::check($lc);
sp_eq('Alter Plan nachgerechnet: 22 Plätze, keine Überlappung, IDs unverändert', [22, 22, 0, 'section_10_A6'],
    [count($lcs), count(array_unique(array_map(function ($s) { return $s['x'] . '|' . $s['y']; }, $lcs))), $lchk['overlaps'], end($lcs)['id']]);
$dup = ['sections' => [['id' => 'a', 'rows' => [['id' => 'A', 'seats' => [['id' => 'x', 'x' => 10, 'y' => 10], ['id' => 'x', 'x' => 50, 'y' => 10], ['id' => 'y', 'x' => 14, 'y' => 13]]]]]]];
$dd = TIX_Seatmap::sanitize_seatmap($dup); $dc = TIX_Seatmap_Geometry::check($dd);
sp_eq('Prüfung: doppelte ID entfernt, Überlappung gezählt', [['x'], 1, 2], [$dc['duplicates'], $dc['overlaps'], $dc['seats']]);
sp_eq('Bezeichnungen: aus dem Plan und aus alten IDs', ['Parkett · Reihe C, Platz 12', 'Reihe A, Platz 3', 'Reihe B, Platz 7'],
    [TIX_Seatmap::seat_label('parkett_C_12', ['sections' => [['label' => 'Parkett', 'rows' => [['id' => 'C', 'label' => 'Reihe C', 'seats' => [['id' => 'parkett_C_12', 'label' => '12']]]]]]]), TIX_Seatmap::seat_label('section_1_A3'), TIX_Seatmap::seat_label('x_B_7')]);

// ═══ 2. Plan + Event, Haltungen ═══
$sm = $mkplan("SP Saal $sfx", ['sections' => [['id' => 'parkett', 'label' => 'Parkett', 'price' => 30, 'block' => ['rows' => 3, 'seats' => 20]]]], $org);
$ev = $mkev("Konzert $sfx", $org);
TIX_Seatmap::$allow_assign = false;
sp_eq('Zuweisen mit Modul', true, TIX_Seatmap::assign($ev, $sm, 'manual'));
$ids = array_column($seats_of(TIX_Seatmap::data($sm)), 'id');
$r = TIX_Seatmap::hold($ev, $sm, array_slice($ids, 0, 10), 'web_A');
sp_eq('10 Plätze je Halter erlaubt', [10, []], [count($r['reserved']), $r['failed']]);
$r = TIX_Seatmap::hold($ev, $sm, [$ids[10]], 'web_A');
sp_eq('Der 11. Platz → limit_holder, nichts gehalten', ['limit_holder', [], [$ids[10]]], [$r['error'], $r['reserved'], $r['failed']]);
$r = TIX_Seatmap::hold($ev, $sm, [$ids[0], $ids[1]], 'web_A');
sp_eq('Eigene Plätze verlängern geht weiter', [2, null], [count($r['reserved']), $r['error']]);
$r = TIX_Seatmap::hold($ev, $sm, [$ids[0]], 'web_B');
sp_eq('Wettlauf: fremd gehaltener Platz → abgelehnt', [[], [$ids[0]]], [$r['reserved'], $r['failed']]);
add_filter('tix_seat_hold_limits', $lf = function ($l) { return array_merge($l, ['ip' => 12]); });
$r = TIX_Seatmap::hold($ev, $sm, array_slice($ids, 20, 3), 'web_B');
sp_eq('Gleiche IP, neuer Halter: über 12 → limit_ip', 'limit_ip', $r['error']);
remove_filter('tix_seat_hold_limits', $lf);
add_filter('tix_seat_hold_limits', $lf = function ($l) { return array_merge($l, ['ip' => 100, 'share' => 0.2, 'min_event' => 5]); });
$r = TIX_Seatmap::hold($ev, $sm, array_slice($ids, 20, 3), 'web_C');
sp_eq('Event-Anteil (20 % von 60 = 12): 10 + 3 → limit_event', 'limit_event', $r['error']);
remove_filter('tix_seat_hold_limits', $lf);
TIX_Seatmap::release($ev, array_slice($ids, 0, 10), 'web_A');
sp_eq('Freigabe: Halter A hält nichts mehr', [], TIX_Seatmap::held_by($ev, 'web_A'));
// Längste Haltezeit ab der ersten Wahl
TIX_Seatmap::hold($ev, $sm, [$ids[30]], 'web_D');
$wpdb->query($wpdb->prepare("UPDATE $T SET reserved_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 70 MINUTE) WHERE event_id = %d AND seat_id = %s", $ev, $ids[30]));
TIX_Seatmap::hold($ev, $sm, [$ids[30]], 'web_D');
sp_eq('Verlängern höchstens 60 min nach der ersten Wahl', [], TIX_Seatmap::held_by($ev, 'web_D'));
// REST
$wpdb->query($wpdb->prepare("DELETE FROM $T WHERE event_id = %d", $ev));
wp_set_current_user(0);
[$st, $d] = sp_rest('POST', '/customer/seats/hold', ['event_id' => $ev, 'seat_ids' => array_slice($ids, 0, 2)]);
$tok = (string) ($d['hold_token'] ?? '');
sp_eq('REST hold: Token, hold_seconds vom Server, max_seats', [200, true, true, 10], [$st, strlen($tok) >= 16, ($d['hold_seconds'] ?? 0) > 800 && ($d['hold_seconds'] ?? 0) <= 900, $d['max_seats'] ?? 0]);
[$st, $d, $c] = sp_rest('POST', '/customer/seats/hold', ['event_id' => $ev, 'seat_ids' => array_slice($ids, 2, 9), 'hold_token' => $tok]);
sp_eq('REST hold über 10 → 429 tix_seat_limit_holder', [429, 'tix_seat_limit_holder'], [$st, $c]);
[$st, $d] = sp_rest('GET', "/public/events/$ev/seatmap", [], ['hold_token' => $tok]);
sp_eq('Seatmap: Koordinaten, Version, Haltezeit in Sekunden, eigene Haltung', [200, 900, 2, true, 1], [$st, $d['hold_seconds'] ?? 0, count($d['held'] ?? []), ($d['map'][0]['rows'][0]['seats'][1]['x'] ?? 0) > ($d['map'][0]['rows'][0]['seats'][0]['x'] ?? 0), $d['version'] ?? 0]);
$wpdb->query($wpdb->prepare("DELETE FROM $T WHERE event_id = %d", $ev));

// ═══ 3. Verkauf: Halter-Prüfung, Doppelverkauf, fehlgeschlagenes INSERT ═══
$S = $ids;
$o1 = $mkorder($ev, [$S[0]], $sm);
TIX_Seatmap::hold($ev, $sm, [$S[0]], 'web_O1', TIX_Seatmap::CHECKOUT_MIN);
TIX_Seatmap::attach_order([['event_id' => $ev, 'meta' => ['seats' => [$S[0]]]]], 'web_O1', $o1);
$o2 = $mkorder($ev, [$S[0]], $sm);
$c2 = TIX_Seatmap::sell($o2, [[$ev, $sm, $S[0]]]);
sp_eq('Fremde, gültige Haltung bleibt: Konflikt held, Haltung von O1 unverändert', [[[$ev, $S[0], 'held']], 'reserved', (string) $o1], [$c2, $row($ev, $S[0])->status ?? '', (string) ($row($ev, $S[0])->order_id ?? '')]);
TIX_Seatmap::on_native_status($o1, 'completed');
sp_eq('O1 bezahlt: verkauft an O1, Schnappschuss angelegt', ['sold', (string) $o1, true], [$row($ev, $S[0])->status ?? '', (string) ($row($ev, $S[0])->order_id ?? ''), is_array(get_post_meta($ev, TIX_Seatmap::META_SNAPSHOT, true))]);
$mails = [];
$o3 = $mkorder($ev, [$S[0]], $sm);
TIX_Seatmap::on_native_status($o3, 'completed');
$notes = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_order_notes WHERE order_id = %d AND note LIKE %s", $o3, '%Sitzplatz-Konflikt%')));
sp_eq('Doppelverkauf: Konflikt gemerkt, Notiz, Mail an Kunde und Veranstalter', [true, 1, 2, true], [isset(TIX_Seatmap::conflicts_of($o3)[$ev . '|' . $S[0]]), $notes, count($mails), strpos((string) ($mails[0]['subject'] ?? ''), 'Sitzplatz') !== false]);
TIX_Seatmap::on_native_status($o3, 'completed');
sp_eq('Wiederholung: keine zweite Mail', 2, count($mails));
// verspätete Zahlung: eigene Haltung abgelaufen, Platz frei → wird verkauft
$o4 = $mkorder($ev, [$S[1]], $sm);
TIX_Seatmap::on_native_status($o4, 'processing');
sp_eq('Verspätete Zahlung, Platz frei → verkauft', 'sold', $row($ev, $S[1])->status ?? '');
// verspätete Zahlung, inzwischen fremd gehalten → Konflikt, fremde Haltung bleibt
TIX_Seatmap::hold($ev, $sm, [$S[2]], 'web_X');
$o5 = $mkorder($ev, [$S[2]], $sm);
TIX_Seatmap::on_native_status($o5, 'completed');
sp_eq('Verspätete Zahlung, fremd gehalten → Konflikt, fremde Haltung bleibt', [true, 'reserved', 'web_X'], [isset(TIX_Seatmap::conflicts_of($o5)[$ev . '|' . $S[2]]), $row($ev, $S[2])->status ?? '', $row($ev, $S[2])->session_id ?? '']);
// fehlgeschlagenes INSERT
$bad = $S[3];
add_filter('query', $qf = function ($q) use ($bad, $T) { return (strpos($q, "INSERT INTO `$T`") === 0 && strpos($q, "'$bad'") !== false) ? 'SELECT * FROM tix_gibt_es_nicht_' . wp_rand() : $q; });
$o6 = $mkorder($ev, [$bad], $sm);
TIX_Seatmap::on_native_status($o6, 'completed');
remove_filter('query', $qf);
sp_eq('Fehlgeschlagenes INSERT → Konflikt db statt still', 'db', TIX_Seatmap::conflicts_of($o6)[$ev . '|' . $bad]['reason'] ?? '');
// Storno
TIX_Seatmap::on_native_cancelled($o4);
sp_eq('Storno gibt den Platz frei', null, $row($ev, $S[1]));
// Einzelticket-Storno (tix_release_seat mit richtiger Meta)
$o7 = $mkorder($ev, [$S[4]], $sm);
TIX_Seatmap::on_native_status($o7, 'completed');
$tk = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'SP Ticket', 'meta_input' => ['_tix_ticket_event_id' => $ev, '_tix_ticket_order_id' => $o7, '_tix_ticket_seat_id' => $S[4], '_tix_ticket_seatmap_id' => $sm]]); $posts[] = $tk;
TIX_Tickets::on_native_order_cancelled($o7);
sp_eq('Ticket-Storno liest _tix_ticket_seat_id und gibt den Platz frei', null, $row($ev, $S[4], 'sold'));
// verfügbare Plätze mit Unterstrich-Sektionen (LIKE-Fehler)
$smL = $mkplan("SP Alt $sfx", $legacy, $org);
$evL = $mkev("Alt $sfx", $org);
TIX_Seatmap::assign($evL, $smL);
$oL = $mkorder($evL, ['section_10_A1', 'section_10_A2'], $smL);
TIX_Seatmap::on_native_status($oL, 'completed');
sp_eq('Freie Plätze je Sektion: section_1 unberührt von section_10', [16, 4], [TIX_Seatmap::available_seats($evL, $smL, 'section_1'), TIX_Seatmap::available_seats($evL, $smL, 'section_10')]);

// ═══ 4. Versionen und Schnappschuss ═══
$v1 = TIX_Seatmap::version($sm);
$smaller = ['sections' => [['id' => 'parkett', 'label' => 'Parkett', 'price' => 30, 'block' => ['rows' => 3, 'seats' => 20, 'types' => [$S[0] => 'blocked']]]]];
$r = TIX_Seatmap::store($sm, $smaller);
sp_eq('Verkaufter Platz fehlt im neuen Plan → 409 mit Event', ['tix_seatmap_conflict', 409, $ev], [is_wp_error($r) ? $r->get_error_code() : '', is_wp_error($r) ? $r->get_error_data()['status'] : 0, is_wp_error($r) ? ($r->get_error_data()['events'][0]['event_id'] ?? 0) : 0]);
$r = TIX_Seatmap::store($sm, $smaller, ['keep_old_for_sold' => true]);
sp_eq('Als neue Version: Plan v+1, Event bleibt auf alter Fassung (Platz buchbar im Schnappschuss)', [$v1 + 1, $v1, true, false],
    [$r['version'] ?? 0, TIX_Seatmap::event_version($ev), (bool) TIX_Seatmap::section_of(TIX_Seatmap::event_data($ev), $S[0]), (bool) TIX_Seatmap::section_of(TIX_Seatmap::data($sm), $S[0])]);
$bigger = ['sections' => [['id' => 'parkett', 'label' => 'Parkett', 'price' => 35, 'block' => ['rows' => 4, 'seats' => 20]]]];
$r = TIX_Seatmap::store($sm, $bigger);
sp_eq('Zuordnung bestanden: Event wechselt auf die neue Version (neuer Preis, 80 Plätze)', [[$ev], $v1 + 2, 35.0, 80], [$r['upgraded'] ?? null, TIX_Seatmap::event_version($ev), floatval(TIX_Seatmap::event_data($ev)['sections'][0]['price'] ?? 0), TIX_Seatmap::count_seats(TIX_Seatmap::event_data($ev))]);
$revs = get_post_meta($sm, TIX_Seatmap::META_REVS, true);
sp_eq('Alte Fassungen aufgehoben', [$v1, $v1 + 1], array_slice(array_map('intval', array_column((array) $revs, 'v')), -2));
$sm2 = $mkplan("SP Zweiter $sfx", $bigger, $org);
update_post_meta($ev, '_tix_seatmap_id', $sm2);
$a = TIX_Seatmap::assign($ev, $sm2);
sp_eq('Nach dem Verkauf: Plan-Wechsel gesperrt (Meta-Schutz und Studio)', [$sm, 'tix_seatmap_sold'], [TIX_Seatmap::event_seatmap($ev), is_wp_error($a) ? $a->get_error_code() : '']);

// ═══ 5. Studio-Routen ═══
wp_set_current_user($multi ? $owner : $admin);
[$st, $d] = sp_rest('GET', '/studio/seatmaps/templates');
sp_eq('Vorlagen: Theater, Club, Bankett, Arena mit Plätzen', [200, ['theater', 'club', 'banquet', 'arena'], true], [$st, array_column((array) ($d['templates'] ?? []), 'key'), min(array_column((array) ($d['templates'] ?? []), 'seats') ?: [0]) > 0]);
$tseats = intval($d['templates'][0]['seats'] ?? 0);
[$st, $d] = sp_rest('POST', '/studio/seatmaps', ['template' => 'theater', 'title' => "SP Theater $sfx"]);
$tid = intval($d['id'] ?? 0); $posts[] = $tid;
sp_eq('Anlegen aus Vorlage → 201, Version 1, Plätze wie Vorlage', [201, 1, $tseats], [$st, $d['version'] ?? 0, $d['seats'] ?? 0]);
$data = $d['data'];
$data['sections'][0]['price'] = 44;
[$st, $d] = sp_rest('POST', "/studio/seatmaps/$tid", ['data' => $data, 'title' => "SP Theater neu $sfx"]);
sp_eq('Speichern → Version 2, Titel, ältere Fassung abrufbar', [200, 2, "SP Theater neu $sfx", 1], [$st, $d['version'] ?? 0, $d['title'] ?? '', $d['revisions'][0]['v'] ?? 0]);
[$st, $d] = sp_rest('GET', "/studio/seatmaps/$tid/revisions/1");
sp_eq('Fassung 1 lesen', [200, 1, true], [$st, $d['v'] ?? 0, is_array($d['data'] ?? null)]);
[$st, $d] = sp_rest('POST', "/studio/seatmaps/$tid/copy");
$cid = intval($d['id'] ?? 0); $posts[] = $cid;
sp_eq('Kopieren → neuer Plan „(Kopie)“', [201, true], [$st, strpos((string) ($d['title'] ?? ''), '(Kopie)') !== false]);
[$st, $d] = sp_rest('POST', '/studio/seatmaps/generate', ['data' => ['sections' => [['id' => 'a', 'label' => 'A', 'block' => ['rows' => 2, 'seats' => [8, 10]]]]]]);
sp_eq('Vorschau aus Blöcken ohne Speichern', [200, 18], [$st, $d['stats']['bookable'] ?? 0]);
$ev2 = $mkev("Studio $sfx", $org);
[$st, $d] = sp_rest('POST', "/studio/events/$ev2/seatmap", ['seatmap_id' => $tid, 'mode' => 'best']);
sp_eq('Zuweisen im Studio: Plan und Bestplatz-Modus', [200, $tid, 'best', $tseats], [$st, $d['seatmap_id'] ?? 0, $d['mode'] ?? '', $d['counts']['total'] ?? 0]);
$tids = array_column($seats_of(TIX_Seatmap::data($tid)), 'id');
TIX_Seatmap::hold($ev2, $tid, [$tids[0]], 'web_S');
[$st, $d] = sp_rest('POST', "/studio/events/$ev2/seatmap/block", ['seat_ids' => [$tids[0], $tids[1], $tids[2]], 'blocked' => true]);
sp_eq('Sperren: freie Plätze gesperrt, gehaltener übersprungen; Belegung zählt', [200, [$tids[1], $tids[2]], [$tids[0]], 2, 1, 'blocked'],
    [$st, $d['changed'] ?? null, $d['skipped'] ?? null, $d['counts']['blocked'] ?? 0, $d['counts']['held'] ?? 0, ((array) ($d['seats'] ?? []))[$tids[1]]['status'] ?? '']);
sp_eq('Gesperrter Platz ist für Gäste belegt', true, in_array($tids[1], TIX_Seatmap::taken($ev2, $tid), true));
[$st, $d] = sp_rest('POST', "/studio/events/$ev2/seatmap/block", ['seat_ids' => [$tids[1]], 'blocked' => false]);
sp_eq('Freigeben', [200, 1], [$st, $d['counts']['blocked'] ?? 0]);
[$st, $d, $c] = sp_rest('DELETE', "/studio/seatmaps/$tid");
sp_eq('Löschen eines zugewiesenen Plans → 409', [409, 'tix_seatmap_in_use'], [$st, $c]);
[$st, $d] = sp_rest('DELETE', "/studio/seatmaps/$cid");
sp_eq('Kopie löschen', [200, null], [$st, get_post($cid)]);
[$st, $d] = sp_rest('GET', '/studio/seatmaps');
$lst = array_column((array) ($d['seatmaps'] ?? []), null, 'id');
sp_eq('Liste: Plan mit Vorschau und Event-Nutzung', [200, true, 1, true], [$st, isset($lst[$tid]), $lst[$tid]['events_count'] ?? 0, count($lst[$tid]['preview']['dots'] ?? []) > 10]);

// ═══ 6. Mandanten (Plattform) ═══
if ($multi) {
    $evB = $mkev("Fremd $sfx", $org2);
    $smB = $mkplan("SP Fremdplan $sfx", $bigger, $org2);
    sp_eq('Eigene Liste ohne fremde Pläne; get_all_seatmaps gefiltert', [false, false], [isset($lst[$smB]), isset(TIX_Seatmap::get_all_seatmaps()[$smB])]);
    [$st] = sp_rest('GET', "/studio/seatmaps/$smB");
    [$st2] = sp_rest('POST', "/studio/seatmaps/$smB", ['title' => 'gekapert']);
    [$st3] = sp_rest('DELETE', "/studio/seatmaps/$smB");
    sp_eq('Fremder Plan: lesen/speichern/löschen → 404', [404, 404, 404, "SP Fremdplan $sfx"], [$st, $st2, $st3, get_the_title($smB)]);
    [$st] = sp_rest('POST', "/studio/events/$ev2/seatmap", ['seatmap_id' => $smB]);
    sp_eq('Fremden Plan dem eigenen Event zuweisen → 404', 404, $st);
    [$st] = sp_rest('GET', "/studio/events/$evB/seatmap");
    sp_eq('Fremdes Event → 403', 403, $st);
    sp_eq('Veranstalter-Rolle ohne Beitragsrechte: im WP-Admin gar nicht bearbeitbar', false, TIX_Seatmap::can_edit($sm, $owner));
    // mit Beitragsrechten (manche Seiten geben sie der Rolle): nur der eigene Veranstalter
    foreach ([$owner, $other] as $u) { $uo = new WP_User($u); $uo->add_cap('edit_posts'); $uo->add_cap('edit_others_posts'); $uo->add_cap('delete_posts'); $uo->add_cap('delete_others_posts'); $uo->add_cap('edit_published_posts'); $uo->add_cap('delete_published_posts'); }
    wp_cache_flush();
    sp_eq('Bearbeiten mit Rechten: edit_post + eigener Veranstalter', [true, false], [TIX_Seatmap::can_edit($sm, $owner), TIX_Seatmap::can_edit($smB, $owner)]);
    sp_eq('WP-Admin: fremder Plan nicht bearbeitbar (map_meta_cap)', [false, true, false], [user_can($owner, 'edit_post', $smB), user_can($other, 'edit_post', $smB), user_can($owner, 'delete_post', $smB)]);
    foreach ([$owner, $other] as $u) { $uo = new WP_User($u); foreach (['edit_posts', 'edit_others_posts', 'delete_posts', 'delete_others_posts', 'edit_published_posts', 'delete_published_posts'] as $cp) $uo->remove_cap($cp); }
}

// ═══ 7. Bild → Saalplan ═══
update_option(TIX_AI_Provider::OPT, ['provider' => 'claude', 'key_enc' => TIX_Ad_Accounts::enc('sk-ant-api03-' . str_repeat('s', 40) . 'Ab12')], false);
delete_option(TIX_Seating::OPT_AI_USAGE);
$GLOBALS['sp_ai'] = [];
TIX_AI_Provider::$http = function ($url, $args) {
    $GLOBALS['sp_ai'][] = json_decode((string) $args['body'], true);
    $tool = ['frame' => ['width' => 1000, 'height' => 700], 'layout' => 'theater', 'confidence' => 0.7, 'notes' => 'Rang schwer lesbar', 'total_seats_estimate' => 290,
        'sections' => [['label' => 'Parkett', 'block' => ['origin' => ['x' => 500, 'y' => 150], 'rows' => 10, 'seats' => 20, 'curve' => 0.2]],
                       ['label' => 'Rang', 'block' => ['origin' => ['x' => 500, 'y' => 520], 'rows' => 4, 'seats' => [20, 22, 24, 26]]]],
        'objects' => [['type' => 'stage', 'x' => 500, 'y' => 60, 'w' => 400, 'h' => 60, 'label' => 'Bühne']]];
    return ['headers' => [], 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null,
        'body' => wp_json_encode(['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'saalplan', 'input' => $tool]], 'usage' => ['input_tokens' => 1500, 'output_tokens' => 300]])];
};
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
[$st, $d] = sp_rest('POST', '/studio/seatmaps/from-image', ['image' => $png, 'total_seats' => 300]);
$sent = $GLOBALS['sp_ai'][0] ?? [];
sp_eq('KI-Anfrage: Bild als Base64, Werkzeug saalplan erzwungen', ['image', 'image/png', 'saalplan'], [$sent['messages'][0]['content'][0]['type'] ?? '', $sent['messages'][0]['content'][0]['source']['media_type'] ?? '', $sent['tool_choice']['name'] ?? '']);
sp_eq('Entwurf: 200 + 92 Plätze, Abgleich mit 300, Hinweis zur Genauigkeit, nicht gespeichert', [200, 292, -8, true, 2, 0.7],
    [$st, $d['stats']['bookable'] ?? 0, $d['check']['diff'] ?? null, strpos((string) ($d['accuracy_note'] ?? ''), '80–90 %') !== false, count($d['draft']['data']['sections'] ?? []), $d['ai']['confidence'] ?? null]);
$fs = $d['draft']['data']['sections'][0]['rows'][0]['seats'][0] ?? ['x' => 0, 'y' => 0];
$raw0 = TIX_Seatmap_Geometry::block_rows('x', ['origin' => ['x' => 500, 'y' => 150], 'rows' => 10, 'seats' => 20, 'curve' => 0.2])[0][0]['seats'][0];
sp_eq('Überlagerung: Planpunkt − shift = Bildrahmen', [round($raw0['x'], 1), round($raw0['y'], 1)], [round($fs['x'] - ($d['overlay']['shift']['x'] ?? 0), 1), round($fs['y'] - ($d['overlay']['shift']['y'] ?? 0), 1)]);
[$st, $d, $c] = sp_rest('POST', '/studio/seatmaps/from-image', ['image' => base64_encode('kein bild'), 'media_type' => 'image/png']);
sp_eq('Kein Bild → 415', [415, 'tix_image'], [$st, $c]);
// Audit 13.11. (1.38.797): Tokens, Zeitlimit, echte Fehlerursachen
sp_eq('KI-Anfrage: max_tokens 16000', 16000, intval($sent['max_tokens'] ?? 0));
$GLOBALS['sp_case'] = null;
TIX_AI_Provider::$http = function ($url, $args) {
    $GLOBALS['sp_to'] = $args['timeout'] ?? null;
    $c = $GLOBALS['sp_case'];
    if ($c === 'timeout') return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 150001 milliseconds');
    if ($c === 'cut') return ['headers' => [], 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null,
        'body' => wp_json_encode(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'max_tokens', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'saalplan', 'input' => ['sections' => [['label' => 'Parkett']]]]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 16000]])];
    $map = ['key' => [401, 'authentication_error', 'invalid x-api-key'], 'busy' => [529, 'overloaded_error', 'Overloaded'], 'rate' => [429, 'rate_limit_error', 'rate limit'], 'img' => [400, 'invalid_request_error', 'Could not process image'], 'other' => [500, 'api_error', 'Internal']][$c];
    return ['headers' => [], 'response' => ['code' => $map[0], 'message' => ''], 'cookies' => [], 'filename' => null, 'body' => wp_json_encode(['type' => 'error', 'error' => ['type' => $map[1], 'message' => $map[2]]])];
};
$got = [];
foreach (['cut' => null, 'timeout' => null, 'key' => null, 'busy' => null, 'rate' => null, 'img' => null, 'other' => null] as $case => $_) {
    $GLOBALS['sp_case'] = $case;
    [$st, $d, $c] = sp_rest('POST', '/studio/seatmaps/from-image', ['image' => $png]);
    $got[$case] = [$st, $c];
}
sp_eq('Zeitlimit 150 s an die KI', 150, intval($GLOBALS['sp_to'] ?? 0));
// Auftrag statt langer Anfrage (1.38.798, Cloudflare 100 s)
add_filter('tix_seatmap_job_defer', '__return_true');
$GLOBALS['sp_case'] = 'other';
delete_option(TIX_Seating::OPT_AI_USAGE);
[$st, $d] = sp_rest('POST', '/studio/seatmaps/from-image/jobs', ['image' => $png, 'total_seats' => 300]);
$jid = (string) ($d['job_id'] ?? '');
sp_eq('Auftrag: 202 sofort, job_id, queued, poll_after', [202, true, 'queued', 3], [$st, strlen($jid) >= 16, $d['status'] ?? '', $d['poll_after'] ?? 0]);
TIX_Seating::run_job($jid);
[$st, $d, $c] = sp_rest('GET', "/studio/seatmaps/from-image/jobs/$jid");
sp_eq('Auftrag fehlgeschlagen: Code und Status der Ursache bleiben', [200, 'failed', 'ai_failed', 502], [$st, $d['status'] ?? '', $d['error']['code'] ?? '', $d['error']['status'] ?? 0]);
$GLOBALS['sp_case'] = 'key';
[, $d] = sp_rest('POST', '/studio/seatmaps/from-image', ['image' => $png, 'async' => 1]);
$jid2 = (string) ($d['job_id'] ?? '');
TIX_Seating::run_job($jid2);
sp_eq('async=1 an der alten Route: Auftrag; Schlüssel ungültig → ai_key_invalid', ['failed', 'ai_key_invalid'], [sp_rest('GET', "/studio/seatmaps/from-image/jobs/$jid2")[1]['status'] ?? '', sp_rest('GET', "/studio/seatmaps/from-image/jobs/$jid2")[1]['error']['code'] ?? '']);
TIX_AI_Provider::$http = function ($url, $args) {
    $tool = ['frame' => ['width' => 1000, 'height' => 700], 'layout' => 'theater', 'confidence' => 0.8, 'sections' => [['label' => 'Parkett', 'block' => ['origin' => ['x' => 500, 'y' => 150], 'rows' => 5, 'seats' => 10]]], 'objects' => []];
    return ['headers' => [], 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null,
        'body' => wp_json_encode(['model' => 'claude-sonnet-5-5', 'stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'saalplan', 'input' => $tool]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]])];
};
[, $d] = sp_rest('POST', '/studio/seatmaps/from-image/jobs', ['image' => $png, 'total_seats' => 50]);
$jid3 = (string) ($d['job_id'] ?? '');
[, $q] = sp_rest('GET', "/studio/seatmaps/from-image/jobs/$jid3");
TIX_Seating::run_job($jid3);
TIX_Seating::run_job($jid3); // zweiter Lauf: nichts
[$st, $d] = sp_rest('GET', "/studio/seatmaps/from-image/jobs/$jid3");
sp_eq('Auftrag fertig: Ergebnis wie die direkte Route (50 Plätze, Abgleich), Bild-Zwischenspeicher gelöscht', ['queued', 200, 'done', 50, 0, false],
    [$q['status'] ?? '', $st, $d['status'] ?? '', $d['result']['stats']['bookable'] ?? 0, $d['result']['check']['diff'] ?? null, file_exists(wp_upload_dir()['basedir'] . '/tix-ai-jobs/' . $jid3 . '.bin')]);
$old_u = get_current_user_id();
$stranger = wp_insert_user(['user_login' => "sp_fremd_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "sp_fremd_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $stranger;
if ($multi) { $og = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'SP Fremd-Org', 'meta_input' => ['_tix_org_user_id' => $stranger]]); $posts[] = $og; TIX_Plan::save_org($og, ['seating' => true]); }
wp_set_current_user($stranger);
sp_eq('Fremder Nutzer sieht den Auftrag nicht', true, in_array(sp_rest('GET', "/studio/seatmaps/from-image/jobs/$jid3")[0], [403, 404], true));
wp_set_current_user($old_u);
$k = 'tix_smjob_' . $jid3; $jj = get_transient($k); $jj['status'] = 'running'; $jj['started'] = time() - 600; set_transient($k, $jj, 3600);
sp_eq('Hängt der Lauf: ai_timeout', ['failed', 'ai_timeout'], [sp_rest('GET', "/studio/seatmaps/from-image/jobs/$jid3")[1]['status'] ?? '', sp_rest('GET', "/studio/seatmaps/from-image/jobs/$jid3")[1]['error']['code'] ?? '']);
remove_filter('tix_seatmap_job_defer', '__return_true');
delete_option(TIX_Seating::OPT_AI_USAGE);
sp_eq('Fehlerursachen als eigene Codes (nie 401/403)', ['cut' => [422, 'ai_too_large'], 'timeout' => [504, 'ai_timeout'], 'key' => [502, 'ai_key_invalid'], 'busy' => [503, 'ai_overloaded'], 'rate' => [429, 'ai_rate_limited'], 'img' => [422, 'ai_image_rejected'], 'other' => [502, 'ai_failed']], $got);
delete_option(TIX_Seating::OPT_AI_USAGE);
update_option(TIX_Seating::OPT_AI_USAGE, [current_time('Y-m-d') => [$multi ? $org : 0 => TIX_Seating::AI_PER_DAY]], false);
[$st, $d, $c] = sp_rest('POST', '/studio/seatmaps/from-image', ['image' => $png]);
sp_eq('Tageslimit → 429', [429, 'tix_rate_limit'], [$st, $c]);
TIX_AI_Provider::$http = null;

// ═══ 8. Modul-Sperre, Test, Bestandsschutz ═══
TIX_Public_Events::flush();
if ($multi) {
    $ev3 = $mkev("Neu $sfx", $org3);
    sp_eq('Neuer Veranstalter: Modul aus', [false, false], [TIX_Seating::org_access($org3), TIX_Seatmap::module_on($ev3)]);
    $u3 = wp_insert_user(['user_login' => "sp_neu_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "sp_neu_$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $u3;
    update_post_meta($org3, '_tix_org_user_id', $u3);
    wp_set_current_user($u3);
    [$st, $d, $c] = sp_rest('GET', '/studio/seatmaps');
    sp_eq('Studio ohne Modul → 403 tix_module_off', [403, 'tix_module_off'], [$st, $c]);
    [$st, $d] = sp_rest('GET', '/studio/seating');
    sp_eq('Stand: aus, Test verfügbar', [false, true], [$d['module']['on'] ?? null, $d['module']['trial_available'] ?? null]);
    [$st, $d] = sp_rest('POST', '/studio/seating/trial');
    sp_eq('Test gestartet: an, 30 Tage', [200, true, true], [$st, $d['module']['on'] ?? null, $d['module']['trial'] ?? null]);
    [$st, $d, $c] = sp_rest('POST', '/studio/seating/trial');
    sp_eq('Zweiter Test → 409', 409, $st);
    // Bestandsschutz: Veranstalter mit zugewiesenem Plan, ohne Schalter
    $raw = json_decode((string) get_post_meta($org2, TIX_Plan::META, true), true); unset($raw['seating']); update_post_meta($org2, TIX_Plan::META, wp_json_encode($raw));
    delete_post_meta($org2, TIX_Seating::META_LEGACY);
    $evB2 = $mkev("Bestand $sfx", $org2);
    TIX_Seatmap::$allow_assign = true; update_post_meta($evB2, '_tix_seatmap_id', $smB); TIX_Seatmap::$allow_assign = false; // Bestand von vor dem Update
    delete_post_meta($org2, TIX_Seating::META_LEGACY);
    sp_eq('Bestandsschutz: Veranstalter mit Plan bleibt an', true, TIX_Seating::org_access($org2));
    TIX_Plan::save_org($org2, ['seating' => false]);
    sp_eq('Im Plattform-Studio ausgeschaltet → aus trotz Bestand', false, TIX_Seating::org_access($org2));
    $lockEv = $evB2; $lockSm = $smB;
    wp_set_current_user($multi ? $owner : $admin);
} else {
    update_option(TIX_Seating::OPT_BOOKED, '0', false); update_option(TIX_Seating::OPT_LEGACY, '0', false); delete_option(TIX_Seating::OPT_TRIAL);
    sp_eq('Einzel-Seite ohne Buchung/Test/Bestand: aus', [false, false], [TIX_Seating::site_access(), TIX_Plan::site_on('seating')]);
    [$st, $d] = sp_rest('GET', '/studio/seating');
    sp_eq('Stand: aus, Test verfügbar', [false, true], [$d['module']['on'] ?? null, $d['module']['trial_available'] ?? null]);
    $lockEv = $ev; $lockSm = $sm;
}
wp_set_current_user(0);
[$st, $d, $c] = sp_rest('GET', "/public/events/$lockEv/seatmap");
sp_eq('Gast: Saalplan ohne Modul → 403 tix_module_disabled', [403, 'tix_module_disabled'], [$st, $c]);
[$st, $d, $c] = sp_rest('POST', '/customer/seats/hold', ['event_id' => $lockEv, 'seat_ids' => ['parkett_A_5']]);
sp_eq('Gast: halten ohne Modul → 403', 403, $st);
sp_eq('Web-Kasse ohne Modul: klare Meldung', 'Die Platzwahl ist für dieses Event nicht verfügbar.', TIX_Native_Checkout::seat_line($lockEv, ['parkett_A_5'], 'web_Z'));
TIX_Public_Events::flush();
$pl = TIX_Public_Events::payload($lockEv, true);
sp_eq('Event-Antwort: seatmap false; Shortcode leer', [false, ''], [$pl['seatmap'] ?? null, TIX_Seatmap::render_shortcode(['event' => $lockEv])]);
if (!$multi) {
    $ev4 = $mkev("Zuweisen $sfx", 0);
    $a = TIX_Seatmap::assign($ev4, $sm);
    sp_eq('Zuweisen ohne Modul → tix_module_off', 'tix_module_off', is_wp_error($a) ? $a->get_error_code() : '');
    wp_set_current_user($admin);
    [$st, $d] = sp_rest('POST', '/studio/seating/trial');
    sp_eq('Test gestartet: an, Gast-Route wieder offen', [200, true, 200], [$st, $d['module']['on'] ?? null, sp_rest('GET', "/public/events/$lockEv/seatmap")[0]]);
    [$st] = sp_rest('POST', '/studio/seating/trial');
    sp_eq('Zweiter Test → 409', 409, $st);
    delete_option(TIX_Seating::OPT_TRIAL); delete_option(TIX_Seating::OPT_LEGACY);
    sp_eq('Bestandsschutz: Events mit Plan → an (Merker gesetzt)', [true, '1'], [TIX_Seating::site_access(), get_option(TIX_Seating::OPT_LEGACY)]);
    [$st, $d] = sp_rest('POST', '/studio/seating/module', ['on' => false]);
    sp_eq('Admin schaltet ab → aus (Bestandsschutz endet)', [200, false], [$st, $d['module']['on'] ?? null]);
}

// Aufräumen
wp_set_current_user($admin);
remove_all_filters('pre_wp_mail');
foreach (array_unique(array_filter($posts)) as $p) { $wpdb->delete($T, ['event_id' => $p]); wp_delete_post($p, true); }
foreach ($orders as $o) { $wpdb->delete($wpdb->prefix . 'tix_order_items', ['order_id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_orders', ['id' => $o]); $wpdb->delete($wpdb->prefix . 'tix_order_notes', ['order_id' => $o]); delete_option('tix_seat_conflicts_' . $o); }
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
