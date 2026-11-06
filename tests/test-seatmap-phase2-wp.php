<?php
/** Saalplan Phase 2 (1.38.671): Bestplatz-Logik, Einstellungen, Modus pick|best|both, Sektion → Kategorie, Studio-Felder, Bild → Saalplan.
 *  wp eval-file …/tests/test-seatmap-phase2-wp.php (beide Modi). */
if (!defined('ABSPATH') || !class_exists('TIX_Seatmap_Best')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function s2_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function s2_rest($m, $r, array $b = [], array $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
echo $multi ? "── Mehr-Veranstalter-Modus\n" : "── Einzel-Seite\n";
$T = $wpdb->prefix . TIX_Seatmap::TABLE_SUFFIX;
TIX_Seatmap::maybe_upgrade();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_seat\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%tix\\_app\\_rl\\_seat\\_%'");
$keep = [];
foreach ([TIX_Seating::OPT_BOOKED, TIX_Seating::OPT_TRIAL, TIX_Seating::OPT_LEGACY, TIX_Seating::OPT_AI_USAGE, TIX_Seating::OPT_SETTINGS, TIX_AI_Provider::OPT, TIX_AI_Provider::OPT_USAGE] as $o) $keep[$o] = get_option($o, null);
$sfx = wp_rand(1000, 9999); $users = []; $posts = [];
$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$owner = wp_insert_user(['user_login' => "s2_owner_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "s2_owner_$sfx@example.test", 'role' => $multi ? 'tix_organizer' : 'administrator']); $users[] = $owner;
$org = 0;
if ($multi) {
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'S2 Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    TIX_Plan::save_org($org, ['seating' => true]);
} else {
    update_option(TIX_Seating::OPT_BOOKED, '1', false);
}
delete_option(TIX_Seating::OPT_SETTINGS);
$ids = function ($r) { return $r['seats']; };
$plan = function ($sections, $layout = 'theater', $extra = []) { return TIX_Seatmap::sanitize_seatmap(['layout' => $layout, 'sections' => $sections] + $extra); };

// ═══ 1. Punktwert: Mitte und „beste Reihe“ je Vorlage ═══
$theater = $plan([['id' => 'parkett', 'label' => 'Parkett', 'price' => 30, 'block' => ['rows' => 12, 'seats' => 20]]], 'theater');
$r = TIX_Seatmap_Best::find($theater, ['qty' => 2]);
s2_eq('Theater: 2 Plätze in der Mitte der Reihe D (erstes Drittel, nicht Reihe 1)', [['parkett_D_10', 'parkett_D_11'], false, ['Reihe D']], [$r['seats'], $r['split'], $r['rows']]);
$club = $plan([['id' => 'parkett', 'label' => 'Parkett', 'price' => 30, 'block' => ['rows' => 12, 'seats' => 20]]], 'club');
s2_eq('Club: vorn in der Mitte (Reihe A)', ['parkett_A_10', 'parkett_A_11'], TIX_Seatmap_Best::find($club, ['qty' => 2])['seats']);
$own = $plan([['id' => 'parkett', 'label' => 'Parkett', 'price' => 30, 'block' => ['rows' => 12, 'seats' => 20]]], 'theater', ['best_row' => 1]);
s2_eq('Eigene beste Reihe (best_row 1 = hinten)', ['parkett_L_10', 'parkett_L_11'], TIX_Seatmap_Best::find($own, ['qty' => 2])['seats']);
$tpl = TIX_Seating::templates();
$ar = TIX_Seatmap_Best::find($tpl['arena']['data'], ['qty' => 2, 'sections' => ['sued']]);
$front = $tpl['arena']['data']['sections'][1]['rows'][0];
s2_eq('Arena: Tribüne Süd vorn Mitte (Achse je Tribüne)', [['Reihe ' . $front['id']], true], [$ar['rows'], in_array($ar['seats'][0], ['sued_A_9', 'sued_A_10'], true)]);
$th = TIX_Seatmap_Best::find($tpl['theater']['data'], ['qty' => 3]);
s2_eq('Vorlage Theater: Rang hat Priorität „zuletzt“, gesucht wird im Parkett', ['last', 'parkett'], [$tpl['theater']['data']['sections'][1]['priority'] ?? '', $th['section_id']]);
$taken_p = []; foreach ($tpl['theater']['data']['sections'][0]['rows'] as $row) foreach ($row['seats'] as $s) $taken_p[] = $s['id'];
s2_eq('Parkett voll → Rang', 'rang', TIX_Seatmap_Best::find($tpl['theater']['data'], ['qty' => 3, 'taken' => $taken_p])['section_id']);

$bb = TIX_Seatmap_Geometry::bounds(['sections' => $theater['sections']]);
$stg = $theater['objects'][0];
$tb = $tpl['banquet']['data']; $t0 = $tb['sections'][0]['tables'][0]; $s0 = $tb['sections'][0]['rows'][0]['seats'];
s2_eq('Normalisieren verschiebt Bühne und Tische mit (Bühne mittig über den Plätzen, Tisch zwischen seinen Plätzen)', [true, true],
    [abs($stg['x'] - ($bb[0] + $bb[2]) / 2) < 1 && $stg['y'] < $bb[1], abs($t0['x'] - array_sum(array_column($s0, 'x')) / count($s0)) < 2]);

// ═══ 2. Single-Seat-Regel, Gang, Rollstuhl ═══
$one = $plan([['id' => 'r', 'label' => 'Reihe', 'price' => 10, 'block' => ['rows' => 1, 'seats' => 20]]]);
$taken = []; foreach (array_merge(range(1, 8), range(14, 20)) as $n) $taken[] = "r_A_$n"; // frei: 9–13
s2_eq('Keine Einzellücke: frei 9–13, 2 Plätze → 9+10 (nicht 10+11, das ließe Platz 9 allein)', ['r_A_9', 'r_A_10'], TIX_Seatmap_Best::find($one, ['qty' => 2, 'taken' => $taken])['seats']);
s2_eq('Ohne Regel: genau die Mitte 10+11', ['r_A_10', 'r_A_11'], TIX_Seatmap_Best::find($one, ['qty' => 2, 'taken' => $taken, 'single_seat_rule' => false])['seats']);
$aisle = $plan([['id' => 'g', 'label' => 'Gang', 'price' => 10, 'block' => ['rows' => 1, 'seats' => 20, 'gaps' => [['after' => 10, 'width' => 2]]]]]);
$tk = []; foreach (range(1, 20) as $n) if ($n !== 10 && $n !== 11) $tk[] = "g_A_$n";
$ga = TIX_Seatmap_Best::find($aisle, ['qty' => 2, 'taken' => $tk]);
s2_eq('Gang trennt: 10 und 11 liegen nicht nebeneinander → kein Vorschlag mit Hinweis', [[], 'none', true], [$ga['seats'], $ga['reason'], $ga['message'] !== '']);
$wc = $plan([['id' => 'w', 'label' => 'W', 'price' => 10, 'block' => ['rows' => 1, 'seats' => 10, 'types' => ['w_A_5' => 'wheelchair', 'w_A_6' => 'wheelchair']]]]);
$w1 = TIX_Seatmap_Best::find($wc, ['qty' => 1]);
$w2 = TIX_Seatmap_Best::find($wc, ['qty' => 1, 'wheelchair' => true]);
s2_eq('Rollstuhlplätze nur, wenn ausdrücklich gewählt (dann mit Rollstuhlplatz)', [false, true], [in_array($w1['seats'][0], ['w_A_5', 'w_A_6'], true), in_array($w2['seats'][0], ['w_A_5', 'w_A_6'], true)]);

// ═══ 3. Zwei Reihen hintereinander, nie verstreut ═══
$two = $plan([['id' => 'z', 'label' => 'Z', 'price' => 10, 'block' => ['rows' => 2, 'seats' => 10]]]);
$tk = []; foreach (['A', 'B'] as $rl) foreach (range(1, 10) as $n) if ($n !== 5 && $n !== 6) $tk[] = "z_{$rl}_$n";
$sp = TIX_Seatmap_Best::find($two, ['qty' => 4, 'taken' => $tk]);
s2_eq('4 Plätze: nebeneinander nirgends → 2 + 2 übereinander, mit Hinweis', [['z_A_5', 'z_A_6', 'z_B_5', 'z_B_6'], true, ['Reihe A', 'Reihe B'], true],
    [$sp['seats'], $sp['split'], $sp['rows'], strpos($sp['message'], 'hintereinander') !== false]);
$tk2 = $tk; $tk2 = array_diff($tk2, ['z_B_1', 'z_B_2']); $tk2[] = 'z_B_5'; $tk2[] = 'z_B_6';
s2_eq('Nicht übereinander (A 5–6, B 1–2) → kein Vorschlag statt verstreut', [], TIX_Seatmap_Best::find($two, ['qty' => 4, 'taken' => array_values($tk2)])['seats']);
s2_eq('Ohne split_rows → kein Vorschlag', [], TIX_Seatmap_Best::find($two, ['qty' => 4, 'taken' => $tk, 'split_rows' => false])['seats']);

// ═══ 4. Deterministisch, andere Vorschläge ═══
$a0 = TIX_Seatmap_Best::find($theater, ['qty' => 2]);
$a1 = TIX_Seatmap_Best::find($theater, ['qty' => 2, 'attempt' => 1]);
$a2 = TIX_Seatmap_Best::find($theater, ['qty' => 2, 'attempt' => 2]);
s2_eq('Gleiche Eingabe → gleiches Ergebnis; „anderer Vorschlag“ ohne gemeinsame Plätze, schlechter oder gleich', [true, [], [], true, true],
    [$a0 === TIX_Seatmap_Best::find($theater, ['qty' => 2]), array_values(array_intersect($a0['seats'], $a1['seats'])), array_values(array_intersect($a1['seats'], $a2['seats'])), $a1['score'] <= $a0['score'], $a0['alternatives'] === $a1['alternatives'] + 1]);
$tkr = []; foreach (range(1, 20) as $n) if ($n < 9 || $n > 12) $tkr[] = "r_A_$n";
$last = TIX_Seatmap_Best::find($one, ['qty' => 4, 'taken' => $tkr, 'attempt' => 1]);
s2_eq('Keine weiteren Vorschläge → reason no_more', ['no_more', []], [$last['reason'], $last['seats']]);

// ═══ 5. Event: Modus, Sektion → Kategorie, Einstellungen ═══
wp_set_current_user($multi ? $owner : $admin);
$sm = wp_insert_post(['post_type' => 'tix_seatmap', 'post_status' => 'publish', 'post_title' => "S2 Plan $sfx"]); $posts[] = $sm;
if ($multi) update_post_meta($sm, TIX_Seatmap::META_ORG, $org);
TIX_Seatmap::store($sm, ['layout' => 'theater', 'sections' => [
    ['id' => 'links', 'label' => 'Parkett links', 'price' => 20, 'block' => ['rows' => 8, 'seats' => 10, 'origin' => ['x' => -200, 'y' => 100]]],
    ['id' => 'rechts', 'label' => 'Parkett rechts', 'price' => 20, 'block' => ['rows' => 8, 'seats' => 10, 'origin' => ['x' => 200, 'y' => 100]]],
    ['id' => 'rang', 'label' => 'Rang', 'price' => 15, 'priority' => 'last', 'block' => ['rows' => 4, 'seats' => 24, 'origin' => ['x' => 0, 'y' => 500]]],
]]);
$ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "S2 Event $sfx", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS),
    '_tix_ticket_categories' => [['name' => 'Parkett', 'price' => 39, 'qty' => 160], ['name' => 'Rang', 'price' => 25, 'qty' => 96]]]]); $posts[] = $ev;
