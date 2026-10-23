<?php
/**
 * TIX_Club – Pflege der Homepage-Seite „Der Club“ (1.38.539, Vertrag uebergaben/vertrag-club-seite.md §2a).
 *
 *   GET  /studio/club        {club: {...} | null, locations: [{id, title, city}]}
 *   POST /studio/club        {location_id?, tagline?, short_description?, description?, image_id?, gallery?, hours?,
 *                             hours_note?, phone?, email?, website?} → wie GET; location_id setzt site-config venue.id
 *   POST /studio/club/image  Multipart image (≤ 15 MB), rights_confirmed=true, credit → {image: {id, url, small, credit}};
 *                            vorläufig (TIX_Register_API::TMP_META), unbenutzt nach 24 h gelöscht
 *
 * Nur Einzel-Veranstalter-Seiten (im Mehr-Veranstalter-Modus nicht registriert → 404); Rechte wie Farbe/Logo
 * (Admin, Inhaber). Felder und Prüfung: TIX_Venues::clean_page_input, öffentlich: GET /public/venues/{id}.
 */
if (!defined('ABSPATH')) exit;

class TIX_Club {

    const NS = 'tixomat/v1';
    const META_RIGHTS = '_tix_photo_rights';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    private static function multi() {
        return class_exists('TIX_Website') ? TIX_Website::multi() : (get_option('tix_multi_organizer') === '1');
    }

