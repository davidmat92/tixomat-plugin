<?php
/**
 * TIX Dashboard — Admin-Startseite für Veranstalter & Admins
 *
 * Zeigt KPIs, Umsatz-Chart, nächste Events, letzte Bestellungen,
 * Quick Actions und Alerts. Funktioniert mit WooCommerce UND nativem Checkout.
 */
if (!defined('ABSPATH')) exit;

class TIX_Dashboard {

    public static function init() {
        // AJAX endpoint für Dashboard-Daten
        add_action('wp_ajax_tix_dashboard_data', [__CLASS__, 'ajax_data']);

        // Redirect: alte Dashboard-Slugs → tixomat
        add_action('admin_init', function() {
            $page = $_GET['page'] ?? '';
            if (in_array($page, ['tix-dashboard', 'tix-organizer-dashboard'], true)) {
                wp_redirect(admin_url('admin.php?page=tixomat'));
                exit;
            }
        });
    }

    // ═══════════════════════════════════════════
    // Page Render (HTML Skeleton + AJAX Load)
    // ═══════════════════════════════════════════

    public static function render_page() {
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', [], '4.4.0', true);
        wp_enqueue_script('chartjs-adapter', 'https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3/dist/chartjs-adapter-date-fns.bundle.min.js', ['chartjs'], '3.0.0', true);
        ?>
        <div class="tix-dashboard" id="tix-dashboard">
            <style><?php echo self::get_css(); ?></style>

            <!-- KPI Cards -->
            <div class="tix-db-kpis" id="tix-db-kpis">
                <div class="tix-db-kpi tix-db-skeleton"><div class="tix-db-kpi-val">&nbsp;</div><div class="tix-db-kpi-label">&nbsp;</div></div>
                <div class="tix-db-kpi tix-db-skeleton"><div class="tix-db-kpi-val">&nbsp;</div><div class="tix-db-kpi-label">&nbsp;</div></div>
                <div class="tix-db-kpi tix-db-skeleton"><div class="tix-db-kpi-val">&nbsp;</div><div class="tix-db-kpi-label">&nbsp;</div></div>
                <div class="tix-db-kpi tix-db-skeleton"><div class="tix-db-kpi-val">&nbsp;</div><div class="tix-db-kpi-label">&nbsp;</div></div>
            </div>

            <!-- Middle Row -->
            <div class="tix-db-mid">
                <div class="tix-db-chart-wrap tix-db-skeleton" id="tix-db-chart-wrap">
                    <div class="tix-db-card-header">
                        <h3>Umsatz & Tickets</h3>
                        <span class="tix-db-period">Letzte 30 Tage</span>
                    </div>
                    <div class="tix-db-chart-container">
                        <canvas id="tix-db-chart" height="260"></canvas>
                    </div>
                </div>
                <div class="tix-db-upcoming tix-db-skeleton" id="tix-db-upcoming">
                    <div class="tix-db-card-header">
                        <h3>N&auml;chste Events</h3>
                    </div>
                    <div class="tix-db-upcoming-list" id="tix-db-upcoming-list"></div>
                </div>
            </div>

            <!-- Bottom Row -->
            <div class="tix-db-bottom">
                <div class="tix-db-orders tix-db-skeleton" id="tix-db-orders">
                    <div class="tix-db-card-header">
                        <h3>Letzte Bestellungen</h3>
                        <a href="<?php echo admin_url('admin.php?page=tix-orders'); ?>" class="tix-db-link">Alle anzeigen &rarr;</a>
                    </div>
                    <div id="tix-db-orders-table"></div>
                </div>
                <div class="tix-db-sidebar" id="tix-db-sidebar">
                    <!-- Quick Actions -->
                    <div class="tix-db-actions">
                        <div class="tix-db-card-header"><h3>Schnellzugriff</h3></div>
                        <div class="tix-db-action-grid">
                            <a href="<?php echo admin_url('post-new.php?post_type=event'); ?>" class="tix-db-action">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                <span>Neues Event</span>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=tix-statistics'); ?>" class="tix-db-action">
                                <span class="dashicons dashicons-chart-area"></span>
                                <span>Statistiken</span>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=tix-bulk-editor'); ?>" class="tix-db-action">
                                <span class="dashicons dashicons-cloud-upload"></span>
                                <span>KI-Import</span>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=tix-settings'); ?>" class="tix-db-action">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <span>Einstellungen</span>
                            </a>
                        </div>
                    </div>
                    <!-- Alerts -->
                    <div class="tix-db-alerts" id="tix-db-alerts">
                        <div class="tix-db-card-header"><h3>Hinweise</h3></div>
                        <div id="tix-db-alerts-list"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var nonce = '<?php echo wp_create_nonce('tix_dashboard'); ?>';

            fetch(ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=tix_dashboard_data&_wpnonce=' + nonce
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (!resp.success) return;
                var d = resp.data;
                renderKPIs(d.kpis);
                renderChart(d.chart);
                renderUpcoming(d.upcoming);
                renderOrders(d.orders);
                renderAlerts(d.alerts);
            });

