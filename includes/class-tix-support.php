<?php
/**
 * Tixomat – Support-System (CRM + Kunden-Portal)
 *
 * Admin-Dashboard zum Nachschlagen von Kunden, Bestellungen und Tickets.
 * Anfragen-System (CPT) mit Nachrichten-Verlauf und Status-Tracking.
 * Kunden-Portal (Frontend-Shortcode) für Anfragen-Verwaltung.
 *
 * @since 1.23.0
 */
if (!defined('ABSPATH')) exit;

class TIX_Support {

    // ══════════════════════════════════════════════
    // INIT
    // ══════════════════════════════════════════════

    public static function init() {
        // CPT + Custom Statuses immer registrieren
        add_action('init', [__CLASS__, 'register_post_type']);
        add_action('init', [__CLASS__, 'register_post_statuses']);

        // Nur wenn Support aktiviert
        if (!tix_get_settings('support_enabled')) return;

        // Admin-Dashboard
        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        }

        // Frontend-Shortcode
        add_shortcode('tix_support', [__CLASS__, 'render_shortcode']);

        // Floating Chat-Widget deaktiviert – ersetzt durch Tixomat Bot Chat-Widget
        // if (!is_admin() && tix_get_settings('support_chat_enabled')) {
        //     add_action('wp_footer', [__CLASS__, 'render_chat_widget']);
        // }

        // ── Admin AJAX ──
        add_action('wp_ajax_tix_support_search',        [__CLASS__, 'ajax_search']);
        add_action('wp_ajax_tix_support_list',          [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_tix_support_detail',        [__CLASS__, 'ajax_detail']);
        add_action('wp_ajax_tix_support_reply',         [__CLASS__, 'ajax_reply']);
        add_action('wp_ajax_tix_support_note',          [__CLASS__, 'ajax_note']);
        add_action('wp_ajax_tix_support_status',        [__CLASS__, 'ajax_status']);
        add_action('wp_ajax_tix_support_resend_ticket', [__CLASS__, 'ajax_resend_ticket']);
        add_action('wp_ajax_tix_support_change_owner',  [__CLASS__, 'ajax_change_owner']);

        // ── Neu (1.38.126): Drafts, Bulk, File-Upload, AI ──
        add_action('wp_ajax_tix_support_draft_save',    [__CLASS__, 'ajax_draft_save']);
        add_action('wp_ajax_tix_support_draft_get',     [__CLASS__, 'ajax_draft_get']);
        add_action('wp_ajax_tix_support_bulk',          [__CLASS__, 'ajax_bulk']);
        add_action('wp_ajax_tix_support_upload',        [__CLASS__, 'ajax_upload']);
        add_action('wp_ajax_nopriv_tix_support_upload', [__CLASS__, 'ajax_upload_customer']);
        add_action('wp_ajax_tix_support_ai_summary',    [__CLASS__, 'ajax_ai_summary']);
        add_action('wp_ajax_tix_support_ai_reply',      [__CLASS__, 'ajax_ai_reply']);

        // ── Frontend (nopriv) AJAX ──
        add_action('wp_ajax_tix_support_create',          [__CLASS__, 'ajax_create']);
        add_action('wp_ajax_nopriv_tix_support_create',   [__CLASS__, 'ajax_create']);
        add_action('wp_ajax_tix_support_customer_reply',  [__CLASS__, 'ajax_customer_reply']);
        add_action('wp_ajax_nopriv_tix_support_customer_reply', [__CLASS__, 'ajax_customer_reply']);
        add_action('wp_ajax_tix_support_customer_list',   [__CLASS__, 'ajax_customer_list']);
        add_action('wp_ajax_nopriv_tix_support_customer_list',  [__CLASS__, 'ajax_customer_list']);
        add_action('wp_ajax_tix_support_customer_detail', [__CLASS__, 'ajax_customer_detail']);
        add_action('wp_ajax_nopriv_tix_support_customer_detail', [__CLASS__, 'ajax_customer_detail']);
        add_action('wp_ajax_tix_support_customer_auth',   [__CLASS__, 'ajax_customer_auth']);
        add_action('wp_ajax_nopriv_tix_support_customer_auth',  [__CLASS__, 'ajax_customer_auth']);

        // Sidebar-Badge-Cache invalidieren wenn sich Status ändert
        add_action('transition_post_status', [__CLASS__, 'invalidate_open_count_cache'], 10, 3);
    }

    /**
     * Löscht den 60-s-Cache für die offenen-Anfragen-Badge-Zahl,
     * sobald ein Support-Ticket erzeugt wird oder seinen Status ändert.
     */
    public static function invalidate_open_count_cache($new_status, $old_status, $post) {
        if (!$post || $post->post_type !== 'tix_support_ticket') return;
        if ($new_status === $old_status) return;
        delete_transient('tix_open_support_count');
    }

    // ══════════════════════════════════════════════
    // CPT REGISTRIERUNG
    // ══════════════════════════════════════════════

    public static function register_post_type() {
        register_post_type('tix_support_ticket', [
            'public'             => false,
            'show_ui'            => false,   // Kein WP-UI, eigenes Admin-Dashboard
            'show_in_menu'       => false,
            'show_in_rest'       => false,
            'supports'           => ['title'],
            'labels'             => [
                'name'               => 'Support-Anfragen',
                'singular_name'      => 'Support-Anfrage',
                'add_new'            => 'Neue Anfrage',
                'add_new_item'       => 'Neue Anfrage erstellen',
                'edit_item'          => 'Anfrage bearbeiten',
                'view_item'          => 'Anfrage ansehen',
                'search_items'       => 'Anfragen suchen',
                'not_found'          => 'Keine Anfragen gefunden',
            ],
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
        ]);
    }

    // ══════════════════════════════════════════════
    // CUSTOM POST STATUSES
    // ══════════════════════════════════════════════

    public static function register_post_statuses() {
        $statuses = [
            'tix_open'     => ['label' => 'Offen',           'color' => '#3b82f6'],
            'tix_progress' => ['label' => 'In Bearbeitung',  'color' => '#f59e0b'],
            'tix_resolved' => ['label' => 'Gelöst',          'color' => '#10b981'],
            'tix_closed'   => ['label' => 'Geschlossen',     'color' => '#6b7280'],
        ];

        foreach ($statuses as $slug => $data) {
            register_post_status($slug, [
                'label'                     => $data['label'],
                'public'                    => false,
                'exclude_from_search'       => true,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop($data['label'] . ' <span class="count">(%s)</span>', $data['label'] . ' <span class="count">(%s)</span>'),
            ]);
        }
    }

    /**
     * Status-Konfiguration (Label + Farbe)
     */
    public static function get_statuses() {
        return [
            'tix_open'     => ['label' => 'Offen',           'color' => '#3b82f6'],
            'tix_progress' => ['label' => 'In Bearbeitung',  'color' => '#f59e0b'],
            'tix_resolved' => ['label' => 'Gelöst',          'color' => '#10b981'],
            'tix_closed'   => ['label' => 'Geschlossen',     'color' => '#6b7280'],
        ];
    }

    /**
     * Support-Kategorien aus Settings laden (mit Defaults)
     */
    public static function get_categories() {
        $raw = tix_get_settings('support_categories');

        // JSON-String versuchen
        if (!empty($raw) && is_string($raw)) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
            // Zeilenweise parsen (eine Kategorie pro Zeile)
            $lines = array_filter(array_map('trim', explode("\n", $raw)));
            if (!empty($lines)) {
                $cats = [];
                foreach ($lines as $line) {
                    $slug = sanitize_title($line);
                    $cats[] = ['slug' => $slug, 'label' => $line];
                }
                return $cats;
            }
        }

        // Defaults
        return [
            ['slug' => 'ticket_missing',  'label' => 'Ticket nicht erhalten'],
            ['slug' => 'name_change',     'label' => 'Ticketinhaber ändern'],
            ['slug' => 'cancellation',    'label' => 'Stornierung / Erstattung'],
            ['slug' => 'event_info',      'label' => 'Fragen zum Event'],
            ['slug' => 'other',           'label' => 'Sonstiges'],
        ];
    }

    /**
     * Eindeutige Nachrichten-ID generieren
     */
    private static function generate_message_id() {
        return 'msg_' . bin2hex(random_bytes(8));
    }

    /**
     * Zufälligen Access-Key für Gast-Zugriff generieren
     */
    private static function generate_access_key() {
        return bin2hex(random_bytes(16)); // 32 Zeichen
    }

    // ══════════════════════════════════════════════
    // ADMIN MENU + ASSETS
    // ══════════════════════════════════════════════

