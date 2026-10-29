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
        $event_id = intval($_POST['event_id'] ?? 0);
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tix_waitlist_' . $event_id)) {
            wp_send_json_error(['message' => 'Ungültige Anfrage.'], 403);
        }
        $r = self::join($event_id, $_POST['email'] ?? '', $_POST['type'] ?? '');
        if (!$r['ok']) wp_send_json_error(['message' => $r['message']], $r['code'] ?? 200);
        wp_send_json_success(['message' => $r['message']]);
    }

    /**
     * Auf die Warteliste setzen (Web per admin-ajax, Apps per REST). type: presale | soldout.
     * Rate-Limit 5 s je IP.
     * @return array{ok:bool,message:string,code?:int}
     */
    public static function join($event_id, $email, $type) {
        $ip_key = 'tix_wl_' . md5(class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (get_transient($ip_key)) return ['ok' => false, 'message' => 'Bitte warte einen Moment.', 'code' => 429];

        $event_id = intval($event_id);
        $email    = sanitize_email((string) $email);
        $type     = in_array($type, ['presale', 'soldout'], true) ? $type : 'presale';

        if (!$event_id || !is_email($email)) return ['ok' => false, 'message' => 'Bitte gib eine gültige E-Mail-Adresse ein.'];
        if (get_post_type($event_id) !== 'event') return ['ok' => false, 'message' => 'Event nicht gefunden.'];
        $s = tix_get_settings();
        if (empty($s['waitlist_enabled'])) return ['ok' => false, 'message' => 'Die Warteliste ist nicht verfügbar.'];

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        // Insert (or ignore duplicate)
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (event_id, email, type) VALUES (%d, %s, %s)",
            $event_id, $email, $type
        ));
        if ($result === false) return ['ok' => false, 'message' => 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.'];

        // Rate limit: 5 seconds
        set_transient($ip_key, 1, 5);
        if ($result) do_action('tix_waitlist_joined', intval($event_id), (string) $type); // Team-Mitteilung (1.38.595)
        return ['ok' => true, 'message' => $type === 'presale'
            ? 'Du wirst benachrichtigt, sobald der Vorverkauf startet!'
            : 'Du stehst auf der Warteliste und wirst benachrichtigt, wenn Tickets verfügbar werden!'];
    }

    /** Warteliste für dieses Event möglich? presale = vor Vorverkaufsstart, soldout = ausverkauft + Event-Schalter. */
    public static function available($event_id, $type) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (empty($s['waitlist_enabled'])) return false;
        if ($type === 'presale') return true;
        return get_post_meta($event_id, '_tix_waitlist_enabled', true) === '1';
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
                self::send_notification($entry->email, $title, $url, 'presale', $event_id);
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
            // Wieder Tickets da? (native Kasse; WooCommerce-Produkte nur, wenn WooCommerce aktiv ist)
            if (!self::has_stock(intval($event_id))) continue;

            $entries = $wpdb->get_results($wpdb->prepare(
                "SELECT email FROM $table WHERE event_id = %d AND type = 'soldout' AND notified = 0",
                $event_id
            ));

            if (empty($entries)) continue;

            $title = get_the_title($event_id);
            $url   = get_permalink($event_id);

            foreach ($entries as $entry) {
                // Check if user already purchased via native order
                if (class_exists('TIX_Order')) {
                    $existing = TIX_Order::query([
                        'email'  => $entry->email,
                        'status' => ['completed', 'processing'],
                    ]);
                    $already_bought = false;
                    foreach ($existing as $ord) {
                        foreach ($ord->get_items() as $item) {
                            if (method_exists($item, 'get_event_id') && $item->get_event_id() == $event_id) {
                                $already_bought = true;
                                break 2;
                            }
                        }
                    }
                    if ($already_bought) continue;
                }

                self::send_notification($entry->email, $title, $url, 'soldout', $event_id);
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
     * Gibt es für das Event wieder Tickets im Verkauf? Event veröffentlicht, Verkauf an, nicht
     * abgesagt/verschoben/vorbei; mindestens eine öffentliche Kategorie mit Bestand > 0 oder
     * unbegrenzt (native Kasse: `stock` in `_tix_ticket_categories`, -1/fehlt = unbegrenzt).
     */
    public static function has_stock($event_id) {
        $event_id = intval($event_id);
        if (!$event_id || get_post_status($event_id) !== 'publish') return false;
        if (get_post_meta($event_id, '_tix_tickets_enabled', true) !== '1') return false;
        if (in_array((string) get_post_meta($event_id, '_tix_status', true), ['cancelled', 'postponed', 'past'], true)) return false;
        wp_cache_delete($event_id, 'post_meta');
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats)) return false;
        $wc = function_exists('wc_get_product');
        foreach ($cats as $cat) {
            if (!is_array($cat)) continue;
            // nur Kategorien, die Gäste online kaufen können
            if (!empty($cat['admin_only']) || !empty($cat['offline_ticket']) || (isset($cat['online']) && (string) $cat['online'] === '0')) continue;
            if (!empty($cat['hidden']) && empty($cat['gift_card'])) continue;
            $pid = intval($cat['product_id'] ?? 0);
            if ($wc && $pid && ($product = wc_get_product($pid))) {
                if ($product->is_in_stock()) return true;
                continue;
            }
            $stock = isset($cat['stock']) && $cat['stock'] !== '' ? intval($cat['stock']) : -1;
            if ($stock < 0 || $stock > 0) return true;
        }
        return false;
    }

    /**
     * Send notification email
     */
    private static function send_notification($email, $event_title, $event_url, $type, $event_id = 0) {
        // Seit 1.38.471 im Mail-Layout und mit dem Absender der Seite (vorher nacktes HTML-Fragment, Absender = Admin-Adresse)
        $title = html_entity_decode((string) $event_title, ENT_QUOTES, 'UTF-8');
        if ($type === 'presale') {
            $subject = 'Der Vorverkauf für ' . $title . ' hat begonnen!';
            $heading = 'Der Vorverkauf ist gestartet!';
            $text    = 'Der Ticketverkauf für <strong>' . esc_html($title) . '</strong> hat begonnen.';
        } else {
            $subject = 'Tickets für ' . $title . ' wieder verfügbar!';
            $heading = 'Tickets wieder verfügbar!';
            $text    = 'Gute Nachrichten! Für <strong>' . esc_html($title) . '</strong> sind wieder Tickets verfügbar.';
        }
        $blocks = [['type' => 'text', 'html' => 'Hallo,<br>' . $text]];
        if ($event_id && class_exists('TIX_Emails') && ($ev = TIX_Emails::event_block($event_id))) $blocks[] = $ev;
        $blocks[] = ['type' => 'button', 'label' => 'Jetzt Tickets sichern', 'url' => $event_url];
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : get_bloginfo('name');
        $m = ['title' => $heading, 'blocks' => $blocks, 'kind' => 'customer',
              'note' => 'Du bekommst diese E-Mail, weil du dich bei ' . $brand . ' für ' . $title . ' auf die Warteliste gesetzt hast.'];
        if (class_exists('TIX_Mail_Layout')) return TIX_Mail_Layout::send($email, $subject, $m);
        return TIX_Customer_URL::mail($email, $subject, '<p>' . $text . '</p>', ['Content-Type: text/html; charset=UTF-8']);
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
