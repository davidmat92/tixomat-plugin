<?php
/**
 * W3 (1.38.884): Benachrichtigungen je Veranstalter (/organizer/notifications), Event „stumm“ (notify_muted im Editor),
 * Promoter-Rest (auth_method, my_tickets_referral), Buchhaltung testen (/studio/invoice-config/test, Status) – ohne echten Lexoffice-Aufruf.
 *   wp eval-file …/test-w3-team-notify-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Org_Notify_REST')) { echo "Tixomat ab 1.38.884 nötig\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = null, $q = []) { $req = new WP_REST_Request($m, '/tixomat/v1' . $r); foreach ($q as $k => $v) $req->set_param($k, $v); if ($b !== null) { $req->set_header('content-type', 'application/json'); $req->set_body(wp_json_encode($b)); } $res = rest_do_request($req); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null, is_array($d) ? ($d['data']['field'] ?? null) : null]; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$keep = get_option('tix_settings');
$keep_inv = get_option('tix_invoice_config', null);
$org = 0; $ev = 0; $sub = 0;
// Lexoffice nie wirklich anfragen: /profile mit Testschlüsseln beantworten, Schlüssel im Fehlertext prüfen
$http = function ($pre, $args, $url) {
    if (strpos($url, 'api.lexoffice.io/v1/profile') === false) return $pre;
    $auth = (string) ($args['headers']['Authorization'] ?? '');
    if ($auth === 'Bearer gut-schluessel-123') return ['headers' => [], 'body' => wp_json_encode(['companyName' => 'Test GmbH']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    return ['headers' => [], 'body' => wp_json_encode(['message' => 'Unauthorized']), 'response' => ['code' => 401, 'message' => 'Unauthorized'], 'cookies' => [], 'filename' => null];
};
add_filter('pre_http_request', $http, 10, 3);
try {
    // ═══ Rein ═══
    $eq('E-Mails: Liste, doppelte raus, Standard an', [[['email' => 'a@example.test', 'label' => '', 'enabled' => 1], ['email' => 'b@example.test', 'label' => 'Kasse', 'enabled' => 0]], null],
        TIX_Org_Notify_REST::clean_emails(['a@example.test', ['email' => 'A@example.test'], ['email' => 'b@example.test', 'label' => 'Kasse', 'enabled' => false]]));
    $eq('E-Mails: ungültig → Feld emails.1', [null, 'emails.1'], TIX_Org_Notify_REST::clean_emails(['a@example.test', 'kaputt']));
    $eq('E-Mails: 11 → emails', [null, 'emails'], TIX_Org_Notify_REST::clean_emails(array_fill(0, 11, 'a@example.test')));
    $now = time();
    $eq('Pause: leer = aus, indefinite, gestern ungültig', [0, TIX_Organizer_Notifications::PAUSE_INDEFINITE, null],
        [TIX_Org_Notify_REST::clean_pause('', $now)[0], TIX_Org_Notify_REST::clean_pause('indefinite', $now)[0], TIX_Org_Notify_REST::clean_pause(wp_date('Y-m-d', $now - 2 * DAY_IN_SECONDS), $now)[0]]);

    $adm = get_users(['role' => 'administrator', 'number' => 1])[0]; wp_set_current_user($adm->ID);
    $org = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => 'W3 Veranstalter']);
    $q = ['organizer_id' => $org];

    // ═══ V3 Benachrichtigungen ═══
    [$s, $d] = $rest('GET', '/organizer/notifications', null, $q);
    $eq('GET: Standard-Arten (Bestellung an, Einlass aus), keine Pause', [200, $org, true, false, false, 7], [$s, $d['organizer_id'] ?? null, $d['types']['orders'] ?? null, $d['types']['checkin'] ?? null, $d['paused'] ?? null, count($d['type_labels'] ?? [])]);
    if (!$multi) $eq('Einzel-Seite: Liste der Veranstalter', true, is_array($d['organizers'] ?? null) && in_array($org, array_column($d['organizers'], 'id'), true));
    $until = wp_date('Y-m-d', time() + 3 * DAY_IN_SECONDS);
    [$s, $d] = $rest('POST', '/organizer/notifications', ['emails' => ['team@example.test', ['email' => 'chef@example.test', 'label' => 'Chef']], 'paused_until' => $until, 'types' => ['checkin' => true, 'failed' => false]], $q);
    $eq('POST: Empfänger, Pause, Arten gespeichert', [200, 2, true, $until, true, false, true],
        [$s, count($d['emails'] ?? []), $d['paused'] ?? null, $d['paused_until'] ?? null, $d['types']['checkin'] ?? null, $d['types']['failed'] ?? null, $d['types']['orders'] ?? null]);
    $eq('Metabox-Daten geschrieben (wirkt im Versand)', [['team@example.test', 'chef@example.test'], true], [TIX_Organizer_Notifications::active_emails($org), TIX_Organizer_Notifications::is_paused($org)]);
    [$s, , $c, $fld] = $rest('POST', '/organizer/notifications', ['emails' => ['ok@example.test', 'kaputt']], $q);
    $eq('ungültige Adresse → 400 tix_bad_email emails.1, nichts geändert', [400, 'tix_bad_email', 'emails.1', 2], [$s, $c, $fld, count(TIX_Organizer_Notifications::get_emails($org))]);
    [$s, , , $fld] = $rest('POST', '/organizer/notifications', ['types' => ['gibts' => true]], $q);
    $eq('unbekannte Art → 400 types.gibts', [400, 'types.gibts'], [$s, $fld]);
    [$s, $d] = $rest('POST', '/organizer/notifications', ['paused_until' => 'indefinite'], $q);
    $eq('Pause unbegrenzt', [200, true, ''], [$s, $d['paused_indefinite'] ?? null, $d['paused_until'] ?? null]);
    [$s, $d] = $rest('POST', '/organizer/notifications', ['paused_until' => ''], $q);
    $eq('Pause aufheben, Empfänger unverändert', [200, false, 2], [$s, $d['paused'] ?? null, count($d['emails'] ?? [])]);
    update_post_meta($org, '_tix_org_notify_pushover', [['user_key' => 'u-geheim', 'app_token' => 't-geheim', 'label' => 'Handy', 'enabled' => 1]]);
    [, $d] = $rest('GET', '/organizer/notifications', null, $q);
    $eq('Pushover nur Bezeichnung/an, keine Schlüssel in der Antwort', [[['label' => 'Handy', 'enabled' => true]], false], [$d['pushover'] ?? null, strpos(wp_json_encode($d), 'geheim') !== false]);

    // ═══ Event stumm ═══
    $ev = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'W3 Event']);
    update_post_meta($ev, '_tix_date_start', wp_date('Y-m-d', time() + 5 * DAY_IN_SECONDS));
    [$s] = $rest('POST', "/events/$ev", ['notify_muted' => true]);
    [, $d] = $rest('GET', "/events/$ev/edit");
    $eq('notify_muted gesetzt (Versand liest _tix_org_notify_muted)', [200, true, '1'], [$s, ($d['event'] ?? $d)['notify_muted'] ?? null, (string) get_post_meta($ev, '_tix_org_notify_muted', true)]);
    $rest('POST', "/events/$ev", ['notify_muted' => false]);
    $eq('notify_muted aus → Meta weg', '', (string) get_post_meta($ev, '_tix_org_notify_muted', true));

    // ═══ V14 Promoter ═══
    [$s, $d] = $rest('POST', '/organizer/promoter-settings', ['auth_method' => 'magic_link', 'my_tickets_referral' => true]);
    $s2 = get_option('tix_settings');
    $eq('Promoter: Anmeldung + Hinweis gespeichert', [200, 'magic_link', true, 'magic_link', 1], [$s, $d['auth_method'] ?? null, $d['my_tickets_referral'] ?? null, $s2['promoter_auth_method'] ?? null, $s2['promoter_my_tickets_enabled'] ?? null]);
    [$s, , , $fld] = $rest('POST', '/organizer/promoter-settings', ['auth_method' => 'sms']);
    $eq('Promoter: unbekannte Anmeldung → 400', [400, 'auth_method'], [$s, $fld]);
    [, $d] = $rest('GET', '/organizer/promoters');
    $eq('GET /organizer/promoters: settings mit auth_method und hints', ['magic_link', true], [$d['settings']['auth_method'] ?? null, isset($d['settings']['hints']['my_tickets_referral'])]);

    // ═══ V16 Buchhaltung ═══
    [$s, $d] = $rest('GET', '/studio/invoice-config');
    $eq('GET: accounting mit status/last_sync/pending', [200, true], [$s, is_array($d['accounting'] ?? null) && array_key_exists('status', $d['accounting']) && array_key_exists('pending', $d['accounting'])]);
    [$s, $d] = $rest('POST', '/studio/invoice-config/test', ['key' => 'gut-schluessel-123']);
    $eq('Test mit gutem Schlüssel: ok, Firma, Schlüssel nicht in der Antwort', [200, true, 'Test GmbH', false], [$s, $d['ok'] ?? null, $d['company'] ?? null, strpos(wp_json_encode($d), 'gut-schluessel') !== false]);
    [$s, $d] = $rest('POST', '/studio/invoice-config/test', ['key' => 'falsch-999']);
    $eq('Test mit falschem Schlüssel: ok false, Meldung ohne Schlüssel', [200, false, false], [$s, $d['ok'] ?? null, strpos(wp_json_encode($d), 'falsch-999') !== false]);
    [$s, $d] = $rest('POST', '/studio/invoice-config', ['accounting' => ['provider' => 'lexoffice', 'key' => 'gut-schluessel-123']]);
    $eq('Schlüssel gespeichert (verschlüsselt), nur key_set', [200, true, false], [$s, $d['accounting']['key_set'] ?? null, strpos(wp_json_encode($d), 'gut-schluessel') !== false]);
    $raw = wp_json_encode(get_option('tix_invoice_config'));
    $eq('Schlüssel nicht im Klartext gespeichert', false, strpos($raw, 'gut-schluessel') !== false);
    [$s, $d] = $rest('POST', '/studio/invoice-config/test', []);
    $eq('Test ohne Schlüssel nimmt den gespeicherten', [200, true], [$s, $d['ok'] ?? null]);

    // ═══ Rechte ═══
    $sfx = substr(md5(microtime()), 0, 6);
    $sub = wp_insert_user(['user_login' => 'w3_' . $sfx, 'user_pass' => wp_generate_password(), 'user_email' => 'w3_' . $sfx . '@example.test', 'role' => 'subscriber']);
    wp_set_current_user($sub);
    [$s1] = $rest('GET', '/organizer/notifications', null, $q);
    [$s2] = $rest('POST', '/studio/invoice-config/test', ['key' => 'x']);
    $eq('Kunde → 401/403 (Benachrichtigungen, Test)', [true, true], [in_array($s1, [401, 403], true), in_array($s2, [401, 403], true)]);
    wp_set_current_user($adm->ID);
} catch (\Throwable $e) { $f++; echo "FEHLER " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
finally {
    remove_filter('pre_http_request', $http, 10);
    update_option('tix_settings', $keep);
    if ($keep_inv === null) delete_option('tix_invoice_config'); else update_option('tix_invoice_config', $keep_inv, false);
    if ($org) wp_delete_post($org, true);
    if ($ev) wp_delete_post($ev, true);
    if ($sub) wp_delete_user($sub);
}
echo $f ? "FEHLER $n Prüfungen, $f Fehler\n" : "ALLES OK ($n Prüfungen)\n";
