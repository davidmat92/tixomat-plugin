<?php
/**
 * M4 Gäste-Abläufe (1.38.813): Übersicht mit Bestand, Schalter (Vorgabe aus), nächstes Event an die letzten Gäste,
 * Danke am Tag danach, Follower-Alarm (evendis), einmal je Event.
 *   wp eval-file …/test-guest-flows-wp.php (beide Modi)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Guest_Flows')) { echo "Tixomat ab 1.38.813 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null) { $q = []; if (strpos($r, '?') !== false) { parse_str(substr($r, strpos($r, '?') + 1), $q); $r = substr($r, 0, strpos($r, '?')); } $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($q) $x->set_query_params($q); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$posts = []; $users = [];
$opt = get_option(TIX_Guest_Flows::OPT, null);
$ts_before = get_option('tix_settings', []);
$items = function ($uid) { $l = get_user_meta($uid, '_tix_app_notif_user', true); return array_column(is_array($l) ? $l : [], 'title'); };
try {
    TIX_Guest_Flows::maybe_install();
    if (class_exists('TIX_Ad_Accounts') && !TIX_Ad_Accounts::module_on(0)) { delete_option('tix_marketing_trial_until'); }
    $adm = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
    wp_set_current_user($adm);
    $oid = 0;
    if ($multi) { $oid = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Flow-Club']); $posts[] = $oid; }
    $meta = $oid ? ['_tix_organizer_id' => $oid] : [];
    $last = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Flow Vorher', 'meta_input' => $meta + ['_tix_date_start' => wp_date('Y-m-d', time() - DAY_IN_SECONDS)]]); $posts[] = $last;
    $g1 = wp_insert_user(['user_login' => 'gf1' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'gf1' . wp_rand(1000, 9999) . '@example.test', 'role' => 'subscriber']); $users[] = $g1;
    $g2 = wp_insert_user(['user_login' => 'gf2' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(), 'user_email' => 'gf2' . wp_rand(1000, 9999) . '@example.test', 'role' => 'subscriber']); $users[] = $g2;
    foreach ([$g1, $g2] as $u) { $t = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'T', 'meta_input' => ['_tix_ticket_event_id' => $last, '_tix_ticket_owner_email' => get_userdata($u)->user_email]]); $posts[] = $t; }
    [$st, $d] = $rest('GET', '/organizer/guest-flows' . ($oid ? '?organizer=' . $oid : ''));
    $keys = array_column((array) ($d['flows'] ?? []), 'enabled', 'key');
    $eq('Übersicht: neue Abläufe aus, Bestand mit Erinnerung am Event-Tag', [200, false, false, true], [$st, $keys['next_event'] ?? null, $keys['thanks'] ?? null, $keys['event_day_push'] ?? null]);
    $eq('Typ je Modus (Follower nur evendis, alle App-Nutzer nur Einzel-Seite)', [$multi, !$multi], [array_key_exists('follower_alert', $keys), array_key_exists('new_event_all', $keys)]);
    // ausgeschaltet: nichts
    $new1 = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => 'Flow Neu 1', 'meta_input' => $meta + ['_tix_date_start' => wp_date('Y-m-d', time() + 20 * DAY_IN_SECONDS)]]); $posts[] = $new1;
    wp_update_post(['ID' => $new1, 'post_status' => 'publish']);
    TIX_Guest_Flows::process_new($new1);
    $eq('Aus: keine Mitteilung', false, in_array('Dein nächstes Event', $items($g1), true));
    // einschalten (als Admin für den Bereich)
    [$st, $d] = $rest('POST', '/organizer/guest-flows' . ($oid ? '?organizer=' . $oid : ''), ['flows' => ['next_event' => true, 'thanks' => true] + ($multi ? ['follower_alert' => true] : [])]);
    $keys = array_column((array) ($d['flows'] ?? []), 'enabled', 'key');
    $eq('Einschalten', [200, true, true], [$st, $keys['next_event'] ?? null, $keys['thanks'] ?? null]);
    [$st] = $rest('POST', '/organizer/guest-flows', ['flows' => ['quatsch' => true]]);
    $eq('Unbekannter Ablauf → 400', 400, $st);
    if ($multi) update_user_meta($g2, '_tix_app_following', [$oid]);
    $new2 = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => 'Flow Neu 2', 'meta_input' => $meta + ['_tix_date_start' => wp_date('Y-m-d', time() + 21 * DAY_IN_SECONDS)]]); $posts[] = $new2;
    wp_update_post(['ID' => $new2, 'post_status' => 'publish']);
    $r = TIX_Guest_Flows::process_new($new2);
    $eq('Nächstes Event an beide Gäste des letzten Events', [2, true, true], [$r['next_event'] ?? 0, in_array('Dein nächstes Event', $items($g1), true), in_array('Dein nächstes Event', $items($g2), true)]);
    if ($multi) $eq('Follower-Alarm an den Konto-Follower', [1, true], [$r['follower_new'] ?? 0, in_array('Neu von Flow-Club', $items($g2), true)]);
    $c = count($items($g1));
    TIX_Guest_Flows::process_new($new2);
    $eq('Einmal je Event', $c, count($items($g1)));
    $t = TIX_Guest_Flows::process_thanks($last);
    $eq('Danke an die Gäste (einmal)', [2, true, 0], [$t, in_array('Danke für deinen Besuch', $items($g1), true), TIX_Guest_Flows::process_thanks($last)]);
    $eq('Ohne Ticket-Gäste: niemand', true, TIX_Guest_Flows::sent('next_event', $new2));
    if (!$multi) {
        [$st, $d] = $rest('POST', '/organizer/guest-flows', ['followup_mail' => true]);
        $keys = array_column((array) ($d['flows'] ?? []), 'enabled', 'key');
        $eq('Einzel-Seite: Danke-Mail schaltbar', [200, true], [$st, $keys['followup_mail'] ?? null]);
    }
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($posts as $p) { $wpdb->delete($wpdb->prefix . 'tix_guest_flow_log', ['event_id' => $p]); if (get_post_type($p) === 'tix_organizer' && class_exists('TIX_Object_Data')) TIX_Object_Data::remove(TIX_Guest_Flows::KIND, $p); wp_delete_post($p, true); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    if ($opt === null) delete_option(TIX_Guest_Flows::OPT); else update_option(TIX_Guest_Flows::OPT, $opt, false);
    delete_option('tix_guest_flows_new');
    update_option('tix_settings', $ts_before);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
