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
}
