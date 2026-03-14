<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Exit_Intent – Exit-Intent Popup mit Rabattcode.
 *
 * Desktop: mouseleave-Event (Maus verlässt Viewport oben).
 * Mobile: Scroll-Up-Pattern + Back-Button Detection.
 * Cookie verhindert Wiederholung.
 *
 * @since 1.28.90
 */
class TIX_Exit_Intent {

    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_tix_exit_intent_impression', [__CLASS__, 'track_impression']);
        add_action('wp_ajax_nopriv_tix_exit_intent_impression', [__CLASS__, 'track_impression']);
    }

    /**
     * Assets auf Event-Seiten laden.
     */
    public static function enqueue_assets() {
        if (!is_singular('event')) return;

        $post_id = get_the_ID();
        $s = tix_get_settings();

        // Per-Event Override prüfen
        $event_enabled = get_post_meta($post_id, '_tix_exit_intent_enabled', true);
        if ($event_enabled === '0') return; // Explizit deaktiviert

        $coupon_code = get_post_meta($post_id, '_tix_exit_intent_coupon', true);
        if (empty($coupon_code)) {
            $coupon_code = $s['exit_intent_coupon_code'] ?? '';
        }
        if (empty($coupon_code)) return; // Kein Coupon → nichts anzeigen

        // Rabatt-Info aus WC-Coupon lesen
        $discount_text = self::get_coupon_discount_text($coupon_code);

        wp_enqueue_style('tix-exit-intent', TIXOMAT_URL . 'assets/css/exit-intent.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-exit-intent', TIXOMAT_URL . 'assets/js/exit-intent.js', [], TIXOMAT_VERSION, true);

        $headline = $s['exit_intent_headline'] ?? 'Warte! Hier ist dein Rabatt';
        $text     = $s['exit_intent_text'] ?? 'Sichere dir {discount} Rabatt mit folgendem Code:';
        $text     = str_replace('{discount}', $discount_text, $text);

        wp_localize_script('tix-exit-intent', 'tixExitIntent', [
            'headline'   => $headline,
            'text'       => $text,
            'coupon'     => strtoupper($coupon_code),
            'buttonText' => $s['exit_intent_button_text'] ?? 'Jetzt einlösen',
            'cookieDays' => intval($s['exit_intent_cookie_days'] ?? 7),
            'delay'      => intval($s['exit_intent_delay'] ?? 5) * 1000,
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'eventId'    => $post_id,
            'nonce'      => wp_create_nonce('tix_exit_intent'),
        ]);
    }

    /**
     * Rabatt-Text aus WC-Coupon ermitteln.
     */
    private static function get_coupon_discount_text($code) {
        if (!function_exists('WC')) return '';

        $coupon = new \WC_Coupon($code);
        if (!$coupon->get_id()) return '';

        $amount = $coupon->get_amount();
        $type   = $coupon->get_discount_type();

        if ($type === 'percent') {
            return intval($amount) . '%';
        } else {
            return number_format($amount, 2, ',', '.') . ' €';
        }
    }

    /**
     * Impression tracken (optional, für Analytics).
     */
    public static function track_impression() {
        check_ajax_referer('tix_exit_intent', 'nonce');
        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error();

        // Counter in Transient
        $key = 'tix_ei_impressions_' . $event_id;
        $count = intval(get_transient($key));
        set_transient($key, $count + 1, MONTH_IN_SECONDS);

        wp_send_json_success();
    }
}