[$st, $d] = s2_rest('POST', "/studio/events/$ev/seatmap", ['seatmap_id' => $sm, 'mode' => 'both', 'section_categories' => ['links' => 0, 'rechts' => 0, 'rang' => 1]]);
$secs = array_column((array) ($d['sections'] ?? []), null, 'id');
s2_eq('Zuweisen mit Modus both und Sektion → Kategorie: Name, Preis und Farbe aus der Kategorie', [200, 'both', ['links' => 0, 'rechts' => 0, 'rang' => 1], 'Parkett', 39.0, 'category', TIX_Seatmap::PALETTE[1], 2],
    [$st, $d['mode'] ?? '', (array) ($d['section_categories'] ?? []), $secs['links']['category_name'] ?? '', floatval($secs['rechts']['price'] ?? 0), $secs['links']['color_source'] ?? '', $secs['rang']['color'] ?? '', count((array) ($d['categories'] ?? []))]);
[$st, $d] = s2_rest('POST', "/studio/events/$ev/seatmap", ['section_colors' => ['rang' => '#123456']]);
$secs = array_column((array) ($d['sections'] ?? []), null, 'id');
s2_eq('Farbe änderbar (section_colors); ohne seatmap_id bleibt der Plan', [200, $sm, '#123456', 'event'], [$st, intval($d['seatmap_id'] ?? 0), $secs['rang']['color'] ?? '', $secs['rang']['color_source'] ?? '']);
[$st, , $c] = s2_rest('POST', "/studio/events/$ev/seatmap", ['section_categories' => ['gibtsnicht' => 0]]);
[$st2, , $c2] = s2_rest('POST', "/studio/events/$ev/seatmap", ['mode' => 'random']);
s2_eq('Unbekannter Bereich bzw. Modus → 400', [400, 400], [$st, $st2]);
$line = TIX_Native_Checkout::seat_line($ev, ['rang_A_12'], 'web_s2');
s2_eq('Kasse: Zeile mit Kategorie-Index und Preis aus der Kategorie', [1, 25.0, 'Rang'], [is_array($line) ? $line['cat_index'] : $line, is_array($line) ? $line['price'] : null, is_array($line) ? $line['name'] : null]);
TIX_Seatmap::release($ev, ['rang_A_12'], 'web_s2');
[$st, $d] = s2_rest('POST', '/studio/seating/settings', ['hold_minutes' => 8, 'best' => ['split_rows' => false]], $multi ? ['organizer' => $org] : []);
s2_eq('Einstellungen: Haltezeit 8 min, Verteilen aus, Vorgaben für beste Reihe', [200, 8, false, true, 0.3], [$st, $d['settings']['hold_minutes'] ?? 0, $d['settings']['best']['split_rows'] ?? null, $d['settings']['best']['single_seat_rule'] ?? null, $d['defaults']['best_row']['theater'] ?? null]);
[, $d] = s2_rest('GET', '/studio/seating', [], $multi ? ['organizer' => $org] : []);
s2_eq('Modul-Stand zeigt Einstellungen und Haltezeit', [8, 8], [$d['module']['settings']['hold_minutes'] ?? 0, $d['module']['hold']['minutes'] ?? 0]);
[$st, , $c] = s2_rest('POST', '/studio/seating/settings', ['hold_minutes' => 99], $multi ? ['organizer' => $org] : []);
s2_eq('Haltezeit wird auf 5–30 begrenzt', 30, TIX_Seating::settings($org)['hold_minutes']);
s2_rest('POST', '/studio/seating/settings', ['hold_minutes' => 8], $multi ? ['organizer' => $org] : []);

