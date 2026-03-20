<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Statistics – Statistik-Dashboard mit KPIs, Charts und Filtern.
 * Nutzt Chart.js (CDN) für Visualisierung, AJAX für Daten.
 */
class TIX_Statistics {

    /* ──────────────────────────── Bootstrap ──────────────────────────── */

    public static function init() {
        if (!wp_doing_ajax()) {
            add_action('admin_menu', [__CLASS__, 'add_menu']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        }
        // AJAX endpoints
        $tabs = ['overview','revenue','tickets','events','checkin','carts','newsletter','discounts','export'];
        foreach ($tabs as $t) {
            add_action('wp_ajax_tix_stats_' . $t, [__CLASS__, 'ajax_' . $t]);
        }
        // Cache invalidation
        add_action('woocommerce_order_status_changed', [__CLASS__, 'flush_cache']);
        add_action('save_post_tix_ticket',             [__CLASS__, 'flush_cache']);
        add_action('save_post_event',                  [__CLASS__, 'flush_cache']);
    }

    public static function add_menu() {
        add_submenu_page(
            'tixomat',
            'Tixomat Statistiken',
            'Statistiken',
            'manage_options',
            'tix-statistics',
            [__CLASS__, 'render']
        );
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'tixomat_page_tix-statistics') return;
        wp_enqueue_style('dashicons');
        wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        $min = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
        wp_enqueue_style('tix-statistics', TIXOMAT_URL . 'assets/css/statistics' . $min . '.css', ['tix-admin'], TIXOMAT_VERSION);

        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', [], '4.4.0', true);
        wp_enqueue_script('chartjs-adapter', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3/dist/chartjs-adapter-date-fns.bundle.min.js', ['chartjs'], '3.0.0', true);
        wp_enqueue_script('tix-statistics', TIXOMAT_URL . 'assets/js/statistics' . $min . '.js', ['jquery', 'chartjs', 'chartjs-adapter'], TIXOMAT_VERSION, true);

        wp_localize_script('tix-statistics', 'tixStats', [
            'ajaxurl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('tix_stats_nonce'),
            'events'     => self::get_events_list(),
            'locations'  => self::get_locations_list(),
            'categories' => self::get_event_categories(),
            'currency'   => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '€',
        ]);
    }

    public static function flush_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tix_stats_%' OR option_name LIKE '_transient_timeout_tix_stats_%'");
    }

    /* ──────────────────────────── Helpers ──────────────────────────── */

