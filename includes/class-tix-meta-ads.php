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

                    <!-- Varianten-Tabs -->
                    <div class="tix-variant-tabs" id="tix-variant-tabs" style="margin-bottom:16px"></div>

                    <!-- Strategie-Erklärung -->
                    <div class="tix-meta-strategy" id="tix-wizard-strategy" style="background:#f0f7ff;border:1px solid #bfdbfe;border-radius:8px;padding:16px;margin-bottom:20px"></div>

                    <div class="tix-meta-result-grid">
                        <div class="tix-meta-result-section">
                            <label>Haupttext (Primary Text)</label>
                            <textarea id="tix-wizard-primary" rows="6" class="large-text" readonly></textarea>
                            <div style="display:flex;gap:8px;align-items:center;margin-top:4px">
                                <button type="button" class="button tix-copy-btn" data-target="tix-wizard-primary">Kopieren</button>
                                <span class="tix-meta-rationale" id="tix-rationale-primary" style="font-size:12px;color:#6b7280;font-style:italic"></span>
                            </div>
                        </div>
                        <div class="tix-meta-result-section">
                            <label>Headline</label>
                            <input type="text" id="tix-wizard-headline" class="regular-text" readonly>
                            <div style="display:flex;gap:8px;align-items:center;margin-top:4px">
                                <button type="button" class="button tix-copy-btn" data-target="tix-wizard-headline">Kopieren</button>
                                <span class="tix-meta-rationale" id="tix-rationale-headline" style="font-size:12px;color:#6b7280;font-style:italic"></span>
                            </div>
                        </div>
                        <div class="tix-meta-result-section">
                            <label>Beschreibung (Link Description)</label>
                            <input type="text" id="tix-wizard-description" class="regular-text" readonly>
                            <button type="button" class="button tix-copy-btn" data-target="tix-wizard-description" style="margin-top:4px">Kopieren</button>
                        </div>
                        <div class="tix-meta-result-section">
                            <label>Tracking-Link</label>
                            <input type="text" id="tix-wizard-link" class="regular-text" readonly>
                            <button type="button" class="button tix-copy-btn" data-target="tix-wizard-link" style="margin-top:4px">Kopieren</button>
                        </div>
                    </div>

                    <div class="tix-meta-result-grid" style="margin-top:20px">
                        <div class="tix-meta-result-section">
                            <h4>💰 Budget-Empfehlung</h4>
                            <div id="tix-wizard-budget"></div>
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

        $result['tracking_link'] = $tracking_link;
        wp_send_json_success($result);
    }

    /**
     * Generate campaign template with multiple variants + psychological rationale
     */
    private static function generate_template($type, $d) {
        $loc = $d['location'] ?: 'deiner Stadt';
        $link = $d['tracking_link'];

        // Days until event
        $days_until = '';
        if ($d['date']) {
            $diff = (strtotime($d['date']) - time()) / 86400;
            if ($diff > 0 && $diff <= 7)       $days_until = 'diese Woche';
            elseif ($diff > 7 && $diff <= 14)   $days_until = 'in ' . ceil($diff) . ' Tagen';
            elseif ($diff > 14 && $diff <= 30)  $days_until = 'in ' . ceil($diff / 7) . ' Wochen';
            elseif ($diff > 30)                 $days_until = 'am ' . $d['date_formatted'];
        }

        $templates = [

            /* ══════════════════════════════════════════
               FRÜHBUCHER-PUSH
               Psychologie: Verlustaversion + Exklusivität
            ══════════════════════════════════════════ */
            'earlybird' => [
                'title' => '🐦 Frühbucher-Push',
                'strategy' => '<strong>Strategie: Verlustaversion + Belohnungsgefühl</strong><br>Menschen reagieren stärker auf den Gedanken, einen Vorteil zu verlieren, als auf den Gewinn selbst (Kahneman, Prospect Theory). Der Frühbucher-Preis wird als Belohnung für schnelle Entscheidung geframed — nicht als Rabatt, sondern als exklusiver Vorteil. Die Deadline erzeugt natürliche Dringlichkeit ohne aggressive Countdown-Taktiken.',
                'variants' => [
                    [
                        'name' => 'A: Exklusivitäts-Hook',
                        'primary' => "{$d['title']} {$days_until} — und du kannst dir gerade noch den Frühbucher-Preis sichern.\n\nAb {$d['price']} statt zum regulären Preis. Das ist der Vorteil, wenn man früh dran ist.\n\n📅 {$d['date_formatted']}\n📍 {$loc}\n\nDer Preis steigt bald. Wer jetzt bucht, spart.\n{$link}",
                        'headline' => "Früh dran sein lohnt sich — ab {$d['price']}",
                        'description' => "{$d['title']} · {$d['date_formatted']} · {$loc}",
                        'rationale_primary' => 'Kein Emoji-Spam, keine Ausrufezeichen. Ruhiger, selbstbewusster Ton wirkt bei kalten Audiences glaubwürdiger als aggressive Dringlichkeit.',
                        'rationale_headline' => 'Belohnungs-Framing ("lohnt sich") statt Verlust-Framing. Erzeugt positive Assoziation mit der Kaufentscheidung.',
                    ],
                    [
                        'name' => 'B: Social-Proof-Hook',
                        'primary' => "Die ersten Tickets für {$d['title']} gehen gerade weg.\n\nWer sich den Frühbucher-Preis ab {$d['price']} sichern will, sollte nicht zu lange warten — die Aktion gilt nur begrenzt.\n\n📅 {$d['date_formatted']}\n📍 {$loc}\n\n{$link}",
                        'headline' => "Andere sind schon dabei",
                        'description' => "Frühbucher ab {$d['price']} · {$d['title']}",
                        'rationale_primary' => 'Social Proof ("gehen gerade weg") nutzt Herdenverhalten. Menschen folgen der Masse — wenn andere kaufen, muss es gut sein.',
                        'rationale_headline' => 'Kurze Headlines mit Social-Proof-Element performen 23% besser als reine Preis-Headlines (Meta Best Practices).',
                    ],
                    [
                        'name' => 'C: Direkt & Knapp',
                        'primary' => "{$d['title']}\n{$d['date_formatted']} · {$loc}\n\nFrühbucher-Tickets ab {$d['price']}.\nDer Preis steigt nach der Frühbucher-Phase.\n\n{$link}",
                        'headline' => "Ab {$d['price']} · Frühbucher",
                        'description' => "{$d['date_formatted']} in {$loc}",
                        'rationale_primary' => 'Minimal-Copy funktioniert besonders gut auf Instagram Stories und Reels. Das Bild macht die Arbeit — der Text liefert nur die Facts.',
                        'rationale_headline' => 'Preis + ein Wort. Maximale Klarheit. Gut für mobile Feeds wo nur 2 Zeilen sichtbar sind.',
                    ],
                ],
                'budget' => $d['price_raw'] > 0
                    ? '<strong>' . number_format($d['price_raw'] * 15 * 0.08, 0, ',', '.') . '–' . number_format($d['price_raw'] * 15 * 0.12, 0, ',', '.') . ' € Gesamtbudget</strong> (8-12% des Ziel-Umsatzes bei 15 Frühbucher-Tickets).<br><br><strong>Warum?</strong> CPM (Kosten pro 1000 Impressions) liegt in DE bei 5-15 €. Bei einer Conversion Rate von 1-3% brauchst du ~500-1500 Impressions pro Ticket. Starte mit 7-10 €/Tag und skaliere, wenn der CPA (Cost per Acquisition) unter 20% des Ticketpreises liegt.'
                    : 'Starte mit <strong>7-10 €/Tag</strong>. Nach 3 Tagen hast du genug Daten um zu entscheiden: CPA unter 20% des Ticketpreises = skalieren, darüber = Zielgruppe/Creative anpassen.',
                'audience' => [
                    '<strong>Primär:</strong> Lookalike 1% basierend auf bisherigen Käufern — das ist die stärkste Audience die du haben kannst',
                    '<strong>Alternativ:</strong> Interessen-Targeting: Events + Nachtleben + lokale Interessen (z.B. Stadt-Hashtags)',
                    '<strong>Standort:</strong> 25-40 km um ' . $loc . ' — zu weit = zu hohe Streuverluste',
                    '<strong>Alter:</strong> 18-40 (Frühbucher sind typischerweise Planende, eher 25-35)',
                    '<strong>Ausschließen:</strong> Bestehende Käufer und Website-Besucher der letzten 7 Tage (die kennen das Event schon)',
                ],
                'steps' => [
                    'Meta Ads Manager → "Erstellen" → Kampagnenziel: <strong>"Umsatz"</strong> (nicht Reichweite!)',
                    'Conversion-Event: "Purchase" (über deinen Tixomat-Pixel)',
                    'Anzeigengruppe: Zielgruppe wie oben, <strong>Advantage+ Placements</strong> (Meta optimiert wo es am besten performt)',
                    'Mindestens <strong>2 der 3 Varianten gleichzeitig</strong> schalten (A/B-Test!)',
                    'Für jede Variante: 1080x1080 Bild (Feed) PLUS 1080x1920 (Stories) — gleiches Motiv, anderes Format',
                    'CTA-Button: "Tickets kaufen" oder "Jetzt buchen"',
                    'Tracking-Link als Ziel-URL einfügen',
                    'Nach 72h: Variante mit besserem CPA behalten, andere pausieren',
                    'Budget der Gewinner-Ad um 20% erhöhen — nicht mehr, sonst verliert Meta die Optimierung',
                ],
            ],

            /* ══════════════════════════════════════════
               LETZTE TICKETS
               Psychologie: Scarcity + Regret Aversion
            ══════════════════════════════════════════ */
            'lasttickets' => [
                'title' => '🔥 Letzte Tickets',
                'strategy' => '<strong>Strategie: Knappheit + Reue-Vermeidung</strong><br>Scarcity ist einer der stärksten psychologischen Trigger (Cialdini). Wenn etwas bald weg ist, steigt der wahrgenommene Wert. Kombiniert mit Reue-Vermeidung ("stell dir vor, du verpasst es") entsteht ein starker Handlungsdruck. Wichtig: Nur einsetzen wenn wirklich knapp — falsche Scarcity zerstört Vertrauen.',
                'variants' => [
                    [
                        'name' => 'A: Reue-Vermeidung',
                        'primary' => "Stell dir vor, alle reden am Montag über {$d['title']} — und du warst nicht dabei.\n\n{$d['date_formatted']} in {$loc}. Die letzten Tickets sind jetzt verfügbar.\n\nDas ist einer dieser Momente wo \"ich überleg mir das noch\" heißt: zu spät.\n\n{$link}",
                        'headline' => "Die letzten Tickets. Kein Witz.",
                        'description' => "{$d['title']} · Fast ausverkauft",
                        'rationale_primary' => 'Regret Aversion: Menschen vermeiden Reue stärker als sie Gewinne anstreben. Das "alle reden darüber"-Szenario aktiviert sowohl FOMO als auch Social Proof gleichzeitig.',
                        'rationale_headline' => '"Kein Witz" bricht das Pattern generischer Headlines. Authentizität schlägt Marketing-Sprache.',
                    ],
                    [
                        'name' => 'B: Faktisch & Dringlich',
                        'primary' => "{$d['title']} — {$d['date_formatted']}\n\nFakt: Die Tickets sind fast ausverkauft.\nFakt: Es wird keine Abendkasse geben.\nFakt: Wer jetzt nicht bucht, ist zu spät.\n\n{$link}",
                        'headline' => "Fast ausverkauft",
                        'description' => "Letzte Tickets für {$d['date_formatted']} in {$loc}",
                        'rationale_primary' => 'Die Fakt-Fakt-Fakt-Struktur ist ein rhetorisches Stilmittel (Tricolon). Jeder Fakt eskaliert die Dringlichkeit. Kein Emoji, kein Fluff — pure Information schafft Glaubwürdigkeit.',
                        'rationale_headline' => 'Zwei Worte. Maximale Dringlichkeit. Funktioniert besonders gut als Text-Overlay auf einem stimmungsvollen Event-Bild.',
                    ],
                    [
                        'name' => 'C: Countdown',
                        'primary' => "{$d['title']} ist in {$days_until}.\n\nWas du wissen musst:\n→ Die Tickets gehen gerade schnell weg\n→ Wenn sie weg sind, sind sie weg\n→ {$d['price']} ist alles was zwischen dir und einem legendären Abend steht\n\n{$link}",
                        'headline' => "{$days_until} — und die Tickets schwinden",
                        'description' => "{$d['title']} · {$loc} · Ab {$d['price']}",
                        'rationale_primary' => 'Die "Was du wissen musst"-Struktur ist eine Storytelling-Technik aus dem Journalismus. Sie signalisiert: das hier ist wichtig, lies weiter. Die letzte Zeile reframed den Preis als niedrige Hürde.',
                        'rationale_headline' => 'Zeitliche Nähe + Knappheit in einer Headline. Die Kombination zweier Urgency-Trigger ist stärker als jeder einzelne.',
                    ],
                ],
                'budget' => '<strong>15-25 €/Tag für maximal 5 Tage</strong> — Scarcity-Kampagnen müssen kurz und intensiv sein.<br><br><strong>Warum so aggressiv?</strong> Du hast ein kurzes Zeitfenster. Jeder Tag ohne Verkauf ist ein verlorener Platz. Der CPM steigt kurz vor Events, aber die Conversion Rate steigt ebenfalls (Dringlichkeit ist real). Ein CPA von bis zu 30% des Ticketpreises ist hier akzeptabel — du verkaufst sonst gar nicht.',
                'audience' => [
                    '<strong>Primär (warm):</strong> Website-Besucher der Event-Seite in den letzten 14 Tagen — die kennen das Event schon, brauchen nur den letzten Push',
                    '<strong>Sekundär:</strong> Warenkorb-Abbrecher (AddToCart ohne Purchase) — die wollten schon kaufen!',
                    '<strong>Tertiär (kalt):</strong> Lookalike 1-2% der Käufer + Standort 20 km um ' . $loc,
                    '<strong>Ausschließen:</strong> Bestehende Käufer (Custom Audience "Purchase")',
                    '<strong>Tipp:</strong> Schalte 2 Anzeigengruppen: eine für warme Audiences (Retargeting), eine für kalte. Die warme wird 3-5x besseren CPA haben.',
                ],
                'steps' => [
                    'Kampagnenziel: <strong>"Umsatz"</strong> mit "Purchase" als Conversion-Event',
                    '<strong>2 Anzeigengruppen</strong> erstellen: "Warm" (Website-Besucher) und "Kalt" (Lookalike)',
                    'Budgetverteilung: 60% warm, 40% kalt',
                    'Platzierung: <strong>Stories + Reels bevorzugt</strong> — Fullscreen-Format erzeugt mehr Urgency',
                    'Creative: Dunkles/kontrastreiches Bild mit großem Text-Overlay "FAST AUSVERKAUFT"',
                    'Alle 3 Varianten gleichzeitig testen — nach 48h nur die beste behalten',
                    'Laufzeit: Maximal bis 24h vor Event-Start, dann stoppen',
                ],
            ],

            /* ══════════════════════════════════════════
               RETARGETING
               Psychologie: Mere Exposure + Commitment
            ══════════════════════════════════════════ */
            'retargeting' => [
                'title' => '🎯 Retargeting',
                'strategy' => '<strong>Strategie: Mere-Exposure-Effekt + Offene Frage</strong><br>Der Mere-Exposure-Effekt besagt: Je öfter wir etwas sehen, desto positiver bewerten wir es. Die Person hat die Event-Seite schon besucht — das zweite Mal ist vertrauter, die Hürde niedriger. Die direkte Ansprache ("Du hast dir X angesehen") nutzt den Commitment-Effekt: Wer sich Zeit genommen hat zu schauen, fühlt sich innerlich bereits beteiligt.',
                'variants' => [
                    [
                        'name' => 'A: Persönliche Nachfrage',
                        'primary' => "Du hast dir {$d['title']} angesehen.\n\nMeistens bedeutet das: Interesse ist da, aber irgendwas hat dich noch abgehalten.\n\nVielleicht hilft das:\n→ {$d['date_formatted']} in {$loc}\n→ Tickets ab {$d['price']}\n→ Sicherer Kauf, echte Tickets, sofortige Bestätigung\n\nManchmal braucht es nur den zweiten Blick.\n{$link}",
                        'headline' => "Noch am Überlegen?",
                        'description' => "{$d['title']} · Tickets ab {$d['price']}",
                        'rationale_primary' => 'Empathischer Ton: "irgendwas hat dich abgehalten" validiert die Unsicherheit des Users statt sie zu ignorieren. Die Bullet-Points räumen typische Kaufhürden aus dem Weg (Preis, Vertrauen).',
                        'rationale_headline' => 'Offene Frage erzeugt einen kognitiven "Loop" — das Gehirn will die Frage beantworten, was Engagement erhöht.',
                    ],
                    [
                        'name' => 'B: FOMO Light',
                        'primary' => "Du warst schon auf der Seite von {$d['title']}.\n\nSeitdem sind weitere Tickets verkauft worden.\n\n{$d['date_formatted']} · {$loc}\n\nBevor du es dir nochmal überlegst — hier ist der direkte Link zu deinem Ticket:\n{$link}",
                        'headline' => "Die Tickets gehen weiter weg",
                        'description' => "{$d['title']} · {$d['date_formatted']}",
                        'rationale_primary' => 'Subtile Scarcity: "weitere Tickets verkauft" impliziert Momentum ohne aggressive Zahlen. Der "direkte Link"-Satz reduziert Friction — der User muss nicht mehr suchen.',
                        'rationale_headline' => 'Progressions-Sprache ("gehen weiter weg") erzeugt ein Gefühl von Bewegung und Verknappung, ohne "JETZT KAUFEN" zu schreien.',
                    ],
                    [
                        'name' => 'C: Minimalistisch',
                        'primary' => "{$d['title']}\n{$d['date_formatted']}\n{$loc}\n\nDein Ticket wartet.\n{$link}",
                        'headline' => "Du weißt Bescheid.",
                        'description' => "Tickets ab {$d['price']}",
                        'rationale_primary' => 'Bei Retargeting kennt die Person das Event bereits. Weniger ist mehr — jedes zusätzliche Wort ist Noise. Die 5 Zeilen sagen alles. "Dein Ticket wartet" personalisiert ohne aufdringlich zu sein.',
                        'rationale_headline' => 'Selbstbewusste Insider-Ansprache. Funktioniert nur bei warmen Audiences die das Event kennen — genau deshalb ist es perfekt für Retargeting.',
                    ],
                ],
                'budget' => '<strong>3-5 €/Tag</strong> — die günstigste Kampagnenform.<br><br><strong>Warum so wenig?</strong> Retargeting-Audiences sind klein (typisch: 200-5000 Personen). Ein zu hohes Budget führt zu Ad Fatigue (die gleiche Person sieht die Anzeige zu oft). Meta empfiehlt eine Frequenz von 2-4x pro Woche. Bei 5 €/Tag und einer Audience von 1000 Personen erreichst du das optimal. <strong>ROAS ist hier 3-10x höher</strong> als bei kalten Kampagnen — das ist dein effizientestes Werbebudget.',
                'audience' => [
                    '<strong>Custom Audience 1:</strong> Alle Website-Besucher der Event-Seite (URL enthält Event-Slug) in den letzten 14-30 Tagen',
                    '<strong>Custom Audience 2:</strong> "ViewContent" Pixel-Event — noch präziser als URL-basiert',
                    '<strong>Custom Audience 3 (optional):</strong> "AddToCart" ohne "Purchase" — Warenkorbabbrecher, höchste Kaufwahrscheinlichkeit',
                    '<strong>Zwingend ausschließen:</strong> "Purchase" der letzten 30 Tage — wer gekauft hat, soll nicht genervt werden',
                    '<strong>Kein Interessen-Targeting nötig!</strong> Die Audience ist bereits qualifiziert durch ihr Verhalten',
                ],
                'steps' => [
                    'Events Manager → Zielgruppen → "Erstellen" → <strong>"Custom Audience"</strong> → "Website"',
                    'Pixel wählen → Event: "ViewContent" oder "Alle Websitebesucher" → URL enthält <code>' . sanitize_title($d['title']) . '</code>',
                    'Zeitraum: <strong>14 Tage</strong> (frisch genug für relevantes Retargeting)',
                    'Zweite Audience: Event "Purchase" → als Ausschluss verwenden',
                    'Kampagne: Ziel "Umsatz" → diese Custom Audience als Zielgruppe',
                    '<strong>Wichtig: KEIN zusätzliches Targeting</strong> (Alter, Interessen) — die Audience ist schon qualifiziert',
                    'Alle 3 Varianten gleichzeitig testen',
                    'Frequenz im Auge behalten: über 4x pro Woche = Ad Fatigue → pausieren',
                ],
            ],

            /* ══════════════════════════════════════════
               AWARENESS / NEUE VERANSTALTUNG
               Psychologie: Curiosity Gap + Identität
            ══════════════════════════════════════════ */
            'awareness' => [
                'title' => '📢 Neue Veranstaltung',
                'strategy' => '<strong>Strategie: Curiosity Gap + Identitäts-Ansprache</strong><br>Bei kalten Audiences (Menschen die dich nicht kennen) funktioniert klassisches "Kauf mein Ticket"-Marketing nicht. Stattdessen nutzen wir den Curiosity Gap (Loewenstein): Eine Informationslücke erzeugen, die der User schließen will. Kombiniert mit Identitäts-Ansprache ("Für alle die X lieben") fühlt sich die Person direkt angesprochen, bevor sie überhaupt weiß worum es geht.',
                'variants' => [
                    [
                        'name' => 'A: Curiosity Gap',
                        'primary' => "Am {$d['date_formatted']} passiert etwas in {$loc}, das du nicht verpassen willst.\n\n{$d['title']}.\n\n{$d['description']}\n\nTickets ab {$d['price']} — solange verfügbar.\n{$link}",
                        'headline' => "{$d['date_formatted']} in {$loc}",
                        'description' => "Tickets ab {$d['price']} · {$d['title']}",
                        'rationale_primary' => 'Die erste Zeile erzeugt eine Informationslücke: "etwas passiert" — aber was? Das Gehirn will die Lücke schließen und liest weiter. Erst dann kommt der Event-Name als "Auflösung". Dieser Aufbau hat 2-3x höhere Read-Through-Rates als sofortiges "Komm zu unserem Event".',
                        'rationale_headline' => 'Datum + Ort statt Event-Name in der Headline. Klingt wie ein persönlicher Tipp, nicht wie Werbung. Passt zum Curiosity-Ansatz.',
                    ],
                    [
                        'name' => 'B: Identitäts-Hook',
                        'primary' => "Für alle in {$loc}, die gute Events zu schätzen wissen:\n\n{$d['title']}\n📅 {$d['date_formatted']}\n💰 Ab {$d['price']}\n\n{$d['description']}\n\nDie Frage ist nicht ob es gut wird — sondern ob du dabei bist.\n{$link}",
                        'headline' => "{$d['title']}",
                        'description' => "{$d['date_formatted']} · Ab {$d['price']}",
                        'rationale_primary' => 'Identitäts-Framing: "Für alle die gute Events zu schätzen wissen" spricht das Selbstbild an. Wer sich als Event-Liebhaber sieht, fühlt sich angesprochen. Der Abschluss-Satz ist eine implizite Herausforderung.',
                        'rationale_headline' => 'Einfach der Event-Name. Bei Awareness geht es um Wiedererkennung — je öfter jemand den Namen sieht, desto vertrauter wird er (Mere Exposure).',
                    ],
                    [
                        'name' => 'C: Storytelling',
                        'primary' => "Es gibt Events, die man besucht.\nUnd es gibt Events, über die man noch Wochen später redet.\n\n{$d['title']} wird zweiteres.\n\n📅 {$d['date_formatted']}\n📍 {$loc}\n🎟️ Ab {$d['price']}\n\n{$link}",
                        'headline' => "Das wird man nicht vergessen",
                        'description' => "{$d['title']} · {$d['date_formatted']} · {$loc}",
                        'rationale_primary' => 'Storytelling-Opening mit Kontrast: "Events die man besucht vs. Events über die man redet" setzt einen Standard und positioniert das Event in der oberen Kategorie. Keine Fakten im Einstieg — erst die Emotion, dann die Details.',
                        'rationale_headline' => 'Emotionales Versprechen statt Information. Funktioniert besonders gut mit starkem Event-Bild. Der User soll fühlen, nicht rechnen.',
                    ],
                ],
                'budget' => $d['price_raw'] > 0
                    ? '<strong>' . number_format($d['price_raw'] * 50 * 0.05, 0, ',', '.') . '–' . number_format($d['price_raw'] * 50 * 0.10, 0, ',', '.') . ' € Gesamtbudget</strong> (5-10% des Ziel-Umsatzes bei 50 Tickets).<br><br><strong>Awareness-Strategie:</strong> Teile das Budget in 2 Phasen:<br>1. <strong>Phase 1 (Tag 1-5):</strong> 60% Budget für 3 Varianten parallel → finde den Gewinner<br>2. <strong>Phase 2 (Tag 6-14):</strong> 40% Budget nur auf die beste Variante → skaliere den Gewinner<br><br>Erwarte einen CPA von 15-25% des Ticketpreises bei kaltem Traffic. Das verbessert sich über die Zeit wenn der Pixel lernt.'
                    : '<strong>5-10 €/Tag für 7-14 Tage</strong>. Die ersten 3 Tage sind Lernphase — nicht optimieren! Ab Tag 4 die schlechteste Variante pausieren.',
                'audience' => [
                    '<strong>Beste Option:</strong> Lookalike 1% basierend auf deinen Käufern — Meta findet automatisch ähnliche Menschen',
                    '<strong>Wenn keine Käufer-Daten:</strong> Broad Targeting mit Standort 30-60 km + Alter 18-40 + Advantage+ lassen',
                    '<strong>Interessen (nur wenn Broad nicht performt):</strong> Nachtleben, Live-Events, Konzerte, Festivals, lokale Seiten/Clubs',
                    '<strong>Standort:</strong> Nicht zu eng (min. 25 km Radius) — Meta braucht genug Audience-Größe zum Optimieren',
                    '<strong>Pro-Tipp:</strong> Erstelle eine "Engagement Custom Audience" (Personen die mit deiner Instagram-/Facebook-Seite interagiert haben) als separaten Test',
                ],
                'steps' => [
                    'Kampagnenziel: <strong>"Umsatz"</strong> — auch bei Awareness! Meta optimiert auf den richtigen Funnel-Schritt',
                    'Conversion-Event: "Purchase" (nicht "ViewContent" — Meta soll auf Käufer optimieren, nicht auf Besucher)',
                    '<strong>3 Anzeigen in einer Anzeigengruppe</strong> (Variante A, B, C) → Meta verteilt Budget automatisch an den Gewinner',
                    'Creative: <strong>Das Event-Bild ist 80% des Erfolgs</strong> — investiere hier! 1080x1080 (Feed) + 1080x1920 (Stories)',
                    'CTA: "Mehr dazu" (nicht "Jetzt kaufen" — bei Awareness ist Neugier wichtiger als Druck)',
                    'Tracking-Link als Ziel-URL',
                    '<strong>Tag 1-3: NICHT ANFASSEN</strong> — Meta braucht die Lernphase',
                    'Tag 4: Variante mit dem höchsten CTR und niedrigstem CPA behalten, Rest pausieren',
                    'Tag 7: Wenn CPA akzeptabel → Budget um 20% erhöhen',
                ],
            ],
        ];

        return $templates[$type] ?? $templates['awareness'];
    }
}
