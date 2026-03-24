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
        add_action('template_redirect', [__CLASS__, 'handle_return']);
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
        update_option('_tix_paypal_order_' . $order_id, $pp_order_id);
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
     * Return von PayPal → Capture + Thank-You
     */
    public static function handle_return() {
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
            TIX_Native_Checkout::update_order_status($tix_order_id, 'completed', 'paypal');
            return true;
        }

        return false;
    }
}
