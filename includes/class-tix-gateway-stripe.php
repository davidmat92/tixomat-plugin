<?php
/**
 * TIX Gateway: Stripe
 *
 * Stripe Checkout (gehostet) mit Methoden-Liste in Tixomat. Pro Methode wird
 * eine Checkout Session mit payment_method_types=[$method] erstellt und der
 * Kunde direkt in den Stripe-Flow geleitet.
 *
 * Webhook-Events: checkout.session.completed, .async_payment_succeeded,
 *                 .async_payment_failed, charge.refunded
 */
if (!defined('ABSPATH')) exit;

class TIX_Gateway_Stripe {

    const API_URL           = 'https://api.stripe.com/v1';
    const METHODS_CACHE_KEY = 'tix_stripe_methods_v1';
    const METHODS_CACHE_TTL = 6 * HOUR_IN_SECONDS;

    // Label + Logo-URL (Mollies öffentlicher Payment-Methods-CDN — stabile, hochauflösende SVGs)
    private static $method_labels = [
        'card'       => ['label' => 'Karte (Visa, Mastercard, Amex)', 'image' => 'https://www.mollie.com/external/icons/payment-methods/creditcard.svg'],
        'klarna'     => ['label' => 'Klarna (Rechnung, Ratenzahlung)', 'image' => 'https://www.mollie.com/external/icons/payment-methods/klarna.svg'],
        'sepa_debit' => ['label' => 'SEPA-Lastschrift',                'image' => 'https://www.mollie.com/external/icons/payment-methods/directdebit.svg'],
        'sofort'     => ['label' => 'SOFORT',                          'image' => 'https://www.mollie.com/external/icons/payment-methods/sofort.svg'],
        'giropay'    => ['label' => 'GiroPay',                         'image' => 'https://www.mollie.com/external/icons/payment-methods/giropay.svg'],
        'bancontact' => ['label' => 'Bancontact',                      'image' => 'https://www.mollie.com/external/icons/payment-methods/bancontact.svg'],
        'ideal'      => ['label' => 'iDEAL',                           'image' => 'https://www.mollie.com/external/icons/payment-methods/ideal.svg'],
        'eps'        => ['label' => 'EPS',                             'image' => 'https://www.mollie.com/external/icons/payment-methods/eps.svg'],
        'p24'        => ['label' => 'Przelewy24',                      'image' => 'https://www.mollie.com/external/icons/payment-methods/przelewy24.svg'],
        'paypal'     => ['label' => 'PayPal',                          'image' => 'https://www.mollie.com/external/icons/payment-methods/paypal.svg'],
        'link'       => ['label' => 'Stripe Link',                     'image' => ''],
        'apple_pay'  => ['label' => 'Apple Pay',                       'image' => 'https://www.mollie.com/external/icons/payment-methods/applepay.svg'],
        'google_pay' => ['label' => 'Google Pay',                      'image' => ''],
        'amazon_pay' => ['label' => 'Amazon Pay',                      'image' => ''],
        'blik'       => ['label' => 'BLIK',                            'image' => ''],
        'kakao_pay'  => ['label' => 'Kakao Pay',                       'image' => ''],
        'naver_pay'  => ['label' => 'Naver Pay',                       'image' => ''],
        'payco'      => ['label' => 'Payco',                           'image' => ''],
        'mb_way'     => ['label' => 'MB Way',                          'image' => ''],
    ];

    public static function get_id()    { return 'stripe'; }
    public static function get_title() { return 'Stripe'; }
    public static function get_icon()  { return ''; }

    public static function is_available() {
        if (!tix_get_settings('stripe_enabled')) return false;
        return !empty(self::get_secret_key());
    }

    public static function is_test_mode(): bool {
        return !empty(tix_get_settings('stripe_test_mode'));
    }

