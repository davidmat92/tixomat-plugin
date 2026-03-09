<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat - Veranstalter Dashboard (Frontend)
 *
 * Shortcode: [tix_organizer_dashboard]
 * Zeigt dem eingeloggten Veranstalter sein persoenliches Dashboard
 * mit Event-Verwaltung, Bestellungen, Gaesteliste, Statistiken.
 *
 * @since 1.29.0
 */
class TIX_Organizer_Dashboard {

    /* ══════════════════════════════════════════
     * Bootstrap
     * ══════════════════════════════════════════ */

    public static function init() {
        add_shortcode('tix_organizer_dashboard', [__CLASS__, 'render']);

        // Rolle registrieren
        add_action('init', [__CLASS__, 'register_role'], 5);

        // AJAX endpoints
        $actions = [
            'tix_od_overview',
            'tix_od_events',
            'tix_od_event_detail',
            'tix_od_save_event',
            'tix_od_delete_event',
            'tix_od_duplicate_event',
            'tix_od_orders',
            'tix_od_order_detail',
            'tix_od_guestlist',
            'tix_od_guestlist_save',
            'tix_od_checkin',
            'tix_od_stats',
            'tix_od_upload_media',
            'tix_od_save_discount',
            'tix_od_raffle_draw',
            'tix_od_profile',
        ];
        foreach ($actions as $action) {
            $method = 'ajax_' . str_replace('tix_od_', '', $action);
            add_action('wp_ajax_' . $action, [__CLASS__, $method]);
            add_action('wp_ajax_nopriv_' . $action, [__CLASS__, $method]);
        }
    }

    /* ══════════════════════════════════════════
     * Custom Role
     * ══════════════════════════════════════════ */

    public static function register_role() {
        if (!get_role('tix_organizer')) {
            add_role('tix_organizer', 'Veranstalter', [
                'read'           => true,
                'upload_files'   => true,
            ]);
        }
    }

    /* ══════════════════════════════════════════
     * Assets (nur wenn Shortcode genutzt wird)
     * ══════════════════════════════════════════ */

    private static function enqueue() {
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'tix-organizer-dashboard',
            TIXOMAT_URL . 'assets/css/organizer-dashboard.css',
            [],
            TIXOMAT_VERSION
        );

        // Event-Editor Styles
        wp_enqueue_style(
            'tix-organizer-event-editor',
            TIXOMAT_URL . 'assets/css/organizer-event-editor.css',
            ['tix-organizer-dashboard'],
            TIXOMAT_VERSION
        );

