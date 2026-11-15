<?php
/**
 * Integrationstest „Ein Demo-Zugang mit Fällen“ (TIX_Demo_Remote + TIX_Demo_Access, 1.38.843) gegen lokale Test-Seiten.
 * NIE auf einer Live-Seite. tests/run-demo-remote.sh sichert die DBs, richtet Schlüssel/Schalter ein, startet die Instanz als
 * HTTP-Server (php -S) und ruft die Phasen auf:
 *   inst – Instanz (zh-kk, tix_demo_instance + tix_demo_remote_enabled): signierte Routen direkt (rest_do_request)
 *   plat – Plattform (zh-plat): echte Signatur-Kette über HTTP zu beiden Instanzen (shop: tix_demo_shop_url → zh-kk:8080,
 *          club: tix_demo_club_url → zh-kk:8081 = zweite WordPress-Installation im selben Container, Tabellen-Präfix club_; 1.38.844)
 *   none – Plattform ohne Verbindung / ohne Fälle: verhält sich wie bisher
 *
 *   TIX_T_PHASE=inst wp eval-file wp-content/plugins/tixomat/tests/test-demo-remote-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Demo_Remote')) { echo "WordPress mit Tixomat nötig\n"; return; }
$host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($host, '.') !== false && !preg_match('/\.(test|localhost)$/', $host)) { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$phase = getenv('TIX_T_PHASE') ?: 'inst';

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0; $GLOBALS['t_mails'] = [];
function t_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
add_filter('pre_wp_mail', function ($null, $atts) { if ($null === null) $GLOBALS['t_mails'][] = $atts; return true; }, 3, 2);
function t_rest($method, $route, array $body = [], array $headers = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    foreach ($headers as $k => $v) $r->set_header($k, $v);
    $res = rest_do_request($r);
    $res = apply_filters('rest_post_dispatch', rest_ensure_response($res), rest_get_server(), $r);
    return [$res->get_status(), $res->get_data()];
}
function t_code($d) { return is_array($d) ? (string) ($d['code'] ?? '') : ''; }
function t_mail_to($addr) { foreach (array_reverse($GLOBALS['t_mails']) as $m) { foreach ((array) $m['to'] as $t) if (stripos((string) $t, $addr) !== false) return $m; } return null; }
/** Signierter Aufruf wie von der Plattform (rest_do_request); $over: time, sig, body (roh, abweichend von der Signatur) */
function t_signed($method, $path, array $query = [], $body = null, array $over = []) {
    $route = '/tixomat/v1' . $path;
    if (empty($over['same'])) $query['_n'] = bin2hex(random_bytes(8)); // wie die Plattform: Zufallswert je Aufruf
    $raw = $body === null ? '' : wp_json_encode($body);
    $time = (string) ($over['time'] ?? time());
    $sig = $over['sig'] ?? TIX_Demo_Remote::sign((string) get_option('tix_demo_shop_key'), $time, $method, $route, $query, $raw);
    $r = new WP_REST_Request($method, $route);
    if ($query) $r->set_query_params($query);
    $send = array_key_exists('body', $over) ? (string) $over['body'] : $raw;
    if ($send !== '') { $r->set_header('content-type', 'application/json'); $r->set_body($send); }
    if (empty($over['nohead'])) { $r->set_header('X-Tix-Remote-Time', $time); $r->set_header('X-Tix-Remote-Sig', $sig); }
    $res = rest_ensure_response(rest_do_request($r));
    return [$res->get_status(), $res->get_data()];
}

