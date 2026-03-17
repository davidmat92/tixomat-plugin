<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Table_Reservation – Tischreservierungssystem mit Kalender-Frontend.
 *
 * Monatskalender → Event → Tischkategorie → Buchungsformular.
 * Zahlungsmodi pro Event: Anzahlung / Vollzahlung / Vor Ort.
 *
 * Shortcodes:
 *   [tix_table_reservation]        – Vollständige SPA (Kalender → Buchung)
 *   [tix_table_button event_id=""] – Button für Direktbuchung auf Event-Seiten
 *
 * @since 1.30.0
 */
class TIX_Table_Reservation {

    const TABLE = 'tix_table_reservations';

    public static function init() {
        add_shortcode('tix_table_reservation', [__CLASS__, 'render_shortcode']);
        add_shortcode('tix_table_button',      [__CLASS__, 'render_button_shortcode']);

        // AJAX (public – Gäste können ohne Login buchen)
        $actions = [
            'tix_table_events',
            'tix_table_event_detail',
            'tix_table_submit',
            'tix_table_cancel',
        ];
        foreach ($actions as $a) {
            $handler = 'handle_' . str_replace('tix_table_', '', $a);
            add_action('wp_ajax_' . $a,        [__CLASS__, $handler]);
            add_action('wp_ajax_nopriv_' . $a, [__CLASS__, $handler]);
        }

        // WC Order Hooks
        add_action('woocommerce_order_status_completed',  [__CLASS__, 'on_order_paid']);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'on_order_paid']);
        add_action('woocommerce_order_status_cancelled',  [__CLASS__, 'on_order_cancelled']);
        add_action('woocommerce_order_status_refunded',   [__CLASS__, 'on_order_cancelled']);

        // ── Modal Checkout Integration ──
        // AJAX: Tischkategorien für Modal-Checkout
        add_action('wp_ajax_tix_mc_table_categories',        [__CLASS__, 'ajax_mc_table_categories']);
        add_action('wp_ajax_nopriv_tix_mc_table_categories', [__CLASS__, 'ajax_mc_table_categories']);
        // AJAX: Tischreservierung im Checkout speichern
        add_action('wp_ajax_tix_mc_table_select',            [__CLASS__, 'ajax_mc_table_select']);
        add_action('wp_ajax_nopriv_tix_mc_table_select',     [__CLASS__, 'ajax_mc_table_select']);
        // WC Cart Fee für Tischreservierung
        add_action('woocommerce_cart_calculate_fees',         [__CLASS__, 'add_table_fee_to_cart']);
        // WC Order: Reservierung beim Checkout erstellen
        add_action('woocommerce_checkout_create_order',       [__CLASS__, 'on_checkout_create_order'], 20, 2);

        // ── Normal WC Checkout Integration ──
        add_action('woocommerce_review_order_before_submit',  [__CLASS__, 'render_checkout_table_section']);
        add_action('wp_ajax_tix_checkout_table_select',       [__CLASS__, 'ajax_checkout_table_select']);
        add_action('wp_ajax_nopriv_tix_checkout_table_select',[__CLASS__, 'ajax_checkout_table_select']);
    }

    // ──────────────────────────────────────────
    // DB Table
    // ──────────────────────────────────────────

    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            category_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            category_name VARCHAR(200) NOT NULL DEFAULT '',
            table_name VARCHAR(100) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            customer_name VARCHAR(200) NOT NULL,
            customer_email VARCHAR(200) NOT NULL,
            customer_phone VARCHAR(50) DEFAULT NULL,
            guest_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            comments TEXT DEFAULT NULL,
            payment_mode VARCHAR(20) NOT NULL DEFAULT 'on_site',
            order_id BIGINT UNSIGNED DEFAULT NULL,
            amount_total DECIMAL(10,2) DEFAULT 0,
            amount_paid DECIMAL(10,2) DEFAULT 0,
            confirmation_token VARCHAR(64) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_event_status (event_id, status),
            KEY idx_email (customer_email),
            KEY idx_order (order_id),
            KEY idx_token (confirmation_token)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ──────────────────────────────────────────
    // Shortcode: Full SPA
    // ──────────────────────────────────────────

    private static function get_localize_config() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];

        // Pre-fill user data for logged-in users
        $user_data = ['name' => '', 'email' => '', 'phone' => ''];
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            // Try WooCommerce billing data first, fallback to WP user data
            if (function_exists('WC') && WC()->customer) {
                $customer = WC()->customer;
                $user_data['name']  = trim($customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name());
                $user_data['email'] = $customer->get_billing_email() ?: $user->user_email;
                $user_data['phone'] = $customer->get_billing_phone();
            } else {
                $user_data['name']  = trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name;
                $user_data['email'] = $user->user_email;
                $user_data['phone'] = get_user_meta($user->ID, 'billing_phone', true);
            }
        }

        return [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('tix_table_res'),
            'currency'    => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '€',
            'accentColor' => $s['color_accent'] ?? '#c8ff00',
            'user'        => $user_data,
            'i18n'        => [
                'calendar'      => 'Kalender',
                'today'         => 'Heute',
                'noEvents'      => 'Keine Events mit Tischreservierung in diesem Monat.',
                'tables'        => 'Tische',
                'guests'        => 'Gäste',
                'minSpend'      => 'Mindestverzehr',
                'available'     => 'verfügbar',
                'soldOut'       => 'Ausgebucht',
                'book'          => 'Reservieren',
                'back'          => 'Zurück',
                'yourReservation' => 'Deine Reservierung',
                'name'          => 'Name',
                'email'         => 'E-Mail',
                'phone'         => 'Telefon',
                'guestCount'    => 'Anzahl Gäste',
                'comments'      => 'Anmerkungen',
                'commentsPlaceholder' => 'Besondere Wünsche, Allergien, Anlass...',
                'submit'        => 'Jetzt reservieren',
                'payOnline'     => 'Jetzt bezahlen & reservieren',
                'payDeposit'    => 'Anzahlung & reservieren',
                'price'         => 'Preis',
                'deposit'       => 'Anzahlung',
                'total'         => 'Gesamt',
                'payOnSite'     => 'Zahlung vor Ort',
                'fullPayment'   => 'Volle Zahlung',
                'confirmed'     => 'Reservierung bestätigt!',
                'confirmText'   => 'Du erhältst eine Bestätigung per E-Mail.',
                'redirecting'   => 'Weiterleitung zur Zahlung...',
                'newReservation' => 'Neue Reservierung',
                'error'         => 'Fehler',
                'required'      => 'Bitte fülle alle Pflichtfelder aus.',
                'months'        => ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'],
                'weekdays'      => ['Mo','Di','Mi','Do','Fr','Sa','So'],
                'perTable'      => 'pro Tisch',
                'persons'       => 'Personen',
                'floorPlan'     => 'Raumplan',
            ],
        ];
    }

    private static function enqueue_assets() {
        wp_enqueue_style('tix-table-reservation', TIXOMAT_URL . 'assets/css/table-reservation.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-table-reservation', TIXOMAT_URL . 'assets/js/table-reservation.js', [], TIXOMAT_VERSION, true);
        wp_localize_script('tix-table-reservation', 'tixTableRes', self::get_localize_config());
    }

    private static function build_color_style($atts) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $color_map = [
            'bg-card'    => 'reservation_bg',
            'bg-subtle'  => 'reservation_surface',
            'border'     => 'reservation_card',
            'text'       => 'reservation_text',
        ];
        $style = '';
        foreach ($color_map as $css_key => $setting_key) {
            $attr_key = explode('-', $css_key)[0]; // bg, border, text for shortcode atts
            $val = '';
            // Map shortcode attributes to CSS variables
            if ($css_key === 'bg-card' && !empty($atts['bg'])) $val = $atts['bg'];
            elseif ($css_key === 'bg-subtle' && !empty($atts['surface'])) $val = $atts['surface'];
            elseif ($css_key === 'border' && !empty($atts['card'])) $val = $atts['card'];
            elseif ($css_key === 'text' && !empty($atts['text'])) $val = $atts['text'];
            if (!$val) $val = $s[$setting_key] ?? '';
            if ($val) {
                $style .= '--tx-' . $css_key . ':' . esc_attr($val) . ';';
            }
        }
        return $style;
    }

    public static function render_shortcode($atts) {
        $atts = shortcode_atts(['page' => '', 'bg' => '', 'surface' => '', 'card' => '', 'text' => ''], $atts, 'tix_table_reservation');

        self::enqueue_assets();

        $style = self::build_color_style($atts);
        return '<div id="tix-table-res-app"' . ($style ? ' style="' . $style . '"' : '') . '></div>';
    }

    // ──────────────────────────────────────────
    // Shortcode: Button (für Event-Seiten)
    // ──────────────────────────────────────────

    public static function render_button_shortcode($atts) {
        $atts = shortcode_atts([
            'event_id'  => '',
            'label'     => 'Tisch reservieren',
            'text'      => '',          // alias für label (Rückwärtskompatibilität)
            'fullwidth' => '0',
            'variant'   => '1',
        ], $atts, 'tix_table_button');

        // label-Alias
        $label = $atts['text'] ?: $atts['label'];

        $event_id = intval($atts['event_id']);
        if (!$event_id) {
            // Auto-detect: auf Event-Seiten oder in Loop
            global $post;
            if ($post && $post->post_type === 'event') {
                $event_id = $post->ID;
            } elseif (get_post_type() === 'event') {
                $event_id = get_the_ID();
            } elseif (is_singular('event')) {
                $event_id = get_queried_object_id();
            }
        }
        if (!$event_id) return '<!-- tix_table_button: kein Event erkannt -->';

        // Prüfe ob Event Tischreservierung hat
        $config = get_post_meta($event_id, '_tix_table_reservation', true);
        if (empty($config['enabled'])) return '';

        self::enqueue_assets();

        // Variante: identisch wie [tix_ticket_modal]
        $fw    = $atts['fullwidth'] === '1' ? ' tix-fullwidth' : '';
        $tix_v = intval($atts['variant']) === 2 ? 2 : 1;

        // Wrapper mit CSS-Variable-Remapping bei Variante 2 (wie tix_ticket_modal)
        $v2_style = '';
        if ($tix_v === 2) {
            $v2_style = ' style="--tix-btn1-bg:var(--tix-btn2-bg,transparent);--tix-btn1-color:var(--tix-btn2-color,inherit);--tix-btn1-hover-bg:var(--tix-btn2-hover-bg,transparent);--tix-btn1-hover-color:var(--tix-btn2-hover-color,inherit);--tix-btn1-radius:var(--tix-btn2-radius,8px);--tix-btn1-border:var(--tix-btn2-border,1px solid currentColor);--tix-btn1-font-size:var(--tix-btn2-font-size,0.9rem)"';
        }

        $html = sprintf(
            '<span class="tix-table-btn-wrap%s"%s><button type="button" class="tix-table-btn" onclick="window._trOpenModal(%d)">%s</button></span>',
            $fw, $v2_style, $event_id, esc_html($label)
        );

        // Render modal container once per page
        static $modal_rendered = false;
        if (!$modal_rendered) {
            $modal_rendered = true;
            $html .= '<div id="tix-table-res-modal" class="tr-modal-overlay" style="display:none">';
            $html .= '<div class="tr-modal-content">';
            $html .= '<button class="tr-modal-close" onclick="window._trCloseModal()">&#x2715;</button>';
            $html .= '<div id="tix-table-res-app-modal"></div>';
            $html .= '</div></div>';
        }

        return $html;
    }

    // ──────────────────────────────────────────
    // AJAX: Kalender-Events
    // ──────────────────────────────────────────

    public static function handle_events() {
        check_ajax_referer('tix_table_res', 'nonce');

        $year  = intval($_POST['year']  ?? date('Y'));
        $month = intval($_POST['month'] ?? date('n'));

        $first = sprintf('%04d-%02d-01', $year, $month);
        $last  = date('Y-m-t', strtotime($first));

        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_tix_date_start',
                    'value'   => [$first, $last],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATE',
                ],
            ],
            'orderby'  => 'meta_value',
            'meta_key' => '_tix_date_start',
            'order'    => 'ASC',
        ];

        $posts = get_posts($args);
        $events = [];

        foreach ($posts as $p) {
            $config = get_post_meta($p->ID, '_tix_table_reservation', true);
            if (empty($config['enabled'])) continue;

            $date  = get_post_meta($p->ID, '_tix_date_start', true);
            $thumb = get_the_post_thumbnail_url($p->ID, 'medium');
            $time_start = get_post_meta($p->ID, '_tix_time_start', true);
            $time_end   = get_post_meta($p->ID, '_tix_time_end', true);

            $events[] = [
                'id'        => $p->ID,
                'title'     => $p->post_title,
                'date'      => $date,
                'time'      => $time_start ? ($time_start . ($time_end ? ' – ' . $time_end : '')) : '',
                'thumbnail' => $thumb ?: '',
            ];
        }

        wp_send_json_success(['events' => $events, 'year' => $year, 'month' => $month]);
    }

    // ──────────────────────────────────────────
    // AJAX: Event-Detail + Tischkategorien
    // ──────────────────────────────────────────

    public static function handle_event_detail() {
        check_ajax_referer('tix_table_res', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error(['message' => 'Event fehlt.']);

        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'event') {
            wp_send_json_error(['message' => 'Event nicht gefunden.']);
        }

        $config = get_post_meta($event_id, '_tix_table_reservation', true);
        if (empty($config['enabled'])) {
            wp_send_json_error(['message' => 'Tischreservierung nicht aktiviert.']);
        }

        $date       = get_post_meta($event_id, '_tix_date_start', true);
        $time_start = get_post_meta($event_id, '_tix_time_start', true);
        $time_end   = get_post_meta($event_id, '_tix_time_end', true);
        $time_doors = get_post_meta($event_id, '_tix_time_doors', true);
        $loc_id     = get_post_meta($event_id, '_tix_location_id', true);
        $location   = $loc_id ? get_the_title($loc_id) : '';
        $thumb      = get_the_post_thumbnail_url($event_id, 'large');

        // Floor plan
        $floor_plan_url = '';
        if (!empty($config['floor_plan_id'])) {
            $floor_plan_url = wp_get_attachment_url(intval($config['floor_plan_id']));
        }

        // Kategorien mit Verfügbarkeit
        $categories = $config['categories'] ?? [];
        $result_cats = [];

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        foreach ($categories as $idx => $cat) {
            $name      = $cat['name'] ?? '';
            $desc      = $cat['desc'] ?? '';
            $min_spend = floatval($cat['min_spend'] ?? 0);
            $min_guests = intval($cat['min_guests'] ?? 1);
            $max_guests = intval($cat['max_guests'] ?? 10);
            $quantity  = intval($cat['quantity'] ?? 0);

            if (empty($name)) continue;

            // Aktive Reservierungen zählen (graceful wenn Tabelle fehlt)
            $reserved = 0;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $reserved = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE event_id = %d AND category_index = %d AND status IN ('pending','confirmed')",
                    $event_id, $idx
                ));
            } else {
                // Tabelle fehlt – erstellen
                self::create_table();
            }
            $available = max(0, $quantity - $reserved);

            // Individual tables with positions
            $tables_data = [];
            $cat_tables = $cat['tables'] ?? [];
            if (!empty($cat_tables)) {
                foreach ($cat_tables as $ti => $t) {
                    $t_name = $t['name'] ?? '';
                    if (!$t_name) continue;
                    $t_reserved = 0;
                    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                        $t_reserved = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND category_index = %d AND table_name = %s AND status IN ('pending','confirmed')",
                            $event_id, $idx, $t_name
                        ));
                    }
                    $tables_data[] = [
                        'name'     => $t_name,
                        'x'        => floatval($t['x'] ?? 0),
                        'y'        => floatval($t['y'] ?? 0),
                        'reserved' => $t_reserved > 0,
                    ];
                }
                // Override available count based on individual tables
                $available = count(array_filter($tables_data, function($t) { return !$t['reserved']; }));
            }

            $result_cats[] = [
                'index'      => $idx,
                'name'       => $name,
                'desc'       => $desc,
                'min_spend'  => $min_spend,
                'min_guests' => $min_guests,
                'max_guests' => $max_guests,
                'price'      => $min_spend, // Mindestverzehr = Zahlbetrag
                'quantity'   => $quantity,
                'available'  => $available,
                'tables'     => $tables_data,
            ];
        }

        // Zahlungsmodus
        $payment_mode  = $config['payment_mode']  ?? 'on_site';
        $deposit_type  = $config['deposit_type']  ?? 'percent';
        $deposit_value = floatval($config['deposit_value'] ?? 0);

        // Datum formatieren
        $date_formatted = '';
        if ($date) {
            $ts = strtotime($date);
            $weekdays = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
            $months   = ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
            $date_formatted = $weekdays[date('w', $ts)] . ', ' . date('j', $ts) . '. ' . $months[date('n', $ts)] . ' ' . date('Y', $ts);
        }

        wp_send_json_success([
            'event' => [
                'id'             => $event_id,
                'title'          => $event->post_title,
                'date'           => $date,
                'date_formatted' => $date_formatted,
                'time_start'     => $time_start,
                'time_end'       => $time_end,
                'time_doors'     => $time_doors,
                'location'       => $location,
                'thumbnail'      => $thumb ?: '',
                'floor_plan'     => $floor_plan_url ?: '',
            ],
            'categories'    => $result_cats,
            'payment_mode'  => $payment_mode,
            'deposit_type'  => $deposit_type,
            'deposit_value' => $deposit_value,
            'info_text'     => $config['info_text'] ?? '',
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Reservierung abschicken
    // ──────────────────────────────────────────

    public static function handle_submit() {
        check_ajax_referer('tix_table_res', 'nonce');

        $event_id    = intval($_POST['event_id'] ?? 0);
        $cat_index   = intval($_POST['category_index'] ?? 0);
        $name        = sanitize_text_field($_POST['customer_name'] ?? '');
        $email       = sanitize_email($_POST['customer_email'] ?? '');
        $phone       = sanitize_text_field($_POST['customer_phone'] ?? '');
        $guests      = intval($_POST['guest_count'] ?? 1);
        $comments    = sanitize_textarea_field($_POST['comments'] ?? '');
        $table_name  = sanitize_text_field($_POST['table_name'] ?? '');

        // Validierung
        if (!$event_id || !$name || !$email) {
            wp_send_json_error(['message' => 'Bitte fülle alle Pflichtfelder aus.']);
        }
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Bitte gib eine gültige E-Mail-Adresse ein.']);
        }

        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'event') {
            wp_send_json_error(['message' => 'Event nicht gefunden.']);
        }

        $config = get_post_meta($event_id, '_tix_table_reservation', true);
        if (empty($config['enabled'])) {
            wp_send_json_error(['message' => 'Tischreservierung nicht aktiviert.']);
        }

        $categories = $config['categories'] ?? [];
        if (!isset($categories[$cat_index])) {
            wp_send_json_error(['message' => 'Tischkategorie nicht gefunden.']);
        }
        $cat = $categories[$cat_index];
        $cat_name = $cat['name'] ?? '';

        // Gästeanzahl prüfen
        $min_guests = intval($cat['min_guests'] ?? 1);
        $max_guests = intval($cat['max_guests'] ?? 10);
        if ($guests < $min_guests || $guests > $max_guests) {
            wp_send_json_error(['message' => "Gästeanzahl muss zwischen $min_guests und $max_guests liegen."]);
        }

        // Verfügbarkeit prüfen (Race-Condition-sicher)
        global $wpdb;
        $table    = $wpdb->prefix . self::TABLE;

        // Tabelle sicherstellen
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            self::create_table();
        }

        $quantity = intval($cat['quantity'] ?? 0);
        $reserved = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND category_index = %d AND status IN ('pending','confirmed')",
            $event_id, $cat_index
        ));

        if ($reserved >= $quantity) {
            wp_send_json_error(['message' => 'Diese Tischkategorie ist leider ausgebucht.']);
        }

        // Zahlungsmodus
        $payment_mode  = $config['payment_mode']  ?? 'on_site';
        $deposit_type  = $config['deposit_type']  ?? 'percent';
        $deposit_value = floatval($config['deposit_value'] ?? 0);
        $min_spend     = floatval($cat['min_spend'] ?? 0);

        // Beträge berechnen – Mindestverzehr = Zahlbetrag
        $amount_total = $min_spend;
        $amount_paid  = 0;
        if ($payment_mode === 'full') {
            $amount_paid = $min_spend;
        } elseif ($payment_mode === 'deposit') {
            if ($deposit_type === 'percent') {
                $amount_paid = round($min_spend * $deposit_value / 100, 2);
            } else {
                $amount_paid = min($deposit_value, $min_spend);
            }
        }

        // Token
        $token = wp_generate_password(32, false);

        // Status
        $status = ($payment_mode === 'on_site') ? 'confirmed' : 'pending';

        // Validate table_name if individual tables are configured
        $cat_tables = $cat['tables'] ?? [];
        if ($table_name && !empty($cat_tables)) {
            $valid_table = false;
            foreach ($cat_tables as $t) {
                if (($t['name'] ?? '') === $table_name) {
                    $valid_table = true;
                    break;
                }
            }
            if (!$valid_table) {
                wp_send_json_error(['message' => 'Ungültiger Tisch gewählt.']);
            }
            // Check if this specific table is already reserved
            $t_reserved = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE event_id = %d AND category_index = %d AND table_name = %s AND status IN ('pending','confirmed')",
                $event_id, $cat_index, $table_name
            ));
            if ($t_reserved > 0) {
                wp_send_json_error(['message' => 'Dieser Tisch ist leider bereits reserviert.']);
            }
        }

        // Reservierung speichern
        $inserted = $wpdb->insert($table, [
            'event_id'           => $event_id,
            'category_index'     => $cat_index,
            'category_name'      => $cat_name,
            'table_name'         => $table_name ?: null,
            'status'             => $status,
            'customer_name'      => $name,
            'customer_email'     => $email,
            'customer_phone'     => $phone,
            'guest_count'        => $guests,
            'comments'           => $comments,
            'payment_mode'       => $payment_mode,
            'amount_total'       => $amount_total,
            'amount_paid'        => $amount_paid,
            'confirmation_token' => $token,
            'created_at'         => current_time('mysql'),
            'confirmed_at'       => $status === 'confirmed' ? current_time('mysql') : null,
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s','%f','%f','%s','%s','%s']);

        if (!$inserted) {
            wp_send_json_error(['message' => 'Datenbankfehler beim Speichern der Reservierung.']);
        }

        $reservation_id = $wpdb->insert_id;

        // Bei Zahlung: WC Order erstellen
        if ($payment_mode !== 'on_site' && $amount_paid > 0 && class_exists('WooCommerce')) {
            $order = wc_create_order();
            if (is_wp_error($order)) {
                wp_send_json_error(['message' => 'Bestellung konnte nicht erstellt werden.']);
            }

            // Fee statt Produkt (einfacher, kein WC-Produkt nötig)
            $fee = new \WC_Order_Item_Fee();
            $fee_label = 'Tischreservierung: ' . $cat_name . ' – ' . $event->post_title;
            if ($payment_mode === 'deposit') {
                $fee_label .= ' (Anzahlung)';
            }
            $fee->set_name($fee_label);
            $fee->set_amount($amount_paid);
            $fee->set_total($amount_paid);
            $fee->set_tax_status('none');
            $order->add_item($fee);

            // Billing
            $name_parts = explode(' ', $name, 2);
            $order->set_billing_first_name($name_parts[0]);
            $order->set_billing_last_name($name_parts[1] ?? '');
            $order->set_billing_email($email);
            if ($phone) $order->set_billing_phone($phone);

            // Meta
            $order->update_meta_data('_tix_table_reservation_id', $reservation_id);
            $order->update_meta_data('_tix_table_reservation_event', $event_id);
            $order->update_meta_data('_tix_table_reservation_category', $cat_name);
            $order->update_meta_data('_tix_table_reservation_guests', $guests);

            // Payment method
            $method_title = $payment_mode === 'deposit' ? 'Anzahlung Tischreservierung' : 'Tischreservierung';
            $order->set_payment_method('tix_table_res');
            $order->set_payment_method_title($method_title);

            $order->calculate_totals();
            $order->set_status('pending', 'Tischreservierung – Zahlung ausstehend');
            $order->save();

            $order_id = $order->get_id();

            // Reservation mit Order verknüpfen
            $wpdb->update($table, ['order_id' => $order_id], ['id' => $reservation_id], ['%d'], ['%d']);

            // Checkout-URL
            $checkout_url = $order->get_checkout_payment_url();

            wp_send_json_success([
                'status'       => 'payment_required',
                'checkout_url' => $checkout_url,
                'order_id'     => $order_id,
                'reservation'  => [
                    'id'         => $reservation_id,
                    'event'      => $event->post_title,
                    'category'   => $cat_name,
                    'guests'     => $guests,
                    'total'      => $amount_total,
                    'to_pay'     => $amount_paid,
                ],
            ]);
            return;
        }

        // Bei Vor-Ort: Bestätigungs-E-Mail senden
        self::send_confirmation_email($reservation_id);

        wp_send_json_success([
            'status'      => 'confirmed',
            'reservation' => [
                'id'       => $reservation_id,
                'event'    => $event->post_title,
                'date'     => get_post_meta($event_id, '_tix_date_start', true),
                'time'     => get_post_meta($event_id, '_tix_time_start', true),
                'location' => get_post_meta($event_id, '_tix_location_id', true) ? get_the_title(get_post_meta($event_id, '_tix_location_id', true)) : '',
                'category' => $cat_name,
                'guests'   => $guests,
                'total'    => $amount_total,
                'payment'  => $payment_mode,
            ],
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Stornierung
    // ──────────────────────────────────────────

    public static function handle_cancel() {
        check_ajax_referer('tix_table_res', 'nonce');

        $token = sanitize_text_field($_POST['token'] ?? '');
        if (!$token) wp_send_json_error(['message' => 'Token fehlt.']);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $res = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE confirmation_token = %s", $token
        ));

        if (!$res) wp_send_json_error(['message' => 'Reservierung nicht gefunden.']);
        if ($res->status === 'cancelled') wp_send_json_error(['message' => 'Bereits storniert.']);

        $wpdb->update($table, [
            'status'       => 'cancelled',
            'cancelled_at' => current_time('mysql'),
        ], ['id' => $res->id], ['%s','%s'], ['%d']);

        // WC Order stornieren wenn vorhanden
        if ($res->order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($res->order_id);
            if ($order && !in_array($order->get_status(), ['cancelled', 'refunded'])) {
                $order->update_status('cancelled', 'Tischreservierung storniert');
            }
        }

        wp_send_json_success(['message' => 'Reservierung wurde storniert.']);
    }

    // ──────────────────────────────────────────
    // WC Order Hooks
    // ──────────────────────────────────────────

    public static function on_order_paid($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $res_id = $order->get_meta('_tix_table_reservation_id');
        if (!$res_id) return;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $res = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $res_id));
        if (!$res || $res->status !== 'pending') return;

        $wpdb->update($table, [
            'status'       => 'confirmed',
            'confirmed_at' => current_time('mysql'),
        ], ['id' => $res_id], ['%s','%s'], ['%d']);

        self::send_confirmation_email($res_id);
    }

    public static function on_order_cancelled($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $res_id = $order->get_meta('_tix_table_reservation_id');
        if (!$res_id) return;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->update($table, [
            'status'       => 'cancelled',
            'cancelled_at' => current_time('mysql'),
        ], ['id' => $res_id], ['%s','%s'], ['%d']);
    }

    // ──────────────────────────────────────────
    // Modal Checkout: Tischkategorien laden
    // ──────────────────────────────────────────

    public static function ajax_mc_table_categories() {
        check_ajax_referer('tix_modal_checkout', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error(['message' => 'Event-ID fehlt.']);

        $config = get_post_meta($event_id, '_tix_table_reservation', true);
        if (empty($config['enabled']) || empty($config['show_in_checkout'])) {
            wp_send_json_success(['available' => false]);
        }

        $categories = $config['categories'] ?? [];
        if (empty($categories)) {
            wp_send_json_success(['available' => false]);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            self::create_table();
        }

        $result_cats = [];
        foreach ($categories as $i => $cat) {
            $qty  = intval($cat['quantity'] ?? 0);
            $used = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE event_id = %d AND category_index = %d AND status IN ('pending','confirmed')",
                $event_id, $i
            ));

            $result_cats[] = [
                'index'      => $i,
                'name'       => $cat['name'] ?? '',
                'desc'       => $cat['desc'] ?? '',
                'min_spend'  => floatval($cat['min_spend'] ?? 0),
                'min_guests' => intval($cat['min_guests'] ?? 1),
                'max_guests' => intval($cat['max_guests'] ?? 10),
                'available'  => max(0, $qty - $used),
            ];
        }

        wp_send_json_success([
            'available'  => true,
            'categories' => $result_cats,
            'info_text'  => $config['info_text'] ?? '',
        ]);
    }

    // ──────────────────────────────────────────
    // Modal Checkout: Tisch auswählen (in WC Session speichern)
    // ──────────────────────────────────────────

    public static function ajax_mc_table_select() {
        check_ajax_referer('tix_modal_checkout', 'nonce');

        $event_id  = intval($_POST['event_id'] ?? 0);
        $cat_index = intval($_POST['category_index'] ?? -1);
        $guests    = intval($_POST['guest_count'] ?? 1);
        $comments  = sanitize_textarea_field($_POST['comments'] ?? '');
        $skip      = !empty($_POST['skip']); // "Kein Tisch" Option

        if ($skip) {
            // Tischreservierung überspringen – Session löschen
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('tix_table_reservation', null);
            }
            wp_send_json_success(['selected' => false]);
        }

        if (!$event_id || $cat_index < 0) {
            wp_send_json_error(['message' => 'Bitte wähle eine Tischkategorie.']);
        }

        $config = get_post_meta($event_id, '_tix_table_reservation', true);
        if (empty($config['enabled'])) {
            wp_send_json_error(['message' => 'Tischreservierung nicht verfügbar.']);
        }

        $categories = $config['categories'] ?? [];
        if (!isset($categories[$cat_index])) {
            wp_send_json_error(['message' => 'Tischkategorie nicht gefunden.']);
        }
        $cat = $categories[$cat_index];

        // Gästeanzahl validieren
        $min = intval($cat['min_guests'] ?? 1);
        $max = intval($cat['max_guests'] ?? 10);
        if ($guests < $min || $guests > $max) {
            wp_send_json_error(['message' => "Gästeanzahl muss zwischen $min und $max liegen."]);
        }

        // Verfügbarkeit prüfen
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            self::create_table();
        }
        $qty  = intval($cat['quantity'] ?? 0);
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND category_index = %d AND status IN ('pending','confirmed')",
            $event_id, $cat_index
        ));
        if ($used >= $qty) {
            wp_send_json_error(['message' => 'Diese Tischkategorie ist leider ausgebucht.']);
        }

        // In WC Session speichern
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('tix_table_reservation', [
                'event_id'       => $event_id,
                'category_index' => $cat_index,
                'category_name'  => $cat['name'] ?? '',
                'guest_count'    => $guests,
                'comments'       => $comments,
                'min_spend'      => floatval($cat['min_spend'] ?? 0),
            ]);
        }

        wp_send_json_success([
            'selected'      => true,
            'category_name' => $cat['name'] ?? '',
            'guests'        => $guests,
            'min_spend'     => floatval($cat['min_spend'] ?? 0),
        ]);
    }

    // ──────────────────────────────────────────
    // WC Cart Fee: Mindestverzehr als Gebühr
    // ──────────────────────────────────────────

    public static function add_table_fee_to_cart($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!function_exists('WC') || !WC()->session) return;

        $table_data = WC()->session->get('tix_table_reservation');
        if (empty($table_data) || empty($table_data['min_spend'])) return;

        $fee_amount = floatval($table_data['min_spend']);
        $cat_name   = $table_data['category_name'] ?? 'Tisch';

        $cart->add_fee('Tischreservierung: ' . $cat_name, $fee_amount, false);
    }

    // ──────────────────────────────────────────
    // WC Checkout: Reservierung bei Order erstellen
    // ──────────────────────────────────────────

    public static function on_checkout_create_order($order, $data) {
        if (!function_exists('WC') || !WC()->session) return;

        $table_data = WC()->session->get('tix_table_reservation');
        if (empty($table_data)) return;

        $event_id   = intval($table_data['event_id'] ?? 0);
        $cat_index  = intval($table_data['category_index'] ?? 0);
        $cat_name   = $table_data['category_name'] ?? '';
        $guests     = intval($table_data['guest_count'] ?? 1);
        $comments   = $table_data['comments'] ?? '';
        $min_spend  = floatval($table_data['min_spend'] ?? 0);

        if (!$event_id) return;

        // Billing-Daten aus Order
        $name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $email = $order->get_billing_email();
        $phone = $order->get_billing_phone();

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            self::create_table();
        }

        // Verfügbarkeit nochmal prüfen (Race Condition)
        $config = get_post_meta($event_id, '_tix_table_reservation', true);
        $categories = $config['categories'] ?? [];
        if (!isset($categories[$cat_index])) return;

        $qty  = intval($categories[$cat_index]['quantity'] ?? 0);
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND category_index = %d AND status IN ('pending','confirmed')",
            $event_id, $cat_index
        ));
        if ($used >= $qty) return; // Ausgebucht – still kein Fehler, Order geht trotzdem durch

        $token = wp_generate_password(32, false);

        $wpdb->insert($table, [
            'event_id'           => $event_id,
            'category_index'     => $cat_index,
            'category_name'      => $cat_name,
            'table_name'         => null,
            'status'             => 'confirmed',
            'customer_name'      => $name,
            'customer_email'     => $email,
            'customer_phone'     => $phone,
            'guest_count'        => $guests,
            'comments'           => $comments,
            'payment_mode'       => 'checkout',
            'amount_total'       => $min_spend,
            'amount_paid'        => $min_spend,
            'confirmation_token' => $token,
            'created_at'         => current_time('mysql'),
            'confirmed_at'       => current_time('mysql'),
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s','%f','%f','%s','%s','%s']);

        $reservation_id = $wpdb->insert_id;

        if ($reservation_id) {
            $order->update_meta_data('_tix_table_reservation_id', $reservation_id);
            $order->update_meta_data('_tix_table_reservation_event', $event_id);
            $order->update_meta_data('_tix_table_reservation_category', $cat_name);
            $order->update_meta_data('_tix_table_reservation_guests', $guests);

            // Bestätigungs-E-Mail senden
            self::send_confirmation_email($reservation_id);
        }

        // Session aufräumen
        WC()->session->set('tix_table_reservation', null);
    }

    // ──────────────────────────────────────────
    // WC Checkout: Tischreservierung-Sektion
    // ──────────────────────────────────────────

    public static function render_checkout_table_section() {
        if (!function_exists('WC') || !WC()->cart) return;

        // Event-ID aus Warenkorb ermitteln
        $event_id = 0;
        foreach (WC()->cart->get_cart() as $item) {
            $pid = intval($item['product_id']);
            $events = get_posts([
                'post_type'  => 'event',
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key'     => '_tix_ticket_categories',
                        'value'   => '"product_id";i:' . $pid . ';',
                        'compare' => 'LIKE',
                    ],
                    [
                        'key'     => '_tix_ticket_categories',
                        'value'   => '"product_id";s:' . strlen($pid) . ':"' . $pid . '"',
                        'compare' => 'LIKE',
                    ],
                ],
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ]);
            if (!empty($events)) {
                $event_id = $events[0];
                break;
            }
        }

        if (!$event_id) return;

        $config = get_post_meta($event_id, '_tix_table_reservation', true);
        if (empty($config['enabled']) || empty($config['show_in_checkout']) || empty($config['categories'])) return;

        // Verfügbarkeit prüfen
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            self::create_table();
        }

        $has_available = false;
        $cats_data = [];
        foreach ($config['categories'] as $i => $cat) {
            $qty  = intval($cat['quantity'] ?? 0);
            $used = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE event_id = %d AND category_index = %d AND status IN ('pending','confirmed')",
                $event_id, $i
            ));
            $avail   = max(0, $qty - $used);
            $soldOut = $avail <= 0;
            if (!$soldOut) $has_available = true;

            $cats_data[] = [
                'index'      => $i,
                'name'       => $cat['name'] ?? '',
                'desc'       => $cat['desc'] ?? '',
                'min_guests' => intval($cat['min_guests'] ?? 1),
                'max_guests' => intval($cat['max_guests'] ?? 10),
                'min_spend'  => floatval($cat['min_spend'] ?? 0),
                'avail'      => $avail,
                'sold_out'   => $soldOut,
            ];
        }

        if (!$has_available) return;

        $info_text = $config['info_text'] ?? '';
        $nonce     = wp_create_nonce('tix_checkout_table');
        $ajax_url  = admin_url('admin-ajax.php');
        ?>
        <div class="tix-co-tables" id="tix-co-table-section" data-event-id="<?php echo $event_id; ?>" data-nonce="<?php echo $nonce; ?>" data-ajax-url="<?php echo esc_url($ajax_url); ?>">
            <h3 class="tix-co-tables-heading">Tisch reservieren</h3>

            <?php if ($info_text): ?>
                <p class="tix-co-tables-info"><?php echo esc_html($info_text); ?></p>
            <?php endif; ?>

            <div class="tix-co-tables-grid">
                <div class="tix-co-table-card tix-co-table-active" data-index="">
                    <div class="tix-co-table-radio-wrap">
                        <input type="radio" name="tix_table_cat" value="" checked class="tix-co-table-radio">
                        <span class="tix-co-table-radio-dot"></span>
                    </div>
                    <div class="tix-co-table-info">
                        <strong>Kein Tisch</strong>
                        <span class="tix-co-table-meta">Ohne Tischreservierung fortfahren</span>
                    </div>
                </div>

                <?php foreach ($cats_data as $cat): ?>
                <div class="tix-co-table-card<?php echo $cat['sold_out'] ? ' tix-co-table-soldout' : ''; ?>" data-index="<?php echo $cat['index']; ?>">
                    <div class="tix-co-table-radio-wrap">
                        <input type="radio" name="tix_table_cat" value="<?php echo $cat['index']; ?>"<?php echo $cat['sold_out'] ? ' disabled' : ''; ?> class="tix-co-table-radio">
                        <span class="tix-co-table-radio-dot"></span>
                    </div>
                    <div class="tix-co-table-info">
                        <strong><?php echo esc_html($cat['name']); ?></strong>
                        <?php if (!empty($cat['desc'])): ?>
                            <span class="tix-co-table-desc"><?php echo esc_html($cat['desc']); ?></span>
                        <?php endif; ?>
                        <span class="tix-co-table-meta">
                            <?php echo $cat['min_guests']; ?>&ndash;<?php echo $cat['max_guests']; ?> Personen
                            <?php if ($cat['min_spend'] > 0): ?>
                                &middot; <?php echo number_format($cat['min_spend'], 2, ',', '.'); ?>&nbsp;&euro; Mindestverzehr
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="tix-co-table-action">
                        <?php if ($cat['sold_out']): ?>
                            <span class="tix-co-table-soldout-label">Ausgebucht</span>
                        <?php else: ?>
                            <span class="tix-co-table-avail"><?php echo $cat['avail']; ?> verf&uuml;gbar</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="tix-co-table-form" id="tix-co-table-form" style="display:none;">
                <div class="tix-co-table-form-row">
                    <label class="tix-co-table-form-label">Anzahl G&auml;ste</label>
                    <div class="tix-co-table-stepper">
                        <button type="button" class="tix-co-table-step-btn tix-co-qty-btn" data-dir="-1">&minus;</button>
                        <span class="tix-co-table-guest-val" id="tix-co-table-guests">2</span>
                        <button type="button" class="tix-co-table-step-btn tix-co-qty-btn" data-dir="1">+</button>
                    </div>
                </div>
                <div class="tix-co-table-form-row">
                    <label class="tix-co-table-form-label" for="tix-co-table-comments">Anmerkungen (optional)</label>
                    <textarea id="tix-co-table-comments" class="tix-co-input tix-co-textarea" placeholder="Besondere W&uuml;nsche, Anlass&hellip;" rows="2"></textarea>
                </div>
            </div>

            <div class="tix-co-table-status" id="tix-co-table-status" style="display:none;"></div>
        </div>

        <script>
        (function(){
            var section = document.getElementById('tix-co-table-section');
            if (!section) return;
            var eventId  = section.dataset.eventId;
            var nonce    = section.dataset.nonce;
            var ajaxUrl  = section.dataset.ajaxUrl || '/wp-admin/admin-ajax.php';
            var form     = document.getElementById('tix-co-table-form');
            var status   = document.getElementById('tix-co-table-status');
            var guestsEl = document.getElementById('tix-co-table-guests');
            var commentsEl = document.getElementById('tix-co-table-comments');
            var cats = <?php echo json_encode(array_map(function($c) {
                return ['index' => $c['index'], 'min_guests' => $c['min_guests'], 'max_guests' => $c['max_guests'], 'min_spend' => $c['min_spend']];
            }, $cats_data)); ?>;
            var selected = null;
            var guests = 2;

            // Radio-Change via Card-Klick
            section.addEventListener('click', function(e) {
                var card = e.target.closest('.tix-co-table-card');
                if (!card || card.classList.contains('tix-co-table-soldout')) return;
                var radio = card.querySelector('.tix-co-table-radio');
                if (radio && !radio.checked) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', {bubbles: true}));
                }
            });

            section.querySelectorAll('.tix-co-table-radio').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    // Active-Klasse setzen
                    section.querySelectorAll('.tix-co-table-card').forEach(function(c) { c.classList.remove('tix-co-table-active'); });
                    this.closest('.tix-co-table-card').classList.add('tix-co-table-active');

                    if (!this.value) {
                        selected = null;
                        form.style.display = 'none';
                        sendSelection(true);
                        return;
                    }
                    var idx = parseInt(this.value);
                    var cat = null;
                    cats.forEach(function(c) { if (c.index === idx) cat = c; });
                    if (!cat) return;
                    selected = cat;
                    guests = cat.min_guests;
                    guestsEl.textContent = guests;
                    form.style.display = '';
                    sendSelection(false);
                });
            });

            section.querySelectorAll('.tix-co-table-step-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (!selected) return;
                    var dir = parseInt(this.dataset.dir);
                    var nv = guests + dir;
                    if (nv >= selected.min_guests && nv <= selected.max_guests) {
                        guests = nv;
                        guestsEl.textContent = guests;
                    }
                });
            });

            function sendSelection(skip) {
                var fd = new FormData();
                fd.append('action', 'tix_checkout_table_select');
                fd.append('nonce', nonce);
                if (skip) {
                    fd.append('skip', '1');
                } else {
                    fd.append('event_id', eventId);
                    fd.append('category_index', selected.index);
                    fd.append('guest_count', guests);
                    fd.append('comments', commentsEl ? commentsEl.value : '');
                }
                fetch(ajaxUrl, {method: 'POST', body: fd, credentials: 'same-origin'})
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            status.style.display = '';
                            status.className = 'tix-co-table-status tix-co-table-status-ok';
                            status.textContent = skip ? '' : 'Tisch wird reserviert';
                            if (skip) status.style.display = 'none';
                            // Totals aktualisieren
                            if (res.data.total) {
                                var t = document.querySelector('.tix-co-total');
                                if (t) t.innerHTML = res.data.total;
                                var bp = document.querySelector('.tix-co-submit-price');
                                if (bp) bp.innerHTML = res.data.total;
                                if (res.data.subtotal) {
                                    var st = document.querySelector('.tix-co-subtotal');
                                    if (st) st.innerHTML = res.data.subtotal;
                                }
                                if (res.data.fees_html !== undefined) {
                                    var f = document.querySelector('.tix-co-fees');
                                    if (f) f.innerHTML = res.data.fees_html;
                                }
                            }
                            // WC native update
                            if (typeof jQuery !== 'undefined') jQuery(document.body).trigger('update_checkout');
                        } else {
                            status.style.display = '';
                            status.className = 'tix-co-table-status tix-co-table-status-err';
                            status.textContent = res.data && res.data.message ? res.data.message : 'Fehler';
                        }
                    })
                    .catch(function() {});
            }
        })();
        </script>
        <?php
    }

    // ──────────────────────────────────────────
    // WC Checkout: AJAX Tischauswahl speichern
    // ──────────────────────────────────────────

    public static function ajax_checkout_table_select() {
        check_ajax_referer('tix_checkout_table', 'nonce');

        $skip = !empty($_POST['skip']);
        if ($skip) {
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('tix_table_reservation', null);
            }
            // Recalculate totals without table fee
            $totals = [];
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->calculate_fees();
                WC()->cart->calculate_totals();
                $totals = [
                    'total'     => strip_tags(WC()->cart->get_total()),
                    'subtotal'  => strip_tags(WC()->cart->get_cart_subtotal()),
                    'fees_html' => class_exists('TIX_Checkout') ? TIX_Checkout::render_fee_rows() : '',
                ];
            }
            wp_send_json_success(array_merge(['selected' => false], $totals));
        }

        $event_id  = intval($_POST['event_id'] ?? 0);
        $cat_index = intval($_POST['category_index'] ?? -1);
        $guests    = intval($_POST['guest_count'] ?? 1);
        $comments  = sanitize_textarea_field($_POST['comments'] ?? '');

        if (!$event_id || $cat_index < 0) {
            wp_send_json_error(['message' => 'Bitte wähle eine Tischkategorie.']);
        }

        $config = get_post_meta($event_id, '_tix_table_reservation', true);
        if (empty($config['enabled'])) {
            wp_send_json_error(['message' => 'Tischreservierung nicht verfügbar.']);
        }

        $categories = $config['categories'] ?? [];
        if (!isset($categories[$cat_index])) {
            wp_send_json_error(['message' => 'Tischkategorie nicht gefunden.']);
        }
        $cat = $categories[$cat_index];

        $min = intval($cat['min_guests'] ?? 1);
        $max = intval($cat['max_guests'] ?? 10);
        if ($guests < $min || $guests > $max) {
            wp_send_json_error(['message' => "Gästeanzahl muss zwischen $min und $max liegen."]);
        }

        // Verfügbarkeit
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            self::create_table();
        }
        $qty  = intval($cat['quantity'] ?? 0);
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d AND category_index = %d AND status IN ('pending','confirmed')",
            $event_id, $cat_index
        ));
        if ($used >= $qty) {
            wp_send_json_error(['message' => 'Diese Tischkategorie ist leider ausgebucht.']);
        }

        // In WC Session speichern
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('tix_table_reservation', [
                'event_id'       => $event_id,
                'category_index' => $cat_index,
                'category_name'  => $cat['name'] ?? '',
                'guest_count'    => $guests,
                'comments'       => $comments,
                'min_spend'      => floatval($cat['min_spend'] ?? 0),
            ]);
        }

        // Recalculate cart totals to include table fee
        $totals = [];
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->calculate_fees();
            WC()->cart->calculate_totals();
            $totals = [
                'total'     => strip_tags(WC()->cart->get_total()),
                'subtotal'  => strip_tags(WC()->cart->get_cart_subtotal()),
                'fees_html' => class_exists('TIX_Checkout') ? TIX_Checkout::render_fee_rows() : '',
            ];
        }

        wp_send_json_success(array_merge([
            'selected'      => true,
            'category_name' => $cat['name'] ?? '',
        ], $totals));
    }

    // ──────────────────────────────────────────
    // Bestätigungs-E-Mail
    // ──────────────────────────────────────────

    public static function send_confirmation_email($reservation_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $res = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $reservation_id));
        if (!$res) return;

        $event = get_post($res->event_id);
        if (!$event) return;

        $date       = get_post_meta($res->event_id, '_tix_date_start', true);
        $time_start = get_post_meta($res->event_id, '_tix_time_start', true);
        $time_doors = get_post_meta($res->event_id, '_tix_time_doors', true);
        $loc_id     = get_post_meta($res->event_id, '_tix_location_id', true);
        $location   = $loc_id ? get_the_title($loc_id) : '';
        $loc_addr   = $loc_id ? get_post_meta($loc_id, '_tix_loc_address', true) : '';

        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $accent = $s['color_accent'] ?? '#c8ff00';
        $logo   = $s['email_logo_url'] ?? '';
        $brand  = $s['email_brand_name'] ?? get_bloginfo('name');
        $footer = $s['email_footer_text'] ?? '';

        // Datum formatieren
        $date_formatted = '';
        if ($date) {
            $ts = strtotime($date);
            $weekdays = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
            $months   = ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
            $date_formatted = $weekdays[date('w', $ts)] . ', ' . date('j', $ts) . '. ' . $months[date('n', $ts)] . ' ' . date('Y', $ts);
        }

        // Zahlungsinfo
        $payment_info = '';
        if ($res->payment_mode === 'on_site') {
            $payment_info = 'Zahlung vor Ort – ' . number_format($res->amount_total, 2, ',', '.') . ' €';
        } elseif ($res->payment_mode === 'deposit') {
            $payment_info = 'Anzahlung bezahlt: ' . number_format($res->amount_paid, 2, ',', '.') . ' € – Restzahlung vor Ort: ' . number_format($res->amount_total - $res->amount_paid, 2, ',', '.') . ' €';
        } elseif ($res->payment_mode === 'full') {
            $payment_info = 'Vollständig bezahlt: ' . number_format($res->amount_total, 2, ',', '.') . ' €';
        }

        $subject = 'Reservierungsbestätigung – ' . $event->post_title;

        $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">';
        $body .= '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px">';
        $body .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08)">';

        // Header
        if ($logo) {
            $body .= '<tr><td style="padding:32px 40px 16px;text-align:center"><img src="' . esc_url($logo) . '" alt="' . esc_attr($brand) . '" style="max-width:180px;height:auto"></td></tr>';
        }
        $body .= '<tr><td style="padding:16px 40px 8px;text-align:center;font-size:28px;font-weight:700;color:#111">✅ Reservierung bestätigt</td></tr>';
        $body .= '<tr><td style="padding:8px 40px 24px;text-align:center;color:#666;font-size:15px">Deine Tischreservierung wurde erfolgreich bestätigt.</td></tr>';

        // Details
        $body .= '<tr><td style="padding:0 40px"><table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-radius:12px;padding:24px">';
        $body .= '<tr><td style="padding:12px 24px;border-bottom:1px solid #e5e7eb"><strong style="color:#374151">Event</strong><br><span style="color:#111;font-size:16px">' . esc_html($event->post_title) . '</span></td></tr>';
        $body .= '<tr><td style="padding:12px 24px;border-bottom:1px solid #e5e7eb"><strong style="color:#374151">Datum</strong><br><span style="color:#111">' . esc_html($date_formatted) . '</span></td></tr>';
        if ($time_start) {
            $body .= '<tr><td style="padding:12px 24px;border-bottom:1px solid #e5e7eb"><strong style="color:#374151">Uhrzeit</strong><br><span style="color:#111">' . esc_html($time_start) . ' Uhr' . ($time_doors ? ' (Einlass: ' . esc_html($time_doors) . ' Uhr)' : '') . '</span></td></tr>';
        }
        if ($location) {
            $body .= '<tr><td style="padding:12px 24px;border-bottom:1px solid #e5e7eb"><strong style="color:#374151">Location</strong><br><span style="color:#111">' . esc_html($location) . ($loc_addr ? '<br>' . esc_html($loc_addr) : '') . '</span></td></tr>';
        }
        $body .= '<tr><td style="padding:12px 24px;border-bottom:1px solid #e5e7eb"><strong style="color:#374151">Tisch</strong><br><span style="color:#111">' . esc_html($res->category_name) . '</span></td></tr>';
        $body .= '<tr><td style="padding:12px 24px;border-bottom:1px solid #e5e7eb"><strong style="color:#374151">Gäste</strong><br><span style="color:#111">' . intval($res->guest_count) . ' Personen</span></td></tr>';
        $body .= '<tr><td style="padding:12px 24px"><strong style="color:#374151">Zahlung</strong><br><span style="color:#111">' . esc_html($payment_info) . '</span></td></tr>';
        $body .= '</table></td></tr>';

        // Reservierungsnummer
        $body .= '<tr><td style="padding:24px 40px;text-align:center;color:#6b7280;font-size:13px">Reservierungsnummer: <strong>#' . intval($res->id) . '</strong></td></tr>';

        // Footer
        if ($footer) {
            $body .= '<tr><td style="padding:16px 40px 32px;text-align:center;color:#9ca3af;font-size:12px">' . wp_kses_post($footer) . '</td></tr>';
        }

        $body .= '</table></td></tr></table></body></html>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($res->customer_email, $subject, $body, $headers);

        // Admin-Benachrichtigung
        $admin_email = get_option('admin_email');
        $admin_subject = 'Neue Tischreservierung: ' . $event->post_title . ' – ' . $res->category_name;
        $admin_body = "Neue Tischreservierung eingegangen:\n\n";
        $admin_body .= "Event: {$event->post_title}\n";
        $admin_body .= "Datum: {$date_formatted}\n";
        $admin_body .= "Tisch: {$res->category_name}\n";
        $admin_body .= "Gäste: {$res->guest_count}\n";
        $admin_body .= "Name: {$res->customer_name}\n";
        $admin_body .= "E-Mail: {$res->customer_email}\n";
        $admin_body .= "Telefon: {$res->customer_phone}\n";
        if ($res->comments) $admin_body .= "Kommentar: {$res->comments}\n";
        $admin_body .= "\nZahlungsmodus: {$res->payment_mode}\n";
        $admin_body .= "Betrag: " . number_format($res->amount_total, 2, ',', '.') . " €\n";

        wp_mail($admin_email, $admin_subject, $admin_body);
    }

    // ──────────────────────────────────────────
    // Metabox-Rendering (vom Metabox aufgerufen)
    // ──────────────────────────────────────────

    public static function render_metabox($post) {
        $config = get_post_meta($post->ID, '_tix_table_reservation', true);
        if (!is_array($config)) $config = [];

        $enabled       = !empty($config['enabled']);
        $payment_mode  = $config['payment_mode']  ?? 'on_site';
        $deposit_type  = $config['deposit_type']  ?? 'percent';
        $deposit_value = $config['deposit_value']  ?? 50;
        $floor_plan_id = intval($config['floor_plan_id'] ?? 0);
        $categories    = $config['categories'] ?? [];
        if (empty($categories) && empty($config)) {
            $defaults_json = function_exists('tix_get_settings') ? (tix_get_settings()['table_default_categories'] ?? '[]') : '[]';
            $defaults = json_decode($defaults_json, true);
            if (!empty($defaults) && is_array($defaults)) {
                $categories = $defaults;
            }
        }
        if (empty($categories)) {
            $categories = [self::empty_category()];
        }
        ?>

        <div class="tix-toggle-wrap">
            <label class="tix-toggle-label">
                <input type="hidden" name="tix_table_reservation[enabled]" value="0">
                <input type="checkbox" name="tix_table_reservation[enabled]" value="1" id="tix-table-res-enabled"
                       <?php checked($enabled); ?>>
                <span class="tix-toggle-text">Tischreservierung für dieses Event aktivieren</span>
            </label>
        </div>

        <div id="tix-table-res-panel" <?php echo !$enabled ? 'style="display:none"' : ''; ?>>

            <?php // ── Zahlungsmodus ── ?>
            <div class="tix-field-group" style="margin-top:16px">
                <label class="tix-field-label">Zahlungsmodus</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <label class="tix-radio-card <?php echo $payment_mode === 'on_site' ? 'active' : ''; ?>">
                        <input type="radio" name="tix_table_reservation[payment_mode]" value="on_site" <?php checked($payment_mode, 'on_site'); ?>>
                        <span class="tix-radio-icon">🪑</span>
                        <span class="tix-radio-title">Zahlung vor Ort</span>
                        <span class="tix-radio-desc">Keine Online-Zahlung</span>
                    </label>
                    <label class="tix-radio-card <?php echo $payment_mode === 'deposit' ? 'active' : ''; ?>">
                        <input type="radio" name="tix_table_reservation[payment_mode]" value="deposit" <?php checked($payment_mode, 'deposit'); ?>>
                        <span class="tix-radio-icon">💳</span>
                        <span class="tix-radio-title">Anzahlung</span>
                        <span class="tix-radio-desc">Teilbetrag vorab</span>
                    </label>
                    <label class="tix-radio-card <?php echo $payment_mode === 'full' ? 'active' : ''; ?>">
                        <input type="radio" name="tix_table_reservation[payment_mode]" value="full" <?php checked($payment_mode, 'full'); ?>>
                        <span class="tix-radio-icon">✅</span>
                        <span class="tix-radio-title">Volle Zahlung</span>
                        <span class="tix-radio-desc">Komplett vorab</span>
                    </label>
                </div>
            </div>

            <?php // ── Anzahlung Details ── ?>
            <div class="tix-field-group tix-deposit-fields" style="margin-top:12px;<?php echo $payment_mode !== 'deposit' ? 'display:none' : ''; ?>">
                <label class="tix-field-label">Anzahlung</label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="number" name="tix_table_reservation[deposit_value]" value="<?php echo esc_attr($deposit_value); ?>"
                           min="1" step="1" style="width:100px" class="tix-input">
                    <select name="tix_table_reservation[deposit_type]" class="tix-select-sm">
                        <option value="percent" <?php selected($deposit_type, 'percent'); ?>>%</option>
                        <option value="fixed" <?php selected($deposit_type, 'fixed'); ?>>€ (fest)</option>
                    </select>
                </div>
            </div>

            <?php // ── Info-Text ── ?>
            <div class="tix-field-group" style="margin-top:16px">
                <label class="tix-field-label">Info-Text (optional)</label>
                <textarea name="tix_table_reservation[info_text]" class="tix-input" rows="2"
                          placeholder="z.B. Tickets m&uuml;ssen an der Abendkasse gekauft werden"
                          style="width:100%"><?php echo esc_textarea($config['info_text'] ?? ''); ?></textarea>
                <p class="description" style="font-size:12px;color:#6b7280;margin-top:4px">Wird im Reservierungsformular angezeigt.</p>
            </div>

            <?php // ── Im Checkout anzeigen ── ?>
            <div class="tix-field-group" style="margin-top:16px">
                <label class="tix-toggle-label">
                    <input type="hidden" name="tix_table_reservation[show_in_checkout]" value="0">
                    <input type="checkbox" name="tix_table_reservation[show_in_checkout]" value="1"
                           <?php checked(!empty($config['show_in_checkout'])); ?>>
                    <span class="tix-toggle-text">Im Ticket-Kaufprozess anzeigen</span>
                </label>
                <p class="description" style="font-size:12px;color:#6b7280;margin-top:4px">Wenn aktiviert, wird die Tischreservierung als optionaler Schritt im Ticket-Modal und im WooCommerce-Checkout angeboten.</p>
            </div>

            <?php // ── Raumplan ── ?>
            <div class="tix-field-group" style="margin-top:16px">
                <label class="tix-field-label">Raumplan (optional)</label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="hidden" name="tix_table_reservation[floor_plan_id]" id="tix-floor-plan-id" value="<?php echo $floor_plan_id; ?>">
                    <?php if ($floor_plan_id): ?>
                        <img src="<?php echo esc_url(wp_get_attachment_url($floor_plan_id)); ?>" style="max-width:200px;border-radius:8px;border:1px solid #ddd" id="tix-floor-plan-preview">
                    <?php else: ?>
                        <img src="" style="max-width:200px;border-radius:8px;border:1px solid #ddd;display:none" id="tix-floor-plan-preview">
                    <?php endif; ?>
                    <button type="button" class="button" id="tix-floor-plan-upload">Bild wählen</button>
                    <button type="button" class="button" id="tix-floor-plan-remove" <?php echo !$floor_plan_id ? 'style="display:none"' : ''; ?>>Entfernen</button>
                </div>
            </div>

            <?php // ── Tischkategorien ── ?>
            <div class="tix-field-group" style="margin-top:24px">
                <label class="tix-field-label" style="font-size:15px;font-weight:600">Tischkategorien</label>

                <div id="tix-table-categories">
                    <?php foreach ($categories as $i => $cat): ?>
                    <div class="tix-table-cat-row" style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:12px">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                            <strong style="font-size:14px">Kategorie <?php echo $i + 1; ?></strong>
                            <button type="button" class="button tix-remove-table-cat" style="color:#dc2626;border-color:#dc2626" title="Entfernen">✕</button>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px">
                            <div>
                                <label class="tix-mini-label">Name *</label>
                                <input type="text" name="tix_table_reservation[categories][<?php echo $i; ?>][name]" value="<?php echo esc_attr($cat['name'] ?? ''); ?>" class="tix-input" placeholder="z.B. VIP Lounge">
                            </div>
                            <div>
                                <label class="tix-mini-label">Mindestverzehr / Zahlbetrag (€)</label>
                                <input type="number" name="tix_table_reservation[categories][<?php echo $i; ?>][min_spend]" value="<?php echo esc_attr($cat['min_spend'] ?? ''); ?>" class="tix-input" step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div>
                                <label class="tix-mini-label">Verfügbare Tische</label>
                                <input type="number" name="tix_table_reservation[categories][<?php echo $i; ?>][quantity]" value="<?php echo esc_attr($cat['quantity'] ?? ''); ?>" class="tix-input" min="0" placeholder="5">
                            </div>
                            <div>
                                <label class="tix-mini-label">Min. Gäste</label>
                                <input type="number" name="tix_table_reservation[categories][<?php echo $i; ?>][min_guests]" value="<?php echo esc_attr($cat['min_guests'] ?? 1); ?>" class="tix-input" min="1" placeholder="1">
                            </div>
                            <div>
                                <label class="tix-mini-label">Max. Gäste</label>
                                <input type="number" name="tix_table_reservation[categories][<?php echo $i; ?>][max_guests]" value="<?php echo esc_attr($cat['max_guests'] ?? 10); ?>" class="tix-input" min="1" placeholder="10">
                            </div>
                            <div style="grid-column:1/-1">
                                <label class="tix-mini-label">Beschreibung</label>
                                <textarea name="tix_table_reservation[categories][<?php echo $i; ?>][desc]" class="tix-input" rows="2" placeholder="Exklusiver Bereich mit Bottle-Service..."><?php echo esc_textarea($cat['desc'] ?? ''); ?></textarea>
                            </div>
                            <div style="grid-column:1/-1;margin-top:8px">
                                <details class="tix-table-details">
                                    <summary style="cursor:pointer;font-size:13px;color:#6b7280;user-select:none">Einzelne Tische (optional &ndash; f&uuml;r Tischauswahl auf dem Raumplan)</summary>
                                    <div class="tix-individual-tables" data-cat-index="<?php echo $i; ?>" style="margin-top:8px">
                                        <?php
                                        $tables = $cat['tables'] ?? [];
                                        if (!empty($tables)):
                                            foreach ($tables as $ti => $t): ?>
                                            <div class="tix-table-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                                                <input type="text" name="tix_table_reservation[categories][<?php echo $i; ?>][tables][<?php echo $ti; ?>][name]" value="<?php echo esc_attr($t['name'] ?? ''); ?>" class="tix-input" style="flex:2" placeholder="z.B. VIP 1">
                                                <label style="font-size:11px;color:#999">X%</label>
                                                <input type="number" name="tix_table_reservation[categories][<?php echo $i; ?>][tables][<?php echo $ti; ?>][x]" value="<?php echo esc_attr($t['x'] ?? 0); ?>" class="tix-input" style="width:60px" min="0" max="100" step="0.1">
                                                <label style="font-size:11px;color:#999">Y%</label>
                                                <input type="number" name="tix_table_reservation[categories][<?php echo $i; ?>][tables][<?php echo $ti; ?>][y]" value="<?php echo esc_attr($t['y'] ?? 0); ?>" class="tix-input" style="width:60px" min="0" max="100" step="0.1">
                                                <button type="button" class="button tix-remove-table-item" style="color:#dc2626;border-color:#dc2626;flex-shrink:0" title="Entfernen">&#x2715;</button>
                                            </div>
                                        <?php endforeach;
                                        endif; ?>
                                    </div>
                                    <button type="button" class="button tix-add-table-item" data-cat-index="<?php echo $i; ?>" style="margin-top:4px;font-size:12px">+ Tisch hinzuf&uuml;gen</button>
                                </details>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="button" id="tix-add-table-cat" style="margin-top:8px">
                    + Kategorie hinzufügen
                </button>
            </div>
        </div>

        <script>
        jQuery(function($) {
            // Toggle Panel
            $('#tix-table-res-enabled').on('change', function() {
                $('#tix-table-res-panel').toggle(this.checked);
            });

            // Payment mode radio
            $('input[name="tix_table_reservation[payment_mode]"]').on('change', function() {
                $('.tix-radio-card').removeClass('active');
                $(this).closest('.tix-radio-card').addClass('active');
                $('.tix-deposit-fields').toggle($(this).val() === 'deposit');
            });

            // Floor plan upload
            $('#tix-floor-plan-upload').on('click', function(e) {
                e.preventDefault();
                var frame = wp.media({ title: 'Raumplan wählen', multiple: false, library: { type: 'image' } });
                frame.on('select', function() {
                    var att = frame.state().get('selection').first().toJSON();
                    $('#tix-floor-plan-id').val(att.id);
                    $('#tix-floor-plan-preview').attr('src', att.url).show();
                    $('#tix-floor-plan-remove').show();
                });
                frame.open();
            });
            $('#tix-floor-plan-remove').on('click', function() {
                $('#tix-floor-plan-id').val('0');
                $('#tix-floor-plan-preview').hide();
                $(this).hide();
            });

            // Add category
            $('#tix-add-table-cat').on('click', function() {
                var idx = $('#tix-table-categories .tix-table-cat-row').length;
                var tpl = `<div class="tix-table-cat-row" style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                        <strong style="font-size:14px">Kategorie ${idx+1}</strong>
                        <button type="button" class="button tix-remove-table-cat" style="color:#dc2626;border-color:#dc2626" title="Entfernen">✕</button>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px">
                        <div><label class="tix-mini-label">Name *</label><input type="text" name="tix_table_reservation[categories][${idx}][name]" class="tix-input" placeholder="z.B. VIP Lounge"></div>
                        <div><label class="tix-mini-label">Mindestverzehr / Zahlbetrag (€)</label><input type="number" name="tix_table_reservation[categories][${idx}][min_spend]" class="tix-input" step="0.01" min="0" placeholder="0.00"></div>
                        <div><label class="tix-mini-label">Verfügbare Tische</label><input type="number" name="tix_table_reservation[categories][${idx}][quantity]" class="tix-input" min="0" placeholder="5"></div>
                        <div><label class="tix-mini-label">Min. Gäste</label><input type="number" name="tix_table_reservation[categories][${idx}][min_guests]" value="1" class="tix-input" min="1" placeholder="1"></div>
                        <div><label class="tix-mini-label">Max. Gäste</label><input type="number" name="tix_table_reservation[categories][${idx}][max_guests]" value="10" class="tix-input" min="1" placeholder="10"></div>
                        <div style="grid-column:1/-1"><label class="tix-mini-label">Beschreibung</label><textarea name="tix_table_reservation[categories][${idx}][desc]" class="tix-input" rows="2" placeholder="Exklusiver Bereich mit Bottle-Service..."></textarea></div>
                        <div style="grid-column:1/-1;margin-top:8px">
                            <details class="tix-table-details">
                                <summary style="cursor:pointer;font-size:13px;color:#6b7280;user-select:none">Einzelne Tische (optional – für Tischauswahl auf dem Raumplan)</summary>
                                <div class="tix-individual-tables" data-cat-index="${idx}" style="margin-top:8px"></div>
                                <button type="button" class="button tix-add-table-item" data-cat-index="${idx}" style="margin-top:4px;font-size:12px">+ Tisch hinzufügen</button>
                            </details>
                        </div>
                    </div>
                </div>`;
                $('#tix-table-categories').append(tpl);
            });

            // Remove category (alle löschbar – beim Speichern werden Defaults geladen wenn leer)
            $(document).on('click', '.tix-remove-table-cat', function() {
                $(this).closest('.tix-table-cat-row').remove();
                // Re-index
                $('#tix-table-categories .tix-table-cat-row').each(function(i) {
                    $(this).find('strong:first').text('Kategorie ' + (i+1));
                    $(this).find('[name]').each(function() {
                        this.name = this.name.replace(/\[categories\]\[\d+\]/, '[categories][' + i + ']');
                    });
                    $(this).find('.tix-individual-tables').attr('data-cat-index', i);
                    $(this).find('.tix-add-table-item').attr('data-cat-index', i);
                });
            });

            // Add individual table
            $(document).on('click', '.tix-add-table-item', function() {
                var catIdx = $(this).attr('data-cat-index');
                var container = $(this).siblings('.tix-individual-tables');
                var tIdx = container.find('.tix-table-row').length;
                var tpl = `<div class="tix-table-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                    <input type="text" name="tix_table_reservation[categories][${catIdx}][tables][${tIdx}][name]" class="tix-input" style="flex:2" placeholder="z.B. VIP 1">
                    <label style="font-size:11px;color:#999">X%</label>
                    <input type="number" name="tix_table_reservation[categories][${catIdx}][tables][${tIdx}][x]" value="0" class="tix-input" style="width:60px" min="0" max="100" step="0.1">
                    <label style="font-size:11px;color:#999">Y%</label>
                    <input type="number" name="tix_table_reservation[categories][${catIdx}][tables][${tIdx}][y]" value="0" class="tix-input" style="width:60px" min="0" max="100" step="0.1">
                    <button type="button" class="button tix-remove-table-item" style="color:#dc2626;border-color:#dc2626;flex-shrink:0" title="Entfernen">✕</button>
                </div>`;
                container.append(tpl);
            });

            // Remove individual table
            $(document).on('click', '.tix-remove-table-item', function() {
                var container = $(this).closest('.tix-individual-tables');
                $(this).closest('.tix-table-row').remove();
                // Re-index tables
                var catIdx = container.attr('data-cat-index');
                container.find('.tix-table-row').each(function(ti) {
                    $(this).find('[name]').each(function() {
                        this.name = this.name.replace(/\[tables\]\[\d+\]/, '[tables][' + ti + ']');
                    });
                });
            });
        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────
    // Metabox Save (vom Metabox aufgerufen)
    // ──────────────────────────────────────────

    public static function save_metabox($post_id) {
        $raw = $_POST['tix_table_reservation'] ?? [];
        if (empty($raw)) return;

        $data = [
            'enabled'       => !empty($raw['enabled']) ? '1' : '',
            'payment_mode'  => in_array($raw['payment_mode'] ?? '', ['deposit','full','on_site']) ? $raw['payment_mode'] : 'on_site',
            'deposit_type'  => in_array($raw['deposit_type'] ?? '', ['percent','fixed']) ? $raw['deposit_type'] : 'percent',
            'deposit_value' => max(1, floatval($raw['deposit_value'] ?? 50)),
            'floor_plan_id' => intval($raw['floor_plan_id'] ?? 0),
            'info_text'          => sanitize_textarea_field($raw['info_text'] ?? ''),
            'show_in_checkout'   => !empty($raw['show_in_checkout']) ? '1' : '',
            'categories'         => [],
        ];

        $raw_cats = $raw['categories'] ?? [];
        if (is_array($raw_cats)) {
            foreach ($raw_cats as $cat) {
                $name = sanitize_text_field($cat['name'] ?? '');
                if (empty($name)) continue;

                $tables = array_values(array_filter(
                    array_map(function($t) {
                        $tname = sanitize_text_field($t['name'] ?? '');
                        return $tname ? [
                            'name' => $tname,
                            'x'    => floatval($t['x'] ?? 0),
                            'y'    => floatval($t['y'] ?? 0),
                        ] : null;
                    }, $cat['tables'] ?? [])
                ));

                $data['categories'][] = [
                    'name'       => $name,
                    'desc'       => sanitize_textarea_field($cat['desc'] ?? ''),
                    'min_spend'  => floatval($cat['min_spend'] ?? 0),
                    'min_guests' => max(1, intval($cat['min_guests'] ?? 1)),
                    'max_guests' => max(1, intval($cat['max_guests'] ?? 10)),
                    'quantity'   => max(0, intval($cat['quantity'] ?? 0)),
                    'tables'     => $tables,
                ];
            }
        }

        update_post_meta($post_id, '_tix_table_reservation', $data);
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    private static function empty_category() {
        return [
            'name' => '', 'desc' => '', 'min_spend' => 0,
            'min_guests' => 1, 'max_guests' => 10,
            'quantity' => 0, 'tables' => [],
        ];
    }
}
