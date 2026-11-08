<?php
/**
 * Webhooks Stripe/PayPal gegen gefälschte Events (Audit 08.11.).
 * Zwei Läufe, weil tix_get_settings() je Prozess zwischenspeichert (vorher die Einstellungen setzen, danach zurück):
 *   wp eval-file …/tests/test-payment-webhooks-wp.php secret     (Stripe-Webhook-Geheimnis + PayPal-Webhook-ID gesetzt)
 *   wp eval-file …/tests/test-payment-webhooks-wp.php nosecret   (beides leer, API-Schlüssel gesetzt)
 * Netz wird per pre_http_request nachgestellt, es geht nichts nach außen.
 */
if (!defined('ABSPATH') || !class_exists('TIX_Gateway_Stripe') || !class_exists('TIX_Gateway_Paypal')) { echo "Plugin nötig\n"; return; }
$mode = $args[0] ?? 'secret';
$GLOBALS['t_fails'] = 0; $GLOBALS['t_count'] = 0;
function wh_eq($l, $e, $a) { $GLOBALS['t_count']++; if ($e === $a) { echo "ok    $l\n"; return; } $GLOBALS['t_fails']++; echo "FAIL  $l: erwartet " . var_export($e, true) . ", ist " . var_export($a, true) . "\n"; }
global $wpdb;
$T = $wpdb->prefix . 'tix_orders';
$orders = [];
$mk = function ($pm, $total) use ($wpdb, $T, &$orders) {
    $wpdb->insert($T, ['order_number' => 'WH-' . wp_rand(10000, 99999), 'status' => 'pending', 'total' => $total, 'subtotal' => $total, 'tax' => 0, 'discount' => 0,
        'payment_method' => $pm, 'payment_method_title' => $pm, 'billing_email' => 'wh@example.test', 'order_key' => wp_generate_password(20, false)]);
    $orders[] = $id = intval($wpdb->insert_id);
    return $id;
};
$status = function ($id) use ($wpdb, $T) { return (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM $T WHERE id = %d", $id)); };

// Netz-Attrappe: $GLOBALS['wh_net'][<Teil der URL>] = [code, body] | 'error'
$GLOBALS['wh_net'] = []; $GLOBALS['wh_calls'] = [];
add_filter('pre_http_request', function ($pre, $a, $url) {
    $GLOBALS['wh_calls'][] = $url;
    foreach ($GLOBALS['wh_net'] as $part => $r) {
        if (strpos($url, $part) === false) continue;
        if ($r === 'error') return new WP_Error('http_request_failed', 'Zeitüberschreitung');
        return ['response' => ['code' => $r[0], 'message' => ''], 'body' => is_string($r[1]) ? $r[1] : wp_json_encode($r[1]), 'headers' => [], 'cookies' => []];
    }
    return new WP_Error('http_request_failed', 'Kein Netz im Test: ' . $url);
}, 10, 3);
$GLOBALS['wh_net']['/v1/oauth2/token'] = [200, ['access_token' => 'tok', 'expires_in' => 3600]];

$s = tix_get_settings();
wh_eq("Lauf $mode: Einstellungen wie erwartet", $mode === 'secret', !empty($s['stripe_webhook_secret_test']) && !empty($s['paypal_webhook_id']));

// ───────── Stripe ─────────
$sess_payload = function ($oid, $sid, $cents, $type = 'checkout.session.completed') {
    return wp_json_encode(['type' => $type, 'data' => ['object' => ['id' => $sid, 'payment_status' => 'paid', 'amount_total' => $cents, 'currency' => 'eur', 'metadata' => ['tix_order_id' => $oid]]]]);
};
$o1 = $mk('stripe', 25.00); $sid1 = 'cs_test_' . wp_rand(100000, 999999);
update_option('_tix_stripe_session_' . $o1, $sid1, false);
$p1 = $sess_payload($o1, $sid1, 2500);

if ($mode === 'secret') {
    $sec = TIX_Gateway_Stripe::get_webhook_secret();
    $r = TIX_Gateway_Stripe::process_webhook($p1, '');
    wh_eq('Stripe: Geheimnis gesetzt, Kopf fehlt → 400, nicht bezahlt', [400, 'pending'], [$r[0], $status($o1)]);
    $r = TIX_Gateway_Stripe::process_webhook($p1, 't=' . time() . ',v1=' . str_repeat('0', 64));
    wh_eq('Stripe: falsche Signatur → 400', [400, 'pending'], [$r[0], $status($o1)]);
    $old = time() - 600;
    $r = TIX_Gateway_Stripe::process_webhook($p1, "t=$old,v1=" . hash_hmac('sha256', "$old.$p1", $sec));
    wh_eq('Stripe: alte Signatur (Replay) → 400', [400, 'pending'], [$r[0], $status($o1)]);
    $t = time();
    $r = TIX_Gateway_Stripe::process_webhook($p1, "t=$t,v1=" . hash_hmac('sha256', "$t.$p1", $sec));
    wh_eq('Stripe: gültige Signatur → bezahlt', [200, 'completed'], [$r[0], $status($o1)]);
} else {
    $GLOBALS['wh_net'] = ['/v1/oauth2/token' => [200, ['access_token' => 'tok']]];
    $GLOBALS['wh_net']['/checkout/sessions/' . $sid1] = [200, ['id' => $sid1, 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'eur', 'metadata' => ['tix_order_id' => $o1]]];
    $r = TIX_Gateway_Stripe::process_webhook($p1, '');
    wh_eq('Stripe ohne Geheimnis: Betrag bei Stripe passt nicht → 400', [400, 'pending'], [$r[0], $status($o1)]);
    $GLOBALS['wh_net']['/checkout/sessions/' . $sid1] = [200, ['id' => $sid1, 'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 2500, 'currency' => 'eur', 'metadata' => ['tix_order_id' => $o1]]];
    $r = TIX_Gateway_Stripe::process_webhook($p1, '');
    wh_eq('Stripe ohne Geheimnis: Event sagt „paid“, Stripe sagt „unpaid“ → nicht bezahlt', 'completed' !== $status($o1), true);
    wh_eq('… Status kommt von Stripe (processing statt completed)', 'processing', $status($o1));
    $o2 = $mk('stripe', 10.00);
    $r = TIX_Gateway_Stripe::process_webhook($sess_payload($o2, 'cs_test_fremd', 1000), '');
    wh_eq('Stripe ohne Geheimnis: fremde/unbekannte Session → 400', [400, 'pending'], [$r[0], $status($o2)]);
    $sid2 = 'cs_test_' . wp_rand(100000, 999999); update_option('_tix_stripe_session_' . $o2, $sid2, false);
    $GLOBALS['wh_net']['/checkout/sessions/' . $sid2] = 'error';
    $r = TIX_Gateway_Stripe::process_webhook($sess_payload($o2, $sid2, 1000), '');
    wh_eq('Stripe ohne Geheimnis: Stripe nicht erreichbar → 503', [503, 'pending'], [$r[0], $status($o2)]);
    $o3 = $mk('mollie', 10.00); $sid3 = 'cs_test_' . wp_rand(100000, 999999); update_option('_tix_stripe_session_' . $o3, $sid3, false);
    $GLOBALS['wh_net']['/checkout/sessions/' . $sid3] = [200, ['id' => $sid3, 'payment_status' => 'paid', 'amount_total' => 1000, 'currency' => 'eur', 'metadata' => ['tix_order_id' => $o3]]];
    $r = TIX_Gateway_Stripe::process_webhook($sess_payload($o3, $sid3, 1000), '');
    wh_eq('Stripe ohne Geheimnis: Bestellung ist nicht Stripe → 400', [400, 'pending'], [$r[0], $status($o3)]);
    $GLOBALS['wh_net']['/checkout/sessions/' . $sid2] = [200, ['id' => $sid2, 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 1000, 'currency' => 'eur', 'metadata' => ['tix_order_id' => $o2]]];
    $r = TIX_Gateway_Stripe::process_webhook($sess_payload($o2, $sid2, 1000), '');
    wh_eq('Stripe ohne Geheimnis: echte, bezahlte Session → bezahlt', [200, 'completed'], [$r[0], $status($o2)]);
}

// ───────── PayPal ─────────
$cap_payload = function ($cap_id, $value, $ref = '') {
    return wp_json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => $cap_id, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => $value],
        'purchase_units' => $ref ? [['reference_id' => $ref]] : [], 'supplementary_data' => ['related_ids' => ['order_id' => 'PPORDER' . substr($cap_id, 3)]]]]);
};
$q1 = $mk('paypal', 30.00); $cap1 = 'CAP' . wp_rand(100000, 999999);
update_option('_tix_paypal_order_' . $q1, 'PPORDER' . substr($cap1, 3), false);
$hdr = ['auth_algo' => 'SHA256withRSA', 'cert_url' => 'https://api.paypal.com/cert', 'transmission_id' => 'x', 'transmission_sig' => 'y', 'transmission_time' => gmdate('c')];

