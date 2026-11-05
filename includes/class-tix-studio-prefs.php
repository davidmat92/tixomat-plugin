<?php
/**
 * TIX_Studio_Prefs – Einstellungen des Studios je Konto (1.38.655): Menü-Ansicht kompakt bzw. komplett.
 *
 * User-Meta `_tix_studio_menu_mode` (compact|full, fehlt = Vorgabe). Vorgabe `menu_mode_default` (User 2026-11-05):
 * full für Admins, Vertrieb und Plattform-Betreiber; sonst full für Konten, die es beim Ausrollen schon gab, compact für
 * Konten, die danach angelegt werden (Stichtag Option `tix_studio_menu_cutoff`, beim ersten Laden gesetzt).
 * GET /me liefert `studio_prefs`, POST /studio/prefs {menu_mode} speichert (jedes angemeldete Studio-Konto).
 */
if (!defined('ABSPATH')) exit;

class TIX_Studio_Prefs {

    const NS = 'tixomat/v1';
    const META = '_tix_studio_menu_mode';
    const OPT_CUTOFF = 'tix_studio_menu_cutoff';
    const MODES = ['compact', 'full'];

    public static function init() {
        add_action('init', [__CLASS__, 'cutoff'], 5);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    /** Stichtag: Zeitpunkt des Ausrollens (UTC) – bestehende Konten behalten „komplett“ */
    public static function cutoff() {
        $c = (string) get_option(self::OPT_CUTOFF, '');
        if ($c === '') { $c = current_time('mysql', true); add_option(self::OPT_CUTOFF, $c, '', false); }
        return $c;
    }

    public static function default_for($user) {
        if (!$user || !$user->ID) return 'full';
        if (user_can($user, 'manage_options')) return 'full';
        if (class_exists('TIX_Sales') && TIX_Sales::is_sales_user($user)) return 'full';
        return (string) $user->user_registered > self::cutoff() ? 'compact' : 'full';
    }

    public static function payload($user = null) {
        $user = $user ?: wp_get_current_user();
        $m = $user && $user->ID ? (string) get_user_meta($user->ID, self::META, true) : '';
        return ['menu_mode' => in_array($m, self::MODES, true) ? $m : null, 'menu_mode_default' => self::default_for($user)];
    }

    public static function routes() {
        register_rest_route(self::NS, '/studio/prefs', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'],
            'permission_callback' => function () { return is_user_logged_in() ? true : new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]); }]);
    }

    /** POST /studio/prefs {menu_mode: compact|full|null} – null setzt auf die Vorgabe zurück */
    public static function rest_save(WP_REST_Request $req) {
        $uid = get_current_user_id();
        if (!$req->has_param('menu_mode')) return new WP_Error('tix_invalid_field', 'menu_mode fehlt.', ['status' => 400, 'field' => 'menu_mode']);
        $m = $req->get_param('menu_mode');
        if ($m === null || $m === '') delete_user_meta($uid, self::META);
        elseif (in_array((string) $m, self::MODES, true)) update_user_meta($uid, self::META, (string) $m);
        else return new WP_Error('tix_invalid_field', 'Menü-Ansicht: compact oder full.', ['status' => 400, 'field' => 'menu_mode']);
        $r = rest_ensure_response(['ok' => true, 'studio_prefs' => self::payload(wp_get_current_user())]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
}

TIX_Studio_Prefs::init();
