<?php
/**
 * Co-Veranstalter-Schalter im Studio unter Module (1.38.901): /studio/modules settings[co_remote].
 *   wp eval-file …/test-co-remote-switch-wp.php   (Einzelseite: Schalter; Plattform: kein Eintrag)
 */
if (!defined('ABSPATH') || !method_exists('TIX_Plan', 'site_settings')) { echo "Tixomat ab 1.38.901 nötig\n"; return; }
$f = 0; $n = 0;
$ok = function ($l, $c) use (&$f, &$n) { $n++; echo ($c ? 'ok    ' : 'FAIL  ') . $l . "\n"; if (!$c) $f++; };
$call = function ($m, $body = null) { $r = new WP_REST_Request($m, '/tixomat/v1/studio/modules'); if ($body !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$orig = get_option('tix_settings', []);
$demo = get_option('tix_demo_instance', null); // Testseiten tragen evtl. die Demo-Kennung; der Demo-Shop sperrt Schreiben für Nicht-Admins
update_option('tix_demo_instance', '');
$users = [];
$mk = function ($role, $owner = false) use (&$users) { $id = wp_insert_user(['user_login' => 'cort_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'cort' . wp_rand() . '@example.test', 'role' => $role]); if ($owner) update_user_meta($id, '_tix_site_owner', 1); $users[] = $id; return $id; };
try {
    $admin = $mk('administrator');
    wp_set_current_user($admin);
    [$st, $d] = $call('GET');
    if ($multi) {
        $ok('Plattform: /studio/modules 404', $st === 404);
        $ok('Plattform: site_settings leer', TIX_Plan::site_settings() === []);
    } else {
        $s = is_array($orig) ? $orig : []; unset($s['co_remote_enabled']); update_option('tix_settings', $s);
        [$st, $d] = $call('GET');
        $e = array_values(array_filter((array) ($d['settings'] ?? []), function ($x) { return ($x['key'] ?? '') === 'co_remote'; }))[0] ?? null;
        $ok('GET 200 mit settings.co_remote', $st === 200 && is_array($e));
        $ok('Vorgabe aus', $e && $e['enabled'] === false);
        $ok('Felder key/label/enabled/can_edit/hint/requirements/ready', $e && !array_diff(['key', 'label', 'enabled', 'can_edit', 'hint', 'requirements', 'ready'], array_keys($e)));
        $ok('Voraussetzungen sharing + partner_api', $e && array_column($e['requirements'], 'key') === ['sharing', 'partner_api']);
        $ok('nicht bereit → Hinweis nennt Fehlendes', $e && ($e['ready'] || strpos($e['hint'], 'Wirkt erst') !== false));
        $ok('Admin can_edit + can_edit_modules', $e && $e['can_edit'] === true && ($d['can_edit_modules'] ?? null) === true);
        global $wpdb; $t = TIX_Customers::audit_table(); $before = intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE action='site_setting'"));
        [$st, $d] = $call('POST', ['settings' => ['co_remote' => true]]);
        $ok('Admin schaltet ein (auch ohne Voraussetzungen)', $st === 200 && $d['settings'][0]['enabled'] === true && !empty(get_option('tix_settings')['co_remote_enabled']));
        $ok('Protokoll-Eintrag', intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE action='site_setting'")) === $before + 1);
        $call('POST', ['settings' => ['co_remote' => true]]);
        $ok('gleicher Wert: kein weiterer Eintrag', intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE action='site_setting'")) === $before + 1);
        [$st] = $call('POST', ['settings' => ['foo' => true]]);
        $ok('unbekannter Schalter 400', $st === 400);
        $owner = $mk('subscriber', true);
        wp_set_current_user($owner);
        [$st, $d] = $call('GET');
        $ok('Inhaber liest', $st === 200 && ($d['settings'][0]['can_edit'] ?? null) === true && ($d['can_edit_modules'] ?? null) === false);
        [$st, $d] = $call('POST', ['settings' => ['co_remote' => false]]);
        $ok('Inhaber schaltet aus', $st === 200 && empty(get_option('tix_settings')['co_remote_enabled']));
        $row = $wpdb->get_row("SELECT * FROM $t WHERE action='site_setting' ORDER BY id DESC LIMIT 1", ARRAY_A);
        $ok('Protokoll mit Inhaber als Akteur', $row && intval($row['actor_id']) === $owner && json_decode($row['summary'], true) === ['co_remote' => [true, false]]);
        [$st] = $call('POST', ['modules' => ['music' => false]]);
        $ok('Inhaber darf keine Module schalten (403)', $st === 403);
        update_option('tix_demo_instance', '1');
        wp_set_current_user($owner);
        [$st, $d] = $call('POST', ['settings' => ['co_remote' => true]]);
        $ok('Demo-Shop: Inhaber gesperrt (403)', class_exists('TIX_Demo_Shop') ? ($st === 403 && empty(get_option('tix_settings')['co_remote_enabled'])) : true);
        update_option('tix_demo_instance', '');
        $other = $mk('subscriber');
        wp_set_current_user($other);
        [$st] = $call('GET');
        $ok('Sonstiges Konto 403', $st === 403);
        [$st] = $call('POST', ['settings' => ['co_remote' => true]]);
        $ok('Sonstiges Konto darf nicht schreiben', $st === 403 && empty(get_option('tix_settings')['co_remote_enabled']));
        wp_set_current_user($admin);
        [$st, $d] = $call('POST', ['modules' => []]);
        $ok('Module-POST unverändert (Admin, leeres Objekt)', $st === 200 && isset($d['modules']));
    }
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    update_option('tix_settings', $orig);
    if ($demo === null) delete_option('tix_demo_instance'); else update_option('tix_demo_instance', $demo);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
