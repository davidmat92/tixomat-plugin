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
        // Erstattung und Rückbuchung (1.38.643): vorher blieb die Provision bei Teil-Erstattungen, bei voller Erstattung
        // ohne Ticket-Storno und bei Rückbuchungen stehen, nach einer Auszahlung auch bei Storno
        add_action('tix_order_refund_recorded', [__CLASS__, 'on_refund'], 10, 3);
        add_action('tix_order_chargeback', [__CLASS__, 'on_chargeback'], 10, 2);
        add_action('tix_order_status_changed', function ($order_id, $new) { if ($new === 'refunded') self::cancel_commissions($order_id); }, 10, 2);
        // Klicks nach 13 Monaten löschen (Datenschutz, Auswertung reicht ein Jahr zurück)
        add_action('init', function () { if (!wp_next_scheduled('tix_promoter_daily')) wp_schedule_event(time() + 3600, 'daily', 'tix_promoter_daily'); }, 52);
        add_action('tix_promoter_daily', [__CLASS__, 'daily']);
    }

    public static function daily() {
        global $wpdb;
        if (!class_exists('TIX_Promoter_DB')) return;
        $t = TIX_Promoter_DB::table_clicks();
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return;
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE click_date < %s LIMIT 20000", gmdate('Y-m-d', time() - 400 * DAY_IN_SECONDS)));
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    /**
     * Mandantentrennung (evendis, 1.38.643): Darf der Promoter an diesem Event verdienen bzw. dafür werben?
     * Einzel-Seite: immer. Mehr-Veranstalter: Promoter eines Veranstalters nur bei dessen Events (auch Mit-Veranstalter),
     * Promoter ohne Veranstalter (vom Admin angelegt) nur bei ausdrücklich zugeordneten Events.
     */
    public static function event_allowed($promoter, $event_id, $explicit = false) {
        if (!self::multi()) return true;
        $org = intval(is_object($promoter) ? ($promoter->organizer_id ?? 0) : 0);
        if ($org > 0) return in_array($org, array_map('intval', TIX_App_Scope::event_org_ids(intval($event_id))), true);
        return (bool) $explicit;
    }

    /** Events, die der Promoter im Dashboard sieht (null = alle, Einzel-Seite) */
    public static function scope_event_ids($promoter) {
        if (!self::multi()) return null;
        $ids = [];
        foreach ((array) TIX_Promoter_DB::get_promoter_events(intval($promoter->id)) as $a) if (intval($a->event_id) > 0) $ids[] = intval($a->event_id);
        $org = intval($promoter->organizer_id ?? 0);
        if ($org > 0) {
            $own = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 500, 'fields' => 'ids', 'meta_query' => ['relation' => 'OR',
                ['key' => '_tix_organizer_id', 'value' => $org], ['key' => '_tix_co_organizer_id', 'value' => $org]]]);
            $ids = array_merge($ids, array_map('intval', $own));
        }
        $ids = array_values(array_unique(array_filter($ids, function ($e) use ($promoter) { return self::event_allowed($promoter, $e, true); })));
        return $ids ?: [0];
    }

    /** Selbstkauf: Käufer ist der Promoter selbst (gleiche E-Mail bzw. gleiches Konto) */
    public static function is_self_purchase($promoter, $order) {
        $mail = strtolower(trim((string) (method_exists($order, 'get_billing_email') ? $order->get_billing_email() : '')));
        $cid = method_exists($order, 'get_customer_id') ? intval($order->get_customer_id()) : 0;
        $uid = intval($promoter->user_id ?? 0);
        if ($uid > 0 && $cid === $uid) return true;
        $mails = array_filter([strtolower(trim((string) ($promoter->email ?? '')))]);
        if ($uid > 0 && ($u = get_userdata($uid))) $mails[] = strtolower($u->user_email);
        return $mail !== '' && in_array($mail, $mails, true);
    }

    /** Teil-Erstattung: Provision anteilig zurücknehmen; volle Erstattung: ganz */
    public static function on_refund($order_id, $amount, $full) {
        if (!class_exists('TIX_Promoter_DB')) return;
        if ($full) { TIX_Promoter_DB::reverse_order(intval($order_id), 1.0, 'refund'); return; }
        $total = self::order_paid_total($order_id);
        if ($total > 0) TIX_Promoter_DB::reverse_order(intval($order_id), floatval($amount) / $total, 'refund');
    }

    public static function on_chargeback($order_id, $amount = 0) {
        if (!class_exists('TIX_Promoter_DB')) return;
        $total = self::order_paid_total($order_id);
        $ratio = ($amount > 0 && $total > 0) ? floatval($amount) / $total : 1.0;
        TIX_Promoter_DB::reverse_order(intval($order_id), $ratio, 'chargeback');
    }

    private static function order_paid_total($order_id) {
        $o = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if (!$o && class_exists('TIX_Order')) $o = TIX_Order::get($order_id);
        return $o ? floatval($o->get_total()) : 0.0;
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

        // 2) Sonst: Referral-Cookie (mit Einwilligung) bzw. Zuordnung aus diesem Besuch
        $ref_code = !empty($_COOKIE[self::COOKIE_NAME]) ? sanitize_text_field($_COOKIE[self::COOKIE_NAME]) : self::session_ref();
        if (!$promoter_id && $ref_code !== '') {
            $promoter = TIX_Promoter_DB::get_promoter_by_code($ref_code);
            if ($promoter) {
                $promoter_id = intval($promoter->id);
                $attribution = 'referral';
            }
        }

        if (!$promoter_id) return;

        // An der Bestellung (TIX_Order_Attribution, seit 1.38.616 nicht mehr als Postmeta an der Bestell-ID)
        TIX_Order_Attribution::set_meta($order_id, '_tix_promoter_id', $promoter_id);
        TIX_Order_Attribution::set_meta($order_id, '_tix_promoter_attribution', $attribution);

        // Zuordnung dieses Besuchs verbrauchen (nächste Bestellung neu zuordnen)
        $k = self::session_key();
        if ($k !== '') delete_transient('tix_pref_' . md5($k));

        // Cookie löschen damit es nicht für die nächste Order erneut greift
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            setcookie(self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            unset($_COOKIE[self::COOKIE_NAME]);
        }
    }

    // ──────────────────────────────────────────
    // Zuordnung im selben Besuch (ohne Einwilligung)
    // ──────────────────────────────────────────

    /** Schlüssel der laufenden Shop-Sitzung (Konto bzw. Warenkorb-Sitzung), '' ohne Sitzung. */
    private static function session_key() {
        if (is_user_logged_in()) return 'u' . get_current_user_id();
        $sid = isset($_COOKIE['tix_cart_session']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $_COOKIE['tix_cart_session']) : '';
        return $sid !== '' ? 's' . $sid : '';
    }

    private static function remember_session_ref($code) {
        $k = self::session_key();
        if ($k !== '') set_transient('tix_pref_' . md5($k), (string) $code, 2 * HOUR_IN_SECONDS);
    }

    public static function session_ref() {
        $k = self::session_key();
        return $k !== '' ? (string) get_transient('tix_pref_' . md5($k)) : '';
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
        // Bots und Vorschau-Abrufe (Messenger, Crawler) ordnen nichts zu und zählen keinen Klick
        if (preg_match(class_exists('TIX_Web_Stats') ? TIX_Web_Stats::BOT : '/bot|crawl|spider|preview/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) || empty($_SERVER['HTTP_USER_AGENT'])) return;

        $promoter = TIX_Promoter_DB::get_promoter_by_code($code);
        if (!$promoter) return;

        if (class_exists('TIX_Consent') && TIX_Consent::has('marketing')) {
            // Mit Einwilligung „Marketing“: Cookie (konfigurierbare Laufzeit)
            $expire = time() + (self::get_cookie_days() * DAY_IN_SECONDS);
            if (!headers_sent()) setcookie(self::COOKIE_NAME, $code, $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE[self::COOKIE_NAME] = $code;
        }
        // Immer: Zuordnung für diesen Besuch serverseitig an der (technisch notwendigen)
        // Warenkorb-Sitzung – kein zusätzliches Cookie, gilt bis zum Ende der Sitzung
        self::remember_session_ref($code);

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

        // Besucher-Kennung ohne Cookie: Hash aus IP + Browser + Tagessalz (nur zum Entdoppeln,
        // nicht rückrechenbar, wechselt täglich) – früher Cookie tix_visitor (1 Jahr)
        $ip = class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $visitor_id = substr(hash_hmac('sha256', $ip . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''), wp_salt('nonce') . gmdate('Y-m-d')), 0, 16);

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
    // Provisionen-Hierarchie (Helper)
    // ──────────────────────────────────────────

    /**
     * Liefert die effektive Provision fuer (Promoter, Event) als Array.
     * 3-Stufen-Fallback:
     *   1. Event-spezifische Zuordnung (event_id > 0, commission_value > 0)
     *   2. Globale Promoter-Zuordnung (event_id = 0, commission_value > 0)
     *   3. Settings-Default (promoter_default_commission_*)
     *
     * @return array{type: string, value: float, source: string}
     *   source: 'event' | 'global' | 'settings' | 'none'
     */
    public static function get_effective_commission($promoter_id, $event_id) {
        // Mehr-Veranstalter: nur bei erlaubten Events (sonst verdient ein Promoter an fremden Veranstaltern)
        if (self::multi() && class_exists('TIX_Promoter_DB') && $event_id > 0) {
            $p = TIX_Promoter_DB::get_promoter(intval($promoter_id));
            $explicit = (bool) TIX_Promoter_DB::get_assignment_by_promoter_event(intval($promoter_id), intval($event_id), true);
            if (!$p || !self::event_allowed($p, $event_id, $explicit)) return ['type' => 'percent', 'value' => 0, 'source' => 'none'];
        }
        if (class_exists('TIX_Promoter_DB')) {
            // 1. Event-spezifisch (nur wenn event_id > 0)
            if ($event_id > 0) {
                global $wpdb;
                $specific = $wpdb->get_row($wpdb->prepare(
                    "SELECT commission_type, commission_value FROM " . TIX_Promoter_DB::table_events() . "
                     WHERE promoter_id = %d AND event_id = %d AND status = 'active'",
                    intval($promoter_id), intval($event_id)
                ));
                if ($specific && floatval($specific->commission_value) > 0) {
                    return [
                        'type'   => $specific->commission_type ?: 'percent',
                        'value'  => floatval($specific->commission_value),
                        'source' => 'event',
                    ];
                }
            }

            // 2. Globale Promoter-Zuordnung (event_id = 0)
            $global = TIX_Promoter_DB::get_global_assignment(intval($promoter_id));
            if ($global && floatval($global->commission_value) > 0) {
                return [
                    'type'   => $global->commission_type ?: 'percent',
                    'value'  => floatval($global->commission_value),
                    'source' => 'global',
                ];
            }
        }

        // 3. Settings-Default
        $s = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        $def_val = floatval($s['promoter_default_commission_value'] ?? 0);
        if ($def_val > 0) {
            return [
                'type'   => in_array($s['promoter_default_commission_type'] ?? '', ['percent', 'fixed']) ? $s['promoter_default_commission_type'] : 'percent',
                'value'  => $def_val,
                'source' => 'settings',
            ];
        }

        return ['type' => 'percent', 'value' => 0, 'source' => 'none'];
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
            : intval(TIX_Order_Attribution::meta($order_id, '_tix_promoter_id'));
        if (!$promoter_id) return;

        // Doppelte Berechnung vermeiden
        $already = $is_wc
            ? $order->get_meta('_tix_promoter_commissions_calculated')
            : TIX_Order_Attribution::meta($order_id, '_tix_promoter_commissions_calculated');
        if ($already) return;

        $attribution = ($is_wc ? $order->get_meta('_tix_promoter_attribution') : TIX_Order_Attribution::meta($order_id, '_tix_promoter_attribution')) ?: 'referral';

        // Selbstkauf: keine Provision (Bestellung bleibt markiert, damit es nachvollziehbar ist)
        $promoter = TIX_Promoter_DB::get_promoter($promoter_id);
        if (!$promoter) return;
        if (apply_filters('tix_promoter_block_self_purchase', true) && self::is_self_purchase($promoter, $order)) {
            update_option('_tix_promoter_self_' . $order_id, $promoter_id, false);
            if ($is_wc) { $order->update_meta_data('_tix_promoter_commissions_calculated', 1); $order->save(); }
            else TIX_Order_Attribution::set_meta($order_id, '_tix_promoter_commissions_calculated', 1);
            return;
        }

        // Rabatt der Bestellung (Gutschein, Promo-Code) anteilig von den Positionen abziehen: Provision auf den bezahlten Preis.
        // Geschenkgutscheine sind Guthaben, kein Rabatt → zählen nicht.
        $share = 1.0;
        if (!$is_wc) {
            $disc = floatval($order->get_total_discount());
            $cp = get_option('_tix_order_coupon_' . $order_id);
            $code = is_array($cp) ? (string) ($cp['code'] ?? '') : '';
            $gift = false;
            if ($code !== '') foreach ((array) get_option('tix_coupons', []) as $k => $c) if (strcasecmp((string) $k, $code) === 0 && is_array($c) && ($c['discount_type'] ?? '') === 'giftcard') $gift = true;
            $items_sum = 0.0;
            foreach ($order->get_items() as $it) $items_sum += floatval(method_exists($it, 'get_total') ? $it->get_total() : 0);
            if ($disc > 0 && !$gift && $items_sum > 0) $share = max(0.0, ($items_sum - min($disc, $items_sum)) / $items_sum);
        }

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
            $line_total = round($line_total * $share, 2);

            // Effektive Provision ermitteln (3-Stufen-Hierarchie)
            $eff = self::get_effective_commission($promoter_id, $event_id);
            if ($eff['source'] === 'none') continue; // keine Provision irgendwo gesetzt → skip

            $commission = 0;
            if ($eff['type'] === 'percent') {
                $commission = $line_total * ($eff['value'] / 100);
            } elseif ($eff['type'] === 'fixed') {
                $commission = $eff['value'] * $qty;
            }

            // Discount: nur wenn echte Assignment existiert (Settings haben keinen Discount)
            $assignment = TIX_Promoter_DB::get_assignment_by_promoter_event($promoter_id, $event_id);
            $discount = 0;
            if ($assignment) {
                if ($assignment->discount_type === 'percent') {
                    $discount = $line_total * ($assignment->discount_value / 100);
                } elseif ($assignment->discount_type === 'fixed') {
                    $discount = $assignment->discount_value * $qty;
                }
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
            TIX_Order_Attribution::set_meta($order_id, '_tix_promoter_commissions_calculated', 1);
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
        // offene Provision stornieren, schon abgerechnete gegenbuchen (1.38.643; vorher blieb sie nach der Auszahlung stehen)
        TIX_Promoter_DB::reverse_order(intval($order_id), 1.0, 'cancel');
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
