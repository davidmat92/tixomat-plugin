<?php
/** Live schalten durch den Kunden (1.38.742). wp eval-file …/test-go-live-wp.php (Kunden-Seite und Plattform) */
if (!defined('ABSPATH') || !class_exists('TIX_Go_Live')) { echo "Tixomat ab 1.38.742 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = [], $raw = null, $hd = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); foreach ($hd as $k => $v) $q->set_header($k, $v); if ($raw !== null) { $q->set_header('content-type', 'application/json'); $q->set_body($raw); } elseif ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = json_decode(wp_json_encode(rest_get_server()->response_to_data($res, false)), true); return [$res->get_status(), is_array($d) ? $d : [], is_array($d) ? ($d['code'] ?? '') : '']; };
$multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
$a = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($a[0]->ID);
global $wpdb;
if (!$multi) {
    $keep = [];
    foreach (['tix_license_key', 'tix_license_mode', 'tix_license_server', TIX_Go_Live::OPT_STATE, TIX_Studio_Payments::OPT_NONE, 'tix_settings'] as $o) $keep[$o] = get_option($o, null);
    $pages_bak = []; $created = [];
    $texts = [
        'impressum' => '<p>Angaben gemäß § 5 DDG: Muster Events GmbH, Clubstraße 2, 42103 Wuppertal. E-Mail: info@muster.test. Handelsregister HRB 12345, Registergericht Wuppertal. Geschäftsführer: Max Muster.</p>',
        'datenschutz' => '<p>Verantwortlich: Muster Events GmbH, Clubstraße 2, 42103 Wuppertal, info@muster.test. Rechtsgrundlage Art. 6 DSGVO. Speicherdauer: Daten werden gelöscht. Sie haben ein Recht auf Auskunft, Widerspruch und Beschwerde. Newsletter, KI-Assistent (Anthropic), Degaso, Awareness, Guthaben.</p>',
        'agb' => '<p>Geltungsbereich und Vertragsschluss. Preise und Zahlung. Guthaben. Gutscheine.</p>',
        'widerruf' => '<p>Kein Widerrufsrecht bei Tickets für Freizeit-Events (§ 312g BGB). Muster-Widerrufsformular.</p>',
    ];
    $setpage = function ($k, $html) use (&$pages_bak, &$created) {
        $p = TIX_Studio_Legal::page($k);
        if ($p) { if (!isset($pages_bak[$p->ID])) $pages_bak[$p->ID] = [$p->post_content, $p->post_status]; wp_update_post(['ID' => $p->ID, 'post_content' => $html, 'post_status' => 'publish']); }
        else { $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $k, 'post_name' => TIX_Studio_Legal::DOCS[$k][1][0], 'post_content' => $html]); $created[] = $id; }
    };
    try {
        update_option('tix_license_key', 'TIX-GOLIVE-' . strtoupper(wp_generate_password(8, false)), false); update_option('tix_license_mode', 'selfhosted', false); update_option('tix_license_server', '0', false);
        delete_option(TIX_Go_Live::OPT_STATE);
        foreach ($texts as $k => $t) $setpage($k, $t);
        $setpage('impressum', str_replace('Muster Events GmbH', '[PLATZHALTER: Firma] GmbH', $texts['impressum']));
        $ls = TIX_Studio_Legal::legal_status();
        $imp = array_column($ls['items'], null, 'key')['impressum'];
        $eq('Platzhalter erkannt: Impressum nicht ok', [false, false, 1], [$ls['ok'], $imp['ok'], $imp['placeholders']]);
        [$s, $d] = $rest('GET', '/studio/readiness');
        $it = array_column((array) ($d['items'] ?? []), null, 'key');
        $eq('Readiness: Rechtstexte offen, nicht übergehbar', [200, false, false, false], [$s, $d['ready'] ?? null, $it['legal']['ok'] ?? null, $it['legal']['overridable'] ?? null]);
        [$s, , $c] = $rest('POST', '/studio/go-live', ['action' => 'live']);
        $eq('Live schalten mit Platzhalter: 409 tix_legal_incomplete', [409, 'tix_legal_incomplete'], [$s, $c]);
        $setpage('impressum', $texts['impressum']);
        $eq('Rechtstexte vollständig', true, TIX_Studio_Legal::legal_status()['ok']);
        // Zahlung: kein Anbieter, nicht „keine“
        $s0 = (array) get_option('tix_settings', []); foreach (['mollie_api_key', 'stripe_secret_key', 'stripe_live_secret_key', 'paypal_client_id', 'bank_iban'] as $k) $s0[$k] = ''; update_option('tix_settings', $s0);
        delete_option(TIX_Studio_Payments::OPT_NONE);
        $st = TIX_Studio_Payments::state_of();
        if ($st === 'missing') {
            [$s, , $c] = $rest('POST', '/studio/go-live', ['action' => 'live']);
            $eq('Zahlung fehlt: 409 tix_not_ready', [409, 'tix_not_ready'], [$s, $c]);
        } else echo "      (Zahlung hier schon $st – Prüfung „fehlt“ übersprungen)\n";
        // 1.38.744: nur vermittelte Co-Events = noch nicht entschieden (nicht erfüllt)
        $brk = wp_insert_post(['post_type' => 'event', 'post_status' => 'publish', 'post_title' => 'GL Vermittelt', 'meta_input' => ['_tix_platform_co' => 99001, TIX_Co_Sale::M_SALE => '1', '_tix_date_start' => wp_date('Y-m-d', time() + 30 * DAY_IN_SECONDS)]]);
        if (!TIX_Co_Sale::payments_needed() && TIX_Studio_Payments::state_of() !== 'ok') {
            $pi = array_values(array_filter(TIX_Go_Live::checklist()['items'], function ($i) { return $i['key'] === 'payment'; }))[0];
            $rd = TIX_Owner_Access::readiness();
            $pp = $rest('GET', '/studio/payments')[1];
            $eq('Nur Vermittlung: undecided, nicht erfüllt, übergehbar, Hinweis, payments_needed als Info', ['undecided', false, true, true, false],
                [$pi['state'], $pi['ok'], $pi['overridable'], strpos((string) $pi['hint'], 'andere Veranstalter') !== false, $pi['payments_needed']]);
            $eq('Startklar: payment false, payment_mode undecided; /studio/payments state + hint', [false, 'undecided', 'undecided', true], [$rd['payment'], $rd['payment_mode'], $pp['state'] ?? '', ($pp['hint'] ?? '') !== '']);
            [$s, , $c] = $rest('POST', '/studio/go-live', ['action' => 'live']);
            $eq('Live schalten bei undecided: 409 (Rechtstexte bzw. nicht bereit)', true, $s === 409 && in_array($c, ['tix_not_ready', 'tix_legal_incomplete'], true));
        } else echo "      (Testseite hat eigene Online-Tickets bzw. Anbieter – undecided übersprungen)\n";
        wp_delete_post($brk, true);
        [$s, $d] = $rest('POST', '/studio/payments', ['none' => true]);
        $eq('„Keine Zahlungen anbieten“ gespeichert, Zustand none', [200, true], [$s, in_array(TIX_Studio_Payments::state_of(), ['none', 'ok'], true)]);
        $eq('Website: keine Online-Kasse bei „keine Zahlungen“ ohne Anbieter', TIX_Studio_Payments::state_of() === 'none' ? false : true, (bool) (TIX_Site_Config::payload()['features']['checkout'] ?? true));
        $eq('Website: vermittelte Co-Events bleiben kaufbar (checkout_brokered, 1.38.762)', true, (bool) (TIX_Site_Config::payload()['features']['checkout_brokered'] ?? false));
        $rd = TIX_Owner_Access::readiness();
        $eq('Startklar an die Plattform: payment true, payment_mode, legal_detail', [true, true, 4], [$rd['payment'], in_array($rd['payment_mode'], ['none', 'ok'], true), count((array) $rd['legal_detail'])]);
        // Anfrage
        $GLOBALS['gl_calls'] = [];
        add_filter('pre_http_request', function ($pre, $ar, $url) { if (strpos($url, '/license/go-live') === false) return $pre; $GLOBALS['gl_calls'][] = ['url' => $url, 'body' => $ar['body'], 'headers' => $ar['headers']]; return ['headers' => [], 'body' => '{"ok":true,"status":"pending"}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null]; }, 10, 3);
        $c0 = TIX_Go_Live::checklist();
        if (!$c0['ready']) { echo '      (nicht bereit: ' . implode(', ', array_column(array_filter($c0['items'], function ($i) { return $i['required'] && !$i['ok']; }), 'key')) . ' – Testumgebung ohne Domain/Inhaber, Anfrage wird trotzdem geprüft)' . "\n"; }
        [$s, $d, $c] = $rest('POST', '/studio/go-live', ['action' => 'live']);
        if ($c0['ready']) {
            $call = $GLOBALS['gl_calls'][0] ?? [];
            $eq('Anfrage signiert an die Plattform, Zustand requested', [200, 'requested', true], [$s, $d['state']['status'] ?? '', hash_equals(TIX_Go_Live::sign((string) ($call['body'] ?? ''), parse_url(home_url(), PHP_URL_HOST), get_option('tix_license_key')), (string) ($call['headers']['X-Tix-Signature'] ?? ''))]);
        } else $eq('nicht bereit → 409 tix_not_ready', [409, 'tix_not_ready'], [$s, $c]);
        // Ergebnis über die Lizenzprüfung (signiert)
        update_option(TIX_Go_Live::OPT_STATE, ['status' => 'requested', 'action' => 'live', 'nonce' => 'n123'], false);
        $issued = time(); $hostx = strtolower(preg_replace('/^www\./', '', parse_url(home_url(), PHP_URL_HOST)));
        $sig = hash_hmac('sha256', implode('|', ['tix-golive-res-v1', $hostx, 'n123', 'done', 'live', $issued]), strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', get_option('tix_license_key'))));
        TIX_Go_Live::from_check(get_option('tix_license_key'), ['go_live' => ['nonce' => 'n123', 'status' => 'done', 'action' => 'live'], 'go_live_sig' => 'falsch', 'issued_at' => $issued]);
        $eq('falsche Signatur: nichts übernommen', 'requested', TIX_Go_Live::state()['status']);
        TIX_Go_Live::from_check(get_option('tix_license_key'), ['go_live' => ['nonce' => 'n123', 'status' => 'done', 'action' => 'live'], 'go_live_sig' => $sig, 'issued_at' => $issued]);
        $eq('Ergebnis „done“ → live', 'live', TIX_Go_Live::state()['status']);
        $eq('Ungültige Aktion 400', 400, $rest('POST', '/studio/go-live', ['action' => 'x'])[0]);
        wp_set_current_user(0);
        $eq('Ohne Anmeldung 401', 401, $rest('GET', '/studio/readiness')[0]);
    } finally {
        foreach ($pages_bak as $id => [$c, $st]) wp_update_post(['ID' => $id, 'post_content' => $c, 'post_status' => $st]);
        foreach ($created as $id) wp_delete_post($id, true);
        foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v, false); }
        wp_set_current_user(0);
    }
} else {
    $keep = get_option('tix_license_server', null);
    update_option('tix_license_server', '1', false);
    TIX_License_Server::install();
    $lic = TIX_License_Server::create(['customer' => 'GL Kunde', 'email' => 'gl@example.test', 'valid_until' => gmdate('Y-m-d', strtotime('+60 days'))]);
    $st = $wpdb->prefix . 'tix_license_sites';
    $wpdb->insert($st, ['license_id' => $lic->id, 'host' => 'gl-kunde.test', 'dev' => 0, 'first_seen' => current_time('mysql'), 'last_seen' => current_time('mysql')]);
    TIX_Platform_Customers::flush();
    try {
        $body = function ($action, $legal = true, $nonce = null) { return wp_json_encode(['action' => $action, 'host' => 'gl-kunde.test', 'by' => ['name' => 'Kunde', 'email' => 'k@gl.test'], 'checks' => [['key' => 'legal', 'ok' => $legal, 'state' => $legal ? 'ok' : 'missing'], ['key' => 'payment', 'ok' => true, 'state' => 'none']], 'ts' => time(), 'nonce' => $nonce ?: wp_generate_password(16, false)]); };
        $raw = $body('live');
        wp_set_current_user(0);
        $eq('Annahme: falsche Signatur 401', 401, $rest('POST', '/license/go-live', [], $raw, ['X-Tix-Signature' => 'x'])[0]);
        [$s, $d] = $rest('POST', '/license/go-live', [], $raw, ['X-Tix-Signature' => TIX_Go_Live::sign($raw, 'gl-kunde.test', $lic->license_key)]);
        $eq('Annahme: pending', [200, 'pending'], [$s, $d['status'] ?? '']);
        $eq('dieselbe Anfrage zweimal 401', 401, $rest('POST', '/license/go-live', [], $raw, ['X-Tix-Signature' => TIX_Go_Live::sign($raw, 'gl-kunde.test', $lic->license_key)])[0]);
        $raw2 = $body('live', false);
        $eq('Rechtstexte offen: 409 auch auf der Plattform', 409, $rest('POST', '/license/go-live', [], $raw2, ['X-Tix-Signature' => TIX_Go_Live::sign($raw2, 'gl-kunde.test', $lic->license_key)])[0]);
        wp_set_current_user($a[0]->ID);
        [$s, $d] = $rest('GET', "/platform/customers/l-{$lic->id}/go-live");
        $eq('Akte: Anfrage sichtbar', [200, 'pending', 'live'], [$s, $d['request']['status'] ?? '', $d['request']['action'] ?? '']);
        add_filter('pre_http_request', function ($pre, $ar, $url) { return strpos($url, 'gl-kunde.test') !== false ? ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null] : $pre; }, 10, 3);
        [$s, $d] = $rest('POST', "/platform/customers/l-{$lic->id}/go-live", ['status' => 'done']);
        $eq('Ergebnis eintragen: done', [200, 'done'], [$s, $d['request']['status'] ?? '']);
        $out = TIX_Go_Live::add_to_check(['issued_at' => 1700000000], $lic, 'gl-kunde.test');
        $exp = hash_hmac('sha256', implode('|', ['tix-golive-res-v1', 'gl-kunde.test', $out['go_live']['nonce'] ?? '', 'done', 'live', 1700000000]), TIX_License_Server::normalize_key($lic->license_key));
        $eq('Lizenzprüfung: Ergebnis signiert mitgeschickt', [true, $exp], [isset($out['go_live']), $out['go_live_sig'] ?? '']);
        $eq('Ungültiger Status 400', 400, $rest('POST', "/platform/customers/l-{$lic->id}/go-live", ['status' => 'x'])[0]);
    } finally {
        $wpdb->delete($st, ['license_id' => $lic->id]); $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $lic->id]); $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic->id]);
        delete_option(TIX_Go_Live::OPT_REQ . $lic->id);
        if ($keep === null) delete_option('tix_license_server'); else update_option('tix_license_server', $keep, false);
        TIX_Platform_Customers::flush();
        wp_set_current_user(0);
    }
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