// Altes Loch: reference_id im gefälschten Event → nie mehr bezahlt ohne Prüfung
$q0 = $mk('paypal', 30.00);
if ($mode === 'secret') {
    $GLOBALS['wh_net']['verify-webhook-signature'] = [200, ['verification_status' => 'FAILURE']];
    $r = TIX_Gateway_Paypal::process_webhook($cap_payload('CAP999999', '30.00', 'tix_' . $q0), $hdr);
    wh_eq('PayPal: Signatur abgelehnt → 403, nicht bezahlt', [403, 'pending'], [$r[0], $status($q0)]);
    $GLOBALS['wh_net']['verify-webhook-signature'] = 'error';
    $GLOBALS['wh_net']['/v2/payments/captures/'] = 'error';
    $r = TIX_Gateway_Paypal::process_webhook($cap_payload($cap1, '30.00'), $hdr);
    wh_eq('PayPal: Prüfung scheitert (Timeout) und API nicht erreichbar → 503', [503, 'pending'], [$r[0], $status($q1)]);
    $GLOBALS['wh_net']['verify-webhook-signature'] = [200, ['verification_status' => 'SUCCESS']];
    $r = TIX_Gateway_Paypal::process_webhook($cap_payload($cap1, '1.00'), $hdr);
    wh_eq('PayPal: geprüft, aber Betrag falsch → 400', [400, 'pending'], [$r[0], $status($q1)]);
    $r = TIX_Gateway_Paypal::process_webhook($cap_payload($cap1, '30.00'), $hdr);
    wh_eq('PayPal: geprüft, Betrag stimmt → bezahlt', [200, 'completed'], [$r[0], $status($q1)]);
} else {
    $r = TIX_Gateway_Paypal::process_webhook($cap_payload('CAP999999', '30.00', 'tix_' . $q0), $hdr);
    wh_eq('PayPal ohne Webhook-ID: gefälschtes Event mit reference_id → nicht bezahlt', 'pending', $status($q0));
    $GLOBALS['wh_net']['/v2/payments/captures/' . $cap1] = [200, ['id' => $cap1, 'status' => 'PENDING', 'amount' => ['currency_code' => 'EUR', 'value' => '30.00'], 'supplementary_data' => ['related_ids' => ['order_id' => 'PPORDER' . substr($cap1, 3)]]]];
    $r = TIX_Gateway_Paypal::process_webhook($cap_payload($cap1, '30.00'), $hdr);
    wh_eq('PayPal ohne Webhook-ID: Capture bei PayPal nicht abgeschlossen → nicht bezahlt', [400, 'pending'], [$r[0], $status($q1)]);
    $q2 = $mk('stripe', 30.00); update_option('_tix_paypal_order_' . $q2, 'PPORDER222222', false);
    $GLOBALS['wh_net']['/v2/payments/captures/CAP222222'] = [200, ['id' => 'CAP222222', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '30.00'], 'supplementary_data' => ['related_ids' => ['order_id' => 'PPORDER222222']]]];
    $r = TIX_Gateway_Paypal::process_webhook($cap_payload('CAP222222', '30.00'), $hdr);
    wh_eq('PayPal: Bestellung ist nicht PayPal → nicht bezahlt', 'pending', $status($q2));
    $GLOBALS['wh_net']['/v2/payments/captures/' . $cap1] = [200, ['id' => $cap1, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '30.00'], 'supplementary_data' => ['related_ids' => ['order_id' => 'PPORDER' . substr($cap1, 3)]]]];
    $r = TIX_Gateway_Paypal::process_webhook($cap_payload($cap1, '5.00'), $hdr);
    wh_eq('PayPal ohne Webhook-ID: Betrag aus der API, nicht aus dem Event → bezahlt', [200, 'completed'], [$r[0], $status($q1)]);
    delete_option('_tix_paypal_order_' . $q2);
}
// APPROVED: Capture nur für die eigene gespeicherte PayPal-Bestellung
$q3 = $mk('paypal', 12.00);
$GLOBALS['wh_calls'] = [];
$r = TIX_Gateway_Paypal::process_webhook(wp_json_encode(['event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'UNBEKANNT1', 'purchase_units' => [['reference_id' => 'tix_' . $q3]]]]), $hdr);
wh_eq('PayPal APPROVED mit fremder PayPal-ID: kein Capture-Aufruf, nicht bezahlt', [false, 'pending'],
    [(bool) array_filter($GLOBALS['wh_calls'], function ($u) { return (bool) preg_match('#/checkout/orders/[^/]+/capture$#', $u); }), $status($q3)]);

// Einstellungen: WP-Admin-Speichern behält die Webhook-ID
if ($mode === 'secret' && class_exists('TIX_Settings')) {
    $old = error_reporting(E_ERROR);
    $clean = TIX_Settings::sanitize(['paypal_client_id' => 'x']);
    error_reporting($old);
    wh_eq('WP-Admin speichern: paypal_webhook_id bleibt', $s['paypal_webhook_id'], $clean['paypal_webhook_id'] ?? null);
}

foreach ($orders as $id) { $wpdb->delete($T, ['id' => $id]); foreach (['_tix_stripe_session_', '_tix_stripe_pi_', '_tix_paypal_order_', '_tix_paypal_capture_', '_tix_stripe_method_'] as $p) delete_option($p . $id); }
echo "\n" . ($GLOBALS['t_count'] - $GLOBALS['t_fails']) . '/' . $GLOBALS['t_count'] . " ok\n";