    public static function register_routes() {
        if (self::multi()) return;
        register_rest_route(self::NS, '/studio/club', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => [__CLASS__, 'perm']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => [__CLASS__, 'perm']],
        ]);
        register_rest_route(self::NS, '/studio/club/image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_image'], 'permission_callback' => [__CLASS__, 'perm']]);
    }

    public static function perm(WP_REST_Request $req) {
        $r = class_exists('TIX_Studio') ? TIX_Studio::perm_brand($req) : current_user_can('manage_options');
        if ($r === true) return true;
        if (is_wp_error($r) && $r->get_error_code() === 'rest_not_logged_in') return $r;
        return new WP_Error('tix_forbidden', 'Nur der Inhaber der Seite kann „Der Club“ ändern.', ['status' => 403]);
    }

    private static function bad($field, $msg) {
        return new WP_Error('tix_invalid', $msg, ['status' => 400, 'field' => $field]);
    }

    private static function img($aid, $credit = null) {
        $aid = intval($aid);
        $url = $aid ? (string) (wp_get_attachment_image_url($aid, 'large') ?: '') : '';
        if ($url === '') return null;
        $out = ['id' => $aid, 'url' => $url, 'small' => (string) (wp_get_attachment_image_url($aid, 'medium') ?: $url)];
        if ($credit !== null) $out['credit'] = (string) $credit;
        return $out;
    }

    /** Orte, die als Club wählbar sind (Einzel-Seite: alle veröffentlichten, zusammengeführte nur einmal). */
    private static function locations() {
        $ids = get_posts(['post_type' => 'tix_location', 'post_status' => 'publish', 'posts_per_page' => 300, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids']);
        $out = [];
        foreach ($ids as $lid) {
            if (class_exists('TIX_Locations') && TIX_Locations::canonical_id($lid) !== intval($lid)) continue;
            $out[] = ['id' => intval($lid), 'title' => html_entity_decode(get_the_title($lid), ENT_QUOTES, 'UTF-8'), 'city' => (string) get_post_meta($lid, '_tix_loc_city', true)];
        }
        return $out;
    }

    private static function club_id() {
        $lid = class_exists('TIX_Site_Config') ? TIX_Site_Config::venue_location_id() : 0;
        if ($lid && class_exists('TIX_Locations')) {
            $cid = TIX_Locations::canonical_id($lid);
            if (get_post_type($cid) === 'tix_location') $lid = $cid;
        }
        return intval($lid);
    }

    private static function club($lid) {
        if (!$lid) return null;
        $m = function ($k) use ($lid) { return (string) get_post_meta($lid, $k, true); };
        $hours = TIX_Venues::hours($lid);
        return [
            'location_id'       => intval($lid),
            'name'              => html_entity_decode(get_the_title($lid), ENT_QUOTES, 'UTF-8'),
            'address'           => $m('_tix_loc_address'),
            'zip'               => $m('_tix_loc_zip') ?: $m('_tix_loc_postcode'),
            'city'              => $m('_tix_loc_city'),
            'tagline'           => $m('_tix_loc_tagline'),
            'short_description' => $m('_tix_loc_short_desc'),
            'description'       => $m('_tix_loc_description'),
            'image'             => self::img($m('_tix_loc_image_id')),
            'gallery'           => array_values(array_filter(array_map(function ($g) { return self::img($g['id'], $g['credit']); }, TIX_Venues::gallery($lid)))),
            'hours'             => $hours ?: null,
            'hours_note'        => $m('_tix_loc_hours_note'),
            'phone'             => $m('_tix_loc_phone'),
            'email'             => $m('_tix_loc_email'),
            'website'           => $m('_tix_loc_website'),
        ];
    }

    private static function response() {
        return rest_ensure_response(['ok' => true, 'club' => self::club(self::club_id()), 'locations' => self::locations()]);
    }

    /** GET /studio/club */
    public static function rest_get(WP_REST_Request $req) {
        return self::response();
    }

    /** POST /studio/club */
    public static function rest_save(WP_REST_Request $req) {
        $p = $req->get_json_params();
        if (!is_array($p)) $p = $req->get_body_params();
        if (!is_array($p)) return self::bad('body', 'Bitte JSON senden.');
        $lid = self::club_id();
        $new = null;
        if (array_key_exists('location_id', $p)) {
            $new = intval($p['location_id']);
            if (!$new || get_post_type($new) !== 'tix_location' || get_post_status($new) !== 'publish') {
                return new WP_Error('tix_venue_not_found', 'Ort nicht gefunden.', ['status' => 404, 'field' => 'location_id']);
            }
            if (class_exists('TIX_Locations')) $new = TIX_Locations::canonical_id($new);
            $lid = $new;
        }
        if (!$lid) return new WP_Error('tix_venue_not_found', 'Es gibt noch keinen Ort. Bitte zuerst unter „Orte“ anlegen.', ['status' => 404]);
        $page = TIX_Venues::clean_page_input($p, 'tix_invalid', 10000);
        if (is_wp_error($page)) return $page;
        if ($new !== null && class_exists('TIX_Site_Config')) TIX_Site_Config::set_venue_id($new);
        foreach ($page as $meta => $val) update_post_meta($lid, $meta, $val);
        // Benutzte Bilder gehören jetzt zum Ort (nicht mehr vorläufig)
        $used = [];
        if (!empty($page['_tix_loc_image_id'])) $used[] = intval($page['_tix_loc_image_id']);
        if (isset($page['_tix_loc_gallery'])) foreach (TIX_Venues::gallery($lid) as $g) $used[] = $g['id'];
        foreach ($used as $aid) {
            if (class_exists('TIX_Register_API')) delete_post_meta($aid, TIX_Register_API::TMP_META);
            if (!get_post_field('post_parent', $aid)) wp_update_post(['ID' => $aid, 'post_parent' => $lid]);
        }
        delete_transient('tix_pub_events_venue_' . $lid); // auch bei Objekt-Cache sofort aktuell
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return self::response();
    }

    /** POST /studio/club/image */
    public static function rest_image(WP_REST_Request $req) {
        if (!filter_var($req->get_param('rights_confirmed'), FILTER_VALIDATE_BOOLEAN)) return self::bad('rights_confirmed', 'Bitte bestätigen, dass du die Rechte an diesem Bild hast.');
        $credit = trim(sanitize_text_field((string) $req->get_param('credit')));
        if ($credit === '') return self::bad('credit', 'Bitte die Quelle angeben („Foto: …“).');
        if (mb_strlen($credit) > 120) return self::bad('credit', 'Höchstens 120 Zeichen.');
        if (!class_exists('TIX_AI_REST') || !class_exists('TIX_Register_API')) return new WP_Error('tix_upload_failed', 'Upload nicht verfügbar.', ['status' => 500]);
        $img = TIX_AI_REST::read_image($req);
        if (is_wp_error($img)) return self::bad('image', $img->get_error_message());
        if ($img[1] === 'image/gif') return self::bad('image', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.');
        $aid = TIX_Register_API::store_tmp_image($img[0], $img[1]);
        if (!$aid) return new WP_Error('tix_upload_failed', 'Das Bild konnte nicht gespeichert werden.', ['status' => 500]);
        wp_update_post(['ID' => $aid, 'post_title' => 'Der Club', 'post_author' => get_current_user_id()]);
        update_post_meta($aid, self::META_RIGHTS, ['confirmed_by' => get_current_user_id(), 'at' => current_time('c'), 'credit' => $credit]);
        return rest_ensure_response(['ok' => true, 'image' => self::img($aid, $credit)]);
    }
}
