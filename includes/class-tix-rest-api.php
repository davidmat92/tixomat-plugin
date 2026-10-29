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
        add_action('init', [__CLASS__, 'ensure_staff_role'], 5);
        // Token-Index (Suche per Hash statt Schleife über alle Konten): folgt jeder
        // Änderung der Token-Listen, egal über welchen Weg (Login, Logout, Passwort …)
        add_action('added_user_meta',   [__CLASS__, 'on_token_meta'], 10, 3);
        add_action('updated_user_meta', [__CLASS__, 'on_token_meta'], 10, 3);
        add_action('deleted_user_meta', [__CLASS__, 'on_token_meta'], 10, 3);
        add_action(self::TOKEN_REINDEX_HOOK, [__CLASS__, 'reindex_app_tokens']);
        add_action('init', [__CLASS__, 'maybe_schedule_token_reindex'], 20);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('tixomat app-tokens', [__CLASS__, 'cli_app_tokens']);
        }
    }

    // ── Token-Index (`_tix_app_token_h` = SHA-256 je gültigem Token, mehrfach je Konto) ──

    const META_TOKEN_H       = '_tix_app_token_h';
    const OPT_TOKEN_INDEX    = 'tix_app_token_index_v1';
    const TOKEN_REINDEX_HOOK = 'tix_app_token_reindex';

    /** Index eines Kontos aus `_tix_app_tokens` (+ Legacy `_tix_app_token`) neu schreiben. */
    public static function sync_token_index($user_id) {
        $user_id = intval($user_id);
        if ($user_id <= 0) return;
        $now = time();
        $hashes = [];
        $list = get_user_meta($user_id, '_tix_app_tokens', true);
        foreach (is_array($list) ? $list : [] as $t) {
            if (is_array($t) && !empty($t['token']) && intval($t['expires'] ?? 0) > $now) $hashes[(string) $t['token']] = true;
        }
        $legacy = get_user_meta($user_id, '_tix_app_token', true);
        if (is_array($legacy) && !empty($legacy['token']) && intval($legacy['expires'] ?? 0) > $now) $hashes[(string) $legacy['token']] = true;
        delete_user_meta($user_id, self::META_TOKEN_H);
        foreach (array_keys($hashes) as $h) add_user_meta($user_id, self::META_TOKEN_H, $h);
    }

    public static function on_token_meta($meta_id, $user_id, $meta_key) {
        if ($meta_key === '_tix_app_tokens' || $meta_key === '_tix_app_token') self::sync_token_index($user_id);
    }

    /** Einmalig alle Konten mit Tokens indizieren. Rückgabe: Anzahl Konten. */
    public static function reindex_app_tokens() {
        $users = get_users([
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_tix_app_tokens', 'compare' => 'EXISTS'],
                ['key' => '_tix_app_token',  'compare' => 'EXISTS'],
            ],
            'number' => 0,
            'fields' => 'ids',
        ]);
        foreach ($users as $uid) self::sync_token_index($uid);
        update_option(self::OPT_TOKEN_INDEX, '1', true);
        return count($users);
    }

    /** Nach dem Update den Altbestand einmal im Hintergrund indizieren (alternativ per WP-CLI). */
    public static function maybe_schedule_token_reindex() {
        if (get_option(self::OPT_TOKEN_INDEX) === '1') return;
        if (!wp_next_scheduled(self::TOKEN_REINDEX_HOOK)) wp_schedule_single_event(time() + 30, self::TOKEN_REINDEX_HOOK);
    }

    /**
     * Token-Index der App-Anmeldung pflegen.
     *
     * ## OPTIONS
     *
     * <action>
     * : `reindex` (alle Konten neu indizieren) oder `status`.
     *
     * ## EXAMPLES
     *
     *     wp tixomat app-tokens reindex
     */
    public static function cli_app_tokens($args, $assoc) {
        $action = $args[0] ?? 'status';
        if ($action === 'reindex') {
            $n = self::reindex_app_tokens();
            WP_CLI::success("{$n} Konten indiziert.");
            return;
        }
        global $wpdb;
        $rows = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META_TOKEN_H));
        WP_CLI::log('Index ' . (get_option(self::OPT_TOKEN_INDEX) === '1' ? 'fertig' : 'noch nicht aufgebaut') . ", {$rows} Einträge.");
    }

    /**
     * Rolle „Mitarbeiter (App)“: Zugang zum Veranstalter-Bereich der App
     * (Check-in, Kasse, Gästeliste, Musikwünsche) für alle Events – ohne
     * WordPress-Admin und ohne Veranstalter-Verknüpfung.
     */
    public static function ensure_staff_role() {
        if (!get_role('tix_staff')) {
            add_role('tix_staff', 'Mitarbeiter (App)', ['read' => true, 'tix_app_staff' => true]);
        }
        // Rolle „DJ (App)“: sieht nur die Musikwunsch-Liste (kein Veranstalter-Bereich).
        if (!get_role('tix_dj')) {
            add_role('tix_dj', 'DJ (App)', ['read' => true, 'tix_app_dj' => true]);
        }
        // Rolle „Eingang (App)“: darf nur einchecken/scannen und die Namensliste
        // sehen (kein Zugriff auf Adressen, Bestellungen, Statistik, Kasse, Editor).
        // Rolle „Awareness (App)“: nur Awareness-Routen (Dienst, Hinweise) für die Events des eigenen Veranstalters
        if (!get_role('tix_awareness')) {
            add_role('tix_awareness', 'Awareness (App)', ['read' => true, 'tix_app_awareness' => true]);
        }
        if (!get_role('tix_entrance')) {
            add_role('tix_entrance', 'Eingang (App)', ['read' => true, 'tix_app_entrance' => true]);
        }
        // Rolle „Kasse & Bar (App)“ (1.38.441): nur Abendkasse (/pos/*) und Guthaben abbuchen/aufladen
        if (!get_role('tix_cashier')) {
            add_role('tix_cashier', 'Kasse & Bar (App)', ['read' => true, 'tix_app_pos' => true, 'tix_app_wallet_charge' => true]);
        }
    }

    /** Admin, Mitarbeiter (App) oder Veranstalter-Rolle? */
    public static function is_staff_user($user) {
        if (!$user || !$user->ID) return false;
        if ($user->has_cap('manage_options')) return true;
        $roles = (array) $user->roles;
        return in_array('tix_staff', $roles, true) || in_array('tix_organizer', $roles, true);
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

        // Zuerst per Index (eine Abfrage); Prüfung wie bisher über die Token-Liste des Kontos
        $hit = get_users(['meta_key' => self::META_TOKEN_H, 'meta_value' => $hashed, 'number' => 1, 'fields' => 'ids']);
        if ($hit) {
            $found = self::match_token_for_user(intval($hit[0]), $hashed);
            if ($found !== null) return $found ?: $user_id;
        }
        // Index fertig: nur noch Legacy-Einzeltokens durchsuchen (werden mit der Zeit weniger)
        $legacy_only = get_option(self::OPT_TOKEN_INDEX) === '1';

        // Mehrere Geräte pro Konto: Liste in _tix_app_tokens (seit 1.38.288);
        // Legacy-Einzeltoken in _tix_app_token bleibt gültig, bis es ausläuft.
        $users = $legacy_only ? get_users([
            'meta_key' => '_tix_app_token',
            'meta_compare' => 'EXISTS',
            'number' => 0,
            'fields' => 'ids',
        ]) : get_users([
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_tix_app_tokens', 'compare' => 'EXISTS'],
                ['key' => '_tix_app_token',  'compare' => 'EXISTS'],
            ],
            'number' => 0,
            'fields' => 'ids',
        ]);

        foreach ($users as $uid) {
            $list = get_user_meta($uid, '_tix_app_tokens', true);
            if (is_array($list)) {
                foreach ($list as $data) {
                    if (!is_array($data) || !isset($data['token'], $data['expires'])) continue;
                    if (hash_equals((string) $data['token'], $hashed)) {
                        if (intval($data['expires']) > time()) return $uid;
                        self::prune_app_tokens($uid);
                        return $user_id;
                    }
                }
            }
            $data = get_user_meta($uid, '_tix_app_token', true);
            if (is_array($data) && isset($data['token'], $data['expires'])
                && hash_equals((string) $data['token'], $hashed)) {
                if (intval($data['expires']) > time()) return $uid;
                delete_user_meta($uid, '_tix_app_token');
                return $user_id;
            }
        }

        return $user_id;
    }

    /**
     * Token im Konto prüfen: Konto-ID (gültig), 0 (gefunden, aber abgelaufen – aufgeräumt)
     * oder null (nicht in diesem Konto).
     */
    private static function match_token_for_user($uid, $hashed) {
        $list = get_user_meta($uid, '_tix_app_tokens', true);
        if (is_array($list)) {
            foreach ($list as $data) {
                if (!is_array($data) || !isset($data['token'], $data['expires'])) continue;
                if (hash_equals((string) $data['token'], $hashed)) {
                    if (intval($data['expires']) > time()) return $uid;
                    self::prune_app_tokens($uid);
                    return 0;
                }
            }
        }
        $data = get_user_meta($uid, '_tix_app_token', true);
        if (is_array($data) && isset($data['token'], $data['expires']) && hash_equals((string) $data['token'], $hashed)) {
            if (intval($data['expires']) > time()) return $uid;
            delete_user_meta($uid, '_tix_app_token');
            return 0;
        }
        // Index veraltet (z. B. Meta direkt in der Datenbank geändert) → neu schreiben
        self::sync_token_index($uid);
        return null;
    }

    /** Neues Geräte-Token ausstellen (90 Tage), ohne andere Geräte abzumelden. */
    public static function issue_app_token($user_id, $device = '') {
        $token = wp_generate_password(64, false, false);
        $list  = get_user_meta($user_id, '_tix_app_tokens', true);
        $list  = is_array($list) ? $list : [];
        $now   = time();
        $list  = array_values(array_filter($list, function ($t) use ($now) {
            return is_array($t) && intval($t['expires'] ?? 0) > $now;
        }));
        $list[] = [
            'token'   => hash('sha256', $token),
            'expires' => $now + (90 * DAY_IN_SECONDS),
            'created' => $now,
            'device'  => mb_substr(sanitize_text_field((string) $device), 0, 80),
        ];
        // Maximal 8 Geräte, älteste fliegen raus
        if (count($list) > 8) $list = array_slice($list, -8);
        update_user_meta($user_id, '_tix_app_tokens', $list);
        return $token;
    }

    /** Abgelaufene Geräte-Tokens entfernen. */
    private static function prune_app_tokens($user_id) {
        $list = get_user_meta($user_id, '_tix_app_tokens', true);
        if (!is_array($list)) return;
        $now  = time();
        $list = array_values(array_filter($list, function ($t) use ($now) {
            return is_array($t) && intval($t['expires'] ?? 0) > $now;
        }));
        if ($list) update_user_meta($user_id, '_tix_app_tokens', $list);
        else delete_user_meta($user_id, '_tix_app_tokens');
    }

    /** POST /auth/logout – nur das Token dieses Geräts ungültig machen. */
    public static function auth_logout(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $raw  = (string) ($_SERVER['HTTP_X_TIX_TOKEN'] ?? '');
        if ($raw === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ')) {
            $raw = substr($_SERVER['HTTP_AUTHORIZATION'], 7);
        }
        $hashed = hash('sha256', $raw);
        $list = get_user_meta($user->ID, '_tix_app_tokens', true);
        if (is_array($list)) {
            $list = array_values(array_filter($list, function ($t) use ($hashed) {
                return !(is_array($t) && hash_equals((string) ($t['token'] ?? ''), $hashed));
            }));
            if ($list) update_user_meta($user->ID, '_tix_app_tokens', $list);
            else delete_user_meta($user->ID, '_tix_app_tokens');
        }
        $legacy = get_user_meta($user->ID, '_tix_app_token', true);
        if (is_array($legacy) && hash_equals((string) ($legacy['token'] ?? ''), $hashed)) {
            delete_user_meta($user->ID, '_tix_app_token');
        }
        return rest_ensure_response(['success' => true]);
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
            // Auch „Eingang“ (zum Auswählen des Events fürs Einchecken); die Liste
            // enthält nur Titel/Datum/Zählstände, keinen Umsatz oder Adressen.
            'permission_callback' => [__CLASS__, 'check_entrance'],
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

        // Archiv (1.38.457): {archived: true|false} wie die Zeilen-Aktion im Experten-Modus (TIX_Archive)
        register_rest_route($ns, '/events/(?P<id>\d+)/archive', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'archive_event'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        // Papierkorb (1.38.462): DELETE /events/{id} (nur ohne Verkäufe), POST /events/{id}/restore
        register_rest_route($ns, '/events/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [__CLASS__, 'trash_event'],
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);
        register_rest_route($ns, '/events/(?P<id>\d+)/restore', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'restore_event'],
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
            'permission_callback' => [__CLASS__, 'check_entrance'],
        ]);

        register_rest_route($ns, '/checkin/(?P<event_id>\d+)/list', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'checkin_list'],
            'permission_callback' => [__CLASS__, 'check_entrance'],
        ]);

        register_rest_route($ns, '/checkin/(?P<event_id>\d+)/guest/(?P<guest_id>[A-Za-z0-9-]+)', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'checkin_update_guest'],
            'permission_callback' => [__CLASS__, 'check_entrance'],
        ]);

        // Auschecken mit festem Ziel „nicht eingecheckt“ (1.38.465; Toggle würde zwei Geräte gegenseitig zurücksetzen)
        register_rest_route($ns, '/checkin/uncheck', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'checkin_uncheck'],
            'permission_callback' => [__CLASS__, 'check_entrance'],
        ]);

        register_rest_route($ns, '/checkin/ticket/(?P<ticket_id>\d+)/toggle', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'checkin_toggle_ticket'],
            'permission_callback' => [__CLASS__, 'check_entrance'],
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

        // Einzelner Gast (App): POST = ändern, POST mit _method=DELETE oder DELETE = löschen
        register_rest_route($ns, '/events/(?P<id>\d+)/guestlist/(?P<guest_id>[A-Za-z0-9-]+)', [
            'methods'             => ['POST', 'DELETE'],
            'callback'            => [__CLASS__, 'update_guest'],
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

        // ── POS ── (auch Rolle „Kasse & Bar“, check_pos)
        register_rest_route($ns, '/pos/events', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'pos_events'],
            'permission_callback' => [__CLASS__, 'check_pos'],
        ]);
        register_rest_route($ns, '/pos/events/(?P<id>\d+)/categories', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'pos_categories'],
            'permission_callback' => [__CLASS__, 'check_pos'],
        ]);

        register_rest_route($ns, '/pos/orders', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pos_create_order'],
            'permission_callback' => [__CLASS__, 'check_pos'],
        ]);

        register_rest_route($ns, '/pos/orders/(?P<id>\d+)/email', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pos_send_email'],
            'permission_callback' => [__CLASS__, 'check_pos'],
        ]);

        register_rest_route($ns, '/pos/orders/(?P<id>\d+)/void', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pos_void_order'],
            'permission_callback' => [__CLASS__, 'check_pos'],
        ]);

        register_rest_route($ns, '/pos/report', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'pos_report'],
            // Tagesbericht/Buchungen nur Veranstalter, Team-Admin, Mitarbeiter, Admin – nicht „Kasse & Bar“ (User-Entscheid 1.38.442)
            'permission_callback' => [__CLASS__, 'check_organizer'],
        ]);

        register_rest_route($ns, '/pos/transactions', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'pos_transactions'],
            // „Kasse & Bar“ sieht nur eigene Verkäufe von heute (User-Entscheid 1.38.484); Tagesbericht bleibt Inhaber/Team
            'permission_callback' => [__CLASS__, 'check_pos'],
        ]);

        // Vorschau mit Gutschein-Code (Betrag, Abzug, Restwert) vor dem Kassieren (1.38.484)
        register_rest_route($ns, '/pos/quote', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pos_quote'],
            'permission_callback' => [__CLASS__, 'check_pos'],
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

        register_rest_route($ns, '/auth/logout', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'auth_logout'],
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

        // ── Native Orders ──
        register_rest_route($ns, '/orders', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_orders'],
            'permission_callback' => function() { return current_user_can('edit_posts'); },
        ]);

        register_rest_route($ns, '/orders/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_order'],
            'permission_callback' => function() { return current_user_can('edit_posts'); },
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
        // Admins, Mitarbeiter (App) und Veranstalter haben Zugriff
        if (self::is_staff_user(wp_get_current_user())) {
            // Mehr-Veranstalter-Modus: nur mit freigegebenem Veranstalter
            if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) return TIX_App_Scope::require_organizer();
            return true;
        }
        return new WP_Error('rest_forbidden', 'Keine Berechtigung. Rolle „Veranstalter“ oder „Mitarbeiter (App)“ erforderlich.', ['status' => 403]);
    }

    /** Abendkasse: Staff (wie check_organizer) oder Rolle „Kasse & Bar“ (Recht tix_app_pos). */
    public static function check_pos(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        $u = wp_get_current_user();
        if (self::is_staff_user($u)) return self::check_organizer($req);
        if (!$u->has_cap('tix_app_pos')) return new WP_Error('rest_forbidden', 'Keine Berechtigung für die Kasse.', ['status' => 403]);
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) return TIX_App_Scope::require_organizer();
        return true;
    }

    /** Rolle „Kasse & Bar“ ohne weitere Staff-Rolle? */
    public static function is_cashier_only($user) {
        return $user && $user->ID && $user->has_cap('tix_app_pos') && !self::is_staff_user($user);
    }

    /** GET /pos/events – Events für die Kasse (laufend und die nächsten 30 Tage, im Mehr-Veranstalter-Modus nur eigene). */
    public static function pos_events(WP_REST_Request $req) {
        $now = current_time('timestamp');
        $args = ['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 50, 'fields' => 'ids',
            'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_date_start', 'value' => [gmdate('Y-m-d', $now - 2 * DAY_IN_SECONDS), gmdate('Y-m-d', $now + 30 * DAY_IN_SECONDS)], 'compare' => 'BETWEEN', 'type' => 'DATE']]];
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::scoped()) {
            $oid = TIX_App_Scope::organizer_id_for_user();
            if (!$oid) return rest_ensure_response(['ok' => true, 'events' => []]);
            $args['meta_query'][] = TIX_App_Scope::event_meta_clause($oid);
        }
        $out = [];
        foreach (get_posts($args) as $eid) {
            $end = (string) (get_post_meta($eid, '_tix_date_end', true) ?: get_post_meta($eid, '_tix_date_start', true));
            if ($end !== '' && strtotime($end) < strtotime(gmdate('Y-m-d', $now - DAY_IN_SECONDS))) continue;
            $out[] = [
                'id'         => intval($eid),
                'title'      => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'),
                'date_start' => (string) get_post_meta($eid, '_tix_date_start', true),
                'time_start' => (string) get_post_meta($eid, '_tix_time_start', true),
                'date_end'   => (string) get_post_meta($eid, '_tix_date_end', true),
                'location'   => (string) get_post_meta($eid, '_tix_location', true),
            ];
        }
        return rest_ensure_response(['ok' => true, 'events' => $out]);
    }

    /** Hat der Nutzer die Rolle „Eingang (App)“? */
    public static function is_entrance_user($user) {
        return $user && $user->ID && (
            $user->has_cap('tix_app_entrance') ||
            in_array('tix_entrance', (array) $user->roles, true)
        );
    }

    /** Reiner Eingang-Zugang (Eingang, aber kein Voll-Staff/Admin)? */
    public static function is_entrance_only($user) {
        return self::is_entrance_user($user) && !self::is_staff_user($user);
    }

    /**
     * Berechtigung für Check-in/Einlass: volle Staff-Rollen ODER „Eingang“.
     * Nur für Scan + Namensliste, NICHT für Bestellungen/Statistik/Kasse.
     */
    public static function check_entrance(WP_REST_Request $req) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        }
        $user = wp_get_current_user();
        if (self::is_staff_user($user) || self::is_entrance_user($user)) {
            if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) return TIX_App_Scope::require_organizer();
            return true;
        }
        return new WP_Error('rest_forbidden', 'Keine Berechtigung für den Einlass.', ['status' => 403]);
    }

    /**
     * Prüft ob der aktuelle User Zugriff auf ein bestimmtes Event hat.
     */
    /** Öffentlich für andere Module (Event-Editor, Bestell-Details). */
    public static function user_can_access_event($event_id) {
        return self::can_access_event($event_id);
    }

    /** Event im Veranstalter-Format (öffentlich für andere Module). */
    public static function event_payload($post, $detailed = false) {
        return self::format_event($post, $detailed);
    }

    public static function can_access_event($event_id) {
        // Mehr-Veranstalter-Modus: nur Events des eigenen Veranstalters (Admins alle)
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            return TIX_App_Scope::event_allowed($event_id);
        }
        $user = wp_get_current_user();
        // Admins und Mitarbeiter (App) sehen alles; „Eingang“ ebenfalls (nur Check-in,
        // die restlichen Endpunkte sperrt bereits die jeweilige permission_callback).
        if ($user->has_cap('manage_options')
            || in_array('tix_staff', (array) $user->roles, true)
            || self::is_entrance_user($user)
            || $user->has_cap('tix_app_pos')) {
            return true;
        }
        // Veranstalter: nur eigene Events – ohne Verknüpfung alle (Ein-Club-Setup)
        if (class_exists('TIX_Organizer_Dashboard')) {
            $org = TIX_Organizer_Dashboard::get_organizer_by_user($user->ID);
            if (!$org) return true;
            return TIX_Organizer_Dashboard::user_owns_event($user->ID, $event_id);
        }
        // Veranstalter-Dashboard aus (Ein-Club-Betrieb, z. B. App-Team-Rolle „Admin“):
        // dieselbe Regel ohne die Dashboard-Klasse – vorher gab es hier immer 403.
        if (in_array('tix_organizer', (array) $user->roles, true)) {
            $org = self::linked_organizer($user->ID);
            if (!$org) return true;
            return class_exists('TIX_App_Scope')
                && in_array(intval($org->ID), TIX_App_Scope::event_org_ids($event_id), true);
        }
        return false;
    }

    /**
     * Veröffentlichter Veranstalter, mit dem der Nutzer verknüpft ist (Inhaber
     * oder Team) – auch wenn das Veranstalter-Dashboard ausgeschaltet ist.
     */
    private static function linked_organizer($user_id) {
        if (class_exists('TIX_Organizer_Dashboard')) {
            return TIX_Organizer_Dashboard::get_organizer_by_user($user_id);
        }
        if (!class_exists('TIX_App_Scope')) return null;
        $org = TIX_App_Scope::organizer_post_for_user($user_id);
        return ($org && $org->post_status === 'publish') ? $org : null;
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

    /**
     * Shop-Steckbrief für die App „evendis für Veranstalter“ (1.38.562):
     * Name, Logo, Akzent, Art (platform = Mehr-Veranstalter-Modus, shop = eigener Shop)
     * und ob die Seite eine eigene App hat. Öffentlich, ohne personenbezogene Daten.
     */
    public static function staff_site_info() {
        $b = class_exists('TIX_Mail_Layout') && method_exists('TIX_Mail_Layout', 'brand') ? (array) TIX_Mail_Layout::brand() : [];
        $app = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'app_info') ? (array) TIX_Studio::app_info() : [];
        $multi = class_exists('TIX_App_Scope') ? TIX_App_Scope::multi() : (get_option('tix_multi_organizer') === '1');
        $accent = (string) ($b['accent'] ?? '');
        return [
            'name'           => (string) ($b['name'] ?? html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8')),
            'logo'           => (string) ($b['logo'] ?? ''),
            'logo_on_accent' => (string) ($b['logo_on_accent'] ?? ''),
            'icon'           => (string) get_site_icon_url(256),
            'accent'         => preg_match('/^#[0-9a-f]{6}$/i', $accent) ? strtoupper($accent) : '#FC2F55',
            'kind'           => $multi ? 'platform' : 'shop',
            'has_app'        => !empty($app['has_app']),
        ];
    }

    public static function get_info(WP_REST_Request $req) {
        $features = [];
        // Platzhalterbild für Events ohne Bild (1.38.458, Studio → Marke): {url, url_small} | null
        $ph = class_exists('TIX_Studio') ? TIX_Studio::placeholder() : null;

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
            'compat'         => class_exists('TIX_App_Compat') ? TIX_App_Compat::info_payload($req) : null,
            'modules'        => class_exists('TIX_Modules') ? TIX_Modules::info_payload() : null,
            'modules_source' => class_exists('TIX_Modules') ? TIX_Modules::source() : 'all',
            // Awareness (kostenlos, nie gesperrt): was die Seite freigeschaltet hat – maßgeblich für Melden/Feedback bzw. Hilfe vor Ort
            'awareness'      => class_exists('TIX_Awareness_Live') ? TIX_Awareness_Live::flags() : ['report_enabled' => false, 'alarm_live' => false],
            'event_placeholder' => $ph ? ['url' => $ph['url'], 'url_small' => $ph['url_small']] : null,
            // App-Funktionen an/aus (TIX_Features, 1.38.498): {<key>: bool} wie GET /app/config `features`
            'app_features'   => class_exists('TIX_Features') ? TIX_Features::map(TIX_Features::org_from_param($req->get_param('organizer'))) : null,
        ] + ((class_exists('TIX_Weekly_Mail') && TIX_Weekly_Mail::multi()) ? [
            // Wochenmail (evendis): Karte „Was geht am Wochenende?“ nur bei enabled
            'newsletter'     => ['enabled' => TIX_Weekly_Mail::enabled()],
            // Folgen per E-Mail ohne Konto (Veranstalter-Seite): Feld nur bei enabled zeigen
            'mail_follow'    => ['enabled' => class_exists('TIX_Mail_Follow') && TIX_Mail_Follow::enabled()],
            // Aktive Zahlarten (Vertrauens-Leiste im Web) – nur Anzeige, maßgeblich bleibt das Angebot der Kasse
            'payment_methods' => class_exists('TIX_App_Checkout') ? TIX_App_Checkout::info_methods() : [],
        ] : []) + [
            // App „evendis für Veranstalter“ (1.38.562): Shop erkennen, Marke je Shop, Mindestversion
            'site'           => self::staff_site_info(),
            // Module der Seite (TIX_Plan): immer dabei · Pakete · Extras
            'plan'           => class_exists('TIX_Plan') ? TIX_Plan::info_payload() : null,
            // Lizenz-Stufe (1.38.566): active | notice | locked | basis – Apps schalten Kauf/Basis danach
            'license'        => class_exists('TIX_License_Stage') ? TIX_License_Stage::payload() : null,
            'staff_app'      => [
                'min_version' => (string) get_option('tix_staff_app_min_version', ''),
                'push_ready'  => class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'push_configured') && TIX_Notifications::push_configured(),
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    //  AUTH
    // ═══════════════════════════════════════════

    private static function app_role_key($user) {
        $roles = (array) $user->roles;
        if (in_array('administrator', $roles, true)) return 'admin_wp';
        foreach (['admin' => 'tix_organizer', 'cashier' => 'tix_cashier', 'entrance' => 'tix_entrance', 'awareness' => 'tix_awareness', 'dj' => 'tix_dj', 'staff' => 'tix_staff'] as $k => $r) {
            if (in_array($r, $roles, true)) return $k;
        }
        return null;
    }

    public static function get_me(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $organizer_id = null;
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();

        if ($multi) {
            $organizer_id = TIX_App_Scope::organizer_id_for_user($user->ID) ?: null;
        } elseif (class_exists('TIX_Organizer_Dashboard')) {
            $org = TIX_Organizer_Dashboard::get_organizer_by_user($user->ID);
            if ($org) {
                $organizer_id = $org->ID;
            }
        }

        $out = [
            'ok'   => true,
            'user' => [
                'id'           => $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'role'         => implode(',', (array) $user->roles),
                'organizer_id' => $organizer_id,
                'avatar'       => get_avatar_url($user->ID, ['size' => 96]),
                // App-Rolle wie in /team: admin_wp|admin|dj|entrance|awareness|cashier|staff|null (1.38.441)
                'app_role'     => self::app_role_key($user),
            ],
        ];
        // Module dieses Kontos (TIX_Plan, 1.38.563): {key: bool} – Apps blenden danach aus
        if (class_exists('TIX_Plan')) $out['modules'] = TIX_Plan::me_payload();
        if (class_exists('TIX_License_Stage')) $out['license'] = TIX_License_Stage::payload();
        // Mehr-Veranstalter-Modus: Veranstalter (Name, Status, Inhaber?) zusätzlich
        if ($multi) {
            $out['multi_organizer'] = true;
            $out['organizer']       = TIX_App_Scope::me_payload($user->ID);
            $out['is_admin']        = $user->has_cap('manage_options');
            $out['sales']           = class_exists('TIX_Sales') && TIX_Sales::is_sales_user($user); // Vertrieb (1.38.508)
        }
        return rest_ensure_response($out);
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
        // Mehr-Veranstalter-Modus: auch zurückgehaltene Events (warten auf Freigabe des Veranstalters)
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) $args['post_status'][] = 'pending';

        if ($search) {
            $args['s'] = $search;
        }

        $today = current_time('Y-m-d');

        if ($filter === 'today') {
            // heute beginnend oder gerade laufend (mehrtägig, Enddatum >= heute)
            $args['meta_query'] = [[
                'relation' => 'OR',
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '=', 'type' => 'DATE'],
                [
                    'relation' => 'AND',
                    ['key' => '_tix_date_start', 'value' => $today, 'compare' => '<=', 'type' => 'DATE'],
                    ['key' => '_tix_date_end',   'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                ],
            ]];
        } elseif ($filter === 'upcoming') {
            // auch Events, die gestern begonnen haben und noch laufen (Enddatum)
            $args['meta_query'] = [[
                'relation' => 'OR',
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                ['key' => '_tix_date_end',   'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
            ]];
        } elseif ($filter === 'past') {
            $args['meta_query'] = [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '<', 'type' => 'DATE'],
            ];
            $args['order'] = 'DESC';
        }

        // Mehr-Veranstalter-Modus: nur Events des eigenen Veranstalters (Admins alle)
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped()) {
            $oid = TIX_App_Scope::organizer_id_for_user($user->ID);
            if (!$oid) {
                return rest_ensure_response(['ok' => true, 'count' => 0, 'events' => []]);
            }
            $args['meta_query']   = $args['meta_query'] ?? [];
            $args['meta_query'][] = TIX_App_Scope::event_meta_clause($oid);
        } elseif (!$user->has_cap('manage_options')
            && !in_array('tix_staff', (array) $user->roles, true)) {
            // verknüpfte Veranstalter (auch bei ausgeschaltetem Dashboard)
            $org = self::linked_organizer($user->ID);
            if ($org) {
                $args['meta_query']   = $args['meta_query'] ?? [];
                $args['meta_query'][] = [
                    'relation' => 'OR',
                    ['key' => '_tix_organizer_id', 'value' => strval($org->ID)],
                    ['key' => '_tix_co_organizer_id', 'value' => strval($org->ID)],
                ];
            }
        }

        // Vorschauen des Vertriebs (1.38.513): auch für Admins nie in /events – bearbeitet wird nur über /sales/previews*
        if (class_exists('TIX_Sales_Previews') && ($pc = TIX_Sales_Previews::event_list_clause())) {
            $args['meta_query'] = $args['meta_query'] ?? [];
            $args['meta_query'][] = $pc;
        }

        // Archiv (1.38.457): view=active (Vorgabe, wie die Admin-Liste) | archived | all; bei filter=past Vorgabe all (wie bisher)
        $view = sanitize_key((string) ($req->get_param('view') ?: ($filter === 'past' ? 'all' : 'active')));
        if (class_exists('TIX_Archive') && in_array($view, ['active', 'archived'], true)) {
            $args['meta_query'] = $args['meta_query'] ?? [];
            $args['meta_query'][] = $view === 'archived'
                ? ['key' => TIX_Archive::META_FLAG, 'value' => '1']
                : ['key' => TIX_Archive::META_FLAG, 'compare' => 'NOT EXISTS'];
        }

        $posts  = get_posts($args);
        $events = [];

        foreach ($posts as $p) {
            $events[] = self::format_event($p);
        }

        return rest_ensure_response([
            'ok'     => true,
            'count'  => count($events),
            'view'   => $view,
            'events' => $events,
        ]);
    }

    /** POST /events/{id}/archive {archived: true|false} → {ok, event_id, archived, archived_at} */
    public static function archive_event(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        if (!class_exists('TIX_Archive')) return new WP_Error('tix_unavailable', 'Das Archiv ist nicht verfügbar.', ['status' => 404]);
        $a = $req->get_param('archived');
        if ($a === null) return new WP_Error('tix_invalid_field', 'Bitte archived: true oder false angeben.', ['status' => 400, 'field' => 'archived']);
        if (filter_var($a, FILTER_VALIDATE_BOOLEAN)) { if (!TIX_Archive::is_archived($id)) TIX_Archive::archive_event($id); }
        elseif (TIX_Archive::is_archived($id)) TIX_Archive::unarchive_event($id);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return rest_ensure_response(['ok' => true, 'event_id' => $id, 'archived' => TIX_Archive::is_archived($id), 'archived_at' => (string) get_post_meta($id, TIX_Archive::META_DATE, true) ?: null]);
    }

    /** Verkaufte (nicht stornierte) Tickets bzw. Plätze in offenen/bezahlten Bestellungen – Gästeliste zählt nicht. */
    public static function sales_count($event_id) {
        global $wpdb;
        $n = intval(self::ticket_counts($event_id)['total'] ?? 0);
        $t = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $q = intval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(i.quantity), 0) FROM $ti i INNER JOIN $t o ON o.id = i.order_id
                WHERE i.event_id = %d AND o.status NOT IN ('cancelled', 'failed', 'refunded', 'trash')", intval($event_id))));
            $n = max($n, $q);
        }
        return $n;
    }

    /**
     * DELETE /events/{id} → in den Papierkorb (nicht endgültig). Mit Verkäufen 409 tix_has_sales {sold}.
     * Geteilte Events: wie der Papierkorb im WP-Admin – die Verteilung nimmt das Plattform-Event auf Entwurf zurück
     * (TIX_Syndication_Push::on_status_change; nicht bei einer Kopie mit derselben remote_id).
     */
    public static function trash_event(WP_REST_Request $req) {
        $id = absint($req['id']);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        if ($post->post_status === 'trash') return rest_ensure_response(['ok' => true, 'event_id' => $id, 'trashed' => true]);
        $sold = self::sales_count($id);
        if ($sold > 0) {
            return new WP_Error('tix_has_sales', sprintf('Das Event hat %d verkaufte Tickets und kann nicht gelöscht werden. Archivieren oder als Entwurf offline nehmen geht.', $sold), ['status' => 409, 'sold' => $sold]);
        }
        if (!wp_trash_post($id)) return new WP_Error('tix_trash_failed', 'Das Event konnte nicht gelöscht werden.', ['status' => 500]);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return rest_ensure_response(['ok' => true, 'event_id' => $id, 'trashed' => true]);
    }

    /** POST /events/{id}/restore → aus dem Papierkorb, immer als Entwurf (Veröffentlichen danach wie gewohnt). */
    public static function restore_event(WP_REST_Request $req) {
        $id = absint($req['id']);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!self::can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        if ($post->post_status !== 'trash') return new WP_Error('tix_not_trashed', 'Das Event liegt nicht im Papierkorb.', ['status' => 409, 'post_status' => $post->post_status]);
        $draft = function () { return 'draft'; };
        add_filter('wp_untrash_post_status', $draft, 99);
        $ok = wp_untrash_post($id);
        remove_filter('wp_untrash_post_status', $draft, 99);
        if (!$ok) return new WP_Error('tix_restore_failed', 'Das Event konnte nicht wiederhergestellt werden.', ['status' => 500]);
        return rest_ensure_response(['ok' => true, 'event_id' => $id, 'restored' => true, 'status' => get_post_status($id)]);
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
        // Event-Editor der App (Titel, Termin, Ort, Info-Texte, Kategorien, Vorverkauf …)
        return TIX_App_Events::create($req);
    }

    // ═══════════════════════════════════════════
    //  EVENT: STATISTICS
    // ═══════════════════════════════════════════

    public static function event_statistics(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (!self::can_access_event($id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }
        // Umsatz + Bestellungen aus den nativen Bestellungen (kein WooCommerce)
        $total_revenue = 0;
        $total_orders  = 0;
        $total_tickets = 0;
        $by_category   = [];
        $by_day        = [];
        if (class_exists('TIX_Order')) {
            $native_orders = TIX_Order::query([
                'event_id' => $id,
                'status'   => ['completed', 'processing'],
                'limit'    => 5000,
            ]);
            foreach ((array) $native_orders as $native) {
                $order_event_revenue   = 0;
                $order_has_event_items = false;
                $created = $native->get_date_created();
                $day     = $created ? $created->format('Y-m-d') : '';
                foreach ($native->get_items() as $item) {
                    $item_event_id = $item->get_event_id();
                    if ($item_event_id && $item_event_id != $id) continue;
                    $order_has_event_items = true;
                    $qty        = $item->get_quantity();
                    $cat        = self::item_label($item);
                    $item_total = (float) $item->get_total();
                    $total_tickets       += $qty;
                    $order_event_revenue += $item_total;
                    if (!isset($by_category[$cat])) {
                        $by_category[$cat] = ['revenue' => 0, 'tickets' => 0];
                    }
                    $by_category[$cat]['revenue'] += $item_total;
                    $by_category[$cat]['tickets'] += $qty;
                }
                if ($order_has_event_items) {
                    $total_orders++;
                    $total_revenue += $order_event_revenue;
                    if ($day) {
                        $by_day[$day] = ($by_day[$day] ?? 0) + $order_event_revenue;
                    }
                }
            }
        }
        // Check-in-Quote: Gästeliste + Ticket-Posts
        $checkin_total = 0;
        $checkin_done  = 0;
        $guests = get_post_meta($id, '_tix_guest_list', true);
        if (is_array($guests)) {
            foreach ($guests as $g) {
                $expected = 1 + intval($g['plus'] ?? 0);
                $checkin_total += $expected;
                $done = isset($g['checked_in_count']) ? intval($g['checked_in_count']) : (empty($g['checked_in']) ? 0 : $expected);
                $checkin_done += min($done, $expected);
            }
        }
        $counts = self::ticket_counts($id);
        $checkin_total += $counts['total'];
        $checkin_done  += $counts['checked'];
        $checkin_rate = $checkin_total > 0 ? round($checkin_done / $checkin_total * 100, 1) : 0;
        ksort($by_day);
        $by_day_last30 = array_slice($by_day, -30, null, true);
        return rest_ensure_response([
            'ok'         => true,
            'statistics' => [
                'total_revenue'  => round($total_revenue, 2),
                'total_tickets'  => $total_tickets,
                'total_orders'   => $total_orders,
                'by_category'    => (object) $by_category,
                'by_day'         => (object) $by_day_last30,
                'checkin_total'  => $checkin_total,
                'checkin_done'   => $checkin_done,
                'checkin_rate'   => $checkin_rate,
            // 1.38.454 (rein hinzufügend, nur Inhaber/Team-Admins): sales_by_day, by_channel, by_source, checkin_by_slot, views, conversion
            ] + (class_exists('TIX_Studio_Stats') && TIX_Studio_Stats::is_manager() ? TIX_Studio_Stats::event_extra($id) : []),
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
        // Native Bestellungen mit Positionen dieses Events (kein WooCommerce)
        // Seitenweise (1.38.441): page, per_page (1–100), status paid|open|cancelled|all, search; ohne page/per_page wie bisher bis 200
        $groups = ['paid' => ['completed', 'processing'], 'open' => ['pending', 'on-hold'], 'cancelled' => ['cancelled', 'refunded', 'failed']];
        $status = sanitize_key((string) ($req->get_param('status') ?? 'all'));
        $search = mb_substr(sanitize_text_field((string) ($req->get_param('search') ?? '')), 0, 80);
        $paged  = $req->get_param('page') !== null || $req->get_param('per_page') !== null;
        $per    = $paged ? max(1, min(100, intval($req->get_param('per_page') ?: 25))) : 200;
        $page   = max(1, intval($req->get_param('page') ?: 1));
        $base   = ['event_id' => $id, 'search' => $search];
        $filter = $base + (isset($groups[$status]) ? ['status' => $groups[$status]] : []);
        $orders_out = [];
        $total = 0;
        if (class_exists('TIX_Order')) {
            $total  = TIX_Order::count($filter);
            $orders = TIX_Order::query($filter + ['limit' => $per, 'offset' => ($page - 1) * $per]);
            foreach ($orders as $order) {
                $items = [];
                $event_total = 0;
                foreach ($order->get_items() as $item) {
                    $item_event_id = $item->get_event_id();
                    if ($item_event_id && $item_event_id != $id) continue;
                    $item_total = (float) $item->get_total();
                    $items[] = [
                        'name'     => self::item_label($item),
                        'category' => $item->get_cat_name(),
                        'quantity' => $item->get_quantity(),
                        'total'    => $item_total,
                    ];
                    $event_total += $item_total;
                }
                if (empty($items)) continue;
                $created = $order->get_date_created();
                $orders_out[] = [
                    'id'           => $order->get_id(),
                    'order_number' => $order->get_order_number(),
                    'customer'     => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'email'        => (string) $order->get_billing_email(),
                    'items'        => $items,
                    'total'        => round($event_total, 2),
                    'payment'      => (string) ($order->get_payment_method_title() ?: $order->get_payment_method()),
                    'status'       => (string) $order->get_status(),
                    'date'         => $created ? $created->format('Y-m-d H:i') : '',
                ];
            }
        }
        $counts = ['all' => 0, 'paid' => 0, 'open' => 0, 'cancelled' => 0];
        if (class_exists('TIX_Order')) {
            $counts['all'] = TIX_Order::count($base);
            foreach ($groups as $k => $sts) $counts[$k] = TIX_Order::count($base + ['status' => $sts]);
        }
        return rest_ensure_response([
            'ok'       => true,
            'orders'   => $orders_out,
            'count'    => count($orders_out),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per,
            'has_more' => $page * $per < $total,
            'counts'   => $counts,
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

        // Event-Zugriff prüfen (Mehr-Veranstalter-Modus: Tickets ohne Event nur für Admins)
        if (!$ticket_event_id && class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped()) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Ticket.', ['status' => 403]);
        }
        // Test-Tickets einer Vorschau (1.38.512): auch der Vertriebler, dem die Vorschau gehört
        if ($ticket_event_id && !self::can_access_event($ticket_event_id) && !(class_exists('TIX_Sales_Previews') && TIX_Sales_Previews::can_checkin($ticket_event_id))) {
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

        // Spiegel-Ticket eines geteilten Events: Einlass nur beim Veranstalter (Quelle)
        if ($lock = TIX_Tickets::mirror_lock_message($ticket->ID)) {
            return rest_ensure_response([
                'ok'      => true,
                'status'  => 'invalid',
                'mirror'  => true,
                'name'    => $name,
                'type'    => 'ticket',
                'code'    => $code,
                'message' => $lock,
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

        // Auto-Repair: Fehlende tix_ticket Posts nachgenerieren (z.B. bei gelöschten Produkten,
        // verschobenen Tickets oder Orders bei denen on_order_completed nie gefeuert hat).
        if (class_exists('TIX_Tickets')) {
            TIX_Tickets::ensure_tickets_for_event($event_id);
        }

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
                    // Studio verlinkt zur Bestellung (1.38.465; Einlass sieht es nicht)
                    'order_id'        => intval(get_post_meta($t->ID, '_tix_ticket_order_id', true)) ?: null,
                    'order_number'    => class_exists('TIX_Order') ? TIX_Order::number_for(get_post_meta($t->ID, '_tix_ticket_order_id', true)) : null,
                ];

                $stats['total']++;
                $stats['tickets']++;
                if ($checked_in) $stats['checked_in']++;
            }
        }

        // „Eingang“ sieht nur Name + Check-in-Status, keine personenbezogenen
        // Zusatzdaten (E-Mail, Notiz, Sitzplatz).
        if (self::is_entrance_only(wp_get_current_user())) {
            foreach ($combined as &$row) {
                unset($row['email'], $row['note'], $row['seat'], $row['order_id']);
            }
            unset($row);
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

    /**
     * POST /checkin/uncheck {ticket_id | code} → Ticket bzw. Gast sicher auf „nicht eingecheckt“ (idempotent).
     * code: Ticket-Code, Ticket-QR „GL-<Event>-<Code>“ oder Gästelisten-QR „GL-<Event>-<Gast-ID>“.
     * Antwort {ok, type: ticket|guest, id, event_id, checked_in: false, changed, name, message}.
     */
    public static function checkin_uncheck(WP_REST_Request $req) {
        $tid = absint($req->get_param('ticket_id'));
        $code = strtoupper(trim((string) $req->get_param('code')));
        if (!$tid && $code === '') return new WP_Error('tix_invalid_field', 'Bitte ticket_id oder code angeben.', ['status' => 400, 'field' => 'code']);
        if (!class_exists('TIX_Tickets')) return new WP_Error('no_ticket_system', 'Ticketsystem nicht aktiv.', ['status' => 500]);
        if (!$tid && $code !== '') {
            $plain = $code;
            $ev = 0;
            if (preg_match('/^GL-(\d+)-(.+)$/', $code, $m)) { $ev = intval($m[1]); $plain = $m[2]; }
            $t = TIX_Tickets::get_ticket_by_code($plain);
            $tid = is_object($t) ? intval($t->ID ?? 0) : (is_array($t) ? intval($t['ID'] ?? ($t['id'] ?? 0)) : intval($t));
            if (!$tid && $ev) {
                // Gästeliste: gleicher Weg wie POST /checkin/{event}/guest/{id} mit count 0
                $guests = get_post_meta($ev, '_tix_guest_list', true);
                foreach ((array) $guests as $g) {
                    if (is_array($g) && strtoupper((string) ($g['id'] ?? '')) === $plain) {
                        $was = !empty($g['checked_in']) || intval($g['checked_in_count'] ?? 0) > 0;
                        $sub = new WP_REST_Request('POST', '/tixomat/v1/checkin/' . $ev . '/guest/' . $g['id']);
                        $sub->set_url_params(['event_id' => $ev, 'guest_id' => (string) $g['id']]);
                        $sub->set_header('content-type', 'application/json');
                        $sub->set_body(wp_json_encode(['count' => 0, 'checkin_password' => $req->get_param('checkin_password')]));
                        foreach (['X-Checkin-Password'] as $hk) if ($req->get_header($hk)) $sub->set_header($hk, $req->get_header($hk));
                        $r = self::checkin_update_guest($sub);
                        if (is_wp_error($r)) return $r;
                        return rest_ensure_response(['ok' => true, 'type' => 'guest', 'id' => (string) $g['id'], 'event_id' => $ev, 'checked_in' => false, 'changed' => $was,
                            'name' => (string) ($g['name'] ?? ''), 'message' => $was ? 'Check-in zurückgesetzt.' : 'War nicht eingecheckt.']);
                    }
                }
            }
            if (!$tid) return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        }
        $post = get_post($tid);
        if (!$post || $post->post_type !== 'tix_ticket') return new WP_Error('not_found', 'Ticket nicht gefunden.', ['status' => 404]);
        $event_id = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
        if (!self::can_access_event($event_id)) return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        $pw = self::verify_checkin_password($event_id, $req);
        if (is_wp_error($pw)) return $pw;
        if ($lock = TIX_Tickets::mirror_lock_message($tid)) return new WP_Error('tix_mirror_ticket', $lock, ['status' => 409]);
        $was = TIX_Tickets::is_checked_in($tid);
        if ($was) {
            TIX_Tickets::reset_checkin($tid);
            try {
                $c = get_post_meta($tid, '_tix_ticket_code', true);
                if ($c && class_exists('TIX_Ticket_DB') && class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled')) {
                    TIX_Ticket_DB::update_ticket($c, ['checked_in' => 0, 'checkin_time' => null, 'ticket_status' => 'valid', 'synced_supabase' => 0, 'synced_airtable' => 0]);
                }
            } catch (\Throwable $e) {}
        }
        return rest_ensure_response(['ok' => true, 'type' => 'ticket', 'id' => $tid, 'event_id' => $event_id, 'checked_in' => false, 'changed' => $was,
            'name' => (string) get_post_meta($tid, '_tix_ticket_owner_name', true), 'message' => $was ? 'Check-in zurückgesetzt.' : 'War nicht eingecheckt.']);
    }

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

        if ($lock = TIX_Tickets::mirror_lock_message($ticket_id)) {
            return new WP_Error('tix_mirror_ticket', $lock, ['status' => 409]);
        }

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

        $body = $req->get_json_params();
        if (!is_array($body)) $body = [];

        // ── Einzelnen Gast anhängen (App: {name, email, note, plus}) ──
        if (!isset($body['guests']) && isset($body['name'])) {
            $name = sanitize_text_field($body['name']);
            if ($name === '') {
                return new WP_Error('missing_name', 'Bitte einen Namen angeben.', ['status' => 400]);
            }
            $guests = get_post_meta($event_id, '_tix_guest_list', true);
            if (!is_array($guests)) $guests = [];
            $guest = [
                'id'               => wp_generate_uuid4(),
                'name'             => $name,
                'email'            => sanitize_email($body['email'] ?? ''),
                'plus'             => absint($body['plus'] ?? 0),
                'note'             => sanitize_text_field($body['note'] ?? ''),
                'checked_in'       => false,
                'checked_in_count' => 0,
                'checkin_time'     => '',
                'checkin_by'       => '',
                'added_by'         => wp_get_current_user()->user_login,
                'added_at'         => current_time('mysql'),
            ];
            $guests[] = $guest;
            update_post_meta($event_id, '_tix_guest_list', $guests);
            update_post_meta($event_id, '_tix_guest_list_enabled', '1');
            return rest_ensure_response([
                'ok'    => true,
                'guest' => self::guest_payload($event_id, $guest),
                'count' => count($guests),
            ]);
        }

        // ── Ganze Liste ersetzen ──
        $guests = $body['guests'] ?? null;
        if (!is_array($guests)) {
            return new WP_Error('invalid_data', 'Ungültige Daten.', ['status' => 400]);
        }

        $clean = [];
        foreach ($guests as $g) {
            $clean[] = [
                'id'               => sanitize_text_field($g['id'] ?? wp_generate_uuid4()),
                'name'             => sanitize_text_field($g['name'] ?? ''),
                'email'            => sanitize_email($g['email'] ?? ''),
                'plus'             => absint($g['plus'] ?? 0),
                'note'             => sanitize_text_field($g['note'] ?? ''),
                'checked_in'       => !empty($g['checked_in']),
                'checked_in_count' => absint($g['checked_in_count'] ?? 0),
                'checkin_time'     => sanitize_text_field($g['checkin_time'] ?? ''),
                'checkin_by'       => sanitize_text_field($g['checkin_by'] ?? ''),
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

    /** Gast-Eintrag im Format der Gästeliste-Antwort. */
    private static function guest_payload($event_id, array $g) {
        $total_expected = 1 + intval($g['plus'] ?? 0);
        if (!empty($g['checked_in']) && !isset($g['checked_in_count'])) {
            $checked_in_count = $total_expected;
        } else {
            $checked_in_count = intval($g['checked_in_count'] ?? 0);
        }
        return [
            'id'               => $g['id'] ?? '',
            'name'             => $g['name'] ?? '',
            'email'            => $g['email'] ?? '',
            'plus'             => intval($g['plus'] ?? 0),
            'note'             => $g['note'] ?? '',
            'checked_in'       => !empty($g['checked_in']),
            'checkin_time'     => $g['checkin_time'] ?? '',
            'checked_in_count' => $checked_in_count,
            'total_expected'   => $total_expected,
            'code'             => 'GL-' . $event_id . '-' . ($g['id'] ?? ''),
        ];
    }

    /**
     * POST|DELETE /events/{id}/guestlist/{guest_id} – Gast ändern oder löschen.
     * Löschen: HTTP DELETE oder POST mit `_method: DELETE` (App).
     */
    public static function update_guest(WP_REST_Request $req) {
        $event_id = absint($req['id']);
        $guest_id = sanitize_text_field($req['guest_id']);
        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }
        $body = $req->get_json_params();
        if (!is_array($body)) $body = [];
        $delete = $req->get_method() === 'DELETE' || strtoupper((string) ($body['_method'] ?? '')) === 'DELETE';

        $guests = get_post_meta($event_id, '_tix_guest_list', true);
        if (!is_array($guests)) $guests = [];

        foreach ($guests as $i => $g) {
            if (($g['id'] ?? '') !== $guest_id) continue;
            if ($delete) {
                array_splice($guests, $i, 1);
                update_post_meta($event_id, '_tix_guest_list', array_values($guests));
                return rest_ensure_response(['ok' => true, 'deleted' => $guest_id, 'count' => count($guests)]);
            }
            if (isset($body['name'])) {
                $name = sanitize_text_field($body['name']);
                if ($name === '') {
                    return new WP_Error('missing_name', 'Bitte einen Namen angeben.', ['status' => 400]);
                }
                $g['name'] = $name;
            }
            if (isset($body['email'])) $g['email'] = sanitize_email($body['email']);
            if (isset($body['note']))  $g['note']  = sanitize_text_field($body['note']);
            if (isset($body['plus'])) {
                $g['plus'] = absint($body['plus']);
                // Zähler darf nicht über die neue Personenzahl hinausgehen
                $max = 1 + $g['plus'];
                if (intval($g['checked_in_count'] ?? 0) > $max) $g['checked_in_count'] = $max;
            }
            $guests[$i] = $g;
            update_post_meta($event_id, '_tix_guest_list', $guests);
            return rest_ensure_response(['ok' => true, 'guest' => self::guest_payload($event_id, $g)]);
        }

        return new WP_Error('guest_not_found', 'Gast nicht gefunden.', ['status' => 404]);
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
                'order_id'      => intval(get_post_meta($t->ID, '_tix_ticket_order_id', true) ?: get_post_meta($t->ID, '_tix_order_id', true)),
                'order_number'  => class_exists('TIX_Order') ? TIX_Order::number_for(get_post_meta($t->ID, '_tix_ticket_order_id', true) ?: get_post_meta($t->ID, '_tix_order_id', true)) : null,
                'seat'          => get_post_meta($t->ID, '_tix_ticket_seat_id', true),
                'price'         => floatval(get_post_meta($t->ID, '_tix_ticket_price', true)),
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

        $order_id = intval(get_post_meta($ticket_id, '_tix_ticket_order_id', true) ?: get_post_meta($ticket_id, '_tix_order_id', true));
        $order    = ($order_id && class_exists('TIX_Order')) ? TIX_Order::get($order_id) : null;
        if (!$order) {
            return new WP_Error('no_order', 'Zu diesem Ticket gibt es keine Bestellung – E-Mail nicht möglich.', ['status' => 404]);
        }
        if (!class_exists('TIX_Emails') || !method_exists('TIX_Emails', 'send_native_completed')) {
            return new WP_Error('no_mail', 'E-Mail-Versand nicht verfügbar.', ['status' => 500]);
        }

        // Empfänger: Ticket-Inhaber, sonst Rechnungs-E-Mail der Bestellung
        $email = sanitize_email((string) get_post_meta($ticket_id, '_tix_ticket_owner_email', true));
        if (!is_email($email)) $email = sanitize_email((string) $order->get_billing_email());
        if (!is_email($email)) {
            return new WP_Error('no_email', 'Keine E-Mail-Adresse hinterlegt.', ['status' => 400]);
        }
        if (strcasecmp($email, (string) $order->get_billing_email()) !== 0) {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'tix_orders', ['billing_email' => $email], ['id' => $order_id]);
        }

        // Nur die Kunden-Mail, keine zweite Admin-Benachrichtigung
        set_transient('_tix_skip_admin_email_' . $order_id, 1, 300);
        delete_post_meta($order_id, '_tix_completed_email_sent');
        TIX_Emails::send_native_completed($order_id);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note($order_id, '🎟️ Tickets erneut gesendet (App) an ' . $email, 'email');
        }

        return rest_ensure_response([
            'ok'      => true,
            'email'   => $email,
            'message' => 'E-Mail gesendet an ' . $email,
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
        // Kein Fehler, wenn ein Event keine Kategorien hat: leere Liste (200).
        // Die App zeigt dann einen sauberen Hinweis statt einer Fehlermeldung.
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats)) {
            $cats = [];
        }
        $categories = [];
        foreach ($cats as $idx => $cat) {
            if (!is_array($cat) || !empty($cat['gift_card'])) continue;
            $n = self::category_numbers($event_id, $idx, $cat);
            $categories[] = [
                'index'              => intval($idx),
                'product_id'         => intval($idx), // kein WooCommerce: Index = Kennung
                'name'               => $cat['name'] ?? 'Ticket',
                'price'              => self::current_price($cat),
                'quantity_total'     => $n['total'],
                'quantity_sold'      => $n['sold'],
                'quantity_available' => $n['available'],
                'unlimited'          => $n['unlimited'],
                'sold_out'           => $n['sold_out'],
                // alte Feldnamen (Kompatibilität)
                'stock'              => $n['unlimited'] ? -1 : $n['available'],
                'sold'               => $n['sold'],
                'total'              => $n['total'],
            ];
        }
        return rest_ensure_response([
            'ok'          => true,
            'event_title' => get_the_title($event_id),
            'categories'  => $categories,
        ]);
    }

    /** Positionsname für die App: Kategorie („Standard“) statt „Event – Standard“. */
    private static function item_label($item) {
        $cat = method_exists($item, 'get_cat_name') ? trim((string) $item->get_cat_name()) : '';
        return $cat !== '' ? $cat : (string) $item->get_name();
    }

    /** POS-Zusatzdaten (Zahlart, Mitarbeiter) je Bestellung – Option statt WooCommerce-Meta. */
    private static function pos_meta($order_id, ?array $set = null) {
        $key = '_tix_pos_order_' . intval($order_id);
        if ($set !== null) {
            update_option($key, $set, false);
            return $set;
        }
        $v = get_option($key, []);
        return is_array($v) ? $v : [];
    }

    private static function pos_payment_label($payment) {
        $labels = ['cash' => 'Barzahlung (Kasse)', 'card' => 'EC-Karte (Kasse)', 'free' => 'Kostenlos (Kasse)'];
        return $labels[$payment] ?? $payment;
    }

    // ═══════════════════════════════════════════
    //  POS: ORDER ERSTELLEN
    // ═══════════════════════════════════════════

    public static function pos_create_order(WP_REST_Request $req) {
        $body = $req->get_json_params();
        if (!is_array($body)) $body = [];
        $event_id       = absint($body['event_id'] ?? 0);
        $items          = $body['items'] ?? [];
        $payment        = sanitize_key($body['payment'] ?? 'cash');
        $customer_name  = sanitize_text_field($body['customer_name'] ?? '');
        $customer_email = sanitize_email($body['customer_email'] ?? '');
        $coupon_code    = sanitize_text_field($body['coupon_code'] ?? '');
        // Entweder–oder (App-Kasse): Gast geht sofort rein → Tickets direkt einchecken, dann keine
        // Ticket-E-Mail; oder Tickets per E-Mail schicken → kein Check-in jetzt.
        $checkin        = filter_var($body['checkin'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($checkin) $customer_email = '';
        if (!$event_id || empty($items) || !is_array($items)) {
            return new WP_Error('missing_data', 'Event oder Artikel fehlen.', ['status' => 400]);
        }
        if (!self::can_access_event($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        }
        // Mehr-Veranstalter-Modus: Kasse erst nach Freigabe des Veranstalters
        if (class_exists('TIX_Org_Approval') && ($e = TIX_Org_Approval::sale_error($event_id))) {
            return $e;
        }
        if (!class_exists('TIX_Native_Checkout')) {
            return new WP_Error('no_native', 'Nativer Checkout nicht aktiv.', ['status' => 500]);
        }
        if (!in_array($payment, ['cash', 'card', 'free'], true)) $payment = 'cash';

        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats) || empty($cats)) {
            return new WP_Error('no_categories', 'Keine Ticket-Kategorien.', ['status' => 404]);
        }
        // Positionen bündeln (Kennung = Kategorie-Index, wie in pos_categories)
        $merged = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $idx = intval($item['index'] ?? $item['product_id'] ?? -1);
            $qty = intval($item['qty'] ?? $item['quantity'] ?? 0);
            if ($idx < 0 || $qty <= 0) continue;
            $merged[$idx] = ($merged[$idx] ?? 0) + $qty;
        }
        if (!$merged) return new WP_Error('missing_data', 'Keine gültigen Artikel.', ['status' => 400]);
        $event_title = get_the_title($event_id);
        $cart     = ['items' => [], 'coupon' => null];
        $subtotal = 0;
        foreach ($merged as $idx => $qty) {
            if (!isset($cats[$idx]) || !is_array($cats[$idx]) || !empty($cats[$idx]['gift_card'])) {
                return new WP_Error('category_not_found', 'Kategorie #' . $idx . ' nicht gefunden.', ['status' => 400]);
            }
            $cat   = $cats[$idx];
            $stock = (isset($cat['stock']) && $cat['stock'] !== '') ? intval($cat['stock']) : -1;
            if ($stock >= 0 && $qty > $stock) {
                return new WP_Error('out_of_stock', ($cat['name'] ?? 'Ticket') . ': Nur noch ' . $stock . ' verfügbar.', ['status' => 409]);
            }
            $price = $payment === 'free' ? 0.0 : floatval(self::current_price($cat));
            $cart['items'][] = [
                'event_id'    => $event_id,
                'cat_index'   => intval($idx),
                'name'        => sanitize_text_field($cat['name'] ?? 'Ticket'),
                'event_title' => $event_title,
                'price'       => $price,
                'qty'         => $qty,
                'meta'        => ['pos' => 1],
            ];
            $subtotal += $price * $qty;
        }
        // Gutschein-Code (Rabatt/Geschenkgutschein) wie in der App-Kasse; ungültig → 409 statt still ignorieren (1.38.484)
        if ($coupon_code !== '' && method_exists('TIX_Native_Checkout', 'app_prepare_cart')) {
            $cart = TIX_Native_Checkout::app_prepare_cart($cart, $coupon_code);
            if (empty($cart['coupon']['code'])) {
                return new WP_Error('tix_coupon', (string) (($cart['coupon_error'] ?? '') ?: 'Gutscheincode ungültig.'), ['status' => 409, 'field' => 'coupon_code']);
            }
        }
        $discount = round(floatval($cart['coupon']['discount'] ?? 0), 2);
        $total    = max(0, round($subtotal - $discount, 2));
        $coupon_info = self::pos_coupon_info($cart, $discount);
        if ($total <= 0) $payment = 'free';

        $name_parts = preg_split('/\s+/', trim($customer_name !== '' ? $customer_name : 'Kasse'), 2);
        $staff      = wp_get_current_user();

        // Kassenbon mit TSE über eine angebundene Kasse (Degaso, 1.38.557): Fehler = kein Verkauf, nie still ohne Bon
        $receipt = apply_filters('tix_pos_sign_sale', null, ['event_id' => $event_id, 'event_title' => $event_title, 'items' => $cart['items'],
            'subtotal' => round($subtotal, 2), 'discount' => $discount, 'total' => $total, 'payment' => $payment, 'coupon' => $coupon_info]);
        if (is_wp_error($receipt)) return $receipt;

        // Warenkorb in die Session des Mitarbeiters legen (create_order liest Gutschein/Gebühren daraus)
        $previous_cart = TIX_Native_Checkout::get_cart();
        TIX_Native_Checkout::save_cart($cart);
        $order_id = TIX_Native_Checkout::create_order([
            'billing_first_name' => $name_parts[0] ?? 'Kasse',
            'billing_last_name'  => $name_parts[1] ?? '',
            'billing_email'      => $customer_email,
            'billing_phone'      => '',
            'billing_company'    => '',
            'billing_address_1'  => '',
            'billing_city'       => '',
            'billing_postcode'   => '',
            'billing_country'    => 'DE',
            'payment_method'     => 'pos_' . $payment,
            'total'              => $subtotal,
            'items'              => $cart['items'],
        ]);
        if (!$order_id) {
            if (!empty($previous_cart['items'])) TIX_Native_Checkout::save_cart($previous_cart);
            else TIX_Native_Checkout::clear_cart();
            if ($receipt) do_action('tix_pos_sign_cancel', $receipt, 'order_failed'); // Bon zurücknehmen (1.38.557)
            return new WP_Error('order_failed', 'Bestellung konnte nicht angelegt werden.', ['status' => 500]);
        }
        update_option('_tix_order_source_' . $order_id, 'pos', false);
        if ($receipt) do_action('tix_pos_signed', $order_id, $receipt);
        self::pos_meta($order_id, array_filter([
            'payment'    => $payment,
            'staff_id'   => intval($staff->ID),
            'staff_name' => (string) $staff->display_name,
            'event_id'   => $event_id,
            'created'    => current_time('mysql'),
            'coupon'     => $coupon_info ? ['code' => $coupon_info['code'], 'type' => $coupon_info['type'], 'amount' => $coupon_info['amount']] : null,
        ], function ($v) { return $v !== null; }));
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note($order_id, '🧾 Kassenverkauf (App) – ' . self::pos_payment_label($payment) . ' durch ' . $staff->display_name, 'pos');
        }
        // Bezahlt → Tickets erzeugen (+ E-Mail, falls Adresse angegeben)
        TIX_Native_Checkout::update_order_status($order_id, 'completed', 'pos');
        // Web-Warenkorb des Mitarbeiters unangetastet lassen
        if (!empty($previous_cart['items'])) TIX_Native_Checkout::save_cart($previous_cart);
        $tickets = self::get_order_tickets($order_id, $event_id);
        // Direkt einchecken: Gast steht am Einlass und geht sofort rein
        if ($checkin && $tickets && class_exists('TIX_Tickets')) {
            $by = $staff->user_login ? $staff->user_login : 'pos';
            foreach ($tickets as $i => $t) {
                if (!TIX_Tickets::is_checked_in($t['id'])) self::mark_ticket_checked_in($t['id'], $by);
                $tickets[$i]['checked_in'] = true;
                $tickets[$i]['status']     = 'used';
            }
            $meta = self::pos_meta($order_id);
            $meta['checkin'] = current_time('mysql');
            self::pos_meta($order_id, $meta);
            if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
                TIX_Order_Admin::add_note($order_id, '✅ ' . count($tickets) . ' Ticket(s) direkt an der Kasse (App) eingecheckt durch ' . $staff->display_name, 'pos');
            }
        }
        $order = class_exists('TIX_Order') ? TIX_Order::get($order_id) : null;
        if ($coupon_info && $coupon_info['type'] === 'giftcard' && class_exists('TIX_Giftcards')) {
            $card = TIX_Giftcards::get_card($coupon_info['code']);
            $coupon_info['remaining'] = $card ? round(floatval($card['balance'] ?? 0), 2) : null;
        }
        return rest_ensure_response([
            'ok'           => true,
            'order_id'     => intval($order_id),
            'order_number' => $order ? $order->get_order_number() : (string) $order_id,
            'total'        => $order ? floatval($order->get_total()) : $total,
            'subtotal'     => round($subtotal, 2),
            'discount'     => $discount,
            'coupon'       => $coupon_info,
            'payment'      => $payment,
            'checked_in'   => (bool) $checkin,
            'tickets'      => $tickets,
        ] + (is_array($receipt) ? ['receipt' => ['provider' => (string) $receipt['provider'], 'cash' => (string) $receipt['cash'], 'order_id' => (string) $receipt['order_id']]] : []));
    }

    /**
     * Ticket als eingecheckt markieren (wie /checkin/ticket/{id}/toggle, inkl. optionaler Ticket-DB).
     */
    private static function mark_ticket_checked_in($ticket_id, $by) {
        TIX_Tickets::checkin_ticket($ticket_id, $by);
        try {
            $code = get_post_meta($ticket_id, '_tix_ticket_code', true);
            if ($code && class_exists('TIX_Ticket_DB') && class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled')) {
                TIX_Ticket_DB::update_ticket($code, [
                    'checked_in'      => 1,
                    'checkin_time'    => current_time('mysql'),
                    'ticket_status'   => 'used',
                    'synced_supabase' => 0,
                    'synced_airtable' => 0,
                ]);
            }
        } catch (\Throwable $e) {}
    }

    // ═══════════════════════════════════════════
    //  POS: E-MAIL SENDEN
    // ═══════════════════════════════════════════

    public static function pos_send_email(WP_REST_Request $req) {
        $order_id = absint($req['id']);
        $body     = $req->get_json_params();
        $email    = sanitize_email(is_array($body) ? ($body['email'] ?? '') : '');
        if (!$order_id || !is_email($email)) {
            return new WP_Error('missing_data', 'Bestellnummer oder E-Mail fehlt.', ['status' => 400]);
        }
        $order = class_exists('TIX_Order') ? TIX_Order::get($order_id) : null;
        if (!$order) {
            return new WP_Error('not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
        }
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && !TIX_App_Scope::order_allowed($order)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf diese Bestellung.', ['status' => 403]);
        }
        if (!class_exists('TIX_Emails') || !method_exists('TIX_Emails', 'send_native_completed')) {
            return new WP_Error('no_mail', 'E-Mail-Versand nicht verfügbar.', ['status' => 500]);
        }
        global $wpdb;
        if (strcasecmp($email, (string) $order->get_billing_email()) !== 0) {
            $wpdb->update($wpdb->prefix . 'tix_orders', ['billing_email' => $email], ['id' => $order_id]);
            foreach (self::get_order_tickets($order_id) as $t) {
                update_post_meta($t['id'], '_tix_ticket_owner_email', $email);
            }
        }
        set_transient('_tix_skip_admin_email_' . $order_id, 1, 300);
        delete_post_meta($order_id, '_tix_completed_email_sent');
        TIX_Emails::send_native_completed($order_id);
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note($order_id, '🎟️ Tickets per Kasse (App) gesendet an ' . $email, 'email');
        }
        return rest_ensure_response(['ok' => true, 'message' => 'E-Mail gesendet an ' . $email]);
    }

    // ═══════════════════════════════════════════
    //  POS: STORNO
    // ═══════════════════════════════════════════

    public static function pos_void_order(WP_REST_Request $req) {
        $order_id = absint($req['id']);
        $order = class_exists('TIX_Order') ? TIX_Order::get($order_id) : null;
        if (!$order) {
            return new WP_Error('not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
        }
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && !TIX_App_Scope::order_allowed($order)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf diese Bestellung.', ['status' => 403]);
        }
        if (strpos((string) $order->get_payment_method(), 'pos_') !== 0) {
            return new WP_Error('not_pos', 'Keine Kassen-Bestellung.', ['status' => 400]);
        }
        // „Kasse & Bar“: nur eigene Verkäufe von heute (1.38.484)
        if (self::is_cashier_only(wp_get_current_user()) && !self::pos_own_today($order)) {
            return new WP_Error('tix_cashier_scope', 'Du kannst nur deine eigenen Verkäufe von heute stornieren.', ['status' => 403]);
        }
        if ($order->get_status() === 'cancelled') {
            return rest_ensure_response(['ok' => true, 'message' => 'Bestellung war bereits storniert.']);
        }
        if (class_exists('TIX_Native_Checkout')) {
            TIX_Native_Checkout::update_order_status($order_id, 'cancelled', 'admin');
        }
        // Gutschein zurück: Geschenkgutschein bekommt den Betrag wieder gutgeschrieben, Rabatt-Code eine Nutzung (1.38.484)
        $restored = class_exists('TIX_Giftcards') && method_exists('TIX_Giftcards', 'restore_for_order') ? TIX_Giftcards::restore_for_order($order_id, 'pos') : null;
        // Tickets stornieren + Bestand zurückgeben
        $restock = [];
        foreach (self::get_order_tickets($order_id) as $t) {
            if ($t['status'] === 'cancelled') continue;
            update_post_meta($t['id'], '_tix_ticket_status', 'cancelled');
            $eid = intval(get_post_meta($t['id'], '_tix_ticket_event_id', true));
            $ci  = get_post_meta($t['id'], '_tix_ticket_cat_index', true);
            if ($eid && $ci !== '' && $ci !== false) {
                $restock[$eid][intval($ci)] = ($restock[$eid][intval($ci)] ?? 0) + 1;
            }
        }
        foreach ($restock as $eid => $by_cat) {
            wp_cache_delete($eid, 'post_meta');
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            if (!is_array($cats)) continue;
            foreach ($by_cat as $ci => $n) {
                if (isset($cats[$ci]['stock']) && $cats[$ci]['stock'] !== '' && intval($cats[$ci]['stock']) >= 0) {
                    $cats[$ci]['stock'] = intval($cats[$ci]['stock']) + $n;
                }
            }
            update_post_meta($eid, '_tix_ticket_categories', $cats);
        }
        if (class_exists('TIX_Order_Admin') && method_exists('TIX_Order_Admin', 'add_note')) {
            TIX_Order_Admin::add_note($order_id, '↩️ Kassen-Storno (App) durch ' . wp_get_current_user()->display_name, 'pos');
        }
        do_action('tix_pos_voided', $order_id); // Storno-Bon an der angebundenen Kasse (Degaso, 1.38.557)
        return rest_ensure_response(['ok' => true, 'message' => 'Bestellung storniert.', 'coupon_restored' => $restored]);
    }

    /** Kassen-Bestellung des aktuellen Nutzers von heute? */
    private static function pos_own_today($order) {
        $meta = self::pos_meta($order->get_id());
        $created = $order->get_date_created();
        return intval($meta['staff_id'] ?? 0) === get_current_user_id() && $created && $created->format('Y-m-d') === current_time('Y-m-d');
    }

    /** Gutschein im Kassen-Warenkorb → {code, type, amount} oder null. */
    private static function pos_coupon_info(array $cart, $discount) {
        if (empty($cart['coupon']['code']) || $discount <= 0) return null;
        return ['code' => (string) $cart['coupon']['code'], 'type' => !empty($cart['coupon']['giftcard']) ? 'giftcard' : 'discount', 'amount' => round(floatval($discount), 2)];
    }

    /**
     * POST /pos/quote {event_id, items[{index, qty}], coupon_code?} → {ok, subtotal, discount, total, coupon {code, type, amount, balance, remaining}|null}
     * Vorschau vor dem Kassieren; Gutschein ungültig → 409 tix_coupon. Bucht nichts.
     */
    public static function pos_quote(WP_REST_Request $req) {
        $body = $req->get_json_params();
        if (!is_array($body)) $body = [];
        $event_id = absint($body['event_id'] ?? 0);
        $items    = is_array($body['items'] ?? null) ? $body['items'] : [];
        $code     = sanitize_text_field((string) ($body['coupon_code'] ?? ''));
        $free     = sanitize_key((string) ($body['payment'] ?? '')) === 'free';
        if (!$event_id || !$items) return new WP_Error('missing_data', 'Event oder Artikel fehlen.', ['status' => 400]);
        if (!self::can_access_event($event_id)) return new WP_Error('forbidden', 'Kein Zugriff.', ['status' => 403]);
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats) || !$cats) return new WP_Error('no_categories', 'Keine Ticket-Kategorien.', ['status' => 404]);
        $cart = ['items' => [], 'coupon' => null];
        $subtotal = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $idx = intval($item['index'] ?? $item['product_id'] ?? -1);
            $qty = intval($item['qty'] ?? $item['quantity'] ?? 0);
            if ($idx < 0 || $qty <= 0) continue;
            if (!isset($cats[$idx]) || !is_array($cats[$idx]) || !empty($cats[$idx]['gift_card'])) {
                return new WP_Error('category_not_found', 'Kategorie #' . $idx . ' nicht gefunden.', ['status' => 400]);
            }
            $price = $free ? 0.0 : floatval(self::current_price($cats[$idx]));
            $cart['items'][] = ['event_id' => $event_id, 'cat_index' => $idx, 'name' => (string) ($cats[$idx]['name'] ?? 'Ticket'), 'price' => $price, 'qty' => $qty, 'meta' => ['pos' => 1]];
            $subtotal += $price * $qty;
        }
        if (!$cart['items']) return new WP_Error('missing_data', 'Keine gültigen Artikel.', ['status' => 400]);
        $coupon = null;
        $discount = 0.0;
        if ($code !== '' && method_exists('TIX_Native_Checkout', 'app_prepare_cart')) {
            $cart = TIX_Native_Checkout::app_prepare_cart($cart, $code);
            if (empty($cart['coupon']['code'])) return new WP_Error('tix_coupon', (string) (($cart['coupon_error'] ?? '') ?: 'Gutscheincode ungültig.'), ['status' => 409, 'field' => 'coupon_code']);
            $discount = round(floatval($cart['coupon']['discount'] ?? 0), 2);
            $coupon = self::pos_coupon_info($cart, $discount);
            if ($coupon && $coupon['type'] === 'giftcard' && class_exists('TIX_Giftcards')) {
                $card = TIX_Giftcards::get_card($coupon['code']);
                $bal = $card ? round(floatval($card['balance'] ?? 0), 2) : 0.0;
                $coupon['balance'] = $bal;
                $coupon['remaining'] = round(max(0, $bal - $discount), 2);
            }
        }
        return rest_ensure_response(['ok' => true, 'subtotal' => round($subtotal, 2), 'discount' => $discount, 'total' => max(0, round($subtotal - $discount, 2)), 'coupon' => $coupon]);
    }

    /** Kassen-Bestellungen eines Tages (nativ, payment_method pos_*). */
    private static function pos_orders_for_day($date, array $statuses, $event_id = 0) {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $params = array_merge([$date], $statuses);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $t WHERE payment_method LIKE 'pos\\_%' AND DATE(date_created) = %s AND status IN ($placeholders) ORDER BY date_created DESC",
            ...$params
        ), ARRAY_A);
        $orders = [];
        $scoped = class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped();
        foreach ((array) $rows as $r) {
            $o = TIX_Order::get(intval($r['id']));
            if (!$o) continue;
            if ($scoped && !TIX_App_Scope::order_allowed($o)) continue;
            if ($event_id) {
                $meta = self::pos_meta($o->get_id());
                if (intval($meta['event_id'] ?? 0) !== intval($event_id)) continue;
            }
            $orders[] = $o;
        }
        return $orders;
    }

    // ═══════════════════════════════════════════
    //  POS: TAGESBERICHT
    // ═══════════════════════════════════════════

    public static function pos_report(WP_REST_Request $req) {
        $date     = sanitize_text_field($req->get_param('date') ?: current_time('Y-m-d'));
        $event_id = absint($req->get_param('event_id'));
        if ($event_id && class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && !TIX_App_Scope::event_allowed($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }
        $report = [
            'date'          => $date,
            'total_revenue' => 0.0,
            'total_tickets' => 0,
            'total_orders'  => 0,
            'by_payment'    => ['cash' => 0.0, 'card' => 0.0, 'free' => 0.0],
            'by_category'   => [],
            'by_hour'       => [],
            'cancelled'     => 0,
            // Gutscheine (1.38.484): giftcard = mit Geschenkgutschein bezahlt (zusätzlich zum Umsatz), discount = Rabatt-Codes
            'vouchers'      => ['giftcard' => 0.0, 'discount' => 0.0, 'orders' => 0],
            'gross_revenue' => 0.0,
        ];
        if (!class_exists('TIX_Order')) {
            foreach (['by_payment', 'by_category', 'by_hour'] as $k) $report[$k] = (object) $report[$k];
            return rest_ensure_response(['ok' => true, 'report' => $report]);
        }
        foreach (self::pos_orders_for_day($date, ['completed', 'processing'], $event_id) as $order) {
            $total   = floatval($order->get_total());
            $payment = substr((string) $order->get_payment_method(), 4) ?: 'cash';
            $created = $order->get_date_created();
            $hour    = $created ? $created->format('H') : '00';
            $report['total_revenue'] += $total;
            $report['total_orders']++;
            $pc = self::pos_meta($order->get_id())['coupon'] ?? null;
            if (is_array($pc) && floatval($pc['amount'] ?? 0) > 0) {
                $k = ($pc['type'] ?? '') === 'giftcard' ? 'giftcard' : 'discount';
                $report['vouchers'][$k] = round($report['vouchers'][$k] + floatval($pc['amount']), 2);
                $report['vouchers']['orders']++;
            }
            $report['by_payment'][$payment] = round(($report['by_payment'][$payment] ?? 0) + $total, 2);
            if (!isset($report['by_hour'][$hour])) $report['by_hour'][$hour] = ['revenue' => 0.0, 'tickets' => 0];
            foreach ($order->get_items() as $item) {
                $qty        = $item->get_quantity();
                $cat_name   = self::item_label($item);
                $item_total = floatval($item->get_total());
                $report['total_tickets'] += $qty;
                $report['by_hour'][$hour]['revenue'] = round($report['by_hour'][$hour]['revenue'] + $item_total, 2);
                $report['by_hour'][$hour]['tickets'] += $qty;
                if (!isset($report['by_category'][$cat_name])) $report['by_category'][$cat_name] = ['tickets' => 0, 'revenue' => 0.0];
                $report['by_category'][$cat_name]['tickets'] += $qty;
                $report['by_category'][$cat_name]['revenue'] = round($report['by_category'][$cat_name]['revenue'] + $item_total, 2);
            }
        }
        $report['cancelled']     = count(self::pos_orders_for_day($date, ['cancelled', 'refunded'], $event_id));
        $report['total_revenue'] = round($report['total_revenue'], 2);
        $report['gross_revenue'] = round($report['total_revenue'] + $report['vouchers']['giftcard'], 2);
        ksort($report['by_hour']);
        // Maps immer als JSON-Objekt (leer → {} statt [])
        foreach (['by_payment', 'by_category', 'by_hour'] as $k) $report[$k] = (object) $report[$k];
        return rest_ensure_response(['ok' => true, 'report' => $report]);
    }

    // ═══════════════════════════════════════════
    //  POS: TRANSAKTIONEN
    // ═══════════════════════════════════════════

    public static function pos_transactions(WP_REST_Request $req) {
        $date = sanitize_text_field($req->get_param('date') ?: current_time('Y-m-d'));
        $event_id = absint($req->get_param('event_id'));
        if ($event_id && class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && !TIX_App_Scope::event_allowed($event_id)) {
            return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        }
        // „Kasse & Bar“: nur eigene Verkäufe von heute (1.38.484)
        $cashier = self::is_cashier_only(wp_get_current_user());
        if ($cashier && $date !== current_time('Y-m-d')) {
            return new WP_Error('tix_cashier_scope', 'Du siehst nur deine Verkäufe von heute.', ['status' => 403, 'field' => 'date']);
        }
        $transactions = [];
        if (class_exists('TIX_Order')) {
            foreach (self::pos_orders_for_day($date, ['completed', 'processing', 'cancelled', 'refunded'], $event_id) as $order) {
                if ($cashier && !self::pos_own_today($order)) continue;
                $items_list   = [];
                $ticket_count = 0;
                foreach ($order->get_items() as $item) {
                    $items_list[]  = $item->get_quantity() . '× ' . self::item_label($item);
                    $ticket_count += $item->get_quantity();
                }
                $meta     = self::pos_meta($order->get_id());
                $payment  = substr((string) $order->get_payment_method(), 4) ?: 'cash';
                $created  = $order->get_date_created();
                $customer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                $transactions[] = [
                    'order_id'      => $order->get_id(),
                    'order_number'  => $order->get_order_number(),
                    'time'          => $created ? $created->format('H:i') : '',
                    'items'         => implode(', ', $items_list),
                    'tickets'       => $ticket_count,
                    'total'         => floatval($order->get_total()),
                    'payment'       => $payment,
                    'payment_label' => self::pos_payment_label($payment),
                    'customer'      => $customer === 'Kasse' ? '' : $customer,
                    'email'         => (string) $order->get_billing_email(),
                    'status'        => (string) $order->get_status(),
                    'staff'         => (string) ($meta['staff_name'] ?? ''),
                    'staff_id'      => intval($meta['staff_id'] ?? 0) ?: null,
                    'event_id'      => intval($meta['event_id'] ?? 0) ?: null,
                    'coupon'        => is_array($meta['coupon'] ?? null) ? $meta['coupon'] : null,
                    // Darf der Aufrufer stornieren? (Kasse & Bar: eigene von heute, sonst alle offenen)
                    'can_void'      => in_array($order->get_status(), ['completed', 'processing'], true) && (!$cashier || self::pos_own_today($order)),
                ];
            }
        }
        return rest_ensure_response(['ok' => true, 'date' => $date, 'event_id' => $event_id ?: null, 'scope' => $cashier ? 'own' : 'all', 'transactions' => $transactions]);
    }

    // ═══════════════════════════════════════════
    //  HILFSFUNKTIONEN
    // ═══════════════════════════════════════════

    /**
     * Event-Daten für API-Response formatieren.
     */
    /**
     * Tickets eines Events aus den Ticket-Posts zählen (gesamt, eingecheckt,
     * je Kategorie-Index). Stornierte zählen nicht. Kein WooCommerce.
     */
    public static function ticket_counts($event_id) {
        static $cache = [];
        $event_id = intval($event_id);
        if (isset($cache[$event_id])) return $cache[$event_id];
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, st.meta_value AS status, ci.meta_value AS cat_index, ch.meta_value AS checked
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID AND ev.meta_key = '_tix_ticket_event_id' AND ev.meta_value = %s
             LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_tix_ticket_status'
             LEFT JOIN {$wpdb->postmeta} ci ON ci.post_id = p.ID AND ci.meta_key = '_tix_ticket_cat_index'
             LEFT JOIN {$wpdb->postmeta} ch ON ch.post_id = p.ID AND ch.meta_key = '_tix_ticket_checked_in'
             WHERE p.post_type = 'tix_ticket' AND p.post_status IN ('publish', 'private')",
            (string) $event_id
        ), ARRAY_A);
        $out = ['total' => 0, 'checked' => 0, 'by_cat' => []];
        foreach ((array) $rows as $r) {
            $status = (string) ($r['status'] ?: 'valid');
            if ($status === 'cancelled') continue;
            $out['total']++;
            if ($status === 'used' || (string) $r['checked'] === '1') $out['checked']++;
            $ci = ($r['cat_index'] === null || $r['cat_index'] === '') ? -1 : intval($r['cat_index']);
            $out['by_cat'][$ci] = ($out['by_cat'][$ci] ?? 0) + 1;
        }
        return $cache[$event_id] = $out;
    }

    /** Kategorie-Zahlen ohne WooCommerce: Bestand aus `stock`, verkauft aus Ticket-Posts. */
    private static function category_numbers($event_id, $index, array $cat) {
        $counts    = self::ticket_counts($event_id);
        $qty_total = absint($cat['quantity'] ?? $cat['qty'] ?? 0);
        $stock     = (isset($cat['stock']) && $cat['stock'] !== '') ? intval($cat['stock']) : -1; // -1 = unbegrenzt
        $qty_sold  = intval($counts['by_cat'][intval($index)] ?? 0);
        if ($stock >= 0) {
            $qty_avail = $stock;
            if (!$qty_total) $qty_total = $qty_sold + $stock;
            $unlimited = false;
        } elseif ($qty_total > 0) {
            $qty_avail = max(0, $qty_total - $qty_sold);
            $unlimited = false;
        } else {
            $qty_avail = 999;
            $unlimited = true;
        }
        return [
            'total'     => $qty_total,
            'sold'      => $qty_sold,
            'available' => $qty_avail,
            'unlimited' => $unlimited,
            'sold_out'  => !$unlimited && $qty_avail <= 0,
        ];
    }

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
                if (!is_array($cat) || !empty($cat['gift_card'])) continue; // Gutscheine sind keine Tickets
                $n = self::category_numbers($id, $i, $cat);
                $categories[] = [
                    'index'              => intval($i),
                    'name'               => $cat['name'] ?? '',
                    'price'              => self::current_price($cat),
                    'product_id'         => intval($i), // kein WooCommerce: Index = Kennung
                    'quantity_total'     => $n['total'],
                    'quantity_sold'      => $n['sold'],
                    'quantity_available' => $n['available'],
                    'unlimited'          => $n['unlimited'],
                    'sold_out'           => $n['sold_out'],
                ];
                $total_capacity  += $n['total'];
                if (!$n['unlimited']) $total_available += $n['available'];
                $total_sold      += $n['sold'];
            }
        }

        // Check-in-Zahlen (Gästeliste + Tickets) – auch in der Liste, damit
        // Dashboard und Event-Zeilen den Stand zeigen; Tickets aus ticket_counts()
        $gl_total   = 0;
        $gl_checked = 0;
        $guests = get_post_meta($id, '_tix_guest_list', true);
        if (is_array($guests)) {
            foreach ($guests as $g) {
                $expected = 1 + intval($g['plus'] ?? 0);
                $gl_total += $expected;
                $done = isset($g['checked_in_count']) ? intval($g['checked_in_count']) : (empty($g['checked_in']) ? 0 : $expected);
                $gl_checked += min($done, $expected);
            }
        }
        $counts = self::ticket_counts($id);
        $checkin_stats = [
            'total'      => $gl_total + $counts['total'],
            'checked_in' => $gl_checked + $counts['checked'],
            'guests'     => $gl_total,
            'tickets'    => $counts['total'],
        ];

        $event = [
            'id'               => $id,
            'title'            => $post->post_title,
            'excerpt'          => wp_strip_all_tags($post->post_excerpt),
            'thumbnail'        => $thumbnail,
            // 1.38.457: Archiv (TIX_Archive)
            'archived'         => (bool) get_post_meta($id, '_tix_archived', true),
            'archived_at'      => (string) get_post_meta($id, '_tix_archived_at', true) ?: null,
            // 1.38.456: Bild groß (large) und klein (medium) für Listen
            'image'            => $thumb_id ? (string) (wp_get_attachment_image_url($thumb_id, 'large') ?: '') : '',
            'image_small'      => $thumb_id ? (string) (wp_get_attachment_image_url($thumb_id, 'medium') ?: '') : '',
            'date_start'       => $date_start,
            'date_end'         => $date_end,
            'time_start'       => $time_start,
            'time_end'         => $time_end,
            'time_doors'       => $time_doors,
            'date_formatted'   => $date_start ? date_i18n('l, d. F Y', strtotime($date_start)) : '',
            'location'         => $location_name,
            'status'           => $status,
            // Veröffentlichung wartet auf die Freigabe des Veranstalters (TIX_Org_Approval)
            'post_status'      => (string) $post->post_status,
            'publish_held'     => get_post_meta($id, '_tix_publish_held', true) !== '',
            'categories'       => $categories,
            'total_capacity'   => $total_capacity,
            'total_available'  => $total_available,
            'total_sold'       => $total_sold,
            'has_guest_list'   => (bool) get_post_meta($id, '_tix_guest_list_enabled', true),
            'has_checkin_password' => !empty(get_post_meta($id, '_tix_checkin_password', true)),
            // Studio (1.38.464, alle Seiten, auch Entwürfe): Ticket-Art + Bericht vorhanden
            'tickets_enabled'  => get_post_meta($id, '_tix_tickets_enabled', true) === '1',
            'ticket_mode'      => (class_exists('TIX_Register_Event') ? TIX_Register_Event::ticket_mode_of($id) : '') ?: 'none',
            'free_entry'       => get_post_meta($id, '_tix_free_entry', true) === '1' && get_post_meta($id, '_tix_tickets_enabled', true) !== '1',
            'has_report'       => class_exists('TIX_Event_Report') && method_exists('TIX_Event_Report', 'has_presale') && TIX_Event_Report::has_presale($id),
        ];

        if ($checkin_stats) {
            $event['checkin_stats'] = $checkin_stats;
        }

        // Mehr-Veranstalter-Modus (evendis): großes Bild, Sichtbarkeit, Vorverkauf
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            $event['image']           = $thumb_id ? (wp_get_attachment_image_url($thumb_id, 'large') ?: '') : '';
            $event['post_status']     = (string) $post->post_status;
            $event['presale_active']  = (bool) get_post_meta($id, '_tix_presale_active', true);
        }
        // Teilen aus dem Veranstalter-Bereich: Event-Link mit Herkunft „veranstalter-link“ (evendis)
        if (class_exists('TIX_Organizer_Link') && TIX_Organizer_Link::multi()) {
            $event['share_url'] = TIX_Organizer_Link::share_url($id);
        } else {
            // Einzel-Veranstalter-Seite (1.38.453): eigene Event-Seite mit Herkunft (zählt in /organizer/link-stats), nur veröffentlicht
            $u = (string) get_permalink($id);
            if (class_exists('TIX_Customer_URL')) $u = TIX_Customer_URL::to($u);
            $event['share_url'] = $post->post_status === 'publish' && $u !== '' ? add_query_arg(['utm_source' => 'veranstalter-link'], $u) : '';
            // Auf evendis.de geteilt: Adresse der Event-Seite dort (merkt sich der Push aus der Antwort der Plattform)
            $syn_url = (string) get_post_meta($id, '_tix_syndicate_url', true);
            $event['evendis_url'] = get_post_meta($id, '_tix_syndicate', true) === '1' && get_post_meta($id, '_tix_syndicate_status', true) === 'synced'
                && get_post_meta($id, '_tix_syndicate_remote_id', true) && $syn_url !== '' ? $syn_url : null;
        }
        // Platzhalter wie /public/events (1.38.513): Ursprungsseite (geteilt) bzw. Veranstalter, sonst Seite; auch für Entwürfe
        $event['placeholder'] = (class_exists('TIX_Studio') && method_exists('TIX_Studio', 'event_placeholder') && ($ph = TIX_Studio::event_placeholder($id))) ? ['url' => $ph['url'], 'url_small' => $ph['url_small']] : null;
        // Demo Club (1.38.508)
        $event['demo'] = class_exists('TIX_Sales') && TIX_Sales::is_demo_organizer(intval(get_post_meta($id, '_tix_organizer_id', true)));
        // KI-Schutz (1.38.505): {flagged, reason, checked_at, approved}
        $event['guard'] = class_exists('TIX_Content_Guard') && method_exists('TIX_Content_Guard', 'guard_info') ? TIX_Content_Guard::guard_info($id) : null;

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
        $tickets = [];
        $posts = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [['key' => '_tix_ticket_order_id', 'value' => (string) intval($order_id)]],
        ]);
        foreach ($posts as $tp) {
            $eid = intval(get_post_meta($tp->ID, '_tix_ticket_event_id', true));
            $tickets[] = [
                'id'       => $tp->ID,
                'code'     => (string) get_post_meta($tp->ID, '_tix_ticket_code', true),
                'event'    => $eid ? get_the_title($eid) : (string) get_post_meta($tp->ID, '_tix_ticket_event_name', true),
                'category' => (string) (get_post_meta($tp->ID, '_tix_ticket_cat_name', true) ?: 'Ticket'),
                'price'    => floatval(get_post_meta($tp->ID, '_tix_ticket_price', true)),
                'status'   => (string) (get_post_meta($tp->ID, '_tix_ticket_status', true) ?: 'valid'),
                'checked_in' => (bool) get_post_meta($tp->ID, '_tix_ticket_checked_in', true),
            ];
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
        // E-Mail (Gäste) oder WordPress-Benutzername (Veranstalter/Mitarbeiter)
        $login    = trim((string) ($req->get_param('email') ?: $req->get_param('username') ?: ''));
        $password = $req->get_param('password');

        if ($login === '' || empty($password)) {
            return new WP_Error('missing_fields', 'E-Mail und Passwort sind erforderlich.', ['status' => 400]);
        }
        // Sperre nach Fehlversuchen je Besucher-IP und Konto (nicht von fail2ban überwacht)
        if (class_exists('TIX_Customer_Auth') && TIX_Customer_Auth::login_blocked($login)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Versuche. Bitte warte ein paar Minuten und versuche es dann erneut.', ['status' => 429]);
        }

        $user = false;
        if (is_email($login)) {
            $user = get_user_by('email', sanitize_email($login));
        }
        if (!$user) {
            // Fallback: Username
            $user = get_user_by('login', sanitize_user($login, true));
        }

        if (!$user) {
            if (class_exists('TIX_Customer_Auth')) TIX_Customer_Auth::login_failed($login);
            return new WP_Error('invalid_credentials', 'Ungültige E-Mail oder Passwort.', ['status' => 401]);
        }

        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            if (class_exists('TIX_Customer_Auth')) TIX_Customer_Auth::login_failed($login);
            return new WP_Error('invalid_credentials', 'Ungültige E-Mail oder Passwort.', ['status' => 401]);
        }
        if (class_exists('TIX_App_Account') && get_user_meta($user->ID, TIX_App_Account::META_UNVERIFIED, true)) {
            // Registrierung noch nicht bestätigt – die App holt sich einen neuen Code (purpose=verify)
            return new WP_Error('account_unverified', 'Bitte bestätige zuerst deine E-Mail-Adresse. Dafür bekommst du einen Code per E-Mail.', ['status' => 403, 'email' => $user->user_email]);
        }

        // Geräte-Token (90 Tage) – weitere Geräte bleiben angemeldet
        $token = self::issue_app_token($user->ID, (string) ($req->get_param('device') ?? ''));

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
        update_user_meta($user_id, '_tix_app_created', current_time('mysql'));
        $verify = filter_var($req->get_param('verify'), FILTER_VALIDATE_BOOLEAN);
        if ($verify && class_exists('TIX_App_Account')) {
            // Neue Apps (ab 1.38.323): Konto erst nach Bestätigung der E-Mail-Adresse aktiv –
            // der Code kommt per Mail und wird in der App eingegeben (POST /auth/code/confirm, purpose=verify).
            update_user_meta($user_id, TIX_App_Account::META_UNVERIFIED, 1);
            TIX_App_Account::send_verification_code($email);
            return rest_ensure_response([
                'success'               => true,
                'verification_required' => true,
                'email'                 => $email,
                'expires_in'            => TIX_App_Account::CODE_TTL,
            ]);
        }
        // Ältere Apps: sofort angemeldet. Willkommens-Mail ohne „Passwort festlegen“-Link –
        // das Passwort wurde gerade in der App vergeben.
        self::send_welcome_email($user);
        // Geräte-Token (90 Tage)
        $token = self::issue_app_token($user_id, (string) ($req->get_param('device') ?? ''));
        return rest_ensure_response([
            'success' => true,
            'token'   => $token,
            'user'    => self::format_guest_user($user),
        ]);
    }

    /** Willkommens-Mail nach der App-Registrierung (kein Passwort-Link, das Passwort ist gesetzt). */
    private static function send_welcome_email(WP_User $user) {
        $site = get_bloginfo('name');
        $body  = '<p>Hallo ' . esc_html($user->first_name ?: $user->display_name) . ',</p>';
        $body .= '<p>willkommen bei <strong>' . esc_html($site) . '</strong>! Dein Konto ist eingerichtet.</p>';
        $body .= '<p>Du meldest dich in der App mit <strong>' . esc_html($user->user_email) . '</strong> und dem Passwort an, das du gerade festgelegt hast.</p>';
        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html('Willkommen!', $body, 'Dein Konto wurde erstellt.')
            : '<html><body>' . $body . '</body></html>';
        TIX_Customer_URL::mail($user->user_email, 'Willkommen bei ' . $site, $html, ['Content-Type: text/html; charset=UTF-8']);
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

            // Rechnungsadresse aus dem Profil (Felder wie in der Kasse)

            $billing = $req->get_param('billing');

            if (is_array($billing) && class_exists('TIX_App_Checkout')) {

                TIX_App_Checkout::save_billing($user, $billing);

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
     *
     * Speichert das Bild als einfache Datei unter uploads/tix-avatars/ –
     * bewusst NICHT als Attachment in der Mediathek (Kundenbilder sollen dort
     * nicht auftauchen). Bild wird auf 600 px verkleinert, EXIF-Daten fallen weg.
     */
    public static function auth_profile_avatar(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $files = $req->get_file_params();

        if (empty($files['avatar']) || empty($files['avatar']['tmp_name'])) {
            return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file = $files['avatar'];
        if (!empty($file['error'])) {
            return new WP_Error('upload_failed', 'Upload fehlgeschlagen.', ['status' => 400]);
        }
        if (intval($file['size'] ?? 0) > 10 * MB_IN_BYTES) {
            return new WP_Error('too_large', 'Das Bild ist zu groß (max. 10 MB).', ['status' => 400]);
        }
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'] ?? 'avatar.jpg');
        $mime  = $check['type'] ?: (string) ($file['type'] ?? '');
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) {
            return new WP_Error('bad_type', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.', ['status' => 400]);
        }

        $paths = self::avatar_dir();
        if (!$paths) {
            return new WP_Error('upload_failed', 'Bild konnte nicht gespeichert werden.', ['status' => 500]);
        }

        // Name nicht erratbar (User-ID + Zufall); alte Datei entfernen
        self::delete_avatar_file($user->ID, $paths['dir']);
        $name   = $user->ID . '-' . wp_generate_password(12, false) . '.' . $allowed[$mime];
        $target = $paths['dir'] . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], $target) && !@rename($file['tmp_name'], $target)) {
            return new WP_Error('upload_failed', 'Bild konnte nicht gespeichert werden.', ['status' => 500]);
        }
        @chmod($target, 0644);

        // Verkleinern (max. 600 px) – schneidet zugleich Metadaten ab
        $editor = wp_get_image_editor($target);
        if (!is_wp_error($editor)) {
            $editor->resize(600, 600, false);
            $editor->set_quality(85);
            $editor->save($target, $mime);
        }

        // Früherer Stand: Attachment in der Mediathek → jetzt entfernen
        $old_id = intval(get_user_meta($user->ID, '_tix_avatar_id', true));
        if ($old_id) {
            wp_delete_attachment($old_id, true);
            delete_user_meta($user->ID, '_tix_avatar_id');
        }

        update_user_meta($user->ID, '_tix_avatar_file', $name);
        update_user_meta($user->ID, '_tix_avatar_url', $paths['url'] . '/' . $name . '?v=' . time());

        $user = get_user_by('ID', $user->ID);

        return rest_ensure_response([
            'success' => true,
            'user'    => self::format_guest_user($user),
        ]);
    }

    /** Ordner für Profilbilder (außerhalb der Mediathek): uploads/tix-avatars */
    private static function avatar_dir() {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) return null;
        $dir = trailingslashit($uploads['basedir']) . 'tix-avatars';
        $url = trailingslashit($uploads['baseurl']) . 'tix-avatars';
        if (!wp_mkdir_p($dir)) return null;
        if (!file_exists($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        return ['dir' => $dir, 'url' => $url];
    }

    private static function delete_avatar_file($user_id, $dir) {
        $old = basename((string) get_user_meta($user_id, '_tix_avatar_file', true));
        if ($old !== '' && file_exists($dir . '/' . $old)) {
            @unlink($dir . '/' . $old);
        }
    }

    /**
     * Einmalige Migration: Profilbild, das früher als Attachment in der
     * Mediathek lag, in den tix-avatars-Ordner kopieren und das Attachment
     * löschen. Läuft lazy beim nächsten Profil-Abruf des Nutzers.
     */
    private static function migrate_avatar_from_library($user_id) {
        $old_id = intval(get_user_meta($user_id, '_tix_avatar_id', true));
        if (!$old_id) return;
        $paths = self::avatar_dir();
        $src   = $paths ? get_attached_file($old_id) : '';
        if ($src && file_exists($src)) {
            $ext  = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'jpg';
            $name = $user_id . '-' . wp_generate_password(12, false) . '.' . $ext;
            if (@copy($src, $paths['dir'] . '/' . $name)) {
                self::delete_avatar_file($user_id, $paths['dir']);
                update_user_meta($user_id, '_tix_avatar_file', $name);
                update_user_meta($user_id, '_tix_avatar_url', $paths['url'] . '/' . $name);
            }
        }
        wp_delete_attachment($old_id, true);
        delete_user_meta($user_id, '_tix_avatar_id');
    }

    /**
     * GET /customer/tickets – Tickets des eingeloggten Kunden.
     */
    public static function customer_tickets(WP_REST_Request $req) {
        $user = wp_get_current_user();
        // Zuordnung per E-Mail nur mit bestätigter E-Mail (sonst nur im Konto gekaufte Tickets)
        $trusted = class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : (string) $user->user_email;
        // Ticket-Posts (CPT) sind die Quelle der Wahrheit – kitchenklub.de hat keine Ticket-Tabelle
        $tickets = class_exists('TIX_App_Checkout') ? TIX_App_Checkout::tickets_for_user($user) : [];
        if (empty($tickets) && $trusted !== '') {
            // Aus Ticket-DB
            global $wpdb;
            $table = $wpdb->prefix . 'tix_tickets';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE buyer_email = %s ORDER BY id DESC",
                    $trusted
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
                        // Flyer groß (1.38.532, vorher medium = 300 px), klein für Listen
                        'event_image' => $event_id ? get_the_post_thumbnail_url($event_id, 'large') : '',
                        'event_image_small' => $event_id ? get_the_post_thumbnail_url($event_id, 'medium') : '',
                        'category'    => $row['category_name'] ?? '',
                        'seat'        => $row['seat_id'] ?? '',
                        'status'      => $row['ticket_status'] ?? 'valid',
                        'checked_in'  => !empty($row['checked_in']),
                        'checkin_time' => $row['checkin_time'] ?? '',
                        'order_id'    => intval($row['order_id'] ?? 0),
                        'order_number' => class_exists('TIX_Order') ? TIX_Order::number_for($row['order_id'] ?? 0) : null,
                        'price'       => floatval($row['ticket_price'] ?? 0),
                        'purchased'   => $row['created_at'] ?? '',
                    ] + (class_exists('TIX_App_Checkout') ? TIX_App_Checkout::gift_fields(intval($row['ticket_post_id'] ?? 0)) : []);
                }
            }
        }
        // Sortierung: neueste zuerst
        usort($tickets, fn($a, $b) => strcmp($b['purchased'] ?? '', $a['purchased'] ?? ''));

        $out = [
            'success' => true,
            'tickets' => $tickets,
            'count'   => count($tickets),
        ];
        if ($trusted === '') {
            $out['email_confirmed'] = false;
            $out['notice'] = TIX_App_Account::NOTICE_UNCONFIRMED;
        }
        return rest_ensure_response($out);
    }

    /**
     * GET /customer/events – Kommende Events des Kunden (basierend auf Tickets).
     */
    public static function customer_events(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $event_ids = [];
        $trusted = class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : (string) $user->user_email;
        if (class_exists('TIX_App_Checkout')) {
            foreach (TIX_App_Checkout::tickets_for_user($user) as $t) {
                if (!empty($t['event_id'])) $event_ids[intval($t['event_id'])] = true;
            }
        }
        // Fallback: Ticket-Tabelle (nur mit bestätigter E-Mail)
        if (empty($event_ids) && $trusted !== '') {
            global $wpdb;
            $table = $wpdb->prefix . 'tix_tickets';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT DISTINCT event_id FROM {$table} WHERE buyer_email = %s",
                    $trusted
                ), ARRAY_A);
                foreach ($rows as $row) {
                    $eid = intval($row['event_id'] ?? 0);
                    if ($eid) $event_ids[$eid] = true;
                }
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
                'image'      => get_the_post_thumbnail_url($event_id, 'large'),
                'image_small' => get_the_post_thumbnail_url($event_id, 'medium'),
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
    public static function format_guest_user(WP_User $user) {
        self::migrate_avatar_from_library($user->ID);
        $avatar_url = get_user_meta($user->ID, '_tix_avatar_url', true);
        if (empty($avatar_url)) {
            $avatar_url = get_avatar_url($user->ID, ['size' => 96]);
        }
        $avatar_large = get_user_meta($user->ID, '_tix_avatar_url', true);
        if (empty($avatar_large)) {
            $avatar_large = get_avatar_url($user->ID, ['size' => 300]);
        }

        // Ticket-Statistiken (Ticket-Posts zuerst, sonst Ticket-Tabelle)
        $tickets_count = 0;
        $upcoming_events = 0;
        $rows = class_exists('TIX_App_Checkout') ? TIX_App_Checkout::tickets_for_user($user) : [];
        $trusted = class_exists('TIX_App_Account') ? TIX_App_Account::trusted_email($user) : (string) $user->user_email;
        global $wpdb;
        $table = $wpdb->prefix . 'tix_tickets';
        if (empty($rows) && $trusted !== '' && $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT event_id FROM {$table} WHERE buyer_email = %s",
                $trusted
            ), ARRAY_A);
        }
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
            // false = Konto wurde per E-Mail-Code angelegt und hat noch kein eigenes Passwort
            'has_password'    => !get_user_meta($user->ID, '_tix_app_passwordless', true),
            // Rechnungsadresse (Profil + Kasse)
            'billing'         => class_exists('TIX_App_Checkout') ? TIX_App_Checkout::billing_prefill($user) : null,
        ];
    }

    /** Öffentlicher Zugriff auf das Kunden-Profil-Format (für TIX_App_Checkout / TIX_App_Account). */
    public static function guest_user_payload(WP_User $user) {
        return self::format_guest_user($user);
    }

    // ═══════════════════════════════════════════
    //  NATIVE ORDERS
    // ═══════════════════════════════════════════

    /**
     * GET /orders – list native orders (wc_order_id = 0).
     */
    public static function get_orders(WP_REST_Request $req) {
        if (!class_exists('TIX_Order')) {
            return rest_ensure_response(['ok' => true, 'orders' => []]);
        }

        $args = [
            'limit' => absint($req->get_param('per_page') ?: 50),
        ];

        $status = $req->get_param('status');
        if ($status) {
            $args['status'] = array_map('sanitize_text_field', explode(',', $status));
        }

        $email = sanitize_email($req->get_param('email') ?: '');
        if ($email) {
            $args['email'] = $email;
        }

        $event_id = absint($req->get_param('event_id') ?: 0);
        if ($event_id) {
            $args['event_id'] = $event_id;
        }

        // Mehr-Veranstalter-Modus: nur Veranstalter-Team, nur eigene Bestellungen
        $scoped = class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped();
        if ($scoped) {
            $perm = self::check_organizer($req);
            if (is_wp_error($perm)) return $perm;
            if ($event_id && !TIX_App_Scope::event_allowed($event_id)) {
                return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
            }
        }

        $orders = TIX_Order::query($args);
        $out = [];

        foreach ($orders as $order) {
            // Only return native orders (wc_order_id = 0)
            $wc_id = method_exists($order, 'get_wc_order_id') ? $order->get_wc_order_id() : 0;
            if ($wc_id > 0) continue;
            if ($scoped && !TIX_App_Scope::order_allowed($order)) continue;

            $out[] = self::format_native_order($order);
        }

        return rest_ensure_response(['ok' => true, 'orders' => $out]);
    }

    /**
     * GET /orders/{id} – single native order.
     */
    public static function get_order(WP_REST_Request $req) {
        if (!class_exists('TIX_Order')) {
            return new WP_Error('not_found', 'Native Orders nicht verfügbar.', ['status' => 404]);
        }

        $order = TIX_Order::get(absint($req['id']));
        if (!$order) {
            return new WP_Error('not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
        }
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped()) {
            $perm = self::check_organizer($req);
            if (is_wp_error($perm)) return $perm;
            if (!TIX_App_Scope::order_allowed($order)) {
                return new WP_Error('forbidden', 'Kein Zugriff auf diese Bestellung.', ['status' => 403]);
            }
        }

        return rest_ensure_response(['ok' => true, 'order' => self::format_native_order($order)]);
    }

    /**
     * Format a TIX_Order for JSON response.
     */
    private static function format_native_order($order) {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'name'       => $item->get_name(),
                'quantity'   => $item->get_quantity(),
                'total'      => (float) $item->get_total(),
                'event_id'   => method_exists($item, 'get_event_id') ? $item->get_event_id() : 0,
                'product_id' => method_exists($item, 'get_product_id') ? $item->get_product_id() : 0,
            ];
        }

        return [
            'id'             => $order->get_id(),
            'order_number'   => method_exists($order, 'get_order_number') ? $order->get_order_number() : $order->get_id(),
            'status'         => $order->get_status(),
            'total'          => (float) $order->get_total(),
            'currency'       => method_exists($order, 'get_currency') ? $order->get_currency() : 'EUR',
            'billing_email'  => $order->get_billing_email(),
            'billing_name'   => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'date_created'   => method_exists($order, 'get_date_created') && $order->get_date_created()
                ? $order->get_date_created()->format('c') : '',
            'payment_method' => method_exists($order, 'get_payment_method') ? $order->get_payment_method() : '',
            'wc_order_id'    => method_exists($order, 'get_wc_order_id') ? $order->get_wc_order_id() : 0,
            'items'          => $items,
        ];
    }
}
