<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Order – Eigenes Bestellsystem parallel zu WooCommerce.
 *
 * Speichert alle Bestelldaten als tix_order CPT + tix_order_item Child-Posts.
 * API-kompatibel mit WC_Order für einfache Migration.
 */
class TIX_Order {

    private $id;
    private $post;
    private $meta_cache = [];

    public function __construct($post) {
        $this->post = $post;
        $this->id   = $post->ID;
    }

    // ══════════════════════════════════════
    // GETTERS (WC_Order-kompatibel)
    // ══════════════════════════════════════

    public function get_id()                     { return $this->id; }
    public function get_status()                 { return $this->get_meta('_tix_order_status') ?: 'pending'; }
    public function get_total()                  { return floatval($this->get_meta('_tix_order_total')); }
    public function get_subtotal()               { return floatval($this->get_meta('_tix_order_subtotal') ?: $this->get_total()); }
    public function get_total_tax()              { return floatval($this->get_meta('_tix_order_tax')); }
    public function get_total_discount()         { return floatval($this->get_meta('_tix_order_discount')); }
    public function get_order_number()           { return $this->get_meta('_tix_order_number') ?: (string) $this->id; }
    public function get_order_key()              { return $this->get_meta('_tix_order_key') ?: ''; }
    public function get_payment_method()         { return $this->get_meta('_tix_payment_method') ?: ''; }
    public function get_payment_method_title()   { return $this->get_meta('_tix_payment_method_title') ?: ''; }

    public function get_billing_first_name()     { return $this->get_meta('_tix_billing_first_name') ?: ''; }
    public function get_billing_last_name()      { return $this->get_meta('_tix_billing_last_name') ?: ''; }
    public function get_billing_email()          { return $this->get_meta('_tix_billing_email') ?: ''; }
    public function get_billing_phone()          { return $this->get_meta('_tix_billing_phone') ?: ''; }
    public function get_billing_company()        { return $this->get_meta('_tix_billing_company') ?: ''; }
    public function get_billing_address_1()      { return $this->get_meta('_tix_billing_address_1') ?: ''; }
    public function get_billing_address_2()      { return $this->get_meta('_tix_billing_address_2') ?: ''; }
    public function get_billing_city()           { return $this->get_meta('_tix_billing_city') ?: ''; }
    public function get_billing_postcode()       { return $this->get_meta('_tix_billing_postcode') ?: ''; }
    public function get_billing_country()        { return $this->get_meta('_tix_billing_country') ?: ''; }

    public function get_date_created() {
        if (!$this->post) return null;
        if (class_exists('WC_DateTime')) {
            return new WC_DateTime($this->post->post_date);
        }
        return new TIX_DateTime($this->post->post_date);
    }

    public function get_customer_id() {
        return intval($this->get_meta('_tix_customer_id'));
    }

    public function get_formatted_order_total() {
        if (function_exists('wc_price')) {
            return wc_price($this->get_total());
        }
        return number_format($this->get_total(), 2, ',', '.') . ' €';
    }

    // ══════════════════════════════════════
    // ITEMS
    // ══════════════════════════════════════

