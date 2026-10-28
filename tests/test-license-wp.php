<?php
/**
 * Integrationstest Lizenz-Fernschalter (1.38.566) gegen ein lokales Test-WordPress:
 * Lizenzserver (Stufen, Signatur, Admin-Routen, Verlauf) und Kundenseite (Betriebsart, Frist,
 * Sperren je Stufe, /info.license). Stellt alle Optionen am Ende wieder her.
 *
 *   wp eval-file wp-content/plugins/tixomat/tests/test-license-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_License_Stage')) { echo "WordPress mit Tixomat (TIX_License_Stage) nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }

$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function tl_eq($label, $exp, $act) {
    $GLOBALS['t_count']++;
    if ($exp === $act) { echo "ok    $label\n"; return; }
    $GLOBALS['t_fails']++;
    echo "FAIL  $label: erwartet " . var_export($exp, true) . ", ist " . var_export($act, true) . "\n";
}
function tl_rest($method, $route, array $body = []) {
    $r = new WP_REST_Request($method, '/tixomat/v1' . $route);
    if ($body && $method === 'GET') $r->set_query_params($body);
    elseif ($body) { $r->set_header('content-type', 'application/json'); $r->set_body(wp_json_encode($body)); }
    $res = rest_do_request($r);
    $d = $res->get_data();
    return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : ''];
}

$keep = [];
foreach (['tix_license_server', 'tix_license_key', 'tix_license_status', 'tix_license_mode'] as $o) $keep[$o] = get_option($o, null);
$restore = function () use ($keep) {
    foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
    delete_transient('tix_last_sold_event');
};
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = $admin ? intval($admin[0]->ID) : 0;

// ══════════ Bestand: Betriebsart none = immer active ══════════
echo "── Betriebsart none ──\n";
delete_option('tix_license_mode'); delete_option('tix_license_key');
tl_eq('ohne Schlüssel/Flag: none', 'none', TIX_License::mode());
tl_eq('none: Stufe active', 'active', TIX_License_Stage::stage());
tl_eq('none: nicht durchgesetzt', false, TIX_License_Stage::enforced());
tl_eq('Frist 7 Tage', 7, TIX_License::GRACE_DAYS);

// ══════════ Lizenzserver ══════════
echo "── Lizenzserver ──\n";
update_option('tix_license_server', '1', false);
TIX_License_Server::install();
add_action('rest_api_init', ['TIX_License_Server', 'register_routes']);
$lic = TIX_License_Server::create(['customer' => 'Testkunde', 'email' => 'test@example.test', 'valid_until' => gmdate('Y-m-d', strtotime('+60 days')), 'enforce' => true]);
tl_eq('Lizenz angelegt, enforce', 1, intval($lic->enforce));
$today = '2026-11-01';
$L = function (array $f) use ($lic) { $o = clone $lic; foreach ($f as $k => $v) $o->$k = $v; return $o; };
tl_eq('Stufe active (Laufzeit lang)', 'active', TIX_License_Server::stage_for($L(['valid_until' => '2027-01-01', 'last_event_date' => null]), null, $today));
tl_eq('Stufe notice (10 Tage vorher)', 'notice', TIX_License_Server::stage_for($L(['valid_until' => '2026-11-11', 'last_event_date' => null]), null, $today));
tl_eq('Stufe locked (abgelaufen, Event kommt noch)', 'locked', TIX_License_Server::stage_for($L(['valid_until' => '2026-10-01', 'last_event_date' => '2026-11-20']), null, $today));
tl_eq('Stufe locked durch gemeldetes Event', 'locked', TIX_License_Server::stage_for($L(['valid_until' => '2026-10-01', 'last_event_date' => null]), '2026-11-05', $today));
tl_eq('Stufe basis (abgelaufen, Events vorbei)', 'basis', TIX_License_Server::stage_for($L(['valid_until' => '2026-10-01', 'last_event_date' => '2026-10-20']), null, $today));
tl_eq('Fernschalter „sperren“', 'locked', TIX_License_Server::stage_for($L(['valid_until' => '2027-01-01', 'stage_override' => 'locked', 'last_event_date' => '2026-12-01']), null, $today));
tl_eq('Fernschalter „basis“', 'basis', TIX_License_Server::stage_for($L(['valid_until' => '2027-01-01', 'stage_override' => 'basis']), null, $today));
tl_eq('Fernschalter „aktiv“ hebt Ablauf auf', 'active', TIX_License_Server::stage_for($L(['valid_until' => '2026-10-01', 'stage_override' => 'active']), null, $today));
tl_eq('pausiert → locked bis Event', 'locked', TIX_License_Server::stage_for($L(['status' => 'suspended', 'last_event_date' => '2026-11-02']), null, $today));

[$st, $d] = tl_rest('POST', '/license/check', ['key' => $lic->license_key, 'site' => home_url(), 'version' => TIXOMAT_VERSION, 'last_event_date' => '2026-12-24']);
tl_eq('check 200', 200, $st);
tl_eq('check liefert Stufe', 'active', $d['stage'] ?? null);
tl_eq('check enforce', true, $d['enforce'] ?? null);
tl_eq('gemeldetes letztes Event gespeichert', '2026-12-24', (string) TIX_License_Server::get($lic->id)->last_event_date);
tl_eq('Signatur prüft beim Kunden', true, TIX_License::verify($lic->license_key, $d));
$bad = $d; $bad['stage'] = 'active'; $bad['enforce'] = false;
tl_eq('veränderte Antwort fällt durch', false, TIX_License::verify($lic->license_key, $bad));
tl_eq('falscher Schlüssel fällt durch', false, TIX_License::verify('TIX-AAAA-BBBB-CCCC-DDDD', $d));
$old = $d; $old['issued_at'] = time() - 3 * DAY_IN_SECONDS; $old['signature'] = TIX_License_Server::signature($lic->license_key, $old + ['host' => $h]);
tl_eq('alte Antwort (3 Tage) fällt durch', false, TIX_License::verify($lic->license_key, $old));

wp_set_current_user(0);
[$st] = tl_rest('GET', '/license/admin/licenses');
tl_eq('Admin-Liste ohne Login 401', 401, $st);
wp_set_current_user($admin_id);
[$st, $d] = tl_rest('GET', '/license/admin/licenses');
tl_eq('Admin-Liste 200', 200, $st);
$row = null; foreach (($d['licenses'] ?? []) as $x) if ($x['id'] === intval($lic->id)) $row = $x;
tl_eq('Liste enthält Lizenz', true, $row !== null);
tl_eq('Liste ohne vollen Schlüssel', false, $row && in_array($lic->license_key, $row, true));
tl_eq('Heartbeat sichtbar', false, $row ? $row['heartbeat']['overdue'] : null);
[$st, $d] = tl_rest('POST', '/license/admin/licenses/' . $lic->id, ['stage_override' => 'locked']);
tl_eq('Fernschalter sperren 200', 200, $st);
tl_eq('Stufe nach Sperre locked (Event 24.12.)', 'locked', $d['license']['stage'] ?? null);
[$st, , $code] = tl_rest('POST', '/license/admin/licenses/' . $lic->id, ['stage_override' => 'kaputt']);
tl_eq('unbekannter Schalter 400', 400, $st);
[$st, , $code] = tl_rest('POST', '/license/admin/licenses/' . $lic->id, ['basis_url' => 'http://x.test/app.json']);
tl_eq('Basis-Adresse nur https', 'tix_invalid_field', $code);
[$st, $d] = tl_rest('POST', '/license/admin/licenses/' . $lic->id, ['stage_override' => '', 'valid_until' => gmdate('Y-m-d', strtotime('+1 year')), 'basis_url' => 'https://kunde.test/app.json']);
tl_eq('Verlängern 200', 200, $st);
tl_eq('wieder active', 'active', $d['license']['stage'] ?? null);
[$st, $d] = tl_rest('GET', '/license/admin/licenses/' . $lic->id . '/log');
tl_eq('Verlauf hat Einträge (Anlage + 2 Änderungen)', 3, count($d['log'] ?? []));
tl_eq('Verlauf nennt Stufe davor/danach', 'locked', $d['log'][1]['data']['stage_after'] ?? null);

// ══════════ Kundenseite ══════════
echo "── Kundenseite ──\n";
update_option('tix_license_server', '0', false);
update_option('tix_license_mode', 'managed', false);
update_option('tix_license_key', $lic->license_key, false);
tl_eq('Betriebsart managed', 'managed', TIX_License::mode());
tl_eq('managed ist aktiv', true, TIX_License::active());

$now = time();
$base = ['valid' => true, 'status' => 'active', 'enforce' => true, 'signed_at' => $now, 'expires_at' => wp_date('Y-m-d', $now + 300 * DAY_IN_SECONDS)];
tl_eq('compute: frisch active', 'active', TIX_License_Stage::compute($base + ['stage' => 'active'], $now, null));
tl_eq('compute: frisch locked, Event morgen', 'locked', TIX_License_Stage::compute($base + ['stage' => 'locked'], $now, wp_date('Y-m-d', $now + DAY_IN_SECONDS)));
tl_eq('compute: locked, keine Events → basis', 'basis', TIX_License_Stage::compute($base + ['stage' => 'locked'], $now, null));
$off = ['stage' => 'active', 'signed_at' => $now - 6 * DAY_IN_SECONDS, 'expires_at' => wp_date('Y-m-d', $now - DAY_IN_SECONDS)] + $base;
tl_eq('Server weg 6 Tage: letzter Stand gilt', 'active', TIX_License_Stage::compute($off, $now, null));
$off['signed_at'] = $now - 8 * DAY_IN_SECONDS;
tl_eq('Server weg 8 Tage + abgelaufen: selbst gerechnet → basis', 'basis', TIX_License_Stage::compute($off, $now, null));
tl_eq('… mit kommendem Event → locked', 'locked', TIX_License_Stage::compute($off, $now, wp_date('Y-m-d', $now + 3 * DAY_IN_SECONDS)));
$off['expires_at'] = wp_date('Y-m-d', $now + 5 * DAY_IN_SECONDS);
tl_eq('Server weg 8 Tage, läuft bald ab → notice', 'notice', TIX_License_Stage::compute($off, $now, null));
$off['expires_at'] = wp_date('Y-m-d', $now + 90 * DAY_IN_SECONDS);
tl_eq('Server weg 8 Tage, Laufzeit lang → active', 'active', TIX_License_Stage::compute($off, $now, null));

$set = function ($stage, $enforce = true) use ($base) {
    update_option('tix_license_status', ['stage' => $stage, 'enforce' => $enforce, 'last_event_date' => $stage === 'locked' ? '2099-12-31' : null] + $base, false);
};
$set('locked', false);
tl_eq('ohne enforce: active trotz locked', 'active', TIX_License_Stage::stage());
[$st, , $code] = tl_rest('POST', '/customer/orders', ['event_id' => 1]);
tl_eq('ohne enforce: Kauf nicht von Lizenz gesperrt', false, $code === 'tix_license_locked');

$set('locked');
tl_eq('Stufe locked', 'locked', TIX_License_Stage::stage());
[$st, , $code] = tl_rest('POST', '/customer/orders', ['event_id' => 1]);
tl_eq('locked: Kauf 403', 'tix_license_locked', $code);
[$st, , $code] = tl_rest('POST', '/customer/cart/quote', ['items' => []]);
tl_eq('locked: Angebot 403', 'tix_license_locked', $code);
[$st, , $code] = tl_rest('POST', '/pos/orders', ['event_id' => 1]);
tl_eq('locked: Kasse 403', 'tix_license_locked', $code);
[$st, , $code] = tl_rest('POST', '/app/config', ['config' => []]);
tl_eq('locked: Inhalte ändern 403', 'tix_license_locked', $code);
[$st, , $code] = tl_rest('POST', '/checkin/scan', ['code' => 'GL-1-XXXX']);
tl_eq('locked: Einlass nicht gesperrt', false, strpos((string) $code, 'tix_license') === 0);
[$st] = tl_rest('GET', '/public/events');
tl_eq('locked: Events lesen 200', 200, $st);
[$st, $d] = tl_rest('GET', '/info');
tl_eq('/info.license.stage locked', 'locked', $d['license']['stage'] ?? null);
tl_eq('/info.license.enforced', true, $d['license']['enforced'] ?? null);
tl_eq('/info.license.basis_url Standard', home_url('/app.json'), $d['license']['basis_url'] ?? null);

$set('basis');
tl_eq('Stufe basis', 'basis', TIX_License_Stage::stage());
[$st, , $code] = tl_rest('GET', '/public/events');
tl_eq('basis: Katalog 403', 'tix_license_basis', $code);
[$st, , $code] = tl_rest('POST', '/checkin/scan', ['code' => 'GL-1-XXXX']);
tl_eq('basis: Einlass 403', 'tix_license_basis', $code);
[$st] = tl_rest('GET', '/info');
tl_eq('basis: /info 200', 200, $st);
[$st, , $code] = tl_rest('GET', '/customer/tickets');
tl_eq('basis: Tickets lesen nicht von Lizenz gesperrt', false, strpos((string) $code, 'tix_license') === 0);
tl_eq('allowed(): Export lesend', true, TIX_License_Stage::allowed('basis', 'GET', '/organizer/export'));
tl_eq('allowed(): Anmeldung', true, TIX_License_Stage::allowed('basis', 'POST', '/auth/login'));
tl_eq('allowed(): locked Support', true, TIX_License_Stage::allowed('locked', 'POST', '/customer/support'));
tl_eq('allowed(): locked Event ändern', false, TIX_License_Stage::allowed('locked', 'POST', '/events/5'));

$set('notice');
[$st, , $code] = tl_rest('POST', '/customer/orders', ['event_id' => 1]);
tl_eq('notice: Kauf nicht von Lizenz gesperrt', false, strpos((string) $code, 'tix_license') === 0);
tl_eq('notice: Hinweistext', true, strpos(TIX_License_Stage::message('notice'), 'läuft') !== false);

// Aufräumen
global $wpdb;
$wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic->id]);
$wpdb->delete($wpdb->prefix . 'tix_license_sites', ['license_id' => $lic->id]);
$wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $lic->id]);
$restore();
tl_eq('aufgeräumt: Betriebsart wieder wie vorher', $keep['tix_license_mode'] === null ? 'none' : $keep['tix_license_mode'], TIX_License::mode() === 'none' && $keep['tix_license_mode'] === null ? 'none' : TIX_License::mode());
tl_eq('aufgeräumt: Stufe active', 'active', TIX_License_Stage::stage());

echo "\n" . ($GLOBALS['t_fails'] ? $GLOBALS['t_fails'] . ' FEHLER' : 'ALLES GRÜN') . " ({$GLOBALS['t_count']} Prüfungen)\n";

// ══════════ L3: Basis-Inhalt app.json ══════════
echo "── app.json ──\n";
$GLOBALS['t_fails2'] = 0;
$eid = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Basis-Testevent']);
update_post_meta($eid, '_tix_date_start', wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS));
update_post_meta($eid, '_tix_time_start', '22:00');
update_post_meta($eid, '_tix_location', 'Testhalle');
update_post_meta($eid, '_tix_basis_ticket_url', 'https://tickets.example.test/1');
$past = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'Basis-Altes-Event']);
update_post_meta($past, '_tix_date_start', '2020-01-01');
tl_eq('app.json geschrieben', true, TIX_Basis_Content::write());
$j = json_decode((string) file_get_contents(TIX_Basis_Content::file()), true);
tl_eq('Format-Version 1', 1, $j['version'] ?? null);
tl_eq('Marke hat Namen', true, ($j['brand']['name'] ?? '') !== '');
$ev = null; foreach (($j['events'] ?? []) as $e) if ($e['id'] === $eid) $ev = $e;
tl_eq('kommendes Event drin', 'Basis-Testevent', $ev['title'] ?? null);
tl_eq('Ticket-Link übernommen', 'https://tickets.example.test/1', $ev['ticket_url'] ?? null);
tl_eq('vergangenes Event fehlt', false, in_array($past, array_column($j['events'] ?? [], 'id'), true));
$flat = wp_json_encode($j);
tl_eq('keine Kunden-E-Mails (kein @example.test-Kunde)', false, strpos($flat, 'test@example.test') !== false);
tl_eq('Schlüssel vollständig', ['version', 'updated_at', 'source', 'brand', 'home', 'pages', 'contact', 'social', 'legal', 'events'], array_keys($j));
[$st, $d] = tl_rest('GET', '/basis/content');
tl_eq('REST /basis/content 200', 200, $st);
tl_eq('REST liefert dieselben Events', count($j['events']), count($d['events'] ?? []));
wp_delete_post($eid, true); wp_delete_post($past, true);
TIX_Basis_Content::write();
echo "\n" . ($GLOBALS['t_fails'] ? $GLOBALS['t_fails'] . ' FEHLER' : 'ALLES GRÜN') . " ({$GLOBALS['t_count']} Prüfungen gesamt)\n";
