<?php
/**
 * Bearbeitungssperre je Event (1.38.789). Über Seiten hinweg: tests/test-co-remote-e2e.php.   wp eval-file …/test-edit-lock-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Edit_Lock')) { echo "Tixomat ab 1.38.789 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $tok = '') { $x = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b !== null) { $x->set_header('content-type', 'application/json'); $x->set_body(wp_json_encode($b)); } if ($tok !== '') $x->set_header('X-Tix-Edit-Lock', $tok); $res = rest_do_request($x); return [$res->get_status(), $res->get_data()]; };
$users = []; $ev = 0;
$keep = ['tix_edit_lock_strict' => get_option('tix_edit_lock_strict', null)];
try {
    TIX_Edit_Lock::install();
    $adm = get_users(['role' => 'administrator', 'number' => 1])[0];
    $mk = function ($n) use (&$users) { $id = wp_insert_user(['user_login' => $n . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => $n . wp_rand(1000, 9999) . '@example.test', 'role' => 'administrator', 'display_name' => ucfirst($n)]); $users[] = $id; return $id; };
    $A = $mk('anna'); $B = $mk('bernd');
    wp_set_current_user($A);
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Sperr-Test']);
    [$st, $d] = $rest('POST', "/events/$ev/lock", []);
    $ta = (string) ($d['token'] ?? '');
    $eq('Anna holt die Sperre: Token, Halter, ttl', [200, true, 'Anna', 300], [$st, strlen($ta) === 32, $d['holder']['name'] ?? null, $d['ttl'] ?? null]);
    wp_set_current_user($B);
    [$st, $d] = $rest('GET', "/events/$ev/lock");
    $eq('Bernd sieht: gesperrt von Anna, nicht seine', [200, true, false, 'Anna'], [$st, $d['locked'] ?? null, $d['mine'] ?? null, $d['holder']['name'] ?? null]);
    [$st, $d] = $rest('POST', "/events/$ev/lock", []);
    $eq('Bernd will auch: 409 tix_event_locked mit Name', [409, 'tix_event_locked', 'Anna', true], [$st, $d['code'] ?? null, $d['data']['holder']['name'] ?? null, strpos((string) ($d['message'] ?? ''), 'Anna') !== false]);
    [$st, $d] = $rest('POST', "/events/$ev", ['title' => 'Von Bernd']);
    $eq('Bernd speichert: 409, nichts gespeichert', [409, 'tix_event_locked', 'Sperr-Test'], [$st, $d['code'] ?? null, get_the_title($ev)]);
    [$st] = $rest('POST', "/events/$ev/guestlist", ['name' => 'Gast']);
    $eq('Gästeliste (kein Inhalt) bleibt für Bernd möglich', true, $st !== 409);
    wp_set_current_user($A);
    [$st] = $rest('POST', "/events/$ev", ['title' => 'Von Anna'], $ta);
    clean_post_cache($ev);
    $eq('Anna speichert mit Token', [200, 'Von Anna'], [$st, get_the_title($ev)]);
    [$st, $d] = $rest('POST', "/events/$ev/lock", ['token' => $ta]);
    $eq('Herzschlag: gleiches Token verlängert', [200, $ta], [$st, $d['token'] ?? null]);
    // Ablauf
    $wpdb->update(TIX_Edit_Lock::table(), ['expires_at' => gmdate('Y-m-d H:i:s', time() - 5)], ['event_id' => $ev]);
    wp_set_current_user($B);
    [$st, $d] = $rest('POST', "/events/$ev/lock", []);
    $tb = (string) ($d['token'] ?? '');
    $eq('nach Ablauf: Bernd bekommt die Sperre', [200, 'Bernd'], [$st, $d['holder']['name'] ?? null]);
    wp_set_current_user($A);
    [$st, $d] = $rest('POST', "/events/$ev/lock", ['token' => $ta]);
    $eq('Annas alter Herzschlag: 409 tix_event_lock_lost (Bernd hält sie)', [409, 'tix_event_lock_lost', 'Bernd'], [$st, $d['code'] ?? null, $d['data']['holder']['name'] ?? null]);
    // Lösen durch Admin
    wp_set_current_user($adm->ID);
    [$st, $d] = $rest('POST', "/events/$ev/lock/break", []);
    $eq('Admin löst Bernds Sperre', [200, 'Bernd'], [$st, $d['released']['holder']['name'] ?? null]);
    wp_set_current_user($B);
    [$st, $d] = $rest('POST', "/events/$ev/lock", ['token' => $tb]);
    $eq('Bernds Herzschlag: 409 tix_event_lock_lost mit „von wem“', [409, 'tix_event_lock_lost', true], [$st, $d['code'] ?? null, strpos((string) ($d['data']['by'] ?? ''), $adm->display_name) === 0]);
    // Freigeben
    [$st, $d] = $rest('POST', "/events/$ev/lock", []);
    $tb2 = (string) ($d['token'] ?? '');
    [$st, $d] = $rest('DELETE', "/events/$ev/lock", ['token' => $tb2]);
    $eq('Freigeben', [200, true, false], [$st, $d['released'] ?? null, $rest('GET', "/events/$ev/lock")[1]['locked'] ?? null]);
    // ohne Sperre: wie bisher speichern; strenger Modus: 409
    [$st] = $rest('POST', "/events/$ev", ['title' => 'Ohne Sperre']);
    $eq('ohne Sperre (nicht streng): Speichern geht', 200, $st);
    update_option('tix_edit_lock_strict', '1');
    [$st, $d] = $rest('POST', "/events/$ev", ['title' => 'Streng']);
    $eq('streng: ohne Token 409 tix_event_lock_required', [409, 'tix_event_lock_required'], [$st, $d['code'] ?? null]);
    wp_set_current_user($A);
    [, $d] = $rest('POST', "/events/$ev/lock", []);
    [$st] = $rest('POST', "/events/$ev", ['title' => 'Streng mit Token'], (string) ($d['token'] ?? ''));
    $eq('streng: mit Token geht', 200, $st);
    wp_set_current_user($B);
    // Studio-Wünsche (Plan Studio 13.11.)
    wp_set_current_user($A);
    $rest('POST', "/events/$ev/lock/release", [], (string) ($d['token'] ?? ''));
    [$st, $d] = $rest('POST', "/events/$ev/lock", []);
    $t1 = (string) ($d['lock']['token'] ?? '');
    $eq('Antwort mit lock{token,expires_at,ttl}, holder mit since und channel', [200, true, true, 'studio'], [$st, $t1 !== '' && $t1 === ($d['token'] ?? null), !empty($d['holder']['since']), $d['holder']['channel'] ?? null]);
    [$st, $d] = $rest('POST', "/events/$ev/lock/heartbeat", ['token' => $t1]);
    $eq('Herzschlag-Route verlängert', [200, $t1], [$st, $d['lock']['token'] ?? null]);
    [$st, $d] = $rest('POST', "/events/$ev/lock", []);
    $t2 = (string) ($d['token'] ?? '');
    [$st, $d] = $rest('POST', "/events/$ev/lock/heartbeat", ['token' => $t1]);
    $eq('zweites Fenster derselben Person übernimmt; altes Fenster: 409 tix_event_lock_other_window', [409, 'tix_event_lock_other_window', true], [$st, $d['code'] ?? null, $t2 !== $t1]);
    [$st] = $rest('POST', "/events/$ev", ['title' => 'Altes Fenster'], $t1);
    $eq('altes Fenster speichert nicht', 409, $st);
    $x = new WP_REST_Request('POST', "/tixomat/v1/events/$ev"); $x->set_header('content-type', 'application/json'); $x->set_header('X-Tix-Lock', $t2); $x->set_body(wp_json_encode(['title' => 'Mit X-Tix-Lock']));
    $eq('Kopfzeile X-Tix-Lock gilt wie X-Tix-Edit-Lock', 200, rest_do_request($x)->get_status());
    [$st] = $rest('POST', "/events/$ev", ['title' => 'Mit lock_token', 'lock_token' => $t2]);
    $eq('Body lock_token gilt', 200, $st);
    wp_set_current_user($B);
    $x = new WP_REST_Request('POST', "/tixomat/v1/events/$ev/image"); $x->set_body_params(['lock_token' => 'falsch123456789012345']);
    $eq('Bild-Upload (Formular) mit fremdem Token: 409', 409, rest_do_request($x)->get_status());
    wp_set_current_user($A);
    $up = function ($tok, $how) use ($ev) {
        $x = new WP_REST_Request('POST', "/tixomat/v1/events/$ev/image");
        if ($how === 'body') $x->set_body_params(['lock_token' => $tok]); else $x->set_query_params(['lock_token' => $tok]);
        $x->set_file_params(['file' => ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '/nonexistent', 'error' => 4, 'size' => 0]]);
        $r = rest_do_request($x); $d = $r->get_data();
        return [$r->get_status(), is_array($d) ? ($d['code'] ?? null) : null];
    };
    foreach (['Formularfeld' => 'body', '?lock_token=' => 'query'] as $lbl => $how) {
        [$s1, $c1] = $up('altesToken12345678901234567890', $how);
        [$s2] = $up($t2, $how);
        $eq("Bild-Upload, lock_token als $lbl wird gelesen: falsches Token 409, richtiges kein 409", [409, 'tix_event_lock_other_window', true], [$s1, $c1, $s2 !== 409]);
    }
    [, $d] = $rest('GET', "/events/$ev/edit"); $ed = $d['event'] ?? $d;
    $eq('GET /edit liefert edit_lock (meine)', [true, true], [$ed['edit_lock']['locked'] ?? null, $ed['edit_lock']['mine'] ?? null]);
    $lr = TIX_Edit_Lock::list_locks(new WP_REST_Response(['ok' => true, 'events' => [['id' => $ev], ['id' => 999999]]], 200), null, new WP_REST_Request('GET', '/tixomat/v1/events'));
    $mine = $lr->get_data()['events'][0];
    $eq('Event-Liste: andere ohne Sperre null', null, $lr->get_data()['events'][1]['locked_by']);
    $eq('Event-Liste: locked_by mit Name', ['Anna'], [$mine === null ? 'nicht in Liste' : ($mine['locked_by']['name'] ?? null)]);
    wp_set_current_user($B);
    [$st, $d] = $rest('POST', "/events/$ev/lock", []);
    $eq('409 enthält can_break und holder.since', [409, true, true], [$st, array_key_exists('can_break', (array) ($d['data'] ?? [])), !empty($d['data']['holder']['since'])]);
    wp_set_current_user($A);
    $x = new WP_REST_Request('POST', "/tixomat/v1/events/$ev/lock/release"); $x->set_header('content-type', 'text/plain;charset=UTF-8'); $x->set_body(wp_json_encode(['token' => $t2]));
    $r = rest_do_request($x);
    $eq('Freigeben per sendBeacon (text/plain)', [200, true], [$r->get_status(), $r->get_data()['released'] ?? null]);
    [, $d] = $rest('POST', "/events/$ev/lock", []); $t3 = (string) ($d['token'] ?? '');
    $wpdb->delete(TIX_Edit_Lock::table(), ['event_id' => $ev]);
    [$st, $d] = $rest('POST', "/events/$ev/lock/heartbeat", ['token' => $t3]);
    $eq('Herzschlag nach Ablauf (frei): Sperre zurück mit demselben Token', [200, $t3], [$st, $d['token'] ?? null]);
    $rest('POST', "/events/$ev/lock/release", ['token' => $t3]);
    $eq('nur Admins lösen fremde Sperren (Team nicht)', true, (function () use ($rest, $ev) { $u = wp_insert_user(['user_login' => 'tm' . wp_rand(1000, 9999), 'user_pass' => wp_generate_password(20), 'user_email' => 'tm' . wp_rand(1000, 9999) . '@example.test', 'role' => 'tix_organizer']); wp_set_current_user($u); $s = $rest('POST', "/events/$ev/lock/break", [])[0]; require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u); return in_array($s, [403, 401], true); })());
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    foreach ($keep as $k => $v) { if ($v === null) delete_option($k); else update_option($k, $v); }
    if ($ev) { $wpdb->delete(TIX_Edit_Lock::table(), ['event_id' => $ev]); wp_delete_post($ev, true); }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($users as $u) wp_delete_user($u);
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
