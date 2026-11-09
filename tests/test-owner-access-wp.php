<?php
/** Inhaber-Zugang, Einladung, Stammdaten, Startklar (TIX_Owner_Access + /platform/customers, §103). wp eval-file …/tests/test-owner-access-wp.php (Plattform; Einzel-Seite nur Kundenseiten-Teil). */
if (!defined('ABSPATH') || !class_exists('TIX_Owner_Access')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function oa_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function oa_rest($m, $r, array $b = []) { if (class_exists('TIX_Platform_Customers')) TIX_Platform_Customers::flush(); $qs = []; if (strpos($r, '?')) { parse_str(substr(strstr($r, '?'), 1), $qs); $r = strstr($r, '?', true); } $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($qs) $q->set_query_params($qs); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '']; }
global $wpdb;
$GLOBALS['oa_mails'] = [];
add_filter('pre_wp_mail', function ($r, $a) { $GLOBALS['oa_mails'][] = $a; return true; }, 1, 2);
add_filter('pre_http_request', function ($pre, $a, $url) { return strpos($url, '/license/ping') !== false ? ['response' => ['code' => 200, 'message' => ''], 'body' => '{"stage":"active"}', 'headers' => [], 'cookies' => []] : $pre; }, 10, 3);
$sfx = wp_rand(1000, 9999); $users = []; $posts = []; $lics = []; $opts = [];
$done0 = get_option(TIX_Owner_Access::OPT_DONE, null);

// ── Kundenseite ──
$GLOBALS['oa_mails'] = [];
$r = TIX_Owner_Access::invite("inh$sfx@example.test", 'Ina Inhaber');
$users[] = $r['user_id'] ?? 0;
$u = get_userdata($r['user_id'] ?? 0);
oa_eq('Einladen: Konto neu, Rolle Inhaber, Merkmal, Mail mit Passwort-Link', [true, true, '1', 1, true],
    [$r['created'] ?? null, $u && in_array('tix_organizer', $u->roles, true), (string) get_user_meta($u->ID, TIX_Owner_Access::OWNER_META, true), count($GLOBALS['oa_mails']), strpos((string) ($GLOBALS['oa_mails'][0]['message'] ?? ''), 'action=rp') !== false]);
$sf = function () { return 'https://studio.example.test'; };
add_filter('pre_option_' . TIX_Studio::OPTION, $sf);
$GLOBALS['oa_mails'] = [];
$ok = TIX_Owner_Access::send_invite($u, 'Ina Inhaber', true);
remove_filter('pre_option_' . TIX_Studio::OPTION, $sf);
$m = (string) ($GLOBALS['oa_mails'][0]['message'] ?? '');
oa_eq('Mit Studio: Code + /passwort/?email=…&art=owner statt WordPress-Link', [true, true, true, false],
    [$ok, (bool) preg_match('/letter-spacing:6px;">\d{6}</', $m), strpos($m, 'studio.example.test/passwort/?email=') !== false && strpos($m, 'art=owner') !== false, strpos($m, 'action=rp') !== false]);
$GLOBALS['oa_mails'] = [1];
$r2 = TIX_Owner_Access::invite("INH$sfx@example.test", '', ['no_mail' => true]);
oa_eq('Zweites Mal: vorhandenes Konto, keine Mail', [false, $r['user_id'], false, 1], [$r2['created'], $r2['user_id'], $r2['mailed'], count($GLOBALS['oa_mails'])]);
oa_eq('Ungültige E-Mail → 400', 'tix_invalid_field', TIX_Owner_Access::invite('kein')->get_error_code());
$rd = TIX_Owner_Access::readiness();
oa_eq('Startklar: vier Ja/Nein-Felder, Inhaber vorhanden', [['payment', 'legal', 'owner', 'homepage'], true], [array_keys($rd), $rd['owner']]);
// Einladung aus der Lizenzprüfung: nur mit gültiger Signatur
$key = 'TEST-' . strtoupper(wp_generate_password(8, false)) . '-AAAA-BBBB';
$host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
$inv = ['email' => "sig$sfx@example.test", 'name' => 'Sig', 'nonce' => 'n' . $sfx];
$t = time();
$good = hash_hmac('sha256', TIX_Owner_Access::invite_base($host, $inv, $t), strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $key)));
TIX_Owner_Access::from_check($key, ['owner_invite' => $inv, 'owner_invite_sig' => str_repeat('a', 64), 'issued_at' => $t]);
oa_eq('Falsche Signatur → kein Konto', false, (bool) email_exists("sig$sfx@example.test"));
TIX_Owner_Access::from_check($key, ['owner_invite' => $inv, 'owner_invite_sig' => $good, 'issued_at' => $t - 3 * DAY_IN_SECONDS]);
oa_eq('Alte Antwort → kein Konto', false, (bool) email_exists("sig$sfx@example.test"));
TIX_Owner_Access::from_check($key, ['owner_invite' => $inv, 'owner_invite_sig' => $good, 'issued_at' => $t]);
$sid = email_exists("sig$sfx@example.test"); $users[] = $sid;
oa_eq('Gültige Signatur → Konto + erledigt gemerkt', [true, 'n' . $sfx, true], [(bool) $sid, get_option(TIX_Owner_Access::OPT_DONE)['nonce'] ?? '', get_option(TIX_Owner_Access::OPT_DONE)['ok'] ?? null]);
$cp = TIX_Owner_Access::check_params();
oa_eq('Rückmeldung in der nächsten Prüfung', ['n' . $sfx, '1'], [$cp['invite_done'], $cp['invite_ok']]);

