<?php
/**
 * Zwei-Seiten-Test Co-Veranstalter Teil B (Durchgriff) über echtes HTTP – nur lokale Test-Seiten.
 * Lizenzseite (Einzel-Seite, z. B. http://tqc-kk) und Plattform (Mehr-Veranstalter, z. B. http://tqc-plat) laufen
 * als eigene Webserver; die Seiten sprechen über HTTP miteinander. Ablauf (Wrapper: tests/run-co-remote-e2e.sh):
 *
 *   kk   eval-file … setup-kk                       → JSON {key_in, key_out}
 *   plat eval-file … setup-plat <kk_base> <key_in> <key_out>
 *   kk   eval-file … push-kk                        → JSON {event, remote_id, admin_login, admin_pass}
 *   plat eval-file … run <kk_base> <plat_base> <json aus push-kk>
 *   plat eval-file … cleanup-plat     ·     kk eval-file … cleanup-kk
 *
 * Alle Testdaten tragen „E2E-CO“ bzw. die Optionen tix_co_e2e_* und werden beim Aufräumen entfernt.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Remote')) { echo "WordPress mit Tixomat (TIX_Co_Remote) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$mode = (string) ($args[0] ?? '');
$GLOBALS['e2e_fails'] = 0; $GLOBALS['e2e_n'] = 0;
function e2e_eq($label, $exp, $act) {
    $GLOBALS['e2e_n']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['e2e_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
/** HTTP an eine Seite. $base = http://host, $route = /events/1 → [status, data] */
function e2e_http($base, $method, $route, array $body = [], $token = '') {
    $url = rtrim($base, '/') . '/?rest_route=/tixomat/v1' . $route;
    $args = ['method' => $method, 'timeout' => 40, 'headers' => ['Accept' => 'application/json']];
    if ($token !== '') $args['headers']['X-Tix-Token'] = $token;
    if ($method === 'GET' && $body) $url .= '&' . http_build_query($body);
    elseif ($body) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode($body); }
    $r = wp_remote_request($url, $args);
    if (is_wp_error($r)) return [0, ['code' => 'http', 'message' => $r->get_error_message()]];
    return [intval(wp_remote_retrieve_response_code($r)), json_decode((string) wp_remote_retrieve_body($r), true)];
}
function e2e_login($base, $login, $pass) {
    [$st, $d] = e2e_http($base, 'POST', '/auth/login', ['username' => $login, 'password' => $pass, 'device' => 'e2e']);
    return $st === 200 ? (string) ($d['token'] ?? '') : '';
}
$E2E_EMAIL = 'e2e-co@example.test';

