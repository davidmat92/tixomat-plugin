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
        if (isset($c['nav']) && is_array($c['nav'])) unset($c['nav']['sos']);
        // SOS-Menüpunkt (TIX_App_Sos, 1.38.434): nur wenn eingeschaltet, sonst Antwort unverändert
        $sos = class_exists('TIX_App_Sos') ? TIX_App_Sos::nav_block() : null;
        if (empty($c)) {
            // Leer → App fällt auf die WordPress-Seite / Defaults zurück (mit SOS: nur `nav`, ohne `ok`)
            return rest_ensure_response($sos ? ['nav' => ['sos' => $sos]] : new stdClass());
        }
        if ($sos) {
            $c['nav'] = (isset($c['nav']) && is_array($c['nav']) ? $c['nav'] : []);
            $c['nav']['sos'] = $sos;
        }
        $c['ok'] = true;
        return rest_ensure_response($c);
    }

    /** Editor: aktuelle Overrides laden (auch wenn leer). */
    public static function rest_admin_get(WP_REST_Request $req) {
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped()) {
            return TIX_App_Scope::deny('Die App-Inhalte pflegt der Plattform-Betreiber.');
        }
        return rest_ensure_response(['config' => (object) self::stored()] + (class_exists('TIX_App_Sos') ? ['nav_sos' => TIX_App_Sos::settings()] : []));
    }

    public static function rest_save(WP_REST_Request $req) {
        // Mehr-Veranstalter-Modus: App-Inhalte gelten für die ganze Plattform → nur Admins
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && TIX_App_Scope::scoped()) {
            return TIX_App_Scope::deny('Die App-Inhalte pflegt der Plattform-Betreiber. Deine Veranstalter-Seite bearbeitest du unter „Veranstalter-Seite“.');
        }
        $b = $req->get_json_params();
        if (!is_array($b)) {
            return new WP_Error('bad_data', 'Ungültige Daten.', ['status' => 400]);
        }
        unset($b['ok']);
        // SOS-Menüpunkt liegt in eigener Option (bleibt unverändert, wenn `nav.sos` fehlt)
        if (isset($b['nav']['sos']) && is_array($b['nav']['sos']) && class_exists('TIX_App_Sos')) {
            $sos = $b['nav']['sos'];
            unset($sos['icon'], $sos['live']);
            TIX_App_Sos::save($sos);
        }
        if (isset($b['nav']) && is_array($b['nav'])) {
            unset($b['nav']['sos']);
            if (!$b['nav']) unset($b['nav']);
        }
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
