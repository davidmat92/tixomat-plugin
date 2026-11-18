<?php
/**
 * 1.38.902: Vorgabe-Kategorien für Tische (/studio/checkout-settings features.table_reservation.default_categories + Editor)
 * und Preis-Wächter freigeben (/platform/price-watch-settings, nur Plattform-Admin).
 *   wp eval-file …/test-table-defaults-price-watch-wp.php   (beide Modi)
 */
if (!defined('ABSPATH') || !method_exists('TIX_Site_Switches', 'clean_table_defaults')) { echo "Tixomat ab 1.38.902 nötig\n"; return; }
$f = 0; $n = 0;
$ok = function ($l, $c) use (&$f, &$n) { $n++; echo ($c ? 'ok    ' : 'FAIL  ') . $l . "\n"; if (!$c) $f++; };
$call = function ($m, $route, $body = null) { $r = new WP_REST_Request($m, '/tixomat/v1' . $route); if ($body !== null) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); } $res = rest_do_request($r); return [$res->get_status(), $res->get_data()]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$orig = get_option('tix_settings', []);
$demo = get_option('tix_demo_instance', null);
update_option('tix_demo_instance', '');
$users = []; $posts = [];
$mk = function ($role) use (&$users) { $id = wp_insert_user(['user_login' => 'td_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'user_email' => 'td' . wp_rand() . '@example.test', 'role' => $role]); $users[] = $id; return $id; };
try {
    $admin = $mk('administrator');
    wp_set_current_user($admin);
    // ── Rein ──
    [$j, $bad] = TIX_Site_Switches::clean_table_defaults([['name' => 'VIP', 'min_spend' => '250', 'quantity' => 4, 'min_guests' => 2, 'max_guests' => 8]], 20);
    $ok('clean: gültig → JSON', $bad === null && json_decode($j, true)[0]['min_spend'] == 250);
    $ok('clean: max < min → Feld 0.max_guests', TIX_Site_Switches::clean_table_defaults([['name' => 'A', 'min_guests' => 5, 'max_guests' => 2]], 20)[1] === '0.max_guests');
    $ok('clean: ohne Namen → 0.name', TIX_Site_Switches::clean_table_defaults([['name' => '']], 20)[1] === '0.name');
    $ok('clean: zu viele → Fehler', TIX_Site_Switches::clean_table_defaults(array_fill(0, 21, ['name' => 'x']), 20)[0] === null);
    // ── /studio/checkout-settings ──
    $s = is_array($orig) ? $orig : []; $s['table_default_categories'] = '[]'; update_option('tix_settings', $s);
    [$st, $d] = $call('GET', '/studio/checkout-settings');
    $tr = $d['features']['table_reservation'] ?? null;
    $ok('GET: default_categories [] + limits', $st === 200 && is_array($tr) && $tr['default_categories'] === [] && isset($tr['limits']['default_categories']));
    $cats = [['name' => 'Lounge', 'desc' => 'Sofa', 'min_spend' => 300, 'quantity' => 3, 'min_guests' => 4, 'max_guests' => 10], ['name' => 'Stehtisch', 'min_spend' => 0, 'quantity' => 6, 'min_guests' => 1, 'max_guests' => 4]];
    [$st, $d] = $call('POST', '/studio/checkout-settings', ['features' => ['table_reservation' => ['default_categories' => $cats]]]);
    $got = $d['features']['table_reservation']['default_categories'] ?? null;
    $ok('POST speichert', $st === 200 && is_array($got) && count($got) === 2 && $got[0]['name'] === 'Lounge' && $got[1]['desc'] === '');
    $raw = json_decode(get_option('tix_settings')['table_default_categories'], true);
    $ok('gespeichert als JSON-Text (WP-Admin-Form)', is_string(get_option('tix_settings')['table_default_categories']) && $raw[0]['max_guests'] === 10);
    [$st, $d] = $call('POST', '/studio/checkout-settings', ['features' => ['table_reservation' => ['default_categories' => [['name' => 'X', 'min_guests' => 6, 'max_guests' => 2]]]]]);
    $ok('400 mit Feld features.table_reservation.default_categories.0.max_guests', $st === 400 && ($d['data']['field'] ?? '') === 'features.table_reservation.default_categories.0.max_guests');
    $ok('Fehler ändert nichts', count(json_decode(get_option('tix_settings')['table_default_categories'], true)) === 2);
    // ── Editor ──
    if (!class_exists('TIX_Table_Reservation') && file_exists(TIXOMAT_PATH . 'includes/class-tix-table-reservation.php')) require_once TIXOMAT_PATH . 'includes/class-tix-table-reservation.php';
    $eid = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => 'Tisch-Vorgabe']); $posts[] = $eid;
    $ref = new ReflectionClass('TIX_Studio_Editor');
    $val = null;
    foreach (['check2'] as $mname) if ($ref->hasMethod($mname)) { $val = $mname; break; }
    if ($val && class_exists('TIX_Table_Reservation')) {
        $m = $ref->getMethod($val); $m->setAccessible(true);
        $pc = $m->getNumberOfParameters();
        $out = $pc >= 2 ? $m->invoke(null, ['table_reservation' => ['enabled' => true]], $eid) : $m->invoke(null, ['table_reservation' => ['enabled' => true]]);
        $c2 = is_array($out) ? ($out['table_reservation']['categories'] ?? null) : null;
        $ok('Editor: Einschalten ohne Kategorien → Vorgabe übernommen', is_array($c2) && count($c2) === 2 && $c2[0]['name'] === 'Lounge' && $c2[0]['tables'] === []);
        update_post_meta($eid, '_tix_table_reservation', ['enabled' => '1', 'categories' => [['name' => 'Eigene', 'min_guests' => 1, 'max_guests' => 2, 'tables' => []]]]);
        $out = $pc >= 2 ? $m->invoke(null, ['table_reservation' => ['enabled' => true]], $eid) : $m->invoke(null, ['table_reservation' => ['enabled' => true]]);
        $ok('Editor: bestehende Kategorien bleiben', ($out['table_reservation']['categories'][0]['name'] ?? '') === 'Eigene' && count($out['table_reservation']['categories']) === 1);
    } else { echo "skip  Editor-Prüfer nicht gefunden\n"; }
    // ── Preis-Wächter ──
    [$st, $d] = $call('GET', '/platform/price-watch-settings');
    if (!$multi) {
        $ok('Einzel-Seite: price-watch-settings 404', $st === 404);
    } else {
        $ok('GET: Felder', $st === 200 && array_key_exists('enabled', $d) && isset($d['shop_monthly'], $d['ref_percent'], $d['ref_fixed'], $d['limits']) && is_bool($d['ref_in_use']));
        global $wpdb; $t = TIX_Customers::audit_table(); $before = intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE action='platform_price_watch'"));
        [$st, $d] = $call('POST', '/platform/price-watch-settings', ['enabled' => true, 'shop_monthly' => '89,50']);
        $ok('POST: schaltet frei, Komma-Zahl', $st === 200 && $d['enabled'] === true && $d['shop_monthly'] == 89.5 && !empty(get_option('tix_settings')['price_watch_enabled']));
        $ok('POST: andere Felder unverändert', abs($d['ref_percent'] - floatval($orig['price_watch_ref_percent'] ?? 2.9)) < 0.0001);
        $ok('Protokoll platform_price_watch', intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE action='platform_price_watch'")) === $before + 1);
        [$st, $d] = $call('POST', '/platform/price-watch-settings', ['ref_percent' => 101]);
        $ok('400 außerhalb der Grenzen, field ref_percent', $st === 400 && ($d['data']['field'] ?? '') === 'ref_percent');
        [$st] = $call('POST', '/platform/price-watch-settings', ['foo' => 1]);
        $ok('400 unbekanntes Feld', $st === 400);
        $ok('TIX_Price_Watch liest den Wert (frischer Cache-Zustand: Option)', !empty(get_option('tix_settings')['price_watch_enabled']));
        $org = $mk('tix_organizer');
        wp_set_current_user($org);
        [$st] = $call('POST', '/platform/price-watch-settings', ['enabled' => false]);
        $ok('Veranstalter 403', $st === 403 && !empty(get_option('tix_settings')['price_watch_enabled']));
    }
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    update_option('tix_settings', $orig);
    if ($demo === null) delete_option('tix_demo_instance'); else update_option('tix_demo_instance', $demo);
    foreach ($posts as $p) wp_delete_post($p, true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
