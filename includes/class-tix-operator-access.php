<?php
if (!defined('ABSPATH')) exit;

/**
 * „Als Betreiber anmelden“ (1.38.722, Auftrag uebergaben/auftrag-betreiber-zugang-und-zahlung-startklar-2026-11-10.md).
 *
 * Plattform (Lizenz-Server, evendis.de):
 *   POST /platform/customers/{key}/operator-login   nur Plattform-Admin, nur Lizenz-Kunden mit gebundener Seite
 *        → {ok, ticket, host, site_url, redeem_url, expires_at}   Einmal-Ticket, 60 s gültig, an Seite + Admin gebunden
 * Kunden-Seite:
 *   POST /studio/operator-login {ticket}            → {ok, token, expires_at, user, operator}   Studio-Token 8 h
 *   GET|POST /studio/operator-access {enabled}      Inhaber: Schalter (Vorgabe an) + Protokoll; aus = Betreiber-Tokens ungültig
 *   GET /me                                          + `operator` {active, by, since, until} für den Hinweis-Balken
 * Ticket: base64url(JSON {v, host, admin {id, name, email}, nonce, iat, exp}) . "." . HMAC-SHA256("tix-op-v1|" . payload, Lizenzschlüssel)
 * Technisches Konto „evendis Betreiber“ (Rolle tix_organizer, Meta `_tix_operator`): kein Passwort-Login, kein Passwort-Reset,
 * keine Änderung von Zahlungs-/Mail-Schlüsseln, Passwörtern oder dem eigenen Zugang.
 */
class TIX_Operator_Access {

