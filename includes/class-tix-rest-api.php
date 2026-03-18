<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_REST_API – REST-Endpunkte für die Tixomat App (iOS/Android).
 *
 * Namespace: tixomat/v1
 * Auth: WordPress Application Passwords (Basic Auth, nativ seit WP 5.6)
 *
 * Portiert die AJAX-Logik aus class-tix-checkin.php, class-tix-pos.php
 * und class-tix-organizer-dashboard.php in saubere REST-Endpoints.
 *
 * @since 1.34.0
 */
class TIX_REST_API {

    const NAMESPACE = 'tixomat/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Token-basierte Authentifizierung für Guest-User
        add_filter('determine_current_user', [__CLASS__, 'authenticate_by_token'], 30);
    }

    /**
     * Authentifiziert User anhand des X-Tix-Token Headers.
     */
    public static function authenticate_by_token($user_id) {
        // Nur greifen wenn noch nicht authentifiziert
        if ($user_id) return $user_id;

        // Nur für REST-API Requests
        if (!defined('REST_REQUEST') || !REST_REQUEST) return $user_id;

        $token = '';
        if (isset($_SERVER['HTTP_X_TIX_TOKEN'])) {
            $token = $_SERVER['HTTP_X_TIX_TOKEN'];
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
            if (str_starts_with($auth, 'Bearer ')) {
                $token = substr($auth, 7);
            }
        }

        if (empty($token)) return $user_id;

        $hashed = hash('sha256', $token);

        // User mit passendem Token finden
        $users = get_users([
            'meta_key'   => '_tix_app_token',
            'number'     => 10,
            'fields'     => 'ids',
        ]);

        foreach ($users as $uid) {
            $data = get_user_meta($uid, '_tix_app_token', true);
            if (!is_array($data)) continue;
            if (!isset($data['token'], $data['expires'])) continue;

            if (hash_equals($data['token'], $hashed)) {
                if ($data['expires'] > time()) {
                    return $uid;
                }
                // Token abgelaufen → aufräumen
                delete_user_meta($uid, '_tix_app_token');
                return $user_id;
            }
        }

        return $user_id;
    }

    // ═══════════════════════════════════════════
    //  ROUTEN REGISTRIEREN
    // ═══════════════════════════════════════════

    public static function register_routes() {
        $ns = self::NAMESPACE;

        // ── Discovery (unauthenticated) ──
        register_rest_route($ns, '/info', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_info'],
            'permission_callback' => '__return_true',
        ]);

        // ── Auth ──
        register_rest_route($ns, '/me', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_me'],
            'permission_callback' => [__CLASS__, 'check_authenticated'],
        ]);

        // ── Events (Organizer-scoped) ──
        register_rest_route($ns, '/events', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_events'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/events/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_event'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/events', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'create_event'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/events/(?P<id>\d+)/statistics', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'event_statistics'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/events/(?P<id>\d+)/orders', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'event_orders'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        // ── Check-in ──
        register_rest_route($ns, '/checkin/scan', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'checkin_scan'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/checkin/(?P<event_id>\d+)/list', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'checkin_list'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/checkin/(?P<event_id>\d+)/guest/(?P<guest_id>[A-Za-z0-9-]+)', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'checkin_update_guest'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/checkin/ticket/(?P<ticket_id>\d+)/toggle', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'checkin_toggle_ticket'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        // ── Gästeliste ──
        register_rest_route($ns, '/events/(?P<id>\d+)/guestlist', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_guestlist'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/events/(?P<id>\d+)/guestlist', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'save_guestlist'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        // ── Tickets ──
        register_rest_route($ns, '/events/(?P<id>\d+)/tickets', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_tickets'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/tickets/(?P<id>\d+)/resend-email', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'resend_ticket_email'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        // ── POS ──
        register_rest_route($ns, '/pos/events/(?P<id>\d+)/categories', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'pos_categories'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/pos/orders', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pos_create_order'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/pos/orders/(?P<id>\d+)/email', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pos_send_email'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/pos/orders/(?P<id>\d+)/void', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pos_void_order'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/pos/report', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'pos_report'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/pos/transactions', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'pos_transactions'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        // ── Guest / Customer Auth (unauthenticated) ──
        register_rest_route($ns, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'auth_login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/auth/register', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'auth_register'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route($ns, '/auth/profile', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [__CLASS__, 'auth_profile'],
            'permission_callback' => [__CLASS__, 'check_authenticated'],
        ]);

        register_rest_route($ns, '/auth/profile/avatar', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'auth_profile_avatar'],
            'permission_callback' => [__CLASS__, 'check_authenticated'],
        ]);

        register_rest_route($ns, '/customer/tickets', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'customer_tickets'],
            'permission_callback' => [__CLASS__, 'check_authenticated'],
        ]);

        register_rest_route($ns, '/customer/events', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'customer_events'],
            'permission_callback' => [__CLASS__, 'check_authenticated'],
        ]);
    }

    // ═══════════════════════════════════════════
    //  PERMISSION CALLBACKS
    // ═══════════════════════════════════════════

    public static function check_authenticated(WP_REST_Request $req) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        }
        return true;
    }

    public static function check_organizer(WP_REST_Request $req) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        }

        $user = wp_get_current_user();

        // Admins haben immer Zugriff
        if ($user->has_cap('manage_options')) {
            return true;
        }

        // tix_organizer Rolle prüfen
        if (in_array('tix_organizer', (array) $user->roles, true)) {
            return true;
        }

        return new WP_Error('rest_forbidden', 'Keine Berechtigung. Veranstalter-Rolle erforderlich.', ['status' => 403]);
    }

    /**
     * Prüft ob der aktuelle User Zugriff auf ein bestimmtes Event hat.
     */
    private static function can_access_event($event_id) {
        $user = wp_get_current_user();

        // Admins sehen alles
        if ($user->has_cap('manage_options')) {
            return true;
        }

        // Organizer: nur eigene Events
        if (class_exists('TIX_Organizer_Dashboard')) {
            return TIX_Organizer_Dashboard::user_owns_event($user->ID, $event_id);
        }

        return false;
    }

    /**
     * Check-in-Passwort aus Header prüfen.
     */
    private static function verify_checkin_password($event_id, WP_REST_Request $req) {
        $stored_pw = get_post_meta($event_id, '_tix_checkin_password', true);
        if (!$stored_pw) return true; // Kein Passwort gesetzt

        $password = $req->get_header('X-Checkin-Password');
        if (!$password || $password !== $stored_pw) {
            return new WP_Error('checkin_unauthorized', 'Falsches Check-in-Passwort.', ['status' => 401]);
        }
        return true;
    }

    // ═══════════════════════════════════════════
    //  DISCOVERY
    // ═══════════════════════════════════════════

    public static function get_info(WP_REST_Request $req) {
        $features = [];

        // Feature-Flags aus Settings
        if (function_exists('tix_get_settings')) {
            $s = tix_get_settings();
            $feature_keys = [
                'pos_enabled', 'promoter_enabled', 'organizer_dashboard_enabled',
                'ticket_db_enabled', 'waitlist_enabled', 'feedback_enabled',
                'raffle_enabled', 'seatmap_enabled', 'group_booking_enabled',
            ];
            foreach ($feature_keys as $key) {
                if (!empty($s[$key])) {
                    $features[] = str_replace('_enabled', '', $key);
                }
            }
        }

        return rest_ensure_response([
            'ok'             => true,
            'name'           => get_bloginfo('name'),
            'url'            => home_url(),
            'plugin_version' => defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : 'unknown',
            'api_version'    => '1.0.0',
            'features'       => $features,
        ]);
    }

    // ═══════════════════════════════════════════
    //  AUTH
    // ═══════════════════════════════════════════

    public static function get_me(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $organizer_id = null;

        if (class_exists('TIX_Organizer_Dashboard')) {
            $org = TIX_Organizer_Dashboard::get_organizer_by_user($user->ID);
            if ($org) {
                $organizer_id = $org->ID;
            }
        }

        return rest_ensure_response([
            'ok'   => true,
            'user' => [
                'id'           => $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'role'         => implode(',', (array) $user->roles),
                'organizer_id' => $organizer_id,
                'avatar'       => get_avatar_url($user->ID, ['size' => 96]),
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    //  EVENTS
    // ═══════════════════════════════════════════

    public static function get_events(WP_REST_Request $req) {
        $user   = wp_get_current_user();
        $filter = sanitize_text_field($req->get_param('filter') ?: 'upcoming');
        $search = sanitize_text_field($req->get_param('search') ?: '');

        $args = [
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 100,
            'orderby'        => 'meta_value',
            'meta_key'       => '_tix_date_start',
            'order'          => 'ASC',
        ];

        if ($search) {
            $args['s'] = $search;
        }

        $today = current_time('Y-m-d');

        if ($filter === 'today') {
            $args['meta_query'] = [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '='],
            ];
        } elseif ($filter === 'upcoming') {
            $args['meta_query'] = [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
            ];
        } elseif ($filter === 'past') {
            $args['meta_query'] = [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '<', 'type' => 'DATE'],
            ];
            $args['order'] = 'DESC';
        }

        // Organizer-Scoping: nur eigene Events (nicht für Admins)
        if (!$user->has_cap('manage_options') && class_exists('TIX_Organizer_Dashboard')) {
            $org = TIX_Organizer_Dashboard::get_organizer_by_user($user->ID);
            if ($org) {
                $args['meta_query']   = $args['meta_query'] ?? [];
                $args['meta_query'][] = [
                    'key'   => '_tix_organizer_id',
                    'value' => strval($org->ID),
                ];
            } else {
                return rest_ensure_response(['ok' => true, 'count' => 0, 'events' => []]);
            }
        }

        $posts  = get_posts($args);
        $events = [];

        foreach ($posts as $p) {
            $events[] = self::format_event($p);
        }

        return rest_ensure_response([
            'ok'     => true,
            'count'  => count($events),
            'events' => $events,
        ]);
    }

    public static function get_event(WP_REST_Request $req) {
        $id   = absint($req['id']);
        $post = get_post($id);

        if (!$post || $post->post_type !== 'event') {
            return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        }

        if (!self::can_access_event($id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }

        return rest_ensure_response([
            'ok'    => true,
            'event' => self::format_event($post, true),
        ]);
    }

    // ═══════════════════════════════════════════
    //  EVENT: CREATE
    // ═══════════════════════════════════════════

    public static function create_event(WP_REST_Request $req) {
        $body = $req->get_json_params();

        $title    = sanitize_text_field($body['title'] ?? '');
        $excerpt  = wp_kses_post($body['excerpt'] ?? '');
        $location = sanitize_text_field($body['location'] ?? '');

        if (empty($title)) {
            return new WP_Error('missing_title', 'Titel ist erforderlich.', ['status' => 400]);
        }

        // Organizer-ID ermitteln
        $user_id = get_current_user_id();
        $organizer_id = 0;
        $organizers = get_posts([
            'post_type'      => 'tix_organizer',
            'posts_per_page' => 1,
            'meta_query'     => [['key' => '_tix_org_user_id', 'value' => $user_id]],
            'fields'         => 'ids',
        ]);
        if (!empty($organizers)) $organizer_id = $organizers[0];

        // Auto-Publish Setting
        $auto_publish = get_option('tix_organizer_auto_publish', '0') === '1';

        $post_id = wp_insert_post([
            'post_type'    => 'event',
            'post_title'   => $title,
            'post_excerpt' => $excerpt,
            'post_status'  => $auto_publish ? 'publish' : 'draft',
            'post_author'  => $user_id,
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Meta-Daten
        if (!empty($body['date_start']))  update_post_meta($post_id, '_tix_date_start', sanitize_text_field($body['date_start']));
        if (!empty($body['date_end']))    update_post_meta($post_id, '_tix_date_end', sanitize_text_field($body['date_end']));
        if (!empty($body['time_start']))  update_post_meta($post_id, '_tix_time_start', sanitize_text_field($body['time_start']));
        if (!empty($body['time_end']))    update_post_meta($post_id, '_tix_time_end', sanitize_text_field($body['time_end']));
        if (!empty($body['time_doors']))  update_post_meta($post_id, '_tix_time_doors', sanitize_text_field($body['time_doors']));
        if ($location)                    update_post_meta($post_id, '_tix_location', $location);
        if (!empty($body['status']))      update_post_meta($post_id, '_tix_status', sanitize_text_field($body['status']));
        if ($organizer_id)                update_post_meta($post_id, '_tix_organizer_id', $organizer_id);

        // Ticket-Kategorien
        if (!empty($body['categories']) && is_array($body['categories'])) {
            $categories = [];
            foreach ($body['categories'] as $cat) {
                $categories[] = [
                    'name'     => sanitize_text_field($cat['name'] ?? ''),
                    'price'    => floatval($cat['price'] ?? 0),
                    'quantity' => absint($cat['quantity'] ?? 0),
                ];
            }
            update_post_meta($post_id, '_tix_ticket_categories', $categories);

            // WooCommerce-Sync triggern
            if (class_exists('TIX_Sync')) {
                TIX_Sync::sync_event($post_id);
            }
        }

        $post = get_post($post_id);
        return rest_ensure_response([
            'ok'    => true,
            'event' => self::format_event($post, true),
        ]);
    }

    // ═══════════════════════════════════════════
    //  EVENT: STATISTICS
    // ═══════════════════════════════════════════

    public static function event_statistics(WP_REST_Request $req) {
        $id = absint($req['id']);

        if (!self::can_access_event($id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        // Umsatz + Bestellungen via WooCommerce
        $total_revenue = 0;
        $total_orders  = 0;
        $total_tickets = 0;
        $by_category   = [];
        $by_day        = [];

        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'limit'   => -1,
                'status'  => ['completed', 'processing'],
                'meta_query' => [
                    ['key' => '_tix_event_id', 'value' => $id],
                ],
            ]);

            // Fallback: auch per Line-Item Meta suchen
            if (empty($orders)) {
                global $wpdb;
                $order_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
                     JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
                     WHERE oim.meta_key = '_tix_event_id' AND oim.meta_value = %d",
                    $id
                ));
                foreach ($order_ids as $oid) {
                    $order = wc_get_order($oid);
                    if ($order && in_array($order->get_status(), ['completed', 'processing'])) {
                        $orders[] = $order;
                    }
                }
            }

            foreach ($orders as $order) {
                $total_revenue += (float) $order->get_total();
                $total_orders++;

                $day = $order->get_date_created() ? $order->get_date_created()->format('Y-m-d') : '';
                if ($day) {
                    $by_day[$day] = ($by_day[$day] ?? 0) + (float) $order->get_total();
                }

                foreach ($order->get_items() as $item) {
                    $qty  = $item->get_quantity();
                    $cat  = $item->get_name();
                    $total_tickets += $qty;
                    if (!isset($by_category[$cat])) {
                        $by_category[$cat] = ['revenue' => 0, 'tickets' => 0];
                    }
                    $by_category[$cat]['revenue'] += (float) $item->get_total();
                    $by_category[$cat]['tickets'] += $qty;
                }
            }
        }

        // Check-in Rate
        $checkin_rate = 0;
        $checkin_total = 0;
        $checkin_done  = 0;

        // Gästeliste
        $guests = get_post_meta($id, '_tix_guest_list', true);
        if (is_array($guests)) {
            foreach ($guests as $g) {
                $expected = 1 + intval($g['plus'] ?? 0);
                $checkin_total += $expected;
                $checkin_done  += min(intval($g['checked_in_count'] ?? (empty($g['checked_in']) ? 0 : $expected)), $expected);
            }
        }

        // Tickets aus DB
        if (class_exists('TIX_Ticket_DB')) {
            $tickets = TIX_Ticket_DB::get_by_event($id);
            foreach ($tickets as $t) {
                $checkin_total++;
                if (!empty($t['checked_in'])) $checkin_done++;
            }
        }

        $checkin_rate = $checkin_total > 0 ? round($checkin_done / $checkin_total * 100, 1) : 0;

        // Letzte 30 Tage
        ksort($by_day);
        $by_day_last30 = array_slice($by_day, -30, null, true);

        return rest_ensure_response([
            'ok'         => true,
            'statistics' => [
                'total_revenue'  => $total_revenue,
                'total_tickets'  => $total_tickets,
                'total_orders'   => $total_orders,
                'by_category'    => $by_category,
                'by_day'         => $by_day_last30,
                'checkin_total'  => $checkin_total,
                'checkin_done'   => $checkin_done,
                'checkin_rate'   => $checkin_rate,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    //  EVENT: ORDERS
    // ═══════════════════════════════════════════

    public static function event_orders(WP_REST_Request $req) {
        $id = absint($req['id']);

        if (!self::can_access_event($id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        $orders_out = [];

        if (function_exists('wc_get_orders')) {
            global $wpdb;
            $order_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
                 JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
                 WHERE oim.meta_key = '_tix_event_id' AND oim.meta_value = %d
                 ORDER BY order_id DESC",
                $id
            ));

            foreach (array_slice($order_ids, 0, 200) as $oid) {
                $order = wc_get_order($oid);
                if (!$order) continue;

                $items = [];
                foreach ($order->get_items() as $item) {
                    $items[] = [
                        'name'     => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'total'    => (float) $item->get_total(),
                    ];
                }

                $orders_out[] = [
                    'id'       => $order->get_id(),
                    'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email'    => $order->get_billing_email(),
                    'items'    => $items,
                    'total'    => (float) $order->get_total(),
                    'payment'  => $order->get_payment_method_title(),
                    'status'   => $order->get_status(),
                    'date'     => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i') : '',
                ];
            }
        }

        return rest_ensure_response([
            'ok'     => true,
            'orders' => $orders_out,
            'count'  => count($orders_out),
        ]);
    }

    // ═══════════════════════════════════════════
    //  CHECK-IN: SCAN
    // ═══════════════════════════════════════════

    public static function checkin_scan(WP_REST_Request $req) {
        $body     = $req->get_json_params();
        $code     = strtoupper(sanitize_text_field($body['code'] ?? ''));
        $event_id = absint($body['event_id'] ?? 0);

        if (!$code) {
            return new WP_Error('invalid_code', 'Kein Code angegeben.', ['status' => 400]);
        }

        // ── 1. Direkter Ticket-Code (12-char oder TIX-XXXXXX) ──
        if (preg_match('/^(TIX-[A-Z2-9]{6}|[A-Z0-9]{12})$/', $code)) {
            return self::validate_and_checkin_ticket($code, $event_id, $req);
        }

        // ── 2. GL-Format: GL-{EVENT_ID}-{CODE} ──
        if (preg_match('/^GL-(\d+)-([A-Z0-9-]{3,20})$/', $code, $m)) {
            $gl_event_id = intval($m[1]);
            $inner_code  = $m[2];

            // Event-ID aus GL-Code verwenden falls nicht gesetzt
            if (!$event_id) $event_id = $gl_event_id;

            // Erst als Ticket-Code versuchen
            if (class_exists('TIX_Tickets')) {
                $ticket = TIX_Tickets::get_ticket_by_code($inner_code);
                if ($ticket) {
                    return self::validate_and_checkin_ticket($inner_code, $event_id, $req);
                }
            }

            // Als Gast-Code verarbeiten
            return self::validate_and_checkin_guest($gl_event_id, $inner_code, $req);
        }

        return new WP_Error('invalid_format', 'Ungültiges Code-Format.', ['status' => 400]);
    }

    /**
     * Ticket-Code validieren und einchecken.
     */
    private static function validate_and_checkin_ticket($code, $event_id, WP_REST_Request $req) {
        if (!class_exists('TIX_Tickets')) {
            return new WP_Error('no_ticket_system', 'Ticketsystem nicht aktiv.', ['status' => 500]);
        }

        $ticket = TIX_Tickets::get_ticket_by_code($code);
        if (!$ticket) {
            return rest_ensure_response([
                'ok'      => true,
                'status'  => 'not_found',
                'message' => 'Ticket nicht gefunden.',
            ]);
        }

        $ticket_event_id = intval(get_post_meta($ticket->ID, '_tix_ticket_event_id', true));

        // Event-Zugriff prüfen
        if ($ticket_event_id && !self::can_access_event($ticket_event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }

        // Check-in-Passwort prüfen
        if ($ticket_event_id) {
            $pw_check = self::verify_checkin_password($ticket_event_id, $req);
            if (is_wp_error($pw_check)) return $pw_check;
        }

        $status = get_post_meta($ticket->ID, '_tix_ticket_status', true) ?: 'valid';
        $name   = get_post_meta($ticket->ID, '_tix_ticket_owner_name', true);

        if ($status === 'cancelled') {
            return rest_ensure_response([
                'ok'      => true,
                'status'  => 'cancelled',
                'name'    => $name,
                'type'    => 'ticket',
                'code'    => $code,
                'message' => 'Ticket storniert.',
            ]);
        }

        $checked_in = (bool) get_post_meta($ticket->ID, '_tix_ticket_checked_in', true);

        if ($checked_in) {
            return rest_ensure_response([
                'ok'              => true,
                'status'          => 'already',
                'name'            => $name,
                'time'            => get_post_meta($ticket->ID, '_tix_ticket_checkin_time', true),
                'checked_in_count' => 1,
                'total_expected'  => 1,
                'type'            => 'ticket',
                'code'            => $code,
                'message'         => 'Bereits eingecheckt.',
            ]);
        }

        // Einchecken
        $by = wp_get_current_user()->user_login;
        TIX_Tickets::checkin_ticket($ticket->ID, $by);

        // Custom DB aktualisieren
        try {
            if (class_exists('TIX_Ticket_DB') && class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled')) {
                TIX_Ticket_DB::update_ticket($code, [
                    'checked_in'      => 1,
                    'checkin_time'    => current_time('mysql'),
                    'ticket_status'   => 'used',
                    'synced_supabase' => 0,
                    'synced_airtable' => 0,
                ]);
            }
        } catch (\Throwable $e) {
            // Custom-DB Fehler darf Check-in nicht blockieren
        }

        // Kategorie-Name
        $cat_index = intval(get_post_meta($ticket->ID, '_tix_ticket_cat_index', true));
        $cats      = get_post_meta($ticket_event_id, '_tix_ticket_categories', true);
        $cat_name  = (is_array($cats) && isset($cats[$cat_index])) ? ($cats[$cat_index]['name'] ?? '') : '';

        return rest_ensure_response([
            'ok'              => true,
            'status'          => 'ok',
            'name'            => $name,
            'checked_in_count' => 1,
            'total_expected'  => 1,
            'type'            => 'ticket',
            'code'            => $code,
            'category'        => $cat_name,
            'seat'            => get_post_meta($ticket->ID, '_tix_ticket_seat_id', true),
            'message'         => 'Willkommen!',
        ]);
    }

    /**
     * Gast-Code validieren und einchecken.
     */
    private static function validate_and_checkin_guest($event_id, $guest_id, WP_REST_Request $req) {
        if (!$event_id) {
            return new WP_Error('missing_event', 'Event-ID fehlt.', ['status' => 400]);
        }

        // Event-Zugriff prüfen
        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }

        // Check-in-Passwort prüfen
        $pw_check = self::verify_checkin_password($event_id, $req);
        if (is_wp_error($pw_check)) return $pw_check;

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) {
            return rest_ensure_response([
                'ok'      => true,
                'status'  => 'not_found',
                'message' => 'Keine Gästeliste.',
            ]);
        }

        foreach ($guests as &$g) {
            if (($g['id'] ?? '') !== $guest_id) continue;

            $total_expected = 1 + intval($g['plus'] ?? 0);

            // Backward-Compat
            if (!empty($g['checked_in']) && !isset($g['checked_in_count'])) {
                $g['checked_in_count'] = $total_expected;
            }

            $current_count = intval($g['checked_in_count'] ?? 0);

            // Vollständig eingecheckt?
            if ($current_count >= $total_expected) {
                return rest_ensure_response([
                    'ok'              => true,
                    'status'          => 'already',
                    'name'            => $g['name'],
                    'plus'            => intval($g['plus'] ?? 0),
                    'note'            => $g['note'] ?? '',
                    'time'            => $g['checkin_time'] ?? '',
                    'checked_in_count' => $current_count,
                    'total_expected'  => $total_expected,
                    'type'            => 'guest',
                    'message'         => 'Bereits eingecheckt.',
                ]);
            }

            // Teilweise eingecheckt?
            if ($current_count > 0 && $current_count < $total_expected) {
                return rest_ensure_response([
                    'ok'              => true,
                    'status'          => 'partial',
                    'name'            => $g['name'],
                    'plus'            => intval($g['plus'] ?? 0),
                    'note'            => $g['note'] ?? '',
                    'time'            => $g['checkin_time'] ?? '',
                    'checked_in_count' => $current_count,
                    'total_expected'  => $total_expected,
                    'type'            => 'guest',
                    'message'         => 'Teilweise eingecheckt (' . $current_count . '/' . $total_expected . ').',
                ]);
            }

            // Neu einchecken → vollständig
            $g['checked_in']       = true;
            $g['checked_in_count'] = $total_expected;
            $g['checkin_time']     = current_time('c');
            $g['checkin_by']       = wp_get_current_user()->user_login;

            update_post_meta($event_id, '_tix_guest_list', $guests);

            return rest_ensure_response([
                'ok'              => true,
                'status'          => 'ok',
                'name'            => $g['name'],
                'plus'            => intval($g['plus'] ?? 0),
                'note'            => $g['note'] ?? '',
                'checked_in_count' => $total_expected,
                'total_expected'  => $total_expected,
                'type'            => 'guest',
                'message'         => 'Willkommen!',
            ]);
        }
        unset($g);

        return rest_ensure_response([
            'ok'      => true,
            'status'  => 'not_found',
            'message' => 'Nicht auf der Liste.',
        ]);
    }

    // ═══════════════════════════════════════════
    //  CHECK-IN: KOMBINIERTE LISTE
    // ═══════════════════════════════════════════

    public static function checkin_list(WP_REST_Request $req) {
        $event_id = absint($req['event_id']);

        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        $pw_check = self::verify_checkin_password($event_id, $req);
        if (is_wp_error($pw_check)) return $pw_check;

        $combined = [];
        $stats    = ['total' => 0, 'checked_in' => 0, 'guests' => 0, 'tickets' => 0, 'partial' => 0];

        // ── Gäste ──
        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (is_array($guests)) {
            foreach ($guests as $g) {
                $total_expected = 1 + intval($g['plus'] ?? 0);
                if (!empty($g['checked_in']) && !isset($g['checked_in_count'])) {
                    $checked_in_count = $total_expected;
                } else {
                    $checked_in_count = intval($g['checked_in_count'] ?? 0);
                }

                $is_checked = !empty($g['checked_in']);
                $is_partial = $checked_in_count > 0 && $checked_in_count < $total_expected;

                $combined[] = [
                    'id'              => $g['id'] ?? '',
                    'type'            => 'guest',
                    'name'            => $g['name'] ?? '',
                    'email'           => $g['email'] ?? '',
                    'plus'            => intval($g['plus'] ?? 0),
                    'note'            => $g['note'] ?? '',
                    'checked_in'      => $is_checked,
                    'checkin_time'    => $g['checkin_time'] ?? '',
                    'checked_in_count' => $checked_in_count,
                    'total_expected'  => $total_expected,
                    'code'            => 'GL-' . $event_id . '-' . ($g['id'] ?? ''),
                ];

                $stats['total']++;
                $stats['guests']++;
                if ($is_checked) $stats['checked_in']++;
                if ($is_partial) $stats['partial']++;
            }
        }

        // ── Gekaufte Tickets ──
        if (class_exists('TIX_Tickets')) {
            $tickets = TIX_Tickets::get_tickets_by_event($event_id);
            foreach ($tickets as $t) {
                $status = get_post_meta($t->ID, '_tix_ticket_status', true) ?: 'valid';
                if ($status === 'cancelled') continue;

                $checked_in = (bool) get_post_meta($t->ID, '_tix_ticket_checked_in', true);
                $code       = get_post_meta($t->ID, '_tix_ticket_code', true);

                $cat_index = intval(get_post_meta($t->ID, '_tix_ticket_cat_index', true));
                $cats      = get_post_meta($event_id, '_tix_ticket_categories', true);
                $cat_name  = (is_array($cats) && isset($cats[$cat_index])) ? ($cats[$cat_index]['name'] ?? '') : '';

                $combined[] = [
                    'id'              => strval($t->ID),
                    'type'            => 'ticket',
                    'name'            => get_post_meta($t->ID, '_tix_ticket_owner_name', true),
                    'email'           => get_post_meta($t->ID, '_tix_ticket_owner_email', true),
                    'plus'            => 0,
                    'note'            => '',
                    'checked_in'      => $checked_in,
                    'checkin_time'    => get_post_meta($t->ID, '_tix_ticket_checkin_time', true),
                    'checked_in_count' => $checked_in ? 1 : 0,
                    'total_expected'  => 1,
                    'code'            => $code,
                    'category'        => $cat_name,
                    'seat'            => get_post_meta($t->ID, '_tix_ticket_seat_id', true),
                    'ticket_status'   => $status,
                ];

                $stats['total']++;
                $stats['tickets']++;
                if ($checked_in) $stats['checked_in']++;
            }
        }

        return rest_ensure_response([
            'ok'            => true,
            'total'         => $stats['total'],
            'checked_in'    => $stats['checked_in'],
            'partial'       => $stats['partial'],
            'open'          => $stats['total'] - $stats['checked_in'],
            'guests_count'  => $stats['guests'],
            'tickets_count' => $stats['tickets'],
            'items'         => $combined,
        ]);
    }

    // ═══════════════════════════════════════════
    //  CHECK-IN: GAST-ZÄHLER ANPASSEN
    // ═══════════════════════════════════════════

    public static function checkin_update_guest(WP_REST_Request $req) {
        $event_id = absint($req['event_id']);
        $guest_id = sanitize_text_field($req['guest_id']);
        $body     = $req->get_json_params();
        $count    = intval($body['count'] ?? -1);

        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        $pw_check = self::verify_checkin_password($event_id, $req);
        if (is_wp_error($pw_check)) return $pw_check;

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) {
            return new WP_Error('no_guestlist', 'Keine Gästeliste.', ['status' => 404]);
        }

        foreach ($guests as &$g) {
            if (($g['id'] ?? '') !== $guest_id) continue;

            $total_expected = 1 + intval($g['plus'] ?? 0);

            if ($count < 0 || $count > $total_expected) {
                return new WP_Error('invalid_count', 'Ungültiger Wert (0-' . $total_expected . ').', ['status' => 400]);
            }

            $g['checked_in_count'] = $count;
            $g['checked_in']       = ($count > 0);

            if ($count === 0) {
                $g['checkin_time'] = '';
                $g['checkin_by']   = '';
            } elseif (empty($g['checkin_time'])) {
                $g['checkin_time'] = current_time('c');
                $g['checkin_by']   = wp_get_current_user()->user_login;
            }

            update_post_meta($event_id, '_tix_guest_list', $guests);

            return rest_ensure_response([
                'ok'              => true,
                'status'          => $count === 0 ? 'reset' : ($count >= $total_expected ? 'full' : 'partial'),
                'name'            => $g['name'],
                'checked_in_count' => $count,
                'total_expected'  => $total_expected,
                'message'         => $count === 0 ? 'Check-in zurückgesetzt.' : $count . '/' . $total_expected . ' eingecheckt.',
            ]);
        }
        unset($g);

        return new WP_Error('guest_not_found', 'Gast nicht gefunden.', ['status' => 404]);
    }

    // ═══════════════════════════════════════════
    //  CHECK-IN: TICKET TOGGLE
    // ═══════════════════════════════════════════

    public static function checkin_toggle_ticket(WP_REST_Request $req) {
        $ticket_id = absint($req['ticket_id']);

        if (!class_exists('TIX_Tickets')) {
            return new WP_Error('no_ticket_system', 'Ticketsystem nicht aktiv.', ['status' => 500]);
        }

        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_ticket') {
            return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        }

        $event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        $pw_check = self::verify_checkin_password($event_id, $req);
        if (is_wp_error($pw_check)) return $pw_check;

        $checked_in = TIX_Tickets::is_checked_in($ticket_id);
        $by         = wp_get_current_user()->user_login;

        if ($checked_in) {
            TIX_Tickets::reset_checkin($ticket_id);
            $new_status = false;
            $msg        = 'Check-in zurückgesetzt.';
        } else {
            TIX_Tickets::checkin_ticket($ticket_id, $by);
            $new_status = true;
            $msg        = 'Eingecheckt!';
        }

        // Custom DB aktualisieren
        try {
            $code = get_post_meta($ticket_id, '_tix_ticket_code', true);
            if ($code && class_exists('TIX_Ticket_DB') && class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled')) {
                TIX_Ticket_DB::update_ticket($code, [
                    'checked_in'      => $new_status ? 1 : 0,
                    'checkin_time'    => $new_status ? current_time('mysql') : null,
                    'ticket_status'   => $new_status ? 'used' : 'valid',
                    'synced_supabase' => 0,
                    'synced_airtable' => 0,
                ]);
            }
        } catch (\Throwable $e) {}

        return rest_ensure_response([
            'ok'         => true,
            'checked_in' => $new_status,
            'name'       => get_post_meta($ticket_id, '_tix_ticket_owner_name', true),
            'message'    => $msg,
        ]);
    }

    // ═══════════════════════════════════════════
    //  GÄSTELISTE
    // ═══════════════════════════════════════════

    public static function get_guestlist(WP_REST_Request $req) {
        $event_id = absint($req['id']);

        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) $guests = [];

        $list = [];
        foreach ($guests as $g) {
            $total_expected = 1 + intval($g['plus'] ?? 0);
            if (!empty($g['checked_in']) && !isset($g['checked_in_count'])) {
                $checked_in_count = $total_expected;
            } else {
                $checked_in_count = intval($g['checked_in_count'] ?? 0);
            }

            $list[] = [
                'id'              => $g['id'] ?? '',
                'name'            => $g['name'] ?? '',
                'email'           => $g['email'] ?? '',
                'plus'            => intval($g['plus'] ?? 0),
                'note'            => $g['note'] ?? '',
                'checked_in'      => !empty($g['checked_in']),
                'checkin_time'    => $g['checkin_time'] ?? '',
                'checked_in_count' => $checked_in_count,
                'total_expected'  => $total_expected,
                'code'            => 'GL-' . $event_id . '-' . ($g['id'] ?? ''),
            ];
        }

        return rest_ensure_response([
            'ok'     => true,
            'count'  => count($list),
            'guests' => $list,
        ]);
    }

    public static function save_guestlist(WP_REST_Request $req) {
        $event_id = absint($req['id']);

        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        $body   = $req->get_json_params();
        $guests = $body['guests'] ?? [];

        if (!is_array($guests)) {
            return new WP_Error('invalid_data', 'Ungültige Daten.', ['status' => 400]);
        }

        // Sanitize
        $clean = [];
        foreach ($guests as $g) {
            $clean[] = [
                'id'              => sanitize_text_field($g['id'] ?? wp_generate_uuid4()),
                'name'            => sanitize_text_field($g['name'] ?? ''),
                'email'           => sanitize_email($g['email'] ?? ''),
                'plus'            => absint($g['plus'] ?? 0),
                'note'            => sanitize_text_field($g['note'] ?? ''),
                'checked_in'      => !empty($g['checked_in']),
                'checked_in_count' => absint($g['checked_in_count'] ?? 0),
                'checkin_time'    => sanitize_text_field($g['checkin_time'] ?? ''),
                'checkin_by'      => sanitize_text_field($g['checkin_by'] ?? ''),
            ];
        }

        update_post_meta($event_id, '_tix_guest_list', $clean);
        update_post_meta($event_id, '_tix_guest_list_enabled', '1');

        return rest_ensure_response([
            'ok'      => true,
            'count'   => count($clean),
            'message' => 'Gästeliste gespeichert.',
        ]);
    }

    // ═══════════════════════════════════════════
    //  TICKETS
    // ═══════════════════════════════════════════

    public static function get_tickets(WP_REST_Request $req) {
        $event_id = absint($req['id']);

        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        if (!class_exists('TIX_Tickets')) {
            return rest_ensure_response(['ok' => true, 'count' => 0, 'tickets' => []]);
        }

        $ticket_posts = TIX_Tickets::get_tickets_by_event($event_id);
        $tickets      = [];

        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);

        foreach ($ticket_posts as $t) {
            $status    = get_post_meta($t->ID, '_tix_ticket_status', true) ?: 'valid';
            $cat_index = intval(get_post_meta($t->ID, '_tix_ticket_cat_index', true));
            $cat_name  = (is_array($cats) && isset($cats[$cat_index])) ? ($cats[$cat_index]['name'] ?? '') : '';

            $tickets[] = [
                'id'            => $t->ID,
                'code'          => get_post_meta($t->ID, '_tix_ticket_code', true),
                'name'          => get_post_meta($t->ID, '_tix_ticket_owner_name', true),
                'email'         => get_post_meta($t->ID, '_tix_ticket_owner_email', true),
                'category'      => $cat_name,
                'status'        => $status,
                'checked_in'    => (bool) get_post_meta($t->ID, '_tix_ticket_checked_in', true),
                'checkin_time'  => get_post_meta($t->ID, '_tix_ticket_checkin_time', true),
                'order_id'      => intval(get_post_meta($t->ID, '_tix_order_id', true)),
                'seat'          => get_post_meta($t->ID, '_tix_ticket_seat_id', true),
            ];
        }

        return rest_ensure_response([
            'ok'      => true,
            'count'   => count($tickets),
            'tickets' => $tickets,
        ]);
    }

    public static function resend_ticket_email(WP_REST_Request $req) {
        $ticket_id = absint($req['id']);

        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_ticket') {
            return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        }

        $event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        $order_id = intval(get_post_meta($ticket_id, '_tix_order_id', true));

        if (class_exists('TIX_Emails') && $order_id) {
            TIX_Emails::send_ticket_email($order_id);
        }

        return rest_ensure_response([
            'ok'      => true,
            'message' => 'E-Mail erneut gesendet.',
        ]);
    }

    // ═══════════════════════════════════════════
    //  POS: KATEGORIEN
    // ═══════════════════════════════════════════

    public static function pos_categories(WP_REST_Request $req) {
        $event_id = absint($req['id']);

        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats) || empty($cats)) {
            return new WP_Error('no_categories', 'Keine Ticket-Kategorien.', ['status' => 404]);
        }

        if (!function_exists('wc_get_product')) {
            return new WP_Error('no_wc', 'WooCommerce nicht aktiv.', ['status' => 500]);
        }

        $categories = [];
        foreach ($cats as $idx => $cat) {
            $pid = intval($cat['product_id'] ?? 0);
            if (!$pid) continue;

            $product = wc_get_product($pid);
            if (!$product) continue;

            $stock     = $product->get_stock_quantity();
            $total_qty = intval($cat['quantity'] ?? 0);

            if ($stock === null || $stock === '') {
                $available = -1;
                $sold      = 0;
            } else {
                $available = max(0, intval($stock));
                $sold      = max(0, $total_qty - intval($stock));
            }

            $categories[] = [
                'index'      => $idx,
                'product_id' => $pid,
                'name'       => $cat['name'] ?? 'Ticket',
                'price'      => floatval($product->get_price()),
                'stock'      => $available,
                'sold'       => $sold,
                'total'      => $total_qty,
            ];
        }

        return rest_ensure_response([
            'ok'          => true,
            'event_title' => get_the_title($event_id),
            'categories'  => $categories,
        ]);
    }

    // ═══════════════════════════════════════════
    //  POS: ORDER ERSTELLEN
    // ═══════════════════════════════════════════

    public static function pos_create_order(WP_REST_Request $req) {
        $body = $req->get_json_params();

        $event_id       = absint($body['event_id'] ?? 0);
        $items          = $body['items'] ?? [];
        $payment        = sanitize_key($body['payment'] ?? 'cash');
        $customer_name  = sanitize_text_field($body['customer_name'] ?? '');
        $customer_email = sanitize_email($body['customer_email'] ?? '');
        $coupon_code    = sanitize_text_field($body['coupon_code'] ?? '');

        if (!$event_id || empty($items)) {
            return new WP_Error('missing_data', 'Event oder Artikel fehlen.', ['status' => 400]);
        }

        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }

        if (!function_exists('wc_create_order')) {
            return new WP_Error('no_wc', 'WooCommerce nicht aktiv.', ['status' => 500]);
        }

        $valid_payments = ['cash', 'card', 'free'];
        if (!in_array($payment, $valid_payments, true)) $payment = 'cash';

        $payment_methods = [
            'cash' => 'Barzahlung (POS)',
            'card' => 'EC-Karte (POS)',
            'free' => 'Kostenlos (POS)',
        ];

        // Stock-Check
        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $qty = intval($item['qty'] ?? 0);
            if (!$pid || $qty <= 0) continue;

            $product = wc_get_product($pid);
            if (!$product) {
                return new WP_Error('product_not_found', 'Produkt #' . $pid . ' nicht gefunden.', ['status' => 400]);
            }
            $stock = $product->get_stock_quantity();
            if ($stock !== null && $stock !== '' && intval($stock) < $qty) {
                return new WP_Error('out_of_stock', $product->get_name() . ': Nur noch ' . $stock . ' verfügbar.', ['status' => 409]);
            }
        }

        // WC Order erstellen
        $order = wc_create_order();
        if (is_wp_error($order)) {
            return new WP_Error('order_failed', 'Order-Erstellung fehlgeschlagen.', ['status' => 500]);
        }

        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $qty = intval($item['qty'] ?? 0);
            if (!$pid || $qty <= 0) continue;

            $product = wc_get_product($pid);
            if (!$product) continue;

            $order->add_product($product, $qty);
        }

        // Billing
        $billing_name = $customer_name ?: 'POS Kunde';
        $name_parts   = explode(' ', $billing_name, 2);
        $order->set_billing_first_name($name_parts[0]);
        $order->set_billing_last_name($name_parts[1] ?? '');
        $order->set_billing_email($customer_email ?: get_option('admin_email'));

        $order->set_payment_method('tix_pos_' . $payment);
        $order->set_payment_method_title($payment_methods[$payment]);

        // POS Meta
        $staff_id   = get_current_user_id();
        $staff_user = wp_get_current_user();

        $order->update_meta_data('_tix_pos_order', 1);
        $order->update_meta_data('_tix_pos_payment_type', $payment);
        $order->update_meta_data('_tix_pos_staff_id', $staff_id);
        $order->update_meta_data('_tix_pos_staff_name', $staff_user->display_name);

        // Coupon
        if ($coupon_code) {
            $order->apply_coupon($coupon_code);
        }

        $order->calculate_totals();
        $order->set_status('completed', 'POS-Verkauf (App)');
        $order->save();

        $order_id = $order->get_id();

        // Tickets aus DB holen
        $tickets = self::get_order_tickets($order_id, $event_id);

        return rest_ensure_response([
            'ok'       => true,
            'order_id' => $order_id,
            'total'    => floatval($order->get_total()),
            'tickets'  => $tickets,
            'payment'  => $payment,
        ]);
    }

    // ═══════════════════════════════════════════
    //  POS: E-MAIL SENDEN
    // ═══════════════════════════════════════════

    public static function pos_send_email(WP_REST_Request $req) {
        $order_id = absint($req['id']);
        $body     = $req->get_json_params();
        $email    = sanitize_email($body['email'] ?? '');

        if (!$order_id || !$email) {
            return new WP_Error('missing_data', 'Order-ID oder E-Mail fehlt.', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
        }

        if ($email !== $order->get_billing_email()) {
            $order->set_billing_email($email);
            $order->save();
        }

        do_action('woocommerce_order_status_completed_notification', $order_id, $order);

        if (class_exists('TIX_Emails')) {
            TIX_Emails::send_ticket_email($order_id);
        }

        return rest_ensure_response([
            'ok'      => true,
            'message' => 'E-Mail gesendet an ' . $email,
        ]);
    }

    // ═══════════════════════════════════════════
    //  POS: STORNO
    // ═══════════════════════════════════════════

    public static function pos_void_order(WP_REST_Request $req) {
        $order_id = absint($req['id']);

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
        }

        if (!$order->get_meta('_tix_pos_order')) {
            return new WP_Error('not_pos', 'Keine POS-Bestellung.', ['status' => 400]);
        }

        $order->set_status('cancelled', 'POS-Storno (App)');
        $order->save();

        // Tickets stornieren
        global $wpdb;
        $table = $wpdb->prefix . 'tixomat_tickets';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $wpdb->update($table, ['ticket_status' => 'cancelled'], ['order_id' => $order_id]);
        }

        $ticket_posts = get_posts([
            'post_type'      => 'tix_ticket',
            'meta_query'     => [['key' => '_tix_order_id', 'value' => $order_id]],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        foreach ($ticket_posts as $tid) {
            update_post_meta($tid, '_tix_ticket_status', 'cancelled');
        }

        return rest_ensure_response([
            'ok'      => true,
            'message' => 'Bestellung storniert.',
        ]);
    }

    // ═══════════════════════════════════════════
    //  POS: TAGESBERICHT
    // ═══════════════════════════════════════════

    public static function pos_report(WP_REST_Request $req) {
        $date     = sanitize_text_field($req->get_param('date') ?: current_time('Y-m-d'));
        $event_id = absint($req->get_param('event_id'));

        global $wpdb;

        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $meta_table   = $wpdb->prefix . 'wc_orders_meta';
            $sql = "SELECT o.id FROM $orders_table o
                INNER JOIN $meta_table m ON o.id = m.order_id AND m.meta_key = '_tix_pos_order'
                WHERE o.status = 'wc-completed' AND DATE(o.date_created_gmt) = %s";
        } else {
            $sql = "SELECT p.ID as id FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_pos_order'
                WHERE p.post_type = 'shop_order' AND p.post_status = 'wc-completed' AND DATE(p.post_date) = %s";
        }

        $order_ids = $wpdb->get_col($wpdb->prepare($sql, $date));

        $report = [
            'date'          => $date,
            'total_revenue' => 0,
            'total_tickets' => 0,
            'total_orders'  => 0,
            'by_payment'    => ['cash' => 0, 'card' => 0, 'free' => 0],
            'by_category'   => [],
            'by_hour'       => [],
            'cancelled'     => 0,
        ];

        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;

            $total        = floatval($order->get_total());
            $payment_type = $order->get_meta('_tix_pos_payment_type') ?: 'cash';
            $hour         = date('H', strtotime($order->get_date_created()->format('Y-m-d H:i:s')));

            $report['total_revenue'] += $total;
            $report['total_orders']++;
            $report['by_payment'][$payment_type] = ($report['by_payment'][$payment_type] ?? 0) + $total;

            if (!isset($report['by_hour'][$hour])) {
                $report['by_hour'][$hour] = ['revenue' => 0, 'tickets' => 0];
            }

            foreach ($order->get_items() as $item) {
                $qty       = $item->get_quantity();
                $cat_name  = $item->get_name();
                $item_total = floatval($item->get_total());

                $report['total_tickets'] += $qty;
                $report['by_hour'][$hour]['revenue'] += $item_total;
                $report['by_hour'][$hour]['tickets'] += $qty;

                if (!isset($report['by_category'][$cat_name])) {
                    $report['by_category'][$cat_name] = ['tickets' => 0, 'revenue' => 0];
                }
                $report['by_category'][$cat_name]['tickets'] += $qty;
                $report['by_category'][$cat_name]['revenue'] += $item_total;
            }
        }

        // Stornierte zählen
        if ($hpos) {
            $cancel_sql = "SELECT COUNT(*) FROM $orders_table o
                INNER JOIN $meta_table m ON o.id = m.order_id AND m.meta_key = '_tix_pos_order'
                WHERE o.status = 'wc-cancelled' AND DATE(o.date_created_gmt) = %s";
        } else {
            $cancel_sql = "SELECT COUNT(*) FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_pos_order'
                WHERE p.post_type = 'shop_order' AND p.post_status = 'wc-cancelled' AND DATE(p.post_date) = %s";
        }
        $report['cancelled'] = intval($wpdb->get_var($wpdb->prepare($cancel_sql, $date)));

        ksort($report['by_hour']);

        return rest_ensure_response([
            'ok'     => true,
            'report' => $report,
        ]);
    }

    // ═══════════════════════════════════════════
    //  POS: TRANSAKTIONEN
    // ═══════════════════════════════════════════

    public static function pos_transactions(WP_REST_Request $req) {
        $date = sanitize_text_field($req->get_param('date') ?: current_time('Y-m-d'));

        global $wpdb;

        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $meta_table   = $wpdb->prefix . 'wc_orders_meta';
            $sql = "SELECT o.id FROM $orders_table o
                INNER JOIN $meta_table m ON o.id = m.order_id AND m.meta_key = '_tix_pos_order'
                WHERE o.status IN ('wc-completed', 'wc-cancelled')
                AND DATE(o.date_created_gmt) = %s ORDER BY o.date_created_gmt DESC";
        } else {
            $sql = "SELECT p.ID as id FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_pos_order'
                WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed', 'wc-cancelled')
                AND DATE(p.post_date) = %s ORDER BY p.post_date DESC";
        }

        $order_ids    = $wpdb->get_col($wpdb->prepare($sql, $date));
        $transactions = [];

        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;

            $items_list   = [];
            $ticket_count = 0;
            foreach ($order->get_items() as $item) {
                $items_list[] = $item->get_name() . ' x' . $item->get_quantity();
                $ticket_count += $item->get_quantity();
            }

            $transactions[] = [
                'order_id'      => intval($oid),
                'time'          => $order->get_date_created()->format('H:i'),
                'items'         => implode(', ', $items_list),
                'tickets'       => $ticket_count,
                'total'         => floatval($order->get_total()),
                'payment'       => $order->get_meta('_tix_pos_payment_type') ?: 'cash',
                'payment_label' => $order->get_payment_method_title(),
                'customer'      => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'email'         => $order->get_billing_email(),
                'status'        => $order->get_status(),
                'staff'         => $order->get_meta('_tix_pos_staff_name') ?: '',
            ];
        }

        return rest_ensure_response([
            'ok'           => true,
            'transactions' => $transactions,
        ]);
    }

    // ═══════════════════════════════════════════
    //  HILFSFUNKTIONEN
    // ═══════════════════════════════════════════

    /**
     * Event-Daten für API-Response formatieren.
     */
    private static function format_event($post, $detailed = false) {
        $id = $post->ID;

        $date_start = get_post_meta($id, '_tix_date_start', true);
        $date_end   = get_post_meta($id, '_tix_date_end', true);
        $time_start = get_post_meta($id, '_tix_time_start', true);
        $time_end   = get_post_meta($id, '_tix_time_end', true);
        $time_doors = get_post_meta($id, '_tix_time_doors', true);
        $location   = get_post_meta($id, '_tix_location', true);
        $status     = get_post_meta($id, '_tix_status', true) ?: 'available';

        // Location-Name aus CPT
        $location_name = '';
        $location_id   = intval(get_post_meta($id, '_tix_location_id', true));
        if ($location_id) {
            $loc_post = get_post($location_id);
            if ($loc_post) $location_name = $loc_post->post_title;
        }
        if (!$location_name && $location) $location_name = $location;

        // Thumbnail
        $thumb_id  = get_post_thumbnail_id($id);
        $thumbnail = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium') : '';

        // Ticket-Kategorien mit Stock
        $categories_raw = get_post_meta($id, '_tix_ticket_categories', true);
        $categories     = [];
        $total_capacity  = 0;
        $total_available = 0;
        $total_sold      = 0;

        if (is_array($categories_raw)) {
            foreach ($categories_raw as $i => $cat) {
                $product_id = absint($cat['product_id'] ?? 0);
                $qty_total  = absint($cat['quantity'] ?? $cat['qty'] ?? 0);
                $qty_sold   = 0;
                $qty_avail  = $qty_total ?: 999;
                $sold_out   = false;

                if ($product_id && function_exists('wc_get_product')) {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        if ($product->managing_stock()) {
                            $stock    = (int) $product->get_stock_quantity();
                            $qty_sold = $qty_total > 0 ? max(0, $qty_total - $stock) : 0;
                            $qty_avail = max(0, $stock);
                        } else {
                            $sold_out  = !$product->is_in_stock();
                            $qty_avail = $sold_out ? 0 : ($qty_total ?: 999);
                        }
                    }
                }

                $price = self::current_price($cat);

                $categories[] = [
                    'index'              => $i,
                    'name'               => $cat['name'] ?? '',
                    'price'              => $price,
                    'product_id'         => $product_id,
                    'quantity_total'     => $qty_total,
                    'quantity_sold'      => $qty_sold,
                    'quantity_available' => $qty_avail,
                    'sold_out'           => $sold_out || $qty_avail <= 0,
                ];

                $total_capacity  += $qty_total;
                $total_available += $qty_avail;
                $total_sold      += $qty_sold;
            }
        }

        // Check-in Stats
        $checkin_stats = null;
        if ($detailed) {
            $guests    = get_post_meta($id, '_tix_guest_list', true);
            $gl_total  = is_array($guests) ? count($guests) : 0;
            $gl_checked = 0;
            if (is_array($guests)) {
                foreach ($guests as $g) {
                    if (!empty($g['checked_in'])) $gl_checked++;
                }
            }

            $tk_total = 0;
            $tk_checked = 0;
            if (class_exists('TIX_Tickets')) {
                $tks = TIX_Tickets::get_tickets_by_event($id);
                foreach ($tks as $t) {
                    $s = get_post_meta($t->ID, '_tix_ticket_status', true) ?: 'valid';
                    if ($s === 'cancelled') continue;
                    $tk_total++;
                    if (get_post_meta($t->ID, '_tix_ticket_checked_in', true)) $tk_checked++;
                }
            }

            $checkin_stats = [
                'total'      => $gl_total + $tk_total,
                'checked_in' => $gl_checked + $tk_checked,
                'guests'     => $gl_total,
                'tickets'    => $tk_total,
            ];
        }

        $event = [
            'id'               => $id,
            'title'            => $post->post_title,
            'excerpt'          => wp_strip_all_tags($post->post_excerpt),
            'thumbnail'        => $thumbnail,
            'date_start'       => $date_start,
            'date_end'         => $date_end,
            'time_start'       => $time_start,
            'time_end'         => $time_end,
            'time_doors'       => $time_doors,
            'date_formatted'   => $date_start ? date_i18n('l, d. F Y', strtotime($date_start)) : '',
            'location'         => $location_name,
            'status'           => $status,
            'categories'       => $categories,
            'total_capacity'   => $total_capacity,
            'total_available'  => $total_available,
            'total_sold'       => $total_sold,
            'has_guest_list'   => (bool) get_post_meta($id, '_tix_guest_list_enabled', true),
            'has_checkin_password' => !empty(get_post_meta($id, '_tix_checkin_password', true)),
        ];

        if ($checkin_stats) {
            $event['checkin_stats'] = $checkin_stats;
        }

        return $event;
    }

    /**
     * Aktuellen Preis einer Kategorie bestimmen (Phasen / Early Bird).
     */
    private static function current_price($cat) {
        $now    = current_time('Y-m-d');
        $phases = $cat['phases'] ?? [];

        if (!empty($phases) && is_array($phases)) {
            foreach ($phases as $phase) {
                $until = $phase['until'] ?? '';
                if ($until && $now <= $until) {
                    return (float) ($phase['price'] ?? $cat['price'] ?? 0);
                }
            }
        }

        if (!empty($cat['sale_price']) && (float) $cat['sale_price'] > 0) {
            return (float) $cat['sale_price'];
        }

        return (float) ($cat['price'] ?? 0);
    }

    /**
     * Tickets einer Bestellung holen.
     */
    private static function get_order_tickets($order_id, $event_id = 0) {
        global $wpdb;
        $tickets = [];

        // Custom DB zuerst
        $table = $wpdb->prefix . 'tixomat_tickets';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $ticket_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ticket_code, event_name, ticket_category, ticket_price FROM $table WHERE order_id = %d AND ticket_status = 'valid'",
                $order_id
            ), ARRAY_A);

            foreach ($ticket_rows as $tr) {
                $tickets[] = [
                    'code'     => $tr['ticket_code'],
                    'event'    => $tr['event_name'],
                    'category' => $tr['ticket_category'],
                    'price'    => floatval($tr['ticket_price']),
                ];
            }
        }

        // Fallback: CPT
        if (empty($tickets)) {
            $ticket_posts = get_posts([
                'post_type'      => 'tix_ticket',
                'meta_query'     => [['key' => '_tix_order_id', 'value' => $order_id]],
                'posts_per_page' => -1,
            ]);
            foreach ($ticket_posts as $tp) {
                $tickets[] = [
                    'code'     => get_post_meta($tp->ID, '_tix_ticket_code', true),
                    'event'    => $event_id ? get_the_title($event_id) : '',
                    'category' => get_post_meta($tp->ID, '_tix_ticket_category', true) ?: 'Ticket',
                    'price'    => floatval(get_post_meta($tp->ID, '_tix_ticket_price', true)),
                ];
            }
        }

        return $tickets;
    }

    // ═══════════════════════════════════════════
    //  GUEST / CUSTOMER AUTH
    // ═══════════════════════════════════════════

    /**
     * POST /auth/login – Anmeldung mit E-Mail + Passwort.
     * Gibt User-Daten + Auth-Token zurück bei Erfolg.
     */
    public static function auth_login(WP_REST_Request $req) {
        $email    = sanitize_email($req->get_param('email'));
        $password = $req->get_param('password');

        if (empty($email) || empty($password)) {
            return new WP_Error('missing_fields', 'E-Mail und Passwort sind erforderlich.', ['status' => 400]);
        }

        // WordPress-Login mit E-Mail
        $user = get_user_by('email', $email);
        if (!$user) {
            // Fallback: Username
            $user = get_user_by('login', $email);
        }

        if (!$user) {
            return new WP_Error('invalid_credentials', 'Ungültige E-Mail oder Passwort.', ['status' => 401]);
        }

        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            return new WP_Error('invalid_credentials', 'Ungültige E-Mail oder Passwort.', ['status' => 401]);
        }

        // Token generieren (gespeichert als User-Meta, gültig 90 Tage)
        $token = wp_generate_password(64, false, false);
        $token_data = [
            'token'   => hash('sha256', $token),
            'expires' => time() + (90 * DAY_IN_SECONDS),
        ];
        update_user_meta($user->ID, '_tix_app_token', $token_data);

        return rest_ensure_response([
            'success' => true,
            'token'   => $token,
            'user'    => self::format_guest_user($user),
        ]);
    }

    /**
     * POST /auth/register – Neues Konto erstellen.
     * Erstellt einen WordPress-User mit Rolle "subscriber".
     */
    public static function auth_register(WP_REST_Request $req) {
        $first_name = sanitize_text_field($req->get_param('first_name'));
        $last_name  = sanitize_text_field($req->get_param('last_name'));
        $email      = sanitize_email($req->get_param('email'));
        $password   = $req->get_param('password');

        if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
            return new WP_Error('missing_fields', 'Alle Felder sind erforderlich.', ['status' => 400]);
        }

        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Ungültige E-Mail-Adresse.', ['status' => 400]);
        }

        if (strlen($password) < 8) {
            return new WP_Error('weak_password', 'Das Passwort muss mindestens 8 Zeichen lang sein.', ['status' => 400]);
        }

        if (email_exists($email)) {
            return new WP_Error('email_exists', 'Ein Konto mit dieser E-Mail-Adresse existiert bereits.', ['status' => 409]);
        }

        // Username aus E-Mail ableiten
        $username = sanitize_user(strtolower(explode('@', $email)[0]));
        $base_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }

        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim("$first_name $last_name"),
            'role'         => 'subscriber',
        ]);

        if (is_wp_error($user_id)) {
            return new WP_Error('registration_failed', $user_id->get_error_message(), ['status' => 500]);
        }

        $user = get_user_by('ID', $user_id);

        // Optional: Willkommens-E-Mail senden
        wp_new_user_notification($user_id, null, 'user');

        // Token generieren
        $token = wp_generate_password(64, false, false);
        $token_data = [
            'token'   => hash('sha256', $token),
            'expires' => time() + (90 * DAY_IN_SECONDS),
        ];
        update_user_meta($user_id, '_tix_app_token', $token_data);

        return rest_ensure_response([
            'success' => true,
            'token'   => $token,
            'user'    => self::format_guest_user($user),
        ]);
    }

    /**
     * GET|POST /auth/profile – Profil abrufen oder aktualisieren.
     */
    public static function auth_profile(WP_REST_Request $req) {
        $user = wp_get_current_user();

        if ($req->get_method() === 'POST') {
            $first_name = $req->get_param('first_name');
            $last_name  = $req->get_param('last_name');
            $phone      = $req->get_param('phone');

            $update_data = ['ID' => $user->ID];

            if ($first_name !== null) {
                $update_data['first_name'] = sanitize_text_field($first_name);
            }
            if ($last_name !== null) {
                $update_data['last_name'] = sanitize_text_field($last_name);
            }
            if ($first_name !== null || $last_name !== null) {
                $fn = $first_name !== null ? sanitize_text_field($first_name) : $user->first_name;
                $ln = $last_name !== null ? sanitize_text_field($last_name) : $user->last_name;
                $update_data['display_name'] = trim("$fn $ln");
            }

            $result = wp_update_user($update_data);
            if (is_wp_error($result)) {
                return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
            }

            if ($phone !== null) {
                update_user_meta($user->ID, '_tix_phone', sanitize_text_field($phone));
            }

            // Refresh user
            $user = get_user_by('ID', $user->ID);
        }

        return rest_ensure_response([
            'success' => true,
            'user'    => self::format_guest_user($user),
        ]);
    }

    /**
     * POST /auth/profile/avatar – Profilbild hochladen.
     */
    public static function auth_profile_avatar(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $files = $req->get_file_params();

        if (empty($files['avatar'])) {
            return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Upload
        $file = $files['avatar'];
        $upload = wp_handle_upload($file, ['test_form' => false]);

        if (isset($upload['error'])) {
            return new WP_Error('upload_failed', $upload['error'], ['status' => 500]);
        }

        // Alte Attachments aufräumen
        $old_avatar_id = get_user_meta($user->ID, '_tix_avatar_id', true);
        if ($old_avatar_id) {
            wp_delete_attachment($old_avatar_id, true);
        }

        // Attachment erstellen
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $upload['type'],
            'post_title'     => 'avatar-' . $user->ID,
            'post_status'    => 'private',
        ], $upload['file']);

        if (is_wp_error($attachment_id)) {
            return new WP_Error('attachment_failed', 'Bild konnte nicht gespeichert werden.', ['status' => 500]);
        }

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));

        // In User-Meta speichern
        update_user_meta($user->ID, '_tix_avatar_id', $attachment_id);
        update_user_meta($user->ID, '_tix_avatar_url', $upload['url']);

        $user = get_user_by('ID', $user->ID);

        return rest_ensure_response([
            'success' => true,
            'user'    => self::format_guest_user($user),
        ]);
    }

    /**
     * GET /customer/tickets – Tickets des eingeloggten Kunden.
     */
    public static function customer_tickets(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $tickets = [];

        // Aus Ticket-DB
        global $wpdb;
        $table = $wpdb->prefix . 'tix_tickets';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE buyer_email = %s ORDER BY id DESC",
                $user->user_email
            ), ARRAY_A);

            foreach ($rows as $row) {
                $event_id = intval($row['event_id'] ?? 0);
                $event = $event_id ? get_post($event_id) : null;

                $tickets[] = [
                    'id'          => intval($row['id']),
                    'code'        => $row['ticket_code'] ?? '',
                    'event_id'    => $event_id,
                    'event_title' => $event ? $event->post_title : ($row['event_name'] ?? ''),
                    'event_date'  => $event_id ? get_post_meta($event_id, '_tix_date_start', true) : '',
                    'event_image' => $event_id ? get_the_post_thumbnail_url($event_id, 'medium') : '',
                    'category'    => $row['category_name'] ?? '',
                    'seat'        => $row['seat_id'] ?? '',
                    'status'      => $row['ticket_status'] ?? 'valid',
                    'checked_in'  => !empty($row['checked_in']),
                    'checkin_time' => $row['checkin_time'] ?? '',
                    'order_id'    => intval($row['order_id'] ?? 0),
                    'price'       => floatval($row['ticket_price'] ?? 0),
                    'purchased'   => $row['created_at'] ?? '',
                ];
            }
        }

        // Fallback: CPT
        if (empty($tickets)) {
            $ticket_posts = get_posts([
                'post_type'      => 'tix_ticket',
                'posts_per_page' => -1,
                'meta_query'     => [
                    ['key' => '_tix_email', 'value' => $user->user_email],
                ],
            ]);

            foreach ($ticket_posts as $tp) {
                $event_id = intval(get_post_meta($tp->ID, '_tix_event_id', true));
                $event = $event_id ? get_post($event_id) : null;

                $tickets[] = [
                    'id'          => $tp->ID,
                    'code'        => get_post_meta($tp->ID, '_tix_ticket_code', true),
                    'event_id'    => $event_id,
                    'event_title' => $event ? $event->post_title : '',
                    'event_date'  => $event_id ? get_post_meta($event_id, '_tix_date_start', true) : '',
                    'event_image' => $event_id ? get_the_post_thumbnail_url($event_id, 'medium') : '',
                    'category'    => get_post_meta($tp->ID, '_tix_ticket_category', true) ?: 'Ticket',
                    'seat'        => get_post_meta($tp->ID, '_tix_seat', true),
                    'status'      => get_post_meta($tp->ID, '_tix_status', true) ?: 'valid',
                    'checked_in'  => (bool) get_post_meta($tp->ID, '_tix_checked_in', true),
                    'checkin_time' => get_post_meta($tp->ID, '_tix_checkin_time', true) ?: '',
                    'order_id'    => intval(get_post_meta($tp->ID, '_tix_order_id', true)),
                    'price'       => floatval(get_post_meta($tp->ID, '_tix_ticket_price', true)),
                    'purchased'   => $tp->post_date,
                ];
            }
        }

        // Sortierung: neueste zuerst
        usort($tickets, fn($a, $b) => strcmp($b['purchased'] ?? '', $a['purchased'] ?? ''));

        return rest_ensure_response([
            'success' => true,
            'tickets' => $tickets,
            'count'   => count($tickets),
        ]);
    }

    /**
     * GET /customer/events – Kommende Events des Kunden (basierend auf Tickets).
     */
    public static function customer_events(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $event_ids = [];

        // Aus Ticket-DB
        global $wpdb;
        $table = $wpdb->prefix . 'tix_tickets';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT event_id FROM {$table} WHERE buyer_email = %s",
                $user->user_email
            ), ARRAY_A);
            foreach ($rows as $row) {
                $eid = intval($row['event_id'] ?? 0);
                if ($eid) $event_ids[$eid] = true;
            }
        }

        // Fallback: CPT
        if (empty($event_ids)) {
            $ticket_posts = get_posts([
                'post_type'      => 'tix_ticket',
                'posts_per_page' => -1,
                'meta_query'     => [
                    ['key' => '_tix_email', 'value' => $user->user_email],
                ],
                'fields'         => 'ids',
            ]);
            foreach ($ticket_posts as $tp_id) {
                $eid = intval(get_post_meta($tp_id, '_tix_event_id', true));
                if ($eid) $event_ids[$eid] = true;
            }
        }

        $events = [];
        foreach (array_keys($event_ids) as $event_id) {
            $event = get_post($event_id);
            if (!$event || $event->post_status !== 'publish') continue;

            $date_start = get_post_meta($event_id, '_tix_date_start', true);

            // Nur kommende Events
            if ($date_start && strtotime($date_start) < strtotime('-1 day')) continue;

            $events[] = [
                'id'         => $event_id,
                'title'      => $event->post_title,
                'date_start' => $date_start,
                'time_start' => get_post_meta($event_id, '_tix_time_start', true),
                'time_doors' => get_post_meta($event_id, '_tix_time_doors', true),
                'location'   => get_post_meta($event_id, '_tix_location', true),
                'image'      => get_the_post_thumbnail_url($event_id, 'medium'),
            ];
        }

        // Sortierung: nächstes Event zuerst
        usort($events, fn($a, $b) => strcmp($a['date_start'] ?? '', $b['date_start'] ?? ''));

        return rest_ensure_response([
            'success' => true,
            'events'  => $events,
            'count'   => count($events),
        ]);
    }

    /**
     * Hilfsfunktion: Guest-User Daten formatieren.
     */
    private static function format_guest_user(WP_User $user) {
        $avatar_url = get_user_meta($user->ID, '_tix_avatar_url', true);
        if (empty($avatar_url)) {
            $avatar_url = get_avatar_url($user->ID, ['size' => 96]);
        }
        $avatar_large = get_user_meta($user->ID, '_tix_avatar_url', true);
        if (empty($avatar_large)) {
            $avatar_large = get_avatar_url($user->ID, ['size' => 300]);
        }

        // Ticket-Statistiken
        $tickets_count = 0;
        $upcoming_events = 0;

        global $wpdb;
        $table = $wpdb->prefix . 'tix_tickets';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT event_id FROM {$table} WHERE buyer_email = %s",
                $user->user_email
            ), ARRAY_A);
            $tickets_count = count($rows);

            $seen_events = [];
            foreach ($rows as $row) {
                $eid = intval($row['event_id'] ?? 0);
                if ($eid && !isset($seen_events[$eid])) {
                    $seen_events[$eid] = true;
                    $ds = get_post_meta($eid, '_tix_date_start', true);
                    if ($ds && strtotime($ds) >= strtotime('today')) {
                        $upcoming_events++;
                    }
                }
            }
        }

        return [
            'id'              => $user->ID,
            'display_name'    => $user->display_name,
            'first_name'      => $user->first_name,
            'last_name'       => $user->last_name,
            'email'           => $user->user_email,
            'phone'           => get_user_meta($user->ID, '_tix_phone', true) ?: '',
            'avatar'          => $avatar_url ?: '',
            'avatar_large'    => $avatar_large ?: '',
            'registered_date' => $user->user_registered,
            'tickets_count'   => $tickets_count,
            'upcoming_events' => $upcoming_events,
        ];
    }
}
