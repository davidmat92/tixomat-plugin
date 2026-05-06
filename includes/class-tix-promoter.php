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

        // ── NATIVE CHECKOUT INTEGRATION ──
        // Cookie/Cart-Attribution wird gespeichert sobald Native-Order angelegt wird
        add_action('tix_native_order_created', [__CLASS__, 'save_native_order_attribution'], 10, 3);
        // Provision auf Native + WC einheitlich berechnen
        add_action('tix_order_completed', [__CLASS__, 'calculate_commissions']);
        add_action('tix_order_cancelled', [__CLASS__, 'cancel_commissions']);
    }

    /**
     * Native: Promoter-Attribution beim Order-Erstellen abspeichern.
     * Liest aus dem Cart (Promo-Code) und/oder dem Cookie (Referral).
     * Schreibt Promoter-ID + Attribution-Typ als Post-Meta auf das tix_order.
     */
    public static function save_native_order_attribution($order_id, $data, $cart) {
        if (!class_exists('TIX_Promoter_DB')) return;

        $promoter_id = 0;
        $attribution = '';

        // 1) Cart hat einen Promo-Code-Coupon → finde Assignment dazu
        if (!empty($cart['coupon']['code'])) {
            $code = (string) $cart['coupon']['code'];
            $assignment = TIX_Promoter_DB::get_assignment_by_promo_code($code);
            if ($assignment && !empty($assignment->promoter_id)) {
                $promoter_id = intval($assignment->promoter_id);
                $attribution = 'promo_code';
            }
        }

        // 2) Sonst: Referral-Cookie auflösen
        if (!$promoter_id && !empty($_COOKIE[self::COOKIE_NAME])) {
            $code = sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
            $promoter = TIX_Promoter_DB::get_promoter_by_code($code);
            if ($promoter) {
                $promoter_id = intval($promoter->id);
                $attribution = 'referral';
            }
        }

        if (!$promoter_id) return;

        // Als Post-Meta auf das tix_order — nutzt update_post_meta auch wenn TIX_Order
        // intern eine eigene Tabelle hat; das post_id existiert.
        update_post_meta($order_id, '_tix_promoter_id',          $promoter_id);
        update_post_meta($order_id, '_tix_promoter_attribution', $attribution);

        // Cookie löschen damit es nicht für die nächste Order erneut greift
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            setcookie(self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            unset($_COOKIE[self::COOKIE_NAME]);
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

        // Click-Tracking
        self::log_referral_click($promoter);
    }

    /**
     * Loggt einen Referral-Klick. Dedup pro Session+Promoter (max 1 Klick alle 30 Min).
     */
    private static function log_referral_click($promoter) {
        $promoter_id = intval($promoter->id);
        if (!$promoter_id) return;

        // Visitor-ID aus Cookie/Session ableiten — wenn nichts da, eine neue erzeugen
        $vid_cookie = 'tix_visitor';
        $visitor_id = isset($_COOKIE[$vid_cookie]) ? sanitize_text_field($_COOKIE[$vid_cookie]) : '';
        if (!$visitor_id) {
            $visitor_id = bin2hex(random_bytes(8));
            setcookie($vid_cookie, $visitor_id, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }

        // Dedup: Transient-Lock für 30 Min — derselbe Visitor zählt pro Promoter nur 1× /30min
        $lock_key = 'tix_pclick_' . md5($visitor_id . '_' . $promoter_id);
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 30 * MINUTE_IN_SECONDS);

        // Page-Path + Event-ID + Referrer-Host
        $path = sanitize_text_field($_SERVER['REQUEST_URI'] ?? '/');
        $path = strtok($path, '?'); // Query-String entfernen
        $path = substr($path, 0, 500);
        $event_id = is_singular('event') ? get_the_ID() : 0;
        $ref_host = '';
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $h = parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST);
            $ref_host = strtolower(preg_replace('/^www\./', '', (string) $h));
        }
        // Device
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $device = 'desktop';
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua))      $device = 'tablet';
        elseif (preg_match('/Mobile|iPhone|Android|webOS/i', $ua)) $device = 'mobile';

        TIX_Promoter_DB::insert_click([
            'promoter_id'   => $promoter_id,
            'visitor_id'    => $visitor_id,
            'page_path'     => $path,
            'event_id'      => intval($event_id),
            'referrer_host' => $ref_host,
            'device_type'   => $device,
        ]);
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

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        $is_wc = $order ? true : false;
        if (!$order && class_exists('TIX_Order')) {
            $order = TIX_Order::get($order_id);
        }
        if (!$order) return;

        // Promoter-ID aus Order-Meta (WC + native einheitlich via post_meta)
        $promoter_id = $is_wc
            ? intval($order->get_meta('_tix_promoter_id'))
            : intval(get_post_meta($order_id, '_tix_promoter_id', true));
        if (!$promoter_id) return;

        // Doppelte Berechnung vermeiden
        $already = $is_wc
            ? $order->get_meta('_tix_promoter_commissions_calculated')
            : get_post_meta($order_id, '_tix_promoter_commissions_calculated', true);
        if ($already) return;

        $attribution = ($is_wc ? $order->get_meta('_tix_promoter_attribution') : get_post_meta($order_id, '_tix_promoter_attribution', true)) ?: 'referral';

        foreach ($order->get_items() as $item_id => $item) {
            // Event-ID aus Item ermitteln — WC vs. native
            $event_id = 0;
            $qty = 0;
            $line_total = 0.0;

            if ($is_wc) {
                $product_id = method_exists($item, 'get_product_id') ? $item->get_product_id() : 0;
                $event_id   = intval(get_post_meta($product_id, '_tix_parent_event_id', true));
                $qty        = method_exists($item, 'get_quantity') ? $item->get_quantity() : 1;
                $line_total = floatval(method_exists($item, 'get_total') ? $item->get_total() : 0);
            } else {
                // Native TIX_Order Item: event_id direkt
                $event_id   = intval(method_exists($item, 'get_event_id') ? $item->get_event_id() : ($item->event_id ?? 0));
                $qty        = method_exists($item, 'get_quantity') ? $item->get_quantity() : intval($item->quantity ?? 1);
                $line_total = floatval(method_exists($item, 'get_total') ? $item->get_total() : ($item->total ?? 0));
            }

            if (!$event_id) continue;

            // Assignment für (Promoter, Event) — mit Global-Fallback (event_id=0)
            $assignment = TIX_Promoter_DB::get_assignment_by_promoter_event($promoter_id, $event_id);
            if (!$assignment) continue;

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
                'order_item_id'     => intval($item_id),
                'attribution'       => $attribution,
                'tickets_qty'       => $qty,
                'order_total'       => $line_total,
                'commission_amount' => round($commission, 2),
                'discount_amount'   => round($discount, 2),
            ]);
        }

        if ($is_wc) {
            $order->update_meta_data('_tix_promoter_commissions_calculated', 1);
            $order->save();
        } else {
            update_post_meta($order_id, '_tix_promoter_commissions_calculated', 1);
        }

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
