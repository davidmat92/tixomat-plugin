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
    const CHECKOUT_MIN = 30; // Haltezeit ab „Bestellen“ (Zahlung bei Mollie/Stripe/PayPal)
    // Blockade-Schutz (1.38.659, Filter tix_seat_hold_limits)
    const MAX_PER_HOLDER = 10;      // Plätze je Halter und Event
    const MAX_PER_IP = 20;          // Plätze je IP und Event
    const MAX_SHARE = 0.3;          // höchstens 30 % eines Events gleichzeitig gehalten …
    const MIN_EVENT_HOLD = 20;      // … mindestens aber 20 Plätze
    const MAX_HOLD_TOTAL_MIN = 60;  // längste Haltezeit ab der ersten Wahl
    const DB_VERSION = '2';
    const META_ORG = '_tix_seatmap_org';
    const META_VERSION = '_tix_seatmap_version';
    const META_REVS = '_tix_seatmap_revs';
    const META_SEATS = '_tix_seatmap_seats';
    const META_SNAPSHOT = '_tix_seatmap_snapshot';

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

        // Native Kasse: Sitze nach Zahlung (bzw. Vorkasse „on-hold“) fest buchen, bei Storno freigeben
        add_action('tix_order_status_changed', [__CLASS__, 'on_native_status'], 5, 2);
        add_action('tix_order_cancelled',      [__CLASS__, 'on_native_cancelled'], 5);

        // App-Schnittstelle
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        // Phase 1 (1.38.659): Tabelle nachrüsten, Mandanten, Zuweisung schützen, Einzelstorno
        add_action('init', [__CLASS__, 'maybe_upgrade'], 7);
        add_filter('map_meta_cap', [__CLASS__, 'map_meta_cap'], 10, 4);
        add_action('pre_get_posts', [__CLASS__, 'admin_query']);
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
        add_filter('update_post_metadata', [__CLASS__, 'guard_assign'], 10, 4);
        add_filter('add_post_metadata', [__CLASS__, 'guard_assign'], 10, 4);
        add_filter('delete_post_metadata', [__CLASS__, 'guard_assign_delete'], 10, 3);
        add_action('tix_release_seat', [__CLASS__, 'release_ticket_seat'], 10, 3);
    }

    /** Tabelle auf DB_VERSION bringen (Spalte ip_hash) */
    public static function maybe_upgrade() {
        if (get_option('tix_seat_db') === self::DB_VERSION) return;
        self::create_table();
        update_option('tix_seat_db', self::DB_VERSION, false);
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
            ip_hash VARCHAR(16) DEFAULT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            ticket_id BIGINT UNSIGNED DEFAULT NULL,
            reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY seat_event_active (event_id, seat_id, status),
            KEY idx_session (session_id),
            KEY idx_order (order_id),
            KEY idx_expires (status, expires_at),
            KEY idx_ip (event_id, ip_hash)
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
    // Speichern (WP-Admin)
    // ──────────────────────────────────────────
    public static function save($post_id, $post) {
        if (!isset($_POST['tix_seatmap_nonce']) || !wp_verify_nonce($_POST['tix_seatmap_nonce'], 'tix_seatmap_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!self::can_edit($post_id)) return;
        if (self::multi() && !self::org_of($post_id) && !current_user_can('manage_options')) {
            update_post_meta($post_id, self::META_ORG, intval(TIX_App_Scope::organizer_id_for_user()));
        }
        $raw = wp_unslash($_POST['tix_seatmap_data'] ?? '');
        if ($raw === '') return;
        $r = self::store($post_id, $raw, ['keep_old_for_sold' => true]);
        if (is_wp_error($r)) set_transient('tix_sm_notice_' . get_current_user_id(), $r->get_error_message(), 60);
    }

    public static function admin_notice() {
        $k = 'tix_sm_notice_' . get_current_user_id();
        $m = get_transient($k);
        if (!$m) return;
        delete_transient($k);
        echo '<div class="notice notice-error"><p>' . esc_html($m) . '</p></div>';
    }

    // ──────────────────────────────────────────
    // AJAX: Save / Load (WP-Admin)
    // ──────────────────────────────────────────
    public static function ajax_save() {
        check_ajax_referer('tix_seatmap', 'nonce');
        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || get_post_type($post_id) !== self::CPT) wp_send_json_error('Ungültiger Saalplan');
        if (!self::can_edit($post_id)) wp_send_json_error('Keine Berechtigung');
        $r = self::store($post_id, wp_unslash($_POST['data'] ?? ''), ['keep_old_for_sold' => !empty($_POST['keep_old_for_sold'])]);
        if (is_wp_error($r)) wp_send_json_error(['message' => $r->get_error_message(), 'code' => $r->get_error_code()] + (array) $r->get_error_data());
        wp_send_json_success(['seats' => $r['stats']['bookable'], 'version' => $r['version'], 'data' => self::data($post_id), 'kept' => $r['kept']]);
    }

    public static function ajax_load() {
        check_ajax_referer('tix_seatmap', 'nonce');
        $post_id = intval($_GET['post_id'] ?? $_POST['post_id'] ?? 0);
        if (!$post_id || get_post_type($post_id) !== self::CPT) wp_send_json_error('Keine ID');
        if (!self::can_edit($post_id)) wp_send_json_error('Keine Berechtigung');
        wp_send_json_success(['data' => self::data($post_id)]);
    }

    // ──────────────────────────────────────────
    // AJAX: Web-Picker (Frontend)
    // ──────────────────────────────────────────

    /** Event + Plan aus der Anfrage prüfen (Modul, Zuweisung). → [event_id, seatmap_id] oder Abbruch */
    private static function ajax_event() {
        $event_id   = intval($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
        $seatmap_id = intval($_POST['seatmap_id'] ?? $_GET['seatmap_id'] ?? 0);
        if (!$event_id) wp_send_json_error('Fehlende Parameter');
        $sm = self::event_seatmap($event_id);
        if (!$sm || ($seatmap_id && $seatmap_id !== $sm) || !self::module_on($event_id)) wp_send_json_error('Kein Saalplan gefunden');
        return [$event_id, $sm];
    }

    public static function ajax_availability() {
        list($event_id, $sm) = self::ajax_event();
        $data = self::event_data($event_id);
        if (!$data) wp_send_json_error('Kein Saalplan gefunden');
        wp_send_json_success([
            'seatmap'      => $data,
            // eigene Reservierungen nicht als belegt zeigen (z. B. nach Neuladen)
            'taken'        => self::taken($event_id, $sm, self::get_session_id()),
            'held'         => self::held_by($event_id, self::get_session_id()),
            'hold_seconds' => self::reserve_min($event_id) * 60,
            'max_seats'    => self::limits($event_id)['holder'],
            'products'     => [],
        ]);
    }

    public static function ajax_reserve() {
        list($event_id, $sm) = self::ajax_event();
        $seat_ids = array_slice(array_map('sanitize_text_field', (array) ($_POST['seat_ids'] ?? [])), 0, 50);
        if (!$seat_ids) wp_send_json_error('Fehlende Parameter');
        if (class_exists('TIX_Rate_Limit')) TIX_Rate_Limit::check('seat_reserve', 30, 60, 'ajax');
        $r = self::hold($event_id, $sm, $seat_ids, self::get_session_id());
        if (empty($r['reserved'])) {
            wp_send_json_error(['message' => $r['message'] ?: 'Sitze bereits belegt', 'failed' => $r['failed'], 'code' => $r['error']]);
        }
        wp_send_json_success($r + ['session_id' => self::get_session_id(), 'hold_seconds' => self::reserve_min($event_id) * 60]);
    }

    public static function ajax_release() {
        $event_id = intval($_POST['event_id'] ?? 0);
        $seat_ids = array_map('sanitize_text_field', (array) ($_POST['seat_ids'] ?? []));
        if (!$event_id || empty($seat_ids)) wp_send_json_error('Fehlende Parameter');
        self::release($event_id, $seat_ids, self::get_session_id());
        wp_send_json_success();
    }

    public static function ajax_best_available() {
        list($event_id, $sm) = self::ajax_event();
        $section_id = sanitize_key($_POST['section_id'] ?? '');
        $qty        = max(1, min(self::limits($event_id)['holder'], intval($_POST['qty'] ?? 1)));
        $best = self::best($event_id, $sm, $section_id, $qty, self::get_session_id());
        if (count($best) < $qty) wp_send_json_error('Nicht genügend zusammenhängende Plätze verfügbar');
        wp_send_json_success(['seats' => $best]);
    }

    // ──────────────────────────────────────────
    // Cron: Abgelaufene Reservierungen löschen
    // ──────────────────────────────────────────
    public static function cleanup_expired() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $wpdb->query("DELETE FROM $table WHERE status = 'reserved' AND expires_at < UTC_TIMESTAMP()");
    }

    // ──────────────────────────────────────────
    // WooCommerce (Altweg): Seats verkaufen / freigeben
    // ──────────────────────────────────────────
    public static function on_order_paid($order_id) {
        if (!function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;
        $seats = [];
        foreach ($order->get_items() as $item) {
            $ids = $item->get_meta('_tix_seats');
            $event_id = intval($item->get_meta('_tix_event_id'));
            if (empty($ids) || !is_array($ids) || !$event_id) continue;
            $sm = intval($item->get_meta('_tix_seatmap_id')) ?: self::event_seatmap($event_id);
            foreach ($ids as $sid) $seats[] = [$event_id, $sm, (string) $sid];
        }
        if ($seats) self::sell(intval($order_id), $seats, 'wc');
    }

    public static function on_order_cancelled($order_id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . self::TABLE_SUFFIX, ['order_id' => intval($order_id), 'status' => 'sold'], ['%d', '%s']);
    }

    // ══════════════════════════════════════
    //  Daten, Mandanten, Versionen
    // ══════════════════════════════════════

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    /** Modul an für das Event? (Saalplan & Tische, TIX_Seating) */
    public static function module_on($event_id) {
        return !class_exists('TIX_Seating') || TIX_Seating::event_on(intval($event_id));
    }

    /** Veranstalter eines Plans (Plattform). Alte Pläne ohne Merker: Veranstalter des Autors. */
    public static function org_of($seatmap_id) {
        $o = intval(get_post_meta(intval($seatmap_id), self::META_ORG, true));
        if ($o || !self::multi()) return $o;
        $author = intval(get_post_field('post_author', intval($seatmap_id)));
        return $author ? intval(TIX_App_Scope::organizer_id_for_user($author)) : 0;
    }

    /** Darf der Nutzer den Plan bearbeiten? edit_post und (Plattform) eigener Veranstalter. */
    public static function can_edit($seatmap_id, $uid = 0) {
        $uid = $uid ?: get_current_user_id();
        if (!$uid || get_post_type(intval($seatmap_id)) !== self::CPT) return false;
        if (user_can($uid, 'manage_options')) return true;
        if (!user_can($uid, 'edit_post', intval($seatmap_id))) return false;
        if (!self::multi()) return true;
        $mine = intval(TIX_App_Scope::organizer_id_for_user($uid));
        return $mine > 0 && self::org_of($seatmap_id) === $mine;
    }

    /** WP-Admin: fremde Pläne auf der Plattform weder ansehen noch bearbeiten */
    public static function map_meta_cap($caps, $cap, $uid, $args) {
        if (!in_array($cap, ['edit_post', 'delete_post', 'read_post'], true) || empty($args[0])) return $caps;
        $pid = intval($args[0]);
        if (get_post_type($pid) !== self::CPT || !self::multi() || user_can($uid, 'manage_options')) return $caps;
        $mine = intval(TIX_App_Scope::organizer_id_for_user($uid));
        $org = intval(get_post_meta($pid, self::META_ORG, true));
        if (!$org) { $a = intval(get_post_field('post_author', $pid)); $org = $a ? intval(TIX_App_Scope::organizer_id_for_user($a)) : 0; }
        if (!$mine || $org !== $mine) return ['do_not_allow'];
        return $caps;
    }

    /** WP-Admin-Liste: auf der Plattform nur eigene Pläne */
    public static function admin_query($q) {
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== self::CPT || !self::multi() || current_user_can('manage_options')) return;
        $q->set('meta_query', [['key' => self::META_ORG, 'value' => intval(TIX_App_Scope::organizer_id_for_user())]]);
    }

    /** Saalplan-Daten (dekodiert) oder null */
    public static function data($seatmap_id) {
        $raw  = get_post_meta(intval($seatmap_id), '_tix_seatmap_data', true);
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    public static function version($seatmap_id) {
        return max(1, intval(get_post_meta(intval($seatmap_id), self::META_VERSION, true)));
    }

    /** Platzvergabe: pick (Gast wählt) | best (nur Bestplatz) | both (Vorschlag + selbst wählen); gespeichert manual|best|both */
    public static function mode($event_id) {
        $m = (string) get_post_meta(intval($event_id), '_tix_seatmap_mode', true);
        return $m === 'best' ? 'best' : ($m === 'both' ? 'both' : 'pick');
    }

    const PALETTE = ['#E8445A', '#3D7BF7', '#1F8F5F', '#E8B931', '#8E5CF7', '#F28C28', '#14B8A6', '#EC4899'];
    const META_COLORS = '_tix_seatmap_colors';
    const META_CATEGORIES = '_tix_seatmap_categories'; // Event: {sektion: kategorie_index} (Phase 2)

    /**
     * Sektion → Ticket-Kategorie des Events → [section_id => cat_index]. Reihenfolge: Studio-Zuordnung (_tix_seatmap_categories),
     * Kategorie-Feld seatmap_section, sonst der Kategorie-Name der Sektion (category_name, wie das Studio ihn setzt; der
     * category_index des Plans ist nur ein Hinweis aus den Vorschlägen und gilt nicht für ein bestimmtes Event).
     */
    public static function section_categories($event_id, $data = null) {
        $out = [];
        foreach ((array) get_post_meta(intval($event_id), '_tix_ticket_categories', true) as $ci => $c) {
            if (is_array($c) && !empty($c['seatmap_section']) && !isset($out[$c['seatmap_section']])) $out[(string) $c['seatmap_section']] = intval($ci);
        }
        foreach ((array) get_post_meta(intval($event_id), self::META_CATEGORIES, true) as $sid => $ci) if (is_numeric($ci)) $out[(string) $sid] = intval($ci);
        if ($data === null) $data = self::raw_event_data($event_id);
        if (is_array($data)) {
            $by_name = [];
            foreach ((array) get_post_meta(intval($event_id), '_tix_ticket_categories', true) as $ci => $c) {
                $k = is_array($c) ? self::name_key((string) ($c['name'] ?? '')) : '';
                if ($k !== '' && !isset($by_name[$k])) $by_name[$k] = intval($ci);
            }
            foreach ((array) ($data['sections'] ?? []) as $sec) {
                $sid = (string) ($sec['id'] ?? '');
                $k = self::name_key((string) ($sec['category_name'] ?? ''));
                if ($sid !== '' && !isset($out[$sid]) && $k !== '' && isset($by_name[$k])) $out[$sid] = $by_name[$k];
            }
        }
        return $out;
    }

    /** Namensvergleich für Kategorien: klein, ohne Akzente und Zeichen */
    public static function name_key($s) {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents(html_entity_decode(trim((string) $s), ENT_QUOTES, 'UTF-8'))));
    }

    /** Plan-Daten des Events ohne Kategorie-Überlagerung (Schnappschuss bzw. aktueller Plan) */
    public static function raw_event_data($event_id) {
        $sm = self::event_seatmap($event_id);
        if (!$sm) return null;
        $snap = get_post_meta(intval($event_id), self::META_SNAPSHOT, true);
        if (is_array($snap) && intval($snap['seatmap_id'] ?? 0) === $sm && is_array($snap['data'] ?? null)) return $snap['data'];
        return self::data($sm);
    }

    /**
     * Verknüpfte Kategorien auf die Sektionen legen (Phase 2): Name und Preis aus der Kategorie (inkl. Preisphase),
     * Farbe aus der Kategorie (Palette nach Index, Event-Farbe _tix_seatmap_colors schlägt sie) – nur für die Ausgabe.
     */
    public static function apply_categories($event_id, $data) {
        if (!is_array($data) || empty($data['sections'])) return $data;
        $map = self::section_categories($event_id, $data);
        $cats = (array) get_post_meta(intval($event_id), '_tix_ticket_categories', true);
        $colors = (array) get_post_meta(intval($event_id), self::META_COLORS, true);
        foreach ($data['sections'] as &$sec) {
            $sid = (string) ($sec['id'] ?? '');
            if (!isset($map[$sid])) { $sec['color_source'] = 'plan'; unset($sec['category_index']); continue; } // Plan-Index ist nur ein Hinweis
            $ci = $map[$sid];
            $c = is_array($cats[$ci] ?? null) ? $cats[$ci] : [];
            $sec['category_index'] = $ci;
            if (!empty($c['name'])) $sec['category_name'] = sanitize_text_field((string) $c['name']);
            $price = class_exists('TIX_Cart_Pricing') ? TIX_Cart_Pricing::category_price($event_id, $ci) : floatval($c['price'] ?? 0);
            if ($price !== null) $sec['price'] = floatval($price);
            $own = sanitize_hex_color((string) ($colors[$sid] ?? ''));
            $sec['color'] = $own ?: self::PALETTE[$ci % count(self::PALETTE)];
            $sec['color_source'] = $own ? 'event' : 'category';
        }
        unset($sec);
        return $data;
    }

    /** Saalplan eines Events (0 = keiner) */
    public static function event_seatmap($event_id) {
        $id = intval(get_post_meta(intval($event_id), '_tix_seatmap_id', true));
        return ($id && get_post_type($id) === self::CPT) ? $id : 0;
    }

    /** Wirksame Daten eines Events: Schnappschuss (ab dem ersten Verkauf) oder der aktuelle Plan */
    public static function event_data($event_id) {
        $sm = self::event_seatmap($event_id);
        if (!$sm) return null;
        $snap = get_post_meta(intval($event_id), self::META_SNAPSHOT, true);
        if (is_array($snap) && intval($snap['seatmap_id'] ?? 0) === $sm && is_array($snap['data'] ?? null)) return self::apply_categories($event_id, $snap['data']);
        return self::apply_categories($event_id, self::data($sm));
    }

    /** Version, die das Event nutzt */
    public static function event_version($event_id) {
        $sm = self::event_seatmap($event_id);
        $snap = get_post_meta(intval($event_id), self::META_SNAPSHOT, true);
        if ($sm && is_array($snap) && intval($snap['seatmap_id'] ?? 0) === $sm) return intval($snap['version'] ?? 1);
        return $sm ? self::version($sm) : 0;
    }

    /** Schnappschuss festhalten (beim ersten Verkauf bzw. nach bestandener Zuordnungsprüfung) */
    public static function snapshot_event($event_id, $seatmap_id, $force = false, $data = null, $version = null) {
        $snap = get_post_meta(intval($event_id), self::META_SNAPSHOT, true);
        if (!$force && is_array($snap) && intval($snap['seatmap_id'] ?? 0) === intval($seatmap_id)) return;
        $data = $data ?: self::data($seatmap_id);
        if (!$data) return;
        update_post_meta(intval($event_id), self::META_SNAPSHOT, wp_slash([
            'seatmap_id' => intval($seatmap_id), 'version' => $version ?: self::version($seatmap_id), 'at' => gmdate('c'), 'data' => $data,
        ]));
    }

    /** Events, denen der Plan zugewiesen ist */
    public static function events_using($seatmap_id) {
        return array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
            'meta_key' => '_tix_seatmap_id', 'meta_value' => intval($seatmap_id)]));
    }

    /** Verkaufte Sitze eines Events */
    public static function sold_seats($event_id) {
        global $wpdb;
        return array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM {$wpdb->prefix}" . self::TABLE_SUFFIX . " WHERE event_id = %d AND status = 'sold'", intval($event_id))));
    }

    /**
     * Plan speichern: säubern, Koordinaten erzeugen, Version +1 (letzte 10 Fassungen bleiben).
     * Events mit Verkäufen: Zuordnungsprüfung – alle verkauften Sitze müssen im neuen Plan buchbar sein,
     * dann wechselt das Event auf die neue Version. Sonst 409 `tix_seatmap_conflict`, außer keep_old_for_sold:
     * dann bleibt das Event auf seinem Schnappschuss (alte Version), der Plan wird trotzdem gespeichert.
     * → {version, stats, kept[{event_id,title,missing[]}], upgraded[]} | WP_Error
     */
    public static function store($seatmap_id, $raw, array $args = []) {
        $seatmap_id = intval($seatmap_id);
        $clean = self::sanitize_seatmap($raw);
        if (!$clean) return new WP_Error('tix_seatmap_invalid', 'Der Saalplan ist leer oder ungültig.', ['status' => 400]);
        $stats = TIX_Seatmap_Geometry::check($clean);
        if ($stats['seats'] > TIX_Seatmap_Geometry::MAX_SEATS) return new WP_Error('tix_seatmap_invalid', sprintf('Höchstens %d Plätze je Saalplan.', TIX_Seatmap_Geometry::MAX_SEATS), ['status' => 400]);
        $old = self::data($seatmap_id);
        $old_v = self::version($seatmap_id);
        $kept = []; $upgrade = [];
        foreach (self::events_using($seatmap_id) as $ev) {
            $sold = self::sold_seats($ev);
            if (!$sold) continue;
            // Bestand ohne Schnappschuss (vor 1.38.659 verkauft): alte Fassung festhalten
            if ($old) self::snapshot_event($ev, $seatmap_id, false, $old, $old_v);
            $missing = [];
            foreach ($sold as $sid) if (!self::section_of($clean, $sid)) $missing[] = $sid;
            if ($missing) $kept[] = ['event_id' => $ev, 'title' => get_the_title($ev), 'missing' => array_slice($missing, 0, 50), 'missing_count' => count($missing)];
            else $upgrade[] = $ev;
        }
        if ($kept && empty($args['keep_old_for_sold'])) {
            return new WP_Error('tix_seatmap_conflict', 'Verkaufte Plätze fehlen im neuen Plan. Speichere als neue Version – die betroffenen Events behalten dann ihren bisherigen Plan.', ['status' => 409, 'events' => $kept]);
        }
        $v = $old ? $old_v + 1 : 1;
        if ($old) {
            $revs = get_post_meta($seatmap_id, self::META_REVS, true);
            $revs = is_array($revs) ? $revs : [];
            $revs[] = ['v' => $old_v, 'at' => gmdate('c'), 'user' => get_current_user_id(), 'data' => wp_json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            update_post_meta($seatmap_id, self::META_REVS, wp_slash(array_slice($revs, -10)));
        }
        $clean['version'] = $v;
        // Umlaute direkt speichern + slashen: update_post_meta entfernt sonst den Backslash aus Ü → „BU00DCHNE“
        update_post_meta($seatmap_id, '_tix_seatmap_data', wp_slash(wp_json_encode($clean, JSON_UNESCAPED_UNICODE)));
        update_post_meta($seatmap_id, self::META_VERSION, $v);
        update_post_meta($seatmap_id, self::META_SEATS, $stats['bookable']);
        foreach ($upgrade as $ev) self::snapshot_event($ev, $seatmap_id, true, $clean, $v);
        return ['version' => $v, 'stats' => $stats, 'kept' => $kept, 'upgraded' => $upgrade];
    }

    /**
     * Zuweisung `_tix_seatmap_id` schützen (Metabox, REST, Kopie): Nach dem ersten Verkauf ist der Plan fest,
     * neue Zuweisung nur mit Modul und (Plattform) eigenem Plan. Rückgabe false = nicht speichern.
     */
    public static $allow_assign = false;
    public static function guard_assign($check, $object_id, $meta_key, $meta_value) {
        if ($meta_key !== '_tix_seatmap_id' || self::$allow_assign || get_post_type($object_id) !== 'event') return $check;
        $new = intval(is_array($meta_value) ? 0 : $meta_value);
        $cur = intval(get_post_meta($object_id, '_tix_seatmap_id', true));
        if ($new === $cur) return $check;
        $err = self::assign_error($object_id, $new);
        if ($err) {
            if (is_user_logged_in()) set_transient('tix_sm_notice_' . get_current_user_id(), $err->get_error_message(), 60);
            return false;
        }
        return $check;
    }

    public static function guard_assign_delete($check, $object_id, $meta_key) {
        if ($meta_key !== '_tix_seatmap_id' || self::$allow_assign || get_post_type($object_id) !== 'event') return $check;
        return self::assign_error($object_id, 0) ? false : $check;
    }

    /** Fehler beim Zuweisen (null = erlaubt) */
    public static function assign_error($event_id, $seatmap_id) {
        $cur = intval(get_post_meta($event_id, '_tix_seatmap_id', true));
        if ($cur && $cur !== intval($seatmap_id) && self::sold_seats($event_id)) {
            return new WP_Error('tix_seatmap_sold', 'Für dieses Event sind schon Plätze verkauft – der Saalplan lässt sich nicht mehr wechseln.', ['status' => 409]);
        }
        if (!$seatmap_id) return null;
        if (get_post_type($seatmap_id) !== self::CPT) return new WP_Error('tix_seatmap', 'Saalplan nicht gefunden.', ['status' => 404]);
        if (!self::module_on($event_id)) return new WP_Error('tix_module_off', 'Saalpläne gehören zum Modul „Saalplan & Tische“.', ['status' => 403, 'module' => 'seating']);
        if (self::multi()) {
            $eorg = intval(get_post_meta($event_id, '_tix_organizer_id', true));
            if ($eorg && self::org_of($seatmap_id) && self::org_of($seatmap_id) !== $eorg) return new WP_Error('rest_forbidden', 'Dieser Saalplan gehört einem anderen Veranstalter.', ['status' => 403]);
        }
        return null;
    }

    /** Zuweisen (Studio): prüft, setzt Plan und Modus, löscht den alten Schnappschuss */
    public static function assign($event_id, $seatmap_id, $mode = null) {
        $err = self::assign_error($event_id, $seatmap_id);
        if ($err) return $err;
        self::$allow_assign = true;
        if ($seatmap_id) update_post_meta($event_id, '_tix_seatmap_id', intval($seatmap_id));
        else delete_post_meta($event_id, '_tix_seatmap_id');
        self::$allow_assign = false;
        $snap = get_post_meta($event_id, self::META_SNAPSHOT, true);
        if (is_array($snap) && intval($snap['seatmap_id'] ?? 0) !== intval($seatmap_id)) delete_post_meta($event_id, self::META_SNAPSHOT);
        if ($mode !== null) update_post_meta($event_id, '_tix_seatmap_mode', in_array($mode, ['best', 'both'], true) ? $mode : 'manual');
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return true;
    }

    // ──────────────────────────────────────────
    // Säubern
    // ──────────────────────────────────────────

    /**
     * Saalplan säubern und aufbauen (Version 2): Sektionen mit Block werden erzeugt, alte Sitze ohne
     * Koordinaten nachgerechnet, alles normalisiert (TIX_Seatmap_Geometry::build).
     */
    public static function sanitize_seatmap($raw) {
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($data)) return null;

        $layouts   = ['theater', 'u-shape', 'stadium', 'arena', 'club', 'banquet', 'custom'];
        $positions = ['center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right'];
        $num = function ($v) { return is_numeric($v) ? round(floatval($v), 1) : 0.0; };

        $clean = [
            'v'           => 2,
            'width'       => intval($data['width'] ?? 800),
            'height'      => intval($data['height'] ?? 600),
            'layout'      => in_array($data['layout'] ?? '', $layouts, true) ? $data['layout'] : 'theater',
            'stage_label' => sanitize_text_field($data['stage_label'] ?? 'BÜHNE'),
            'best_row'    => (isset($data['best_row']) && is_numeric($data['best_row'])) ? round(max(0, min(1, floatval($data['best_row']))), 2) : null,
            'sections'    => [],
            'objects'     => [],
        ];
        foreach (array_slice((array) ($data['objects'] ?? []), 0, 100) as $o) {
            if (!is_array($o) || !in_array($o['type'] ?? '', ['stage', 'standing', 'label', 'area'], true)) continue;
            $clean['objects'][] = array_filter([
                'type' => $o['type'], 'x' => $num($o['x'] ?? 0), 'y' => $num($o['y'] ?? 0),
                'w' => max(0, $num($o['w'] ?? 0)), 'h' => max(0, $num($o['h'] ?? 0)), 'angle' => $num($o['angle'] ?? 0),
                'label' => sanitize_text_field((string) ($o['label'] ?? '')),
                'capacity' => $o['type'] === 'standing' ? max(0, intval($o['capacity'] ?? 0)) : null,
                'color' => isset($o['color']) ? (sanitize_hex_color($o['color']) ?: null) : null,
            ], function ($v) { return $v !== null; });
        }
        $ids = [];
        foreach ((array) ($data['sections'] ?? []) as $section) {
            if (!is_array($section)) continue;
            $sid = sanitize_key($section['id'] ?? '');
            if ($sid === '' || isset($ids[$sid])) continue;
            $ids[$sid] = true;
            $s = [
                'id'            => $sid,
                'label'         => sanitize_text_field($section['label'] ?? ''),
                'color'         => sanitize_hex_color($section['color'] ?? '#FF5500') ?: '#FF5500',
                'position'      => in_array($section['position'] ?? '', $positions, true) ? $section['position'] : 'center',
                'price'         => max(0, floatval($section['price'] ?? 0)),
                'category_name' => sanitize_text_field($section['category_name'] ?? ''),
                'priority'      => in_array($section['priority'] ?? '', ['first', 'last'], true) ? $section['priority'] : 'normal',
                'rows'          => [],
            ];
            if (isset($section['category_index']) && is_numeric($section['category_index'])) $s['category_index'] = intval($section['category_index']);
            if (!empty($section['block']) && is_array($section['block'])) {
                $s['block'] = TIX_Seatmap_Geometry::clean_block($section['block']);
            } else {
                foreach ((array) ($section['rows'] ?? []) as $row) {
                    if (!is_array($row)) continue;
                    $r = ['id' => sanitize_text_field($row['id'] ?? ''), 'seats' => []];
                    if ($r['id'] === '') continue;
                    if (!empty($row['label'])) $r['label'] = sanitize_text_field($row['label']);
                    foreach ((array) ($row['seats'] ?? []) as $seat) {
                        if (!is_array($seat)) continue;
                        $one = [
                            'id'   => sanitize_text_field($seat['id'] ?? ''),
                            'x'    => $num($seat['x'] ?? 0),
                            'y'    => $num($seat['y'] ?? 0),
                            'type' => in_array($seat['type'] ?? 'standard', TIX_Seatmap_Geometry::TYPES, true) ? $seat['type'] : 'standard',
                        ];
                        if (isset($seat['label']) && $seat['label'] !== '') $one['label'] = sanitize_text_field((string) $seat['label']);
                        $r['seats'][] = $one;
                    }
                    $s['rows'][] = $r;
                }
            }
            $clean['sections'][] = $s;
        }
        if (!$clean['sections'] && !$clean['objects']) return null;
        $clean = TIX_Seatmap_Geometry::build($clean);
        // Sitztypen aus dem Pinsel (block.types) auch an generierten Sitzen sicherstellen
        return $clean;
    }

    // ──────────────────────────────────────────
    // Hilfsfunktionen
    // ──────────────────────────────────────────

    public static function count_seats($data) {
        if (!is_array($data)) return 0;
        $count = 0;
        foreach (($data['sections'] ?? []) as $section) foreach (($section['rows'] ?? []) as $row) foreach (($row['seats'] ?? []) as $seat) {
            if (($seat['type'] ?? 'standard') !== 'blocked') $count++;
        }
        return $count;
    }

    /** Alle Sektionen eines Saalplans als ID→Label Array */
    public static function get_sections($seatmap_id) {
        $data = self::data($seatmap_id);
        if (!$data || empty($data['sections'])) return [];
        $out = [];
        foreach ($data['sections'] as $s) $out[$s['id']] = $s['label'] ?: $s['id'];
        return $out;
    }

    /**
     * Saalpläne als ID→Titel (Dropdowns). Plattform: nur Pläne des eigenen Veranstalters
     * ($org = Veranstalter eines Events, für Admins im Event-Editor).
     */
    public static function get_all_seatmaps($org = null) {
        $args = ['post_type' => self::CPT, 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC'];
        $posts = get_posts($args);
        $filter = null;
        if (self::multi()) {
            if ($org !== null) $filter = intval($org);
            elseif (!current_user_can('manage_options')) $filter = intval(TIX_App_Scope::organizer_id_for_user());
        }
        $out = [];
        foreach ($posts as $p) {
            if ($filter !== null && $filter > 0 && self::org_of($p->ID) !== $filter) continue;
            if ($filter === 0 && !current_user_can('manage_options')) continue;
            $out[$p->ID] = $p->post_title;
        }
        return $out;
    }

    /** Sitz-IDs einer Sektion (buchbar) */
    private static function section_seat_ids(array $data, $section_id = null) {
        $ids = [];
        foreach (($data['sections'] ?? []) as $s) {
            if ($section_id && $s['id'] !== $section_id) continue;
            foreach (($s['rows'] ?? []) as $row) foreach (($row['seats'] ?? []) as $seat) {
                if (($seat['type'] ?? 'standard') !== 'blocked') $ids[] = (string) $seat['id'];
            }
        }
        return $ids;
    }

    /** Freie Sitze eines Events (je Sektion) */
    public static function available_seats($event_id, $seatmap_id, $section_id = null) {
        $data = (intval($seatmap_id) === self::event_seatmap($event_id)) ? self::event_data($event_id) : self::data($seatmap_id);
        if (!$data) return 0;
        $ids = self::section_seat_ids($data, $section_id);
        if (!$ids) return 0;
        $taken = array_flip(self::taken($event_id, $seatmap_id));
        $free = 0;
        foreach ($ids as $id) if (!isset($taken[$id])) $free++;
        return $free;
    }

    /** Session-ID für temporäre Reservierungen (Web) */
    private static function get_session_id() {
        if (function_exists('WC') && WC()->session) {
            return (string) WC()->session->get_customer_id();
        }
        // Native Kasse: gleicher Schlüssel wie der Warenkorb (Gast-Cookie bzw. Konto)
        if (class_exists('TIX_Native_Checkout') && method_exists('TIX_Native_Checkout', 'session_key')) {
            return (string) TIX_Native_Checkout::session_key();
        }
        if (!session_id()) @session_start();
        return session_id() ?: wp_generate_uuid4();
    }

    /** Sektions-Daten inkl. Preis, Verfügbarkeit (product_id bleibt 0: keine WooCommerce-Produkte mehr) */
    public static function get_section_data($seatmap_id, $event_id = 0) {
        $data = ($event_id && intval($seatmap_id) === self::event_seatmap($event_id)) ? self::event_data($event_id) : self::data($seatmap_id);
        if (!$data || empty($data['sections'])) return [];
        $result = [];
        foreach ($data['sections'] as $s) {
            $total = count(self::section_seat_ids(['sections' => [$s]]));
            $result[] = [
                'id'         => $s['id'],
                'label'      => !empty($s['category_name']) ? $s['category_name'] : $s['label'],
                'color'      => $s['color'] ?? '#FF5500',
                'price'      => floatval($s['price'] ?? 0),
                'category_index' => isset($s['category_index']) ? intval($s['category_index']) : null,
                'product_id' => 0,
                'total'      => $total,
                'available'  => $event_id ? self::available_seats($event_id, $seatmap_id, $s['id']) : $total,
            ];
        }
        return $result;
    }

    /** Picker-Assets laden (öffentlich, einmalig) */
    public static function enqueue_picker_assets() {
        if (wp_script_is('tix-seatmap-picker', 'enqueued')) return;
        wp_enqueue_script('tix-seatmap-picker', TIXOMAT_URL . 'assets/js/seatmap-picker.js', [], TIXOMAT_VERSION, true);
        wp_enqueue_style('tix-seatmap-picker', TIXOMAT_URL . 'assets/css/seatmap-picker.css', ['tix-google-fonts'], TIXOMAT_VERSION);
    }

    /** Best-Available über TIX_Seatmap_Best (Phase 2) → Sitz-IDs bzw. [] */
    private static function find_best_seats($data, $taken, $section_id, $qty) {
        $r = TIX_Seatmap_Best::find($data, ['qty' => $qty, 'sections' => $section_id ? [$section_id] : [], 'taken' => $taken]);
        return $r['seats'];
    }

    // ──────────────────────────────────────────
    // Shortcode: [tix_seatmap id="" event=""]
    // ──────────────────────────────────────────
    public static function render_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0, 'event' => 0], $atts);
        $event_id   = intval($atts['event']) ?: get_the_ID();
        $seatmap_id = self::event_seatmap($event_id);
        if (!$seatmap_id || (intval($atts['id']) && intval($atts['id']) !== $seatmap_id)) return '<p class="tix-sp-error">Kein Saalplan zugewiesen.</p>';
        if (!self::module_on($event_id)) return '';
        $section_data = self::get_section_data($seatmap_id, $event_id);
        self::enqueue_picker_assets();
        return '<div class="tix-seatmap-standalone"
            data-tix-seatmap-picker
            data-event-id="' . esc_attr($event_id) . '"
            data-seatmap-id="' . esc_attr($seatmap_id) . '"
            data-mode="standalone"
            data-max-seats="' . esc_attr(self::limits($event_id)['holder']) . '"
            data-sections=\'' . esc_attr(wp_json_encode($section_data)) . '\'
            data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '"></div>';
    }

    /** WooCommerce-Altweg: Sitze eines Order-Items fest buchen (mit Ticket-IDs) */
    public static function confirm_seats($event_id, $seat_ids, $order_id, $ticket_ids = []) {
        $sm = self::event_seatmap($event_id);
        $seats = [];
        foreach ((array) $seat_ids as $sid) $seats[] = [intval($event_id), $sm, (string) $sid];
        return self::sell(intval($order_id), $seats, 'wc', array_values((array) $ticket_ids));
    }

    // ══════════════════════════════════════
    //  Kern: Halten / Buchen (Web-Kasse + App)
    // ══════════════════════════════════════

    /** Sektion eines Sitzes (nur buchbare Sitze), sonst null */
    public static function section_of(array $data, $seat_id) {
        foreach (($data['sections'] ?? []) as $sec) {
            foreach (($sec['rows'] ?? []) as $row) {
                foreach (($row['seats'] ?? []) as $seat) {
                    if ((string) ($seat['id'] ?? '') === (string) $seat_id) {
                        return (($seat['type'] ?? 'standard') === 'blocked') ? null : $sec;
                    }
                }
            }
        }
        return null;
    }

    /** Belegte Sitze (verkauft, gesperrt oder von anderen gehalten); $holder = eigene Haltungen ausnehmen */
    public static function taken($event_id, $seatmap_id, $holder = '') {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $sql = $wpdb->prepare(
            "SELECT DISTINCT seat_id FROM $table
             WHERE event_id = %d
             AND (status IN ('sold','blocked') OR (status = 'reserved' AND expires_at > UTC_TIMESTAMP()",
            $event_id
        );
        $sql .= $holder !== '' ? $wpdb->prepare(" AND session_id <> %s))", $holder) : '))';
        return array_values(array_map('strval', (array) $wpdb->get_col($sql)));
    }

    /** Vom Halter aktuell gehaltene Sitze */
    public static function held_by($event_id, $holder) {
        if ($holder === '') return [];
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        return array_values(array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT seat_id FROM $table WHERE event_id = %d AND session_id = %s
             AND status = 'reserved' AND expires_at > UTC_TIMESTAMP()",
            $event_id, $holder
        ))));
    }

    /**
     * Grenzen gegen Blockade (Filter tix_seat_hold_limits):
     * holder = Plätze je Halter und Event, ip = je IP und Event, share = höchstens gehaltener Anteil des Events
     * (mindestens min_event Plätze), total_min = längste Haltezeit ab der ersten Wahl.
     */
    public static function limits($event_id = 0) {
        $l = (array) apply_filters('tix_seat_hold_limits', ['holder' => self::MAX_PER_HOLDER, 'ip' => self::MAX_PER_IP, 'share' => self::MAX_SHARE,
            'min_event' => self::MIN_EVENT_HOLD, 'total_min' => self::MAX_HOLD_TOTAL_MIN], intval($event_id));
        return ['holder' => max(1, intval($l['holder'] ?? 10)), 'ip' => max(1, intval($l['ip'] ?? 20)), 'share' => max(0.01, min(1, floatval($l['share'] ?? 0.3))),
            'min_event' => max(1, intval($l['min_event'] ?? 20)), 'total_min' => max(5, intval($l['total_min'] ?? 60))];
    }

    private static function ip_hash() {
        $ip = class_exists('TIX_Client_IP') ? (string) TIX_Client_IP::get() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip === '' ? '' : substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 16);
    }

    /**
     * Sitze für einen Halter reservieren bzw. die eigene Haltung verlängern (nur durch diesen Aufruf = Aktivität,
     * höchstens bis total_min nach der ersten Wahl). Atomar: ein Sitz gilt nur, wenn er weder verkauft, gesperrt
     * noch von anderen gehalten ist. Neue Plätze unterliegen den Grenzen (limits()).
     * @return array {reserved[], failed[], expires_at (UTC, Y-m-d H:i:s), error (limit_holder|limit_ip|limit_event|null), message}
     */
    /** Haltezeit in Minuten (Studio-Einstellung hold_minutes, sonst RESERVE_MIN) */
    public static function reserve_min($event_id = 0) {
        return class_exists('TIX_Seating') ? TIX_Seating::hold_minutes_for_event(intval($event_id)) : self::RESERVE_MIN;
    }

    public static function hold($event_id, $seatmap_id, array $seat_ids, $holder, $minutes = null) {
        global $wpdb;
        if ($minutes === null) $minutes = self::reserve_min($event_id);
        self::maybe_upgrade();
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;
        $event_id = intval($event_id);
        $expires = gmdate('Y-m-d H:i:s', time() + intval($minutes) * 60);
        $now     = gmdate('Y-m-d H:i:s');
        $data    = (intval($seatmap_id) === self::event_seatmap($event_id)) ? self::event_data($event_id) : null;
        $lim     = self::limits($event_id);
        $ip      = self::ip_hash();
        $reserved = []; $failed = []; $error = null; $message = '';
        $seat_ids = array_values(array_unique(array_map('strval', $seat_ids)));
        if (!$data || $holder === '') return ['reserved' => [], 'failed' => $seat_ids, 'expires_at' => $expires, 'error' => null, 'message' => ''];

        // Abgelaufene Reste dieser Sitze löschen (sonst blockiert der UNIQUE-Key bis zum Cron)
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE event_id = %d AND status = 'reserved' AND expires_at <= UTC_TIMESTAMP()", $event_id));
        $own = array_flip(self::held_by($event_id, $holder));
        $new = array_values(array_filter($seat_ids, function ($s) use ($own) { return !isset($own[$s]); }));
        if ($new) {
            if (count($own) + count($new) > $lim['holder']) {
                $error = 'limit_holder';
                $message = sprintf('Du kannst höchstens %d Plätze gleichzeitig reservieren.', $lim['holder']);
            } elseif ($ip !== '' && intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE event_id = %d AND status = 'reserved' AND ip_hash = %s AND expires_at > UTC_TIMESTAMP()", $event_id, $ip))) + count($new) > $lim['ip']) {
                $error = 'limit_ip';
                $message = 'Von diesem Anschluss sind schon viele Plätze reserviert. Bitte schließe zuerst eine Bestellung ab.';
            } else {
                $total = count(self::section_seat_ids($data));
                $cap = max($lim['min_event'], (int) ceil($total * $lim['share']));
                $held = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE event_id = %d AND status = 'reserved' AND order_id IS NULL AND expires_at > UTC_TIMESTAMP()", $event_id)));
                if ($held + count($new) > $cap) {
                    $error = 'limit_event';
                    $message = 'Gerade sind sehr viele Plätze reserviert. Bitte versuche es in ein paar Minuten noch einmal.';
                }
            }
            if ($error) { $failed = $new; $new = []; }
        }
        foreach ($seat_ids as $seat_id) {
            if (in_array($seat_id, $failed, true)) continue;
            if ($seat_id === '' || !self::section_of($data, $seat_id)) { $failed[] = $seat_id; continue; }
            if (isset($own[$seat_id])) {
                // Eigene Haltung verlängern, höchstens bis total_min nach der ersten Wahl
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET expires_at = LEAST(GREATEST(expires_at, %s), DATE_ADD(reserved_at, INTERVAL %d MINUTE))
                     WHERE event_id = %d AND seat_id = %s AND status = 'reserved' AND session_id = %s",
                    $expires, $lim['total_min'], $event_id, $seat_id, $holder
                ));
                $reserved[] = $seat_id;
                continue;
            }
            $ok = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (event_id, seatmap_id, seat_id, status, session_id, ip_hash, reserved_at, expires_at)
                 SELECT %d, %d, %s, 'reserved', %s, %s, %s, %s FROM (SELECT 1) AS tmp
                 WHERE NOT EXISTS (
                     SELECT 1 FROM $table WHERE event_id = %d AND seat_id = %s
                     AND (status IN ('sold','blocked') OR (status = 'reserved' AND expires_at > UTC_TIMESTAMP()))
                 )",
                $event_id, intval($seatmap_id), $seat_id, $holder, $ip, $now, $expires, $event_id, $seat_id
            ));
            if ($ok) $reserved[] = $seat_id; else $failed[] = $seat_id;
        }
        // Tatsächliche Ablaufzeit (kann durch die Obergrenze früher sein)
        if ($reserved) {
            $real = $wpdb->get_var($wpdb->prepare("SELECT MIN(expires_at) FROM $table WHERE event_id = %d AND session_id = %s AND status = 'reserved'", $event_id, $holder));
            if ($real) $expires = $real;
        }
        return ['reserved' => $reserved, 'failed' => array_values(array_unique($failed)), 'expires_at' => $expires, 'error' => $error, 'message' => $message];
    }

    /** Haltungen eines Halters freigeben (nur noch nicht bestellte) */
    public static function release($event_id, array $seat_ids, $holder) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        foreach ($seat_ids as $seat_id) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE event_id = %d AND seat_id = %s AND session_id = %s AND status = 'reserved' AND order_id IS NULL",
                $event_id, (string) $seat_id, $holder
            ));
        }
    }

    /** Beste freie Plätze einer Sektion (ohne sie zu halten) */
    public static function best($event_id, $seatmap_id, $section_id, $qty, $holder = '') {
        return (array) self::best_info($event_id, $seatmap_id, ['section_id' => $section_id, 'qty' => $qty, 'holder' => $holder])['seats'];
    }

    /**
     * Bester Platz mit allen Angaben (TIX_Seatmap_Best::find): $o {qty, section_id | category_index, wheelchair, attempt, holder,
     * ignore_own (eigene Haltung wie frei behandeln)} – Einstellungen des Veranstalters (split_rows, single_seat_rule) gelten.
     */
    public static function best_info($event_id, $seatmap_id, array $o) {
        $data = self::event_data($event_id);
        $empty = ['seats' => [], 'split' => false, 'rows' => [], 'section_id' => null, 'score' => null, 'attempt' => 0, 'alternatives' => 0, 'message' => '', 'reason' => 'none'];
        if (!$data) return $empty;
        $sections = [];
        $sid = sanitize_key((string) ($o['section_id'] ?? ''));
        if ($sid !== '') $sections = [$sid];
        elseif (isset($o['category_index']) && $o['category_index'] !== '' && $o['category_index'] !== null) {
            foreach ((array) ($data['sections'] ?? []) as $sec) if (isset($sec['category_index']) && intval($sec['category_index']) === intval($o['category_index'])) $sections[] = (string) $sec['id'];
            $sections = array_values(array_unique($sections));
            if (!$sections) return ['message' => 'Für diese Kategorie gibt es keinen Bereich im Saalplan.'] + $empty;
        }
        $set = class_exists('TIX_Seating') ? TIX_Seating::settings_for_event(intval($event_id)) : ['best' => []];
        $holder = (string) ($o['holder'] ?? '');
        return TIX_Seatmap_Best::find($data, [
            'qty' => max(1, intval($o['qty'] ?? 1)), 'sections' => $sections, 'taken' => self::taken($event_id, $seatmap_id, $holder),
            'wheelchair' => !empty($o['wheelchair']) || !empty($set['best']['wheelchair_in_best']),
            'split_rows' => $set['best']['split_rows'] ?? true, 'single_seat_rule' => $set['best']['single_seat_rule'] ?? true,
            'attempt' => max(0, intval($o['attempt'] ?? 0)),
        ]);
    }

    /**
     * Sitzplätze der Warenkorb-Positionen vor dem Bestellen festhalten (CHECKOUT_MIN).
     * Fehlermeldung oder '' wenn alle Plätze gesichert sind.
     */
    public static function secure_cart(array $items, $holder) {
        foreach ($items as $it) {
            $seats = (array) ($it['meta']['seats'] ?? []);
            $sm    = intval($it['meta']['seatmap_id'] ?? 0);
            if (!$seats || !$sm) continue;
            $event_id = intval($it['event_id']);
            if (!self::module_on($event_id)) return 'Die Platzwahl ist für dieses Event nicht verfügbar.';
            $r = self::hold($event_id, $sm, $seats, $holder, self::CHECKOUT_MIN);
            if ($r['failed']) {
                $data = self::event_data($event_id);
                return $r['message'] ?: 'Diese Plätze sind leider nicht mehr frei: ' . implode(', ', array_map(function ($s) use ($data) { return self::seat_label($s, $data); }, $r['failed'])) . '. Bitte wähle neue Plätze.';
            }
        }
        return '';
    }

    /** Gesicherte Plätze einer neuen Bestellung zuordnen */
    public static function attach_order(array $items, $holder, $order_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        foreach ($items as $it) {
            foreach ((array) ($it['meta']['seats'] ?? []) as $seat_id) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET order_id = %d WHERE event_id = %d AND seat_id = %s AND session_id = %s AND status = 'reserved'",
                    $order_id, intval($it['event_id']), (string) $seat_id, $holder
                ));
            }
        }
    }

    /**
     * Lesbare Sitzbezeichnung. Mit Plandaten aus Reihe und Platz („Parkett · Reihe C, Platz 12“),
     * sonst aus der ID (Sektion_Reihe_Platz bzw. alte IDs section_1_A3).
     */
    public static function seat_label($seat_id, $data = null) {
        $seat_id = (string) $seat_id;
        if (is_array($data)) {
            foreach (($data['sections'] ?? []) as $sec) foreach (($sec['rows'] ?? []) as $row) foreach (($row['seats'] ?? []) as $s) {
                if ((string) $s['id'] !== $seat_id) continue;
                $num = (string) ($s['label'] ?? preg_replace('/^.*?(\d+)$/', '$1', $seat_id));
                $rl = (string) ($row['label'] ?? ('Reihe ' . $row['id']));
                $sl = trim((string) ($sec['label'] ?? ''));
                return ($sl !== '' ? $sl . ' · ' : '') . $rl . ', Platz ' . $num;
            }
        }
        $p = explode('_', $seat_id);
        if (count($p) >= 3) {
            $last = $p[count($p) - 1];
            if (preg_match('/^([A-Za-z]+)(\d+)$/', $last, $m)) return 'Reihe ' . $m[1] . ', Platz ' . $m[2]; // alt: section_1_A3
            return 'Reihe ' . $p[count($p) - 2] . ', Platz ' . $last;
        }
        if (count($p) === 2 && preg_match('/^([A-Za-z]+)(\d+)$/', $p[1], $m)) return 'Reihe ' . $m[1] . ', Platz ' . $m[2];
        return $seat_id;
    }

    /** Sitzplätze einer nativen Bestellung: [[event_id, seatmap_id, seat_id], …] */
    private static function order_seats($order_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id, meta FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", $order_id
        ));
        $out = [];
        foreach ((array) $rows as $r) {
            $m = $r->meta ? json_decode($r->meta, true) : null;
            if (empty($m['seats']) || !is_array($m['seats'])) continue;
            foreach ($m['seats'] as $sid) $out[] = [intval($r->event_id), intval($m['seatmap_id'] ?? 0), (string) $sid];
        }
        return $out;
    }

    /**
     * Sitze einer Bestellung verkaufen – je Event unter einer Sperre (GET_LOCK).
     * strict (native Kasse): nur die eigene Haltung (order_id) oder ein freier Platz wird verkauft; eine gültige
     * Haltung anderer bleibt bestehen. wc (Altweg): Haltungen ohne Bestellung gelten als eigene (der WC-Halter
     * ist nicht bekannt). Ein fehlgeschlagenes INSERT (UNIQUE) ist ein Konflikt.
     * → Konflikte [[event_id, seat_id, reason sold|held|blocked|db]]
     */
    public static function sell($order_id, array $seats, $mode = 'strict', array $ticket_ids = []) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $order_id = intval($order_id);
        $by_event = [];
        foreach ($seats as $i => $s) $by_event[intval($s[0])][] = [intval($s[1]), (string) $s[2], $ticket_ids[$i] ?? null];
        $conflicts = [];
        foreach ($by_event as $event_id => $list) {
            $lock = 'tix_seat_' . $event_id;
            $got = intval($wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)));
            $sold_any = false;
            foreach ($list as list($sm, $seat_id, $ticket_id)) {
                $sm = $sm ?: self::event_seatmap($event_id);
                $sold = $wpdb->get_var($wpdb->prepare("SELECT order_id FROM $table WHERE event_id = %d AND seat_id = %s AND status = 'sold' LIMIT 1", $event_id, $seat_id));
                if ($sold !== null) {
                    if (intval($sold) !== $order_id) { $conflicts[] = [$event_id, $seat_id, 'sold']; continue; }
                    if ($ticket_id) $wpdb->query($wpdb->prepare("UPDATE $table SET ticket_id = %d WHERE event_id = %d AND seat_id = %s AND status = 'sold'", intval($ticket_id), $event_id, $seat_id));
                    $sold_any = true;
                    continue;
                }
                if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE event_id = %d AND seat_id = %s AND status = 'blocked' LIMIT 1", $event_id, $seat_id))) {
                    $conflicts[] = [$event_id, $seat_id, 'blocked'];
                    continue;
                }
                $res = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, order_id, (expires_at > UTC_TIMESTAMP()) AS valid FROM $table WHERE event_id = %d AND seat_id = %s AND status = 'reserved' LIMIT 1",
                    $event_id, $seat_id));
                if ($res) {
                    $ours = intval($res->order_id) === $order_id || ($mode === 'wc' && !intval($res->order_id));
                    if ($ours) {
                        $ok = $wpdb->query($wpdb->prepare(
                            "UPDATE $table SET status = 'sold', order_id = %d, expires_at = NULL" . ($ticket_id ? ', ticket_id = ' . intval($ticket_id) : '') . " WHERE id = %d",
                            $order_id, intval($res->id)));
                        if ($ok === false) $conflicts[] = [$event_id, $seat_id, 'db']; else $sold_any = true;
                        continue;
                    }
                    if (intval($res->valid)) { $conflicts[] = [$event_id, $seat_id, 'held']; continue; }
                    $wpdb->delete($table, ['id' => intval($res->id)], ['%d']); // abgelaufene fremde Haltung
                }
                $row = ['event_id' => $event_id, 'seatmap_id' => $sm, 'seat_id' => $seat_id, 'status' => 'sold', 'order_id' => $order_id, 'reserved_at' => gmdate('Y-m-d H:i:s')];
                if ($ticket_id) $row['ticket_id'] = intval($ticket_id);
                $suppress = $wpdb->suppress_errors(true);
                $ins = $wpdb->insert($table, $row);
                $wpdb->suppress_errors($suppress);
                if (!$ins) $conflicts[] = [$event_id, $seat_id, 'db']; else $sold_any = true;
            }
            if ($sold_any) self::snapshot_event($event_id, self::event_seatmap($event_id));
            if ($got) $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        if ($conflicts) self::handle_conflicts($order_id, $conflicts, $mode);
        return $conflicts;
    }

    /** Konflikte einer Bestellung (für die Ticket-Erstellung: Sitz nicht vergeben) */
    public static function conflicts_of($order_id) {
        $c = get_option('tix_seat_conflicts_' . intval($order_id), []);
        return is_array($c) ? $c : [];
    }

    /**
     * Doppelverkauf: Konflikte merken, Notiz an der Bestellung, Kunde und Veranstalter per Mail informieren,
     * Erstattung vorschlagen, Aktion `tix_seat_conflict` (order_id, conflicts).
     */
    private static function handle_conflicts($order_id, array $conflicts, $mode) {
        $known = self::conflicts_of($order_id);
        $fresh = [];
        foreach ($conflicts as $c) {
            $k = $c[0] . '|' . $c[1];
            if (isset($known[$k])) continue;
            $known[$k] = ['event_id' => $c[0], 'seat_id' => $c[1], 'reason' => $c[2], 'at' => gmdate('c')];
            $fresh[] = $known[$k];
        }
        update_option('tix_seat_conflicts_' . $order_id, $known, false);
        if (!$fresh) return;
        $labels = [];
        foreach ($fresh as $f) $labels[] = self::seat_label($f['seat_id'], self::event_data($f['event_id']));
        $event_id = intval($fresh[0]['event_id']);
        $title = get_the_title($event_id);
        $note = '⚠️ Sitzplatz-Konflikt: ' . implode(', ', $labels) . ' – der Platz ist schon anderweitig vergeben. Bitte dem Kunden einen anderen Platz anbieten oder erstatten (Erstattung empfohlen).';
        if ($mode === 'wc') {
            if (function_exists('wc_get_order') && ($wo = wc_get_order($order_id))) $wo->add_order_note($note);
        } elseif (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note($order_id, $note, 'system');
        }
        do_action('tix_seat_conflict', $order_id, $fresh, $mode);
        if (!class_exists('TIX_Mail_Layout') || $mode === 'wc') return;
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare("SELECT order_number, billing_email, billing_first_name FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id));
        if (!$o) return;
        $list = '<ul>' . implode('', array_map(function ($l) { return '<li>' . esc_html($l) . '</li>'; }, $labels)) . '</ul>';
        $nr = (string) ($o->order_number ?: $order_id);
        if (is_email($o->billing_email)) {
            TIX_Mail_Layout::send($o->billing_email, 'Wichtig zu deiner Bestellung ' . $nr . ': Sitzplatz', [
                'title' => 'Dein Platz ist leider doppelt vergeben',
                'blocks' => [['type' => 'html', 'html' => '<p>Hallo ' . esc_html($o->billing_first_name ?: '') . ',</p><p>bei deiner Bestellung <strong>' . esc_html($nr) . '</strong> für <strong>' . esc_html($title) . '</strong> wurde dieser Platz gleichzeitig an jemand anderen vergeben:</p>' . $list . '<p>Der Veranstalter meldet sich bei dir und bietet dir einen anderen Platz an oder erstattet den Betrag. Du musst nichts tun.</p>']],
                'kind' => 'customer',
            ]);
        }
        $to = [];
        $org = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        if ($org && ($uid = intval(get_post_meta($org, '_tix_org_user_id', true))) && ($u = get_userdata($uid))) $to[] = $u->user_email;
        if (!$to) $to[] = get_option('admin_email');
        TIX_Mail_Layout::send(array_values(array_filter($to, 'is_email')), 'Sitzplatz doppelt vergeben – Bestellung ' . $nr, [
            'title' => 'Sitzplatz doppelt vergeben',
            'blocks' => [['type' => 'html', 'html' => '<p>Bei der Bestellung <strong>' . esc_html($nr) . '</strong> für <strong>' . esc_html($title) . '</strong> war dieser Platz schon vergeben:</p>' . $list . '<p>Die Tickets wurden ohne Platz ausgestellt. Bitte biete dem Kunden einen anderen Platz an oder <strong>erstatte die Bestellung</strong> (empfohlen).</p>']],
            'kind' => 'admin',
        ], ['customer' => false]);
    }

    /** Native Kasse: bezahlt / Vorkasse offen → Sitze verkaufen (Halter-Prüfung über order_id) */
    public static function on_native_status($order_id, $new_status) {
        if (!in_array($new_status, ['completed', 'processing', 'on-hold'], true)) return;
        $seats = self::order_seats(intval($order_id));
        if ($seats) self::sell(intval($order_id), $seats, 'strict');
    }

    /** Native Kasse: Storno / fehlgeschlagen → Plätze freigeben */
    public static function on_native_cancelled($order_id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . self::TABLE_SUFFIX, ['order_id' => intval($order_id)], ['%d']);
    }

    /** Einzelnes Ticket storniert → seinen Platz freigeben (Aktion tix_release_seat) */
    public static function release_ticket_seat($seatmap_id, $seat_id, $ticket_id = 0) {
        global $wpdb;
        $event_id = intval(get_post_meta(intval($ticket_id), '_tix_ticket_event_id', true));
        $order_id = intval(get_post_meta(intval($ticket_id), '_tix_ticket_order_id', true));
        if (!$event_id || !$seat_id) return;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . self::TABLE_SUFFIX . " WHERE event_id = %d AND seat_id = %s AND status = 'sold' AND (ticket_id = %d OR order_id = %d)",
            $event_id, (string) $seat_id, intval($ticket_id), $order_id));
    }

    // ══════════════════════════════════════
    //  REST (App)
    // ══════════════════════════════════════

    public static function register_routes() {
        $ns = 'tixomat/v1';
        register_rest_route($ns, '/public/events/(?P<id>\d+)/seatmap', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_seatmap'], 'permission_callback' => '__return_true',
        ]);
        foreach (['hold', 'release', 'best'] as $a) {
            register_rest_route($ns, '/customer/seats/' . $a, [
                'methods' => 'POST', 'callback' => [__CLASS__, 'rest_' . $a], 'permission_callback' => '__return_true',
            ]);
        }
    }

    /** Halter für die App: hold_token (vom Server vergeben, von der App bis zur Bestellung mitgeschickt) */
    public static function app_holder($token) {
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
        return strlen($token) >= 16 ? 'app_' . substr($token, 0, 64) : '';
    }

    /** Rate-Limit je IP (über alle Tokens) */
    private static function rest_limit($bucket, $max, $window = 60) {
        if (class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'rate_limited')) return TIX_App_Account::rate_limited($bucket, $max, $window);
        $key = 'tix_rl_' . $bucket . '_' . md5((string) (class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : ''));
        $n = intval(get_transient($key)) + 1;
        set_transient($key, $n, $window);
        return $n > $max;
    }

    private static function rest_event(WP_REST_Request $req) {
        $event_id = intval($req['id'] ?? $req->get_param('event_id'));
        if (!$event_id || get_post_type($event_id) !== 'event' || get_post_status($event_id) !== 'publish') {
            return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        }
        $sm = self::event_seatmap($event_id);
        if (!$sm || !self::event_data($event_id)) return new WP_Error('tix_no_seatmap', 'Für dieses Event gibt es keinen Saalplan.', ['status' => 404]);
        if (!self::module_on($event_id)) return new WP_Error('tix_module_disabled', 'Die Platzwahl ist für dieses Event nicht verfügbar.', ['status' => 403, 'module' => 'seating']);
        return [$event_id, $sm];
    }

    /** Halter aus dem Token; neues Token höchstens 10 je IP und 10 Minuten */
    private static function rest_holder(WP_REST_Request $req) {
        $token  = (string) $req->get_param('hold_token');
        $holder = self::app_holder($token);
        if ($holder === '') {
            if (self::rest_limit('seat_token', 10, 600)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', ['status' => 429]);
            $token  = wp_generate_password(32, false);
            $holder = self::app_holder($token);
        }
        return [$token, $holder];
    }

    private static function hold_response($token, array $r, $event_id) {
        if (!$r['reserved'] && $r['error']) {
            return new WP_Error('tix_seat_' . $r['error'], $r['message'], ['status' => 429, 'failed' => $r['failed'], 'hold_token' => $token]);
        }
        return rest_ensure_response(['ok' => !empty($r['reserved']), 'hold_token' => $token,
            'expires_at' => gmdate('c', strtotime($r['expires_at'] . ' UTC')),
            'hold_seconds' => max(0, strtotime($r['expires_at'] . ' UTC') - time()),
            'max_seats' => self::limits($event_id)['holder']] + $r);
    }

    /** GET /public/events/{id}/seatmap[?hold_token=] */
    public static function rest_seatmap(WP_REST_Request $req) {
        $ev = self::rest_event($req);
        if (is_wp_error($ev)) return $ev;
        list($event_id, $sm) = $ev;
        $holder = self::app_holder($req->get_param('hold_token'));
        $data = self::event_data($event_id);
        $sections = [];
        foreach (self::get_section_data($sm, $event_id) as $sec) {
            $sections[] = [
                'id' => $sec['id'], 'label' => $sec['label'], 'color' => $sec['color'],
                'price' => round($sec['price'], 2), 'total' => $sec['total'], 'available' => $sec['available'],
                'category_index' => $sec['category_index'] ?? null,
            ];
        }
        $lim = self::limits($event_id);
        return rest_ensure_response([
            'ok'           => true,
            'event_id'     => $event_id,
            'seatmap_id'   => $sm,
            'version'      => self::event_version($event_id),
            'mode'         => self::mode($event_id) === 'best' ? 'best' : 'manual', // manual | best (alte Apps); both → manual + best_available
            'pick'         => self::mode($event_id) !== 'best',
            'best_available' => self::mode($event_id) !== 'pick',
            'hold_minutes' => self::reserve_min($event_id),
            'hold_seconds' => self::reserve_min($event_id) * 60,
            'max_seats'    => $lim['holder'],
            'layout'       => [
                'width' => intval($data['width'] ?? 800), 'height' => intval($data['height'] ?? 600),
                'layout' => (string) ($data['layout'] ?? 'theater'), 'stage_label' => (string) ($data['stage_label'] ?? ''),
            ],
            'sections'     => $sections,
            // Sitze mit Koordinaten: sections[].rows[].seats[] {id, x, y, type, label}
            'map'          => $data['sections'] ?? [],
            'objects'      => $data['objects'] ?? [],
            'taken'        => self::taken($event_id, $sm, $holder),
            'held'         => self::held_by($event_id, $holder),
        ]);
    }

    /** POST /customer/seats/hold {event_id, seat_ids[], hold_token?} */
    public static function rest_hold(WP_REST_Request $req) {
        if (self::rest_limit('seat_hold', 30)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', ['status' => 429]);
        $ev = self::rest_event($req);
        if (is_wp_error($ev)) return $ev;
        list($event_id, $sm) = $ev;
        $seats = array_slice(array_map('sanitize_text_field', (array) $req->get_param('seat_ids')), 0, 50);
        if (!$seats) return new WP_Error('tix_seats', 'Bitte Plätze wählen.', ['status' => 400]);
        $h = self::rest_holder($req);
        if (is_wp_error($h)) return $h;
        list($token, $holder) = $h;
        return self::hold_response($token, self::hold($event_id, $sm, $seats, $holder), $event_id);
    }

    /** POST /customer/seats/release {event_id, seat_ids[], hold_token} */
    public static function rest_release(WP_REST_Request $req) {
        $ev = self::rest_event($req);
        if (is_wp_error($ev)) return $ev;
        $holder = self::app_holder($req->get_param('hold_token'));
        if ($holder === '') return new WP_Error('tix_hold_token', 'hold_token fehlt.', ['status' => 400]);
        self::release($ev[0], array_map('sanitize_text_field', (array) $req->get_param('seat_ids')), $holder);
        return rest_ensure_response(['ok' => true]);
    }

    /**
     * POST /customer/seats/best {event_id, qty, section_id | category_index, wheelchair?, attempt?, replace?, preview?, hold_token?}
     * → beste Plätze finden und halten (preview: nur zeigen). attempt = „anderer Vorschlag“, replace = eigene Haltung vorher freigeben.
     */
    public static function rest_best(WP_REST_Request $req) {
        if (self::rest_limit('seat_hold', 30)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte kurz warten.', ['status' => 429]);
        $ev = self::rest_event($req);
        if (is_wp_error($ev)) return $ev;
        list($event_id, $sm) = $ev;
        // Auch im Modus pick erlaubt (ältere Apps nutzen „Beste Plätze“ als Hilfe beim Wählen)
        $h = self::rest_holder($req);
        if (is_wp_error($h)) return $h;
        list($token, $holder) = $h;
        $qty = min(self::limits($event_id)['holder'], max(1, intval($req->get_param('qty'))));
        $preview = filter_var($req->get_param('preview'), FILTER_VALIDATE_BOOLEAN);
        $replace = filter_var($req->get_param('replace'), FILTER_VALIDATE_BOOLEAN);
        if ($replace && !$preview) self::release($event_id, self::held_by($event_id, $holder), $holder);
        $info = self::best_info($event_id, $sm, ['qty' => $qty, 'section_id' => $req->get_param('section_id'), 'category_index' => $req->get_param('category_index'),
            'wheelchair' => filter_var($req->get_param('wheelchair'), FILTER_VALIDATE_BOOLEAN), 'attempt' => $req->get_param('attempt'), 'holder' => $holder]);
        $pub = array_intersect_key($info, array_flip(['seats', 'split', 'rows', 'section_id', 'score', 'attempt', 'alternatives', 'message']));
        if (count($info['seats']) < $qty) {
            return new WP_Error($info['reason'] === 'no_more' ? 'tix_no_more' : 'tix_no_seats', $info['message'] ?: 'Nicht genügend freie Plätze verfügbar.', ['status' => 409, 'hold_token' => $token] + $pub);
        }
        if ($preview) return rest_ensure_response(['ok' => true, 'preview' => true, 'hold_token' => $token] + $pub);
        $r = self::hold_response($token, self::hold($event_id, $sm, $info['seats'], $holder), $event_id);
        if (is_wp_error($r)) return $r;
        $r->set_data($r->get_data() + $pub);
        return $r;
    }
}