    public function get_items() {
        $item_posts = get_posts([
            'post_type'      => 'tix_order_item',
            'post_parent'    => $this->id,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $items = [];
        foreach ($item_posts as $p) {
            $items[] = new TIX_Order_Item($p);
        }
        return $items;
    }

    // ══════════════════════════════════════
    // META
    // ══════════════════════════════════════

    public function get_meta($key, $single = true) {
        if (!isset($this->meta_cache[$key])) {
            $this->meta_cache[$key] = get_post_meta($this->id, $key, $single);
        }
        return $this->meta_cache[$key];
    }

    public function update_meta_data($key, $value) {
        update_post_meta($this->id, $key, $value);
        $this->meta_cache[$key] = $value;
    }

    public function save() {
        // Meta-Cache ist bereits persistent via update_post_meta
        clean_post_cache($this->id);
    }

    // ══════════════════════════════════════
    // STATUS
    // ══════════════════════════════════════

    public function update_status($status) {
        $this->update_meta_data('_tix_order_status', $status);
        do_action('tix_order_status_changed', $this->id, $status, $this);
    }

    // ══════════════════════════════════════
    // STATISCHE METHODEN
    // ══════════════════════════════════════

    /**
     * Lädt eine TIX_Order anhand der ID.
     */
    public static function get($id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'tix_order') return null;
        return new self($post);
    }

    /**
     * Lädt eine TIX_Order anhand der WC Order ID.
     */
    public static function get_by_wc_order($wc_order_id) {
        $posts = get_posts([
            'post_type'      => 'tix_order',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_key'       => '_tix_wc_order_id',
            'meta_value'     => $wc_order_id,
            'meta_type'      => 'NUMERIC',
        ]);
        return $posts ? new self($posts[0]) : null;
    }

    /**
     * Query tix_orders.
     * @param array $args  Keys: status, date_from, date_to, event_id, email, customer_id, limit, offset, orderby, order
     * @return TIX_Order[]
     */
    public static function query($args = []) {
        $wp_args = [
            'post_type'      => 'tix_order',
            'post_status'    => 'publish',
            'posts_per_page' => $args['limit'] ?? -1,
            'offset'         => $args['offset'] ?? 0,
            'orderby'        => 'date',
            'order'          => $args['order'] ?? 'DESC',
        ];

        $meta_query = [];

        if (!empty($args['status'])) {
            $statuses = (array) $args['status'];
            if (count($statuses) === 1) {
                $meta_query[] = ['key' => '_tix_order_status', 'value' => $statuses[0]];
            } else {
                $meta_query[] = ['key' => '_tix_order_status', 'value' => $statuses, 'compare' => 'IN'];
            }
        }

        if (!empty($args['email'])) {
            $meta_query[] = ['key' => '_tix_billing_email', 'value' => $args['email']];
        }

        if (!empty($args['customer_id'])) {
            $meta_query[] = ['key' => '_tix_customer_id', 'value' => $args['customer_id'], 'type' => 'NUMERIC'];
        }

        if (!empty($args['date_from'])) {
            $wp_args['date_query'][] = ['after' => $args['date_from'], 'inclusive' => true];
        }
        if (!empty($args['date_to'])) {
            $wp_args['date_query'][] = ['before' => $args['date_to'], 'inclusive' => true];
        }

        if ($meta_query) {
            $wp_args['meta_query'] = $meta_query;
        }

        // Event-Filter: nur Orders mit Items für dieses Event
        if (!empty($args['event_id'])) {
            $event_id = intval($args['event_id']);
            $order_ids = self::get_order_ids_by_event($event_id);
            if (empty($order_ids)) return [];
            $wp_args['post__in'] = $order_ids;
        }

        $posts = get_posts($wp_args);
        return array_map(fn($p) => new self($p), $posts);
    }

    /**
     * Orders eines Kunden (per E-Mail).
     */
    public static function get_by_customer($email, $args = []) {
        $args['email'] = $email;
        return self::query($args);
    }

    /**
     * Order-IDs die Items für ein bestimmtes Event enthalten.
     */
    public static function get_order_ids_by_event($event_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.post_parent
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_item_event_id'
             WHERE p.post_type = 'tix_order_item'
               AND p.post_status = 'publish'
               AND pm.meta_value = %d",
            $event_id
        ));
    }

    /**
     * Umsatz + Ticket-Anzahl für Event-IDs.
     */
    public static function get_revenue_for_events($event_ids, $statuses = ['completed', 'processing']) {
        global $wpdb;

        if (empty($event_ids)) return ['revenue' => 0, 'tickets' => 0];

        $eids = implode(',', array_map('intval', $event_ids));
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(CAST(item_total.meta_value AS DECIMAL(10,2))), 0) as revenue,
                    COALESCE(SUM(CAST(item_qty.meta_value AS UNSIGNED)), 0) as tickets
             FROM {$wpdb->posts} item
             INNER JOIN {$wpdb->postmeta} item_eid ON item.ID = item_eid.post_id AND item_eid.meta_key = '_tix_item_event_id'
             INNER JOIN {$wpdb->postmeta} item_total ON item.ID = item_total.post_id AND item_total.meta_key = '_tix_item_total'
             INNER JOIN {$wpdb->postmeta} item_qty ON item.ID = item_qty.post_id AND item_qty.meta_key = '_tix_item_quantity'
             INNER JOIN {$wpdb->posts} ord ON item.post_parent = ord.ID AND ord.post_type = 'tix_order'
             INNER JOIN {$wpdb->postmeta} ord_status ON ord.ID = ord_status.post_id AND ord_status.meta_key = '_tix_order_status'
             WHERE item.post_type = 'tix_order_item'
               AND item.post_status = 'publish'
               AND item_eid.meta_value IN ($eids)
               AND ord_status.meta_value IN ($status_placeholders)",
            ...$statuses
        );

        $r = $wpdb->get_row($sql);
        return [
            'revenue' => floatval($r->revenue ?? 0),
            'tickets' => intval($r->tickets ?? 0),
        ];
    }

    // ══════════════════════════════════════
    // DUAL-WRITE: WC → tix_order
    // ══════════════════════════════════════

    public static function init() {
        // Dual-Write bei neuer WC-Bestellung
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'on_wc_order_created'], 5, 1);

        // Status-Sync
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_wc_status_changed'], 10, 3);
    }

    /**
     * Erstellt eine tix_order aus einer WC_Order (Dual-Write).
     */
    public static function on_wc_order_created($order_id) {
        $wc_order = wc_get_order($order_id);
        if (!$wc_order) return;

        // Bereits gespiegelt?
        if (get_post_meta($order_id, '_tix_order_id', true)) return;

        self::create_from_wc_order($wc_order);
    }

    /**
     * Sync WC Order Status → tix_order Status.
     */
    public static function on_wc_status_changed($order_id, $old_status, $new_status) {
        $tix_order_id = get_post_meta($order_id, '_tix_order_id', true);
        if (!$tix_order_id) return;

        update_post_meta($tix_order_id, '_tix_order_status', $new_status);
        do_action('tix_order_status_changed', $tix_order_id, $new_status);
    }

    /**
     * Factory: Erstellt tix_order + tix_order_items aus WC_Order.
     */
    public static function create_from_wc_order($wc_order) {
        // Nächste Bestellnummer
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $counter = (int) get_option('tix_order_seq_counter', 0);
        if (!$counter) $counter = 1;
        $seq_number = $counter;
        update_option('tix_order_seq_counter', $counter + 1);

        $order_number = $wc_order->get_order_number();

        // tix_order Post erstellen
        $order_id = wp_insert_post([
            'post_type'   => 'tix_order',
            'post_title'  => $order_number,
            'post_status' => 'publish',
            'post_date'   => $wc_order->get_date_created() ? $wc_order->get_date_created()->format('Y-m-d H:i:s') : current_time('mysql'),
        ]);

        if (!$order_id || is_wp_error($order_id)) return null;

        // Meta-Felder kopieren
        $meta = [
            '_tix_order_status'          => $wc_order->get_status(),
            '_tix_order_total'           => $wc_order->get_total(),
            '_tix_order_subtotal'        => $wc_order->get_subtotal(),
            '_tix_order_tax'             => $wc_order->get_total_tax(),
            '_tix_order_discount'        => $wc_order->get_total_discount(),
            '_tix_order_number'          => $order_number,
            '_tix_order_key'             => $wc_order->get_order_key(),
            '_tix_order_seq'             => $seq_number,
            '_tix_payment_method'        => $wc_order->get_payment_method(),
            '_tix_payment_method_title'  => $wc_order->get_payment_method_title(),
            '_tix_billing_first_name'    => $wc_order->get_billing_first_name(),
            '_tix_billing_last_name'     => $wc_order->get_billing_last_name(),
            '_tix_billing_email'         => $wc_order->get_billing_email(),
            '_tix_billing_phone'         => $wc_order->get_billing_phone(),
            '_tix_billing_company'       => $wc_order->get_billing_company(),
            '_tix_billing_address_1'     => $wc_order->get_billing_address_1(),
            '_tix_billing_address_2'     => $wc_order->get_billing_address_2(),
            '_tix_billing_city'          => $wc_order->get_billing_city(),
            '_tix_billing_postcode'      => $wc_order->get_billing_postcode(),
            '_tix_billing_country'       => $wc_order->get_billing_country(),
            '_tix_customer_id'           => $wc_order->get_customer_id(),
            '_tix_wc_order_id'           => $wc_order->get_id(),
        ];

        foreach ($meta as $key => $val) {
            update_post_meta($order_id, $key, $val);
        }

        // Bidirektionale Referenz auf WC Order
        update_post_meta($wc_order->get_id(), '_tix_order_id', $order_id);

        // Order Items erstellen
        foreach ($wc_order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $event_id   = get_post_meta($product_id, '_tix_parent_event_id', true);

            // Kategorie-Name ermitteln
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

            $item_id = wp_insert_post([
                'post_type'   => 'tix_order_item',
                'post_parent' => $order_id,
                'post_title'  => $item->get_name(),
                'post_status' => 'publish',
            ]);

            if ($item_id && !is_wp_error($item_id)) {
                update_post_meta($item_id, '_tix_item_product_id', $product_id);
                update_post_meta($item_id, '_tix_item_event_id', intval($event_id));
                update_post_meta($item_id, '_tix_item_quantity', $item->get_quantity());
                update_post_meta($item_id, '_tix_item_total', $item->get_total());
                update_post_meta($item_id, '_tix_item_tax', $item->get_total_tax());
                update_post_meta($item_id, '_tix_item_name', $item->get_name());
                update_post_meta($item_id, '_tix_item_cat_name', $cat_name);

                // Custom Tixomat Meta kopieren (Seats, Combos, etc.)
                foreach (['_tix_combo', '_tix_seats', '_tix_seatmap_id', '_tix_bundle', '_tix_group_booking'] as $custom_key) {
                    $val = $item->get_meta($custom_key);
                    if ($val) update_post_meta($item_id, $custom_key, $val);
                }
            }
        }

        return new self(get_post($order_id));
    }
}

