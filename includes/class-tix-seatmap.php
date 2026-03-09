<?php
/**
 * Tixomat – Saalplan (Seat Map) System
 *
 * Registriert den CPT tix_seatmap, die DB-Tabelle für Reservierungen,
 * den Admin-Editor und alle AJAX-Endpoints.
 *
 * @since 1.22.0
 */
if (!defined('ABSPATH')) exit;

class TIX_Seatmap {

    const CPT          = 'tix_seatmap';
    const TABLE_SUFFIX = 'tix_seat_reservations';
    const RESERVE_MIN  = 15; // Minuten für temporäre Reservierung

    // ──────────────────────────────────────────
    // Init
    // ──────────────────────────────────────────
    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin']);

        // AJAX – Admin
        add_action('wp_ajax_tix_seatmap_save', [__CLASS__, 'ajax_save']);
        add_action('wp_ajax_tix_seatmap_load', [__CLASS__, 'ajax_load']);

        // AJAX – Frontend (Seat Picker)
        add_action('wp_ajax_tix_seat_availability',        [__CLASS__, 'ajax_availability']);
        add_action('wp_ajax_nopriv_tix_seat_availability', [__CLASS__, 'ajax_availability']);
        add_action('wp_ajax_tix_reserve_seats',            [__CLASS__, 'ajax_reserve']);
        add_action('wp_ajax_nopriv_tix_reserve_seats',     [__CLASS__, 'ajax_reserve']);
        add_action('wp_ajax_tix_release_seats',            [__CLASS__, 'ajax_release']);
        add_action('wp_ajax_nopriv_tix_release_seats',     [__CLASS__, 'ajax_release']);
        add_action('wp_ajax_tix_best_available',           [__CLASS__, 'ajax_best_available']);
        add_action('wp_ajax_nopriv_tix_best_available',    [__CLASS__, 'ajax_best_available']);

        // Shortcode
        add_shortcode('tix_seatmap', [__CLASS__, 'render_shortcode']);

        // Cron: Abgelaufene Reservierungen freigeben
        add_action('tix_cleanup_expired_seats', [__CLASS__, 'cleanup_expired']);
        if (!wp_next_scheduled('tix_cleanup_expired_seats')) {
            wp_schedule_event(time(), 'five_minutes', 'tix_cleanup_expired_seats');
        }

        // Cron-Intervall: 5 Minuten
        add_filter('cron_schedules', function($s) {
            $s['five_minutes'] = ['interval' => 300, 'display' => 'Alle 5 Minuten'];
            return $s;
        });

