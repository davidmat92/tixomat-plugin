<?php
/**
 * TIX Gateway: PayPal
 * PayPal Orders v2 API — Redirect-basiert.
 */
if (!defined('ABSPATH')) exit;

class TIX_Gateway_PayPal {

    public static function get_id()    { return 'paypal'; }
    public static function get_title() { return 'PayPal'; }
    public static function get_icon()  { return 'https://www.paypalobjects.com/webstatic/mktg/Logo/pp-logo-100px.png'; }

    public static function is_available() {
        return !empty(self::get_client_id()) && !empty(self::get_secret());
    }

    private static function get_client_id() { return trim(tix_get_settings('paypal_client_id') ?? ''); }
    private static function get_secret()    { return trim(tix_get_settings('paypal_secret') ?? ''); }
    private static function is_sandbox()    { return !empty(tix_get_settings('paypal_sandbox')); }

    private static function api_url() {
        return self::is_sandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    public static function init() {
        add_action('wp_ajax_tix_paypal_webhook',        [__CLASS__, 'handle_webhook']);
        add_action('wp_ajax_nopriv_tix_paypal_webhook', [__CLASS__, 'handle_webhook']);
        add_action('template_redirect',                 [__CLASS__, 'handle_return']);
    }

    /**
     * Access Token holen (Client Credentials)
     */
    private static function get_access_token() {
        $response = wp_remote_post(self::api_url() . '/v1/oauth2/token', [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(self::get_client_id() . ':' . self::get_secret()),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => 'grant_type=client_credentials',
        ]);

        if (is_wp_error($response)) return null;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['access_token'] ?? null;
    }

    /**
     * PayPal Order erstellen → Redirect-URL
     */
    public static function process($order_id) {
        $token = self::get_access_token();
        if (!$token) return ['error' => 'PayPal-Authentifizierung fehlgeschlagen.'];

        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        if (!$order) return ['error' => 'Bestellung nicht gefunden.'];

        $amount = number_format(floatval($order->total), 2, '.', '');

        $return_url = add_query_arg([
            'tix_payment_return' => 1,
            'gateway'            => 'paypal',
            'order_id'           => $order_id,
            'order_key'          => $order->order_key,
        ], home_url('/'));

        $cancel_url = add_query_arg([
            'tix_payment_cancel' => 1,
            'order_id'           => $order_id,
            'order_key'          => $order->order_key,
        ], home_url('/'));

        $response = wp_remote_post(self::api_url() . '/v2/checkout/orders', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => 'tix_' . $order_id,
                    'description'  => 'Bestellung ' . $order->order_number,
                    'amount' => [
                        'currency_code' => 'EUR',
                        'value'         => $amount,
                    ],
                ]],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'return_url'          => $return_url,
                            'cancel_url'          => $cancel_url,
                            'user_action'         => 'PAY_NOW',
                            'brand_name'          => get_bloginfo('name'),
                            'landing_page'        => 'LOGIN',
                            'shipping_preference' => 'NO_SHIPPING',
                        ],
                    ],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['error' => 'PayPal-Verbindungsfehler: ' . $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $pp_order_id = $body['id'] ?? '';

        if (!$pp_order_id) {
            $msg = $body['details'][0]['description'] ?? $body['message'] ?? 'Unbekannter Fehler';
            return ['error' => 'PayPal: ' . $msg];
        }

        // PayPal Order ID speichern
        update_option('_tix_paypal_order_' . $order_id, $pp_order_id, false);
        $wpdb->update(
            $wpdb->prefix . 'tix_orders',
            ['payment_method' => 'paypal', 'payment_method_title' => 'PayPal'],
            ['id' => $order_id]
        );

        TIX_Native_Checkout::update_order_status($order_id, 'pending', 'paypal');

        // Redirect-Link finden
        $approve_url = '';
        foreach (($body['links'] ?? []) as $link) {
            if ($link['rel'] === 'payer-action') {
                $approve_url = $link['href'];
                break;
            }
        }

        if (!$approve_url) {
            return ['error' => 'Keine PayPal-Zahlungsseite erhalten.'];
        }

        return ['redirect' => $approve_url];
    }

    /**
     * PayPal Webhook: Zahlungsstatus-Update
     *
     * Note: Webhooks must be configured in the PayPal Developer Dashboard.
     * Set the webhook URL to: admin-ajax.php?action=tix_paypal_webhook
     * Subscribe to events: CHECKOUT.ORDER.APPROVED, PAYMENT.CAPTURE.COMPLETED
     */
    public static function handle_webhook() {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data) || empty($data['event_type'])) {
            wp_die('Invalid payload', '', 400);
        }

        // Verify webhook signature
        $webhook_id = tix_get_settings('paypal_webhook_id') ?? '';
        if ($webhook_id) {
            $verify_response = wp_remote_post(self::api_url() . '/v1/notifications/verify-webhook-signature', [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . (self::get_access_token() ?: ''),
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode([
                    'auth_algo'         => sanitize_text_field($_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? ''),
                    'cert_url'          => esc_url_raw($_SERVER['HTTP_PAYPAL_CERT_URL'] ?? ''),
                    'transmission_id'   => sanitize_text_field($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? ''),
                    'transmission_sig'  => sanitize_text_field($_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? ''),
                    'transmission_time' => sanitize_text_field($_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? ''),
                    'webhook_id'        => $webhook_id,
                    'webhook_event'     => $data,
                ]),
            ]);

            if (!is_wp_error($verify_response)) {
                $verify_body = json_decode(wp_remote_retrieve_body($verify_response), true);
                if (($verify_body['verification_status'] ?? '') !== 'SUCCESS') {
                    error_log('[TIX PayPal Webhook] Signature verification failed');
                    wp_die('Signature verification failed', '', 403);
                }
            }
        }

        $event_type = sanitize_text_field($data['event_type']);
        error_log('[TIX PayPal Webhook] Event: ' . $event_type);

        $resource = $data['resource'] ?? [];
        $purchase_units = $resource['purchase_units'] ?? [];
        $reference_id = $purchase_units[0]['reference_id'] ?? '';

        // Extract order_id from reference_id format "tix_123"
        if (!preg_match('/^tix_(\d+)$/', $reference_id, $matches)) {
            // For PAYMENT.CAPTURE.COMPLETED, reference_id may be in supplementary_data
            $reference_id = $resource['supplementary_data']['related_ids']['order_id'] ?? '';
            // Try to find our order via the PayPal order ID
            if (!empty($reference_id)) {
                error_log('[TIX PayPal Webhook] Capture event with PayPal order ID: ' . $reference_id);
            }
            wp_die('OK', '', 200);
        }

        $order_id = intval($matches[1]);

        // Verify order exists in DB
        global $wpdb;
        $order_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        if (!$order_exists) {
            error_log('[TIX PayPal Webhook] Order not found: ' . $order_id);
            wp_die('Order not found', '', 200);
        }

        if ($event_type === 'CHECKOUT.ORDER.APPROVED') {
            // Capture the payment if not yet captured
            $pp_order_id = get_option('_tix_paypal_order_' . $order_id);
            if ($pp_order_id) {
                error_log('[TIX PayPal Webhook] Capturing order ' . $order_id . ' (PayPal: ' . $pp_order_id . ')');
                $captured = self::capture($pp_order_id, $order_id);
                if ($captured) {
                    delete_option('_tix_paypal_order_' . $order_id);
                }
            }
        } elseif ($event_type === 'PAYMENT.CAPTURE.COMPLETED') {
            // Idempotency: skip if already completed
            $old_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
            ));
            if ($old_status !== 'completed') {
                error_log('[TIX PayPal Webhook] Completing order ' . $order_id);
                TIX_Native_Checkout::update_order_status($order_id, 'completed', 'paypal');
            }
        }

        wp_die('OK', '', 200);
    }

    /**
     * Return von PayPal → Capture + Thank-You
     */
    public static function handle_return() {
        // Handle cancellation
        if (!empty($_GET['tix_payment_cancel'])) {
            $order_id = intval($_GET['order_id'] ?? 0);
            $order_key = sanitize_text_field($_GET['order_key'] ?? '');
            if ($order_id && $order_key) {
                // Verify order exists and key matches
                global $wpdb;
                $valid = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}tix_orders WHERE id = %d AND order_key = %s",
                    $order_id, $order_key
                ));
                if ($valid) {
                    TIX_Native_Checkout::update_order_status($order_id, 'cancelled', 'paypal');
                }
            }
            $checkout_url = class_exists('TIX_Native_Checkout') ? TIX_Native_Checkout::checkout_url() : home_url('/checkout/');
            wp_safe_redirect(add_query_arg('tix_cancelled', '1', $checkout_url));
            exit;
        }

        if (empty($_GET['tix_payment_return']) || ($_GET['gateway'] ?? '') !== 'paypal') return;

        $order_id  = intval($_GET['order_id'] ?? 0);
        $order_key = sanitize_text_field($_GET['order_key'] ?? '');
        if (!$order_id || !$order_key) return;

        // Verifizieren
        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d AND order_key = %s",
            $order_id, $order_key
        ));
        if (!$order) return;

        // Capture ausführen
        $pp_order_id = get_option('_tix_paypal_order_' . $order_id);
        if ($pp_order_id) {
            $captured = self::capture($pp_order_id, $order_id);
            if ($captured) {
                delete_option('_tix_paypal_order_' . $order_id);
            }
        }

        wp_safe_redirect(TIX_Native_Checkout::thankyou_url($order_id, $order_key));
        exit;
    }

    /**
     * PayPal Capture ausführen
     */
    private static function capture($pp_order_id, $tix_order_id) {
        $token = self::get_access_token();
        if (!$token) return false;

        $response = wp_remote_post(self::api_url() . '/v2/checkout/orders/' . $pp_order_id . '/capture', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => '{}',
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = $body['status'] ?? '';

        if ($status === 'COMPLETED') {
            // Store capture ID for potential refunds
            $captures = $body['purchase_units'][0]['payments']['captures'] ?? [];
            if (!empty($captures[0]['id'])) {
                update_option('_tix_paypal_capture_' . $tix_order_id, $captures[0]['id'], false);
            }
            TIX_Native_Checkout::update_order_status($tix_order_id, 'completed', 'paypal');
            return true;
        }

        return false;
    }

    /**
     * Erstattung über PayPal Captures API
     *
     * @param int        $order_id  TIX Order ID
     * @param float|null $amount    Teilbetrag oder null für Vollerstattung
     * @return array     ['success' => true] oder ['error' => '...']
     */
    public static function refund($order_id, $amount = null) {
        $capture_id = get_option('_tix_paypal_capture_' . $order_id);
        if (!$capture_id) {
            return ['error' => 'Keine PayPal-Capture-ID gefunden. Erstattung nicht möglich.'];
        }

        $token = self::get_access_token();
        if (!$token) {
            return ['error' => 'PayPal-Authentifizierung fehlgeschlagen.'];
        }

        $body = new stdClass();
        if ($amount !== null) {
            $body->amount = [
                'currency_code' => 'EUR',
                'value'         => number_format($amount, 2, '.', ''),
            ];
        }

        $response = wp_remote_post(self::api_url() . '/v2/payments/captures/' . $capture_id . '/refund', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return ['error' => 'PayPal-Verbindungsfehler: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return ['success' => true];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $msg = $data['details'][0]['description'] ?? $data['message'] ?? ('HTTP ' . $code);
        return ['error' => 'PayPal-Fehler: ' . $msg];
    }
}