if (TIX_App_Scope::multi()) {
    $adm = wp_insert_user(['user_login' => "oa_adm_$sfx", 'user_pass' => wp_generate_password(), 'user_email' => "oa_adm_$sfx@example.test", 'role' => 'administrator']); $users[] = $adm;
    wp_set_current_user($adm);
    $ls_old = get_option('tix_license_server', '0'); update_option('tix_license_server', '1'); TIX_License_Server::maybe_install();
    $kurz = 'oak' . $sfx;
    $L = TIX_License_Server::create(['customer' => 'OA Gehostet', 'email' => 'hallo@evendis.de', 'valid_until' => gmdate('Y-m-d', strtotime('+1 year')), 'note' => "[kunden-hetzner:$kurz] Gehostet auf kunden-hetzner, Host $host (Betriebsart managed)."]); $lics[] = $L->id;

    // Ende-zu-Ende über /license/check: Seite = diese Seite
    [$st, $d] = oa_rest('POST', '/platform/customers/h-' . $kurz . '/owner', ['email' => "owner$sfx@example.test", 'name' => 'Otto']);
    oa_eq('Owner (gehostet): vorgemerkt, Lizenz-Mail statt hallo@evendis.de', [200, true, 'queued', "owner$sfx@example.test"], [$st, $d['queued'] ?? null, $d['owner']['status'] ?? '', TIX_License_Server::get($L->id)->email]);
    $q = new WP_REST_Request('POST', '/tixomat/v1/license/check');
    foreach (['key' => $L->license_key, 'site' => home_url(), 'version' => TIXOMAT_VERSION, 'mode' => 'managed'] + TIX_Owner_Access::check_params() as $k => $v) $q->set_param($k, $v);
    $chk = TIX_License_Server::rest_check($q)->get_data();
    oa_eq('Prüfung liefert signierte Einladung', ["owner$sfx@example.test", true], [$chk['owner_invite']['email'] ?? '', TIX_Owner_Access::verify_invite($L->license_key, (array) ($chk['owner_invite'] ?? []), (string) ($chk['owner_invite_sig'] ?? ''), intval($chk['issued_at'] ?? 0))]);
    TIX_Owner_Access::from_check($L->license_key, $chk);
    $oid_u = email_exists("owner$sfx@example.test"); $users[] = $oid_u;
    oa_eq('Seite legt das Konto an', true, (bool) $oid_u);
    $q2 = new WP_REST_Request('POST', '/tixomat/v1/license/check');
    foreach (['key' => $L->license_key, 'site' => home_url(), 'version' => TIXOMAT_VERSION, 'mode' => 'managed'] + TIX_Owner_Access::check_params() as $k => $v) $q2->set_param($k, $v);
    $chk2 = TIX_License_Server::rest_check($q2)->get_data();
    oa_eq('Nächste Prüfung: Einladung erledigt, nicht erneut geschickt', ['done', false], [TIX_Owner_Access::queued($L->id)['status'] ?? '', isset($chk2['owner_invite'])]);
    [$st, $d] = oa_rest('GET', '/platform/customers/h-' . $kurz);
    $items = array_column($d['customer']['ready']['items'] ?? [], 'ok', 'key');
    oa_eq('Akte: owner done, Startklar von der Seite gemeldet', ['done', 'site', true, 4], [$d['customer']['owner']['status'] ?? '', $d['customer']['ready']['source'] ?? '', $items['owner'] ?? null, count($items)]);

    // Stammdaten
    [$st, $d] = oa_rest('POST', '/platform/customers/h-' . $kurz . '/license', ['customer' => 'OA Neu GmbH', 'note' => 'nur Text']);
    oa_eq('Stammdaten: Name geändert, Hosting-Merkmal bleibt in der Notiz', [200, 'OA Neu GmbH', true], [$st, $d['license']['customer'] ?? '', strpos((string) ($d['license']['note'] ?? ''), "[kunden-hetzner:$kurz]") === 0]);
    [$st, , $code] = oa_rest('POST', '/platform/customers/h-' . $kurz . '/license', ['email' => 'kein']);
    oa_eq('Stammdaten: falsche E-Mail → 400', [400, 'tix_invalid_field'], [$st, $code]);
    [$st, $d] = oa_rest('GET', '/platform/customers/h-' . $kurz);
    oa_eq('… Schlüssel bleibt h-kurz', 'h-' . $kurz, $d['customer']['key'] ?? '');

    // Provision einladen
    $GLOBALS['oa_mails'] = [];
    [$st, $o] = oa_rest('POST', '/platform/offers', ['customer' => ['company' => 'OA Club', 'email' => "club$sfx@example.test"], 'items' => ['tickets']]);
    $ofid = $o['offer']['id'] ?? 0;
    [$st, $iv] = oa_rest('POST', '/platform/customers/invite', ['email' => "club$sfx@example.test", 'name' => 'Clara', 'organizer' => "OA Club $sfx", 'offer_id' => $ofid]);
    $posts[] = $iv['organizer_id'] ?? 0; $users[] = $iv['user_id'] ?? 0;
    oa_eq('Einladen (Provision): 201, Schlüssel v-, Mail, Status pending', [201, 'v-' . ($iv['organizer_id'] ?? 0), true, 1, 'pending'],
        [$st, $iv['key'] ?? '', $iv['mailed'] ?? null, count($GLOBALS['oa_mails']), TIX_Org_Approval::status($iv['organizer_id'] ?? 0)]);
    [$st, $r] = oa_rest('GET', '/platform/offers?customer=' . ($iv['key'] ?? 'x'));
    oa_eq('… Angebot ist mit dem neuen Kunden verknüpft', [$ofid], array_column($r['offers'] ?? [], 'id'));
    [$st, , $code] = oa_rest('POST', '/platform/customers/invite', ['email' => "club$sfx@example.test", 'organizer' => 'X']);
    oa_eq('E-Mail vergeben → 409', [409, 'tix_email_exists'], [$st, $code]);
    [$st, $d] = oa_rest('GET', '/platform/customers/' . ($iv['key'] ?? 'x'));
    $it = array_column($d['customer']['ready']['items'] ?? [], 'ok', 'key');
    oa_eq('Akte Provision: Zugang aktiv, Seite noch nicht online, nicht startklar', ['active', true, false, false], [$d['customer']['owner']['status'] ?? '', $it['owner'] ?? null, $it['homepage'] ?? null, $d['customer']['ready']['ok'] ?? null]);
    $GLOBALS['oa_mails'] = [];
    [$st, $d] = oa_rest('POST', '/platform/customers/' . ($iv['key'] ?? 'x') . '/owner', []);
    oa_eq('Owner (Provision): erneut einladen', [200, true, 1], [$st, $d['mailed'] ?? null, count($GLOBALS['oa_mails'])]);
    [$st, , $code] = oa_rest('POST', '/platform/customers/' . ($iv['key'] ?? 'x') . '/owner', ['email' => 'anders@example.test']);
    oa_eq('Owner (Provision) mit anderer E-Mail → 409', [409, 'tix_owner_exists'], [$st, $code]);
    [$st, , $code] = oa_rest('POST', '/platform/customers/invite', ['email' => 'neu@example.test']);
    oa_eq('Einladen ohne Veranstalter-Name → 400', [400, 'tix_invalid_field'], [$st, $code]);
    $wpdb->delete(TIX_Platform_Offers::table(), ['id' => $ofid]);
    update_option('tix_license_server', $ls_old);
}

// Aufräumen
foreach ($lics as $x) { $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $x]); $wpdb->delete($wpdb->prefix . 'tix_license_sites', ['license_id' => $x]); $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $x]); delete_option(TIX_Owner_Access::OPT_QUEUE . $x); }
foreach ($posts as $x) if ($x) wp_delete_post($x, true);
if ($done0 === null) delete_option(TIX_Owner_Access::OPT_DONE); else update_option(TIX_Owner_Access::OPT_DONE, $done0, false);
require_once ABSPATH . 'wp-admin/includes/user.php'; foreach (array_filter($users) as $x) wp_delete_user($x); wp_set_current_user(0);
echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n";