// ═══ 6. Gast: Bestplatz je Kategorie, anderer Vorschlag, Vorschau ═══
TIX_Public_Events::flush();
wp_set_current_user(0);
[$st, $d] = s2_rest('GET', "/public/events/$ev/seatmap");
s2_eq('Öffentlich: pick + best_available, mode für alte Apps manual, hold_seconds aus den Einstellungen, Kategorie je Bereich', [200, true, true, 'manual', 480, 0],
    [$st, $d['pick'] ?? null, $d['best_available'] ?? null, $d['mode'] ?? '', $d['hold_seconds'] ?? 0, array_column((array) ($d['sections'] ?? []), 'category_index', 'id')['links'] ?? null]);
[$st, $d] = s2_rest('POST', '/customer/seats/best', ['event_id' => $ev, 'category_index' => 0, 'qty' => 2]);
$tok = (string) ($d['hold_token'] ?? '');
$s1 = (array) ($d['seats'] ?? []);
$in_parkett = !array_filter($s1, function ($x) { return strpos($x, 'rang_') === 0; });
s2_eq('Bestplatz je Kategorie: 2 Plätze im Parkett gehalten, Rang nicht', [200, 2, true, 2, true, 0],
    [$st, count($s1), $in_parkett, count(TIX_Seatmap::held_by($ev, TIX_Seatmap::app_holder($tok))), ($d['hold_seconds'] ?? 0) > 400 && ($d['hold_seconds'] ?? 0) <= 480, $d['attempt'] ?? -1]);
