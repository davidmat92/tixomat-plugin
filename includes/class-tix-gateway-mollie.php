<?php
/**
 * TIX Gateway: Mollie
 * Kreditkarte, SEPA, iDEAL, Sofort, Apple Pay, Klarna via Mollie API v2.
 */
if (!defined('ABSPATH')) exit;

class TIX_Gateway_Mollie {

    const API_URL = 'https://api.mollie.com/v2';

    public static function get_id()    { return 'mollie'; }
    public static function get_title() { return 'Online bezahlen'; }
    public static function get_icon()  { return TIXOMAT_URL . 'assets/img/mollie.svg'; }

    public static function is_available() {
        return !empty(self::get_api_key());
    }

    private static function get_api_key() {
        return trim(tix_get_settings('mollie_api_key') ?? '');
    }

    /**
     * Hooks registrieren
     */
    public static function init() {
        add_action('wp_ajax_tix_mollie_webhook',        [__CLASS__, 'handle_webhook']);
        add_action('wp_ajax_nopriv_tix_mollie_webhook', [__CLASS__, 'handle_webhook']);
        add_action('template_redirect',                 [__CLASS__, 'handle_return']);
    }

    /**
     * Zahlung erstellen → Redirect-URL zurückgeben
     */
    public static function process($order_id) {
        $api_key = self::get_api_key();
        if (!$api_key) {
            return ['error' => 'Mollie API-Key nicht konfiguriert.'];
        }

        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        if (!$order) return ['error' => 'Bestellung nicht gefunden.'];

        $amount = number_format(floatval($order->total), 2, '.', '');
        $description = 'Bestellung ' . $order->order_number;

        // Webhook + Return URLs
        $webhook_url = add_query_arg([
            'action'   => 'tix_mollie_webhook',
        ], admin_url('admin-ajax.php'));

        $return_url = add_query_arg([
            'tix_payment_return' => 1,
            'gateway'            => 'mollie',
            'order_id'           => $order_id,
            'order_key'          => $order->order_key,
        ], home_url('/'));

        $response = wp_remote_post(self::API_URL . '/payments', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'amount' => [
                    'currency' => 'EUR',
                    'value'    => $amount,
                ],
                'description' => $description,
                'redirectUrl'  => $return_url,
                'webhookUrl'   => $webhook_url,
                'metadata'     => [
                    'order_id'     => $order_id,
                    'order_number' => $order->order_number,
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['error' => 'Mollie-Verbindungsfehler: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $msg = $body['detail'] ?? $body['title'] ?? ('HTTP ' . $code);
            return ['error' => 'Mollie-Fehler: ' . $msg];
        }

        // Payment-ID speichern
        $payment_id = $body['id'] ?? '';
        $wpdb->update(
            $wpdb->prefix . 'tix_orders',
            [
                'payment_method'       => 'mollie',
                'payment_method_title' => 'Mollie (' . ($body['method'] ?? 'online') . ')',
            ],
            ['id' => $order_id]
        );
        update_option('_tix_mollie_payment_' . $order_id, $payment_id);

        // Redirect-URL
        $checkout_url = $body['_links']['checkout']['href'] ?? '';
        if (!$checkout_url) {
            return ['error' => 'Keine Mollie Checkout-URL erhalten.'];
        }

        TIX_Native_Checkout::update_order_status($order_id, 'pending', 'mollie');

        return ['redirect' => $checkout_url];
    }

    /**
     * Mollie Webhook: Zahlungsstatus-Update
     */
    public static function handle_webhook() {
        $payment_id = sanitize_text_field($_POST['id'] ?? '');
        if (!$payment_id) wp_die('No payment ID', '', 200);

        $api_key = self::get_api_key();
        if (!$api_key) wp_die('No API key', '', 200);

        // Payment-Status bei Mollie abfragen
        $response = wp_remote_get(self::API_URL . '/payments/' . $payment_id, [
            'timeout' => 10,
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
        ]);

        if (is_wp_error($response)) wp_die('API error', '', 200);

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = $body['status'] ?? '';
        $order_id = intval($body['metadata']['order_id'] ?? 0);

        if (!$order_id) wp_die('No order ID', '', 200);

        // Mollie-Status → TIX-Status mappen
        $map = [
            'paid'      => 'completed',
            'authorized' => 'processing',
            'pending'   => 'pending',
            'open'      => 'pending',
            'canceled'  => 'cancelled',
            'expired'   => 'cancelled',
            'failed'    => 'failed',
        ];

        $tix_status = $map[$status] ?? 'pending';
        TIX_Native_Checkout::update_order_status($order_id, $tix_status, 'mollie');

        wp_die('OK', '', 200);
    }

    /**
     * Return von Mollie Checkout → Thank-You-Seite
     */
    public static function handle_return() {
        if (empty($_GET['tix_payment_return']) || ($_GET['gateway'] ?? '') !== 'mollie') return;

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

        // Zur Thank-You-Seite weiterleiten
        wp_safe_redirect(TIX_Native_Checkout::thankyou_url($order_id, $order_key));
        exit;
    }
}