// ═════════ Lizenzseite ═════════
if ($mode === 'setup-kk') {
    if (TIX_App_Scope::multi()) { echo "setup-kk nur auf der Lizenzseite\n"; return; }
    $pass = wp_generate_password(24, false);
    $uid = wp_insert_user(['user_login' => 'e2e_co_admin', 'user_pass' => $pass, 'user_email' => 'e2e-admin@example.test', 'role' => 'administrator']);
    if (is_wp_error($uid)) { echo 'Admin: ' . $uid->get_error_message() . "\n"; return; }
    update_option('tix_co_e2e_user', $uid, false);
    $cats = [['name' => 'Regulär', 'price' => 10, 'qty' => 100, 'stock' => 100]];
    $eid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'E2E-CO Lizenz-Event', 'post_author' => $uid,
        'meta_input' => ['_tix_date_start' => wp_date('Y-m-d', time() + 10 * DAY_IN_SECONDS), '_tix_time_start' => '20:00', '_tix_date_end' => wp_date('Y-m-d', time() + 10 * DAY_IN_SECONDS), '_tix_time_end' => '23:00',
            '_tix_tickets_enabled' => '1', '_tix_presale_active' => '1', '_tix_ticket_categories' => $cats, '_tix_syndicate' => '1',
            '_tix_guest_list' => [['id' => 'e2e1', 'name' => 'E2E Gast Eins', 'plus' => 0, 'checked_in' => false]]]]);
    update_option('tix_co_e2e_event', $eid, false);
    update_option('tix_co_e2e_pass', $pass, false);
    $set = get_option('tix_settings', []);
    update_option('tix_co_e2e_switch', isset($set['co_remote_enabled']) ? $set['co_remote_enabled'] : '__none__', false);
    $set['co_remote_enabled'] = 1; // Schalter für den Test an
    update_option('tix_settings', $set);
    echo wp_json_encode(['key_in' => (string) tix_get_settings('syndication_api_key'), 'key_out' => (string) tix_get_settings('partner_api_key')]) . "\n";
    return;
}
if ($mode === 'push-kk') { // nach setup-plat: Event an die Plattform verteilen (echtes HTTP)
    $eid = intval(get_option('tix_co_e2e_event'));
    TIX_Syndication_Push::push_event($eid);
    echo wp_json_encode(['event' => $eid, 'remote_id' => intval(get_post_meta($eid, '_tix_syndicate_remote_id', true)), 'admin_login' => 'e2e_co_admin', 'admin_pass' => (string) get_option('tix_co_e2e_pass')]) . "\n";
    return;
}
if ($mode === 'cleanup-kk') {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $eid = intval(get_option('tix_co_e2e_event'));
    if ($eid) {
        // Bestellungen + Tickets des Events
        foreach (get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_ticket_event_id', 'meta_value' => $eid]) as $t) wp_delete_post($t, true);
        if (class_exists('TIX_Order')) {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT order_id FROM ' . TIX_Order::items_table_name() . ' WHERE event_id = %d', $eid));
            foreach ($ids as $oid) {
                $oid = intval($oid);
                $wpdb->delete(TIX_Order::items_table_name(), ['order_id' => $oid]);
                $wpdb->delete(TIX_Order::notes_table_name(), ['order_id' => $oid]);
                $wpdb->delete(TIX_Order::table_name(), ['id' => $oid]);
                foreach (['_tix_pos_order_', '_tix_order_stock_taken_', '_tix_order_stock_restored_', '_tix_pos_receipt_'] as $o) delete_option($o . $oid);
            }
        }
        if (class_exists('TIX_Sponsor_Logos')) { global $wpdb; $wpdb->delete(TIX_Sponsor_Logos::t('settings'), ['scope' => 'event', 'owner_id' => $eid]); $wpdb->delete(TIX_Sponsor_Logos::t('logos'), ['scope' => 'event', 'owner_id' => $eid]); }
        // Löschen schickt das DELETE an die Plattform (TIX_Syndication_Push::on_delete)
        wp_delete_post($eid, true);
    }
    foreach (get_users(['meta_key' => '_tix_co_remote_user', 'fields' => 'ID']) as $u) wp_delete_user($u);
    if ($u = intval(get_option('tix_co_e2e_user'))) wp_delete_user($u);
    $r = get_option('tix_co_e2e_switch', null);
    if ($r !== null) { $set = get_option('tix_settings', []); if ($r === '__none__') unset($set['co_remote_enabled']); else $set['co_remote_enabled'] = $r; update_option('tix_settings', $set); }
    delete_option('tix_co_e2e_event'); delete_option('tix_co_e2e_user'); delete_option('tix_co_e2e_pass'); delete_option('tix_co_e2e_switch');
    echo "cleanup-kk ok\n"; return;
}

// ═════════ Plattform ═════════
if ($mode === 'setup-plat') {
    if (!TIX_App_Scope::multi()) { echo "setup-plat nur auf der Plattform\n"; return; }
    $host = strtolower((string) wp_parse_url((string) $args[1], PHP_URL_HOST));
    $all = get_option(TIX_Partners::OPTION, []);
    if (get_option('tix_co_e2e_partners', null) === null) update_option('tix_co_e2e_partners', is_array($all) ? $all : [], false);
    $all = is_array($all) ? $all : [];
    $all[sanitize_key($host)] = ['id' => sanitize_key($host), 'name' => 'E2E-CO Lizenzseite', 'api_base' => rtrim($args[1], '/') . '/?rest_route=/tixomat/v1',
        'key_in' => (string) $args[2], 'key_out' => (string) $args[3], 'organizer_id' => 0, 'sales_enabled' => 0, 'created' => gmdate('c')];
    update_option(TIX_Partners::OPTION, $all, false);
    $set = get_option('tix_settings', []);
    if (get_option('tix_co_e2e_receive', null) === null) update_option('tix_co_e2e_receive', isset($set['syndication_receive_enabled']) ? $set['syndication_receive_enabled'] : '__none__', false);
    $set['syndication_receive_enabled'] = 1;
    update_option('tix_settings', $set);
    echo "setup-plat ok (Partner " . sanitize_key($host) . ")\n"; return;
}
if ($mode === 'cleanup-plat') {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $keep = get_option('tix_co_e2e_partners', null);
    if ($keep !== null) update_option(TIX_Partners::OPTION, $keep, false);
    foreach (get_posts(['post_type' => 'event', 'post_status' => ['any', 'trash'], 'posts_per_page' => -1, 'fields' => 'ids', 's' => 'E2E-CO']) as $p) wp_delete_post($p, true);
    $u = get_user_by('email', $E2E_EMAIL);
    if ($u) {
        foreach (get_posts(['post_type' => 'tix_organizer', 'post_status' => ['any', 'trash'], 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_tix_org_user_id', 'meta_value' => $u->ID]) as $o) wp_delete_post($o, true);
        wp_delete_user($u->ID);
    }
    $r = get_option('tix_co_e2e_receive', null);
    if ($r !== null) { $set = get_option('tix_settings', []); if ($r === '__none__') unset($set['syndication_receive_enabled']); else $set['syndication_receive_enabled'] = $r; update_option('tix_settings', $set); }
    delete_option('tix_co_e2e_partners'); delete_option('tix_co_e2e_receive');
    echo "cleanup-plat ok\n"; return;
}
if ($mode !== 'run') { echo "Modus: setup-kk | setup-plat | run | cleanup-plat | cleanup-kk\n"; return; }

// ═════════ Ablauf (läuft im Plattform-Kontext, alles über HTTP) ═════════
[$kk, $plat] = [rtrim((string) $args[1], '/'), rtrim((string) $args[2], '/')];
$s = json_decode((string) $args[3], true);
$sid = intval($s['event']);
e2e_eq('Lizenz-Event an die Plattform verteilt', true, intval($s['remote_id']) > 0);
$mirror = intval($s['remote_id']);
e2e_eq('Spiegel auf der Plattform', ['1', $sid], [get_post_meta($mirror, '_tix_syndicated', true), intval(get_post_meta($mirror, '_tix_source_id', true))]);

$adm = e2e_login($kk, $s['admin_login'], $s['admin_pass']);
e2e_eq('Lizenzseite: Admin angemeldet', true, $adm !== '');

// 1. Einladung (Lizenzseite → Plattform)
[$st, $d] = e2e_http($kk, 'POST', "/events/$sid/co-organizer/invite", ['email' => $E2E_EMAIL, 'organizer' => 'E2E-CO Partner', 'name' => 'E2E Co', 'access' => true, 'consent' => true], $adm);
e2e_eq('Einladung: 201', 201, $st);
e2e_eq('Einladung: Profil auf der Plattform angelegt', [true, true], [!empty($d['created']), !empty($d['co_organizer']['id'])]);
$oid = intval($d['co_organizer']['id'] ?? 0);
e2e_eq('Einladung: Lizenzseite meldet remote', true, !empty($d['co_organizer']['remote']));

// 2. Profil
wp_cache_flush();
$u = get_user_by('email', $E2E_EMAIL);
e2e_eq('Profil: Konto + Veranstalter (Art co)', [true, 'co'], [(bool) $u, (string) get_post_meta($oid, '_tix_org_kind', true)]);
e2e_eq('Profil: Spiegel verknüpft, Zugriff an', [$oid, '1'], [intval(get_post_meta($mirror, '_tix_co_remote_oid', true)), get_post_meta($mirror, '_tix_co_remote_access', true)]);
e2e_eq('Profil: kein lokaler Zugriff aufs Spiegel-Event', '', (string) get_post_meta($mirror, '_tix_co_organizer_id', true));
$pass = wp_generate_password(24, false);
wp_set_password($pass, $u->ID);
update_post_meta($oid, '_tix_org_approval', 'approved'); // Freigabe für die Kasse (Testkonto)
$co = e2e_login($plat, $u->user_login, $pass);
e2e_eq('Co-Veranstalter angemeldet (Plattform)', true, $co !== '');

// 3. Liste
[$st, $d] = e2e_http($plat, 'GET', '/co-remote/events', [], $co);
$it = array_values(array_filter((array) ($d['items'] ?? []), function ($i) use ($mirror) { return intval($i['id']) === $mirror; }));
e2e_eq('/co-remote/events: Event gelistet, bereit', [200, 1, true, $sid], [$st, count($it), $it[0]['ready'] ?? null, intval($it[0]['source_id'] ?? 0)]);
$B = "/co-remote/$mirror";

// 4. Bearbeiten + Speichern
[$st, $d] = e2e_http($plat, 'GET', "$B/events/$sid/edit", [], $co);
e2e_eq('Bearbeiten: Editor-Daten von der Lizenzseite', [200, 'E2E-CO Lizenz-Event'], [$st, html_entity_decode((string) ($d['event']['title'] ?? $d['title'] ?? ''), ENT_QUOTES)]);
[$st, $d] = e2e_http($plat, 'POST', "$B/events/$sid", ['title' => 'E2E-CO Lizenz-Event (geändert)'], $co);
e2e_eq('Speichern: 200', 200, $st);
[, $d] = e2e_http($kk, 'GET', "/events/$sid/edit", [], $adm);
e2e_eq('Speichern: Titel auf der Lizenzseite geändert', 'E2E-CO Lizenz-Event (geändert)', html_entity_decode((string) ($d['event']['title'] ?? $d['title'] ?? ''), ENT_QUOTES));
[$st, $d] = e2e_http($plat, 'POST', "$B/events/$sid", ['co_organizer' => null], $co);
e2e_eq('Speichern mit co_organizer: 403 tix_co_forbidden', [403, 'tix_co_forbidden'], [$st, $d['code'] ?? null]);
[$st, $d] = e2e_http($plat, 'DELETE', "$B/events/$sid", [], $co);
e2e_eq('Löschen: 403', 403, $st);

// 4b. Verlauf über den Durchgriff (1.38.776): Liste der Lizenzseite mit Person/Seite, Wiederherstellen an der Quelle
if (class_exists('TIX_Event_History')) {
    [$st, $d] = e2e_http($plat, 'GET', "$B/events/$sid/history", [], $co);
    $top = $d['items'][0] ?? [];
    e2e_eq('Verlauf: Liste von der Lizenzseite, oberste Änderung vom Co mit Seite der Plattform', [200, true, false], [$st, ($top['site'] ?? '') === (string) wp_parse_url($plat, PHP_URL_HOST) && ($top['actor']['name'] ?? '') !== '', !empty($d['source']['copy'])]);
    $first = end($d['items']);
    [$st, $d] = e2e_http($plat, 'POST', "$B/events/$sid/history/" . intval($first['rev'] ?? 1) . "/restore", ['areas' => ['basis']], $co);
    [, $e] = e2e_http($kk, 'GET', "/events/$sid/edit", [], $adm);
    e2e_eq('Verlauf: Wiederherstellen über den Durchgriff setzt den Titel auf der Lizenzseite zurück', [200, 'E2E-CO Lizenz-Event'], [$st, html_entity_decode((string) ($e['event']['title'] ?? $e['title'] ?? ''), ENT_QUOTES)]);
    [$st, $d] = e2e_http($plat, 'POST', "$B/events/$sid", ['title' => 'E2E-CO Lizenz-Event (geändert)'], $co); // Stand für die folgenden Schritte
}

// 4c. Betreiber-Durchgriff (1.38.788): evendis-Admin ändert am Spiegel → Quelle (Lizenzseite) speichert, Spiegel folgt per Verteilung
if (class_exists('TIX_Source_Lock')) {
    $padm = get_users(['role' => 'administrator', 'number' => 1])[0];
    wp_set_current_user($padm->ID);
    $rq = new WP_REST_Request('POST', '/tixomat/v1/events/' . $mirror); $rq->set_header('content-type', 'application/json'); $rq->set_body(wp_json_encode(['timetable' => ['hidden' => true]]));
    $rs = rest_do_request($rq);
    wp_set_current_user(0);
    [, $e] = e2e_http($kk, 'GET', "/events/$sid/edit", [], $adm);
    $ev = $e['event'] ?? $e;
    wp_cache_delete($mirror, 'post_meta');
    e2e_eq('Betreiber: Änderung am Spiegel landet an der Quelle, Spiegel folgt', [200, 'operator', true, '1'], [$rs->get_status(), $rs->get_headers()['X-Tix-Forwarded'] ?? null, !empty($ev['timetable']['hidden']), get_post_meta($mirror, '_tix_timetable_hidden', true)]);
    if (class_exists('TIX_Event_History')) {
        [, $hh] = e2e_http($kk, 'GET', "/events/$sid/history", [], $adm);
        $top = $hh['items'][0] ?? [];
        e2e_eq('Betreiber: Verlauf an der Quelle mit Person und Seite der Plattform', [$padm->display_name, (string) wp_parse_url($plat, PHP_URL_HOST)], [$top['actor']['name'] ?? null, $top['site'] ?? null]);
    }
    // zurück für die folgenden Schritte
    wp_set_current_user($padm->ID);
    $rq = new WP_REST_Request('POST', '/tixomat/v1/events/' . $mirror); $rq->set_header('content-type', 'application/json'); $rq->set_body(wp_json_encode(['timetable' => ['hidden' => false]]));
    rest_do_request($rq);
    wp_set_current_user(0);
}

// 4d. Bearbeitungssperre über alle Seiten (1.38.789)
if (class_exists('TIX_Edit_Lock')) {
    $plr = function ($m, $r, $b = null, $tok = '') { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } if ($tok !== '') $x->set_header('X-Tix-Edit-Lock', $tok); $res = rest_do_request($x); return [$res->get_status(), $res->get_data()]; };
    $padm = get_users(['role' => 'administrator', 'number' => 1])[0];
    [$st, $lk] = e2e_http($kk, 'POST', "/events/$sid/lock", [], $adm);
    $kt = (string) ($lk['token'] ?? '');
    wp_set_current_user($padm->ID);
    [$st1, $d1] = $plr('POST', "/events/$mirror", ['title' => 'Vom Betreiber']);
    [$st2, $d2] = $plr('GET', "/events/$mirror/lock");
    wp_set_current_user(0);
    [$st3, $d3] = e2e_http($plat, 'POST', "$B/events/$sid", ['title' => 'Vom Co'], $co);
    e2e_eq('Sperre an der Quelle gilt am Spiegel: Betreiber und Co 409 mit Name, Status zeigt Halter von der Quelle',
        [200, 409, 'tix_event_locked', 409, true, (string) wp_parse_url($kk, PHP_URL_HOST)],
        [$st, $st1, $d1['code'] ?? null, $st3, !empty($d2['locked']), $d2['holder']['site'] ?? null]);
    wp_set_current_user($padm->ID);
    [$st4] = $plr('POST', "/events/$mirror/lock/break", []);
    [$st5, $mk] = $plr('POST', "/events/$mirror/lock", []);
    $mt = (string) ($mk['token'] ?? '');
    [$st6] = $plr('POST', "/events/$mirror", ['title' => 'E2E-CO Lizenz-Event (geändert)'], $mt);
    wp_set_current_user(0);
    [$st7, $d7] = e2e_http($kk, 'POST', "/events/$sid/lock", ['token' => $kt], $adm);
    e2e_eq('Betreiber löst die Sperre, holt sie selbst, speichert mit Token; Halter an der Quelle verliert sie', [200, 200, 200, 409, 'tix_event_lock_lost'], [$st4, $st5, $st6, $st7, $d7['code'] ?? null]);
    wp_set_current_user($padm->ID);
    [$h1, $hb] = $plr('POST', "/events/$mirror/lock/heartbeat", ['token' => $mt]);
    $x = new WP_REST_Request('POST', '/tixomat/v1/events/' . $mirror . '/lock/release'); $x->set_header('content-type', 'text/plain;charset=UTF-8'); $x->set_body(wp_json_encode(['token' => $mt]));
    $rr = rest_do_request($x);
    wp_set_current_user(0);
    e2e_eq('Herzschlag und Freigeben per sendBeacon (text/plain) am Spiegel gehen an die Quelle', [200, $mt, 200, true], [$h1, $hb['token'] ?? null, $rr->get_status(), $rr->get_data()['released'] ?? null]);
    [, $d8] = e2e_http($kk, 'GET', "/events/$sid/lock", [], $adm);
    e2e_eq('Freigeben am Spiegel gibt die Sperre an der Quelle frei', false, $d8['locked'] ?? null);
}

// 4e. Sponsoren über alle Seiten (1.38.793)
if (class_exists('TIX_Sponsor_Logos')) {
    $plr2 = function ($m, $r, $b = null) { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } $res = rest_do_request($x); return [$res->get_status(), $res->get_data(), $res->get_headers()]; };
    [$s1] = e2e_http($kk, 'PUT', '/studio/sponsors', ['enabled' => true, 'main' => [['name' => 'KK-Sponsor', 'logo' => ['url' => 'https://kk.example/logo.png'], 'link' => 'https://kk.example']], 'partners' => []], $adm);
    [$s2] = e2e_http($kk, 'PUT', "/events/$sid/sponsors", ['mode' => 'inherit'], $adm); // speichert + verteilt
    wp_cache_flush();
    $sp = TIX_Sponsor_Logos::event_public($mirror);
    e2e_eq('Sponsoren der Quelle kommen am Spiegel an (Klick-Link zählt bei der Quelle)', [200, 200, 'KK-Sponsor', true],
        [$s1, $s2, $sp['main'][0]['name'] ?? null, strpos((string) ($sp['main'][0]['link'] ?? ''), (string) wp_parse_url($kk, PHP_URL_HOST)) !== false]);
    $padm = get_users(['role' => 'administrator', 'number' => 1])[0];
    wp_set_current_user($padm->ID);
    [$s3, , $h3] = $plr2('PUT', "/events/$mirror/sponsors", ['mode' => 'own', 'main' => [['name' => 'Vom Betreiber', 'logo' => ['url' => 'https://evendis.example/l.png']]], 'partners' => []]);
    wp_set_current_user(0);
    wp_cache_flush();
    [, $e3] = e2e_http($kk, 'GET', "/events/$sid/sponsors", [], $adm);
    $sp = TIX_Sponsor_Logos::event_public($mirror);
    e2e_eq('Betreiber ändert Event-Sponsoren am Spiegel → beim Ersteller gespeichert, Spiegel folgt', [200, 'operator', 'own', 'Vom Betreiber'],
        [$s3, $h3['X-Tix-Forwarded'] ?? null, $e3['mode'] ?? null, $sp['main'][0]['name'] ?? null]);
    e2e_http($kk, 'PUT', "/events/$sid/sponsors", ['mode' => 'inherit', 'main' => [], 'partners' => []], $adm);
    e2e_http($kk, 'PUT', '/studio/sponsors', ['enabled' => false, 'main' => [], 'partners' => []], $adm);
}

// 5. Gästeliste
[$st, $d] = e2e_http($plat, 'POST', "$B/events/$sid/guestlist", ['name' => 'E2E Gast Zwei'], $co);
e2e_eq('Gästeliste: Gast angelegt', true, $st >= 200 && $st < 300);
[$st, $d] = e2e_http($plat, 'GET', "$B/events/$sid/guestlist", [], $co);
$names = array_map(function ($g) { return (string) ($g['name'] ?? ''); }, (array) ($d['guests'] ?? $d['guest_list'] ?? $d));
e2e_eq('Gästeliste: beide Gäste da', [200, true, true], [$st, in_array('E2E Gast Eins', $names, true), in_array('E2E Gast Zwei', $names, true)]);

// 6. Check-in
[$st, $d] = e2e_http($plat, 'GET', "$B/checkin/$sid/list", [], $co);
e2e_eq('Check-in: Liste 200', 200, $st);
[$st, $d] = e2e_http($plat, 'POST', "$B/checkin/$sid/guest/e2e1", ['count' => 1], $co);
e2e_eq('Check-in: Gast eingecheckt', true, $st >= 200 && $st < 300);
[, $d] = e2e_http($kk, 'GET', "/events/$sid/guestlist", [], $adm);
$g1 = array_values(array_filter((array) ($d['guests'] ?? $d['guest_list'] ?? $d), function ($g) { return ($g['id'] ?? '') === 'e2e1'; }));
e2e_eq('Check-in: auf der Lizenzseite gespeichert', true, !empty($g1[0]['checked_in']));

// 7. Kasse ohne Storno/Erstattung
[$st, $d] = e2e_http($plat, 'GET', "$B/pos/events/$sid/categories", [], $co);
e2e_eq('Kasse: Kategorien 200', 200, $st);
[$st, $d] = e2e_http($plat, 'POST', "$B/pos/orders", ['event_id' => $sid, 'items' => [['index' => 0, 'qty' => 1]], 'payment' => 'cash', 'checkin' => true, 'idempotency_key' => 'e2e' . wp_rand()], $co);
$order = intval($d['order_id'] ?? $d['id'] ?? 0);
e2e_eq('Kasse: Verkauf 200 mit Bestellung', [200, true], [$st, $order > 0]);
[$st, $d] = e2e_http($plat, 'POST', "$B/pos/orders", ['event_id' => $mirror + 99999, 'items' => [['index' => 0, 'qty' => 1]], 'payment' => 'cash', 'checkin' => true], $co);
e2e_eq('Kasse: fremdes Event 403', 403, $st);
foreach (["/pos/orders/$order/void", "/orders/$order/refund", "/orders/$order/cancel"] as $r) {
    [$st, $d] = e2e_http($plat, 'POST', "$B$r", [], $co);
    e2e_eq("Kein Storno: POST $r → 403 tix_co_forbidden", [403, 'tix_co_forbidden'], [$st, $d['code'] ?? null]);
}
[$st, $d] = e2e_http($plat, 'GET', "$B/pos/report", ['event_id' => $sid], $co);
e2e_eq('Kasse: Tagesbericht 200', 200, $st);

// 8. Fremde Routen
[$st, $d] = e2e_http($plat, 'GET', "$B/organizer/export", ['event_id' => $sid, 'type' => 'orders'], $co);
e2e_eq('Export Käuferdaten: 403', 403, $st);

// 9. Zugriff aus → Sperre, wieder an
[$st] = e2e_http($kk, 'POST', "/events/$sid", ['co_organizer' => ['access' => false]], $adm);
e2e_eq('Zugriff aus (Lizenzseite): 200', 200, $st);
wp_cache_flush();
[$st, $d] = e2e_http($plat, 'GET', "$B/events/$sid/edit", [], $co);
e2e_eq('Zugriff aus: Durchgriff gesperrt', 403, $st);
[$st] = e2e_http($kk, 'POST', "/events/$sid", ['co_organizer' => ['access' => true]], $adm);
wp_cache_flush();
[$st] = e2e_http($plat, 'GET', "$B/events/$sid/edit", [], $co);
e2e_eq('Zugriff wieder an: Durchgriff geht', 200, $st);

// 10. Entfernen + Schlüssel gesperrt
$tok = (string) get_post_meta($mirror, '_tix_co_remote_token', true);
[$st] = e2e_http($kk, 'POST', "/events/$sid", ['co_organizer' => null], $adm);
e2e_eq('Entfernen (Lizenzseite): 200', 200, $st);
wp_cache_flush();
e2e_eq('Entfernen: Spiegel-Meta auf der Plattform weg', ['', ''], [(string) get_post_meta($mirror, '_tix_co_remote_oid', true), (string) get_post_meta($mirror, '_tix_co_remote_token', true)]);
[$st] = e2e_http($plat, 'GET', "$B/events/$sid/edit", [], $co);
e2e_eq('Entfernen: Durchgriff 403', 403, $st);
[$st, $d] = e2e_http($plat, 'GET', '/co-remote/events', [], $co);
e2e_eq('Entfernen: nicht mehr gelistet', 0, count(array_filter((array) ($d['items'] ?? []), function ($i) use ($mirror) { return intval($i['id']) === $mirror; })));
$p = TIX_Partners::get(sanitize_key((string) wp_parse_url($kk, PHP_URL_HOST)));
$r = wp_remote_post($kk . '/?rest_route=/tixomat/v1/partner/co/' . $sid, ['timeout' => 20, 'body' => wp_json_encode(['method' => 'GET', 'path' => "/events/$sid/edit"]),
    'headers' => ['Content-Type' => 'application/json', 'X-Tix-Partner-Id' => TIX_Partners::platform_id(), 'X-Tix-Partner-Key' => (string) $p['key_out'], 'X-Tix-Co-Token' => $tok]]);
e2e_eq('Alter Event-Schlüssel direkt an der Lizenzseite: 401 tix_co_token', [401, 'tix_co_token'], [intval(wp_remote_retrieve_response_code($r)), json_decode((string) wp_remote_retrieve_body($r), true)['code'] ?? null]);

echo ($GLOBALS['e2e_fails'] ? "FEHLER: {$GLOBALS['e2e_fails']}" : 'ALLES OK') . " ({$GLOBALS['e2e_n']} Prüfungen)\n";
