<?php
/**
 * Integrationstest Co-Veranstalter anzeigen, trennen, Kunden-Seiten (1.38.706, Vertrag `uebergaben/vertrag-co-anzeigen-trennen.md`).
 * Plattform: wp eval-file …/test-co-sites-wp.php
 * Lizenzseite: setup [off] | run | cleanup (tix_get_settings speichert je Prozess zwischen).
 * HTTP zu anderen Seiten (*.test) wird per pre_http_request nachgestellt, Mails per pre_wp_mail abgefangen.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Sites')) { echo "WordPress mit Tixomat (TIX_Co_Sites) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = isset($args[0]) ? (string) $args[0] : 'run';
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cs_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function cs_rest($method, $route, array $body = [], array $headers = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . (strpos($route, '?') !== false ? strtok($route, '?') : $route));
    if (strpos($route, '?') !== false) { parse_str(substr($route, strpos($route, '?') + 1), $qq); $r->set_query_params($qq); }
    foreach ($headers as $k => $v) $r->set_header($k, $v);
    if ($body && in_array($method, ['GET', 'DELETE'], true)) $r->set_query_params(array_merge($r->get_query_params(), $body));
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = rest_get_server()->response_to_data($res, false);
    return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : ''];
}
$GLOBALS['cs_calls'] = [];
$GLOBALS['cs_reply'] = null;
add_filter('pre_http_request', function ($pre, $a, $url) {
    if (strpos($url, '.test/') === false) return $pre;
    $GLOBALS['cs_calls'][] = ['url' => $url, 'body' => json_decode((string) ($a['body'] ?? ''), true), 'headers' => $a['headers'] ?? []];
    $r = is_callable($GLOBALS['cs_reply']) ? call_user_func($GLOBALS['cs_reply'], $url, $a) : [200, ['ok' => true]];
    return ['headers' => [], 'body' => wp_json_encode($r[1]), 'response' => ['code' => $r[0], 'message' => ''], 'cookies' => [], 'filename' => null];
}, 10, 3);
$GLOBALS['cs_mails'] = [];
add_filter('pre_wp_mail', function ($r, $a) { $GLOBALS['cs_mails'][] = $a; return true; }, 1, 2);
function cs_last_call($needle) {
    foreach (array_reverse($GLOBALS['cs_calls']) as $c) if (strpos($c['url'], $needle) !== false) return $c;
    return null;
}

// ═════════════════ Lizenzseite ═════════════════
if (!$multi) {
    if ($mode === 'setup') {
        $s = get_option('tix_settings', []);
        update_option('tix_cs_test_backup', ['had' => is_array($s), 'settings' => $s], false);
        $s = is_array($s) ? $s : [];
        $s = array_merge($s, ['syndication_enabled' => 1, 'syndication_api_url' => 'https://plat.test/wp-json/tixomat/v1', 'syndication_api_key' => str_repeat('s', 32),
            'partner_api_enabled' => 1, 'partner_api_key' => str_repeat('p', 32), 'co_remote_enabled' => ($args[1] ?? '') === 'off' ? 0 : 1]);
        update_option('tix_settings', $s);
        echo "setup ok\n"; return;
    }
    if ($mode === 'cleanup') {
        $b = get_option('tix_cs_test_backup');
        if (is_array($b)) { if ($b['had']) update_option('tix_settings', $b['settings']); else delete_option('tix_settings'); }
        delete_option('tix_cs_test_backup');
        echo "cleanup ok\n"; return;
    }
    if (!get_option('tix_cs_test_backup')) { echo "Erst `setup` ausführen.\n"; return; }
    echo "== Lizenzseite\n";
    $admin = get_users(['role' => 'administrator', 'number' => 1]);
    wp_set_current_user(intval($admin[0]->ID));
    $tmp = [];
    $tok = str_repeat('t', 48);
    $set_remote = function ($sid, $site = null) use ($tok) {
        delete_post_meta($sid, '_tix_co_remotes'); update_post_meta($sid, '_tix_co_remote', ['organizer_id' => 77, 'name' => 'MDJ Test', 'logo' => '', 'slug' => '', 'email' => 'mdj@example.test', 'access' => true, 'status' => 'active', 'since' => gmdate('c'), 'site' => $site]);
        update_post_meta($sid, '_tix_co_token_hash', hash('sha256', $tok));
    };
    try {
        $sid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cstest Lizenz-Event', 'meta_input' => ['_tix_date_start' => '2027-02-27', '_tix_syndicate' => '1', '_tix_syndicate_remote_id' => 555]]);
        $tmp[] = $sid;
        $set_remote($sid);
        [, $d] = cs_rest('GET', "/events/$sid/edit");
        $ev = $d['event'] ?? $d;
        $e0 = $ev['co_organizers'][0] ?? [];
        cs_eq('Editor: Liste mit remote-Eintrag', [1, 'remote', 'evendis', 'auf plat.test', true], [count($ev['co_organizers'] ?? []), $e0['kind'] ?? '', $e0['origin']['type'] ?? '', $e0['origin']['label'] ?? '', $e0['removable'] ?? null]);
        cs_eq('Editor: trennbar', true, $ev['co_organizer_removable'] ?? null);
        if (!TIX_Co_Remote::enabled()) {
            echo "== Schalter aus\n";
            cs_eq('Schalter aus: trotzdem angezeigt, nicht verfügbar', [false, 'disabled', 1], [$ev['co_organizer_available'] ?? null, $ev['co_organizer_unavailable_reason'] ?? null, count($ev['co_organizers'] ?? [])]);
            $GLOBALS['cs_reply'] = function () { return [200, ['ok' => true, 'co_organizer' => null]]; };
            [$st, $d] = cs_rest('DELETE', "/events/$sid/co-organizer");
            cs_eq('Schalter aus: Trennen geht, clear gemeldet', [200, 'removed', true, 'clear'], [$st, $d['changed'] ?? '', $d['released']['platform'] ?? null, cs_last_call('/syndicate/555/co-organizer')['body']['action'] ?? '']);
            cs_eq('Schalter aus: Meta weg', ['', ''], [get_post_meta($sid, '_tix_co_remote', true), get_post_meta($sid, '_tix_co_token_hash', true)]);
            [$st, , $c] = cs_rest('GET', "/events/$sid/co-organizer/sites", ['q' => 'kmt']);
            cs_eq('Schalter aus: Suche 409', [409, 'tix_co_remote_unavailable'], [$st, $c]);
            return;
        }
        // 1.38.709: von Hand als JSON gesetzt, Status eingeladen
        delete_post_meta($sid, '_tix_co_remotes'); update_post_meta($sid, '_tix_co_remote', wp_json_encode(['organizer_id' => 9507, 'name' => 'MDJ Veranstaltungs UG', 'access' => true, 'status' => 'invited', 'since' => '2026-11-09T10:00:00+00:00']));
        [, $d] = cs_rest('GET', "/events/$sid/edit");
        $e0 = ($d['event'] ?? $d)['co_organizers'][0] ?? [];
        cs_eq('JSON-Meta, eingeladen: angezeigt + trennbar', [9507, 'invited', true], [$e0['id'] ?? 0, $e0['status'] ?? '', $e0['removable'] ?? null]);
        // 1.38.709: erster Durchgriff von der Plattform setzt „eingeladen“ → „aktiv“
        $set_remote($sid);
        delete_post_meta($sid, '_tix_co_remotes'); update_post_meta($sid, '_tix_co_remote', array_merge(get_post_meta($sid, '_tix_co_remote', true), ['status' => 'invited']));
        wp_set_current_user(0);
        $r = new WP_REST_Request('POST', "/tixomat/v1/partner/co/$sid");
        $r->set_header('X-Tix-Partner-Id', 'plat.test'); $r->set_header('X-Tix-Partner-Key', str_repeat('p', 32)); $r->set_header('X-Tix-Co-Token', $tok);
        $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode(['method' => 'GET', 'path' => "/events/$sid/edit"]));
        $st = rest_do_request($r)->get_status();
        cs_eq('Durchgriff: Status wird aktiv', [200, 'active'], [$st, get_post_meta($sid, '_tix_co_remote', true)['status'] ?? '']);
        wp_set_current_user(intval($admin[0]->ID));
        // Zugriff umschalten übernimmt den Status der Plattform
        delete_post_meta($sid, '_tix_co_remotes'); update_post_meta($sid, '_tix_co_remote', array_merge(get_post_meta($sid, '_tix_co_remote', true), ['status' => 'invited', 'consent' => ['at' => gmdate('c'), 'by' => 1]]));
        $GLOBALS['cs_reply'] = function () { return [200, ['ok' => true, 'co_organizer' => ['id' => 77, 'name' => 'MDJ Test', 'access' => false, 'status' => 'active']]]; };
        [$st] = cs_rest('POST', "/events/$sid", ['co_organizer' => ['access' => false]]);
        $dd = get_post_meta($sid, '_tix_co_remote', true);
        cs_eq('Zugriff aus: Status von der Plattform', [200, false, 'active'], [$st, $dd['access'] ?? null, $dd['status'] ?? '']);
        // Kunden-Seite als Herkunft
        $set_remote($sid, ['key' => 'h-kmt', 'host' => 'kmt.test', 'name' => 'KMT Test']);
        [, $d] = cs_rest('GET', "/events/$sid/edit");
        $e0 = ($d['event'] ?? $d)['co_organizers'][0] ?? [];
        cs_eq('Editor: Kunden-Seite', ['site', 'eigene Seite kmt.test', 'h-kmt'], [$e0['kind'] ?? '', $e0['origin']['label'] ?? '', $e0['site']['key'] ?? '']);
        cs_eq('Editor: Kunden-Seiten-Suche an', true, ($d['event'] ?? $d)['co_organizer_site_search'] ?? null);

        // Trennen: Plattform nicht erreichbar
        $GLOBALS['cs_reply'] = function () { return [500, ['code' => 'x']]; };
        [$st, , $c] = cs_rest('DELETE', "/events/$sid/co-organizer");
        cs_eq('Trennen ohne Antwort: 502', [502, 'tix_co_remote_unreachable'], [$st, $c]);
        cs_eq('… Meta bleibt', true, is_array(get_post_meta($sid, '_tix_co_remote', true)));
        [$st, $d] = cs_rest('DELETE', "/events/$sid/co-organizer?force=1");
        cs_eq('force=1: lokal getrennt', [200, 'removed', false], [$st, $d['changed'] ?? '', $d['released']['platform'] ?? null]);
        cs_eq('… Meta + Schlüssel weg', ['', ''], [get_post_meta($sid, '_tix_co_remote', true), get_post_meta($sid, '_tix_co_token_hash', true)]);
        [$st, $d] = cs_rest('DELETE', "/events/$sid/co-organizer");
        cs_eq('nochmal: unchanged', [200, 'unchanged'], [$st, $d['changed'] ?? '']);

        // Trennen mit Bestätigung
        $set_remote($sid);
        $GLOBALS['cs_reply'] = function () { return [200, ['ok' => true, 'co_organizer' => null]]; };
        [$st, $d] = cs_rest('DELETE', "/events/$sid/co-organizer");
        cs_eq('Trennen: bestätigt', [200, 'removed', true, 'clear'], [$st, $d['changed'] ?? '', $d['released']['platform'] ?? null, cs_last_call('/syndicate/555/co-organizer')['body']['action'] ?? '']);

        // Alter Weg POST co_organizer: null
        $set_remote($sid);
        [$st] = cs_rest('POST', "/events/$sid", ['co_organizer' => null]);
        cs_eq('POST co_organizer null: getrennt', [200, ''], [$st, get_post_meta($sid, '_tix_co_remote', true)]);

        // Suche über die Plattform
        $GLOBALS['cs_reply'] = function ($url) { return [200, ['ok' => true, 'items' => [['key' => 'h-kmt', 'name' => 'KMT Test', 'host' => 'kmt.test', 'ready' => true, 'reason' => null, 'message' => null]]]]; };
        [$st, $d] = cs_rest('GET', "/events/$sid/co-organizer/sites", ['q' => 'kmt']);
        $c = cs_last_call('/syndicate/co-sites');
        cs_eq('Suche: Treffer von der Plattform', [200, 'h-kmt'], [$st, $d['items'][0]['key'] ?? '']);
        cs_eq('Suche: mit Partner-Schlüssel + q', [true, true], [($c['headers']['X-Tix-Syndication-Key'] ?? '') === str_repeat('s', 32), strpos($c['url'] ?? '', 'q=kmt') !== false]);
        cs_eq('Suche: kurz → 400', 400, cs_rest('GET', "/events/$sid/co-organizer/sites", ['q' => 'k'])[0]);

        // Einladen einer Kunden-Seite
        $GLOBALS['cs_reply'] = function () { return [200, ['ok' => true, 'created' => true, 'mailed' => false, 'co_organizer' => ['id' => 88, 'name' => 'KMT Test', 'logo' => '', 'slug' => '', 'access' => true, 'status' => 'active', 'site' => ['key' => 'h-kmt', 'host' => 'kmt.test', 'name' => 'KMT Test']]]]; };
        [$st, $d] = cs_rest('POST', "/events/$sid/co-organizer/invite", ['site' => 'h-kmt', 'access' => true, 'consent' => true]);
        $c = cs_last_call('/syndicate/555/co-organizer');
        cs_eq('Einladen Seite: 201, site gesendet', [201, 'invite', 'h-kmt', ''], [$st, $c['body']['action'] ?? '', $c['body']['site'] ?? '', $c['body']['email'] ?? 'x']);
        cs_eq('Einladen Seite: gespeichert als site', ['site', 'kmt.test'], [$d['co_organizers'][0]['kind'] ?? '', get_post_meta($sid, '_tix_co_remote', true)['site']['host'] ?? '']);
        $tok2 = (string) ($c['body']['token'] ?? '');

        // Freigabe durch die Plattform
        $rel = function ($t) use ($sid) {
            $r = new WP_REST_Request('POST', "/tixomat/v1/partner/co/$sid/release");
            $r->set_header('X-Tix-Partner-Id', 'plat.test'); $r->set_header('X-Tix-Partner-Key', str_repeat('p', 32)); $r->set_header('X-Tix-Co-Token', $t);
            return rest_do_request($r)->get_status();
        };
        wp_set_current_user(0);
        cs_eq('release: falscher Schlüssel 401', 401, $rel(str_repeat('x', 48)));
        cs_eq('release: richtiger Schlüssel 200', 200, $rel($tok2));
        cs_eq('release: Meta weg', ['', ''], [get_post_meta($sid, '_tix_co_remote', true), get_post_meta($sid, '_tix_co_token_hash', true)]);
    } catch (Throwable $e) {
        $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
    } finally {
        foreach ($tmp as $id) wp_delete_post($id, true);
        wp_set_current_user(0);
        echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
    }
    return;
}

// ═════════════════ Plattform ═════════════════
echo "== Plattform\n";
$tmp_u = []; $tmp_p = [];
$mku = function ($role) use (&$tmp_u) {
    $id = wp_insert_user(['user_login' => 'cs_' . $role . '_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'cs_' . wp_rand(1000, 999999) . '@example.test', 'role' => $role]);
    $tmp_u[] = $id; return $id;
};
$mko = function ($uid, $name) use (&$tmp_p) {
    $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $name, 'meta_input' => ['_tix_org_user_id' => $uid]]);
    $tmp_p[] = $id; return $id;
};
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = intval($admin[0]->ID);
$p_backup = get_option(TIX_Partners::OPTION, null);
$kmt_key = 'tixp_' . str_repeat('k', 32); $kk_key = 'tixp_' . str_repeat('c', 32); $off_key = '';
$sf = function () { return 'https://studio.example.test'; };
add_filter('pre_option_' . TIX_Studio::OPTION, $sf);
try {
    $ua = $mku('tix_organizer'); $oa = $mko($ua, 'Csxtest Ersteller');
    $ub = $mku('tix_organizer'); $ob = $mko($ub, 'Csxtest Partner');
    $okk = $mko(0, 'Csxtest KK-Profil');
    $parts = is_array($p_backup) ? $p_backup : [];
    $parts['cskmt'] = ['id' => 'cskmt', 'name' => 'Csxtest KMT', 'api_base' => 'https://kmt.test/wp-json/tixomat/v1', 'key_in' => $kmt_key, 'key_out' => str_repeat('o', 32), 'organizer_id' => 0];
    $parts['csoff'] = ['id' => 'csoff', 'name' => 'Csxtest Offline', 'api_base' => 'https://offline.test/wp-json/tixomat/v1', 'key_in' => '', 'key_out' => '', 'organizer_id' => 0];
    $parts['cskk']  = ['id' => 'cskk', 'name' => 'Csxtest KK', 'api_base' => 'https://kk.test/wp-json/tixomat/v1', 'key_in' => $kk_key, 'key_out' => str_repeat('q', 32), 'organizer_id' => $okk];
    update_option(TIX_Partners::OPTION, $parts, false);
    TIX_Platform_Customers::flush();

    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Csxtest Konzert', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-03-01']]);
    $tmp_p[] = $ev;

    // ── Editor Teil A
    wp_set_current_user($ua);
    TIX_Co_Organizer::set($ev, $ob, true, $ua);
    [, $d] = cs_rest('GET', "/events/$ev/edit");
    $e = $d['event'] ?? $d;
    $e0 = $e['co_organizers'][0] ?? [];
    cs_eq('Editor A: local, platform, trennbar', ['local', 'platform', true, true, true], [$e0['kind'] ?? '', $e0['origin']['type'] ?? '', $e0['removable'] ?? null, $e['co_organizer_removable'] ?? null, $e['co_organizer_site_search'] ?? null]);
    wp_set_current_user($ub);
    cs_eq('Co selbst darf nicht trennen: 403', 403, cs_rest('DELETE', "/events/$ev/co-organizer")[0]);
    [, $d] = cs_rest('GET', "/events/$ev/edit");
    cs_eq('Co sieht Eintrag ohne Knopf', [1, false], [count(($d['event'] ?? $d)['co_organizers'] ?? []), ($d['event'] ?? $d)['co_organizer_removable'] ?? null]);
    wp_set_current_user($ua);
    [$st, $d] = cs_rest('DELETE', "/events/$ev/co-organizer");
    cs_eq('Ersteller trennt', [200, 'removed', 0], [$st, $d['changed'] ?? '', TIX_Co_Organizer::ref_id($ev)]);
    cs_eq('Feed an den Co', true, in_array('Nicht mehr Co-Veranstalter', array_column((array) get_user_meta($ub, TIX_Notifications::META_USER, true), 'title'), true));
    cs_eq('Protokoll removed via creator', ['removed', 'creator'], [TIX_Co_Organizer::org_log($oa)[0]['what'] ?? '', TIX_Co_Organizer::org_log($oa)[0]['via'] ?? '']);
    cs_eq('nochmal: unchanged', 'unchanged', cs_rest('DELETE', "/events/$ev/co-organizer")[1]['changed'] ?? '');

    // 1.38.709: Lizenzseite trennt (clear) → Event sofort aus der Liste des Co-Veranstalters
    $mir0 = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Csxtest Spiegel0', 'meta_input' => ['_tix_organizer_id' => $okk, '_tix_date_start' => '2027-04-02',
        '_tix_syndicated' => '1', '_tix_source_partner' => 'cskk', '_tix_source_id' => 1170, '_tix_co_remote_oid' => $ob, '_tix_co_remote_access' => '1', '_tix_co_remote_token' => str_repeat('y', 48)]]);
    $tmp_p[] = $mir0;
    wp_set_current_user($ub);
    cs_eq('Co sieht Spiegel in /co-remote/events', true, in_array($mir0, array_column(cs_rest('GET', '/co-remote/events')[1]['items'] ?? [], 'id'), true));
    wp_set_current_user(0);
    [$st] = cs_rest('POST', "/syndicate/$mir0/co-organizer", ['action' => 'clear'], ['X-Tix-Syndication-Key' => $kk_key]);
    wp_set_current_user($ub);
    cs_eq('Nach clear: Metas weg, nicht mehr in der Liste, Durchgriff 403', [200, '', false, 403],
        [$st, (string) get_post_meta($mir0, '_tix_co_remote_oid', true), in_array($mir0, array_column(cs_rest('GET', '/co-remote/events')[1]['items'] ?? [], 'id'), true), cs_rest('GET', "/co-remote/$mir0/events/1170/edit")[0]]);
    cs_eq('Feed an den Co: nicht mehr Co-Veranstalter', true, in_array('Nicht mehr Co-Veranstalter', array_column((array) get_user_meta($ub, TIX_Notifications::META_USER, true), 'title'), true));
    wp_set_current_user($ua);

    // ── Suche Kunden-Seiten
    [$st, $d] = cs_rest('GET', '/co-organizer/sites', ['q' => 'csxtest']);
    $keys = array_column($d['items'] ?? [], 'key');
    $byk = array_column($d['items'] ?? [], null, 'key');
    cs_eq('Suche: KMT, Offline, KK gefunden', [200, true, true], [$st, isset($byk['p-cskmt']), isset($byk['p-csoff'])]);
    cs_eq('Suche: bereit / nicht verbunden', [true, false, 'not_connected'], [$byk['p-cskmt']['ready'] ?? null, $byk['p-csoff']['ready'] ?? null, $byk['p-csoff']['reason'] ?? null]);
    cs_eq('Suche: Host', 'kmt.test', $byk['p-cskmt']['host'] ?? '');
    cs_eq('Suche: zu kurz 400', 400, cs_rest('GET', '/co-organizer/sites', ['q' => 'c'])[0]);

    // ── Teil A: Kunden-Seite verbinden
    [$st, , $c] = cs_rest('POST', "/events/$ev/co-organizer/invite", ['site' => 'p-csoff', 'consent' => true]);
    cs_eq('nicht verbunden: 409', [409, 'tix_co_site_not_connected'], [$st, $c]);
    [$st, , $c] = cs_rest('POST', "/events/$ev/co-organizer/invite", ['site' => 'p-gibtsnicht', 'consent' => true]);
    cs_eq('unbekannt: 400', [400, 'tix_invalid_field'], [$st, $c]);
    [$st, $d] = cs_rest('POST', "/events/$ev/co-organizer/invite", ['site' => 'p-cskmt', 'consent' => true]);
    $kmt_oid = intval(TIX_Co_Organizer::ref_id($ev));
    if ($kmt_oid) $tmp_p[] = $kmt_oid;
    cs_eq('Seite verbunden: 201, Profil neu', [201, true], [$st, $d['created'] ?? null]);
    cs_eq('Profil Art site, Partner zugeordnet', ['site', $kmt_oid], [get_post_meta($kmt_oid, '_tix_org_kind', true), intval(TIX_Partners::get('cskmt')['organizer_id'] ?? 0)]);
    cs_eq('Eintrag: site, Label', ['site', 'eigene Seite kmt.test'], [$d['co_organizers'][0]['kind'] ?? '', $d['co_organizers'][0]['origin']['label'] ?? '']);
    cs_eq('Zugriff an', true, TIX_Co_Organizer::has_access($ev));
    // 1.38.715: Kunden-Seite sofort aktiv, keine Mail an Ersatzadressen
    cs_eq('Kunden-Seite: Status aktiv, ohne Einladungs-Adresse', ['active', ''], [TIX_Co_Organizer::payload($ev)['status'] ?? '', TIX_Co_Organizer::payload($ev)['invited_email'] ?? 'x']);
    update_post_meta($kmt_oid, '_tix_org_email', 'inhaber@kunden.evendis.invalid');
    [$m0, $n0] = TIX_Co_Sites::notify_result($ev, $kmt_oid);
    cs_eq('Ersatzadresse: keine Mail, Hinweis', [false, true], [$m0, strpos($n0, 'keine E-Mail-Adresse') !== false]);
    // 1.38.709: Profil der Kunden-Seite nicht in Veranstalter-Suche und öffentlicher Liste
    [, $d] = cs_rest('GET', '/organizers/search', ['q' => 'Csxtest']);
    cs_eq('Veranstalter-Suche ohne Kunden-Seite', [false, true], [in_array($kmt_oid, array_map('intval', array_column($d['items'] ?? [], 'id')), true), in_array($ob, array_map('intval', array_column($d['items'] ?? [], 'id')), true)]);
    delete_transient('tix_pub_events_org_' . $kmt_oid);
    cs_eq('Öffentliches Profil: 404', 404, cs_rest('GET', "/public/organizers/$kmt_oid")[0]);
    global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tix_pub_events_orgs_%'");
    [, $d] = cs_rest('GET', '/public/organizers', ['q' => 'csxtest', 'per_page' => 200]);
    cs_eq('Öffentliche Liste ohne Kunden-Seite', false, in_array($kmt_oid, array_map('intval', array_column($d['organizers'] ?? [], 'id')), true));
    // Profil aus der Kopplung (ohne Art) wird beim Verbinden zur Art site
    $okmt2 = $mko(0, 'Csxtest Gekoppelt'); $parts = get_option(TIX_Partners::OPTION); $parts['csoff']['organizer_id'] = $okmt2; $parts['csoff']['key_in'] = 'tixp_' . str_repeat('f', 32); update_option(TIX_Partners::OPTION, $parts, false); TIX_Platform_Customers::flush();
    TIX_Co_Sites::organizer_for('p-csoff');
    cs_eq('Profil aus Kopplung: Art site', 'site', get_post_meta($okmt2, '_tix_org_kind', true));

    // Kunden-Seite holt ihre Co-Events
    wp_set_current_user(0);
    [$st, $d] = cs_rest('GET', '/partner/co-events', [], ['X-Tix-Syndication-Key' => $kmt_key]);
    cs_eq('Kunden-Seite: Event in co-events', [200, true], [$st, in_array($ev, array_column($d['items'] ?? [], 'id'), true)]);

    // ── Teil B: Spiegel eines KK-Events, KK verbindet KMT
    $mir = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Csxtest Spiegel', 'meta_input' => ['_tix_organizer_id' => $okk, '_tix_date_start' => '2027-04-01',
        '_tix_syndicated' => '1', '_tix_source_partner' => 'cskk', '_tix_source_id' => 1171, '_tix_source_site' => 'https://kk.test']]);
    $tmp_p[] = $mir;
    $ctok = str_repeat('z', 48);
    [$st, $d] = cs_rest('POST', "/syndicate/$mir/co-organizer", ['action' => 'invite', 'site' => 'p-cskmt', 'access' => true, 'token' => $ctok, 'by' => 'KK Test'], ['X-Tix-Syndication-Key' => $kk_key]);
    cs_eq('B: Seite verbunden', [200, $kmt_oid, 'kmt.test'], [$st, intval($d['co_organizer']['id'] ?? 0), $d['co_organizer']['site']['host'] ?? '']);
    [$st, , $c] = cs_rest('POST', "/syndicate/$mir/co-organizer", ['action' => 'invite', 'site' => 'p-cskk', 'access' => true, 'token' => $ctok], ['X-Tix-Syndication-Key' => $kk_key]);
    cs_eq('B: sich selbst → 409', [409, 'tix_co_self'], [$st, $c]);
    [$st, $d] = cs_rest('GET', '/syndicate/co-sites', ['q' => 'csxtest'], ['X-Tix-Syndication-Key' => $kk_key]);
    $sh = array_column($d['items'] ?? [], 'host'); // Schlüssel wird nach der Zuordnung v-…, der alte p-… bleibt als Alias gültig
    cs_eq('B: Suche ohne eigene Seite', [200, true, false], [$st, in_array('kmt.test', $sh, true), in_array('kk.test', $sh, true)]);
    cs_eq('B: alter Schlüssel p-… bleibt gültig', TIX_Platform_Customers::canonical('v-' . $kmt_oid), TIX_Platform_Customers::canonical('p-cskmt'));
    cs_eq('B: Suche ohne Schlüssel 401', 401, cs_rest('GET', '/syndicate/co-sites', ['q' => 'csx'])[0]);

    [$st, $d] = cs_rest('GET', '/partner/co-events', [], ['X-Tix-Syndication-Key' => $kmt_key]);
    $it = array_column($d['items'] ?? [], null, 'id')[$mir] ?? [];
    cs_eq('B: Spiegel in co-events der Kunden-Seite', [true, 'kk.test', true], [!empty($it), $it['source_site'] ?? '', $it['access'] ?? null]);
    cs_eq('B: origin_id = Event-ID der Lizenzseite, Plattform-Event 0 (1.38.718)', [1171, 0], [$it['origin_id'] ?? null, array_column($d['items'] ?? [], null, 'id')[$ev]['origin_id'] ?? null]);

    // Durchgriff Kunden-Seite → Plattform → KK
    $GLOBALS['cs_reply'] = function ($url) { return [200, ['ok' => true, 'status' => 200, 'data' => ['id' => 1171, 'title' => 'von KK', 'event' => ['id' => '1171', 'event_id' => 1171], 'items' => [['id' => 1171, 'event_id' => 1171]]]]]; };
    [$st, $d] = cs_rest('POST', "/partner/co-events/$mir/call", ['method' => 'GET', 'path' => "/events/$mir/edit"], ['X-Tix-Syndication-Key' => $kmt_key]);
    $c = cs_last_call('kk.test/');
    cs_eq('B: Durchgriff weitergeleitet', [200, 200, 'von KK'], [$st, $d['status'] ?? 0, $d['data']['title'] ?? '']);
    cs_eq('B: Quell-ID in der Antwort → Plattform-ID, fremde IDs bleiben (1.38.718)', [$mir, strval($mir), $mir, 1171, $mir],
        [$d['data']['id'] ?? 0, $d['data']['event']['id'] ?? '', $d['data']['event']['event_id'] ?? 0, $d['data']['items'][0]['id'] ?? 0, $d['data']['items'][0]['event_id'] ?? 0]);
    [, $d] = cs_rest('POST', "/partner/co-events/$mir/call", ['method' => 'GET', 'path' => "/events/$mir/orders"], ['X-Tix-Syndication-Key' => $kmt_key]);
    cs_eq('B: Unterroute: oberste id bleibt (keine Event-Antwort)', [1171, strval($mir)], [$d['data']['id'] ?? 0, $d['data']['event']['id'] ?? 0]);
    cs_eq('B: an KK mit Quell-ID und Schlüssel', [true, '/events/1171/edit', $ctok], [strpos($c['url'] ?? '', '/partner/co/1171') !== false, $c['body']['path'] ?? '', $c['headers']['X-Tix-Co-Token'] ?? '']);
    [$st, $d] = cs_rest('POST', "/partner/co-events/$mir/call", ['method' => 'POST', 'path' => '/orders/5/refund'], ['X-Tix-Syndication-Key' => $kmt_key]);
    cs_eq('B: Storno gesperrt', [false, 403], [$d['ok'] ?? null, $d['status'] ?? 0]);

    // Plattform-Admin: Zeile + Trennen am Spiegel
    wp_set_current_user($admin_id);
    [, $d] = cs_rest('GET', '/platform/events', ['q' => 'Csxtest Spiegel']);
    $row = $d['items'][0] ?? [];
    cs_eq('Zeile Spiegel: änderbar über die Quelle, trennbar', [true, true, 'site', true], [$row['co_editable'] ?? null, $row['co_removable'] ?? null, $row['co_organizer']['kind'] ?? '', $row['co_source']['forward'] ?? null]);
    // 1.38.715: Ändern auf dem Spiegel geht an die Quelle (kk.test), lokal kein Teil-A-Co
    $GLOBALS['cs_reply'] = function () { return [200, ['ok' => true, 'mailed' => false]]; };
    [$st, $d] = cs_rest('POST', "/platform/events/$mir/co-organizer", ['organizer_id' => $ob]);
    $c = cs_last_call('/partner/co/1171/assign');
    cs_eq('POST auf Spiegel: an die Quelle weitergereicht', [true, true, $ob, 0], [in_array($st, [200, 201], true), !empty($d['forwarded']), intval($c['body']['organizer_id'] ?? 0), TIX_Co_Organizer::ref_id($mir)]);
    $GLOBALS['cs_reply'] = function () { return [200, ['ok' => true]]; };
    [$st, $d] = cs_rest('DELETE', "/platform/events/$mir/co-organizer");
    $c = cs_last_call('/release');
    cs_eq('Admin trennt Spiegel', [200, 'removed', true, 0], [$st, $d['changed'] ?? '', $d['source_released'] ?? null, intval(get_post_meta($mir, '_tix_co_remote_oid', true))]);
    cs_eq('… Freigabe an KK mit Schlüssel', [true, $ctok], [strpos($c['url'] ?? '', 'kk.test/wp-json/tixomat/v1/partner/co/1171/release') !== false, $c['headers']['X-Tix-Co-Token'] ?? '']);
    wp_set_current_user(0);
    [, $d] = cs_rest('GET', '/partner/co-events', [], ['X-Tix-Syndication-Key' => $kmt_key]);
    cs_eq('… Spiegel nicht mehr bei der Kunden-Seite', false, in_array($mir, array_column($d['items'] ?? [], 'id'), true));

    // Plattform-Admin verbindet Kunden-Seite (Teil A)
    wp_set_current_user($admin_id);
    TIX_Co_Organizer::clear($ev);
    [$st, $d] = cs_rest('POST', "/platform/events/$ev/co-organizer", ['site' => 'p-cskmt', 'access' => false]);
    cs_eq('Plattform: Seite verbunden', [201, false, 'site'], [$st, $d['created'] ?? null, $d['event']['co_organizer']['kind'] ?? '']);
    [$st, $d] = cs_rest('DELETE', "/events/$ev/co-organizer");
    cs_eq('Admin trennt im Editor (via platform)', [200, 'removed', 'platform'], [$st, $d['changed'] ?? '', TIX_Co_Organizer::org_log($oa)[0]['via'] ?? '']);
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    remove_filter('pre_option_' . TIX_Studio::OPTION, $sf);
    if ($p_backup === null) delete_option(TIX_Partners::OPTION); else update_option(TIX_Partners::OPTION, $p_backup, false);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tmp_p as $id) if ($id) wp_delete_post($id, true);
    foreach ($tmp_u as $id) if ($id) wp_delete_user($id);
    TIX_Platform_Customers::flush();
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