    public static function register_admin_menu() {
        add_submenu_page(
            'tixomat',
            'Support',
            'Support',
            'manage_options',
            'tix-support',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function enqueue_admin_assets($hook) {
        if ($hook !== 'tixomat_page_tix-support') return;

        $ver = defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '1.23.0';
        $url = TIXOMAT_URL;

        wp_enqueue_style('tix-support', $url . 'assets/css/support.css', ['tix-google-fonts'], $ver);
        wp_enqueue_script('tix-support', $url . 'assets/js/support.js', ['jquery'], $ver, true);

        wp_localize_script('tix-support', 'tixSupport', [
            'ajax'       => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('tix_support_action'),
            'statuses'   => self::get_statuses(),
            'categories' => self::get_categories(),
            'isAdmin'    => true,
            'crmUrl'     => admin_url('admin.php?page=tix-customers&email='),
            'adminUrl'   => admin_url(),
        ]);
    }

    // ══════════════════════════════════════════════
    // ADMIN PAGE RENDER
    // ══════════════════════════════════════════════

    public static function render_admin_page() {
        $statuses   = self::get_statuses();
        $categories = self::get_categories();
        ?>
        <div class="wrap tix-support-app">

            <div class="tix-app">

                <!-- Tabs -->
                <nav class="tix-nav">
                    <button type="button" class="tix-nav-tab active" data-tab="tickets">
                        <span class="dashicons dashicons-format-chat"></span>
                        <span class="tix-nav-label">Anfragen</span>
                    </button>
                    <button type="button" class="tix-nav-tab" data-tab="search">
                        <span class="dashicons dashicons-search"></span>
                        <span class="tix-nav-label">Kunden-Suche</span>
                    </button>
                    <button type="button" class="tix-nav-tab" data-tab="stats">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <span class="tix-nav-label">Statistiken</span>
                    </button>
                </nav>

                <div class="tix-content">

                    <!-- ═══ Tab: Anfragen ═══ -->
                    <div class="tix-pane active" data-pane="tickets">

                        <div class="tix-sp-toolbar">
                            <div class="tix-sp-filters">
                                <select id="tix-sp-filter-status" class="tix-sp-select">
                                    <option value="">Alle Status</option>
                                    <?php foreach ($statuses as $slug => $data): ?>
                                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($data['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="tix-sp-filter-category" class="tix-sp-select">
                                    <option value="">Alle Kategorien</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo esc_attr($cat['slug']); ?>"><?php echo esc_html($cat['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" id="tix-sp-filter-search" class="tix-sp-input" placeholder="Betreff oder E-Mail suchen…">
                            </div>
                            <button type="button" id="tix-sp-create-btn" class="tix-sp-btn tix-sp-btn-accent">
                                <span class="dashicons dashicons-plus-alt2"></span> Neue Anfrage
                            </button>
                        </div>

                        <!-- Anfragen-Liste -->
                        <div id="tix-sp-ticket-list" class="tix-sp-ticket-list">
                            <div class="tix-sp-loading">Lade Anfragen…</div>
                        </div>

                        <!-- Anfrage-Detail (inline, wird per JS eingeblendet) -->
                        <div id="tix-sp-ticket-detail" class="tix-sp-ticket-detail" style="display:none;"></div>

                    </div>

                    <!-- ═══ Tab: Kunden-Suche ═══ -->
                    <div class="tix-pane" data-pane="search">

                        <div class="tix-card">
                            <div class="tix-card-header">
                                <span class="dashicons dashicons-search"></span>
                                <h3>Kunden / Bestellungen / Tickets suchen</h3>
                            </div>
                            <div class="tix-card-body">
                                <div class="tix-sp-search-row">
                                    <input type="text" id="tix-sp-search-input" class="tix-sp-input tix-sp-search-main"
                                           placeholder="E-Mail, Name, Bestellnr. (#12345) oder Ticket-Code (12-stellig)…">
                                    <button type="button" id="tix-sp-search-btn" class="tix-sp-btn tix-sp-btn-accent">Suchen</button>
                                </div>
                                <p class="tix-sp-search-hint">Erkennt automatisch: E-Mail → Kundensuche · #Nummer → Bestellung · 12-stellig → Ticket-Code</p>
                            </div>
                        </div>

                        <div id="tix-sp-search-results" class="tix-sp-search-results"></div>

                    </div>

                    <!-- ═══ Tab: Statistiken ═══ -->
                    <div class="tix-pane" data-pane="stats">

                        <div class="tix-sp-stats-grid" id="tix-sp-stats-grid">
                            <div class="tix-sp-stat-card">
                                <div class="tix-sp-stat-icon" style="background:rgba(59,130,246,0.1);color:#3b82f6;">
                                    <span class="dashicons dashicons-email-alt"></span>
                                </div>
                                <div class="tix-sp-stat-value" id="tix-sp-stat-open">–</div>
                                <div class="tix-sp-stat-label">Offene Anfragen</div>
                            </div>
                            <div class="tix-sp-stat-card">
                                <div class="tix-sp-stat-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                                    <span class="dashicons dashicons-clock"></span>
                                </div>
                                <div class="tix-sp-stat-value" id="tix-sp-stat-progress">–</div>
                                <div class="tix-sp-stat-label">In Bearbeitung</div>
                            </div>
                            <div class="tix-sp-stat-card">
                                <div class="tix-sp-stat-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                </div>
                                <div class="tix-sp-stat-value" id="tix-sp-stat-resolved">–</div>
                                <div class="tix-sp-stat-label">Heute gelöst</div>
                            </div>
                            <div class="tix-sp-stat-card">
                                <div class="tix-sp-stat-icon" style="background:rgba(255,85,0,0.1);color:<?php echo tix_primary(); ?>;">
                                    <span class="dashicons dashicons-performance"></span>
                                </div>
                                <div class="tix-sp-stat-value" id="tix-sp-stat-avg-time">–</div>
                                <div class="tix-sp-stat-label">Ø Antwortzeit</div>
                            </div>
                        </div>

                        <!-- 7-Tage-Trend -->
                        <div class="tix-card" style="margin-top:20px;">
                            <div class="tix-card-header">
                                <span class="dashicons dashicons-chart-bar"></span>
                                <h3>Letzte 7 Tage</h3>
                            </div>
                            <div class="tix-card-body">
                                <div id="tix-sp-stats-chart" class="tix-sp-stats-chart"></div>
                            </div>
                        </div>

                    </div>

                </div>

            </div>

        </div>
        <?php
    }

    // ══════════════════════════════════════════════
    // AJAX: KUNDEN-SUCHE (Admin Tab 2)
    // ══════════════════════════════════════════════

    public static function ajax_search() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $query = sanitize_text_field($_POST['query'] ?? '');
        if (empty($query)) wp_send_json_error('Bitte Suchbegriff eingeben.');

        $result = [
            'type'    => '',
            'orders'  => [],
            'tickets' => [],
            'support' => [],
            'customer' => null,
        ];

        // ── Auto-Detect: Ticket-Code (alt: TIX-XXXXXX, neu: 12 alphanumerische Zeichen) ──
        if (preg_match('/^TIX-/i', $query) || preg_match('/^[A-Z0-9]{12}$/i', $query)) {
            $result['type'] = 'ticket_code';
            if (class_exists('TIX_Tickets')) {
                $ticket = TIX_Tickets::get_ticket_by_code(strtoupper($query));
                if ($ticket) {
                    $order_id = get_post_meta($ticket->ID, '_tix_ticket_order_id', true);
                    $result['tickets'][] = self::format_ticket($ticket);
                    if ($order_id) {
                        $order = wc_get_order($order_id);
                        if (!$order && class_exists('TIX_Order')) {
                            $order = TIX_Order::get($order_id);
                        }
                        if ($order) {
                            $result['orders'][] = self::format_order($order);
                            $result['customer'] = self::format_customer_from_order($order);
                        }
                    }
                }
            }
        }
        // ── Auto-Detect: Bestellnummer (numerisch oder mit Prefix wie MFXXL-0042) ──
        elseif (preg_match('/^#?[A-Za-z0-9_\-]+$/', $query)) {
            $result['type'] = 'order';
            $clean = ltrim($query, '#');
            $order = null;
            $order_id = 0;

            // 1) Direkte numerische ID → WC oder native
            if (ctype_digit($clean)) {
                $order_id = intval($clean);
                if (function_exists('wc_get_order')) $order = wc_get_order($order_id);
                if (!$order && class_exists('TIX_Order')) $order = TIX_Order::get($order_id);
            }

            // 2) Lookup über aktuelle TIX_Order order_number-Spalte
            if (!$order && class_exists('TIX_Order')) {
                $matches = TIX_Order::query(['order_number' => $clean, 'limit' => 1]);
                if (!empty($matches)) { $order = $matches[0]; $order_id = $order->get_id(); }
            }

            // 3) Lookup über Legacy-WC-Order-Number (für migrierte Bestellungen)
            if (!$order) {
                $hits = get_posts([
                    'post_type'   => 'any',
                    'meta_key'    => '_tix_legacy_wc_order_number',
                    'meta_value'  => $clean,
                    'posts_per_page' => 1,
                    'fields'      => 'ids',
                ]);
                if (!empty($hits) && class_exists('TIX_Order')) {
                    $order = TIX_Order::get(intval($hits[0]));
                    if ($order) $order_id = $order->get_id();
                }
                // Auch numerisch: alte WC-ID
                if (!$order && ctype_digit($clean)) {
                    $hits = get_posts([
                        'post_type'   => 'any',
                        'meta_key'    => '_tix_legacy_wc_order_id',
                        'meta_value'  => intval($clean),
                        'posts_per_page' => 1,
                        'fields'      => 'ids',
                    ]);
                    if (!empty($hits) && class_exists('TIX_Order')) {
                        $order = TIX_Order::get(intval($hits[0]));
                        if ($order) $order_id = $order->get_id();
                    }
                }
            }

            if ($order) {
                $result['orders'][] = self::format_order($order);
                $result['customer'] = self::format_customer_from_order($order);
                if (class_exists('TIX_Tickets')) {
                    $tickets = TIX_Tickets::get_all_tickets_for_order($order_id);
                    foreach ($tickets as $t) {
                        $result['tickets'][] = self::format_ticket($t);
                    }
                }
            }
        }
        // ── Auto-Detect: E-Mail ──
        elseif (is_email($query)) {
            $result['type'] = 'email';
            $email = sanitize_email($query);
            $result['customer'] = ['email' => $email, 'name' => ''];

            // WC-Bestellungen
            if (function_exists('wc_get_orders')) {
                $orders = wc_get_orders([
                    'billing_email' => $email,
                    'limit'         => 20,
                    'orderby'       => 'date',
                    'order'         => 'DESC',
                ]);
                foreach ($orders as $o) {
                    $result['orders'][] = self::format_order($o);
                    if (!$result['customer']['name']) {
                        $result['customer']['name'] = $o->get_billing_first_name() . ' ' . $o->get_billing_last_name();
                    }
                }
                // Tickets aus allen Bestellungen
                if (class_exists('TIX_Tickets')) {
                    foreach ($orders as $o) {
                        $tickets = TIX_Tickets::get_all_tickets_for_order($o->get_id());
                        foreach ($tickets as $t) {
                            $result['tickets'][] = self::format_ticket($t);
                        }
                    }
                }
            }

            // Native orders (wc_order_id = 0 only, to avoid double-counting)
            if (class_exists('TIX_Order')) {
                $native_orders = TIX_Order::query(['email' => $email, 'limit' => 10]);
                foreach ($native_orders as $no) {
                    $result['orders'][] = [
                        'id'     => $no->get_id(),
                        'number' => $no->get_order_number(),
                        'status' => $no->get_status(),
                        'total'  => $no->get_formatted_order_total(),
                        'date'   => $no->get_date_created() ? $no->get_date_created()->date_i18n('d.m.Y H:i') : '',
                        'email'  => $no->get_billing_email(),
                        'name'   => $no->get_billing_first_name() . ' ' . $no->get_billing_last_name(),
                        'type'   => 'native',
                    ];
                    if (!$result['customer']['name']) {
                        $result['customer']['name'] = $no->get_billing_first_name() . ' ' . $no->get_billing_last_name();
                    }
                }
                // Tickets from native orders
                if (class_exists('TIX_Tickets')) {
                    foreach ($native_orders as $no) {
                        $tickets = TIX_Tickets::get_all_tickets_for_order($no->get_id());
                        foreach ($tickets as $t) {
                            $result['tickets'][] = self::format_ticket($t);
                        }
                    }
                }
            }

            // Support-Anfragen mit dieser E-Mail
            $support_posts = get_posts([
                'post_type'   => 'tix_support_ticket',
                'post_status' => ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'],
                'meta_key'    => '_tix_sp_email',
                'meta_value'  => $email,
                'numberposts' => 20,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);
            foreach ($support_posts as $sp) {
                $result['support'][] = self::format_support_ticket($sp);
            }
        }
        // ── Freitext-Suche (Name, Nachname, Teil-Email, Ticket-Betreff) ──
        else {
            $result['type'] = 'name';
            global $wpdb;
            $like = '%' . $wpdb->esc_like($query) . '%';
            $ticket_statuses = ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'];

            // ── 1. Support-Tickets: Betreff (post_title/content) + Email/Name-Meta (LIKE) ──
            $support_posts = get_posts([
                'post_type'   => 'tix_support_ticket',
                'post_status' => $ticket_statuses,
                's'           => $query,
                'numberposts' => 20,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);
            foreach ($support_posts as $sp) {
                $result['support'][] = self::format_support_ticket($sp);
            }

            // Meta-Match: Name ODER Email (beide LIKE)
            $meta_fields = ['_tix_sp_name', '_tix_sp_email'];
            foreach ($meta_fields as $mk) {
                $by_meta = get_posts([
                    'post_type'    => 'tix_support_ticket',
                    'post_status'  => $ticket_statuses,
                    'meta_key'     => $mk,
                    'meta_value'   => $query,
                    'meta_compare' => 'LIKE',
                    'numberposts'  => 20,
                ]);
                $existing_ids = array_column($result['support'], 'id');
                foreach ($by_meta as $sp) {
                    if (!in_array($sp->ID, $existing_ids, true)) {
                        $result['support'][] = self::format_support_ticket($sp);
                    }
                }
            }

            // ── 2. Native TIX_Orders: Email / First / Last partial LIKE ──
            $tix_orders_table = $wpdb->prefix . 'tix_orders';
            if ($wpdb->get_var("SHOW TABLES LIKE '$tix_orders_table'") === $tix_orders_table) {
                $native_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM $tix_orders_table
                     WHERE billing_email LIKE %s
                        OR billing_first_name LIKE %s
                        OR billing_last_name LIKE %s
                        OR CONCAT_WS(' ', billing_first_name, billing_last_name) LIKE %s
                     ORDER BY date_created DESC
                     LIMIT 20",
                    $like, $like, $like, $like
                ));
                if (class_exists('TIX_Order')) {
                    foreach ($native_ids as $oid) {
                        $no = TIX_Order::get(intval($oid));
                        if (!$no) continue;
                        $result['orders'][] = [
                            'id'     => $no->get_id(),
                            'number' => $no->get_order_number(),
                            'status' => $no->get_status(),
                            'total'  => $no->get_formatted_order_total(),
                            'date'   => $no->get_date_created() ? $no->get_date_created()->date_i18n('d.m.Y H:i') : '',
                            'email'  => $no->get_billing_email(),
                            'name'   => trim($no->get_billing_first_name() . ' ' . $no->get_billing_last_name()),
                            'type'   => 'native',
                        ];
                    }
                }
            }

            // ── 3. WC-Bestellungen: Built-in 's' param (deckt Email + Name intern ab) ──
            if (function_exists('wc_get_orders')) {
                $orders = wc_get_orders([
                    's'       => $query,
                    'limit'   => 10,
                    'orderby' => 'date',
                    'order'   => 'DESC',
                ]);
                foreach ($orders as $o) {
                    $result['orders'][] = self::format_order($o);
                }
            }
        }

        wp_send_json_success($result);
    }

    // ══════════════════════════════════════════════
    // AJAX: ANFRAGEN-LISTE (Admin Tab 1)
    // ══════════════════════════════════════════════

    public static function ajax_list() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $status   = sanitize_text_field($_POST['status'] ?? '');
        $category = sanitize_text_field($_POST['category'] ?? '');
        $search   = sanitize_text_field($_POST['search'] ?? '');
        $page     = max(1, intval($_POST['page'] ?? 1));
        $per_page = 20;

        $args = [
            'post_type'      => 'tix_support_ticket',
            'post_status'    => $status ?: ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'],
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        if ($search) {
            // Suche in Betreff + E-Mail
            $args['meta_query'] = [
                'relation' => 'OR',
                ['key' => '_tix_sp_email', 'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_tix_sp_name',  'value' => $search, 'compare' => 'LIKE'],
            ];
            // Auch im Titel suchen
            $args['s'] = $search;
            // WP_Query hat kein "OR" für s + meta_query, daher splitten
            unset($args['meta_query']);
        }

        if ($category) {
            $args['meta_query'] = $args['meta_query'] ?? [];
            $args['meta_query'][] = ['key' => '_tix_sp_category', 'value' => $category];
        }

        $q = new WP_Query($args);
        $tickets = [];

        foreach ($q->posts as $p) {
            $tickets[] = self::format_support_ticket($p);
        }

        wp_send_json_success([
            'tickets' => $tickets,
            'total'   => $q->found_posts,
            'pages'   => $q->max_num_pages,
            'page'    => $page,
        ]);
    }

    // ══════════════════════════════════════════════
    // AJAX: ANFRAGE-DETAIL (Admin)
    // ══════════════════════════════════════════════

    public static function ajax_detail() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_support_ticket') {
            wp_send_json_error('Anfrage nicht gefunden.');
        }

        $ticket = self::format_support_ticket($post);
        $ticket['messages'] = self::get_messages($ticket_id);

        // Verknüpfte Order-Infos
        $order_id    = intval(get_post_meta($ticket_id, '_tix_sp_order_id', true));
        $ticket_code = get_post_meta($ticket_id, '_tix_sp_ticket_code', true);

        // ── Linked-Ticket per Code auflösen ── (auch wenn keine Order-ID gesetzt)
        $linked_ticket_obj = null;
        if ($ticket_code && class_exists('TIX_Tickets')) {
            $linked_ticket_obj = TIX_Tickets::get_ticket_by_code($ticket_code);
            if ($linked_ticket_obj) {
                $ticket['linked_ticket'] = self::format_ticket($linked_ticket_obj);
                // Order-ID aus Ticket nachholen, falls fehlend
                if (!$order_id) {
                    $order_id = intval(get_post_meta($linked_ticket_obj->ID, '_tix_ticket_order_id', true));
                }
            }
        }

        // ── Fallback: Order anhand Kunden-Email finden, wenn weder Order-ID noch Ticket-Code ──
        if (!$order_id) {
            $email = get_post_meta($ticket_id, '_tix_sp_email', true);
            if ($email) {
                global $wpdb;
                $orders_table = $wpdb->prefix . 'tix_orders';
                if ($wpdb->get_var("SHOW TABLES LIKE '$orders_table'") === $orders_table) {
                    $latest = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $orders_table WHERE billing_email = %s ORDER BY date_created DESC LIMIT 1",
                        $email
                    ));
                    if ($latest) $order_id = intval($latest);
                }
            }
        }

