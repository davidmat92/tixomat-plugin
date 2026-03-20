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
                        if ($order) {
                            $result['orders'][] = self::format_order($order);
                            $result['customer'] = self::format_customer_from_order($order);
                        }
                    }
                }
            }
        }
        // ── Auto-Detect: Bestellnummer ──
        elseif (preg_match('/^#?(\d+)$/', $query, $m)) {
            $result['type'] = 'order';
            $order_id = intval($m[1]);
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $result['orders'][] = self::format_order($order);
                    $result['customer'] = self::format_customer_from_order($order);
                    // Tickets für diese Bestellung
                    if (class_exists('TIX_Tickets')) {
                        $tickets = TIX_Tickets::get_all_tickets_for_order($order_id);
                        foreach ($tickets as $t) {
                            $result['tickets'][] = self::format_ticket($t);
                        }
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
        // ── Freitext-Suche (Name) ──
        else {
            $result['type'] = 'name';
            // In Support-Tickets suchen
            $support_posts = get_posts([
                'post_type'   => 'tix_support_ticket',
                'post_status' => ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'],
                's'           => $query,
                'numberposts' => 20,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]);
            foreach ($support_posts as $sp) {
                $result['support'][] = self::format_support_ticket($sp);
            }

            // In Support-Meta suchen (Name)
            $by_name = get_posts([
                'post_type'   => 'tix_support_ticket',
                'post_status' => ['tix_open', 'tix_progress', 'tix_resolved', 'tix_closed'],
                'meta_key'    => '_tix_sp_name',
                'meta_value'  => $query,
                'meta_compare' => 'LIKE',
                'numberposts' => 20,
            ]);
            $existing_ids = array_column($result['support'], 'id');
            foreach ($by_name as $sp) {
                if (!in_array($sp->ID, $existing_ids)) {
                    $result['support'][] = self::format_support_ticket($sp);
                }
            }

            // WC-Bestellungen nach Kundenname suchen
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
        $order_id = get_post_meta($ticket_id, '_tix_sp_order_id', true);
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $ticket['order'] = self::format_order($order);
            }
        }

        // Verknüpftes Ticket (per Ticket-Code)
        $ticket_code = get_post_meta($ticket_id, '_tix_sp_ticket_code', true);
        if ($ticket_code && class_exists('TIX_Tickets')) {
            $t = TIX_Tickets::get_ticket_by_code($ticket_code);
            if ($t) {
                $ticket['linked_ticket'] = self::format_ticket($t);
            }
        }

        // Alle Tickets der verknüpften Bestellung
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

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $content   = sanitize_textarea_field($_POST['content'] ?? '');

        if (!$ticket_id || empty($content)) {
            wp_send_json_error('Ticket-ID und Nachricht erforderlich.');
        }

        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_support_ticket') {
            wp_send_json_error('Anfrage nicht gefunden.');
        }

        $user = wp_get_current_user();
        $msg = [
            'id'      => self::generate_message_id(),
            'type'    => 'admin',
            'author'  => $user->display_name ?: 'Support-Team',
            'user_id' => $user->ID,
            'content' => $content,
            'date'    => current_time('c'),
        ];

        self::add_message($ticket_id, $msg);

        // Status auf "In Bearbeitung" setzen, wenn noch "Offen"
        if ($post->post_status === 'tix_open') {
            wp_update_post(['ID' => $ticket_id, 'post_status' => 'tix_progress']);
        }

        // Letzte Antwort-Timestamp aktualisieren
        update_post_meta($ticket_id, '_tix_sp_last_reply', current_time('c'));

        // E-Mail an Kunden senden
        $email = get_post_meta($ticket_id, '_tix_sp_email', true);
        if ($email) {
            self::send_email_reply_to_customer($ticket_id, $post->post_title, $email, $content);
        }

        wp_send_json_success(['message' => $msg]);
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
            if ($order_id && function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
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
        $body .= '<p><a href="' . esc_url($download_url) . '" style="display:inline-block;padding:12px 24px;background:' . tix_primary() . ';color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Ticket herunterladen</a></p>';
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

        $ticket_id  = intval($_POST['ticket_id'] ?? 0);
        $email      = sanitize_email($_POST['email'] ?? '');
        $access_key = sanitize_text_field($_POST['access_key'] ?? '');
        $content    = sanitize_textarea_field($_POST['content'] ?? '');

        if (!$ticket_id || !is_email($email) || empty($content)) {
            wp_send_json_error('Fehlende Daten.');
        }

        // Zugriff prüfen
        if (!self::verify_customer_access($ticket_id, $email, $access_key)) {
            wp_send_json_error('Zugriff verweigert.');
        }

        $post = get_post($ticket_id);
        $name = get_post_meta($ticket_id, '_tix_sp_name', true) ?: $email;

        $msg = [
            'id'      => self::generate_message_id(),
            'type'    => 'customer',
            'author'  => $name,
            'email'   => $email,
            'content' => $content,
            'date'    => current_time('c'),
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

            wp_send_json_success([
                'access_key' => $access_key,
                'name'       => $user->display_name,
                'orders'     => $orders_data,
            ]);
        }

        // Option 2: Bestellnummer prüfen (Gast)
        if (!empty($order_id) && function_exists('wc_get_order')) {
            $oid = intval(str_replace('#', '', $order_id));
            $order = wc_get_order($oid);
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

    private static function add_message($ticket_id, $msg) {
        $messages = self::get_messages($ticket_id);
        $messages[] = $msg;
        update_post_meta($ticket_id, '_tix_sp_messages', wp_json_encode($messages));
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
        ];
    }

    private static function format_order($order) {
        return [
            'id'       => $order->get_id(),
            'status'   => $order->get_status(),
            'total'    => $order->get_total(),
            'currency' => $order->get_currency(),
            'date'     => $order->get_date_created() ? $order->get_date_created()->format('d.m.Y H:i') : '',
            'email'    => $order->get_billing_email(),
            'name'     => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'items'    => array_map(function($item) {
                return [
                    'name' => $item->get_name(),
                    'qty'  => $item->get_quantity(),
                ];
            }, $order->get_items()),
            'edit_url' => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
        ];
    }

    private static function format_ticket($ticket) {
        $ticket_id = $ticket->ID;
        return [
            'id'           => $ticket_id,
            'code'         => get_post_meta($ticket_id, '_tix_ticket_code', true),
            'event'        => get_post_meta($ticket_id, '_tix_ticket_event_name', true),
            'event_id'     => get_post_meta($ticket_id, '_tix_ticket_event_id', true),
            'owner'        => get_post_meta($ticket_id, '_tix_ticket_owner_name', true),
            'email'        => get_post_meta($ticket_id, '_tix_ticket_owner_email', true),
            'status'       => $ticket->post_status,
            'order_id'     => get_post_meta($ticket_id, '_tix_ticket_order_id', true),
            'edit_url'     => admin_url('post.php?post=' . $ticket_id . '&action=edit'),
            'download_url' => class_exists('TIX_Tickets') ? TIX_Tickets::get_download_url($ticket_id) : '',
            'seat_id'      => get_post_meta($ticket_id, '_tix_ticket_seat_id', true),
            'seat_label'   => get_post_meta($ticket_id, '_tix_ticket_seat_label', true),
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
        $body .= '<p><a href="' . esc_url(admin_url('admin.php?page=tix-support&ticket=' . $ticket_id)) . '" style="display:inline-block;padding:12px 24px;background:' . tix_primary() . ';color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Anfrage öffnen</a></p>';

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
    private static function send_email_reply_to_customer($ticket_id, $subject, $email, $reply_content) {
        $name = get_post_meta($ticket_id, '_tix_sp_name', true);
        $body = '<p>Hallo ' . esc_html($name ?: 'Kunde') . ',</p>';
        $body .= '<p>du hast eine neue Antwort zu deiner Anfrage <strong>#' . $ticket_id . '</strong> erhalten:</p>';
        $body .= '<div style="background:#FAF8F4;border-left:4px solid ' . tix_primary() . ';padding:16px;border-radius:8px;margin:16px 0;">';
        $body .= nl2br(esc_html($reply_content));
        $body .= '</div>';

        $html = TIX_Emails::build_generic_email_html(
            'Neue Antwort',
            $body,
            '#' . $ticket_id . ' – ' . $subject
        );

        wp_mail($email, 'Neue Antwort zu deiner Anfrage #' . $ticket_id, $html, ['Content-Type: text/html; charset=UTF-8']);
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
        $body .= '<p><a href="' . esc_url(admin_url('admin.php?page=tix-support&ticket=' . $ticket_id)) . '" style="display:inline-block;padding:12px 24px;background:' . tix_primary() . ';color:#fff;text-decoration:none;border-radius:8px;font-weight:700;">Anfrage öffnen</a></p>';

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
