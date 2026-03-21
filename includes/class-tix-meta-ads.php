<?php
if (!defined('ABSPATH')) exit;

class TIX_Meta_Ads {

    public static function init() {
        if (!wp_doing_ajax()) {
            add_action('admin_menu', [__CLASS__, 'add_menu'], 25);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        }
        add_action('wp_ajax_tix_meta_dashboard_data', [__CLASS__, 'ajax_dashboard_data']);
        add_action('wp_ajax_tix_meta_update_adspend', [__CLASS__, 'ajax_update_adspend']);
        add_action('wp_ajax_tix_meta_generate_utm', [__CLASS__, 'ajax_generate_utm']);
        add_action('wp_ajax_tix_meta_wizard_generate', [__CLASS__, 'ajax_wizard_generate']);
    }

    public static function add_menu() {
        add_submenu_page(
            'tixomat',
            'Meta Ads',
            'Meta Ads',
            'manage_options',
            'tix-meta-ads',
            [__CLASS__, 'render']
        );
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'tixomat_page_tix-meta-ads') return;
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', [], '4.4.0', true);
        wp_enqueue_script('tix-meta-ads', TIXOMAT_URL . 'assets/js/meta-ads.js', ['jquery', 'chartjs'], TIXOMAT_VERSION, true);
        wp_enqueue_style('tix-meta-ads', TIXOMAT_URL . 'assets/css/meta-ads.css', [], TIXOMAT_VERSION);
        wp_localize_script('tix-meta-ads', 'tixMeta', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tix_admin_nonce'),
            'home'  => home_url(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       RENDER
    ═══════════════════════════════════════════════════════════════ */
    public static function render() {
        $s = tix_get_settings();
        $pixel_id = $s['meta_pixel_id'] ?? '';
        $has_token = !empty($s['meta_access_token']);
        $pixel_enabled = !empty($s['meta_pixel_enabled']);
        $capi_enabled = !empty($s['meta_capi_enabled']);
        $catalog_enabled = !empty($s['meta_catalog_enabled']);
        $feed_key = $s['meta_feed_key'] ?? '';
        $feed_url = !empty($feed_key) ? rest_url('tixomat/v1/meta-feed') . '?key=' . $feed_key : '';

        // Get all events for dropdowns
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'meta_value',
            'meta_key'       => '_tix_date_start',
            'order'          => 'DESC',
        ]);
        ?>
        <div class="wrap tix-meta-ads-wrap">
            <h1 style="display:flex;align-items:center;gap:10px">
                <span class="dashicons dashicons-facebook-alt" style="font-size:28px;width:28px;height:28px;color:#1877F2"></span>
                Meta Ads
            </h1>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper tix-meta-tabs" style="margin-bottom:20px">
                <a href="#" class="nav-tab nav-tab-active" data-tab="dashboard">Dashboard</a>
                <a href="#" class="nav-tab" data-tab="wizard">Kampagnen-Wizard</a>
                <a href="#" class="nav-tab" data-tab="utm">UTM-Links & QR</a>
                <a href="#" class="nav-tab" data-tab="setup">Setup & Anleitung</a>
            </nav>

            <!-- ═══ TAB: Dashboard ═══ -->
            <div class="tix-meta-tab active" data-tab="dashboard">

                <!-- Connection Status -->
                <div class="tix-meta-status-bar">
                    <div class="tix-meta-status-item">
                        <span class="tix-meta-dot <?php echo $pixel_enabled && $pixel_id ? 'green' : 'red'; ?>"></span>
                        Pixel <?php echo $pixel_enabled ? esc_html(substr($pixel_id, 0, 4) . '…' . substr($pixel_id, -4)) : 'Nicht konfiguriert'; ?>
                    </div>
                    <div class="tix-meta-status-item">
                        <span class="tix-meta-dot <?php echo $capi_enabled && $has_token ? 'green' : 'gray'; ?>"></span>
                        CAPI <?php echo $capi_enabled ? 'Aktiv' : 'Inaktiv'; ?>
                    </div>
                    <div class="tix-meta-status-item">
                        <span class="tix-meta-dot <?php echo $catalog_enabled ? 'green' : 'gray'; ?>"></span>
                        Katalog <?php echo $catalog_enabled ? 'Aktiv' : 'Inaktiv'; ?>
                    </div>
                    <?php if ($capi_enabled && $has_token): ?>
                    <button type="button" class="button" id="tix-meta-test-btn">
                        <span class="dashicons dashicons-admin-plugins" style="margin-top:4px"></span>
                        Test-Event senden
                    </button>
                    <?php endif; ?>
                    <a href="<?php echo admin_url('admin.php?page=tix-settings'); ?>" class="button" style="margin-left:auto">
                        <span class="dashicons dashicons-admin-generic" style="margin-top:4px"></span>
                        Einstellungen
                    </a>
                </div>

                <!-- KPI Cards -->
                <div class="tix-meta-kpis" id="tix-meta-kpis">
                    <div class="tix-meta-kpi">
                        <div class="tix-meta-kpi-label">Ad-attributed Umsatz</div>
                        <div class="tix-meta-kpi-value" id="kpi-revenue">—</div>
                    </div>
                    <div class="tix-meta-kpi">
                        <div class="tix-meta-kpi-label">Bestellungen</div>
                        <div class="tix-meta-kpi-value" id="kpi-orders">—</div>
                    </div>
                    <div class="tix-meta-kpi">
                        <div class="tix-meta-kpi-label">Ø Bestellwert</div>
                        <div class="tix-meta-kpi-value" id="kpi-aov">—</div>
                    </div>
                    <div class="tix-meta-kpi">
                        <div class="tix-meta-kpi-label">ROAS</div>
                        <div class="tix-meta-kpi-value" id="kpi-roas">—</div>
                    </div>
                </div>

                <!-- Chart -->
                <div class="tix-meta-card">
                    <h3>Umsatz über Zeit</h3>
                    <div class="tix-meta-chart-filters">
                        <select id="tix-meta-period">
                            <option value="7">Letzte 7 Tage</option>
                            <option value="30" selected>Letzte 30 Tage</option>
                            <option value="90">Letzte 90 Tage</option>
                        </select>
                        <select id="tix-meta-event-filter">
                            <option value="">Alle Events</option>
                            <?php foreach ($events as $ev): ?>
                            <option value="<?php echo $ev->ID; ?>"><?php echo esc_html(get_the_title($ev->ID)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <canvas id="tix-meta-chart" height="300"></canvas>
                </div>

                <!-- Per-Event Breakdown -->
                <div class="tix-meta-card">
                    <h3>Performance pro Event</h3>
                    <table class="widefat tix-meta-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Conversions</th>
                                <th>Umsatz</th>
                                <th>Ad Spend</th>
                                <th>ROAS</th>
                            </tr>
                        </thead>
                        <tbody id="tix-meta-event-rows">
                            <tr><td colspan="5" style="text-align:center;padding:20px">Daten werden geladen…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ═══ TAB: Kampagnen-Wizard ═══ -->
            <div class="tix-meta-tab" data-tab="wizard" style="display:none">

                <div class="tix-meta-card">
                    <h3>Event auswählen</h3>
                    <select id="tix-wizard-event" class="regular-text" style="min-width:300px">
                        <option value="">— Event wählen —</option>
                        <?php foreach ($events as $ev):
                            $ds = get_post_meta($ev->ID, '_tix_date_start', true);
                            $loc = get_post_meta($ev->ID, '_tix_location', true);
                        ?>
                        <option value="<?php echo $ev->ID; ?>"
                                data-title="<?php echo esc_attr(get_the_title($ev->ID)); ?>"
                                data-date="<?php echo esc_attr($ds); ?>"
                                data-location="<?php echo esc_attr($loc); ?>"
                                data-price="<?php echo esc_attr(get_post_meta($ev->ID, '_tix_price_min', true)); ?>"
                                data-url="<?php echo esc_attr(get_permalink($ev->ID)); ?>"
                                data-image="<?php echo esc_attr(get_the_post_thumbnail_url($ev->ID, 'large')); ?>">
                            <?php echo esc_html(get_the_title($ev->ID)); ?> (<?php echo esc_html($ds); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Campaign Templates -->
                <div class="tix-meta-templates" id="tix-wizard-templates" style="display:none">
                    <div class="tix-meta-template-grid">

                        <div class="tix-meta-template" data-template="earlybird">
                            <div class="tix-meta-template-icon">🐦</div>
                            <h4>Frühbucher-Push</h4>
                            <p>Bewirb Early-Bird-Tickets mit Dringlichkeit</p>
                        </div>

                        <div class="tix-meta-template" data-template="lasttickets">
                            <div class="tix-meta-template-icon">🔥</div>
                            <h4>Letzte Tickets</h4>
                            <p>Scarcity-Kampagne für fast ausverkaufte Events</p>
                        </div>

                        <div class="tix-meta-template" data-template="retargeting">
                            <div class="tix-meta-template-icon">🎯</div>
                            <h4>Retargeting</h4>
                            <p>Erreiche Besucher die sich das Event angesehen haben</p>
                        </div>

                        <div class="tix-meta-template" data-template="awareness">
                            <div class="tix-meta-template-icon">📢</div>
                            <h4>Neue Veranstaltung</h4>
                            <p>Reichweiten-Kampagne für maximale Sichtbarkeit</p>
                        </div>

                    </div>
                </div>

                <!-- Generated Campaign Content -->
                <div class="tix-meta-card" id="tix-wizard-result" style="display:none">
                    <h3 id="tix-wizard-result-title">Kampagne</h3>

                    <div class="tix-meta-result-grid">
                        <div class="tix-meta-result-section">
                            <label>Haupttext (Primary Text)</label>
                            <textarea id="tix-wizard-primary" rows="4" class="large-text" readonly></textarea>
                            <button type="button" class="button tix-copy-btn" data-target="tix-wizard-primary">Kopieren</button>
                        </div>
                        <div class="tix-meta-result-section">
                            <label>Headline</label>
                            <input type="text" id="tix-wizard-headline" class="regular-text" readonly>
                            <button type="button" class="button tix-copy-btn" data-target="tix-wizard-headline">Kopieren</button>
                        </div>
                        <div class="tix-meta-result-section">
                            <label>Beschreibung</label>
                            <input type="text" id="tix-wizard-description" class="regular-text" readonly>
                            <button type="button" class="button tix-copy-btn" data-target="tix-wizard-description">Kopieren</button>
                        </div>
                        <div class="tix-meta-result-section">
                            <label>Tracking-Link</label>
                            <input type="text" id="tix-wizard-link" class="regular-text" readonly>
                            <button type="button" class="button tix-copy-btn" data-target="tix-wizard-link">Kopieren</button>
                        </div>
                    </div>

                    <div class="tix-meta-result-grid" style="margin-top:20px">
                        <div class="tix-meta-result-section">
                            <h4>💰 Budget-Empfehlung</h4>
                            <p id="tix-wizard-budget"></p>
                        </div>
                        <div class="tix-meta-result-section">
                            <h4>👥 Zielgruppe</h4>
                            <div id="tix-wizard-audience"></div>
                        </div>
                        <div class="tix-meta-result-section">
                            <h4>📋 Schritt-für-Schritt</h4>
                            <ol id="tix-wizard-steps"></ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: UTM-Links & QR ═══ -->
            <div class="tix-meta-tab" data-tab="utm" style="display:none">

                <div class="tix-meta-card">
                    <h3>UTM-Link Generator</h3>
                    <div class="tix-meta-utm-form">
                        <div class="tix-meta-field">
                            <label>Event</label>
                            <select id="tix-utm-event" class="regular-text">
                                <option value="">— Event wählen —</option>
                                <?php foreach ($events as $ev): ?>
                                <option value="<?php echo esc_attr(get_permalink($ev->ID)); ?>">
                                    <?php echo esc_html(get_the_title($ev->ID)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tix-meta-field">
                            <label>Source</label>
                            <select id="tix-utm-source">
                                <option value="facebook">Facebook</option>
                                <option value="instagram">Instagram</option>
                                <option value="meta">Meta</option>
                                <option value="newsletter">Newsletter</option>
                                <option value="flyer">Flyer</option>
                                <option value="poster">Poster</option>
                            </select>
                        </div>
                        <div class="tix-meta-field">
                            <label>Medium</label>
                            <select id="tix-utm-medium">
                                <option value="paid">Paid</option>
                                <option value="social">Social (Organisch)</option>
                                <option value="email">E-Mail</option>
                                <option value="print">Print</option>
                                <option value="qr">QR-Code</option>
                            </select>
                        </div>
                        <div class="tix-meta-field">
                            <label>Campaign</label>
                            <input type="text" id="tix-utm-campaign" class="regular-text" placeholder="z.B. earlybird_april">
                        </div>
                        <div class="tix-meta-field">
                            <label>Content (optional)</label>
                            <input type="text" id="tix-utm-content" class="regular-text" placeholder="z.B. variante_a">
                        </div>
                        <button type="button" class="button button-primary" id="tix-utm-generate">
                            Link generieren
                        </button>
                    </div>
                </div>

                <!-- Generated Link + QR -->
                <div class="tix-meta-card" id="tix-utm-result" style="display:none">
                    <h3>Generierter Link</h3>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:16px">
                        <input type="text" id="tix-utm-url" class="large-text" readonly>
                        <button type="button" class="button tix-copy-btn" data-target="tix-utm-url">Kopieren</button>
                    </div>

                    <h3>QR-Code</h3>
                    <p>Für Poster, Flyer und andere Print-Materialien.</p>
                    <div id="tix-utm-qr" style="margin:16px 0"></div>
                    <button type="button" class="button" id="tix-utm-qr-download">
                        <span class="dashicons dashicons-download" style="margin-top:4px"></span>
                        QR-Code herunterladen (PNG)
                    </button>
                </div>

                <!-- Link History -->
                <div class="tix-meta-card">
                    <h3>Letzte Links</h3>
                    <table class="widefat" id="tix-utm-history">
                        <thead>
                            <tr><th>Link</th><th>Source</th><th>Medium</th><th>Campaign</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="5" style="text-align:center;color:#999">Noch keine Links generiert</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ═══ TAB: Setup & Anleitung ═══ -->
            <div class="tix-meta-tab" data-tab="setup" style="display:none">

                <div class="tix-meta-card">
                    <h3>1. Meta Pixel einrichten</h3>
                    <ol class="tix-meta-steps">
                        <li>Öffne den <a href="https://business.facebook.com/events_manager" target="_blank">Meta Events Manager</a></li>
                        <li>Klicke auf <strong>"Datenquellen verbinden"</strong> → <strong>"Web"</strong></li>
                        <li>Wähle <strong>"Meta Pixel"</strong> und vergib einen Namen (z.B. "Tixomat")</li>
                        <li>Kopiere die <strong>Pixel-ID</strong> (16-stellige Zahl)</li>
                        <li>Füge sie unter <a href="<?php echo admin_url('admin.php?page=tix-settings'); ?>">Einstellungen → Meta Ads</a> ein</li>
                        <li>Tixomat trackt automatisch: PageView, ViewContent, AddToCart, InitiateCheckout, Purchase</li>
                    </ol>
                </div>

                <div class="tix-meta-card">
                    <h3>2. Conversions API (CAPI) aktivieren</h3>
                    <ol class="tix-meta-steps">
                        <li>Im Events Manager → Einstellungen → <strong>"Conversions API"</strong></li>
                        <li>Klicke auf <strong>"Access Token generieren"</strong></li>
                        <li>Kopiere den Token und füge ihn in den Einstellungen ein</li>
                        <li>Optional: Unter "Test-Events" findest du einen <strong>Test Event Code</strong> zum Testen</li>
                        <li>CAPI sendet Purchase-Events serverseitig — funktioniert auch bei Ad-Blockern</li>
                    </ol>
                </div>

                <div class="tix-meta-card">
                    <h3>3. Event-Katalog für Dynamic Ads</h3>
                    <ol class="tix-meta-steps">
                        <li>Öffne den <a href="https://business.facebook.com/commerce" target="_blank">Meta Commerce Manager</a></li>
                        <li>Erstelle einen neuen <strong>Katalog</strong> (Typ: "E-Commerce")</li>
                        <li>Wähle als Datenquelle <strong>"Datenfeed"</strong></li>
                        <li>Füge diese Feed-URL ein:</li>
                        <?php if ($feed_url): ?>
                        <li>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input type="text" value="<?php echo esc_url($feed_url); ?>" class="regular-text" readonly id="tix-setup-feed-url">
                                <button type="button" class="button tix-copy-btn" data-target="tix-setup-feed-url">Kopieren</button>
                            </div>
                        </li>
                        <?php else: ?>
                        <li><em>Aktiviere den Katalog-Feed zuerst in den <a href="<?php echo admin_url('admin.php?page=tix-settings'); ?>">Einstellungen</a>.</em></li>
                        <?php endif; ?>
                        <li>Stelle den Abrufintervall auf <strong>"Täglich"</strong></li>
                        <li>Deine Events werden automatisch als Produkte im Katalog angezeigt</li>
                    </ol>
                </div>

                <div class="tix-meta-card">
                    <h3>4. Erste Kampagne erstellen</h3>
                    <ol class="tix-meta-steps">
                        <li>Wechsle zum <strong>"Kampagnen-Wizard"</strong> Tab oben</li>
                        <li>Wähle ein Event und ein Kampagnen-Template</li>
                        <li>Kopiere die generierten Texte in den <a href="https://adsmanager.facebook.com" target="_blank">Meta Ads Manager</a></li>
                        <li>Nutze den <strong>Tracking-Link</strong> als Ziel-URL deiner Anzeige</li>
                        <li>Prüfe die Performance hier im Dashboard</li>
                    </ol>
                </div>

            </div>

        </div>
        <?php
    }

    /* ═══════════════════════════════════════════════════════════════
       AJAX HANDLERS
    ═══════════════════════════════════════════════════════════════ */

    public static function ajax_dashboard_data() {
        check_ajax_referer('tix_admin_nonce', 'nonce');
        global $wpdb;
        $table = $wpdb->prefix . 'tix_meta_conversions';

        $days = intval($_POST['days'] ?? 30);
        $event_id = intval($_POST['event_id'] ?? 0);
        $date_from = gmdate('Y-m-d', strtotime("-{$days} days"));

        $where = $wpdb->prepare("WHERE created_at >= %s AND meta_event_name = 'Purchase'", $date_from);
        if ($event_id) $where .= $wpdb->prepare(" AND event_post_id = %d", $event_id);

        // KPIs
        $kpis = $wpdb->get_row("SELECT COUNT(DISTINCT order_id) as orders, SUM(value) as revenue FROM $table $where");

        $orders = intval($kpis->orders ?? 0);
        $revenue = floatval($kpis->revenue ?? 0);
        $aov = $orders > 0 ? $revenue / $orders : 0;

        // Ad spend (manual entries stored in options)
        $adspend_data = get_option('tix_meta_adspend', []);
        $total_spend = 0;
        foreach ($adspend_data as $eid => $spend) {
            if (!$event_id || $eid == $event_id) $total_spend += floatval($spend);
        }
        $roas = $total_spend > 0 ? $revenue / $total_spend : 0;

        // Chart data: revenue per day
        $chart = $wpdb->get_results("SELECT DATE(created_at) as day, SUM(value) as rev, COUNT(DISTINCT order_id) as cnt FROM $table $where GROUP BY DATE(created_at) ORDER BY day ASC");

        $chart_labels = [];
        $chart_revenue = [];
        $chart_orders = [];
        foreach ($chart as $row) {
            $chart_labels[] = date_i18n('d.m.', strtotime($row->day));
            $chart_revenue[] = floatval($row->rev);
            $chart_orders[] = intval($row->cnt);
        }

        // Per-event breakdown
        $event_rows = $wpdb->get_results("SELECT event_post_id, COUNT(DISTINCT order_id) as conversions, SUM(value) as revenue FROM $table WHERE created_at >= '$date_from' AND meta_event_name = 'Purchase' GROUP BY event_post_id ORDER BY revenue DESC");

        $event_data = [];
        foreach ($event_rows as $row) {
            $eid = intval($row->event_post_id);
            $spend = floatval($adspend_data[$eid] ?? 0);
            $event_data[] = [
                'id'          => $eid,
                'title'       => $eid ? get_the_title($eid) : 'Unbekannt',
                'conversions' => intval($row->conversions),
                'revenue'     => floatval($row->revenue),
                'spend'       => $spend,
                'roas'        => $spend > 0 ? round(floatval($row->revenue) / $spend, 2) : 0,
            ];
        }

        wp_send_json_success([
            'kpis' => [
                'revenue' => number_format($revenue, 2, ',', '.') . ' €',
                'orders'  => $orders,
                'aov'     => number_format($aov, 2, ',', '.') . ' €',
                'roas'    => $total_spend > 0 ? number_format($roas, 1, ',', '.') . 'x' : '—',
            ],
            'chart' => [
                'labels'  => $chart_labels,
                'revenue' => $chart_revenue,
                'orders'  => $chart_orders,
            ],
            'events' => $event_data,
        ]);
    }

    public static function ajax_update_adspend() {
        check_ajax_referer('tix_admin_nonce', 'nonce');
        $event_id = intval($_POST['event_id'] ?? 0);
        $spend = floatval($_POST['spend'] ?? 0);
        if (!$event_id) wp_send_json_error();

        $data = get_option('tix_meta_adspend', []);
        $data[$event_id] = $spend;
        update_option('tix_meta_adspend', $data);
        wp_send_json_success();
    }

    public static function ajax_generate_utm() {
        check_ajax_referer('tix_admin_nonce', 'nonce');

        $base = esc_url_raw($_POST['base_url'] ?? '');
        $source = sanitize_text_field($_POST['source'] ?? 'meta');
        $medium = sanitize_text_field($_POST['medium'] ?? 'paid');
        $campaign = sanitize_text_field($_POST['campaign'] ?? '');
        $content = sanitize_text_field($_POST['content'] ?? '');

        if (empty($base)) wp_send_json_error(['message' => 'Kein Event gewählt.']);

        $params = [
            'utm_source'   => $source,
            'utm_medium'   => $medium,
            'utm_campaign' => $campaign,
        ];
        if ($content) $params['utm_content'] = $content;

        $url = add_query_arg($params, $base);

        wp_send_json_success(['url' => $url]);
    }

    public static function ajax_wizard_generate() {
        check_ajax_referer('tix_admin_nonce', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        $template = sanitize_text_field($_POST['template'] ?? '');

        if (!$event_id || !$template) wp_send_json_error();

        $title    = get_the_title($event_id);
        $date     = get_post_meta($event_id, '_tix_date_start', true);
        $time     = get_post_meta($event_id, '_tix_time_start', true);
        $location = get_post_meta($event_id, '_tix_location', true);
        $price    = get_post_meta($event_id, '_tix_price_min', true);
        $url      = get_permalink($event_id);
        $desc     = wp_strip_all_tags(get_post_meta($event_id, '_tix_info_description', true));

        // Format date
        $date_formatted = '';
        if ($date) {
            $ts = strtotime($date);
            $date_formatted = date_i18n('l, j. F Y', $ts);
        }

        $slug = sanitize_title($title);
        $utm_campaign = sanitize_title($template) . '_' . $slug;
        $tracking_link = add_query_arg([
            'utm_source'   => 'meta',
            'utm_medium'   => 'paid',
            'utm_campaign' => $utm_campaign,
        ], $url);

        $price_text = $price ? number_format(floatval($price), 2, ',', '.') . ' €' : '';

        $result = self::generate_template($template, [
            'title'         => $title,
            'date'          => $date,
            'date_formatted'=> $date_formatted,
            'time'          => $time,
            'location'      => $location,
            'price'         => $price_text,
            'price_raw'     => floatval($price),
            'url'           => $url,
            'tracking_link' => $tracking_link,
            'description'   => mb_substr($desc, 0, 200),
            'utm_campaign'  => $utm_campaign,
        ]);

        wp_send_json_success($result);
    }

    private static function generate_template($type, $d) {
        $templates = [
            'earlybird' => [
                'title'       => '🐦 Frühbucher-Push',
                'primary'     => "Sei schnell — Frühbucher-Tickets für {$d['title']} sind begrenzt!\n\n📅 {$d['date_formatted']}\n📍 {$d['location']}\n💰 Ab nur {$d['price']}\n\nSichere dir jetzt dein Ticket zum Frühbucher-Preis, bevor die Preise steigen!\n\n👉 {$d['tracking_link']}",
                'headline'    => "Frühbucher-Tickets ab {$d['price']}",
                'description' => "{$d['title']} — {$d['date_formatted']} in {$d['location']}",
                'budget'      => $d['price_raw'] > 0
                    ? 'Empfehlung: ' . number_format($d['price_raw'] * 10 * 0.10, 0, ',', '.') . ' € (10% des Ziel-Umsatzes bei 10 Tickets)'
                    : 'Starte mit 5-10 € Tagesbudget und skaliere nach Performance.',
                'audience'    => [
                    'Alter: 18-45 (anpassen je nach Event)',
                    'Standort: 30-50 km um ' . ($d['location'] ?: 'den Veranstaltungsort'),
                    'Interessen: Nachtleben, Konzerte, Festivals, Live-Events',
                    'Lookalike: Basierend auf bisherigen Ticketkäufern (wenn vorhanden)',
                ],
                'steps'       => [
                    'Öffne den Meta Ads Manager → "Erstellen"',
                    'Kampagnenziel: "Conversions" oder "Umsatz"',
                    'Pixel als Conversion-Event auswählen → "Purchase"',
                    'Zielgruppe wie oben vorgeschlagen einstellen',
                    'Platzierung: Instagram Feed + Stories + Facebook Feed',
                    'Bild: Event-Bild oder ansprechendes Visual hochladen',
                    'Texte oben einfügen (Haupttext, Headline, Beschreibung)',
                    'Ziel-URL: Den Tracking-Link oben einfügen',
                    'Budget: Tagesbudget wie empfohlen einstellen',
                    'Kampagne starten und nach 3 Tagen optimieren',
                ],
            ],
            'lasttickets' => [
                'title'       => '🔥 Letzte Tickets',
                'primary'     => "⚠️ Nur noch wenige Tickets verfügbar!\n\n{$d['title']} am {$d['date_formatted']} in {$d['location']} ist fast ausverkauft.\n\nDas willst du nicht verpassen — sichere dir jetzt eines der letzten Tickets!\n\n🎟️ {$d['tracking_link']}",
                'headline'    => "Fast ausverkauft — letzte Chance!",
                'description' => "Nur noch wenige Tickets für {$d['title']}",
                'budget'      => 'Empfehlung: 10-20 € Tagesbudget für 3-5 Tage. Scarcity-Kampagnen performen am besten mit kurzer Laufzeit und höherem Budget.',
                'audience'    => [
                    'Custom Audience: Website-Besucher der Event-Seite (letzte 14 Tage)',
                    'Custom Audience: Warenkorb-Abbrecher',
                    'Standort: 30-50 km um ' . ($d['location'] ?: 'den Veranstaltungsort'),
                    'Ausschließen: Bestehende Käufer (Custom Audience aus Käuferliste)',
                ],
                'steps'       => [
                    'Öffne den Meta Ads Manager → "Erstellen"',
                    'Kampagnenziel: "Conversions" oder "Umsatz"',
                    'Custom Audience erstellen: Events Manager → Zielgruppen → "Website-Traffic" → URL enthält Event-Slug',
                    'Diese Audience als Zielgruppe verwenden',
                    'Platzierung: Instagram + Facebook, Stories bevorzugt',
                    'Texte oben einfügen',
                    'Kurze Laufzeit: 3-5 Tage',
                    'Budget: Höher als normal (Urgency!)',
                ],
            ],
            'retargeting' => [
                'title'       => '🎯 Retargeting',
                'primary'     => "Hey, du hast dir {$d['title']} angesehen — hast du schon dein Ticket?\n\n📅 {$d['date_formatted']}\n📍 {$d['location']}\n\nDie besten Events verpasst man nicht — sichere dir jetzt dein Ticket!\n\n🎟️ {$d['tracking_link']}",
                'headline'    => "Hast du schon dein Ticket?",
                'description' => "{$d['title']} — Tickets sichern bevor es zu spät ist",
                'budget'      => 'Empfehlung: 3-5 € Tagesbudget. Retargeting-Audiences sind klein aber hochqualifiziert — niedrigere Kosten pro Conversion.',
                'audience'    => [
                    'Custom Audience: Website-Besucher der Event-Seite (letzte 30 Tage)',
                    'Ausschließen: Käufer (wer bereits gekauft hat)',
                    'Custom Audience: "ViewContent" Pixel-Event der letzten 14 Tage',
                ],
                'steps'       => [
                    'Im Events Manager → Zielgruppen → "Erstellen" → "Custom Audience"',
                    'Quelle: "Website" → Pixel auswählen',
                    'Event: "ViewContent" in den letzten 14-30 Tagen',
                    'Optional: URL enthält Event-Slug (für spezifisches Event)',
                    'Zweite Audience: Käufer ausschließen (Event: "Purchase")',
                    'Im Ads Manager: Kampagne erstellen → diese Audiences nutzen',
                    'Texte oben einfügen',
                    'Niedriges Budget, hohe Relevanz',
                ],
            ],
            'awareness' => [
                'title'       => '📢 Neue Veranstaltung',
                'primary'     => "🎉 NEU: {$d['title']}!\n\n📅 {$d['date_formatted']}\n📍 {$d['location']}\n💰 Tickets ab {$d['price']}\n\n{$d['description']}\n\n🎟️ Jetzt Tickets sichern:\n{$d['tracking_link']}",
                'headline'    => $d['title'],
                'description' => "{$d['date_formatted']} in {$d['location']} — Tickets ab {$d['price']}",
                'budget'      => $d['price_raw'] > 0
                    ? 'Empfehlung: ' . number_format($d['price_raw'] * 50 * 0.05, 0, ',', '.') . ' € Gesamtbudget (5% des Ziel-Umsatzes bei 50 Tickets). Starte mit 5-10 €/Tag.'
                    : 'Starte mit 5-10 € Tagesbudget. Skaliere nach 3-5 Tagen basierend auf Performance.',
                'audience'    => [
                    'Alter: 18-45 (anpassen je nach Event-Typ)',
                    'Standort: 30-80 km um ' . ($d['location'] ?: 'den Veranstaltungsort'),
                    'Interessen: Events, Nachtleben, Konzerte, Festivals, Partys',
                    'Lookalike Audience: Basierend auf bisherigen Käufern (beste Option!)',
                    'Advantage+ Audience: Meta optimiert automatisch (gut für Reichweite)',
                ],
                'steps'       => [
                    'Öffne den Meta Ads Manager → "Erstellen"',
                    'Kampagnenziel: "Bekanntheit" oder "Traffic" für neue Events',
                    'Broad Targeting: Standort + Alter + Interessen',
                    'Optional: Lookalike Audience erstellen (basierend auf Käufer-Custom-Audience)',
                    'Platzierung: Automatic (Meta optimiert) oder manuell Instagram + Facebook Feed/Stories/Reels',
                    'Bild: Event-Bild in 1080x1080 (Feed) oder 1080x1920 (Stories)',
                    'Texte oben einfügen',
                    'Budget: 5-10 €/Tag für 7-14 Tage',
                    'Nach 3 Tagen: Performance prüfen, schlechte Ads pausieren',
                ],
            ],
        ];

        return $templates[$type] ?? $templates['awareness'];
    }
}
