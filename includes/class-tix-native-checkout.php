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

    /** Session-Key für den Warenkorb */
    const CART_KEY = 'tix_native_cart';

    public static function init() {
        // Session starten
        add_action('init', [__CLASS__, 'start_session'], 1);

        // AJAX: Cart-Aktionen
        add_action('wp_ajax_tix_native_add_to_cart',        [__CLASS__, 'ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_tix_native_add_to_cart', [__CLASS__, 'ajax_add_to_cart']);
        add_action('wp_ajax_tix_native_remove_item',        [__CLASS__, 'ajax_remove_item']);
        add_action('wp_ajax_nopriv_tix_native_remove_item', [__CLASS__, 'ajax_remove_item']);

        // AJAX: Checkout verarbeiten
        add_action('wp_ajax_tix_native_checkout',        [__CLASS__, 'ajax_process_checkout']);
        add_action('wp_ajax_nopriv_tix_native_checkout', [__CLASS__, 'ajax_process_checkout']);

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

    public static function start_session() {
        if (is_admin() && !wp_doing_ajax()) return; // Kein Session im Admin-Backend
        if (!session_id() && !headers_sent()) {
            session_start();
        }
    }

    public static function get_cart() {
        return $_SESSION[self::CART_KEY] ?? ['items' => [], 'coupon' => null];
    }

    public static function save_cart($cart) {
        $_SESSION[self::CART_KEY] = $cart;
    }

    public static function clear_cart() {
        unset($_SESSION[self::CART_KEY]);
    }

    public static function cart_total() {
        $cart = self::get_cart();
        $total = 0;
        foreach ($cart['items'] as $item) {
            $total += floatval($item['price']) * intval($item['qty']);
        }
        return round($total, 2);
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

            if (!$event_id) continue;

            // Ticket-Kategorie validieren
            $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
            if (!is_array($categories) || !isset($categories[$cat_index])) continue;

            $cat = $categories[$cat_index];
            $price = floatval($cat['price'] ?? 0);
            $name = sanitize_text_field($cat['name'] ?? 'Ticket');
            $event_title = get_the_title($event_id);

            // Prüfe ob schon im Cart → Menge erhöhen
            $found = false;
            foreach ($cart['items'] as &$ci) {
                if ($ci['event_id'] === $event_id && $ci['cat_index'] === $cat_index) {
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
                        'special'    => $item['special'] ?? null,
                        'special_id' => $item['special_id'] ?? null,
                    ]),
                ];
            }
        }

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
     * AJAX: Artikel entfernen
     */
    public static function ajax_remove_item() {
        check_ajax_referer('tix_native_checkout', 'nonce');
        $index = intval($_POST['index'] ?? -1);
        $cart = self::get_cart();

        if (isset($cart['items'][$index])) {
            array_splice($cart['items'], $index, 1);
            self::save_cart($cart);
        }

        wp_send_json_success(['cart_count' => self::cart_count(), 'cart_total' => self::cart_total()]);
    }

    // ──────────────────────────────────────────
    // CHECKOUT URL
    // ──────────────────────────────────────────

    public static function checkout_url() {
        // Suche nach Seite mit [tix_checkout] Shortcode
        $pages = get_posts([
            'post_type'   => 'page',
            'post_status' => 'publish',
            's'           => '[tix_checkout]',
            'fields'      => 'ids',
            'numberposts' => 1,
        ]);
        if (!empty($pages)) return get_permalink($pages[0]);

        // Fallback: Suche nach WC checkout oder eigener Seite
        $checkout_page_id = get_option('tix_checkout_page_id');
        if ($checkout_page_id) return get_permalink($checkout_page_id);

        return home_url('/checkout/');
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
     * Rendert den nativen Checkout wenn checkout_mode != woocommerce
     * Wird vom bestehenden [tix_checkout] Shortcode aufgerufen oder eigener Shortcode
     */
    public static function render_checkout() {
        $cart = self::get_cart();
        if (empty($cart['items'])) {
            return '<div class="tix-co" style="text-align:center;padding:40px 20px;">'
                . '<p style="font-size:18px;color:#6b7280;">Dein Warenkorb ist leer.</p>'
                . '<a href="' . esc_url(home_url('/events/')) . '" class="tix-co-btn" style="display:inline-block;margin-top:12px;">Events entdecken</a>'
                . '</div>';
        }

        $total = self::cart_total();
        $is_free = ($total <= 0);
        $nonce = wp_create_nonce('tix_native_checkout');

        // Verfügbare Gateways
        $gateways = [];
        if ($is_free) {
            $gateways[] = ['id' => 'free', 'title' => 'Kostenlos', 'icon' => ''];
        } else {
            if (TIX_Gateway_Mollie::is_available()) {
                $gateways[] = ['id' => 'mollie', 'title' => TIX_Gateway_Mollie::get_title(), 'icon' => TIX_Gateway_Mollie::get_icon()];
            }
            if (TIX_Gateway_PayPal::is_available()) {
                $gateways[] = ['id' => 'paypal', 'title' => TIX_Gateway_PayPal::get_title(), 'icon' => TIX_Gateway_PayPal::get_icon()];
            }
        }

        $s = tix_get_settings();
        $user = wp_get_current_user();

        ob_start();
        ?>
        <div class="tix-co tix-native-checkout" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">

            <?php // ── Warenkorb-Übersicht ── ?>
            <div class="tix-co-section tix-co-cart-summary">
                <h3>Deine Tickets</h3>
                <div class="tix-co-items">
                    <?php foreach ($cart['items'] as $i => $item): ?>
                        <div class="tix-co-item" data-index="<?php echo $i; ?>">
                            <div class="tix-co-item-info">
                                <strong><?php echo esc_html($item['event_title']); ?></strong>
                                <span><?php echo esc_html($item['name']); ?> &times; <?php echo intval($item['qty']); ?></span>
                            </div>
                            <div class="tix-co-item-price">
                                <?php echo number_format($item['price'] * $item['qty'], 2, ',', '.'); ?> &euro;
                            </div>
                            <button type="button" class="tix-co-remove" data-index="<?php echo $i; ?>" title="Entfernen">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="tix-co-total">
                    <strong>Gesamt</strong>
                    <strong><?php echo number_format($total, 2, ',', '.'); ?> &euro;</strong>
                </div>
            </div>

            <?php // ── Kontaktdaten ── ?>
            <form id="tix-native-checkout-form" method="post">
                <input type="hidden" name="action" value="tix_native_checkout">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">

                <div class="tix-co-section">
                    <h3>Kontaktdaten</h3>
                    <div class="tix-co-fields">
                        <div class="tix-co-row">
                            <div class="tix-co-field">
                                <label for="tix_billing_first_name">Vorname *</label>
                                <input type="text" id="tix_billing_first_name" name="billing_first_name" required
                                       value="<?php echo esc_attr($user->first_name ?? ''); ?>">
                            </div>
                            <div class="tix-co-field">
                                <label for="tix_billing_last_name">Nachname *</label>
                                <input type="text" id="tix_billing_last_name" name="billing_last_name" required
                                       value="<?php echo esc_attr($user->last_name ?? ''); ?>">
                            </div>
                        </div>
                        <div class="tix-co-field">
                            <label for="tix_billing_email">E-Mail-Adresse *</label>
                            <input type="email" id="tix_billing_email" name="billing_email" required
                                   value="<?php echo esc_attr($user->user_email ?? ''); ?>">
                        </div>
                        <div class="tix-co-field">
                            <label for="tix_billing_phone">Telefon</label>
                            <input type="tel" id="tix_billing_phone" name="billing_phone">
                        </div>
                    </div>
                </div>

                <?php if (!$is_free && !empty($gateways)): ?>
                <div class="tix-co-section">
                    <h3>Zahlungsart</h3>
                    <div class="tix-co-gateways">
                        <?php foreach ($gateways as $gi => $gw): ?>
                            <label class="tix-co-gateway <?php echo $gi === 0 ? 'active' : ''; ?>">
                                <input type="radio" name="payment_method" value="<?php echo esc_attr($gw['id']); ?>"
                                    <?php checked($gi, 0); ?>>
                                <?php if ($gw['icon']): ?>
                                    <img src="<?php echo esc_url($gw['icon']); ?>" alt="" style="height:20px;">
                                <?php endif; ?>
                                <span><?php echo esc_html($gw['title']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                    <input type="hidden" name="payment_method" value="free">
                <?php endif; ?>

                <?php // ── AGB + Datenschutz ── ?>
                <div class="tix-co-section tix-co-legal">
                    <?php
                    $terms_url = $s['terms_url'] ?? '';
                    $privacy_url = $s['privacy_url'] ?? '';
                    if ($terms_url || $privacy_url):
                    ?>
                        <label class="tix-co-checkbox">
                            <input type="checkbox" name="accept_terms" required>
                            <span>Ich akzeptiere die
                                <?php if ($terms_url): ?><a href="<?php echo esc_url($terms_url); ?>" target="_blank">AGB</a><?php endif; ?>
                                <?php if ($terms_url && $privacy_url): ?> und <?php endif; ?>
                                <?php if ($privacy_url): ?><a href="<?php echo esc_url($privacy_url); ?>" target="_blank">Datenschutzerklärung</a><?php endif; ?>
                            .</span>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="tix-co-submit">
                    <button type="submit" class="tix-co-btn tix-co-pay-btn" id="tix-native-pay-btn">
                        <?php if ($is_free): ?>
                            Kostenlos bestellen
                        <?php else: ?>
                            Jetzt bezahlen &middot; <?php echo number_format($total, 2, ',', '.'); ?> &euro;
                        <?php endif; ?>
                    </button>
                    <p class="tix-co-secure">🔒 Sichere Verbindung (SSL-verschlüsselt)</p>
                </div>

                <div id="tix-native-checkout-error" style="display:none;" class="tix-co-error"></div>
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

        $payment_method = sanitize_text_field($_POST['payment_method'] ?? 'free');
        $total = self::cart_total();

        // Order erstellen
        $order_id = self::create_order([
            'billing_first_name' => $first_name,
            'billing_last_name'  => $last_name,
            'billing_email'      => $email,
            'billing_phone'      => sanitize_text_field($_POST['billing_phone'] ?? ''),
            'payment_method'     => $payment_method,
            'total'              => $total,
            'items'              => $cart['items'],
        ]);

        if (!$order_id) {
            wp_send_json_error(['message' => 'Bestellung konnte nicht erstellt werden.']);
        }

        // Payment verarbeiten
        if ($total <= 0 || $payment_method === 'free') {
            $result = TIX_Gateway_Free::process($order_id);
        } elseif ($payment_method === 'mollie') {
            $result = TIX_Gateway_Mollie::process($order_id);
        } elseif ($payment_method === 'paypal') {
            $result = TIX_Gateway_PayPal::process($order_id);
        } else {
            wp_send_json_error(['message' => 'Unbekannte Zahlungsart.']);
            return;
        }

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
            return;
        }

        // Cart leeren
        self::clear_cart();

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

        $wpdb->insert($t, [
            'order_number'          => $order_number,
            'status'                => 'pending',
            'total'                 => $data['total'],
            'subtotal'              => $data['total'],
            'tax'                   => 0,
            'discount'              => 0,
            'payment_method'        => $data['payment_method'],
            'payment_method_title'  => self::gateway_title($data['payment_method']),
            'billing_first_name'    => $data['billing_first_name'],
            'billing_last_name'     => $data['billing_last_name'],
            'billing_email'         => $data['billing_email'],
            'billing_phone'         => $data['billing_phone'] ?? '',
            'billing_company'       => '',
            'billing_address_1'     => '',
            'billing_address_2'     => '',
            'billing_city'          => '',
            'billing_postcode'      => '',
            'billing_country'       => 'DE',
            'customer_id'           => get_current_user_id(),
            'wc_order_id'           => 0, // Kein WC-Pendant
            'order_key'             => $order_key,
            'date_created'          => current_time('mysql'),
        ]);

        $order_id = $wpdb->insert_id;
        if (!$order_id) return null;

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

        return $order_id;
    }

    private static function gateway_title($id) {
        $titles = [
            'free'   => 'Kostenlos',
            'mollie' => 'Online bezahlen (Mollie)',
            'paypal' => 'PayPal',
        ];
        return $titles[$id] ?? $id;
    }

    // ──────────────────────────────────────────
    // ORDER STATUS UPDATE + HOOKS
    // ──────────────────────────────────────────

    public static function update_order_status($order_id, $new_status, $gateway = '') {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';

        $old_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $t WHERE id = %d", $order_id));

        $wpdb->update($t, ['status' => $new_status], ['id' => $order_id]);

        // Zentraler Hook — wird von Tickets, Emails, Seatmap etc. genutzt
        do_action('tix_order_status_changed', $order_id, $new_status, $old_status, $gateway);

        // Spezifische Hooks
        if ($new_status === 'completed') {
            do_action('tix_order_completed', $order_id);
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
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bestellbestätigung – <?php echo esc_html($order->order_number); ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f7f8fa; color:#1e293b; line-height:1.6; }
        .ty-wrap { max-width:640px; margin:40px auto; padding:0 20px; }
        .ty-status { text-align:center; padding:40px 20px; background:#fff; border-radius:16px; box-shadow:0 1px 3px rgba(0,0,0,0.06); margin-bottom:20px; }
        .ty-check { width:60px; height:60px; border-radius:50%; background:<?php echo $primary; ?>; color:#fff; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:28px; }
        .ty-status h1 { font-size:22px; margin-bottom:4px; }
        .ty-status p { color:#6b7280; font-size:14px; }
        .ty-card { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.06); padding:24px; margin-bottom:16px; }
        .ty-card h3 { font-size:15px; margin-bottom:12px; color:#374151; }
        .ty-item { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f0f0f0; font-size:14px; }
        .ty-item:last-child { border:none; }
        .ty-total { display:flex; justify-content:space-between; padding:12px 0 0; font-size:16px; font-weight:600; border-top:2px solid #e5e7eb; }
        .ty-ticket { display:flex; align-items:center; justify-content:space-between; padding:12px; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:8px; }
        .ty-ticket-code { font-family:monospace; font-size:13px; color:#6b7280; }
        .ty-dl-btn { background:<?php echo $primary; ?>; color:#fff; padding:8px 16px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600; }
        .ty-dl-btn:hover { opacity:0.9; }
        .ty-pending { background:#fef3c7; border:1px solid #f59e0b; border-radius:8px; padding:12px 16px; font-size:13px; color:#92400e; margin-bottom:16px; }
        .ty-back { display:block; text-align:center; margin-top:24px; color:#6b7280; font-size:14px; }
    </style>
</head>
<body>
    <div class="ty-wrap">
        <?php if ($order->status === 'completed' || $order->status === 'processing'): ?>
            <div class="ty-status">
                <div class="ty-check">✓</div>
                <h1>Vielen Dank für deine Bestellung!</h1>
                <p>Bestellung <?php echo esc_html($order->order_number); ?> &middot; Bestätigung wird an <?php echo esc_html($order->billing_email); ?> gesendet.</p>
            </div>
        <?php elseif ($order->status === 'pending'): ?>
            <div class="ty-status">
                <div class="ty-check" style="background:#f59e0b;">⏳</div>
                <h1>Zahlung wird verarbeitet</h1>
                <p>Bestellung <?php echo esc_html($order->order_number); ?> &middot; Du erhältst eine Bestätigung per E-Mail sobald die Zahlung eingegangen ist.</p>
            </div>
            <div class="ty-pending">Die Zahlung wird gerade verarbeitet. Deine Tickets werden automatisch erstellt sobald die Zahlung bestätigt ist.</div>
        <?php else: ?>
            <div class="ty-status">
                <div class="ty-check" style="background:#ef4444;">✗</div>
                <h1>Zahlung fehlgeschlagen</h1>
                <p>Bitte versuche es erneut oder wähle eine andere Zahlungsart.</p>
            </div>
        <?php endif; ?>

        <?php // Tickets ?>
        <?php if (!empty($tickets)): ?>
            <div class="ty-card">
                <h3>Deine Tickets</h3>
                <?php foreach ($tickets as $ticket):
                    $code = get_post_meta($ticket->ID, '_tix_ticket_code', true);
                    $token = get_post_meta($ticket->ID, '_tix_ticket_download_token', true);
                    $event_id = get_post_meta($ticket->ID, '_tix_ticket_event_id', true);
                    $dl_url = $token ? add_query_arg('tix_dl', $token, home_url('/')) : '';
                ?>
                    <div class="ty-ticket">
                        <div>
                            <strong><?php echo esc_html(get_the_title($event_id)); ?></strong>
                            <div class="ty-ticket-code"><?php echo esc_html($code); ?></div>
                        </div>
                        <?php if ($dl_url): ?>
                            <a href="<?php echo esc_url($dl_url); ?>" class="ty-dl-btn">PDF ↓</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php // Bestellübersicht ?>
        <div class="ty-card">
            <h3>Bestellübersicht</h3>
            <?php foreach ($items as $item): ?>
                <div class="ty-item">
                    <span><?php echo esc_html($item->name); ?> &times; <?php echo intval($item->quantity); ?></span>
                    <span><?php echo number_format($item->total, 2, ',', '.'); ?> &euro;</span>
                </div>
            <?php endforeach; ?>
            <div class="ty-total">
                <span>Gesamt</span>
                <span><?php echo number_format($order->total, 2, ',', '.'); ?> &euro;</span>
            </div>
        </div>

        <a href="<?php echo esc_url(home_url('/events/')); ?>" class="ty-back">&larr; Zurück zu den Events</a>
    </div>
</body>
</html>
        <?php
    }

    // ──────────────────────────────────────────
    // ASSETS
    // ──────────────────────────────────────────

    public static function enqueue_assets() {
        if (is_admin() || tix_has_wc()) return; // Nur Frontend, nur ohne WC

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