$admin = intval(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
$sfx = wp_rand(10000, 99999);
$_SERVER['REMOTE_ADDR'] = '10.7.' . wp_rand(1, 250) . '.' . wp_rand(1, 250);
global $wpdb;

// ═══════════════════════════════ Instanz ═══════════════════════════════
if ($phase === 'inst') {
    t_eq('Instanz an', true, TIX_Demo_Shop::on());
    t_eq('Signierte Aufrufe bereit (Schalter + Schlüssel)', true, TIX_Demo_Remote::instance_ready());
    wp_set_current_user(0);
    $rid = 900000 + $sfx;
    $exp = time() + 14 * DAY_IN_SECONDS;
    $new = ['remote_id' => $rid, 'owner' => ['id' => 7, 'name' => 'Tim Vertrieb', 'email' => 'tim@evendis.de'], 'name' => "Ina Shop $sfx", 'email' => "ina$sfx@beispiel.test", 'company' => 'Ina GmbH', 'expires_at' => wp_date('c', $exp), 'extended' => false, 'invite' => false];

    // ── Abschottung der Signatur ──
    list($st, $d) = t_signed('POST', '/demo-access/remote', [], $new, ['nohead' => true]);
    t_eq('ohne Signatur → 401 tix_remote_signature', [401, 'tix_remote_signature'], [$st, t_code($d)]);
    list($st, $d) = t_signed('POST', '/demo-access/remote', [], $new, ['sig' => str_repeat('a', 64)]);
    t_eq('falsche Signatur → 401', 401, $st);
    list($st, $d) = t_signed('POST', '/demo-access/remote', [], $new, ['body' => wp_json_encode(array_merge($new, ['email' => "boese$sfx@x.test"]))]);
    t_eq('Body nach dem Signieren geändert → 401', 401, $st);
    list($st, $d) = t_signed('POST', '/demo-access/remote', [], $new, ['time' => time() - 301]);
    t_eq('Zeit außerhalb ±300 s → 401', 401, $st);
    list($st, $d) = t_signed('POST', '/demo-access/remote', [], $new, ['time' => time() + 301]);
    t_eq('Zeit in der Zukunft > 300 s → 401', 401, $st);
    t_eq('nichts angelegt bei ungültigen Aufrufen', 0, count(get_users(['meta_key' => '_tix_demo_remote_id', 'meta_value' => (string) $rid, 'fields' => 'ids'])));
    // Nutzer-Token: nie (auch nicht der Admin)
    wp_set_current_user($admin);
    list($st, $d) = t_signed('GET', '/demo-access/remote');
    t_eq('mit angemeldetem Nutzer (auch Admin) → 401', 401, $st);
    wp_set_current_user(0);
    // Schalter aus → 404
    update_option('tix_demo_remote_enabled', '0');
    list($st, $d) = t_signed('GET', '/demo-access/remote');
    t_eq('tix_demo_remote_enabled aus → 404', 404, $st);
    update_option('tix_demo_remote_enabled', '1');

    // ── Anlegen ──
    $GLOBALS['t_mails'] = [];
    $time = (string) time();
    $sig = TIX_Demo_Remote::sign((string) get_option('tix_demo_shop_key'), $time, 'POST', '/tixomat/v1/demo-access/remote', ['_n' => 'fest1'], wp_json_encode($new));
    list($st, $d) = t_signed('POST', '/demo-access/remote', ['_n' => 'fest1'], $new, ['time' => $time, 'sig' => $sig, 'same' => true]);
    t_eq('anlegen → 201', 201, $st);
    t_eq('Antwort mit Einmal-Anmelde-Link fürs Studio der Instanz', true, (bool) preg_match('~/anmelden/link/\?t=[A-Za-z0-9%]{20,}~', (string) ($d['login_url'] ?? '')));
    t_eq('Antwort ohne Schlüssel', false, strpos(wp_json_encode($d), (string) get_option('tix_demo_shop_key')) !== false);
    t_eq('invite:false → keine Mail der Instanz', 0, count($GLOBALS['t_mails']));
    t_eq('Stand: remote_id, aktiv, Ablauf von der Plattform', [$rid, 'active', true], [intval($d['access']['remote_id'] ?? 0), $d['access']['status'] ?? '', abs(strtotime((string) ($d['access']['expires_at'] ?? '')) - $exp) < 2]);
    list($st, $d) = t_signed('POST', '/demo-access/remote', ['_n' => 'fest1'], $new, ['time' => $time, 'sig' => $sig, 'same' => true]);
    t_eq('dieselbe Signatur noch einmal (Replay) → 401', [401, 'tix_remote_signature'], [$st, t_code($d)]);
    $u = get_users(['meta_key' => '_tix_demo_remote_id', 'meta_value' => (string) $rid]);
    $u = $u ? $u[0] : null;
    t_eq('genau ein Zugang angelegt', true, $u instanceof WP_User);
    t_eq('Rollen tix_demo + tix_organizer, kein Admin', [true, true, false], [$u && in_array('tix_demo', $u->roles, true), $u && in_array('tix_organizer', $u->roles, true), $u && $u->has_cap('manage_options')]);
    t_eq('Eigentümer der Plattform gemerkt', 'Tim Vertrieb', (string) ((array) get_user_meta($u->ID, '_tix_demo_remote_owner', true))['name']);
    list($st, $d) = t_signed('POST', '/demo-access/remote', [], $new);
    t_eq('remote_id doppelt → 409', 409, $st);
    list($st, $d) = t_signed('POST', '/demo-access/remote', [], ['remote_id' => $rid + 1] + $new);
    t_eq('E-Mail auf der Instanz schon vergeben → 409 field email', [409, 'email'], [$st, (string) ($d['data']['field'] ?? '')]);
    list($st, $d) = t_signed('POST', '/demo-access/remote', [], ['remote_id' => $rid + 2, 'email' => "alt$sfx@beispiel.test", 'expires_at' => wp_date('c', time() - 60)] + $new);
    t_eq('Ablauf in der Vergangenheit → 400', 400, $st);
    list($st, $d) = t_signed('POST', '/demo-access/remote', [], ['remote_id' => $rid + 3, 'email' => "lang$sfx@beispiel.test", 'expires_at' => wp_date('c', time() + 400 * DAY_IN_SECONDS)] + $new);
    t_eq('Ablauf zu weit → gekappt (≤ 29 Tage)', true, $st === 201 && strtotime((string) $d['access']['expires_at']) <= time() + 29 * DAY_IN_SECONDS + 5);
    $long = get_users(['meta_key' => '_tix_demo_remote_id', 'meta_value' => (string) ($rid + 3), 'fields' => 'ids']);

    // ── Stand ──
    list($st, $d) = t_signed('GET', '/demo-access/remote', ['ids' => $rid . ',' . ($rid + 3) . ',1']);
    t_eq('GET ?ids → Stand je remote_id', [200, [$rid, $rid + 3]], [$st, array_values(array_map('intval', array_column((array) ($d['accesses'] ?? []), 'remote_id')))] );
    $one = null; foreach ((array) $d['accesses'] as $a) if (intval($a['remote_id']) === $rid) $one = $a;
    t_eq('Stand-Felder status, last_seen, test_orders', ['active', null, 0], [$one['status'] ?? '', is_array($one) && array_key_exists('last_seen', $one) ? $one['last_seen'] : 'x', $one['test_orders'] ?? -1]);
    t_eq('Kopfdaten shop (studio, home, vorlage, summary)', true, !empty($d['shop']['studio']) && !empty($d['shop']['home']) && in_array($d['shop']['vorlage'] ?? '', ['openair', 'club'], true) && isset($d['shop']['summary']['active']));
    // Anmeldung über den Link funktioniert auf der Instanz
    list($st, $d) = t_signed('POST', "/demo-access/remote/$rid/link", [], []);
    t_eq('Link → login_url, keine Mail', [200, true, 0], [$st, strpos((string) ($d['login_url'] ?? ''), '/anmelden/link/?t=') !== false, count($GLOBALS['t_mails'])]);
    preg_match('~\?t=([A-Za-z0-9%]+)~', (string) $d['login_url'], $m);
    list($st, $c) = t_rest('POST', '/public/demo-access/confirm', ['token' => rawurldecode($m[1] ?? '')]);
    t_eq('Interessent meldet sich mit dem Link im Studio der Instanz an', true, $st === 200 && !empty($c['token']));
    wp_set_current_user(0);

    // ── Ändern ──
    $exp2 = time() + 20 * DAY_IN_SECONDS;
    list($st, $d) = t_signed('PATCH', "/demo-access/remote/$rid", [], ['expires_at' => wp_date('c', $exp2), 'extended' => true]);
    t_eq('verlängern übernimmt expires_at + extended', [200, true, true], [$st, abs(TIX_Demo_Access::expires($u->ID) - $exp2) < 2, (bool) get_user_meta($u->ID, '_tix_demo_extended', true)]);
    list($st, $d) = t_signed('PATCH', "/demo-access/$rid", [], ['blocked' => true]);
    t_eq('normale Route ohne Nutzer → kein Zugriff', true, $st >= 400);
    list($st, $d) = t_signed('PATCH', "/demo-access/remote/$rid", [], ['blocked' => true]);
    t_eq('sperren → blocked, Tokens weg', ['blocked', ''], [$d['access']['status'] ?? '', (string) get_user_meta($u->ID, '_tix_app_tokens', true) === '' ? '' : 'x']);
    list($st, $d) = t_signed('POST', "/demo-access/remote/$rid/link", [], []);
    t_eq('Link für gesperrten Zugang → 409', 409, $st);
    list($st, $d) = t_signed('PATCH', "/demo-access/remote/$rid", [], ['blocked' => false, 'name' => "Ina Neu $sfx", 'company' => 'Neu GmbH']);
    t_eq('entsperren + Name/Firma', ['active', "Ina Neu $sfx", 'Neu GmbH'], [$d['access']['status'] ?? '', get_userdata($u->ID)->display_name, (string) get_user_meta($u->ID, '_tix_demo_company', true)]);

    // ── Nur per Signatur angelegte Zugänge sind erreichbar ──
    $native = TIX_Demo_Access::insert_prospect("Eigen $sfx", "eigen$sfx@beispiel.test", '', time() + DAY_IN_SECONDS, $admin, true);
    list($st, $d) = t_signed('PATCH', '/demo-access/remote/' . $native->ID, [], ['blocked' => true]);
    t_eq('eigener Zugang der Instanz (ohne remote_id) → 404', 404, $st);
    list($st, $d) = t_signed('DELETE', '/demo-access/remote/' . $admin);
    t_eq('Admin über remote löschen → 404', [404, true], [$st, get_userdata($admin) instanceof WP_User]);
    update_user_meta($admin, '_tix_demo_remote_id', 4242);
    list($st, $d) = t_signed('DELETE', '/demo-access/remote/4242');
    t_eq('Admin mit untergeschobener remote_id → 404 (nur Interessenten)', [404, true], [$st, get_userdata($admin) instanceof WP_User]);
    delete_user_meta($admin, '_tix_demo_remote_id');
    list($st, $d) = t_signed('GET', '/demo-access/remote');
    t_eq('Liste ohne ids nur per Plattform angelegte', false, in_array($native->ID, array_map('intval', array_column((array) $d['accesses'], 'remote_id')), true));

    // ── Zurücksetzen: nur Vorlage prüfen (der echte Lauf steht in der Plattform-Phase) ──
    list($st, $d) = t_signed('POST', '/demo-access/remote/reset', [], ['vorlage' => 'quatsch']);
    t_eq('Reset mit falscher Vorlage → 400', 400, $st);

    // ── Löschen ──
    list($st, $d) = t_signed('DELETE', "/demo-access/remote/$rid");
    t_eq('löschen → 200, Konto weg', [200, false], [$st, get_userdata($u->ID) instanceof WP_User]);
    list($st, $d) = t_signed('DELETE', "/demo-access/remote/$rid");
    t_eq('noch einmal löschen → 404', 404, $st);

    // ── Fehlversuche begrenzt ──
    $_SERVER['REMOTE_ADDR'] = '10.6.6.' . wp_rand(1, 250);
    for ($i = 0; $i < 31; $i++) t_signed('GET', '/demo-access/remote', [], null, ['sig' => str_repeat('b', 64)]);
    list($st, $d) = t_signed('GET', '/demo-access/remote');
    t_eq('nach 30 Fehlversuchen je IP → 429, auch mit gültiger Signatur', 429, $st);

    // ── Ohne Instanz-Schalter: alles 404 ──
    update_option('tix_demo_instance', '0');
    $_SERVER['REMOTE_ADDR'] = '10.6.7.' . wp_rand(1, 250);
    list($st, $d) = t_signed('GET', '/demo-access/remote');
    t_eq('ohne tix_demo_instance → 404', 404, $st);
    update_option('tix_demo_instance', '1');
    foreach (array_merge([$native->ID], $long) as $x) TIX_Demo_Access::delete_prospect($x);
}

// ═══════════════════════════════ Plattform mit echter Kette ═══════════════════════════════
if ($phase === 'plat') {
    t_eq('Plattform (Mehr-Veranstalter)', true, TIX_Demo_Access::multi());
    t_eq('Verbindung eingerichtet (shop + club)', [true, true], [TIX_Demo_Remote::platform_ready('shop'), TIX_Demo_Remote::platform_ready('club')]);
    t_eq('zwei verschiedene Instanzen mit eigenem Schlüssel', [true, true], [TIX_Demo_Remote::base_url('shop') !== TIX_Demo_Remote::base_url('club'), TIX_Demo_Remote::fingerprint('shop') !== TIX_Demo_Remote::fingerprint('club')]);
    t_eq('unbekanntes Ziel nie bereit', false, TIX_Demo_Remote::platform_ready('quatsch'));
    delete_transient('tix_demo_shop_reset_rl'); delete_transient('tix_demo_reset_rl');
    if (!TIX_Sales::demo_id()) TIX_Demo::ensure_organizer();
    $sent_http = [];
    add_filter('tix_demo_remote_http_args', function ($args, $url) use (&$sent_http) { $sent_http[] = [$url, $args]; return $args; }, 10, 2);
    $cleanup = [];
    wp_set_current_user($admin);
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'sales', 'name' => "Vera Vertrieb $sfx", 'email' => "vera$sfx@example.org"]);
    $s1 = intval($d['access']['id'] ?? 0); $cleanup[] = $s1;
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'sales', 'name' => "Olaf Vertrieb $sfx", 'email' => "olaf$sfx@example.org"]);
    $s2 = intval($d['access']['id'] ?? 0); $cleanup[] = $s2;

    // ── Liste mit Fällen ──
    wp_set_current_user($s1);
    list($st, $d) = t_rest('GET', '/demo-access', ['cases' => 'all']);
    t_eq('GET ?cases=all → Block shop verfügbar', [200, true], [$st, (bool) ($d['shop']['available'] ?? false)]);
    t_eq('shop-Block mit studio/home/vorlage/summary', true, !empty($d['shop']['studio']) && !empty($d['shop']['home']) && isset($d['shop']['summary']['test_orders']));
    t_eq('Antwort ohne Schlüssel', false, strpos(wp_json_encode($d), (string) get_option('tix_demo_shop_key')) !== false);
    list($st, $d) = t_rest('GET', '/demo-access');
    t_eq('ohne cases=all: kein shop-Block, keine cases (wie bisher)', [false, false], [array_key_exists('shop', $d), isset($d['accesses'][0]) && array_key_exists('cases', $d['accesses'][0])]);

    // ── Anlegen: beide Fälle, eine Mail ──
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['platform', 'shop'], 'kind' => 'prospect', 'model' => 'platform', 'profile' => 'festival', 'name' => "Beate Beide $sfx", 'email' => "beate$sfx@beispiel.test", 'company' => 'Beide e. V.']);
    t_eq('beide Fälle anlegen → 201', 201, $st);
    $pb = intval($d['access']['id'] ?? 0); $cleanup[] = $pb;
    t_eq('Zeile: cases platform+shop, Shop-Stand aktiv', [['platform', 'shop'], 'active'], [$d['access']['cases'] ?? null, $d['access']['shop']['status'] ?? '']);
    t_eq('Einladung verschickt', true, !empty($d['invite_sent']));
    t_eq('genau EINE Mail an den Interessenten', 1, count(array_filter($GLOBALS['t_mails'], function ($m) use ($sfx) { return in_array("beate$sfx@beispiel.test", (array) $m['to'], true); })));
    $m = t_mail_to("beate$sfx@beispiel.test");
    $msg = (string) ($m['message'] ?? '');
    t_eq('Mail: Knopf „Plattform ansehen“ + „Eigenen Shop ansehen“ + demo.evendis.de', [true, true, true], [strpos($msg, 'Plattform ansehen') !== false, strpos($msg, 'Eigenen Shop ansehen') !== false, strpos($msg, 'demo.evendis.de') !== false]);
    preg_match_all('~(https?://[^"\s<]+/anmelden/link/\?t=([A-Za-z0-9%]+))~', $msg, $mm);
    t_eq('zwei Anmelde-Links in der Mail', 2, count(array_unique($mm[1] ?? [])));
    $ptok = ''; $stok = '';
    foreach ($mm[1] ?? [] as $i => $url) { if (strpos($url, 'demo-studio') !== false || strpos($url, TIX_Demo_Access::studio_url('/')) === 0) $ptok = rawurldecode($mm[2][$i]); else $stok = rawurldecode($mm[2][$i]); }
    t_eq('Schlüssel nicht in der Mail', false, strpos($msg, (string) get_option('tix_demo_shop_key')) !== false);
    t_eq('Plattform-Zugang: Team im Beispiel-Veranstalter (Festival)', true, intval(get_user_meta($pb, '_tix_team_organizer_id', true)) > 0);
    t_eq('gleiches Ablaufdatum auf beiden Seiten', true, abs(strtotime((string) $d['access']['expires_at']) - TIX_Demo_Access::expires($pb)) < 2);
    // Shop-Link meldet auf der Instanz an (echt über HTTP)
    $base = TIX_Demo_Remote::base_url();
    $confirm = function ($tok) use ($base) {
        $u = strpos($base, 'rest_route=') !== false ? $base . '/public/demo-access/confirm' : $base . '/public/demo-access/confirm';
        $r = wp_remote_post($u, ['headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode(['token' => $tok]), 'timeout' => 15]);
        return is_wp_error($r) ? [0, null] : [intval(wp_remote_retrieve_response_code($r)), json_decode(wp_remote_retrieve_body($r), true)];
    };
    list($cs, $cd) = $confirm($stok);
    t_eq('Shop-Link aus der Mail meldet auf der Instanz an', true, $cs === 200 && !empty($cd['token']));
    list($cs, $cd) = $confirm($stok);
    t_eq('Shop-Link nur einmal einlösbar', 404, $cs);
    list($st, $c) = t_rest('POST', '/public/demo-access/confirm', ['token' => $ptok]);
    t_eq('Plattform-Link meldet im Demo-Studio an', true, $st === 200 && !empty($c['token']));
    wp_set_current_user($s1);

    // ── Echte Signatur: Replay und Manipulation über HTTP ──
    $last = end($sent_http);
    t_eq('Aufrufe tragen X-Tix-Remote-Time/-Sig', true, !empty($last[1]['headers']['X-Tix-Remote-Sig']) && !empty($last[1]['headers']['X-Tix-Remote-Time']));
    $rp = wp_remote_request($last[0], $last[1]);
    t_eq('gleichen Aufruf noch einmal schicken (Replay) → 401', 401, intval(wp_remote_retrieve_response_code($rp)));
    $first = $sent_http[0];
    $a2 = $first[1]; $a2['headers']['X-Tix-Remote-Time'] = (string) (intval($a2['headers']['X-Tix-Remote-Time']) + 1);
    $rp = wp_remote_request($first[0], $a2);
    t_eq('Zeit geändert (Signatur passt nicht) → 401', 401, intval(wp_remote_retrieve_response_code($rp)));
    $rp = wp_remote_request($first[0], ['method' => 'GET', 'timeout' => 10, 'headers' => ['X-Tix-Remote-Time' => (string) time(), 'X-Tix-Remote-Sig' => hash_hmac('sha256', 'x', 'falscher-schluessel-falscher-schluessel')]]);
    t_eq('falscher Schlüssel → 401', 401, intval(wp_remote_retrieve_response_code($rp)));
    $old = (string) (time() - 400);
    $rp = wp_remote_request($first[0], ['method' => 'GET', 'timeout' => 10, 'headers' => ['X-Tix-Remote-Time' => $old, 'X-Tix-Remote-Sig' => TIX_Demo_Remote::sign((string) get_option('tix_demo_shop_key'), $old, 'GET', '/tixomat/v1/demo-access/remote', [], '')]]);
    t_eq('richtig signiert, aber 400 s alt → 401', 401, intval(wp_remote_retrieve_response_code($rp)));

    // ── Nur Shop ──
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['shop'], 'kind' => 'prospect', 'model' => 'platform', 'name' => "Sven Shop $sfx", 'email' => "sven$sfx@beispiel.test"]);
    t_eq('nur Shop ohne Profil → 201', 201, $st);
    $ps = intval($d['access']['id'] ?? 0); $cleanup[] = $ps;
    t_eq('nur Shop: Zeile cases [shop]', ['shop'], $d['access']['cases'] ?? null);
    $msg = (string) (t_mail_to("sven$sfx@beispiel.test")['message'] ?? '');
    t_eq('nur Shop: Mail nur mit „Eigenen Shop ansehen“', [false, true], [strpos($msg, 'Plattform ansehen') !== false, strpos($msg, 'Eigenen Shop ansehen') !== false]);
    t_eq('nur Shop: kein Team, keine Veranstalter-Rolle auf der Plattform', [0, false], [intval(get_user_meta($ps, '_tix_team_organizer_id', true)), in_array('tix_organizer', get_userdata($ps)->roles, true)]);
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/public/demo-access/link', ['email' => "sven$sfx@beispiel.test"]);
    t_eq('nur Shop: Demo-Studio schickt keinen Anmelde-Link', [202, null], [$st, t_mail_to("sven$sfx@beispiel.test")]);
    t_eq('nur Shop: Token wirkt im Demo-Studio nicht (gate)', 0, TIX_Demo_Access::gate($ps));
    t_eq('nur Shop: Passwort-Anmeldung gesperrt', true, is_wp_error(TIX_Demo_Access::gate_password(get_userdata($ps))));
    list($st, $d) = t_rest('PATCH', "/demo-access/$ps", ['profile' => 'club']);
    t_eq('nur Shop: Profil ändern → 400', 400, $st);
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => [], 'kind' => 'prospect', 'name' => 'Leer', 'email' => "leer$sfx@beispiel.test"]);
    t_eq('ohne Fall → 400 field cases', [400, 'cases'], [$st, (string) ($d['data']['field'] ?? '')]);
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['platform'], 'kind' => 'prospect', 'name' => "Paul Plattform $sfx", 'email' => "paul$sfx@beispiel.test"]);
    t_eq('nur Plattform ohne Profil → 400 field profile', [400, 'profile'], [$st, (string) ($d['data']['field'] ?? '')]);

    // ── Liste: Shop-Stand je Zeile, Abschottung zwischen Vertrieblern ──
    TIX_Demo_Remote::forget();
    list($st, $d) = t_rest('GET', '/demo-access', ['cases' => 'all']);
    $ids = array_map('intval', array_column((array) $d['accesses'], 'id'));
    t_eq('Vertriebler sieht seine zwei Zugänge mit Fällen', [true, true], [in_array($pb, $ids, true), in_array($ps, $ids, true)]);
    t_eq('Kopfzahlen nur eigene Zugänge (2 aktiv)', 2, intval($d['shop']['summary']['active'] ?? -1));
    wp_set_current_user($s2);
    list($st, $d) = t_rest('GET', '/demo-access', ['cases' => 'all']);
    t_eq('anderer Vertriebler sieht sie nicht', [], array_values(array_intersect([$pb, $ps], array_map('intval', array_column((array) $d['accesses'], 'id')))));
    $n_http = count($sent_http);
    list($st, $d) = t_rest('PATCH', "/demo-access/$pb", ['blocked' => true]);
    t_eq('fremden Zugang sperren → 404 …', 404, $st);
    list($st, $d) = t_rest('DELETE', "/demo-access/$ps");
    t_eq('… löschen → 404 …', 404, $st);
    list($st, $d) = t_rest('POST', "/demo-access/$pb/link");
    t_eq('… Link → 404 …', 404, $st);
    t_eq('… und kein Aufruf an die Instanz', $n_http, count($sent_http));
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['shop'], 'kind' => 'sales', 'name' => 'X', 'email' => "xs$sfx@example.org"]);
    t_eq('Vertriebler legt keinen Vertriebler an (auch mit Fällen) → 403', 403, $st);

    // ── Verlängern, Sperren, Link: auf beiden Seiten ──
    wp_set_current_user($s1);
    list($st, $d) = t_rest('PATCH', "/demo-access/$pb", ['extend' => true]);
    TIX_Demo_Remote::forget();
    $state = TIX_Demo_Remote::state(true);
    $remote_exp = strtotime((string) ($state['accesses'][$pb]['expires_at'] ?? ''));
    t_eq('verlängern → beide Seiten dasselbe Ende (28 Tage)', [200, true, true], [$st, abs($remote_exp - TIX_Demo_Access::expires($pb)) < 2, abs(TIX_Demo_Access::expires($pb) - (time() + 28 * DAY_IN_SECONDS)) < 120]);
    t_eq('Shop-Teil ebenfalls „verlängert“', true, !empty($state['accesses'][$pb]['extended']));
    list($st, $d) = t_rest('PATCH', "/demo-access/$pb", ['extend' => true]);
    t_eq('zweites Verlängern → 409', 409, $st);
    list($st, $d) = t_rest('PATCH', "/demo-access/$pb", ['blocked' => true]);
    $state = TIX_Demo_Remote::state(true);
    t_eq('sperren → Plattform und Shop gesperrt', ['blocked', 'blocked'], [TIX_Demo_Access::status($pb), (string) ($state['accesses'][$pb]['status'] ?? '')]);
    list($st, $d) = t_rest('PATCH', "/demo-access/$pb", ['blocked' => false]);
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', "/demo-access/$pb/link");
    $msg = (string) (t_mail_to("beate$sfx@beispiel.test")['message'] ?? '');
    t_eq('Link neu senden → eine Mail mit beiden Links', [200, 1, true, true], [$st, count($GLOBALS['t_mails']), strpos($msg, 'Plattform ansehen') !== false, strpos($msg, 'Eigenen Shop ansehen') !== false]);

    // ── Instanz nicht erreichbar: nichts halb ──
    $url = get_option('tix_demo_shop_url');
    update_option('tix_demo_shop_url', 'http://zh-kk:9/?rest_route=/tixomat/v1');
    TIX_Demo_Remote::forget();
    $before = count(get_users(['role' => 'tix_demo', 'fields' => 'ids']));
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['platform', 'shop'], 'kind' => 'prospect', 'profile' => 'club', 'name' => "Nina Nichts $sfx", 'email' => "nina$sfx@beispiel.test"]);
    t_eq('Instanz weg → 502 tix_demo_shop_unreachable', [502, 'tix_demo_shop_unreachable'], [$st, t_code($d)]);
    t_eq('… kein Zugang angelegt, keine Mail', [$before, false, 0], [count(get_users(['role' => 'tix_demo', 'fields' => 'ids'])), (bool) get_user_by('email', "nina$sfx@beispiel.test"), count($GLOBALS['t_mails'])]);
    $exp_before = TIX_Demo_Access::expires($ps);
    list($st, $d) = t_rest('PATCH', "/demo-access/$ps", ['blocked' => true]);
    t_eq('sperren bei Ausfall → 502, Plattform unverändert', [502, 'active'], [$st, TIX_Demo_Access::status($ps) === 'expiring' ? 'active' : TIX_Demo_Access::status($ps)]);
    list($st, $d) = t_rest('DELETE', "/demo-access/$ps");
    t_eq('löschen bei Ausfall → 502, Zugang bleibt', [502, true], [$st, get_userdata($ps) instanceof WP_User]);
    list($st, $d) = t_rest('POST', "/demo-access/$ps/link");
    t_eq('Link bei Ausfall → 502', 502, $st);
    list($st, $d) = t_rest('GET', '/demo-access', ['cases' => 'all']);
    $row = null; foreach ((array) $d['accesses'] as $r) if (intval($r['id']) === $pb) $row = $r;
    t_eq('Liste bei Ausfall: vollständig, available false mit Meldung, letzter Stand', [200, false, true, true], [$st, (bool) $d['shop']['available'], ($d['shop']['message'] ?? '') !== '', $row !== null && is_array($row['shop'])]);
    t_eq('Fehler ohne Schlüssel', false, strpos(wp_json_encode($d), (string) get_option('tix_demo_shop_key')) !== false);
    update_option('tix_demo_shop_url', $url);
    TIX_Demo_Remote::forget();

    // ── Limit zählt Zugänge, nicht Fälle ──
    $fill = [];
    while (count($fill) + 2 < TIX_Demo_Access::MAX_ACTIVE) { $u = TIX_Demo_Access::insert_prospect('Füll ' . count($fill), 'fuell' . count($fill) . "x$sfx@beispiel.test", '', time() + DAY_IN_SECONDS * 5, $s1, false); $fill[] = $u->ID; }
    $n_http = count($sent_http);
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['platform', 'shop'], 'kind' => 'prospect', 'profile' => 'club', 'name' => 'Zuviel', 'email' => "zuviel$sfx@beispiel.test"]);
    t_eq('21. Zugang (beide Fälle zählen als einer) → 409 tix_demo_limit, kein Aufruf an die Instanz', [409, 'tix_demo_limit', $n_http], [$st, t_code($d), count($sent_http)]);
    foreach ($fill as $x) TIX_Demo_Access::delete_prospect($x);

    // ── Demo-Shop zurücksetzen (echt) ──
    list($st, $d) = t_rest('POST', '/demo-access/reset', ['target' => 'quatsch']);
    t_eq('Reset mit falschem Ziel → 400', 400, $st);
    list($st, $d) = t_rest('POST', '/demo-access/reset', ['target' => 'shop', 'vorlage' => 'club']);
    t_eq('Vertriebler setzt den Demo-Shop zurück (Vorlage club)', [200, 'shop', 'club'], [$st, $d['target'] ?? '', $d['vorlage'] ?? '']);
    t_eq('… Zugänge bleiben beim Zurücksetzen', true, isset(TIX_Demo_Remote::state(true)['accesses'][$pb]));

    // ── Löschen: beide Seiten ──
    list($st, $d) = t_rest('DELETE', "/demo-access/$pb");
    $state = TIX_Demo_Remote::state(true);
    t_eq('löschen → Plattform und Shop weg', [200, false, false], [$st, get_userdata($pb) instanceof WP_User, isset($state['accesses'][$pb])]);
    list($st, $d) = t_rest('DELETE', "/demo-access/$ps");
    t_eq('nur-Shop-Zugang löschen', 200, $st);
    // Shop-Teil schon weg (z. B. auf der Instanz gelöscht) → Plattform löscht trotzdem
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['shop'], 'kind' => 'prospect', 'name' => "Weg $sfx", 'email' => "weg$sfx@beispiel.test"]);
    $pw = intval($d['access']['id'] ?? 0);
    TIX_Demo_Remote::call('DELETE', '/demo-access/remote/' . $pw);
    list($st, $d) = t_rest('DELETE', "/demo-access/$pw");
    t_eq('Shop-Teil fehlt schon → Plattform löscht trotzdem', [200, false], [$st, get_userdata($pw) instanceof WP_User]);

    // ═══════ Drei Fälle (1.38.844): platform + shop + club ═══════
    TIX_Demo_Remote::forget();
    list($st, $d) = t_rest('GET', '/demo-access', ['cases' => 'all']);
    t_eq('GET ?cases=all → Blöcke shop UND club verfügbar', [200, true, true], [$st, (bool) ($d['shop']['available'] ?? false), (bool) ($d['club']['available'] ?? false)]);
    t_eq('club-Block: studio/home/vorlage club/summary', [true, 'club'], [!empty($d['club']['studio']) && !empty($d['club']['home']) && isset($d['club']['summary']['active']), $d['club']['vorlage'] ?? '']);
    t_eq('Blöcke zeigen auf verschiedene Instanzen', true, ($d['shop']['home'] ?? '') !== '' && ($d['shop']['home'] ?? '') !== ($d['club']['home'] ?? ''));
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['platform', 'shop', 'club'], 'kind' => 'prospect', 'profile' => 'club', 'name' => "Dora Drei $sfx", 'email' => "dora$sfx@beispiel.test"]);
    t_eq('alle drei Fälle anlegen → 201', 201, $st);
    $p3 = intval($d['access']['id'] ?? 0); $cleanup[] = $p3;
    t_eq('Zeile: cases platform+shop+club, shop und club aktiv', [['platform', 'shop', 'club'], 'active', 'active'], [$d['access']['cases'] ?? null, $d['access']['shop']['status'] ?? '', $d['access']['club']['status'] ?? '']);
    t_eq('genau EINE Mail an den Interessenten', 1, count(array_filter($GLOBALS['t_mails'], function ($m) use ($sfx) { return in_array("dora$sfx@beispiel.test", (array) $m['to'], true); })));
    $msg = (string) (t_mail_to("dora$sfx@beispiel.test")['message'] ?? '');
    t_eq('Mail: Plattform + Shop + Club + demo.evendis.de', [true, true, true, true], [strpos($msg, 'Plattform ansehen') !== false, strpos($msg, 'Eigenen Shop ansehen') !== false, strpos($msg, 'Club-Website ansehen') !== false, strpos($msg, 'demo.evendis.de') !== false]);
    preg_match_all('~(https?://[^"\s<]+/anmelden/link/\?t=([A-Za-z0-9%]+))~', $msg, $mm);
    t_eq('drei Anmelde-Links in der Mail', 3, count(array_unique($mm[1] ?? [])));
    t_eq('Schlüssel (shop und club) nicht in der Mail', [false, false], [strpos($msg, (string) get_option('tix_demo_shop_key')) !== false, strpos($msg, (string) get_option('tix_demo_club_key')) !== false]);
    // Club-Link meldet auf der Club-Instanz an, nicht auf der Shop-Instanz
    $confirm_at = function ($base, $tok) {
        $r = wp_remote_post($base . '/public/demo-access/confirm', ['headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode(['token' => $tok]), 'timeout' => 15]);
        return is_wp_error($r) ? 0 : intval(wp_remote_retrieve_response_code($r));
    };
    $s_club = TIX_Demo_Remote::state(true, 'club'); $s_shop = TIX_Demo_Remote::state(true, 'shop');
    t_eq('beide Instanzen kennen den Zugang (gleiches Ende)', [true, true, true], [isset($s_club['accesses'][$p3]), isset($s_shop['accesses'][$p3]),
        abs(strtotime((string) ($s_club['accesses'][$p3]['expires_at'] ?? '')) - TIX_Demo_Access::expires($p3)) < 2]);
    $ctok = '';
    foreach ($mm[1] ?? [] as $i => $url) if (strpos($msg, 'Club-Website ansehen') !== false && strpos($url, TIX_Demo_Access::studio_url('/')) !== 0) $ctoks[] = rawurldecode($mm[2][$i]);
    // Reihenfolge in der Mail: Plattform, Shop, Club → der letzte Instanz-Link ist der Club-Link
    $ctok = !empty($ctoks) ? end($ctoks) : '';
    t_eq('Club-Link gilt NICHT auf der Shop-Instanz', 404, $confirm_at(TIX_Demo_Remote::base_url('shop'), $ctok));
    t_eq('Club-Link meldet auf der Club-Instanz an', 200, $confirm_at(TIX_Demo_Remote::base_url('club'), $ctok));
    // Nur Club
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['club'], 'kind' => 'prospect', 'name' => "Carl Club $sfx", 'email' => "carl$sfx@beispiel.test"]);
    $pc = intval($d['access']['id'] ?? 0); $cleanup[] = $pc;
    $msg = (string) (t_mail_to("carl$sfx@beispiel.test")['message'] ?? '');
    t_eq('nur Club: 201, cases [club], shop null, Mail nur mit Club-Knopf', [201, ['club'], null, [false, false, true]], [$st, $d['access']['cases'] ?? null, array_key_exists('shop', (array) ($d['access'] ?? [])) ? $d['access']['shop'] : 'fehlt',
        [strpos($msg, 'Plattform ansehen') !== false, strpos($msg, 'Eigenen Shop ansehen') !== false, strpos($msg, 'Club-Website ansehen') !== false]]);
    t_eq('nur Club: Testkauf-Adresse = Club-Homepage', true, ($d['access']['home'] ?? '') !== '' && ($d['access']['home'] ?? '') === (string) (TIX_Demo_Remote::state(true, 'club')['shop']['home'] ?? '-'));
    t_eq('nur Club: kein Demo-Studio-Zugang', 0, TIX_Demo_Access::gate($pc));
    t_eq('unbekannter Fall wird ignoriert, ohne gültigen → 400', 400, t_rest('POST', '/demo-access', ['cases' => ['quatsch'], 'kind' => 'prospect', 'name' => 'Q', 'email' => "q$sfx@beispiel.test"])[0]);

    // Kein halber Zugang: Club fällt aus → Shop-Teil wird zurückgerollt
    $curl = get_option('tix_demo_club_url');
    update_option('tix_demo_club_url', 'http://zh-kk:9/?rest_route=/tixomat/v1');
    TIX_Demo_Remote::forget();
    $before_shop = count((array) (TIX_Demo_Remote::state(true, 'shop')['accesses'] ?? []));
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['platform', 'shop', 'club'], 'kind' => 'prospect', 'profile' => 'club', 'name' => "Rolf Rück $sfx", 'email' => "rolf$sfx@beispiel.test"]);
    t_eq('Club weg → 502 tix_demo_shop_unreachable (target club)', [502, 'tix_demo_shop_unreachable', 'club'], [$st, t_code($d), (string) ($d['data']['target'] ?? '')]);
    t_eq('… Shop-Teil zurückgerollt, kein Plattform-Zugang, keine Mail', [$before_shop, false, 0], [count((array) (TIX_Demo_Remote::state(true, 'shop')['accesses'] ?? [])), (bool) get_user_by('email', "rolf$sfx@beispiel.test"), count($GLOBALS['t_mails'])]);
    list($st, $d) = t_rest('PATCH', "/demo-access/$p3", ['blocked' => true]);
    $s_shop = TIX_Demo_Remote::state(true, 'shop');
    t_eq('sperren bei Club-Ausfall → 502, Shop wieder entsperrt, Plattform unverändert', [502, 'active', true], [$st, (string) ($s_shop['accesses'][$p3]['status'] ?? ''), in_array(TIX_Demo_Access::status($p3), ['active', 'expiring'], true)]);
    list($st, $d) = t_rest('POST', "/demo-access/$p3/link");
    t_eq('Link neu senden bei Club-Ausfall → 502, keine Mail', [502, 0], [$st, count($GLOBALS['t_mails'])]);
    list($st, $d) = t_rest('GET', '/demo-access', ['cases' => 'all']);
    t_eq('Liste bei Club-Ausfall: club available false, shop true', [false, true], [(bool) ($d['club']['available'] ?? true), (bool) ($d['shop']['available'] ?? false)]);
    list($st, $d) = t_rest('DELETE', "/demo-access/$p3");
    t_eq('löschen bei Club-Ausfall → 502, Zugang bleibt – auch der Shop-Teil (vorher geprüft, nichts halb gelöscht)', [502, true, true], [$st, get_userdata($p3) instanceof WP_User, isset(TIX_Demo_Remote::state(true, 'shop')['accesses'][$p3])]);
    update_option('tix_demo_club_url', $curl);
    TIX_Demo_Remote::forget();

    // Verlängern auf allen drei Seiten
    list($st, $d) = t_rest('PATCH', "/demo-access/$p3", ['extend' => true]);
    $s_club = TIX_Demo_Remote::state(true, 'club'); $s_shop = TIX_Demo_Remote::state(true, 'shop');
    t_eq('verlängern → Plattform, Shop und Club dasselbe Ende', [200, true, true], [$st, abs(strtotime((string) ($s_shop['accesses'][$p3]['expires_at'] ?? '')) - TIX_Demo_Access::expires($p3)) < 2, abs(strtotime((string) ($s_club['accesses'][$p3]['expires_at'] ?? '')) - TIX_Demo_Access::expires($p3)) < 2]);
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', "/demo-access/$p3/link");
    preg_match_all('~/anmelden/link/\?t=~', (string) (t_mail_to("dora$sfx@beispiel.test")['message'] ?? ''), $mm);
    t_eq('Link neu senden → eine Mail mit drei Links', [200, 1, 3], [$st, count($GLOBALS['t_mails']), count($mm[0])]);
    // Club zurücksetzen
    list($st, $d) = t_rest('POST', '/demo-access/reset', ['target' => 'club']);
    t_eq('Club zurücksetzen → 200 target club, Vorlage club, scan_event heute', [200, 'club', 'club', wp_date('Y-m-d')], [$st, $d['target'] ?? '', $d['vorlage'] ?? '', (string) ($d['seeded']['scan_event_date'] ?? '')]);
    // Löschen: alle drei Seiten
    list($st, $d) = t_rest('DELETE', "/demo-access/$p3");
    $s_club = TIX_Demo_Remote::state(true, 'club'); $s_shop = TIX_Demo_Remote::state(true, 'shop');
    t_eq('löschen → Plattform, Shop und Club weg', [200, false, false, false], [$st, get_userdata($p3) instanceof WP_User, isset($s_shop['accesses'][$p3]), isset($s_club['accesses'][$p3])]);
    list($st, $d) = t_rest('DELETE', "/demo-access/$pc");
    t_eq('nur-Club-Zugang löschen', [200, false], [$st, isset(TIX_Demo_Remote::state(true, 'club')['accesses'][$pc])]);

    // ── demo_links in /studio/config ──
    list($st, $d) = t_rest('GET', '/studio/config');
    t_eq('/studio/config demo_links {hub, platform, shop, club}', ['https://demo.evendis.de/', 'https://demo-studio.evendis.de/', 'https://studio.demo-shop.evendis.de/', 'https://studio.demo-club.evendis.de/'], [$d['demo_links']['hub'] ?? '', $d['demo_links']['platform'] ?? '', $d['demo_links']['shop'] ?? '', $d['demo_links']['club'] ?? '']);
    update_option('tix_demo_links', ['hub' => 'https://demo.example.test/', 'shop' => 'http://unsicher.example/']);
    list($st, $d) = t_rest('GET', '/studio/config');
    t_eq('demo_links: Option überschreibt, nur https', ['https://demo.example.test/', 'https://studio.demo-shop.evendis.de/'], [$d['demo_links']['hub'] ?? '', $d['demo_links']['shop'] ?? '']);
    delete_option('tix_demo_links');

    wp_set_current_user($admin);
    foreach ($cleanup as $x) if ($x && get_userdata($x)) TIX_Demo_Access::delete_prospect($x);
}

