<?php
/**
 * Integrationstest Co-Veranstalter durch den Plattform-Admin (1.38.705, Vertrag uebergaben/vertrag-co-durch-plattform.md).
 * Nur Plattform (Mehr-Veranstalter-Modus). Legt Testdaten an und räumt sie wieder ab.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-co-platform-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Organizer')) { echo "WordPress mit TIX_Co_Organizer nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function cp_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function cp_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && in_array($method, ['GET', 'DELETE'], true)) $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = $res->get_data();
    $d = is_object($d) ? (array) $d : $d;
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
}
function cp_feed($uid) {
    return array_column((array) get_user_meta($uid, TIX_Notifications::META_USER, true), 'title');
}
if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) {
    echo "== Einzel-Seite\n";
    cp_eq('Route /platform/events fehlt', 404, cp_rest('GET', '/platform/events')[0]);
    echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
    return;
}
echo "== Plattform\n";
$tmp_u = []; $tmp_p = [];
$mku = function ($role) use (&$tmp_u) {
    $id = wp_insert_user(['user_login' => 'cp_' . $role . '_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'cp_' . wp_rand(1000, 999999) . '@example.test', 'role' => $role]);
    $tmp_u[] = $id; return $id;
};
$mko = function ($uid, $name) use (&$tmp_p) {
    $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $name, 'meta_input' => ['_tix_org_user_id' => $uid]]);
    $tmp_p[] = $id; return $id;
};
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = $admin ? intval($admin[0]->ID) : 0;
$GLOBALS['cp_mails'] = [];
$mf = function ($r, $a) { $GLOBALS['cp_mails'][] = $a; return true; };
add_filter('pre_wp_mail', $mf, 1, 2);
$sf = function () { return 'https://studio.example.test'; };
add_filter('pre_option_' . TIX_Studio::OPTION, $sf);
$new_email = 'cp_neu_' . wp_rand(1000, 999999) . '@example.test';
try {
    $ua = $mku('tix_organizer'); $oa = $mko($ua, 'Cpxtest Ersteller');
    $ub = $mku('tix_organizer'); $ob = $mko($ub, 'Cpxtest Partner');
    $uc = $mku('tix_organizer'); $oc = $mko($uc, 'Cpxtest Dritter');
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cpxtest Konzert', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-03-01', '_tix_time_start' => '20:00']]);
    $past = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cpxtest Altes', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2020-01-01']]);
    $syn = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Cpxtest Geteilt', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_date_start' => '2027-04-01', '_tix_syndicated' => '1', '_tix_source_site' => 'https://partner.test']]);
    array_push($tmp_p, $ev, $past, $syn);

    // Rechte
    wp_set_current_user($ua);
    cp_eq('Veranstalter: /platform/events 403', 403, cp_rest('GET', '/platform/events')[0]);
    cp_eq('Veranstalter: setzen 403', 403, cp_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $ob])[0]);
    wp_set_current_user(0);
    cp_eq('ohne Login: 401', 401, cp_rest('GET', '/platform/events')[0]);
    wp_set_current_user($admin_id);

    // Suche
    [$st, $d] = cp_rest('GET', '/platform/events', ['q' => 'Cpxtest']);
    $ids = array_column($d['items'] ?? [], 'id');
    cp_eq('Suche kommend: Konzert + Geteilt, nicht Altes', [200, true, true, false], [$st, in_array($ev, $ids, true), in_array($syn, $ids, true), in_array($past, $ids, true)]);
    [, $d] = cp_rest('GET', '/platform/events', ['q' => 'Cpxtest', 'scope' => 'past']);
    cp_eq('Suche vergangen: nur Altes', [$past], array_column($d['items'] ?? [], 'id'));
    [, $d] = cp_rest('GET', '/platform/events', ['organizer_id' => $oa, 'scope' => 'all', 'per_page' => 2]);
    cp_eq('Filter Ersteller + Seiten', [3, 2, true], [$d['total'] ?? 0, count($d['items'] ?? []), $d['has_more'] ?? null]);
    cp_eq('scope falsch: 400', 400, cp_rest('GET', '/platform/events', ['scope' => 'x'])[0]);
    [, $d] = cp_rest('GET', '/platform/events', ['q' => 'Cpxtest Geteilt']);
    $row = $d['items'][0] ?? [];
    cp_eq('Geteiltes Event: gesperrt source', [false, 'source', 'partner.test'], [$row['co_editable'] ?? null, $row['co_locked']['reason'] ?? null, $row['co_locked']['site'] ?? null]);
    [, $d] = cp_rest('GET', '/platform/events', ['q' => 'Cpxtest Konzert']);
    $row = $d['items'][0] ?? [];
    cp_eq('Zeile: Ersteller, frei, kein Co', [$oa, true, null, '20:00'], [$row['owner']['id'] ?? 0, $row['co_editable'] ?? null, array_key_exists('co_organizer', $row) ? $row['co_organizer'] : 'x', $row['time'] ?? '']);

    // Fehler
    [$st, , $code] = cp_rest('POST', "/platform/events/$syn/co-organizer", ['organizer_id' => $ob]);
    cp_eq('geteilt: 409 tix_co_source', [409, 'tix_co_source'], [$st, $code]);
    [$st, , $code] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $oa]);
    cp_eq('Ersteller als Co: 409 tix_co_self', [409, 'tix_co_self'], [$st, $code]);
    cp_eq('nichts angegeben: 400', 400, cp_rest('POST', "/platform/events/$ev/co-organizer", ['access' => true])[0]);
    cp_eq('unbekannter Veranstalter: 400', 400, cp_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => 99999999])[0]);
    cp_eq('access falsch: 400', 400, cp_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $ob, 'access' => 'vielleicht'])[0]);
    cp_eq('kein Event: 404', 404, cp_rest('POST', '/platform/events/99999999/co-organizer', ['organizer_id' => $ob])[0]);
    [$st, , $code] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['email' => get_userdata($ua)->user_email]);
    cp_eq('E-Mail des Erstellers: 409 tix_co_self', [409, 'tix_co_self'], [$st, $code]);
    cp_eq('noch nichts gesetzt', 0, TIX_Co_Organizer::ref_id($ev));

    // Bestehenden verknüpfen
    $GLOBALS['cp_mails'] = [];
    [$st, $d] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $ob]);
    cp_eq('verknüpfen: 201 added, Zugriff an', [201, 'added', false, $ob, true], [$st, $d['changed'] ?? '', $d['created'] ?? null, $d['event']['co_organizer']['id'] ?? 0, $d['event']['co_organizer']['access'] ?? null]);
    cp_eq('Zustimmung durch Admin gespeichert', $admin_id, intval(get_post_meta($ev, '_tix_co_organizer_consent', true)['by'] ?? 0));
    cp_eq('Meta _tix_co_organizer_id gesetzt', $ob, intval(get_post_meta($ev, '_tix_co_organizer_id', true)));
    $subj = array_column($GLOBALS['cp_mails'], 'subject');
    $to = array_column($GLOBALS['cp_mails'], 'to');
    cp_eq('Mails: an Co + an Ersteller', [true, true], [in_array(get_userdata($ub)->user_email, $to, true), in_array(get_userdata($ua)->user_email, $to, true)]);
    $cm = ''; foreach ($GLOBALS['cp_mails'] as $m) if (strpos((string) $m['subject'], 'Neuer Co-Veranstalter') !== false) $cm = (string) $m['message'];
    $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
    cp_eq('Ersteller-Mail: „<Seite> hat … hinzugefügt“', true, strpos($cm, esc_html($site . ' hat Cpxtest Partner als Co-Veranstalter hinzugefügt')) !== false);
    cp_eq('Mails ohne „Tixomat“', false, (bool) preg_match('/tixomat/i', implode(' ', $subj) . $cm));
    cp_eq('Feed Ersteller', true, in_array($site . ' hat Cpxtest Partner als Co-Veranstalter hinzugefügt', cp_feed($ua), true));
    cp_eq('Feed Co', true, in_array('Du bist Co-Veranstalter', cp_feed($ub), true));

    // Co sieht das Event
    wp_set_current_user($ub);
    cp_eq('Co: Rolle co', 'co', TIX_Co_Organizer::role($ev));
    wp_set_current_user($admin_id);

    // gleich nochmal = unverändert, keine Mails
    $GLOBALS['cp_mails'] = [];
    [$st, $d] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $ob]);
    cp_eq('nochmal: 200 unchanged, keine Mail', [200, 'unchanged', 0], [$st, $d['changed'] ?? '', count($GLOBALS['cp_mails'])]);

    // Zugriff aus
    [$st, $d] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $ob, 'access' => false]);
    cp_eq('Zugriff aus: 200 changed', [200, 'changed', false], [$st, $d['changed'] ?? '', $d['event']['co_organizer']['access'] ?? null]);
    cp_eq('Meta id weg, ref bleibt', ['', $ob], [get_post_meta($ev, '_tix_co_organizer_id', true), intval(get_post_meta($ev, '_tix_co_organizer_ref', true))]);
    wp_set_current_user($ub);
    cp_eq('Co ohne Zugriff: keine Rolle', null, TIX_Co_Organizer::role($ev));
    wp_set_current_user($admin_id);
    cp_eq('Feed Ersteller: ausgeschaltet', true, in_array($site . ' hat den Zugriff von Cpxtest Partner ausgeschaltet', cp_feed($ua), true));
    [, $d] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $ob, 'access' => true]);
    cp_eq('Zugriff wieder an', 'changed', $d['changed'] ?? '');

    // Ersetzen durch Dritten
    [$st, $d] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['organizer_id' => $oc]);
    cp_eq('ersetzen: 201 added', [201, 'added', $oc], [$st, $d['changed'] ?? '', $d['event']['co_organizer']['id'] ?? 0]);
    cp_eq('Feed alter Co: nicht mehr', true, in_array('Nicht mehr Co-Veranstalter', cp_feed($ub), true));

    // Neu anlegen per E-Mail
    $GLOBALS['cp_mails'] = [];
    cp_eq('neu ohne Namen: 400 organizer', [400, 'organizer'], (function () use ($ev, $new_email) { [$s, $d] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['email' => $new_email]); return [$s, $d['data']['field'] ?? '']; })());
    [$st, $d] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['email' => $new_email, 'organizer' => 'Cpxtest Neu GmbH', 'name' => 'Nina Neu']);
    $nu = get_user_by('email', $new_email);
    if ($nu) $tmp_u[] = $nu->ID;
    $no = intval($d['event']['co_organizer']['id'] ?? 0);
    if ($no) $tmp_p[] = $no;
    cp_eq('neu: 201, angelegt, eingeladen, Art co', [201, true, true, 'invited', 'co'], [$st, $d['created'] ?? null, $d['mailed'] ?? null, $d['event']['co_organizer']['status'] ?? '', get_post_meta($no, '_tix_org_kind', true)]);
    cp_eq('Einladung: via platform gemerkt', 'platform', (get_post_meta($no, '_tix_org_co_invited_by', true)['via'] ?? ''));
    $im = ''; foreach ($GLOBALS['cp_mails'] as $m) if ($m['to'] === $new_email || (is_array($m['to']) && in_array($new_email, $m['to'], true))) $im = (string) $m['message'];
    cp_eq('Einladung: Studio-Link /passwort/ mit art=co', true, strpos($im, 'studio.example.test/passwort/?email=') !== false && strpos($im, 'art=co') !== false);
    cp_eq('Feed alter Co (Dritter): nicht mehr', true, in_array('Nicht mehr Co-Veranstalter', cp_feed($uc), true));
    [$st, $d] = cp_rest('POST', "/platform/events/$ev/co-organizer", ['email' => $new_email]);
    cp_eq('gleiche E-Mail erneut: vorhandener, unchanged', [200, false, 'unchanged'], [$st, $d['created'] ?? null, $d['changed'] ?? '']);

    // Akte
    $ka = TIX_Platform_Customers::canonical('v-' . $oa);
    [$st, $d] = cp_rest('GET', "/platform/customers/$ka/co");
    cp_eq('Akte Ersteller: 3 eigene, kommende zuerst', [200, 3, $ev], [$st, count($d['own_events'] ?? []), $d['own_events'][0]['id'] ?? 0]);
    $log = $d['log'] ?? [];
    cp_eq('Akte Ersteller: Protokoll neueste zuerst, via platform', ['added', 'owner', $no, 'platform'], [$log[0]['what'] ?? '', $log[0]['role'] ?? '', $log[0]['other']['id'] ?? 0, $log[0]['via'] ?? '']);
    cp_eq('Protokoll vollständig (7)', 7, count($log));
    $kb = TIX_Platform_Customers::canonical('v-' . $ob);
    [, $d] = cp_rest('GET', "/platform/customers/$kb/co");
    cp_eq('Akte Partner: keine geteilten mehr, Protokoll mit removed', [0, 'removed', 'co'], [count($d['shared_events'] ?? []), $d['log'][0]['what'] ?? '', $d['log'][0]['role'] ?? '']);
    $kn = TIX_Platform_Customers::canonical('v-' . $no);
    [, $d] = cp_rest('GET', "/platform/customers/$kn/co");
    cp_eq('Akte Neuer: 1 geteiltes, 0 eigene', [1, 0, $ev], [count($d['shared_events'] ?? []), count($d['own_events'] ?? []), $d['shared_events'][0]['id'] ?? 0]);
    cp_eq('Akte unbekannt: 404', 404, cp_rest('GET', '/platform/customers/v-99999999/co')[0]);
    [, $d] = cp_rest('GET', '/platform/events', ['co_organizer_id' => $no, 'scope' => 'all']);
    cp_eq('Filter co_organizer_id', [$ev], array_column($d['items'] ?? [], 'id'));
    [, $d] = cp_rest('GET', '/platform/co-organizers');
    cp_eq('Liste /platform/co-organizers enthält Neuen', true, in_array($no, array_column($d['items'] ?? [], 'organizer_id'), true));

    // Lösen
    [$st, $d] = cp_rest('DELETE', "/platform/events/$ev/co-organizer");
    cp_eq('lösen: removed, leer', [200, 'removed', null], [$st, $d['changed'] ?? '', array_key_exists('co_organizer', (array) ($d['event'] ?? [])) ? $d['event']['co_organizer'] : 'x']);
    cp_eq('Meta weg', [0, ''], [TIX_Co_Organizer::ref_id($ev), get_post_meta($ev, '_tix_co_organizer_id', true)]);
    cp_eq('Feed Ersteller: entfernt', true, in_array($site . ' hat Cpxtest Neu GmbH als Co-Veranstalter entfernt', cp_feed($ua), true));
    [$st, $d] = cp_rest('DELETE', "/platform/events/$ev/co-organizer");
    cp_eq('nochmal lösen: unchanged', [200, 'unchanged'], [$st, $d['changed'] ?? '']);
    $sd = cp_rest('DELETE', "/platform/events/$syn/co-organizer"); // ab 1.38.706 erlaubt (Teil B lösen)
    cp_eq('geteilt lösen ohne Co: 200 unchanged', [200, 'unchanged'], [$sd[0], $sd[1]['changed'] ?? '']);
    cp_eq('Profil + Konto bleiben', [true, true], [get_post_status($no) === 'publish', (bool) get_user_by('email', $new_email)]);
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
}
// Ersteller-Weg (Editor) protokolliert via creator
try {
    wp_set_current_user($ua);
    [$st] = cp_rest('POST', "/events/$ev", ['co_organizer' => ['id' => $ob, 'access' => true, 'consent' => true]]);
    $log = TIX_Co_Organizer::org_log($oa);
    cp_eq('Editor: Protokoll via creator', [200, 'added', 'creator', $ob], [$st, $log[0]['what'] ?? '', $log[0]['via'] ?? '', $log[0]['other']['id'] ?? 0]);
    cp_rest('POST', "/events/$ev", ['co_organizer' => null]);
    cp_eq('Editor lösen: Protokoll removed', 'removed', TIX_Co_Organizer::org_log($oa)[0]['what'] ?? '');
} catch (Throwable $e) {
    $GLOBALS['t_fails']++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    remove_filter('pre_wp_mail', $mf, 1);
    remove_filter('pre_option_' . TIX_Studio::OPTION, $sf);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tmp_p as $id) if ($id) wp_delete_post($id, true);
    foreach ($tmp_u as $id) if ($id) wp_delete_user($id);
    TIX_Platform_Customers::flush();
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
