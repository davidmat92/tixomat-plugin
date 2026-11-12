<?php
/**
 * Vertragsdaten mit evendis + Seitenname (1.38.764). Einzel-Seite spielt Kunden-Seite UND Lizenz-Server (Anfrage per pre_http_request
 * intern an /license/contract-data weitergereicht).   wp eval-file …/test-contract-data-wp.php
 */
if (!defined('ABSPATH') || !class_exists('TIX_Contract_Data')) { echo "Tixomat ab 1.38.764 nötig\n"; return; }
$h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
if (strpos($h, '.') !== false && stripos($h, 'localhost') === false && substr($h, -5) !== '.test') { echo "Nur auf lokalen Test-Seiten!\n"; return; }
if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) { echo "Nur Einzel-Seite.\n"; return; }
global $wpdb;
$f = 0; $n = 0;
$eq = function ($l, $e, $a) use (&$f, &$n) { $n++; if ($e === $a) { echo "ok    $l\n"; return; } $f++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; };
$rest = function ($m, $r, $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? null) : null, is_array($d) ? ($d['data']['field'] ?? null) : null]; };
$keep = [];
foreach (['tix_license_key', 'tix_license_mode', 'tix_license_server', 'blogname', 'tix_settings', TIX_Contract_Data::OPT_COPY] as $o) $keep[$o] = get_option($o, null);
$host = strtolower(preg_replace('/^www\./', '', $h));
$mails = [];
add_filter('pre_wp_mail', function ($r, $atts) use (&$mails) { $mails[] = $atts; return true; }, 10, 2);
$lic = null;
try {
    update_option('tix_license_server', '1', false);
    TIX_License_Server::install();
    $lic = TIX_License_Server::create(['customer' => 'CD Kunde', 'email' => 'cd@example.test', 'valid_until' => gmdate('Y-m-d', strtotime('+60 days'))]);
    $wpdb->insert($wpdb->prefix . 'tix_license_sites', ['license_id' => $lic->id, 'host' => $host, 'dev' => 0, 'first_seen' => current_time('mysql'), 'last_seen' => current_time('mysql')]);
    update_option('tix_license_server', '0', false);
    update_option('tix_license_key', $lic->license_key, false); update_option('tix_license_mode', 'selfhosted', false);
    delete_option(TIX_Contract_Data::OPT_COPY);
    $s = (array) get_option('tix_settings', []); $s['syndication_site_name'] = (string) get_option('blogname'); update_option('tix_settings', $s);
    // Anfrage der Seite → Lizenz-Server (dieselbe Installation)
    add_filter('pre_http_request', function ($pre, $ar, $url) {
        if ($pre !== false || strpos($url, '/license/contract-data') === false) return $pre;
        update_option('tix_license_server', '1', false);
        $q = new WP_REST_Request('POST', '/tixomat/v1/license/contract-data');
        $q->set_header('content-type', 'application/json'); $q->set_body((string) $ar['body']);
        foreach ((array) $ar['headers'] as $k => $v) $q->set_header($k, $v);
        $res = rest_do_request($q);
        update_option('tix_license_server', '0', false);
        return ['headers' => [], 'body' => wp_json_encode($res->get_data()), 'response' => ['code' => $res->get_status(), 'message' => ''], 'cookies' => [], 'filename' => null];
    }, 10, 3);
    $adm = get_users(['role' => 'administrator', 'number' => 1]); wp_set_current_user($adm[0]->ID);

    [$st, $d] = $rest('GET', '/studio/contract-data');
    $eq('GET leer: verfügbar, keine Daten, unvollständig, Pflichtfelder fehlen', [200, true, null, false, 7], [$st, $d['available'] ?? null, array_key_exists('data', (array) $d) ? $d['data'] : 'x', $d['complete'] ?? null, count($d['missing'] ?? [])]);
    $valid = ['company' => 'CD Events GmbH', 'site_name' => 'CD Partynacht', 'address' => ['street' => 'Markt 1', 'zip' => '58332', 'city' => 'Schwelm'], 'contact_person' => 'Max Muster',
        'email' => 'vertrag@cd.test', 'phone' => '+49 2336 123456', 'vat_id' => 'de 123 456 789'];
    foreach ([['address', ['street' => 'Markt 1', 'zip' => '583', 'city' => 'Schwelm'], 'address.zip'], ['vat_id', '123', 'vat_id'], ['email', 'kein', 'email'], ['phone', 'abc', 'phone'], ['company', 'X', 'company']] as [$k, $v, $fld]) {
        [$st, , $c, $fd] = $rest('POST', '/studio/contract-data', array_merge($valid, [$k => $v]));
        $eq("400 bei $k", [400, 'tix_invalid_field', $fld], [$st, $c, $fd]);
    }
    $eq('nach Fehlern nichts gespeichert', null, TIX_Contract_Data::record($lic->id));
    [$st, $d] = $rest('POST', '/studio/contract-data', $valid);
    $eq('POST: gespeichert, vollständig, USt-IdNr. normalisiert, Land DE', [200, true, 'DE123456789', 'DE', 'site'], [$st, $d['complete'] ?? null, $d['data']['vat_id'] ?? null, $d['data']['address']['country'] ?? null, $d['updated_by']['source'] ?? null]);
    $rec = TIX_Contract_Data::record($lic->id);
    $eq('Quelle an der Lizenz: Firma, Protokoll mit geänderten Feldern', ['CD Events GmbH', true], [$rec['data']['company'] ?? null, in_array('company', (array) (end($rec['log'])['changed'] ?? []), true)]);
    $eq('Seitenname gesetzt (blogname) und Name für geteilte Events folgt', ['CD Partynacht', 'CD Partynacht', 'CD Partynacht'],
        [get_option('blogname'), (string) (get_option('tix_settings')['syndication_site_name'] ?? ''), (string) TIX_Site_Config::payload()['name']]);
    $eq('Hinweis an die Plattform per Mail (Kunde hat geändert)', true, (bool) array_filter($mails, function ($m) { return strpos((string) ($m['subject'] ?? ''), 'Vertragsdaten geändert') !== false; }));
    [$st, $d] = $rest('POST', '/studio/contract-data', ['phone' => '', 'billing_address' => ['company' => 'CD Buchhaltung', 'street' => 'Weg 2', 'zip' => '42103', 'city' => 'Wuppertal']]);
    $eq('Teil-Update: Telefon leer, Rechnungsanschrift gesetzt, Rest bleibt', [200, '', 'Wuppertal', 'CD Events GmbH'], [$st, $d['data']['phone'] ?? null, $d['data']['billing_address']['city'] ?? null, $d['data']['company'] ?? null]);
    [$st, $d] = $rest('POST', '/studio/contract-data', ['billing_address' => null]);
    $eq('Rechnungsanschrift entfernen', [200, null], [$st, array_key_exists('billing_address', (array) ($d['data'] ?? [])) ? $d['data']['billing_address'] : 'x']);
    // Akte ändert → Lizenzprüfung bringt es signiert zurück
    $mails = [];
    update_option('tix_license_server', '1', false);
    $d2 = TIX_Contract_Data::merge($rec['data'], ['site_name' => 'CD Sommerfest', 'contact_person' => 'Erika Muster']);
    TIX_Contract_Data::save($lic->id, $d2, ['name' => 'evendis Admin', 'email' => 'admin@evendis.test'], 'platform');
    $out = TIX_Contract_Data::add_to_check(['signature' => 'x', 'issued_at' => time()], TIX_License_Server::get($lic->id), $host);
    update_option('tix_license_server', '0', false);
    $eq('Akte-Änderung: keine Mail an die Plattform', 0, count(array_filter($mails, function ($m) { return strpos((string) ($m['subject'] ?? ''), 'Vertragsdaten') !== false; })));
    TIX_Contract_Data::from_check($lic->license_key, array_merge($out, ['contract_data_sig' => 'falsch']));
    $eq('falsche Signatur: nichts übernommen', 'CD Partynacht', get_option('blogname'));
    TIX_Contract_Data::from_check($lic->license_key, $out);
    $eq('Lizenzprüfung: Kopie + Seitenname aus der Akte', ['Erika Muster', 'platform', 'CD Sommerfest'], [TIX_Contract_Data::copy()['data']['contact_person'] ?? null, TIX_Contract_Data::copy()['updated_by']['source'] ?? null, get_option('blogname')]);
    // Evendis nicht erreichbar → GET mit Kopie, POST 502 ohne Speichern
    add_filter('pre_http_request', function ($pre, $ar, $url) { return strpos($url, '/license/contract-data') !== false ? new WP_Error('http', 'down') : $pre; }, 5, 3);
    [$st, $d] = $rest('GET', '/studio/contract-data');
    $eq('nicht erreichbar: GET liefert Kopie mit stale', [200, true, 'CD Sommerfest'], [$st, $d['stale'] ?? null, $d['data']['site_name'] ?? null]);
    $before = TIX_Contract_Data::copy()['data']['phone'] ?? null;
    [$st, , $c] = $rest('POST', '/studio/contract-data', ['phone' => '+49 1']);
    $eq('nicht erreichbar: POST 502, nichts geändert', [502, 'tix_contract_unreachable', $before], [$st, $c, TIX_Contract_Data::copy()['data']['phone'] ?? null]);
    wp_set_current_user(0);
    $eq('ohne Anmeldung 401', 401, $rest('GET', '/studio/contract-data')[0]);
} catch (Throwable $e) {
    $f++; echo 'FEHLER ' . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    remove_all_filters('pre_http_request'); remove_all_filters('pre_wp_mail');
    if ($lic) { $wpdb->delete($wpdb->prefix . 'tix_license_sites', ['license_id' => $lic->id]); $wpdb->delete($wpdb->prefix . 'tix_license_log', ['license_id' => $lic->id]); $wpdb->delete($wpdb->prefix . 'tix_licenses', ['id' => $lic->id]); delete_option(TIX_Contract_Data::OPT_LIC . $lic->id); }
    foreach ($keep as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v); }
    wp_set_current_user(0);
}
echo ($f ? "FEHLER: $f" : 'ALLES OK') . " ($n Prüfungen)\n";
