<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat - Promoter Dashboard (Frontend)
 *
 * Shortcode: [tix_promoter_dashboard]
 * Zeigt dem eingeloggten Promoter sein persoenliches Dashboard
 * mit KPIs, Events, Verkaeufen, Provisionen und Auszahlungen.
 *
 * @since 1.30.0
 */
class TIX_Promoter_Dashboard {

    /* ══════════════════════════════════════════
     * Bootstrap
     * ══════════════════════════════════════════ */

    public static function init() {
        add_shortcode('tix_promoter_dashboard', [__CLASS__, 'render']);

        // AJAX endpoints (logged-in + nopriv fuer konsistentes Verhalten)
        $actions = [
            'tix_pd_overview',
            'tix_pd_events',
            'tix_pd_sales',
            'tix_pd_commissions',
            'tix_pd_payouts',
        ];
        foreach ($actions as $action) {
            add_action('wp_ajax_' . $action, [__CLASS__, 'ajax_' . str_replace('tix_pd_', '', $action)]);
            add_action('wp_ajax_nopriv_' . $action, [__CLASS__, 'ajax_' . str_replace('tix_pd_', '', $action)]);
        }
    }

    /* ══════════════════════════════════════════
     * Assets (nur wenn Shortcode genutzt wird)
     * ══════════════════════════════════════════ */

    private static function enqueue() {
        wp_enqueue_style(
            'tix-promoter-dashboard',
            TIXOMAT_URL . 'assets/css/promoter-dashboard.css',
            [],
            TIXOMAT_VERSION
        );

        // Chart.js fuer Umsatz-Chart
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        wp_enqueue_script(
            'tix-promoter-dashboard',
            TIXOMAT_URL . 'assets/js/promoter-dashboard.js',
            ['jquery', 'chartjs'],
            TIXOMAT_VERSION,
            true
        );

        wp_localize_script('tix-promoter-dashboard', 'tixPD', [
            'ajax'     => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tix_promoter_dashboard'),
            'site_url' => home_url('/'),
        ]);
    }

    /* ══════════════════════════════════════════
     * Shortcode rendern
     * ══════════════════════════════════════════ */