        // Order Events: Reservation → sold / released
        add_action('woocommerce_order_status_completed',  [__CLASS__, 'on_order_paid']);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'on_order_paid']);
        add_action('woocommerce_order_status_cancelled',  [__CLASS__, 'on_order_cancelled']);
        add_action('woocommerce_order_status_refunded',   [__CLASS__, 'on_order_cancelled']);
        add_action('woocommerce_order_status_failed',     [__CLASS__, 'on_order_cancelled']);
    }

    // ──────────────────────────────────────────
    // DB-Tabelle erstellen (Plugin-Aktivierung)
    // ──────────────────────────────────────────
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            seatmap_id BIGINT UNSIGNED NOT NULL,
            seat_id VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'reserved',
            session_id VARCHAR(100) DEFAULT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            ticket_id BIGINT UNSIGNED DEFAULT NULL,
            reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY seat_event_active (event_id, seat_id, status),
            KEY idx_session (session_id),
            KEY idx_order (order_id),
            KEY idx_expires (status, expires_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ──────────────────────────────────────────
    // CPT registrieren
    // ──────────────────────────────────────────
    public static function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name'               => 'Saalpläne',
                'singular_name'      => 'Saalplan',
                'add_new'            => 'Neuer Saalplan',
                'add_new_item'       => 'Neuen Saalplan anlegen',
                'edit_item'          => 'Saalplan bearbeiten',
                'all_items'          => 'Saalpläne',
                'search_items'       => 'Saalpläne suchen',
                'not_found'          => 'Keine Saalpläne gefunden',
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => 'tixomat',
            'supports'      => ['title'],
            'show_in_rest'  => false,
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);
    }

    // ──────────────────────────────────────────
    // Admin Assets
    // ──────────────────────────────────────────
    public static function enqueue_admin($hook) {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::CPT) return;

        wp_enqueue_style(
            'tix-seatmap-editor',
            TIXOMAT_URL . 'assets/css/seatmap-editor.css',
            [],
            TIXOMAT_VERSION
        );
        wp_enqueue_script(
            'tix-seatmap-editor',
            TIXOMAT_URL . 'assets/js/seatmap-editor.js',
            ['jquery', 'jquery-ui-draggable', 'jquery-ui-droppable'],
            TIXOMAT_VERSION,
            true
        );
        wp_localize_script('tix-seatmap-editor', 'tixSeatmap', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tix_seatmap'),
            'postId'  => get_the_ID(),
            'i18n'    => [
                'addSection'   => 'Sektion hinzufügen',
                'addRow'       => 'Reihe hinzufügen',
                'deleteRow'    => 'Reihe löschen',
                'deleteSection'=> 'Sektion löschen',
                'seatLabel'    => 'Sitz',
                'rowLabel'     => 'Reihe',
                'sectionLabel' => 'Sektion',
                'saved'        => 'Saalplan gespeichert!',
                'error'        => 'Fehler beim Speichern',
                'confirmDelete'=> 'Wirklich löschen?',
                'seatTypes'    => [
                    'standard'   => 'Standard',
                    'vip'        => 'VIP',
                    'wheelchair' => 'Rollstuhl',
                    'blocked'    => 'Gesperrt',
                ],
            ],
        ]);
    }

    // ──────────────────────────────────────────
    // Metabox: Saalplan-Editor
    // ──────────────────────────────────────────
    public static function register_metabox() {
        add_meta_box(
            'tix_seatmap_editor',
            'Saalplan-Editor',
            [__CLASS__, 'render_editor'],
            self::CPT,
            'normal',
            'high'
        );
    }

    public static function render_editor($post) {
        wp_nonce_field('tix_seatmap_save', 'tix_seatmap_nonce');
        $data = get_post_meta($post->ID, '_tix_seatmap_data', true) ?: '';
        ?>
        <div id="tix-seatmap-app" data-config="<?php echo esc_attr($data); ?>">

            <!-- Toolbar -->
            <div class="tix-sm-toolbar">
                <div class="tix-sm-toolbar-left">
                    <label class="tix-sm-tool-label">
                        <span>Layout:</span>
                        <select id="tix-sm-layout-select" class="tix-sm-select">
                            <option value="theater">Theater</option>
                            <option value="u-shape">U-Form</option>
                            <option value="stadium">Stadion</option>
                            <option value="arena">Arena</option>
                        </select>
                    </label>
                    <div class="tix-sm-tool-sep"></div>
                    <button type="button" class="button tix-sm-btn-add-section">
                        <span class="dashicons dashicons-plus-alt2"></span> Sektion
                    </button>
                    <div class="tix-sm-tool-sep"></div>
                    <label class="tix-sm-tool-label">
                        <span>Sitze/Reihe:</span>
                        <input type="number" id="tix-sm-seats-per-row" value="10" min="1" max="100" class="small-text">
                    </label>
                    <label class="tix-sm-tool-label">
                        <span>Reihen:</span>
                        <input type="number" id="tix-sm-rows-count" value="5" min="1" max="50" class="small-text">
                    </label>
                    <span id="tix-sm-stage-label-wrap" style="display:none">
                        <div class="tix-sm-tool-sep"></div>
                        <label class="tix-sm-tool-label">
                            <span>Bühne/Feld:</span>
                            <input type="text" id="tix-sm-stage-label" value="BÜHNE" class="regular-text" style="width:120px">
                        </label>
                    </span>
                </div>
                <div class="tix-sm-toolbar-right">
                    <span class="tix-sm-stats">
                        <span id="tix-sm-total-seats">0</span> Sitze
                    </span>
                    <button type="button" class="button button-primary tix-sm-btn-save">
                        <span class="dashicons dashicons-saved"></span> Speichern
                    </button>
                </div>
            </div>

            <!-- Overview Map (nur bei nicht-Theater-Layout) -->
            <div id="tix-sm-overview-wrap" style="display:none"></div>

            <!-- Canvas -->
            <div class="tix-sm-canvas-wrap">
                <div class="tix-sm-canvas" id="tix-sm-canvas">
                    <div class="tix-sm-stage">
                        <span>BÜHNE</span>
                    </div>
                    <div class="tix-sm-sections" id="tix-sm-sections"></div>
                </div>
            </div>

            <!-- Properties Panel -->
            <div class="tix-sm-props" id="tix-sm-props" style="display:none;">
                <h4>Eigenschaften</h4>
                <div class="tix-sm-props-grid">
                    <div class="tix-sm-prop-field">
                        <label>ID</label>
                        <input type="text" id="tix-sm-prop-id" readonly>
                    </div>
                    <div class="tix-sm-prop-field">
                        <label>Label</label>
                        <input type="text" id="tix-sm-prop-label">
                    </div>
                    <div class="tix-sm-prop-field">
                        <label>Typ</label>
                        <select id="tix-sm-prop-type">
                            <option value="standard">Standard</option>
                            <option value="vip">VIP</option>
                            <option value="wheelchair">Rollstuhl</option>
                            <option value="blocked">Gesperrt</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Hidden Input für Post-Save -->
            <input type="hidden" name="tix_seatmap_data" id="tix-seatmap-data" value="<?php echo esc_attr($data); ?>">
        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Speichern (POST)
    // ──────────────────────────────────────────
    public static function save($post_id, $post) {
        if (!isset($_POST['tix_seatmap_nonce']) || !wp_verify_nonce($_POST['tix_seatmap_nonce'], 'tix_seatmap_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $raw = wp_unslash($_POST['tix_seatmap_data'] ?? '');
        $data = self::sanitize_seatmap($raw);
        update_post_meta($post_id, '_tix_seatmap_data', $data ? wp_json_encode($data) : '');
    }

    // ──────────────────────────────────────────
    // AJAX: Save
    // ──────────────────────────────────────────
    public static function ajax_save() {
        check_ajax_referer('tix_seatmap', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('Keine Berechtigung');

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || get_post_type($post_id) !== self::CPT) {
            wp_send_json_error('Ungültiger Saalplan');
        }

        $raw  = wp_unslash($_POST['data'] ?? '');
        $data = self::sanitize_seatmap($raw);
        if (!$data) wp_send_json_error('Ungültige Daten');

        update_post_meta($post_id, '_tix_seatmap_data', wp_json_encode($data));

        // WC-Produkte pro Sektion synchronisieren
        $products = self::sync_section_products($post_id, $data);

        wp_send_json_success([
            'seats'    => self::count_seats($data),
            'products' => $products,
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Load
    // ──────────────────────────────────────────
    public static function ajax_load() {
        check_ajax_referer('tix_seatmap', 'nonce');
        $post_id = intval($_GET['post_id'] ?? $_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Keine ID');

        $raw  = get_post_meta($post_id, '_tix_seatmap_data', true);
        $data = $raw ? json_decode($raw, true) : null;
        wp_send_json_success(['data' => $data]);
    }

    // ──────────────────────────────────────────
    // AJAX: Seat Availability (Frontend)
    // ──────────────────────────────────────────
    public static function ajax_availability() {
        $event_id   = intval($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
        $seatmap_id = intval($_POST['seatmap_id'] ?? $_GET['seatmap_id'] ?? 0);
        if (!$event_id || !$seatmap_id) wp_send_json_error('Fehlende Parameter');

        // Saalplan-Daten laden
        $raw  = get_post_meta($seatmap_id, '_tix_seatmap_data', true);
        $data = $raw ? json_decode($raw, true) : null;
        if (!$data) wp_send_json_error('Kein Saalplan gefunden');

        // Belegte Sitze abfragen
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $taken = $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM $table
             WHERE event_id = %d AND seatmap_id = %d
             AND status IN ('reserved','sold')
             AND (status = 'sold' OR expires_at > NOW())",
            $event_id, $seatmap_id
        ));

        // Sektion-Produkte für Preisinfo laden
        $products = get_post_meta($seatmap_id, '_tix_section_products', true) ?: [];

        wp_send_json_success([
            'seatmap'  => $data,
            'taken'    => $taken,
            'products' => $products,
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Reserve Seats (Frontend)
    // ──────────────────────────────────────────
    public static function ajax_reserve() {
        $event_id   = intval($_POST['event_id'] ?? 0);
        $seatmap_id = intval($_POST['seatmap_id'] ?? 0);
        $seat_ids   = array_map('sanitize_text_field', (array)($_POST['seat_ids'] ?? []));
        $session_id = self::get_session_id();

        if (!$event_id || !$seatmap_id || empty($seat_ids)) {
            wp_send_json_error('Fehlende Parameter');
        }

        global $wpdb;
        $table      = $wpdb->prefix . self::TABLE_SUFFIX;
        $expires_at = gmdate('Y-m-d H:i:s', time() + (self::RESERVE_MIN * 60));
        $now        = current_time('mysql', true);
        $reserved   = [];
        $failed     = [];

        foreach ($seat_ids as $seat_id) {
            // Prüfen ob bereits belegt
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table
                 WHERE event_id = %d AND seat_id = %s
                 AND status IN ('reserved','sold')
                 AND (status = 'sold' OR expires_at > NOW())",
                $event_id, $seat_id
            ));

            if ($existing) {
                $failed[] = $seat_id;
                continue;
            }

            $wpdb->insert($table, [
                'event_id'    => $event_id,
                'seatmap_id'  => $seatmap_id,
                'seat_id'     => $seat_id,
                'status'      => 'reserved',
                'session_id'  => $session_id,
                'reserved_at' => $now,
                'expires_at'  => $expires_at,
            ], ['%d','%d','%s','%s','%s','%s','%s']);

            if ($wpdb->insert_id) {
                $reserved[] = $seat_id;
            } else {
                $failed[] = $seat_id;
            }
        }

        if (empty($reserved)) {
            wp_send_json_error(['message' => 'Sitze bereits belegt', 'failed' => $failed]);
        }

        wp_send_json_success([
            'reserved'   => $reserved,
            'failed'     => $failed,
            'expires_at' => $expires_at,
            'session_id' => $session_id,
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Release Seats (Frontend)
    // ──────────────────────────────────────────
    public static function ajax_release() {
        $event_id   = intval($_POST['event_id'] ?? 0);
        $seat_ids   = array_map('sanitize_text_field', (array)($_POST['seat_ids'] ?? []));
        $session_id = self::get_session_id();

        if (!$event_id || empty($seat_ids)) {
            wp_send_json_error('Fehlende Parameter');
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        foreach ($seat_ids as $seat_id) {
            $wpdb->delete($table, [
                'event_id'   => $event_id,
                'seat_id'    => $seat_id,
                'session_id' => $session_id,
                'status'     => 'reserved',
            ], ['%d','%s','%s','%s']);
        }

        wp_send_json_success();
    }

    // ──────────────────────────────────────────
    // Cron: Abgelaufene Reservierungen löschen
    // ──────────────────────────────────────────
    public static function cleanup_expired() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $wpdb->query("DELETE FROM $table WHERE status = 'reserved' AND expires_at < NOW()");
    }

    // ──────────────────────────────────────────
    // Order: Seats auf "sold" setzen
    // ──────────────────────────────────────────
    public static function on_order_paid($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        foreach ($order->get_items() as $item) {
            $seats = $item->get_meta('_tix_seats');
            if (empty($seats) || !is_array($seats)) continue;

            $event_id = $item->get_meta('_tix_event_id');
            if (!$event_id) continue;

            foreach ($seats as $seat_id) {
                $wpdb->update(
                    $table,
                    [
                        'status'   => 'sold',
                        'order_id' => $order_id,
                        'expires_at' => null,
                    ],
                    [
                        'event_id' => $event_id,
                        'seat_id'  => $seat_id,
                        'status'   => 'reserved',
                    ],
                    ['%s','%d',null],
                    ['%d','%s','%s']
                );
            }
        }
    }

    // ──────────────────────────────────────────
    // Order: Seats freigeben bei Storno
    // ──────────────────────────────────────────
    public static function on_order_cancelled($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $wpdb->delete($table, [
            'order_id' => $order_id,
            'status'   => 'sold',
        ], ['%d','%s']);
    }

    // ──────────────────────────────────────────
    // Hilfsfunktionen
    // ──────────────────────────────────────────

    /**
     * Sanitize Saalplan JSON
     */
    public static function sanitize_seatmap($raw) {
        if (is_string($raw)) {
            $data = json_decode($raw, true);
        } else {
            $data = $raw;
        }
        if (!is_array($data)) return null;

        $layouts   = ['theater', 'u-shape', 'stadium', 'arena'];
        $positions = ['center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right'];

        $clean = [
            'width'       => intval($data['width'] ?? 800),
            'height'      => intval($data['height'] ?? 600),
            'layout'      => in_array($data['layout'] ?? '', $layouts) ? $data['layout'] : 'theater',
            'stage_label' => sanitize_text_field($data['stage_label'] ?? 'BÜHNE'),
            'sections'    => [],
        ];

        foreach (($data['sections'] ?? []) as $section) {
            $s = [
                'id'       => sanitize_key($section['id'] ?? ''),
                'label'    => sanitize_text_field($section['label'] ?? ''),
                'color'         => sanitize_hex_color($section['color'] ?? '#FF5500') ?: '#FF5500',
                'position'      => in_array($section['position'] ?? '', $positions) ? $section['position'] : 'center',
                'price'         => floatval($section['price'] ?? 0),
                'category_name' => sanitize_text_field($section['category_name'] ?? ''),
                'rows'          => [],
            ];
            if (empty($s['id'])) continue;

            foreach (($section['rows'] ?? []) as $row) {
                $r = [
                    'id'    => sanitize_text_field($row['id'] ?? ''),
                    'seats' => [],
                ];
                if (empty($r['id'])) continue;

                foreach (($row['seats'] ?? []) as $seat) {
                    $r['seats'][] = [
                        'id'   => sanitize_text_field($seat['id'] ?? ''),
                        'x'    => floatval($seat['x'] ?? 0),
                        'y'    => floatval($seat['y'] ?? 0),
                        'type' => in_array($seat['type'] ?? 'standard', ['standard','vip','wheelchair','blocked'])
                                  ? $seat['type'] : 'standard',
                    ];
                }
                $s['rows'][] = $r;
            }
            $clean['sections'][] = $s;
        }

        return $clean;
    }

    /**
     * Sitze zählen
     */
    public static function count_seats($data) {
        if (!is_array($data)) return 0;
        $count = 0;
        foreach (($data['sections'] ?? []) as $section) {
            foreach (($section['rows'] ?? []) as $row) {
                foreach (($row['seats'] ?? []) as $seat) {
                    if (($seat['type'] ?? 'standard') !== 'blocked') $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Alle Sektionen eines Saalplans als ID→Label Array
     */
    public static function get_sections($seatmap_id) {
        $raw  = get_post_meta($seatmap_id, '_tix_seatmap_data', true);
        $data = $raw ? json_decode($raw, true) : null;
        if (!$data || empty($data['sections'])) return [];

        $out = [];
        foreach ($data['sections'] as $s) {
            $out[$s['id']] = $s['label'] ?: $s['id'];
        }
        return $out;
    }

    /**
     * Alle Saalpläne als ID→Title Array (für Dropdowns)
     */
    public static function get_all_seatmaps() {
        $posts = get_posts([
            'post_type'      => self::CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        $out = [];
        foreach ($posts as $p) {
            $out[$p->ID] = $p->post_title;
        }
        return $out;
    }

    /**
     * Verfügbare Sitze für ein Event + Sektion zählen
     */
    public static function available_seats($event_id, $seatmap_id, $section_id = null) {
        $raw  = get_post_meta($seatmap_id, '_tix_seatmap_data', true);
        $data = $raw ? json_decode($raw, true) : null;
        if (!$data) return 0;

        // Alle Sitze zählen (ohne blocked)
        $total = 0;
        foreach (($data['sections'] ?? []) as $s) {
            if ($section_id && $s['id'] !== $section_id) continue;
            foreach (($s['rows'] ?? []) as $row) {
                foreach (($row['seats'] ?? []) as $seat) {
                    if (($seat['type'] ?? 'standard') !== 'blocked') $total++;
                }
            }
        }

        // Belegte abziehen
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $where_section = '';
        if ($section_id) {
            $where_section = $wpdb->prepare(" AND seat_id LIKE %s", $section_id . '_%');
        }
        $taken = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE event_id = %d AND seatmap_id = %d
             AND status IN ('reserved','sold')
             AND (status = 'sold' OR expires_at > NOW())
             $where_section",
            $event_id, $seatmap_id
        ));

        return max(0, $total - $taken);
    }

    /**
     * Session-ID für temporäre Reservierungen
     */
    private static function get_session_id() {
        if (function_exists('WC') && WC()->session) {
            return WC()->session->get_customer_id();
        }
        if (!session_id()) {
            @session_start();
        }
        return session_id() ?: wp_generate_uuid4();
    }

    /**
     * WC-Produkte pro Saalplan-Sektion synchronisieren
     * Erstellt/aktualisiert ein WC-Produkt für jede Sektion mit Preis > 0
     */
    public static function sync_section_products($seatmap_id, $data) {
        if (!function_exists('wc_get_product')) return [];

        $existing = get_post_meta($seatmap_id, '_tix_section_products', true) ?: [];
        $updated  = [];

        foreach (($data['sections'] ?? []) as $section) {
            $sec_id = $section['id'] ?? '';
            $name   = !empty($section['category_name']) ? $section['category_name'] : ($section['label'] ?? '');
            $price  = floatval($section['price'] ?? 0);

            if (empty($sec_id) || $price <= 0) continue;

            // Bestand = Anzahl nicht-blockierter Sitze
            $stock = 0;
            foreach (($section['rows'] ?? []) as $row) {
                foreach (($row['seats'] ?? []) as $seat) {
                    if (($seat['type'] ?? 'standard') !== 'blocked') $stock++;
                }
            }

            $product_id = $existing[$sec_id] ?? 0;

            if ($product_id && wc_get_product($product_id)) {
                $product = wc_get_product($product_id);
                $product->set_name('[Saalplan] ' . $name);
                $product->set_regular_price((string) $price);
                $product->set_stock_quantity($stock);
                $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                $product->save();
            } else {
                $product = new \WC_Product_Simple();
                $product->set_name('[Saalplan] ' . $name);
                $product->set_regular_price((string) $price);
                $product->set_status('publish');
                $product->set_catalog_visibility('hidden');
                $product->set_virtual(true);
                $product->set_manage_stock(true);
                $product->set_stock_quantity($stock);
                $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                $product->set_sku('tix-sm-' . $seatmap_id . '-' . $sec_id);
                $product_id = $product->save();
            }

            $updated[$sec_id] = $product_id;
        }

        update_post_meta($seatmap_id, '_tix_section_products', $updated);
        return $updated;
    }

    /**
     * Sektions-Daten inkl. Preis, Produkt-ID, Verfügbarkeit
     */
    public static function get_section_data($seatmap_id, $event_id = 0) {
        $raw  = get_post_meta($seatmap_id, '_tix_seatmap_data', true);
        $data = $raw ? json_decode($raw, true) : null;
        if (!$data || empty($data['sections'])) return [];

        $products = get_post_meta($seatmap_id, '_tix_section_products', true) ?: [];

        $result = [];
        foreach ($data['sections'] as $s) {
            $total = 0;
            foreach (($s['rows'] ?? []) as $r) {
                foreach (($r['seats'] ?? []) as $seat) {
                    if (($seat['type'] ?? 'standard') !== 'blocked') $total++;
                }
            }

            $result[] = [
                'id'         => $s['id'],
                'label'      => !empty($s['category_name']) ? $s['category_name'] : $s['label'],
                'color'      => $s['color'] ?? '#FF5500',
                'price'      => floatval($s['price'] ?? 0),
                'product_id' => $products[$s['id']] ?? 0,
                'total'      => $total,
                'available'  => $event_id ? self::available_seats($event_id, $seatmap_id, $s['id']) : $total,
            ];
        }
        return $result;
    }

    /**
     * Picker-Assets laden (öffentlich, einmalig)
     */
    public static function enqueue_picker_assets() {
        if (wp_script_is('tix-seatmap-picker', 'enqueued')) return;
        wp_enqueue_script('tix-seatmap-picker', TIXOMAT_URL . 'assets/js/seatmap-picker.js', [], TIXOMAT_VERSION, true);
        wp_enqueue_style('tix-seatmap-picker', TIXOMAT_URL . 'assets/css/seatmap-picker.css', ['tix-google-fonts'], TIXOMAT_VERSION);
    }

    // ──────────────────────────────────────────
    // AJAX: Best Available (Frontend)
    // ──────────────────────────────────────────
    public static function ajax_best_available() {
        $event_id   = intval($_POST['event_id'] ?? 0);
        $seatmap_id = intval($_POST['seatmap_id'] ?? 0);
        $section_id = sanitize_key($_POST['section_id'] ?? '');
        $qty        = max(1, intval($_POST['qty'] ?? 1));

        if (!$event_id || !$seatmap_id) wp_send_json_error('Fehlende Parameter');

        $raw  = get_post_meta($seatmap_id, '_tix_seatmap_data', true);
        $data = $raw ? json_decode($raw, true) : null;
        if (!$data) wp_send_json_error('Kein Saalplan gefunden');

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $taken = $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM $table
             WHERE event_id = %d AND seatmap_id = %d
             AND status IN ('reserved','sold')
             AND (status = 'sold' OR expires_at > NOW())",
            $event_id, $seatmap_id
        ));

        $best = self::find_best_seats($data, $taken, $section_id, $qty);
        if (empty($best)) {
            wp_send_json_error('Nicht genügend zusammenhängende Plätze verfügbar');
        }

        wp_send_json_success(['seats' => $best]);
    }

    /**
     * Best-Available-Algorithmus:
     * 1. Zusammenhängende Sitze in einer Reihe suchen
     * 2. Bevorzugt mittige Reihen (näher an der Bühne)
     * 3. Fallback: Einzelsitze aus verschiedenen Reihen
     */
    private static function find_best_seats($data, $taken, $section_id, $qty) {
        $candidates = []; // [score => [seat_ids]]

        foreach (($data['sections'] ?? []) as $section) {
            if ($section_id && $section['id'] !== $section_id) continue;

            $rows       = $section['rows'] ?? [];
            $total_rows = count($rows);

            foreach ($rows as $ri => $row) {
                $seats     = $row['seats'] ?? [];
                $total_in_row = count($seats);

                // Zusammenhängende freie Blöcke finden
                $block = [];
                foreach ($seats as $si => $seat) {
                    if (($seat['type'] ?? 'standard') === 'blocked' || in_array($seat['id'], $taken)) {
                        // Block suchen im bisherigen Block
                        if (count($block) >= $qty) {
                            // Besten Sub-Block wählen (möglichst mittig)
                            $center = $total_in_row / 2;
                            $best_start = 0;
                            $best_dist  = PHP_INT_MAX;
                            for ($b = 0; $b <= count($block) - $qty; $b++) {
                                $mid  = $b + $qty / 2;
                                $dist = abs($mid - $center);
                                if ($dist < $best_dist) {
                                    $best_dist  = $dist;
                                    $best_start = $b;
                                }
                            }
                            $selected = array_slice($block, $best_start, $qty);
                            // Score: niedrigere Reihe (näher an Bühne) + mittiger = besser
                            $row_score  = ($total_rows - $ri) * 100;
                            $seat_score = 100 - $best_dist;
                            $score      = $row_score + $seat_score;
                            $candidates[$score] = $selected;
                        }
                        $block = [];
                        continue;
                    }
                    $block[] = $seat['id'];
                }

                // Rest des Blocks prüfen
                if (count($block) >= $qty) {
                    $center = $total_in_row / 2;
                    $best_start = 0;
                    $best_dist  = PHP_INT_MAX;
                    for ($b = 0; $b <= count($block) - $qty; $b++) {
                        $mid  = $b + $qty / 2;
                        $dist = abs($mid - $center);
                        if ($dist < $best_dist) {
                            $best_dist  = $dist;
                            $best_start = $b;
                        }
                    }
                    $selected   = array_slice($block, $best_start, $qty);
                    $row_score  = ($total_rows - $ri) * 100;
                    $seat_score = 100 - $best_dist;
                    $score      = $row_score + $seat_score;
                    $candidates[$score] = $selected;
                }
            }
        }

        if (!empty($candidates)) {
            krsort($candidates);
            return reset($candidates);
        }

        // Fallback: Einzelsitze aus verschiedenen Reihen
        $fallback = [];
        foreach (($data['sections'] ?? []) as $section) {
            if ($section_id && $section['id'] !== $section_id) continue;
            foreach (($section['rows'] ?? []) as $row) {
                foreach (($row['seats'] ?? []) as $seat) {
                    if (($seat['type'] ?? 'standard') !== 'blocked' && !in_array($seat['id'], $taken)) {
                        $fallback[] = $seat['id'];
                        if (count($fallback) >= $qty) return $fallback;
                    }
                }
            }
        }

        return [];
    }

    // ──────────────────────────────────────────
    // Shortcode: [tix_seatmap]
    // ──────────────────────────────────────────
    public static function render_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0, 'event' => 0], $atts);
        $event_id   = intval($atts['event']) ?: get_the_ID();
        $seatmap_id = intval($atts['id']) ?: intval(get_post_meta($event_id, '_tix_seatmap_id', true));

        if (!$seatmap_id) return '<p class="tix-sp-error">Kein Saalplan zugewiesen.</p>';

        $section_data = self::get_section_data($seatmap_id, $event_id);
        self::enqueue_picker_assets();

        return '<div class="tix-seatmap-standalone"
            data-tix-seatmap-picker
            data-event-id="' . esc_attr($event_id) . '"
            data-seatmap-id="' . esc_attr($seatmap_id) . '"
            data-mode="standalone"
            data-sections=\'' . esc_attr(wp_json_encode($section_data)) . '\'
            data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '"></div>';
    }

    /**
     * Seats eines Events für ein Order-Item fest buchen
     */
    public static function confirm_seats($event_id, $seat_ids, $order_id, $ticket_ids = []) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        foreach ($seat_ids as $i => $seat_id) {
            $ticket_id = $ticket_ids[$i] ?? null;
            $wpdb->update(
                $table,
                array_filter([
                    'status'     => 'sold',
                    'order_id'   => $order_id,
                    'ticket_id'  => $ticket_id,
                    'expires_at' => null,
                ]),
                [
                    'event_id' => $event_id,
                    'seat_id'  => $seat_id,
                    'status'   => 'reserved',
                ]
            );
        }
    }
}
