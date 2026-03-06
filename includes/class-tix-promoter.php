<?php
/**
 * Tixomat – Promoter Core
 *
 * Referral-Tracking, WooCommerce-Integration, Provisionsberechnung,
 * Promo-Code/WC-Coupon-Management.
 *
 * @since 1.29.0
 */

if (!defined('ABSPATH')) exit;

class TIX_Promoter {

    const COOKIE_NAME = 'tix_promoter_ref';

    private static function get_cookie_days() {
        if (function_exists('tix_get_settings')) {
            $days = intval(tix_get_settings('promoter_cookie_days'));
            return $days > 0 ? $days : 30;
        }
        return 30;
    }

    public static function init() {
        // Custom Role registrieren
        add_action('init', [__CLASS__, 'register_role'], 5);

        // Referral-Link erkennen (Frontend)
        add_action('template_redirect', [__CLASS__, 'detect_referral'], 5);

        // WooCommerce Hooks
        if (class_exists('WooCommerce')) {
            // Cart-Attribution
            add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_attribution'], 10, 2);

            // Coupon-Attribution
            add_action('woocommerce_applied_coupon', [__CLASS__, 'detect_promo_coupon']);

            // Discount via Referral-Link (Cart-Fee)
            add_action('woocommerce_cart_calculate_fees', [__CLASS__, 'apply_referral_discount']);

            // Order-Meta speichern
            add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_order_meta'], 10, 2);
            add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_line_item_meta'], 10, 4);

            // Provision berechnen bei Order-Complete
            add_action('woocommerce_order_status_completed',  [__CLASS__, 'calculate_commissions'], 15);
            add_action('woocommerce_order_status_processing', [__CLASS__, 'calculate_commissions'], 15);

            // Provision stornieren
            add_action('woocommerce_order_status_cancelled', [__CLASS__, 'cancel_commissions'], 15);
            add_action('woocommerce_order_status_refunded',  [__CLASS__, 'cancel_commissions'], 15);
        }
    }

    // ──────────────────────────────────────────
    // Custom Role
    // ──────────────────────────────────────────

    public static function register_role() {
        if (!get_role('tix_promoter')) {
            add_role('tix_promoter', 'Promoter', ['read' => true]);
        }
    }

    // ──────────────────────────────────────────
    // Referral-Link erkennen
    // ──────────────────────────────────────────

    public static function detect_referral() {
        if (empty($_GET['ref'])) return;

        $code = sanitize_text_field($_GET['ref']);
        if (!class_exists('TIX_Promoter_DB')) return;

        $promoter = TIX_Promoter_DB::get_promoter_by_code($code);
        if (!$promoter) return;

        // Cookie setzen (konfigurierbare Laufzeit)
        $expire = time() + (self::get_cookie_days() * DAY_IN_SECONDS);
        setcookie(self::COOKIE_NAME, $code, $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[self::COOKIE_NAME] = $code;

        // WC Session als Fallback
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('tix_promoter_ref', $code);
            WC()->session->set('tix_promoter_attribution', 'referral');
        }
    }

    // ──────────────────────────────────────────
    // Aktiven Promoter-Code ermitteln
    // ──────────────────────────────────────────

