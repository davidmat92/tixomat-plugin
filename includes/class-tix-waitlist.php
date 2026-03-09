<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat Waitlist & Presale Notifications
 *
 * Handles:
 * - Presale countdown + "notify me" email collection
 * - Sold-out waitlist email collection
 * - Auto-notification when presale starts or stock returns
 */
class TIX_Waitlist {

    const TABLE = 'tix_waitlist';

    public static function init() {
        // Frontend: Shortcode JS/CSS wird über Ticket-Selektor geladen
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
            email       VARCHAR(255)    NOT NULL,
            name        VARCHAR(255)    DEFAULT '',
            type        VARCHAR(20)     NOT NULL DEFAULT 'presale',
            notified    TINYINT(1)      DEFAULT 0,
            created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_entry (event_id, email, type),
            KEY idx_event_type (event_id, type)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ════════════════════════════════════════
       AJAX: Join Waitlist / Presale Notify
       ════════════════════════════════════════ */
    public static function ajax_join() {
        // Rate limiting
        $ip_key = 'tix_wl_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
        if (get_transient($ip_key)) {
            wp_send_json_error(['message' => 'Bitte warte einen Moment.'], 429);
        }

        $event_id = intval($_POST['event_id'] ?? 0);
        $email    = sanitize_email($_POST['email'] ?? '');
        $type     = in_array($_POST['type'] ?? '', ['presale', 'soldout']) ? $_POST['type'] : 'presale';
        $nonce    = $_POST['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'tix_waitlist_' . $event_id)) {
            wp_send_json_error(['message' => 'Ungültige Anfrage.'], 403);
        }

        if (!$event_id || !is_email($email)) {
            wp_send_json_error(['message' => 'Bitte gib eine gültige E-Mail-Adresse ein.']);
        }

        // Check event exists
        if (get_post_type($event_id) !== 'event') {
            wp_send_json_error(['message' => 'Event nicht gefunden.']);
        }

        // Check waitlist enabled
        $s = tix_get_settings();
        if (empty($s['waitlist_enabled'])) {
            wp_send_json_error(['message' => 'Die Warteliste ist nicht verfügbar.']);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Insert (or ignore duplicate)
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (event_id, email, type) VALUES (%d, %s, %s)",
            $event_id, $email, $type
        ));

        if ($result === false) {
            wp_send_json_error(['message' => 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.']);
        }

        // Rate limit: 5 seconds
        set_transient($ip_key, 1, 5);

        $msg = $type === 'presale'
            ? 'Du wirst benachrichtigt, sobald der Vorverkauf startet!'
            : 'Du stehst auf der Warteliste und wirst benachrichtigt, wenn Tickets verfügbar werden!';

        wp_send_json_success(['message' => $msg]);
    }

    /* ════════════════════════════════════════
       CRON: Check & Notify
       ════════════════════════════════════════ */
    public static function cron_check() {
        $s = tix_get_settings();
        if (empty($s['waitlist_enabled'])) return;

        self::check_presale_starts();
        self::check_stock_returns();
    }

    /**
     * Notify presale subscribers when presale_start has arrived
     */
    private static function check_presale_starts() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $now   = current_time('mysql');

        // Find events with presale_start <= now AND unnotified presale entries
        $events = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT w.event_id FROM $table w
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = w.event_id AND pm.meta_key = '_tix_presale_start'
             WHERE w.type = 'presale' AND w.notified = 0 AND pm.meta_value != '' AND pm.meta_value <= %s",
            $now
        ));