// ═══════════════════════════════ Plattform ohne Verbindung / ohne Fälle ═══════════════════════════════
if ($phase === 'none') {
    delete_option('tix_demo_shop_url');
    delete_option('tix_demo_club_url');
    TIX_Demo_Remote::forget();
    t_eq('ohne URL: nicht verbunden (shop, club)', [false, false], [TIX_Demo_Remote::platform_ready('shop'), TIX_Demo_Remote::platform_ready('club')]);
    $cleanup = [];
    wp_set_current_user($admin);
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'sales', 'name' => "Nora $sfx", 'email' => "nora$sfx@example.org"]);
    $s1 = intval($d['access']['id'] ?? 0); $cleanup[] = $s1;
    wp_set_current_user($s1);
    list($st, $d) = t_rest('GET', '/demo-access', ['cases' => 'all']);
    t_eq('cases=all ohne Verbindung: available false + Meldung', [200, false, true], [$st, $d['shop']['available'] ?? null, ($d['shop']['message'] ?? '') !== '']);
    t_eq('… auch der club-Block (Meldung nennt den Demo-Club)', [false, true], [$d['club']['available'] ?? null, strpos((string) ($d['club']['message'] ?? ''), 'Demo-Club') !== false]);
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['club'], 'kind' => 'prospect', 'name' => "Kein Club $sfx", 'email' => "keinclub$sfx@beispiel.test"]);
    t_eq('Fall club ohne Verbindung → 502 target club', [502, 'club'], [$st, (string) ($d['data']['target'] ?? '')]);
    list($st, $d) = t_rest('POST', '/demo-access/reset', ['target' => 'club']);
    t_eq('Club zurücksetzen ohne Verbindung → 502', 502, $st);
    $before = count(get_users(['role' => 'tix_demo', 'fields' => 'ids']));
    list($st, $d) = t_rest('POST', '/demo-access', ['cases' => ['shop'], 'kind' => 'prospect', 'name' => "Kein Shop $sfx", 'email' => "keinshop$sfx@beispiel.test"]);
    t_eq('Fall shop ohne Verbindung → 502, nichts angelegt', [502, $before], [$st, count(get_users(['role' => 'tix_demo', 'fields' => 'ids']))]);
    list($st, $d) = t_rest('POST', '/demo-access/reset', ['target' => 'shop']);
    t_eq('Shop zurücksetzen ohne Verbindung → 502', 502, $st);
    // Ohne cases: wie bisher (Modell Pflicht, Profil Pflicht, alte Mail)
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'profile' => 'club', 'name' => 'Alt', 'email' => "alt$sfx@beispiel.test"]);
    t_eq('ohne cases: Modell weiter Pflicht', [400, 'model'], [$st, (string) ($d['data']['field'] ?? '')]);
    $GLOBALS['t_mails'] = [];
    list($st, $d) = t_rest('POST', '/demo-access', ['kind' => 'prospect', 'model' => 'self', 'profile' => 'club', 'name' => "Alt $sfx", 'email' => "alt$sfx@beispiel.test"]);
    $pa = intval($d['access']['id'] ?? 0); $cleanup[] = $pa;
    $msg = (string) (t_mail_to("alt$sfx@beispiel.test")['message'] ?? '');
    t_eq('ohne cases: 201, Zeile ohne cases/shop, alte Einladung', [201, false, false, true], [$st, array_key_exists('cases', (array) $d['access']), array_key_exists('shop', (array) $d['access']), strpos($msg, 'Demo-Studio öffnen') !== false]);
    t_eq('ohne cases: kein Meta _tix_demo_cases, Fall platform', [false, ['platform']], [is_array(get_user_meta($pa, '_tix_demo_cases', true)), TIX_Demo_Access::cases($pa)]);
    list($st, $d) = t_rest('PATCH', "/demo-access/$pa", ['extend' => true, 'profile' => 'festival']);
    t_eq('ohne cases: verlängern + Profil wie bisher', [200, 'festival', true], [$st, $d['access']['profile'] ?? '', !empty($d['access']['extended'])]);
    list($st, $d) = t_rest('GET', '/demo-access');
    t_eq('ohne cases: Liste ohne shop-Block', false, array_key_exists('shop', $d));
    wp_set_current_user($admin);
    foreach ($cleanup as $x) if ($x && get_userdata($x)) TIX_Demo_Access::delete_prospect($x);
}

echo ($GLOBALS['t_fails'] ? "FEHLER {$GLOBALS['t_fails']} von {$GLOBALS['t_count']}" : "ALLES OK ({$GLOBALS['t_count']} Prüfungen, Phase $phase)") . "\n";
