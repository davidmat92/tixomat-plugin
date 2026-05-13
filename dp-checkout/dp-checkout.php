<?php
/**
 * Plugin Name: DP Checkout
 * Description: Schlanker, gestylter WooCommerce-Checkout (Shortcode + Page-Override). Basis fuer dpconnect.de.
 * Version:     0.1.0
 * Author:      DP Connect
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: dp-checkout
 */

if (!defined('ABSPATH')) exit;

define('DPC_VERSION', '0.1.0');
define('DPC_FILE',    __FILE__);
define('DPC_PATH',    plugin_dir_path(__FILE__));
define('DPC_URL',     plugin_dir_url(__FILE__));

/**
 * Settings-Provider: zentrale Defaults + Filter-Hook fuer spaetere Settings-UI.
 *
 * Alle Strings/Flags, die der Checkout konsumiert, leben hier. Filter:
 *   apply_filters('dpc_settings', $defaults)
 */
function dpc_get_settings($key = null, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $defaults = [
            // Texte
            'empty_text'         => 'Dein Warenkorb ist leer.',
            'empty_link_text'    => 'Zum Shop',
            'btn_text_checkout'  => 'Jetzt kostenpflichtig bestellen',
            'vat_text_checkout'  => 'inkl. MwSt.',
            'shipping_text'      => 'Kostenloser Versand per E-Mail',

            // Rechtliches (URLs)
            'terms_url'          => '',
            'privacy_url'        => '',
            'revocation_url'     => '',

            // Felder
            'show_company_field' => true,

            // Verhalten
            'skip_cart'          => false, // direkt in Checkout statt Cart-Seite
            'checkout_steps'     => false, // 3-Step-UI
            'checkout_countdown' => false, // Reservierungs-Timer
            'checkout_countdown_minutes' => 10,
            'checkout_btn_variant' => 1,   // 1 = primary, 2 = secondary

            // Optional: Google Places Autocomplete (leer = aus)
            'google_api_key'     => '',
        ];
        $cache = apply_filters('dpc_settings', $defaults);
    }
    if ($key === null) return $cache;
    return $cache[$key] ?? $default;
}

require_once DPC_PATH . 'includes/class-dpc-checkout.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>DP Checkout:</strong> WooCommerce wird benoetigt.</p></div>';
        });
        return;
    }
    DPC_Checkout::init();
}, 20);
