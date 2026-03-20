<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Order – Eigenes Bestellsystem mit eigenen MySQL-Tabellen.
 *
 * Tabellen: {prefix}tix_orders + {prefix}tix_order_items
 * API-kompatibel mit WC_Order für einfache Migration.
 */
class TIX_Order {

    private $id;
    private $data = [];

    private function __construct($row) {
        $this->id   = intval($row->id);
        $this->data = (array) $row;
    }

    // ══════════════════════════════════════
    // TABELLEN-SETUP
    // ══════════════════════════════════════

    public static function table_name()      { global $wpdb; return $wpdb->prefix . 'tix_orders'; }
    public static function items_table_name() { global $wpdb; return $wpdb->prefix . 'tix_order_items'; }

    /**
     * Erstellt die Tabellen bei Plugin-Aktivierung / Version-Update.
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $t = self::table_name();
        $ti = self::items_table_name();

        $sql_orders = "CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(50) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
            tax DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NOT NULL DEFAULT '',
            payment_method_title VARCHAR(100) NOT NULL DEFAULT '',
            billing_first_name VARCHAR(100) NOT NULL DEFAULT '',
            billing_last_name VARCHAR(100) NOT NULL DEFAULT '',
            billing_email VARCHAR(200) NOT NULL DEFAULT '',
            billing_phone VARCHAR(50) NOT NULL DEFAULT '',
            billing_company VARCHAR(200) NOT NULL DEFAULT '',
            billing_address_1 VARCHAR(255) NOT NULL DEFAULT '',
            billing_address_2 VARCHAR(255) NOT NULL DEFAULT '',
            billing_city VARCHAR(100) NOT NULL DEFAULT '',
            billing_postcode VARCHAR(20) NOT NULL DEFAULT '',
            billing_country VARCHAR(10) NOT NULL DEFAULT '',
            customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            wc_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            order_key VARCHAR(50) NOT NULL DEFAULT '',
            checked_in TINYINT(1) NOT NULL DEFAULT 0,
            date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY billing_email (billing_email),
            KEY wc_order_id (wc_order_id),
            KEY date_created (date_created),
            KEY customer_id (customer_id)
        ) $charset;";

        $sql_items = "CREATE TABLE $ti (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            tax DECIMAL(10,2) NOT NULL DEFAULT 0,
            name VARCHAR(255) NOT NULL DEFAULT '',
            cat_name VARCHAR(100) NOT NULL DEFAULT '',
            meta LONGTEXT,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY event_id (event_id),
            KEY product_id (product_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_orders);
        dbDelta($sql_items);
    }

    // ══════════════════════════════════════
    // GETTERS (WC_Order-kompatibel)
    // ══════════════════════════════════════

    public function get_id()                     { return $this->id; }
    public function get_status()                 { return $this->data['status'] ?? 'pending'; }
    public function get_total()                  { return floatval($this->data['total'] ?? 0); }
    public function get_subtotal()               { return floatval($this->data['subtotal'] ?? $this->get_total()); }
    public function get_total_tax()              { return floatval($this->data['tax'] ?? 0); }
    public function get_total_discount()         { return floatval($this->data['discount'] ?? 0); }
    public function get_order_number()           { return $this->data['order_number'] ?: (string) $this->id; }
    public function get_order_key()              { return $this->data['order_key'] ?? ''; }
    public function get_payment_method()         { return $this->data['payment_method'] ?? ''; }
    public function get_payment_method_title()   { return $this->data['payment_method_title'] ?? ''; }

    public function get_billing_first_name()     { return $this->data['billing_first_name'] ?? ''; }
    public function get_billing_last_name()      { return $this->data['billing_last_name'] ?? ''; }
    public function get_billing_email()          { return $this->data['billing_email'] ?? ''; }
    public function get_billing_phone()          { return $this->data['billing_phone'] ?? ''; }
    public function get_billing_company()        { return $this->data['billing_company'] ?? ''; }
    public function get_billing_address_1()      { return $this->data['billing_address_1'] ?? ''; }
    public function get_billing_address_2()      { return $this->data['billing_address_2'] ?? ''; }
    public function get_billing_city()           { return $this->data['billing_city'] ?? ''; }
    public function get_billing_postcode()       { return $this->data['billing_postcode'] ?? ''; }
    public function get_billing_country()        { return $this->data['billing_country'] ?? ''; }
    public function get_customer_id()            { return intval($this->data['customer_id'] ?? 0); }

    public function get_date_created() {
        $d = $this->data['date_created'] ?? null;
        if (!$d) return null;
        if (class_exists('WC_DateTime')) return new WC_DateTime($d);
        return new TIX_DateTime($d);
    }

    public function get_formatted_order_total() {
        if (function_exists('wc_price')) return wc_price($this->get_total());
        return number_format($this->get_total(), 2, ',', '.') . ' €';
    }

    public function get_meta($key, $single = true) {
        // Direkt aus data-Array lesen (Spaltenname ohne Prefix)
        $col = str_replace('_tix_', '', $key);
        if (isset($this->data[$col])) return $this->data[$col];
        // Fallback: WC Order Meta (für Übergangszeit)
        if ($this->data['wc_order_id'] ?? 0) {
            return get_post_meta($this->data['wc_order_id'], $key, $single);
        }
        return $single ? '' : [];
    }

    // ══════════════════════════════════════
    // ITEMS
    // ══════════════════════════════════════

    public function get_items() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::items_table_name() . " WHERE order_id = %d ORDER BY id ASC",
            $this->id
        ));
        return array_map(fn($r) => new TIX_Order_Item($r), $rows);
    }

    // ══════════════════════════════════════
    // STATUS
    // ══════════════════════════════════════

    public function update_status($status) {
        global $wpdb;
        $wpdb->update(self::table_name(), ['status' => $status], ['id' => $this->id]);
        $this->data['status'] = $status;
        do_action('tix_order_status_changed', $this->id, $status, $this);
    }

    // ══════════════════════════════════════
    // STATISCHE METHODEN
    // ══════════════════════════════════════

    public static function get($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE id = %d", $id));
        return $row ? new self($row) : null;
    }

    public static function get_by_wc_order($wc_order_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE wc_order_id = %d LIMIT 1",
            $wc_order_id
        ));
        return $row ? new self($row) : null;
    }

    /**
     * Query tix_orders.
     */
    public static function query($args = []) {
        global $wpdb;
        $t = self::table_name();
        $ti = self::items_table_name();
        $where = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $statuses = (array) $args['status'];
            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where[] = "o.status IN ($placeholders)";
            $params = array_merge($params, $statuses);
        }

