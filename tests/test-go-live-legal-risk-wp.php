<?php
/** Live schalten trotz unvollständiger Rechtstexte mit Hinweis (1.38.909, User 23.11.). wp eval-file …/test-go-live-legal-risk-wp.php (Kunden-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Go_Live') || !method_exists('TIX_Go_Live', 'legal_warning')) { echo "Tixomat ab 1.38.909 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = [], $raw = null, $hd = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); foreach ($hd as $k => $v) $q->set_header($k, $v); if ($raw !== null) { $q->set_header('content-type', 'application/json'); $q->set_body($raw); } elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
global $wpdb;
$G = 'TIX_Go_Live';
if (!$multi) {
    $keep = [];
    foreach (['tix_license_key', 'tix_license_mode', 'tix_license_server', $G::OPT_STATE, $G::OPT_RISK, $G::OPT_RISK_MAIL, TIX_Studio_Payments::OPT_NONE, 'tix_demo_instance'] as $o) $keep[$o] = get_option($o, null);
    $pages_bak = []; $created = [];
    $setpage = function ($k, $html) use (&$pages_bak, &$created) {
        $p = TIX_Studio_Legal::page($k);
        if ($p) { if (!isset($pages_bak[$p->ID])) $pages_bak[$p->ID] = [$p->post_content, $p->post_status]; wp_update_post(['ID' => $p->ID, 'post_content' => $html, 'post_status' => 'publish']); }
        else { $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $k, 'post_name' => TIX_Studio_Legal::DOCS[$k][1][0], 'post_content' => $html]); $created[] = $id; }
    };
    $calls = [];
    $hk = function ($pre, $ar, $url) use (&$calls) { if (strpos($url, '/license/go-live') === false) return $pre; $calls[] = (string) $ar['body']; return ['headers' => [], 'body' => '{"ok":true,"status":"pending"}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null]; };
    $mails = []; $mh = function ($pre, $atts) use (&$mails) { $mails[] = $atts; return true; };
    try {
        update_option('tix_demo_instance', '0', false);
        update_option('tix_license_key', 'TIX-GLRISK-' . strtoupper(wp_generate_password(8, false)), false); update_option('tix_license_mode', 'selfhosted', false); update_option('tix_license_server', '0', false);
        delete_option($G::OPT_STATE); delete_option($G::OPT_RISK); delete_option($G::OPT_RISK_MAIL);
        update_option(TIX_Studio_Payments::OPT_NONE, '1', false);
        $setpage('impressum', '<p>Angaben gemäß § 5 DDG: [PLATZHALTER: Firma] GmbH, Clubstraße 2, 42103 Wuppertal. E-Mail: info@muster.test.</p>');
        add_filter('pre_http_request', $hk, 10, 3);
        $other = array_values(array_filter($G::checklist()['items'], function ($i) { return $i['required'] && !$i['ok'] && $i['key'] !== 'legal'; }));
        $eq('Ausgang: Rechtstexte offen', false, TIX_Studio_Legal::legal_status()['ok']);
        $eq('Kein Dauerhinweis, solange die Seite im Test ist', false, $G::legal_warning()['active']);
        [$s, $d, $c] = $rest('POST', '/studio/go-live', ['action' => 'live']);
        $eq('Ohne Bestätigung: 409 tix_legal_incomplete mit items, can_accept, accept_param, notice, missing', [409, 'tix_legal_incomplete', true, true, 'accept_legal_risk', true, true],
            [$s, $c, !empty($d['data']['items']), !empty($d['data']['can_accept']), $d['data']['accept_param'] ?? '', ($d['data']['notice'] ?? '') !== '', !empty($d['data']['missing'])]);
        $eq('Nichts an die Plattform, kein Nachweis', [0, []], [count($calls), $G::risk_log()]);
        [$s, $d, $c] = $rest('POST', '/studio/go-live', ['action' => 'live', 'accept_legal_risk' => 'nein']);
        $eq('accept_legal_risk ungleich true: weiter 409', 409, $s);
        [$s, $d, $c] = $rest('POST', '/studio/go-live', ['action' => 'live', 'accept_legal_risk' => true]);
        if ($other) {
            $eq('Mit Bestätigung, aber anderes fehlt: 409 tix_not_ready ohne Nachweis', [409, 'tix_not_ready', []], [$s, $c, $G::risk_log()]);
        } else {
            $body = json_decode((string) ($calls[0] ?? ''), true) ?: [];
            $log = $G::risk_log();
            $eq('Mit Bestätigung: 200 requested, legal_risk_accepted, Plattform bekommt legal_risk mit fehlenden Punkten', [200, 'requested', true, true, true],
                [$s, $d['state']['status'] ?? '', !empty($d['legal_risk_accepted']), !empty($body['legal_risk']['accepted']), in_array('impressum', array_column((array) ($body['legal_risk']['missing'] ?? []), 'key'), true)]);
            $eq('Nachweis: wer, wann, welche Punkte, Hinweistext', [1, intval($a[0]->ID), true, true, true],
                [count($log), intval($log[0]['user_id'] ?? 0), !empty($log[0]['at']), in_array('impressum', array_column((array) ($log[0]['missing'] ?? []), 'key'), true), ($log[0]['text'] ?? '') === $G::RISK_TEXT]);
            $eq('Nachweis ohne E-Mail-Adresse', false, strpos(wp_json_encode($log), (string) $a[0]->user_email) !== false);
        }
        // live (Ergebnis der Plattform) → Dauerhinweis
        update_option($G::OPT_STATE, ['status' => 'live', 'action' => 'live', 'nonce' => 'nx'], false);
        $w = $G::legal_warning();
        [, $rd] = $rest('GET', '/studio/readiness');
        $eq('Live + unvollständig: Dauerhinweis aktiv mit fehlenden Punkten, Link, auch in /studio/readiness', [true, 'warning', true, '/rechtstexte/', true],
            [$w['active'], $w['level'], in_array('impressum', array_column($w['missing'], 'key'), true), $w['link'], !empty($rd['legal_warning']['active'])]);
        add_filter('pre_wp_mail', $mh, 1, 2);
        $eq('Vorgabe (User-Entscheid 23.11.): Erinnerungsmail aus, mail_daily false, keine Mail', [false, 0, 0], [$w['mail_daily'], $G::daily_warning(), count($mails)]);
        update_option($G::OPT_RISK_MAIL, '1', false);
        $sent = $G::daily_warning();
        $eq('Eingeschaltet: tägliche Erinnerung, eine Mail je Empfänger', [true, true], [$G::legal_warning()['mail_daily'], $sent >= 1 && count($mails) === $sent]);
        // Rechtstexte vervollständigt → Hinweis weg
        $setpage('impressum', '<p>Angaben gemäß § 5 DDG: Muster Events GmbH, Clubstraße 2, 42103 Wuppertal. E-Mail: info@muster.test. Handelsregister HRB 12345, Registergericht Wuppertal. Geschäftsführer: Max Muster.</p>');
        $full = TIX_Studio_Legal::legal_status()['ok'];
        $eq('Rechtstexte vollständig → Hinweis weg, keine Mail' . ($full ? '' : ' (Testseite hat weitere offene Texte)'), $full ? [false, 0] : [true, true], $full ? [$G::legal_warning()['active'], $G::daily_warning()] : [$G::legal_warning()['active'], true]);
    } finally {
        remove_filter('pre_http_request', $hk, 10); remove_filter('pre_wp_mail', $mh, 1);
        foreach ($pages_bak as $id => [$cc, $st]) wp_update_post(['ID' => $id, 'post_content' => $cc, 'post_status' => $st]);
        foreach ($created as $id) wp_delete_post($id, true);
        foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
        wp_set_current_user(0);
    }
} else {
    $keep = get_option('tix_license_server', null);
    update_option('tix_license_server', '1', false);
    TIX_License_Server::install();
    $lic = TIX_License_Server::create(['customer' => 'GLR Kunde', 'email' => 'glr@example.test', 'valid_until' => gmdate('Y-m-d', strtotime('+60 days'))]);
    $st = $wpdb->prefix . 'tix_license_sites';
    $wpdb->insert($st, ['license_id' => $lic->id, 'host' => 'glr-kunde.test', 'dev' => 0, 'first_seen' => current_time('mysql'), 'last_seen' => current_time('mysql')]);
    try {
        $body = function ($risk) { return wp_json_encode(['action' => 'live', 'host' => 'glr-kunde.test', 'by' => ['name' => 'Kunde', 'email' => 'k@glr.test'], 'checks' => [['key' => 'legal', 'ok' => false, 'state' => 'missing'], ['key' => 'payment', 'ok' => true, 'state' => 'none']], 'ts' => time(), 'nonce' => wp_generate_password(16, false)]
            + ($risk ? ['legal_risk' => ['accepted' => true, 'at' => gmdate('c'), 'by' => 'Kunde', 'missing' => [['key' => 'impressum', 'title' => 'Impressum']]]] : [])); };
        wp_set_current_user(0);
        $raw = $body(false);
        [$s, $d] = $rest('POST', '/license/go-live', [], $raw, ['X-Tix-Signature' => TIX_Go_Live::sign($raw, 'glr-kunde.test', $lic->license_key)]);
        $eq('Plattform: Rechtstexte offen ohne Annahme → 409 mit can_accept', [409, true], [$s, !empty($d['data']['can_accept'])]);
        $raw = $body(true);
        [$s, $d] = $rest('POST', '/license/go-live', [], $raw, ['X-Tix-Signature' => TIX_Go_Live::sign($raw, 'glr-kunde.test', $lic->license_key)]);
        $r = TIX_Go_Live::request_of($lic->id);
        $eq('Plattform: mit bestätigter Annahme → pending, Akte mit legal_risk (fehlende Punkte)', [200, 'pending', true, ['impressum']], [$s, $d['status'] ?? '', !empty($r['legal_risk']['accepted']), array_column((array) ($r['legal_risk']['missing'] ?? []), 'key')]);
        $op = array_values(array_filter(TIX_Go_Live::open_requests(), function ($q) use ($lic) { return intval($q['license_id']) === intval($lic->id); }));
        $eq('Kunden-Dienst sieht legal_risk in „offen“', true, !empty($op[0]['legal_risk']['accepted']));
        $eq('Protokoll go_live_legal_risk', 1, intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tix_license_log WHERE license_id = %d AND action = %s', $lic->id, 'go_live_legal_risk'))));
    } finally {
        $wpdb->delete($st, ['license_id' => $lic->id]); $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $lic->id]); $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic->id]);
        TIX_Object_Data::remove(TIX_Go_Live::OPT_REQ, $lic->id);
        if ($keep === null) delete_option('tix_license_server'); else update_option('tix_license_server', $keep, false);
        wp_set_current_user(0);
    }
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
