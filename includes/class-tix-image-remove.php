<?php
if (!defined('ABSPATH')) exit;

/**
 * Bilder entfernen (1.38.741, User: „überall Bilder auch entfernen können“). Gleiches Muster: DELETE auf der Upload-Route.
 * Diese Klasse ergänzt die Routen, deren Klassen kein Entfernen hatten (WordPress hängt die DELETE-Endpunkte an die Route an):
 *   DELETE /events/{id}/image                  Flyer (Beitragsbild)
 *   DELETE /events/{id}/charity-image          Bild der Spendenaktion
 *   DELETE /events/{id}/sponsor-image          Bild des Ticket-Sponsors
 *   DELETE /events/{id}/lineup-photo?name=…    Act-Foto im Line-up (Act bewusst ohne Foto)
 *   DELETE /auth/profile/avatar                eigenes Profilbild (Gast-/Kundenkonto)
 *   DELETE /app/tips/{id}/image?kind=author|image
 * Rechte wie beim Hochladen. Kopien geteilter Events (Spiegel) sind ausgenommen: 409 tix_mirrored_readonly.
 * Weitere Wege: /artists/{id}/image (TIX_Artists), /organizer/profile/image, /partner/evendis-page/image (Brücke),
 * /studio/placeholder (seit 1.38.460), /studio/config {logo_id|logo_on_light_id|mail_*: 0}.
 */
class TIX_Image_Remove {

    const NS = 'tixomat/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes'], 20);
    }

    public static function routes() {
        $org = ['permission_callback' => ['TIX_REST_API', 'check_organizer']];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/image', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'event_image']] + $org);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/charity-image', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'event_image']] + $org);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/sponsor-image', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'event_image']] + $org);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/lineup-photo', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'lineup_photo']] + $org);
        register_rest_route(self::NS, '/auth/profile/avatar', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'avatar'], 'permission_callback' => function () { return is_user_logged_in() ? true : new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]); }]);
        if (class_exists('TIX_App_Tips')) register_rest_route(self::NS, '/app/tips/(?P<id>\d+)/image', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'tip_image'], 'permission_callback' => function () { return current_user_can('manage_options') || (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) && in_array('tix_organizer', (array) wp_get_current_user()->roles, true)) ? true : new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]); }]);
    }

    /** Event prüfen: vorhanden, Zugriff, keine Kopie eines geteilten Events → true | WP_Error */
    private static function event_ok($id) {
        if (get_post_type($id) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $copy = class_exists('TIX_Artists') && method_exists('TIX_Artists', 'is_copy') ? TIX_Artists::is_copy($id) : get_post_meta($id, '_tix_syndicated', true) === '1';
        if ($copy) return new WP_Error('tix_mirrored_readonly', 'Dieses Event kommt von einer anderen Seite und wird dort gepflegt – hier nur lesbar.', ['status' => 409]);
        return true;
    }

    private static function done($id) {
        clean_post_cache($id);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        if (class_exists('TIX_Syndication_Push') && get_post_meta($id, '_tix_syndicate', true) === '1') TIX_Syndication_Push::push_event($id); // an die Spiegel
        return rest_ensure_response(['ok' => true, 'removed' => true, 'id' => intval($id)]);
    }

    public static function event_image(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (is_wp_error($ok = self::event_ok($id))) return $ok;
        $route = (string) $req->get_route();
        if (substr($route, -14) === '/charity-image') delete_post_meta($id, '_tix_charity_image');
        elseif (substr($route, -14) === '/sponsor-image') { delete_post_meta($id, '_tix_ticket_sponsor_image_id'); delete_post_meta($id, '_tix_ticket_sponsor_image_url'); }
        else delete_post_thumbnail($id);
        return self::done($id);
    }

    public static function lineup_photo(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (is_wp_error($ok = self::event_ok($id))) return $ok;
        $name = trim((string) $req->get_param('name'));
        if ($name === '') return new WP_Error('tix_invalid_field', 'Bitte den Act angeben.', ['status' => 400, 'field' => 'name']);
        $r = TIX_Lineup_Links::set_photo($id, $name, 0); // 0 = in diesem Event bewusst ohne Foto
        if (is_wp_error($r)) return $r;
        if (class_exists('TIX_Artists')) TIX_Artists::flush_cache();
        return self::done($id);
    }

    public static function avatar(WP_REST_Request $req) {
        $uid = get_current_user_id();
        $file = (string) get_user_meta($uid, '_tix_avatar_file', true);
        if ($file !== '') {
            $up = wp_upload_dir();
            $path = trailingslashit($up['basedir']) . 'tix-avatars/' . basename($file);
            if (is_file($path)) @unlink($path);
        }
        $old = intval(get_user_meta($uid, '_tix_avatar_id', true));
        if ($old) wp_delete_attachment($old, true);
        foreach (['_tix_avatar_file', '_tix_avatar_url', '_tix_avatar_id'] as $k) delete_user_meta($uid, $k);
        return rest_ensure_response(['ok' => true, 'removed' => true, 'avatar' => get_avatar_url($uid, ['size' => 96])]);
    }

    public static function tip_image(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (get_post_type($id) !== 'tix_app_tip') return new WP_Error('not_found', 'Tipp nicht gefunden.', ['status' => 404]);
        $keys = ['author' => '_tix_tip_author_image', 'image' => '_tix_tip_image'];
        $kind = sanitize_key((string) $req->get_param('kind'));
        if (!isset($keys[$kind])) return new WP_Error('tix_tip_kind', '„kind“ muss „author“ oder „image“ sein.', ['status' => 400]);
        delete_post_meta($id, $keys[$kind]);
        if (class_exists('TIX_App_Tips') && method_exists('TIX_App_Tips', 'flush')) TIX_App_Tips::flush();
        return rest_ensure_response(['ok' => true, 'removed' => true, 'id' => $id]);
    }
}

TIX_Image_Remove::init();
