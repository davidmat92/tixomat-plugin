<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat — Sponsor Authentication (Magic-Link)
 *
 * Sponsoren bekommen Login-Link per Mail an die in tix_sponsors.email
 * hinterlegte Adresse. Identisches Pattern wie TIX_Promoter_Auth.
 *
 * @since 1.38.171
 */
class TIX_Sponsor_Auth {

    const COOKIE_NAME       = 'tix_sponsor_session';
    const COOKIE_TTL        = 90 * DAY_IN_SECONDS;
    const MAGIC_TTL         = 30 * MINUTE_IN_SECONDS;
    const RATE_LIMIT_KEY    = 'tix_sponsor_auth_rl_';
    const RATE_LIMIT_WINDOW = 15 * MINUTE_IN_SECONDS;
    const RATE_LIMIT_MAX    = 5;

    public static function init() {
        add_action('init', [__CLASS__, 'detect_magic_link'], 8);
        add_action('wp_ajax_tix_sponsor_request_login',        [__CLASS__, 'ajax_request_login']);
        add_action('wp_ajax_nopriv_tix_sponsor_request_login', [__CLASS__, 'ajax_request_login']);
        add_action('wp_ajax_tix_sponsor_password_login',        [__CLASS__, 'ajax_password_login']);
        add_action('wp_ajax_nopriv_tix_sponsor_password_login', [__CLASS__, 'ajax_password_login']);
        add_action('wp_ajax_tix_sponsor_set_password',          [__CLASS__, 'ajax_set_password']);
        add_action('init', [__CLASS__, 'maybe_handle_logout'], 9);
    }

    public static function build_magic_url(int $sponsor_id): string {
        $expiry  = time() + self::MAGIC_TTL;
        $payload = $sponsor_id . '.' . $expiry;
        $sig     = hash_hmac('sha256', 'tix-sponsor-magic|' . $payload, wp_salt('auth'));
        $token   = $payload . '.' . substr($sig, 0, 32);
        return add_query_arg('tix_sauth', $token, self::get_sponsor_page_url());
    }

    public static function verify_magic_token(string $token): int {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return 0;
        list($sid, $exp, $sig) = $parts;
        $sid = intval($sid);
        $exp = intval($exp);
        if ($sid <= 0 || $exp < time()) return 0;
        $expected = substr(hash_hmac('sha256', 'tix-sponsor-magic|' . $sid . '.' . $exp, wp_salt('auth')), 0, 32);
        return hash_equals($expected, $sig) ? $sid : 0;
    }

