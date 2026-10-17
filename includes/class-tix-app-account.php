<?php
/**
 * App-Konto & Service für die KitchenKlub-App (REST, Namespace tixomat/v1).
 *
 * Alles läuft nativ in der App – keine Website-Umleitungen:
 *   POST /auth/code                    Einmal-Code per E-Mail anfordern (login | reset)
 *   POST /auth/code/confirm            Code bestätigen → Geräte-Token (Login ohne Passwort,
 *                                      Gäste ohne Konto bekommen automatisch ein Konto;
 *                                      bei „reset“ wird das neue Passwort gesetzt)
 *                                      `context: staff` (evendis Studio): nur vorhandene Veranstalter-/
 *                                      Team-/Admin-Konten, nie ein neues Konto
 *   POST /auth/password                Passwort ändern (altes Passwort nötig, außer das
 *                                      Konto wurde per Code angelegt und hat noch keins)
 *   GET  /customer/tickets/{id}/pdf    Ticket als PDF (Template des Events, sonst generisch)
 *   GET  /support/config               Support aktiv? + Kategorien + responsible (öffentlich, ?event_id)
 *   POST /customer/support             Support-Anfrage stellen (auch ohne Konto)
 *   GET  /customer/support             eigene Anfragen (Konto-E-Mail)
 *   GET  /customer/support/{id}        Verlauf einer Anfrage
 *   POST /customer/support/{id}/reply  Antwort des Kunden
 *
 * Codes: 6 Ziffern, 15 Minuten gültig, 5 Versuche, Rate-Limit je IP und E-Mail.
 */
if (!defined('ABSPATH')) exit;

class TIX_App_Account {

    const NS             = 'tixomat/v1';
    const CODE_TTL       = 15 * MINUTE_IN_SECONDS;
    const CODE_MAX_TRIES = 5;
    const META_PASSWORDLESS = '_tix_app_passwordless';
    /** Konto per App registriert, E-Mail-Adresse noch nicht bestätigt (seit 1.38.323). */
    const META_UNVERIFIED = '_tix_app_unverified';
    const META_EMAIL_CONFIRMED = '_tix_email_confirmed';

    /** Hat das Konto seine aktuelle E-Mail-Adresse per Code bestätigt? */
    public static function email_confirmed($user) {
        if (!$user || empty($user->ID)) return false;
        $c = (string) get_user_meta($user->ID, self::META_EMAIL_CONFIRMED, true);
        return $c !== '' && strcasecmp($c, (string) $user->user_email) === 0;
    }

    /** Zeitpunkt der Einführung von email_trusted() – ältere Konten mit eigenem Kauf gelten als bestätigt. */
    const OPTION_TRUST_SINCE = 'tix_email_trust_since';
    const NOTICE_UNCONFIRMED = 'Bestätige deine E-Mail-Adresse (Anmeldung per Code oder Passwort zurücksetzen), damit wir dir auch Tickets und Anfragen zeigen, die nicht in diesem Konto gekauft wurden.';

    private static $trusted = [];

    /**
     * Darf das Konto Daten sehen, die nur über die E-Mail-Adresse zugeordnet sind (Tickets,
     * Gastbestellungen, Support-Anfragen)? Ja, wenn die E-Mail bestätigt ist (Code, Passwort-Reset
     * per Mail, Magic Link) oder das Konto schon vor OPTION_TRUST_SINCE bestand und mindestens
     * eine eigene Bestellung hat (dann wird die Bestätigung gespeichert). Sonst nur Eigenes.
     */
    public static function email_trusted($user) {
        if (!$user || empty($user->ID)) return false;
        $id = intval($user->ID);
        if (!empty(self::$trusted[$id])) return true;
        if (self::email_confirmed($user)) return self::$trusted[$id] = true;
        $since = intval(get_option(self::OPTION_TRUST_SINCE, 0));
        $registered = strtotime((string) $user->user_registered . ' UTC');
        if ($since > 0 && $registered && $registered < $since && self::has_own_order($id, (string) $user->user_email)) {
            update_user_meta($id, self::META_EMAIL_CONFIRMED, (string) $user->user_email);
            update_user_meta($id, '_tix_email_confirmed_by', 'legacy_order');
            return self::$trusted[$id] = true;
        }
        return false; // „nein“ nicht merken – eine Bestätigung kann noch in derselben Anfrage folgen
    }

    /** Platzhalter für Abfragen per E-Mail, der zu keiner gespeicherten Adresse passt (kein @). */
    const NO_EMAIL_MATCH = '~nicht-bestaetigt~';

    /** E-Mail für Abfragen „per E-Mail“: bestätigte Adresse oder NO_EMAIL_MATCH. */
    public static function match_email($user) {
        $e = self::trusted_email($user);
        return $e !== '' ? $e : self::NO_EMAIL_MATCH;
    }

    /** E-Mail des Kontos für E-Mail-Zuordnungen, '' wenn nicht bestätigt. */
    public static function trusted_email($user) {
        return self::email_trusted($user) ? (string) $user->user_email : '';
    }

    /** Bestätigung speichern (Beweis über die E-Mail: Code, Reset-Link, Magic Link). */
    public static function mark_confirmed($user, $by = '') {
        if (!$user || empty($user->ID)) return;
        update_user_meta($user->ID, self::META_EMAIL_CONFIRMED, (string) $user->user_email);
        if ($by !== '') update_user_meta($user->ID, '_tix_email_confirmed_by', $by);
        unset(self::$trusted[intval($user->ID)]);
    }

