<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat — Promoter Authentication (Magic-Link + Session-Cookie)
 *
 * Erlaubt Promoter-Login OHNE WordPress-Account:
 *   1. Promoter gibt E-Mail im Dashboard-Login-Form ein
 *   2. System sendet Magic-Link mit Token an die Mail
 *   3. Klick auf Link → Server verifiziert HMAC, setzt Session-Cookie (90 Tage)
 *   4. Dashboard prüft entweder WP-Login (klassisch) ODER Cookie
 *
 * Funktioniert parallel zu WP-Login: WP-User-Promoter haben beide Optionen.
 *
 * @since 1.38.158
 */
class TIX_Promoter_Auth {

    const COOKIE_NAME       = 'tix_promoter_session';
    const COOKIE_TTL        = 90 * DAY_IN_SECONDS;
    const MAGIC_TTL         = 15 * MINUTE_IN_SECONDS;
    const RATE_LIMIT_KEY    = 'tix_promoter_auth_rl_';
    const RATE_LIMIT_WINDOW = 15 * MINUTE_IN_SECONDS;
    const RATE_LIMIT_MAX    = 5; // 5 send-Versuche / 15 Min / IP

    /* ──────────────────────────── Bootstrap ──────────────────────────── */

    public static function init() {
        // Magic-Link-Detection auf init (vor template_redirect)
        add_action('init', [__CLASS__, 'detect_magic_link'], 8);

        // AJAX: Magic-Link-Send (Login-Form-Submit)
        add_action('wp_ajax_tix_promoter_request_login',        [__CLASS__, 'ajax_request_login']);
        add_action('wp_ajax_nopriv_tix_promoter_request_login', [__CLASS__, 'ajax_request_login']);

        // Logout-Handler
        add_action('init', [__CLASS__, 'maybe_handle_logout'], 9);

        // WP-Profile-Update → tix_promoters.email syncen
        add_action('profile_update', [__CLASS__, 'sync_user_email_to_promoter'], 10, 2);

        // WP-Login-Redirect: wenn auf Promoter-Page eingeloggt → direkt zum Dashboard
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 10, 3);
    }

    /**
     * Wenn ein Promoter sich klassisch über das WP-Login-Form auf der
     * Promoter-Dashboard-Seite einloggt, leiten wir ihn direkt zum Dashboard
     * (statt zum WP-Backend).
     */
    public static function login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (!class_exists('TIX_Promoter_DB')) return $redirect_to;
        if (is_wp_error($user) || !($user instanceof WP_User)) return $redirect_to;

        $promoter = TIX_Promoter_DB::get_promoter_by_user(intval($user->ID));
        if (!$promoter || $promoter->status !== 'active') return $redirect_to;

        // Wenn der requested_redirect die Promoter-Page ist → dorthin
        // ODER wenn requested_redirect leer/Standard ist und der User Promoter ist → Promoter-Page
        $promoter_url = self::get_promoter_page_url();
        $promoter_path = parse_url($promoter_url, PHP_URL_PATH);

        if ($requested_redirect_to) {
            $req_path = parse_url($requested_redirect_to, PHP_URL_PATH);
            if ($req_path && $promoter_path && rtrim($req_path, '/') === rtrim($promoter_path, '/')) {
                return $promoter_url;
            }
        }

        return $redirect_to;
    }

    /* ──────────────────────────── Magic-Link Generation ──────────────────────────── */

    /**
     * Generiert einen signed Magic-Link für einen Promoter.
     * Token-Format: "promoter_id.expiry.hmac" (Base64URL)
     */
    public static function build_magic_url(int $promoter_id) {
        $expiry  = time() + self::MAGIC_TTL;
        $payload = $promoter_id . '.' . $expiry;
        $sig     = hash_hmac('sha256', 'tix-promoter-magic|' . $payload, wp_salt('auth'));
        $token   = $payload . '.' . substr($sig, 0, 32);

        $base = self::get_promoter_page_url();
        return add_query_arg('tix_pauth', $token, $base);
    }

    /**
     * Verifiziert einen Magic-Link-Token und gibt promoter_id zurück (oder 0).
     */
    public static function verify_magic_token(string $token): int {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return 0;
        list($pid, $expiry, $sig) = $parts;
        $pid = intval($pid);
        $expiry = intval($expiry);
        if ($pid <= 0 || $expiry < time()) return 0;
        $payload  = $pid . '.' . $expiry;
        $expected = substr(hash_hmac('sha256', 'tix-promoter-magic|' . $payload, wp_salt('auth')), 0, 32);
        if (!hash_equals($expected, $sig)) return 0;
        return $pid;
    }

    /* ──────────────────────────── Session-Cookie ──────────────────────────── */

    /**
     * Cookie-Token: "promoter_id.expiry.hmac" (signed, 90 Tage).
     */
    public static function set_session_cookie(int $promoter_id) {
        $expiry  = time() + self::COOKIE_TTL;
        $payload = $promoter_id . '.' . $expiry;
        $sig     = hash_hmac('sha256', 'tix-promoter-session|' . $payload, wp_salt('auth'));
        $token   = $payload . '.' . substr($sig, 0, 32);

        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, $token, [
                'expires'  => $expiry,
                'path'     => '/',
                'samesite' => 'Lax',
                'secure'   => is_ssl(),
                'httponly' => true,
            ]);
            $_COOKIE[self::COOKIE_NAME] = $token;
        }
    }

    /**
     * Liest den Session-Cookie aus und gibt promoter_id zurück (oder 0).
     */
    public static function get_session_promoter_id(): int {
        if (empty($_COOKIE[self::COOKIE_NAME])) return 0;
        $token = (string) $_COOKIE[self::COOKIE_NAME];
        $parts = explode('.', $token);
        if (count($parts) !== 3) return 0;
        list($pid, $expiry, $sig) = $parts;
        $pid = intval($pid);
        $expiry = intval($expiry);
        if ($pid <= 0 || $expiry < time()) return 0;
        $payload  = $pid . '.' . $expiry;
        $expected = substr(hash_hmac('sha256', 'tix-promoter-session|' . $payload, wp_salt('auth')), 0, 32);
        if (!hash_equals($expected, $sig)) return 0;
        return $pid;
    }

    public static function clear_session_cookie() {
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'samesite' => 'Lax',
                'secure'   => is_ssl(),
                'httponly' => true,
            ]);
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /* ──────────────────────────── Authentication API ──────────────────────────── */

    /**
     * Liefert den aktuellen Promoter-Datensatz oder null.
     * Versucht zuerst Session-Cookie, dann WP-Login.
     */
    public static function get_current_promoter() {
        if (!class_exists('TIX_Promoter_DB')) return null;

        // 1. Session-Cookie
        $pid = self::get_session_promoter_id();
        if ($pid > 0) {
            $promoter = TIX_Promoter_DB::get_promoter($pid);
            if ($promoter && $promoter->status === 'active') return $promoter;
        }

        // 2. WP-Login als Fallback
        if (is_user_logged_in()) {
            $promoter = TIX_Promoter_DB::get_promoter_by_user(get_current_user_id());
            if ($promoter && $promoter->status === 'active') return $promoter;
        }

        return null;
    }

    public static function is_authenticated(): bool {
        return self::get_current_promoter() !== null;
    }

    /* ──────────────────────────── Magic-Link-Send (AJAX) ──────────────────────────── */

    public static function ajax_request_login() {
        check_ajax_referer('tix_promoter_login', 'nonce');

        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Bitte eine gültige E-Mail-Adresse eingeben.']);
        }

        // Auth-Method-Check: Wenn Admin "wp_login only" eingestellt hat → ablehnen
        $s = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        $method = $s['promoter_auth_method'] ?? 'both';
        if ($method === 'wp_login') {
            wp_send_json_error(['message' => 'Magic-Link-Login ist auf dieser Seite deaktiviert. Bitte melde dich klassisch mit deinem WordPress-Konto an.']);
        }

        // Rate-Limit
        $ip = self::client_ip();
        $rl_key = self::RATE_LIMIT_KEY . md5($ip);
        $count = (int) get_transient($rl_key);
        if ($count >= self::RATE_LIMIT_MAX) {
            wp_send_json_error(['message' => 'Zu viele Anfragen. Bitte warte 15 Minuten und versuche es erneut.']);
        }
        set_transient($rl_key, $count + 1, self::RATE_LIMIT_WINDOW);

        // Promoter suchen — über tix_promoters.email ODER über WP-User-Mail (für WP-User-Promoter)
        if (!class_exists('TIX_Promoter_DB')) {
            wp_send_json_error(['message' => 'Promoter-System nicht verfügbar.']);
        }

        $promoter = TIX_Promoter_DB::get_promoter_by_email($email);
        if (!$promoter) {
            // Fallback: WP-User mit dieser Mail → ist er Promoter?
            $u = get_user_by('email', $email);
            if ($u) {
                $promoter = TIX_Promoter_DB::get_promoter_by_user($u->ID);
            }
        }

        // Aus Sicherheit immer Erfolgsmeldung — auch wenn keine Mail im System.
        // So leakt das System nicht, welche Mails registriert sind.
        if (!$promoter || $promoter->status !== 'active') {
            wp_send_json_success([
                'message' => 'Wenn diese E-Mail in unserem System registriert ist, haben wir dir einen Login-Link geschickt.',
            ]);
        }

        // Magic-Link bauen + senden
        $magic_url = self::build_magic_url(intval($promoter->id));
        self::send_magic_link_email($email, $magic_url, $promoter);

        wp_send_json_success([
            'message' => 'Wenn diese E-Mail in unserem System registriert ist, haben wir dir einen Login-Link geschickt.',
        ]);
    }

    /* ──────────────────────────── Magic-Link-Detection (init Hook) ──────────────────────────── */

    public static function detect_magic_link() {
        if (empty($_GET['tix_pauth'])) return;

        $token = sanitize_text_field((string) $_GET['tix_pauth']);
        $pid   = self::verify_magic_token($token);
        if ($pid <= 0) {
            // Invalid/expired → leite zur Login-Seite mit Fehler
            self::redirect_with_flag('expired');
        }

        if (!class_exists('TIX_Promoter_DB')) return;
        $promoter = TIX_Promoter_DB::get_promoter($pid);
        if (!$promoter || $promoter->status !== 'active') {
            self::redirect_with_flag('invalid');
        }

        // Session-Cookie setzen + sauber zur Dashboard-URL redirecten (URL ohne Token)
        self::set_session_cookie(intval($promoter->id));

        $base = self::get_promoter_page_url();
        wp_safe_redirect(add_query_arg('tix_pauth_ok', '1', $base));
        exit;
    }

    private static function redirect_with_flag(string $flag) {
        $base = self::get_promoter_page_url();
        wp_safe_redirect(add_query_arg('tix_pauth_err', $flag, $base));
        exit;
    }

    /* ──────────────────────────── Logout ──────────────────────────── */

    public static function maybe_handle_logout() {
        if (empty($_GET['tix_promoter_logout'])) return;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tix_promoter_logout')) return;
        self::clear_session_cookie();
        wp_safe_redirect(self::get_promoter_page_url());
        exit;
    }

    public static function logout_url() {
        return wp_nonce_url(
            add_query_arg('tix_promoter_logout', '1', self::get_promoter_page_url()),
            'tix_promoter_logout'
        );
    }

    /* ──────────────────────────── Helpers ──────────────────────────── */

    /**
     * Gibt die Promoter-Dashboard-Seiten-URL zurück.
     * Priorisiert die Settings-Seite, fällt auf /promoter/-Slug zurück.
     */
    public static function get_promoter_page_url(): string {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        $page_id = intval($s['promoter_page_id'] ?? 0);
        if ($page_id > 0) {
            $url = get_permalink($page_id);
            if ($url) return $url;
        }
        // Fallback: /promoter/-Slug suchen
        $page = get_page_by_path('promoter');
        if ($page) return get_permalink($page->ID);
        return home_url('/promoter/');
    }

    private static function client_ip(): string {
        $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }

    /**
     * Sendet die Magic-Link-Mail.
     */
    private static function send_magic_link_email($email, $url, $promoter) {
        $site_name = get_bloginfo('name');
        $subject   = 'Dein Promoter-Login — ' . $site_name;
        $name      = $promoter->display_name ?: ($promoter->promoter_code ?: '');

        $body  = '<p>Hallo' . ($name ? ' ' . esc_html($name) : '') . ',</p>';
        $body .= '<p>du hast einen Login-Link für dein Promoter-Dashboard angefordert. Klicke auf den folgenden Button, um dich automatisch einzuloggen:</p>';
        $body .= '<p style="text-align:center;margin:28px 0;">';
        $body .= '<a href="' . esc_url($url) . '" style="display:inline-block;padding:14px 28px;'
               . (function_exists('tix_btn_style') ? tix_btn_style() : 'background:#0f172a;color:#fff;border-radius:10px;')
               . 'text-decoration:none;font-weight:600;font-size:15px;">Zum Promoter-Dashboard</a>';
        $body .= '</p>';
        $body .= '<p style="color:#64748b;font-size:13px;line-height:1.6;">Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:<br><span style="word-break:break-all;color:#334155;">' . esc_html($url) . '</span></p>';
        $body .= '<p style="color:#64748b;font-size:13px;">Der Link ist aus Sicherheitsgr&uuml;nden <strong>15 Minuten g&uuml;ltig</strong>. Nach dem Klick bleibst du <strong>90 Tage</strong> eingeloggt.</p>';
        $body .= '<p style="color:#94a3b8;font-size:12px;margin-top:24px;">Falls du diesen Link nicht angefordert hast, kannst du diese E-Mail ignorieren — niemand kann sich ohne den Link in dein Promoter-Dashboard einloggen.</p>';

        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html('Promoter-Login', $body, 'Dein Login-Link')
            : '<html><body>' . $body . '</body></html>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($email, $subject, $html, $headers);
    }

    /* ──────────────────────────── Profile-Update Sync ──────────────────────────── */

    /**
     * Wenn ein WP-User seine E-Mail ändert, syncen wir das ins tix_promoters.email-Feld.
     */
    public static function sync_user_email_to_promoter($user_id, $old_user_data) {
        if (!class_exists('TIX_Promoter_DB')) return;
        $u = get_userdata($user_id);
        if (!$u) return;

        $promoter = TIX_Promoter_DB::get_promoter_by_user(intval($user_id));
        if (!$promoter) return;

        $new_email = sanitize_email($u->user_email);
        if ($new_email && strtolower($new_email) !== strtolower((string) ($promoter->email ?? ''))) {
            TIX_Promoter_DB::update_promoter(intval($promoter->id), ['email' => $new_email]);
        }
    }
}