[$st, $d] = s2_rest('POST', '/customer/seats/best', ['event_id' => $ev, 'category_index' => 0, 'qty' => 2, 'attempt' => 1, 'replace' => true, 'hold_token' => $tok]);
$held = TIX_Seatmap::held_by($ev, TIX_Seatmap::app_holder($tok));
s2_eq('Anderer Vorschlag: andere Plätze, alte freigegeben', [200, [], 2], [$st, array_values(array_intersect($s1, (array) ($d['seats'] ?? []))), count($held)]);
[$st, $d] = s2_rest('POST', '/customer/seats/best', ['event_id' => $ev, 'category_index' => 1, 'qty' => 3, 'preview' => true, 'hold_token' => $tok]);
s2_eq('Vorschau: Rang, nichts zusätzlich gehalten', [200, true, 3, 'rang', 2], [$st, $d['preview'] ?? null, count((array) ($d['seats'] ?? [])), $d['section_id'] ?? '', count(TIX_Seatmap::held_by($ev, TIX_Seatmap::app_holder($tok)))]);
[$st, $d, $c] = s2_rest('POST', '/customer/seats/best', ['event_id' => $ev, 'category_index' => 7, 'qty' => 2]);
s2_eq('Kategorie ohne Bereich → 409 mit Text', [409, 'tix_no_seats', true], [$st, $c, ($d['message'] ?? '') !== '']);
TIX_Seatmap::release($ev, $held, TIX_Seatmap::app_holder($tok));