    /** Mindestens eine bezahlte Bestellung, die im Konto selbst gekauft wurde (customer_id). */
    private static function has_own_order($user_id, $email) {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return false;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t} WHERE customer_id = %d AND billing_email = %s AND status IN ('completed','processing','refunded') LIMIT 1",
            intval($user_id), (string) $email
        ));
    }

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Stichtag einmalig beim ersten Laden nach dem Update setzen
        if (get_option(self::OPTION_TRUST_SINCE, false) === false) add_option(self::OPTION_TRUST_SINCE, time(), '', false);
        // Passwort per Reset-Link (Mail) gesetzt → E-Mail bewiesen
        add_action('after_password_reset', function ($user) { TIX_App_Account::mark_confirmed($user, 'password_reset'); }, 10, 1);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/auth/code', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'request_code'],
            'permission_callback' => '__return_true',
        ]);
        // Magic Link ohne WordPress-Seite (1.38.459): {token} aus ?tix_mt_token= → Kunden-Token + Profil
        register_rest_route(self::NS, '/auth/magic', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'magic_login'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/auth/code/confirm', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'confirm_code'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/auth/password', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'change_password'],
            'permission_callback' => [__CLASS__, 'check_customer'],
        ]);
        // Konto endgültig löschen (Apple-Pflicht 5.1.1(v) bei App-Konten)
        register_rest_route(self::NS, '/auth/delete', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'delete_account'],
            'permission_callback' => [__CLASS__, 'check_customer'],
        ]);
        register_rest_route(self::NS, '/customer/tickets/(?P<id>\d+)/pdf', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'ticket_pdf'],
            'permission_callback' => [__CLASS__, 'check_customer'],
        ]);
        register_rest_route(self::NS, '/support/config', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'support_config'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/customer/support', [
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'support_create'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'support_list'],
                'permission_callback' => [__CLASS__, 'check_customer'],
            ],
        ]);
        register_rest_route(self::NS, '/customer/support/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'support_detail'],
            'permission_callback' => [__CLASS__, 'check_customer'],
        ]);
        register_rest_route(self::NS, '/customer/support/(?P<id>\d+)/reply', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'support_reply'],
            'permission_callback' => [__CLASS__, 'check_customer'],
        ]);
        // Gäste mit dem Schlüssel aus der Support-Mail (?tix_sp_ticket=…&tix_sp_key=…), ohne Konto
        register_rest_route(self::NS, '/public/support/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'support_guest_detail'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/public/support/(?P<id>\d+)/reply', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'support_guest_reply'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function check_customer(WP_REST_Request $req) {
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'check_authenticated')) {
            return TIX_REST_API::check_authenticated($req);
        }
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        }
        return true;
    }

    // ──────────────────────────────────────────
    //  Hilfen
    // ──────────────────────────────────────────

    private static function error($code, $message, $status = 400, array $extra = []) {
        return new WP_Error($code, $message, ['status' => $status] + $extra);
    }

    public static function client_ip() {
        // Proxy-Kopfzeilen nur von vertrauenswürdigen Proxys (TIX_Client_IP)
        $ip = TIX_Client_IP::get();
        return $ip !== '' ? $ip : '0.0.0.0';
    }

    /** Rate-Limit (REST-tauglich, liefert bool). Schlüssel: IP oder eigener Wert. */
    public static function rate_limited($bucket, $max, $window, $key = '') {
        $key  = 'tix_app_rl_' . $bucket . '_' . md5($key !== '' ? (string) $key : self::client_ip());
        $data = get_transient($key);
        if (!is_array($data) || time() - intval($data['first']) >= $window) {
            $data = ['count' => 0, 'first' => time()];
        }
        $data['count']++;
        set_transient($key, $data, $window);
        return $data['count'] > $max;
    }

    /** Gibt es Tickets/Bestellungen zu dieser E-Mail (Gast ohne Konto)? */
    private static function email_has_orders($email) {
        global $wpdb;
        $table = $wpdb->prefix . 'tix_tickets';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $n = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE buyer_email = %s", $email));
            if (intval($n) > 0) return true;
        }
        $orders = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$orders}'") === $orders) {
            $n = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$orders} WHERE billing_email = %s AND status IN ('completed','processing')", $email
            ));
            if (intval($n) > 0) return true;
        }
        if (function_exists('wc_get_orders')) {
            $wc = wc_get_orders(['billing_email' => $email, 'limit' => 1, 'status' => ['wc-completed', 'wc-processing'], 'return' => 'ids']);
            if (!empty($wc)) return true;
        }
        return false;
    }

    /** Name aus der letzten Bestellung (für automatisch angelegte Konten). */
    private static function name_from_orders($email) {
        global $wpdb;
        $orders = $wpdb->prefix . 'tix_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$orders}'") === $orders) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT billing_first_name, billing_last_name FROM {$orders} WHERE billing_email = %s ORDER BY id DESC LIMIT 1", $email
            ));
            if ($row) return [(string) $row->billing_first_name, (string) $row->billing_last_name];
        }
        return ['', ''];
    }

    private static function code_key($email, $purpose) {
        return 'tix_app_code_' . md5(strtolower($email) . '|' . $purpose);
    }

    private static function code_hash($code) {
        return hash_hmac('sha256', (string) $code, wp_salt('auth'));
    }

    private static function user_payload(WP_User $user) {
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'guest_user_payload')) {
            return TIX_REST_API::guest_user_payload($user);
        }
        return ['id' => $user->ID, 'email' => $user->user_email, 'display_name' => $user->display_name];
    }

    private static function issue_token($user_id, WP_REST_Request $req) {
        $device = (string) ($req->get_param('device') ?? '');
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'issue_app_token')) {
            return TIX_REST_API::issue_app_token($user_id, $device);
        }
        return '';
    }

    /** Alle anderen Geräte-Tokens entwerten (nach Passwort-Änderung/-Reset). */
    private static function revoke_other_tokens($user_id, WP_REST_Request $req) {
        $current = (string) $req->get_header('x-tix-token');
        $keep    = $current !== '' ? hash('sha256', $current) : '';
        $list    = get_user_meta($user_id, '_tix_app_tokens', true);
        $list    = is_array($list) ? $list : [];
        $list    = array_values(array_filter($list, function ($t) use ($keep) {
            return $keep !== '' && is_array($t) && ($t['token'] ?? '') === $keep;
        }));
        if ($list) update_user_meta($user_id, '_tix_app_tokens', $list);
        else delete_user_meta($user_id, '_tix_app_tokens');
        delete_user_meta($user_id, '_tix_app_token');
    }

    /** Kundenkonto ohne Passwort anlegen (nach bestätigtem E-Mail-Code). */
    private static function create_passwordless_user($email) {
        list($first, $last) = self::name_from_orders($email);
        if (class_exists('TIX_Native_Checkout') && method_exists('TIX_Native_Checkout', 'generate_username')) {
            $username = TIX_Native_Checkout::generate_username($first, $last, $email);
        } else {
            $base = sanitize_user(strtolower(explode('@', $email)[0]), true) ?: 'kunde';
            $username = $base;
            $i = 1;
            while (username_exists($username)) { $username = $base . $i++; }
        }
        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password(32, true, true),
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first . ' ' . $last) ?: $username,
            'role'         => 'subscriber',
        ]);
        if (is_wp_error($user_id)) return $user_id;
        if (class_exists('TIX_Customer_Role')) TIX_Customer_Role::assign_to_user($user_id);
        update_user_meta($user_id, self::META_PASSWORDLESS, 1);
        update_user_meta($user_id, '_tix_app_created', current_time('mysql'));
        return get_user_by('ID', $user_id);
    }

    /** Zweck eines Einmal-Codes: login (Standard), reset, verify (Konto bestätigen, seit 1.38.323). */
    private static function normalize_purpose($raw) {
        $p = (string) $raw;
        return in_array($p, ['reset', 'verify'], true) ? $p : 'login';
    }

    /** Code erzeugen, als Transient merken und per E-Mail schicken. */
    private static function issue_code($email, $purpose, $has_account) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        set_transient(self::code_key($email, $purpose), [
            'hash'    => self::code_hash($code),
            'tries'   => 0,
            'created' => time(),
        ], self::CODE_TTL);
        return self::send_code_email($email, $code, $purpose, $has_account);
    }

    /** Bestätigungscode nach der Registrierung (wird von POST /auth/register aufgerufen). */
    public static function send_verification_code($email) {
        return self::issue_code(sanitize_email($email), 'verify', true);
    }

    private static function send_code_email($email, $code, $purpose, $has_account) {
        $site = get_bloginfo('name');
        $login  = ($purpose === 'login');
        $verify = ($purpose === 'verify');
        if ($verify) {
            $subject = 'Bestätige dein Konto – ' . $site;
            $intro   = 'schön, dass du dabei bist! Mit diesem Code bestätigst du dein neues Konto in der App – danach ist es aktiv:';
        } else {
            $subject = $login
                ? 'Dein Anmeldecode – ' . $site
                : 'Dein Code zum Zurücksetzen des Passworts – ' . $site;
            $intro = $login
                ? ($has_account
                    ? 'mit diesem Code meldest du dich ohne Passwort in der App an:'
                    : 'mit diesem Code siehst du deine Tickets in der App – ein Konto legen wir dabei automatisch für dich an:')
                : 'mit diesem Code legst du in der App ein neues Passwort fest:';
        }
        $m = ['title' => $verify ? 'Konto bestätigen' : ($login ? 'Dein Anmeldecode' : 'Passwort zurücksetzen'), 'subtitle' => $site . ' App', 'kind' => 'account',
              'preheader' => 'Dein Code: ' . $code . ' – gültig für 15 Minuten.',
              'blocks' => [
                  ['type' => 'text', 'html' => 'Hallo,<br>' . $intro],
                  ['type' => 'code', 'value' => (string) $code, 'hint' => 'Gültig für 15 Minuten – gib ihn in der App ein, du musst dafür keinen Link öffnen.'],
                  ['type' => 'notice', 'tone' => 'info', 'html' => 'Falls du diesen Code nicht angefordert hast, kannst du diese E-Mail ignorieren.'],
              ]];
        $html = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::render($m) : '<html><body><p>' . esc_html($code) . '</p></body></html>';
        return TIX_Customer_URL::mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ──────────────────────────────────────────
    //  Einmal-Codes
    // ──────────────────────────────────────────

    /** `context: staff` – nur vorhandene Veranstalter-, Team- oder Admin-Konten (evendis Studio). */
    private static function staff_context(WP_REST_Request $req) {
        return sanitize_key((string) $req->get_param('context')) === 'staff';
    }

    /** Admin, Veranstalter oder Team-Konto (App-Rollen bzw. einem Veranstalter zugeordnet)? */
    public static function is_staff_account($user) {
        if (!$user || empty($user->ID)) return false;
        if (user_can($user, 'manage_options')) return true;
        if (array_intersect((array) $user->roles, ['tix_organizer', 'tix_staff', 'tix_entrance', 'tix_dj', 'tix_awareness', 'tix_cashier'])) return true;
        return class_exists('TIX_App_Scope') && (bool) TIX_App_Scope::organizer_post_for_user($user->ID);
    }

    private static function staff_error() {
        return self::error('tix_staff_not_found', 'Zu dieser E-Mail-Adresse gibt es kein Veranstalter- oder Team-Konto. Neu hier? Dann registriere dich unter „Veranstalter werden“.', 404);
    }

    /** POST /auth/code */
    public static function request_code(WP_REST_Request $req) {
        $email   = sanitize_email((string) $req->get_param('email'));
        $purpose = self::normalize_purpose($req->get_param('purpose'));
        if (!is_email($email)) return self::error('invalid_email', 'Bitte eine gültige E-Mail-Adresse eingeben.');
        if (self::rate_limited('code_ip', 10, 600)) {
            return self::error('tix_rate_limit', 'Zu viele Anfragen. Bitte in ein paar Minuten erneut versuchen.', 429);
        }
        if (self::rate_limited('code_mail', 3, 600, strtolower($email))) {
            return self::error('tix_rate_limit', 'Für diese E-Mail-Adresse wurden gerade schon Codes verschickt. Bitte kurz warten und den Posteingang prüfen.', 429);
        }
        $user = get_user_by('email', $email);
        if (self::staff_context($req) && !self::is_staff_account($user)) return self::staff_error();
        if ($purpose === 'reset' && !$user) {
            return self::error('not_found', 'Zu dieser E-Mail-Adresse gibt es kein Konto.', 404);
        }
        if ($purpose === 'login' && !$user && !self::email_has_orders($email)) {
            return self::error('not_found', 'Wir konnten weder ein Konto noch eine Bestellung mit dieser E-Mail-Adresse finden.', 404);
        }
        if ($purpose === 'verify') {
            if (!$user) return self::error('not_found', 'Zu dieser E-Mail-Adresse gibt es kein Konto.', 404);
            if (!get_user_meta($user->ID, self::META_UNVERIFIED, true)) {
                return self::error('already_verified', 'Dieses Konto ist schon bestätigt – du kannst dich einfach anmelden.', 409);
            }
        }
        self::issue_code($email, $purpose, (bool) $user);
        return rest_ensure_response([
            'ok'          => true,
            'email'       => $email,
            'purpose'     => $purpose,
            'has_account' => (bool) $user,
            'expires_in'  => self::CODE_TTL,
        ]);
    }

    /** POST /auth/code/confirm */
    /**
     * POST /auth/magic {token, device?} – Link aus der Mail „Meine Tickets“ (?tix_mt_token=) einlösen, einmalig.
     * Vorhandenes Kundenkonto → angemeldet (E-Mail gilt als bestätigt); sonst neues Konto ohne Passwort (wie der Code-Weg).
     * Personal-Konten nicht (normal anmelden). → {ok, success, token, user, created}
     */
    public static function magic_login(WP_REST_Request $req) {
        if (self::rate_limited('magic_ip', 20, 600)) return self::error('tix_rate_limit', 'Zu viele Versuche. Bitte später erneut versuchen.', 429);
        $token = (string) $req->get_param('token');
        if (!preg_match('/^[a-f0-9]{64}$/i', $token) || !class_exists('TIX_My_Tickets')) return self::error('tix_magic_invalid', 'Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.', 400);
        $email = TIX_My_Tickets::consume_guest_token($token);
        if (!$email) return self::error('tix_magic_invalid', 'Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.', 400);
        $user = get_user_by('email', $email);
        if ($user && self::is_staff_account($user)) return self::error('tix_magic_staff', 'Bitte melde dich mit deinem Passwort an.', 403);
        $created = false;
        if (!$user) {
            $user = self::create_passwordless_user($email);
            if (is_wp_error($user)) return self::error('registration_failed', $user->get_error_message(), 500);
            $created = true;
        }
        self::mark_confirmed($user, 'magic_link');
        $user = get_user_by('ID', $user->ID);
        return rest_ensure_response(['ok' => true, 'success' => true, 'token' => self::issue_token($user->ID, $req), 'user' => self::user_payload($user), 'created' => $created]);
    }

    public static function confirm_code(WP_REST_Request $req) {
        $email   = sanitize_email((string) $req->get_param('email'));
        $purpose = self::normalize_purpose($req->get_param('purpose'));
        $code    = preg_replace('/\D/', '', (string) $req->get_param('code'));
        if (!is_email($email) || strlen($code) !== 6) {
            return self::error('code_invalid', 'Bitte den 6-stelligen Code aus der E-Mail eingeben.');
        }
        if (self::rate_limited('confirm_ip', 20, 600)) {
            return self::error('tix_rate_limit', 'Zu viele Versuche. Bitte später erneut versuchen.', 429);
        }
        $key  = self::code_key($email, $purpose);
        $data = get_transient($key);
        if (!is_array($data) || empty($data['hash'])) {
            return self::error('code_expired', 'Der Code ist abgelaufen – bitte einen neuen anfordern.');
        }
        $data['tries'] = intval($data['tries'] ?? 0) + 1;
        if ($data['tries'] > self::CODE_MAX_TRIES) {
            delete_transient($key);
            return self::error('code_expired', 'Zu viele Fehlversuche – bitte einen neuen Code anfordern.');
        }
        if (!hash_equals((string) $data['hash'], self::code_hash($code))) {
            $remaining = max(0, self::CODE_TTL - (time() - intval($data['created'] ?? time())));
            set_transient($key, $data, max(60, $remaining));
            return self::error('code_invalid', 'Der Code ist nicht korrekt.');
        }
        delete_transient($key);

        $user    = get_user_by('email', $email);
        $created = false;
        // Studio: kein neues Kundenkonto, keine Rollenänderung – nur vorhandene Staff-Konten
        if (self::staff_context($req) && !self::is_staff_account($user)) return self::staff_error();
        if ($purpose === 'verify' && !$user) {
            return self::error('not_found', 'Zu dieser E-Mail-Adresse gibt es kein Konto.', 404);
        }
        if ($purpose === 'reset') {
            if (!$user) return self::error('not_found', 'Zu dieser E-Mail-Adresse gibt es kein Konto.', 404);
            $password = (string) $req->get_param('new_password');
            if (strlen($password) < 8) {
                return self::error('weak_password', 'Das neue Passwort muss mindestens 8 Zeichen lang sein.', 400, ['field' => 'new_password']);
            }
            $res = wp_update_user(['ID' => $user->ID, 'user_pass' => $password]);
            if (is_wp_error($res)) return self::error('update_failed', $res->get_error_message(), 500);
            delete_user_meta($user->ID, self::META_PASSWORDLESS);
            // Andere Geräte abmelden – das Konto wurde gerade neu gesichert
            delete_user_meta($user->ID, '_tix_app_tokens');
            delete_user_meta($user->ID, '_tix_app_token');
        } elseif (!$user) {
            $user = self::create_passwordless_user($email);
            if (is_wp_error($user)) return self::error('registration_failed', $user->get_error_message(), 500);
            $created = true;
        }
        // Der Code beweist die E-Mail-Adresse: ein frisch registriertes Konto ist damit bestätigt
        // (Merker `_tix_email_confirmed`: frühere Gastbestellungen dieser E-Mail in GET /customer/orders)
        self::mark_confirmed($user, 'code');
        $verified = (bool) get_user_meta($user->ID, self::META_UNVERIFIED, true);
        if ($verified) {
            delete_user_meta($user->ID, self::META_UNVERIFIED);
            update_user_meta($user->ID, '_tix_app_verified', current_time('mysql'));
        }
        $user  = get_user_by('ID', $user->ID);
        $token = self::issue_token($user->ID, $req);
        return rest_ensure_response([
            'ok'       => true,
            'success'  => true,
            'token'    => $token,
            'user'     => self::user_payload($user),
            'created'  => $created,
            'verified' => $verified,
        ]);
    }

    // ──────────────────────────────────────────
    //  Passwort ändern
    // ──────────────────────────────────────────

    /** POST /auth/password */
    public static function change_password(WP_REST_Request $req) {
        $user = wp_get_current_user();
        if (self::rate_limited('password', 10, 600, 'u' . $user->ID)) {
            return self::error('tix_rate_limit', 'Zu viele Versuche. Bitte später erneut versuchen.', 429);
        }
        $current = (string) $req->get_param('current_password');
        $new     = (string) $req->get_param('new_password');
        $passwordless = (bool) get_user_meta($user->ID, self::META_PASSWORDLESS, true);
        if (!$passwordless) {
            if ($current === '' || !wp_check_password($current, $user->user_pass, $user->ID)) {
                return self::error('wrong_password', 'Das aktuelle Passwort ist nicht korrekt.', 400, ['field' => 'current_password']);
            }
        }
        if (strlen($new) < 8) {
            return self::error('weak_password', 'Das neue Passwort muss mindestens 8 Zeichen lang sein.', 400, ['field' => 'new_password']);
        }
        if (!$passwordless && $new === $current) {
            return self::error('same_password', 'Das neue Passwort muss sich vom aktuellen unterscheiden.', 400, ['field' => 'new_password']);
        }
        $res = wp_update_user(['ID' => $user->ID, 'user_pass' => $new]);
        if (is_wp_error($res)) return self::error('update_failed', $res->get_error_message(), 500);
        delete_user_meta($user->ID, self::META_PASSWORDLESS);
        self::revoke_other_tokens($user->ID, $req);
        $user = get_user_by('ID', $user->ID);
        return rest_ensure_response([
            'ok'      => true,
            'success' => true,
            'user'    => self::user_payload($user),
        ]);
    }

    /**
     * Konto endgültig löschen (in der App bestätigt).
     * Entfernt den WordPress-Nutzer inkl. aller User-Meta, Profilbild und
     * App-Tokens. Bestellungen/Tickets sind an die E-Mail gebunden und bleiben
     * aus buchhalterischen/gesetzlichen Gründen erhalten (kein Login mehr möglich).
     * Mitarbeiter-/Veranstalter-/Admin-Konten sind hier gesperrt.
     */
    public static function delete_account(WP_REST_Request $req) {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return self::error('rest_not_logged_in', 'Authentifizierung erforderlich.', 401);
        }
        if (self::rate_limited('delete', 5, 600, 'u' . $user->ID)) {
            return self::error('tix_rate_limit', 'Zu viele Versuche. Bitte später erneut versuchen.', 429);
        }
        // Personal-/Veranstalter-/Admin-Konten nicht über die App löschbar.
        $protected = ['administrator', 'tix_organizer', 'tix_staff', 'tix_dj', 'tix_entrance'];
        if (array_intersect($protected, (array) $user->roles)) {
            return self::error('tix_delete_forbidden', 'Dieses Konto kann nicht in der App gelöscht werden. Bitte wende dich an den Support.', 403);
        }
        // Gemeinsamer Kern mit der Website (1.38.460): Bestellungen und Tickets anonymisieren (Codes bleiben gültig), Konto löschen
        $res = TIX_Account_Delete::delete($user->ID, 'app');
        if (is_wp_error($res)) return $res;
        // Bezahltes Restguthaben: Support-Anfrage zur Auszahlung (1.38.499) → {id, amount, retain_until} | null
        return rest_ensure_response(['ok' => true, 'deleted' => true, 'wallet_payout_request' => $res['wallet_payout_request'] ?? null]);
    }

    // ──────────────────────────────────────────
    //  Ticket-PDF
    // ──────────────────────────────────────────

    /** Ticket-Post-ID zu einer App-Ticket-ID (Tabellenzeile oder Post) – nur eigene Tickets. */
    private static function own_ticket_post($id, $user) {
        // Ohne bestätigte E-Mail nur Tickets aus im Konto gekauften Bestellungen
        $email = self::trusted_email($user);
        if ($email === '') {
            foreach (class_exists('TIX_App_Checkout') ? TIX_App_Checkout::tickets_for_user($user) : [] as $t) {
                if (intval($t['id'] ?? 0) === intval($id)) {
                    return ['post_id' => intval($id), 'code' => (string) get_post_meta(intval($id), '_tix_ticket_code', true)];
                }
            }
            return null;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tix_tickets';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", intval($id)), ARRAY_A);
            if ($row && strcasecmp((string) ($row['buyer_email'] ?? ''), $email) === 0) {
                $pid = intval($row['ticket_post_id'] ?? 0);
                if (!$pid && !empty($row['ticket_code'])) {
                    $posts = get_posts([
                        'post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
                        'meta_query' => [['key' => '_tix_ticket_code', 'value' => (string) $row['ticket_code']]],
                    ]);
                    $pid = $posts ? intval($posts[0]) : 0;
                }
                return ['post_id' => $pid, 'code' => (string) ($row['ticket_code'] ?? '')];
            }
        }
        $post = get_post(intval($id));
        if ($post && $post->post_type === 'tix_ticket') {
            $owner = (string) get_post_meta($post->ID, '_tix_ticket_owner_email', true);
            $buyer = (string) get_post_meta($post->ID, '_tix_email', true);
            if (strcasecmp($owner, $email) === 0 || strcasecmp($buyer, $email) === 0) {
                return ['post_id' => $post->ID, 'code' => (string) get_post_meta($post->ID, '_tix_ticket_code', true)];
            }
        }
        return null;
    }

    /**
     * Generisches PDF-Ticket (A4 hochkant, 150 dpi), wenn das Event keine Ticket-Vorlage hat: hell und druckfreundlich,
     * Kopf in der Markenfarbe, Eventdaten, großer QR-Code, Ticketcode.
     */
    private static function generic_pdf($ticket_post_id) {
        if (!class_exists('TIX_Ticket_Template') || !function_exists('imagecreatetruecolor')) return null;
        $w = 1240;
        $h = 1754;
        $img = imagecreatetruecolor($w, $h);
        if (!$img) return null;
        $d = TIX_Ticket_Template::gather_ticket_data($ticket_post_id);
        $hex = function ($c, $fallback) { $c = ltrim((string) $c, '#'); return preg_match('/^[0-9a-f]{6}$/i', $c) ? $c : $fallback; };
        $rgb = function ($img, $c) { return imagecolorallocate($img, hexdec(substr($c, 0, 2)), hexdec(substr($c, 2, 2)), hexdec(substr($c, 4, 2))); };
        // Kopf in der Akzentfarbe, wenn weiße Schrift darauf lesbar ist (Kontrast ≥ 3:1 für große Schrift, relative Helligkeit < 0,3); sonst evendis-Rot bzw. Dunkelgrau
        $fallback = (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) ? 'E8445A' : '2C2F3F';
        $brand = $hex(function_exists('tix_get_settings') ? tix_get_settings('color_accent') : '', $fallback);
        $lum = function ($c) { $f = function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }; return 0.2126 * $f(hexdec(substr($c, 0, 2))) + 0.7152 * $f(hexdec(substr($c, 2, 2))) + 0.0722 * $f(hexdec(substr($c, 4, 2))); };
        if ($lum($brand) >= 0.3 || (class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) $brand = $fallback;
        $white = $rgb($img, 'ffffff');
        imagefilledrectangle($img, 0, 0, $w, $h, $white);
        $m = 90;                                    // Rand
        imagefilledrectangle($img, 0, 0, $w, 230, $rgb($img, $brand));
        $text = function ($str, $x, $y, $width, $size, $bold = false, $color = '2C2F3F', $align = 'left') use ($img) {
            if ((string) $str === '') return;
            TIX_Ticket_Template::render_text_field($img, ['enabled' => true, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => intval($size * 3),
                'font_size' => $size, 'font_family' => 'sans-serif', 'font_weight' => $bold ? 'bold' : 'normal', 'color' => '#' . $color, 'alignment' => $align, 'line_height' => 1.7], (string) $str);
        };
        $site = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $text($site, $m, 70, $w - 2 * $m, 20, true, 'ffffff');
        $text('Ticket', $m, 130, $w - 2 * $m, 14, false, 'ffffff');
        // Titel (bis 2 Zeilen)
        $y = 290;
        $text($d['event_name'] ?? '', $m, $y, $w - 2 * $m, 34, true);
        $title_lines = (function () use ($d, $w, $m) {
            $font = TIX_Ticket_Template::get_font_path('sans-serif', 'bold');
            if (!$font || !function_exists('imagettfbbox')) return 1;
            $words = explode(' ', (string) ($d['event_name'] ?? '')); $lines = 1; $line = '';
            foreach ($words as $wd) { $t = trim($line . ' ' . $wd); $b = imagettfbbox(34, 0, $font, $t); if ($b && abs($b[2] - $b[0]) > $w - 2 * $m && $line !== '') { $lines++; $line = $wd; } else { $line = $t; } }
            return $lines;
        })();
        $y += 50 + $title_lines * 58; // Zeilenabstand 34 pt × 1,7
        $rows = [
            ['Datum', trim(($d['event_date'] ?? '') . (($d['event_time'] ?? '') !== '' ? ' · ' . $d['event_time'] : ''))],
            ['Einlass', $d['event_doors'] ?? ''],
            ['Ort', trim(($d['event_location'] ?? '') . (($d['event_address'] ?? '') !== '' ? ', ' . $d['event_address'] : ''), ', ')],
            ['Kategorie', trim(($d['cat_name'] ?? '') . (($d['seat'] ?? '') !== '' ? ' · ' . $d['seat'] : ''))],
            ['Name', $d['owner_name'] ?? ''],
            ['Preis', $d['price'] ?? ''],
            ['Bestellung', $d['order_id'] ?? ''],
        ];
        $line = $rgb($img, 'E3E5EC');
        foreach ($rows as $r) {
            if ((string) $r[1] === '') continue;
            $text($r[0], $m, $y, 280, 15, false, '6B6F82');
            $text($r[1], $m + 300, $y, $w - 2 * $m - 300, 17, true);
            $y += mb_strlen($r[1]) > 46 ? 112 : 78;
            imageline($img, $m, $y - 22, $w - $m, $y - 22, $line);
        }
        // QR-Code groß und mittig
        $qs = 560;
        $qy = max($y + 30, 1000);
        if ($qy + $qs + 200 > $h) { $qs = max(380, $h - 200 - $qy); }
        TIX_Ticket_Template::render_qr_code($img, ['enabled' => true, 'x' => intval(($w - $qs) / 2), 'y' => $qy, 'width' => $qs, 'height' => $qs], $d['qr_code'] ?? '');
        $text($d['ticket_code'] ?? '', $m, $qy + $qs + 30, $w - 2 * $m, 26, true, '2C2F3F', 'center');
        $text('Bitte am Einlass vorzeigen – ausgedruckt oder auf dem Handy.', $m, $h - 110, $w - 2 * $m, 13, false, '6B6F82', 'center');
        ob_start();
        imagejpeg($img, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($img);
        $pdf = TIX_Ticket_Template::create_minimal_pdf($jpeg, $w, $h);
        return $pdf ?: null;
    }

    /** GET /customer/tickets/{id}/pdf – Binärantwort (application/pdf). */
    public static function ticket_pdf(WP_REST_Request $req) {
        $user = wp_get_current_user();
        $own  = self::own_ticket_post(intval($req['id']), $user);
        if (!$own || !$own['post_id']) return self::error('tix_ticket_missing', 'Ticket nicht gefunden.', 404);
        $pdf = null;
        if (class_exists('TIX_Tickets') && method_exists('TIX_Tickets', 'get_pdf_binary')) {
            $pdf = TIX_Tickets::get_pdf_binary($own['post_id']);
        }
        if (!$pdf) $pdf = self::generic_pdf($own['post_id']);
        if (!$pdf) return self::error('tix_no_pdf', 'Für dieses Ticket kann gerade kein PDF erzeugt werden.', 500);
        $name = 'ticket-' . (preg_replace('/[^A-Za-z0-9]/', '', $own['code']) ?: $own['post_id']) . '.pdf';
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    // ──────────────────────────────────────────
    //  Support
    // ──────────────────────────────────────────

    /**
     * Support aktiv? Konten des Personals (Studio „Hilfe & Support“) blockt der Schalter nicht (1.38.490):
     * Anfragen an den Plattform-Betreiber gehen auch, wenn der Kunden-Support der Seite aus ist.
     */
    private static function support_enabled($staff_ok = true) {
        if (!class_exists('TIX_Support')) return false;
        if (function_exists('tix_get_settings') && !empty(tix_get_settings('support_enabled'))) return true;
        return $staff_ok && self::is_help_user();
    }

    private static function is_help_user() {
        if (!is_user_logged_in() || !class_exists('TIX_REST_API') || !method_exists('TIX_REST_API', 'is_staff_user')) return false;
        return TIX_REST_API::is_staff_user(wp_get_current_user());
    }

    private static function support_categories() {
        $cats = class_exists('TIX_Support') ? TIX_Support::get_categories() : [];
        $out = [];
        foreach ((array) $cats as $c) {
            if (is_array($c) && !empty($c['slug'])) {
                $row = ['slug' => (string) $c['slug'], 'label' => (string) ($c['label'] ?? $c['slug'])];
                if (!empty($c['audience'])) $row['audience'] = (string) $c['audience'];
                if (!empty($c['anonymous'])) $row['anonymous'] = true;
                $out[] = $row;
            }
        }
        return $out;
    }

    /** GET /support/config[?event_id=] – responsible: wer antwortet (Support-Modell, bei geteilten Events je Event) */
    public static function support_config(WP_REST_Request $req) {
        $enabled = self::support_enabled();
        $out = [
            'ok'         => true,
            'enabled'    => $enabled,
            'categories' => $enabled ? self::support_categories() : [],
        ];
        // Studio „Hilfe & Support“: Themen für Anfragen an den Plattform-Betreiber (nur Personal)
        $out['customer_enabled'] = self::support_enabled(false); // Kunden-Support der Seite (enabled = für diesen Aufrufer nutzbar)
        if (self::is_help_user() && class_exists('TIX_Support') && method_exists('TIX_Support', 'studio_topics')) {
            $out['help_topics'] = array_map(function ($c) { return ['slug' => $c['slug'], 'label' => $c['label']]; }, TIX_Support::studio_topics());
        }
        if (class_exists('TIX_Support_Model')) $out['responsible'] = TIX_Support_Model::responsible_public(absint($req->get_param('event_id')));
        return rest_ensure_response($out);
    }

    /** POST /customer/support – Anfrage (Konto oder Gast mit Name + E-Mail). */
    public static function support_create(WP_REST_Request $req) {
        if (!self::support_enabled() || !method_exists('TIX_Support', 'app_create')) {
            return self::error('tix_support_disabled', 'Der Support in der App ist aktuell nicht aktiv.', 404);
        }
        // Personal (Studio „Hilfe & Support“): je Konto 30 in 10 min statt 5 je IP – mehrere Leute im selben Büro (1.38.491)
        $limited = self::is_help_user() ? self::rate_limited('support_staff', 30, 600, 'u' . get_current_user_id()) : self::rate_limited('support', 5, 600);
        if ($limited) {
            return self::error('tix_rate_limit', 'Zu viele Anfragen. Bitte in ein paar Minuten erneut versuchen.', 429);
        }
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        // Awareness-Meldung (Stufe 2): Name/E-Mail optional, Bezug zum Event
        if ((string) $req->get_param('category') === 'awareness' && class_exists('TIX_Awareness_Live')) {
            $anon = $req->get_param('anonymous') === null ? !$user : filter_var($req->get_param('anonymous'), FILTER_VALIDATE_BOOLEAN);
            $tid = TIX_Awareness_Live::create_report([
                'event_id' => $req->get_param('event_id'),
                'subject'  => (string) $req->get_param('subject'),
                'content'  => (string) $req->get_param('message'),
                'email'    => $anon ? (string) ($req->get_param('email') ?? '') : ($user ? (string) $user->user_email : (string) $req->get_param('email')),
                'name'     => $anon ? '' : ($user ? (string) $user->display_name : (string) $req->get_param('name')),
                'user_id'  => (!$anon && $user) ? intval($user->ID) : 0,
                'source'   => 'app',
            ]);
            if (is_wp_error($tid)) return $tid;
            $e = (string) get_post_meta($tid, '_tix_sp_email', true);
            return rest_ensure_response([
                'ok'        => true,
                'anonymous' => $e === '',
                'notice'    => $e === '' ? 'Ohne Kontaktangabe können wir dir nicht antworten.' : '',
                'request'   => ['id' => intval($tid), 'category' => 'awareness', 'status' => 'tix_open'],
            ]);
        }
        if ($user) {
            $email = (string) $user->user_email;
            $name  = trim($user->first_name . ' ' . $user->last_name) ?: (string) $user->display_name;
        } else {
            $email = sanitize_email((string) $req->get_param('email'));
            $name  = mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 80);
            if (!is_email($email)) return self::error('invalid_email', 'Bitte eine gültige E-Mail-Adresse angeben.', 400, ['field' => 'email']);
            if ($name === '') return self::error('missing_name', 'Bitte deinen Namen angeben.', 400, ['field' => 'name']);
        }
        $subject  = mb_substr(sanitize_text_field((string) $req->get_param('subject')), 0, 120);
        $content  = mb_substr(sanitize_textarea_field((string) $req->get_param('message')), 0, 5000);
        $category = sanitize_text_field((string) ($req->get_param('category') ?? 'other'));
        $slugs    = array_column(self::support_categories(), 'slug');
        // Studio-Themen (studio_*) vom Personal bleiben erhalten (1.38.490), sonst Rückfall wie bisher
        $studio_topic = self::is_help_user() && method_exists('TIX_Support', 'is_studio_topic') && TIX_Support::is_studio_topic($category);
        if ((string) $req->get_param('scope') === 'help' && self::is_help_user() && !$studio_topic && !in_array($category, $slugs, true)) { $category = 'studio_other'; $studio_topic = true; }
        if (!$studio_topic && !in_array($category, $slugs, true)) $category = $slugs ? (in_array('other', $slugs, true) ? 'other' : $slugs[0]) : 'other';
        if ($subject === '') return self::error('missing_subject', 'Bitte einen Betreff angeben.', 400, ['field' => 'subject']);
        if ($content === '') return self::error('missing_message', 'Bitte deine Nachricht eingeben.', 400, ['field' => 'message']);
        $ticket_id = TIX_Support::app_create([
            'email'       => $email,
            'name'        => $name,
            'subject'     => $subject,
            'category'    => $category,
            'content'     => $content,
            'order_id'    => (string) ($req->get_param('order_id') ?? ''),
            'ticket_code' => (string) ($req->get_param('ticket_code') ?? ''),
            'user_id'     => $user ? $user->ID : 0,
            'source'      => 'app',
        ]);
        if (is_wp_error($ticket_id)) return $ticket_id;
        // Studio „Hilfe & Support“ (Staff-Konto): Anfrage an den Plattform-Betreiber (1.38.480, TIX_Platform_Admin)
        if ($user && class_exists('TIX_Platform_Admin')) TIX_Platform_Admin::mark_help($ticket_id, $user);
        // Event-Bezug (z. B. „Event melden“ nach DSA Art. 16: Grund, Bestätigung „nach bestem Wissen“)
        $rep_eid = absint($req->get_param('event_id'));
        if ($rep_eid && get_post_type($rep_eid) === 'event') update_post_meta($ticket_id, '_tix_sp_event_id', $rep_eid);
        if ($category === 'event_report') {
            update_post_meta($ticket_id, '_tix_sp_priority', 'high');
            $reason = sanitize_key((string) $req->get_param('reason'));
            if ($reason !== '') update_post_meta($ticket_id, '_tix_sp_reason', mb_substr($reason, 0, 40));
            update_post_meta($ticket_id, '_tix_sp_good_faith', filter_var($req->get_param('good_faith'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }
        $detail = TIX_Support::app_detail($ticket_id, $email);
        return rest_ensure_response([
            'ok'      => true,
            'request' => is_wp_error($detail) ? ['id' => $ticket_id] : $detail,
        ]);
    }

    /** 0 = alle Anfragen der E-Mail; sonst (E-Mail nicht bestätigt) nur vom Konto selbst angelegte. */
    private static function support_author($user) {
        return self::email_trusted($user) ? 0 : intval($user->ID);
    }

    /** Schlüssel aus der Support-Mail prüfen → E-Mail der Anfrage oder WP_Error (10 Fehlversuche je IP in 10 min → 429). */
    private static function support_guest_email(WP_REST_Request $req) {
        if (!self::support_enabled() || !method_exists('TIX_Support', 'guest_email')) {
            return self::error('tix_support_disabled', 'Der Support ist aktuell nicht aktiv.', 404);
        }
        $fail_key = 'tix_sp_guest_fail_' . md5(self::client_ip());
        if (intval(get_transient($fail_key)) >= 10) {
            return self::error('tix_rate_limit', 'Zu viele Versuche. Bitte in ein paar Minuten erneut versuchen.', 429);
        }
        $key   = (string) ($req->get_param('key') ?? $req->get_param('tix_sp_key') ?? '');
        $email = TIX_Support::guest_email(intval($req['id']), $key);
        if ($email === '') {
            set_transient($fail_key, intval(get_transient($fail_key)) + 1, 600);
            return self::error('rest_forbidden', 'Der Link ist ungültig. Bitte öffne ihn erneut aus der E-Mail.', 403);
        }
        return $email;
    }

    /** GET /public/support/{id}?key= – Verlauf für Gäste (gleiches Format wie /customer/support/{id}) */
    public static function support_guest_detail(WP_REST_Request $req) {
        $email = self::support_guest_email($req);
        if (is_wp_error($email)) return $email;
        $detail = TIX_Support::app_detail(intval($req['id']), $email);
        if (is_wp_error($detail)) return $detail;
        return rest_ensure_response(['ok' => true, 'request' => $detail]);
    }

    /** POST /public/support/{id}/reply {key, message} – Antwort als Gast */
    public static function support_guest_reply(WP_REST_Request $req) {
        $email = self::support_guest_email($req);
        if (is_wp_error($email)) return $email;
        if (self::rate_limited('support_guest_reply', 20, 600)) {
            return self::error('tix_rate_limit', 'Zu viele Nachrichten. Bitte kurz warten.', 429);
        }
        $content = mb_substr(sanitize_textarea_field((string) $req->get_param('message')), 0, 5000);
        if ($content === '') return self::error('missing_message', 'Bitte deine Nachricht eingeben.', 400, ['field' => 'message']);
        $detail = TIX_Support::app_reply(intval($req['id']), $email, $content);
        if (is_wp_error($detail)) return $detail;
        return rest_ensure_response(['ok' => true, 'request' => $detail]);
    }

    /** GET /customer/support */
    public static function support_list(WP_REST_Request $req) {
        if (!self::support_enabled() || !method_exists('TIX_Support', 'app_list')) {
            return rest_ensure_response(['ok' => true, 'enabled' => false, 'requests' => []]);
        }
        $user = wp_get_current_user();
        $list = TIX_Support::app_list((string) $user->user_email, self::support_author($user));
        // ?scope=help: nur Anfragen an den Plattform-Betreiber (Studio „Hilfe & Support“), je mit help: true (1.38.490)
        if ((string) $req->get_param('scope') === 'help') {
            $list = array_values(array_filter($list, function ($r) { return !empty($r['id']) && get_post_meta(intval($r['id']), '_tix_sp_help', true); }));
            foreach ($list as $i => $r) {
                $list[$i]['help'] = true;
                $cat = (string) get_post_meta(intval($r['id']), '_tix_sp_category', true);
                $list[$i]['category'] = $cat;
                $list[$i]['category_label'] = method_exists('TIX_Support', 'label_of') ? TIX_Support::label_of($cat) : $cat;
            }
        }
        $out = [
            'ok'       => true,
            'enabled'  => true,
            'scope'    => (string) $req->get_param('scope') === 'help' ? 'help' : 'all',
            'requests' => $list,
        ];
        if (self::support_author($user)) {
            $out['email_confirmed'] = false;
            $out['notice'] = self::NOTICE_UNCONFIRMED;
        }
        return rest_ensure_response($out);
    }

    /** GET /customer/support/{id} */
    public static function support_detail(WP_REST_Request $req) {
        if (!self::support_enabled() || !method_exists('TIX_Support', 'app_detail')) {
            return self::error('tix_support_disabled', 'Der Support in der App ist aktuell nicht aktiv.', 404);
        }
        $user   = wp_get_current_user();
        $detail = TIX_Support::app_detail(intval($req['id']), (string) $user->user_email, self::support_author($user));
        if (is_wp_error($detail)) return $detail;
        return rest_ensure_response(['ok' => true, 'request' => $detail]);
    }

    /** POST /customer/support/{id}/reply */
    public static function support_reply(WP_REST_Request $req) {
        if (!self::support_enabled() || !method_exists('TIX_Support', 'app_reply')) {
            return self::error('tix_support_disabled', 'Der Support in der App ist aktuell nicht aktiv.', 404);
        }
        if (self::is_help_user() ? self::rate_limited('support_reply_staff', 60, 600, 'u' . get_current_user_id()) : self::rate_limited('support_reply', 20, 600)) {
            return self::error('tix_rate_limit', 'Zu viele Nachrichten. Bitte kurz warten.', 429);
        }
        $user    = wp_get_current_user();
        $content = mb_substr(sanitize_textarea_field((string) $req->get_param('message')), 0, 5000);
        if ($content === '') return self::error('missing_message', 'Bitte deine Nachricht eingeben.', 400, ['field' => 'message']);
        $detail = TIX_Support::app_reply(intval($req['id']), (string) $user->user_email, $content, self::support_author($user));
        if (is_wp_error($detail)) return $detail;
        return rest_ensure_response(['ok' => true, 'request' => $detail]);
    }
}