    public static function get_secret_key(): string {
        $k = self::is_test_mode()
            ? tix_get_settings('stripe_secret_key_test')
            : tix_get_settings('stripe_secret_key_live');
        return trim((string) $k);
    }

    public static function get_publishable_key(): string {
        $k = self::is_test_mode()
            ? tix_get_settings('stripe_publishable_key_test')
            : tix_get_settings('stripe_publishable_key_live');
        return trim((string) $k);
    }

    public static function get_webhook_secret(): string {
        $k = self::is_test_mode()
            ? tix_get_settings('stripe_webhook_secret_test')
            : tix_get_settings('stripe_webhook_secret_live');
        return trim((string) $k);
    }

    public static function init() {
        add_action('wp_ajax_tix_stripe_webhook',        [__CLASS__, 'handle_webhook']);
        add_action('wp_ajax_nopriv_tix_stripe_webhook', [__CLASS__, 'handle_webhook']);
        add_action('template_redirect',                 [__CLASS__, 'handle_return']);
    }

    /* ────────────── METHODEN ────────────── */

    /**
     * Liefert aktivierte Methoden vom Stripe-Account (gecached).
     * Nutzt /v1/payment_method_configurations — falls leer/nicht konfiguriert,
     * Fallback auf alle bekannten Default-Methoden.
     */
    public static function get_methods(bool $force_refresh = false): array {
        if (!self::is_available()) return [];

        // Cache-Key inkl. Test/Live damit Wechsel funktioniert
        $cache_key = self::METHODS_CACHE_KEY . '_' . (self::is_test_mode() ? 'test' : 'live');
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) return $cached;
        }

        $response = wp_remote_get(self::API_URL . '/payment_method_configurations', [
            'timeout' => 8,
            'headers' => ['Authorization' => 'Bearer ' . self::get_secret_key()],
        ]);

        $methods = [];
        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if ($code >= 200 && $code < 300 && !empty($body['data'])) {
                // Default-Konfiguration finden (oder erste aktive)
                $config = null;
                foreach ($body['data'] as $c) {
                    if (!empty($c['is_default']) && !empty($c['active'])) { $config = $c; break; }
                }
                if (!$config) {
                    foreach ($body['data'] as $c) {
                        if (!empty($c['active'])) { $config = $c; break; }
                    }
                }
                if ($config) {
                    foreach ($config as $key => $val) {
                        if (!is_array($val) || !isset($val['display_preference'])) continue;
                        $pref = $val['display_preference']['value'] ?? '';
                        if ($pref === 'on') {
                            $methods[] = [
                                'id'    => $key,
                                'label' => self::$method_labels[$key]['label'] ?? ucwords(str_replace('_', ' ', $key)),
                                'image' => self::$method_labels[$key]['image'] ?? '',
                            ];
                        }
                    }
                }
            }
        }

        // Fallback: wenn Stripe keine Configuration-API-Antwort gibt, nehmen wir Card als Default
        if (empty($methods)) {
            $methods[] = ['id' => 'card', 'label' => self::$method_labels['card']['label'], 'image' => self::$method_labels['card']['image']];
        }

        set_transient($cache_key, $methods, self::METHODS_CACHE_TTL);
        return $methods;
    }

    public static function clear_methods_cache() {
        delete_transient(self::METHODS_CACHE_KEY . '_test');
        delete_transient(self::METHODS_CACHE_KEY . '_live');
    }

    /* ────────────── CHECKOUT SESSION ────────────── */

    public static function process($order_id, string $preferred_method = '') {
        $secret = self::get_secret_key();
        if (!$secret) return ['error' => 'Stripe Secret Key nicht konfiguriert.'];

        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        if (!$order) return ['error' => 'Bestellung nicht gefunden.'];

        $amount_cents = intval(round(floatval($order->total) * 100));
        if ($amount_cents <= 0) return ['error' => 'Ungültiger Betrag.'];

        // Methode (default card)
        $method = $preferred_method !== '' ? sanitize_text_field($preferred_method) : 'card';

        $return_url = add_query_arg([
            'tix_payment_return' => 1,
            'gateway'            => 'stripe',
            'order_id'           => $order_id,
            'order_key'          => $order->order_key,
            'session_id'         => '{CHECKOUT_SESSION_ID}', // Stripe ersetzt das
        ], home_url('/'));

        $cancel_url = add_query_arg([
            'tix_payment_return' => 1,
            'gateway'            => 'stripe',
            'order_id'           => $order_id,
            'order_key'          => $order->order_key,
            'cancelled'          => 1,
        ], home_url('/'));

        // Form-encoded body (Stripe API erwartet application/x-www-form-urlencoded)
        $body = [
            'mode'                          => 'payment',
            'success_url'                   => $return_url,
            'cancel_url'                    => $cancel_url,
            'payment_method_types[0]'       => $method,
            'line_items[0][quantity]'       => 1,
            'line_items[0][price_data][currency]'                    => 'eur',
            'line_items[0][price_data][unit_amount]'                 => $amount_cents,
            'line_items[0][price_data][product_data][name]'          => 'Bestellung ' . $order->order_number,
            'metadata[tix_order_id]'        => $order_id,
            'metadata[tix_order_number]'    => $order->order_number,
            'payment_intent_data[metadata][tix_order_id]'     => $order_id,
            'payment_intent_data[metadata][tix_order_number]' => $order->order_number,
            'customer_email'                => $order->billing_email,
        ];

        $response = wp_remote_post(self::API_URL . '/checkout/sessions', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return ['error' => 'Stripe-Verbindungsfehler: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $code);
            return ['error' => 'Stripe-Fehler: ' . $msg];
        }

        $session_id   = $data['id'] ?? '';
        $checkout_url = $data['url'] ?? '';
        if (!$checkout_url) return ['error' => 'Stripe lieferte keine Checkout-URL.'];

        $wpdb->update(
            $wpdb->prefix . 'tix_orders',
            [
                'payment_method'       => 'stripe',
                'payment_method_title' => 'Stripe (' . (self::$method_labels[$method]['label'] ?? $method) . ')',
            ],
            ['id' => $order_id]
        );
        update_option('_tix_stripe_session_' . $order_id, $session_id, false);
        update_option('_tix_stripe_method_' . $order_id, $method, false);

        return ['redirect' => $checkout_url];
    }

    /* ────────────── WEBHOOK ────────────── */

    public static function handle_webhook() {
        $signing_secret = self::get_webhook_secret();
        $payload = file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if ($signing_secret && $sig_header) {
            if (!self::verify_signature($payload, $sig_header, $signing_secret)) {
                status_header(400);
                echo 'Invalid signature';
                exit;
            }
        }
        // Hinweis: ohne signing_secret akzeptieren wir den Call (z.B. lokales Testing).
        // In Produktion immer signing_secret konfigurieren!

        $event = json_decode($payload, true);
        $type  = $event['type'] ?? '';

        switch ($type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                $sess = $event['data']['object'] ?? [];
                $order_id = intval($sess['metadata']['tix_order_id'] ?? 0);
                if ($order_id && self::order_belongs_to_stripe($order_id)) {
                    if (!empty($sess['payment_intent'])) {
                        update_option('_tix_stripe_pi_' . $order_id, $sess['payment_intent'], false);
                    }
                    $status = (($sess['payment_status'] ?? '') === 'paid') ? 'completed' : 'processing';
                    if (class_exists('TIX_Native_Checkout') && method_exists('TIX_Native_Checkout', 'update_order_status')) {
                        // update_order_status feuert tix_order_status_changed selbst → triggert Ticket-Erstellung
                        TIX_Native_Checkout::update_order_status($order_id, $status, 'stripe');
                    }
                }
                break;

            case 'checkout.session.async_payment_failed':
                $sess = $event['data']['object'] ?? [];
                $order_id = intval($sess['metadata']['tix_order_id'] ?? 0);
                if ($order_id && self::order_belongs_to_stripe($order_id)) {
                    if (class_exists('TIX_Native_Checkout') && method_exists('TIX_Native_Checkout', 'update_order_status')) {
                        TIX_Native_Checkout::update_order_status($order_id, 'failed', 'stripe');
                    }
                }
                break;

            case 'charge.refunded':
                // Optional: Status-Sync bei Refund — Order-Admin handhabt Refunds aktiv via API
                break;
        }

        status_header(200);
        echo 'OK';
        exit;
    }

    private static function verify_signature(string $payload, string $sig_header, string $secret): bool {
        $parts = [];
        foreach (explode(',', $sig_header) as $pair) {
            $kv = explode('=', $pair, 2);
            if (count($kv) === 2) $parts[trim($kv[0])] = trim($kv[1]);
        }
        $timestamp = $parts['t'] ?? '';
        $sig       = $parts['v1'] ?? '';
        if (!$timestamp || !$sig) return false;
        // Replay-Schutz: max 5 Min alt
        if (abs(time() - intval($timestamp)) > 5 * MINUTE_IN_SECONDS) return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        return hash_equals($expected, $sig);
    }

    private static function order_belongs_to_stripe(int $order_id): bool {
        global $wpdb;
        $pm = $wpdb->get_var($wpdb->prepare(
            "SELECT payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        return $pm === 'stripe';
    }

    /* ────────────── RETURN-HANDLER ────────────── */

    public static function handle_return() {
        if (empty($_GET['tix_payment_return']) || ($_GET['gateway'] ?? '') !== 'stripe') return;
        $order_id  = intval($_GET['order_id']  ?? 0);
        $order_key = sanitize_text_field($_GET['order_key'] ?? '');
        if (!$order_id) return;

        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d AND order_key = %s",
            $order_id, $order_key
        ));
        if (!$order) return;

        // Bei "cancelled" → zurück zum Checkout
        if (!empty($_GET['cancelled'])) {
            $checkout_url = home_url('/');
            $s = get_option('tix_settings', []);
            if (!empty($s['checkout_page_id'])) {
                $cp = get_permalink(intval($s['checkout_page_id']));
                if ($cp) $checkout_url = $cp;
            }
            wp_safe_redirect(add_query_arg('tix_payment_cancelled', '1', $checkout_url));
            exit;
        }

        // Bei Erfolg → Thank-You (Webhook setzt eigentlichen Status, wir leiten direkt weiter)
        $thank_url = home_url('/');
        $s = get_option('tix_settings', []);
        if (!empty($s['thank_you_page_id'])) {
            $tp = get_permalink(intval($s['thank_you_page_id']));
            if ($tp) $thank_url = $tp;
        }
        $thank_url = add_query_arg([
            'order_id'  => $order_id,
            'order_key' => $order_key,
        ], $thank_url);
        wp_safe_redirect($thank_url);
        exit;
    }

    /* ────────────── REFUND ────────────── */

    public static function refund($order_id, $amount = null) {
        $secret = self::get_secret_key();
        if (!$secret) return ['success' => false, 'error' => 'Stripe Secret Key nicht konfiguriert.'];

        $pi = get_option('_tix_stripe_pi_' . $order_id);
        if (!$pi) return ['success' => false, 'error' => 'Kein Stripe Payment-Intent für diese Bestellung.'];

        $body = ['payment_intent' => $pi];
        if ($amount !== null) {
            $body['amount'] = intval(round(floatval($amount) * 100));
        }

        $response = wp_remote_post(self::API_URL . '/refunds', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => 'Verbindungsfehler: ' . $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'error' => $data['error']['message'] ?? ('HTTP ' . $code)];
        }
        return ['success' => true, 'refund_id' => $data['id'] ?? ''];
    }
}
