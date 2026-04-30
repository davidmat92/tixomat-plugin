<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Campaign_Analytics – Kampagnen-Dashboard mit Auswertung.
 *
 * Admin-Seite unter Tixomat → Kampagnen.
 * Zeigt: Kanäle, Besucher, Tickets, Umsatz, Conversion-Rate.
 *
 * @since 1.28.92
 */
class TIX_Campaign_Analytics {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu'], 30);
        add_action('admin_post_tix_campaign_export_csv', [__CLASS__, 'handle_csv_export']);
    }

    public static function add_menu() {
        add_submenu_page(
            'tixomat',
            'Kampagnen',
            'Kampagnen',
            'manage_options',
            'tix-campaigns',
            [__CLASS__, 'render_page']
        );
    }

    // ──────────────────────────────────────────
    // Admin-Seite
    // ──────────────────────────────────────────

    public static function render_page() {
        $events  = self::get_events_list();
        $filter  = self::get_filter_from_request();
        $results = null;

        if (isset($_GET['tix_preview'])) {
            $results = self::query_analytics($filter);
        }

        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-chart-bar" style="font-size:24px;margin-right:8px"></span>Kampagnen-Tracking</h1>
            <p>Auswertung deiner Marketing-Kan&auml;le: Welcher Kanal bringt Besucher und Ticket-Verk&auml;ufe?</p>

            <form method="get" action="">
                <input type="hidden" name="page" value="tix-campaigns">
                <input type="hidden" name="tix_preview" value="1">

                <table class="form-table">
                    <tr>
                        <th>Event</th>
                        <td>
                            <select name="event_id">
                                <option value="">Alle Events</option>
                                <?php foreach ($events as $id => $title): ?>
                                    <option value="<?php echo $id; ?>" <?php selected($filter['event_id'], $id); ?>><?php echo esc_html($title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Zeitraum</th>
                        <td>
                            <input type="date" name="date_from" value="<?php echo esc_attr($filter['date_from']); ?>">
                            &ndash;
                            <input type="date" name="date_to" value="<?php echo esc_attr($filter['date_to']); ?>">
                        </td>
                    </tr>
                </table>

                <?php submit_button('Daten laden', 'secondary', 'submit', true); ?>
            </form>

            <?php if ($results !== null): ?>
                <?php if (!empty($results)): ?>
                    <?php self::render_summary($results); ?>
                    <?php self::render_table($results, $filter); ?>
                <?php else: ?>
                    <p><em>Keine Daten f&uuml;r diesen Zeitraum vorhanden.</em></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Zusammenfassung (KPI-Boxen)
    // ──────────────────────────────────────────

    private static function render_summary($results) {
        $total_views   = array_sum(array_column($results, 'views'));
        $total_orders  = array_sum(array_column($results, 'orders'));
        $total_tickets = array_sum(array_column($results, 'tickets'));
        $total_revenue = array_sum(array_column($results, 'revenue'));
        // Conversion = Bestellungen / Besucher (Anteil der Besucher die kaufen)
        $avg_conv      = $total_views > 0 ? ($total_orders / $total_views * 100) : 0;
        $channels_used = count($results);

        ?>
        <div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap">
            <?php
            $kpis = [
                ['📊', 'Kan&auml;le', $channels_used, '#e0f2fe', '#0369a1'],
                ['👁️', 'Besucher', number_format($total_views, 0, ',', '.'), '#f0fdf4', '#166534'],
                ['🛒', 'Bestellungen', number_format($total_orders, 0, ',', '.'), '#fef9c3', '#854d0e'],
                ['🎟️', 'Tickets', number_format($total_tickets, 0, ',', '.'), '#fef3c7', '#92400e'],
                ['💰', 'Umsatz', number_format($total_revenue, 2, ',', '.') . ' &euro;', '#fce7f3', '#9d174d'],
                ['📈', 'Conversion', number_format($avg_conv, 1, ',', '.') . '%', '#ede9fe', '#5b21b6'],
            ];
            foreach ($kpis as $k): ?>
            <div style="flex:1;min-width:140px;padding:16px 20px;background:<?php echo $k[3]; ?>;border-radius:10px;text-align:center">
                <div style="font-size:24px;margin-bottom:4px"><?php echo $k[0]; ?></div>
                <div style="font-size:22px;font-weight:800;color:<?php echo $k[4]; ?>"><?php echo $k[2]; ?></div>
                <div style="font-size:12px;color:#64748b;margin-top:2px"><?php echo $k[1]; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <p style="font-size:12px;color:#64748b;margin:0 0 16px;">
            <strong>Conversion</strong> = Bestellungen ÷ Besucher · <strong>Tickets</strong> = Stückzahl, <strong>Bestellungen</strong> = Anzahl distinkter Käufe.
        </p>
        <?php
    }

    // ──────────────────────────────────────────
    // Ergebnis-Tabelle + CSV-Export
    // ──────────────────────────────────────────

    private static function render_table($results, $filter) {
        // Kanal-Farben
        $colors = [
            'instagram' => '#E1306C', 'tiktok' => '#010101', 'facebook' => '#1877F2',
            'linkedin'  => '#0A66C2', 'xing'   => '#006567', 'whatsapp' => '#25D366',
            'youtube'   => '#FF0000', 'email'  => '#6366f1', 'google_ads' => '#4285F4',
            'flyer'     => '#78716c', 'website'=> '#06b6d4', 'twitter' => '#1DA1F2',
            'podcast'   => '#8B5CF6', 'telegram'=> '#0088cc', 'direct' => '#94a3b8',
        ];

        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top:8px">
            <input type="hidden" name="action" value="tix_campaign_export_csv">
            <?php wp_nonce_field('tix_campaign_export'); ?>
            <input type="hidden" name="event_id" value="<?php echo esc_attr($filter['event_id']); ?>">
            <input type="hidden" name="date_from" value="<?php echo esc_attr($filter['date_from']); ?>">
            <input type="hidden" name="date_to" value="<?php echo esc_attr($filter['date_to']); ?>">
            <?php submit_button('CSV exportieren', 'primary', 'submit', true); ?>
        </form>

        <table class="wp-list-table widefat fixed striped" style="margin-top:12px">
            <thead>
                <tr>
                    <th style="width:180px">Kanal</th>
                    <th>Kampagne</th>
                    <th style="width:100px;text-align:right">Besucher</th>
                    <th style="width:90px;text-align:right">Bestellungen</th>
                    <th style="width:80px;text-align:right">Tickets</th>
                    <th style="width:120px;text-align:right">Umsatz</th>
                    <th style="width:100px;text-align:right">Conversion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row):
                    $bg = $colors[$row['source']] ?? '#94a3b8';
                    $orders = intval($row['orders'] ?? 0);
                    $tickets = intval($row['tickets'] ?? 0);
                    // Conversion = Bestellungen / Besucher
                    $conv = $row['views'] > 0 ? ($orders / $row['views'] * 100) : 0;
                ?>
                <tr>
                    <td>
                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo $bg; ?>;margin-right:6px;vertical-align:middle"></span>
                        <strong><?php echo esc_html($row['channel_label']); ?></strong>
                    </td>
                    <td><?php echo $row['campaign'] ? esc_html($row['campaign']) : '<span style="color:#94a3b8">&mdash;</span>'; ?></td>
                    <td style="text-align:right"><?php echo number_format($row['views'], 0, ',', '.'); ?></td>
                    <td style="text-align:right"><?php echo number_format($orders, 0, ',', '.'); ?></td>
                    <td style="text-align:right"><strong><?php echo number_format($tickets, 0, ',', '.'); ?></strong></td>
                    <td style="text-align:right"><?php echo number_format($row['revenue'], 2, ',', '.') . ' &euro;'; ?></td>
                    <td style="text-align:right">
                        <?php if ($conv > 0): ?>
                            <span style="display:inline-block;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:600;background:<?php echo $conv >= 10 ? '#dcfce7;color:#166534' : ($conv >= 5 ? '#fef9c3;color:#854d0e' : '#fee2e2;color:#991b1b'); ?>"><?php echo number_format($conv, 1, ',', '.'); ?>%</span>
                        <?php else: ?>
                            <span style="color:#94a3b8">0%</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php
                $total_views   = array_sum(array_column($results, 'views'));
                $total_orders  = array_sum(array_column($results, 'orders'));
                $total_tickets = array_sum(array_column($results, 'tickets'));
                $total_revenue = array_sum(array_column($results, 'revenue'));
                $total_conv    = $total_views > 0 ? ($total_orders / $total_views * 100) : 0;
                ?>
                <tr style="font-weight:700">
                    <td>Gesamt</td>
                    <td></td>
                    <td style="text-align:right"><?php echo number_format($total_views, 0, ',', '.'); ?></td>
                    <td style="text-align:right"><?php echo number_format($total_orders, 0, ',', '.'); ?></td>
                    <td style="text-align:right"><?php echo number_format($total_tickets, 0, ',', '.'); ?></td>
                    <td style="text-align:right"><?php echo number_format($total_revenue, 2, ',', '.') . ' &euro;'; ?></td>
                    <td style="text-align:right"><?php echo number_format($total_conv, 1, ',', '.'); ?>%</td>
                </tr>
            </tfoot>
        </table>
        <?php
    }

    // ──────────────────────────────────────────
    // CSV Export
    // ──────────────────────────────────────────

    public static function handle_csv_export() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung');
        check_admin_referer('tix_campaign_export');

        $filter  = self::get_filter_from_request();
        $results = self::query_analytics($filter);

        $filename = 'tixomat-kampagnen-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM für Excel
        fputcsv($out, ['Kanal', 'Kampagne', 'Besucher', 'Bestellungen', 'Tickets', 'Umsatz', 'Conversion (%)'], ';');

        foreach ($results as $row) {
            $orders = intval($row['orders'] ?? 0);
            $conv = $row['views'] > 0 ? ($orders / $row['views'] * 100) : 0;
            fputcsv($out, [
                $row['channel_label'],
                $row['campaign'],
                $row['views'],
                $orders,
                $row['tickets'],
                number_format($row['revenue'], 2, '.', ''),
                number_format($conv, 1, '.', ''),
            ], ';');
        }

        fclose($out);
        exit;
    }

    // ──────────────────────────────────────────
    // Analytics Query
    // ──────────────────────────────────────────

    /**
     * Pageview-Daten + Order-Daten zusammenführen.
     * Liefert pro Quelle/Kampagne: views, orders (Bestellungen), tickets (Stückzahlen), revenue.
     */
    private static function query_analytics($filter) {
        global $wpdb;

        $views_data  = self::query_pageviews($filter);
        $orders_data = self::query_orders($filter);

        // Merge by source + campaign key
        $merged = [];

        foreach ($views_data as $row) {
            $key = $row['source'] . '::' . $row['campaign'];
            $merged[$key] = [
                'source'        => $row['source'],
                'campaign'      => $row['campaign'],
                'channel_label' => self::get_channel_label($row['source']),
                'views'         => intval($row['views']),
                'orders'        => 0,
                'tickets'       => 0,
                'revenue'       => 0.0,
            ];
        }

        foreach ($orders_data as $row) {
            $key = $row['source'] . '::' . $row['campaign'];
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'source'        => $row['source'],
                    'campaign'      => $row['campaign'],
                    'channel_label' => self::get_channel_label($row['source']),
                    'views'         => 0,
                    'orders'        => 0,
                    'tickets'       => 0,
                    'revenue'       => 0.0,
                ];
            }
            $merged[$key]['orders']  = intval($row['orders']);
            $merged[$key]['tickets'] = intval($row['tickets']);
            $merged[$key]['revenue'] = floatval($row['revenue']);
        }

        // Sortieren nach Tickets absteigend, dann Besucher
        usort($merged, function ($a, $b) {
            return $b['tickets'] - $a['tickets'] ?: $b['views'] - $a['views'];
        });

        return $merged;
    }

    /**
     * Pageviews aus campaign_views Tabelle.
     */
    private static function query_pageviews($filter) {
        global $wpdb;

        $table = $wpdb->prefix . 'tixomat_campaign_views';

        // Prüfen ob Tabelle existiert
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$exists) return [];

        $where = ['1=1'];
        $args  = [];

        if (!empty($filter['event_id'])) {
            $where[] = 'event_id = %d';
            $args[]  = intval($filter['event_id']);
        }
        if (!empty($filter['date_from'])) {
            $where[] = 'view_date >= %s';
            $args[]  = $filter['date_from'];
        }
        if (!empty($filter['date_to'])) {
            $where[] = 'view_date <= %s';
            $args[]  = $filter['date_to'];
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT source, campaign, SUM(views) AS views
                FROM $table
                WHERE $where_sql
                GROUP BY source, campaign
                ORDER BY views DESC";

        if (!empty($args)) {
            $sql = $wpdb->prepare($sql, ...$args);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Order-Daten aus WC-Orders mit _tix_campaign_source Meta + native Orders.
     * Liefert pro Quelle/Kampagne:
     *   - orders   = Anzahl distinkter Bestellungen
     *   - tickets  = Summe der Ticket-Stückzahlen über alle Bestellungen
     *   - revenue  = Summe der Gesamtbeträge
     */
    private static function query_orders($filter) {
        global $wpdb;

        // WC orders
        $wc_data = [];
        if (class_exists('WooCommerce')) {
            $is_hpos = class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

            $wc_data = $is_hpos
                ? self::query_orders_hpos($filter)
                : self::query_orders_legacy($filter);
        }

        // Native orders (wc_order_id = 0)
        $native_data = self::query_orders_native($filter);

        // Merge by source + campaign — orders, tickets, revenue addieren
        $merged = [];
        foreach (array_merge($wc_data, $native_data) as $row) {
            $key = $row['source'] . '::' . $row['campaign'];
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'source'   => $row['source'],
                    'campaign' => $row['campaign'],
                    'orders'   => intval($row['orders'] ?? 0),
                    'tickets'  => intval($row['tickets'] ?? 0),
                    'revenue'  => floatval($row['revenue'] ?? 0),
                ];
            } else {
                $merged[$key]['orders']  += intval($row['orders'] ?? 0);
                $merged[$key]['tickets'] += intval($row['tickets'] ?? 0);
                $merged[$key]['revenue'] += floatval($row['revenue'] ?? 0);
            }
        }

        return array_values($merged);
    }

    /**
     * Order-Query für HPOS (Custom Orders Table).
     */
    private static function query_orders_hpos($filter) {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'wc_orders';
        $meta_table   = $wpdb->prefix . 'wc_orders_meta';

        $where = ["o.status IN ('wc-completed', 'wc-processing')"];
        $args  = [];

        // Source-Meta muss existieren
        $where[] = "ms.meta_value IS NOT NULL AND ms.meta_value != ''";

        if (!empty($filter['date_from'])) {
            $where[] = 'o.date_created_gmt >= %s';
            $args[]  = $filter['date_from'] . ' 00:00:00';
        }
        if (!empty($filter['date_to'])) {
            $where[] = 'o.date_created_gmt <= %s';
            $args[]  = $filter['date_to'] . ' 23:59:59';
        }

        // Event-Filter: Prüfe ob Order Tickets für dieses Event enthält
        $event_join = '';
        if (!empty($filter['event_id'])) {
            $event_join = "INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = o.id
                           INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_ev ON oim_ev.order_item_id = oi.order_item_id
                               AND oim_ev.meta_key = '_tix_event_id' AND oim_ev.meta_value = %d";
            $args[] = intval($filter['event_id']);
        }

        $where_sql = implode(' AND ', $where);

        // Orders + Revenue pro Quelle/Kampagne
        $sql = "SELECT
                    ms.meta_value AS source,
                    COALESCE(mc.meta_value, '') AS campaign,
                    COUNT(DISTINCT o.id) AS orders,
                    SUM(o.total_amount) AS revenue,
                    GROUP_CONCAT(DISTINCT o.id) AS order_ids
                FROM $orders_table o
                INNER JOIN $meta_table ms ON ms.order_id = o.id AND ms.meta_key = '_tix_campaign_source'
                LEFT JOIN $meta_table mc ON mc.order_id = o.id AND mc.meta_key = '_tix_campaign_name'
                $event_join
                WHERE $where_sql
                GROUP BY ms.meta_value, mc.meta_value
                ORDER BY revenue DESC";

        if (!empty($args)) {
            $sql = $wpdb->prepare($sql, ...$args);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        // Tickets pro Bestellung über order_items + _qty meta
        foreach ($rows as &$r) {
            $r['tickets'] = self::count_wc_tickets_for_orders(
                array_filter(array_map('intval', explode(',', $r['order_ids'] ?? ''))),
                intval($filter['event_id'] ?? 0)
            );
            unset($r['order_ids']);
        }
        unset($r);

        return $rows;
    }

    /**
     * Zählt Ticket-Stückzahlen (SUM _qty) für gegebene WC-Order-IDs.
     * Optional auf ein Event eingeschränkt.
     */
    private static function count_wc_tickets_for_orders($order_ids, $event_id = 0) {
        if (empty($order_ids)) return 0;
        global $wpdb;
        $ids_in = implode(',', array_map('intval', $order_ids));

        $event_filter = '';
        if ($event_id > 0) {
            $event_filter = "AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}woocommerce_order_itemmeta oim_ev
                WHERE oim_ev.order_item_id = oi.order_item_id
                  AND oim_ev.meta_key = '_tix_event_id'
                  AND oim_ev.meta_value = " . intval($event_id) . "
            )";
        }

        $sql = "SELECT COALESCE(SUM(CAST(oim_q.meta_value AS UNSIGNED)), 0)
                FROM {$wpdb->prefix}woocommerce_order_items oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_q
                    ON oim_q.order_item_id = oi.order_item_id AND oim_q.meta_key = '_qty'
                WHERE oi.order_id IN ($ids_in) AND oi.order_item_type = 'line_item' $event_filter";
        return intval($wpdb->get_var($sql));
    }

    /**
     * Order-Query für Legacy (Post-Meta).
     */
    private static function query_orders_legacy($filter) {
        global $wpdb;

        $where = ["p.post_type = 'shop_order'", "p.post_status IN ('wc-completed', 'wc-processing')"];
        $args  = [];

        // Source-Meta muss existieren
        $where[] = "ms.meta_value IS NOT NULL AND ms.meta_value != ''";

        if (!empty($filter['date_from'])) {
            $where[] = 'p.post_date >= %s';
            $args[]  = $filter['date_from'] . ' 00:00:00';
        }
        if (!empty($filter['date_to'])) {
            $where[] = 'p.post_date <= %s';
            $args[]  = $filter['date_to'] . ' 23:59:59';
        }

        $event_join = '';
        if (!empty($filter['event_id'])) {
            $event_join = "INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = p.ID
                           INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_ev ON oim_ev.order_item_id = oi.order_item_id
                               AND oim_ev.meta_key = '_tix_event_id' AND oim_ev.meta_value = %d";
            $args[] = intval($filter['event_id']);
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT
                    ms.meta_value AS source,
                    COALESCE(mc.meta_value, '') AS campaign,
                    COUNT(DISTINCT p.ID) AS orders,
                    SUM(CAST(mt.meta_value AS DECIMAL(10,2))) AS revenue,
                    GROUP_CONCAT(DISTINCT p.ID) AS order_ids
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_tix_campaign_source'
                LEFT JOIN {$wpdb->postmeta} mc ON mc.post_id = p.ID AND mc.meta_key = '_tix_campaign_name'
                LEFT JOIN {$wpdb->postmeta} mt ON mt.post_id = p.ID AND mt.meta_key = '_order_total'
                $event_join
                WHERE $where_sql
                GROUP BY ms.meta_value, mc.meta_value
                ORDER BY revenue DESC";

        if (!empty($args)) {
            $sql = $wpdb->prepare($sql, ...$args);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        // Tickets über order_items + _qty Meta
        foreach ($rows as &$r) {
            $r['tickets'] = self::count_wc_tickets_for_orders(
                array_filter(array_map('intval', explode(',', $r['order_ids'] ?? ''))),
                intval($filter['event_id'] ?? 0)
            );
            unset($r['order_ids']);
        }
        unset($r);

        return $rows;
    }

    /**
     * Order-Query für native TIX-Orders (wc_order_id = 0) mit campaign data in wp_options.
     */
    private static function query_orders_native($filter) {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'tix_orders';

        // Check if table exists
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$orders_table'");
        if (!$exists) return [];

        $where = ["o.status IN ('completed', 'processing')", "o.wc_order_id = 0"];
        $args  = [];

        if (!empty($filter['date_from'])) {
            $where[] = 'o.date_created >= %s';
            $args[]  = $filter['date_from'] . ' 00:00:00';
        }
        if (!empty($filter['date_to'])) {
            $where[] = 'o.date_created <= %s';
            $args[]  = $filter['date_to'] . ' 23:59:59';
        }

        $event_join = '';
        if (!empty($filter['event_id'])) {
            $items_table = $wpdb->prefix . 'tix_order_items';
            $event_join = "INNER JOIN $items_table oi ON oi.order_id = o.id AND oi.event_id = %d";
            $args[] = intval($filter['event_id']);
        }

        $where_sql = implode(' AND ', $where);

        // Tickets via SUM(quantity) aus tix_order_items, optional Event-gefiltert
        $items_table = $wpdb->prefix . 'tix_order_items';
        $items_have_table = $wpdb->get_var("SHOW TABLES LIKE '$items_table'") === $items_table;
        $event_id_filter = intval($filter['event_id'] ?? 0);

        $tickets_subquery = $items_have_table
            ? "(SELECT COALESCE(SUM(oi2.quantity), 0)
                FROM $items_table oi2
                WHERE oi2.order_id = o.id"
                . ($event_id_filter > 0 ? " AND oi2.event_id = " . $event_id_filter : '')
              . ")"
            : "0";

        $sql = "SELECT o.id, o.total, $tickets_subquery AS ticket_count
                FROM $orders_table o
                $event_join
                WHERE $where_sql
                GROUP BY o.id";

        if (!empty($args)) {
            $sql = $wpdb->prepare($sql, ...$args);
        }

        $orders = $wpdb->get_results($sql, ARRAY_A) ?: [];

        // Group by campaign data stored in wp_options
        $grouped = [];
        foreach ($orders as $row) {
            $campaign_data = get_option('_tix_order_campaign_' . $row['id']);
            if (empty($campaign_data) || empty($campaign_data['source'])) continue;

            $source   = $campaign_data['source'];
            $campaign = $campaign_data['name'] ?? '';
            $key = $source . '::' . $campaign;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'source'   => $source,
                    'campaign' => $campaign,
                    'orders'   => 0,
                    'tickets'  => 0,
                    'revenue'  => 0.0,
                ];
            }
            $grouped[$key]['orders']  += 1;
            $grouped[$key]['tickets'] += intval($row['ticket_count']);
            $grouped[$key]['revenue'] += floatval($row['total']);
        }

        return array_values($grouped);
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    private static function get_channel_label($slug) {
        if (class_exists('TIX_Campaign_Tracking')) {
            return TIX_Campaign_Tracking::get_channel_label($slug);
        }
        $channels = TIX_Campaign_Tracking::CHANNELS ?? [];
        return $channels[$slug] ?? ucfirst($slug);
    }

    private static function get_filter_from_request() {
        return [
            'event_id'  => sanitize_text_field($_REQUEST['event_id'] ?? ''),
            'date_from' => sanitize_text_field($_REQUEST['date_from'] ?? ''),
            'date_to'   => sanitize_text_field($_REQUEST['date_to'] ?? ''),
        ];
    }

    private static function get_events_list() {
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $list = [];
        foreach ($events as $e) {
            $list[$e->ID] = $e->post_title;
        }
        return $list;
    }
}
