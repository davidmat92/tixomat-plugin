<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Partner_Page – „Deine Veranstalterseite“ für Einzel-Veranstalter-Seiten (KitchenKlub, Mallorca …): Brücke zum
 * Partner-Eintrag auf evendis.de (1.38.450).
 *
 * Partner-Seite (Ein-Veranstalter-Betrieb; Admin bzw. Rolle Veranstalter):
 *   GET  /partner/evendis-page                → Profil wie /organizer/profile + {connected, page_url, slug} | {connected:false, reason}
 *   POST /partner/evendis-page                → Felder wie POST /organizer/profile
 *   POST /partner/evendis-page/image          → multipart `file` + `kind` (logo|hero)
 *   Weitergereicht über die Verteilung: `syndication_api_url` + Partner-Schlüssel `syndication_api_key` (X-Tix-Syndication-Key).
 * Plattform (evendis.de, Mehr-Veranstalter):
 *   GET/POST /syndicate/organizer, POST /syndicate/organizer/image {kind, filename, data (Base64)}
 *   Nur mit dem eigenen Schlüssel eines Partners aus dem Partner-Verzeichnis (nie Nutzer-Logins, nie der gemeinsame
 *   Schlüssel); der Partner liest und ändert genau den ihm zugeordneten Veranstalter (`organizer_id`).
 */
class TIX_Partner_Page {

    const NS = 'tixomat/v1';

