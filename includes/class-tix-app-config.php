<?php
if (!defined('ABSPATH')) exit;

/**
 * App-Inhalte ohne WordPress bearbeiten.
 *
 * Die App liest ihre Konfiguration (Kacheln, Seiten-Texte wie FAQ/Ü16/Kontakt,
 * Hashtag, Kontaktdaten) zuerst von `GET /app/config` und legt sie über ihre
 * gebündelten Defaults (`AppContent.mergeJson`). Diese Klasse speichert genau
 * diese JSON-Overrides in der Option `_tix_app_config` und lässt sie über
 * `POST /app/config` (Veranstalter-Auth) im App-Editor bearbeiten.
 *
 * Ist nichts gespeichert, liefert GET `{}` – dann greift wie bisher die
 * WordPress-Seite `app-config` bzw. es bleibt bei den Defaults.
 */
class TIX_App_Config {
    const NS = 'tixomat/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function stored() {
        $c = get_option('_tix_app_config', []);
        return is_array($c) ? $c : [];
    }

    public static function rest_get(WP_REST_Request $req) {
        $c = self::stored();
        if (empty($c)) {
            // Leer → App fällt auf die WordPress-Seite / Defaults zurück.
            return rest_ensure_response(new stdClass());
        }
        $c['ok'] = true;
        return rest_ensure_response($c);
    }

    /** Editor: aktuelle Overrides laden (auch wenn leer). */
    public static function rest_admin_get(WP_REST_Request $req) {
        return rest_ensure_response(['config' => (object) self::stored()]);
    }

    public static function rest_save(WP_REST_Request $req) {
        $b = $req->get_json_params();
        if (!is_array($b)) {
            return new WP_Error('bad_data', 'Ungültige Daten.', ['status' => 400]);
        }
        unset($b['ok']);
        update_option('_tix_app_config', $b, false);
        return rest_ensure_response(['ok' => true]);
    }

    public static function register_routes() {
        $organizer = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/app/config', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/app/config', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'],
            'permission_callback' => $organizer,
        ]);
        register_rest_route(self::NS, '/app/config/edit', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_admin_get'],
            'permission_callback' => $organizer,
        ]);
    }
}
