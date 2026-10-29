<?php
/**
 * Integrationstest Modul-Verkauf (1.38.587) gegen ein lokales Test-WordPress:
 * ein Schlüsselsatz (Lizenz = TIX_Plan), alte Listen, Kundenseite sperrt Studio/App/API, Admin bekommt 409,
 * „Jetzt anfragen“ (Kundenseite → Lizenz-Server → Betreiber-Support), Modul zurück. Stellt alles wieder her.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-module-sale-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Modules') || !class_exists('TIX_Plan')) { echo "WordPress mit Tixomat (TIX_Modules, TIX_Plan) nötig\n"; return; }
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
    $d = $res->get_data();
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
}

$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = [];
foreach (['tix_license_server', 'tix_license_key', 'tix_license_status', 'tix_license_mode', TIX_Modules::OPTION, TIX_Modules::OPTION . '_v', 'tix_license_modules_v', TIX_Plan::SITE_OPT] as $o) $keep[$o] = get_option($o, null);
$restore = function () use ($keep) {
    foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    if (class_exists('TIX_License_Stage')) TIX_License_Stage::reset();
};
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = $admin ? intval($admin[0]->ID) : 0;
$set_license = function ($modules, $v = TIX_Modules::VERSION) {
    update_option('tix_license_server', '0', false); // Kundenseite (auf dem Server ist die Kunden-Lizenz nie aktiv)
    update_option('tix_license_mode', 'managed', false);
    update_option('tix_license_key', 'TEST-AAAA-BBBB-CCCC', false);
    update_option('tix_license_status', ['valid' => true, 'status' => 'active', 'checked_at' => time(), 'last_ok' => time(), 'modules' => $modules, 'modules_v' => $v], false);
    if (class_exists('TIX_License_Stage')) TIX_License_Stage::reset();
};

// ══════════ Ein Schlüsselsatz ══════════
echo "── Schlüsselsatz ──\n";
$keys = TIX_Modules::keys();
tm_eq('Lizenz-Schlüssel = Pakete + Extras + App-Extras', true,
    array_values(array_diff(array_merge(TIX_Plan::PACKAGES, TIX_Plan::EXTRAS, TIX_Plan::APP_EXTRAS), $keys)) === [] && count($keys) === count(TIX_Plan::PACKAGES) + count(TIX_Plan::EXTRAS) + count(TIX_Plan::APP_EXTRAS));
tm_eq('Immer-dabei-Module nie in der Lizenz', [], array_values(array_intersect(TIX_Plan::ALWAYS, $keys)));
tm_eq('Alte Liste: neue Schlüssel bleiben frei', true, in_array('wallet', TIX_Modules::normalize(['loyalty'], 1), true) && !in_array('music', TIX_Modules::normalize(['loyalty'], 1), true));
tm_eq('Neue Liste: genau diese', ['loyalty'], TIX_Modules::normalize(['loyalty', 'quatsch'], 2));

// ══════════ Bestand: ohne Lizenz alles an ══════════
echo "── Bestand ──\n";
update_option('tix_license_server', '0', false);
delete_option('tix_license_mode'); delete_option('tix_license_key'); delete_option(TIX_Modules::OPTION);
tm_eq('ohne Lizenz: alles frei', null, TIX_Modules::entitled());
tm_eq('ohne Lizenz: nichts gesperrt', [], TIX_Modules::locked());

if ($multi) {
    echo "── Plattform-Seite: Kundenseiten-Teil übersprungen ──\n";
} else {
    // ══════════ Kundenseite: Modul aus der Lizenz ══════════
    echo "── Kundenseite: Musikwunsch und Tipps nicht lizenziert ──\n";
    $set_license(array_values(array_diff($keys, ['music', 'tips'])));
    tm_eq('Lizenz aktiv (managed)', true, TIX_License::active());
    tm_eq('music nicht frei', false, TIX_Modules::allowed('music'));
    tm_eq('wallet frei', true, TIX_Modules::allowed('wallet'));
    tm_eq('gesperrt: music, tips', ['music', 'tips'], TIX_Modules::locked());
    wp_set_current_user(0);
    [$st, , $code] = tm_rest('GET', '/music/status');
    tm_eq('API: /music/status 403', [403, 'tix_module_disabled'], [$st, $code]);
    [$st, , $code] = tm_rest('GET', '/public/tips');
    tm_eq('API: /public/tips 403', [403, 'tix_module_disabled'], [$st, $code]);
    [$st, $d] = tm_rest('GET', '/info');
    tm_eq('/info.license.locked', ['music', 'tips'], $d['license']['locked'] ?? null);
    tm_eq('/info.plan: music aus', false, $d['plan']['extras']['music'] ?? null);
    tm_eq('Betrieb: Funktion music aus', false, TIX_Features::on('music'));

    wp_set_current_user($admin_id);
    [$st, $d] = tm_rest('GET', '/studio/modules');
    tm_eq('Studio: locked.music', true, $d['locked']['music'] ?? null);
    tm_eq('Studio: locked.tips (App-Extra)', true, $d['locked']['tips'] ?? null);
    tm_eq('Studio: Betriebsstand vorhanden', true, is_array($d['usage'] ?? null) && array_key_exists('wallet', $d['usage']));
    tm_eq('Studio: Quelle Lizenz', 'license', $d['licensed_by'] ?? null);
    [$st, , $code] = tm_rest('POST', '/studio/modules', ['modules' => ['music' => true]]);
    tm_eq('Kunden-Admin schaltet music ein: 409', [409, 'tix_module_locked'], [$st, $code]);
    [$st, , $code] = tm_rest('POST', '/studio/features', ['app_features' => ['tips' => true]]);
    tm_eq('Kunden-Admin schaltet Tipps in der App ein: 409', [409, 'tix_module_disabled'], [$st, $code]);
    [$st, $d] = tm_rest('GET', '/me');
    tm_eq('/me.modules_locked', true, in_array('music', (array) ($d['modules_locked'] ?? []), true) && in_array('tips', (array) ($d['modules_locked'] ?? []), true));
    tm_eq('/me.modules.music aus', false, $d['modules']['music'] ?? null);

    // „Jetzt anfragen“: Kundenseite schickt an den Lizenz-Server
    $sent = null;
    $fake = function ($pre, $args, $url) use (&$sent) {
        if (strpos($url, '/license/module-request') === false) return $pre;
        $sent = $args['body'];
        return ['headers' => [], 'body' => wp_json_encode(['ok' => true, 'ticket' => 77]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    };
    delete_transient('tix_app_rl_mod_req_' . md5((string) $admin_id));
    add_filter('pre_http_request', $fake, 10, 3);
    [$st, $d] = tm_rest('POST', '/studio/module-request', ['module' => 'music', 'message' => 'Für Silvester']);
    remove_filter('pre_http_request', $fake, 10);
    tm_eq('Anfrage: ok mit Ticket', [200, 77], [$st, $d['ticket'] ?? null]);
    tm_eq('Anfrage: Modul und Schlüssel an den Server', ['music', 'TEST-AAAA-BBBB-CCCC'], [$sent['module'] ?? null, $sent['key'] ?? null]);
    [$st, , $code] = tm_rest('POST', '/studio/module-request', ['module' => 'wallet']);
    tm_eq('Anfrage für freies Modul: 409', [409, 'tix_module_already'], [$st, $code]);

    // Alte Lizenz-Liste (v1): neue Schlüssel bleiben frei
    echo "── Alte Lizenz-Liste ──\n";
    $set_license(['loyalty'], 1);
    tm_eq('v1: wallet frei', true, TIX_Modules::allowed('wallet'));
    tm_eq('v1: music gesperrt (alter Schlüssel, nicht in der Liste)', false, TIX_Modules::allowed('music'));

    // Modul zurück
    echo "── Modul zurück ──\n";
    $set_license(null);
    wp_set_current_user(0);
    [$st, , $code] = tm_rest('GET', '/music/status');
    tm_eq('API: /music/status wieder offen', true, $code !== 'tix_module_disabled');
    wp_set_current_user($admin_id);
    [$st] = tm_rest('POST', '/studio/modules', ['modules' => ['music' => true]]);
    tm_eq('Admin schaltet music ein: 200', 200, $st);
    tm_eq('nichts gesperrt', [], TIX_Modules::locked());
}

// ══════════ Lizenz-Server: Migration, Anfrage, Verlauf ══════════
echo "── Lizenz-Server ──\n";
global $wpdb;
update_option('tix_license_server', '1', false);
TIX_License_Server::install();
$lic = TIX_License_Server::create(['customer' => 'Modulkunde', 'email' => 'modul@example.test', 'valid_until' => gmdate('Y-m-d', strtotime('+60 days')), 'enforce' => true]);
$wpdb->update($wpdb->prefix . 'tix_licenses', ['modules' => wp_json_encode(['loyalty', 'music'])], ['id' => $lic->id]);
update_option('tix_license_modules_v', 1, false);
TIX_License_Server::maybe_migrate_modules();
$m = TIX_License_Server::license_modules(TIX_License_Server::get($lic->id));
tm_eq('Migration: alte Liste + neue Schlüssel', true, in_array('wallet', $m, true) && in_array('music', $m, true) && !in_array('giftcards', $m, true));
tm_eq('Migration: nur einmal', TIX_Modules::VERSION, intval(get_option('tix_license_modules_v')));
$now = current_time('mysql');
$wpdb->insert($wpdb->prefix . 'tix_license_sites', ['license_id' => $lic->id, 'host' => 'kunde.test', 'dev' => 0, 'version' => TIXOMAT_VERSION, 'first_seen' => $now, 'last_seen' => $now]);
add_action('rest_api_init', ['TIX_License_Server', 'register_routes']);
do_action('rest_api_init');
wp_set_current_user(0);
[$st, $d] = tm_rest('POST', '/license/module-request', ['key' => $lic->license_key, 'site' => 'https://kunde.test', 'module' => 'giftcards', 'name' => 'Max', 'email' => 'max@kunde.test']);
$tid = intval($d['ticket'] ?? 0);
tm_eq('Server: Anfrage angelegt', true, $st === 200 && $tid > 0);
tm_eq('Server: Ticket im Betreiber-Support', true, $tid && get_post_type($tid) === 'tix_support_ticket' && (bool) get_post_meta($tid, '_tix_sp_help', true));
tm_eq('Server: Ticket nennt Kunde und Modul', true, $tid && strpos(get_the_title($tid), 'Geschenkgutscheine') !== false && strpos(get_the_title($tid), 'Modulkunde') !== false);
$log = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_license_log WHERE license_id = %d AND action = 'module_request'", $lic->id));
tm_eq('Server: Verlauf „Modul angefragt“', 1, intval($log));
[$st, , $code] = tm_rest('POST', '/license/module-request', ['key' => $lic->license_key, 'site' => 'https://fremd.test', 'module' => 'giftcards']);
tm_eq('Server: fremde Seite abgelehnt', 403, $st);
// Module im Studio speichern: Verlauf mit dazu/weg
wp_set_current_user($admin_id);
[$st, $d] = tm_rest('POST', '/license/admin/licenses/' . $lic->id, ['modules' => ['loyalty']]);
$row = $wpdb->get_row($wpdb->prepare("SELECT data FROM {$wpdb->prefix}tix_license_log WHERE license_id = %d AND action = 'save' ORDER BY id DESC LIMIT 1", $lic->id));
$ld = $row ? json_decode($row->data, true) : [];
tm_eq('Studio-Speichern: Module ohne music', ['loyalty'], $d['license']['modules'] ?? null);
tm_eq('Verlauf: music weg', true, in_array('music', (array) ($ld['modules_removed'] ?? []), true));
[$st, $d] = tm_rest('GET', '/license/admin/licenses');
tm_eq('Studio-Liste: Bezeichnung + Gruppe je Modul', 'app', $d['module_info']['party_game']['group'] ?? null);

// Aufräumen
if ($tid) wp_delete_post($tid, true);
$wpdb->delete($wpdb->prefix . 'tix_license_sites', ['license_id' => $lic->id]);
$wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $lic->id]);
$wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic->id]);
$restore();
wp_set_current_user(0);

echo $GLOBALS['t_fails'] === 0 ? "\nALLES GRÜN ({$GLOBALS['t_count']} Prüfungen)\n" : "\n{$GLOBALS['t_fails']} von {$GLOBALS['t_count']} FEHLGESCHLAGEN\n";
