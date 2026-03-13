<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_VIP – Wiederkehrende Käufer automatisch als VIP markieren.
 *
 * Berechnet VIP-Status basierend auf Ticket-Anzahl ODER Bestellanzahl.
 * Speichert Ergebnis in user_meta (_tix_vip_status).
 * Optionaler Auto-Rabatt im Warenkorb.
 *
 * @since 1.28.90
 */
class TIX_VIP {

    const META_KEY     = '_tix_vip_status';
    const CACHE_PREFIX = 'tix_vip_stats_';
    const CACHE_TTL    = DAY_IN_SECONDS;

    public static function init() {
        // Recalculate on order completion
        add_action('woocommerce_order_status_completed',  [__CLASS__, 'recalculate_on_order'], 25);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'recalculate_on_order'], 25);

        // Admin: VIP badge in order list
        add_filter('woocommerce_admin_order_preview_get_order_details', [__CLASS__, 'order_preview_badge'], 10, 2);

        // Admin: VIP column in orders (HPOS + legacy)
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'order_detail_badge']);

        // Auto-discount
        if (!empty(tix_get_settings('vip_discount_enabled'))) {
            add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'apply_vip_discount']);
        }
    }

    /**
     * VIP-Status prüfen (gecacht).
     */
    public static function is_vip($user_id) {
        if (!$user_id) return false;
        $status = get_user_meta($user_id, self::META_KEY, true);
        return $status === 'vip';
    }

    /**
     * VIP-Status berechnen und speichern.
     */
    public static function calculate_vip_status($user_id) {
        if (!$user_id) return false;

        $s = TIX_Settings::get();
        $min_tickets = intval($s['vip_min_tickets'] ?? 5);
        $min_orders  = intval($s['vip_min_orders'] ?? 3);

        // Ticket-Count aus tixomat_tickets
        global $wpdb;
        $ticket_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tixomat_tickets WHERE buyer_email = (SELECT user_email FROM {$wpdb->users} WHERE ID = %d) AND ticket_status = 'valid'",
            $user_id
        ));

        // Order-Count via WC
        $order_count = 0;
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'customer_id' => $user_id,
                'status'      => ['wc-completed', 'wc-processing'],
                'return'      => 'ids',
                'limit'       => -1,
            ]);
            $order_count = count($orders);
        }

        // OR-Logik: VIP wenn eines der Kriterien erfüllt
        $is_vip = ($ticket_count >= $min_tickets) || ($order_count >= $min_orders);

        // Speichern
        update_user_meta($user_id, self::META_KEY, $is_vip ? 'vip' : '');

        // Cache stats
        set_transient(self::CACHE_PREFIX . $user_id, [
            'tickets' => $ticket_count,
            'orders'  => $order_count,
            'is_vip'  => $is_vip,
        ], self::CACHE_TTL);

        return $is_vip;
    }

    /**
     * Hook: Neuberechnung nach Bestellabschluss.
     */
    public static function recalculate_on_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $user_id = $order->get_customer_id();
        if (!$user_id) return;

        // Transient-Cache löschen
        delete_transient(self::CACHE_PREFIX . $user_id);

        self::calculate_vip_status($user_id);
    }

    /**
     * VIP-Badge im Order-Detail (Billing-Adresse).
     */
    public static function order_detail_badge($order) {
        $user_id = $order->get_customer_id();
        if (!$user_id || !self::is_vip($user_id)) return;

        $label = esc_html(tix_get_settings('vip_badge_label') ?: 'VIP');
        echo '<p style="margin-top:8px"><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:linear-gradient(135deg,#FFD700,#FFA500);color:#000">' . $label . '</span></p>';
    }

    /**
     * VIP-Badge in Order-Preview-Modal.
     */
    public static function order_preview_badge($data, $order) {
        $user_id = $order->get_customer_id();
        if ($user_id && self::is_vip($user_id)) {
            $label = esc_html(tix_get_settings('vip_badge_label') ?: 'VIP');
            $data['formatted_billing_address'] .= '<br><span style="display:inline-block;margin-top:4px;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FFD700,#FFA500);color:#000">' . $label . '</span>';
        }
        return $data;
    }

    /**
     * Auto-Rabatt für VIP-Kunden im Warenkorb.
     */
    public static function apply_vip_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (did_action('woocommerce_cart_calculate_fees') > 1) return;

        $user_id = get_current_user_id();
        if (!$user_id || !self::is_vip($user_id)) return;

        $s    = TIX_Settings::get();
        $type = $s['vip_discount_type'] ?? 'percent';
        $val  = floatval($s['vip_discount_value'] ?? 10);
        if ($val <= 0) return;

        $label = esc_html($s['vip_badge_label'] ?? 'VIP') . '-Rabatt';

        if ($type === 'percent') {
            $subtotal = $cart->get_subtotal();
            $discount = round($subtotal * ($val / 100), 2);
            $label   .= ' (' . intval($val) . '%)';
        } else {
            $discount = $val;
        }

        if ($discount > 0) {
            $cart->add_fee($label, -$discount);
        }
    }

    /**
     * Alle User VIP-Status neu berechnen (Bulk).
     * Für CLI oder Admin-Action.
     */
    public static function recalculate_all() {
        $users = get_users(['fields' => 'ID']);
        $count = 0;
        foreach ($users as $user_id) {
            if (self::calculate_vip_status($user_id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * VIP-Stats für einen User holen (gecacht).
     */
    public static function get_stats($user_id) {
        $cached = get_transient(self::CACHE_PREFIX . $user_id);
        if ($cached !== false) return $cached;

        // Neuberechnung triggern
        self::calculate_vip_status($user_id);
        return get_transient(self::CACHE_PREFIX . $user_id) ?: ['tickets' => 0, 'orders' => 0, 'is_vip' => false];
    }
}