        foreach ($events as $event_id) {
            // Only notify if presale is actually active now
            $presale_active = get_post_meta($event_id, '_tix_presale_active', true);
            if ($presale_active !== '1') continue;

            $entries = $wpdb->get_results($wpdb->prepare(
                "SELECT email FROM $table WHERE event_id = %d AND type = 'presale' AND notified = 0",
                $event_id
            ));

            if (empty($entries)) continue;

            $title = get_the_title($event_id);
            $url   = get_permalink($event_id);

            foreach ($entries as $entry) {
                self::send_notification($entry->email, $title, $url, 'presale');
            }

            // Mark all as notified
            $wpdb->update($table,
                ['notified' => 1],
                ['event_id' => $event_id, 'type' => 'presale', 'notified' => 0],
                ['%d'],
                ['%d', '%s', '%d']
            );
        }
    }

    /**
     * Notify soldout subscribers when stock returns
     */
    private static function check_stock_returns() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Find events with unnotified soldout entries
        $events = $wpdb->get_col(
            "SELECT DISTINCT event_id FROM $table WHERE type = 'soldout' AND notified = 0"
        );

        foreach ($events as $event_id) {
            // Check if any ticket category has stock
            $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
            if (!is_array($cats)) continue;

            $has_stock = false;
            foreach ($cats as $cat) {
                $pid = intval($cat['product_id'] ?? 0);
                if (!$pid) continue;
                $product = wc_get_product($pid);
                if ($product && $product->is_in_stock()) {
                    $has_stock = true;
                    break;
                }
            }

            if (!$has_stock) continue;

            $entries = $wpdb->get_results($wpdb->prepare(
                "SELECT email FROM $table WHERE event_id = %d AND type = 'soldout' AND notified = 0",
                $event_id
            ));

            if (empty($entries)) continue;

            $title = get_the_title($event_id);
            $url   = get_permalink($event_id);

            foreach ($entries as $entry) {
                self::send_notification($entry->email, $title, $url, 'soldout');
            }

            // Mark as notified
            $wpdb->update($table,
                ['notified' => 1],
                ['event_id' => $event_id, 'type' => 'soldout', 'notified' => 0],
                ['%d'],
                ['%d', '%s', '%d']
            );
        }
    }

    /**
     * Send notification email
     */
    private static function send_notification($email, $event_title, $event_url, $type) {
        $s = tix_get_settings();
        $brand = !empty($s['email_brand_name']) ? $s['email_brand_name'] : get_bloginfo('name');

        if ($type === 'presale') {
            $subject = 'Der Vorverkauf für ' . $event_title . ' hat begonnen!';
            $body    = '<h2 style="margin:0 0 16px;">🎉 Der Vorverkauf ist gestartet!</h2>'
                     . '<p>Der Ticketverkauf für <strong>' . esc_html($event_title) . '</strong> hat begonnen.</p>'
                     . '<p style="margin:24px 0;"><a href="' . esc_url($event_url) . '" style="display:inline-block;padding:12px 28px;background:#6366f1;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Jetzt Tickets sichern</a></p>';
        } else {
            $subject = 'Tickets für ' . $event_title . ' wieder verfügbar!';
            $body    = '<h2 style="margin:0 0 16px;">🎫 Tickets wieder verfügbar!</h2>'
                     . '<p>Gute Nachrichten! Für <strong>' . esc_html($event_title) . '</strong> sind wieder Tickets verfügbar.</p>'
                     . '<p style="margin:24px 0;"><a href="' . esc_url($event_url) . '" style="display:inline-block;padding:12px 28px;background:#6366f1;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Jetzt Tickets sichern</a></p>';
        }

        // Use TIX_Emails template if available
        if (class_exists('TIX_Emails') && method_exists('TIX_Emails', 'wrap_template')) {
            $body = TIX_Emails::wrap_template($body, $s);
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if ($brand) {
            $from = get_option('admin_email');
            $headers[] = 'From: ' . $brand . ' <' . $from . '>';
        }

        wp_mail($email, $subject, $body, $headers);
    }

    /* ════════════════════════════════════════
       HELPERS
       ════════════════════════════════════════ */

    /**
     * Get count of waitlist entries for an event
     */
    public static function get_count($event_id, $type = null) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        if ($type) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE event_id = %d AND type = %s",
                $event_id, $type
            ));
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d",
            $event_id
        ));
    }
}
