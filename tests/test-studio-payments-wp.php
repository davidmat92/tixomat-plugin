<?php
/** Studio: Zahlungsanbieter (TIX_Studio_Payments, 1.38.679, §96). wp eval-file …/tests/test-studio-payments-wp.php (beide Modi). Anbieter werden per pre_http_request nachgestellt. */
if (!defined('ABSPATH') || !class_exists('TIX_Studio_Payments')) { echo "Plugin nötig\n"; return; }
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function zp_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
function zp_rest($m, $r, array $b = []) { $q = new WP_REST_Request($m, '/tixomat/v1' . $r); if ($b) { $q->set_header('content-type', 'application/json'); $q->set_body(wp_json_encode($b)); } $res = rest_do_request($q); $d = $res->get_data(); return [$res->get_status(), $d, is_array($d) ? ($d['code'] ?? '') : '', $res->get_headers()]; }
$multi = TIX_Studio_Payments::locked();
echo $multi ? "── Plattform (gesperrt)\n" : "── Einzel-Seite\n";
$backup = get_option('tix_settings', []);
$state_backup = get_option(TIX_Studio_Payments::STATE, null);
delete_transient('tix_pay_tests');
$sfx = wp_rand(1000, 9999); $users = [];
$mk = function ($login, $role) use (&$users) { $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => "$login@example.test", 'role' => $role]); $users[] = $id; return $id; };
$adm = $mk("zp_adm_$sfx", 'administrator');
$org = $mk("zp_org_$sfx", 'tix_organizer');
$stf = $mk("zp_stf_$sfx", 'tix_staff');

// Anbieter nachstellen
$GLOBALS['zp_calls'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) {
    $GLOBALS['zp_calls'][] = $url;
    $auth = $args['headers']['Authorization'] ?? '';
    $resp = function ($code, $body) { return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null]; };
    if (strpos($url, 'api.mollie.com') !== false) {
        if (strpos($auth, 'live_KAPUTT') !== false) return $resp(401, ['title' => 'Unauthorized']);
        if (strpos($auth, 'test_LEER') !== false) return $resp(200, ['_embedded' => ['methods' => []]]);
        return $resp(200, ['_embedded' => ['methods' => [['id' => 'creditcard', 'description' => 'Kreditkarte', 'image' => ['size2x' => 'https://x/cc.png']], ['id' => 'klarna', 'description' => 'Klarna', 'image' => ['size2x' => '']], ['id' => 'applepay', 'description' => 'Apple Pay', 'image' => []]]]]);
    }
    if (strpos($url, 'api.stripe.com/v1/balance') !== false) return strpos($auth, 'sk_live_GUT') !== false ? $resp(200, ['object' => 'balance']) : $resp(401, ['error' => []]);
    if (strpos($url, 'api.stripe.com') !== false) return $resp(200, ['data' => []]);
    if (strpos($url, 'paypal.com/v1/oauth2/token') !== false) return strpos($auth, base64_encode('PPID:PPSEC')) !== false ? $resp(200, ['access_token' => 'x']) : $resp(401, ['error' => 'invalid_client']);
    return $pre;
}, 10, 3);