    public static function set_session_cookie(int $sponsor_id) {
        $expiry  = time() + self::COOKIE_TTL;
        $payload = $sponsor_id . '.' . $expiry;
        $sig     = hash_hmac('sha256', 'tix-sponsor-session|' . $payload, wp_salt('auth'));
        $token   = $payload . '.' . substr($sig, 0, 32);
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, $token, [
                'expires' => $expiry, 'path' => '/', 'samesite' => 'Lax',
                'secure' => is_ssl(), 'httponly' => true,
            ]);
            $_COOKIE[self::COOKIE_NAME] = $token;
        }
    }

    public static function get_session_sponsor_id(): int {
        if (empty($_COOKIE[self::COOKIE_NAME])) return 0;
        $parts = explode('.', (string) $_COOKIE[self::COOKIE_NAME]);
        if (count($parts) !== 3) return 0;
        list($sid, $exp, $sig) = $parts;
        $sid = intval($sid);
        $exp = intval($exp);
        if ($sid <= 0 || $exp < time()) return 0;
        $expected = substr(hash_hmac('sha256', 'tix-sponsor-session|' . $sid . '.' . $exp, wp_salt('auth')), 0, 32);
        return hash_equals($expected, $sig) ? $sid : 0;
    }

    public static function clear_session_cookie() {
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, '', [
                'expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax',
                'secure' => is_ssl(), 'httponly' => true,
            ]);
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    public static function get_current_sponsor() {
        if (!class_exists('TIX_Sponsor_DB')) return null;
        $sid = self::get_session_sponsor_id();
        if ($sid <= 0) return null;
        $s = TIX_Sponsor_DB::get_sponsor($sid);
        return ($s && $s->status === 'active') ? $s : null;
    }

    public static function is_authenticated(): bool {
        return self::get_current_sponsor() !== null;
    }

    public static function ajax_request_login() {
        check_ajax_referer('tix_sponsor_login', 'nonce');

        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) wp_send_json_error(['message' => 'Bitte eine gültige E-Mail-Adresse eingeben.']);

        $ip = self::client_ip();
        $rl_key = self::RATE_LIMIT_KEY . md5($ip);
        $count  = (int) get_transient($rl_key);
        if ($count >= self::RATE_LIMIT_MAX) {
            wp_send_json_error(['message' => 'Zu viele Anfragen. Bitte warte 15 Minuten.']);
        }
        set_transient($rl_key, $count + 1, self::RATE_LIMIT_WINDOW);

        if (!class_exists('TIX_Sponsor_DB')) wp_send_json_error(['message' => 'Sponsor-System nicht verfügbar.']);
        $sponsor = TIX_Sponsor_DB::get_sponsor_by_email($email);

        if ($sponsor && $sponsor->status === 'active') {
            self::send_magic_link_email($email, self::build_magic_url(intval($sponsor->id)), $sponsor);
        }

        // Always success — gegen Email-Enumeration
        wp_send_json_success([
            'message' => 'Wenn diese E-Mail in unserem System registriert ist, haben wir dir einen Login-Link geschickt.',
        ]);
    }

    public static function ajax_password_login() {
        check_ajax_referer('tix_sponsor_login', 'nonce');

        $email = sanitize_email($_POST['email'] ?? '');
        $pass  = (string) ($_POST['password'] ?? '');
        if (!is_email($email) || $pass === '') {
            wp_send_json_error(['message' => 'Bitte E-Mail und Passwort eingeben.']);
        }

        // Rate-Limit (eigener Bucket — strenger als Magic-Link)
        $ip = self::client_ip();
        $rl_key = self::RATE_LIMIT_KEY . 'pw_' . md5($ip . '|' . strtolower($email));
        $count  = (int) get_transient($rl_key);
        if ($count >= self::RATE_LIMIT_MAX) {
            wp_send_json_error(['message' => 'Zu viele Fehlversuche. Bitte warte 15 Minuten oder nutze den Login-Link.']);
        }

        if (!class_exists('TIX_Sponsor_DB')) {
            wp_send_json_error(['message' => 'Sponsor-System nicht verfügbar.']);
        }
        $sponsor = TIX_Sponsor_DB::get_sponsor_by_email($email);
        $ok = ($sponsor && $sponsor->status === 'active')
            ? TIX_Sponsor_DB::verify_password(intval($sponsor->id), $pass)
            : false;

        if (!$ok) {
            set_transient($rl_key, $count + 1, self::RATE_LIMIT_WINDOW);
            // Bewusst generisch — keine Auskunft, ob E-Mail existiert oder Passwort falsch
            wp_send_json_error(['message' => 'E-Mail oder Passwort falsch.']);
        }

        delete_transient($rl_key);
        self::set_session_cookie(intval($sponsor->id));
        wp_send_json_success(['redirect' => add_query_arg('tix_sauth_ok', '1', self::get_sponsor_page_url())]);
    }

    public static function ajax_set_password() {
        check_ajax_referer('tix_sponsor_dashboard', 'nonce');
        $sponsor = self::get_current_sponsor();
        if (!$sponsor) wp_send_json_error(['message' => 'Nicht eingeloggt.']);

        $new = (string) ($_POST['new_password'] ?? '');
        $old = (string) ($_POST['old_password'] ?? '');

        if (strlen($new) < 8) {
            wp_send_json_error(['message' => 'Passwort muss mindestens 8 Zeichen lang sein.']);
        }
        // Wenn schon ein Passwort existiert → altes muss stimmen
        if (TIX_Sponsor_DB::has_password(intval($sponsor->id))) {
            if (!TIX_Sponsor_DB::verify_password(intval($sponsor->id), $old)) {
                wp_send_json_error(['message' => 'Aktuelles Passwort ist falsch.']);
            }
        }
        if (!TIX_Sponsor_DB::set_password(intval($sponsor->id), $new)) {
            wp_send_json_error(['message' => 'Passwort konnte nicht gespeichert werden.']);
        }
        wp_send_json_success(['message' => 'Passwort gespeichert.']);
    }

    public static function detect_magic_link() {
        if (empty($_GET['tix_sauth'])) return;
        $token = sanitize_text_field((string) $_GET['tix_sauth']);
        $sid = self::verify_magic_token($token);
        if ($sid <= 0) {
            wp_safe_redirect(add_query_arg('tix_sauth_err', 'expired', self::get_sponsor_page_url()));
            exit;
        }
        if (!class_exists('TIX_Sponsor_DB')) return;
        $s = TIX_Sponsor_DB::get_sponsor($sid);
        if (!$s || $s->status !== 'active') {
            wp_safe_redirect(add_query_arg('tix_sauth_err', 'invalid', self::get_sponsor_page_url()));
            exit;
        }
        self::set_session_cookie(intval($s->id));
        wp_safe_redirect(add_query_arg('tix_sauth_ok', '1', self::get_sponsor_page_url()));
        exit;
    }

    public static function maybe_handle_logout() {
        if (empty($_GET['tix_sponsor_logout'])) return;
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tix_sponsor_logout')) return;
        self::clear_session_cookie();
        wp_safe_redirect(self::get_sponsor_page_url());
        exit;
    }

    public static function logout_url(): string {
        return wp_nonce_url(
            add_query_arg('tix_sponsor_logout', '1', self::get_sponsor_page_url()),
            'tix_sponsor_logout'
        );
    }

    public static function get_sponsor_page_url(): string {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $page_id = intval($s['sponsor_page_id'] ?? 0);
        if ($page_id > 0) {
            $url = get_permalink($page_id);
            if ($url) return $url;
        }
        $page = get_page_by_path('sponsor');
        if ($page) return get_permalink($page->ID);
        return home_url('/sponsor/');
    }

    private static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }

    private static function send_magic_link_email($email, $url, $sponsor) {
        $site = get_bloginfo('name');
        $subject = 'Dein Sponsor-Login — ' . $site;
        $name = $sponsor->contact_name ?: $sponsor->name;

        $body  = '<p>Hallo' . ($name ? ' ' . esc_html($name) : '') . ',</p>';
        $body .= '<p>du hast einen Login-Link für dein Sponsor-Portal angefordert. Klicke auf den Button, um direkt eingeloggt zu werden:</p>';
        $body .= '<p style="text-align:center;margin:28px 0;">';
        $body .= '<a href="' . esc_url($url) . '" style="display:inline-block;padding:14px 28px;background:#FF5500;color:#fff;border-radius:10px;text-decoration:none;font-weight:600;font-size:15px;">Zum Sponsor-Portal</a>';
        $body .= '</p>';
        $body .= '<p style="color:#64748b;font-size:13px;line-height:1.6;">Link funktioniert nicht? Kopiere diese URL:<br><span style="word-break:break-all;color:#334155;">' . esc_html($url) . '</span></p>';
        $body .= '<p style="color:#64748b;font-size:13px;">Der Link ist <strong>30 Minuten</strong> gültig. Nach dem Klick bleibst du <strong>90 Tage</strong> eingeloggt.</p>';
        $body .= '<p style="color:#94a3b8;font-size:12px;margin-top:24px;">Falls du diesen Link nicht angefordert hast: einfach ignorieren.</p>';

        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html('Sponsor-Login', $body, 'Dein Login-Link')
            : '<html><body>' . $body . '</body></html>';

        wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }
}