// ═══ 7. Studio: Vorschlag ausprobieren, Liste used_by ═══
wp_set_current_user($multi ? $owner : $admin);
[$st, $d] = s2_rest('POST', "/studio/events/$ev/seatmap/best", ['qty' => 4, 'section_id' => 'rang']);
s2_eq('Studio-Vorschau: 4 Plätze im Rang, hält nichts', [200, 4, 'rang', 0], [$st, count((array) ($d['seats'] ?? [])), $d['section_id'] ?? '', count(TIX_Seatmap::taken($ev, $sm))]);
[$st, $d] = s2_rest('GET', '/studio/seatmaps', [], $multi ? ['organizer' => $org] : []);
$mine = array_column((array) ($d['seatmaps'] ?? []), null, 'id')[$sm] ?? [];
s2_eq('Liste: used_by mit dem Event, used_count 1', [1, $ev], [intval($mine['used_count'] ?? 0), intval($mine['used_by'][0]['id'] ?? 0)]);
[$st, $d] = s2_rest('GET', "/studio/seatmaps/$sm", [], $multi ? ['organizer' => $org] : []);
s2_eq('Plan speichert Priorität je Bereich', 'last', array_column((array) ($d['data']['sections'] ?? []), 'priority', 'id')['rang'] ?? '');

// ═══ 7b. Abgleich mit dem Studio: Kategorie per Name, POST /studio/seating, Anlegen ═══
$smN = wp_insert_post(['post_type' => 'tix_seatmap', 'post_status' => 'publish', 'post_title' => "S2 Namen $sfx"]); $posts[] = $smN;
if ($multi) update_post_meta($smN, TIX_Seatmap::META_ORG, $org);
TIX_Seatmap::store($smN, ['layout' => 'theater', 'sections' => [
    ['id' => 'vorne', 'label' => 'Vorne', 'price' => 10, 'category_name' => 'Parkett ', 'category_index' => 5, 'block' => ['rows' => 2, 'seats' => 6]],
    ['id' => 'oben', 'label' => 'Oben', 'price' => 10, 'category_name' => 'Galerie', 'category_index' => 0, 'block' => ['rows' => 2, 'seats' => 6, 'origin' => ['x' => 0, 'y' => 200]]],
]]);
$evN = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => "S2 Namen $sfx", 'meta_input' => ['_tix_organizer_id' => $org, '_tix_date_start' => wp_date('Y-m-d', time() + 9 * DAY_IN_SECONDS),
    '_tix_ticket_categories' => [['name' => 'Stehplatz', 'price' => 12], ['name' => 'parkett', 'price' => 33]]]]); $posts[] = $evN;
