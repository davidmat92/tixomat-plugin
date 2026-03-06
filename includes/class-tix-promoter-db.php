<?php
/**
 * Tixomat – Promoter Database Layer
 *
 * Custom tables for promoter management, event assignments,
 * commissions and payouts.
 *
 * @since 1.29.0
 */

if (!defined('ABSPATH')) exit;

class TIX_Promoter_DB {

    // ──────────────────────────────────────────
    // Tabellennamen
    // ──────────────────────────────────────────

    public static function table_promoters() {
        global $wpdb;
        return $wpdb->prefix . 'tixomat_promoters';
    }

    public static function table_events() {
        global $wpdb;
        return $wpdb->prefix . 'tixomat_promoter_events';
    }

    public static function table_commissions() {
        global $wpdb;
        return $wpdb->prefix . 'tixomat_promoter_commissions';
    }

    public static function table_payouts() {
        global $wpdb;
        return $wpdb->prefix . 'tixomat_promoter_payouts';
    }

    // ──────────────────────────────────────────
    // Tabellen erstellen (Aktivierung + Migration)
    // ──────────────────────────────────────────

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Promoter
        $t1 = self::table_promoters();
        dbDelta("CREATE TABLE $t1 (
            id              BIGINT UNSIGNED AUTO_INCREMENT,
            user_id         BIGINT UNSIGNED NOT NULL,
            promoter_code   VARCHAR(30) NOT NULL,
            status          VARCHAR(20) DEFAULT 'active',
            display_name    VARCHAR(255) DEFAULT '',
            notes           TEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY promoter_code (promoter_code),
            UNIQUE KEY user_id (user_id),
            KEY status (status)
        ) $charset;");

        // Promoter ↔ Event Zuordnung
        $t2 = self::table_events();
        dbDelta("CREATE TABLE $t2 (
            id              BIGINT UNSIGNED AUTO_INCREMENT,
            promoter_id     BIGINT UNSIGNED NOT NULL,
            event_id        BIGINT UNSIGNED NOT NULL,
            commission_type VARCHAR(10) DEFAULT 'percent',
            commission_value DECIMAL(10,2) DEFAULT 0,
            discount_type   VARCHAR(10) DEFAULT '',
            discount_value  DECIMAL(10,2) DEFAULT 0,
            promo_code      VARCHAR(50) DEFAULT '',
            coupon_id       BIGINT UNSIGNED DEFAULT NULL,
            status          VARCHAR(20) DEFAULT 'active',
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY promoter_event (promoter_id, event_id),
            KEY event_id (event_id),
            KEY promo_code (promo_code)
        ) $charset;");

        // Provisionen
        $t3 = self::table_commissions();
        dbDelta("CREATE TABLE $t3 (
            id              BIGINT UNSIGNED AUTO_INCREMENT,
            promoter_id     BIGINT UNSIGNED NOT NULL,
            event_id        BIGINT UNSIGNED NOT NULL,
            order_id        BIGINT UNSIGNED NOT NULL,
            order_item_id   BIGINT UNSIGNED DEFAULT NULL,
            attribution     VARCHAR(20) DEFAULT 'referral',
            tickets_qty     INT UNSIGNED DEFAULT 0,
            order_total     DECIMAL(10,2) DEFAULT 0,
            commission_amount DECIMAL(10,2) DEFAULT 0,
            discount_amount DECIMAL(10,2) DEFAULT 0,
            status          VARCHAR(20) DEFAULT 'pending',
            payout_id       BIGINT UNSIGNED DEFAULT NULL,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY promoter_id (promoter_id),
            KEY order_id (order_id),
            KEY event_id (event_id),
            KEY status (status),
            KEY payout_id (payout_id)
        ) $charset;");

