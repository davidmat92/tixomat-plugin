<?php
/**
 * Tixomat – Custom Ticket Database
 *
 * Speichert Ticket- und Käuferdaten in einer eigenen
 * WordPress-Tabelle für schnellen Zugriff und Sync.
 *
 * @since 1.27.0
 */

if (!defined('ABSPATH')) exit;

class TIX_Ticket_DB {

    const TABLE_SUFFIX = 'tixomat_tickets';

    // ──────────────────────────────────────────
    // DB-Tabelle erstellen (Plugin-Aktivierung)
    // ──────────────────────────────────────────
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id              BIGINT UNSIGNED AUTO_INCREMENT,
            ticket_post_id  BIGINT UNSIGNED DEFAULT NULL,
            ticket_code     VARCHAR(20) NOT NULL,
            event_id        BIGINT UNSIGNED NOT NULL,
            event_name      VARCHAR(255) DEFAULT '',
            order_id        BIGINT UNSIGNED DEFAULT NULL,
            category_name   VARCHAR(100) DEFAULT '',
            buyer_name      VARCHAR(255) NOT NULL DEFAULT '',
            buyer_email     VARCHAR(255) NOT NULL DEFAULT '',
            buyer_phone     VARCHAR(50) DEFAULT '',
            buyer_company   VARCHAR(255) DEFAULT '',
            buyer_city      VARCHAR(100) DEFAULT '',
            buyer_zip       VARCHAR(20) DEFAULT '',
            buyer_country   VARCHAR(10) DEFAULT '',
            seat_id         VARCHAR(50) DEFAULT '',
            ticket_status   VARCHAR(20) DEFAULT 'valid',
            ticket_price    DECIMAL(10,2) DEFAULT 0,
            checked_in      TINYINT(1) DEFAULT 0,
            checkin_time     DATETIME DEFAULT NULL,
            newsletter_optin TINYINT(1) DEFAULT 0,
            synced_supabase  TINYINT(1) DEFAULT 0,
            synced_airtable  TINYINT(1) DEFAULT 0,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_code (ticket_code),
            KEY event_id (event_id),
            KEY order_id (order_id),
            KEY buyer_email (buyer_email)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ──────────────────────────────────────────
    // Tabellenname
    // ──────────────────────────────────────────
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    // ──────────────────────────────────────────
    // Insert
    // ──────────────────────────────────────────
    public static function insert_ticket(array $data) {
        global $wpdb;

        $defaults = [
            'ticket_post_id'  => null,
            'ticket_code'     => '',
            'event_id'        => 0,
            'event_name'      => '',
            'order_id'        => null,
            'category_name'   => '',
            'buyer_name'      => '',
            'buyer_email'     => '',
            'buyer_phone'     => '',
            'buyer_company'   => '',
            'buyer_city'      => '',
            'buyer_zip'       => '',
            'buyer_country'   => '',
            'seat_id'         => '',
            'ticket_status'   => 'valid',
            'ticket_price'    => 0,
            'checked_in'      => 0,
            'checkin_time'    => null,
            'newsletter_optin' => 0,
            'synced_supabase' => 0,
            'synced_airtable' => 0,
        ];

        $row = array_intersect_key(array_merge($defaults, $data), $defaults);

        $formats = [
            'ticket_post_id'  => '%d',
            'ticket_code'     => '%s',
            'event_id'        => '%d',
            'event_name'      => '%s',
            'order_id'        => '%d',
            'category_name'   => '%s',
            'buyer_name'      => '%s',
            'buyer_email'     => '%s',
            'buyer_phone'     => '%s',
            'buyer_company'   => '%s',
            'buyer_city'      => '%s',
            'buyer_zip'       => '%s',
            'buyer_country'   => '%s',
            'seat_id'         => '%s',
            'ticket_status'   => '%s',
            'ticket_price'    => '%f',
            'checked_in'      => '%d',
            'checkin_time'    => '%s',
            'newsletter_optin' => '%d',
            'synced_supabase' => '%d',
            'synced_airtable' => '%d',
        ];

        // NULL-Werte korrekt behandeln
        if (empty($row['ticket_post_id'])) $row['ticket_post_id'] = null;
        if (empty($row['order_id']))       $row['order_id']       = null;
        if (empty($row['checkin_time']))    $row['checkin_time']   = null;

        $format = array_values(array_intersect_key($formats, $row));

        return $wpdb->insert(self::table(), $row, $format);
    }

    // ──────────────────────────────────────────
    // Update by ticket_code
    // ──────────────────────────────────────────
    public static function update_ticket(string $code, array $data) {
        global $wpdb;

        if (empty($code) || empty($data)) return false;

        // Nur erlaubte Spalten
        $allowed = [
            'ticket_status', 'checked_in', 'checkin_time',
            'buyer_name', 'buyer_email', 'buyer_phone',
            'buyer_company', 'buyer_city', 'buyer_zip', 'buyer_country',
            'seat_id', 'category_name', 'ticket_price',
            'newsletter_optin', 'synced_supabase', 'synced_airtable',
        ];
        $update = array_intersect_key($data, array_flip($allowed));
        if (empty($update)) return false;

        return $wpdb->update(self::table(), $update, ['ticket_code' => $code]);
    }

    // ──────────────────────────────────────────
    // Lesen
    // ──────────────────────────────────────────

    public static function get_by_code(string $code) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table() . " WHERE ticket_code = %s LIMIT 1", $code)
        );
    }

    public static function get_by_event(int $event_id, $limit = 0) {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM " . self::table() . " WHERE event_id = %d ORDER BY created_at DESC", $event_id);
        if ($limit > 0) $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        return $wpdb->get_results($sql);
    }

    public static function get_by_order(int $order_id) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM " . self::table() . " WHERE order_id = %d ORDER BY id ASC", $order_id)
        );
    }

    // ──────────────────────────────────────────
    // Unsynced Records
    // ──────────────────────────────────────────

    public static function get_unsynced(string $service, int $limit = 50) {
        global $wpdb;

        $column = ($service === 'supabase') ? 'synced_supabase' : 'synced_airtable';
        if (!in_array($column, ['synced_supabase', 'synced_airtable'])) return [];

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE {$column} = 0 ORDER BY id ASC LIMIT %d",
                $limit
            )
        );
    }

    public static function mark_synced(string $code, string $service) {
        $column = ($service === 'supabase') ? 'synced_supabase' : 'synced_airtable';
        if (!in_array($column, ['synced_supabase', 'synced_airtable'])) return false;

        return self::update_ticket($code, [$column => 1]);
    }

    // ──────────────────────────────────────────
    // Statistik
    // ──────────────────────────────────────────

    public static function count_by_event(int $event_id) {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM " . self::table() . " WHERE event_id = %d", $event_id)
        );
    }

    public static function count_unsynced(string $service) {
        global $wpdb;
        $column = ($service === 'supabase') ? 'synced_supabase' : 'synced_airtable';
        if (!in_array($column, ['synced_supabase', 'synced_airtable'])) return 0;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE {$column} = 0"
        );
    }

    // ──────────────────────────────────────────
    // Tabelle vorhanden?
    // ──────────────────────────────────────────

    public static function table_exists() {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    }
}
