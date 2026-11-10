<?php
/**
 * „Als Betreiber anmelden“ + ehrliche Startklar-Zahlung (1.38.722).
 *   plat eval-file …/test-operator-access-wp.php   (Lizenz-Server: Ticket ausstellen, ready_payload)
 *   kk   eval-file …/test-operator-access-wp.php   (Kunden-Seite: Ticket einlösen, Sperren, Schalter, Protokoll)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Operator_Access')) { echo "Tixomat ab 1.38.722 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (!defined('REST_REQUEST')) define('REST_REQUEST', true);
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
global $wpdb;
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function op_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function op_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = intval($admin[0]->ID);

if ($multi) {
    // ═══════════════ Plattform ═══════════════
    $keep = get_option('tix_license_server', null);
    update_option('tix_license_server', '1', false);
    TIX_License_Server::install();
    $lic = TIX_License_Server::create(['customer' => 'OPTEST Kunde', 'email' => 'optest@example.test', 'valid_until' => gmdate('Y-m-d', strtotime('+60 days'))]);
    $st = $wpdb->prefix . 'tix_license_sites';
    $ready = function (array $r) use ($wpdb, $st, $lic) {
        $wpdb->delete($st, ['license_id' => $lic->id]);
        $wpdb->insert($st, ['license_id' => $lic->id, 'host' => 'optest-kunde.test', 'dev' => 0, 'ready' => wp_json_encode($r), 'first_seen' => current_time('mysql'), 'last_seen' => current_time('mysql')]);
        TIX_Platform_Customers::flush();
        return TIX_Platform_Customers::ready_payload(TIX_Platform_Customers::raw('l-' . $lic->id));
    };
    $pay = function ($p) { foreach ($p['items'] as $i) if ($i['key'] === 'payment') return $i; return []; };
    try {
        // ── Teil 1: Zahlung ehrlich
        $p = $ready(['payment' => true, 'legal' => true, 'owner' => true, 'homepage' => true, 'payments_needed' => false, 'payments_reason' => 'only_brokered', 'payment_provider' => false]);
        $i = $pay($p);
        op_eq('nur Vermittlung: not_needed, ok bleibt true, ehrliches Etikett', ['not_needed', true, true, false, 'only_brokered'],
            [$i['state'] ?? '', $i['ok'] ?? null, strpos((string) $i['label'], 'noch nicht nötig') !== false, $p['payments_needed'], $p['payments_reason']]);
        $i = $pay($ready(['payment' => true, 'legal' => true, 'owner' => true, 'homepage' => true, 'payments_needed' => true, 'payment_provider' => true]));
        op_eq('echter Anbieter: ok', ['ok', true], [$i['state'] ?? '', $i['ok'] ?? null]);
        $i = $pay($ready(['payment' => false, 'legal' => true, 'owner' => true, 'homepage' => true, 'payments_needed' => true, 'payment_provider' => false]));
        op_eq('Anbieter fehlt: missing', ['missing', false], [$i['state'] ?? '', $i['ok'] ?? null]);
        $i = $pay($ready(['payment' => true, 'legal' => true, 'owner' => true, 'homepage' => true, 'payments_needed' => false]));
        op_eq('ältere Seite ohne payment_provider, nur Vermittlung: not_needed', 'not_needed', $i['state'] ?? '');
        $i = $pay($ready(['payment' => true, 'legal' => true, 'owner' => true, 'homepage' => true]));
        op_eq('ganz alte Seite (nur payment): ok', 'ok', $i['state'] ?? '');

        // ── Teil 2: Ticket ausstellen
        wp_set_current_user(0);
        op_eq('ohne Anmeldung 401', 401, op_rest('POST', "/platform/customers/l-{$lic->id}/operator-login")[0]);
        $org = wp_insert_user(['user_login' => 'optest_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'optest' . wp_rand(1000, 99999) . '@example.test', 'role' => 'tix_organizer']);
        wp_set_current_user($org);
        op_eq('Veranstalter 403', 403, op_rest('POST', "/platform/customers/l-{$lic->id}/operator-login")[0]);
        wp_set_current_user($admin_id);
        [$s, $d] = op_rest('POST', "/platform/customers/l-{$lic->id}/operator-login");
        op_eq('Admin: Ticket für die gebundene Seite', [200, 'optest-kunde.test', 'https://optest-kunde.test/wp-json/tixomat/v1/studio/operator-login'], [$s, $d['host'] ?? '', $d['redeem_url'] ?? '']);
        [$b, $sig] = array_pad(explode('.', (string) ($d['ticket'] ?? '')), 2, '');
        $pl = json_decode((string) base64_decode(strtr($b, '-_', '+/') . str_repeat('=', (4 - strlen($b) % 4) % 4)), true);
        op_eq('Ticket signiert mit dem Lizenzschlüssel, 60 s, an Admin gebunden', [true, 60, $admin_id, 'optest-kunde.test'],
            [hash_equals(TIX_Operator_Access::sign($b, $lic->license_key), $sig), intval($pl['exp'] ?? 0) - intval($pl['iat'] ?? 0), intval($pl['admin']['id'] ?? 0), $pl['host'] ?? '']);
        $log = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_license_log WHERE license_id = %d AND action = 'operator_login'", $lic->id));
        op_eq('Protokoll auf der Plattform', 1, intval($log));
        $wpdb->delete($st, ['license_id' => $lic->id]);
        TIX_Platform_Customers::flush();
        op_eq('ohne gebundene Seite 409', [409, 'tix_operator_no_site'], (function ($r) { return [$r[0], $r[2]]; })(op_rest('POST', "/platform/customers/l-{$lic->id}/operator-login")));
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($org);
    } finally {
        $wpdb->delete($st, ['license_id' => $lic->id]);
        $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $lic->id]);
        $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic->id]);
        if ($keep === null) delete_option('tix_license_server'); else update_option('tix_license_server', $keep, false);
        TIX_Platform_Customers::flush();
        wp_set_current_user(0);
    }
} else {
    // ═══════════════ Kunden-Seite ═══════════════
    $keep = [];
    foreach (['tix_license_server', 'tix_license_key', 'tix_license_mode', TIX_Operator_Access::OPT_ENABLED, TIX_Operator_Access::OPT_LOG, TIX_Operator_Access::OPT_NONCES] as $o) $keep[$o] = get_option($o, null);
    $key = 'TIX-OPTEST-' . strtoupper(wp_generate_password(10, false));
    update_option('tix_license_key', $key, false);
    update_option('tix_license_mode', 'selfhosted', false);
    update_option('tix_license_server', '0', false); // Kunden-Seite, nicht selbst Lizenz-Server
    $clear_rl = function () use ($wpdb) { $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_app\\_rl\\_op\\_login\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tix\\_app\\_rl\\_op\\_login\\_%'"); wp_cache_flush(); };
    $clear_rl();
    delete_option(TIX_Operator_Access::OPT_ENABLED); delete_option(TIX_Operator_Access::OPT_LOG); delete_option(TIX_Operator_Access::OPT_NONCES);
    $host = TIX_Operator_Access::host();
    $adm = ['id' => 7, 'name' => 'Pat Plattform', 'email' => 'pat@evendis.test'];
    $tok_user = function ($tok) { $_SERVER['HTTP_X_TIX_TOKEN'] = $tok; $u = TIX_REST_API::authenticate_by_token(0); unset($_SERVER['HTTP_X_TIX_TOKEN']); return intval($u); };
    try {
        wp_set_current_user(0);
        op_eq('Vorgabe: Betreiber-Zugang an', true, TIX_Operator_Access::enabled());
        [$t] = TIX_Operator_Access::make_ticket($key, $host, $adm);
        [$s, $d] = op_rest('POST', '/studio/operator-login', ['ticket' => $t]);
        $uid = intval($d['user']['id'] ?? 0);
        $GLOBALS['op_tok'] = (string) ($d['token'] ?? '');
        op_eq('Ticket eingelöst: Token + Betreiber-Konto', [200, true, true], [$s, strlen((string) ($d['token'] ?? '')) >= 32, TIX_Operator_Access::is_operator($uid)]);
        $until = strtotime((string) ($d['expires_at'] ?? ''));
        op_eq('Token gilt ca. 8 h', true, abs($until - (time() + 8 * HOUR_IN_SECONDS)) < 60);
        op_eq('Token meldet das Betreiber-Konto an', $uid, $tok_user((string) $d['token']));
        $u = get_userdata($uid);
        op_eq('Konto: evendis Betreiber, Rolle Inhaber, zählt nicht als Inhaber', ['evendis Betreiber', true, false],
            [$u->display_name, in_array('tix_organizer', (array) $u->roles, true), in_array($uid, array_map('intval', get_users(['role' => 'tix_organizer', 'fields' => 'ID', 'meta_query' => [['key' => '_tix_operator', 'compare' => 'NOT EXISTS']]])), true)]);
        op_eq('dasselbe Ticket zweimal → 401 used', [401, 'tix_operator_ticket_used'], (function ($r) { return [$r[0], $r[2]]; })(op_rest('POST', '/studio/operator-login', ['ticket' => $t])));
        [$t2] = TIX_Operator_Access::make_ticket($key, 'fremde-seite.test', $adm);
        op_eq('Ticket für andere Seite → 401', [401, 'tix_operator_ticket_host'], (function ($r) { return [$r[0], $r[2]]; })(op_rest('POST', '/studio/operator-login', ['ticket' => $t2])));
        [$t3] = TIX_Operator_Access::make_ticket('TIX-FALSCH-123', $host, $adm);
        op_eq('falscher Schlüssel → 401', [401, 'tix_operator_ticket'], (function ($r) { return [$r[0], $r[2]]; })(op_rest('POST', '/studio/operator-login', ['ticket' => $t3])));
        [$t4] = TIX_Operator_Access::make_ticket($key, $host, $adm, time() - 120);
        op_eq('abgelaufen → 401', [401, 'tix_operator_ticket_expired'], (function ($r) { return [$r[0], $r[2]]; })(op_rest('POST', '/studio/operator-login', ['ticket' => $t4])));
        [$t5] = TIX_Operator_Access::make_ticket($key, $host, $adm);
        $parts = explode('.', $t5); $parts[0] = rtrim(strtr(base64_encode(str_replace('Pat Plattform', 'Mallory', (string) base64_decode(strtr($parts[0], '-_', '+/')))), '+/', '-_'), '=');
        op_eq('verändertes Ticket → 401', 401, op_rest('POST', '/studio/operator-login', ['ticket' => implode('.', $parts)])[0]);

        // Passwort-Login gesperrt
        wp_set_password('Bekannt-123456', $uid);
        op_eq('Passwort-Login gesperrt (wp_check_password, wp_authenticate)', [false, true],
            [wp_check_password('Bekannt-123456', get_userdata($uid)->user_pass, $uid), is_wp_error(wp_authenticate($u->user_login, 'Bekannt-123456'))]);
        [$s, , $c] = op_rest('POST', '/auth/login', ['email' => $u->user_email, 'password' => 'Bekannt-123456']);
        op_eq('App-Login mit Passwort scheitert', true, $s >= 400);
        op_eq('kein Passwort-Reset', false, apply_filters('allow_password_reset', true, $uid));
        $clear_rl();

        // Als Betreiber: /me-Balken, Sperren
        wp_set_current_user($uid);
        [$s, $d] = op_rest('GET', '/me');
        op_eq('/me: Hinweis-Balken mit Admin-Name', [200, true, 'Pat Plattform', 'Als Betreiber angemeldet (evendis)'], [$s, $d['operator']['active'] ?? null, $d['operator']['by'] ?? '', $d['operator']['label'] ?? '']);
        op_eq('Zahlungs-Schlüssel ändern 403', [403, 'tix_operator_forbidden'], (function ($r) { return [$r[0], $r[2]]; })(op_rest('POST', '/studio/payments', ['mollie_api_key' => 'live_x'])));
        op_eq('Mail-Zugang ändern 403', 403, op_rest('POST', '/studio/mail-transport', ['api_key' => 'x'])[0]);
        op_eq('Passwort ändern 403', 403, op_rest('POST', '/auth/password', ['current_password' => 'a', 'new_password' => 'b'])[0]);
        op_eq('eigenen Zugang abschalten 403', 403, op_rest('POST', '/studio/operator-access', ['enabled' => false])[0]);
        op_eq('Feld password in anderer Route 403', 403, op_rest('POST', '/team', ['email' => 'x@example.test', 'password' => 'geheim'])[0]);
        op_eq('Lesen erlaubt (Studio-Konfiguration)', 200, op_rest('GET', '/studio/config')[0]);

        // Inhaber: Protokoll + Schalter
        wp_set_current_user($admin_id);
        [$s, $d] = op_rest('GET', '/studio/operator-access');
        op_eq('Inhaber sieht Schalter, aktive Sitzung und Protokoll', [200, true, 'Pat Plattform', 'Pat Plattform', 'pat@evendis.test'],
            [$s, $d['enabled'] ?? null, $d['active']['by'] ?? '', $d['log'][0]['by'] ?? '', $d['log'][0]['by_email'] ?? '']);
        [$s, $d] = op_rest('POST', '/studio/operator-access', ['enabled' => false]);
        op_eq('ausschalten: aus, Sitzung beendet, protokolliert', [200, false, null, 'disabled'], [$s, $d['enabled'] ?? null, $d['active'], $d['log'][0]['action'] ?? '']);
        wp_set_current_user(0);
        op_eq('alter Token gilt nicht mehr', 0, $tok_user((string) ($GLOBALS['op_tok'] ?? 'x')));
        [$t6] = TIX_Operator_Access::make_ticket($key, $host, $adm);
        op_eq('Schalter aus → 403 tix_operator_disabled', [403, 'tix_operator_disabled'], (function ($r) { return [$r[0], $r[2]]; })(op_rest('POST', '/studio/operator-login', ['ticket' => $t6])));
        wp_set_current_user($admin_id);
        op_rest('POST', '/studio/operator-access', ['enabled' => true]);
        wp_set_current_user(0);
        [$t7] = TIX_Operator_Access::make_ticket($key, $host, $adm);
        [$s, $d] = op_rest('POST', '/studio/operator-login', ['ticket' => $t7]);
        op_eq('wieder an: dasselbe Konto wiederverwendet', [200, $uid], [$s, intval($d['user']['id'] ?? 0)]);
        op_eq('Startklar meldet payment_provider', true, array_key_exists('payment_provider', TIX_Owner_Access::readiness()));
    } finally {
        $clear_rl();
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach (get_users(['meta_key' => TIX_Operator_Access::META, 'meta_value' => '1', 'fields' => 'ID']) as $id) wp_delete_user($id);
        foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
        wp_set_current_user(0);
    }
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