            function renderKPIs(kpis) {
                var wrap = document.getElementById('tix-db-kpis');
                var html = '';
                kpis.forEach(function(k) {
                    var trendCls = k.trend > 0 ? 'up' : (k.trend < 0 ? 'down' : 'neutral');
                    var trendIcon = k.trend > 0 ? '&#9650;' : (k.trend < 0 ? '&#9660;' : '&#8212;');
                    var trendVal = k.trend !== null ? Math.abs(k.trend) + '%' : '';
                    html += '<div class="tix-db-kpi">' +
                        '<div class="tix-db-kpi-icon"><span class="dashicons dashicons-' + k.icon + '"></span></div>' +
                        '<div class="tix-db-kpi-body">' +
                            '<div class="tix-db-kpi-val">' + k.value + '</div>' +
                            '<div class="tix-db-kpi-label">' + k.label + '</div>' +
                        '</div>' +
                        (k.trend !== null ? '<div class="tix-db-kpi-trend tix-db-trend-' + trendCls + '">' + trendIcon + ' ' + trendVal + '</div>' : '') +
                    '</div>';
                });
                wrap.innerHTML = html;
            }

            function renderChart(chartData) {
                var wrap = document.getElementById('tix-db-chart-wrap');
                wrap.classList.remove('tix-db-skeleton');
                if (!chartData || !chartData.labels || chartData.labels.length === 0) {
                    wrap.querySelector('.tix-db-chart-container').innerHTML = '<p class="tix-db-empty">Noch keine Daten vorhanden.</p>';
                    return;
                }
                var ctx = document.getElementById('tix-db-chart').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: [
                            {
                                label: 'Umsatz (€)',
                                data: chartData.revenue,
                                borderColor: '#E8445A',
                                backgroundColor: 'rgba(232,68,90,0.08)',
                                fill: true,
                                tension: 0.3,
                                yAxisID: 'y',
                                borderWidth: 2,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                            },
                            {
                                label: 'Tickets',
                                data: chartData.tickets,
                                borderColor: '#3b82f6',
                                backgroundColor: 'transparent',
                                borderDash: [5, 3],
                                tension: 0.3,
                                yAxisID: 'y1',
                                borderWidth: 2,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: true, position: 'top', labels: { usePointStyle: true, boxWidth: 8, font: { size: 12, family: "'Sora', sans-serif" } } },
                            tooltip: { bodyFont: { family: "'DM Sans', sans-serif" }, titleFont: { family: "'Sora', sans-serif" } }
                        },
                        scales: {
                            x: { grid: { display: false }, ticks: { font: { size: 11 }, maxTicksLimit: 10 } },
                            y: { position: 'left', grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 11 }, callback: function(v) { return v + ' €'; } } },
                            y1: { position: 'right', grid: { display: false }, ticks: { font: { size: 11 }, precision: 0 } }
                        }
                    }
                });
            }

            function renderUpcoming(events) {
                var wrap = document.getElementById('tix-db-upcoming');
                wrap.classList.remove('tix-db-skeleton');
                var list = document.getElementById('tix-db-upcoming-list');
                if (!events || events.length === 0) {
                    list.innerHTML = '<p class="tix-db-empty">Keine kommenden Events.</p>';
                    return;
                }
                var html = '';
                events.forEach(function(ev) {
                    var pct = ev.capacity > 0 ? Math.min(100, Math.round(ev.sold / ev.capacity * 100)) : 0;
                    var statusCls = ev.status === 'sold_out' ? 'sold-out' : (ev.status === 'few_tickets' ? 'few' : 'available');
                    html += '<a href="' + ev.edit_url + '" class="tix-db-event">' +
                        (ev.thumb ? '<img src="' + ev.thumb + '" class="tix-db-event-img" alt="">' : '<div class="tix-db-event-img tix-db-event-img-placeholder"><span class="dashicons dashicons-calendar-alt"></span></div>') +
                        '<div class="tix-db-event-info">' +
                            '<div class="tix-db-event-title">' + ev.title + '</div>' +
                            '<div class="tix-db-event-date">' + ev.date + '</div>' +
                            '<div class="tix-db-event-bar-wrap">' +
                                '<div class="tix-db-event-bar"><div class="tix-db-event-bar-fill tix-db-bar-' + statusCls + '" style="width:' + pct + '%"></div></div>' +
                                '<span class="tix-db-event-sold">' + ev.sold + (ev.capacity > 0 ? '/' + ev.capacity : '') + '</span>' +
                            '</div>' +
                        '</div>' +
                    '</a>';
                });
                list.innerHTML = html;
            }

            function renderOrders(orders) {
                var wrap = document.getElementById('tix-db-orders');
                wrap.classList.remove('tix-db-skeleton');
                var tbl = document.getElementById('tix-db-orders-table');
                if (!orders || orders.length === 0) {
                    tbl.innerHTML = '<p class="tix-db-empty">Noch keine Bestellungen.</p>';
                    return;
                }
                var html = '<table class="tix-db-table"><thead><tr>' +
                    '<th>Nr.</th><th>Kunde</th><th>Event</th><th>Tickets</th><th>Betrag</th><th>Status</th><th>Datum</th>' +
                    '</tr></thead><tbody>';
                orders.forEach(function(o) {
                    var statusCls = o.status === 'completed' ? 'complete' : (o.status === 'processing' ? 'processing' : (o.status === 'cancelled' ? 'cancelled' : 'pending'));
                    html += '<tr>' +
                        '<td><a href="' + o.url + '">' + o.number + '</a></td>' +
                        '<td>' + o.customer + '</td>' +
                        '<td class="tix-db-td-event">' + o.event + '</td>' +
                        '<td>' + o.tickets + '</td>' +
                        '<td>' + o.total + '</td>' +
                        '<td><span class="tix-db-status tix-db-status-' + statusCls + '">' + o.status_label + '</span></td>' +
                        '<td>' + o.date + '</td>' +
                    '</tr>';
                });
                html += '</tbody></table>';
                tbl.innerHTML = html;
            }

            function renderAlerts(alerts) {
                var wrap = document.getElementById('tix-db-alerts');
                var list = document.getElementById('tix-db-alerts-list');
                if (!alerts || alerts.length === 0) {
                    wrap.style.display = 'none';
                    return;
                }
                var html = '';
                alerts.forEach(function(a) {
                    html += '<div class="tix-db-alert tix-db-alert-' + a.type + '">' +
                        '<span class="dashicons dashicons-' + a.icon + '"></span>' +
                        '<div class="tix-db-alert-body">' +
                            '<div class="tix-db-alert-text">' + a.text + '</div>' +
                            (a.url ? '<a href="' + a.url + '" class="tix-db-alert-link">' + a.link_text + '</a>' : '') +
                        '</div>' +
                    '</div>';
                });
                list.innerHTML = html;
            }
        })();
        </script>
        <?php
    }

    // ═══════════════════════════════════════════
    // AJAX: Dashboard Data
    // ═══════════════════════════════════════════

    public static function ajax_data() {
        check_ajax_referer('tix_dashboard');

        // Organizer-Kontext: nur eigene Events
        $event_ids = self::get_context_event_ids();

        // Zeitraum: aktueller Monat + Vormonat für Trend
        $now        = current_time('Y-m-d');
        $month_from = current_time('Y-m-01');
        $prev_from  = date('Y-m-01', strtotime('-1 month', strtotime($month_from)));
        $prev_to    = date('Y-m-t', strtotime($prev_from));

        wp_send_json_success([
            'kpis'     => self::get_kpis($event_ids, $month_from, $now, $prev_from, $prev_to),
            'chart'    => self::get_chart_data($event_ids, 30),
            'upcoming' => self::get_upcoming_events($event_ids, 5),
            'orders'   => self::get_recent_orders($event_ids, 8),
            'alerts'   => self::get_alerts($event_ids),
        ]);
    }

    // ═══════════════════════════════════════════
    // Context: Event-IDs für aktuellen User
    // ═══════════════════════════════════════════

    private static function get_context_event_ids() {
        // Admins sehen alles
        if (current_user_can('manage_options')) return [];

        // Organizer: nur eigene Events
        if (class_exists('TIX_Organizer_Admin') && method_exists('TIX_Organizer_Admin', 'is_organizer')) {
            if (TIX_Organizer_Admin::is_organizer()) {
                $org_id = 0;
                if (class_exists('TIX_Organizer_Dashboard')) {
                    $org = TIX_Organizer_Dashboard::get_organizer_by_user(get_current_user_id());
                    $org_id = $org ? $org->ID : 0;
                }
                if ($org_id > 0) {
                    $ids = get_posts([
                        'post_type'      => 'event',
                        'posts_per_page' => -1,
                        'post_status'    => 'any',
                        'fields'         => 'ids',
                        'meta_key'       => '_tix_organizer_id',
                        'meta_value'     => $org_id,
                        'meta_type'      => 'NUMERIC',
                    ]);
                    return $ids ?: [0]; // [0] = kein Match
                }
                return [0];
            }
        }

        return []; // Alles sehen
    }

    // ═══════════════════════════════════════════
    // KPIs
    // ═══════════════════════════════════════════

    private static function get_kpis($event_ids, $from, $to, $prev_from, $prev_to) {
        global $wpdb;
        $t  = TIX_Order::table_name();
        $ti = TIX_Order::items_table_name();

        // Revenue aktueller Monat
        $rev = self::query_revenue_kpi($t, $ti, $event_ids, $from, $to);
        $prev_rev = self::query_revenue_kpi($t, $ti, $event_ids, $prev_from, $prev_to);

        // Tickets aktueller Monat
        $tix = self::query_tickets_kpi($t, $ti, $event_ids, $from, $to);
        $prev_tix = self::query_tickets_kpi($t, $ti, $event_ids, $prev_from, $prev_to);

        // Aktive Events
        $today = current_time('Y-m-d');
        $active_args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
            ],
        ];
        if (!empty($event_ids)) {
            $active_args['post__in'] = $event_ids;
        }
        $active = count(get_posts($active_args));

        // Check-in Rate
        $checkin_rate = self::query_checkin_rate($t, $ti, $event_ids);

        return [
            [
                'value' => number_format($rev, 2, ',', '.') . ' €',
                'label' => 'Umsatz (Monat)',
                'icon'  => 'money-alt',
                'trend' => $prev_rev > 0 ? round(($rev - $prev_rev) / $prev_rev * 100, 1) : null,
            ],
            [
                'value' => number_format($tix, 0, ',', '.'),
                'label' => 'Tickets verkauft',
                'icon'  => 'tickets-alt',
                'trend' => $prev_tix > 0 ? round(($tix - $prev_tix) / $prev_tix * 100, 1) : null,
            ],
            [
                'value' => $active,
                'label' => 'Aktive Events',
                'icon'  => 'calendar-alt',
                'trend' => null,
            ],
            [
                'value' => $checkin_rate . '%',
                'label' => 'Check-in Rate',
                'icon'  => 'groups',
                'trend' => null,
            ],
        ];
    }

    private static function query_revenue_kpi($t, $ti, $event_ids, $from, $to) {
        global $wpdb;

        $event_filter = '';
        if (!empty($event_ids)) {
            $eids = implode(',', array_map('intval', $event_ids));
            $event_filter = "AND o.id IN (SELECT order_id FROM $ti WHERE event_id IN ($eids))";
        }

        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(o.total), 0) as revenue
             FROM $t o
             WHERE o.status IN ('completed','processing')
               AND o.date_created >= %s
               AND o.date_created <= %s $event_filter",
            $from, $to . ' 23:59:59'
        );

        return floatval($wpdb->get_var($sql));
    }

    private static function query_tickets_kpi($t, $ti, $event_ids, $from, $to) {
        global $wpdb;

        $event_filter = '';
        if (!empty($event_ids)) {
            $eids = implode(',', array_map('intval', $event_ids));
            $event_filter = "AND i.event_id IN ($eids)";
        }

        $sql = $wpdb->prepare(
            "SELECT COALESCE(SUM(i.quantity), 0)
             FROM $ti i
             INNER JOIN $t o ON i.order_id = o.id
             WHERE o.status IN ('completed','processing')
               AND o.date_created >= %s
               AND o.date_created <= %s $event_filter",
            $from, $to . ' 23:59:59'
        );

        return intval($wpdb->get_var($sql));
    }

    private static function query_checkin_rate($t, $ti, $event_ids) {
        global $wpdb;

        $event_filter = '';
        if (!empty($event_ids)) {
            $eids = implode(',', array_map('intval', $event_ids));
            $event_filter = "AND i.event_id IN ($eids)";
        }

        $sql = "SELECT
                    COALESCE(SUM(i.quantity), 0) as total,
                    COALESCE(SUM(CASE WHEN i.checked_in = 1 THEN i.quantity ELSE 0 END), 0) as checked
                FROM $ti i
                INNER JOIN $t o ON i.order_id = o.id
                WHERE o.status IN ('completed','processing') $event_filter";

        $r = $wpdb->get_row($sql);
        $total = intval($r->total ?? 0);
        $checked = intval($r->checked ?? 0);
        return $total > 0 ? round($checked / $total * 100, 1) : 0;
    }

    // ═══════════════════════════════════════════
    // Chart Data
    // ═══════════════════════════════════════════

    private static function get_chart_data($event_ids, $days = 30) {
        global $wpdb;
        $t  = TIX_Order::table_name();
        $ti = TIX_Order::items_table_name();

        $from = date('Y-m-d', strtotime("-{$days} days"));

        $event_filter = '';
        if (!empty($event_ids)) {
            $eids = implode(',', array_map('intval', $event_ids));
            $event_filter = "AND i.event_id IN ($eids)";
        }

        $sql = $wpdb->prepare(
            "SELECT DATE(o.date_created) as day,
                    COALESCE(SUM(i.total), 0) as revenue,
                    COALESCE(SUM(i.quantity), 0) as tickets
             FROM $t o
             INNER JOIN $ti i ON o.id = i.order_id
             WHERE o.status IN ('completed','processing')
               AND o.date_created >= %s $event_filter
             GROUP BY DATE(o.date_created)
             ORDER BY day ASC",
            $from
        );

        $rows = $wpdb->get_results($sql);

        // Alle Tage ausfüllen (auch 0-Tage)
        $labels = [];
        $revenue = [];
        $tickets = [];
        $by_day = [];
        foreach ($rows as $r) {
            $by_day[$r->day] = $r;
        }

        for ($i = $days; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('d.m.', strtotime($day));
            $row = $by_day[$day] ?? null;
            $revenue[] = $row ? round(floatval($row->revenue), 2) : 0;
            $tickets[] = $row ? intval($row->tickets) : 0;
        }

        return [
            'labels'  => $labels,
            'revenue' => $revenue,
            'tickets' => $tickets,
        ];
    }

    // ═══════════════════════════════════════════
    // Upcoming Events
    // ═══════════════════════════════════════════

    private static function get_upcoming_events($event_ids, $limit = 5) {
        $today = current_time('Y-m-d');
        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
            ],
        ];
        if (!empty($event_ids)) {
            $args['post__in'] = $event_ids;
        }
        $events = get_posts($args);

        $result = [];
        foreach ($events as $e) {
            $sold     = intval(get_post_meta($e->ID, '_tix_sold_total', true));
            $capacity = intval(get_post_meta($e->ID, '_tix_capacity_total', true));
            $status   = get_post_meta($e->ID, '_tix_event_status', true) ?: 'available';
            $date     = get_post_meta($e->ID, '_tix_date_card', true) ?: get_post_meta($e->ID, '_tix_date_display', true);
            $thumb    = get_the_post_thumbnail_url($e->ID, 'thumbnail');

            $result[] = [
                'id'       => $e->ID,
                'title'    => get_the_title($e->ID),
                'date'     => $date ?: '',
                'sold'     => $sold,
                'capacity' => $capacity,
                'status'   => $status,
                'thumb'    => $thumb ?: '',
                'edit_url' => get_edit_post_link($e->ID, 'raw'),
            ];
        }
        return $result;
    }

    // ═══════════════════════════════════════════
    // Recent Orders
    // ═══════════════════════════════════════════

    private static function get_recent_orders($event_ids, $limit = 8) {
        global $wpdb;
        $t  = TIX_Order::table_name();
        $ti = TIX_Order::items_table_name();

        // Event-Filter
        $event_filter = '';
        if (!empty($event_ids)) {
            $eids = implode(',', array_map('intval', $event_ids));
            $event_filter = "AND o.id IN (SELECT order_id FROM $ti WHERE event_id IN ($eids))";
        }

        $sql = $wpdb->prepare(
            "SELECT o.*, GROUP_CONCAT(DISTINCT i.name SEPARATOR ', ') as event_names,
                    COALESCE(SUM(i.quantity), 0) as ticket_count
             FROM $t o
             LEFT JOIN $ti i ON o.id = i.order_id
             WHERE 1=1 $event_filter
             GROUP BY o.id
             ORDER BY o.date_created DESC
             LIMIT %d",
            $limit
        );

        $rows = $wpdb->get_results($sql);

        $status_labels = [
            'completed'  => 'Abgeschlossen',
            'processing' => 'In Bearbeitung',
            'pending'    => 'Ausstehend',
            'cancelled'  => 'Storniert',
            'failed'     => 'Fehlgeschlagen',
        ];

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'id'           => $r->id,
                'number'       => $r->order_number ?: '#' . $r->id,
                'customer'     => trim(($r->billing_first_name ?? '') . ' ' . ($r->billing_last_name ?? '')) ?: ($r->billing_email ?? ''),
                'event'        => mb_strimwidth($r->event_names ?? '', 0, 40, '...'),
                'tickets'      => intval($r->ticket_count),
                'total'        => number_format(floatval($r->total), 2, ',', '.') . ' €',
                'status'       => $r->status,
                'status_label' => $status_labels[$r->status] ?? ucfirst($r->status),
                'date'         => $r->date_created ? date_i18n('d.m.Y H:i', strtotime($r->date_created)) : '',
                'url'          => admin_url('admin.php?page=tix-orders&order_id=' . $r->id),
            ];
        }
        return $result;
    }

    // ═══════════════════════════════════════════
    // Alerts
    // ═══════════════════════════════════════════

    private static function get_alerts($event_ids) {
        $today = current_time('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day', strtotime($today)));
        $alerts = [];

        // Aktive Events holen
        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
            ],
        ];
        if (!empty($event_ids)) $args['post__in'] = $event_ids;
        $events = get_posts($args);

        foreach ($events as $e) {
            $id = $e->ID;
            $title = get_the_title($id);
            $short = mb_strimwidth($title, 0, 35, '...');
            $date_start = get_post_meta($id, '_tix_date_start', true);
            $edit_url = get_edit_post_link($id, 'raw');

            // Event ohne Bild
            if (!has_post_thumbnail($id)) {
                $alerts[] = [
                    'type' => 'warning',
                    'icon' => 'format-image',
                    'text' => '<strong>' . esc_html($short) . '</strong> hat kein Beitragsbild.',
                    'url'  => $edit_url,
                    'link_text' => 'Bearbeiten',
                ];
            }

            // Event in den nächsten 24h
            if ($date_start && $date_start >= $today && $date_start <= $tomorrow) {
                $alerts[] = [
                    'type' => 'info',
                    'icon' => 'clock',
                    'text' => '<strong>' . esc_html($short) . '</strong> startet heute/morgen!',
                    'url'  => $edit_url,
                    'link_text' => 'Ansehen',
                ];
            }

            // Wenig Restkapazität (< 10%)
            $sold = intval(get_post_meta($id, '_tix_sold_total', true));
            $cap  = intval(get_post_meta($id, '_tix_capacity_total', true));
            if ($cap > 0 && $sold > 0) {
                $remaining_pct = round(($cap - $sold) / $cap * 100, 1);
                if ($remaining_pct <= 10 && $remaining_pct > 0) {
                    $alerts[] = [
                        'type' => 'urgent',
                        'icon' => 'warning',
                        'text' => '<strong>' . esc_html($short) . '</strong> fast ausverkauft (' . ($cap - $sold) . ' Tickets übrig).',
                        'url'  => $edit_url,
                        'link_text' => 'Details',
                    ];
                }
            }

            // Presale inaktiv aber Tickets aktiviert
            $tickets_enabled = get_post_meta($id, '_tix_tickets_enabled', true);
            $presale_active  = get_post_meta($id, '_tix_presale_active', true);
            if ($tickets_enabled === '1' && $presale_active !== '1') {
                $alerts[] = [
                    'type' => 'warning',
                    'icon' => 'dismiss',
                    'text' => '<strong>' . esc_html($short) . '</strong> — Vorverkauf ist deaktiviert.',
                    'url'  => $edit_url,
                    'link_text' => 'Aktivieren',
                ];
            }
        }

        // Maximal 8 Alerts zeigen
        return array_slice($alerts, 0, 8);
    }

    // ═══════════════════════════════════════════
    // CSS
    // ═══════════════════════════════════════════

    private static function get_css() {
        return '
.tix-dashboard { max-width:1200px; margin:0 auto; padding:24px; font-family:"DM Sans",sans-serif; color:#1f2937; }

/* ── Skeleton ── */
.tix-db-skeleton { position:relative; overflow:hidden; }
.tix-db-skeleton::after { content:""; position:absolute; inset:0; background:linear-gradient(90deg,transparent,rgba(0,0,0,0.03),transparent); animation:tix-db-shimmer 1.5s infinite; }
@keyframes tix-db-shimmer { 0%{transform:translateX(-100%)} 100%{transform:translateX(100%)} }

/* ── KPI Cards ── */
.tix-db-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
.tix-db-kpi { display:flex; align-items:center; gap:14px; background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; transition:box-shadow .2s; }
.tix-db-kpi:hover { box-shadow:0 4px 16px rgba(0,0,0,0.06); }
.tix-db-kpi-icon { width:44px; height:44px; border-radius:12px; background:rgba(232,68,90,0.08); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.tix-db-kpi-icon .dashicons { font-size:22px; width:22px; height:22px; color:#E8445A; }
.tix-db-kpi-body { flex:1; min-width:0; }
.tix-db-kpi-val { font-family:"Sora",sans-serif; font-size:1.4rem; font-weight:800; line-height:1.2; color:#131020; }
.tix-db-kpi-label { font-size:0.8rem; font-weight:500; color:#6b7280; margin-top:2px; }
.tix-db-kpi-trend { font-size:0.75rem; font-weight:700; padding:3px 8px; border-radius:8px; white-space:nowrap; }
.tix-db-trend-up { color:#059669; background:rgba(5,150,105,0.08); }
.tix-db-trend-down { color:#dc2626; background:rgba(220,38,38,0.08); }
.tix-db-trend-neutral { color:#9ca3af; background:rgba(156,163,175,0.08); }

/* ── Middle Row ── */
.tix-db-mid { display:grid; grid-template-columns:3fr 2fr; gap:16px; margin-bottom:24px; }
.tix-db-chart-wrap, .tix-db-upcoming { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; }
.tix-db-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
.tix-db-card-header h3 { font-family:"Sora",sans-serif; font-size:0.95rem; font-weight:700; margin:0; color:#131020; }
.tix-db-period { font-size:0.75rem; font-weight:500; color:#9ca3af; background:#f9fafb; padding:4px 10px; border-radius:8px; }
.tix-db-link { font-size:0.8rem; font-weight:600; color:#E8445A; text-decoration:none; }
.tix-db-link:hover { text-decoration:underline; }
.tix-db-chart-container { position:relative; height:260px; }

/* ── Upcoming Events ── */
.tix-db-event { display:flex; align-items:center; gap:12px; padding:10px; border-radius:12px; text-decoration:none; color:#1f2937; transition:background .15s; }
.tix-db-event:hover { background:#f9fafb; }
.tix-db-event-img { width:44px; height:44px; border-radius:10px; object-fit:cover; flex-shrink:0; }
.tix-db-event-img-placeholder { background:#f3f4f6; display:flex; align-items:center; justify-content:center; }
.tix-db-event-img-placeholder .dashicons { color:#d1d5db; }
.tix-db-event-info { flex:1; min-width:0; }
.tix-db-event-title { font-family:"Sora",sans-serif; font-size:0.85rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tix-db-event-date { font-size:0.72rem; color:#6b7280; margin:2px 0 4px; }
.tix-db-event-bar-wrap { display:flex; align-items:center; gap:8px; }
.tix-db-event-bar { flex:1; height:6px; background:#f3f4f6; border-radius:3px; overflow:hidden; }
.tix-db-event-bar-fill { height:100%; border-radius:3px; transition:width .3s; }
.tix-db-bar-available { background:#10b981; }
.tix-db-bar-few { background:#f59e0b; }
.tix-db-bar-sold-out { background:#ef4444; }
.tix-db-event-sold { font-size:0.7rem; font-weight:600; color:#6b7280; white-space:nowrap; }

/* ── Bottom Row ── */
.tix-db-bottom { display:grid; grid-template-columns:3fr 2fr; gap:16px; }
.tix-db-orders { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; }
.tix-db-sidebar { display:flex; flex-direction:column; gap:16px; }

/* ── Table ── */
.tix-db-table { width:100%; border-collapse:collapse; font-size:0.8rem; }
.tix-db-table th { text-align:left; font-family:"Sora",sans-serif; font-size:0.72rem; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:0.03em; padding:8px 10px; border-bottom:1px solid #e5e7eb; }
.tix-db-table td { padding:10px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.tix-db-table a { color:#E8445A; text-decoration:none; font-weight:600; }
.tix-db-table a:hover { text-decoration:underline; }
.tix-db-td-event { max-width:140px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tix-db-status { display:inline-block; font-size:0.7rem; font-weight:600; padding:2px 8px; border-radius:6px; }
.tix-db-status-complete { background:#d1fae5; color:#059669; }
.tix-db-status-processing { background:#dbeafe; color:#2563eb; }
.tix-db-status-pending { background:#fef3c7; color:#d97706; }
.tix-db-status-cancelled { background:#fee2e2; color:#dc2626; }

/* ── Quick Actions ── */
.tix-db-actions { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; }
.tix-db-action-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.tix-db-action { display:flex; align-items:center; gap:8px; padding:12px; border-radius:12px; background:#f9fafb; text-decoration:none; color:#374151; font-size:0.82rem; font-weight:600; transition:all .15s; }
.tix-db-action:hover { background:#E8445A; color:#fff; }
.tix-db-action:hover .dashicons { color:#fff; }
.tix-db-action .dashicons { font-size:18px; width:18px; height:18px; color:#E8445A; transition:color .15s; }

/* ── Alerts ── */
.tix-db-alerts { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; }
.tix-db-alert { display:flex; gap:10px; padding:10px; border-radius:10px; margin-bottom:6px; }
.tix-db-alert:last-child { margin-bottom:0; }
.tix-db-alert .dashicons { flex-shrink:0; font-size:18px; width:18px; height:18px; margin-top:1px; }
.tix-db-alert-body { flex:1; min-width:0; }
.tix-db-alert-text { font-size:0.8rem; line-height:1.4; }
.tix-db-alert-link { font-size:0.75rem; font-weight:600; color:#E8445A; text-decoration:none; }
.tix-db-alert-link:hover { text-decoration:underline; }
.tix-db-alert-warning { background:#fffbeb; }
.tix-db-alert-warning .dashicons { color:#f59e0b; }
.tix-db-alert-info { background:#eff6ff; }
.tix-db-alert-info .dashicons { color:#3b82f6; }
.tix-db-alert-urgent { background:#fef2f2; }
.tix-db-alert-urgent .dashicons { color:#ef4444; }

/* ── Empty ── */
.tix-db-empty { text-align:center; color:#9ca3af; font-size:0.85rem; padding:24px 0; }

/* ── Responsive ── */
@media (max-width:1024px) {
    .tix-db-kpis { grid-template-columns:repeat(2,1fr); }
    .tix-db-mid, .tix-db-bottom { grid-template-columns:1fr; }
}
@media (max-width:600px) {
    .tix-db-kpis { grid-template-columns:1fr; }
    .tix-dashboard { padding:12px; }
    .tix-db-action-grid { grid-template-columns:1fr; }
}
';
    }
}
