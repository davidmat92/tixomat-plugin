<?php
if (!defined('ABSPATH')) exit;
/**
 * Tixomat – FAQ Shortcode [tix_faq]
 *
 * Renders an accordion FAQ from post meta.
 * Place on any Event single template (or pass id="123").
 */

if (!defined('ABSPATH')) exit;

class TIX_FAQ {

    public static function init() {
        add_shortcode('tix_faq', [__CLASS__, 'render']);
    }

    /**
     * Shortcode: [tix_faq]
     *
     * Attributes:
     *   id     – Event-Post-ID (default: aktueller Post)
     *   title  – Überschrift über dem Accordion (default: "Häufige Fragen")
     *   class  – Zusätzliche CSS-Klasse
     *   wide   – Volle Breite, ohne Heading, Border aus Settings (default: 0)
     */
    public static function render($atts = []) {
        $atts = shortcode_atts([
            'id'    => 0,
            'title' => 'Häufige Fragen',
            'class' => '',
            'wide'  => 0,
        ], $atts, 'tix_faq');

        $post_id = (int) $atts['id'] ?: get_the_ID();
        if (!$post_id) return '';

        $faqs = get_post_meta($post_id, '_tix_faq', true);
        if (!is_array($faqs) || empty($faqs)) return '';

        // Nur FAQs mit Frage ausgeben
        $faqs = array_filter($faqs, function($f) {
            return !empty($f['q']);
        });
        if (empty($faqs)) return '';

        // Assets laden
        wp_enqueue_style('tix-faq', TIXOMAT_URL . 'assets/css/faq.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-faq', TIXOMAT_URL . 'assets/js/faq.js', [], TIXOMAT_VERSION, true);

        $extra_class = $atts['class'] ? ' ' . esc_attr($atts['class']) : '';
        $is_wide = !empty($atts['wide']) && $atts['wide'] !== '0';
        if ($is_wide) $extra_class .= ' tix-faq-wide';

        ob_start();
        ?>
        <div class="tix-faq<?php echo $extra_class; ?>">

            <?php if (!$is_wide && !empty($atts['title'])): ?>
                <h3 class="tix-faq-title"><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>

            <div class="tix-faq-list">
                <?php foreach ($faqs as $i => $faq): ?>
                    <div class="tix-faq-item">
                        <button type="button" class="tix-faq-question" aria-expanded="false">
                            <span class="tix-faq-q-text"><?php echo esc_html($faq['q']); ?></span>
                            <span class="tix-faq-icon" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                                    <path d="M5 8l5 5 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                        </button>
                        <div class="tix-faq-answer" hidden>
                            <div class="tix-faq-a-inner">
                                <?php echo wpautop(wp_kses_post($faq['a'])); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php echo tix_branding_footer(); ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