    const NS          = 'tixomat/v1';
    const META        = '_tix_operator';
    const META_LAST   = '_tix_operator_last';
    const OPT_ENABLED = 'tix_operator_access';
    const OPT_LOG     = 'tix_operator_log';
    const OPT_NONCES  = 'tix_operator_nonces';
    const TICKET_TTL  = 60;
    const SESSION_TTL = 8 * HOUR_IN_SECONDS;
    const LOG_MAX     = 100;
    /** Für das Betreiber-Konto gesperrt (nur lesen erlaubt). */
    const BLOCKED     = '#^/tixomat/v1/(studio/payments|studio/mail-transport|studio/operator-access|auth/(password|profile|delete|logout-all)|license)(/|$)#';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('check_password', [__CLASS__, 'no_password'], 99, 4);
        add_filter('authenticate', [__CLASS__, 'no_login'], 99, 1);
        add_filter('allow_password_reset', [__CLASS__, 'no_reset'], 99, 2);
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard'], 6, 3);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'me_flag'], 20, 3);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function host() {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
    }

    public static function is_operator($uid = 0) {
        $uid = $uid ?: get_current_user_id();
        return $uid > 0 && get_user_meta($uid, self::META, true) === '1';
    }

    // ═══════════════ Ticket ═══════════════

    private static function b64e($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function b64d($s) { return base64_decode(strtr((string) $s, '-_', '+/') . str_repeat('=', (4 - strlen((string) $s) % 4) % 4), true); }
    private static function norm_key($key) { return strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim((string) $key))); }

    public static function sign($payload_b64, $key) {
        return hash_hmac('sha256', 'tix-op-v1|' . $payload_b64, self::norm_key($key));
    }

    public static function make_ticket($key, $host, array $admin, $now = null) {
        $now = $now ?: time();
        $p = ['v' => 1, 'host' => strtolower((string) $host), 'admin' => ['id' => intval($admin['id'] ?? 0), 'name' => (string) ($admin['name'] ?? ''), 'email' => (string) ($admin['email'] ?? '')],
            'nonce' => wp_generate_password(24, false, false), 'iat' => $now, 'exp' => $now + self::TICKET_TTL];
        $b = self::b64e(wp_json_encode($p));
        return [$b . '.' . self::sign($b, $key), $p];
    }

    /** Ticket prüfen → Nutzlast | WP_Error (ohne nonce-Verbrauch). */
    public static function read_ticket($ticket, $key) {
        $parts = explode('.', (string) $ticket);
        if (count($parts) !== 2 || $key === '') return new WP_Error('tix_operator_ticket', 'Ungültiges Anmelde-Ticket.', ['status' => 401]);
        if (!hash_equals(self::sign($parts[0], $key), $parts[1])) return new WP_Error('tix_operator_ticket', 'Ungültiges Anmelde-Ticket.', ['status' => 401]);
        $p = json_decode((string) self::b64d($parts[0]), true);
        if (!is_array($p) || intval($p['v'] ?? 0) !== 1 || empty($p['nonce'])) return new WP_Error('tix_operator_ticket', 'Ungültiges Anmelde-Ticket.', ['status' => 401]);
        if ((string) ($p['host'] ?? '') !== self::host()) return new WP_Error('tix_operator_ticket_host', 'Das Ticket gilt für eine andere Seite.', ['status' => 401]);
        $now = time();
        if (intval($p['exp'] ?? 0) < $now - 5 || intval($p['iat'] ?? 0) > $now + 30 || intval($p['exp']) - intval($p['iat']) > 2 * self::TICKET_TTL) {
            return new WP_Error('tix_operator_ticket_expired', 'Das Anmelde-Ticket ist abgelaufen. Bitte in der Kunden-Akte neu anmelden.', ['status' => 401]);
        }
        return $p;
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        if (class_exists('TIX_Platform_Customers')) {
            register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/operator-login', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_issue'],
                'permission_callback' => ['TIX_Platform_Customers', 'perm']]);
        }
        register_rest_route(self::NS, '/studio/operator-login', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_redeem'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/studio/operator-access', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_access'], 'permission_callback' => [__CLASS__, 'perm_owner']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_access_save'], 'permission_callback' => [__CLASS__, 'perm_owner']],
        ]);
    }

    // ── Plattform ──

    public static function rest_issue(WP_REST_Request $req) {
        if (!class_exists('TIX_License_Server') || !TIX_License_Server::enabled()) return new WP_Error('tix_license_server_off', 'Der Lizenz-Server ist hier nicht aktiv.', ['status' => 409]);
        $c = TIX_Platform_Customers::raw($req['key']);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        if (empty($c['_lic'])) return new WP_Error('tix_operator_no_site', 'Nur für Kunden-Seiten mit Lizenz.', ['status' => 409]);
        $host = '';
        foreach (TIX_License_Server::sites(intval($c['license_id'])) as $s) if (!$s->dev) { $host = strtolower(preg_replace('/^www\./', '', (string) $s->host)); break; }
        if ($host === '') return new WP_Error('tix_operator_no_site', 'Die Lizenz ist noch an keine Seite gebunden.', ['status' => 409]);
        $u = wp_get_current_user();
        [$ticket, $p] = self::make_ticket((string) $c['_lic']['row']->license_key, $host, ['id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email]);
        TIX_License_Server::log(intval($c['license_id']), 'operator_login', ['by' => $u->user_email, 'host' => $host, 'nonce' => substr($p['nonce'], 0, 6), 'ip' => self::ip()]);
        $r = rest_ensure_response(['ok' => true, 'ticket' => $ticket, 'host' => $host, 'site_url' => 'https://' . $host,
            'redeem_url' => 'https://' . $host . '/wp-json/' . self::NS . '/studio/operator-login', 'expires_at' => gmdate('c', $p['exp'])]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    // ── Kunden-Seite ──

    public static function enabled() {
        return get_option(self::OPT_ENABLED, '1') !== '0';
    }

    private static function ip() {
        return mb_substr(sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64);
    }

    public static function rest_redeem(WP_REST_Request $req) {
        if (self::multi() || !class_exists('TIX_License') || !TIX_License::active()) return new WP_Error('tix_operator_unavailable', 'Diese Seite nimmt keine Betreiber-Anmeldung an.', ['status' => 404]);
        if (class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'rate_limited') && TIX_App_Account::rate_limited('op_login', 10, 600)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Versuche. Bitte kurz warten.', ['status' => 429]);
        }
        $p = self::read_ticket((string) $req->get_param('ticket'), (string) get_option(TIX_License::OPT_KEY, ''));
        if (is_wp_error($p)) return $p;
        if (!self::enabled()) return new WP_Error('tix_operator_disabled', 'Der Inhaber hat den Betreiber-Zugang ausgeschaltet.', ['status' => 403]);
        // nonce einmalig
        $used = (array) get_option(self::OPT_NONCES, []);
        $now = time();
        $used = array_filter($used, function ($exp) use ($now) { return intval($exp) > $now - 300; });
        if (isset($used[$p['nonce']])) return new WP_Error('tix_operator_ticket_used', 'Dieses Anmelde-Ticket wurde schon benutzt.', ['status' => 401]);
        $used[$p['nonce']] = intval($p['exp']);
        update_option(self::OPT_NONCES, $used, false);
        $uid = self::account();
        if (is_wp_error($uid)) return $uid;
        $token = TIX_REST_API::issue_app_token($uid, 'evendis Betreiber');
        $until = $now + self::SESSION_TTL;
        $list = (array) get_user_meta($uid, '_tix_app_tokens', true);
        $h = hash('sha256', $token);
        foreach ($list as $i => $t) if (is_array($t) && ($t['token'] ?? '') === $h) $list[$i]['expires'] = $until;
        update_user_meta($uid, '_tix_app_tokens', array_values($list));
        if (method_exists('TIX_REST_API', 'sync_token_index')) TIX_REST_API::sync_token_index($uid);
        $by = ['name' => mb_substr(sanitize_text_field((string) ($p['admin']['name'] ?? '')), 0, 120), 'email' => sanitize_email((string) ($p['admin']['email'] ?? ''))];
        $entry = ['at' => gmdate('c', $now), 'until' => gmdate('c', $until), 'by' => $by['name'], 'by_email' => $by['email'], 'ip' => self::ip()];
        update_user_meta($uid, self::META_LAST, $entry);
        $log = (array) get_option(self::OPT_LOG, []);
        array_unshift($log, $entry);
        update_option(self::OPT_LOG, array_slice($log, 0, self::LOG_MAX), false);
        $u = get_userdata($uid);
        $r = rest_ensure_response(['ok' => true, 'token' => $token, 'expires_at' => gmdate('c', $until),
            'user' => ['id' => $uid, 'display_name' => $u ? $u->display_name : 'evendis Betreiber'], 'operator' => self::session_payload($uid)]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** Technisches Konto „evendis Betreiber“ (einmal anlegen, danach wiederverwenden). */
    public static function account() {
        $ids = get_users(['meta_key' => self::META, 'meta_value' => '1', 'fields' => 'ID', 'number' => 1]);
        if ($ids) return intval($ids[0]);
        $login = 'evendis-betreiber';
        $i = 2;
        while (username_exists($login)) $login = 'evendis-betreiber-' . $i++;
        $uid = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(48, true, true), 'user_email' => $login . '@betreiber.evendis.invalid',
            'display_name' => 'evendis Betreiber', 'first_name' => 'evendis', 'last_name' => 'Betreiber', 'role' => 'tix_organizer']);
        if (is_wp_error($uid)) return new WP_Error('tix_operator_account', 'Das Betreiber-Konto konnte nicht angelegt werden: ' . $uid->get_error_message(), ['status' => 500]);
        update_user_meta($uid, self::META, '1');
        return intval($uid);
    }

    public static function session_payload($uid) {
        $l = get_user_meta($uid, self::META_LAST, true);
        if (!is_array($l)) return ['active' => true, 'by' => null, 'since' => null, 'until' => null];
        return ['active' => true, 'by' => $l['by'] ?? null, 'since' => $l['at'] ?? null, 'until' => $l['until'] ?? null, 'label' => 'Als Betreiber angemeldet (evendis)'];
    }

    public static function perm_owner(WP_REST_Request $req) {
        if (self::multi()) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        if (!class_exists('TIX_Studio')) return new WP_Error('rest_forbidden', 'Nicht verfügbar.', ['status' => 403]);
        $r = TIX_Studio::perm_brand($req);
        if ($r !== true) return $r;
        if (self::is_operator()) return new WP_Error('tix_operator_forbidden', 'Diese Einstellung ändert nur der Inhaber.', ['status' => 403]);
        return true;
    }

    private static function access_payload() {
        $ids = get_users(['meta_key' => self::META, 'meta_value' => '1', 'fields' => 'ID', 'number' => 1]);
        $active = null;
        if ($ids) {
            $l = get_user_meta(intval($ids[0]), self::META_LAST, true);
            if (is_array($l) && strtotime((string) ($l['until'] ?? '')) > time() && self::has_valid_token(intval($ids[0]))) $active = ['by' => $l['by'] ?? null, 'since' => $l['at'] ?? null, 'until' => $l['until']];
        }
        return ['ok' => true, 'enabled' => self::enabled(), 'available' => class_exists('TIX_License') && TIX_License::active(), 'active' => $active, 'log' => array_values((array) get_option(self::OPT_LOG, []))];
    }

    private static function has_valid_token($uid) {
        foreach ((array) get_user_meta($uid, '_tix_app_tokens', true) as $t) if (is_array($t) && intval($t['expires'] ?? 0) > time()) return true;
        return false;
    }

    public static function rest_access(WP_REST_Request $req) {
        return rest_ensure_response(self::access_payload());
    }

    public static function rest_access_save(WP_REST_Request $req) {
        if ($req->get_param('enabled') === null) return new WP_Error('tix_invalid_field', 'enabled fehlt.', ['status' => 400, 'field' => 'enabled']);
        $on = filter_var($req->get_param('enabled'), FILTER_VALIDATE_BOOLEAN);
        update_option(self::OPT_ENABLED, $on ? '1' : '0', false);
        if (!$on) self::revoke();
        $u = wp_get_current_user();
        $log = (array) get_option(self::OPT_LOG, []);
        array_unshift($log, ['at' => gmdate('c'), 'until' => null, 'by' => $u->display_name, 'by_email' => $u->user_email, 'ip' => self::ip(), 'action' => $on ? 'enabled' : 'disabled']);
        update_option(self::OPT_LOG, array_slice($log, 0, self::LOG_MAX), false);
        return rest_ensure_response(self::access_payload());
    }

    /** Alle Betreiber-Sitzungen beenden. */
    public static function revoke() {
        foreach (get_users(['meta_key' => self::META, 'meta_value' => '1', 'fields' => 'ID']) as $uid) {
            delete_user_meta($uid, '_tix_app_tokens');
            delete_user_meta($uid, '_tix_app_token');
            if (method_exists('TIX_REST_API', 'sync_token_index')) TIX_REST_API::sync_token_index($uid);
        }
    }

    // ═══════════════ Sperren für das Betreiber-Konto ═══════════════

    public static function no_password($check, $password, $hash, $user_id) {
        return $user_id && self::is_operator(intval($user_id)) ? false : $check;
    }

    public static function no_login($user) {
        if ($user instanceof WP_User && self::is_operator($user->ID)) return new WP_Error('tix_operator_no_login', 'Dieses Konto kann sich nicht mit Passwort anmelden.');
        return $user;
    }

    public static function no_reset($allow, $user_id) {
        return self::is_operator(intval($user_id)) ? false : $allow;
    }

    public static function guard($result, $server, $request) {
        if ($result !== null || !self::is_operator()) return $result;
        if ($request->get_method() === 'GET') return $result;
        $route = (string) $request->get_route();
        $p = $request->get_json_params();
        $p = is_array($p) ? $p : (array) $request->get_body_params();
        $pw = array_intersect(array_map('strval', array_keys($p)), ['password', 'new_password', 'current_password', 'user_pass', 'api_key', 'secret']);
        if (preg_match(self::BLOCKED, $route) || $pw) {
            return new WP_Error('tix_operator_forbidden', 'Als Betreiber lassen sich Passwörter, Zahlungs- und Mail-Zugangsdaten nicht ändern.', ['status' => 403]);
        }
        return $result;
    }

    /** GET /me: `operator` für den Hinweis-Balken (null für normale Konten). */
    public static function me_flag($response, $handler, $request) {
        if ($request->get_route() !== '/' . self::NS . '/me' || is_wp_error($response)) return $response;
        $response = rest_ensure_response($response);
        $d = $response->get_data();
        if (!is_array($d)) return $response;
        $d['operator'] = self::is_operator() ? self::session_payload(get_current_user_id()) : null;
        $response->set_data($d);
        return $response;
    }
}

TIX_Operator_Access::init();