        if (!empty($args['email'])) {
            $where[] = "o.billing_email = %s";
            $params[] = $args['email'];
        }

        if (!empty($args['customer_id'])) {
            $where[] = "o.customer_id = %d";
            $params[] = intval($args['customer_id']);
        }

        if (!empty($args['date_from'])) {
            $where[] = "o.date_created >= %s";
            $params[] = $args['date_from'];
        }
        if (!empty($args['date_to'])) {
            $where[] = "o.date_created <= %s";
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        if (!empty($args['event_id'])) {
            $where[] = "o.id IN (SELECT order_id FROM $ti WHERE event_id = %d)";
            $params[] = intval($args['event_id']);
        }

        $order_col = $args['orderby'] ?? 'date_created';
        $order_dir = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $limit = intval($args['limit'] ?? 0);
        $offset = intval($args['offset'] ?? 0);

        $sql = "SELECT o.* FROM $t o WHERE " . implode(' AND ', $where) . " ORDER BY o.$order_col $order_dir";
        if ($limit > 0) $sql .= " LIMIT $limit OFFSET $offset";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);
        return array_map(fn($r) => new self($r), $rows ?: []);
    }

    public static function get_by_customer($email, $args = []) {
        $args['email'] = $email;
        return self::query($args);
    }

    public static function get_order_ids_by_event($event_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT order_id FROM " . self::items_table_name() . " WHERE event_id = %d",
            $event_id
        ));
    }

    public static function get_revenue_for_events($event_ids, $statuses = ['completed', 'processing']) {
        global $wpdb;
        if (empty($event_ids)) return ['revenue' => 0, 'tickets' => 0];

        $t = self::table_name();
        $ti = self::items_table_name();
        $eids = implode(',', array_map('intval', $event_ids));
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(i.total), 0) as revenue, COALESCE(SUM(i.quantity), 0) as tickets
             FROM $ti i
             INNER JOIN $t o ON i.order_id = o.id
             WHERE i.event_id IN ($eids)
               AND o.status IN ($status_placeholders)",
            ...$statuses
        );

        $r = $wpdb->get_row($sql);
        return [
            'revenue' => floatval($r->revenue ?? 0),
            'tickets' => intval($r->tickets ?? 0),
        ];
    }

    public static function count_all($status = null) {
        global $wpdb;
        $t = self::table_name();
        if ($status) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE status = %s", $status));
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    }

    // ══════════════════════════════════════
    // DUAL-WRITE: WC → tix_orders Tabelle
    // ══════════════════════════════════════

    public static function init() {
        // Frontend-Checkout
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_wc_order_created'], 5, 1);
        // Backend-Order (manuell erstellt) + HPOS
        add_action('woocommerce_new_order', [__CLASS__, 'on_wc_order_created'], 5, 1);
        add_action('woocommerce_checkout_order_created', function($order) {
            self::on_wc_order_created($order->get_id());
        }, 5, 1);
        // Status-Sync
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_wc_status_changed'], 10, 3);
    }

    public static function on_wc_order_created($order_id) {
        $wc_order = wc_get_order($order_id);
        if (!$wc_order) return;
        if (get_post_meta($order_id, '_tix_order_id', true)) return;
        self::create_from_wc_order($wc_order);
    }

    public static function on_wc_status_changed($order_id, $old_status, $new_status) {
        global $wpdb;
        $tix_id = get_post_meta($order_id, '_tix_order_id', true);
        if (!$tix_id) return;
        $wpdb->update(self::table_name(), ['status' => $new_status], ['id' => $tix_id]);
        do_action('tix_order_status_changed', $tix_id, $new_status);
    }

    /**
     * Generiert die nächste eigene Bestellnummer (unabhängig von WC).
     */
    private static function next_order_number() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $prefix = $s['order_number_prefix'] ?? 'TIX-';
        $digits = max(3, intval($s['order_number_digits'] ?? 5));
        $suffix = $s['order_number_suffix'] ?? '';

        // Atomarer Counter via UPDATE + SELECT
        global $wpdb;
        $wpdb->query("UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = 'tix_order_seq'");
        $seq = (int) get_option('tix_order_seq', 0);
        if (!$seq) {
            $start = max(1, intval($s['order_number_start'] ?? 1));
            update_option('tix_order_seq', $start);
            $seq = $start;
        }

        return $prefix . str_pad($seq, $digits, '0', STR_PAD_LEFT) . $suffix;
    }

    public static function create_from_wc_order($wc_order) {
        global $wpdb;
        $t = self::table_name();
        $ti = self::items_table_name();

        $order_number = self::next_order_number();
        $dc = $wc_order->get_date_created();

        $wpdb->insert($t, [
            'order_number'          => $order_number,
            'status'                => $wc_order->get_status(),
            'total'                 => $wc_order->get_total(),
            'subtotal'              => $wc_order->get_subtotal(),
            'tax'                   => $wc_order->get_total_tax(),
            'discount'              => $wc_order->get_total_discount(),
            'payment_method'        => $wc_order->get_payment_method(),
            'payment_method_title'  => $wc_order->get_payment_method_title(),
            'billing_first_name'    => $wc_order->get_billing_first_name(),
            'billing_last_name'     => $wc_order->get_billing_last_name(),
            'billing_email'         => $wc_order->get_billing_email(),
            'billing_phone'         => $wc_order->get_billing_phone(),
            'billing_company'       => $wc_order->get_billing_company(),
            'billing_address_1'     => $wc_order->get_billing_address_1(),
            'billing_address_2'     => $wc_order->get_billing_address_2(),
            'billing_city'          => $wc_order->get_billing_city(),
            'billing_postcode'      => $wc_order->get_billing_postcode(),
            'billing_country'       => $wc_order->get_billing_country(),
            'customer_id'           => $wc_order->get_customer_id(),
            'wc_order_id'           => $wc_order->get_id(),
            'order_key'             => $wc_order->get_order_key(),
            'date_created'          => $dc ? $dc->format('Y-m-d H:i:s') : current_time('mysql'),
        ]);

        $tix_order_id = $wpdb->insert_id;
        if (!$tix_order_id) return null;

        // Bidirektionale Referenz
        update_post_meta($wc_order->get_id(), '_tix_order_id', $tix_order_id);

        // Order Items
        foreach ($wc_order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $event_id   = intval(get_post_meta($product_id, '_tix_parent_event_id', true));

            $cat_name = '';
            if ($event_id) {
                $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
                if (is_array($cats)) {
                    foreach ($cats as $c) {
                        if (!empty($c['product_id']) && intval($c['product_id']) === $product_id) {
                            $cat_name = $c['name'] ?? '';
                            break;
                        }
                    }
                }
            }

            // Custom Meta sammeln
            $meta = [];
            foreach (['_tix_combo', '_tix_seats', '_tix_seatmap_id', '_tix_bundle', '_tix_group_booking'] as $mk) {
                $mv = $item->get_meta($mk);
                if ($mv) $meta[$mk] = $mv;
            }

            $wpdb->insert($ti, [
                'order_id'   => $tix_order_id,
                'product_id' => $product_id,
                'event_id'   => $event_id,
                'quantity'   => $item->get_quantity(),
                'total'      => $item->get_total(),
                'tax'        => $item->get_total_tax(),
                'name'       => $item->get_name(),
                'cat_name'   => $cat_name,
                'meta'       => $meta ? json_encode($meta) : null,
            ]);
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $tix_order_id));
        return $row ? new self($row) : null;
    }
}