if ($multi) {
    wp_set_current_user($adm);
    [$st, $d] = zp_rest('GET', '/studio/payments');
    zp_eq('Plattform: GET liefert nur locked + Grund', [200, true, false], [$st, $d['locked'] ?? null, isset($d['providers'])]);
    [$st, , $code] = zp_rest('POST', '/studio/payments', ['bank' => ['holder' => 'X']]);
    zp_eq('Plattform: Speichern gesperrt', [403, 'tix_payments_locked'], [$st, $code]);
    [$st, , $code] = zp_rest('POST', '/studio/payments/test', ['provider' => 'mollie']);
    zp_eq('Plattform: Test gesperrt', [403, 'tix_payments_locked'], [$st, $code]);
} else {
    // Ausgangslage: alles leer
    $s = is_array($backup) ? $backup : [];
    foreach (['mollie_api_key', 'stripe_secret_key_live', 'stripe_secret_key_test', 'stripe_webhook_secret_live', 'paypal_client_id', 'paypal_secret', 'paypal_webhook_id', 'bank_iban'] as $k) $s[$k] = '';
    unset($s['paypal_enabled']);
    $s['bank_transfer_enabled'] = 0; $s['stripe_enabled'] = 0; $s['mollie_enabled'] = 1; $s['gateway_provider_order'] = 'stripe,mollie,paypal,bank';
    update_option('tix_settings', $s);

    wp_set_current_user(0);
    [$st] = zp_rest('GET', '/studio/payments');
    zp_eq('Ohne Anmeldung → 401', 401, $st);
    wp_set_current_user($stf);
    [$st] = zp_rest('GET', '/studio/payments');
    zp_eq('Mitarbeiter → 403', 403, $st);
    wp_set_current_user($org);
    [$st, $d, , $hd] = zp_rest('GET', '/studio/payments');
    zp_eq('Inhaber (tix_organizer) darf lesen, no-store', [200, false, 'no-store'], [$st, $d['locked'] ?? null, $hd['Cache-Control'] ?? '']);
    zp_eq('Leer: keine Zahlart aktiv, Warnung tix_no_payment', [[], 'tix_no_payment'], [$d['active'], $d['warnings'][0]['code'] ?? '']);
    zp_eq('Reihenfolge aus den Einstellungen', ['stripe', 'mollie', 'paypal', 'bank'], $d['order']);
    zp_eq('Webhook-URLs Stripe/PayPal, Mollie automatisch', [true, true, true], [strpos($d['providers']['stripe']['webhook']['url'], 'action=tix_stripe_webhook') !== false, strpos($d['providers']['paypal']['webhook']['url'], 'action=tix_paypal_webhook') !== false, $d['providers']['mollie']['webhook']['auto']]);

    wp_set_current_user($adm);
    // Mollie
    [$st, , $code, ] = zp_rest('POST', '/studio/payments', ['mollie' => ['api_key' => 'pk_falsch']]);
    $f = zp_rest('POST', '/studio/payments', ['mollie' => ['api_key' => 'pk_falsch']])[1]['data']['field'] ?? '';
    zp_eq('Mollie: falsches Präfix → 400 mit Feld', [400, 'tix_invalid_field', 'mollie.api_key'], [$st, $code, $f]);
    $key = 'live_' . str_repeat('a', 26) . 'Q1w2';
    [$st, $d] = zp_rest('POST', '/studio/payments', ['mollie' => ['api_key' => $key, 'methods_selected' => ['klarna', 'creditcard']]]);
    zp_eq('Mollie gespeichert: bereit, maskiert, live', [200, true, true, '…Q1w2', 'live'], [$st, $d['providers']['mollie']['ready'], $d['providers']['mollie']['key_set'], $d['providers']['mollie']['key_hint'], $d['providers']['mollie']['mode']]);
    zp_eq('Schlüssel nie im Klartext in der Antwort', false, strpos(wp_json_encode($d), $key) !== false);
    zp_eq('Selbsttest nach neuem Schlüssel: last_test ok', [true, 'Verbindung steht · 3 Zahlarten', false], [$d['providers']['mollie']['last_test']['ok'] ?? null, $d['providers']['mollie']['last_test']['message'] ?? '', isset($d['test_failed'])]);
    zp_eq('Auswahl + Reihenfolge der Zahlarten gespeichert', [['klarna', 'creditcard'], 'klarna,creditcard'], [get_option('tix_settings')['mollie_enabled_methods'], get_option('tix_settings')['mollie_methods_order']]);
    zp_eq('Aktiv = mollie', ['mollie'], $d['active']);
    [$st, $d] = zp_rest('POST', '/studio/payments', ['mollie' => ['api_key' => '']]);
    zp_eq('Leerer String lässt den Schlüssel stehen', [true, get_option('tix_settings')['mollie_api_key']], [$d['providers']['mollie']['key_set'], $key]);
    [$st, $d] = zp_rest('POST', '/studio/payments', ['mollie' => ['api_key' => 'live_KAPUTT' . str_repeat('x', 20)]]);
    zp_eq('Ungültiger Schlüssel: gespeichert, test_failed', [200, 'mollie', 'tix_pay_key_invalid', false], [$st, $d['test_failed']['provider'] ?? '', $d['test_failed']['code'] ?? '', $d['providers']['mollie']['last_test']['ok'] ?? null]);
    [$st, $d] = zp_rest('POST', '/studio/payments', ['mollie' => ['api_key' => 'test_LEER' . str_repeat('x', 20)]]);
    zp_eq('Testschlüssel ohne Zahlarten: tix_pay_no_methods, Warnung Testmodus', ['tix_pay_no_methods', 'test', true], [$d['test_failed']['code'] ?? '', $d['providers']['mollie']['mode'], in_array('tix_test_mode', array_column($d['warnings'], 'code'), true)]);
    zp_rest('POST', '/studio/payments', ['mollie' => ['api_key' => $key]]);

    // Stripe
    [$st, , $code] = zp_rest('POST', '/studio/payments', ['stripe' => ['secret_live' => 'sk_test_abc']]);
    zp_eq('Stripe: Testschlüssel im Live-Feld → 400', [400, 'tix_invalid_field'], [$st, $code]);
    [$st, $d] = zp_rest('POST', '/studio/payments', ['stripe' => ['enabled' => true, 'test_mode' => false, 'secret_live' => 'sk_live_GUT123', 'publishable_live' => 'pk_live_abc']]);
    zp_eq('Stripe bereit, Test ok, Warnung Webhook fehlt', [true, true, true], [$d['providers']['stripe']['ready'], $d['providers']['stripe']['last_test']['ok'] ?? null, in_array('tix_webhook_missing', array_column($d['warnings'], 'code'), true)]);
    [$st, $d] = zp_rest('POST', '/studio/payments', ['stripe' => ['webhook_secret_live' => 'whsec_abc']]);
    zp_eq('… mit Webhook-Geheimnis keine Warnung mehr', [false, '…_abc'], [in_array('tix_webhook_missing', array_column($d['warnings'], 'code'), true), $d['providers']['stripe']['webhook_secret_live']['key_hint']]);
    [$st, $d] = zp_rest('POST', '/studio/payments', ['stripe' => ['webhook_secret_live' => null]]);
    zp_eq('null entfernt das Webhook-Geheimnis', [false, ''], [$d['providers']['stripe']['webhook_secret_live']['key_set'], get_option('tix_settings')['stripe_webhook_secret_live']]);

    // PayPal
    [$st, $d] = zp_rest('POST', '/studio/payments', ['paypal' => ['client_id' => 'PPID', 'secret' => 'PPSEC', 'webhook_id' => 'WH1']]);
    zp_eq('PayPal bereit, Test ok', [true, true, '…PSEC'], [$d['providers']['paypal']['ready'], $d['providers']['paypal']['last_test']['ok'] ?? null, $d['providers']['paypal']['secret']['key_hint']]);
    [$st, $d] = zp_rest('POST', '/studio/payments', ['paypal' => ['enabled' => false]]);
    zp_eq('PayPal aus: nicht bereit, Zugangsdaten bleiben', [false, false, 'PPSEC'], [$d['providers']['paypal']['ready'], $d['providers']['paypal']['enabled'], get_option('tix_settings')['paypal_secret']]);
    wp_cache_delete('tix_settings', 'options');
    zp_eq('Kasse sieht PayPal aus (Gateway)', 0, get_option('tix_settings')['paypal_enabled']);
    zp_rest('POST', '/studio/payments', ['paypal' => ['enabled' => true]]);

    // Überweisung
    [$st, , $code] = zp_rest('POST', '/studio/payments', ['bank' => ['enabled' => true, 'iban' => 'DE89 3704 0044 0532 0130 01']]);
    zp_eq('Bank: falsche Prüfziffer → 400', [400, 'tix_invalid_field'], [$st, $code]);
    [$st, , $code] = zp_rest('POST', '/studio/payments', ['bank' => ['enabled' => true]]);
    zp_eq('Bank an ohne IBAN → 400', [400, 'tix_invalid_field'], [$st, $code]);
    [$st, $d] = zp_rest('POST', '/studio/payments', ['bank' => ['enabled' => true, 'iban' => 'de89 3704 0044 0532 0130 00', 'holder' => 'Test GmbH', 'bic' => 'cobadeffxxx']]);
    zp_eq('Bank gespeichert, IBAN gekürzt, BIC groß', [true, 'DE89 •••• 3000', 'COBADEFFXXX', 'DE89370400440532013000'], [$d['providers']['bank']['ready'], $d['providers']['bank']['iban_hint'], $d['providers']['bank']['bic'], get_option('tix_settings')['bank_iban']]);
    zp_eq('IBAN nicht im Klartext', false, strpos(wp_json_encode($d), '0440532013') !== false);

    // Reihenfolge
    [$st, , $code] = zp_rest('POST', '/studio/payments', ['order' => ['mollie', 'klarna']]);
    zp_eq('Unbekannter Anbieter in order → 400', [400, 'tix_invalid_field'], [$st, $code]);
    [$st, $d] = zp_rest('POST', '/studio/payments', ['order' => ['bank', 'paypal']]);
    zp_eq('order wird ergänzt, active folgt der Reihenfolge', [['bank', 'paypal', 'stripe', 'mollie'], ['bank', 'paypal', 'stripe', 'mollie']], [$d['order'], $d['active']]);

    // Test-Route + Grenze
    [$st, , $code] = zp_rest('POST', '/studio/payments/test', ['provider' => 'bank']);
    zp_eq('Test für Überweisung → 400', [400, 'tix_invalid_field'], [$st, $code]);
    delete_transient('tix_pay_tests');
    [$st, $d] = zp_rest('POST', '/studio/payments/test', ['provider' => 'paypal']);
    zp_eq('Test PayPal ok, live', [200, true, 'live'], [$st, $d['ok'] ?? null, $d['mode'] ?? '']);
    for ($i = 1; $i < TIX_Studio_Payments::TEST_LIMIT; $i++) zp_rest('POST', '/studio/payments/test', ['provider' => 'paypal']);
    [$st, , $code] = zp_rest('POST', '/studio/payments/test', ['provider' => 'paypal']);
    zp_eq('Ab dem 11. Test → 429', [429, 'tix_pay_test_limit'], [$st, $code]);
    delete_transient('tix_pay_tests');

    // Inhaber darf speichern, WP-Admin-Sanitize behält paypal_enabled
    wp_set_current_user($org);
    [$st] = zp_rest('POST', '/studio/payments', ['bank' => ['reference' => 'Bestellnummer']]);
    zp_eq('Inhaber darf speichern', 200, $st);
    $s = get_option('tix_settings'); $s['paypal_enabled'] = 0; update_option('tix_settings', $s);
    $er = error_reporting(E_ERROR); $clean = TIX_Settings::sanitize(['paypal_client_id' => 'PPID']); error_reporting($er); // Sanitize erwartet das volle Formular
    zp_eq('WP-Admin-Speichern behält den PayPal-Schalter', 0, $clean['paypal_enabled'] ?? 'fehlt');
}

// Aufräumen
update_option('tix_settings', $backup);
if ($state_backup === null) delete_option(TIX_Studio_Payments::STATE); else update_option(TIX_Studio_Payments::STATE, $state_backup, false);
delete_transient('tix_pay_tests');
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($users as $u) wp_delete_user($u);
wp_set_current_user(0);
echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n";
