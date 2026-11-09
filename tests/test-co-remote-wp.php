<?php
/**
 * Integrationstest Co-Veranstalter Teil B – Durchgriff (1.38.697).
 * Einzel-Seite (Lizenzseite) in drei Läufen, weil tix_get_settings() je Prozess zwischenspeichert:
 *   wp eval-file …/test-co-remote-wp.php setup   (Einstellungen setzen)
 *   wp eval-file …/test-co-remote-wp.php run     (Prüfungen)
 *   wp eval-file …/test-co-remote-wp.php cleanup (Einstellungen zurück)
 * Plattform: ein Lauf (`run`). HTTP zur Gegenseite wird per pre_http_request nachgestellt.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Remote')) { echo "WordPress mit Tixomat (TIX_Co_Remote) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = isset($args[0]) ? (string) $args[0] : 'run';
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cx_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function cx_rest($method, $route, array $body = [], array $headers = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    foreach ($headers as $k => $v) $r->set_header($k, $v);
    if ($body && in_array($method, ['GET', 'DELETE'], true)) $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
$GLOBALS['cx_calls'] = [];
$GLOBALS['cx_reply'] = null;
add_filter('pre_http_request', function ($pre, $a, $url) {
    if (strpos($url, '.test/') === false) return $pre;
    $GLOBALS['cx_calls'][] = ['url' => $url, 'body' => json_decode((string) ($a['body'] ?? ''), true), 'headers' => $a['headers'] ?? []];
    $r = is_callable($GLOBALS['cx_reply']) ? call_user_func($GLOBALS['cx_reply'], $url, $a) : [200, ['ok' => true]];
    return ['headers' => [], 'body' => wp_json_encode($r[1]), 'response' => ['code' => $r[0], 'message' => ''], 'cookies' => [], 'filename' => null];
}, 10, 3);

// ═════════════════ Lizenzseite ═════════════════
if (!$multi) {
    if ($mode === 'setup') {
        $s = get_option('tix_settings', []);
        update_option('tix_co_test_backup', ['had' => is_array($s), 'settings' => $s], false);
        $s = is_array($s) ? $s : [];
        $s = array_merge($s, ['syndication_enabled' => 1, 'syndication_api_url' => 'https://plat.test/wp-json/tixomat/v1', 'syndication_api_key' => str_repeat('s', 32),
            'partner_api_enabled' => 1, 'partner_api_key' => str_repeat('p', 32)]);
        update_option('tix_settings', $s);
        echo "setup ok\n"; return;
    }
    if ($mode === 'cleanup') {
        $b = get_option('tix_co_test_backup');
        if (is_array($b)) { if ($b['had']) update_option('tix_settings', $b['settings']); else delete_option('tix_settings'); }
        delete_option('tix_co_test_backup');
        echo "cleanup ok\n"; return;
    }
    if (!get_option('tix_co_test_backup')) { echo "Erst `setup` ausführen.\n"; return; }
    echo "== Lizenzseite\n";
    $admin = get_users(['role' => 'administrator', 'number' => 1]);
    $admin_id = intval($admin[0]->ID);
    $tmp = [];
    try {
        $sid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cxtest Lizenz-Event', 'meta_input' => ['_tix_date_start' => '2027-02-27', '_tix_syndicate' => '1', '_tix_syndicate_remote_id' => 555]]);
        $other = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cxtest anderes Event', 'meta_input' => ['_tix_date_start' => '2027-03-01']]);
        $tmp = [$sid, $other];
        wp_set_current_user($admin_id);
        [, $d] = cx_rest('GET', "/events/$sid/edit");
        cx_eq('Editor: verfügbar, remote', [true, 'remote', null], [$d['event']['co_organizer_available'] ?? null, $d['event']['co_organizer_mode'] ?? null, array_key_exists('co_organizer', $d['event'] ?? []) ? $d['event']['co_organizer'] : 'x']);
        [, $d] = cx_rest('GET', "/events/$other/edit");
        cx_eq('nicht geteiltes Event: nicht verfügbar', [false, 'not_shared'], [$d['event']['co_organizer_available'] ?? null, $d['event']['co_organizer_unavailable_reason'] ?? null]);

        $GLOBALS['cx_reply'] = function ($url, $a) {
            return [200, ['ok' => true, 'created' => true, 'mailed' => true, 'co_organizer' => ['id' => 77, 'name' => 'Evendis Partner', 'logo' => '', 'slug' => 'evendis-partner', 'access' => true, 'status' => 'invited']]];
        };
        cx_eq('Einladen ohne Haken (nur Admin ausgenommen): Admin darf', 409, cx_rest('POST', "/events/$other/co-organizer/invite", ['email' => 'jan@example.test'])[0]);
        cx_eq('nicht geteilt: 409', 409, cx_rest('POST', "/events/$other/co-organizer/invite", ['email' => 'jan@example.test', 'consent' => true])[0]);
        $GLOBALS['cx_calls'] = [];
        [$st, $d] = cx_rest('POST', "/events/$sid/co-organizer/invite", ['email' => 'jan@example.test', 'organizer' => 'Jan Events', 'consent' => true]);
        $call = $GLOBALS['cx_calls'][0] ?? [];
        cx_eq('Einladen: 201, an evendis gemeldet', [201, true, 'invite'], [$st, strpos((string) ($call['url'] ?? ''), 'plat.test/wp-json/tixomat/v1/syndicate/555/co-organizer') !== false, $call['body']['action'] ?? null]);
        cx_eq('Event-Verteilungs-Schlüssel im Kopf', str_repeat('s', 32), $call['headers']['X-Tix-Syndication-Key'] ?? null);
        $token = (string) ($call['body']['token'] ?? '');
        cx_eq('Schlüssel nur als Hash gespeichert', [true, hash('sha256', $token)], [strlen($token) >= 32, get_post_meta($sid, '_tix_co_token_hash', true)]);
        cx_eq('Payload: remote, Name, Zugriff', [true, 'Evendis Partner', true], [$d['co_organizer']['remote'] ?? null, $d['co_organizer']['name'] ?? null, $d['co_organizer']['access'] ?? null]);
        [, $d] = cx_rest('GET', "/events/$sid");
        cx_eq('Liste: shared_with', 'Evendis Partner', $d['event']['shared_with']['name'] ?? null);
        cx_eq('öffentlich: „mit …“', 'Evendis Partner', TIX_Co_Organizer::public_ref($sid)['name'] ?? null);
        cx_eq('per id setzen: 400', 400, cx_rest('POST', "/events/$sid", ['co_organizer' => ['id' => 12, 'access' => true]])[0]);

        // Durchgriff
        $ph = ['X-Tix-Partner-Id' => 'plat.test', 'X-Tix-Partner-Key' => str_repeat('p', 32), 'X-Tix-Co-Token' => $token];
        $co = function ($method, $path, $q = [], $b = [], $hdr = null) use ($sid, $ph) {
            wp_set_current_user(0);
            $r = cx_rest('POST', "/partner/co/$sid", ['method' => $method, 'path' => $path, 'query' => $q ?: new stdClass(), 'body' => $b ?: new stdClass()], $hdr ?: $ph);
            return $r;
        };
        [$st, $d] = $co('GET', "/events/$sid/edit");
        cx_eq('Durchgriff: Editor 200', [200, 200, $sid], [$st, $d['status'] ?? null, intval($d['data']['event']['id'] ?? 0)]);
        cx_eq('Durchgriff: falscher Schlüssel 401', 401, $co('GET', "/events/$sid/edit", [], [], array_merge($ph, ['X-Tix-Co-Token' => str_repeat('x', 48)]))[0]);
        cx_eq('Durchgriff: falscher Partner-Schlüssel 401', 401, $co('GET', "/events/$sid/edit", [], [], array_merge($ph, ['X-Tix-Partner-Key' => str_repeat('q', 32)]))[0]);
        cx_eq('Durchgriff: anderes Event 403', [200, 403, 'tix_co_forbidden'], (function ($r) { return [$r[0], $r[1]['status'] ?? 0, $r[1]['data']['code'] ?? '']; })($co('GET', "/events/$other/edit")));
        cx_eq('Durchgriff: löschen 403', 403, $co('DELETE', "/events/$sid")[1]['status'] ?? 0);
        cx_eq('Durchgriff: duplizieren 403', 403, $co('POST', "/events/$sid/duplicate")[1]['status'] ?? 0);
        cx_eq('Durchgriff: Co ändern 403', 403, $co('POST', "/events/$sid", [], ['co_organizer' => null])[1]['status'] ?? 0);
        cx_eq('Durchgriff: Export Bestellungen 403', 403, $co('GET', '/organizer/export', ['type' => 'orders', 'event_id' => $sid])[1]['status'] ?? 0);
        cx_eq('Durchgriff: Bestellungen ohne event_id 403', 403, $co('GET', '/orders')[1]['status'] ?? 0);
        cx_eq('Durchgriff: Bestellungen dieses Events freigegeben', true, ($co('GET', '/orders', ['event_id' => $sid])[1]['data']['code'] ?? '') !== 'tix_co_forbidden');
        foreach (['POST /orders/123/refund', 'POST /orders/123/cancel', 'POST /pos/orders/123/void'] as $rt) {
            [$mt, $pt] = explode(' ', $rt);
            $r = TIX_Co_Remote::allowed($mt, $pt, [], [], $sid);
            cx_eq("Durchgriff: $rt gesperrt", 'tix_co_forbidden', is_wp_error($r) ? $r->get_error_code() : $r);
        }
        cx_eq('Durchgriff: Gästeliste 200', 200, $co('GET', "/events/$sid/guestlist")[1]['status'] ?? 0);
        cx_eq('Durchgriff: unbekannte Route 403', 403, $co('GET', '/team')[1]['status'] ?? 0);
        [, $d] = $co('POST', "/events/$sid", [], ['title' => 'Cxtest Lizenz-Event neu']);
        cx_eq('Durchgriff: speichern 200, Titel geändert', [200, 'Cxtest Lizenz-Event neu'], [$d['status'] ?? 0, get_the_title($sid)]);
        $tech = get_users(['meta_key' => '_tix_co_remote_user', 'meta_value' => 77, 'number' => 1]);
        $tech_id = $tech ? intval($tech[0]->ID) : 0;
        if ($tech_id) $tmp_user = $tech_id;
        cx_eq('technisches Konto ohne Rolle, ohne Rechte danach', [true, [], false], [$tech_id > 0, array_values((array) get_userdata($tech_id)->roles), user_can($tech_id, 'tix_organizer')]);
        cx_eq('nach dem Durchgriff kein Nutzer gesetzt', 0, get_current_user_id());

        // Zugriff aus
        wp_set_current_user($admin_id);
        $GLOBALS['cx_calls'] = [];
        $GLOBALS['cx_reply'] = function () { return [200, ['ok' => true]]; };
        cx_eq('Zugriff aus: 200 + Meldung', [200, 'access', false], (function ($r) { $c = $GLOBALS['cx_calls'][0]['body'] ?? []; return [$r[0], $c['action'] ?? null, $c['access'] ?? null]; })(cx_rest('POST', "/events/$sid", ['co_organizer' => ['access' => false]])));
        cx_eq('Durchgriff ohne Zugriff 403', [403, 'tix_co_no_access'], (function ($r) { return [$r[0], $r[2]]; })($co('GET', "/events/$sid/edit")));
        wp_set_current_user($admin_id);
        $GLOBALS['cx_reply'] = function () { return [502, ['code' => 'x']]; };
        cx_eq('evendis nicht erreichbar: 502, nichts geändert', [502, false], (function ($r) use ($sid) { return [$r[0], TIX_Co_Remote::payload($sid)['access']]; })(cx_rest('POST', "/events/$sid", ['co_organizer' => ['access' => true]])));
        $GLOBALS['cx_reply'] = function () { return [200, ['ok' => true]]; };
        $GLOBALS['cx_calls'] = [];
        [$st] = cx_rest('POST', "/events/$sid", ['co_organizer' => null]);
        cx_eq('entfernen: 200, Meldung clear, alles weg', [200, 'clear', '', ''], [$st, $GLOBALS['cx_calls'][0]['body']['action'] ?? null, get_post_meta($sid, '_tix_co_remote', true), get_post_meta($sid, '_tix_co_token_hash', true)]);
        cx_eq('Durchgriff nach Entfernen 401', 401, $co('GET', "/events/$sid/edit")[0]);
    } finally {
        foreach ($tmp as $id) wp_delete_post($id, true);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach (get_users(['meta_key' => '_tix_co_remote_user', 'meta_value' => 77, 'fields' => 'ID']) as $u) wp_delete_user($u);
        wp_set_current_user(0);
    }
    echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
    return;
}

// ═════════════════ Plattform ═════════════════
if ($mode !== 'run') { echo "$mode: auf der Plattform nicht nötig\n"; return; }
echo "== Plattform\n";
$keep_p = get_option('tix_partners', null);
$tmp_u = []; $tmp_p = [];
$mku = function () use (&$tmp_u) { $id = wp_insert_user(['user_login' => 'cx_' . wp_rand(1000, 999999), 'user_pass' => wp_generate_password(20), 'user_email' => 'cx_' . wp_rand(1000, 999999) . '@example.test', 'role' => 'tix_organizer']); $tmp_u[] = $id; return $id; };
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = intval($admin[0]->ID);
try {
    $kin = str_repeat('i', 32); $kout = str_repeat('o', 32);
    $all = is_array($keep_p) ? $keep_p : [];
    $all['cxtest'] = ['id' => 'cxtest', 'name' => 'KK Test', 'api_base' => 'https://kk.test/wp-json/tixomat/v1', 'key_in' => $kin, 'key_out' => $kout, 'organizer_id' => 0, 'sales_enabled' => 1];
    $all['cxother'] = ['id' => 'cxother', 'name' => 'Andere', 'api_base' => 'https://other.test/wp-json/tixomat/v1', 'key_in' => str_repeat('j', 32), 'key_out' => str_repeat('k', 32)];
    update_option('tix_partners', $all);
    $up = $mku(); $op = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'Cxtest KK', 'meta_input' => ['_tix_org_user_id' => $up]]); $tmp_p[] = $op;
    $mirror = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cxtest Spiegel', 'meta_input' => ['_tix_syndicated' => '1', '_tix_source_partner' => 'cxtest', '_tix_source_id' => 1171, '_tix_source_site' => 'KK Test', '_tix_organizer_id' => $op, '_tix_date_start' => '2027-02-27']]);
    $tmp_p[] = $mirror;
    $kh = ['X-Tix-Syndication-Key' => $kin];
    $mail = 'cx_neu_' . wp_rand(1000, 999999) . '@example.test';
    $tok = str_repeat('t', 48);
    wp_set_current_user(0);
    cx_eq('Meldung: falscher Schlüssel 401', 401, cx_rest('POST', "/syndicate/$mirror/co-organizer", ['action' => 'invite', 'email' => $mail, 'organizer' => 'Cx Neu', 'token' => $tok], ['X-Tix-Syndication-Key' => str_repeat('z', 32)])[0]);
    cx_eq('Meldung: fremder Partner 404', 404, cx_rest('POST', "/syndicate/$mirror/co-organizer", ['action' => 'invite', 'email' => $mail, 'organizer' => 'Cx Neu', 'token' => $tok], ['X-Tix-Syndication-Key' => str_repeat('j', 32)])[0]);
    [$st, $d] = cx_rest('POST', "/syndicate/$mirror/co-organizer", ['action' => 'invite', 'email' => $mail, 'organizer' => 'Cx Neu', 'name' => 'Jan', 'access' => true, 'token' => $tok], $kh);
    $nu = get_user_by('email', $mail); if ($nu) $tmp_u[] = $nu->ID;
    $no = intval($d['co_organizer']['id'] ?? 0); if ($no) $tmp_p[] = $no;
    cx_eq('Meldung invite: 200, Profil angelegt', [200, true, 'co'], [$st, $d['created'] ?? null, get_post_meta($no, '_tix_org_kind', true)]);
    cx_eq('Spiegel-Meta gesetzt', [$no, '1', $tok], [intval(get_post_meta($mirror, '_tix_co_remote_oid', true)), get_post_meta($mirror, '_tix_co_remote_access', true), get_post_meta($mirror, '_tix_co_remote_token', true)]);
    cx_eq('lokal kein Zugriff aufs Spiegel-Event', '', get_post_meta($mirror, '_tix_co_organizer_id', true));
    cx_eq('öffentlich: co_organizer_info = neues Profil', $no, intval(TIX_Co_Organizer::public_ref($mirror)['id'] ?? 0));

    wp_set_current_user($nu->ID);
    [$st, $d] = cx_rest('GET', '/co-remote/events');
    $it = $d['items'][0] ?? [];
    cx_eq('Liste: Lizenz-Event mit Quelle', [200, 1, $mirror, 1171, 'co', true], [$st, count($d['items'] ?? []), intval($it['id'] ?? 0), intval($it['source_id'] ?? 0), $it['event_role'] ?? null, $it['ready'] ?? null]);
    $GLOBALS['cx_calls'] = [];
    $GLOBALS['cx_reply'] = function ($url, $a) { return [200, ['ok' => true, 'status' => 200, 'data' => ['ok' => true, 'event' => ['id' => 1171, 'title' => 'X']]]]; };
    [$st, $d] = cx_rest('GET', "/co-remote/$mirror/events/1171/edit", ['x' => '1']);
    $c = $GLOBALS['cx_calls'][0] ?? [];
    cx_eq('Weiterleitung: Antwort der Lizenzseite', [200, 1171], [$st, intval($d['event']['id'] ?? 0)]);
    cx_eq('Weiterleitung: Adresse, Schlüssel, Route', [true, $kout, $tok, '/events/1171/edit', 'GET', 'evendis'], [strpos((string) ($c['url'] ?? ''), 'kk.test/wp-json/tixomat/v1/partner/co/1171') !== false,
        $c['headers']['X-Tix-Partner-Key'] ?? null, $c['headers']['X-Tix-Co-Token'] ?? null, $c['body']['path'] ?? null, $c['body']['method'] ?? null, 'evendis']);
    $GLOBALS['cx_reply'] = function () { return [200, ['ok' => false, 'status' => 403, 'data' => ['code' => 'tix_co_forbidden', 'message' => 'nein']]]; };
    cx_eq('Weiterleitung: Sperre der Lizenzseite 403', [403, 'tix_co_forbidden'], (function ($r) { return [$r[0], $r[2]]; })(cx_rest('DELETE', "/co-remote/$mirror/events/1171")));
    $GLOBALS['cx_reply'] = function () { return [401, ['code' => 'tix_co_token', 'message' => 'x']]; };
    cx_eq('Weiterleitung: Schlüssel abgelehnt 401', 401, cx_rest('GET', "/co-remote/$mirror/events/1171/edit")[0]);
    wp_set_current_user($up);
    cx_eq('anderer Veranstalter: 403', 403, cx_rest('GET', "/co-remote/$mirror/events/1171/edit")[0]);

    wp_set_current_user($admin_id);
    [, $d] = cx_rest('GET', '/platform/co-organizers');
    $row = array_values(array_filter($d['items'] ?? [], function ($x) use ($no) { return intval($x['organizer_id']) === $no; }));
    cx_eq('Plattform-Liste: Lizenz-Event mit Quelle', [1, 1171, true], [count($row), intval($row[0]['events'][0]['remote']['source_id'] ?? 0), $row[0]['events'][0]['access'] ?? null]);

    wp_set_current_user(0);
    cx_eq('Meldung access aus: 200', 200, cx_rest('POST', "/syndicate/$mirror/co-organizer", ['action' => 'access', 'access' => false], $kh)[0]);
    wp_set_current_user($nu->ID);
    cx_eq('ohne Zugriff: Liste leer, Weiterleitung 403', [0, 403], [count(cx_rest('GET', '/co-remote/events')[1]['items'] ?? [1]), cx_rest('GET', "/co-remote/$mirror/events/1171/edit")[0]]);
    wp_set_current_user(0);
    cx_eq('Meldung clear: 200, Meta weg', [200, ''], (function ($r) use ($mirror) { return [$r[0], get_post_meta($mirror, '_tix_co_remote_oid', true)]; })(cx_rest('POST', "/syndicate/$mirror/co-organizer", ['action' => 'clear'], $kh)));
    cx_eq('Syndizierung schützt Teil-B-Meta', true, in_array('_tix_co_remote_token', TIX_Syndication_Receive::PROTECTED_META, true));
} finally {
    if ($keep_p === null) delete_option('tix_partners'); else update_option('tix_partners', $keep_p);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tmp_p as $id) if ($id) wp_delete_post($id, true);
    foreach ($tmp_u as $id) if ($id) wp_delete_user($id);
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
