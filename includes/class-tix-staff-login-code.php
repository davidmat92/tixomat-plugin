<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Staff_Login_Code – „In der App anmelden“ per QR (Paket P7, 1.38.576).
 *
 *   POST /staff/login-code {user_id?}  (Studio, angemeldet) → {code, expires_in, url}
 *        ohne user_id: für das eigene Konto; mit user_id: für ein Team-Konto (z. B. Einlass-Helfer ohne Passwort),
 *        nur für Admins bzw. Inhaber/Team-Admins – auf der Plattform nur im eigenen Veranstalter.
 *   POST /auth/login-code {code, device} (App, öffentlich, begrenzt) → {success, token, user} wie /auth/login
 * Der Code gilt 10 Minuten und genau einmal. QR-Inhalt: https://<seite>/?tix_app_login=<code> – die Veranstalter-App
 * liest daraus Seite und Code.
 */
class TIX_Staff_Login_Code {

    const NS  = 'tixomat/v1';
    const TTL = 600;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/staff/login-code', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'], 'permission_callback' => [__CLASS__, 'perm'],
        ]);
        register_rest_route(self::NS, '/auth/login-code', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_redeem'], 'permission_callback' => '__return_true',
        ]);
    }

    public static function perm() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        return self::is_staff(get_current_user_id()) ? true : new WP_Error('rest_forbidden', 'Nur für Team-Konten.', ['status' => 403]);
    }

    private static function is_staff($uid) {
        return class_exists('TIX_Staff_Push') ? TIX_Staff_Push::is_staff($uid) : user_can($uid, 'manage_options');
    }

    /** Darf $me für $target einen Code erzeugen? */
    public static function may_issue_for($me, $target) {
        if ($me === $target) return true;
        if (!self::is_staff($target)) return false;
        $tu = get_user_by('id', $target);
        if (!$tu || in_array('administrator', (array) $tu->roles, true)) return false; // nie für Admin-Konten
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        if (!$multi) return user_can($me, 'manage_options') || in_array('tix_organizer', (array) get_userdata($me)->roles, true);
        $oid = TIX_App_Scope::organizer_id_for_user($me);
        return $oid && TIX_App_Scope::is_org_manager($me) && TIX_App_Scope::organizer_id_for_user($target) === $oid;
    }

    private static function key($code) {
        return 'tix_slc_' . hash_hmac('sha256', strtoupper($code), wp_salt('auth'));
    }

    public static function rest_create(WP_REST_Request $req) {
        $me = get_current_user_id();
        $target = intval($req->get_param('user_id')) ?: $me;
        if (!self::may_issue_for($me, $target)) return new WP_Error('rest_forbidden', 'Für dieses Konto darfst du keinen Anmelde-Code erzeugen.', ['status' => 403]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('slc_new', 20, 600, (string) $me)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Codes. Bitte warte kurz.', ['status' => 429]);
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 10; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        set_transient(self::key($code), ['user' => $target, 'by' => $me, 'exp' => time() + self::TTL], self::TTL);
        $u = get_userdata($target);
        return rest_ensure_response(['ok' => true, 'code' => $code, 'expires_in' => self::TTL,
            'url' => add_query_arg('tix_app_login', $code, home_url('/')), 'user' => ['id' => $target, 'name' => $u ? $u->display_name : '']]);
    }

    public static function rest_redeem(WP_REST_Request $req) {
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('slc_use', 10, 600)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Versuche. Bitte warte ein paar Minuten.', ['status' => 429]);
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $req->get_param('code')));
        $data = $code !== '' ? get_transient(self::key($code)) : false;
        if (!is_array($data) || intval($data['exp'] ?? 0) < time()) {
            return new WP_Error('tix_code_invalid', 'Der Code ist abgelaufen oder wurde schon benutzt. Bitte im Studio einen neuen erzeugen.', ['status' => 401]);
        }
        delete_transient(self::key($code)); // genau einmal
        $user = get_user_by('id', intval($data['user']));
        if (!$user || !self::is_staff($user->ID)) return new WP_Error('tix_code_invalid', 'Dieses Konto hat keinen Zugang mehr.', ['status' => 401]);
        $token = TIX_REST_API::issue_app_token($user->ID, (string) ($req->get_param('device') ?? 'app-qr'));
        return rest_ensure_response(['success' => true, 'token' => $token, 'user' => TIX_REST_API::format_guest_user($user)]);
    }
}
