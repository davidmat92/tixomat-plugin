<?php
/**
 * TIX_Cart – Cart-Seite (Shortcode) + Mini-Cart Drawer
 *
 * [tix_cart]      – Volle Warenkorbseite im Checkout-Design
 * Mini-Cart       – Slide-in Drawer von rechts, auslösbar per
 *                   CSS-Klasse .tix-minicart-trigger oder data-tix-minicart
 *
 * @since 1.33.0
 */

if (!defined('ABSPATH')) exit;

class TIX_Cart {

    public static function init() {
        add_shortcode('tix_cart', [__CLASS__, 'render_cart_page']);

        // Mini-Cart Fragment (WC AJAX)
        add_filter('woocommerce_add_to_cart_fragments', [__CLASS__, 'minicart_fragment']);

        // AJAX: Mini-Cart HTML holen
        add_action('wp_ajax_tix_get_minicart',        [__CLASS__, 'ajax_get_minicart']);
        add_action('wp_ajax_nopriv_tix_get_minicart',  [__CLASS__, 'ajax_get_minicart']);

        // Mini-Cart im Footer ausgeben
        add_action('wp_footer', [__CLASS__, 'render_minicart_drawer']);

        // Assets auf allen Seiten laden (für Mini-Cart Icon + Drawer)
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_minicart_assets']);
    }

    // ══════════════════════════════════════════════
    // CART PAGE SHORTCODE
    // ══════════════════════════════════════════════

