<?php
/**
 * TIX_Google_Tag — Google Ads + GA4 + GTM Integration
 *
 * Lädt gtag.js / GTM im wp_head und sendet Conversion-Events auf der
 * Thank-You-Seite (Purchase mit transaction_id + value).
 *
 * Settings (Tracking-Pixel-Tab):
 *   google_ads_enabled / google_ads_id / google_ads_conversion_label / google_ads_send_purchase
 *   ga4_enabled / ga4_measurement_id
 *   gtm_enabled / gtm_container_id
 *   google_consent_mode (always | consent_required)
 *   google_consent_cookie
 */
if (!defined('ABSPATH')) exit;

class TIX_Google_Tag {

    public static function init() {
        add_action('wp_head',     [__CLASS__, 'inject_head'], 1);
        add_action('wp_body_open', [__CLASS__, 'inject_body_open'], 1);
        // Purchase-Event auf Thank-You-Seite (sowohl WC als native)
        add_action('woocommerce_thankyou', [__CLASS__, 'track_wc_purchase'], 10, 1);
    }

    private static function get_ids() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        return [
            'ads_enabled'       => !empty($s['google_ads_enabled']),
            'ads_id'            => $s['google_ads_id']            ?? '',
            'ads_label'         => $s['google_ads_conversion_label'] ?? '',
            'ads_send_purchase' => !empty($s['google_ads_send_purchase']),
            'ga4_enabled'       => !empty($s['ga4_enabled']),
            'ga4_id'            => $s['ga4_measurement_id']       ?? '',
            'gtm_enabled'       => !empty($s['gtm_enabled']),
            'gtm_id'            => $s['gtm_container_id']         ?? '',
            'consent_mode'      => $s['google_consent_mode']      ?? 'always',
            'consent_cookie'    => $s['google_consent_cookie']    ?? 'cookie_consent',
        ];
    }

    /**
     * Lädt gtag.js bzw. GTM-Snippet im <head>.
     */
    public static function inject_head() {
        if (is_admin()) return;
        $g = self::get_ids();

        $any_active = ($g['ads_enabled'] && $g['ads_id'])
            || ($g['ga4_enabled'] && $g['ga4_id'])
            || ($g['gtm_enabled'] && $g['gtm_id']);
        if (!$any_active) return;

        // Bei Consent-required: nur laden wenn Cookie vorhanden ist
        if ($g['consent_mode'] === 'consent_required' && empty($_COOKIE[$g['consent_cookie']])) {
            return;
        }

        // GTM eigenständig (lädt selbst gtag.js falls dort GA4/Ads konfiguriert)
        if ($g['gtm_enabled'] && $g['gtm_id']) {
            ?>
            <!-- Google Tag Manager -->
            <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','<?php echo esc_js($g['gtm_id']); ?>');</script>
            <!-- End Google Tag Manager -->
            <?php
        }

        // gtag.js für Ads + GA4 (nur wenn nicht via GTM gemanaged)
        if (($g['ads_enabled'] && $g['ads_id']) || ($g['ga4_enabled'] && $g['ga4_id'])) {
            // Primärer Tag-ID-Wert: bevorzugt GA4-ID, sonst Ads-ID
            $primary = ($g['ga4_enabled'] && $g['ga4_id']) ? $g['ga4_id'] : $g['ads_id'];
            ?>
            <!-- Google tag (gtag.js) -->
            <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($primary); ?>"></script>
            <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            <?php if ($g['ga4_enabled'] && $g['ga4_id']): ?>
            gtag('config', '<?php echo esc_js($g['ga4_id']); ?>');
            <?php endif; ?>
            <?php if ($g['ads_enabled'] && $g['ads_id']): ?>
            gtag('config', '<?php echo esc_js($g['ads_id']); ?>');
            <?php endif; ?>
            </script>
            <?php
        }
    }

    /**
     * GTM Noscript-Iframe direkt nach <body>.
     */
    public static function inject_body_open() {
        if (is_admin()) return;
        $g = self::get_ids();
        if (!$g['gtm_enabled'] || !$g['gtm_id']) return;
        if ($g['consent_mode'] === 'consent_required' && empty($_COOKIE[$g['consent_cookie']])) return;
        ?>
        <!-- Google Tag Manager (noscript) -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($g['gtm_id']); ?>"
        height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
        <?php
    }

    /**
     * WC: Purchase-Conversion + GA4 purchase event auf Thank-You-Seite.
     */
    public static function track_wc_purchase($order_id) {
        if (!$order_id || !function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

        $value    = floatval($order->get_total());
        $currency = $order->get_currency();
        $tx_id    = (string) $order->get_id();
        self::render_purchase_event($tx_id, $value, $currency);
    }

    /**
     * Native: Purchase-Event auf Thank-You-Seite (wird von TIX_Native_Checkout aufgerufen).
     */
    public static function track_native_purchase($tix_order_id) {
        if (!$tix_order_id || !class_exists('TIX_Order')) return;
        $order = TIX_Order::get($tix_order_id);
        if (!$order) return;
        $value    = floatval($order->get_total());
        $currency = method_exists($order, 'get_currency') ? $order->get_currency() : 'EUR';
        $tx_id    = method_exists($order, 'get_order_number') ? $order->get_order_number() : (string) $tix_order_id;
        self::render_purchase_event($tx_id, $value, $currency);
    }

    /**
     * Inline-Script-Rendering für Purchase-Event. Wird auf der Thank-You-Seite ausgegeben.
     */
    private static function render_purchase_event($tx_id, $value, $currency = 'EUR') {
        $g = self::get_ids();
        if (!$g['ads_enabled'] && !$g['ga4_enabled']) return;
        if ($g['consent_mode'] === 'consent_required' && empty($_COOKIE[$g['consent_cookie']])) return;

        $tx_id    = sanitize_text_field((string) $tx_id);
        $value    = round(floatval($value), 2);
        $currency = sanitize_text_field($currency ?: 'EUR');

        ?>
        <script>
        if (typeof gtag === 'function') {
            <?php if ($g['ga4_enabled'] && $g['ga4_id']): ?>
            // GA4 purchase event
            gtag('event', 'purchase', {
                transaction_id: <?php echo wp_json_encode($tx_id); ?>,
                value: <?php echo wp_json_encode($value); ?>,
                currency: <?php echo wp_json_encode($currency); ?>
            });
            <?php endif; ?>
            <?php if ($g['ads_enabled'] && $g['ads_id'] && $g['ads_label'] && $g['ads_send_purchase']): ?>
            // Google Ads Purchase Conversion
            gtag('event', 'conversion', {
                send_to: <?php echo wp_json_encode($g['ads_id'] . '/' . $g['ads_label']); ?>,
                value: <?php echo wp_json_encode($value); ?>,
                currency: <?php echo wp_json_encode($currency); ?>,
                transaction_id: <?php echo wp_json_encode($tx_id); ?>
            });
            <?php endif; ?>
        }
        </script>
        <?php
    }
}