    private static function is_hpos() {
        return class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    private static function get_events_list() {
        $events = get_posts(['post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $list = [];
        foreach ($events as $e) $list[] = ['id' => $e->ID, 'title' => $e->post_title];
        return $list;
    }

    private static function get_locations_list() {
        $locs = get_posts(['post_type' => 'tix_location', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
        $list = [];
        foreach ($locs as $l) $list[] = ['id' => $l->ID, 'title' => $l->post_title];
        return $list;
    }

    private static function get_event_categories() {
        $terms = get_terms(['taxonomy' => 'event_category', 'hide_empty' => false]);
        $list = [];
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) $list[] = ['id' => $t->term_id, 'name' => $t->name];
        }
        return $list;
    }

    private static function parse_filters() {
        $f = [
            'date_from'    => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to'      => sanitize_text_field($_POST['date_to'] ?? ''),
            'event_id'     => intval($_POST['event_id'] ?? 0),
            'location_id'  => intval($_POST['location_id'] ?? 0),
            'category_id'  => intval($_POST['category_id'] ?? 0),
            'compare_mode' => !empty($_POST['compare_mode']) && $_POST['compare_mode'] === '1',
            'compare_from' => sanitize_text_field($_POST['compare_from'] ?? ''),
            'compare_to'   => sanitize_text_field($_POST['compare_to'] ?? ''),
        ];
        return $f;
    }

    private static function cache_key($tab, $filters) {
        $key = 'tix_stats_' . $tab . '_' . md5(wp_json_encode($filters));
        return $key;
    }

    /**
     * Vergleichs-Helper: Führt eine Scalar-Query für aktuellen + Vergleichszeitraum aus.
     * Gibt KPI-Array zurück mit compare + trend.
     */
    private static function compare_kpi($f, $eids, $kpi_key, $kpi_label, $kpi_icon, $current_val, $format = 'number') {
        $kpi = [
            'value' => $format === 'eur' ? self::format_eur($current_val) : ($format === 'pct' ? $current_val . '%' : $current_val),
            'raw'   => floatval($current_val),
            'label' => $kpi_label,
            'icon'  => $kpi_icon,
            'trend' => null,
            'compare' => null,
            'compare_raw' => null,
        ];

        // Vorperiode für Trend (immer berechnen wenn Datum gesetzt)
        if ($f['date_from'] && $f['date_to']) {
            list($pf, $pt) = self::prev_period($f['date_from'], $f['date_to']);
        }

        // Vergleichsmodus
        if ($f['compare_mode'] && $f['compare_from'] && $f['compare_to']) {
            $kpi['compare_from'] = $f['compare_from'];
            $kpi['compare_to']   = $f['compare_to'];
        }

        return $kpi;
    }

    /**
     * Injiziert Vergleichsdaten in Timeline-Chart-Config.
     * Fügt ein gestricheltes Dataset für den Vergleichszeitraum hinzu.
     */
    private static function inject_compare_timeline(&$chart, $f, $eids, $query_fn, $value_key, $label, $color, $extra_args = []) {
        if (!$f['compare_mode'] || !$f['compare_from'] || !$f['compare_to']) return;

        $group = self::auto_group($f['date_from'], $f['date_to']);
        $args = array_merge([$f['compare_from'], $f['compare_to'], $eids], $extra_args, [$group]);
        $cmp_data = call_user_func_array([__CLASS__, $query_fn], $args);
        $cmp_values = array_map(function($v) use ($value_key) { return $value_key === 'cnt' ? intval($v->$value_key) : floatval($v->$value_key); }, $cmp_data);
        $cmp_labels = array_column($cmp_data, 'period');

        // Auf gleiche Länge bringen
        $len = count($chart['data']['datasets'][0]['data'] ?? []);
        $cmp_values = array_pad(array_slice($cmp_values, 0, $len), $len, 0);
        $cmp_labels = array_pad(array_slice($cmp_labels, 0, $len), $len, '');

        $chart['data']['datasets'][] = [
            'label' => $label . ' (Vergleich)',
            'data' => $cmp_values,
            'borderColor' => $color,
            'backgroundColor' => 'transparent',
            'borderWidth' => 2,
            'borderDash' => [6, 4],
            'fill' => false,
            'tension' => 0.3,
            'pointStyle' => 'rect',
            'pointRadius' => 2,
            'yAxisID' => $chart['data']['datasets'][0]['yAxisID'] ?? 'y',
        ];
        $chart['_compare_labels'] = $cmp_labels;
    }

    /**
     * Injiziert Vergleichsdaten in Bar-Chart-Config.
     */
    private static function inject_compare_bar(&$chart, $cmp_values, $label, $color) {
        $chart['data']['datasets'][] = [
            'label' => $label . ' (Vergleich)',
            'data' => $cmp_values,
            'backgroundColor' => $color . '55',
            'borderColor' => $color . '99',
            'borderWidth' => 1,
            'borderRadius' => isset($chart['data']['datasets'][0]['borderRadius']) ? $chart['data']['datasets'][0]['borderRadius'] : 0,
        ];
    }

    private static function format_eur($val) {
        return number_format(floatval($val), 2, ',', '.') . ' €';
    }

    private static function trend($current, $previous) {
        if (!$previous || $previous == 0) return null;
        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    /** Berechne Vorperiode: gleiche Dauer, verschoben */
    private static function prev_period($from, $to) {
        $d1 = new DateTime($from);
        $d2 = new DateTime($to);
        $diff = $d1->diff($d2);
        $days = $diff->days + 1;
        $prev_to   = (clone $d1)->modify('-1 day')->format('Y-m-d');
        $prev_from = (clone $d1)->modify('-' . $days . ' days')->format('Y-m-d');
        return [$prev_from, $prev_to];
    }

    /** Filtere Events nach Location / Kategorie → Array von Event-IDs */
    private static function filtered_event_ids($f) {
        $args = ['post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids'];
        if ($f['location_id']) {
            $args['meta_query'][] = ['key' => '_tix_location_id', 'value' => $f['location_id']];
        }
        if ($f['category_id']) {
            $args['tax_query'][] = ['taxonomy' => 'event_category', 'terms' => $f['category_id']];
        }
        if ($f['event_id']) return [$f['event_id']];
        if ($f['location_id'] || $f['category_id']) return get_posts($args);
        return []; // leer = alle
    }

    /** Revenue aus WC Orders (HPOS-kompatibel) */
    private static function query_revenue($from, $to, $event_ids = []) {
        global $wpdb;
        $hpos = self::is_hpos();
        $ot   = $hpos ? "{$wpdb->prefix}wc_orders" : $wpdb->posts;
        $oj   = $hpos
            ? "INNER JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id AND o.status IN ('wc-completed','wc-processing')"
            : "INNER JOIN {$wpdb->posts} o ON oi.order_id = o.ID AND o.post_status IN ('wc-completed','wc-processing')";
        $dc   = $hpos ? 'o.date_created_gmt' : 'o.post_date';

        $where = "oi.order_item_type = 'line_item'";
        $params = [];
        if ($from) { $where .= " AND $dc >= %s"; $params[] = $from . ' 00:00:00'; }
        if ($to)   { $where .= " AND $dc <= %s"; $params[] = $to   . ' 23:59:59'; }

        // Event-Filter über product_id → _tix_parent_event_id
        if (!empty($event_ids)) {
            $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
            $where .= " AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
                INNER JOIN {$wpdb->postmeta} evm ON CAST(oim_pid.meta_value AS UNSIGNED) = evm.post_id AND evm.meta_key = '_tix_parent_event_id' AND evm.meta_value IN ($placeholders)
                WHERE oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key = '_product_id'
            )";
            $params = array_merge($params, $event_ids);
        }

        $sql = "SELECT COALESCE(SUM(CAST(oim.meta_value AS DECIMAL(10,2))), 0) as revenue,
                       COUNT(DISTINCT oi.order_id) as order_count
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_line_total'
                $oj
                WHERE $where";

        return $wpdb->get_row(empty($params) ? $sql : $wpdb->prepare($sql, ...$params));
    }

    /** Revenue über Zeit (für Line-Charts) */
    private static function query_revenue_over_time($from, $to, $event_ids = [], $group = 'day') {
        global $wpdb;
        $hpos = self::is_hpos();
        $oj   = $hpos
            ? "INNER JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id AND o.status IN ('wc-completed','wc-processing')"
            : "INNER JOIN {$wpdb->posts} o ON oi.order_id = o.ID AND o.post_status IN ('wc-completed','wc-processing')";
        $dc   = $hpos ? 'o.date_created_gmt' : 'o.post_date';
        $df   = $group === 'month' ? '%Y-%m' : ($group === 'week' ? '%x-W%v' : '%Y-%m-%d');

        $where = "oi.order_item_type = 'line_item'";
        $params = [$df];
        if ($from) { $where .= " AND $dc >= %s"; $params[] = $from . ' 00:00:00'; }
        if ($to)   { $where .= " AND $dc <= %s"; $params[] = $to   . ' 23:59:59'; }

        if (!empty($event_ids)) {
            $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
            $where .= " AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
                INNER JOIN {$wpdb->postmeta} evm ON CAST(oim_pid.meta_value AS UNSIGNED) = evm.post_id AND evm.meta_key = '_tix_parent_event_id' AND evm.meta_value IN ($placeholders)
                WHERE oim_pid.order_item_id = oi.order_item_id AND oim_pid.meta_key = '_product_id'
            )";
            $params = array_merge($params, $event_ids);
        }

        $sql = "SELECT DATE_FORMAT($dc, %s) as period,
                       COALESCE(SUM(CAST(oim.meta_value AS DECIMAL(10,2))), 0) as revenue,
                       COUNT(DISTINCT oi.order_id) as orders,
                       SUM(CAST(oim_qty.meta_value AS UNSIGNED)) as tickets
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_line_total'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
                $oj
                WHERE $where
                GROUP BY period ORDER BY period";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /** Tickets (eigenes CPT) zählen */
    private static function query_tickets($from, $to, $event_ids = [], $statuses = ['valid','checked_in','redeemed']) {
        global $wpdb;
        $status_in = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";
        $where = "p.post_type = 'tix_ticket' AND p.post_status = 'publish'";
        $where .= " AND sm.meta_value IN ($status_in)";
        $params = [];
        if ($from) { $where .= " AND p.post_date >= %s"; $params[] = $from . ' 00:00:00'; }
        if ($to)   { $where .= " AND p.post_date <= %s"; $params[] = $to   . ' 23:59:59'; }
        if (!empty($event_ids)) {
            $ph = implode(',', array_fill(0, count($event_ids), '%d'));
            $where .= " AND em.meta_value IN ($ph)";
            $params = array_merge($params, $event_ids);
        }
        $sql = "SELECT COUNT(*) as cnt,
                       COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(10,2))), 0) as revenue
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} sm ON p.ID = sm.post_id AND sm.meta_key = '_tix_ticket_status'
                LEFT JOIN {$wpdb->postmeta} em ON p.ID = em.post_id AND em.meta_key = '_tix_ticket_event_id'
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_ticket_price'
                WHERE $where";
        return $wpdb->get_row(empty($params) ? $sql : $wpdb->prepare($sql, ...$params));
    }

    /** Tickets nach Kategorie */
    private static function query_tickets_by_cat($from, $to, $event_ids = []) {
        global $wpdb;
        $where = "p.post_type = 'tix_ticket' AND p.post_status = 'publish' AND sm.meta_value IN ('valid','checked_in','redeemed')";
        $params = [];
        if ($from) { $where .= " AND p.post_date >= %s"; $params[] = $from . ' 00:00:00'; }
        if ($to)   { $where .= " AND p.post_date <= %s"; $params[] = $to   . ' 23:59:59'; }
        if (!empty($event_ids)) {
            $ph = implode(',', array_fill(0, count($event_ids), '%d'));
            $where .= " AND em.meta_value IN ($ph)";
            $params = array_merge($params, $event_ids);
        }
        $sql = "SELECT COALESCE(cm.meta_value, 'Unbekannt') as cat_name, COUNT(*) as cnt,
                       COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(10,2))), 0) as revenue
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} sm ON p.ID = sm.post_id AND sm.meta_key = '_tix_ticket_status'
                LEFT JOIN {$wpdb->postmeta} cm ON p.ID = cm.post_id AND cm.meta_key = '_tix_ticket_cat_name'
                LEFT JOIN {$wpdb->postmeta} em ON p.ID = em.post_id AND em.meta_key = '_tix_ticket_event_id'
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_ticket_price'
                WHERE $where
                GROUP BY cat_name ORDER BY cnt DESC";
        return $wpdb->get_results(empty($params) ? $sql : $wpdb->prepare($sql, ...$params));
    }

    /** Tickets über Zeit */
    private static function query_tickets_over_time($from, $to, $event_ids = []) {
        global $wpdb;
        $where = "p.post_type = 'tix_ticket' AND p.post_status = 'publish' AND sm.meta_value IN ('valid','checked_in','redeemed')";
        $params = [];
        if ($from) { $where .= " AND p.post_date >= %s"; $params[] = $from . ' 00:00:00'; }
        if ($to)   { $where .= " AND p.post_date <= %s"; $params[] = $to   . ' 23:59:59'; }
        if (!empty($event_ids)) {
            $ph = implode(',', array_fill(0, count($event_ids), '%d'));
            $where .= " AND em.meta_value IN ($ph)";
            $params = array_merge($params, $event_ids);
        }
        $sql = "SELECT DATE(p.post_date) as period, COUNT(*) as cnt
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} sm ON p.ID = sm.post_id AND sm.meta_key = '_tix_ticket_status'
                LEFT JOIN {$wpdb->postmeta} em ON p.ID = em.post_id AND em.meta_key = '_tix_ticket_event_id'
                WHERE $where
                GROUP BY period ORDER BY period";
        return $wpdb->get_results(empty($params) ? $sql : $wpdb->prepare($sql, ...$params));
    }

    /** Top Events nach Umsatz (via eigenes Ticket-CPT) */
    private static function query_top_events($from, $to, $event_ids = [], $limit = 5) {
        global $wpdb;
        $where = "p.post_type = 'tix_ticket' AND p.post_status = 'publish' AND sm.meta_value IN ('valid','checked_in','redeemed')";
        $params = [];
        if ($from) { $where .= " AND p.post_date >= %s"; $params[] = $from . ' 00:00:00'; }
        if ($to)   { $where .= " AND p.post_date <= %s"; $params[] = $to   . ' 23:59:59'; }
        if (!empty($event_ids)) {
            $ph = implode(',', array_fill(0, count($event_ids), '%d'));
            $where .= " AND em.meta_value IN ($ph)";
            $params = array_merge($params, $event_ids);
        }
        $params[] = $limit;
        $sql = "SELECT em.meta_value as event_id, COUNT(*) as cnt,
                       COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(10,2))), 0) as revenue
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} sm ON p.ID = sm.post_id AND sm.meta_key = '_tix_ticket_status'
                INNER JOIN {$wpdb->postmeta} em ON p.ID = em.post_id AND em.meta_key = '_tix_ticket_event_id'
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_ticket_price'
                WHERE $where
                GROUP BY em.meta_value ORDER BY revenue DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        foreach ($rows as &$r) {
            $r->event_title = get_the_title(intval($r->event_id)) ?: '(Event #' . $r->event_id . ')';
        }
        return $rows;
    }

    /** Wochentag-Auswertung: Umsatz nach Bestelltag */
    private static function query_revenue_by_weekday($from, $to, $event_ids = []) {
        global $wpdb;
        $hpos = self::is_hpos();
        $oj = $hpos
            ? "INNER JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id AND o.status IN ('wc-completed','wc-processing')"
            : "INNER JOIN {$wpdb->posts} o ON oi.order_id = o.ID AND o.post_status IN ('wc-completed','wc-processing')";
        $dc = $hpos ? 'o.date_created_gmt' : 'o.post_date';
        $where = "oi.order_item_type = 'line_item'";
        $params = [];
        if ($from) { $where .= " AND $dc >= %s"; $params[] = $from . ' 00:00:00'; }
        if ($to)   { $where .= " AND $dc <= %s"; $params[] = $to   . ' 23:59:59'; }
        $sql = "SELECT DAYOFWEEK($dc) as dow, COALESCE(SUM(CAST(oim.meta_value AS DECIMAL(10,2))), 0) as revenue
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_line_total'
                $oj WHERE $where GROUP BY dow ORDER BY dow";
        return $wpdb->get_results(empty($params) ? $sql : $wpdb->prepare($sql, ...$params));
    }

    /** Revenue nach Zahlungsart */
    private static function query_revenue_by_payment($from, $to) {
        global $wpdb;
        $hpos = self::is_hpos();
        if ($hpos) {
            $where = "o.status IN ('wc-completed','wc-processing')";
            $params = [];
            if ($from) { $where .= " AND o.date_created_gmt >= %s"; $params[] = $from . ' 00:00:00'; }
            if ($to)   { $where .= " AND o.date_created_gmt <= %s"; $params[] = $to   . ' 23:59:59'; }
            $sql = "SELECT o.payment_method_title as method, COUNT(*) as cnt, SUM(o.total_amount) as revenue
                    FROM {$wpdb->prefix}wc_orders o WHERE $where AND o.payment_method_title != ''
                    GROUP BY o.payment_method_title ORDER BY revenue DESC";
        } else {
            $where = "p.post_status IN ('wc-completed','wc-processing') AND p.post_type = 'shop_order'";
            $params = [];
            if ($from) { $where .= " AND p.post_date >= %s"; $params[] = $from . ' 00:00:00'; }
            if ($to)   { $where .= " AND p.post_date <= %s"; $params[] = $to   . ' 23:59:59'; }
            $sql = "SELECT pm.meta_value as method, COUNT(*) as cnt,
                           COALESCE(SUM(CAST(pm2.meta_value AS DECIMAL(10,2))), 0) as revenue
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_payment_method_title'
                    LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_order_total'
                    WHERE $where GROUP BY pm.meta_value ORDER BY revenue DESC";
        }
        return $wpdb->get_results(empty($params) ? $sql : $wpdb->prepare($sql, ...$params));
    }

    /** Granularität auto-detect */
    private static function auto_group($from, $to) {
        if (!$from || !$to) return 'month';
        $days = (new DateTime($to))->diff(new DateTime($from))->days;
        if ($days <= 31) return 'day';
        if ($days <= 180) return 'week';
        return 'month';
    }

    /** Wochentag-Label (deutsch) */
    private static function dow_label($dow) {
        $labels = [1 => 'So', 2 => 'Mo', 3 => 'Di', 4 => 'Mi', 5 => 'Do', 6 => 'Fr', 7 => 'Sa'];
        return $labels[$dow] ?? '';
    }

    /* ──────────────────────────── AJAX: Übersicht ──────────────────────────── */

    public static function ajax_overview() {
        check_ajax_referer('tix_stats_nonce', 'nonce');
        $f = self::parse_filters();
        $ck = self::cache_key('overview', $f);
        $cached = get_transient($ck);
        if ($cached !== false) { wp_send_json_success($cached); }

        global $wpdb;
        $eids = self::filtered_event_ids($f);

        // KPIs aktuelle Periode
        $rev  = self::query_revenue($f['date_from'], $f['date_to'], $eids);
        $tix  = self::query_tickets($f['date_from'], $f['date_to'], $eids);
        $tix_ci = self::query_tickets($f['date_from'], $f['date_to'], $eids, ['checked_in','redeemed']);

        // Aktive Events
        $active_where = "p.post_type = 'event' AND p.post_status = 'publish'";
        if (!empty($eids)) {
            $ph = implode(',', array_map('intval', $eids));
            $active_where .= " AND p.ID IN ($ph)";
        }
        $active_events = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} sm ON p.ID = sm.post_id AND sm.meta_key = '_tix_event_status'
            WHERE $active_where AND (sm.meta_value IS NULL OR sm.meta_value = '' OR sm.meta_value IN ('available','few_tickets'))");

        // Auslastung
        $cap_where = "p.post_type = 'event' AND p.post_status = 'publish'";
        if (!empty($eids)) $cap_where .= " AND p.ID IN (" . implode(',', array_map('intval', $eids)) . ")";
        $sold_total = $wpdb->get_var("SELECT COALESCE(SUM(CAST(m.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON m.post_id=p.ID WHERE $cap_where AND m.meta_key='_tix_sold_total'");
        $cap_total  = $wpdb->get_var("SELECT COALESCE(SUM(CAST(m.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON m.post_id=p.ID WHERE $cap_where AND m.meta_key='_tix_capacity_total'");
        $utilization = $cap_total > 0 ? round($sold_total / $cap_total * 100, 1) : 0;
        $checkin_rate = ($tix->cnt > 0) ? round($tix_ci->cnt / $tix->cnt * 100, 1) : 0;
        $avg_order = ($rev->order_count > 0) ? $rev->revenue / $rev->order_count : 0;

        // KPIs: Vergleich oder Vorperiode für Trend
        $cmp = $f['compare_mode'] && $f['compare_from'] && $f['compare_to'];
        if ($cmp) {
            $prev_rev = self::query_revenue($f['compare_from'], $f['compare_to'], $eids);
            $prev_tix = self::query_tickets($f['compare_from'], $f['compare_to'], $eids);
            $prev_tix_ci = self::query_tickets($f['compare_from'], $f['compare_to'], $eids, ['checked_in','redeemed']);
            $prev_avg = ($prev_rev->order_count > 0) ? $prev_rev->revenue / $prev_rev->order_count : 0;
            $prev_ci_rate = ($prev_tix->cnt > 0) ? round($prev_tix_ci->cnt / $prev_tix->cnt * 100, 1) : 0;
        } else {
            list($pf, $pt) = self::prev_period($f['date_from'], $f['date_to']);
            $prev_rev = self::query_revenue($pf, $pt, $eids);
            $prev_tix = self::query_tickets($pf, $pt, $eids);
        }

        $kpis = [
            'revenue'      => ['value' => self::format_eur($rev->revenue),     'label' => 'Gesamtumsatz',       'icon' => 'dashicons-money-alt',     'trend' => self::trend($rev->revenue, $prev_rev->revenue),  'compare' => $cmp ? self::format_eur($prev_rev->revenue) : null],
            'tickets'      => ['value' => intval($tix->cnt),                    'label' => 'Tickets verkauft',   'icon' => 'dashicons-tickets-alt',   'trend' => self::trend($tix->cnt, $prev_tix->cnt),          'compare' => $cmp ? intval($prev_tix->cnt) : null],
            'active'       => ['value' => intval($active_events),               'label' => 'Aktive Events',      'icon' => 'dashicons-calendar-alt',  'trend' => null, 'compare' => null],
            'avg_order'    => ['value' => self::format_eur($avg_order),         'label' => 'Ø Bestellwert',      'icon' => 'dashicons-cart',          'trend' => $cmp ? self::trend($avg_order, $prev_avg ?? 0) : null, 'compare' => $cmp ? self::format_eur($prev_avg ?? 0) : null],
            'utilization'  => ['value' => $utilization . '%',                   'label' => 'Auslastung',         'icon' => 'dashicons-performance',   'trend' => null, 'compare' => null],
            'checkin_rate' => ['value' => $checkin_rate . '%',                  'label' => 'Check-in Rate',      'icon' => 'dashicons-groups',        'trend' => $cmp ? self::trend($checkin_rate, $prev_ci_rate ?? 0) : null, 'compare' => $cmp ? ($prev_ci_rate ?? 0) . '%' : null],
        ];

        // Charts
        $group = self::auto_group($f['date_from'], $f['date_to']);
        $timeline = self::query_revenue_over_time($f['date_from'], $f['date_to'], $eids, $group);
        $top5     = self::query_top_events($f['date_from'], $f['date_to'], $eids, 5);
        $cats     = self::query_tickets_by_cat($f['date_from'], $f['date_to'], $eids);

        $charts = [
            'timeline' => [
                'type' => 'line',
                'data' => [
                    'labels' => array_column($timeline, 'period'),
                    'datasets' => [
                        ['label' => 'Umsatz (€)', 'data' => array_map('floatval', array_column($timeline, 'revenue')), 'yAxisID' => 'y', 'borderColor' => tix_primary(), 'backgroundColor' => 'rgba(255,85,0,0.08)', 'fill' => true, 'tension' => 0.3],
                        ['label' => 'Tickets', 'data' => array_map('intval', array_column($timeline, 'tickets')), 'yAxisID' => 'y1', 'borderColor' => '#10b981', 'backgroundColor' => 'transparent', 'tension' => 0.3],
                    ],
                ],
                'options' => ['scales' => ['y' => ['position' => 'left', 'beginAtZero' => true], 'y1' => ['position' => 'right', 'beginAtZero' => true, 'grid' => ['drawOnChartArea' => false]]]],
            ],
            'top_events' => [
                'type' => 'bar',
                'data' => [
                    'labels' => array_column($top5, 'event_title'),
                    'datasets' => [['label' => 'Umsatz (€)', 'data' => array_map('floatval', array_column($top5, 'revenue')), 'backgroundColor' => tix_primary()]],
                ],
                'options' => ['indexAxis' => 'y'],
            ],
            'categories' => [
                'type' => 'doughnut',
                'data' => [
                    'labels' => array_column($cats, 'cat_name'),
                    'datasets' => [['data' => array_map('intval', array_column($cats, 'cnt')), 'backgroundColor' => [tix_primary(),'#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16']]],
                ],
            ],
        ];

        // Vergleichsdaten in Timeline-Chart injizieren
        self::inject_compare_timeline($charts['timeline'], $f, $eids, 'query_revenue_over_time', 'revenue', 'Umsatz (€)', tix_primary());
        if ($cmp) {
            // Zweite Vergleichslinie für Tickets
            $group = self::auto_group($f['date_from'], $f['date_to']);
            $cmp_timeline = self::query_revenue_over_time($f['compare_from'], $f['compare_to'], $eids, $group);
            $cmp_tix_vals = array_map('intval', array_column($cmp_timeline, 'tickets'));
            $len = count($charts['timeline']['data']['datasets'][1]['data'] ?? []);
            $cmp_tix_vals = array_pad(array_slice($cmp_tix_vals, 0, $len), $len, 0);
            $charts['timeline']['data']['datasets'][] = [
                'label' => 'Tickets (Vergleich)', 'data' => $cmp_tix_vals,
                'yAxisID' => 'y1', 'borderColor' => '#10b981', 'backgroundColor' => 'transparent',
                'borderWidth' => 2, 'borderDash' => [6, 4], 'fill' => false, 'tension' => 0.3,
                'pointStyle' => 'rect', 'pointRadius' => 2,
            ];

            // Top Events Vergleich
            $cmp_top5 = self::query_top_events($f['compare_from'], $f['compare_to'], $eids, 5);
            $cmp_top_map = [];
            foreach ($cmp_top5 as $ct) $cmp_top_map[$ct->event_title] = floatval($ct->revenue);
            $cmp_top_vals = [];
            foreach ($top5 as $t) $cmp_top_vals[] = $cmp_top_map[$t->event_title] ?? 0;
            self::inject_compare_bar($charts['top_events'], $cmp_top_vals, 'Umsatz (€)', tix_primary());

            // Kategorien Vergleich
            $cmp_cats = self::query_tickets_by_cat($f['compare_from'], $f['compare_to'], $eids);
            $cmp_cat_map = [];
            foreach ($cmp_cats as $cc) $cmp_cat_map[$cc->cat_name] = intval($cc->cnt);
            $cmp_cat_vals = [];
            foreach ($cats as $c) $cmp_cat_vals[] = $cmp_cat_map[$c->cat_name] ?? 0;
            // Doughnut: Vergleich als Tooltip-Daten mitgeben
            $charts['categories']['_compare_data'] = $cmp_cat_vals;
        }

        $data = [
            'kpis' => $kpis,
            'charts' => $charts,
            'compare_mode' => $cmp,
            'compare_label' => $cmp ? $f['compare_from'] . ' – ' . $f['compare_to'] : null,
        ];
        set_transient($ck, $data, 600);
        wp_send_json_success($data);
    }

    /* ──────────────────────────── AJAX: Umsatz ──────────────────────────── */

    public static function ajax_revenue() {
        check_ajax_referer('tix_stats_nonce', 'nonce');
        $f = self::parse_filters();
        $ck = self::cache_key('revenue', $f);
        $cached = get_transient($ck);
        if ($cached !== false) { wp_send_json_success($cached); }

        $eids = self::filtered_event_ids($f);
        $rev  = self::query_revenue($f['date_from'], $f['date_to'], $eids);
        $cmp = $f['compare_mode'] && $f['compare_from'] && $f['compare_to'];
        if ($cmp) {
            $prev = self::query_revenue($f['compare_from'], $f['compare_to'], $eids);
        } else {
            list($pf, $pt) = self::prev_period($f['date_from'], $f['date_to']);
            $prev = self::query_revenue($pf, $pt, $eids);
        }
        $tix  = self::query_tickets($f['date_from'], $f['date_to'], $eids);
        $top  = self::query_top_events($f['date_from'], $f['date_to'], $eids, 50);
        $avg_ticket = $tix->cnt > 0 ? $tix->revenue / $tix->cnt : 0;
        $avg_order  = $rev->order_count > 0 ? $rev->revenue / $rev->order_count : 0;
        $rev_per_event = count($top) > 0 ? $rev->revenue / count($top) : 0;
        $change = self::trend($rev->revenue, $prev->revenue);

        $prev_avg_ticket = 0; $prev_avg_order = 0; $prev_rev_event = 0;
        if ($cmp) {
            $prev_tix = self::query_tickets($f['compare_from'], $f['compare_to'], $eids);
            $prev_top = self::query_top_events($f['compare_from'], $f['compare_to'], $eids, 50);
            $prev_avg_ticket = $prev_tix->cnt > 0 ? $prev_tix->revenue / $prev_tix->cnt : 0;
            $prev_avg_order = $prev->order_count > 0 ? $prev->revenue / $prev->order_count : 0;
            $prev_rev_event = count($prev_top) > 0 ? $prev->revenue / count($prev_top) : 0;
        }

        $kpis = [
            'revenue'      => ['value' => self::format_eur($rev->revenue),    'label' => 'Gesamtumsatz',       'icon' => 'dashicons-money-alt',   'trend' => $change, 'compare' => $cmp ? self::format_eur($prev->revenue) : null],
            'prev_revenue' => ['value' => self::format_eur($prev->revenue),   'label' => $cmp ? 'Vergleichszeitraum' : 'Vorperiode', 'icon' => 'dashicons-backup', 'trend' => null, 'compare' => null],
            'change'       => ['value' => ($change !== null ? ($change > 0 ? '+' : '') . $change . '%' : '–'), 'label' => 'Änderung', 'icon' => 'dashicons-chart-line', 'trend' => $change, 'compare' => null],
            'avg_ticket'   => ['value' => self::format_eur($avg_ticket),      'label' => 'Ø Ticketpreis',      'icon' => 'dashicons-tag',         'trend' => $cmp ? self::trend($avg_ticket, $prev_avg_ticket) : null, 'compare' => $cmp ? self::format_eur($prev_avg_ticket) : null],
            'avg_order'    => ['value' => self::format_eur($avg_order),        'label' => 'Ø Bestellwert',      'icon' => 'dashicons-cart',        'trend' => $cmp ? self::trend($avg_order, $prev_avg_order) : null, 'compare' => $cmp ? self::format_eur($prev_avg_order) : null],
            'rev_event'    => ['value' => self::format_eur($rev_per_event),    'label' => 'Umsatz / Event',     'icon' => 'dashicons-calendar-alt','trend' => $cmp ? self::trend($rev_per_event, $prev_rev_event) : null, 'compare' => $cmp ? self::format_eur($prev_rev_event) : null],
        ];

        $group = self::auto_group($f['date_from'], $f['date_to']);
        $timeline = self::query_revenue_over_time($f['date_from'], $f['date_to'], $eids, $group);
        $cats     = self::query_tickets_by_cat($f['date_from'], $f['date_to'], $eids);
        $payment  = self::query_revenue_by_payment($f['date_from'], $f['date_to']);
        $weekday  = self::query_revenue_by_weekday($f['date_from'], $f['date_to'], $eids);

        $colors = [tix_primary(),'#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16'];

        $charts = [
            'timeline' => [
                'type' => 'line',
                'data' => [
                    'labels' => array_column($timeline, 'period'),
                    'datasets' => [['label' => 'Umsatz (€)', 'data' => array_map('floatval', array_column($timeline, 'revenue')), 'borderColor' => tix_primary(), 'backgroundColor' => 'rgba(255,85,0,0.08)', 'fill' => true, 'tension' => 0.3]],
                ],
            ],
            'by_event' => [
                'type' => 'bar',
                'data' => [
                    'labels' => array_column($top, 'event_title'),
                    'datasets' => [['label' => 'Umsatz (€)', 'data' => array_map('floatval', array_column($top, 'revenue')), 'backgroundColor' => tix_primary()]],
                ],
            ],
            'by_category' => [
                'type' => 'bar',
                'data' => [
                    'labels' => array_column($cats, 'cat_name'),
                    'datasets' => [['label' => 'Umsatz (€)', 'data' => array_map('floatval', array_column($cats, 'revenue')), 'backgroundColor' => $colors]],
                ],
            ],
            'by_payment' => [
                'type' => 'doughnut',
                'data' => [
                    'labels' => array_column($payment, 'method'),
                    'datasets' => [['data' => array_map('floatval', array_column($payment, 'revenue')), 'backgroundColor' => $colors]],
                ],
            ],
            'by_weekday' => [
                'type' => 'bar',
                'data' => [
                    'labels' => array_map(function($r) { return self::dow_label($r->dow); }, $weekday),
                    'datasets' => [['label' => 'Umsatz (€)', 'data' => array_map('floatval', array_column($weekday, 'revenue')), 'backgroundColor' => tix_primary()]],
                ],
            ],
        ];

        // Tabelle
        $table = [];
        foreach ($top as $r) {
            $ev_id = intval($r->event_id);
            $date  = get_post_meta($ev_id, '_tix_date_start', true);
            $loc_id = get_post_meta($ev_id, '_tix_location_id', true);
            $sold  = get_post_meta($ev_id, '_tix_sold_total', true);
            $cap   = get_post_meta($ev_id, '_tix_capacity_total', true);
            $table[] = [
                'event'       => $r->event_title,
                'location'    => $loc_id ? get_the_title($loc_id) : '–',
                'date'        => $date ? date_i18n('d.m.Y', strtotime($date)) : '–',
                'tickets'     => intval($r->cnt),
                'revenue'     => self::format_eur($r->revenue),
                'avg_price'   => $r->cnt > 0 ? self::format_eur($r->revenue / $r->cnt) : '–',
                'utilization' => $cap > 0 ? round($sold / $cap * 100, 1) . '%' : '–',
            ];
        }

        // Vergleichsdaten in Charts injizieren
        if ($cmp) {
            self::inject_compare_timeline($charts['timeline'], $f, $eids, 'query_revenue_over_time', 'revenue', 'Umsatz (€)', tix_primary());
        }

        $data = ['kpis' => $kpis, 'charts' => $charts, 'table' => $table, 'compare_mode' => $cmp, 'compare_label' => $cmp ? $f['compare_from'] . ' – ' . $f['compare_to'] : null];
        set_transient($ck, $data, 600);
        wp_send_json_success($data);
    }

    /* ──────────────────────────── AJAX: Tickets ──────────────────────────── */

    public static function ajax_tickets() {
        check_ajax_referer('tix_stats_nonce', 'nonce');
        $f = self::parse_filters();
        $ck = self::cache_key('tickets', $f);
        $cached = get_transient($ck);
        if ($cached !== false) { wp_send_json_success($cached); }

        global $wpdb;
        $eids = self::filtered_event_ids($f);

        $sold      = self::query_tickets($f['date_from'], $f['date_to'], $eids, ['valid','checked_in','redeemed']);
        $cancelled = self::query_tickets($f['date_from'], $f['date_to'], $eids, ['cancelled']);
        $transferred = self::query_tickets($f['date_from'], $f['date_to'], $eids, ['transferred']);
        $today     = self::query_tickets(current_time('Y-m-d'), current_time('Y-m-d'), $eids);

        // Kapazität
        $cap_where = "p.post_type = 'event' AND p.post_status = 'publish'";
        if (!empty($eids)) $cap_where .= " AND p.ID IN (" . implode(',', array_map('intval', $eids)) . ")";
        $cap_total = $wpdb->get_var("SELECT COALESCE(SUM(CAST(m.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON m.post_id=p.ID WHERE $cap_where AND m.meta_key='_tix_capacity_total'");
        $sold_total_all = $wpdb->get_var("SELECT COALESCE(SUM(CAST(m.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON m.post_id=p.ID WHERE $cap_where AND m.meta_key='_tix_sold_total'");
        $sell_through = $cap_total > 0 ? round($sold_total_all / $cap_total * 100, 1) : 0;

        $cmp = $f['compare_mode'] && $f['compare_from'] && $f['compare_to'];
        if ($cmp) {
            $prev_sold = self::query_tickets($f['compare_from'], $f['compare_to'], $eids);
            $prev_cancelled = self::query_tickets($f['compare_from'], $f['compare_to'], $eids, ['cancelled']);
        } else {
            list($pf, $pt) = self::prev_period($f['date_from'], $f['date_to']);
            $prev_sold = self::query_tickets($pf, $pt, $eids);
        }

        $kpis = [
            'sold'         => ['value' => intval($sold->cnt),       'label' => 'Verkauft',          'icon' => 'dashicons-tickets-alt','trend' => self::trend($sold->cnt, $prev_sold->cnt), 'compare' => $cmp ? intval($prev_sold->cnt) : null],
            'cancelled'    => ['value' => intval($cancelled->cnt),  'label' => 'Storniert',         'icon' => 'dashicons-no-alt',    'trend' => $cmp ? self::trend($cancelled->cnt, $prev_cancelled->cnt ?? 0) : null, 'compare' => $cmp ? intval($prev_cancelled->cnt ?? 0) : null],
            'transferred'  => ['value' => intval($transferred->cnt),'label' => 'Übertragen',        'icon' => 'dashicons-randomize', 'trend' => null, 'compare' => null],
            'capacity'     => ['value' => intval($cap_total),       'label' => 'Gesamtkapazität',   'icon' => 'dashicons-admin-site','trend' => null, 'compare' => null],
            'sell_through' => ['value' => $sell_through . '%',      'label' => 'Sell-Through',      'icon' => 'dashicons-performance','trend' => null, 'compare' => null],
            'today'        => ['value' => intval($today->cnt),      'label' => 'Heute verkauft',    'icon' => 'dashicons-clock',     'trend' => null, 'compare' => null],
        ];

        $timeline = self::query_tickets_over_time($f['date_from'], $f['date_to'], $eids);
        $cats     = self::query_tickets_by_cat($f['date_from'], $f['date_to'], $eids);
        $top      = self::query_top_events($f['date_from'], $f['date_to'], $eids, 20);

        $colors = [tix_primary(),'#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16'];

        $charts = [
            'timeline' => [
                'type' => 'line',
                'data' => [
                    'labels' => array_column($timeline, 'period'),
                    'datasets' => [['label' => 'Tickets', 'data' => array_map('intval', array_column($timeline, 'cnt')), 'borderColor' => tix_primary(), 'backgroundColor' => 'rgba(255,85,0,0.08)', 'fill' => true, 'tension' => 0.3]],
                ],
            ],
            'by_category' => [
                'type' => 'doughnut',
                'data' => [
                    'labels' => array_column($cats, 'cat_name'),
                    'datasets' => [['data' => array_map('intval', array_column($cats, 'cnt')), 'backgroundColor' => $colors]],
                ],
            ],
            'sell_through' => [
                'type' => 'bar',
                'data' => [
                    'labels' => array_map(function($r) {
                        $cap = intval(get_post_meta(intval($r->event_id), '_tix_capacity_total', true));
                        $sold = intval(get_post_meta(intval($r->event_id), '_tix_sold_total', true));
                        return $r->event_title;
                    }, $top),
                    'datasets' => [['label' => 'Auslastung %', 'data' => array_map(function($r) {
                        $cap = intval(get_post_meta(intval($r->event_id), '_tix_capacity_total', true));
                        $sold = intval(get_post_meta(intval($r->event_id), '_tix_sold_total', true));
                        return $cap > 0 ? round($sold / $cap * 100, 1) : 0;
                    }, $top), 'backgroundColor' => tix_primary()]],
                ],
                'options' => ['indexAxis' => 'y'],
            ],
        ];

        // Tabelle
        $table = [];
        foreach ($top as $r) {
            $ev_id = intval($r->event_id);
            $cap = intval(get_post_meta($ev_id, '_tix_capacity_total', true));
            $sold_ev = intval(get_post_meta($ev_id, '_tix_sold_total', true));
            $cats_meta = get_post_meta($ev_id, '_tix_ticket_categories', true);
            $cat_names = [];
            if (is_array($cats_meta)) foreach ($cats_meta as $c) $cat_names[] = $c['name'] ?? '';
            $table[] = [
                'event' => $r->event_title,
                'categories' => implode(', ', $cat_names),
                'sold' => intval($r->cnt),
                'capacity' => $cap,
                'utilization' => $cap > 0 ? round($sold_ev / $cap * 100, 1) . '%' : '–',
                'revenue' => self::format_eur($r->revenue),
            ];
        }

        // Vergleichsdaten in Timeline injizieren
        if ($cmp) {
            self::inject_compare_timeline($charts['timeline'], $f, $eids, 'query_tickets_over_time', 'cnt', 'Tickets', tix_primary());
        }

        $data = ['kpis' => $kpis, 'charts' => $charts, 'table' => $table, 'compare_mode' => $cmp, 'compare_label' => $cmp ? $f['compare_from'] . ' – ' . $f['compare_to'] : null];
        set_transient($ck, $data, 600);
        wp_send_json_success($data);
    }

    /* ──────────────────────────── AJAX: Events ──────────────────────────── */

    public static function ajax_events() {
        check_ajax_referer('tix_stats_nonce', 'nonce');
        $f = self::parse_filters();
        $ck = self::cache_key('events', $f);
        $cached = get_transient($ck);
        if ($cached !== false) { wp_send_json_success($cached); }

        global $wpdb;
        $args = ['post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'publish'];
        if ($f['event_id'])    $args['post__in'] = [$f['event_id']];
        if ($f['location_id']) $args['meta_query'][] = ['key' => '_tix_location_id', 'value' => $f['location_id']];
        if ($f['category_id']) $args['tax_query'][] = ['taxonomy' => 'event_category', 'terms' => $f['category_id']];
        if ($f['date_from'])   $args['meta_query'][] = ['key' => '_tix_date_start', 'value' => $f['date_from'], 'compare' => '>=', 'type' => 'DATE'];
        if ($f['date_to'])     $args['meta_query'][] = ['key' => '_tix_date_start', 'value' => $f['date_to'],   'compare' => '<=', 'type' => 'DATE'];

        $events = get_posts($args);
        $total = count($events);

        $status_map = ['available' => 0, 'few_tickets' => 0, 'sold_out' => 0, 'cancelled' => 0, 'postponed' => 0, 'past' => 0];
        $upcoming = 0;
        $seatmap_count = 0;
        $total_sold = 0; $total_cap = 0;
        $table = [];

        foreach ($events as $ev) {
            $st   = get_post_meta($ev->ID, '_tix_event_status', true) ?: 'available';
            $date = get_post_meta($ev->ID, '_tix_date_start', true);
            $loc_id = get_post_meta($ev->ID, '_tix_location_id', true);
            $sold = intval(get_post_meta($ev->ID, '_tix_sold_total', true));
            $cap  = intval(get_post_meta($ev->ID, '_tix_capacity_total', true));
            $cats = get_post_meta($ev->ID, '_tix_ticket_categories', true);

            if (isset($status_map[$st])) $status_map[$st]++;
            if ($date && $date >= current_time('Y-m-d')) $upcoming++;
            $total_sold += $sold; $total_cap += $cap;

            // Hat Saalplan?
            if (is_array($cats)) {
                foreach ($cats as $c) {
                    if (!empty($c['seatmap_id'])) { $seatmap_count++; break; }
                }
            }

            $tix_rev = self::query_tickets('', '', [$ev->ID]);
            $table[] = [
                'event'       => $ev->post_title,
                'date'        => $date ? date_i18n('d.m.Y', strtotime($date)) : '–',
                'location'    => $loc_id ? get_the_title($loc_id) : '–',
                'status'      => $st,
                'sold'        => $sold,
                'capacity'    => $cap,
                'utilization' => $cap > 0 ? round($sold / $cap * 100, 1) . '%' : '–',
                'revenue'     => self::format_eur($tix_rev->revenue),
            ];
        }

        $avg_util = $total_cap > 0 ? round($total_sold / $total_cap * 100, 1) : 0;

        $kpis = [
            'total'     => ['value' => $total,            'label' => 'Events gesamt',      'icon' => 'dashicons-calendar-alt', 'trend' => null],
            'upcoming'  => ['value' => $upcoming,         'label' => 'Kommende',            'icon' => 'dashicons-arrow-right-alt','trend' => null],
            'sold_out'  => ['value' => $status_map['sold_out'], 'label' => 'Ausverkauft',   'icon' => 'dashicons-yes-alt',      'trend' => null],
            'cancelled' => ['value' => $status_map['cancelled'],'label' => 'Abgesagt',      'icon' => 'dashicons-no-alt',       'trend' => null],
            'avg_util'  => ['value' => $avg_util . '%',   'label' => 'Ø Auslastung',       'icon' => 'dashicons-performance',  'trend' => null],
            'seatmap'   => ['value' => $seatmap_count,    'label' => 'Mit Saalplan',        'icon' => 'dashicons-layout',       'trend' => null],
        ];

        // Charts: Status-Verteilung
        $status_labels = ['available' => 'Verfügbar', 'few_tickets' => 'Wenige Tickets', 'sold_out' => 'Ausverkauft', 'cancelled' => 'Abgesagt', 'postponed' => 'Verschoben', 'past' => 'Vergangen'];
        $colors = ['#10b981','#f59e0b','#ef4444','#64748b','#8b5cf6','#94a3b8'];

        // Location-Verteilung
        $loc_counts = [];
        foreach ($events as $ev) {
            $lid = get_post_meta($ev->ID, '_tix_location_id', true);
            $ln  = $lid ? get_the_title($lid) : 'Ohne Location';
            $loc_counts[$ln] = ($loc_counts[$ln] ?? 0) + 1;
        }
        arsort($loc_counts);

        // Monatliche Verteilung
        $month_counts = [];
        foreach ($events as $ev) {
            $date = get_post_meta($ev->ID, '_tix_date_start', true);
            if ($date) {
                $m = date('Y-m', strtotime($date));
                $month_counts[$m] = ($month_counts[$m] ?? 0) + 1;
            }
        }
        ksort($month_counts);

        $charts = [
            'by_status' => [
                'type' => 'doughnut',
                'data' => [
                    'labels' => array_values(array_intersect_key($status_labels, array_filter($status_map))),
                    'datasets' => [['data' => array_values(array_filter($status_map)), 'backgroundColor' => array_slice($colors, 0, count(array_filter($status_map)))]],
                ],
            ],
            'by_location' => [
                'type' => 'bar',
                'data' => [
                    'labels' => array_keys($loc_counts),
                    'datasets' => [['label' => 'Events', 'data' => array_values($loc_counts), 'backgroundColor' => tix_primary()]],
                ],
            ],
            'by_month' => [
                'type' => 'bar',
                'data' => [
                    'labels' => array_keys($month_counts),
                    'datasets' => [['label' => 'Events', 'data' => array_values($month_counts), 'backgroundColor' => '#10b981']],
                ],
            ],
        ];

        $data = ['kpis' => $kpis, 'charts' => $charts, 'table' => $table, 'compare_mode' => $f['compare_mode'], 'compare_label' => $f['compare_mode'] ? $f['compare_from'] . ' – ' . $f['compare_to'] : null];
        set_transient($ck, $data, 600);
        wp_send_json_success($data);
    }

    /* ──────────────────────────── AJAX: Check-in ──────────────────────────── */

    public static function ajax_checkin() {
        check_ajax_referer('tix_stats_nonce', 'nonce');
        $f = self::parse_filters();
        $ck = self::cache_key('checkin', $f);
        $cached = get_transient($ck);
        if ($cached !== false) { wp_send_json_success($cached); }

        global $wpdb;
        $eids = self::filtered_event_ids($f);

        $total   = self::query_tickets($f['date_from'], $f['date_to'], $eids);
        $checked = self::query_tickets($f['date_from'], $f['date_to'], $eids, ['checked_in','redeemed']);
        $ci_rate = $total->cnt > 0 ? round($checked->cnt / $total->cnt * 100, 1) : 0;

        // Gästeliste-Statistik
        $guest_total = 0; $guest_checked = 0;
        $g_args = ['post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids'];
        if (!empty($eids)) $g_args['post__in'] = $eids;
        $g_events = get_posts($g_args);
        foreach ($g_events as $gid) {
            $gl = get_post_meta($gid, '_tix_guest_list', true);
            if (!is_array($gl)) continue;
            foreach ($gl as $g) {
                $guest_total++;
                if (!empty($g['checked_in'])) $guest_checked++;
            }
        }
        $guest_rate = $guest_total > 0 ? round($guest_checked / $guest_total * 100, 1) : 0;

        // No-Show (vergangene Events: valid Tickets ohne Check-in)
        $past_eids = [];
        foreach ($g_events as $gid) {
            $ds = get_post_meta($gid, '_tix_date_start', true);
            if ($ds && $ds < current_time('Y-m-d')) $past_eids[] = $gid;
        }
        $noshow = 0;
        if (!empty($past_eids)) {
            $ns = self::query_tickets('', '', $past_eids, ['valid']);
            $noshow_total = self::query_tickets('', '', $past_eids);
            $noshow = $noshow_total->cnt > 0 ? round($ns->cnt / $noshow_total->cnt * 100, 1) : 0;
        }

        $kpis = [
            'checked'     => ['value' => intval($checked->cnt), 'label' => 'Eingecheckt',   'icon' => 'dashicons-yes-alt',  'trend' => null],
            'ci_rate'     => ['value' => $ci_rate . '%',        'label' => 'Check-in Rate',  'icon' => 'dashicons-groups',   'trend' => null],
            'guests'      => ['value' => $guest_checked,        'label' => 'Gäste eingecheckt','icon' => 'dashicons-admin-users','trend' => null],
            'guest_rate'  => ['value' => $guest_rate . '%',     'label' => 'Gäste-Rate',     'icon' => 'dashicons-star-filled','trend' => null],
            'noshow'      => ['value' => $noshow . '%',         'label' => 'No-Show Rate',   'icon' => 'dashicons-dismiss', 'trend' => null],
        ];

        // Check-in pro Event
        $top = self::query_top_events($f['date_from'], $f['date_to'], $eids, 20);
        $ci_per_event = [];
        foreach ($top as $r) {
            $ev_id = intval($r->event_id);
            $ev_total = self::query_tickets('', '', [$ev_id]);
            $ev_ci    = self::query_tickets('', '', [$ev_id], ['checked_in','redeemed']);
            $ci_per_event[] = [
                'title' => $r->event_title,
                'rate'  => $ev_total->cnt > 0 ? round($ev_ci->cnt / $ev_total->cnt * 100, 1) : 0,
            ];
        }

        $charts = [
            'ci_per_event' => [
                'type' => 'bar',
                'data' => [
                    'labels' => array_column($ci_per_event, 'title'),
                    'datasets' => [['label' => 'Check-in Rate %', 'data' => array_column($ci_per_event, 'rate'), 'backgroundColor' => '#10b981']],
                ],
                'options' => ['indexAxis' => 'y'],
            ],
        ];

        // Check-in nach Kategorie
        $cats = self::query_tickets_by_cat($f['date_from'], $f['date_to'], $eids);
        $cat_ci = [];
        foreach ($cats as $c) {
            // Approximation: wir haben keine Kategorie-spezifische Check-in Query, zeigen nur Gesamt
            $cat_ci[] = ['name' => $c->cat_name, 'total' => intval($c->cnt)];
        }
        $charts['by_category'] = [
            'type' => 'bar',
            'data' => [
                'labels' => array_column($cat_ci, 'name'),
                'datasets' => [['label' => 'Tickets', 'data' => array_column($cat_ci, 'total'), 'backgroundColor' => tix_primary()]],
            ],
        ];

        $data = ['kpis' => $kpis, 'charts' => $charts, 'compare_mode' => $f['compare_mode'], 'compare_label' => $f['compare_mode'] ? $f['compare_from'] . ' – ' . $f['compare_to'] : null];
        set_transient($ck, $data, 600);
        wp_send_json_success($data);
    }

    /* ──────────────────────────── AJAX: Warenkörbe ──────────────────────────── */

    public static function ajax_carts() {
        check_ajax_referer('tix_stats_nonce', 'nonce');
        $f = self::parse_filters();
        $ck = self::cache_key('carts', $f);
        $cached = get_transient($ck);
        if ($cached !== false) { wp_send_json_success($cached); }

        global $wpdb;

        $where = "p.post_type = 'tix_abandoned_cart' AND p.post_status = 'publish'";
        $params = [];
        if ($f['date_from']) { $where .= " AND p.post_date >= %s"; $params[] = $f['date_from'] . ' 00:00:00'; }
        if ($f['date_to'])   { $where .= " AND p.post_date <= %s"; $params[] = $f['date_to']   . ' 23:59:59'; }

        // Status-Counts
        $sql = "SELECT COALESCE(sm.meta_value, 'pending') as status, COUNT(*) as cnt
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} sm ON p.ID = sm.post_id AND sm.meta_key = '_tix_ac_status'
                WHERE $where GROUP BY status";
        $rows = $wpdb->get_results(empty($params) ? $sql : $wpdb->prepare($sql, ...$params));

        $status_counts = ['pending' => 0, 'sent' => 0, 'recovered' => 0, 'expired' => 0];
        foreach ($rows as $r) $status_counts[$r->status] = intval($r->cnt);
        $ac_total = array_sum($status_counts);
        $emails_sent = $status_counts['sent'] + $status_counts['recovered'];
        $recovery_rate = $ac_total > 0 ? round($status_counts['recovered'] / $ac_total * 100, 1) : 0;

        $kpis = [
            'total'     => ['value' => $ac_total,                        'label' => 'Verlassene Warenkörbe', 'icon' => 'dashicons-cart',       'trend' => null],
            'sent'      => ['value' => $emails_sent,                     'label' => 'E-Mails gesendet',      'icon' => 'dashicons-email-alt',  'trend' => null],
            'recovered' => ['value' => $status_counts['recovered'],      'label' => 'Wiederhergestellt',     'icon' => 'dashicons-yes-alt',    'trend' => null],
            'rate'      => ['value' => $recovery_rate . '%',             'label' => 'Recovery Rate',          'icon' => 'dashicons-performance','trend' => null],
        ];

        // Funnel
        $charts = [
            'funnel' => [
                'type' => 'bar',
                'data' => [
                    'labels' => ['Gesamt', 'E-Mail gesendet', 'Wiederhergestellt'],
                    'datasets' => [['data' => [$ac_total, $emails_sent, $status_counts['recovered']], 'backgroundColor' => [tix_primary(), '#f59e0b', '#10b981']]],
                ],
            ],
        ];

        // Warenkörbe über Zeit
        $sql2 = "SELECT DATE(p.post_date) as period, COUNT(*) as cnt
                 FROM {$wpdb->posts} p WHERE $where GROUP BY period ORDER BY period";
        $over_time = $wpdb->get_results(empty($params) ? $sql2 : $wpdb->prepare($sql2, ...$params));
        $charts['timeline'] = [
            'type' => 'line',
            'data' => [
                'labels' => array_column($over_time, 'period'),
                'datasets' => [['label' => 'Warenkörbe', 'data' => array_map('intval', array_column($over_time, 'cnt')), 'borderColor' => '#ef4444', 'tension' => 0.3]],
            ],
        ];

        $data = ['kpis' => $kpis, 'charts' => $charts, 'compare_mode' => $f['compare_mode'], 'compare_label' => $f['compare_mode'] ? $f['compare_from'] . ' – ' . $f['compare_to'] : null];
        set_transient($ck, $data, 600);
        wp_send_json_success($data);
    }

    /* ──────────────────────────── AJAX: Newsletter ──────────────────────────── */

    public static function ajax_newsletter() {
        check_ajax_referer('tix_stats_nonce', 'nonce');
        $f = self::parse_filters();
        $ck = self::cache_key('newsletter', $f);
        $cached = get_transient($ck);
        if ($cached !== false) { wp_send_json_success($cached); }

        global $wpdb;

        // Gesamt aktive
        $total_active = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} sm ON p.ID = sm.post_id AND sm.meta_key = '_tix_sub_status'
            WHERE p.post_type = 'tix_subscriber' AND p.post_status = 'publish'
            AND (sm.meta_value IS NULL OR sm.meta_value = '' OR sm.meta_value = 'active')");

        // Neue in Periode
        $where = "p.post_type = 'tix_subscriber' AND p.post_status = 'publish'";
        $params = [];
        if ($f['date_from']) { $where .= " AND p.post_date >= %s"; $params[] = $f['date_from'] . ' 00:00:00'; }
        if ($f['date_to'])   { $where .= " AND p.post_date <= %s"; $params[] = $f['date_to']   . ' 23:59:59'; }
        $new_count = $wpdb->get_var(empty($params)
            ? "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where"
            : $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE $where", ...$params));

        // Abmeldungen in Periode
        $unsub = $wpdb->get_var(empty($params)
            ? "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} sm ON p.ID = sm.post_id AND sm.meta_key = '_tix_sub_status' AND sm.meta_value = 'unsubscribed' WHERE $where"
            : $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} sm ON p.ID = sm.post_id AND sm.meta_key = '_tix_sub_status' AND sm.meta_value = 'unsubscribed' WHERE $where", ...$params));

        $growth = $total_active > 0 ? round(($new_count - $unsub) / $total_active * 100, 1) : 0;

        // Quelle
        $sources = $wpdb->get_results(empty($params)
            ? "SELECT COALESCE(src.meta_value, 'unbekannt') as source, COUNT(*) as cnt FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} src ON p.ID = src.post_id AND src.meta_key = '_tix_sub_source' WHERE $where GROUP BY source ORDER BY cnt DESC"
            : $wpdb->prepare("SELECT COALESCE(src.meta_value, 'unbekannt') as source, COUNT(*) as cnt FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} src ON p.ID = src.post_id AND src.meta_key = '_tix_sub_source' WHERE $where GROUP BY source ORDER BY cnt DESC", ...$params));

        $kpis = [
            'total'   => ['value' => intval($total_active), 'label' => 'Abonnenten gesamt', 'icon' => 'dashicons-email-alt',   'trend' => null],
            'new'     => ['value' => intval($new_count),     'label' => 'Neue Abonnenten',   'icon' => 'dashicons-plus-alt2',   'trend' => null],
            'unsub'   => ['value' => intval($unsub),         'label' => 'Abmeldungen',       'icon' => 'dashicons-minus',       'trend' => null],
            'growth'  => ['value' => $growth . '%',          'label' => 'Wachstumsrate',     'icon' => 'dashicons-chart-line',  'trend' => null],
        ];

        // Über Zeit
        $over_time = $wpdb->get_results(empty($params)
            ? "SELECT DATE_FORMAT(p.post_date, '%Y-%m') as period, COUNT(*) as cnt FROM {$wpdb->posts} p WHERE $where GROUP BY period ORDER BY period"
            : $wpdb->prepare("SELECT DATE_FORMAT(p.post_date, '%Y-%m') as period, COUNT(*) as cnt FROM {$wpdb->posts} p WHERE $where GROUP BY period ORDER BY period", ...$params));

        $colors = [tix_primary(),'#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4'];

        $charts = [
            'timeline' => [
                'type' => 'bar',
                'data' => [
                    'labels' => array_column($over_time, 'period'),
                    'datasets' => [['label' => 'Neue Abonnenten', 'data' => array_map('intval', array_column($over_time, 'cnt')), 'backgroundColor' => tix_primary()]],
                ],
            ],
            'by_source' => [
                'type' => 'doughnut',
                'data' => [
                    'labels' => array_column($sources, 'source'),
                    'datasets' => [['data' => array_map('intval', array_column($sources, 'cnt')), 'backgroundColor' => $colors]],
                ],
            ],
        ];

        $data = ['kpis' => $kpis, 'charts' => $charts, 'compare_mode' => $f['compare_mode'], 'compare_label' => $f['compare_mode'] ? $f['compare_from'] . ' – ' . $f['compare_to'] : null];
        set_transient($ck, $data, 600);
        wp_send_json_success($data);
    }

    /* ──────────────────────────── AJAX: Rabatte ──────────────────────────── */

    public static function ajax_discounts() {
        check_ajax_referer('tix_stats_nonce', 'nonce');
        $f = self::parse_filters();
        $ck = self::cache_key('discounts', $f);
        $cached = get_transient($ck);
        if ($cached !== false) { wp_send_json_success($cached); }

        global $wpdb;
        $hpos = self::is_hpos();
        $oj = $hpos
            ? "INNER JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id AND o.status IN ('wc-completed','wc-processing')"
            : "INNER JOIN {$wpdb->posts} o ON oi.order_id = o.ID AND o.post_status IN ('wc-completed','wc-processing')";
        $dc = $hpos ? 'o.date_created_gmt' : 'o.post_date';

        $where = "oi.order_item_type = 'fee'";
        $params = [];
        if ($f['date_from']) { $where .= " AND $dc >= %s"; $params[] = $f['date_from'] . ' 00:00:00'; }
        if ($f['date_to'])   { $where .= " AND $dc <= %s"; $params[] = $f['date_to']   . ' 23:59:59'; }

        // Alle Rabatt-Fees (negative Beträge)
        $sql = "SELECT oi.order_item_name as fee_name,
                       COUNT(*) as cnt,
                       COALESCE(SUM(ABS(CAST(oim.meta_value AS DECIMAL(10,2)))), 0) as total
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_line_total' AND CAST(oim.meta_value AS DECIMAL(10,2)) < 0
                $oj WHERE $where GROUP BY oi.order_item_name ORDER BY total DESC";

        $fees = $wpdb->get_results(empty($params) ? $sql : $wpdb->prepare($sql, ...$params));

        $total_discount = 0; $total_orders = 0;
        $type_map = ['bundle' => 0, 'combo' => 0, 'group' => 0, 'other' => 0];
        foreach ($fees as $fee) {
            $total_discount += $fee->total;
            $total_orders += $fee->cnt;
            $name = mb_strtolower($fee->fee_name);
            if (strpos($name, 'bundle') !== false || strpos($name, '🎁') !== false) $type_map['bundle'] += $fee->total;
            elseif (strpos($name, 'combo') !== false || strpos($name, 'kombi') !== false || strpos($name, '🎫') !== false) $type_map['combo'] += $fee->total;
            elseif (strpos($name, 'gruppe') !== false || strpos($name, 'group') !== false) $type_map['group'] += $fee->total;
            else $type_map['other'] += $fee->total;
        }

        // Gesamt-Bestellungen für Nutzungsrate
        $rev = self::query_revenue($f['date_from'], $f['date_to']);
        $usage_rate = $rev->order_count > 0 ? round($total_orders / $rev->order_count * 100, 1) : 0;
        $avg = $total_orders > 0 ? $total_discount / $total_orders : 0;

        $kpis = [
            'total'   => ['value' => self::format_eur($total_discount), 'label' => 'Gesamtrabatt',          'icon' => 'dashicons-tag',         'trend' => null],
            'orders'  => ['value' => $total_orders,                     'label' => 'Bestellungen mit Rabatt','icon' => 'dashicons-cart',        'trend' => null],
            'avg'     => ['value' => self::format_eur($avg),            'label' => 'Ø Rabatthöhe',           'icon' => 'dashicons-chart-bar',   'trend' => null],
            'rate'    => ['value' => $usage_rate . '%',                 'label' => 'Nutzungsrate',           'icon' => 'dashicons-performance', 'trend' => null],
            'bundle'  => ['value' => self::format_eur($type_map['bundle']), 'label' => 'Bundle-Rabatte',     'icon' => 'dashicons-products',    'trend' => null],
            'combo'   => ['value' => self::format_eur($type_map['combo']),  'label' => 'Kombi-Rabatte',      'icon' => 'dashicons-tickets-alt', 'trend' => null],
        ];

        $colors = [tix_primary(),'#10b981','#f59e0b','#ef4444'];
        $type_labels = ['bundle' => 'Bundle-Deal', 'combo' => 'Kombi-Ticket', 'group' => 'Gruppenrabatt', 'other' => 'Sonstige'];
        $filtered_types = array_filter($type_map);

        $charts = [
            'by_type' => [
                'type' => 'doughnut',
                'data' => [
                    'labels' => array_values(array_intersect_key($type_labels, $filtered_types)),
                    'datasets' => [['data' => array_values($filtered_types), 'backgroundColor' => array_slice($colors, 0, count($filtered_types))]],
                ],
            ],
        ];

        // Über Zeit
        $sql2 = "SELECT DATE_FORMAT($dc, '%Y-%m') as period,
                        COALESCE(SUM(ABS(CAST(oim.meta_value AS DECIMAL(10,2)))), 0) as total
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_line_total' AND CAST(oim.meta_value AS DECIMAL(10,2)) < 0
                 $oj WHERE $where GROUP BY period ORDER BY period";
        $over_time = $wpdb->get_results(empty($params) ? $sql2 : $wpdb->prepare($sql2, ...$params));
        $charts['timeline'] = [
            'type' => 'line',
            'data' => [
                'labels' => array_column($over_time, 'period'),
                'datasets' => [['label' => 'Rabatte (€)', 'data' => array_map('floatval', array_column($over_time, 'total')), 'borderColor' => '#ef4444', 'tension' => 0.3]],
            ],
        ];

        $data = ['kpis' => $kpis, 'charts' => $charts, 'compare_mode' => $f['compare_mode'], 'compare_label' => $f['compare_mode'] ? $f['compare_from'] . ' – ' . $f['compare_to'] : null];
        set_transient($ck, $data, 600);
        wp_send_json_success($data);
    }

    /* ──────────────────────────── AJAX: CSV-Export ──────────────────────────── */

    public static function ajax_export() {
        check_ajax_referer('tix_stats_nonce', 'nonce');
        $tab = sanitize_text_field($_POST['tab'] ?? 'overview');
        $f   = self::parse_filters();

        // Tab-Daten laden (ohne Cache)
        $method = 'get_export_' . $tab;
        if (!method_exists(__CLASS__, $method)) {
            wp_send_json_error('Tab nicht verfügbar');
        }
        $rows = self::$method($f);

        // CSV generieren
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tixomat-statistik-' . $tab . '-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]), ';');
            foreach ($rows as $row) fputcsv($out, array_values($row), ';');
        }
        fclose($out);
        exit;
    }

    private static function get_export_revenue($f) {
        $eids = self::filtered_event_ids($f);
        $top  = self::query_top_events($f['date_from'], $f['date_to'], $eids, 1000);
        $rows = [];
        foreach ($top as $r) {
            $ev_id = intval($r->event_id);
            $rows[] = [
                'Event' => $r->event_title,
                'Datum' => get_post_meta($ev_id, '_tix_date_start', true),
                'Tickets' => intval($r->cnt),
                'Umsatz' => number_format(floatval($r->revenue), 2, ',', '.'),
            ];
        }
        return $rows;
    }

    private static function get_export_tickets($f) { return self::get_export_revenue($f); }
    private static function get_export_events($f)  { return self::get_export_revenue($f); }
    private static function get_export_overview($f) { return self::get_export_revenue($f); }
    private static function get_export_checkin($f)  { return self::get_export_revenue($f); }
    private static function get_export_carts($f)    { return []; }
    private static function get_export_newsletter($f) { return []; }
    private static function get_export_discounts($f)  { return []; }

    /* ──────────────────────────── Render ──────────────────────────── */

    public static function render() {
        $tabs = [
            'overview'   => ['label' => 'Übersicht',    'icon' => 'dashicons-chart-area'],
            'revenue'    => ['label' => 'Umsatz',        'icon' => 'dashicons-money-alt'],
            'tickets'    => ['label' => 'Tickets',       'icon' => 'dashicons-tickets-alt'],
            'events'     => ['label' => 'Events',        'icon' => 'dashicons-calendar-alt'],
            'checkin'    => ['label' => 'Check-in',      'icon' => 'dashicons-groups'],
            'carts'      => ['label' => 'Warenkörbe',    'icon' => 'dashicons-cart'],
            'newsletter' => ['label' => 'Newsletter',    'icon' => 'dashicons-email-alt'],
            'discounts'  => ['label' => 'Rabatte',       'icon' => 'dashicons-tag'],
        ];

        // Chart-Definitionen pro Tab
        $tab_charts = [
            'overview'   => [
                ['id' => 'timeline',   'title' => 'Umsatz & Tickets über Zeit', 'icon' => 'dashicons-chart-line', 'full' => true],
                ['id' => 'top_events', 'title' => 'Top 5 Events nach Umsatz',   'icon' => 'dashicons-awards'],
                ['id' => 'categories', 'title' => 'Ticket-Kategorien',          'icon' => 'dashicons-category'],
            ],
            'revenue'    => [
                ['id' => 'timeline',    'title' => 'Umsatz über Zeit',           'icon' => 'dashicons-chart-line', 'full' => true],
                ['id' => 'by_event',    'title' => 'Umsatz nach Event',          'icon' => 'dashicons-calendar-alt'],
                ['id' => 'by_category', 'title' => 'Umsatz nach Kategorie',     'icon' => 'dashicons-category'],
                ['id' => 'by_payment',  'title' => 'Nach Zahlungsart',           'icon' => 'dashicons-money-alt'],
                ['id' => 'by_weekday',  'title' => 'Nach Wochentag',             'icon' => 'dashicons-calendar'],
            ],
            'tickets'    => [
                ['id' => 'timeline',    'title' => 'Ticketverkäufe über Zeit',   'icon' => 'dashicons-chart-line', 'full' => true],
                ['id' => 'by_category', 'title' => 'Nach Kategorie',             'icon' => 'dashicons-category'],
                ['id' => 'sell_through','title' => 'Sell-Through Rate pro Event', 'icon' => 'dashicons-performance'],
            ],
            'events'     => [
                ['id' => 'by_status',   'title' => 'Events nach Status',         'icon' => 'dashicons-flag'],
                ['id' => 'by_location', 'title' => 'Events nach Location',       'icon' => 'dashicons-location'],
                ['id' => 'by_month',    'title' => 'Events pro Monat',           'icon' => 'dashicons-calendar-alt'],
            ],
            'checkin'    => [
                ['id' => 'ci_per_event','title' => 'Check-in Rate pro Event',    'icon' => 'dashicons-groups', 'full' => true],
                ['id' => 'by_category', 'title' => 'Tickets nach Kategorie',     'icon' => 'dashicons-category'],
            ],
            'carts'      => [
                ['id' => 'funnel',   'title' => 'Recovery Funnel',               'icon' => 'dashicons-filter'],
                ['id' => 'timeline', 'title' => 'Warenkörbe über Zeit',          'icon' => 'dashicons-chart-line'],
            ],
            'newsletter' => [
                ['id' => 'timeline',  'title' => 'Neue Abonnenten pro Monat',    'icon' => 'dashicons-chart-bar'],
                ['id' => 'by_source', 'title' => 'Nach Quelle',                  'icon' => 'dashicons-admin-site-alt3'],
            ],
            'discounts'  => [
                ['id' => 'by_type',  'title' => 'Rabatt nach Typ',               'icon' => 'dashicons-tag'],
                ['id' => 'timeline', 'title' => 'Rabatte über Zeit',             'icon' => 'dashicons-chart-line'],
            ],
        ];

        // Tabellen-Spalten pro Tab
        $tab_table_cols = [
            'overview' => [],
            'revenue'  => ['Event','Location','Datum','Tickets','Umsatz','Ø Preis','Auslastung'],
            'tickets'  => ['Event','Kategorien','Verkauft','Kapazität','Auslastung','Umsatz'],
            'events'   => ['Event','Datum','Location','Status','Verkauft','Kapazität','Auslastung','Umsatz'],
        ];
        ?>
        <div class="wrap tix-settings-wrap">
            <h1>Tixomat &ndash; Statistiken</h1>

            <!-- Filter -->
            <div class="tix-stats-filters">
                <div class="tix-stats-filter-row">
                    <div class="tix-stats-filter">
                        <label>Zeitraum</label>
                        <div class="tix-stats-presets">
                            <button type="button" data-range="7">7T</button>
                            <button type="button" data-range="30" class="active">30T</button>
                            <button type="button" data-range="90">90T</button>
                            <button type="button" data-range="year">Jahr</button>
                            <button type="button" data-range="all">Gesamt</button>
                        </div>
                        <div class="tix-stats-dates">
                            <input type="date" id="tix-stats-from">
                            <span>&ndash;</span>
                            <input type="date" id="tix-stats-to">
                        </div>
                    </div>
                    <div class="tix-stats-filter">
                        <label>Event</label>
                        <select id="tix-stats-event"><option value="">Alle Events</option></select>
                    </div>
                    <div class="tix-stats-filter">
                        <label>Location</label>
                        <select id="tix-stats-location"><option value="">Alle Locations</option></select>
                    </div>
                    <div class="tix-stats-filter">
                        <label>Kategorie</label>
                        <select id="tix-stats-category"><option value="">Alle Kategorien</option></select>
                    </div>
                </div>
                <!-- Vergleichszeitraum -->
                <div class="tix-compare-controls">
                    <label class="tix-compare-toggle">
                        <input type="checkbox" id="tix-compare-mode">
                        <span>Zeiträume vergleichen</span>
                    </label>
                    <div class="tix-compare-options" style="display:none;">
                        <select id="tix-compare-type">
                            <option value="previous">Vorheriger Zeitraum</option>
                            <option value="previous_year">Vorjahr</option>
                            <option value="custom">Benutzerdefiniert</option>
                        </select>
                        <div class="tix-compare-custom-dates" style="display:none;">
                            <input type="date" id="tix-compare-from">
                            <span>&ndash;</span>
                            <input type="date" id="tix-compare-to">
                        </div>
                        <span class="tix-compare-label" id="tix-compare-label"></span>
                    </div>
                </div>
            </div>

            <div class="tix-settings-grid">
                <div class="tix-app tix-settings-app" id="tix-stats-app">

                    <nav class="tix-nav">
                        <?php $first = true; foreach ($tabs as $key => $tab): ?>
                        <button type="button" class="tix-nav-tab<?php echo $first ? ' active' : ''; ?>" data-tab="<?php echo esc_attr($key); ?>">
                            <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                            <span class="tix-nav-label"><?php echo esc_html($tab['label']); ?></span>
                        </button>
                        <?php $first = false; endforeach; ?>
                    </nav>

                    <div class="tix-content">
                        <?php $first = true; foreach ($tabs as $key => $tab): ?>
                        <div class="tix-pane<?php echo $first ? ' active' : ''; ?>" data-pane="<?php echo esc_attr($key); ?>">

                            <!-- KPIs -->
                            <div class="tix-stats-kpi" id="tix-stats-kpi-<?php echo esc_attr($key); ?>">
                                <div class="tix-stats-loading"><div class="tix-stats-spinner"></div></div>
                            </div>

                            <!-- Charts -->
                            <div class="tix-stats-charts">
                                <?php if (isset($tab_charts[$key])): foreach ($tab_charts[$key] as $chart): ?>
                                <div class="tix-card<?php echo !empty($chart['full']) ? ' tix-stats-chart-full' : ''; ?>">
                                    <div class="tix-card-header">
                                        <span class="dashicons <?php echo esc_attr($chart['icon']); ?>"></span>
                                        <h3><?php echo esc_html($chart['title']); ?></h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <canvas id="chart-<?php echo esc_attr($key . '-' . $chart['id']); ?>"></canvas>
                                    </div>
                                </div>
                                <?php endforeach; endif; ?>
                            </div>

                            <!-- Tabelle -->
                            <?php if (!empty($tab_table_cols[$key])): ?>
                            <div class="tix-card" style="margin-top: 20px;">
                                <div class="tix-card-header">
                                    <span class="dashicons dashicons-editor-table"></span>
                                    <h3>Details</h3>
                                    <button type="button" class="tix-stats-export-btn" data-tab="<?php echo esc_attr($key); ?>" title="CSV Export">
                                        <span class="dashicons dashicons-download"></span> CSV
                                    </button>
                                </div>
                                <div class="tix-card-body" style="padding: 0; overflow-x: auto;">
                                    <table class="tix-stats-table" id="tix-stats-table-<?php echo esc_attr($key); ?>">
                                        <thead>
                                            <tr>
                                                <?php foreach ($tab_table_cols[$key] as $col): ?>
                                                <th><?php echo esc_html($col); ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td colspan="<?php echo count($tab_table_cols[$key]); ?>" class="tix-stats-loading"><div class="tix-stats-spinner"></div></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>
                        <?php $first = false; endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