    public static function render_cart_page($atts) {
        $atts = shortcode_atts(['checkout_url' => ''], $atts);

        if (!function_exists('WC') || !WC()->cart) {
            return '<p>WooCommerce ist nicht aktiv.</p>';
        }

        self::enqueue_cart_assets();

        $tix_s        = tix_get_settings();
        $checkout_url = $atts['checkout_url'] ?: wc_get_checkout_url();
        $vat_text     = $tix_s['vat_text_checkout'] ?? 'inkl. MwSt.';
        $nonce        = wp_create_nonce('tix_update_cart');

        ob_start();
        // Wrapper ist .tix-co damit alle CSS-Variablen + Checkout-Styles greifen
        ?>
        <div class="tix-co" id="tix-cart" data-nonce="<?php echo $nonce; ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">

            <?php if (WC()->cart->is_empty()): ?>
                <div class="tix-co-empty">
                    <p><?php echo esc_html($tix_s['empty_text'] ?? 'Dein Warenkorb ist leer.'); ?></p>
                    <a href="<?php echo esc_url(get_post_type_archive_link('event') ?: home_url()); ?>" class="tix-co-btn-back">
                        <?php echo esc_html($tix_s['empty_link_text'] ?? 'Zu den Events'); ?>
                    </a>
                </div>
            <?php else: ?>

                <div class="tix-co-section">
                    <h3 class="tix-co-heading">Dein Warenkorb</h3>
                    <div class="tix-co-cart" id="tix-cart-items">
                        <?php echo TIX_Checkout::render_cart_items(); ?>
                    </div>
                    <a href="<?php echo esc_url(get_post_type_archive_link('event') ?: home_url()); ?>" class="tix-co-btn-more">+ Weitere Tickets kaufen</a>
                </div>

                <?php
                // Specials Upsell
                if (class_exists('TIX_Specials') && function_exists('tix_get_settings') && tix_get_settings('specials_enabled')) {
                    TIX_Specials::render_checkout_section();
                }
                ?>

                <?php
                // Tischreservierung
                if (class_exists('TIX_Table_Reservation')) {
                    TIX_Table_Reservation::render_checkout_table_section();
                }
                ?>

                <?php // Gutscheincode ?>
                <?php $coupons = WC()->cart->get_applied_coupons(); ?>
                <div class="tix-co-section">
                    <div class="tix-co-coupon" id="tix-cart-coupon">
                        <div class="tix-co-coupon-applied" id="tix-cart-coupon-applied">
                            <?php foreach ($coupons as $code): ?>
                                <div class="tix-co-coupon-tag">
                                    <span class="tix-co-coupon-code"><?php echo esc_html(strtoupper($code)); ?></span>
                                    <button type="button" class="tix-co-coupon-remove" data-coupon="<?php echo esc_attr($code); ?>" title="Entfernen">&#x2715;</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="tix-co-coupon-input-wrap">
                            <input type="text" id="tix-cart-coupon-code" class="tix-co-input" placeholder="Gutscheincode eingeben">
                            <button type="button" class="tix-co-coupon-btn" id="tix-cart-coupon-btn">Einl&ouml;sen</button>
                        </div>
                        <div class="tix-co-coupon-msg" id="tix-cart-coupon-msg" style="display:none;"></div>
                    </div>
                </div>

                <?php // Zusammenfassung ?>
                <div class="tix-co-section">
                    <div class="tix-co-summary" id="tix-cart-summary">
                        <div class="tix-co-summary-row tix-co-discount-row tix-co-coupon-discount-row" <?php echo empty($coupons) ? 'style="display:none;"' : ''; ?>>
                            <span>Rabatt</span>
                            <span class="tix-co-discount"><?php echo !empty($coupons) ? '&minus;' . wc_price(WC()->cart->get_cart_discount_total()) : ''; ?></span>
                        </div>
                        <div class="tix-co-fees"><?php echo TIX_Checkout::render_fee_rows(); ?></div>
                        <div class="tix-co-summary-row tix-co-summary-total">
                            <span>Gesamt <span class="tix-co-vat-note"><?php echo esc_html($vat_text); ?></span></span>
                            <span class="tix-co-total"><?php echo WC()->cart->get_total(); ?></span>
                        </div>
                    </div>
                </div>

                <a href="<?php echo esc_url($checkout_url); ?>" class="tix-co-submit">
                    Zur Kasse &middot; <span class="tix-co-submit-price"><?php echo strip_tags(WC()->cart->get_total()); ?></span>
                </a>

            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════════════
    // MINI-CART DRAWER
    // ══════════════════════════════════════════════

    /**
     * Mini-Cart Drawer HTML im Footer ausgeben.
     */
    public static function render_minicart_drawer() {
        if (!function_exists('WC') || !WC()->cart) return;
        ?>
        <div class="tix-co tix-mc-overlay-wrap" id="tix-mc-overlay" style="display:none;">
            <div class="tix-mc-drawer" id="tix-mc-drawer">
                <div class="tix-mc-drawer-header">
                    <h3 class="tix-mc-drawer-title">Warenkorb</h3>
                    <button type="button" class="tix-mc-drawer-close" id="tix-mc-drawer-close" aria-label="Schlie&szlig;en">&#x2715;</button>
                </div>
                <div class="tix-mc-drawer-body" id="tix-mc-drawer-body">
                    <?php echo self::render_minicart_content(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Mini-Cart Inhalt rendern.
     */
    public static function render_minicart_content() {
        if (!function_exists('WC') || !WC()->cart) return '';

        $cart  = WC()->cart;
        $nonce = wp_create_nonce('tix_update_cart');

        ob_start();
        ?>
        <div class="tix-mc-content" data-nonce="<?php echo $nonce; ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <?php if ($cart->is_empty()): ?>
                <div class="tix-mc-empty">
                    <p>Dein Warenkorb ist leer.</p>
                    <a href="<?php echo esc_url(get_post_type_archive_link('event') ?: home_url()); ?>" class="tix-co-btn-more">Tickets kaufen</a>
                </div>
            <?php else: ?>
                <div class="tix-mc-items">
                    <?php foreach ($cart->get_cart() as $cart_key => $item):
                        $product = $item['data'];
                        $qty     = $item['quantity'];
                        $name    = $product->get_name();
                        $price   = floatval($product->get_price());
                    ?>
                    <div class="tix-mc-item" data-cart-key="<?php echo esc_attr($cart_key); ?>">
                        <div class="tix-mc-item-info">
                            <span class="tix-mc-item-name"><?php echo esc_html($name); ?></span>
                            <?php if (!empty($item['_tix_bundle'])): ?>
                                <span class="tix-mc-item-hint"><?php echo esc_html($item['_tix_bundle']['label'] ?? ''); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="tix-mc-item-price"><?php echo wc_price($price); ?></div>
                        <div class="tix-mc-item-qty">
                            <button type="button" class="tix-mc-qty-btn tix-mc-qty-minus" data-key="<?php echo esc_attr($cart_key); ?>" aria-label="Weniger">&minus;</button>
                            <span class="tix-mc-qty-val"><?php echo $qty; ?></span>
                            <button type="button" class="tix-mc-qty-btn tix-mc-qty-plus" data-key="<?php echo esc_attr($cart_key); ?>" aria-label="Mehr">+</button>
                        </div>
                        <button type="button" class="tix-mc-item-remove" data-key="<?php echo esc_attr($cart_key); ?>" aria-label="Entfernen">&#x2715;</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="tix-mc-footer">
                    <div class="tix-mc-total-row">
                        <span>Gesamt</span>
                        <span class="tix-mc-total-amount"><?php echo $cart->get_total(); ?></span>
                    </div>
                    <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="tix-mc-checkout-btn">Zur Kasse</a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * WC Fragment für Mini-Cart Count Badge.
     */
    public static function minicart_fragment($fragments) {
        $count = WC()->cart->get_cart_contents_count();
        $fragments['.tix-minicart-count'] = '<span class="tix-minicart-count"' . ($count ? '' : ' style="display:none"') . '>' . $count . '</span>';
        $fragments['.tix-mc-drawer-body'] = '<div class="tix-mc-drawer-body" id="tix-mc-drawer-body">' . self::render_minicart_content() . '</div>';
        return $fragments;
    }

    /**
     * AJAX: Mini-Cart HTML holen.
     */
    public static function ajax_get_minicart() {
        wp_send_json_success([
            'html'  => self::render_minicart_content(),
            'count' => WC()->cart->get_cart_contents_count(),
            'total' => strip_tags(WC()->cart->get_total()),
        ]);
    }

    // ══════════════════════════════════════════════
    // ASSETS
    // ══════════════════════════════════════════════

    /**
     * Cart-Page-Assets laden.
     */
    private static function enqueue_cart_assets() {
        wp_enqueue_style('tix-checkout', TIXOMAT_URL . 'assets/css/checkout.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-cart', TIXOMAT_URL . 'assets/js/cart.js', [], TIXOMAT_VERSION, true);
    }

    /**
     * Mini-Cart-Assets auf allen Seiten laden.
     */
    public static function enqueue_minicart_assets() {
        wp_enqueue_style('tix-minicart', TIXOMAT_URL . 'assets/css/minicart.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-minicart', TIXOMAT_URL . 'assets/js/minicart.js', [], TIXOMAT_VERSION, true);
        wp_localize_script('tix-minicart', 'tixMC', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('tix_update_cart'),
            'checkoutUrl' => wc_get_checkout_url(),
        ]);
    }
}