s2_rest('POST', "/studio/events/$evN/seatmap", ['seatmap_id' => $smN]);
[$st, $d] = s2_rest('GET', "/studio/events/$evN/seatmap");
$secs = array_column((array) ($d['sections'] ?? []), null, 'id');
s2_eq('Kategorie per Name (Index aus dem Plan nur Hinweis): „Parkett “ → Kategorie 1 „parkett“ 33 €; „Galerie“ ohne Treffer bleibt ohne Kategorie', [200, 1, 33.0, null, ['vorne' => 1]],
    [$st, $secs['vorne']['category_index'] ?? null, floatval($secs['vorne']['price'] ?? 0), $secs['oben']['category_index'] ?? null, (array) ($d['section_categories'] ?? [])]);
$lineN = TIX_Native_Checkout::seat_line($evN, ['vorne_A_3'], 'web_s2n');
s2_eq('Kasse über den Namen: Kategorie 1, 33 €', [1, 33.0], [is_array($lineN) ? $lineN['cat_index'] : $lineN, is_array($lineN) ? $lineN['price'] : null]);
TIX_Seatmap::release($evN, ['vorne_A_3'], 'web_s2n');
[$st, $d] = s2_rest('POST', '/studio/seating', ['hold_minutes' => 12], $multi ? ['organizer' => $org] : []);
s2_eq('POST /studio/seating {hold_minutes} speichert und antwortet wie GET', [200, 12, 12], [$st, $d['module']['hold']['minutes'] ?? 0, $d['module']['settings']['hold_minutes'] ?? 0]);
s2_rest('POST', '/studio/seating', ['hold_minutes' => 8], $multi ? ['organizer' => $org] : []);
[$st, $d] = s2_rest('POST', '/studio/seatmaps', ['title' => "S2 Neu $sfx", 'template' => 'club'], $multi ? ['organizer' => $org] : []);
if (!empty($d['id'])) $posts[] = intval($d['id']);
s2_eq('Anlegen: 201 mit id oben in der Antwort (data = Plan)', [201, true, true], [$st, intval($d['id'] ?? 0) > 0, is_array($d['data']['sections'] ?? null)]);
// Vorschau-Studio: Paket-Feld seatmap_template, beim Übernehmen eigener Plan
if ($multi && class_exists('TIX_Sales_Previews')) {
    $pdx = new ReflectionMethod('TIX_Sales_Previews', 'patch_event'); $pdx->setAccessible(true);
    $ev0 = ['key' => 'e_s2test', 'vid' => 1, 'title' => "S2 Paket $sfx", 'excerpt' => '', 'slug' => "s2-paket-$sfx", 'image_id' => 0, 'term_id' => 0, 'meta' => [], 'updated_at' => time()];
    $pk = ['id' => 0, 'events' => [$ev0]];
    $r1 = $pdx->invoke(null, $pk, $ev0, ['seatmap_template' => 'theater']);
    $r2 = $pdx->invoke(null, $pk, $ev0, ['seatmap_template' => 'zirkus']);
    s2_eq('Paket-Event: seatmap_template gespeichert, unbekannte Vorlage → 400', ['theater', 'tix_invalid_field'],
        [is_array($r1) ? ($r1[0]['meta'][TIX_Sales_Previews::META_SEATMAP_TPL] ?? '') : (is_wp_error($r1) ? $r1->get_error_code() : '?'), is_wp_error($r2) ? $r2->get_error_code() : 'kein Fehler']);
    $orgOff = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'S2 Ohne Modul']); $posts[] = $orgOff;
    $evOn = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => "S2 Übernahme $sfx", 'meta_input' => ['_tix_organizer_id' => $org, TIX_Sales_Previews::META_SEATMAP_TPL => 'theater']]); $posts[] = $evOn;
    $evOff = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => "S2 Übernahme aus $sfx", 'meta_input' => ['_tix_organizer_id' => $orgOff, TIX_Sales_Previews::META_SEATMAP_TPL => 'club']]); $posts[] = $evOff;
    $pOn = TIX_Sales_Previews::claim_seatmap($evOn, $org, $owner, 'theater'); $posts[] = $pOn;
    $pOff = TIX_Sales_Previews::claim_seatmap($evOff, $orgOff, $owner, 'club'); $posts[] = $pOff;
    s2_eq('Übernehmen: Plan aus der Vorlage beim Veranstalter; mit Modul zugewiesen, ohne Modul nur vorgemerkt (kein Bestandsschutz)', [true, $pOn, $org, true, 0, $pOff, false],
        [$pOn > 0, TIX_Seatmap::event_seatmap($evOn), TIX_Seatmap::org_of($pOn), $pOff > 0, TIX_Seatmap::event_seatmap($evOff), intval(get_post_meta($evOff, '_tix_seatmap_suggested', true)), TIX_Seating::legacy_org($orgOff)]);
    delete_post_meta($orgOff, TIX_Seating::META_LEGACY);
}