    public static function render($atts = []) {
        // -- Nicht eingeloggt: Login-Formular --
        if (!is_user_logged_in()) {
            return self::render_login();
        }

        // -- Kein Promoter: Hinweis --
        if (!class_exists('TIX_Promoter_DB')) {
            return '<div class="tix-pd"><p>Promoter-Modul ist nicht verfuegbar.</p></div>';
        }

        $promoter = TIX_Promoter_DB::get_promoter_by_user(get_current_user_id());
        if (!$promoter || $promoter->status !== 'active') {
            return self::render_no_access();
        }

        self::enqueue();

        ob_start();
        ?>
        <div class="tix-pd" id="tix-promoter-dashboard" data-promoter-id="<?php echo esc_attr($promoter->id); ?>">

            <!-- Header -->
            <div class="tix-pd-header">
                <h2 class="tix-pd-title">Promoter Dashboard</h2>
                <span class="tix-pd-welcome">Hallo, <?php echo esc_html($promoter->display_name ?: wp_get_current_user()->display_name); ?>!</span>
            </div>

            <!-- Tab-Navigation -->
            <nav class="tix-pd-tabs" role="tablist">
                <button class="tix-pd-tab active" data-tab="overview" role="tab" aria-selected="true"
                        aria-controls="tix-pd-panel-overview">
                    <span class="tix-pd-tab-icon dashicons dashicons-dashboard"></span>
                    <span class="tix-pd-tab-label">&#220;bersicht</span>
                </button>
                <button class="tix-pd-tab" data-tab="events" role="tab" aria-selected="false"
                        aria-controls="tix-pd-panel-events">
                    <span class="tix-pd-tab-icon dashicons dashicons-calendar-alt"></span>
                    <span class="tix-pd-tab-label">Meine Events</span>
                </button>
                <button class="tix-pd-tab" data-tab="sales" role="tab" aria-selected="false"
                        aria-controls="tix-pd-panel-sales">
                    <span class="tix-pd-tab-icon dashicons dashicons-cart"></span>
                    <span class="tix-pd-tab-label">Verk&#228;ufe</span>
                </button>
                <button class="tix-pd-tab" data-tab="commissions" role="tab" aria-selected="false"
                        aria-controls="tix-pd-panel-commissions">
                    <span class="tix-pd-tab-icon dashicons dashicons-money-alt"></span>
                    <span class="tix-pd-tab-label">Provisionen</span>
                </button>
                <button class="tix-pd-tab" data-tab="payouts" role="tab" aria-selected="false"
                        aria-controls="tix-pd-panel-payouts">
                    <span class="tix-pd-tab-icon dashicons dashicons-bank"></span>
                    <span class="tix-pd-tab-label">Auszahlungen</span>
                </button>
            </nav>

            <!-- Tab-Panels -->
            <div class="tix-pd-panels">

                <!-- Uebersicht -->
                <div class="tix-pd-panel active" id="tix-pd-panel-overview" role="tabpanel" data-tab="overview">
                    <div class="tix-pd-kpis" id="tix-pd-kpis">
                        <div class="tix-pd-kpi" data-kpi="total_sales">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-chart-area"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Gesamtumsatz</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-kpi-total-sales">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-pd-kpi" data-kpi="total_commission">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-money-alt"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Provision gesamt</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-kpi-total-commission">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-pd-kpi" data-kpi="pending_commission">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-clock"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Ausstehend</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-kpi-pending">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-pd-kpi" data-kpi="events_count">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Aktive Events</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-kpi-events">&mdash;</span>
                            </div>
                        </div>
                    </div>
                    <div class="tix-pd-chart-wrap">
                        <h3 class="tix-pd-section-title">Umsatzverlauf (letzte 30 Tage)</h3>
                        <canvas id="tix-pd-chart-sales" height="260"></canvas>
                    </div>
                </div>

                <!-- Meine Events -->
                <div class="tix-pd-panel" id="tix-pd-panel-events" role="tabpanel" data-tab="events">
                    <h3 class="tix-pd-section-title">Meine Events</h3>
                    <div class="tix-pd-table-wrap" id="tix-pd-events-table">
                        <table class="tix-pd-table">
                            <thead>
                                <tr>
                                    <th>Event-Name</th>
                                    <th>Datum</th>
                                    <th>Referral-Link</th>
                                    <th>Promo-Code</th>
                                    <th>Provision</th>
                                    <th>Rabatt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="tix-pd-loading">Lade Daten&hellip;</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Verkaeufe -->
                <div class="tix-pd-panel" id="tix-pd-panel-sales" role="tabpanel" data-tab="sales">
                    <h3 class="tix-pd-section-title">Verk&#228;ufe</h3>
                    <div class="tix-pd-filters">
                        <label>
                            <span>Von:</span>
                            <input type="date" id="tix-pd-sales-from" class="tix-pd-input">
                        </label>
                        <label>
                            <span>Bis:</span>
                            <input type="date" id="tix-pd-sales-to" class="tix-pd-input">
                        </label>
                        <button type="button" class="tix-pd-btn tix-pd-btn-primary" id="tix-pd-sales-filter">Filtern</button>
                    </div>
                    <div class="tix-pd-table-wrap" id="tix-pd-sales-table">
                        <table class="tix-pd-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Event</th>
                                    <th>Tickets</th>
                                    <th>Umsatz</th>
                                    <th>Provision</th>
                                    <th>Attribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="tix-pd-loading">Lade Daten&hellip;</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Provisionen -->
                <div class="tix-pd-panel" id="tix-pd-panel-commissions" role="tabpanel" data-tab="commissions">
                    <h3 class="tix-pd-section-title">Provisionen</h3>
                    <div class="tix-pd-table-wrap" id="tix-pd-commissions-table">
                        <table class="tix-pd-table">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Event</th>
                                    <th>Bestellung</th>
                                    <th>Tickets</th>
                                    <th>Provision</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="tix-pd-loading">Lade Daten&hellip;</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Auszahlungen -->
                <div class="tix-pd-panel" id="tix-pd-panel-payouts" role="tabpanel" data-tab="payouts">
                    <h3 class="tix-pd-section-title">Auszahlungen</h3>
                    <div class="tix-pd-table-wrap" id="tix-pd-payouts-table">
                        <table class="tix-pd-table">
                            <thead>
                                <tr>
                                    <th>Zeitraum</th>
                                    <th>Umsatz</th>
                                    <th>Provision</th>
                                    <th>Anzahl</th>
                                    <th>Status</th>
                                    <th>Bezahlt am</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" class="tix-pd-loading">Lade Daten&hellip;</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /.tix-pd-panels -->
        </div><!-- /#tix-promoter-dashboard -->
        <?php
        return ob_get_clean();
    }

    /* ══════════════════════════════════════════
     * Login-Formular
     * ══════════════════════════════════════════ */