/**
 * TIX_Order_Item – Einzelne Bestellposition.
 */
class TIX_Order_Item {

    private $id;
    private $post;

    public function __construct($post) {
        $this->post = $post;
        $this->id   = $post->ID;
    }

    public function get_id()         { return $this->id; }
    public function get_product_id() { return intval(get_post_meta($this->id, '_tix_item_product_id', true)); }
    public function get_event_id()   { return intval(get_post_meta($this->id, '_tix_item_event_id', true)); }
    public function get_quantity()   { return intval(get_post_meta($this->id, '_tix_item_quantity', true)); }
    public function get_total()      { return floatval(get_post_meta($this->id, '_tix_item_total', true)); }
    public function get_total_tax()  { return floatval(get_post_meta($this->id, '_tix_item_tax', true)); }
    public function get_name()       { return get_post_meta($this->id, '_tix_item_name', true) ?: $this->post->post_title; }
    public function get_cat_name()   { return get_post_meta($this->id, '_tix_item_cat_name', true); }

    public function get_meta($key, $single = true) {
        return get_post_meta($this->id, $key, $single);
    }
}

/**
 * TIX_DateTime – Einfaches DateTime-Objekt kompatibel mit WC_DateTime API.
 * Wird nur geladen wenn WooCommerce nicht aktiv ist.
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
