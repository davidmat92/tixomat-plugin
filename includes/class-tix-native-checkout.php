<?php
/**
 * TIX Native Checkout – Eigener Checkout ohne WooCommerce
 *
 * Session-basierter Warenkorb, eigenes Checkout-Formular,
 * direkte Order-Erstellung in tix_orders/tix_order_items.
 * Payment über Mollie, PayPal oder kostenlos.
 */
if (!defined('ABSPATH')) exit;

class TIX_Native_Checkout {

    const CART_EXPIRY = 7200; // 2 Stunden

    public static function init() {

        add_action('init', [__CLASS__, 'init_cart_session'], 1);

        // AJAX: Cart-Aktionen
        add_action('wp_ajax_tix_native_add_to_cart',        [__CLASS__, 'ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_tix_native_add_to_cart', [__CLASS__, 'ajax_add_to_cart']);
        add_action('wp_ajax_tix_native_remove_item',        [__CLASS__, 'ajax_remove_item']);
        add_action('wp_ajax_nopriv_tix_native_remove_item', [__CLASS__, 'ajax_remove_item']);

        // AJAX: Cart-Aktionen
        add_action('wp_ajax_tix_native_update_qty',      [__CLASS__, 'ajax_update_qty']);
        add_action('wp_ajax_nopriv_tix_native_update_qty', [__CLASS__, 'ajax_update_qty']);

        // AJAX: Login
        add_action('wp_ajax_nopriv_tix_native_login',    [__CLASS__, 'ajax_login']);

        // AJAX: Checkout verarbeiten
        add_action('wp_ajax_tix_native_checkout',        [__CLASS__, 'ajax_process_checkout']);
        add_action('wp_ajax_nopriv_tix_native_checkout', [__CLASS__, 'ajax_process_checkout']);

        // AJAX: Coupon
        add_action('wp_ajax_tix_native_apply_coupon',        [__CLASS__, 'ajax_apply_coupon']);
        add_action('wp_ajax_nopriv_tix_native_apply_coupon', [__CLASS__, 'ajax_apply_coupon']);
        add_action('wp_ajax_tix_native_remove_coupon',        [__CLASS__, 'ajax_remove_coupon']);
        add_action('wp_ajax_nopriv_tix_native_remove_coupon', [__CLASS__, 'ajax_remove_coupon']);

        // Shortcodes — [tix_checkout] übernehmen wenn WC nicht aktiv
        add_shortcode('tix_checkout', function() { return self::render_checkout(); });
        add_shortcode('tix_native_cart', [__CLASS__, 'shortcode_cart']);

        // Thank-You Route
        add_action('template_redirect', [__CLASS__, 'handle_thankyou']);

        // Frontend Assets
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    // ──────────────────────────────────────────
    // SESSION
    // ──────────────────────────────────────────

    // ──────────────────────────────────────────
    // CART STORAGE — Datenbank-basiert, keine Sessions/Cookies nötig
    //
    // Eingeloggte User: user_meta (zuverlässigste Methode)
    // Gäste: wp_options mit IP+UA Hash (Fallback)
    // ──────────────────────────────────────────

    public static function init_cart_session() {
        if (is_user_logged_in()) return;

        // Admin-Requests brauchen keine Frontend-Session → reduziert auch
        // "Cannot modify header information" Warnings, wenn admin_notices
        // vor dem init-Hook feuern
        if (is_admin() && !wp_doing_ajax()) return;

        // REST/WP-Cron/XMLRPC auch überspringen
        if ((defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_CRON') && DOING_CRON)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) return;

        $cookie_name = 'tix_cart_session';
        if (!empty($_COOKIE[$cookie_name])) return;

        // Nur setcookie() aufrufen, wenn Headers noch nicht gesendet wurden.
        // Verhindert "Cannot modify header information"-Warnings auf Seiten,
        // die bereits Output produziert haben (z.B. durch ein anderes Plugin).
        if (headers_sent()) return;

        $session_id = wp_generate_password(32, false);
        setcookie($cookie_name, $session_id, time() + 7200, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[$cookie_name] = $session_id;
    }

    private static function cart_key() {
        if (is_user_logged_in()) {
            return 'user_' . get_current_user_id();
        }
        $cookie_name = 'tix_cart_session';
        $session_id = sanitize_text_field($_COOKIE[$cookie_name] ?? '');
        if (!$session_id) {
            // Fallback for when cookie hasn't been set yet (first request)
            $session_id = wp_generate_password(32, false);
            $_COOKIE[$cookie_name] = $session_id;
        }
        return 'guest_' . $session_id;
    }

    public static function get_cart() {
        $key = self::cart_key();
        if (str_starts_with($key, 'user_')) {
            $cart = get_user_meta(intval(substr($key, 5)), '_tix_cart', true);
        } else {
            $cart = get_transient('tix_cart_' . $key);
        }
        if (!is_array($cart)) return ['items' => [], 'coupon' => null];

        // Preise live nachziehen — falls sich Phase / Sale-Preis nach Hinzufügen geändert hat
        // (Early-Bird läuft ab, neue Phase startet, Sale aktiviert etc.)
        if (!empty($cart['items']) && class_exists('TIX_Dynamic_Pricing')) {
            foreach ($cart['items'] as &$it) {
                if (empty($it['event_id']) || !isset($it['cat_index'])) continue;
                // Locked-Price (z.B. Quote/Vorbestellung mit Sonderpreis): NICHT überschreiben
                if (!empty($it['locked_price'])) continue;
                // Bundle/Combo/Special: feste Preise, nicht nachjustieren
                if (!empty($it['meta']['bundle']) || !empty($it['meta']['combo']) || !empty($it['meta']['special'])) continue;
                $dyn = TIX_Dynamic_Pricing::get_dynamic_price(intval($it['event_id']), intval($it['cat_index']));
                if ($dyn !== null) {
                    $it['price'] = floatval($dyn);
                }
            }
            unset($it);
        }
        return $cart;
    }

    public static function save_cart($cart) {
        $key = self::cart_key();
        if (str_starts_with($key, 'user_')) {
            update_user_meta(intval(substr($key, 5)), '_tix_cart', $cart);
        } else {
            set_transient('tix_cart_' . $key, $cart, self::CART_EXPIRY);
        }
    }

    public static function clear_cart() {
        $key = self::cart_key();
        if (str_starts_with($key, 'user_')) {
            delete_user_meta(intval(substr($key, 5)), '_tix_cart');
        } else {
            delete_transient('tix_cart_' . $key);
        }
    }

    /**
     * Generiert einen eindeutigen Username aus Vor- und Nachname.
     * Format: "vorname.nachname", bei Duplikaten ".2", ".3", …
     * Umlaute werden transliteriert (ä→ae, ö→oe, ü→ue, ß→ss).
     *
     * Fallback wenn Name leer / zu kurz: Teil vor dem @ der E-Mail.
     * Letzter Fallback: "kunde_<6 random hex>".
     */
    public static function generate_username($first, $last, $email = '') {
        $first = sanitize_text_field((string) $first);
        $last  = sanitize_text_field((string) $last);

        $parts = array_filter([$first, $last], 'strlen');
        $base  = '';
        if (!empty($parts)) {
            $base = strtolower(implode('.', array_map('sanitize_title', $parts)));
        }

        // sanitize_title entfernt schon Umlaute/Sonderzeichen — aber wir wollen .-Trenner erhalten
        $base = preg_replace('/[^a-z0-9.\-]/', '', $base);
        $base = trim($base, '.-');

        // Fallback: E-Mail-Localpart
        if (strlen($base) < 2 && $email) {
            $local = strstr($email, '@', true);
            if ($local) $base = strtolower(sanitize_user($local, true));
        }

        // Absoluter Fallback
        if (strlen($base) < 2) {
            $base = 'kunde_' . substr(bin2hex(random_bytes(4)), 0, 6);
        }

        // Eindeutigkeit sicherstellen
        $username = $base;
        $i = 2;
        while (username_exists($username)) {
            $username = $base . '.' . $i;
            $i++;
            if ($i > 9999) { // Safety
                $username = $base . '.' . substr(bin2hex(random_bytes(3)), 0, 5);
                break;
            }
        }
        return $username;
    }

    public static function cart_total() {
        $cart = self::get_cart();
        $total = 0;
        foreach ($cart['items'] as $item) {
            $total += floatval($item['price']) * intval($item['qty']);
        }
        // Kundengebühren aufschlagen
        if (class_exists('TIX_Fees')) {
            $fee_data = TIX_Fees::calc_order_fees($cart['items']);
            $total += $fee_data['customer_fee_line'];
        }
        return round($total, 2);
    }

    /**
     * Gibt die Gebühren-Aufstellung für den aktuellen Warenkorb zurück.
     */
    public static function cart_fees() {
        if (!class_exists('TIX_Fees')) return null;
        $cart = self::get_cart();
        return TIX_Fees::calc_order_fees($cart['items']);
    }

    public static function cart_count() {
        $cart = self::get_cart();
        $count = 0;
        foreach ($cart['items'] as $item) {
            $count += intval($item['qty']);
        }
        return $count;
    }

    // ──────────────────────────────────────────
    // AJAX: Add to Cart
    // ──────────────────────────────────────────

    public static function ajax_add_to_cart() {
        check_ajax_referer('tix_add_to_cart', 'nonce');

        $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
        if (!is_array($items) || empty($items)) {
            wp_send_json_error(['message' => 'Keine Artikel angegeben.']);
        }

        $cart = self::get_cart();

        foreach ($items as $item) {
            $event_id  = intval($item['event_id'] ?? 0);
            $cat_index = intval($item['cat_index'] ?? $item['category_index'] ?? 0);
            $qty       = max(1, intval($item['quantity'] ?? 1));
            $is_special = !empty($item['special']);
            $special_id = intval($item['special_id'] ?? 0);

            // ─── SPECIAL: separater Pfad (kein cat_index, eigener Preis/Name) ───
            if ($is_special && $special_id && class_exists('TIX_Specials')) {
                $special_post = get_post($special_id);
                if (!$special_post || $special_post->post_status !== 'publish') continue;
                if (!$event_id) continue;

                $price = floatval(TIX_Specials::get_effective_price($special_id, $event_id));
                if ($price <= 0) continue;
                $name = sanitize_text_field($special_post->post_title);
                $event_title = get_the_title($event_id);

                // Stock prüfen
                $base_qty = intval(get_post_meta($special_id, '_tix_special_qty', true));
                $event_specials = get_post_meta($event_id, '_tix_specials', true);
                if (is_array($event_specials)) {
                    foreach ($event_specials as $es) {
                        if (intval($es['special_id'] ?? 0) === $special_id && !empty($es['qty_override'])) {
                            $base_qty = intval($es['qty_override']);
                        }
                    }
                }
                if ($base_qty > 0) {
                    $sold = TIX_Specials::get_sold_count($special_id, $event_id);
                    if ($sold + $qty > $base_qty) {
                        wp_send_json_error(['message' => 'Special "' . esc_html($name) . '" ist ausverkauft.']);
                    }
                }

                // Im Cart suchen — match per special_id+event_id (nicht cat_index)
                $found = false;
                foreach ($cart['items'] as &$ci) {
                    if (!empty($ci['meta']['special_id'])
                        && intval($ci['meta']['special_id']) === $special_id
                        && intval($ci['event_id']) === $event_id) {
                        $ci['qty'] += $qty;
                        $found = true;
                        break;
                    }
                }
                unset($ci);

                if (!$found) {
                    $cart['items'][] = [
                        'event_id'    => $event_id,
                        'cat_index'   => -1, // -1 = kein Kategorie-Slot, ist ein Special
                        'name'        => $name,
                        'event_title' => $event_title,
                        'price'       => $price,
                        'qty'         => $qty,
                        'meta'        => [
                            'special'    => 1,
                            'special_id' => $special_id,
                        ],
                    ];
                }
                continue; // Nächstes Item, Special-Pfad fertig
            }
            // ─── Ende Special-Pfad ───

            // Fallback: product_id → event_id + cat_index auflösen
            if (!$event_id && !empty($item['product_id'])) {
                $pid = intval($item['product_id']);
                $event_id = intval(get_post_meta($pid, '_tix_parent_event_id', true));
                if ($event_id) {
                    $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
                    if (is_array($cats)) {
                        foreach ($cats as $ci => $c) {
                            if (intval($c['product_id'] ?? 0) === $pid) {
                                $cat_index = $ci;
                                break;
                            }
                        }
                    }
                }
            }

            if (!$event_id) continue;

            // Ticket-Kategorie validieren
            $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
            if (!is_array($categories) || empty($categories)) continue;

            // Fallback: wenn cat_index nicht existiert, nutze ersten verfügbaren
            if (!isset($categories[$cat_index])) {
                // Versuche als 0-basiert (falls 1-basiert gesendet wurde)
                if (isset($categories[$cat_index - 1])) {
                    $cat_index = $cat_index - 1;
                } else {
                    // Letzter Fallback: erste Kategorie
                    $cat_index = array_key_first($categories);
                }
            }

            $cat = $categories[$cat_index];
            // Dynamic Pricing: Phase- oder Sale-Preis nutzen falls aktiv (Early-Bird etc.)
            // Fallback auf Basispreis wenn TIX_Dynamic_Pricing nicht geladen ist.
            if (class_exists('TIX_Dynamic_Pricing')) {
                $dyn = TIX_Dynamic_Pricing::get_dynamic_price($event_id, $cat_index);
                $price = $dyn !== null ? floatval($dyn) : floatval($cat['price'] ?? 0);
            } else {
                $price = floatval($cat['price'] ?? 0);
            }
            $name = sanitize_text_field($cat['name'] ?? 'Ticket');
            $event_title = get_the_title($event_id);

            // Prüfe ob schon im Cart → Menge erhöhen
            $found = false;
            foreach ($cart['items'] as &$ci) {
                if ($ci['event_id'] === $event_id && $ci['cat_index'] === $cat_index && empty($ci['meta']['special'])) {
                    $ci['qty'] += $qty;
                    $found = true;
                    break;
                }
            }
            unset($ci);

            if (!$found) {
                $cart['items'][] = [
                    'event_id'    => $event_id,
                    'cat_index'   => $cat_index,
                    'name'        => $name,
                    'event_title' => $event_title,
                    'price'       => $price,
                    'qty'         => $qty,
                    'meta'        => array_filter([
                        'seats'      => $item['seats'] ?? null,
                        'seatmap_id' => $item['seatmap_id'] ?? null,
                        'bundle'     => $item['bundle'] ?? null,
                        'combo'      => $item['combo'] ?? null,
                    ]),
                ];
            }
        }

        // Auto-Apply-Coupon einsetzen wenn aktiv und noch keiner im Cart ist
        self::apply_auto_coupon_if_eligible($cart);

        // Coupon-Rabatt neu berechnen (falls aktiv) — gleicher Mechanismus wie bei Update/Remove
        self::recalc_coupon_discount($cart);
        self::save_cart($cart);

        $checkout_url = self::checkout_url();

        wp_send_json_success([
            'message'      => self::cart_count() . ' Ticket(s) im Warenkorb.',
            'cart_count'   => self::cart_count(),
            'cart_total'   => self::cart_total(),
            'checkout_url' => $checkout_url,
        ]);
    }

    /**
     * Wendet einen Auto-Apply-Coupon (markiert in den Coupon-Settings) automatisch
     * auf den Warenkorb an — sofern noch kein anderer Coupon aktiv ist.
     *
     * Räumt zudem auf: wenn der bisher gespeicherte Coupon das Auto-Apply-Marker-Feld
     * trägt aber der User in den Settings deaktiviert hat, wird er nicht entfernt
     * (Kunde behält ihn manuell). Das Auto-Apply läuft NUR wenn `coupon` leer ist.
     *
     * Mutiert $cart by-reference.
     */
    private static function apply_auto_coupon_if_eligible(array &$cart) {
        // Nur wenn noch kein Coupon im Cart
        if (!empty($cart['coupon']) && !empty($cart['coupon']['code'])) return;

        // Kunde hat den Auto-Coupon explizit entfernt → nicht erneut anwenden
        if (!empty($cart['auto_coupon_dismissed'])) return;

        // Auto-Apply-Coupon aus Settings holen (filtert expired/exhausted)
        if (!class_exists('TIX_Coupons')) return;
        $coupon = TIX_Coupons::get_auto_apply_coupon();
        if (!$coupon || empty($coupon['code'])) return;

        // Discount auf Basis des aktuellen Cart-Totals berechnen
        $items_total = 0.0;
        $cart_qty    = 0;
        $event_ids   = [];
        if (!empty($cart['items']) && is_array($cart['items'])) {
            foreach ($cart['items'] as $item) {
                $qty = max(1, intval($item['qty'] ?? 1));
                $items_total += floatval($item['price'] ?? 0) * $qty;
                $cart_qty    += $qty;
                $eid = intval($item['event_id'] ?? 0);
                if ($eid) $event_ids[] = $eid;
            }
        }
        if ($items_total <= 0) return;

        // Restrictions validieren — wenn Coupon nicht passt, NICHT auto-applizieren
        $valid = TIX_Coupons::validate_against_cart($coupon, [
            'items_total' => $items_total,
            'event_ids'   => array_unique($event_ids),
        ]);
        if ($valid !== true) return; // stille Skip — Auto-Apply soll User nicht nerven mit Fehlern

        $type  = $coupon['discount_type'] ?? 'percent';
        $value = floatval($coupon['value'] ?? 0);
        switch ($type) {
            case 'fixed':              $discount = $value; break;
            case 'per_ticket_fixed':   $discount = $value * $cart_qty; break;
            case 'percent':
            case 'per_ticket_percent': $discount = $items_total * $value / 100; break;
            default:                   $discount = 0;
        }
        $discount = min($items_total, max(0, $discount));
        $max_cap = floatval($coupon['max_amount'] ?? 0);
        if ($max_cap > 0 && $discount > $max_cap) $discount = $max_cap;
        $discount = round($discount, 2);
        if ($discount <= 0) return;

        $cart['coupon'] = [
            'code'       => $coupon['code'],
            'discount'   => $discount,
            'auto'       => true, // Marker für UI: dieser wurde automatisch angewendet
        ];
    }

    /**
     * AJAX: Artikel entfernen
     */
    public static function ajax_remove_item() {
        // Akzeptiere beide Nonce-Varianten
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tix_native_checkout')) {
            check_ajax_referer('tix_add_to_cart', 'nonce');
        }
        $index = intval($_POST['index'] ?? -1);
        $cart = self::get_cart();

        if (isset($cart['items'][$index])) {
            array_splice($cart['items'], $index, 1);
            self::recalc_coupon_discount($cart);
            self::save_cart($cart);
        }

        wp_send_json_success(['cart_count' => self::cart_count(), 'cart_total' => self::cart_total()]);
    }

    /**
     * AJAX: Menge ändern (+/-)
     */
    public static function ajax_update_qty() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tix_native_checkout')) {
            check_ajax_referer('tix_add_to_cart', 'nonce');
        }
        $index = intval($_POST['index'] ?? -1);
        $delta = intval($_POST['delta'] ?? 0);
        $cart = self::get_cart();

        if (isset($cart['items'][$index])) {
            $cart['items'][$index]['qty'] = max(1, min(20, $cart['items'][$index]['qty'] + $delta));
            self::recalc_coupon_discount($cart);
            self::save_cart($cart);
        }

        wp_send_json_success([
            'cart_count' => self::cart_count(),
            'cart_total' => self::cart_total(),
        ]);
    }

    /**
     * Berechnet den Coupon-Rabatt basierend auf dem aktuellen Cart-Total neu.
     * Mutiert den übergebenen $cart by-reference. Entfernt den Coupon wenn:
     * - Cart leer ist
     * - Coupon nicht mehr existiert (gelöscht in den Settings)
     * - Coupon abgelaufen ist
     * - max_uses überschritten
     *
     * Wird nach jeder Cart-Mutation aufgerufen (Add, Update-Qty, Remove).
     */
    private static function recalc_coupon_discount(array &$cart) {
        if (empty($cart['coupon']) || empty($cart['coupon']['code'])) return;

        // Cart-Total ohne Coupon (= Summe aller Items) + Quantity (für per_ticket_*)
        $items_total = 0.0;
        $cart_qty    = 0;
        if (!empty($cart['items']) && is_array($cart['items'])) {
            foreach ($cart['items'] as $item) {
                $price = floatval($item['price'] ?? 0);
                $qty   = max(1, intval($item['qty'] ?? 1));
                $items_total += $price * $qty;
                $cart_qty    += $qty;
            }
        }

        // Cart leer → Coupon entfernen
        if ($items_total <= 0) {
            $cart['coupon'] = null;
            return;
        }

        $code = $cart['coupon']['code'];
        $coupons = get_option('tix_coupons', []);

        // Case-insensitive Lookup
        $found_key = null;
        foreach ((array) $coupons as $k => $v) {
            if (strtolower($k) === strtolower($code)) {
                $found_key = $k;
                break;
            }
        }

        if (!$found_key) {
            // Coupon-Definition existiert nicht mehr → entfernen
            $cart['coupon'] = null;
            return;
        }

        $coupon = $coupons[$found_key];

        // Expiry-Check
        if (!empty($coupon['expires'])) {
            $expires = strtotime($coupon['expires']);
            if ($expires && $expires < time()) {
                $cart['coupon'] = null;
                return;
            }
        }

        // Max-Uses-Check
        $used = intval($coupon['used'] ?? 0);
        $max_uses = intval($coupon['max_uses'] ?? 0);
        if ($max_uses > 0 && $used >= $max_uses) {
            $cart['coupon'] = null;
            return;
        }

        // Restrictions validieren — wenn ungültig (z.B. Min-Amount nicht mehr erfüllt durch Item-Entfernen)
        // → Coupon entfernen
        if (class_exists('TIX_Coupons')) {
            $event_ids = [];
            foreach ($cart['items'] as $item) {
                $eid = intval($item['event_id'] ?? 0);
                if ($eid) $event_ids[] = $eid;
            }
            $valid = TIX_Coupons::validate_against_cart($coupon, [
                'items_total' => $items_total,
                'event_ids'   => array_unique($event_ids),
            ]);
            if ($valid !== true) {
                $cart['coupon'] = null;
                return;
            }
        }

        // Rabatt neu berechnen auf Basis des aktuellen Items-Totals
        $discount_type  = $coupon['discount_type'] ?? 'percent';
        $discount_value = floatval($coupon['value'] ?? 0);

        switch ($discount_type) {
            case 'fixed':              $discount = $discount_value; break;
            case 'per_ticket_fixed':   $discount = $discount_value * $cart_qty; break;
            case 'percent':
            case 'per_ticket_percent': $discount = $items_total * $discount_value / 100; break;
            default:                   $discount = 0;
        }
        // Niemals mehr als der Cart-Total
        $discount = min($items_total, max(0, $discount));
        // max_amount-Cap (falls Coupon-Restriction setzt)
        $max_cap = floatval($coupon['max_amount'] ?? 0);
        if ($max_cap > 0 && $discount > $max_cap) $discount = $max_cap;
        $discount = round($discount, 2);

        $cart['coupon']['discount'] = $discount;
    }

    /**
     * AJAX: Login im Checkout
     */
    public static function ajax_login() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tix_native_checkout')) {
            wp_send_json_error(['message' => 'Sicherheitsprüfung fehlgeschlagen.']);
        }

        $user = sanitize_text_field($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';

        if (!$user || !$pass) {
            wp_send_json_error(['message' => 'Bitte Felder ausfüllen.']);
        }

        $creds = ['user_login' => $user, 'user_password' => $pass, 'remember' => true];
        $result = wp_signon($creds, is_ssl());

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => 'E-Mail oder Passwort ungültig.']);
        }

        wp_set_current_user($result->ID);
        wp_set_auth_cookie($result->ID, true, is_ssl());

        wp_send_json_success();
    }

    // ──────────────────────────────────────────
    // CHECKOUT URL
    // ──────────────────────────────────────────

    public static function checkout_url() {
        // Cached lookup
        static $url = null;
        if ($url !== null) return $url;

        // Check saved setting first
        $checkout_page_id = get_option('tix_checkout_page_id');
        if ($checkout_page_id && get_post_status($checkout_page_id) === 'publish') {
            $url = get_permalink($checkout_page_id);
            return $url;
        }

        global $wpdb;

        // 1) Suche im post_content (Classic/Gutenberg-Pages)
        $page_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
               AND post_content LIKE '%[tix_checkout%'
             LIMIT 1"
        );

        // 2) Fallback: Suche in Pagebuilder-Meta (Breakdance, Elementor, etc.)
        //    Shortcode kann in _breakdance_data, _elementor_data oder ähnlichen JSON-Feldern stecken.
        if (!$page_id) {
            $page_id = $wpdb->get_var(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'page' AND p.post_status = 'publish'
                   AND pm.meta_key IN ('_breakdance_data', '_elementor_data', '_et_pb_post_content', 'panels_data', '_fl_builder_data', '_oxygen_data', 'bricks_page_content_2')
                   AND pm.meta_value LIKE '%tix_checkout%'
                 LIMIT 1"
            );
        }

        if ($page_id) {
            update_option('tix_checkout_page_id', $page_id, false);
            $url = get_permalink($page_id);
            return $url;
        }

        $url = home_url('/checkout/');
        return $url;
    }

    public static function thankyou_url($order_id, $order_key) {
        return add_query_arg([
            'tix_thankyou' => 1,
            'order_id'     => $order_id,
            'key'          => $order_key,
        ], home_url('/'));
    }

    // ──────────────────────────────────────────
    // CHECKOUT FORM RENDERN (im [tix_checkout] Shortcode)
    // ──────────────────────────────────────────

    /**
     * Rendert den nativen Checkout — volle Feature-Parität mit WC-Checkout
     */
    public static function render_checkout() {
        wp_enqueue_style('tix-checkout', TIXOMAT_URL . 'assets/css/checkout.css', ['tix-google-fonts'], TIXOMAT_VERSION);

        $cart = self::get_cart();

        // Auto-Apply-Coupon einsetzen falls ein Marker-Coupon existiert und Cart noch keinen hat
        // (Greift z.B. wenn User direkt zum Checkout geht ohne erneutes Add-to-Cart)
        if (!empty($cart['items'])) {
            $cart_was_modified = empty($cart['coupon']);
            self::apply_auto_coupon_if_eligible($cart);
            if ($cart_was_modified && !empty($cart['coupon'])) {
                self::save_cart($cart);
            }
        }

        if (empty($cart['items'])) {
            $s = tix_get_settings();
            return '<div class="tix-co"><div class="tix-co-empty">'
                . '<p>' . esc_html($s['empty_text'] ?: 'Dein Warenkorb ist leer.') . '</p>'
                . '<a href="' . esc_url(home_url('/events/')) . '" class="tix-co-btn-back">'
                . esc_html($s['empty_link_text'] ?: 'Jetzt Tickets sichern') . '</a>'
                . '</div></div>';
        }

        $total = self::cart_total();

        // ── Coupon discount ──
        $coupon_discount = 0;
        $coupon_code = '';
        $coupon_auto = false;
        $coupon_badge_label = '';
        if (!empty($cart['coupon']) && !empty($cart['coupon']['discount'])) {
            $coupon_discount = round(floatval($cart['coupon']['discount']), 2);
            $coupon_code = $cart['coupon']['code'] ?? '';
            $coupon_auto = !empty($cart['coupon']['auto']);
            $total = max(0, round($total - $coupon_discount, 2));

            // Auto-Badge-Label: Coupon-Wert aus tix_coupons-Definition holen
            if ($coupon_auto && $coupon_code) {
                $all_coupons = get_option('tix_coupons', []);
                $coupon_def = is_array($all_coupons) ? ($all_coupons[$coupon_code] ?? null) : null;
                if ($coupon_def) {
                    $cv = floatval($coupon_def['value'] ?? 0);
                    $coupon_badge_label = ($coupon_def['discount_type'] ?? 'percent') === 'percent'
                        ? '−' . number_format($cv, ($cv == intval($cv)) ? 0 : 1, ',', '.') . '%'
                        : '−' . number_format($cv, 2, ',', '.') . '€';
                }
            }
        }

        // ── Gebühren für Anzeige ──
        $fee_display = self::cart_fees();
        $customer_fee_line = $fee_display ? $fee_display['customer_fee_line'] : 0;
        $fee_label = $fee_display ? $fee_display['fee_label'] : '';

        // ── Tax calculation for display ──
        $s = tix_get_settings();
        $tax_enabled   = !empty($s['tax_enabled']);
        $tax_rate      = floatval($s['tax_rate'] ?? 0);
        $tax_inclusive  = !empty($s['tax_inclusive']);
        $display_tax   = 0;
        $display_total = $total;
        if ($tax_enabled && $tax_rate > 0) {
            if ($tax_inclusive) {
                $display_tax = round($total - ($total / (1 + $tax_rate / 100)), 2);
            } else {
                $display_tax = round($total * $tax_rate / 100, 2);
                $display_total = $total + $display_tax;
            }
        }

        $is_free = ($display_total <= 0);
        $nonce = wp_create_nonce('tix_native_checkout');
        $idempotency_token = wp_generate_password(32, false);
        set_transient('tix_checkout_token_' . $idempotency_token, 1, 600); // 10 min
        $user = wp_get_current_user();
        $is_logged = is_user_logged_in();

        // Quote-Prefill: wenn der Kunde via /?tix_quote=<token> kam, hat der Cart
        // die Customer-Daten dabei — diese überschreiben die User-Defaults
        $cart_for_prefill = self::get_cart();
        $quote_customer = isset($cart_for_prefill['quote_customer']) && is_array($cart_for_prefill['quote_customer'])
            ? $cart_for_prefill['quote_customer']
            : [];
        $quote_note = $cart_for_prefill['quote_note'] ?? '';

        $prefill_first = $quote_customer['first_name'] ?? ($user->first_name ?? '');
        $prefill_last  = $quote_customer['last_name']  ?? ($user->last_name  ?? '');
        $prefill_email = $quote_customer['email']      ?? ($user->user_email ?? '');
        $prefill_phone = $quote_customer['phone']      ?? '';

        // Settings
        $use_steps     = !empty($s['checkout_steps']);
        $use_countdown = !empty($s['checkout_countdown']);
        $countdown_min = intval($s['checkout_countdown_minutes'] ?? 10);
        $show_company  = !empty($s['show_company_field']);
        $vat_text      = $s['vat_text_checkout'] ?? 'inkl. MwSt.';
        $btn_text      = $s['btn_text_checkout'] ?? 'Jetzt bestellen';
        $terms_url     = $s['terms_url'] ?? '';
        $privacy_url   = $s['privacy_url'] ?? '';
        $revocation_url = $s['revocation_url'] ?? '';

        // Gateways
        $gateways = [];
        if ($is_free) {
            $gateways[] = ['id' => 'free', 'title' => 'Kostenlos', 'icon' => ''];
        } else {
            if (TIX_Gateway_Mollie::is_available())  $gateways[] = ['id' => 'mollie', 'title' => TIX_Gateway_Mollie::get_title(), 'icon' => TIX_Gateway_Mollie::get_icon()];
            if (TIX_Gateway_PayPal::is_available())  $gateways[] = ['id' => 'paypal', 'title' => TIX_Gateway_PayPal::get_title(), 'icon' => TIX_Gateway_PayPal::get_icon()];
            if (TIX_Gateway_Bank::is_available())    $gateways[] = ['id' => 'bank', 'title' => TIX_Gateway_Bank::get_title(), 'icon' => ''];
        }

        ob_start();
        ?>
        <div class="tix-co<?php echo $use_steps ? ' tix-co-stepped' : ''; ?>">

            <?php // ── COUNTDOWN ── ?>
            <?php if ($use_countdown): ?>
            <div class="tix-co-countdown" id="tix-co-countdown" data-minutes="<?php echo $countdown_min; ?>">
                <div class="tix-co-countdown-track"><div class="tix-co-countdown-bar" id="tix-co-countdown-bar"></div></div>
                <div class="tix-co-countdown-label"><span>Verbleibende Zeit:</span><span class="tix-co-countdown-time" id="tix-co-countdown-time"><?php printf('%02d:%02d', $countdown_min, 0); ?></span></div>
            </div>
            <?php endif; ?>

            <?php // ── LOGIN ── ?>
            <?php if (!$is_logged): ?>
            <div class="tix-co-section tix-co-login-section">
                <div class="tix-co-login-toggle">
                    <span>Bereits ein Konto?</span>
                    <button type="button" class="tix-co-link-btn" onclick="document.getElementById('tix-native-login-form').style.display=this.parentNode.parentNode.querySelector('.tix-co-login-form').style.display==='none'?'':'none'">Anmelden</button>
                </div>
                <div class="tix-co-login-form" id="tix-native-login-form" style="display:none;">
                    <div class="tix-co-fields">
                        <div class="tix-co-field tix-co-field-half">
                            <label class="tix-co-label">E-Mail oder Benutzername</label>
                            <input type="text" id="tix-native-login-user" class="tix-co-input" autocomplete="username">
                        </div>
                        <div class="tix-co-field tix-co-field-half">
                            <label class="tix-co-label">Passwort</label>
                            <input type="password" id="tix-native-login-pass" class="tix-co-input" autocomplete="current-password">
                        </div>
                    </div>
                    <div style="margin-top:8px;">
                        <button type="button" class="button" id="tix-native-login-btn">Anmelden</button>
                        <span id="tix-native-login-msg" style="margin-left:8px;font-size:13px;"></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── STEPPER ── ?>
            <?php if ($use_steps): ?>
            <div class="tix-co-stepper" id="tix-co-stepper">
                <div class="tix-co-step-ind tix-co-step-active" data-step="1"><span class="tix-co-step-num">1</span><span class="tix-co-step-label">Tickets</span></div>
                <div class="tix-co-step-line"></div>
                <div class="tix-co-step-ind" data-step="2"><span class="tix-co-step-num">2</span><span class="tix-co-step-label">Adresse</span></div>
                <div class="tix-co-step-line"></div>
                <div class="tix-co-step-ind" data-step="3"><span class="tix-co-step-num">3</span><span class="tix-co-step-label">Bezahlung</span></div>
            </div>
            <?php endif; ?>

            <form id="tix-native-checkout-form" method="post">
                <input type="hidden" name="action" value="tix_native_checkout">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="idempotency_token" value="<?php echo esc_attr($idempotency_token); ?>">

                <?php // ══════════════ STEP 1: TICKETS ══════════════ ?>
                <div class="tix-co-step-panel<?php echo $use_steps ? ' tix-co-step-visible' : ''; ?>" data-step="1">
                    <div class="tix-co-section">
                        <h3 class="tix-co-heading">Deine Tickets</h3>
                        <div class="tix-co-cart">
                            <?php foreach ($cart['items'] as $i => $item):
                                $line_total = floatval($item['price']) * intval($item['qty']);
                            ?>
                                <div class="tix-co-item" data-index="<?php echo $i; ?>">
                                    <div class="tix-co-item-info">
                                        <div class="tix-co-item-name"><?php echo esc_html($item['event_title']); ?></div>
                                        <div style="font-size:0.85rem;opacity:0.7;"><?php echo esc_html($item['name']); ?></div>
                                    </div>
                                    <div class="tix-co-item-qty">
                                        <button type="button" class="tix-co-qty-btn tix-co-qty-minus" data-index="<?php echo $i; ?>" data-delta="-1">−</button>
                                        <span class="tix-co-qty-val"><?php echo intval($item['qty']); ?></span>
                                        <button type="button" class="tix-co-qty-btn tix-co-qty-plus" data-index="<?php echo $i; ?>" data-delta="1">+</button>
                                    </div>
                                    <div class="tix-co-item-price"><?php echo number_format($line_total, 2, ',', '.'); ?>&nbsp;&euro;</div>
                                    <button type="button" class="tix-co-item-remove tix-co-remove" data-index="<?php echo $i; ?>" title="Entfernen">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?php echo esc_url(get_post_type_archive_link('event') ?: home_url('/events/')); ?>" class="tix-co-btn-more">+ Weitere Tickets kaufen</a>

                        <?php // ── Coupon Input ── ?>
                        <div class="tix-co-coupon" style="margin-top:12px;display:flex;gap:8px;align-items:stretch;">
                            <input type="text" id="tix-co-coupon-input" placeholder="Gutscheincode" class="tix-co-input" style="flex:1;text-transform:uppercase;letter-spacing:1px;" value="<?php echo esc_attr($coupon_code); ?>">
                            <button type="button" id="tix-co-coupon-btn" class="tix-co-btn-more" style="margin-top:0;white-space:nowrap;">Einlösen</button>
                        </div>
                        <div id="tix-co-coupon-msg" style="font-size:13px;margin-top:4px;"></div>
                    </div>

                    <?php // Mini-Zusammenfassung ?>
                    <div class="tix-co-section">
                        <div class="tix-co-summary tix-co-summary-mini">
                            <?php if ($coupon_discount > 0): ?>
                            <div class="tix-co-summary-row tix-co-coupon-row">
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    Rabatt (<?php echo esc_html($coupon_code); ?>)
                                    <?php if ($coupon_auto && $coupon_badge_label): ?>
                                        <span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:5px;font-size:10px;font-weight:700;letter-spacing:0.04em;" title="Automatisch angewendet"><?php echo esc_html($coupon_badge_label); ?></span>
                                    <?php endif; ?>
                                    <button type="button" class="tix-co-remove-coupon" title="Gutschein entfernen" aria-label="Gutschein entfernen"
                                        style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;border:0;background:rgba(0,0,0,.08);color:#6b7280;cursor:pointer;font-size:12px;line-height:1;padding:0;transition:background .15s,color .15s;"
                                        onmouseover="this.style.background='#ef4444';this.style.color='#fff';"
                                        onmouseout="this.style.background='rgba(0,0,0,.08)';this.style.color='#6b7280';">×</button>
                                </span>
                                <span style="color:#22c55e;">-<?php echo number_format($coupon_discount, 2, ',', '.'); ?>&nbsp;&euro;</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($customer_fee_line > 0): ?>
                            <div class="tix-co-summary-row tix-co-fee-row"><span><?php echo esc_html($fee_label); ?></span><span><?php echo number_format($customer_fee_line, 2, ',', '.'); ?>&nbsp;&euro;</span></div>
                            <?php endif; ?>
                            <?php if ($display_tax > 0): ?>
                            <div class="tix-co-summary-row"><span>MwSt. (<?php echo number_format($tax_rate, 1, ',', ''); ?>%)</span><span><?php echo number_format($display_tax, 2, ',', '.'); ?>&nbsp;&euro;</span></div>
                            <?php endif; ?>
                            <div class="tix-co-summary-row tix-co-summary-total">
                                <span>Gesamt <span class="tix-co-vat-note"><?php echo esc_html($vat_text); ?></span></span>
                                <span class="tix-co-total"><?php echo number_format($display_total, 2, ',', '.'); ?>&nbsp;&euro;</span>
                            </div>
                        </div>
                    </div>

                    <?php if ($use_steps): ?>
                    <div class="tix-co-step-nav"><div></div><button type="button" class="tix-co-step-btn tix-co-step-next" data-goto="2">Weiter zur Adresse →</button></div>
                    <?php endif; ?>
                </div>

                <?php // ══════════════ STEP 2: ADRESSE ══════════════ ?>
                <div class="tix-co-step-panel" data-step="2"<?php echo !$use_steps ? '' : ' style="display:none;"'; ?>>
                    <div class="tix-co-section">
                        <h3 class="tix-co-heading">Rechnungsadresse</h3>
                        <div class="tix-co-fields">
                            <div class="tix-co-field tix-co-field-half">
                                <label class="tix-co-label">Vorname <abbr class="tix-co-req">*</abbr></label>
                                <input type="text" class="tix-co-input" name="billing_first_name" required autocomplete="given-name" value="<?php echo esc_attr($prefill_first); ?>">
                            </div>
                            <div class="tix-co-field tix-co-field-half">
                                <label class="tix-co-label">Nachname <abbr class="tix-co-req">*</abbr></label>
                                <input type="text" class="tix-co-input" name="billing_last_name" required autocomplete="family-name" value="<?php echo esc_attr($prefill_last); ?>">
                            </div>
                            <?php if ($show_company): ?>
                            <div class="tix-co-field tix-co-field-full tix-co-company-wrap">
                                <button type="button" class="tix-co-link-btn tix-co-company-toggle" onclick="this.style.display='none';this.nextElementSibling.style.display='';">+ Firma hinzufügen</button>
                                <div class="tix-co-company-field" style="display:none;">
                                    <label class="tix-co-label">Firma</label>
                                    <input type="text" class="tix-co-input" name="billing_company" autocomplete="organization" placeholder="Firmenname (optional)">
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="tix-co-field tix-co-field-full">
                                <label class="tix-co-label">Straße / Hausnummer <abbr class="tix-co-req">*</abbr></label>
                                <input type="text" class="tix-co-input" name="billing_address_1" required autocomplete="address-line1">
                            </div>
                            <div class="tix-co-field tix-co-field-third">
                                <label class="tix-co-label">PLZ <abbr class="tix-co-req">*</abbr></label>
                                <input type="text" class="tix-co-input" name="billing_postcode" required autocomplete="postal-code">
                            </div>
                            <div class="tix-co-field tix-co-field-twothirds">
                                <label class="tix-co-label">Ort <abbr class="tix-co-req">*</abbr></label>
                                <input type="text" class="tix-co-input" name="billing_city" required autocomplete="address-level2">
                            </div>
                            <div class="tix-co-field tix-co-field-full">
                                <label class="tix-co-label">Land <abbr class="tix-co-req">*</abbr></label>
                                <select class="tix-co-select" name="billing_country" required>
                                    <option value="DE" selected>Deutschland</option>
                                    <option value="AT">Österreich</option>
                                    <option value="CH">Schweiz</option>
                                    <option value="NL">Niederlande</option>
                                    <option value="BE">Belgien</option>
                                    <option value="LU">Luxemburg</option>
                                    <option value="FR">Frankreich</option>
                                    <option value="PL">Polen</option>
                                    <option value="DK">Dänemark</option>
                                    <option value="CZ">Tschechien</option>
                                    <option value="IT">Italien</option>
                                    <option value="ES">Spanien</option>
                                    <option value="GB">Vereinigtes Königreich</option>
                                </select>
                            </div>
                            <div class="tix-co-field tix-co-field-full">
                                <label class="tix-co-label">E-Mail-Adresse <abbr class="tix-co-req">*</abbr></label>
                                <input type="email" class="tix-co-input" name="billing_email" required autocomplete="email" value="<?php echo esc_attr($prefill_email); ?>">
                            </div>
                            <div class="tix-co-field tix-co-field-full">
                                <label class="tix-co-label">Telefon</label>
                                <input type="tel" class="tix-co-input" name="billing_phone" autocomplete="tel" value="<?php echo esc_attr($prefill_phone); ?>">
                            </div>
                        </div>

                        <?php if (!$is_logged): ?>
                        <div class="tix-co-create-account">
                            <label class="tix-co-check-label">
                                <input type="checkbox" name="createaccount" class="tix-co-check" value="1">
                                <span class="tix-co-check-custom"></span>
                                <span>Konto anlegen — meine Daten für die nächste Buchung speichern</span>
                            </label>
                            <p class="tix-co-create-account-hint">
                                Nach deiner Bestellung bekommst du eine E-Mail an <strong>diese Adresse</strong>, um dein Passwort zu vergeben und dein Konto zu aktivieren. Danach kannst du jederzeit auf deine Tickets zugreifen.
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($use_steps): ?>
                    <div class="tix-co-step-nav">
                        <button type="button" class="tix-co-step-btn tix-co-step-back" data-goto="1">← Zurück</button>
                        <button type="button" class="tix-co-step-btn tix-co-step-next" data-goto="3">Weiter zur Zahlung →</button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php // ══════════════ STEP 3: ZAHLUNG ══════════════ ?>
                <div class="tix-co-step-panel" data-step="3"<?php echo !$use_steps ? '' : ' style="display:none;"'; ?>>

                    <?php // Versand ?>
                    <div class="tix-co-section">
                        <h3 class="tix-co-heading">Versandmethode</h3>
                        <div class="tix-co-shipping-info">
                            <span class="tix-co-shipping-icon">✉</span>
                            <span><?php echo esc_html($s['shipping_text'] ?? 'Kostenloser Versand per E-Mail'); ?></span>
                        </div>
                    </div>

                    <?php // Zahlungsart ?>
                    <?php if (!$is_free && !empty($gateways)): ?>
                    <div class="tix-co-section">
                        <h3 class="tix-co-heading">Zahlungsart</h3>
                        <div class="tix-co-gateways">
                            <?php foreach ($gateways as $gi => $gw): ?>
                                <div class="tix-co-gateway <?php echo $gi === 0 ? 'tix-co-gw-active' : ''; ?>">
                                    <label class="tix-co-gw-label">
                                        <input type="radio" name="payment_method" value="<?php echo esc_attr($gw['id']); ?>" class="tix-co-gw-radio" <?php checked($gi, 0); ?>>
                                        <span class="tix-co-gw-radio-custom"></span>
                                        <span class="tix-co-gw-title"><?php echo esc_html($gw['title']); ?></span>
                                        <?php if ($gw['icon']): ?><span class="tix-co-gw-icon"><img src="<?php echo esc_url($gw['icon']); ?>" alt=""></span><?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="payment_method" value="free">
                    <?php endif; ?>

                    <?php // Volle Zusammenfassung ?>
                    <div class="tix-co-section">
                        <div class="tix-co-summary">
                            <div class="tix-co-summary-row"><span>Zwischensumme</span><span><?php echo number_format(self::cart_total(), 2, ',', '.'); ?>&nbsp;&euro;</span></div>
                            <?php if ($coupon_discount > 0): ?>
                            <div class="tix-co-summary-row tix-co-coupon-row">
                                <span style="display:inline-flex;align-items:center;gap:6px;">
                                    Rabatt (<?php echo esc_html($coupon_code); ?>)
                                    <?php if ($coupon_auto && $coupon_badge_label): ?>
                                        <span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:5px;font-size:10px;font-weight:700;letter-spacing:0.04em;" title="Automatisch angewendet"><?php echo esc_html($coupon_badge_label); ?></span>
                                    <?php endif; ?>
                                    <button type="button" class="tix-co-remove-coupon" title="Gutschein entfernen" aria-label="Gutschein entfernen"
                                        style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;border:0;background:rgba(0,0,0,.08);color:#6b7280;cursor:pointer;font-size:12px;line-height:1;padding:0;transition:background .15s,color .15s;"
                                        onmouseover="this.style.background='#ef4444';this.style.color='#fff';"
                                        onmouseout="this.style.background='rgba(0,0,0,.08)';this.style.color='#6b7280';">×</button>
                                </span>
                                <span style="color:#22c55e;">-<?php echo number_format($coupon_discount, 2, ',', '.'); ?>&nbsp;&euro;</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($customer_fee_line > 0): ?>
                            <div class="tix-co-summary-row tix-co-fee-row"><span><?php echo esc_html($fee_label); ?></span><span><?php echo number_format($customer_fee_line, 2, ',', '.'); ?>&nbsp;&euro;</span></div>
                            <?php endif; ?>
                            <?php if ($display_tax > 0): ?>
                            <div class="tix-co-summary-row"><span>MwSt. (<?php echo number_format($tax_rate, 1, ',', ''); ?>%)</span><span><?php echo number_format($display_tax, 2, ',', '.'); ?>&nbsp;&euro;</span></div>
                            <?php endif; ?>
                            <div class="tix-co-summary-row tix-co-summary-total">
                                <span>Gesamt <span class="tix-co-vat-note"><?php echo esc_html($vat_text); ?></span></span>
                                <span class="tix-co-total"><?php echo number_format($display_total, 2, ',', '.'); ?>&nbsp;&euro;</span>
                            </div>
                        </div>

                        <?php // Rechtliches ?>
                        <div class="tix-co-legal">
                            <h4 class="tix-co-legal-heading">Rechtliches</h4>
                            <div class="tix-co-legal-checks">
                                <label class="tix-co-check-label">
                                    <input type="checkbox" name="accept_terms" class="tix-co-check" required>
                                    <span class="tix-co-check-custom"></span>
                                    <span>Ich akzeptiere die <?php if ($terms_url): ?><a href="<?php echo esc_url($terms_url); ?>" target="_blank" class="tix-co-legal-link">Nutzungsbedingungen</a><?php else: ?><u>Nutzungsbedingungen</u><?php endif; ?>.</span>
                                </label>
                                <p class="tix-co-legal-note">Bitte beachte auch die <?php if ($privacy_url): ?><a href="<?php echo esc_url($privacy_url); ?>" target="_blank" class="tix-co-legal-link">Datenschutzhinweise</a><?php else: ?><u>Datenschutzhinweise</u><?php endif; ?><?php if ($revocation_url): ?> und die <a href="<?php echo esc_url($revocation_url); ?>" target="_blank" class="tix-co-legal-link">Widerrufsbelehrung</a><?php endif; ?>.</p>
                            </div>
                        </div>

                        <?php // Newsletter ?>
                        <?php if (!empty($s['newsletter_enabled'])):
                            $nl_type = $s['newsletter_type'] ?? 'email';
                            $nl_label = $s['newsletter_label'] ?: ($nl_type === 'whatsapp' ? 'Ich möchte den WhatsApp-Newsletter erhalten' : 'Ich möchte den Newsletter per E-Mail erhalten');
                        ?>
                        <div class="tix-co-newsletter">
                            <h4 class="tix-co-newsletter-heading">Newsletter</h4>
                            <label class="tix-co-check-label">
                                <input type="checkbox" name="tix_newsletter_optin" class="tix-co-check" value="1">
                                <span class="tix-co-check-custom"></span>
                                <span><?php echo esc_html($nl_label); ?></span>
                            </label>
                            <?php if (!empty($s['newsletter_legal'])): ?>
                                <p class="tix-co-newsletter-legal"><?php echo esc_html($s['newsletter_legal']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($use_steps): ?>
                        <div class="tix-co-step-nav" style="margin-bottom:16px;">
                            <button type="button" class="tix-co-step-btn tix-co-step-back" data-goto="2">← Zurück</button><div></div>
                        </div>
                        <?php endif; ?>

                        <button type="submit" class="tix-co-submit" id="tix-native-pay-btn">
                            <span class="tix-co-submit-text">
                                <?php if ($is_free): ?>
                                    Kostenlos bestellen
                                <?php else: ?>
                                    <?php echo esc_html($btn_text); ?> &middot; <span class="tix-co-submit-price"><?php echo number_format($display_total, 2, ',', '.'); ?>&nbsp;&euro;</span>
                                <?php endif; ?>
                            </span>
                            <span class="tix-co-submit-loading" style="display:none;">Bestellung wird verarbeitet&hellip;</span>
                        </button>
                    </div>
                </div>

                <div id="tix-native-checkout-error" class="tix-co-message tix-co-msg-error" style="display:none;"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    // ──────────────────────────────────────────
    // AJAX: Checkout verarbeiten
    // ──────────────────────────────────────────

    public static function ajax_process_checkout() {
        check_ajax_referer('tix_native_checkout', 'nonce');

        // Rate-Limit: max 10 Checkout-Versuche pro 5 Minuten pro IP
        if (class_exists('TIX_Rate_Limit')) {
            TIX_Rate_Limit::check('native_checkout', 10, 300, 'ajax');
        }

        // Idempotency: Prevent double-submit
        $token = sanitize_text_field($_POST['idempotency_token'] ?? '');
        if (!$token || !get_transient('tix_checkout_token_' . $token)) {
            wp_send_json_error(['message' => 'Bestellung wurde bereits verarbeitet. Bitte Seite neu laden.']);
        }
        delete_transient('tix_checkout_token_' . $token);

        $cart = self::get_cart();
        if (empty($cart['items'])) {
            wp_send_json_error(['message' => 'Warenkorb ist leer.']);
        }

        // Pflichtfelder validieren
        $first_name = sanitize_text_field($_POST['billing_first_name'] ?? '');
        $last_name  = sanitize_text_field($_POST['billing_last_name'] ?? '');
        $email      = sanitize_email($_POST['billing_email'] ?? '');

        if (!$first_name || !$last_name) {
            wp_send_json_error(['message' => 'Bitte Vor- und Nachname angeben.']);
        }
        if (!$email || !is_email($email)) {
            wp_send_json_error(['message' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
        }

        // Account creation for guests — Checkbox „Konto anlegen"
        $create_account     = !is_user_logged_in() && !empty($_POST['createaccount']);
        $created_user_id    = 0; // speichern für spätere Activation-Mail
        $existing_user_id   = 0; // falls User schon existiert (Rolle evtl. anpassen)
        if ($create_account && $email) {
            if (!email_exists($email)) {
                // Save guest cart before switching user context
                $guest_cart = self::get_cart();

                // Username aus Vor- + Nachname generieren (statt E-Mail)
                $username = self::generate_username($first_name, $last_name, $email);

                // Random-Passwort als Platzhalter — User setzt eigenes via Activation-Email
                $password = wp_generate_password(24, true, true);
                $user_id  = wp_create_user($username, $password, $email);
                if (!is_wp_error($user_id)) {
                    wp_update_user([
                        'ID'           => $user_id,
                        'first_name'   => $first_name,
                        'last_name'    => $last_name,
                        'display_name' => trim($first_name . ' ' . $last_name) ?: $username,
                    ]);

                    // Kunden-Rolle zuweisen (statt Default „subscriber")
                    if (class_exists('TIX_Customer_Role')) {
                        TIX_Customer_Role::assign_to_user($user_id);
                    }

                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id, true, is_ssl());

                    // Migrate guest cart to new user
                    if (!empty($guest_cart['items'])) {
                        self::save_cart($guest_cart);
                    }

                    $created_user_id = $user_id;
                }
            } else {
                // User existiert bereits — ggf. auf Kunden-Rolle heben wenn noch subscriber
                $existing_user = get_user_by('email', $email);
                if ($existing_user && class_exists('TIX_Customer_Role')) {
                    TIX_Customer_Role::assign_to_user($existing_user->ID);
                    $existing_user_id = $existing_user->ID;
                }
            }
        }

        $payment_method = sanitize_text_field($_POST['payment_method'] ?? 'free');
        $total = self::cart_total();

        // Server-side price validation: use dynamic pricing (phases, sale prices)
        $validated_total = 0;
        foreach ($cart['items'] as &$cart_item) {
            $event_id  = intval($cart_item['event_id'] ?? 0);
            $cat_index = intval($cart_item['cat_index'] ?? 0);

            // Locked-Price (Vorbestellung/Quote mit Sonderpreis) → übernehmen, nicht überschreiben
            if (!empty($cart_item['locked_price'])) {
                $validated_total += floatval($cart_item['price']) * intval($cart_item['qty']);
                continue;
            }

            // Use dynamic pricing if available (respects phases + sale prices)
            if (class_exists('TIX_Dynamic_Pricing')) {
                $dynamic_price = TIX_Dynamic_Pricing::get_dynamic_price($event_id, $cat_index);
                if ($dynamic_price !== null) {
                    $cart_item['price'] = $dynamic_price;
                    $validated_total += $dynamic_price * intval($cart_item['qty']);
                    continue;
                }
            }

            // Fallback: read base price from ticket categories
            $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
            if (is_array($categories) && isset($categories[$cat_index])) {
                $actual_price = floatval($categories[$cat_index]['price'] ?? 0);
                $cart_item['price'] = $actual_price;
            }
            $validated_total += floatval($cart_item['price']) * intval($cart_item['qty']);
        }
        unset($cart_item);
        $total = round($validated_total, 2);

        // Stock validation — prevent overselling
        foreach ($cart['items'] as $cart_item) {
            $event_id  = intval($cart_item['event_id'] ?? 0);
            $cat_index = intval($cart_item['cat_index'] ?? 0);
            $qty       = intval($cart_item['qty']);

            if (get_post_status($event_id) !== 'publish') {
                wp_send_json_error(['message' => 'Event "' . get_the_title($event_id) . '" ist nicht mehr verfügbar.']);
            }

            $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
            if (!is_array($categories) || !isset($categories[$cat_index])) {
                wp_send_json_error(['message' => 'Ticket-Kategorie nicht mehr verfügbar.']);
            }

            $cat = $categories[$cat_index];
            $stock = isset($cat['stock']) ? intval($cat['stock']) : -1; // -1 = unlimited
            if ($stock >= 0 && $qty > $stock) {
                $name = $cat['name'] ?? 'Ticket';
                wp_send_json_error(['message' => esc_html($name) . ': Nur noch ' . $stock . ' verfügbar.']);
            }
        }

        // Order erstellen
        $order_id = self::create_order([
            'billing_first_name' => $first_name,
            'billing_last_name'  => $last_name,
            'billing_email'      => $email,
            'billing_phone'      => sanitize_text_field($_POST['billing_phone'] ?? ''),
            'billing_company'    => sanitize_text_field($_POST['billing_company'] ?? ''),
            'billing_address_1'  => sanitize_text_field($_POST['billing_address_1'] ?? ''),
            'billing_city'       => sanitize_text_field($_POST['billing_city'] ?? ''),
            'billing_postcode'   => sanitize_text_field($_POST['billing_postcode'] ?? ''),
            'billing_country'    => sanitize_text_field($_POST['billing_country'] ?? 'DE'),
            'payment_method'     => $payment_method,
            'total'              => $total,
            'items'              => $cart['items'],
        ]);

        if (!$order_id) {
            wp_send_json_error(['message' => 'Bestellung konnte nicht erstellt werden.']);
        }

        // ── Kontoaktivierung per Email (nur wenn frisch angelegt) ──
        if ($created_user_id && class_exists('TIX_Account_Activation')) {
            TIX_Account_Activation::trigger_activation($created_user_id, $order_id);
        }

        // Newsletter opt-in
        if (!empty($_POST['tix_newsletter_optin'])) {
            update_option('_tix_order_newsletter_' . $order_id, 1, false);
            do_action('tix_newsletter_optin', $order_id, $email, $first_name, $last_name);
        }

        // Payment verarbeiten
        if ($total <= 0 || $payment_method === 'free') {
            $result = TIX_Gateway_Free::process($order_id);
        } elseif ($payment_method === 'mollie') {
            $result = TIX_Gateway_Mollie::process($order_id);
        } elseif ($payment_method === 'paypal') {
            $result = TIX_Gateway_PayPal::process($order_id);
        } elseif ($payment_method === 'bank') {
            $result = TIX_Gateway_Bank::process($order_id);
        } else {
            wp_send_json_error(['message' => 'Unbekannte Zahlungsart.']);
            return;
        }

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
            return;
        }

        if (isset($result['redirect'])) {
            wp_send_json_success(['redirect' => $result['redirect']]);
        } else {
            // Direkt zur Thank-You-Seite (z.B. bei kostenlos)
            global $wpdb;
            $order = $wpdb->get_row($wpdb->prepare(
                "SELECT order_key FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
            ));
            wp_send_json_success([
                'redirect' => self::thankyou_url($order_id, $order->order_key ?? ''),
            ]);
        }
    }

    // ──────────────────────────────────────────
    // ORDER ERSTELLEN (direkt in tix_orders)
    // ──────────────────────────────────────────

    public static function create_order($data) {
        global $wpdb;
        $t  = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';

        $order_number = TIX_Order::next_order_number();
        $order_key = 'tix_' . wp_generate_password(16, false);

        // ── Coupon / Discount ──
        $cart = self::get_cart();
        $coupon_discount = 0;
        if (!empty($cart['coupon']) && !empty($cart['coupon']['discount'])) {
            $coupon_discount = round(floatval($cart['coupon']['discount']), 2);

            // Re-validate coupon before applying
            $coupon_code = $cart['coupon']['code'] ?? '';
            if ($coupon_code) {
                $coupons = get_option('tix_coupons', []);
                $coupon = null;
                foreach ($coupons as $k => $v) {
                    if (strtolower($k) === strtolower($coupon_code)) { $coupon = $v; $coupon_code = $k; break; }
                }
                if (!$coupon) {
                    $coupon_discount = 0; // Coupon no longer exists
                } else {
                    // Check expiry
                    if (!empty($coupon['expires']) && strtotime($coupon['expires']) < time()) {
                        $coupon_discount = 0;
                    }
                    // Check max uses
                    $used = intval($coupon['used'] ?? 0);
                    $max_uses = intval($coupon['max_uses'] ?? 0);
                    if ($max_uses > 0 && $used >= $max_uses) {
                        $coupon_discount = 0;
                    }
                }
            }

            $data['total'] = max(0, round($data['total'] - $coupon_discount, 2));

            if ($coupon_code && $coupon_discount > 0) {
                // Re-read coupons to get latest state (minimize race window)
                wp_cache_delete('tix_coupons', 'options');
                $coupons = get_option('tix_coupons', []);
                if (isset($coupons[$coupon_code])) {
                    $max_uses = intval($coupons[$coupon_code]['max_uses'] ?? 0);
                    $used = intval($coupons[$coupon_code]['used'] ?? 0);
                    if ($max_uses > 0 && $used >= $max_uses) {
                        // Coupon hit limit between validation and now — still apply
                    }
                    $coupons[$coupon_code]['used'] = $used + 1;
                    update_option('tix_coupons', $coupons);
                }
            }
        }

        // ── Gebühren / Provisionen ──
        $fee_data = null;
        if (class_exists('TIX_Fees')) {
            $fee_data = TIX_Fees::calc_order_fees($cart['items']);
            // Kundengebühren auf Total aufschlagen
            if ($fee_data['customer_fee_line'] > 0) {
                $data['total'] = round($data['total'] + $fee_data['customer_fee_line'], 2);
            }
        }

        // ── Tax Calculation ──
        $s = tix_get_settings();
        $tax_enabled   = !empty($s['tax_enabled']);
        $tax_rate      = floatval($s['tax_rate'] ?? 0);
        $tax_inclusive  = !empty($s['tax_inclusive']);

        $subtotal = $data['total'];
        $tax = 0;
        if ($tax_enabled && $tax_rate > 0) {
            if ($tax_inclusive) {
                // Preis enthält MwSt → herausrechnen
                $tax = round($subtotal - ($subtotal / (1 + $tax_rate / 100)), 2);
            } else {
                // MwSt kommt oben drauf
                $tax = round($subtotal * $tax_rate / 100, 2);
                $subtotal = $data['total'];
                $data['total'] = $subtotal + $tax;
            }
        }

        $wpdb->insert($t, [
            'order_number'          => $order_number,
            'status'                => 'pending',
            'total'                 => $data['total'],
            'subtotal'              => $subtotal,
            'tax'                   => $tax,
            'discount'              => $coupon_discount,
            'payment_method'        => $data['payment_method'],
            'payment_method_title'  => self::gateway_title($data['payment_method']),
            'billing_first_name'    => $data['billing_first_name'],
            'billing_last_name'     => $data['billing_last_name'],
            'billing_email'         => $data['billing_email'],
            'billing_phone'         => $data['billing_phone'] ?? '',
            'billing_company'       => $data['billing_company'] ?? '',
            'billing_address_1'     => $data['billing_address_1'] ?? '',
            'billing_address_2'     => '',
            'billing_city'          => $data['billing_city'] ?? '',
            'billing_postcode'      => $data['billing_postcode'] ?? '',
            'billing_country'       => $data['billing_country'] ?? 'DE',
            'customer_id'           => get_current_user_id(),
            'wc_order_id'           => 0, // Kein WC-Pendant
            'order_key'             => $order_key,
            'date_created'          => current_time('mysql'),
        ]);

        $order_id = $wpdb->insert_id;
        if (!$order_id) return null;

        // ── Coupon-Note für Audit-Trail + one_per_email-Check ──
        if (!empty($coupon_code) && $coupon_discount > 0 && class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note(
                $order_id,
                sprintf('🎟️ Gutschein "%s" eingelöst — Rabatt %s €',
                    $coupon_code,
                    number_format($coupon_discount, 2, ',', '.')
                ),
                'coupon'
            );
        }

        // ── Gebühren-Daten als Order-Meta speichern ──
        if ($fee_data) {
            update_option('_tix_order_fees_' . $order_id, [
                'platform_fee'      => $fee_data['platform_fee'],
                'platform_fee_mode' => $fee_data['platform_fee_mode'],
                'gateway_fee'       => $fee_data['gateway_fee'],
                'gateway_fee_mode'  => $fee_data['gateway_fee_mode'],
                'customer_fee_line' => $fee_data['customer_fee_line'],
                'organizer_payout'  => $fee_data['organizer_payout'],
                'fee_label'         => $fee_data['fee_label'],
            ], false);
        }

        // Save campaign tracking data from cookie
        $cookie_raw = class_exists('TIX_Campaign_Tracking') ? ($_COOKIE[TIX_Campaign_Tracking::COOKIE_NAME] ?? '') : '';
        if (!empty($cookie_raw)) {
            $cookie = json_decode(stripslashes(urldecode($cookie_raw)), true);
            if (is_array($cookie)) {
                $campaign_source  = sanitize_key($cookie['src'] ?? '');
                $campaign_name    = sanitize_text_field($cookie['camp'] ?? '');
                $campaign_content = sanitize_text_field($cookie['content'] ?? '');
                if ($campaign_source) {
                    update_option('_tix_order_campaign_' . $order_id, [
                        'source'  => $campaign_source,
                        'name'    => $campaign_name,
                        'content' => $campaign_content,
                    ], false);
                }
            }
        }

        // Save group booking data if available
        $group_key = 'tix_group_order_data_' . get_current_user_id() . '_' . wp_get_session_token();
        $group_data = get_transient($group_key);
        if ($group_data) {
            update_option('_tix_order_group_data_' . $order_id, $group_data, false);
            delete_transient($group_key);
        }

        // Order Items
        foreach ($data['items'] as $item) {
            $event_id  = intval($item['event_id']);
            $cat_index = intval($item['cat_index']);
            $qty       = intval($item['qty']);
            $price     = floatval($item['price']);

            // Product-ID ermitteln (für Kompatibilität mit Ticket-System)
            $product_id = 0;
            $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
            if (is_array($categories) && isset($categories[$cat_index])) {
                $product_id = intval($categories[$cat_index]['product_id'] ?? 0);
            }

            $wpdb->insert($ti, [
                'order_id'   => $order_id,
                'product_id' => $product_id,
                'event_id'   => $event_id,
                'quantity'   => $qty,
                'total'      => round($price * $qty, 2),
                'tax'        => 0,
                'name'       => sanitize_text_field(($item['event_title'] ?? '') . ' – ' . ($item['name'] ?? '')),
                'cat_name'   => sanitize_text_field($item['name'] ?? ''),
                'meta'       => !empty($item['meta']) ? json_encode($item['meta']) : null,
            ]);
        }

        // Decrement stock for each ticket category (cache-busted for freshness)
        // Specials (cat_index === -1) haben eigenen Stock — der wird via get_sold_count berechnet,
        // nicht über _tix_ticket_categories. Daher hier überspringen.
        foreach ($data['items'] as $item) {
            $event_id  = intval($item['event_id']);
            $cat_index = intval($item['cat_index']);
            $qty       = intval($item['qty']);
            if ($cat_index < 0) continue; // Special / Bundle / Combo / Custom-Item

            wp_cache_delete($event_id, 'post_meta');
            $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
            if (is_array($categories) && isset($categories[$cat_index])) {
                $current_stock = isset($categories[$cat_index]['stock']) ? intval($categories[$cat_index]['stock']) : -1;
                if ($current_stock >= 0) {
                    $categories[$cat_index]['stock'] = max(0, $current_stock - $qty);
                    update_post_meta($event_id, '_tix_ticket_categories', $categories);
                }
            }
        }

        // ── Hook für nachgelagerte Module (z.B. Campaign-Tracking, Newsletter, etc.) ──
        // Wird gefeuert bevor die Order via Gateway bezahlt wird, damit die Quelle
        // im Order-Meta gespeichert ist sobald der Status auf "completed" geht.
        do_action('tix_native_order_created', $order_id, $data, $cart);

        return $order_id;
    }

    private static function gateway_title($id) {
        $titles = [
            'free'   => 'Kostenlos',
            'mollie' => 'Online bezahlen (Mollie)',
            'paypal' => 'PayPal',
            'bank'   => 'Banküberweisung (Vorkasse)',
        ];
        return $titles[$id] ?? $id;
    }

    // ──────────────────────────────────────────
    // COUPON SYSTEM
    // ──────────────────────────────────────────

    public static function ajax_apply_coupon() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tix_native_checkout')) {
            check_ajax_referer('tix_add_to_cart', 'nonce');
        }

        $code = strtolower(trim(sanitize_text_field($_POST['coupon_code'] ?? '')));
        if (!$code) {
            wp_send_json_error(['message' => 'Bitte einen Gutscheincode eingeben.']);
        }

        $coupons = get_option('tix_coupons', []);
        // Case-insensitive lookup
        $found_key = null;
        foreach ($coupons as $k => $v) {
            if (strtolower($k) === $code) {
                $found_key = $k;
                break;
            }
        }

        // Wenn KEIN tix_coupons-Eintrag gefunden → Promoter-Code prüfen
        if (!$found_key && class_exists('TIX_Promoter_DB')) {
            $assignment = TIX_Promoter_DB::get_assignment_by_promo_code($code);
            if ($assignment && !empty($assignment->discount_type) && floatval($assignment->discount_value) > 0) {
                // Synthetischen Coupon erstellen — wird nicht in tix_coupons persistiert,
                // sondern temporär für diese Order verwendet
                $found_key = strtoupper($code);
                $coupon = [
                    'code'              => $found_key,
                    'discount_type'     => $assignment->discount_type,           // percent | fixed
                    'value'             => floatval($assignment->discount_value),
                    'expires'           => '',
                    'max_uses'          => 0,
                    'used'              => 0,
                    'description'       => 'Promoter-Code',
                    'is_promoter'       => true,
                    'promoter_id'       => intval($assignment->promoter_id),
                    'event_id'          => intval($assignment->event_id),
                ];
            }
        }

        if (!$found_key) {
            wp_send_json_error(['message' => 'Gutscheincode ungültig.']);
        }

        // Wenn aus tix_coupons → wie bisher; sonst: $coupon ist schon oben gesetzt
        if (!isset($coupon)) {
            $coupon = $coupons[$found_key];
        }

        // Check expiry
        if (!empty($coupon['expires'])) {
            $expires = strtotime($coupon['expires']);
            if ($expires && $expires < time()) {
                wp_send_json_error(['message' => 'Dieser Gutschein ist abgelaufen.']);
            }
        }

        // Check max uses
        $used = intval($coupon['used'] ?? 0);
        $max_uses = intval($coupon['max_uses'] ?? 0);
        if ($max_uses > 0 && $used >= $max_uses) {
            wp_send_json_error(['message' => 'Dieser Gutschein wurde bereits eingelöst.']);
        }

        // Restrictions validieren (Min/Max-Amount, Allowed/Excluded Events + Categories, one_per_email)
        $cart = self::get_cart();
        $cart_total = self::cart_total();
        $event_ids = [];
        if (!empty($cart['items']) && is_array($cart['items'])) {
            foreach ($cart['items'] as $item) {
                $eid = intval($item['event_id'] ?? 0);
                if ($eid) $event_ids[] = $eid;
            }
        }
        // Email für one_per_email aus eingeloggtem User holen (falls vorhanden)
        $email = '';
        if (is_user_logged_in()) {
            $u = wp_get_current_user();
            if ($u && $u->user_email) $email = $u->user_email;
        }
        if (class_exists('TIX_Coupons')) {
            $valid = TIX_Coupons::validate_against_cart($coupon, [
                'items_total' => $cart_total,
                'event_ids'   => array_unique($event_ids),
                'email'       => $email,
            ]);
            if ($valid !== true) {
                wp_send_json_error(['message' => $valid]);
            }
        }

        // Cart-Quantity ermitteln (für per_ticket_*-Discounts)
        $cart_qty = 0;
        if (!empty($cart['items']) && is_array($cart['items'])) {
            foreach ($cart['items'] as $item) {
                $cart_qty += max(1, intval($item['qty'] ?? $item['quantity'] ?? 1));
            }
        }

        // Calculate discount
        $discount_type = $coupon['discount_type'] ?? 'percent';
        $discount_value = floatval($coupon['value'] ?? 0);

        switch ($discount_type) {
            case 'percent':
                // X% auf Cart-Gesamtbetrag
                $discount = $cart_total * $discount_value / 100;
                break;
            case 'fixed':
                // X € pauschal vom Cart
                $discount = $discount_value;
                break;
            case 'per_ticket_percent':
                // X% auf jedes Ticket — math gleich wie 'percent', aber semantisch klarer
                // (für Communication "15% pro Ticket" statt "15% auf den Warenkorb")
                $discount = $cart_total * $discount_value / 100;
                break;
            case 'per_ticket_fixed':
                // X € pro Ticket × Anzahl Tickets im Cart
                $discount = $discount_value * $cart_qty;
                break;
            default:
                $discount = 0;
        }

        // Niemals mehr als der Cart-Total
        $discount = min($cart_total, max(0, $discount));
        // max_amount-Cap (falls Coupon-Restriction setzt)
        $max_cap = floatval($coupon['max_amount'] ?? 0);
        if ($max_cap > 0 && $discount > $max_cap) $discount = $max_cap;
        $discount = round($discount, 2);

        // Apply to cart
        $cart['coupon'] = [
            'code'     => $found_key,
            'discount' => $discount,
        ];
        self::save_cart($cart);

        $new_total = max(0, round($cart_total - $discount, 2));

        wp_send_json_success([
            'message'   => 'Gutschein eingelöst! Rabatt: ' . number_format($discount, 2, ',', '.') . ' €',
            'discount'  => $discount,
            'new_total' => $new_total,
            'code'      => $found_key,
        ]);
    }

    /**
     * AJAX: Gutschein vom Cart entfernen
     */
    public static function ajax_remove_coupon() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tix_native_checkout')) {
            check_ajax_referer('tix_add_to_cart', 'nonce');
        }
        $cart = self::get_cart();
        // War's ein Auto-Apply-Coupon? Dann markieren dass Kunde ihn entfernt hat —
        // verhindert dass apply_auto_coupon_if_eligible() ihn sofort wieder reinpackt
        if (!empty($cart['coupon']['auto'])) {
            $cart['auto_coupon_dismissed'] = true;
        }
        $cart['coupon'] = null;
        self::save_cart($cart);
        wp_send_json_success([
            'message'   => 'Gutschein entfernt.',
            'new_total' => self::cart_total(),
        ]);
    }

    // ──────────────────────────────────────────
    // ORDER STATUS UPDATE + HOOKS
    // ──────────────────────────────────────────

    public static function update_order_status($order_id, $new_status, $gateway = '') {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';

        $old_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $t WHERE id = %d", $order_id));

        // ── DOWNGRADE-PROTECTION ──
        // Schützt zentral vor versehentlichen Downgrades durch Gateway-Race-Conditions.
        // Bezahlte Bestellungen (completed/processing) dürfen nicht durch automatische Hooks
        // auf cancelled/failed/refunded gesetzt werden — nur durch Admin-Aktionen.
        // Admin-Aktionen kommen ohne $gateway-Parameter (oder mit gateway='admin').
        $is_automated = !empty($gateway) && $gateway !== 'admin';
        if ($is_automated
            && in_array($old_status, ['completed', 'processing'], true)
            && in_array($new_status, ['cancelled', 'failed', 'refunded'], true)) {
            if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
                TIX_Order_Admin::add_note(
                    $order_id,
                    '🛡️ Status-Downgrade abgewiesen: ' . $old_status . ' → ' . $new_status . ' (von ' . $gateway . '). Bezahlte Bestellungen können nur manuell vom Admin storniert/erstattet werden.',
                    'system'
                );
            }
            return false;
        }

        $wpdb->update($t, ['status' => $new_status], ['id' => $order_id]);

        // Auto-add order note on status change
        if ($old_status !== $new_status && class_exists('TIX_Order_Admin')) {
            TIX_Order_Admin::add_note(
                $order_id,
                'Status geändert: ' . $old_status . ' → ' . $new_status . ($gateway ? ' (via ' . $gateway . ')' : ''),
                'status_change'
            );
        }

        // Clear cart when order reaches a terminal state
        if (in_array($new_status, ['completed', 'on-hold', 'processing'])) {
            self::clear_cart();
        }

        // Zentraler Hook — wird von Tickets, Emails, Seatmap etc. genutzt
        do_action('tix_order_status_changed', $order_id, $new_status, $old_status, $gateway);

        // Spezifische Hooks
        if ($new_status === 'completed') {
            do_action('tix_order_completed', $order_id);

            // ── SICHERHEITSNETZ ──
            // Race-Condition-Schutz: Falls TIX_Tickets::on_native_order_completed
            // beim Hook-Feuer nicht registriert war (Plugin-Reload, OPcache, fatal),
            // generieren wir die Tickets jetzt nochmal direkt. Der eingebaute Guard
            // verhindert Duplikate falls bereits Tickets existieren.
            if (class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'on_native_order_completed')) {
                $existing = (new WP_Query([
                    'post_type'      => 'tix_ticket',
                    'post_status'    => 'any',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_key'       => '_tix_ticket_order_id',
                    'meta_value'     => $order_id,
                ]))->found_posts;
                if ($existing === 0) {
                    // Hook hat keine Tickets erstellt → direkt nachholen
                    TIX_Tickets::on_native_order_completed($order_id);
                    if (class_exists('TIX_Order_Admin')) {
                        TIX_Order_Admin::add_note(
                            $order_id,
                            '🛡️ Sicherheitsnetz aktiv: Tickets via direkter Fallback-Generierung erstellt (Hook hatte nicht funktioniert).',
                            'system'
                        );
                    }
                }
            }
        } elseif ($new_status === 'cancelled' || $new_status === 'failed') {
            do_action('tix_order_cancelled', $order_id);
        }
    }

    // ──────────────────────────────────────────
    // THANK-YOU SEITE
    // ──────────────────────────────────────────

    public static function handle_thankyou() {
        if (empty($_GET['tix_thankyou'])) return;

        $order_id  = intval($_GET['order_id'] ?? 0);
        $order_key = sanitize_text_field($_GET['key'] ?? '');
        if (!$order_id || !$order_key) return;

        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d AND order_key = %s",
            $order_id, $order_key
        ));
        if (!$order) return;

        // Items laden
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d",
            $order_id
        ));

        // Tickets laden
        $tickets = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_key'       => '_tix_ticket_order_id',
            'meta_value'     => $order_id,
        ]);
        // Fallback: WC order ID Suche (Kompatibilität)
        if (empty($tickets) && $order->wc_order_id) {
            $tickets = get_posts([
                'post_type'      => 'tix_ticket',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_key'       => '_tix_ticket_order_id',
                'meta_value'     => $order->wc_order_id,
            ]);
        }

        // Seite rendern
        self::render_thankyou_page($order, $items, $tickets);
        exit;
    }

    private static function render_thankyou_page($order, $items, $tickets) {
        $s = tix_get_settings();
        $primary = $s['color_primary'] ?? '#FF5500';
        $btn_bg = $s['btn1_bg'] ?? $s['color_accent'] ?? $primary;
        $btn_color = $s['btn1_color'] ?? $s['color_accent_text'] ?? '#fff';

        // Meta Pixel: inject Purchase event
        $pixel_data = get_transient('tix_pixel_purchase_' . $order->id);
        if ($pixel_data) {
            delete_transient('tix_pixel_purchase_' . $order->id);
            $s_pixel = tix_get_settings();
            $pixel_id = esc_js($s_pixel['meta_pixel_id'] ?? '');
            if ($pixel_id && !empty($s_pixel['meta_pixel_enabled'])) {
                add_action('wp_head', function() use ($pixel_id, $pixel_data) {
                    ?>
                    <script>
                    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
                    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
                    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
                    (window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
                    fbq('init','<?php echo $pixel_id; ?>');
                    fbq('track','PageView');
                    fbq('track','Purchase',{
                        value:<?php echo floatval($pixel_data['value']); ?>,
                        currency:<?php echo wp_json_encode($pixel_data['currency']); ?>,
                        content_ids:<?php echo wp_json_encode($pixel_data['content_ids']); ?>,
                        content_type:'product',
                        num_items:<?php echo intval($pixel_data['num_items']); ?>
                    },{eventID:<?php echo wp_json_encode($pixel_data['event_id']); ?>});
                    </script>
                    <?php
                }, 5);
            }
        }

        // Add inline CSS via wp_head
        add_action('wp_head', function() use ($primary, $btn_bg, $btn_color) {
            ?>
            <style>
            /* Breakdance-SVG-Sprite-Fix wird global in tixomat.php registriert */
            .tix-ty-wrap { max-width:640px; margin:20px auto; padding:0 20px 40px; }
            .tix-ty-status { text-align:center; padding:28px 20px; background:#fff; border-radius:16px; box-shadow:0 1px 3px rgba(0,0,0,0.06); margin-bottom:16px; }
            .tix-ty-check { width:60px; height:60px; border-radius:50%; background:<?php echo esc_attr($primary); ?>; color:#fff; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:28px; }
            .tix-ty-status h1 { font-size:22px; margin-bottom:4px; }
            .tix-ty-status p { color:#6b7280; font-size:14px; }
            .tix-ty-card { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.06); padding:24px; margin-bottom:16px; }
            .tix-ty-card h3 { font-size:15px; margin-bottom:12px; color:#374151; }
            .tix-ty-item { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f0f0f0; font-size:14px; }
            .tix-ty-item:last-child { border:none; }
            .tix-ty-total { display:flex; justify-content:space-between; padding:12px 0 0; font-size:16px; font-weight:600; border-top:2px solid #e5e7eb; }
            .tix-ty-ticket { display:flex; align-items:center; justify-content:space-between; gap:24px; padding:14px 16px; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:8px; }
            .tix-ty-ticket > div:first-child { flex:1; min-width:0; }
            .tix-ty-ticket > div:first-child strong { display:block; }
            .tix-ty-ticket-code { font-family:monospace; font-size:13px; color:#6b7280; margin-top:2px; }
            .tix-ty-ticket .tix-ty-dl-btn { flex-shrink:0; white-space:nowrap; }
            @media (max-width:540px) {
                .tix-ty-ticket { flex-direction:column; align-items:stretch; gap:12px; }
                .tix-ty-ticket .tix-ty-dl-btn { text-align:center; }
            }
            .tix-ty-dl-btn { background:<?php echo esc_attr($btn_bg); ?>; color:<?php echo esc_attr($btn_color); ?>; padding:8px 16px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600; display:inline-block; }
            .tix-ty-dl-btn:hover { opacity:0.9; color:<?php echo esc_attr($btn_color); ?>; }
            .tix-ty-pending { background:#fef3c7; border:1px solid #f59e0b; border-radius:8px; padding:12px 16px; font-size:13px; color:#92400e; margin-bottom:16px; }
            .tix-ty-back { display:block; text-align:center; margin-top:24px; color:#6b7280; font-size:14px; }
            /* Info-Box: Mail verschickt + Meine-Tickets-Hinweis */
            .tix-ty-info-box { display:flex; gap:14px; align-items:flex-start; background:#f0f9ff; border:1px solid #bae6fd; border-radius:12px; padding:16px 18px; margin-bottom:16px; }
            .tix-ty-info-icon { color:<?php echo esc_attr($primary); ?>; flex-shrink:0; margin-top:2px; }
            .tix-ty-info-content strong { display:block; font-size:14px; color:#0c4a6e; margin-bottom:4px; }
            .tix-ty-info-content p { font-size:13px; color:#475569; margin:0; line-height:1.5; }
            .tix-ty-info-content a { color:<?php echo esc_attr($primary); ?>; text-decoration:none; }
            .tix-ty-info-content a:hover { text-decoration:underline; }
            /* Bundle-Link: Alle Tickets dieser Bestellung in einer Ansicht */
            .tix-ty-bundle-link { display:inline-flex; align-items:center; gap:8px; margin-bottom:16px; padding:10px 14px; background:#f5f3ff; border:1px solid #ddd6fe; border-radius:8px; color:#5b21b6 !important; font-size:13px; font-weight:600; text-decoration:none; }
            .tix-ty-bundle-link:hover { background:#ede9fe; }
            /* Bankverbindungs-Tabelle: alle Werte in Monospace + bold (wie IBAN) */
            .tix-ty-bank-table { width:100%; font-size:14px; border-collapse:collapse; }
            .tix-ty-bank-table td { padding:6px 0; vertical-align:top; }
            .tix-ty-bank-table td:first-child { color:#6b7280; padding-right:16px; white-space:nowrap; width:180px; }
            .tix-ty-bank-table td:last-child { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-weight:600; color:#0f172a; word-break:break-word; }
            .tix-ty-bank-note { margin-top:12px; font-size:13px; color:#6b7280; }
            @media (max-width:540px) {
                .tix-ty-bank-table td:first-child { width:auto; }
                .tix-ty-bank-table td:last-child { font-size:13px; }
            }
            </style>
            <?php
        }, 99);

        get_header();
        ?>
        <div class="tix-ty-wrap">
            <?php if ($order->status === 'completed' || $order->status === 'processing'): ?>
                <div class="tix-ty-status">
                    <div class="tix-ty-check">✓</div>
                    <h1 class="tix-ty-title">Vielen Dank für deine Bestellung!</h1>
                    <p class="tix-ty-text">Bestellung <?php echo esc_html($order->order_number); ?> &middot; Bestätigung wird an <?php echo esc_html($order->billing_email); ?> gesendet.</p>
                </div>
            <?php elseif ($order->status === 'pending' || $order->status === 'on-hold'): ?>
                <div class="tix-ty-status">
                    <div class="tix-ty-check" style="background:#f59e0b;">⏳</div>
                    <?php if ($order->payment_method === 'bank'): ?>
                        <h1 class="tix-ty-title">Bitte überweise den Betrag</h1>
                        <p class="tix-ty-text">Bestellung <?php echo esc_html($order->order_number); ?> &middot; Deine Tickets werden erstellt sobald die Zahlung eingegangen ist.</p>
                    <?php else: ?>
                        <h1 class="tix-ty-title">Zahlung wird verarbeitet</h1>
                        <p class="tix-ty-text">Bestellung <?php echo esc_html($order->order_number); ?> &middot; Du erhältst eine Bestätigung per E-Mail sobald die Zahlung eingegangen ist.</p>
                    <?php endif; ?>
                </div>
                <?php if ($order->payment_method === 'bank'):
                    $bs = tix_get_settings();
                ?>
                    <div class="tix-ty-card tix-ty-bank">
                        <h3 class="tix-ty-card-title">Bankverbindung</h3>
                        <table class="tix-ty-bank-table">
                            <?php if (!empty($bs['bank_holder'])): ?><tr><td>Kontoinhaber</td><td><?php echo esc_html($bs['bank_holder']); ?></td></tr><?php endif; ?>
                            <?php if (!empty($bs['bank_iban'])): ?><tr><td>IBAN</td><td><?php echo esc_html($bs['bank_iban']); ?></td></tr><?php endif; ?>
                            <?php if (!empty($bs['bank_bic'])): ?><tr><td>BIC</td><td><?php echo esc_html($bs['bank_bic']); ?></td></tr><?php endif; ?>
                            <?php if (!empty($bs['bank_name'])): ?><tr><td>Bank</td><td><?php echo esc_html($bs['bank_name']); ?></td></tr><?php endif; ?>
                            <tr><td>Betrag</td><td><?php echo number_format($order->total, 2, ',', '.'); ?>&nbsp;€</td></tr>
                            <tr><td>Verwendungszweck</td><td><?php echo esc_html($order->order_number); ?></td></tr>
                        </table>
                        <?php if (!empty($bs['bank_reference'])): ?>
                            <p class="tix-ty-bank-note"><?php echo esc_html($bs['bank_reference']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="tix-ty-pending">Die Zahlung wird gerade verarbeitet. Deine Tickets werden automatisch erstellt sobald die Zahlung bestätigt ist.</div>
                <?php endif; ?>
            <?php else: ?>
                <div class="tix-ty-status">
                    <div class="tix-ty-check" style="background:#ef4444;">✗</div>
                    <h1 class="tix-ty-title">Zahlung fehlgeschlagen</h1>
                    <p class="tix-ty-text">Bitte versuche es erneut oder wähle eine andere Zahlungsart.</p>
                </div>
            <?php endif; ?>

            <?php if (!empty($tickets) && ($order->status === 'completed' || $order->status === 'processing')):
                $bundle_url = class_exists('TIX_Tickets') ? TIX_Tickets::get_bundle_url($order->id) : '';

                // Meine-Tickets-URL: Custom-Setting > Auto-Detection
                $tys_link = tix_get_settings();
                $my_tickets_url = !empty($tys_link['ty_my_tickets_url']) ? $tys_link['ty_my_tickets_url'] : '';
                if (!$my_tickets_url && class_exists('TIX_My_Tickets')) {
                    $my_tickets_url = TIX_My_Tickets::get_tickets_page_url();
                }
            ?>
                <?php // Hinweis-Box: Mail verschickt + Meine-Tickets-Bereich ?>
                <div class="tix-ty-info-box">
                    <div class="tix-ty-info-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div class="tix-ty-info-content">
                        <strong>Bestätigung &amp; Tickets per E-Mail verschickt</strong>
                        <p>Wir haben dir alle Tickets soeben an <strong><?php echo esc_html($order->billing_email); ?></strong> geschickt.<?php if ($my_tickets_url): ?> Du kannst sie außerdem jederzeit in deinem <a href="<?php echo esc_url($my_tickets_url); ?>"><strong>„Meine Tickets"-Bereich</strong></a> einsehen und herunterladen.<?php endif; ?></p>
                    </div>
                </div>

                <div class="tix-ty-card">
                    <h3 class="tix-ty-card-title">Deine Tickets</h3>

                    <?php if ($bundle_url): ?>
                    <a href="<?php echo esc_url($bundle_url); ?>" target="_blank" class="tix-ty-bundle-link">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="4" rx="1"/><rect x="3" y="10" width="18" height="4" rx="1"/><rect x="3" y="16" width="18" height="4" rx="1"/></svg>
                        Alle Tickets dieser Bestellung in einer Ansicht öffnen →
                    </a>
                    <?php endif; ?>

                    <?php foreach ($tickets as $ticket):
                        $code = get_post_meta($ticket->ID, '_tix_ticket_code', true);
                        $token = get_post_meta($ticket->ID, '_tix_ticket_download_token', true);
                        $event_id = get_post_meta($ticket->ID, '_tix_ticket_event_id', true);
                        $dl_url = $token ? add_query_arg('tix_dl', $token, home_url('/')) : '';
                    ?>
                        <div class="tix-ty-ticket">
                            <div>
                                <strong><?php echo esc_html(get_the_title($event_id)); ?></strong>
                                <div class="tix-ty-ticket-code"><?php echo esc_html($code); ?></div>
                            </div>
                            <?php if ($dl_url):
                                $dl_label = class_exists('TIX_Tickets') ? TIX_Tickets::ticket_type_label($ticket->ID) : 'Ticket';
                            ?>
                                <a href="<?php echo esc_url($dl_url); ?>" class="tix-ty-dl-btn" target="_blank"><?php echo esc_html($dl_label); ?> ↓</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="tix-ty-card">
                <h3>Bestellübersicht</h3>
                <?php foreach ($items as $item): ?>
                    <div class="tix-ty-item">
                        <span><?php echo esc_html($item->name); ?> &times; <?php echo intval($item->quantity); ?></span>
                        <span><?php echo number_format($item->total, 2, ',', '.'); ?> &euro;</span>
                    </div>
                <?php endforeach; ?>
                <div class="tix-ty-total">
                    <span>Gesamt</span>
                    <span><?php echo number_format($order->total, 2, ',', '.'); ?> &euro;</span>
                </div>
            </div>

            <?php
            // Konfigurierbarer Back-Link (Settings → Checkout → Thank-You-Page)
            $tys = tix_get_settings();
            $show_back = !isset($tys['ty_back_link_show']) || !empty($tys['ty_back_link_show']);
            if ($show_back):
                $back_text = !empty($tys['ty_back_link_text']) ? $tys['ty_back_link_text'] : '← Zurück zu den Events';
                $back_url  = !empty($tys['ty_back_link_url'])  ? $tys['ty_back_link_url']  : home_url('/events/');
            ?>
            <a href="<?php echo esc_url($back_url); ?>" class="tix-ty-back"><?php echo esc_html($back_text); ?></a>
            <?php endif; ?>
        </div>
        <?php
        get_footer();
    }

    // ──────────────────────────────────────────
    // ASSETS
    // ──────────────────────────────────────────

    public static function enqueue_assets() {
        if (is_admin() || tix_has_wc()) return; // Nur Frontend, nur ohne WC

        wp_enqueue_style('tix-checkout', TIXOMAT_URL . 'assets/css/checkout.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-native-checkout', TIXOMAT_URL . 'assets/js/native-checkout.js', ['jquery'], TIXOMAT_VERSION, true);
        wp_localize_script('tix-native-checkout', 'tixNativeCheckout', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tix_native_checkout'),
        ]);
    }

    /**
     * Cart Shortcode (optional)
     */
    public static function shortcode_cart() {
        $cart = self::get_cart();
        if (empty($cart['items'])) return '<p>Dein Warenkorb ist leer.</p>';

        ob_start();
        echo '<div class="tix-native-cart">';
        foreach ($cart['items'] as $i => $item) {
            echo '<div class="tix-cart-item">';
            echo '<strong>' . esc_html($item['event_title']) . '</strong> – ' . esc_html($item['name']);
            echo ' &times; ' . intval($item['qty']);
            echo ' = ' . number_format($item['price'] * $item['qty'], 2, ',', '.') . ' €';
            echo '</div>';
        }
        echo '<div class="tix-cart-total"><strong>Gesamt: ' . number_format(self::cart_total(), 2, ',', '.') . ' €</strong></div>';
        echo '<a href="' . esc_url(self::checkout_url()) . '" class="tix-co-btn">Zur Kasse</a>';
        echo '</div>';
        return ob_get_clean();
    }
}