    private static function render_login() {
        ob_start();
        ?>
        <div class="tix-pd" id="tix-promoter-dashboard">
            <div class="tix-pd-login">
                <div class="tix-pd-login-icon">&#128274;</div>
                <h2 class="tix-pd-login-title">Promoter Login</h2>
                <p class="tix-pd-login-text">Bitte melde dich an, um dein Promoter Dashboard zu sehen.</p>
                <?php
                wp_login_form([
                    'redirect'       => get_permalink(),
                    'form_id'        => 'tix-pd-login-form',
                    'label_username' => 'E-Mail oder Benutzername',
                    'label_password' => 'Passwort',
                    'label_remember' => 'Angemeldet bleiben',
                    'label_log_in'   => 'Anmelden',
                ]);
                ?>
                <?php if (get_option('users_can_register')): ?>
                    <p class="tix-pd-login-register">
                        Noch kein Konto? <a href="<?php echo esc_url(wp_registration_url()); ?>">Jetzt registrieren</a>
                    </p>
                <?php endif; ?>
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
        <div class="tix-pd" id="tix-promoter-dashboard">
            <div class="tix-pd-no-access">
                <div class="tix-pd-no-access-icon">&#128683;</div>
                <h2 class="tix-pd-no-access-title">Kein Zugang</h2>
                <p class="tix-pd-no-access-text">Du hast keinen Promoter-Zugang.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ══════════════════════════════════════════
     * AJAX: Uebersicht / KPIs
     * ══════════════════════════════════════════ */

    public static function ajax_overview() {
        $promoter = self::ajax_guard();
        if (!$promoter) return;

        $stats = TIX_Promoter_DB::get_promoter_stats($promoter->id);
        $events = TIX_Promoter_DB::get_promoter_events($promoter->id);

        // Timeline fuer Chart: letzte 30 Tage
        $from = date('Y-m-d', strtotime('-30 days'));
        $to   = date('Y-m-d');
        $timeline = TIX_Promoter_DB::get_stats_timeline($promoter->id, $from, $to, 'day');

        $chart_labels = [];
        $chart_sales  = [];
        $chart_commission = [];

        foreach ($timeline as $row) {
            $dt = DateTime::createFromFormat('Y-m-d', $row->period);
            $chart_labels[]     = $dt ? $dt->format('d.m.') : $row->period;
            $chart_sales[]      = floatval($row->sales);
            $chart_commission[] = floatval($row->commission);
        }

        wp_send_json_success([
            'kpis' => [
                'total_sales'        => self::format_currency(floatval($stats->total_sales ?? 0)),
                'total_commission'   => self::format_currency(floatval($stats->total_commission ?? 0)),
                'pending_commission' => self::format_currency(floatval($stats->pending_commission ?? 0)),
                'events_count'       => count($events),
            ],
            'chart' => [
                'labels'     => $chart_labels,
                'sales'      => $chart_sales,
                'commission' => $chart_commission,
            ],
        ]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Meine Events
     * ══════════════════════════════════════════ */

    public static function ajax_events() {
        $promoter = self::ajax_guard();
        if (!$promoter) return;

        $assignments = TIX_Promoter_DB::get_promoter_events($promoter->id);
        $rows = [];

        foreach ($assignments as $a) {
            $event_id   = intval($a->event_id);
            $event_title = $a->event_title ?: get_the_title($event_id);
            $permalink   = get_permalink($event_id);

            // Referral-Link
            $referral_link = $permalink
                ? add_query_arg('ref', $promoter->promoter_code, $permalink)
                : home_url('/?p=' . $event_id . '&ref=' . $promoter->promoter_code);

            // Event-Datum (aus Tickera/Custom Meta)
            $event_date = '';
            $date_raw = get_post_meta($event_id, '_EventStartDate', true)
                        ?: get_post_meta($event_id, '_tix_event_date', true)
                        ?: get_post_meta($event_id, 'event_date_time', true);
            if ($date_raw) {
                $dt = strtotime($date_raw);
                $event_date = $dt ? date_i18n('d.m.Y', $dt) : '';
            }

            // Provision-Anzeige
            $commission_display = '';
            if ($a->commission_type === 'percent') {
                $commission_display = number_format(floatval($a->commission_value), 1, ',', '.') . ' %';
            } elseif ($a->commission_type === 'fixed') {
                $commission_display = self::format_currency(floatval($a->commission_value));
            }

            // Rabatt-Anzeige
            $discount_display = '';
            if (!empty($a->discount_type) && floatval($a->discount_value) > 0) {
                if ($a->discount_type === 'percent') {
                    $discount_display = number_format(floatval($a->discount_value), 1, ',', '.') . ' %';
                } elseif ($a->discount_type === 'fixed') {
                    $discount_display = self::format_currency(floatval($a->discount_value));
                }
            }

            $rows[] = [
                'event_id'       => $event_id,
                'event_title'    => $event_title,
                'event_date'     => $event_date,
                'referral_link'  => $referral_link,
                'promo_code'     => $a->promo_code ?: '',
                'commission'     => $commission_display,
                'discount'       => $discount_display ?: '&ndash;',
            ];
        }

        wp_send_json_success(['events' => $rows]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Verkaeufe
     * ══════════════════════════════════════════ */

    public static function ajax_sales() {
        $promoter = self::ajax_guard();
        if (!$promoter) return;

        $filters = ['promoter_id' => $promoter->id];

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');

        if ($date_from) $filters['date_from'] = $date_from;
        if ($date_to)   $filters['date_to']   = $date_to;
        if (empty($filters['date_from']) && empty($filters['date_to'])) {
            $filters['limit'] = 100;
        }

        $commissions = TIX_Promoter_DB::get_commissions($filters);
        $rows = [];

        foreach ($commissions as $c) {
            $dt = strtotime($c->created_at);

            $attribution_label = 'Referral-Link';
            if ($c->attribution === 'promo_code') {
                $attribution_label = 'Promo-Code';
            }

            $rows[] = [
                'date'        => $dt ? date_i18n('d.m.Y H:i', $dt) : '',
                'event'       => $c->event_title ?: get_the_title(intval($c->event_id)),
                'tickets'     => intval($c->tickets_qty),
                'sales'       => self::format_currency(floatval($c->order_total)),
                'commission'  => self::format_currency(floatval($c->commission_amount)),
                'attribution' => $attribution_label,
                'status'      => $c->status,
            ];
        }

        wp_send_json_success(['sales' => $rows]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Provisionen
     * ══════════════════════════════════════════ */

    public static function ajax_commissions() {
        $promoter = self::ajax_guard();
        if (!$promoter) return;

        $commissions = TIX_Promoter_DB::get_commissions([
            'promoter_id' => $promoter->id,
        ]);

        $rows = [];
        foreach ($commissions as $c) {
            $dt = strtotime($c->created_at);

            $rows[] = [
                'date'       => $dt ? date_i18n('d.m.Y H:i', $dt) : '',
                'event'      => $c->event_title ?: get_the_title(intval($c->event_id)),
                'order_id'   => intval($c->order_id),
                'order_link' => '#' . intval($c->order_id),
                'tickets'    => intval($c->tickets_qty),
                'commission' => self::format_currency(floatval($c->commission_amount)),
                'status'     => $c->status,
            ];
        }

        wp_send_json_success(['commissions' => $rows]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Auszahlungen
     * ══════════════════════════════════════════ */

    public static function ajax_payouts() {
        $promoter = self::ajax_guard();
        if (!$promoter) return;

        $payouts = TIX_Promoter_DB::get_payouts([
            'promoter_id' => $promoter->id,
        ]);

        $rows = [];
        foreach ($payouts as $po) {
            $from = strtotime($po->period_from);
            $to   = strtotime($po->period_to);

            $paid_date = '';
            if ($po->paid_date) {
                $pd = strtotime($po->paid_date);
                $paid_date = $pd ? date_i18n('d.m.Y', $pd) : '';
            }

            $rows[] = [
                'period'     => ($from ? date_i18n('d.m.Y', $from) : '') . ' &ndash; ' . ($to ? date_i18n('d.m.Y', $to) : ''),
                'sales'      => self::format_currency(floatval($po->total_sales)),
                'commission' => self::format_currency(floatval($po->total_commission)),
                'count'      => intval($po->commission_count),
                'status'     => $po->status,
                'paid_date'  => $paid_date ?: '&ndash;',
            ];
        }

        wp_send_json_success(['payouts' => $rows]);
    }

    /* ══════════════════════════════════════════
     * AJAX Guard (Nonce + Auth + Promoter-Check)
     * ══════════════════════════════════════════ */

    private static function ajax_guard() {
        check_ajax_referer('tix_promoter_dashboard', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Nicht eingeloggt.']);
            return null;
        }

        if (!class_exists('TIX_Promoter_DB')) {
            wp_send_json_error(['message' => 'Promoter-Modul nicht verfuegbar.']);
            return null;
        }

        $promoter = TIX_Promoter_DB::get_promoter_by_user(get_current_user_id());
        if (!$promoter || $promoter->status !== 'active') {
            wp_send_json_error(['message' => 'Kein Promoter-Zugang.']);
            return null;
        }

        return $promoter;
    }

    /* ══════════════════════════════════════════
     * Hilfsfunktionen
     * ══════════════════════════════════════════ */

    /**
     * Waehrungsformatierung (deutsch)
     */
    private static function format_currency(float $val): string {
        return number_format($val, 2, ',', '.') . '&nbsp;&euro;';
    }
}
