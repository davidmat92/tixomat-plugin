<?php
/**
 * 1.38.905: Prämien – collect_mode both|station|team (Vorgabe both). station: Personal-Award gesperrt (Scan + Einlösen bleiben),
 * team: Stempel-Station (token, claim) gesperrt; Check-in-Stempel in allen Modi. Je Seite bzw. je Veranstalter.
 *   wp eval-file …/test-loyalty-collect-mode-wp.php   (beide Modi)
 */
if (!defined('ABSPATH') || !method_exists('TIX_Loyalty', 'collect_mode')) { echo "Tixomat ab 1.38.905 nötig\n"; return; }
$f = 0; $n = 0;
$ok = function ($l, $c) use (&$f, &$n) { $n++; echo ($c ? 'ok    ' : 'FAIL  ') . $l . "\n"; if (!$c) $f++; };
$req = function ($m, $route, $body = null, $q = []) { $x = new WP_REST_Request($m, '/tixomat/v1' . $route); if ($q) $x->set_query_params($q); if ($body !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($body)); } $r = rest_do_request($x); return [$r->get_status(), $r->get_data()]; };
global $wpdb;
$clear = function () use ($wpdb) { $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%tix\\_app\\_rl\\_loyalty%'"); wp_cache_flush(); };
$clear();
if (class_exists('TIX_REST_API')) TIX_REST_API::ensure_staff_role();
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$users = []; $posts = [];
$mk = function ($role) use (&$users) { $id = wp_insert_user(['user_login' => 'lc_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(20), 'user_email' => 'lc' . wp_rand() . '@example.test', 'role' => $role]); $users[] = $id; return $id; };
$bak = []; foreach (['_tix_loyalty_enabled', '_tix_loyalty_collect_mode', '_tix_loyalty_station_events_only', '_tix_demo_instance'] as $o) $bak[$o] = get_option($o, null);
try {
    $admin = $mk('administrator');
    if ($multi) {
        $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'LC Org']); $posts[] = $org;
        update_post_meta($org, '_tix_org_loyalty', ['enabled' => true]);
        update_post_meta($org, TIX_Plan::META, wp_json_encode(['loyalty' => true]));
        [$st, $d] = $req('GET', '/loyalty/config', null, ['organizer' => $org]);
        $ok('Plattform: GET öffentlich, Vorgabe both, 3 Modi mit Hinweis, checkin_stamp', $st === 200 && $d['collect_mode'] === 'both' && count($d['collect_modes']) === 3 && !empty($d['collect_modes'][1]['hint']) && $d['checkin_stamp'] === true);
        wp_set_current_user($admin);
        [$st, $d] = $req('POST', '/loyalty/config', ['collect_mode' => 'wand'], ['organizer' => $org]);
        $ok('Plattform: 400 field collect_mode', $st === 400 && ($d['data']['field'] ?? '') === 'collect_mode');
        [$st, $d] = $req('POST', '/loyalty/config', ['collect_mode' => 'team'], ['organizer' => $org]);
        $ok('Plattform: je Veranstalter gespeichert', $st === 200 && $d['collect_mode'] === 'team' && (get_post_meta($org, '_tix_org_loyalty', true)['collect_mode'] ?? '') === 'team');
        wp_set_current_user(0);
        [, $d] = $req('GET', '/loyalty/config', null, ['organizer' => $org]);
        [, $d0] = $req('GET', '/loyalty/config');
        $ok('Plattform: Gast sieht team; ohne Veranstalter bleibt Seite both', $d['collect_mode'] === 'team' && $d0['collect_mode'] === 'both');
    } else {
        update_option('_tix_loyalty_enabled', '1'); update_option('_tix_loyalty_station_events_only', '0', false); delete_option('_tix_loyalty_collect_mode');
        [$st, $d] = $req('GET', '/loyalty/config');
        $ok('GET: Vorgabe both + Modi', $st === 200 && $d['collect_mode'] === 'both' && array_column($d['collect_modes'], 'key') === ['both', 'station', 'team']);
        $stu = $mk('tix_stamp_station'); $guest = $mk('subscriber'); $staff = $mk('tix_organizer');
        $token = TIX_Loyalty::make_token($guest);
        // both: alles geht
        wp_set_current_user($stu); [$s1, $tk] = $req('GET', '/loyalty/station/token', null, ['device' => 'lc-1']);
        $ok('both: Station bekommt Code', $s1 === 200 && !empty($tk['qr']));
        // station
        wp_set_current_user($admin); [$st, $d] = $req('POST', '/loyalty/config', ['collect_mode' => 'station']);
        $ok('POST station gespeichert', $st === 200 && $d['collect_mode'] === 'station');
        [$st, $d] = $req('POST', '/loyalty/config', ['collect_mode' => 'x']);
        $ok('POST ungültig → 400, bleibt station', $st === 400 && TIX_Loyalty::collect_mode() === 'station');
        wp_set_current_user($staff);
        [$st, $d] = $req('POST', '/loyalty/award', ['token' => $token]);
        $ok('station: award 403 tix_loyalty_collect_mode', $st === 403 && ($d['code'] ?? '') === 'tix_loyalty_collect_mode');
        [$st, $d] = $req('POST', '/loyalty/scan', ['token' => $token]);
        $ok('station: scan geht (für Einlösen), can_award false', $st === 200 && $d['can_award'] === false && $d['collect_mode'] === 'station');
        wp_set_current_user($stu); [$st, $tk] = $req('GET', '/loyalty/station/token', null, ['device' => 'lc-1']);
        $ok('station: Station-Code geht', $st === 200);
        wp_set_current_user($guest); $clear();
        [$st, $c] = $req('POST', '/loyalty/claim', ['token' => $tk['qr']]);
        $ok('station: Gast claim stempelt', $st === 200 && intval($c['awarded'] ?? 0) >= 1);
        // team
        wp_set_current_user($admin); $req('POST', '/loyalty/config', ['collect_mode' => 'team']);
        wp_set_current_user($stu); [$st, $d] = $req('GET', '/loyalty/station/token', null, ['device' => 'lc-1']);
        $ok('team: Station-Code 403', $st === 403 && ($d['code'] ?? '') === 'tix_loyalty_collect_mode');
        wp_set_current_user($guest); $clear();
        [$st, $d] = $req('POST', '/loyalty/claim', ['token' => $tk['qr']]);
        $ok('team: claim 403 mit Hinweis aufs Team', $st === 403 && strpos((string) ($d['message'] ?? ''), 'Team') !== false);
        wp_set_current_user($guest);
        [, $me] = $req('GET', '/loyalty/me'); [, $cd] = $req('GET', '/loyalty/cards');
        $ok('/loyalty/me und /loyalty/cards tragen collect_mode', ($me['collect_mode'] ?? '') === 'team' && ($cd['cards'][0]['collect_mode'] ?? '') === 'team');
        $guest2 = $mk('subscriber');
        wp_set_current_user($staff);
        [$st, $d] = $req('POST', '/loyalty/award', ['token' => TIX_Loyalty::make_token($guest2)]);
        $ok('team: award geht', $st === 200 && intval($d['awarded'] ?? 0) >= 1);
        [$st, $d] = $req('POST', '/loyalty/scan', ['token' => $token]);
        $ok('team: scan can_award true', $st === 200 && $d['can_award'] === true);
        // Check-in-Stempel in jedem Modus
        foreach (['station', 'team'] as $m) {
            update_option('_tix_loyalty_collect_mode', $m, false);
            $g = $mk('subscriber'); $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'LC ' . $m]); $posts[] = $ev;
            $t = wp_insert_post(['post_type' => 'tix_ticket', 'post_status' => 'publish', 'post_title' => 'T']); $posts[] = $t;
            update_post_meta($t, '_tix_ticket_owner_email', get_userdata($g)->user_email); update_post_meta($t, '_tix_ticket_event_id', $ev);
            do_action('tix_ticket_checked_in', $t);
            $ok("Check-in-Stempel auch im Modus $m", TIX_Loyalty::points($g) >= 1);
        }
    }
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    foreach ($bak as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v, false); }
    foreach ($posts as $p) wp_delete_post($p, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