    /** Partner der laufenden Plattform-Anfrage */
    private static $partner = null;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function register_routes() {
        if (self::multi()) {
            $auth = [__CLASS__, 'check_partner_key'];
            register_rest_route(self::NS, '/syndicate/organizer', [
                ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_platform_get'], 'permission_callback' => $auth],
                ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_platform_save'], 'permission_callback' => $auth],
            ]);
            register_rest_route(self::NS, '/syndicate/organizer/image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_platform_image'], 'permission_callback' => $auth]);
            return;
        }
        $perm = [__CLASS__, 'perm_partner_site'];
        register_rest_route(self::NS, '/partner/evendis-page', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $perm],
        ]);
        register_rest_route(self::NS, '/partner/evendis-page/image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_image'], 'permission_callback' => $perm]);
    }

    // ═══════════════ Plattform (evendis.de) ═══════════════

    /** Nur der eigene Schlüssel eines Partners (Partner-Verzeichnis). */
    public static function check_partner_key(WP_REST_Request $req) {
        self::$partner = null;
        $key = (string) $req->get_header('X-Tix-Syndication-Key');
        $p = class_exists('TIX_Partners') ? TIX_Partners::find_by_key($key) : null;
        if (!$p) {
            // Fehlversuche je IP: ab 30 in 10 Minuten 429
            if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('partner_page_fail', 30, 600)) {
                return new WP_Error('tix_rate_limit', 'Zu viele Fehlversuche. Bitte später erneut versuchen.', ['status' => 429]);
            }
            return new WP_Error('unauthorized', 'Ungültiger Partner-Schlüssel.', ['status' => 401]);
        }
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('partner_page', 120, 600, 'p' . $p['id'])) {
            return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte später erneut versuchen.', ['status' => 429]);
        }
        self::$partner = $p;
        return true;
    }

    /** Dem Partner zugeordneter Veranstalter oder WP_Error. */
    private static function partner_org() {
        $oid = intval(self::$partner['organizer_id'] ?? 0);
        if (!$oid || get_post_type($oid) !== 'tix_organizer' || in_array(get_post_status($oid), ['trash', false], true)) {
            return new WP_Error('tix_partner_no_organizer', 'Diesem Partner ist auf der Plattform noch keine Veranstalterseite zugeordnet.', ['status' => 404]);
        }
        return $oid;
    }

    private static function platform_payload($oid) {
        $slug = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($oid) : (string) get_post_field('post_name', $oid);
        $base = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::base() : '';
        $base = $base !== '' ? $base : untrailingslashit(home_url());
        $out = TIX_App_Scope::profile_for($oid);
        $out['slug'] = $slug;
        $out['page_url'] = $base . '/veranstalter/' . $slug . '/';
        return $out;
    }

    public static function rest_platform_get(WP_REST_Request $req) {
        $oid = self::partner_org();
        return is_wp_error($oid) ? $oid : rest_ensure_response(self::platform_payload($oid));
    }

    public static function rest_platform_save(WP_REST_Request $req) {
        $oid = self::partner_org();
        if (is_wp_error($oid)) return $oid;
        $b = $req->get_json_params();
        $r = TIX_App_Scope::save_profile($oid, is_array($b) ? $b : []);
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(self::platform_payload($oid));
    }

    public static function rest_platform_image(WP_REST_Request $req) {
        $oid = self::partner_org();
        if (is_wp_error($oid)) return $oid;
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('partner_page_img', 20, HOUR_IN_SECONDS, 'p' . self::$partner['id'])) {
            return new WP_Error('tix_rate_limit', 'Zu viele Bilder. Bitte später erneut versuchen.', ['status' => 429]);
        }
        $raw = (string) $req->get_param('data');
        if (strlen($raw) > 7 * MB_IN_BYTES) return new WP_Error('tix_too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => 'file']);
        $bytes = base64_decode($raw, true);
        if ($bytes === false) return new WP_Error('bad_type', 'Ungültige Bilddaten.', ['status' => 400, 'field' => 'file']);
        $aid = TIX_App_Scope::store_profile_image($oid, sanitize_key((string) $req->get_param('kind')), $bytes);
        if (is_wp_error($aid)) return $aid;
        return rest_ensure_response(self::platform_payload($oid));
    }

    // ═══════════════ Partner-Seite (KitchenKlub, Mallorca …) ═══════════════

    public static function perm_partner_site(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options') || in_array('tix_organizer', (array) $u->roles, true)) return true;
        return new WP_Error('rest_forbidden', 'Nur Admins und Veranstalter können die Veranstalterseite pflegen.', ['status' => 403]);
    }

    private static function bridge_configured() {
        return function_exists('tix_get_settings') && trim((string) tix_get_settings('syndication_api_url')) !== '' && trim((string) tix_get_settings('syndication_api_key')) !== '';
    }

    /** Aufruf der Plattform: [HTTP-Status, Body (Array)] oder WP_Error bei Netzwerkfehlern. */
    private static function call($method, $path, $data = null) {
        $base = rtrim((string) tix_get_settings('syndication_api_url'), '/');
        $args = ['method' => $method, 'timeout' => 20, 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Syndication-Key' => (string) tix_get_settings('syndication_api_key')]];
        if ($data !== null) $args['body'] = wp_json_encode($data);
        $r = wp_remote_request($base . $path, $args);
        if (is_wp_error($r)) return $r;
        $body = json_decode((string) wp_remote_retrieve_body($r), true);
        return [intval(wp_remote_retrieve_response_code($r)), is_array($body) ? $body : []];
    }

    /** Antwort der Plattform übersetzen: Profil + connected bzw. {connected:false, reason} bzw. Fehler durchreichen. */
    private static function relay($res) {
        if (is_wp_error($res)) return rest_ensure_response(['ok' => true, 'connected' => false, 'reason' => 'unreachable', 'message' => 'evendis.de ist gerade nicht erreichbar.']);
        list($code, $body) = $res;
        if ($code >= 200 && $code < 300) {
            unset($body['key'], $body['key_in'], $body['key_out'], $body['api_key']);
            return rest_ensure_response(['connected' => true] + $body);
        }
        $c = (string) ($body['code'] ?? '');
        if ($code === 401) return rest_ensure_response(['ok' => true, 'connected' => false, 'reason' => 'unauthorized', 'message' => 'Die Verbindung zu evendis.de ist ungültig (Partner-Schlüssel prüfen).']);
        if ($code === 404 && $c === 'tix_partner_no_organizer') return rest_ensure_response(['ok' => true, 'connected' => false, 'reason' => 'no_organizer', 'message' => (string) ($body['message'] ?? '')]);
        if ($code === 404) return rest_ensure_response(['ok' => true, 'connected' => false, 'reason' => 'unsupported', 'message' => 'evendis.de kennt diese Funktion noch nicht.']);
        // Prüffehler (400/413/429) durchreichen
        $data = ['status' => $code ?: 502];
        if (!empty($body['data']['field'])) $data['field'] = (string) $body['data']['field'];
        return new WP_Error($c !== '' ? $c : 'tix_bridge_error', (string) ($body['message'] ?? 'Fehler bei evendis.de.'), $data);
    }

    public static function rest_get(WP_REST_Request $req) {
        if (!self::bridge_configured()) return rest_ensure_response(['ok' => true, 'connected' => false, 'reason' => 'not_configured', 'message' => 'Diese Seite ist noch nicht mit evendis.de verbunden.']);
        return self::relay(self::call('GET', '/syndicate/organizer'));
    }

    public static function rest_save(WP_REST_Request $req) {
        if (!self::bridge_configured()) return rest_ensure_response(['ok' => true, 'connected' => false, 'reason' => 'not_configured', 'message' => 'Diese Seite ist noch nicht mit evendis.de verbunden.']);
        $b = $req->get_json_params();
        return self::relay(self::call('POST', '/syndicate/organizer', is_array($b) ? $b : []));
    }

    public static function rest_image(WP_REST_Request $req) {
        if (!self::bridge_configured()) return rest_ensure_response(['ok' => true, 'connected' => false, 'reason' => 'not_configured', 'message' => 'Diese Seite ist noch nicht mit evendis.de verbunden.']);
        $files = $req->get_file_params();
        $f = $files['file'] ?? ($files['image'] ?? null);
        if (empty($f['tmp_name'])) return new WP_Error('no_file', 'Kein Bild übermittelt.', ['status' => 400, 'field' => 'file']);
        if (!empty($f['error'])) return new WP_Error('tix_too_large', 'Upload fehlgeschlagen (höchstens 5 MB).', ['status' => in_array(intval($f['error']), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400, 'field' => 'file']);
        if (intval($f['size'] ?? 0) > 5 * MB_IN_BYTES) return new WP_Error('tix_too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => 'file']);
        $info = @getimagesize($f['tmp_name']);
        if (!is_array($info) || !in_array((string) $info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) return new WP_Error('bad_type', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.', ['status' => 400, 'field' => 'file']);
        $kind = sanitize_key((string) $req->get_param('kind')) === 'hero' ? 'hero' : 'logo';
        return self::relay(self::call('POST', '/syndicate/organizer/image', ['kind' => $kind, 'filename' => sanitize_file_name((string) ($f['name'] ?? 'bild')), 'data' => base64_encode((string) file_get_contents($f['tmp_name']))]));
    }
}