    private static function get_active_ref() {
        // Priorität: Session (könnte Promo-Code sein) > Cookie > Session-Fallback
        if (function_exists('WC') && WC()->session) {
            $code = WC()->session->get('tix_promoter_ref');
            if ($code) return $code;
        }
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            return sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
        }
        return '';
    }

    private static function get_attribution_type() {
        if (function_exists('WC') && WC()->session) {
            $type = WC()->session->get('tix_promoter_attribution');
            if ($type) return $type;
        }
        return 'referral';
    }

    // ──────────────────────────────────────────
    // Cart-Item Attribution
    // ──────────────────────────────────────────

    public static function add_cart_attribution($cart_item_data, $product_id) {
        $code = self::get_active_ref();
        if ($code) {
            $cart_item_data['_tix_promoter_ref'] = $code;
        }
        return $cart_item_data;
    }

    // ──────────────────────────────────────────
    // Promo-Code als WC-Coupon erkennen
    // ──────────────────────────────────────────

    public static function detect_promo_coupon($coupon_code) {
        if (!class_exists('TIX_Promoter_DB')) return;

        $assignment = TIX_Promoter_DB::get_assignment_by_promo_code($coupon_code);
        if (!$assignment) return;

        // Promoter-Attribution in Session speichern
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('tix_promoter_ref', $assignment->promoter_code);
            WC()->session->set('tix_promoter_attribution', 'promo_code');
        }
    }

    // ──────────────────────────────────────────
    // Referral-Discount als Cart-Fee
    // ──────────────────────────────────────────

    public static function apply_referral_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!class_exists('TIX_Promoter_DB')) return;

        $code = self::get_active_ref();
        if (!$code) return;
        $type = self::get_attribution_type();

        // Nur Referral-Links anwenden (Promo-Codes haben eigene WC-Coupons)
        if ($type === 'promo_code') return;

        $promoter = TIX_Promoter_DB::get_promoter_by_code($code);
        if (!$promoter) return;

        $total_discount = 0;

        foreach ($cart->get_cart() as $item) {
            $product_id = $item['product_id'];
            $event_id   = intval(get_post_meta($product_id, '_tix_parent_event_id', true));
            if (!$event_id) continue;

            $assignment = TIX_Promoter_DB::get_assignment_by_promoter_event($promoter->id, $event_id);
            if (!$assignment || empty($assignment->discount_type)) continue;

            $line_total = $item['line_total'];
            if ($assignment->discount_type === 'percent') {
                $total_discount += $line_total * ($assignment->discount_value / 100);
            } elseif ($assignment->discount_type === 'fixed') {
                $total_discount += $assignment->discount_value * $item['quantity'];
            }
        }

        if ($total_discount > 0) {
            $cart->add_fee('Promoter-Rabatt', -$total_discount);
        }
    }

    // ──────────────────────────────────────────
    // Order-Meta speichern (HPOS-kompatibel)
    // ──────────────────────────────────────────

    public static function save_order_meta($order, $data) {
        $code = self::get_active_ref();
        if (!$code || !class_exists('TIX_Promoter_DB')) return;

        $promoter = TIX_Promoter_DB::get_promoter_by_code($code);
        if (!$promoter) return;

        $order->update_meta_data('_tix_promoter_id', $promoter->id);
        $order->update_meta_data('_tix_promoter_code', $code);
        $order->update_meta_data('_tix_promoter_attribution', self::get_attribution_type());
    }

    public static function save_line_item_meta($item, $cart_item_key, $values, $order) {
        if (!empty($values['_tix_promoter_ref'])) {
            $item->add_meta_data('_tix_promoter_ref', $values['_tix_promoter_ref'], true);
        }
    }

    // ──────────────────────────────────────────
    // Provisionen berechnen
    // ──────────────────────────────────────────

    public static function calculate_commissions($order_id) {
        if (!class_exists('TIX_Promoter_DB')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $promoter_id = intval($order->get_meta('_tix_promoter_id'));
        if (!$promoter_id) return;

        // Doppelte Berechnung vermeiden
        if ($order->get_meta('_tix_promoter_commissions_calculated')) return;

        $attribution = $order->get_meta('_tix_promoter_attribution') ?: 'referral';

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $event_id   = intval(get_post_meta($product_id, '_tix_parent_event_id', true));
            if (!$event_id) continue;

            $assignment = TIX_Promoter_DB::get_assignment_by_promoter_event($promoter_id, $event_id);
            if (!$assignment) continue;

            $qty        = $item->get_quantity();
            $line_total = floatval($item->get_total());

            // Provision berechnen
            $commission = 0;
            if ($assignment->commission_type === 'percent') {
                $commission = $line_total * ($assignment->commission_value / 100);
            } elseif ($assignment->commission_type === 'fixed') {
                $commission = $assignment->commission_value * $qty;
            }

            // Discount ermitteln (aus Fees oder Coupon)
            $discount = 0;
            if ($assignment->discount_type === 'percent') {
                $discount = $line_total * ($assignment->discount_value / 100);
            } elseif ($assignment->discount_type === 'fixed') {
                $discount = $assignment->discount_value * $qty;
            }

            TIX_Promoter_DB::insert_commission([
                'promoter_id'       => $promoter_id,
                'event_id'          => $event_id,
                'order_id'          => $order_id,
                'order_item_id'     => $item_id,
                'attribution'       => $attribution,
                'tickets_qty'       => $qty,
                'order_total'       => $line_total,
                'commission_amount' => round($commission, 2),
                'discount_amount'   => round($discount, 2),
            ]);
        }

        $order->update_meta_data('_tix_promoter_commissions_calculated', 1);
        $order->save();

        // Referral-Cookie löschen
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            setcookie(self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        }
    }

    // ──────────────────────────────────────────
    // Provisionen stornieren
    // ──────────────────────────────────────────

    public static function cancel_commissions($order_id) {
        if (!class_exists('TIX_Promoter_DB')) return;
        TIX_Promoter_DB::cancel_commissions_by_order($order_id);
    }

    // ──────────────────────────────────────────
    // WC-Coupon für Promo-Code erstellen
    // ──────────────────────────────────────────

    public static function create_promo_coupon(array $assignment_data, int $event_id) {
        $promo_code = sanitize_text_field($assignment_data['promo_code'] ?? '');
        if (empty($promo_code)) return null;

        $discount_type  = $assignment_data['discount_type'] ?? '';
        $discount_value = floatval($assignment_data['discount_value'] ?? 0);

        if (empty($discount_type) || $discount_value <= 0) return null;

        // Bestehenden Coupon prüfen
        $existing = wc_get_coupon_id_by_code($promo_code);
        if ($existing) return $existing;

        // Event-Produkte ermitteln
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        $product_ids = [];
        if (is_array($cats)) {
            foreach ($cats as $cat) {
                if (!empty($cat['product_id'])) {
                    $product_ids[] = intval($cat['product_id']);
                }
            }
        }

        $wc_type = ($discount_type === 'percent') ? 'percent' : 'fixed_cart';

        $coupon = new \WC_Coupon();
        $coupon->set_code($promo_code);
        $coupon->set_discount_type($wc_type);
        $coupon->set_amount($discount_value);
        $coupon->set_individual_use(false);
        $coupon->set_usage_limit(0);
        $coupon->set_usage_limit_per_user(0);

        if (!empty($product_ids)) {
            $coupon->set_product_ids($product_ids);
        }

        $coupon->set_description('Tixomat Promoter-Code');
        $coupon->save();

        return $coupon->get_id();
    }

    /**
     * WC-Coupon löschen wenn Event-Zuordnung entfernt wird
     */
    public static function delete_promo_coupon(int $coupon_id) {
        if ($coupon_id > 0) {
            wp_delete_post($coupon_id, true);
        }
    }
}
