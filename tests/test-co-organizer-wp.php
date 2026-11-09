<?php
/**
 * Integrationstest Co-Veranstalter (1.38.696, Vertrag uebergaben/vertrag-co-veranstalter.md Teil A).
 * Nur Plattform (Mehr-Veranstalter-Modus). Legt Testdaten an und räumt sie wieder ab.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-co-organizer-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Co_Organizer')) { echo "WordPress mit Tixomat (TIX_Co_Organizer) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function co_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function co_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && in_array($method, ['GET', 'DELETE'], true)) $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = $res->get_data();
    $d = is_object($d) ? (array) $d : $d;
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
}
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
if (!$multi) {
    echo "== Einzel-Seite\n";
    co_eq('Editor: nicht verfügbar', false, TIX_Co_Organizer::editor_payload(0)['co_organizer_available']);
    co_eq('Route fehlt', 404, co_rest('GET', '/organizers/search', ['q' => 'ab'])[0]);
    co_eq('Syndizierung schützt Co-Meta', true, in_array('_tix_co_organizer_id', TIX_Syndication_Receive::PROTECTED_META, true));
    echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
    return;
}
echo "== Plattform\n";
$tmp_u = []; $tmp_p = [];
$mku = function ($role) use (&$tmp_u) {
    $id = wp_insert_user(['user_login' => 'co_' . $role . '_' . wp_rand(1000, 99999), 'user_pass' => wp_generate_password(20), 'user_email' => 'co_' . wp_rand(1000, 999999) . '@example.test', 'role' => $role]);
    $tmp_u[] = $id; return $id;
};
$mko = function ($uid, $name) use (&$tmp_p) {
    $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $name, 'meta_input' => ['_tix_org_user_id' => $uid]]);
    $tmp_p[] = $id; return $id;
};
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = $admin ? intval($admin[0]->ID) : 0;
try {
    $ua = $mku('tix_organizer'); $oa = $mko($ua, 'Coxtest Ersteller');
    $ub = $mku('tix_organizer'); $ob = $mko($ub, 'Coxtest Partner');
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Coxtest Event', 'meta_input' => ['_tix_organizer_id' => $oa, '_tix_organizer' => 'Coxtest Ersteller', '_tix_date_start' => '2027-02-27']]);
    $tmp_p[] = $ev;

    wp_set_current_user($ua);
    [$st, $d] = co_rest('GET', "/events/$ev/edit");
    $e = $d['event'] ?? [];
    co_eq('Editor: verfügbar, owner, editierbar, leer', [200, true, 'owner', true, null], [$st, $e['co_organizer_available'] ?? null, $e['event_role'] ?? null, $e['co_organizer_editable'] ?? null, array_key_exists('co_organizer', $e) ? $e['co_organizer'] : 'x']);
    [$st, $d] = co_rest('GET', '/organizers/search', ['q' => 'Coxtest']);
    $names = array_column($d['items'] ?? [], 'id');
    co_eq('Suche: Partner ja, eigener nein', [true, false], [in_array($ob, $names, true), in_array($oa, $names, true)]);
    co_eq('Suche: zu kurz 400', 400, co_rest('GET', '/organizers/search', ['q' => 'C'])[0]);

    [$st, , $code] = co_rest('POST', "/events/$ev", ['co_organizer' => ['id' => $ob, 'access' => true]]);
    co_eq('ohne Haken: 400 tix_co_consent', [400, 'tix_co_consent'], [$st, $code]);
    co_eq('eigener als Co: 400', 400, co_rest('POST', "/events/$ev", ['co_organizer' => ['id' => $oa, 'access' => true, 'consent' => true]])[0]);
    [$st, $d] = co_rest('POST', "/events/$ev", ['co_organizer' => ['id' => $ob, 'access' => true, 'consent' => true]]);
    co_eq('setzen: 200', 200, $st);
    $p = TIX_Co_Organizer::payload($ev);
    co_eq('Payload: id, Zugriff, Haken', [$ob, true, true], [$p['id'] ?? 0, $p['access'] ?? null, !empty($p['consent']['at'])]);
    co_eq('Meta: id + ref gesetzt', [$ob, $ob], [intval(get_post_meta($ev, '_tix_co_organizer_id', true)), intval(get_post_meta($ev, '_tix_co_organizer_ref', true))]);
    [, $d] = co_rest('GET', "/events/$ev");
    co_eq('Ersteller: shared_with = Partner', [$ob, 'owner'], [intval($d['event']['shared_with']['id'] ?? 0), $d['event']['event_role'] ?? null]);

    // Co-Veranstalter
    wp_set_current_user($ub);
    [$st, $d] = co_rest('GET', '/events');
    $mine = array_values(array_filter((array) ($d['events'] ?? $d), function ($x) use ($ev) { return is_array($x) && intval($x['id'] ?? 0) === $ev; }));
    co_eq('Co: Event in der Liste, Rolle co, mit Ersteller', [1, 'co', $oa], [count($mine), $mine[0]['event_role'] ?? null, intval($mine[0]['shared_with']['id'] ?? 0)]);
    [$st, $d] = co_rest('GET', "/events/$ev/edit");
    co_eq('Co: Editor lesend', [200, 'co', false], [$st, $d['event']['event_role'] ?? null, $d['event']['co_organizer_editable'] ?? null]);
    co_eq('Co: Titel speichern 200', 200, co_rest('POST', "/events/$ev", ['title' => 'Coxtest Event neu'])[0]);
    co_eq('Co: Co-Veranstalter ändern 403', [403, 'tix_co_forbidden'], (function ($r) { return [$r[0], $r[2]]; })(co_rest('POST', "/events/$ev", ['co_organizer' => null])));
    co_eq('Co: löschen 403', [403, 'tix_co_forbidden'], (function ($r) { return [$r[0], $r[2]]; })(co_rest('DELETE', "/events/$ev")));
    co_eq('Co: archivieren 403', 403, co_rest('POST', "/events/$ev/archive", ['archived' => true])[0]);
    co_eq('Co: duplizieren 403', 403, co_rest('POST', "/events/$ev/duplicate")[0]);
    co_eq('Co: Export Bestellungen 403', [403, 'tix_co_forbidden'], (function ($r) { return [$r[0], $r[2]]; })(co_rest('GET', '/organizer/export', ['type' => 'orders', 'event_id' => $ev])));
    co_eq('Co: Export Gästeliste nicht gesperrt', true, co_rest('GET', '/organizer/export', ['type' => 'guests', 'event_id' => $ev])[2] !== 'tix_co_forbidden');
    co_eq('Co: Export ohne Event ohne gemeinsames Event', false, in_array($ev, TIX_Export::accessible_event_ids(), true));
    co_eq('Co: Event im Event-Status noch da', 'publish', get_post_status($ev));

    // Zugriff aus
    wp_set_current_user($ua);
    co_eq('Zugriff aus: 200', 200, co_rest('POST', "/events/$ev", ['co_organizer' => ['access' => false]])[0]);
    co_eq('Meta: id weg, ref bleibt, access 0', [0, $ob, '0'], [intval(get_post_meta($ev, '_tix_co_organizer_id', true)), intval(get_post_meta($ev, '_tix_co_organizer_ref', true)), get_post_meta($ev, '_tix_co_organizer_access', true)]);
    wp_set_current_user($ub);
    co_eq('Co ohne Zugriff: Event gesperrt', 403, co_rest('GET', "/events/$ev/edit")[0]);
    wp_set_current_user(0);
    [, $d] = co_rest('GET', "/public/events/$ev");
    co_eq('öffentlich: co_organizer_info = Partner', $ob, intval(($d['event'] ?? $d)['co_organizer_info']['id'] ?? 0));
    // Zugriff wieder an: Haken bleibt gespeichert
    wp_set_current_user($ua);
    co_eq('Zugriff wieder an ohne neuen Haken: 200', 200, co_rest('POST', "/events/$ev", ['co_organizer' => ['access' => true]])[0]);

    // Einladen per E-Mail
    $mail = 'co_neu_' . wp_rand(1000, 999999) . '@example.test';
    co_eq('Einladen ohne Veranstalter-Name 400', 400, co_rest('POST', "/events/$ev/co-organizer/invite", ['email' => $mail, 'consent' => true])[0]);
    co_eq('Einladen eigene E-Mail 409', 409, co_rest('POST', "/events/$ev/co-organizer/invite", ['email' => get_userdata($ua)->user_email, 'consent' => true])[0]);
    [$st, $d] = co_rest('POST', "/events/$ev/co-organizer/invite", ['email' => $mail, 'organizer' => 'Coxtest Neu', 'name' => 'Jan Test', 'consent' => true]);
    $nu = get_user_by('email', $mail); if ($nu) $tmp_u[] = $nu->ID;
    $no = intval($d['co_organizer']['id'] ?? 0); if ($no) $tmp_p[] = $no;
    co_eq('Einladen neu: 201, angelegt, invited', [201, true, 'invited'], [$st, $d['created'] ?? null, $d['co_organizer']['status'] ?? null]);
    co_eq('neues Profil: Art co, Inhaber = neues Konto', ['co', $nu ? $nu->ID : -1], [get_post_meta($no, '_tix_org_kind', true), intval(get_post_meta($no, '_tix_org_user_id', true))]);
    co_eq('Event jetzt beim neuen Co', $no, TIX_Co_Organizer::ref_id($ev));
    [$st, $d] = co_rest('POST', "/events/$ev/co-organizer/invite", ['email' => get_userdata($ub)->user_email, 'consent' => true]);
    co_eq('Einladen vorhandenes Konto: 201, nicht angelegt, Partner', [201, false, $ob], [$st, $d['created'] ?? null, intval($d['co_organizer']['id'] ?? 0)]);

    // Wahl Studio / Lizenz (neues Profil)
    wp_set_current_user($nu->ID);
    [$st, $d] = co_rest('GET', '/organizer/co-choice');
    co_eq('Wahl: Karte zeigen', [200, true, true, null], [$st, $d['show'] ?? null, $d['is_co'] ?? null, array_key_exists('choice', $d) ? $d['choice'] : 'x']);
    co_eq('Wahl ungültig 400', 400, co_rest('POST', '/organizer/co-choice', ['choice' => 'egal'])[0]);
    [$st, $d] = co_rest('POST', '/organizer/co-choice', ['choice' => 'license', 'message' => 'Gern eigene App']);
    co_eq('Wahl Lizenz: angefragt', [200, 'license', true], [$st, $d['choice'] ?? null, !empty($d['requested_at'])]);
    co_eq('danach Karte weg', false, co_rest('GET', '/organizer/co-choice')[1]['show'] ?? null);

    // Plattform-Admin
    wp_set_current_user($admin_id);
    [$st, $d] = co_rest('GET', '/platform/co-organizers');
    $row = array_values(array_filter($d['items'] ?? [], function ($x) use ($no) { return intval($x['organizer_id']) === $no; }));
    co_eq('Plattform-Liste: neues Profil mit Anfrage', [200, 1, 'license', 'co'], [$st, count($row), $row[0]['choice'] ?? null, $row[0]['kind'] ?? null]);
    TIX_Platform_Customers::flush();
    [$st, $d] = co_rest('GET', '/platform/customers', ['art' => 'co', 'q' => 'Coxtest Neu']);
    $rows = $d['items'] ?? ($d['customers'] ?? []);
    co_eq('Kunden: Art co filterbar, co-Feld', [200, 'co', 'license'], [$st, $rows[0]['art'] ?? null, $rows[0]['co']['choice'] ?? null]);
    $key = $rows[0]['key'] ?? 'v-' . $no;
    co_eq('umwandeln falsch 400', 400, co_rest('POST', "/platform/customers/$key/convert", ['to' => 'license'])[0]);
    [$st, $d] = co_rest('POST', "/platform/customers/$key/convert", ['to' => 'provision']);
    co_eq('umwandeln in Provision', [200, 'provision'], [$st, $d['art'] ?? null]);
    co_eq('nochmal umwandeln 409', 409, co_rest('POST', "/platform/customers/$key/convert", ['to' => 'provision'])[0]);
    // Admin setzt ohne Haken
    co_eq('Admin setzt ohne Haken: 200', 200, co_rest('POST', "/events/$ev", ['co_organizer' => ['id' => $no, 'access' => true]])[0]);
    co_eq('Admin: Rolle admin im Editor', 'admin', co_rest('GET', "/events/$ev/edit")[1]['event']['event_role'] ?? null);
    co_eq('Admin entfernt: 200, alles weg', [200, 0, '', ''], (function ($r) use ($ev) { return [$r[0], TIX_Co_Organizer::ref_id($ev), get_post_meta($ev, '_tix_co_organizer_access', true), get_post_meta($ev, '_tix_co_organizer', true)]; })(co_rest('POST', "/events/$ev", ['co_organizer' => null])));
    co_eq('ohne Anmeldung: Suche 401', true, (function () { wp_set_current_user(0); $s = co_rest('GET', '/organizers/search', ['q' => 'Coxtest'])[0]; return in_array($s, [401, 403], true); })());
    co_eq('Syndizierung schützt Co-Meta', true, in_array('_tix_co_organizer_ref', TIX_Syndication_Receive::PROTECTED_META, true));
} finally {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tmp_p as $id) if ($id) wp_delete_post($id, true);
    foreach ($tmp_u as $id) if ($id) wp_delete_user($id);
    TIX_Platform_Customers::flush();
    wp_set_current_user(0);
}
echo ($GLOBALS['t_fails'] ? "FEHLER: {$GLOBALS['t_fails']}" : 'ALLES OK') . " ({$GLOBALS['t_count']} Prüfungen)\n";
