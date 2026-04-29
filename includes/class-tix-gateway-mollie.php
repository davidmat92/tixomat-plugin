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
        update_option('_tix_mollie_payment_' . $order_id, $payment_id, false);

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

        // Verify order exists in DB
        global $wpdb;
        $order_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        if (!$order_exists) wp_die('Order not found', '', 200);

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

        // Atomic idempotency via transient lock
        $lock_key = 'tix_mollie_lock_' . $order_id;
        if (get_transient($lock_key)) {
            wp_die('Already processing', '', 200);
        }
        set_transient($lock_key, 1, 30); // 30 second lock

        $old_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));
        if ($old_status === $tix_status) {
            delete_transient($lock_key);
            wp_die('No change', '', 200);
        }

        // ── DOWNGRADE-SCHUTZ ──
        // Niemals von completed/processing → cancelled/failed downgraden.
        // Schützt vor zeitlich-überlappenden Webhooks (paid + expired in falscher Reihenfolge).
        // Refund/Storno muss manuell im Admin gemacht werden.
        if (in_array($old_status, ['completed', 'processing'], true)
            && in_array($tix_status, ['cancelled', 'failed'], true)) {
            if (class_exists('TIX_Order_Admin')) {
                TIX_Order_Admin::add_note(
                    $order_id,
                    '🛡️ Mollie-Webhook-Downgrade abgewiesen: Status ' . $old_status . ' bleibt erhalten (Mollie meldete: ' . $status . '). Manuelle Storno-Aktion notwendig falls echter Refund.',
                    'system'
                );
            }
            delete_transient($lock_key);
            wp_die('Downgrade blocked', '', 200);
        }

        TIX_Native_Checkout::update_order_status($order_id, $tix_status, 'mollie');

        // ── Gebühren-Erfassung bei erfolgreicher Zahlung ──
        // Mollie liefert: amount (gross), settlementAmount (net) — Differenz = Mollie-Gebühr
        if ($tix_status === 'completed' && !empty($body['amount']['value'])) {
            self::store_fee_from_payment($order_id, $body);
        }

        delete_transient($lock_key);

        wp_die('OK', '', 200);
    }

    /**
     * Erstattung über Mollie Refunds API
     *
     * @param int        $order_id  TIX Order ID
     * @param float|null $amount    Teilbetrag oder null für Vollerstattung
     * @return array     ['success' => true] oder ['error' => '...']
     */
    public static function refund($order_id, $amount = null) {
        $payment_id = get_option('_tix_mollie_payment_' . $order_id);
        if (!$payment_id) {
            return ['error' => 'Keine Mollie-Payment-ID gefunden.'];
        }

        $api_key = self::get_api_key();
        if (!$api_key) {
            return ['error' => 'Mollie API-Key nicht konfiguriert.'];
        }

        $body = [];
        if ($amount !== null) {
            $body['amount'] = [
                'currency' => 'EUR',
                'value'    => number_format($amount, 2, '.', ''),
            ];
        }

        $response = wp_remote_post(self::API_URL . '/payments/' . $payment_id . '/refunds', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return ['error' => 'Mollie-Verbindungsfehler: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return ['success' => true];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return ['error' => $data['detail'] ?? 'Mollie-Fehler (HTTP ' . $code . ')'];
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

    /**
     * Speichert Mollie-Gebühren aus Payment-Response.
     * Mollie-Logik: fee = amount - settlementAmount
     * Bei manchen Methoden ist settlementAmount erst nach Settlement gefüllt (1-2 Tage Delay)
     */
    private static function store_fee_from_payment($order_id, array $body) {
        if (empty($body['amount']['value'])) return false;
        $gross    = floatval($body['amount']['value']);
        $currency = $body['amount']['currency'] ?? 'EUR';

        // settlementAmount ist erst nach Mollie-Settlement gefüllt — Fallback auf 0
        if (empty($body['settlementAmount']['value'])) {
            // Noch keine Gebühren-Info verfügbar — markieren für späteren Backfill
            update_post_meta($order_id, '_tix_mollie_fee_pending', current_time('mysql'));
            return false;
        }
        $net = floatval($body['settlementAmount']['value']);
        $fee = round($gross - $net, 2);

        update_post_meta($order_id, '_tix_payment_fee',          $fee);
        update_post_meta($order_id, '_tix_payment_fee_currency', $currency);
        update_post_meta($order_id, '_tix_payment_gross',        $gross);
        update_post_meta($order_id, '_tix_payment_net',          $net);
        update_post_meta($order_id, '_tix_payment_gateway',      'mollie');
        delete_post_meta($order_id, '_tix_mollie_fee_pending');

        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note(
                $order_id,
                sprintf('💳 Mollie-Capture · Brutto %s · Gebühr %s · Netto %s %s',
                    number_format($gross, 2, ',', '.'),
                    number_format($fee,   2, ',', '.'),
                    number_format($net,   2, ',', '.'),
                    $currency
                ),
                'payment'
            );
        }
        return ['fee' => $fee, 'gross' => $gross, 'net' => $net, 'currency' => $currency];
    }

    /**
     * Lädt Gebühren-Daten für eine bestehende Mollie-Order nach.
     * Reihenfolge:
     *   1. Cache (bereits gespeichert)
     *   2. WC-Migration aus _mollie_payment_id (falls Mollie-WC-Plugin genutzt wurde)
     *   3. Mollie-API mit Payment-ID
     *
     * Returns: ['fee', 'gross', 'net', 'currency', 'cached', 'source'] oder false
     */
    public static function backfill_fee($tix_order_id) {
        // 1) Cache?
        $existing_fee = get_post_meta($tix_order_id, '_tix_payment_fee', true);
        if ($existing_fee !== '' && $existing_fee !== false && floatval($existing_fee) > 0) {
            return [
                'fee'      => floatval($existing_fee),
                'gross'    => floatval(get_post_meta($tix_order_id, '_tix_payment_gross', true) ?: 0),
                'net'      => floatval(get_post_meta($tix_order_id, '_tix_payment_net', true) ?: 0),
                'currency' => get_post_meta($tix_order_id, '_tix_payment_fee_currency', true) ?: 'EUR',
                'cached'   => true,
                'source'   => 'cache',
            ];
        }

        $payment_id = get_option('_tix_mollie_payment_' . $tix_order_id);

        // 2) WC-Migration: Mollie-Payment-ID aus WC-Order-Meta holen wenn keine native vorhanden
        if (!$payment_id) {
            global $wpdb;
            $tix_t = $wpdb->prefix . 'tix_orders';
            $wc_id = intval($wpdb->get_var($wpdb->prepare("SELECT wc_order_id FROM $tix_t WHERE id = %d", $tix_order_id)));
            if ($wc_id > 0) {
                // HPOS oder Legacy
                $op_meta = $wpdb->prefix . 'wc_orders_meta';
                if ($wpdb->get_var("SHOW TABLES LIKE '$op_meta'") === $op_meta) {
                    $payment_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM $op_meta WHERE order_id = %d AND meta_key = '_mollie_payment_id' LIMIT 1",
                        $wc_id
                    ));
                }
                if (!$payment_id) {
                    $payment_id = get_post_meta($wc_id, '_mollie_payment_id', true);
                }
            }
        }
        if (!$payment_id) return false;

        // 3) Mollie-API
        $api_key = self::get_api_key();
        if (!$api_key) return false;

        $r = wp_remote_get(self::API_URL . '/payments/' . $payment_id, [
            'timeout' => 12,
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
        ]);
        if (is_wp_error($r)) return false;
        $body = json_decode(wp_remote_retrieve_body($r), true);
        if (empty($body['amount']['value'])) return false;

        // settlementAmount nicht da → Gebühr noch nicht abrechenbar (Mollie braucht 1-2 Tage)
        if (empty($body['settlementAmount']['value'])) {
            update_post_meta($tix_order_id, '_tix_mollie_fee_pending', current_time('mysql'));
            return false;
        }

        $gross    = floatval($body['amount']['value']);
        $net      = floatval($body['settlementAmount']['value']);
        $fee      = round($gross - $net, 2);
        $currency = $body['amount']['currency'] ?? 'EUR';

        update_post_meta($tix_order_id, '_tix_payment_fee',          $fee);
        update_post_meta($tix_order_id, '_tix_payment_fee_currency', $currency);
        update_post_meta($tix_order_id, '_tix_payment_gross',        $gross);
        update_post_meta($tix_order_id, '_tix_payment_net',          $net);
        update_post_meta($tix_order_id, '_tix_payment_gateway',      'mollie');
        delete_post_meta($tix_order_id, '_tix_mollie_fee_pending');

        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note(
                $tix_order_id,
                sprintf('🔄 Mollie-Gebühren nachgeladen · Brutto %s · Gebühr %s · Netto %s %s',
                    number_format($gross, 2, ',', '.'),
                    number_format($fee,   2, ',', '.'),
                    number_format($net,   2, ',', '.'),
                    $currency
                ),
                'payment'
            );
        }

        return [
            'fee'      => $fee,
            'gross'    => $gross,
            'net'      => $net,
            'currency' => $currency,
            'cached'   => false,
            'source'   => 'mollie_api',
        ];
    }
}
