<?php
/**
 * Zugänge für Kunden-Seiten aus der Plattform-Akte (1.38.724).
 *   plat eval-file …/test-site-users-wp.php   (Veranstalter-Kunde direkt; Lizenz-Kunde: signierte Anfrage an die Seite)
 *   kk   eval-file …/test-site-users-wp.php   (Kunden-Seite: signierte Anfragen ausführen)
 */
if (!defined('ABSPATH') || !class_exists('TIX_Site_Users')) { echo "Tixomat ab 1.38.724 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
global $wpdb;
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function su_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function su_rest($method, $route, array $body = [], array $headers = [], $raw = null) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    foreach ($headers as $k => $v) $r->set_header($k, $v);
    if ($raw !== null) { $r->set_header('content-type', 'application/json'); $r->set_body($raw); }
    elseif ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
add_filter('pre_wp_mail', function () { $GLOBALS['su_mails'] = ($GLOBALS['su_mails'] ?? 0) + 1; return true; });
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = intval($admin[0]->ID);
$sfx = wp_rand(1000, 9999);
$users = []; $posts = [];
$cleanup_users = function () use (&$users) { require_once ABSPATH . 'wp-admin/includes/user.php'; foreach (array_unique($users) as $u) if ($u) wp_delete_user($u); };

if ($multi) {
    // ═══════════════ Plattform ═══════════════
    $owner = wp_insert_user(['user_login' => "sut_owner$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_owner$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $owner;
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "SUTEST Veranstalter $sfx", 'meta_input' => ['_tix_org_user_id' => $owner]]); $posts[] = $org;
    $keep = get_option('tix_license_server', null);
    try {
        TIX_Platform_Customers::flush();
        $k = TIX_Platform_Customers::canonical('v-' . $org);
        wp_set_current_user(0);
        su_eq('ohne Anmeldung 401', 401, su_rest('GET', "/platform/customers/$k/users")[0]);
        wp_set_current_user($owner);
        su_eq('Veranstalter 403', 403, su_rest('GET', "/platform/customers/$k/users")[0]);
        wp_set_current_user($admin_id);
        [$s, $d] = su_rest('GET', "/platform/customers/$k/users");
        su_eq('Liste: Inhaber, nicht entfernbar, Rollen, Ziel Veranstalter', [200, true, false, 5, 'organizer'], [$s, $d['users'][0]['owner'] ?? null, $d['users'][0]['removable'] ?? null, count($d['roles'] ?? []), $d['target'] ?? '']);
        $mails = $GLOBALS['su_mails'] ?? 0;
        [$s, $d] = su_rest('POST', "/platform/customers/$k/users", ['name' => 'Ina Einlass', 'email' => "sut_ina$sfx@example.test", 'role' => 'entrance', 'password_mode' => 'invite']);
        $ina = intval($d['user']['id'] ?? 0); $users[] = $ina;
        su_eq('Einladung per Mail: angelegt, Team des Veranstalters, Mail raus', [200, true, $org, true, 'entrance'], [$s, $d['invited'] ?? null, intval(get_user_meta($ina, TIX_App_Scope::META_TEAM_ORG, true)), ($GLOBALS['su_mails'] ?? 0) > $mails, $d['user']['role'] ?? '']);
        [$s, $d] = su_rest('POST', "/platform/customers/$k/users", ['name' => 'Kai Kasse', 'login' => "sut_kai$sfx", 'role' => 'cashier', 'password_mode' => 'set', 'password' => 'Kasse-Geheim-2026']);
        $kai = intval($d['user']['id'] ?? 0); $users[] = $kai;
        $ku = get_userdata($kai);
        su_eq('Ohne E-Mail: Login, Ersatzadresse, Passwort gesetzt, Passwort nie in der Antwort', [200, "sut_kai$sfx", null, true, true, false],
            [$s, $d['user']['login'] ?? '', array_key_exists('email', (array) ($d['user'] ?? [])) ? $d['user']['email'] : 'fehlt', substr($ku->user_email, -strlen('.ohne-mail.invalid')) === '.ohne-mail.invalid', wp_check_password('Kasse-Geheim-2026', $ku->user_pass, $kai), strpos(wp_json_encode($d), 'Kasse-Geheim') !== false]);
        su_eq('Ohne E-Mail keine Einladung', [400, 'tix_invalid_field'], (function ($r) { return [$r[0], $r[2]]; })(su_rest('POST', "/platform/customers/$k/users", ['login' => "sut_x$sfx", 'role' => 'dj', 'password_mode' => 'invite'])));
        su_eq('Schwaches Passwort 400', [400, 'tix_weak_password'], (function ($r) { return [$r[0], $r[2]]; })(su_rest('POST', "/platform/customers/$k/users", ['login' => "sut_y$sfx", 'role' => 'dj', 'password_mode' => 'set', 'password' => 'kurz'])));
        su_eq('… und kein halbes Konto übrig', false, (bool) username_exists("sut_y$sfx"));
        su_eq('Login doppelt 409', [409, 'tix_login_taken'], (function ($r) { return [$r[0], $r[2]]; })(su_rest('POST', "/platform/customers/$k/users", ['login' => "sut_kai$sfx", 'role' => 'dj', 'password_mode' => 'set', 'password' => 'Anderes-Pass-99'])));
        su_eq('E-Mail mit vorhandenem Konto 409', [409, 'tix_user_exists'], (function ($r) { return [$r[0], $r[2]]; })(su_rest('POST', "/platform/customers/$k/users", ['email' => "sut_owner$sfx@example.test", 'role' => 'dj'])));
        // Form des Studios: {invite: true} bzw. {password}, PATCH {password}|{invite}
        [$s, $d] = su_rest('POST', "/platform/customers/$k/users", ['name' => 'Dana DJ', 'email' => "sut_dana$sfx@example.test", 'role' => 'dj', 'invite' => true]);
        $dana = intval($d['user']['id'] ?? 0); $users[] = $dana;
        su_eq('Studio-Form: invite true → Mail, source platform, role_label', [200, true, 'platform', 'DJ', false], [$s, $d['mailed'] ?? null, $d['source'] ?? '', $d['user']['role_label'] ?? '', $d['user']['email_placeholder'] ?? null]);
        [$s, $d] = su_rest('PATCH', "/platform/customers/$k/users/$dana", ['password' => 'Dana-Pass-2026x']);
        su_eq('Studio-Form: PATCH {password} setzt das Passwort', [200, true], [$s, wp_check_password('Dana-Pass-2026x', get_userdata($dana)->user_pass, $dana)]);
        su_eq('Studio-Form: PATCH {invite: true} schickt den Link', [200, true], (function ($r) { return [$r[0], $r[1]['mailed'] ?? null]; })(su_rest('PATCH', "/platform/customers/$k/users/$dana", ['invite' => true])));
        [$s, $d] = su_rest('PATCH', "/platform/customers/$k/users/$kai", ['role' => 'dj', 'name' => 'Kai DJ']);
        su_eq('Rolle + Name ändern', [200, 'dj', 'Kai DJ'], [$s, $d['user']['role'] ?? '', $d['user']['name'] ?? '']);
        [$s, $d] = su_rest('POST', "/platform/customers/$k/users/$kai/password", ['mode' => 'set', 'password' => 'Neues-Passwort-77']);
        su_eq('Passwort neu setzen', [200, true, true], [$s, $d['password_set'] ?? null, wp_check_password('Neues-Passwort-77', get_userdata($kai)->user_pass, $kai)]);
        su_eq('Ersatzadresse: kein Passwort-Link per Mail', 400, su_rest('POST', "/platform/customers/$k/users/$kai/password", ['mode' => 'invite'])[0]);
        su_eq('Passwort-Link per Mail an Ina', [200, true], (function ($r) { return [$r[0], $r[1]['mailed'] ?? null]; })(su_rest('POST', "/platform/customers/$k/users/$ina/password", ['mode' => 'invite'])));
        su_eq('Inhaber ändern/entfernen 409', [409, 409], [su_rest('DELETE', "/platform/customers/$k/users/$owner")[0], su_rest('PATCH', "/platform/customers/$k/users/$owner", ['role' => 'dj'])[0]]);
        [$s, $d] = su_rest('POST', "/platform/customers/$k/users/$owner/password", ['mode' => 'set', 'password' => 'Owner-Pass-2026x']);
        su_eq('Veranstalter-Inhaber: Passwort direkt setzen (1.38.734)', [200, true], [$s, wp_check_password('Owner-Pass-2026x', get_userdata($owner)->user_pass, $owner)]);
        su_eq('… auch über PATCH {password}', 200, su_rest('PATCH', "/platform/customers/$k/users/$owner", ['password' => 'Owner-Pass-2027y'])[0]);
        su_eq('Fremdes Konto (Admin) 404', 404, su_rest('DELETE', "/platform/customers/$k/users/$admin_id")[0]);
        // 1.38.727: Inhaberschaft wechseln (Veranstalter), Konten auf evendis nie löschen
        [$s, $d] = su_rest('POST', "/platform/customers/$k/users/$ina/owner", ['old' => 'admin']);
        su_eq('Veranstalter: Ina wird Inhaberin, alter Inhaber ins Team', [200, $ina, $org], [$s, intval(get_post_meta($org, '_tix_org_user_id', true)), intval(get_user_meta($owner, TIX_App_Scope::META_TEAM_ORG, true))]);
        su_eq('Konto auf evendis löschen 409', [409, 'tix_users_delete_platform'], (function ($r) { return [$r[0], $r[2]]; })(su_rest('DELETE', "/platform/customers/$k/users/$owner", ['delete' => true])));
        su_eq('Inhaberin nicht entfernbar (Rolle)', 409, su_rest('DELETE', "/platform/customers/$k/users/$ina")[0]);
        update_post_meta($org, '_tix_org_user_id', $owner); delete_user_meta($owner, TIX_App_Scope::META_TEAM_ORG); update_user_meta($ina, TIX_App_Scope::META_TEAM_ORG, $org);
        [$s, $d] = su_rest('DELETE', "/platform/customers/$k/users/$kai");
        su_eq('Entfernen: Rolle weg, nicht mehr im Team', [200, true, '', null], [$s, $d['removed'] ?? null, (string) get_user_meta($kai, TIX_App_Scope::META_TEAM_ORG, true), (function ($u) { foreach (TIX_Team::APP_ROLES as $k => $wp) if (in_array($wp, (array) $u->roles, true)) return $k; return null; })(get_userdata($kai))]);
        $n = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TIX_Customers::audit_table() .  " WHERE action LIKE 'team_%%' AND subject_id IN (%s, %s, %s)", (string) $ina, (string) $kai, (string) $owner));
        su_eq('Protokoll beim Kunden ohne Passwörter', [true, false], [intval($n) >= 5, (bool) $wpdb->get_var("SELECT COUNT(*) FROM " . TIX_Customers::audit_table() . " WHERE summary LIKE '%Geheim%' OR summary LIKE '%Neues-Passwort%'")]);

        // ── Lizenz-Kunde: signierte Anfrage an die gebundene Seite
        update_option('tix_license_server', '1', false);
        TIX_License_Server::install();
        $lic = TIX_License_Server::create(['customer' => 'SUTEST Lizenz', 'email' => 'sut@example.test', 'valid_until' => gmdate('Y-m-d', strtotime('+60 days'))]);
        $st = $wpdb->prefix . 'tix_license_sites';
        $wpdb->insert($st, ['license_id' => $lic->id, 'host' => 'sut-kunde.test', 'dev' => 0, 'first_seen' => current_time('mysql'), 'last_seen' => current_time('mysql')]);
        TIX_Platform_Customers::flush();
        $GLOBALS['su_calls'] = [];
        add_filter('pre_http_request', function ($pre, $a, $url) {
            if (strpos($url, 'sut-kunde.test/') === false) return $pre;
            $GLOBALS['su_calls'][] = ['url' => $url, 'body' => (string) $a['body'], 'headers' => $a['headers']];
            return ['headers' => [], 'body' => wp_json_encode(['ok' => true, 'created' => true, 'password_set' => true, 'user' => ['id' => 5, 'login' => 'kunde-team']]), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
        }, 10, 3);
        [$s, $d] = su_rest('POST', "/platform/customers/l-{$lic->id}/users", ['login' => 'kunde-team', 'role' => 'cashier', 'password_mode' => 'set', 'password' => 'Kunden-Pass-123']);
        $c = $GLOBALS['su_calls'][0] ?? [];
        $b = json_decode((string) ($c['body'] ?? ''), true);
        su_eq('Lizenz-Kunde: an die Seite, signiert mit dem Lizenzschlüssel', [200, 'site', true, true, 'create'],
            [$s, $d['target'] ?? '', strpos((string) ($c['url'] ?? ''), 'https://sut-kunde.test/wp-json/tixomat/v1/site/users') === 0,
             hash_equals(TIX_Site_Users::sign((string) ($c['body'] ?? ''), 'sut-kunde.test', $lic->license_key), (string) ($c['headers']['X-Tix-Signature'] ?? '')), $b['action'] ?? '']);
        su_eq('Protokoll auf der Plattform ohne Passwort', [1, false], [intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_license_log WHERE license_id = %d AND action = 'site_user_create'", $lic->id))),
            (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tix_license_log WHERE license_id = %d AND data LIKE %s", $lic->id, '%Kunden-Pass%'))]);
        $wpdb->delete($st, ['license_id' => $lic->id]); $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $lic->id]); $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic->id]);
    } finally {
        if ($keep === null) delete_option('tix_license_server'); else update_option('tix_license_server', $keep, false);
        foreach ($posts as $p) wp_delete_post($p, true);
        $cleanup_users();
        TIX_Platform_Customers::flush();
        wp_set_current_user(0);
    }
} else {
    // ═══════════════ Kunden-Seite ═══════════════
    $keep = [];
    foreach (['tix_license_server', 'tix_license_key', 'tix_license_mode', TIX_Site_Users::OPT_NONCES] as $o) $keep[$o] = get_option($o, null);
    $key = 'TIX-SUTEST-' . strtoupper(wp_generate_password(10, false));
    update_option('tix_license_key', $key, false); update_option('tix_license_mode', 'selfhosted', false); update_option('tix_license_server', '0', false);
    $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
    $call = function ($action, array $args, $sigkey = null, $ts = null, $nonce = null) use ($key, $host) {
        $raw = wp_json_encode(['action' => $action, 'args' => $args ?: new stdClass(), 'by' => ['name' => 'Pat', 'email' => 'pat@evendis.test'], 'ts' => $ts ?? time(), 'nonce' => $nonce ?? wp_generate_password(20, false)]);
        return su_rest('POST', '/site/users', [], ['X-Tix-Signature' => TIX_Site_Users::sign($raw, $host, $sigkey ?? $key)], $raw);
    };
    $tech_bak = [];
    foreach (get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 0]) as $x) { if (get_user_meta($x, TIX_Site_Users::META_TECH, true) !== '1') { update_user_meta($x, TIX_Site_Users::META_TECH, '1'); $tech_bak[] = intval($x); } }
    try {
        wp_set_current_user(0);
        $own = wp_insert_user(['user_login' => "sut_inh$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_inh$sfx@example.test", 'role' => 'tix_organizer']); $users[] = $own;
        update_user_meta($own, '_tix_site_owner', 1);
        [$s, $d] = $call('create', ['name' => 'Eva Einlass', 'login' => "sut_eva$sfx", 'role' => 'entrance', 'password_mode' => 'set', 'password' => 'Einlass-Pass-2026']);
        $eva = intval($d['user']['id'] ?? 0); $users[] = $eva;
        su_eq('Signierte Anfrage: Zugang angelegt (Rolle Eingang, Passwort gesetzt)', [200, true, 'entrance', true], [$s, $d['password_set'] ?? null, $d['user']['role'] ?? '', $eva > 0 && wp_check_password('Einlass-Pass-2026', get_userdata($eva)->user_pass, $eva)]);
        [$s, $d] = $call('list', []);
        $byid = array_column((array) ($d['users'] ?? []), null, 'id');
        $adm1 = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
        su_eq('Liste: Inhaber nicht entfernbar, Eva mit Login, unser Admin technisch (1.38.731)', [true, false, "sut_eva$sfx", 'technical', false], [$byid[$own]['owner'] ?? null, $byid[$own]['removable'] ?? null, $byid[$eva]['login'] ?? '', $byid[$adm1]['kind'] ?? '', $byid[$adm1]['deletable'] ?? null]);
        su_eq('falscher Schlüssel 401', 401, $call('list', [], 'TIX-FALSCH-1')[0]);
        su_eq('alte Anfrage 401', 401, $call('list', [], null, time() - 600)[0]);
        $nonce = 'fest' . $sfx;
        $call('list', [], null, null, $nonce);
        su_eq('nonce nur einmal', 401, $call('list', [], null, null, $nonce)[0]);
        su_eq('Inhaber entfernen 409', [409, 'tix_is_owner'], (function ($r) { return [$r[0], $r[2]]; })($call('remove', ['id' => $own])));
        // 1.38.734: Passwort direkt setzen auch für den Inhaber, alte Sitzungen/Tokens weg, nie technische/Kundenkonten
        TIX_REST_API::issue_app_token($own, 'altes Gerät');
        [$s, $d] = $call('password', ['id' => $own, 'mode' => 'set', 'password' => 'Inhaber-Pass-2026x']);
        su_eq('Inhaber: Passwort gesetzt, Tokens weg, Passwort nicht in der Antwort', [200, true, true, [], false],
            [$s, $d['password_set'] ?? null, wp_check_password('Inhaber-Pass-2026x', get_userdata($own)->user_pass, $own), (empty(get_user_meta($own, '_tix_app_tokens', true)) ? [] : ['noch da']), strpos(wp_json_encode($d), 'Inhaber-Pass') !== false]);
        [, $d] = $call('list', ['customers' => true]);
        $byid = array_column((array) ($d['users'] ?? []), null, 'id');
        su_eq('Liste: can_set_password Inhaber ja, technisches Konto nein', [true, false], [$byid[$own]['can_set_password'] ?? null, $byid[$adm1]['can_set_password'] ?? null]);
        su_eq('Technisches Konto: kein Passwort 409', [409, 'tix_user_technical'], (function ($r) { return [$r[0], $r[2]]; })($call('password', ['id' => $adm1, 'mode' => 'set', 'password' => 'Technik-Pass-2026x'])));
        $kx = wp_insert_user(['user_login' => "sut_kx$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_kx$sfx@example.test", 'role' => get_role('tix_customer') ? 'tix_customer' : 'subscriber']); $users[] = $kx;
        su_eq('Kundenkonto: kein Passwort 404', 404, $call('password', ['id' => $kx, 'mode' => 'set', 'password' => 'Kunde-Pass-2026x'])[0]);
        su_eq('Protokoll ohne Passwort', false, (bool) $wpdb->get_var("SELECT COUNT(*) FROM " . TIX_Customers::audit_table() . " WHERE summary LIKE '%Inhaber-Pass%'"));
        su_eq('Rolle ändern', [200, 'cashier'], (function ($r) { return [$r[0], $r[1]['user']['role'] ?? '']; })($call('update', ['id' => $eva, 'role' => 'cashier'])));
        su_eq('Entfernen', [200, true], (function ($r) { return [$r[0], $r[1]['removed'] ?? null]; })($call('remove', ['id' => $eva])));

        // ── 1.38.727: alle Konten, Löschen, Inhaberwechsel
        $kunde = wp_insert_user(['user_login' => "sut_kunde$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_kunde$sfx@example.test", 'role' => get_role('tix_customer') ? 'tix_customer' : 'subscriber']); $users[] = $kunde;
        [$s, $d] = $call('list', []);
        $ids = array_map('intval', array_column((array) ($d['users'] ?? []), 'id'));
        su_eq('Kundenkonten nur gezählt', [200, false, true], [$s, in_array($kunde, $ids, true), intval($d['counts']['customer'] ?? 0) >= 1]);
        [, $d] = $call('list', ['customers' => true]);
        $byid = array_column((array) ($d['users'] ?? []), null, 'id');
        su_eq('… mit customers: Kundenkonto dabei, löschbar', ['customer', true], [$byid[$kunde]['kind'] ?? '', $byid[$kunde]['deletable'] ?? null]);
        su_eq('Einziger Inhaber nicht löschbar', [409, 'tix_last_owner'], (function ($r) { return [$r[0], $r[2]]; })($call('delete', ['id' => $own])));
        [$s, $d] = $call('create', ['name' => 'Max Neu', 'login' => "sut_max$sfx", 'role' => 'cashier', 'password_mode' => 'set', 'password' => 'Max-Pass-2026x']);
        $max = intval($d['user']['id'] ?? 0); $users[] = $max;
        $post = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => "SUT Beitrag $sfx", 'post_author' => $own]);
        [$s, $d] = $call('transfer_owner', ['id' => $max, 'old' => 'admin']);
        su_eq('Inhaber wechseln: Max Inhaber, alter Inhaber bleibt Admin', [200, true, '', true],
            [$s, intval(get_user_meta($max, '_tix_site_owner', true)) === 1, (string) get_user_meta($own, '_tix_site_owner', true), in_array('tix_organizer', (array) get_userdata($own)->roles, true)]);
        [$s, $d] = $call('delete', ['id' => $own]);
        su_eq('Alter Inhaber gelöscht, Beitrag an den neuen Inhaber', [200, true, $max, $max], [$s, $d['deleted'] ?? null, $d['reassigned_to'] ?? 0, intval(get_post_field('post_author', $post))]);
        wp_delete_post($post, true);
        su_eq('Neuer Inhaber ist jetzt der einzige: nicht löschbar', 409, $call('delete', ['id' => $max])[0]);
        su_eq('Technisches Konto (unser Admin) nicht löschbar', [409, true], (function ($r) use ($adm1) { return [$r[0], in_array($r[2], ['tix_last_admin', 'tix_last_owner', 'tix_user_technical'], true)]; })($call('delete', ['id' => $adm1])));
        su_eq('Kundenkonto löschen', [200, true, false], (function ($r) use ($kunde) { return [$r[0], $r[1]['deleted'] ?? null, (bool) get_userdata($kunde)]; })($call('delete', ['id' => $kunde])));
        $u2 = wp_insert_user(['user_login' => "sut_neu$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_neu$sfx@example.test", 'role' => 'tix_entrance']); $users[] = $u2;
        [$s] = $call('transfer_owner', ['id' => $u2, 'old' => 'remove']);
        su_eq('Wechsel mit old=remove: alter Inhaber ohne App-Rolle', [200, true, false], [$s, intval(get_user_meta($u2, '_tix_site_owner', true)) === 1, in_array('tix_organizer', (array) get_userdata($max)->roles, true)]);
        su_eq('Technisches Konto kann nicht Inhaber werden', 400, (function () use ($call) { $op = class_exists('TIX_Operator_Access') ? TIX_Operator_Access::account() : 0; $r = $op ? $call('transfer_owner', ['id' => $op]) : [400]; if ($op) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($op); } return $r[0]; })());


        // ── 1.38.730 (Fund XXL): Seite ohne Inhaber – WordPress-Admin „Zum Inhaber machen“ ohne alten Inhaber
        $owner_bak = array_map('intval', get_users(['meta_key' => '_tix_site_owner', 'meta_value' => '1', 'fields' => 'ID', 'number' => 0]));
        foreach ($owner_bak as $x) delete_user_meta($x, '_tix_site_owner');
        $xadm = wp_insert_user(['user_login' => "sut_xadm$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_xadm$sfx@example.test", 'role' => 'administrator']); $users[] = $xadm;
        $xorg = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => "SUT Veranstalter $sfx"]);
        [$s, $d] = $call('list', []);
        $byid = array_column((array) ($d['users'] ?? []), null, 'id');
        su_eq('Ohne Inhaber: owner_missing, weiterer WP-Admin als Team (wp_administrator), kann Inhaber werden', [true, false, 'team', true, true], [$d['owner_missing'] ?? null, $d['has_owner'] ?? null, $byid[$xadm]['kind'] ?? '', $byid[$xadm]['wp_administrator'] ?? null, $byid[$xadm]['can_become_owner'] ?? null]);
        [$s, $d] = $call('transfer_owner', ['id' => $xadm]);
        su_eq('Zum Inhaber machen ohne alten Inhaber: Inhaber, Rolle tix_organizer, bleibt WP-Admin, Veranstalter-Eintrag', [200, true, true, true, $xadm, true],
            [$s, intval(get_user_meta($xadm, '_tix_site_owner', true)) === 1, in_array('tix_organizer', (array) get_userdata($xadm)->roles, true), in_array('administrator', (array) get_userdata($xadm)->roles, true), intval(get_post_meta($xorg, '_tix_org_user_id', true)), $d['has_owner'] ?? null]);
        su_eq('Startklar „Zugang für den Inhaber“', true, class_exists('TIX_Owner_Access') ? TIX_Owner_Access::has_owner() : true);
        // 1.38.731: Inhaber = WP-Admin; Wechsel entzieht dem alten den WP-Admin; weitere WP-Admins → Team; technische bleiben
        $yadm = wp_insert_user(['user_login' => "sut_yadm$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_yadm$sfx@example.test", 'role' => 'administrator']); $users[] = $yadm;
        $tech = wp_insert_user(['user_login' => "evendis-sut$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_t$sfx@example.test", 'role' => 'administrator']); $users[] = $tech;
        $nown = wp_insert_user(['user_login' => "sut_nown$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_nown$sfx@example.test", 'role' => 'tix_entrance']); $users[] = $nown;
        [$s, $d] = $call('transfer_owner', ['id' => $nown, 'old' => 'admin']);
        $r = function ($id) { return array_values((array) get_userdata($id)->roles); };
        su_eq('Neuer Inhaber: administrator + tix_organizer', [200, true, true], [$s, in_array('administrator', $r($nown), true), in_array('tix_organizer', $r($nown), true)]);
        su_eq('Alter Inhaber: kein WP-Admin mehr, Team-Admin', [false, true, ''], [in_array('administrator', $r($xadm), true), in_array('tix_organizer', $r($xadm), true), (string) get_user_meta($xadm, '_tix_site_owner', true)]);
        su_eq('Weiterer WP-Admin aufgeräumt → Team-Admin; technisches Konto (evendis-…) bleibt Admin', [false, true, true], [in_array('administrator', $r($yadm), true), in_array('tix_organizer', $r($yadm), true), in_array('administrator', $r($tech), true)]);
        $byid = array_column((array) ($d['users'] ?? []), null, 'id');
        su_eq('Liste: technisch (login), kein wp_admin mehr', ['technical', 'login', false], [$byid[$tech]['kind'] ?? '', $byid[$tech]['technical_reason'] ?? '', in_array('wp_admin', array_column((array) ($d['users'] ?? []), 'kind'), true)]);
        su_eq('Technisches Konto: nicht löschbar, nicht Inhaber', [409, 400], [$call('delete', ['id' => $tech])[0], $call('transfer_owner', ['id' => $tech])[0]]);
        [$s, $d] = $call('technical', ['id' => $yadm, 'technical' => true]);
        su_eq('Als technisch markieren', [200, 'technical', 'marked'], [$s, $d['user']['kind'] ?? '', $d['user']['technical_reason'] ?? '']);
        $zadm = wp_insert_user(['user_login' => "sut_zadm$sfx", 'user_pass' => wp_generate_password(20), 'user_email' => "sut_zadm$sfx@example.test", 'role' => 'administrator']); $users[] = $zadm;
        [$s, $d] = $call('cleanup', []);
        su_eq('Aufräumen: weiterer WP-Admin → Team', [200, ["sut_zadm$sfx"]], [$s, $d['changed'] ?? []]);
        delete_user_meta($nown, '_tix_site_owner');
        delete_user_meta($xadm, '_tix_site_owner');
        foreach ($owner_bak as $x) if (get_userdata($x)) update_user_meta($x, '_tix_site_owner', 1);
        wp_delete_post($xorg, true);

        update_option('tix_license_server', '1', false);
        su_eq('Seite ohne Lizenz-Betrieb (selbst Lizenz-Server) 404', 404, $call('list', [])[0]);
    } finally {
        foreach ($tech_bak as $x) delete_user_meta($x, TIX_Site_Users::META_TECH);
        foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
        $cleanup_users();
        wp_set_current_user(0);
    }
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
