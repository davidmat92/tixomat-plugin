<?php
/**
 * TIX Promoted Events — Bezahltes Placement in der Event-Homepage.
 *
 * Setzt pro Event:
 *   _tix_promoted          (1|0)
 *   _tix_promoted_priority (int, höher = weiter oben)
 *   _tix_promoted_until    (Y-m-d, optional — danach läuft die Promotion ab)
 *
 * Admins sehen Metabox direkt; Organisatoren bekommen sie nicht (nur Admins
 * können Promotions aktivieren, da Paid-Placement).
 */
if (!defined('ABSPATH')) exit;

class TIX_Promoted_Events {

    public static function init() {
        add_action('add_meta_boxes_event', [__CLASS__, 'register_metabox']);
        add_action('save_post_event',      [__CLASS__, 'save']);
    }

    public static function register_metabox() {
        if (!current_user_can('manage_options')) return; // Nur Admins
        add_meta_box(
            'tix_event_promoted',
            '✨ Promoted Event',
            [__CLASS__, 'render_metabox'],
            'event',
            'side',
            'default'
        );
    }

    public static function render_metabox($post) {
        wp_nonce_field('tix_promoted_save', 'tix_promoted_nonce');

        $promoted = (bool) get_post_meta($post->ID, '_tix_promoted', true);
        $priority = intval(get_post_meta($post->ID, '_tix_promoted_priority', true));
        $until    = get_post_meta($post->ID, '_tix_promoted_until', true);
        ?>
        <p style="margin:0 0 10px;">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                <input type="checkbox" name="tix_promoted" value="1" <?php checked($promoted); ?>>
                <strong>Als Promoted Event markieren</strong>
            </label>
        </p>
        <p style="margin:0 0 10px;color:#64748b;font-size:12px;">
            Erscheint in der „Empfohlene Events"-Sektion der Homepage.
        </p>

        <p style="margin:0 0 10px;">
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Priorität (höher = weiter oben)</label>
            <input type="number" name="tix_promoted_priority" value="<?php echo esc_attr($priority ?: 10); ?>" min="0" max="1000" style="width:100%;">
        </p>

        <p style="margin:0 0 6px;">
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Aktiv bis (optional)</label>
            <input type="date" name="tix_promoted_until" value="<?php echo esc_attr($until); ?>" style="width:100%;">
        </p>
        <p style="margin:0;color:#94a3b8;font-size:11px;">
            Leer lassen = bis Event startet. Nach diesem Datum wird die Promotion automatisch ausgeblendet.
        </p>
        <?php
    }

    public static function save($post_id) {
        if (!isset($_POST['tix_promoted_nonce'])) return;
        if (!wp_verify_nonce($_POST['tix_promoted_nonce'], 'tix_promoted_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('manage_options')) return;

        $promoted = !empty($_POST['tix_promoted']) ? '1' : '0';
        update_post_meta($post_id, '_tix_promoted', $promoted);

        if ($promoted === '1') {
            update_post_meta($post_id, '_tix_promoted_priority', max(0, min(1000, intval($_POST['tix_promoted_priority'] ?? 10))));
            $until = sanitize_text_field($_POST['tix_promoted_until'] ?? '');
            if ($until) update_post_meta($post_id, '_tix_promoted_until', $until);
            else delete_post_meta($post_id, '_tix_promoted_until');
        } else {
            delete_post_meta($post_id, '_tix_promoted_priority');
            delete_post_meta($post_id, '_tix_promoted_until');
        }
    }
}
