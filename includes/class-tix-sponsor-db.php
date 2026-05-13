<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat — Sponsor Database Schema
 *
 * Drei Tabellen:
 *   tix_sponsors        — Sponsor-Stammdaten (Name, Kontakt-E-Mail, Status)
 *   tix_sponsor_pools   — Kontingente pro Sponsor (Event + Kategorie + Anzahl)
 *
 * Die ausgegebenen Tickets sind normale tix_ticket-Posts mit Sponsor-Meta:
 *   _tix_ticket_sponsor_id    — Verweis auf wp_tixomat_sponsors.id
 *   _tix_ticket_sponsor_pool_id — Verweis auf wp_tixomat_sponsor_pools.id
 *   _tix_ticket_personalized  — '1' wenn Name/Email gesetzt
 *
 * @since 1.38.171
 */
class TIX_Sponsor_DB {

    public static function table_sponsors() {
        global $wpdb;
        return $wpdb->prefix . 'tixomat_sponsors';
    }

    public static function table_pools() {
        global $wpdb;
        return $wpdb->prefix . 'tixomat_sponsor_pools';
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $ts = self::table_sponsors();
        dbDelta("CREATE TABLE $ts (
            id              BIGINT UNSIGNED AUTO_INCREMENT,
            name            VARCHAR(255) NOT NULL DEFAULT '',
            contact_name    VARCHAR(255) NOT NULL DEFAULT '',
            email           VARCHAR(190) NOT NULL DEFAULT '',
            notes           TEXT,
            status          VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email),
            KEY status (status)
        ) $charset;");

        $tp = self::table_pools();
        dbDelta("CREATE TABLE $tp (
            id              BIGINT UNSIGNED AUTO_INCREMENT,
            sponsor_id      BIGINT UNSIGNED NOT NULL,
            event_id        BIGINT UNSIGNED NOT NULL,
            cat_index       INT NOT NULL DEFAULT 0,
            cat_name        VARCHAR(190) NOT NULL DEFAULT '',
            total           INT UNSIGNED NOT NULL DEFAULT 0,
            used            INT UNSIGNED NOT NULL DEFAULT 0,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sponsor_id (sponsor_id),
            KEY event_id (event_id)
        ) $charset;");
    }

    public static function tables_exist() {
        global $wpdb;
        $t = self::table_sponsors();
        return $wpdb->get_var("SHOW TABLES LIKE '$t'") === $t;
    }

    /* ──── Sponsor CRUD ──── */

    public static function insert_sponsor(array $data): int {
        global $wpdb;
        $row = [
            'name'         => sanitize_text_field($data['name'] ?? ''),
            'contact_name' => sanitize_text_field($data['contact_name'] ?? ''),
            'email'        => sanitize_email($data['email'] ?? ''),
            'notes'        => sanitize_textarea_field($data['notes'] ?? ''),
            'status'       => sanitize_text_field($data['status'] ?? 'active'),
        ];
        $wpdb->insert(self::table_sponsors(), $row);
        return intval($wpdb->insert_id);
    }

    public static function update_sponsor(int $id, array $data) {
        global $wpdb;
        $allowed = ['name', 'contact_name', 'email', 'notes', 'status'];
        $upd = [];
        foreach ($allowed as $k) {
            if (!isset($data[$k])) continue;
            $upd[$k] = ($k === 'email')
                ? sanitize_email($data[$k])
                : (($k === 'notes') ? sanitize_textarea_field($data[$k]) : sanitize_text_field($data[$k]));
        }
        if (empty($upd)) return false;
        return $wpdb->update(self::table_sponsors(), $upd, ['id' => $id]);
    }

    public static function get_sponsor(int $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_sponsors() . " WHERE id = %d", $id
        ));
    }

    public static function get_sponsor_by_email(string $email) {
        global $wpdb;
        $email = strtolower(trim(sanitize_email($email)));
        if (!$email) return null;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_sponsors() . "
             WHERE LOWER(email) = %s AND status = 'active' LIMIT 1",
            $email
        ));
    }

    public static function get_all_sponsors() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::table_sponsors() . " ORDER BY created_at DESC"
        );
    }

    public static function delete_sponsor(int $id) {
        global $wpdb;
        return $wpdb->update(self::table_sponsors(), ['status' => 'inactive'], ['id' => $id]);
    }

    /* ──── Pool CRUD ──── */

    public static function insert_pool(array $data): int {
        global $wpdb;
        $row = [
            'sponsor_id' => intval($data['sponsor_id'] ?? 0),
            'event_id'   => intval($data['event_id'] ?? 0),
            'cat_index'  => intval($data['cat_index'] ?? 0),
            'cat_name'   => sanitize_text_field($data['cat_name'] ?? ''),
            'total'      => max(0, intval($data['total'] ?? 0)),
            'used'       => 0,
        ];
        $wpdb->insert(self::table_pools(), $row);
        return intval($wpdb->insert_id);
    }

    public static function get_pools_by_sponsor(int $sponsor_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, post.post_title AS event_title
             FROM " . self::table_pools() . " p
             LEFT JOIN {$wpdb->posts} post ON post.ID = p.event_id
             WHERE p.sponsor_id = %d ORDER BY p.created_at ASC",
            $sponsor_id
        ));
    }

    public static function get_pool(int $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_pools() . " WHERE id = %d", $id
        ));
    }

    public static function increment_used(int $pool_id, int $delta = 1) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table_pools() . " SET used = used + %d WHERE id = %d",
            $delta, $pool_id
        ));
    }

    public static function delete_pool(int $id) {
        global $wpdb;
        return $wpdb->delete(self::table_pools(), ['id' => $id]);
    }

    /* ──── Ticket-Listings ──── */

    /**
     * Liefert alle Tickets eines Sponsors (via Meta-Lookup).
     */
    public static function get_sponsor_tickets(int $sponsor_id, array $args = []) {
        global $wpdb;
        $where = "p.post_type = 'tix_ticket' AND p.post_status = 'publish' AND m.meta_key = '_tix_ticket_sponsor_id' AND m.meta_value = %s";
        $params = [(string) $sponsor_id];

        if (!empty($args['pool_id'])) {
            $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m2 WHERE m2.post_id = p.ID AND m2.meta_key = '_tix_ticket_sponsor_pool_id' AND m2.meta_value = %s)";
            $params[] = (string) intval($args['pool_id']);
        }

        $sql = "SELECT p.ID FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                WHERE $where ORDER BY p.ID ASC";
        return $wpdb->get_col($wpdb->prepare($sql, ...$params));
    }
}
