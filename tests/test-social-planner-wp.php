<?php
/**
 * M3 Social-Planer Stufe 1 (1.38.811): Plan aus Event-Daten, Termine wandern mit, Erinnerung (Mail/Push), eigene Posts.
 *   wp eval-file …/test-social-planner-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Social_Planner')) { echo "Tixomat ab 1.38.811 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
TIX_Social_Planner::maybe_install();
$posts = []; $mails = [];
$catch = function ($r, $a) use (&$mails) { if ($r !== null) return $r; $mails[] = $a; return true; };
add_filter('pre_wp_mail', $catch, 1, 2);
try {
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $date = wp_date('Y-m-d', time() + 40 * DAY_IN_SECONDS);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Planer-Event', 'meta_input' => ['_tix_date_start' => $date, '_tix_time_start' => '21:00', '_tix_location' => 'Halle',
        '_tix_presale_start' => wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS),
        '_tix_ticket_categories' => [['name' => 'Normal', 'price' => 10, 'qty' => 100, 'phases' => [['name' => 'Early Bird', 'price' => 8, 'until' => wp_date('Y-m-d', time() + 10 * DAY_IN_SECONDS)]]]]]]); $posts[] = $ev;
    [$st, $d] = $rest('GET', "/events/$ev/social-plan");
    $eq('Vorgabe aus, kein Plan', [200, false, 0], [$st, $d['enabled'] ?? null, count($d['posts'] ?? [])]);
    [$st, $d] = $rest('POST', "/events/$ev/social-plan", ['enabled' => true]);
    $occ = array_column((array) ($d['posts'] ?? []), 'occasion');
    $eq('Einschalten: Ankündigung, VVK-Start, Preisphase, Tag vorher, heute, Danke', [200, true, ['announce', 'presale_start', 'price_phase_end', 'day_before', 'today', 'thanks']],
        [$st, $d['enabled'] ?? null, array_values(array_intersect(['announce', 'presale_start', 'price_phase_end', 'day_before', 'today', 'thanks'], $occ))]);
    $by = array_column((array) $d['posts'], null, 'occasion');
    $eq('Termine: heute 12 Uhr am Event-Tag, Tag vorher 18 Uhr, Danke am Folgetag', [$date . ' 12:00', wp_date('Y-m-d', strtotime($date . ' 12:00') - DAY_IN_SECONDS) . ' 18:00', wp_date('Y-m-d', strtotime($date . ' 12:00') + DAY_IN_SECONDS) . ' 13:00'],
        [wp_date('Y-m-d H:i', strtotime($by['today']['due_at'])), wp_date('Y-m-d H:i', strtotime($by['day_before']['due_at'])), wp_date('Y-m-d H:i', strtotime($by['thanks']['due_at']))]);
    $eq('Text mit Titel und Link, Bild-/Link-Felder', [true, true], [strpos($by['announce']['text'], 'Planer-Event') !== false, strpos($by['announce']['text'], 'http') !== false]);
    // eigener Text bleibt, Termine wandern mit dem Event
    $rest('POST', "/events/$ev/social-plan/posts/{$by['today']['id']}", ['text' => 'Mein eigener Text']);
    $new = wp_date('Y-m-d', time() + 50 * DAY_IN_SECONDS);
    update_post_meta($ev, '_tix_date_start', $new); wp_update_post(['ID' => $ev, 'post_title' => 'Planer-Event']); // save_post_event → Abgleich
    [, $d] = $rest('GET', "/events/$ev/social-plan");
    $by2 = array_column((array) $d['posts'], null, 'occasion');
    $eq('Event verschoben: Termine folgen, bearbeiteter Text bleibt', [$new . ' 12:00', 'Mein eigener Text'], [wp_date('Y-m-d H:i', strtotime($by2['today']['due_at'])), $by2['today']['text']]);
    // Preisphase entfällt → offener Regel-Post weg
    update_post_meta($ev, '_tix_ticket_categories', [['name' => 'Normal', 'price' => 10, 'qty' => 100]]);
    TIX_Social_Planner::sync($ev);
    [, $d] = $rest('GET', "/events/$ev/social-plan");
    $eq('Preisphase gelöscht → Post entfällt', false, in_array('price_phase_end', array_column((array) $d['posts'], 'occasion'), true));
    // Erinnerung
    $wpdb->update(TIX_Social_Planner::t(), ['due_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $by2['announce']['id']]);
    $mails = [];
    TIX_Social_Planner::cron();
    $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM " . TIX_Social_Planner::t() . " WHERE id = %d", $by2['announce']['id']));
    $subj = $mails ? (string) $mails[0]['subject'] : '';
    $eq('Fällig: Erinnerung per Mail mit Text, Status reminded', ['reminded', true, true], [$row->status ?? '', strpos($subj, 'Social-Planer: Ankündigung') === 0, $mails && strpos((string) $mails[0]['message'], 'Planer-Event') !== false]);
    $c = count($mails); TIX_Social_Planner::cron();
    $eq('Keine doppelte Erinnerung', $c, count($mails));
    // eigener Post, Regel-Post löschen → übersprungen
    [$st, $d] = $rest('POST', "/events/$ev/social-plan/posts", ['text' => 'Gewinnspiel!', 'due_at' => gmdate('c', time() + 3 * DAY_IN_SECONDS), 'format' => 'story']);
    $cid = intval($d['post']['id'] ?? 0);
    $eq('Eigener Post → 201', [201, 'custom', 'story'], [$st, $d['post']['occasion'] ?? '', $d['post']['format'] ?? '']);
    [$st] = $rest('POST', "/events/$ev/social-plan/posts", ['text' => 'x', 'due_at' => '2020-01-01']);
    $eq('Termin in der Vergangenheit → 400', 400, $st);
    $rest('DELETE', "/events/$ev/social-plan/posts/{$by2['thanks']['id']}");
    TIX_Social_Planner::sync($ev);
    $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM " . TIX_Social_Planner::t() . " WHERE id = %d", $by2['thanks']['id']));
    $eq('Regel-Post löschen → übersprungen (kommt nicht wieder)', 'skipped', $row->status ?? '');
    $eq('nur noch wenige: ohne Verkäufe nicht', false, TIX_Social_Planner::maybe_few_left($ev));
    // ausschalten
    [$st, $d] = $rest('POST', "/events/$ev/social-plan", ['enabled' => false]);
    $eq('Ausschalten: offene Posts weg, erledigte bleiben', [false, 0], [$d['enabled'] ?? null, count(array_filter((array) $d['posts'], function ($p) { return $p['status'] === 'planned'; }))]);
    $staff = intval(get_users(['role' => 'tix_staff', 'number' => 1, 'fields' => 'ID'])[0] ?? 0);
    wp_set_current_user($staff ?: 0);
    $eq('Ohne Rechte → verboten', true, in_array($rest('GET', "/events/$ev/social-plan")[0], [401, 403], true));
    wp_set_current_user($adm);
    $eq('Staff-Push kennt „Social-Planer“', true, isset(TIX_Staff_Push::EVENTS['social_post']));
    $src = file_get_contents(TIXOMAT_PATH . 'includes/class-tix-social-planner.php');
    $eq('Push-Aktion trägt Event- und Post-ID (staff:social:<event>:<post>)', true, strpos($src, "'staff:social:' . \$eid . ':' . intval(\$r['id'])") !== false);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    remove_filter('pre_wp_mail', $catch, 1);
    foreach ($posts as $p) { $wpdb->delete(TIX_Social_Planner::t(), ['event_id' => $p]); if (class_exists('TIX_Object_Data')) TIX_Object_Data::remove('tix_event_social', $p); wp_delete_post($p, true); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
