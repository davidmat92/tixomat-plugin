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
            'tix_pd_tracking',
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
            ['tix-google-fonts'],
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

        // Auch tix-account-CSS laden für identischen Look
        wp_enqueue_style('tix-account', TIXOMAT_URL . 'assets/css/tix-account.css', [], TIXOMAT_VERSION);

        $primary = function_exists('tix_primary') ? tix_primary() : '#FF5500';

        ob_start();
        ?>
        <div class="tix-account tix-pd" id="tix-promoter-dashboard"
             data-promoter-id="<?php echo esc_attr($promoter->id); ?>"
             style="--tix-acc-primary: <?php echo esc_attr($primary); ?>;">

            <!-- Sidebar-Nav (my-account-Stil) -->
            <nav class="tix-account-nav">
                <ul>
                    <li class="tix-account-nav-item is-active">
                        <a href="#overview" class="tix-pd-nav-link" data-tab="overview">
                            <span class="dashicons dashicons-dashboard"></span> Übersicht
                        </a>
                    </li>
                    <li class="tix-account-nav-item">
                        <a href="#events" class="tix-pd-nav-link" data-tab="events">
                            <span class="dashicons dashicons-calendar-alt"></span> Meine Events
                        </a>
                    </li>
                    <li class="tix-account-nav-item">
                        <a href="#tracking" class="tix-pd-nav-link" data-tab="tracking">
                            <span class="dashicons dashicons-chart-line"></span> Tracking
                        </a>
                    </li>
                    <li class="tix-account-nav-item">
                        <a href="#sales" class="tix-pd-nav-link" data-tab="sales">
                            <span class="dashicons dashicons-cart"></span> Verkäufe
                        </a>
                    </li>
                    <li class="tix-account-nav-item">
                        <a href="#commissions" class="tix-pd-nav-link" data-tab="commissions">
                            <span class="dashicons dashicons-money-alt"></span> Provisionen
                        </a>
                    </li>
                    <li class="tix-account-nav-item">
                        <a href="#payouts" class="tix-pd-nav-link" data-tab="payouts">
                            <span class="dashicons dashicons-bank"></span> Auszahlungen
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="tix-account-content">
                <!-- Header (innerhalb Content) -->
                <div class="tix-pd-header" style="margin-bottom:24px;">
                    <h2 class="tix-pd-title" style="margin:0 0 4px;font-size:24px;">Promoter Dashboard</h2>
                    <p class="tix-pd-welcome" style="margin:0;color:#64748b;">
                        Hallo, <?php echo esc_html($promoter->display_name ?: wp_get_current_user()->display_name); ?>!
                        Dein Promoter-Code: <code style="background:#fef3c7;padding:2px 8px;border-radius:6px;font-weight:700;letter-spacing:0.05em;"><?php echo esc_html($promoter->promoter_code); ?></code>
                    </p>
                </div>

            <!-- (Sentinel: rest of dashboard panels follow below — closing tags am Ende) -->

            <!-- Hidden: Legacy Tab-Buttons für Backward-Compat (nicht sichtbar, nur falls JS sie braucht) -->
            <div class="tix-pd-tabs" role="tablist" style="display:none;">
                <button class="tix-pd-tab active" data-tab="overview"></button>
                <button class="tix-pd-tab" data-tab="events"></button>
                <button class="tix-pd-tab" data-tab="tracking"></button>
                <button class="tix-pd-tab" data-tab="sales"></button>
                <button class="tix-pd-tab" data-tab="commissions"></button>
                <button class="tix-pd-tab" data-tab="payouts"></button>
            </div>

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
                    <div class="tix-pd-links-wrap" id="tix-pd-links">
                        <h3 class="tix-pd-section-title">Deine Referral-Links</h3>
                        <p class="tix-pd-links-hint">Teile diese Links, um Provisionen zu verdienen.</p>
                        <div class="tix-pd-links-list" id="tix-pd-links-list"></div>
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
                            <tbody id="tix-pd-events-body">
                                <tr><td colspan="6" class="tix-pd-loading">Lade Daten&hellip;</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tracking (Klicks auf Referral-Links) -->
                <div class="tix-pd-panel" id="tix-pd-panel-tracking" role="tabpanel" data-tab="tracking">
                    <h3 class="tix-pd-section-title">Tracking — Aufrufe deiner Referral-Links</h3>
                    <p style="color:#64748b;margin:0 0 16px;font-size:13px;">Jeder Klick auf einen Link mit deinem Code <code><?php echo esc_html($promoter->promoter_code); ?></code> wird hier gezählt. Mehrfach-Klicks vom gleichen Besucher werden alle 30 Min. dedupliziert.</p>

                    <!-- KPI-Cards für Klicks -->
                    <div class="tix-pd-kpis" id="tix-pd-tracking-kpis">
                        <div class="tix-pd-kpi">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-visibility"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Klicks gesamt</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-tk-total">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-pd-kpi">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-admin-users"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Unique Besucher</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-tk-unique">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-pd-kpi">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-clock"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Heute</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-tk-today">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-pd-kpi">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-calendar"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Letzte 7 Tage</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-tk-7d">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-pd-kpi">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-chart-bar"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Letzte 30 Tage</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-tk-30d">&mdash;</span>
                            </div>
                        </div>
                        <div class="tix-pd-kpi">
                            <div class="tix-pd-kpi-icon"><span class="dashicons dashicons-chart-pie"></span></div>
                            <div class="tix-pd-kpi-body">
                                <span class="tix-pd-kpi-label">Conversion</span>
                                <span class="tix-pd-kpi-value" id="tix-pd-tk-conv">&mdash;</span>
                            </div>
                        </div>
                    </div>

                    <!-- Klicks-Verlauf -->
                    <div class="tix-pd-chart-wrap" style="margin-top:24px;">
                        <h3 class="tix-pd-section-title">Klick-Verlauf (letzte 30 Tage)</h3>
                        <canvas id="tix-pd-chart-clicks" height="220"></canvas>
                    </div>

                    <!-- Top-Pfade + Geräte -->
                    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-top:24px;">
                        <div>
                            <h3 class="tix-pd-section-title">Top-Seiten (mit deinem Link aufgerufen)</h3>
                            <div class="tix-pd-table-wrap">
                                <table class="tix-pd-table">
                                    <thead><tr><th>Seite</th><th>Klicks</th><th>Unique</th></tr></thead>
                                    <tbody id="tix-pd-tk-pages-body">
                                        <tr><td colspan="3" class="tix-pd-loading">Lade Daten&hellip;</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div>
                            <h3 class="tix-pd-section-title">Geräte</h3>
                            <div id="tix-pd-tk-devices" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;">
                                <div class="tix-pd-loading">Lade Daten&hellip;</div>
                            </div>
                        </div>
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
                            <tbody id="tix-pd-sales-body">
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
                            <tbody id="tix-pd-commissions-body">
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
                            <tbody id="tix-pd-payouts-body">
                                <tr><td colspan="6" class="tix-pd-loading">Lade Daten&hellip;</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /.tix-pd-panels -->
            </div><!-- /.tix-account-content -->
        </div><!-- /#tix-promoter-dashboard -->

        <style>
        /* Promoter-Dashboard im my-account-Look — Overrides */
        .tix-account.tix-pd .tix-pd-tabs { display: none !important; }
        .tix-account.tix-pd .tix-pd-panels { display: block; }
        .tix-account.tix-pd .tix-pd-panel { display: none; }
        .tix-account.tix-pd .tix-pd-panel.active { display: block; }
        .tix-account.tix-pd .tix-pd-section-title {
            font-size: 16px; font-weight: 700; margin: 0 0 12px; color: #0f172a;
        }
        .tix-account.tix-pd .tix-pd-kpis {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px; margin-bottom: 24px;
        }
        .tix-account.tix-pd .tix-pd-kpi {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
            padding: 16px; display: flex; align-items: center; gap: 12px;
        }
        .tix-account.tix-pd .tix-pd-kpi-icon {
            width: 40px; height: 40px; border-radius: 10px;
            background: var(--tix-acc-primary, #FF5500); color: #fff;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .tix-account.tix-pd .tix-pd-kpi-icon .dashicons { font-size: 20px; width: 20px; height: 20px; }
        .tix-account.tix-pd .tix-pd-kpi-label {
            display: block; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600;
        }
        .tix-account.tix-pd .tix-pd-kpi-value {
            display: block; font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.2;
        }
        .tix-account.tix-pd .tix-pd-table-wrap {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden;
        }
        .tix-account.tix-pd .tix-pd-table {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .tix-account.tix-pd .tix-pd-table th {
            background: #f9fafb; padding: 10px 14px; text-align: left;
            font-weight: 600; color: #374151; font-size: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .tix-account.tix-pd .tix-pd-table td {
            padding: 10px 14px; border-top: 1px solid #f3f4f6; vertical-align: middle;
        }
        .tix-account.tix-pd .tix-pd-loading,
        .tix-account.tix-pd .tix-pd-empty {
            text-align: center; color: #9ca3af; padding: 24px; font-size: 13px;
        }
        .tix-account.tix-pd .tix-pd-link { font-family: ui-monospace, Menlo, Consolas, monospace; }
        .tix-account.tix-pd .tix-pd-copy {
            border: 1px solid #e5e7eb; background: #fff; padding: 4px 10px;
            border-radius: 6px; cursor: pointer; font-size: 11px; margin-left: 6px;
        }
        .tix-account.tix-pd .tix-pd-copy:hover { background: #f9fafb; }
        .tix-account.tix-pd .tix-pd-copy.copied {
            background: var(--tix-acc-primary, #FF5500); color: #fff; border-color: transparent;
        }
        .tix-account.tix-pd .tix-pd-chart-wrap {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
            padding: 18px; margin-top: 24px;
        }
        .tix-account.tix-pd .tix-pd-links-wrap {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
            padding: 18px; margin-top: 24px;
        }
        @media (max-width: 768px) {
            .tix-account.tix-pd .tix-pd-kpis { grid-template-columns: 1fr 1fr; }
        }
        </style>
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

        // Referral-Links fuer Uebersicht
        $links = [];
        foreach ($events as $a) {
            if ($a->status !== 'active') continue;
            $eid   = intval($a->event_id);
            $plink = get_permalink($eid);
            $ref   = $plink
                ? add_query_arg('ref', $promoter->promoter_code, $plink)
                : home_url('/?p=' . $eid . '&ref=' . $promoter->promoter_code);
            $links[] = [
                'title'    => $a->event_title ?: get_the_title($eid),
                'link'     => $ref,
                'promo'    => $a->promo_code ?: '',
            ];
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
            'links' => $links,
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

        // Globale Zuordnung (event_id=0) separat behandeln + ALLE bookable Events listen
        $global = null;
        $event_specific = [];
        foreach ($assignments as $a) {
            if (intval($a->event_id) === 0) {
                $global = $a;
            } else {
                $event_specific[intval($a->event_id)] = $a;
            }
        }

        $build_row = function($a, $event_id, $event_title) use ($promoter) {
            $is_global = ($event_id === 0);

            // Referral-Link
            if ($is_global) {
                $referral_link = add_query_arg('ref', $promoter->promoter_code, home_url('/'));
            } else {
                $permalink = get_permalink($event_id);
                $referral_link = $permalink
                    ? add_query_arg('ref', $promoter->promoter_code, $permalink)
                    : add_query_arg(['p' => $event_id, 'ref' => $promoter->promoter_code], home_url('/'));
            }

            // Event-Datum
            $event_date = '';
            if (!$is_global && $event_id) {
                $date_raw = get_post_meta($event_id, '_tix_date_start', true)
                            ?: get_post_meta($event_id, '_EventStartDate', true)
                            ?: get_post_meta($event_id, '_tix_event_date', true)
                            ?: get_post_meta($event_id, 'event_date_time', true);
                if ($date_raw) {
                    $dt = strtotime($date_raw);
                    $event_date = $dt ? date_i18n('d.m.Y', $dt) : '';
                }
            }

            // Provision
            $commission_display = '';
            if ($a->commission_type === 'percent') {
                $commission_display = number_format(floatval($a->commission_value), 1, ',', '.') . ' %';
            } elseif ($a->commission_type === 'fixed') {
                $commission_display = self::format_currency(floatval($a->commission_value));
            }

            // Rabatt
            $discount_display = '';
            if (!empty($a->discount_type) && floatval($a->discount_value) > 0) {
                if ($a->discount_type === 'percent') {
                    $discount_display = number_format(floatval($a->discount_value), 1, ',', '.') . ' %';
                } elseif ($a->discount_type === 'fixed') {
                    $discount_display = self::format_currency(floatval($a->discount_value));
                }
            }

            return [
                'event_id'      => $event_id,
                'event_title'   => $is_global ? '🌐 Alle Events (Allgemeiner Link)' : ($event_title ?: '(Event #' . $event_id . ')'),
                'event_date'    => $event_date ?: ($is_global ? 'Dauerhaft' : ''),
                'referral_link' => $referral_link,
                'promo_code'    => $a->promo_code ?: '',
                'commission'    => $commission_display,
                'discount'      => $discount_display ?: '&ndash;',
                'is_global'     => $is_global,
            ];
        };

        // Globale Zuordnung als erste Zeile
        if ($global) {
            $rows[] = $build_row($global, 0, '');
        }

        // Event-spezifische Zuordnungen
        foreach ($event_specific as $eid => $a) {
            $rows[] = $build_row($a, $eid, $a->event_title);
        }

        // Wenn globale Zuordnung existiert → ALLE bookable Events ergänzen,
        // damit der Promoter pro Event einen Link mitnehmen kann (jeweils mit Global-Provision)
        if ($global) {
            $bookable = get_posts([
                'post_type'      => 'event',
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'meta_query'     => [
                    'relation' => 'OR',
                    [ 'key' => '_tix_date_start', 'value' => date('Y-m-d'), 'compare' => '>=', 'type' => 'DATE' ],
                    [ 'key' => '_tix_date_start', 'compare' => 'NOT EXISTS' ],
                ],
                'orderby'        => 'meta_value',
                'meta_key'       => '_tix_date_start',
                'order'          => 'ASC',
            ]);
            foreach ($bookable as $ev) {
                if (isset($event_specific[$ev->ID])) continue; // schon explizit zugeordnet
                $rows[] = $build_row($global, $ev->ID, $ev->post_title);
            }
        }

        wp_send_json_success(['events' => $rows]);
    }

    /* ══════════════════════════════════════════
     * AJAX: Tracking (Klicks auf Referral-Links)
     * ══════════════════════════════════════════ */

    public static function ajax_tracking() {
        $promoter = self::ajax_guard();
        if (!$promoter) return;

        $stats = TIX_Promoter_DB::get_click_stats(intval($promoter->id));

        // Conversion: total_clicks vs. distinct orders mit promoter_id
        global $wpdb;
        $orders_with_promoter = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
             WHERE meta_key = '_tix_promoter_id' AND meta_value = %s",
            (string) $promoter->id
        ));
        $conversion_rate = ($stats['unique'] ?? 0) > 0
            ? round(($orders_with_promoter / $stats['unique']) * 100, 2)
            : 0;

        wp_send_json_success([
            'stats'           => $stats,
            'orders'          => $orders_with_promoter,
            'conversion_rate' => $conversion_rate,
        ]);
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
