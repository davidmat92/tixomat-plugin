<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat Post-Event Feedback
 *
 * Handles:
 * - Star ratings (1-5) + optional comment
 * - Token-based verification (only ticket holders)
 * - Shortcode [tix_feedback]
 * - Follow-Up Email star integration
 * - Average rating display on event page
 */
class TIX_Feedback {

    const TABLE = 'tix_feedback';

    public static function init() {
        add_shortcode('tix_feedback', [__CLASS__, 'shortcode']);
    }

    /* ════════════════════════════════════════
       DB TABLE
       ════════════════════════════════════════ */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id          BIGINT UNSIGNED AUTO_INCREMENT,
            event_id    BIGINT UNSIGNED NOT NULL,
            order_id    BIGINT UNSIGNED DEFAULT 0,
            email       VARCHAR(255)    NOT NULL,
            name        VARCHAR(255)    DEFAULT '',
            rating      TINYINT UNSIGNED NOT NULL,
            comment     TEXT            DEFAULT '',
            token       VARCHAR(64)     NOT NULL,
            created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_feedback (event_id, email),
            KEY idx_event (event_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ════════════════════════════════════════
       TOKEN
       ════════════════════════════════════════ */
    public static function generate_token($order_id, $event_id, $email) {
        return hash('sha256', $order_id . '|' . $event_id . '|' . $email . '|' . wp_salt());
    }

    /* ════════════════════════════════════════
       SHORTCODE [tix_feedback]
       ════════════════════════════════════════ */
    public static function shortcode($atts = []) {
        $atts    = shortcode_atts(['id' => 0], $atts, 'tix_feedback');
        $post_id = $atts['id'] ? intval($atts['id']) : get_the_ID();

        if (!$post_id || get_post_type($post_id) !== 'event') {
            return '';
        }

        $s = tix_get_settings();
        if (empty($s['feedback_enabled'])) return '';

        // Token from URL?
        $token  = sanitize_text_field($_GET['tix_feedback'] ?? '');
        $rating = intval($_GET['rating'] ?? 0);

        self::enqueue();

        ob_start();

        if ($token) {
            // Token-basiert: Formular oder Danke
            self::render_form($post_id, $token, $rating);
        } else {
            // Öffentlich: Durchschnitt anzeigen
            self::render_public($post_id);
        }

        return ob_get_clean();
    }

    /* ── Formular (Token-Zugang) ── */
    private static function render_form($event_id, $token, $prefill_rating) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Check if already submitted with this token
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE event_id = %d AND token = %s",
            $event_id, $token
        ));

        if ($existing) {
            ?>
            <div class="tix-fb">
                <div class="tix-fb-thanks">
                    <div class="tix-fb-thanks-icon">🎉</div>
                    <p class="tix-fb-thanks-title">Danke für dein Feedback!</p>
                    <p class="tix-fb-thanks-text">Du hast <?php echo intval($existing->rating); ?> von 5 Sternen vergeben.</p>
                </div>
            </div>
            <?php
            return;
        }

        $event_title = get_the_title($event_id);
        $nonce = wp_create_nonce('tix_feedback_' . $event_id);
        ?>
        <div class="tix-fb">
            <div class="tix-fb-header">
                <h3 class="tix-fb-title">Wie hat dir das Event gefallen?</h3>
                <p class="tix-fb-subtitle"><?php echo esc_html($event_title); ?></p>
            </div>
            <form class="tix-fb-form" data-event="<?php echo $event_id; ?>" data-token="<?php echo esc_attr($token); ?>" data-nonce="<?php echo $nonce; ?>">
                <div class="tix-fb-stars" data-rating="<?php echo $prefill_rating ?: 0; ?>">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" class="tix-fb-star<?php echo $i <= $prefill_rating ? ' active' : ''; ?>" data-value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> Stern<?php echo $i > 1 ? 'e' : ''; ?>">★</button>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="rating" value="<?php echo $prefill_rating ?: 0; ?>">
                <textarea name="comment" class="tix-fb-comment" placeholder="Möchtest du uns noch etwas mitteilen? (optional)" rows="3"></textarea>
                <button type="submit" class="tix-fb-submit" <?php echo !$prefill_rating ? 'disabled' : ''; ?>>Feedback absenden</button>
                <div class="tix-fb-msg" hidden></div>
            </form>
        </div>
        <?php
    }

    /* ── Öffentliche Anzeige (Durchschnitt) ── */
    private static function render_public($event_id) {
        $avg   = floatval(get_post_meta($event_id, '_tix_feedback_avg', true));
        $count = intval(get_post_meta($event_id, '_tix_feedback_count', true));

        if ($count === 0) return;

        ?>
        <div class="tix-fb tix-fb-public">
            <div class="tix-fb-avg">
                <span class="tix-fb-avg-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="tix-fb-avg-star<?php echo $i <= round($avg) ? ' filled' : ''; ?>">★</span>
                    <?php endfor; ?>
                </span>
                <span class="tix-fb-avg-value"><?php echo number_format($avg, 1, ',', ''); ?></span>
                <span class="tix-fb-avg-count">(<?php echo $count; ?> Bewertung<?php echo $count !== 1 ? 'en' : ''; ?>)</span>
            </div>
        </div>
        <?php
    }

    /* ════════════════════════════════════════
       AJAX: Submit Feedback
       ════════════════════════════════════════ */
    public static function ajax_submit() {
        $event_id = intval($_POST['event_id'] ?? 0);
        $token    = sanitize_text_field($_POST['token'] ?? '');
        $rating   = intval($_POST['rating'] ?? 0);
        $comment  = sanitize_textarea_field($_POST['comment'] ?? '');
        $nonce    = $_POST['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'tix_feedback_' . $event_id)) {
            wp_send_json_error(['message' => 'Ungültige Anfrage.'], 403);
        }

        if (!$event_id || !$token || $rating < 1 || $rating > 5) {
            wp_send_json_error(['message' => 'Bitte wähle eine Bewertung aus.']);
        }

        if (get_post_type($event_id) !== 'event') {
            wp_send_json_error(['message' => 'Event nicht gefunden.']);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Check: already submitted?
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE event_id = %d AND token = %s",
            $event_id, $token
        ));

        if ($existing) {
            wp_send_json_error(['message' => 'Du hast bereits Feedback gegeben.']);
        }

        // Find order/email by token (verify token is valid)
        // We need to reconstruct – check all orders for this event
        $valid = false;
        $email = '';
        $order_id = 0;
        $name = '';

        // Search recent orders
        $orders = wc_get_orders([
            'limit'  => 500,
            'status' => ['completed', 'processing'],
            'meta_key' => '_tix_event_id',
            'meta_value' => $event_id,
        ]);

        foreach ($orders as $o) {
            $o_email = $o->get_billing_email();
            $expected = self::generate_token($o->get_id(), $event_id, $o_email);
            if (hash_equals($expected, $token)) {
                $valid = true;
                $email = $o_email;
                $order_id = $o->get_id();
                $name = $o->get_billing_first_name() . ' ' . $o->get_billing_last_name();
                break;
            }
        }

        if (!$valid) {
            wp_send_json_error(['message' => 'Ungültiger Zugangslink.']);
        }

        // Insert
        $result = $wpdb->insert($table, [
            'event_id' => $event_id,
            'order_id' => $order_id,
            'email'    => $email,
            'name'     => $name,
            'rating'   => $rating,
            'comment'  => $comment,
            'token'    => $token,
        ], ['%d', '%d', '%s', '%s', '%d', '%s', '%s']);

        if ($result === false) {
            wp_send_json_error(['message' => 'Feedback konnte nicht gespeichert werden.']);
        }

        // Update cached averages
        self::update_cache($event_id);

        wp_send_json_success(['message' => 'Vielen Dank für dein Feedback!']);
    }

    /* ── Cache aktualisieren ── */
    public static function update_cache($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM $table WHERE event_id = %d",
            $event_id
        ));

        $avg   = $stats ? round(floatval($stats->avg_rating), 1) : 0;
        $count = $stats ? intval($stats->total) : 0;

        update_post_meta($event_id, '_tix_feedback_avg', $avg);
        update_post_meta($event_id, '_tix_feedback_count', $count);
    }

    /* ════════════════════════════════════════
       FOLLOW-UP EMAIL: Star Links
       ════════════════════════════════════════ */
    public static function get_email_stars_html($order_id, $event_id, $email) {
        $token = self::generate_token($order_id, $event_id, $email);
        $url   = get_permalink($event_id);

        // Sterne als Links
        $html = '
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">
            <tr>
                <td align="center" style="font-size: 14px; color: #374151; padding-bottom: 8px;">
                    <strong>Wie hat dir das Event gefallen?</strong>
                </td>
            </tr>
            <tr>
                <td align="center">';

        for ($i = 1; $i <= 5; $i++) {
            $link = esc_url(add_query_arg([
                'tix_feedback' => $token,
                'rating'       => $i,
            ], $url));
            $html .= '<a href="' . $link . '" style="font-size:32px;text-decoration:none;color:#fbbf24;padding:0 2px;">★</a>';
        }

        $html .= '
                </td>
            </tr>
        </table>';

        return $html;
    }

    /* ════════════════════════════════════════
       EVENT PAGE: Rating Badge
       ════════════════════════════════════════ */
    public static function get_rating_badge_html($event_id) {
        $avg   = floatval(get_post_meta($event_id, '_tix_feedback_avg', true));
        $count = intval(get_post_meta($event_id, '_tix_feedback_count', true));

        if ($count === 0) return '';

        return '<span class="tix-ep-rating">★ ' . number_format($avg, 1, ',', '') . ' <span class="tix-ep-rating-count">(' . $count . ')</span></span>';
    }

    /* ══ Assets ══ */
    private static function enqueue() {
        wp_enqueue_style('tix-feedback', TIXOMAT_URL . 'assets/css/feedback.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-feedback', TIXOMAT_URL . 'assets/js/feedback.js', [], TIXOMAT_VERSION, true);
        wp_localize_script('tix-feedback', 'tixFeedback', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    /* ════════════════════════════════════════
       ADMIN: Letzte Bewertungen
       ════════════════════════════════════════ */
    public static function get_recent($event_id, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT name, rating, comment, created_at FROM $table WHERE event_id = %d ORDER BY created_at DESC LIMIT %d",
            $event_id, $limit
        ));
    }
}