// ═══ 8. Bild → Saalplan: Sicherheit je Bereich, Stehflächen, Abweichungen, Bild ═══
update_option(TIX_AI_Provider::OPT, ['provider' => 'claude', 'key_enc' => TIX_Ad_Accounts::enc('sk-ant-api03-' . str_repeat('t', 40) . 'Cd34')], false);
delete_option(TIX_Seating::OPT_AI_USAGE);
TIX_AI_Provider::$http = function ($url, $args) {
    $tool = ['frame' => ['width' => 1000, 'height' => 700], 'layout' => 'theater', 'confidence' => 0.8,
        'sections' => [['label' => 'Parkett', 'confidence' => 0.9, 'block' => ['origin' => ['x' => 500, 'y' => 150], 'rows' => 10, 'seats' => 20]],
                       ['label' => 'Loge', 'confidence' => 0.4, 'block' => ['shape' => 'tables', 'origin' => ['x' => 850, 'y' => 400], 'tables' => 2, 'seats_per_table' => 4]]],
        'objects' => [['type' => 'stage', 'x' => 500, 'y' => 60, 'w' => 400, 'h' => 60], ['type' => 'standing', 'x' => 200, 'y' => 500, 'w' => 200, 'h' => 120, 'label' => 'Stehplatz', 'capacity' => 150]]];
    return ['headers' => [], 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null,
        'body' => wp_json_encode(['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'saalplan', 'input' => $tool]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]])];
};
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
[$st, $d] = s2_rest('POST', '/studio/seatmaps/from-image', ['image' => $png, 'total_seats' => 220], $multi ? ['organizer' => $org] : []);
$si = array_column((array) ($d['sections_info'] ?? []), null, 'label');
$dev = implode(' | ', (array) ($d['deviations'] ?? []));
if (!empty($d['image']['attachment_id'])) $posts[] = intval($d['image']['attachment_id']);
s2_eq('Bild → Saalplan: Bereiche mit Art, Plätzen und Sicherheit; Stehfläche; Abweichungen; Bild zur Überlagerung', [200, 'rows', 200, 0.9, 'tables', 8, 0.4, [['label' => 'Stehplatz', 'capacity' => 150]], true, true, true, 1],
    [$st, $si['Parkett']['kind'] ?? '', $si['Parkett']['seats'] ?? 0, $si['Parkett']['confidence'] ?? null, $si['Loge']['kind'] ?? '', $si['Loge']['seats'] ?? 0, $si['Loge']['confidence'] ?? null,
        $d['standing'] ?? null, strpos($dev, 'Loge') !== false, strpos($dev, '208 Plätze erkannt, genannt sind 220') !== false, strpos((string) ($d['image']['url'] ?? ''), 'saalplan-vorlage') !== false, intval($d['image']['width'] ?? 0)]);
TIX_AI_Provider::$http = null;

// Aufräumen
wp_set_current_user($admin);
foreach (array_unique(array_filter($posts)) as $p) { $wpdb->delete($T, ['event_id' => $p]); if (get_post_type($p) === 'attachment') wp_delete_attachment($p, true); else wp_delete_post($p, true); }
if ($multi && $org) delete_post_meta($org, TIX_Seating::META_SETTINGS);
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (array_unique(array_filter($users)) as $u) wp_delete_user($u);
foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
TIX_Public_Events::flush();
echo ($GLOBALS['t_fails'] ? 'FEHLER' : 'GRÜN') . " {$GLOBALS['t_count']} Prüfungen, {$GLOBALS['t_fails']} Fehler\n";
