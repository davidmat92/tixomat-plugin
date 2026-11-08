<?php
/**
 * Integrationstest Garderobe (1.38.691, evendis je Veranstalter ab 1.38.693): App-Schalter `cloakroom`, Vorgabe aus, kein Lizenz-Modul.
 * Lesen über GET /app/config (features), schreiben über Studio (POST /studio/features, /studio/config app_features)
 * und Veranstalterbereich (POST /app/config features). Stellt alles wieder her.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-cloakroom-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Features')) { echo "WordPress mit Tixomat (TIX_Features) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cr_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function cr_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = $res->get_data();
    $d = is_object($d) ? (array) $d : $d;
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
}
function cr_app() { // Zustand aus Sicht der App (ohne Anmeldung)
    $u = get_current_user_id(); wp_set_current_user(0);
    [, $d] = cr_rest('GET', '/app/config');
    wp_set_current_user($u);
    return $d['features']['cloakroom']['enabled'] ?? null;
}

$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = get_option(TIX_Features::OPT_CLOAKROOM, null);
$keep_cfg = get_option('_tix_app_config', null);
delete_option(TIX_Features::OPT_CLOAKROOM);
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = $admin ? intval($admin[0]->ID) : 0;
$tmp = []; $tmp_posts = [];
$mk = function ($role) use (&$tmp) {
    $id = wp_insert_user(['user_login' => 'cr_' . $role . '_' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'cr_' . wp_rand(1000, 99999) . '@example.test', 'role' => $role]);
    if (is_wp_error($id)) return 0;
    $tmp[] = $id; return $id;
};

try {
    echo "== " . ($multi ? 'Plattform' : 'Einzel-Seite') . "\n";
    cr_eq('kein Lizenz-Modul', false, isset(TIX_Features::LICENSE_MODULE['cloakroom']));
    cr_eq('kein Paket-Modul', false, isset(TIX_Features::PLAN_MODULE['cloakroom']));
    cr_eq('in der Reihenfolge', true, in_array('cloakroom', TIX_Features::ORDER, true));
    cr_eq('Vorgabe aus (App-Konfig)', false, cr_app());

    wp_set_current_user(0);
    [$st] = cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => true]]);
    cr_eq('ohne Anmeldung 401', 401, $st);
    cr_eq('bleibt aus', false, cr_app());

    wp_set_current_user($admin_id);
    [$st, $d] = cr_rest('GET', '/studio/features');
    $f = $d['features']['cloakroom'] ?? [];
    cr_eq('Studio: Bezeichnung', 'Garderobe in der App', $f['label'] ?? null);
    cr_eq('Studio: Status aus', 'off', $f['status'] ?? null);
    cr_eq('Studio: verfügbar', true, $f['available'] ?? null);
    cr_eq('Studio: Admin darf schalten', true, $f['can_edit'] ?? null);

    [$st, $d] = cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => true]]);
    cr_eq('Studio an: 200', 200, $st);
    cr_eq('Studio an: Antwort', 'on', $d['features']['cloakroom']['status'] ?? null);
    cr_eq('App sieht an', true, cr_app());

    [$st] = cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => 'vielleicht']]);
    cr_eq('ungültiger Wert 400', 400, $st);
    cr_eq('bleibt an', true, cr_app());

    // Veranstalterbereich der App: POST /app/config mit features
    [$st] = cr_rest('POST', '/app/config', ['features' => ['cloakroom' => false]]);
    cr_eq('Veranstalterbereich aus: 200', 200, $st);
    cr_eq('App sieht aus', false, cr_app());
    cr_eq('App-Inhalte unverändert', $keep_cfg, get_option('_tix_app_config', null));

    // Studio-Konfig (POST /studio/config app_features)
    [$st] = cr_rest('POST', '/studio/config', ['app_features' => ['cloakroom' => true]]);
    cr_eq('Studio-Konfig an: 200', 200, $st);
    cr_eq('App sieht an (Studio-Konfig)', true, cr_app());

    // Rechte: Mitarbeiter nie; Inhaber (Rolle Veranstalter) nur auf Einzel-Seiten
    $staff = $mk(get_role('tix_staff') ? 'tix_staff' : 'subscriber');
    wp_set_current_user($staff);
    [$st] = cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => false]]);
    cr_eq('Mitarbeiter 403', 403, $st);
    $org = $mk('tix_organizer');
    wp_set_current_user($org);
    [$st] = cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => false]]);
    cr_eq($multi ? 'Veranstalter ohne Plattform-Recht 403' : 'Inhaber darf: 200', $multi ? 403 : 200, $st);
    wp_set_current_user($admin_id);
    cr_eq('Zustand nach Rechte-Test', $multi, cr_app());

    // evendis (1.38.693): Veranstalter-Modul `cloakroom` wie Muttizettel; Plattform-Schalter = Vorgabe ohne eigene Wahl
    if ($multi) {
        $owner = $mk('tix_organizer');
        $oid = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'CR Veranstalter', 'meta_input' => ['_tix_org_user_id' => $owner]]);
        $tmp_posts[] = $oid;
        $app_org = function ($o) { $u = get_current_user_id(); wp_set_current_user(0); [, $d] = cr_rest('GET', '/app/config', ['organizer' => (string) $o]); wp_set_current_user($u); return $d['features']['cloakroom']['enabled'] ?? null; };
        wp_set_current_user($admin_id);
        cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => true]]);
        cr_eq('Ohne eigene Wahl: Plattform-Vorgabe an → Modul an', [true, true], [$app_org($oid), TIX_Public_Platform::modules($oid)['cloakroom'] ?? null]);
        cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => false]]);
        cr_eq('Plattform-Vorgabe aus → Modul aus', [false, false], [$app_org($oid), TIX_Public_Platform::modules($oid)['cloakroom'] ?? null]);
        [, $d] = cr_rest('GET', '/studio/features');
        cr_eq('Plattform-Studio: Hinweis Vorgabe', true, strpos((string) ($d['features']['cloakroom']['hint'] ?? ''), 'Vorgabe') === 0);
        wp_set_current_user($owner);
        [$st, $d] = cr_rest('GET', '/studio/features');
        cr_eq('Inhaber: eigener Bereich, darf schalten', ['organizer', true, 'organizer'], [$d['scope'] ?? null, $d['features']['cloakroom']['can_edit'] ?? null, $d['features']['cloakroom']['scope'] ?? null]);
        [$st] = cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => true]]);
        cr_eq('Inhaber schaltet an: 200', 200, $st);
        cr_eq('Nur dieser Veranstalter an, Plattform-Vorgabe bleibt aus', [true, true, false], [$app_org($oid), TIX_Public_Platform::modules($oid)['cloakroom'] ?? null, TIX_Features::site_on('cloakroom')]);
        $raw = json_decode((string) get_post_meta($oid, TIX_Public_Platform::META_MODULES, true), true);
        cr_eq('Gespeichert in den Veranstalter-Modulen', true, $raw['cloakroom'] ?? null);
        wp_set_current_user($admin_id);
        cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => true]]);
        wp_set_current_user($owner);
        cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => false]]);
        cr_eq('Eigene Wahl „aus“ schlägt Plattform-Vorgabe „an“', [false, true], [$app_org($oid), TIX_Features::site_on('cloakroom')]);
        // Veranstalterbereich: auf evendis gehören die App-Inhalte der Plattform (POST /app/config nur Admin) → /studio/features
        [$st] = cr_rest('POST', '/app/config', ['features' => ['cloakroom' => true]]);
        cr_eq('evendis: POST /app/config für Veranstalter gesperrt', 403, $st);
        [$st] = cr_rest('POST', '/studio/features', ['app_features' => ['cloakroom' => true]]);
        cr_eq('Veranstalterbereich über /studio/features: eigenes Modul an', [200, true], [$st, $app_org($oid)]);
        wp_set_current_user($admin_id);
        [$st] = cr_rest('POST', '/studio/features', ['organizer' => $oid, 'app_features' => ['cloakroom' => false]]);
        cr_eq('Admin mit ?organizer= schaltet das Modul', [200, false], [$st, $app_org($oid)]);
    }
} finally {
    if ($keep === null) delete_option(TIX_Features::OPT_CLOAKROOM); else update_option(TIX_Features::OPT_CLOAKROOM, $keep, false);
    if ($keep_cfg === null) delete_option('_tix_app_config'); else update_option('_tix_app_config', $keep_cfg, false);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tmp as $id) wp_delete_user($id);
    foreach ($tmp_posts as $id) wp_delete_post($id, true);
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