/**
 * TIX_Order_Item – Bestellposition aus eigener Tabelle.
 */
class TIX_Order_Item {

    private $id;
    private $data;

    public function __construct($row) {
        $this->data = (array) $row;
        $this->id   = intval($row->id);
    }

    public function get_id()         { return $this->id; }
    public function get_product_id() { return intval($this->data['product_id'] ?? 0); }
    public function get_event_id()   { return intval($this->data['event_id'] ?? 0); }
    public function get_quantity()   { return intval($this->data['quantity'] ?? 0); }
    public function get_total()      { return floatval($this->data['total'] ?? 0); }
    public function get_total_tax()  { return floatval($this->data['tax'] ?? 0); }
    public function get_name()       { return $this->data['name'] ?? ''; }
    public function get_cat_name()   { return $this->data['cat_name'] ?? ''; }

    public function get_meta($key, $single = true) {
        $meta = $this->data['meta'] ?? null;
        if (!$meta) return $single ? '' : [];
        if (is_string($meta)) $meta = json_decode($meta, true);
        if (!is_array($meta)) return $single ? '' : [];
        return $meta[$key] ?? ($single ? '' : []);
    }
}

/**
 * TIX_DateTime – DateTime-Objekt kompatibel mit WC_DateTime API.
 */
if (!class_exists('TIX_DateTime')) {
    class TIX_DateTime extends DateTime {
        public function __construct($date = 'now') {
            parent::__construct($date, wp_timezone());
        }
        public function date_i18n($format = 'd.m.Y H:i') {
            return wp_date($format, $this->getTimestamp());
        }
        public function getOffsetTimestamp() {
            return $this->getTimestamp();
        }
    }
}