        // Auszahlungen
        $t4 = self::table_payouts();
        dbDelta("CREATE TABLE $t4 (
            id              BIGINT UNSIGNED AUTO_INCREMENT,
            promoter_id     BIGINT UNSIGNED NOT NULL,
            period_from     DATE NOT NULL,
            period_to       DATE NOT NULL,
            total_sales     DECIMAL(10,2) DEFAULT 0,
            total_commission DECIMAL(10,2) DEFAULT 0,
            total_discount  DECIMAL(10,2) DEFAULT 0,
            commission_count INT UNSIGNED DEFAULT 0,
            status          VARCHAR(20) DEFAULT 'pending',
            paid_date       DATETIME DEFAULT NULL,
            payment_note    TEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY promoter_id (promoter_id),
            KEY status (status)
        ) $charset;");
    }

    public static function tables_exist() {
        global $wpdb;
        $t = self::table_promoters();
        return $wpdb->get_var("SHOW TABLES LIKE '$t'") === $t;
    }

    // ══════════════════════════════════════════
    // PROMOTER CRUD
    // ══════════════════════════════════════════

    public static function insert_promoter(array $data) {
        global $wpdb;
        $row = [
            'user_id'       => intval($data['user_id'] ?? 0),
            'promoter_code' => sanitize_text_field($data['promoter_code'] ?? ''),
            'status'        => sanitize_text_field($data['status'] ?? 'active'),
            'display_name'  => sanitize_text_field($data['display_name'] ?? ''),
            'notes'         => sanitize_textarea_field($data['notes'] ?? ''),
        ];
        $wpdb->insert(self::table_promoters(), $row);
        return $wpdb->insert_id;
    }

    public static function update_promoter(int $id, array $data) {
        global $wpdb;
        $allowed = ['promoter_code', 'status', 'display_name', 'notes'];
        $update = [];
        foreach ($allowed as $k) {
            if (isset($data[$k])) $update[$k] = sanitize_text_field($data[$k]);
        }
        if (empty($update)) return false;
        return $wpdb->update(self::table_promoters(), $update, ['id' => $id]);
    }

    public static function get_promoter(int $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_promoters() . " WHERE id = %d", $id
        ));
    }

    public static function get_promoter_by_code(string $code) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_promoters() . " WHERE promoter_code = %s AND status = 'active'", $code
        ));
    }

    public static function get_promoter_by_user(int $user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_promoters() . " WHERE user_id = %d", $user_id
        ));
    }

    public static function get_all_promoters($status = '') {
        global $wpdb;
        $sql = "SELECT p.*, u.user_email, u.display_name AS wp_name FROM " . self::table_promoters() . " p
                LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id";
        if ($status) {
            $sql .= $wpdb->prepare(" WHERE p.status = %s", $status);
        }
        $sql .= " ORDER BY p.created_at DESC";
        return $wpdb->get_results($sql);
    }

    public static function delete_promoter(int $id) {
        global $wpdb;
        return $wpdb->update(self::table_promoters(), ['status' => 'inactive'], ['id' => $id]);
    }

    // ══════════════════════════════════════════
    // EVENT-ZUORDNUNG CRUD
    // ══════════════════════════════════════════

    public static function assign_event(array $data) {
        global $wpdb;
        $row = [
            'promoter_id'      => intval($data['promoter_id'] ?? 0),
            'event_id'         => intval($data['event_id'] ?? 0),
            'commission_type'  => in_array($data['commission_type'] ?? '', ['percent', 'fixed']) ? $data['commission_type'] : 'percent',
            'commission_value' => floatval($data['commission_value'] ?? 0),
            'discount_type'    => in_array($data['discount_type'] ?? '', ['percent', 'fixed', '']) ? $data['discount_type'] : '',
            'discount_value'   => floatval($data['discount_value'] ?? 0),
            'promo_code'       => sanitize_text_field($data['promo_code'] ?? ''),
            'coupon_id'        => intval($data['coupon_id'] ?? 0) ?: null,
            'status'           => sanitize_text_field($data['status'] ?? 'active'),
        ];
        $wpdb->insert(self::table_events(), $row);
        return $wpdb->insert_id;
    }

    public static function update_assignment(int $id, array $data) {
        global $wpdb;
        $allowed = ['commission_type', 'commission_value', 'discount_type', 'discount_value', 'promo_code', 'coupon_id', 'status'];
        $update = [];
        foreach ($allowed as $k) {
            if (isset($data[$k])) {
                $update[$k] = is_numeric($data[$k]) ? $data[$k] : sanitize_text_field($data[$k]);
            }
        }
        if (empty($update)) return false;
        return $wpdb->update(self::table_events(), $update, ['id' => $id]);
    }

    public static function get_assignment(int $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_events() . " WHERE id = %d", $id
        ));
    }

    public static function get_assignment_by_promoter_event(int $promoter_id, int $event_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_events() . " WHERE promoter_id = %d AND event_id = %d AND status = 'active'",
            $promoter_id, $event_id
        ));
    }

    public static function get_assignment_by_promo_code(string $code) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT pe.*, p.promoter_code, p.user_id FROM " . self::table_events() . " pe
             JOIN " . self::table_promoters() . " p ON p.id = pe.promoter_id
             WHERE pe.promo_code = %s AND pe.status = 'active' AND p.status = 'active'",
            $code
        ));
    }

    public static function get_promoter_events(int $promoter_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT pe.*, p.title AS event_title FROM " . self::table_events() . " pe
             LEFT JOIN {$wpdb->posts} p ON p.ID = pe.event_id
             WHERE pe.promoter_id = %d AND pe.status = 'active'
             ORDER BY pe.created_at DESC",
            $promoter_id
        ));
    }

    public static function get_event_promoters(int $event_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT pe.*, pr.promoter_code, pr.display_name, pr.user_id, u.user_email
             FROM " . self::table_events() . " pe
             JOIN " . self::table_promoters() . " pr ON pr.id = pe.promoter_id
             LEFT JOIN {$wpdb->users} u ON u.ID = pr.user_id
             WHERE pe.event_id = %d AND pe.status = 'active'",
            $event_id
        ));
    }

    public static function unassign_event(int $id) {
        global $wpdb;
        return $wpdb->update(self::table_events(), ['status' => 'ended'], ['id' => $id]);
    }

    public static function get_all_assignments($filters = []) {
        global $wpdb;
        $sql = "SELECT pe.*, pr.promoter_code, pr.display_name AS promoter_name, pr.user_id,
                       p.post_title AS event_title, u.user_email
                FROM " . self::table_events() . " pe
                JOIN " . self::table_promoters() . " pr ON pr.id = pe.promoter_id
                LEFT JOIN {$wpdb->posts} p ON p.ID = pe.event_id
                LEFT JOIN {$wpdb->users} u ON u.ID = pr.user_id
                WHERE 1=1";

        if (!empty($filters['promoter_id'])) {
            $sql .= $wpdb->prepare(" AND pe.promoter_id = %d", $filters['promoter_id']);
        }
        if (!empty($filters['event_id'])) {
            $sql .= $wpdb->prepare(" AND pe.event_id = %d", $filters['event_id']);
        }
        if (!empty($filters['status'])) {
            $sql .= $wpdb->prepare(" AND pe.status = %s", $filters['status']);
        }
        $sql .= " ORDER BY pe.created_at DESC";
        return $wpdb->get_results($sql);
    }

    // ══════════════════════════════════════════
    // PROVISIONEN
    // ══════════════════════════════════════════

    public static function insert_commission(array $data) {
        global $wpdb;
        $row = [
            'promoter_id'       => intval($data['promoter_id']),
            'event_id'          => intval($data['event_id']),
            'order_id'          => intval($data['order_id']),
            'order_item_id'     => intval($data['order_item_id'] ?? 0) ?: null,
            'attribution'       => sanitize_text_field($data['attribution'] ?? 'referral'),
            'tickets_qty'       => intval($data['tickets_qty'] ?? 0),
            'order_total'       => floatval($data['order_total'] ?? 0),
            'commission_amount' => floatval($data['commission_amount'] ?? 0),
            'discount_amount'   => floatval($data['discount_amount'] ?? 0),
            'status'            => 'pending',
        ];
        $wpdb->insert(self::table_commissions(), $row);
        return $wpdb->insert_id;
    }

    public static function get_commissions($filters = []) {
        global $wpdb;
        $sql = "SELECT c.*, pr.promoter_code, pr.display_name AS promoter_name,
                       p.post_title AS event_title
                FROM " . self::table_commissions() . " c
                JOIN " . self::table_promoters() . " pr ON pr.id = c.promoter_id
                LEFT JOIN {$wpdb->posts} p ON p.ID = c.event_id
                WHERE 1=1";

        if (!empty($filters['promoter_id'])) {
            $sql .= $wpdb->prepare(" AND c.promoter_id = %d", $filters['promoter_id']);
        }
        if (!empty($filters['event_id'])) {
            $sql .= $wpdb->prepare(" AND c.event_id = %d", $filters['event_id']);
        }
        if (!empty($filters['status'])) {
            $sql .= $wpdb->prepare(" AND c.status = %s", $filters['status']);
        }
        if (!empty($filters['date_from'])) {
            $sql .= $wpdb->prepare(" AND c.created_at >= %s", $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $sql .= $wpdb->prepare(" AND c.created_at <= %s", $filters['date_to'] . ' 23:59:59');
        }
        if (!empty($filters['payout_id'])) {
            $sql .= $wpdb->prepare(" AND c.payout_id = %d", $filters['payout_id']);
        }
        $sql .= " ORDER BY c.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= $wpdb->prepare(" LIMIT %d", $filters['limit']);
        }
        return $wpdb->get_results($sql);
    }

    public static function cancel_commissions_by_order(int $order_id) {
        global $wpdb;
        return $wpdb->update(
            self::table_commissions(),
            ['status' => 'cancelled'],
            ['order_id' => $order_id, 'status' => 'pending']
        );
    }

    public static function get_commissions_for_payout(int $promoter_id, string $from, string $to) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table_commissions() . "
             WHERE promoter_id = %d AND status = 'pending' AND payout_id IS NULL
               AND created_at >= %s AND created_at <= %s
             ORDER BY created_at ASC",
            $promoter_id, $from . ' 00:00:00', $to . ' 23:59:59'
        ));
    }

    // ══════════════════════════════════════════
    // AUSZAHLUNGEN
    // ══════════════════════════════════════════

    public static function create_payout(array $data) {
        global $wpdb;

        $promoter_id = intval($data['promoter_id']);
        $from        = sanitize_text_field($data['period_from']);
        $to          = sanitize_text_field($data['period_to']);

        // Offene Provisionen für den Zeitraum holen
        $commissions = self::get_commissions_for_payout($promoter_id, $from, $to);
        if (empty($commissions)) return false;

        $total_sales      = 0;
        $total_commission  = 0;
        $total_discount    = 0;

        foreach ($commissions as $c) {
            $total_sales     += $c->order_total;
            $total_commission += $c->commission_amount;
            $total_discount  += $c->discount_amount;
        }

        $payout = [
            'promoter_id'      => $promoter_id,
            'period_from'      => $from,
            'period_to'        => $to,
            'total_sales'      => $total_sales,
            'total_commission' => $total_commission,
            'total_discount'   => $total_discount,
            'commission_count' => count($commissions),
            'status'           => 'pending',
            'payment_note'     => sanitize_textarea_field($data['payment_note'] ?? ''),
        ];

        $wpdb->insert(self::table_payouts(), $payout);
        $payout_id = $wpdb->insert_id;

        if ($payout_id) {
            // Provisionen dem Payout zuordnen
            $ids = wp_list_pluck($commissions, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE " . self::table_commissions() . " SET payout_id = %d, status = 'approved'
                 WHERE id IN ($placeholders)",
                array_merge([$payout_id], $ids)
            ));
        }

        return $payout_id;
    }

    public static function get_payout(int $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT po.*, pr.promoter_code, pr.display_name AS promoter_name
             FROM " . self::table_payouts() . " po
             JOIN " . self::table_promoters() . " pr ON pr.id = po.promoter_id
             WHERE po.id = %d", $id
        ));
    }

    public static function get_payouts($filters = []) {
        global $wpdb;
        $sql = "SELECT po.*, pr.promoter_code, pr.display_name AS promoter_name, u.user_email
                FROM " . self::table_payouts() . " po
                JOIN " . self::table_promoters() . " pr ON pr.id = po.promoter_id
                LEFT JOIN {$wpdb->users} u ON u.ID = pr.user_id
                WHERE 1=1";

        if (!empty($filters['promoter_id'])) {
            $sql .= $wpdb->prepare(" AND po.promoter_id = %d", $filters['promoter_id']);
        }
        if (!empty($filters['status'])) {
            $sql .= $wpdb->prepare(" AND po.status = %s", $filters['status']);
        }
        $sql .= " ORDER BY po.created_at DESC";
        return $wpdb->get_results($sql);
    }

    public static function mark_payout_paid(int $id, string $note = '') {
        global $wpdb;

        $wpdb->update(self::table_payouts(), [
            'status'       => 'paid',
            'paid_date'    => current_time('mysql'),
            'payment_note' => sanitize_textarea_field($note),
        ], ['id' => $id]);

        // Zugehörige Provisionen auch als "paid" markieren
        $wpdb->update(
            self::table_commissions(),
            ['status' => 'paid'],
            ['payout_id' => $id]
        );

        return true;
    }

    public static function cancel_payout(int $id) {
        global $wpdb;

        // Provisionen wieder freigeben
        $wpdb->update(
            self::table_commissions(),
            ['status' => 'pending', 'payout_id' => null],
            ['payout_id' => $id, 'status' => 'approved']
        );

        return $wpdb->update(self::table_payouts(), ['status' => 'cancelled'], ['id' => $id]);
    }

    // ══════════════════════════════════════════
    // AGGREGATIONEN / STATISTIKEN
    // ══════════════════════════════════════════

    public static function get_promoter_stats(int $promoter_id) {
        global $wpdb;
        $tc = self::table_commissions();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS total_commissions,
                COALESCE(SUM(order_total), 0) AS total_sales,
                COALESCE(SUM(commission_amount), 0) AS total_commission,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END), 0) AS pending_commission,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END), 0) AS paid_commission,
                COALESCE(SUM(tickets_qty), 0) AS total_tickets
             FROM $tc WHERE promoter_id = %d AND status != 'cancelled'",
            $promoter_id
        ));
    }

    public static function get_all_promoter_stats() {
        global $wpdb;
        $tp = self::table_promoters();
        $tc = self::table_commissions();

        return $wpdb->get_results(
            "SELECT p.id, p.promoter_code, p.display_name, p.status, p.user_id, u.user_email,
                    COALESCE(SUM(c.order_total), 0) AS total_sales,
                    COALESCE(SUM(c.commission_amount), 0) AS total_commission,
                    COALESCE(SUM(CASE WHEN c.status = 'pending' THEN c.commission_amount ELSE 0 END), 0) AS pending_commission,
                    COUNT(c.id) AS total_commissions
             FROM $tp p
             LEFT JOIN $tc c ON c.promoter_id = p.id AND c.status != 'cancelled'
             LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
             GROUP BY p.id
             ORDER BY total_sales DESC"
        );
    }

    public static function get_promoter_event_stats(int $promoter_id) {
        global $wpdb;
        $tc = self::table_commissions();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.event_id, p.post_title AS event_title,
                    COUNT(*) AS commissions,
                    COALESCE(SUM(c.order_total), 0) AS sales,
                    COALESCE(SUM(c.commission_amount), 0) AS commission,
                    COALESCE(SUM(c.tickets_qty), 0) AS tickets
             FROM $tc c
             LEFT JOIN {$wpdb->posts} p ON p.ID = c.event_id
             WHERE c.promoter_id = %d AND c.status != 'cancelled'
             GROUP BY c.event_id
             ORDER BY sales DESC",
            $promoter_id
        ));
    }

    public static function get_stats_timeline(int $promoter_id, string $from, string $to, string $group = 'day') {
        global $wpdb;
        $tc = self::table_commissions();

        $fmt = $group === 'month' ? '%Y-%m' : ($group === 'week' ? '%x-W%v' : '%Y-%m-%d');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(c.created_at, %s) AS period,
                    COALESCE(SUM(c.order_total), 0) AS sales,
                    COALESCE(SUM(c.commission_amount), 0) AS commission,
                    COALESCE(SUM(c.tickets_qty), 0) AS tickets
             FROM $tc c
             WHERE c.promoter_id = %d AND c.status != 'cancelled'
               AND c.created_at >= %s AND c.created_at <= %s
             GROUP BY period ORDER BY period ASC",
            $fmt, $promoter_id, $from . ' 00:00:00', $to . ' 23:59:59'
        ));
    }

    public static function get_top_promoters(string $from = '', string $to = '', int $limit = 10) {
        global $wpdb;
        $tp = self::table_promoters();
        $tc = self::table_commissions();

        $sql = "SELECT p.id, p.promoter_code, p.display_name, p.user_id,
                       COALESCE(SUM(c.order_total), 0) AS sales,
                       COALESCE(SUM(c.commission_amount), 0) AS commission,
                       COALESCE(SUM(c.tickets_qty), 0) AS tickets
                FROM $tp p
                JOIN $tc c ON c.promoter_id = p.id AND c.status != 'cancelled'";

        if ($from) $sql .= $wpdb->prepare(" AND c.created_at >= %s", $from . ' 00:00:00');
        if ($to)   $sql .= $wpdb->prepare(" AND c.created_at <= %s", $to . ' 23:59:59');

        $sql .= " WHERE p.status = 'active' GROUP BY p.id ORDER BY sales DESC";
        $sql .= $wpdb->prepare(" LIMIT %d", $limit);

        return $wpdb->get_results($sql);
    }
}
