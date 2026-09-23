<?php
/**
 * App-Konto & Service für die KitchenKlub-App (REST, Namespace tixomat/v1).
 *
 * Alles läuft nativ in der App – keine Website-Umleitungen:
 *   POST /auth/code                    Einmal-Code per E-Mail anfordern (login | reset)
 *   POST /auth/code/confirm            Code bestätigen → Geräte-Token (Login ohne Passwort,
 *                                      Gäste ohne Konto bekommen automatisch ein Konto;
 *                                      bei „reset“ wird das neue Passwort gesetzt)
 *   POST /auth/password                Passwort ändern (altes Passwort nötig, außer das
 *                                      Konto wurde per Code angelegt und hat noch keins)
 *   GET  /customer/tickets/{id}/pdf    Ticket als PDF (Template des Events, sonst generisch)
 *   GET  /support/config               Support aktiv? + Kategorien (öffentlich)
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

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/auth/code', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'request_code'],
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
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
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

    private static function send_code_email($email, $code, $purpose, $has_account) {
        $site = get_bloginfo('name');
        $login = ($purpose === 'login');
        $subject = $login
            ? 'Dein Anmeldecode – ' . $site
            : 'Dein Code zum Zurücksetzen des Passworts – ' . $site;
        $intro = $login
            ? ($has_account
                ? 'mit diesem Code meldest du dich ohne Passwort in der App an:'
                : 'mit diesem Code siehst du deine Tickets in der App – ein Konto legen wir dabei automatisch für dich an:')
            : 'mit diesem Code legst du in der App ein neues Passwort fest:';
        $body  = '<p>Hallo,</p>';
        $body .= '<p>' . $intro . '</p>';
        $body .= '<p style="text-align:center;margin:28px 0;">';
        $body .= '<span style="display:inline-block;padding:16px 28px;background:#131020;color:#ffffff;border-radius:12px;'
               . 'font-family:Menlo,Consolas,monospace;font-size:32px;font-weight:700;letter-spacing:10px;">'
               . esc_html($code) . '</span></p>';
        $body .= '<p style="color:#64748b;font-size:13px;line-height:1.6;">Der Code ist <strong>15 Minuten</strong> gültig. '
               . 'Gib ihn in der App ein – du musst dafür keinen Link öffnen.</p>';
        $body .= '<p style="color:#94a3b8;font-size:12px;margin-top:24px;">Falls du diesen Code nicht angefordert hast, kannst du diese E-Mail ignorieren.</p>';
        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html($login ? 'Dein Anmeldecode' : 'Passwort zurücksetzen', $body, $site . ' App')
            : '<html><body>' . $body . '</body></html>';
        return wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ──────────────────────────────────────────
    //  Einmal-Codes
    // ──────────────────────────────────────────

    /** POST /auth/code */
    public static function request_code(WP_REST_Request $req) {
        $email   = sanitize_email((string) $req->get_param('email'));
        $purpose = (string) $req->get_param('purpose') === 'reset' ? 'reset' : 'login';
        if (!is_email($email)) return self::error('invalid_email', 'Bitte eine gültige E-Mail-Adresse eingeben.');
        if (self::rate_limited('code_ip', 10, 600)) {
            return self::error('tix_rate_limit', 'Zu viele Anfragen. Bitte in ein paar Minuten erneut versuchen.', 429);
        }
        if (self::rate_limited('code_mail', 3, 600, strtolower($email))) {
            return self::error('tix_rate_limit', 'Für diese E-Mail-Adresse wurden gerade schon Codes verschickt. Bitte kurz warten und den Posteingang prüfen.', 429);
        }
        $user = get_user_by('email', $email);
        if ($purpose === 'reset' && !$user) {
            return self::error('not_found', 'Zu dieser E-Mail-Adresse gibt es kein Konto.', 404);
        }
        if ($purpose === 'login' && !$user && !self::email_has_orders($email)) {
            return self::error('not_found', 'Wir konnten weder ein Konto noch eine Bestellung mit dieser E-Mail-Adresse finden.', 404);
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        set_transient(self::code_key($email, $purpose), [
            'hash'    => self::code_hash($code),
            'tries'   => 0,
            'created' => time(),
        ], self::CODE_TTL);
        self::send_code_email($email, $code, $purpose, (bool) $user);
        return rest_ensure_response([
            'ok'          => true,
            'email'       => $email,
            'purpose'     => $purpose,
            'has_account' => (bool) $user,
            'expires_in'  => self::CODE_TTL,
        ]);
    }

    /** POST /auth/code/confirm */
    public static function confirm_code(WP_REST_Request $req) {
        $email   = sanitize_email((string) $req->get_param('email'));
        $purpose = (string) $req->get_param('purpose') === 'reset' ? 'reset' : 'login';
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
        $user  = get_user_by('ID', $user->ID);
        $token = self::issue_token($user->ID, $req);
        return rest_ensure_response([
            'ok'      => true,
            'success' => true,
            'token'   => $token,
            'user'    => self::user_payload($user),
            'created' => $created,
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

    // ──────────────────────────────────────────
    //  Ticket-PDF
    // ──────────────────────────────────────────

    /** Ticket-Post-ID zu einer App-Ticket-ID (Tabellenzeile oder Post) – nur eigene Tickets. */
    private static function own_ticket_post($id, $email) {
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

    /** Generisches PDF-Ticket, wenn das Event kein Ticket-Template hat. */
    private static function generic_pdf($ticket_post_id) {
        if (!class_exists('TIX_Ticket_Template') || !function_exists('imagecreatetruecolor')) return null;
        $w = 1240;
        $h = 1754;
        $img = imagecreatetruecolor($w, $h);
        if (!$img) return null;
        $bg = imagecolorallocate($img, 0x13, 0x10, 0x20);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        $fields = TIX_Ticket_Template::default_fields($w, $h);
        $data   = TIX_Ticket_Template::gather_ticket_data($ticket_post_id);
        foreach (['owner_name', 'price', 'order_id'] as $extra) {
            if (isset($fields[$extra])) $fields[$extra]['enabled'] = true;
        }
        foreach ($fields as $key => $cfg) {
            if (empty($cfg['enabled'])) continue;
            if ($key === 'qr_code') {
                TIX_Ticket_Template::render_qr_code($img, $cfg, $data['qr_code'] ?? '');
            } elseif ($key === 'barcode' || $key === 'logo' || $key === 'custom_text') {
                continue;
            } else {
                TIX_Ticket_Template::render_text_field($img, $cfg, (string) ($data[$key] ?? ''));
            }
        }
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
        $own  = self::own_ticket_post(intval($req['id']), (string) $user->user_email);
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

    private static function support_enabled() {
        return class_exists('TIX_Support') && function_exists('tix_get_settings') && !empty(tix_get_settings('support_enabled'));
    }

    private static function support_categories() {
        $cats = class_exists('TIX_Support') ? TIX_Support::get_categories() : [];
        $out = [];
        foreach ((array) $cats as $c) {
            if (is_array($c) && !empty($c['slug'])) {
                $out[] = ['slug' => (string) $c['slug'], 'label' => (string) ($c['label'] ?? $c['slug'])];
            }
        }
        return $out;
    }

    /** GET /support/config */
    public static function support_config(WP_REST_Request $req) {
        $enabled = self::support_enabled();
        return rest_ensure_response([
            'ok'         => true,
            'enabled'    => $enabled,
            'categories' => $enabled ? self::support_categories() : [],
        ]);
    }

    /** POST /customer/support – Anfrage (Konto oder Gast mit Name + E-Mail). */
    public static function support_create(WP_REST_Request $req) {
        if (!self::support_enabled() || !method_exists('TIX_Support', 'app_create')) {
            return self::error('tix_support_disabled', 'Der Support in der App ist aktuell nicht aktiv.', 404);
        }
        if (self::rate_limited('support', 5, 600)) {
            return self::error('tix_rate_limit', 'Zu viele Anfragen. Bitte in ein paar Minuten erneut versuchen.', 429);
        }
        $user = is_user_logged_in() ? wp_get_current_user() : null;
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
        if (!in_array($category, $slugs, true)) $category = $slugs ? (in_array('other', $slugs, true) ? 'other' : $slugs[0]) : 'other';
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
        $detail = TIX_Support::app_detail($ticket_id, $email);
        return rest_ensure_response([
            'ok'      => true,
            'request' => is_wp_error($detail) ? ['id' => $ticket_id] : $detail,
        ]);
    }

    /** GET /customer/support */
    public static function support_list(WP_REST_Request $req) {
        if (!self::support_enabled() || !method_exists('TIX_Support', 'app_list')) {
            return rest_ensure_response(['ok' => true, 'enabled' => false, 'requests' => []]);
        }
        $user = wp_get_current_user();
        return rest_ensure_response([
            'ok'       => true,
            'enabled'  => true,
            'requests' => TIX_Support::app_list((string) $user->user_email),
        ]);
    }

    /** GET /customer/support/{id} */
    public static function support_detail(WP_REST_Request $req) {
        if (!self::support_enabled() || !method_exists('TIX_Support', 'app_detail')) {
            return self::error('tix_support_disabled', 'Der Support in der App ist aktuell nicht aktiv.', 404);
        }
        $user   = wp_get_current_user();
        $detail = TIX_Support::app_detail(intval($req['id']), (string) $user->user_email);
        if (is_wp_error($detail)) return $detail;
        return rest_ensure_response(['ok' => true, 'request' => $detail]);
    }

    /** POST /customer/support/{id}/reply */
    public static function support_reply(WP_REST_Request $req) {
        if (!self::support_enabled() || !method_exists('TIX_Support', 'app_reply')) {
            return self::error('tix_support_disabled', 'Der Support in der App ist aktuell nicht aktiv.', 404);
        }
        if (self::rate_limited('support_reply', 20, 600)) {
            return self::error('tix_rate_limit', 'Zu viele Nachrichten. Bitte kurz warten.', 429);
        }
        $user    = wp_get_current_user();
        $content = mb_substr(sanitize_textarea_field((string) $req->get_param('message')), 0, 5000);
        if ($content === '') return self::error('missing_message', 'Bitte deine Nachricht eingeben.', 400, ['field' => 'message']);
        $detail = TIX_Support::app_reply(intval($req['id']), (string) $user->user_email, $content);
        if (is_wp_error($detail)) return $detail;
        return rest_ensure_response(['ok' => true, 'request' => $detail]);
    }
}