        // ── Order laden ──
        if ($order_id) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
            if (!$order && class_exists('TIX_Order')) {
                $order = TIX_Order::get($order_id);
            }
            if ($order) {
                $ticket['order']    = self::format_order($order);
                $ticket['order_id'] = $order_id; // an JS weiterreichen für Ticket-Anhang-Dropdown
            }
        }

        // ── Alle Tickets der verknüpften Bestellung ──
        if ($order_id && class_exists('TIX_Tickets')) {
            $all_tickets = TIX_Tickets::get_all_tickets_for_order($order_id);
            $ticket['linked_tickets'] = array_values(array_map([__CLASS__, 'format_ticket'], $all_tickets));
        }

        wp_send_json_success($ticket);
    }

    // ══════════════════════════════════════════════
    // AJAX: ADMIN ANTWORT
    // ══════════════════════════════════════════════

    public static function ajax_reply() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id       = intval($_POST['ticket_id'] ?? 0);
        $content         = sanitize_textarea_field($_POST['content'] ?? '');
        $attach_order_id = intval($_POST['attach_order_id'] ?? 0);
        $attached_files  = (array) ($_POST['attached_files'] ?? []);

        if (!$ticket_id || empty($content)) {
            wp_send_json_error('Ticket-ID und Nachricht erforderlich.');
        }

        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_support_ticket') {
            wp_send_json_error('Anfrage nicht gefunden.');
        }

        // Sicherheitscheck: attach_order_id muss zur Ticket-Bestellung gehören
        if ($attach_order_id) {
            $linked_order_id = intval(get_post_meta($ticket_id, '_tix_sp_order_id', true));
            if ($linked_order_id !== $attach_order_id) {
                $attach_order_id = 0; // Manipulation: ignorieren statt fehlschlagen
            }
        }

        // Attachments validieren — URLs müssen aus unserer Upload-Struktur stammen
        $upload_dir = wp_upload_dir();
        $allowed_prefix = trailingslashit($upload_dir['baseurl']) . 'tix-support/' . intval($ticket_id) . '/';
        $valid_files = [];
        foreach ($attached_files as $f) {
            if (!is_array($f)) continue;
            $url = esc_url_raw($f['url'] ?? '');
            if ($url && strpos($url, $allowed_prefix) === 0) {
                $valid_files[] = [
                    'url'  => $url,
                    'name' => sanitize_text_field($f['name'] ?? basename($url)),
                    'mime' => sanitize_text_field($f['mime'] ?? ''),
                ];
            }
        }

        $user = wp_get_current_user();
        $msg = [
            'id'          => self::generate_message_id(),
            'type'        => 'admin',
            'author'      => $user->display_name ?: 'Support-Team',
            'user_id'     => $user->ID,
            'content'     => $content,
            'date'        => current_time('c'),
            'attachments' => $valid_files,
        ];

        self::add_message($ticket_id, $msg);

        // Status auf "In Bearbeitung" setzen, wenn noch "Offen"
        if ($post->post_status === 'tix_open') {
            wp_update_post(['ID' => $ticket_id, 'post_status' => 'tix_progress']);
        }

        // Letzte Antwort-Timestamp aktualisieren
        update_post_meta($ticket_id, '_tix_sp_last_reply', current_time('c'));

        // E-Mail an Kunden senden (mit optionalen Ticket-Anhängen + Datei-Anhängen)
        $attach_message = '';
        $email = get_post_meta($ticket_id, '_tix_sp_email', true);
        if ($email) {
            $attach_message = self::send_email_reply_to_customer($ticket_id, $post->post_title, $email, $content, $attach_order_id, $valid_files);
        }

        $response = ['message' => $msg];
        if ($attach_message) {
            $response['attach_message'] = $attach_message;
            // Interne Notiz im Thread: was wurde angehängt
            $note = [
                'id'      => self::generate_message_id(),
                'type'    => 'note',
                'author'  => ($user->display_name ?: 'Support-Team') . ' (intern)',
                'user_id' => $user->ID,
                'content' => '🎫 ' . $attach_message,
                'date'    => current_time('c'),
            ];
            self::add_message($ticket_id, $note);
            $response['attach_note'] = $note;
        }

        wp_send_json_success($response);
    }

    // ══════════════════════════════════════════════
    // AJAX: INTERNE NOTIZ
    // ══════════════════════════════════════════════

    public static function ajax_note() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $content   = sanitize_textarea_field($_POST['content'] ?? '');

        if (!$ticket_id || empty($content)) {
            wp_send_json_error('Ticket-ID und Notiz erforderlich.');
        }

        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_support_ticket') {
            wp_send_json_error('Anfrage nicht gefunden.');
        }

        $user = wp_get_current_user();
        $msg = [
            'id'      => self::generate_message_id(),
            'type'    => 'note',
            'author'  => $user->display_name . ' (intern)',
            'user_id' => $user->ID,
            'content' => $content,
            'date'    => current_time('c'),
        ];

        self::add_message($ticket_id, $msg);

        wp_send_json_success(['message' => $msg]);
    }

    // ══════════════════════════════════════════════
    // AJAX: STATUS ÄNDERN
    // ══════════════════════════════════════════════

    public static function ajax_status() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id  = intval($_POST['ticket_id'] ?? 0);
        $new_status = sanitize_text_field($_POST['status'] ?? '');

        $valid = ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'];
        if (!$ticket_id || !in_array($new_status, $valid, true)) {
            wp_send_json_error('Ungültige Daten.');
        }

        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_support_ticket') {
            wp_send_json_error('Anfrage nicht gefunden.');
        }

        $old_status = $post->post_status;
        wp_update_post(['ID' => $ticket_id, 'post_status' => $new_status]);

        // Benachrichtigung an Kunden wenn → gelöst
        if ($new_status === 'tix_resolved' && $old_status !== 'tix_resolved') {
            $email = get_post_meta($ticket_id, '_tix_sp_email', true);
            if ($email) {
                self::send_email_status_resolved($ticket_id, $post->post_title, $email);
            }
        }

        $statuses = self::get_statuses();
        wp_send_json_success([
            'status'      => $new_status,
            'status_label' => $statuses[$new_status]['label'] ?? $new_status,
        ]);
    }

    // ══════════════════════════════════════════════
    // AJAX: TICKET E-MAIL ERNEUT SENDEN
    // ══════════════════════════════════════════════

    public static function ajax_resend_ticket() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_post_id = intval($_POST['ticket_post_id'] ?? 0);
        $email          = sanitize_email($_POST['email'] ?? '');

        if (!$ticket_post_id) {
            wp_send_json_error('Ticket-ID fehlt.');
        }

        $ticket = get_post($ticket_post_id);
        if (!$ticket || $ticket->post_type !== 'tix_ticket') {
            wp_send_json_error('Ticket nicht gefunden.');
        }

        // E-Mail-Adresse: entweder übergeben oder aus Ticket-Meta
        if (!$email) {
            $email = get_post_meta($ticket_post_id, '_tix_ticket_owner_email', true);
        }
        if (!$email) {
            $order_id = get_post_meta($ticket_post_id, '_tix_ticket_order_id', true);
            if ($order_id) {
                $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
                if (!$order && class_exists('TIX_Order')) {
                    $order = TIX_Order::get($order_id);
                }
                if ($order) $email = $order->get_billing_email();
            }
        }

        if (!is_email($email)) {
            wp_send_json_error('Keine gültige E-Mail-Adresse gefunden.');
        }

        // TIX_Emails nutzen um Ticket-Mail zu senden
        if (class_exists('TIX_Emails') && method_exists('TIX_Emails', 'send_ticket_email')) {
            TIX_Emails::send_ticket_email($ticket_post_id, $email);
            wp_send_json_success(['message' => 'Ticket-E-Mail wurde erneut gesendet an ' . $email]);
        }

        // Fallback: generische E-Mail mit Download-Link
        $code = get_post_meta($ticket_post_id, '_tix_ticket_code', true);
        $download_url = home_url('/ticket-download/?code=' . $code);

        $body = '<p>Hier ist dein Ticket-Download-Link:</p>';
        $body .= '<p><a href="' . esc_url($download_url) . '" style="display:inline-block;padding:12px 24px;' . tix_btn_style() . 'text-decoration:none;font-weight:700;">Ticket herunterladen</a></p>';
        $body .= '<p>Dein Ticket-Code: <strong>' . esc_html($code) . '</strong></p>';

        $html = TIX_Emails::build_generic_email_html('Dein Ticket', $body, 'Ticket erneut gesendet');
        $subject = 'Dein Ticket – ' . $code;

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($email, $subject, $html, $headers);

        wp_send_json_success(['message' => 'Ticket-E-Mail wurde erneut gesendet an ' . $email]);
    }

    // ══════════════════════════════════════════════
    // AJAX: TICKETINHABER ÄNDERN
    // ══════════════════════════════════════════════

    public static function ajax_change_owner() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_post_id = intval($_POST['ticket_post_id'] ?? 0);
        $new_name       = sanitize_text_field($_POST['new_name'] ?? '');
        $new_email      = sanitize_email($_POST['new_email'] ?? '');

        if (!$ticket_post_id || empty($new_name) || !is_email($new_email)) {
            wp_send_json_error('Ticket-ID, Name und gültige E-Mail erforderlich.');
        }

        $ticket = get_post($ticket_post_id);
        if (!$ticket || $ticket->post_type !== 'tix_ticket') {
            wp_send_json_error('Ticket nicht gefunden.');
        }

        // Meta-Felder aktualisieren
        update_post_meta($ticket_post_id, '_tix_ticket_owner_name', $new_name);
        update_post_meta($ticket_post_id, '_tix_ticket_owner_email', $new_email);

        // Auch die Gästeliste aktualisieren wenn möglich
        $event_id = get_post_meta($ticket_post_id, '_tix_ticket_event_id', true);
        if ($event_id) {
            $guests = get_post_meta($event_id, '_tix_guests', true) ?: [];
            $code = get_post_meta($ticket_post_id, '_tix_ticket_code', true);
            foreach ($guests as &$g) {
                if (($g['ticket_code'] ?? '') === $code) {
                    $g['name']  = $new_name;
                    $g['email'] = $new_email;
                    break;
                }
            }
            update_post_meta($event_id, '_tix_guests', $guests);
        }

        wp_send_json_success([
            'message' => 'Ticketinhaber geändert: ' . $new_name . ' (' . $new_email . ')',
            'name'    => $new_name,
            'email'   => $new_email,
        ]);
    }

    // ══════════════════════════════════════════════
    // AJAX: DRAFTS (Auto-save)
    // ══════════════════════════════════════════════

    public static function ajax_draft_save() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $content   = wp_unslash($_POST['content'] ?? '');
        $kind      = sanitize_text_field($_POST['kind'] ?? 'reply'); // 'reply' or 'note'
        $user_id   = get_current_user_id();
        if (!$ticket_id || !$user_id) wp_send_json_error('Ungültige Daten.');

        $key = "_tix_sp_draft_{$user_id}_{$kind}";
        if ($content === '') {
            delete_post_meta($ticket_id, $key);
        } else {
            update_post_meta($ticket_id, $key, [
                'content' => $content,
                'updated' => current_time('mysql'),
            ]);
        }
        wp_send_json_success(['saved' => true]);
    }

    public static function ajax_draft_get() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $user_id   = get_current_user_id();
        if (!$ticket_id || !$user_id) wp_send_json_error('Ungültige Daten.');

        $reply = get_post_meta($ticket_id, "_tix_sp_draft_{$user_id}_reply", true);
        $note  = get_post_meta($ticket_id, "_tix_sp_draft_{$user_id}_note", true);
        wp_send_json_success([
            'reply' => is_array($reply) ? $reply : null,
            'note'  => is_array($note)  ? $note  : null,
        ]);
    }

    // ══════════════════════════════════════════════
    // AJAX: BULK-AKTIONEN
    // ══════════════════════════════════════════════

    public static function ajax_bulk() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ids    = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
        $action = sanitize_text_field($_POST['bulk_action'] ?? '');
        if (empty($ids) || !$action) wp_send_json_error('Keine IDs oder Aktion.');

        $valid_status = ['tix_open' => 1, 'tix_progress' => 1, 'tix_resolved' => 1, 'tix_closed' => 1];
        $changed = 0;

        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'tix_support_ticket') continue;

            if (isset($valid_status[$action])) {
                wp_update_post(['ID' => $id, 'post_status' => $action]);
                update_post_meta($id, '_tix_sp_last_reply', current_time('c'));
                $changed++;
            } elseif ($action === 'delete') {
                wp_delete_post($id, true); // permanent
                $changed++;
            }
        }

        wp_send_json_success([
            'changed' => $changed,
            'message' => $changed . ' Anfrage' . ($changed === 1 ? '' : 'n') . ' aktualisiert.',
        ]);
    }

    // ══════════════════════════════════════════════
    // AJAX: FILE-UPLOAD
    // ══════════════════════════════════════════════

    /**
     * Admin-Upload: Datei hochladen, gibt URL zurück die in der Antwort
     * verlinkt wird (oder als Mail-Anhang via attach_files).
     */
    public static function ajax_upload() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        if (!$ticket_id) wp_send_json_error('Ticket-ID fehlt.');

        $result = self::handle_upload($_FILES['file'] ?? null, $ticket_id, 'admin');
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

        wp_send_json_success($result);
    }

    /**
     * Customer-Upload via Portal mit access_key-Verifikation.
     */
    public static function ajax_upload_customer() {
        check_ajax_referer('tix_support_action', 'nonce');

        $ticket_id  = intval($_POST['ticket_id'] ?? 0);
        $email      = sanitize_email($_POST['email'] ?? '');
        $access_key = sanitize_text_field($_POST['access_key'] ?? '');

        if (!self::verify_customer_access($ticket_id, $email, $access_key)) {
            wp_send_json_error('Zugriff verweigert.');
        }

        $result = self::handle_upload($_FILES['file'] ?? null, $ticket_id, 'customer');
        if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

        wp_send_json_success($result);
    }

    /**
     * Sicherer Upload-Handler.
     * Speichert in /uploads/tix-support/{ticket_id}/, mit Whitelist-Mimetypes.
     */
    private static function handle_upload($file, $ticket_id, $by = 'admin') {
        if (empty($file) || !is_array($file)) {
            return new WP_Error('no_file', 'Keine Datei hochgeladen.');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'Upload-Fehler.');
        }

        // Größe limitieren (10 MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            return new WP_Error('too_large', 'Datei zu groß (max. 10 MB).');
        }

        // Mime-Whitelist (Bilder, PDFs, Office-Dokumente)
        $allowed = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/heic' => 'heic',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
        ];
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $detected_mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? '');
        if ($finfo) finfo_close($finfo);
        if (!isset($allowed[$detected_mime])) {
            return new WP_Error('mime', 'Dateityp nicht erlaubt.');
        }

        // Zielverzeichnis
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit($upload_dir['basedir']) . 'tix-support';
        if (!file_exists($base_dir)) {
            wp_mkdir_p($base_dir);
            // .htaccess: Direktzugriff erlaubt aber kein PHP execute
            file_put_contents($base_dir . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|php\\..*)$\">\nRequire all denied\n</FilesMatch>\n");
        }
        $ticket_dir = $base_dir . '/' . intval($ticket_id);
        if (!file_exists($ticket_dir)) wp_mkdir_p($ticket_dir);

        // Sicherer Dateiname
        $orig_name = sanitize_file_name($file['name']);
        $ext       = $allowed[$detected_mime];
        $base_name = pathinfo($orig_name, PATHINFO_FILENAME) ?: 'upload';
        $base_name = substr(preg_replace('/[^a-zA-Z0-9._-]/', '_', $base_name), 0, 50);
        $unique    = wp_generate_password(6, false, false);
        $filename  = $base_name . '_' . $unique . '.' . $ext;
        $dest      = $ticket_dir . '/' . $filename;

        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            return new WP_Error('move', 'Datei konnte nicht gespeichert werden.');
        }
        @chmod($dest, 0644);

        $url = trailingslashit($upload_dir['baseurl']) . 'tix-support/' . intval($ticket_id) . '/' . $filename;

        // Attachment-Meta auf dem Ticket
        $existing = get_post_meta($ticket_id, '_tix_sp_attachments', true);
        if (!is_array($existing)) $existing = [];
        $existing[] = [
            'id'        => uniqid('att_', true),
            'name'      => $orig_name,
            'filename'  => $filename,
            'url'       => $url,
            'size'      => intval($file['size']),
            'mime'      => $detected_mime,
            'uploaded_by' => $by,
            'date'      => current_time('c'),
        ];
        update_post_meta($ticket_id, '_tix_sp_attachments', $existing);

        return [
            'name' => $orig_name,
            'url'  => $url,
            'size' => intval($file['size']),
            'mime' => $detected_mime,
        ];
    }

    // ══════════════════════════════════════════════
    // AJAX: KI-FEATURES (Anthropic)
    // ══════════════════════════════════════════════

    /**
     * Generiert eine 1-Satz-Zusammenfassung für die Liste, gecached pro Ticket.
     * Cache wird invalidiert sobald eine neue Nachricht hinzukommt.
     */
    public static function ajax_ai_summary() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $force     = !empty($_POST['force']);
        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_support_ticket') {
            wp_send_json_error('Anfrage nicht gefunden.');
        }

        // Cache-Schlüssel aus Hash der Nachrichten — automatische Invalidierung
        $messages = self::get_messages($ticket_id);
        $hash = md5(wp_json_encode($messages) . $post->post_title);
        $cached = get_post_meta($ticket_id, '_tix_sp_ai_summary', true);
        if (!$force && is_array($cached) && ($cached['hash'] ?? '') === $hash && !empty($cached['text'])) {
            wp_send_json_success(['summary' => $cached['text'], 'cached' => true]);
        }

        if (!class_exists('TIX_Support_AI')) {
            wp_send_json_error('KI-Modul nicht geladen.');
        }
        $summary = TIX_Support_AI::summarize_thread($post, $messages);
        if (is_wp_error($summary)) wp_send_json_error($summary->get_error_message());

        update_post_meta($ticket_id, '_tix_sp_ai_summary', [
            'hash' => $hash,
            'text' => $summary,
            'date' => current_time('c'),
        ]);

        wp_send_json_success(['summary' => $summary, 'cached' => false]);
    }

    /**
     * KI-Antwortvorschlag — analysiert Anfrage + Kontext (Bestellung, Tickets,
     * Kunden-Historie, Templates) und gibt einen Antwort-Entwurf zurück.
     */
    public static function ajax_ai_reply() {
        check_ajax_referer('tix_support_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_support_ticket') {
            wp_send_json_error('Anfrage nicht gefunden.');
        }
        if (!class_exists('TIX_Support_AI')) wp_send_json_error('KI-Modul nicht geladen.');

        // Kontext sammeln
        $messages    = self::get_messages($ticket_id);
        $email       = get_post_meta($ticket_id, '_tix_sp_email', true);
        $name        = get_post_meta($ticket_id, '_tix_sp_name', true);
        $category    = get_post_meta($ticket_id, '_tix_sp_category', true);
        $order_id    = intval(get_post_meta($ticket_id, '_tix_sp_order_id', true));
        $ticket_code = get_post_meta($ticket_id, '_tix_sp_ticket_code', true);

        $order_info = '';
        $tickets_info = '';
        if ($order_id) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
            if (!$order && class_exists('TIX_Order')) $order = TIX_Order::get($order_id);
            if ($order) {
                $info = self::format_order($order);
                $order_info = "Bestellung #{$info['id']} vom {$info['date']}, Status: {$info['status']}, Total: {$info['total']} €";
                if (!empty($info['items'])) {
                    $items_strs = array_map(fn($it) => $it['qty'] . '× ' . $it['name'], $info['items']);
                    $order_info .= "\nArtikel: " . implode(', ', $items_strs);
                }
            }
            if (class_exists('TIX_Tickets')) {
                $tix = TIX_Tickets::get_all_tickets_for_order($order_id);
                if (!empty($tix)) {
                    $tickets_info = "Tickets der Bestellung:\n";
                    foreach ($tix as $t) {
                        $tickets_info .= "- " . ($t['code'] ?? '?') . " · " . ($t['event_name'] ?? '') . " · " . ($t['cat_name'] ?? '') . " · Inhaber: " . ($t['owner_name'] ?? '–') . "\n";
                    }
                }
            }
        }

        // Kanned-Templates
        $templates = class_exists('TIX_Support_Templates') ? TIX_Support_Templates::get_for_category($category) : [];

        $reply = TIX_Support_AI::suggest_reply([
            'subject'      => $post->post_title,
            'category'     => $category,
            'customer'     => ['name' => $name, 'email' => $email],
            'messages'     => $messages,
            'order_info'   => $order_info,
            'tickets_info' => $tickets_info,
            'ticket_code'  => $ticket_code,
            'templates'    => $templates,
        ]);

        if (is_wp_error($reply)) wp_send_json_error($reply->get_error_message());

        wp_send_json_success(['reply' => $reply]);
    }

    // ══════════════════════════════════════════════
    // AJAX: NEUE ANFRAGE ERSTELLEN (Frontend + Admin)
    // ══════════════════════════════════════════════

    public static function ajax_create() {
        check_ajax_referer('tix_support_action', 'nonce');

        $email    = sanitize_email($_POST['email'] ?? '');
        $name     = sanitize_text_field($_POST['name'] ?? '');
        $subject  = sanitize_text_field($_POST['subject'] ?? '');
        $category = sanitize_text_field($_POST['category'] ?? 'other');
        $content  = sanitize_textarea_field($_POST['content'] ?? '');
        $order_id = sanitize_text_field($_POST['order_id'] ?? '');
        $ticket_code = sanitize_text_field($_POST['ticket_code'] ?? '');

        if (!is_email($email) || empty($subject) || empty($content)) {
            wp_send_json_error('E-Mail, Betreff und Nachricht sind Pflichtfelder.');
        }

        // Anfrage erstellen
        $post_id = wp_insert_post([
            'post_type'   => 'tix_support_ticket',
            'post_title'  => $subject,
            'post_status' => 'tix_open',
            'post_author' => get_current_user_id(),
        ]);

        if (is_wp_error($post_id)) {
            wp_send_json_error('Anfrage konnte nicht erstellt werden.');
        }

        // Meta-Felder setzen
        $access_key = self::generate_access_key();
        update_post_meta($post_id, '_tix_sp_email',      $email);
        update_post_meta($post_id, '_tix_sp_name',       $name);
        update_post_meta($post_id, '_tix_sp_category',   $category);
        update_post_meta($post_id, '_tix_sp_priority',   'normal');
        update_post_meta($post_id, '_tix_sp_access_key', $access_key);
        update_post_meta($post_id, '_tix_sp_last_reply',  current_time('c'));

        if ($order_id) {
            update_post_meta($post_id, '_tix_sp_order_id', intval(str_replace('#', '', $order_id)));
        }
        if ($ticket_code) {
            update_post_meta($post_id, '_tix_sp_ticket_code', strtoupper($ticket_code));
        }

        // Erste Nachricht speichern
        $msg = [
            'id'      => self::generate_message_id(),
            'type'    => 'customer',
            'author'  => $name ?: $email,
            'email'   => $email,
            'content' => $content,
            'date'    => current_time('c'),
        ];
        update_post_meta($post_id, '_tix_sp_messages', wp_json_encode([$msg]));

        // E-Mail an Admin
        self::send_email_new_ticket_admin($post_id, $subject, $email, $name);

        // Bestätigungs-E-Mail an Kunden
        self::send_email_new_ticket_customer($post_id, $subject, $email, $name, $access_key);

        wp_send_json_success([
            'ticket_id'  => $post_id,
            'access_key' => $access_key,
            'message'    => 'Anfrage wurde erfolgreich erstellt.',
        ]);
    }

    // ══════════════════════════════════════════════
    // AJAX: KUNDEN-ANTWORT (Frontend)
    // ══════════════════════════════════════════════

    public static function ajax_customer_reply() {
        check_ajax_referer('tix_support_action', 'nonce');

        $ticket_id      = intval($_POST['ticket_id'] ?? 0);
        $email          = sanitize_email($_POST['email'] ?? '');
        $access_key     = sanitize_text_field($_POST['access_key'] ?? '');
        $content        = sanitize_textarea_field($_POST['content'] ?? '');
        $attached_files = (array) ($_POST['attached_files'] ?? []);

        if (!$ticket_id || !is_email($email) || empty($content)) {
            wp_send_json_error('Fehlende Daten.');
        }

        // Zugriff prüfen
        if (!self::verify_customer_access($ticket_id, $email, $access_key)) {
            wp_send_json_error('Zugriff verweigert.');
        }

        // Attachments validieren (URL muss aus dieser Ticket-Upload-Struktur stammen)
        $upload_dir = wp_upload_dir();
        $allowed_prefix = trailingslashit($upload_dir['baseurl']) . 'tix-support/' . intval($ticket_id) . '/';
        $valid_files = [];
        foreach ($attached_files as $f) {
            if (!is_array($f)) continue;
            $url = esc_url_raw($f['url'] ?? '');
            if ($url && strpos($url, $allowed_prefix) === 0) {
                $valid_files[] = [
                    'url'  => $url,
                    'name' => sanitize_text_field($f['name'] ?? basename($url)),
                    'mime' => sanitize_text_field($f['mime'] ?? ''),
                ];
            }
        }

        $post = get_post($ticket_id);
        $name = get_post_meta($ticket_id, '_tix_sp_name', true) ?: $email;

        $msg = [
            'id'          => self::generate_message_id(),
            'type'        => 'customer',
            'author'      => $name,
            'email'       => $email,
            'content'     => $content,
            'date'        => current_time('c'),
            'attachments' => $valid_files,
        ];

        self::add_message($ticket_id, $msg);
        update_post_meta($ticket_id, '_tix_sp_last_reply', current_time('c'));

        // Status zurück auf "Offen" wenn gelöst/geschlossen
        if (in_array($post->post_status, ['tix_resolved', 'tix_closed'])) {
            wp_update_post(['ID' => $ticket_id, 'post_status' => 'tix_open']);
        }

        // Admin benachrichtigen
        self::send_email_customer_reply_admin($ticket_id, $post->post_title, $email, $content);

        wp_send_json_success(['message' => $msg]);
    }

    // ══════════════════════════════════════════════
    // AJAX: KUNDEN-AUTHENTIFIZIERUNG (Frontend)
    // ══════════════════════════════════════════════

    public static function ajax_customer_auth() {
        check_ajax_referer('tix_support_action', 'nonce');

        $email    = sanitize_email($_POST['email'] ?? '');
        $order_id = sanitize_text_field($_POST['order_id'] ?? '');

        if (!is_email($email)) {
            wp_send_json_error('Bitte gib eine gültige E-Mail-Adresse ein.');
        }

        // Option 1: Eingeloggter User → E-Mail muss zur WP-Adresse passen → kein Order nötig
        $user = wp_get_current_user();
        if ($user->ID && strtolower($user->user_email) === strtolower($email)) {
            $access_key = self::ensure_access_key_for_email($email);

            // Bestellungen des Users laden
            $orders_data = [];
            if (function_exists('wc_get_orders')) {
                $orders = wc_get_orders([
                    'customer_id' => $user->ID,
                    'limit'       => 20,
                    'orderby'     => 'date',
                    'order'       => 'DESC',
                    'status'      => ['wc-completed', 'wc-processing', 'wc-on-hold'],
                ]);
                // Fallback: per E-Mail suchen
                if (empty($orders)) {
                    $orders = wc_get_orders([
                        'billing_email' => $email,
                        'limit'         => 20,
                        'orderby'       => 'date',
                        'order'         => 'DESC',
                        'status'        => ['wc-completed', 'wc-processing', 'wc-on-hold'],
                    ]);
                }
                foreach ($orders as $o) {
                    $orders_data[] = [
                        'id'     => $o->get_id(),
                        'date'   => $o->get_date_created() ? $o->get_date_created()->format('d.m.Y') : '',
                        'total'  => $o->get_total(),
                        'status' => wc_get_order_status_name($o->get_status()),
                    ];
                }
            }

            // Native orders
            if (class_exists('TIX_Order')) {
                $native_orders = TIX_Order::query(['email' => $email, 'limit' => 10]);
                foreach ($native_orders as $no) {
                    $orders_data[] = [
                        'id'     => $no->get_id(),
                        'date'   => $no->get_date_created() ? $no->get_date_created()->date_i18n('d.m.Y') : '',
                        'total'  => $no->get_total(),
                        'status' => $no->get_status(),
                        'type'   => 'native',
                    ];
                }
            }

            wp_send_json_success([
                'access_key' => $access_key,
                'name'       => $user->display_name,
                'orders'     => $orders_data,
            ]);
        }

        // Option 2: Bestellnummer prüfen (Gast)
        $oid = !empty($order_id) ? intval(str_replace('#', '', $order_id)) : 0;
        if ($oid) {
            $order = function_exists('wc_get_order') ? wc_get_order($oid) : false;
            if (!$order && class_exists('TIX_Order')) {
                $order = TIX_Order::get($oid);
            }
            if ($order && strtolower($order->get_billing_email()) === strtolower($email)) {
                $access_key = self::ensure_access_key_for_email($email);
                $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                wp_send_json_success(['access_key' => $access_key, 'name' => trim($name)]);
            }
        }

        wp_send_json_error('Authentifizierung fehlgeschlagen. Bitte E-Mail und Bestellnummer überprüfen.');
    }

    // ══════════════════════════════════════════════
    // AJAX: KUNDEN-LISTE (Frontend)
    // ══════════════════════════════════════════════

    public static function ajax_customer_list() {
        check_ajax_referer('tix_support_action', 'nonce');

        $email      = sanitize_email($_POST['email'] ?? '');
        $access_key = sanitize_text_field($_POST['access_key'] ?? '');

        if (!is_email($email)) {
            wp_send_json_error('Ungültige E-Mail.');
        }

        // Zugriff prüfen (mindestens ein Ticket muss mit diesem Key verknüpft sein)
        if (!self::verify_email_access($email, $access_key)) {
            wp_send_json_error('Zugriff verweigert.');
        }

        $posts = get_posts([
            'post_type'   => 'tix_support_ticket',
            'post_status' => ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'],
            'meta_key'    => '_tix_sp_email',
            'meta_value'  => $email,
            'numberposts' => 50,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        $tickets = [];
        foreach ($posts as $p) {
            $t = self::format_support_ticket($p);
            // Letzte Nachricht als Vorschau (nur customer + admin, keine notes)
            $messages = self::get_messages($p->ID);
            $visible = array_filter($messages, function($m) { return $m['type'] !== 'note'; });
            $last = end($visible);
            $t['last_message_preview'] = $last ? wp_trim_words($last['content'], 15) : '';
            $t['last_message_type']    = $last ? $last['type'] : '';
            $tickets[] = $t;
        }

        wp_send_json_success(['tickets' => $tickets]);
    }

    // ══════════════════════════════════════════════
    // AJAX: KUNDEN-DETAIL (Frontend)
    // ══════════════════════════════════════════════

    public static function ajax_customer_detail() {
        check_ajax_referer('tix_support_action', 'nonce');

        $ticket_id  = intval($_POST['ticket_id'] ?? 0);
        $email      = sanitize_email($_POST['email'] ?? '');
        $access_key = sanitize_text_field($_POST['access_key'] ?? '');

        if (!$ticket_id || !is_email($email)) {
            wp_send_json_error('Fehlende Daten.');
        }

        if (!self::verify_customer_access($ticket_id, $email, $access_key)) {
            wp_send_json_error('Zugriff verweigert.');
        }

        $post = get_post($ticket_id);
        $ticket = self::format_support_ticket($post);

        // Nur sichtbare Nachrichten (keine internen Notizen)
        $messages = self::get_messages($ticket_id);
        $ticket['messages'] = array_values(array_filter($messages, function($m) {
            return $m['type'] !== 'note';
        }));

        wp_send_json_success($ticket);
    }

    // ══════════════════════════════════════════════
    // NACHRICHTEN HELPERS
    // ══════════════════════════════════════════════

    private static function get_messages($ticket_id) {
        $raw = get_post_meta($ticket_id, '_tix_sp_messages', true);
        if (empty($raw)) return [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    /** Public-Wrapper für TIX_Support_AI::cron_summarize. */
    public static function get_messages_public($ticket_id) {
        return self::get_messages($ticket_id);
    }

    private static function add_message($ticket_id, $msg) {
        $messages = self::get_messages($ticket_id);
        $messages[] = $msg;
        update_post_meta($ticket_id, '_tix_sp_messages', wp_json_encode($messages));

        // KI-Summary asynchron triggern (best effort, fail-soft)
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 5, 'tix_sp_summary_async', [intval($ticket_id)]);
        }
    }

    // ══════════════════════════════════════════════
    // ZUGRIFFS-PRÜFUNG (Frontend)
    // ══════════════════════════════════════════════

    private static function verify_customer_access($ticket_id, $email, $access_key) {
        $stored_email = get_post_meta($ticket_id, '_tix_sp_email', true);
        $stored_key   = get_post_meta($ticket_id, '_tix_sp_access_key', true);

        if (strtolower($stored_email) !== strtolower($email)) return false;
        if ($stored_key && $stored_key === $access_key) return true;

        // Eingeloggter User-Check
        $user = wp_get_current_user();
        if ($user->ID && strtolower($user->user_email) === strtolower($email)) return true;

        return false;
    }

    private static function verify_email_access($email, $access_key) {
        // Eingeloggter User
        $user = wp_get_current_user();
        if ($user->ID && strtolower($user->user_email) === strtolower($email)) return true;

        // Mindestens ein Ticket mit diesem Access-Key
        $posts = get_posts([
            'post_type'   => 'tix_support_ticket',
            'post_status' => ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'],
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => '_tix_sp_email',      'value' => $email],
                ['key' => '_tix_sp_access_key', 'value' => $access_key],
            ],
            'numberposts' => 1,
        ]);

        return !empty($posts);
    }

    /**
     * Stellt sicher, dass alle Tickets einer E-Mail denselben Access-Key haben.
     */
    private static function ensure_access_key_for_email($email) {
        $posts = get_posts([
            'post_type'   => 'tix_support_ticket',
            'post_status' => ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'],
            'meta_key'    => '_tix_sp_email',
            'meta_value'  => $email,
            'numberposts' => 1,
        ]);

        if (!empty($posts)) {
            $key = get_post_meta($posts[0]->ID, '_tix_sp_access_key', true);
            if ($key) return $key;
        }

        // Neuen Key generieren (wird erst beim nächsten Ticket-Erstellen gespeichert)
        return self::generate_access_key();
    }

    // ══════════════════════════════════════════════
    // FORMAT HELPERS
    // ══════════════════════════════════════════════

    private static function format_support_ticket($post) {
        $statuses = self::get_statuses();
        $categories = self::get_categories();
        $status = $post->post_status;
        $cat_slug = get_post_meta($post->ID, '_tix_sp_category', true);
        $cat_label = $cat_slug;
        foreach ($categories as $c) {
            if ($c['slug'] === $cat_slug) { $cat_label = $c['label']; break; }
        }

        $ai_summary  = get_post_meta($post->ID, '_tix_sp_ai_summary', true);
        $attachments = get_post_meta($post->ID, '_tix_sp_attachments', true);

        return [
            'id'           => $post->ID,
            'subject'      => $post->post_title,
            'status'       => $status,
            'status_label' => $statuses[$status]['label'] ?? $status,
            'status_color' => $statuses[$status]['color'] ?? '#6b7280',
            'email'        => get_post_meta($post->ID, '_tix_sp_email', true),
            'name'         => get_post_meta($post->ID, '_tix_sp_name', true),
            'category'     => $cat_slug,
            'category_label' => $cat_label,
            'priority'     => get_post_meta($post->ID, '_tix_sp_priority', true) ?: 'normal',
            'order_id'     => get_post_meta($post->ID, '_tix_sp_order_id', true),
            'ticket_code'  => get_post_meta($post->ID, '_tix_sp_ticket_code', true),
            'last_reply'   => get_post_meta($post->ID, '_tix_sp_last_reply', true),
            'date'         => $post->post_date,
            'date_formatted' => wp_date('d.m.Y H:i', strtotime($post->post_date)),
            'ai_summary'   => is_array($ai_summary) ? ($ai_summary['text'] ?? '') : '',
            'attachments'  => is_array($attachments) ? array_values($attachments) : [],
        ];
    }

    private static function format_order($order) {
        // Erkennt automatisch WC-Order vs TIX_Order (native) — verwendet die jeweils richtige API
        $is_wc = class_exists('WC_Order') && $order instanceof \WC_Order;

        if ($is_wc) {
            return [
                'id'       => $order->get_id(),
                'status'   => $order->get_status(),
                'total'    => floatval($order->get_total()),
                'currency' => $order->get_currency(),
                'date'     => $order->get_date_created() ? $order->get_date_created()->format('d.m.Y H:i') : '',
                'email'    => $order->get_billing_email(),
                'name'     => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'items'    => array_map(function($item) {
                    return ['name' => $item->get_name(), 'qty' => $item->get_quantity()];
                }, $order->get_items()),
                'edit_url' => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
            ];
        }

        // Native TIX_Order
        global $wpdb;
        $order_id = method_exists($order, 'get_id') ? $order->get_id() : 0;
        $items_table = $wpdb->prefix . 'tix_order_items';
        $items_rows = $order_id ? $wpdb->get_results($wpdb->prepare(
            "SELECT item_name, quantity FROM $items_table WHERE order_id = %d", $order_id
        )) : [];

        return [
            'id'       => $order_id,
            'status'   => method_exists($order, 'get_status') ? $order->get_status() : '',
            'total'    => method_exists($order, 'get_total') ? floatval($order->get_total()) : 0,
            'currency' => 'EUR',
            'date'     => method_exists($order, 'get_date_created')
                ? (function() use ($order) {
                    $d = $order->get_date_created();
                    if (!$d) return '';
                    if (is_object($d) && method_exists($d, 'format')) return $d->format('d.m.Y H:i');
                    if (is_string($d)) return wp_date('d.m.Y H:i', strtotime($d));
                    return '';
                })() : '',
            'email'    => method_exists($order, 'get_billing_email') ? $order->get_billing_email() : '',
            'name'     => trim(
                (method_exists($order, 'get_billing_first_name') ? $order->get_billing_first_name() : '') . ' ' .
                (method_exists($order, 'get_billing_last_name')  ? $order->get_billing_last_name()  : '')
            ),
            'items'    => array_map(function($it) {
                return ['name' => $it->item_name ?: 'Ticket', 'qty' => intval($it->quantity)];
            }, $items_rows),
            'edit_url' => admin_url('admin.php?page=tix-orders&order_id=' . $order_id),
        ];
    }

    /**
     * Normalisiert ein Ticket auf die JS-Struktur, egal ob's ein WP_Post
     * (von get_ticket_by_code), ein assoziatives Array (von TIX_Tickets::
     * get_all_tickets_for_order) oder eine Mischung daraus ist.
     */
    private static function format_ticket($ticket) {
        // ── Unified-Array (z.B. aus get_all_tickets_for_order) ──
        if (is_array($ticket)) {
            $ticket_id = intval($ticket['id'] ?? 0);
            $event_name = $ticket['event_name'] ?? $ticket['event'] ?? '';
            // Falls Event-Name fehlt, aus event_id nachladen
            if (!$event_name && !empty($ticket['event_id'])) {
                $ev = get_post(intval($ticket['event_id']));
                if ($ev) $event_name = $ev->post_title;
            }
            // Falls Code fehlt aber ID da → aus Meta nachholen
            $code = $ticket['code'] ?? '';
            if (!$code && $ticket_id) {
                $code = get_post_meta($ticket_id, '_tix_ticket_code', true);
            }
            return [
                'id'           => $ticket_id,
                'code'         => $code,
                'event'        => $event_name,
                'event_id'     => intval($ticket['event_id'] ?? 0),
                'owner'        => $ticket['owner_name'] ?? $ticket['owner'] ?? '',
                'email'        => $ticket['owner_email'] ?? $ticket['email'] ?? '',
                'status'       => $ticket['status'] ?? '',
                'order_id'     => intval($ticket['order_id'] ?? 0),
                'edit_url'     => $ticket_id ? admin_url('post.php?post=' . $ticket_id . '&action=edit') : '',
                'download_url' => $ticket['download_url'] ?? (($ticket_id && class_exists('TIX_Tickets')) ? TIX_Tickets::get_download_url($ticket_id) : ''),
                'seat_id'      => $ticket_id ? get_post_meta($ticket_id, '_tix_ticket_seat_id', true) : '',
                'seat_label'   => $ticket_id ? get_post_meta($ticket_id, '_tix_ticket_seat_label', true) : '',
                'cat_name'     => $ticket['cat_name'] ?? '',
            ];
        }

        // ── WP_Post (legacy: aus get_ticket_by_code, get_tickets_by_order) ──
        if (is_object($ticket) && isset($ticket->ID)) {
            $ticket_id  = intval($ticket->ID);
            $event_id   = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
            $event_name = get_post_meta($ticket_id, '_tix_ticket_event_name', true);
            if (!$event_name && $event_id) {
                $ev = get_post($event_id);
                if ($ev) $event_name = $ev->post_title;
            }
            // Kategorie-Name aus Event-Meta
            $cat_index = intval(get_post_meta($ticket_id, '_tix_ticket_cat_index', true));
            $cats      = get_post_meta($event_id, '_tix_ticket_categories', true);
            $cat_name  = (is_array($cats) && isset($cats[$cat_index]['name'])) ? $cats[$cat_index]['name'] : '';

            return [
                'id'           => $ticket_id,
                'code'         => get_post_meta($ticket_id, '_tix_ticket_code', true),
                'event'        => $event_name,
                'event_id'     => $event_id,
                'owner'        => get_post_meta($ticket_id, '_tix_ticket_owner_name', true),
                'email'        => get_post_meta($ticket_id, '_tix_ticket_owner_email', true),
                'status'       => $ticket->post_status,
                'order_id'     => intval(get_post_meta($ticket_id, '_tix_ticket_order_id', true)),
                'edit_url'     => admin_url('post.php?post=' . $ticket_id . '&action=edit'),
                'download_url' => class_exists('TIX_Tickets') ? TIX_Tickets::get_download_url($ticket_id) : '',
                'seat_id'      => get_post_meta($ticket_id, '_tix_ticket_seat_id', true),
                'seat_label'   => get_post_meta($ticket_id, '_tix_ticket_seat_label', true),
                'cat_name'     => $cat_name,
            ];
        }

        // Fallback: leeres Schema, damit JS nicht crasht
        return [
            'id' => 0, 'code' => '', 'event' => '', 'event_id' => 0,
            'owner' => '', 'email' => '', 'status' => '', 'order_id' => 0,
            'edit_url' => '', 'download_url' => '', 'seat_id' => '', 'seat_label' => '', 'cat_name' => '',
        ];
    }

    private static function format_customer_from_order($order) {
        return [
            'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ];
    }

    // ══════════════════════════════════════════════
    // E-MAIL NOTIFICATIONS
    // ══════════════════════════════════════════════

    /**
     * Neue Anfrage → Admin benachrichtigen
     */
    private static function send_email_new_ticket_admin($ticket_id, $subject, $customer_email, $customer_name) {
        $admin_email = get_option('admin_email');
        $body = '<p>Neue Support-Anfrage von <strong>' . esc_html($customer_name ?: $customer_email) . '</strong></p>';
        $body .= '<p><strong>Betreff:</strong> ' . esc_html($subject) . '</p>';
        $body .= '<p><strong>Anfrage-Nr:</strong> #' . $ticket_id . '</p>';
        $body .= '<p><a href="' . esc_url(admin_url('admin.php?page=tix-support&ticket=' . $ticket_id)) . '" style="display:inline-block;padding:12px 24px;' . tix_btn_style() . 'text-decoration:none;font-weight:700;">Anfrage öffnen</a></p>';

        $html = TIX_Emails::build_generic_email_html(
            'Neue Support-Anfrage',
            $body,
            '#' . $ticket_id . ' – ' . $subject
        );

        wp_mail($admin_email, 'Neue Support-Anfrage: ' . $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * Neue Anfrage → Bestätigung an Kunden
     */
    private static function send_email_new_ticket_customer($ticket_id, $subject, $email, $name, $access_key) {
        $body = '<p>Hallo ' . esc_html($name ?: 'Kunde') . ',</p>';
        $body .= '<p>wir haben deine Anfrage erhalten und werden uns so schnell wie möglich darum kümmern.</p>';
        $body .= '<p><strong>Anfrage-Nr:</strong> #' . $ticket_id . '<br>';
        $body .= '<strong>Betreff:</strong> ' . esc_html($subject) . '</p>';
        $body .= '<p>Du erhältst eine E-Mail, sobald wir dir antworten.</p>';

        $html = TIX_Emails::build_generic_email_html(
            'Anfrage empfangen',
            $body,
            '#' . $ticket_id
        );

        wp_mail($email, 'Deine Anfrage wurde empfangen – #' . $ticket_id, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * Admin-Antwort → E-Mail an Kunden
     */
    private static function send_email_reply_to_customer($ticket_id, $subject, $email, $reply_content, $attach_order_id = 0, $extra_files = []) {
        $name = get_post_meta($ticket_id, '_tix_sp_name', true);
        $body = '<p>Hallo ' . esc_html($name ?: 'Kunde') . ',</p>';
        $body .= '<p>du hast eine neue Antwort zu deiner Anfrage <strong>#' . $ticket_id . '</strong> erhalten:</p>';
        $body .= '<div style="background:#FAF8F4;border-left:4px solid ' . tix_primary() . ';padding:16px;border-radius:8px;margin:16px 0;">';
        $body .= nl2br(esc_html($reply_content));
        $body .= '</div>';

        // ── Datei-Anhänge des Admins (vom Upload-Toolbar) ──
        $extra_attach_files = [];
        $upload_dir = wp_upload_dir();
        if (!empty($extra_files)) {
            $body .= '<div style="margin:16px 0;padding:12px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">';
            $body .= '<strong style="font-size:13px;color:#475569;">📎 Anhänge:</strong><ul style="margin:8px 0 0;padding-left:20px;">';
            foreach ($extra_files as $f) {
                $url = $f['url'] ?? '';
                $name_f = $f['name'] ?? basename($url);
                $body .= '<li style="font-size:13px;margin:3px 0;"><a href="' . esc_url($url) . '" style="color:#2563eb;text-decoration:none;">' . esc_html($name_f) . '</a></li>';
                // URL → Dateipfad ableiten und als wp_mail-Attachment beilegen
                $rel = str_replace(trailingslashit($upload_dir['baseurl']), '', $url);
                $abs = trailingslashit($upload_dir['basedir']) . $rel;
                if (file_exists($abs)) $extra_attach_files[] = $abs;
            }
            $body .= '</ul></div>';
        }

        // ── Optional: Tickets anhängen ──
        $attachments     = [];
        $temp_files      = [];
        $attached_count  = 0;
        $attach_message  = '';
        if ($attach_order_id > 0 && class_exists('TIX_Tickets')) {
            $tickets = TIX_Tickets::get_all_tickets_for_order($attach_order_id);
            if (!empty($tickets)) {
                $upload_dir = wp_upload_dir();
                $tmp_dir = trailingslashit($upload_dir['basedir']) . 'tix-support-mail-tmp';
                if (!file_exists($tmp_dir)) {
                    @wp_mkdir_p($tmp_dir);
                    @file_put_contents($tmp_dir . '/.htaccess', "deny from all\n");
                }

                $ticket_items = [];
                foreach ($tickets as $t) {
                    $ticket_pid = intval($t['id'] ?? 0);
                    $code       = $t['code'] ?? '';
                    $dlurl      = $t['download_url'] ?? '';
                    if (!$dlurl && $ticket_pid) {
                        $dlurl = TIX_Tickets::get_download_url($ticket_pid);
                    }

                    if ($ticket_pid && TIX_Tickets::has_pdf_template($ticket_pid)) {
                        $pdf_binary = TIX_Tickets::get_pdf_binary($ticket_pid);
                        if ($pdf_binary) {
                            $safe_code = preg_replace('/[^A-Z0-9]/i', '', $code) ?: (string) $ticket_pid;
                            $file_path = $tmp_dir . '/ticket-' . $safe_code . '.pdf';
                            if (file_put_contents($file_path, $pdf_binary)) {
                                $attachments[] = $file_path;
                                $temp_files[]  = $file_path;
                                $attached_count++;
                            }
                        }
                    }

                    $label = $ticket_pid ? TIX_Tickets::ticket_type_label($ticket_pid) : 'Ticket';
                    $li  = '<li style="padding:10px 0;border-bottom:1px solid #f3f4f6;">';
                    $li .= '<strong>' . esc_html($code) . '</strong>';
                    if ($dlurl) $li .= ' <a href="' . esc_url($dlurl) . '" style="color:#2563eb;text-decoration:none;margin-left:8px;">' . esc_html($label) . ' öffnen →</a>';
                    $li .= '</li>';
                    $ticket_items[] = $li;
                }

                $body .= '<hr style="margin:24px 0;border:none;border-top:1px solid #e5e7eb;">';
                $body .= '<h3 style="margin:0 0 12px;font-size:15px;">Deine Tickets</h3>';
                if ($attached_count > 0) {
                    $body .= '<p style="margin:0 0 12px;font-size:13px;color:#64748b;">Deine Tickets hängen als <strong>PDF</strong> an dieser E-Mail. Falls du sie nicht öffnen kannst, nutze die Links unten.</p>';
                } else {
                    $body .= '<p style="margin:0 0 12px;font-size:13px;color:#64748b;">Deine Online-Tickets kannst du über die folgenden Links abrufen:</p>';
                }
                $body .= '<ul style="list-style:none;padding:0;margin:0;">' . implode('', $ticket_items) . '</ul>';

                $total = count($tickets);
                if ($attached_count > 0) {
                    $attach_message = $attached_count . ' Ticket-PDF' . ($attached_count > 1 ? 's' : '') . ' angehängt + ' . $total . ' Download-Link' . ($total > 1 ? 's' : '') . ' im Body';
                } else {
                    $attach_message = $total . ' Ticket-Download-Link' . ($total > 1 ? 's' : '') . ' im Mail-Body';
                }
            }
        }

        $html = TIX_Emails::build_generic_email_html(
            'Neue Antwort',
            $body,
            '#' . $ticket_id . ' – ' . $subject
        );

        // Datei-Anhänge zur wp_mail-Liste hinzufügen
        $all_attachments = array_merge($attachments, $extra_attach_files);

        wp_mail($email, 'Neue Antwort zu deiner Anfrage #' . $ticket_id, $html, ['Content-Type: text/html; charset=UTF-8'], $all_attachments);

        // Temp-PDFs aufräumen (Datei-Uploads bleiben — sind permanent gespeichert)
        foreach ($temp_files as $f) { @unlink($f); }

        // Anhang-Status erweitern
        if (!empty($extra_attach_files)) {
            $extra_count = count($extra_attach_files);
            $extra_msg = $extra_count . ' Datei' . ($extra_count > 1 ? 'en' : '') . ' angehängt';
            $attach_message = $attach_message ? ($attach_message . ' · ' . $extra_msg) : $extra_msg;
        }

        return $attach_message;
    }

    /**
     * Kunden-Antwort → Admin benachrichtigen
     */
    private static function send_email_customer_reply_admin($ticket_id, $subject, $customer_email, $reply_content) {
        $admin_email = get_option('admin_email');
        $name = get_post_meta($ticket_id, '_tix_sp_name', true);
        $body = '<p>Neue Antwort von <strong>' . esc_html($name ?: $customer_email) . '</strong> zu Anfrage <strong>#' . $ticket_id . '</strong>:</p>';
        $body .= '<div style="background:#FAF8F4;border-left:4px solid #3b82f6;padding:16px;border-radius:8px;margin:16px 0;">';
        $body .= nl2br(esc_html($reply_content));
        $body .= '</div>';
        $body .= '<p><a href="' . esc_url(admin_url('admin.php?page=tix-support&ticket=' . $ticket_id)) . '" style="display:inline-block;padding:12px 24px;' . tix_btn_style() . 'text-decoration:none;font-weight:700;">Anfrage öffnen</a></p>';

        $html = TIX_Emails::build_generic_email_html(
            'Neue Kunden-Antwort',
            $body,
            '#' . $ticket_id . ' – ' . $subject
        );

        wp_mail($admin_email, 'Neue Kunden-Antwort: #' . $ticket_id . ' – ' . $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * Status → gelöst → Kunden benachrichtigen
     */
    private static function send_email_status_resolved($ticket_id, $subject, $email) {
        $name = get_post_meta($ticket_id, '_tix_sp_name', true);
        $body = '<p>Hallo ' . esc_html($name ?: 'Kunde') . ',</p>';
        $body .= '<p>deine Anfrage <strong>#' . $ticket_id . '</strong> wurde als <strong>gelöst</strong> markiert.</p>';
        $body .= '<p><strong>Betreff:</strong> ' . esc_html($subject) . '</p>';
        $body .= '<p>Falls du weitere Fragen hast, antworte einfach auf diese E-Mail oder erstelle eine neue Anfrage.</p>';

        $html = TIX_Emails::build_generic_email_html(
            'Anfrage gelöst',
            $body,
            '#' . $ticket_id
        );

        wp_mail($email, 'Deine Anfrage #' . $ticket_id . ' wurde gelöst', $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ══════════════════════════════════════════════
    // STATISTIKEN
    // ══════════════════════════════════════════════

    /**
     * Stats-Daten für Admin-Dashboard berechnen.
     * Wird per AJAX (ajax_list mit stats=1) oder direkt aufgerufen.
     */
    public static function get_stats() {
        $open = wp_count_posts('tix_support_ticket');

        $today = current_time('Y-m-d');
        $resolved_today = get_posts([
            'post_type'   => 'tix_support_ticket',
            'post_status' => 'tix_resolved',
            'date_query'  => [['after' => $today . ' 00:00:00', 'inclusive' => true]],
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        // Ø Antwortzeit berechnen (letzte 50 gelöste Anfragen)
        $resolved = get_posts([
            'post_type'   => 'tix_support_ticket',
            'post_status' => 'tix_resolved',
            'numberposts' => 50,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        $total_hours = 0;
        $count = 0;
        foreach ($resolved as $p) {
            $messages = self::get_messages($p->ID);
            $first_customer = null;
            $first_admin = null;
            foreach ($messages as $m) {
                if (!$first_customer && $m['type'] === 'customer') $first_customer = $m;
                if (!$first_admin && $m['type'] === 'admin') { $first_admin = $m; break; }
            }
            if ($first_customer && $first_admin) {
                $diff = strtotime($first_admin['date']) - strtotime($first_customer['date']);
                if ($diff > 0) {
                    $total_hours += $diff / 3600;
                    $count++;
                }
            }
        }
        $avg_hours = $count > 0 ? round($total_hours / $count, 1) : 0;

        // 7-Tage-Trend
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = wp_date('Y-m-d', strtotime("-{$i} days"));
            $label = wp_date('D', strtotime("-{$i} days"));
            $created = get_posts([
                'post_type'   => 'tix_support_ticket',
                'post_status' => ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'],
                'date_query'  => [
                    ['after' => $date . ' 00:00:00', 'before' => $date . ' 23:59:59', 'inclusive' => true],
                ],
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);
            $res = get_posts([
                'post_type'   => 'tix_support_ticket',
                'post_status' => 'tix_resolved',
                'date_query'  => [
                    ['after' => $date . ' 00:00:00', 'before' => $date . ' 23:59:59', 'inclusive' => true],
                ],
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);
            $trend[] = [
                'date'     => $date,
                'label'    => $label,
                'created'  => count($created),
                'resolved' => count($res),
            ];
        }

        return [
            'open'           => isset($open->tix_open) ? $open->tix_open : 0,
            'progress'       => isset($open->tix_progress) ? $open->tix_progress : 0,
            'resolved_today' => count($resolved_today),
            'avg_hours'      => $avg_hours,
            'trend'          => $trend,
        ];
    }

    // ══════════════════════════════════════════════
    // FRONTEND SHORTCODE: [tix_support]
    // ══════════════════════════════════════════════

    public static function render_shortcode($atts) {
        if (!tix_get_settings('support_enabled')) return '';

        $categories = self::get_categories();
        $statuses   = self::get_statuses();

        $ver = defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '1.23.0';
        wp_enqueue_style('tix-support-front', TIXOMAT_URL . 'assets/css/support.css', ['tix-google-fonts'], $ver);
        wp_enqueue_script('tix-support-front', TIXOMAT_URL . 'assets/js/support.js', ['jquery'], $ver, true);

        wp_localize_script('tix-support-front', 'tixSupport', [
            'ajax'       => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('tix_support_action'),
            'statuses'   => $statuses,
            'categories' => $categories,
            'isAdmin'    => false,
            'isFrontend' => true,
            'userEmail'  => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            'userName'   => is_user_logged_in() ? wp_get_current_user()->display_name : '',
        ]);

        ob_start();
        ?>
        <div class="tix-sp-front" id="tix-sp-frontend">
            <!-- Auth-Screen (wird per JS gesteuert) -->
            <div id="tix-sp-front-auth" class="tix-sp-front-section">
                <h2>Support-Portal</h2>
                <p>Melde dich an, um deine Anfragen zu sehen oder eine neue Anfrage zu erstellen.</p>
                <div class="tix-sp-front-form">
                    <input type="email" id="tix-sp-front-email" class="tix-sp-front-input" placeholder="Deine E-Mail-Adresse">
                    <input type="text" id="tix-sp-front-order" class="tix-sp-front-input" placeholder="Bestellnummer (z.B. #12345)">
                    <button type="button" id="tix-sp-front-auth-btn" class="tix-sp-front-btn">Anmelden</button>
                    <div id="tix-sp-front-auth-error" class="tix-sp-front-error" style="display:none;"></div>
                </div>
            </div>

            <!-- Meine Anfragen -->
            <div id="tix-sp-front-list" class="tix-sp-front-section" style="display:none;">
                <div class="tix-sp-front-header">
                    <h2>Meine Anfragen</h2>
                    <button type="button" id="tix-sp-front-new-btn" class="tix-sp-front-btn">+ Neue Anfrage</button>
                </div>
                <div id="tix-sp-front-tickets"></div>
            </div>

            <!-- Neue Anfrage -->
            <div id="tix-sp-front-create" class="tix-sp-front-section" style="display:none;">
                <h2>Neue Anfrage</h2>
                <div class="tix-sp-front-form">
                    <select id="tix-sp-front-category" class="tix-sp-front-input">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo esc_attr($cat['slug']); ?>"><?php echo esc_html($cat['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="tix-sp-front-order-wrap">
                        <input type="text" id="tix-sp-front-order-input" class="tix-sp-front-input" placeholder="Bestellnummer (optional, z.B. #12345)">
                    </div>
                    <input type="text" id="tix-sp-front-ticket-code" class="tix-sp-front-input" placeholder="Ticket-Code (optional, 12-stellig)">
                    <input type="text" id="tix-sp-front-subject" class="tix-sp-front-input" placeholder="Betreff *">
                    <textarea id="tix-sp-front-message" class="tix-sp-front-input" rows="5" placeholder="Deine Nachricht *"></textarea>
                    <div class="tix-sp-front-form-actions">
                        <button type="button" id="tix-sp-front-create-cancel" class="tix-sp-front-btn tix-sp-front-btn-ghost">Abbrechen</button>
                        <button type="button" id="tix-sp-front-create-submit" class="tix-sp-front-btn">Anfrage absenden</button>
                    </div>
                    <div id="tix-sp-front-create-error" class="tix-sp-front-error" style="display:none;"></div>
                </div>
            </div>

            <!-- Anfrage-Detail -->
            <div id="tix-sp-front-detail" class="tix-sp-front-section" style="display:none;">
                <button type="button" id="tix-sp-front-back-btn" class="tix-sp-front-btn tix-sp-front-btn-ghost">← Zurück</button>
                <div id="tix-sp-front-detail-content"></div>
            </div>
        </div>
        <?php
        $html = ob_get_clean();
        $html .= tix_branding_footer();
        return $html;
    }

    // ══════════════════════════════════════════════
    // FLOATING CHAT WIDGET
    // ══════════════════════════════════════════════

    public static function render_chat_widget() {
        // Nicht auf Admin-Seiten oder wenn Shortcode schon auf der Seite ist
        if (is_admin()) return;

        $categories = self::get_categories();
        $statuses   = self::get_statuses();
        $ver = defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '1.23.0';

        wp_enqueue_style('tix-support-chat', TIXOMAT_URL . 'assets/css/support.css', ['tix-google-fonts'], $ver);
        wp_enqueue_script('tix-support-chat', TIXOMAT_URL . 'assets/js/support.js', ['jquery'], $ver, true);

        wp_localize_script('tix-support-chat', 'tixSupport', [
            'ajax'         => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('tix_support_action'),
            'statuses'     => $statuses,
            'categories'   => $categories,
            'isAdmin'      => false,
            'isFrontend'   => false,
            'isChatWidget' => true,
            'userEmail'    => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            'userName'     => is_user_logged_in() ? wp_get_current_user()->display_name : '',
        ]);
        ?>
        <!-- Tixomat Chat Widget -->
        <div id="tix-sp-chat-widget">
            <button type="button" id="tix-sp-chat-toggle" class="tix-sp-chat-btn" aria-label="Support-Chat öffnen">
                <span class="tix-sp-chat-btn-icon">💬</span>
                <span class="tix-sp-chat-btn-close" style="display:none;">✕</span>
            </button>

            <div id="tix-sp-chat-panel" class="tix-sp-chat-panel" style="display:none;">
                <div class="tix-sp-chat-panel-header">
                    <h3>Support</h3>
                    <button type="button" id="tix-sp-chat-close" class="tix-sp-chat-panel-close">✕</button>
                </div>
                <div class="tix-sp-chat-panel-body">

                    <!-- Auth -->
                    <div id="tix-sp-chat-auth" class="tix-sp-front-section">
                        <p style="margin:0 0 12px;font-size:14px;color:#475569;">Wie können wir dir helfen?</p>
                        <div class="tix-sp-front-form">
                            <input type="email" id="tix-sp-chat-email" class="tix-sp-front-input" placeholder="Deine E-Mail-Adresse">
                            <input type="text" id="tix-sp-chat-order" class="tix-sp-front-input" placeholder="Bestellnummer (z.B. #12345)">
                            <button type="button" id="tix-sp-chat-auth-btn" class="tix-sp-front-btn">Anmelden</button>
                            <div id="tix-sp-chat-auth-error" class="tix-sp-front-error" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- Liste -->
                    <div id="tix-sp-chat-list" class="tix-sp-front-section" style="display:none;">
                        <div class="tix-sp-chat-list-header">
                            <span style="font-weight:600;font-size:14px;">Meine Anfragen</span>
                            <button type="button" id="tix-sp-chat-new-btn" class="tix-sp-front-btn" style="padding:5px 12px;font-size:12px;">+ Neu</button>
                        </div>
                        <div id="tix-sp-chat-tickets"></div>
                    </div>

                    <!-- Neue Anfrage -->
                    <div id="tix-sp-chat-create" class="tix-sp-front-section" style="display:none;">
                        <div class="tix-sp-front-form">
                            <select id="tix-sp-chat-category" class="tix-sp-front-input">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo esc_attr($cat['slug']); ?>"><?php echo esc_html($cat['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="tix-sp-chat-order-wrap">
                                <input type="text" id="tix-sp-chat-order-input" class="tix-sp-front-input" placeholder="Bestellnummer (optional)">
                            </div>
                            <input type="text" id="tix-sp-chat-subject" class="tix-sp-front-input" placeholder="Betreff *">
                            <textarea id="tix-sp-chat-message" class="tix-sp-front-input" rows="3" placeholder="Deine Nachricht *"></textarea>
                            <div class="tix-sp-front-form-actions">
                                <button type="button" id="tix-sp-chat-create-cancel" class="tix-sp-front-btn tix-sp-front-btn-ghost" style="font-size:12px;">Abbrechen</button>
                                <button type="button" id="tix-sp-chat-create-submit" class="tix-sp-front-btn" style="font-size:12px;">Absenden</button>
                            </div>
                            <div id="tix-sp-chat-create-error" class="tix-sp-front-error" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- Detail -->
                    <div id="tix-sp-chat-detail" class="tix-sp-front-section" style="display:none;">
                        <button type="button" id="tix-sp-chat-back-btn" class="tix-sp-front-btn tix-sp-front-btn-ghost" style="font-size:12px;margin-bottom:8px;">← Zurück</button>
                        <div id="tix-sp-chat-detail-content"></div>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }
}