        // Chart.js fuer Statistiken
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'tix-organizer-dashboard',
            TIXOMAT_URL . 'assets/js/organizer-dashboard.js',
            ['jquery', 'chartjs'],
            TIXOMAT_VERSION,
            true
        );

        // Event-Editor JS
        wp_enqueue_script(
            'tix-organizer-event-editor',
            TIXOMAT_URL . 'assets/js/organizer-event-editor.js',
            ['jquery', 'tix-organizer-dashboard'],
            TIXOMAT_VERSION,
            true
        );

        wp_localize_script('tix-organizer-dashboard', 'tixOD', [
            'ajax'     => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tix_organizer_dashboard'),
            'site_url' => home_url('/'),
        ]);
    }

    /* ══════════════════════════════════════════
     * Organizer-Lookup
     * ══════════════════════════════════════════ */

    /**
     * Findet den tix_organizer CPT-Eintrag fuer einen WP User.
     */
    public static function get_organizer_by_user($user_id) {
        $orgs = get_posts([
            'post_type'      => 'tix_organizer',
            'meta_key'       => '_tix_org_user_id',
            'meta_value'     => intval($user_id),
            'posts_per_page' => 1,
            'post_status'    => 'publish',
        ]);
        return $orgs ? $orgs[0] : null;
    }

    /**
     * Prueft ob ein User ein bestimmtes Event besitzt.
     */
    public static function user_owns_event($user_id, $event_id) {
        $org = self::get_organizer_by_user($user_id);
        if (!$org) return false;
        return intval(get_post_meta($event_id, '_tix_organizer_id', true)) === $org->ID;
    }

    /**
     * Alle Event-IDs eines Organizers.
     */
    private static function get_organizer_event_ids($org_id) {
        return get_posts([
            'post_type'      => 'event',
            'meta_key'       => '_tix_organizer_id',
            'meta_value'     => strval(intval($org_id)),
            'post_status'    => ['publish', 'draft', 'pending'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
    }

    /* ══════════════════════════════════════════
     * Shortcode rendern
     * ══════════════════════════════════════════ */

    public static function render($atts = []) {
        // Dashboard deaktiviert?
        if (!tix_get_settings('organizer_dashboard_enabled')) {
            return '';
        }

        // Nicht eingeloggt: Login-Formular
        if (!is_user_logged_in()) {
            return self::render_login();
        }

        // Kein Veranstalter: Hinweis
        $org = self::get_organizer_by_user(get_current_user_id());
        if (!$org) {
            return self::render_no_access();
        }

        self::enqueue();

        ob_start();
        ?>
        <div class="tix-od" id="tix-organizer-dashboard" data-org-id="<?php echo esc_attr($org->ID); ?>">

            <!-- Header -->
            <div class="tix-od-header">
                <h2 class="tix-od-title">Veranstalter Dashboard</h2>
                <span class="tix-od-welcome">Hallo, <?php echo esc_html(wp_get_current_user()->display_name); ?>!</span>
            </div>

            <!-- Layout: Sidebar + Content -->
            <div class="tix-od-layout">

                <!-- Sidebar Navigation -->
                <aside class="tix-od-sidebar" role="tablist">
                    <button class="tix-od-tab active" data-tab="overview" role="tab" aria-selected="true"
                            aria-controls="tix-od-panel-overview">
                        <span class="tix-od-tab-icon dashicons dashicons-dashboard"></span>
                        <span class="tix-od-tab-label">&#220;bersicht</span>
                    </button>
                    <button class="tix-od-tab" data-tab="events" role="tab" aria-selected="false"
                            aria-controls="tix-od-panel-events">
                        <span class="tix-od-tab-icon dashicons dashicons-calendar-alt"></span>
                        <span class="tix-od-tab-label">Meine Events</span>
                    </button>
                    <button class="tix-od-tab" data-tab="orders" role="tab" aria-selected="false"
                            aria-controls="tix-od-panel-orders">
                        <span class="tix-od-tab-icon dashicons dashicons-cart"></span>
                        <span class="tix-od-tab-label">Bestellungen</span>
                    </button>
                    <button class="tix-od-tab" data-tab="guestlist" role="tab" aria-selected="false"
                            aria-controls="tix-od-panel-guestlist">
                        <span class="tix-od-tab-icon dashicons dashicons-groups"></span>
                        <span class="tix-od-tab-label">G&#228;steliste</span>
                    </button>
                    <button class="tix-od-tab" data-tab="stats" role="tab" aria-selected="false"
                            aria-controls="tix-od-panel-stats">
                        <span class="tix-od-tab-icon dashicons dashicons-chart-area"></span>
                        <span class="tix-od-tab-label">Statistiken</span>
                    </button>
                    <button class="tix-od-tab" data-tab="settings" role="tab" aria-selected="false"
                            aria-controls="tix-od-panel-settings">
                        <span class="tix-od-tab-icon dashicons dashicons-admin-generic"></span>
                        <span class="tix-od-tab-label">Einstellungen</span>
                    </button>
                </aside>

                <!-- Content Area -->
                <div class="tix-od-content">

                <!-- Uebersicht -->
                <div class="tix-od-panel active" id="tix-od-panel-overview" role="tabpanel" data-tab="overview">
                    <div class="tix-od-kpis" id="tix-od-kpis">
                        <div class="tix-od-kpi" data-kpi="events_total">
                            <div class="tix-od-kpi-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
                            <div class="tix-od-kpi-body">
                                <span class="tix-od-kpi-label">Events gesamt</span>
                                <span class="tix-od-kpi-value" id="tix-od-kpi-events">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-od-kpi" data-kpi="tickets_sold">
                            <div class="tix-od-kpi-icon"><span class="dashicons dashicons-tickets-alt"></span></div>
                            <div class="tix-od-kpi-body">
                                <span class="tix-od-kpi-label">Tickets verkauft</span>
                                <span class="tix-od-kpi-value" id="tix-od-kpi-tickets">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-od-kpi" data-kpi="total_revenue">
                            <div class="tix-od-kpi-icon"><span class="dashicons dashicons-chart-area"></span></div>
                            <div class="tix-od-kpi-body">
                                <span class="tix-od-kpi-label">Gesamtumsatz</span>
                                <span class="tix-od-kpi-value" id="tix-od-kpi-revenue">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-od-kpi" data-kpi="upcoming">
                            <div class="tix-od-kpi-icon"><span class="dashicons dashicons-clock"></span></div>
                            <div class="tix-od-kpi-body">
                                <span class="tix-od-kpi-label">Anstehend</span>
                                <span class="tix-od-kpi-value" id="tix-od-kpi-upcoming">&mdash;</span>
                            </div>
                        </div>
                    </div>
                    <div class="tix-od-chart-wrap">
                        <h3 class="tix-od-section-title">Verk&#228;ufe (letzte 30 Tage)</h3>
                        <canvas id="tix-od-chart-sales" height="260"></canvas>
                    </div>
                </div>

                <!-- Meine Events -->
                <div class="tix-od-panel" id="tix-od-panel-events" role="tabpanel" data-tab="events">
                    <div class="tix-od-panel-header">
                        <h3 class="tix-od-section-title">Meine Events</h3>
                        <button type="button" class="tix-od-btn tix-od-btn-primary" id="tix-od-new-event">
                            <span class="dashicons dashicons-plus-alt2"></span> Neues Event
                        </button>
                    </div>
                    <div id="tix-od-events-list" class="tix-od-events-grid">
                        <div class="tix-od-loading"><div class="tix-od-spinner"></div></div>
                    </div>
                </div>

                <!-- Bestellungen -->
                <div class="tix-od-panel" id="tix-od-panel-orders" role="tabpanel" data-tab="orders">
                    <h3 class="tix-od-section-title">Bestellungen</h3>
                    <div class="tix-od-filters">
                        <select id="tix-od-orders-event" class="tix-od-select">
                            <option value="">Alle Events</option>
                        </select>
                        <label>
                            <span>Von:</span>
                            <input type="date" id="tix-od-orders-from" class="tix-od-input">
                        </label>
                        <label>
                            <span>Bis:</span>
                            <input type="date" id="tix-od-orders-to" class="tix-od-input">
                        </label>
                        <button type="button" class="tix-od-btn tix-od-btn-primary" id="tix-od-orders-filter">Filtern</button>
                    </div>
                    <div class="tix-od-table-wrap" id="tix-od-orders-table">
                        <table class="tix-od-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Datum</th>
                                    <th>Kunde</th>
                                    <th>Event</th>
                                    <th>Tickets</th>
                                    <th>Betrag</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7" class="tix-od-loading-cell">Lade Daten&hellip;</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Gaesteliste -->
                <div class="tix-od-panel" id="tix-od-panel-guestlist" role="tabpanel" data-tab="guestlist">
                    <h3 class="tix-od-section-title">G&#228;steliste &amp; Check-In</h3>
                    <div class="tix-od-filters">
                        <select id="tix-od-gl-event" class="tix-od-select">
                            <option value="">Event w&#228;hlen&hellip;</option>
                        </select>
                    </div>
                    <div id="tix-od-guestlist-content">
                        <div class="tix-od-empty">W&#228;hle ein Event, um die G&#228;steliste anzuzeigen.</div>
                    </div>
                </div>

                <!-- Statistiken -->
                <div class="tix-od-panel" id="tix-od-panel-stats" role="tabpanel" data-tab="stats">
                    <h3 class="tix-od-section-title">Statistiken</h3>
                    <div class="tix-od-filters">
                        <select id="tix-od-stats-event" class="tix-od-select">
                            <option value="">Alle Events</option>
                        </select>
                    </div>
                    <div id="tix-od-stats-content">
                        <div class="tix-od-loading"><div class="tix-od-spinner"></div></div>
                    </div>
                </div>

                <!-- Einstellungen -->
                <div class="tix-od-panel" id="tix-od-panel-settings" role="tabpanel" data-tab="settings">
                    <h3 class="tix-od-section-title">Profil &amp; Einstellungen</h3>
                    <div class="tix-od-settings-form">
                        <div class="tix-od-form-group">
                            <label class="tix-od-label">Anzeigename</label>
                            <input type="text" id="tix-od-profile-name" class="tix-od-input"
                                   value="<?php echo esc_attr(wp_get_current_user()->display_name); ?>">
                        </div>
                        <div class="tix-od-form-group">
                            <label class="tix-od-label">E-Mail</label>
                            <input type="email" id="tix-od-profile-email" class="tix-od-input"
                                   value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" readonly>
                        </div>
                        <button type="button" class="tix-od-btn tix-od-btn-primary" id="tix-od-save-profile">Speichern</button>
                    </div>
                </div>

                </div><!-- /.tix-od-content -->
            </div><!-- /.tix-od-layout -->
        </div><!-- /#tix-organizer-dashboard -->
        <?php
        return ob_get_clean();
    }

    /* ══════════════════════════════════════════
     * Login-Formular
     * ══════════════════════════════════════════ */

    private static function render_login() {
        ob_start();
        ?>
        <div class="tix-od" id="tix-organizer-dashboard">
            <div class="tix-od-login">
                <div class="tix-od-login-icon">&#128274;</div>
                <h2 class="tix-od-login-title">Veranstalter Login</h2>
                <p class="tix-od-login-text">Bitte melde dich an, um dein Veranstalter Dashboard zu sehen.</p>
                <?php
                wp_login_form([
                    'redirect'       => get_permalink(),
                    'form_id'        => 'tix-od-login-form',
                    'label_username' => 'E-Mail oder Benutzername',
                    'label_password' => 'Passwort',
                    'label_remember' => 'Angemeldet bleiben',
                    'label_log_in'   => 'Anmelden',
                ]);
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ══════════════════════════════════════════
     * Kein Zugang
     * ══════════════════════════════════════════ */

    private static function render_no_access() {
        ob_start();
        ?>
        <div class="tix-od" id="tix-organizer-dashboard">
            <div class="tix-od-no-access">
                <div class="tix-od-no-access-icon">&#128683;</div>
                <h2 class="tix-od-no-access-title">Kein Zugang</h2>
                <p class="tix-od-no-access-text">Du hast keinen Veranstalter-Zugang. Bitte kontaktiere den Administrator.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ══════════════════════════════════════════
     * AJAX Guard (Nonce + Auth + Organizer-Check)
     * ══════════════════════════════════════════ */

    private static function ajax_guard() {
        check_ajax_referer('tix_organizer_dashboard', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Nicht eingeloggt.']);
            return null;
        }

        $org = self::get_organizer_by_user(get_current_user_id());
        if (!$org) {
            wp_send_json_error(['message' => 'Kein Veranstalter-Zugang.']);
            return null;
        }

        return $org;
    }

    /* ══════════════════════════════════════════
     * AJAX: Uebersicht / KPIs
     * ══════════════════════════════════════════ */

    public static function ajax_overview() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_ids = self::get_organizer_event_ids($org->ID);
        $total_events = count($event_ids);
        $tickets_sold = 0;
        $total_revenue = 0;
        $upcoming = 0;
        $chart_data = [];

        $now = current_time('Y-m-d');

        foreach ($event_ids as $eid) {
            $date_start = get_post_meta($eid, '_tix_date_start', true);
            if ($date_start && $date_start >= $now) {
                $upcoming++;
            }
        }

        // Umsatz + Tickets ueber WC Orders berechnen
        if (!empty($event_ids) && class_exists('WooCommerce')) {
            global $wpdb;

            // Produkt-IDs fuer diese Events
            $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_tix_parent_event_id'
                 AND meta_value IN ($placeholders)",
                ...$event_ids
            ));

            if (!empty($product_ids)) {
                $pp = implode(',', array_map('intval', $product_ids));

                // HPOS-kompatibel: Order Items mit diesen Produkten
                $results = $wpdb->get_row(
                    "SELECT COUNT(DISTINCT oi.order_id) as order_count,
                            COALESCE(SUM(oim_qty.meta_value), 0) as total_qty,
                            COALESCE(SUM(oim_total.meta_value), 0) as total_revenue
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
                         ON oi.order_item_id = oim_pid.order_item_id
                         AND oim_pid.meta_key = '_product_id'
                         AND oim_pid.meta_value IN ($pp)
                     LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
                         ON oi.order_item_id = oim_qty.order_item_id
                         AND oim_qty.meta_key = '_qty'
                     LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total
                         ON oi.order_item_id = oim_total.order_item_id
                         AND oim_total.meta_key = '_line_total'
                     WHERE oi.order_item_type = 'line_item'"
                );

                if ($results) {
                    $tickets_sold  = intval($results->total_qty);
                    $total_revenue = floatval($results->total_revenue);
                }

                // Chart: letzte 30 Tage
                $chart_rows = $wpdb->get_results(
                    "SELECT DATE(p.post_date) as sale_date,
                            COALESCE(SUM(oim_qty.meta_value), 0) as qty,
                            COALESCE(SUM(oim_total.meta_value), 0) as revenue
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
                         ON oi.order_item_id = oim_pid.order_item_id
                         AND oim_pid.meta_key = '_product_id'
                         AND oim_pid.meta_value IN ($pp)
                     LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
                         ON oi.order_item_id = oim_qty.order_item_id
                         AND oim_qty.meta_key = '_qty'
                     LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total
                         ON oi.order_item_id = oim_total.order_item_id
                         AND oim_total.meta_key = '_line_total'
                     INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
                     WHERE oi.order_item_type = 'line_item'
                       AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY DATE(p.post_date)
                     ORDER BY sale_date ASC"
                );

                $chart_map = [];
                foreach ($chart_rows as $row) {
                    $chart_map[$row->sale_date] = [
                        'qty'     => intval($row->qty),
                        'revenue' => floatval($row->revenue),
                    ];
                }

                // 30-Tage-Array befuellen
                for ($i = 29; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-{$i} days"));
                    $label = date_i18n('d.m.', strtotime($date));
                    $chart_data[] = [
                        'label'   => $label,
                        'tickets' => $chart_map[$date]['qty'] ?? 0,
                        'revenue' => $chart_map[$date]['revenue'] ?? 0,
                    ];
                }
            }
        }

        wp_send_json_success([
            'kpis' => [
                'events_total'  => $total_events,
                'tickets_sold'  => $tickets_sold,
                'total_revenue' => self::format_currency($total_revenue),
                'upcoming'      => $upcoming,
            ],
            'chart' => $chart_data,
        ]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Meine Events
     * ══════════════════════════════════════════ */

    public static function ajax_events() {
        $org = self::ajax_guard();
        if (!$org) return;

        $events = get_posts([
            'post_type'      => 'event',
            'meta_key'       => '_tix_organizer_id',
            'meta_value'     => strval($org->ID),
            'post_status'    => ['publish', 'draft', 'pending'],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $rows = [];
        foreach ($events as $ev) {
            $date_start = get_post_meta($ev->ID, '_tix_date_start', true);
            $time_start = get_post_meta($ev->ID, '_tix_time_start', true);
            $status     = get_post_meta($ev->ID, '_tix_event_status', true) ?: 'available';
            $cats       = get_post_meta($ev->ID, '_tix_ticket_categories', true);
            $total_qty  = 0;
            if (is_array($cats)) {
                foreach ($cats as $c) {
                    $total_qty += intval($c['qty'] ?? 0);
                }
            }

            $rows[] = [
                'id'          => $ev->ID,
                'title'       => $ev->post_title,
                'post_status' => $ev->post_status,
                'date'        => $date_start ? date_i18n('d.m.Y', strtotime($date_start)) : '',
                'time'        => $time_start ?: '',
                'status'      => $status,
                'capacity'    => $total_qty,
                'permalink'   => get_permalink($ev->ID),
                'thumbnail'   => get_the_post_thumbnail_url($ev->ID, 'medium') ?: '',
            ];
        }

        wp_send_json_success([
            'events' => $rows,
        ]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Event Detail laden (fuer Editor)
     * ══════════════════════════════════════════ */

    public static function ajax_event_detail() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id || !self::user_owns_event(get_current_user_id(), $event_id)) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
            return;
        }

        $post = get_post($event_id);
        if (!$post || $post->post_type !== 'event') {
            wp_send_json_error(['message' => 'Event nicht gefunden.']);
            return;
        }

        // Alle relevanten Meta-Felder laden
        $data = [
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'post_status' => $post->post_status,
            // Details
            'date_start'  => get_post_meta($event_id, '_tix_date_start', true),
            'date_end'    => get_post_meta($event_id, '_tix_date_end', true),
            'time_start'  => get_post_meta($event_id, '_tix_time_start', true),
            'time_end'    => get_post_meta($event_id, '_tix_time_end', true),
            'time_doors'  => get_post_meta($event_id, '_tix_time_doors', true),
            'event_status' => get_post_meta($event_id, '_tix_event_status', true) ?: 'available',
            'location_id' => intval(get_post_meta($event_id, '_tix_location_id', true)),
            // Info
            'description'        => get_post_meta($event_id, '_tix_info_description', true),
            'artist_description' => get_post_meta($event_id, '_tix_info_artist', true),
            'short_description'  => get_post_meta($event_id, '_tix_short_description', true),
            // Tickets
            'ticket_categories' => get_post_meta($event_id, '_tix_ticket_categories', true) ?: [],
            // Medien
            'featured_image'    => get_post_thumbnail_id($event_id),
            'featured_image_url' => get_the_post_thumbnail_url($event_id, 'medium') ?: '',
            'gallery'           => get_post_meta($event_id, '_tix_gallery', true) ?: [],
            'video_url'         => get_post_meta($event_id, '_tix_video_url', true),
            // FAQ
            'faq'               => get_post_meta($event_id, '_tix_faq', true) ?: [],
            // Rabattcodes
            'discount_codes'    => get_post_meta($event_id, '_tix_discount_codes', true) ?: [],
            // Gewinnspiel
            'raffle_enabled'    => get_post_meta($event_id, '_tix_raffle_enabled', true),
            'raffle_title'      => get_post_meta($event_id, '_tix_raffle_title', true),
            'raffle_description' => get_post_meta($event_id, '_tix_raffle_description', true),
            'raffle_end_date'   => get_post_meta($event_id, '_tix_raffle_end_date', true),
            'raffle_max_entries' => get_post_meta($event_id, '_tix_raffle_max_entries', true),
            'raffle_prizes'     => get_post_meta($event_id, '_tix_raffle_prizes', true) ?: [],
            // Timetable
            'stages'            => get_post_meta($event_id, '_tix_stages', true) ?: [],
            'timetable'         => get_post_meta($event_id, '_tix_timetable', true) ?: [],
            // Presale
            'presale_active'    => get_post_meta($event_id, '_tix_presale_active', true),
            'presale_start'     => get_post_meta($event_id, '_tix_presale_start', true),
            'presale_end_mode'  => get_post_meta($event_id, '_tix_presale_end_mode', true),
            'presale_end'       => get_post_meta($event_id, '_tix_presale_end', true),
            'waitlist_enabled'  => get_post_meta($event_id, '_tix_waitlist_enabled', true),
        ];

        // Locations fuer Dropdown
        $locations = get_posts(['post_type' => 'tix_location', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish']);
        $loc_list = [];
        foreach ($locations as $loc) {
            $loc_list[] = [
                'id'      => $loc->ID,
                'title'   => $loc->post_title,
                'address' => get_post_meta($loc->ID, '_tix_loc_address', true),
            ];
        }
        $data['locations'] = $loc_list;

        wp_send_json_success($data);
    }

    /* ══════════════════════════════════════════
     * AJAX: Event speichern
     * ══════════════════════════════════════════ */

    public static function ajax_save_event() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_id = intval($_POST['event_id'] ?? 0);

        // Bearbeitung: Ownership pruefen
        if ($event_id) {
            if (!self::user_owns_event(get_current_user_id(), $event_id)) {
                wp_send_json_error(['message' => 'Keine Berechtigung.']);
                return;
            }
            // Titel updaten
            wp_update_post([
                'ID'         => $event_id,
                'post_title' => sanitize_text_field($_POST['title'] ?? get_the_title($event_id)),
            ]);
        } else {
            // Neues Event erstellen
            $auto_publish = tix_get_settings('organizer_auto_publish');
            $event_id = wp_insert_post([
                'post_type'   => 'event',
                'post_title'  => sanitize_text_field($_POST['title'] ?? 'Neues Event'),
                'post_status' => $auto_publish ? 'publish' : 'draft',
                'post_author' => get_current_user_id(),
            ]);

            if (is_wp_error($event_id)) {
                wp_send_json_error(['message' => 'Event konnte nicht erstellt werden.']);
                return;
            }

            // Organizer automatisch zuweisen
            update_post_meta($event_id, '_tix_organizer_id', $org->ID);
            update_post_meta($event_id, '_tix_organizer', $org->post_title);
        }

        // ── Meta-Felder speichern (analog TIX_Metabox::save) ──

        // Event-Details
        $fields = [
            '_tix_date_start' => sanitize_text_field($_POST['date_start'] ?? ''),
            '_tix_date_end'   => sanitize_text_field($_POST['date_end'] ?? ''),
            '_tix_time_start' => sanitize_text_field($_POST['time_start'] ?? ''),
            '_tix_time_end'   => sanitize_text_field($_POST['time_end'] ?? ''),
            '_tix_time_doors' => sanitize_text_field($_POST['time_doors'] ?? ''),
        ];
        foreach ($fields as $key => $val) {
            update_post_meta($event_id, $key, $val);
        }

        // Event-Status
        $valid_statuses = ['available', 'sold_out', 'cancelled', 'postponed', 'presale_closed'];
        $status = sanitize_text_field($_POST['event_status'] ?? 'available');
        if (!in_array($status, $valid_statuses)) $status = 'available';
        update_post_meta($event_id, '_tix_event_status', $status);

        // Location
        $loc_id = intval($_POST['location_id'] ?? 0);
        update_post_meta($event_id, '_tix_location_id', $loc_id);
        if ($loc_id) {
            update_post_meta($event_id, '_tix_location', get_the_title($loc_id));
            update_post_meta($event_id, '_tix_address', get_post_meta($loc_id, '_tix_loc_address', true));
        } else {
            update_post_meta($event_id, '_tix_location', '');
            update_post_meta($event_id, '_tix_address', '');
        }

        // Kurzbeschreibung
        update_post_meta($event_id, '_tix_short_description', wp_kses_post($_POST['short_description'] ?? ''));

        // Info-Sektionen
        if (isset($_POST['description'])) {
            update_post_meta($event_id, '_tix_info_description', wp_kses_post($_POST['description']));
        }
        if (isset($_POST['artist_description'])) {
            update_post_meta($event_id, '_tix_info_artist', wp_kses_post($_POST['artist_description']));
        }

        // Ticket-Kategorien
        if (isset($_POST['ticket_categories'])) {
            $raw_cats = $_POST['ticket_categories'];
            $cats = [];
            if (is_array($raw_cats)) {
                foreach ($raw_cats as $c) {
                    $name = sanitize_text_field($c['name'] ?? '');
                    if (empty($name)) continue;
                    $cats[] = [
                        'name'           => $name,
                        'price'          => floatval($c['price'] ?? 0),
                        'sale_price'     => $c['sale_price'] !== '' ? floatval($c['sale_price']) : '',
                        'qty'            => max(0, intval($c['qty'] ?? 0)),
                        'desc'           => sanitize_text_field($c['desc'] ?? ''),
                        'image_id'       => intval($c['image_id'] ?? 0),
                        'online'         => !empty($c['online']) ? 1 : 0,
                        'offline_ticket' => !empty($c['offline_ticket']) ? 1 : 0,
                    ];
                }
            }
            update_post_meta($event_id, '_tix_ticket_categories', $cats);
        }

        // FAQ
        if (isset($_POST['faq'])) {
            $raw_faq = $_POST['faq'];
            $faqs = [];
            if (is_array($raw_faq)) {
                foreach ($raw_faq as $f) {
                    $q = sanitize_text_field($f['q'] ?? '');
                    $a = wp_kses_post($f['a'] ?? '');
                    if (empty($q) && empty($a)) continue;
                    $faqs[] = ['q' => $q, 'a' => $a];
                }
            }
            update_post_meta($event_id, '_tix_faq', $faqs);
        }

        // Featured Image
        if (isset($_POST['featured_image'])) {
            $thumb_id = intval($_POST['featured_image']);
            if ($thumb_id) {
                set_post_thumbnail($event_id, $thumb_id);
            } else {
                delete_post_thumbnail($event_id);
            }
        }

        // Galerie
        if (isset($_POST['gallery'])) {
            $gallery = array_map('intval', (array) $_POST['gallery']);
            update_post_meta($event_id, '_tix_gallery', array_filter($gallery));
        }

        // Video URL
        if (isset($_POST['video_url'])) {
            update_post_meta($event_id, '_tix_video_url', esc_url_raw($_POST['video_url']));
        }

        // Presale
        if (isset($_POST['presale_active'])) {
            update_post_meta($event_id, '_tix_presale_active', !empty($_POST['presale_active']) ? '1' : '');
            update_post_meta($event_id, '_tix_presale_start', sanitize_text_field($_POST['presale_start'] ?? ''));
            update_post_meta($event_id, '_tix_presale_end_mode', sanitize_text_field($_POST['presale_end_mode'] ?? ''));
            update_post_meta($event_id, '_tix_presale_end', sanitize_text_field($_POST['presale_end'] ?? ''));
            update_post_meta($event_id, '_tix_waitlist_enabled', !empty($_POST['waitlist_enabled']) ? '1' : '');
        }

        // Gewinnspiel (Raffle)
        if (isset($_POST['raffle_enabled'])) {
            $raffle_enabled = !empty($_POST['raffle_enabled']) ? '1' : '';
            update_post_meta($event_id, '_tix_raffle_enabled', $raffle_enabled);
            if ($raffle_enabled) {
                update_post_meta($event_id, '_tix_raffle_title', sanitize_text_field($_POST['raffle_title'] ?? ''));
                update_post_meta($event_id, '_tix_raffle_description', wp_kses_post($_POST['raffle_description'] ?? ''));
                update_post_meta($event_id, '_tix_raffle_end_date', sanitize_text_field($_POST['raffle_end_date'] ?? ''));
                update_post_meta($event_id, '_tix_raffle_max_entries', max(0, intval($_POST['raffle_max_entries'] ?? 0)));
                // Preise
                $raw_prizes = $_POST['raffle_prizes'] ?? [];
                $prizes = [];
                if (is_array($raw_prizes)) {
                    foreach ($raw_prizes as $p) {
                        $name = sanitize_text_field($p['name'] ?? '');
                        if (empty($name)) continue;
                        $prizes[] = [
                            'name' => $name,
                            'qty'  => max(1, intval($p['qty'] ?? 1)),
                            'type' => in_array($p['type'] ?? '', ['text', 'ticket']) ? $p['type'] : 'text',
                        ];
                    }
                }
                update_post_meta($event_id, '_tix_raffle_prizes', $prizes);
            }
        }

        // Timetable
        if (isset($_POST['stages'])) {
            $raw_stages = $_POST['stages'];
            $stages = [];
            if (is_array($raw_stages)) {
                foreach ($raw_stages as $st) {
                    $name = sanitize_text_field($st['name'] ?? '');
                    if (empty($name)) continue;
                    $stages[] = [
                        'name'  => $name,
                        'color' => sanitize_hex_color($st['color'] ?? '') ?: '#6366f1',
                    ];
                }
            }
            update_post_meta($event_id, '_tix_stages', $stages);
        }
        if (isset($_POST['timetable'])) {
            $raw_tt = $_POST['timetable'];
            $timetable = [];
            if (is_array($raw_tt)) {
                foreach ($raw_tt as $day => $slots) {
                    $day_key = sanitize_text_field($day);
                    if (empty($day_key) || !is_array($slots)) continue;
                    $timetable[$day_key] = [];
                    foreach ($slots as $slot) {
                        $title = sanitize_text_field($slot['title'] ?? '');
                        if (empty($title)) continue;
                        $timetable[$day_key][] = [
                            'time'  => sanitize_text_field($slot['time'] ?? ''),
                            'end'   => sanitize_text_field($slot['end'] ?? ''),
                            'stage' => intval($slot['stage'] ?? 0),
                            'title' => $title,
                            'desc'  => sanitize_text_field($slot['desc'] ?? ''),
                        ];
                    }
                }
            }
            update_post_meta($event_id, '_tix_timetable', $timetable);
        }

        // Sync ausfuehren (falls vorhanden)
        if (class_exists('TIX_Sync')) {
            TIX_Sync::sync_event($event_id);
        }

        wp_send_json_success([
            'event_id'    => $event_id,
            'post_status' => get_post_status($event_id),
            'message'     => $event_id ? 'Event gespeichert.' : 'Event erstellt.',
        ]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Event loeschen
     * ══════════════════════════════════════════ */

    public static function ajax_delete_event() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id || !self::user_owns_event(get_current_user_id(), $event_id)) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
            return;
        }

        wp_trash_post($event_id);
        wp_send_json_success(['message' => 'Event in den Papierkorb verschoben.']);
    }

    /* ══════════════════════════════════════════
     * AJAX: Event duplizieren
     * ══════════════════════════════════════════ */

    public static function ajax_duplicate_event() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id || !self::user_owns_event(get_current_user_id(), $event_id)) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
            return;
        }

        $original = get_post($event_id);
        if (!$original) {
            wp_send_json_error(['message' => 'Event nicht gefunden.']);
            return;
        }

        $new_id = wp_insert_post([
            'post_type'   => 'event',
            'post_title'  => $original->post_title . ' (Kopie)',
            'post_status' => 'draft',
            'post_author' => get_current_user_id(),
        ]);

        if (is_wp_error($new_id)) {
            wp_send_json_error(['message' => 'Duplizierung fehlgeschlagen.']);
            return;
        }

        // Alle _tix_ Meta-Felder kopieren
        $meta = get_post_meta($event_id);
        foreach ($meta as $key => $values) {
            if (strpos($key, '_tix_') === 0) {
                foreach ($values as $val) {
                    update_post_meta($new_id, $key, maybe_unserialize($val));
                }
            }
        }

        // Organizer sicherstellen
        update_post_meta($new_id, '_tix_organizer_id', $org->ID);
        update_post_meta($new_id, '_tix_organizer', $org->post_title);

        // Thumbnail kopieren
        $thumb_id = get_post_thumbnail_id($event_id);
        if ($thumb_id) {
            set_post_thumbnail($new_id, $thumb_id);
        }

        wp_send_json_success([
            'event_id' => $new_id,
            'message'  => 'Event dupliziert.',
        ]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Bestellungen
     * ══════════════════════════════════════════ */

    public static function ajax_orders() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_ids = self::get_organizer_event_ids($org->ID);
        if (empty($event_ids)) {
            wp_send_json_success(['orders' => []]);
            return;
        }

        global $wpdb;

        // Produkt-IDs fuer diese Events
        $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_tix_parent_event_id'
             AND meta_value IN ($placeholders)",
            ...$event_ids
        ));

        if (empty($product_ids)) {
            wp_send_json_success(['orders' => []]);
            return;
        }

        $pp = implode(',', array_map('intval', $product_ids));

        // Filter
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
        $filter_event = intval($_POST['filter_event'] ?? 0);

        $where = "WHERE oi.order_item_type = 'line_item'
                  AND oim_pid.meta_value IN ($pp)";

        if ($date_from) $where .= $wpdb->prepare(" AND p.post_date >= %s", $date_from . ' 00:00:00');
        if ($date_to)   $where .= $wpdb->prepare(" AND p.post_date <= %s", $date_to . ' 23:59:59');

        $rows = $wpdb->get_results(
            "SELECT oi.order_id,
                    p.post_date as order_date,
                    p.post_status as order_status,
                    oi.order_item_name,
                    oim_qty.meta_value as qty,
                    oim_total.meta_value as line_total,
                    oim_eid.meta_value as event_id
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
                 ON oi.order_item_id = oim_pid.order_item_id
                 AND oim_pid.meta_key = '_product_id'
                 AND oim_pid.meta_value IN ($pp)
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
                 ON oi.order_item_id = oim_qty.order_item_id
                 AND oim_qty.meta_key = '_qty'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total
                 ON oi.order_item_id = oim_total.order_item_id
                 AND oim_total.meta_key = '_line_total'
             LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_eid
                 ON oi.order_item_id = oim_eid.order_item_id
                 AND oim_eid.meta_key = '_tix_event_id'
             INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
             $where
             ORDER BY p.post_date DESC
             LIMIT 200"
        );

        $orders = [];
        $seen = [];
        foreach ($rows as $row) {
            $oid = intval($row->order_id);
            $eid = intval($row->event_id);

            // Event-Filter
            if ($filter_event && $eid !== $filter_event) continue;

            $order = wc_get_order($oid);
            if (!$order) continue;

            $key = $oid . '_' . $eid;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $orders[] = [
                    'order_id'   => $oid,
                    'date'       => date_i18n('d.m.Y H:i', strtotime($row->order_date)),
                    'customer'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'event'      => $eid ? get_the_title($eid) : $row->order_item_name,
                    'tickets'    => intval($row->qty),
                    'total'      => self::format_currency(floatval($row->line_total)),
                    'status'     => wc_get_order_status_name($order->get_status()),
                    'status_key' => $order->get_status(),
                ];
            }
        }

        wp_send_json_success(['orders' => $orders]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Bestellungsdetails
     * ══════════════════════════════════════════ */

    public static function ajax_order_detail() {
        $org = self::ajax_guard();
        if (!$org) return;

        $order_id = intval($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Bestellung nicht gefunden.']);
            return;
        }

        // Pruefen ob die Bestellung zu einem eigenen Event gehoert
        $event_ids = self::get_organizer_event_ids($org->ID);
        $belongs = false;
        $items = [];

        foreach ($order->get_items() as $item) {
            $eid = intval($item->get_meta('_tix_event_id'));
            if (in_array($eid, $event_ids)) {
                $belongs = true;
                $items[] = [
                    'name'  => $item->get_name(),
                    'qty'   => $item->get_quantity(),
                    'total' => self::format_currency(floatval($item->get_total())),
                    'event' => get_the_title($eid),
                ];
            }
        }

        if (!$belongs) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
            return;
        }

        wp_send_json_success([
            'order_id' => $order_id,
            'date'     => $order->get_date_created() ? $order->get_date_created()->date_i18n('d.m.Y H:i') : '',
            'customer' => [
                'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
            ],
            'items'    => $items,
            'total'    => self::format_currency(floatval($order->get_total())),
            'status'   => wc_get_order_status_name($order->get_status()),
        ]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Gaesteliste
     * ══════════════════════════════════════════ */

    public static function ajax_guestlist() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id || !self::user_owns_event(get_current_user_id(), $event_id)) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
            return;
        }

        // Manuelle Gaeste
        $guests = get_post_meta($event_id, '_tix_guestlist', true) ?: [];
        $manual = [];
        foreach ($guests as $i => $g) {
            $manual[] = [
                'index'      => $i,
                'name'       => $g['name'] ?? '',
                'email'      => $g['email'] ?? '',
                'tickets'    => intval($g['tickets'] ?? 1),
                'checked_in' => !empty($g['checked_in']),
                'source'     => 'manual',
            ];
        }

        // Verkaufte Tickets (WC Orders)
        $sold = [];
        if (class_exists('WooCommerce')) {
            global $wpdb;
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_tix_parent_event_id' AND meta_value = %d",
                $event_id
            ));
            if (!empty($product_ids)) {
                $pp = implode(',', array_map('intval', $product_ids));
                $rows = $wpdb->get_results(
                    "SELECT oi.order_id, oim_qty.meta_value as qty
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
                         ON oi.order_item_id = oim_pid.order_item_id
                         AND oim_pid.meta_key = '_product_id'
                         AND oim_pid.meta_value IN ($pp)
                     LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
                         ON oi.order_item_id = oim_qty.order_item_id
                         AND oim_qty.meta_key = '_qty'
                     INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
                         AND p.post_status IN ('wc-processing', 'wc-completed')
                     WHERE oi.order_item_type = 'line_item'"
                );
                foreach ($rows as $row) {
                    $order = wc_get_order($row->order_id);
                    if (!$order) continue;
                    $checkin = get_post_meta($row->order_id, '_tix_checked_in', true);
                    $sold[] = [
                        'order_id'   => intval($row->order_id),
                        'name'       => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'email'      => $order->get_billing_email(),
                        'tickets'    => intval($row->qty),
                        'checked_in' => !empty($checkin),
                        'source'     => 'order',
                    ];
                }
            }
        }

        wp_send_json_success([
            'manual' => $manual,
            'sold'   => $sold,
        ]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Gaesteliste speichern
     * ══════════════════════════════════════════ */

    public static function ajax_guestlist_save() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id || !self::user_owns_event(get_current_user_id(), $event_id)) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
            return;
        }

        $raw = $_POST['guests'] ?? [];
        $guests = [];
        if (is_array($raw)) {
            foreach ($raw as $g) {
                $name = sanitize_text_field($g['name'] ?? '');
                if (empty($name)) continue;
                $guests[] = [
                    'name'       => $name,
                    'email'      => sanitize_email($g['email'] ?? ''),
                    'tickets'    => max(1, intval($g['tickets'] ?? 1)),
                    'checked_in' => !empty($g['checked_in']) ? 1 : 0,
                ];
            }
        }

        update_post_meta($event_id, '_tix_guestlist', $guests);
        wp_send_json_success(['message' => 'Gästeliste gespeichert.']);
    }

    /* ══════════════════════════════════════════
     * AJAX: Check-In Toggle
     * ══════════════════════════════════════════ */

    public static function ajax_checkin() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_id = intval($_POST['event_id'] ?? 0);
        $source   = sanitize_text_field($_POST['source'] ?? '');
        $index    = intval($_POST['index'] ?? -1);
        $order_id = intval($_POST['order_id'] ?? 0);
        $state    = !empty($_POST['checked_in']);

        if (!$event_id || !self::user_owns_event(get_current_user_id(), $event_id)) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
            return;
        }

        if ($source === 'manual' && $index >= 0) {
            $guests = get_post_meta($event_id, '_tix_guestlist', true) ?: [];
            if (isset($guests[$index])) {
                $guests[$index]['checked_in'] = $state ? 1 : 0;
                update_post_meta($event_id, '_tix_guestlist', $guests);
            }
        } elseif ($source === 'order' && $order_id) {
            if ($state) {
                update_post_meta($order_id, '_tix_checked_in', 1);
            } else {
                delete_post_meta($order_id, '_tix_checked_in');
            }
        }

        wp_send_json_success(['checked_in' => $state]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Statistiken
     * ══════════════════════════════════════════ */

    public static function ajax_stats() {
        $org = self::ajax_guard();
        if (!$org) return;

        // Identisch mit Overview, aber mit Event-Filter
        $filter_event = intval($_POST['filter_event'] ?? 0);
        $event_ids = self::get_organizer_event_ids($org->ID);

        if ($filter_event && in_array($filter_event, $event_ids)) {
            $event_ids = [$filter_event];
        }

        // Stats berechnen
        $stats = [
            'tickets_sold'  => 0,
            'total_revenue' => 0,
            'capacity'      => 0,
        ];

        foreach ($event_ids as $eid) {
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            if (is_array($cats)) {
                foreach ($cats as $c) {
                    $stats['capacity'] += intval($c['qty'] ?? 0);
                }
            }
        }

        if (!empty($event_ids) && class_exists('WooCommerce')) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_tix_parent_event_id'
                 AND meta_value IN ($placeholders)",
                ...$event_ids
            ));

            if (!empty($product_ids)) {
                $pp = implode(',', array_map('intval', $product_ids));
                $result = $wpdb->get_row(
                    "SELECT COALESCE(SUM(oim_qty.meta_value), 0) as total_qty,
                            COALESCE(SUM(oim_total.meta_value), 0) as total_revenue
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
                         ON oi.order_item_id = oim_pid.order_item_id
                         AND oim_pid.meta_key = '_product_id'
                         AND oim_pid.meta_value IN ($pp)
                     LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
                         ON oi.order_item_id = oim_qty.order_item_id
                         AND oim_qty.meta_key = '_qty'
                     LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total
                         ON oi.order_item_id = oim_total.order_item_id
                         AND oim_total.meta_key = '_line_total'
                     WHERE oi.order_item_type = 'line_item'"
                );

                if ($result) {
                    $stats['tickets_sold']  = intval($result->total_qty);
                    $stats['total_revenue'] = floatval($result->total_revenue);
                }
            }
        }

        $stats['utilization'] = $stats['capacity'] > 0
            ? round(($stats['tickets_sold'] / $stats['capacity']) * 100, 1)
            : 0;
        $stats['total_revenue_fmt'] = self::format_currency($stats['total_revenue']);

        // Events-Liste fuer Filter-Dropdown
        $all_events = [];
        foreach (self::get_organizer_event_ids($org->ID) as $eid) {
            $all_events[] = [
                'id'    => $eid,
                'title' => get_the_title($eid),
            ];
        }

        wp_send_json_success([
            'stats'  => $stats,
            'events' => $all_events,
        ]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Bild-Upload
     * ══════════════════════════════════════════ */

    public static function ajax_upload_media() {
        $org = self::ajax_guard();
        if (!$org) return;

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('file', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
            return;
        }

        // Organizer-Zuordnung
        update_post_meta($attachment_id, '_tix_org_upload', $org->ID);

        wp_send_json_success([
            'id'    => $attachment_id,
            'url'   => wp_get_attachment_url($attachment_id),
            'thumb' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
        ]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Rabattcode speichern/loeschen
     * ══════════════════════════════════════════ */

    public static function ajax_save_discount() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id || !self::user_owns_event(get_current_user_id(), $event_id)) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
            return;
        }

        $raw_codes = $_POST['discount_codes'] ?? [];
        $codes = [];

        if (is_array($raw_codes) && class_exists('WC_Coupon')) {
            // Event-Produkte fuer Coupon-Scoping
            global $wpdb;
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_tix_parent_event_id' AND meta_value = %d",
                $event_id
            ));

            foreach ($raw_codes as $dc) {
                $code   = sanitize_text_field($dc['code'] ?? '');
                $type   = in_array($dc['type'] ?? '', ['percent', 'fixed_cart']) ? $dc['type'] : 'percent';
                $amount = floatval($dc['amount'] ?? 0);
                $limit  = max(0, intval($dc['limit'] ?? 0));
                $expiry = sanitize_text_field($dc['expiry'] ?? '');

                if (empty($code)) continue;

                $coupon_id = intval($dc['coupon_id'] ?? 0);
                $coupon = new \WC_Coupon($coupon_id ?: 0);
                $coupon->set_code($code);
                $coupon->set_discount_type($type);
                $coupon->set_amount($amount);
                $coupon->set_usage_limit($limit ?: 0);
                $coupon->set_date_expires($expiry ?: null);
                if (!empty($product_ids)) {
                    $coupon->set_product_ids($product_ids);
                }
                $coupon->set_individual_use(false);
                $coupon->save();
                update_post_meta($coupon->get_id(), '_tix_event_coupon', $event_id);

                $codes[] = [
                    'code'      => $code,
                    'type'      => $type,
                    'amount'    => $amount,
                    'limit'     => $limit,
                    'expiry'    => $expiry,
                    'coupon_id' => $coupon->get_id(),
                    'usage'     => $coupon->get_usage_count(),
                ];
            }
        }

        update_post_meta($event_id, '_tix_discount_codes', $codes);
        wp_send_json_success(['discount_codes' => $codes, 'message' => 'Rabattcodes gespeichert.']);
    }

    /* ══════════════════════════════════════════
     * AJAX: Gewinnspiel ziehen
     * ══════════════════════════════════════════ */

    public static function ajax_raffle_draw() {
        $org = self::ajax_guard();
        if (!$org) return;

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id || !self::user_owns_event(get_current_user_id(), $event_id)) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
            return;
        }

        // Nutze bestehende Raffle-Logik falls vorhanden
        if (class_exists('TIX_Raffle') && method_exists('TIX_Raffle', 'draw')) {
            $result = TIX_Raffle::draw($event_id);
            wp_send_json_success($result);
            return;
        }

        wp_send_json_error(['message' => 'Gewinnspiel-Modul nicht verfügbar.']);
    }

    /* ══════════════════════════════════════════
     * AJAX: Profil speichern
     * ══════════════════════════════════════════ */

    public static function ajax_profile() {
        $org = self::ajax_guard();
        if (!$org) return;

        $display_name = sanitize_text_field($_POST['display_name'] ?? '');
        if ($display_name) {
            wp_update_user([
                'ID'           => get_current_user_id(),
                'display_name' => $display_name,
            ]);
        }

        wp_send_json_success(['message' => 'Profil gespeichert.']);
    }

    /* ══════════════════════════════════════════
     * Hilfsfunktionen
     * ══════════════════════════════════════════ */

    private static function format_currency(float $val): string {
        return number_format($val, 2, ',', '.') . '&nbsp;&euro;';
    }
}
