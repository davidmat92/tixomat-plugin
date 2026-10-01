<?php
if (!defined('ABSPATH')) exit;

/**
 * Mehr-Veranstalter-Modus für den App-Veranstalter-Bereich (evendis).
 *
 * Schalter: Option `tix_multi_organizer` = '1'. Ist er aus (Vorgabe, z. B.
 * kitchenklub.de mit einem einzigen Veranstalter), verhält sich jede Route
 * exakt wie vorher. Ist er an, sehen und ändern Veranstalter und ihr Team
 * NUR die Events ihres eigenen Veranstalters (und alles, was daran hängt:
 * Bestellungen, Tickets, Gäste, Kasse, Musikwünsche, Prämien, Nachrichten).
 * WordPress-Admins sehen weiterhin alles.
 *
 * Zuordnung Nutzer → Veranstalter (`tix_organizer`):
 *   - Inhaber: Meta `_tix_org_user_id` am Veranstalter (wie das Web-Dashboard)
 *   - Team:    User-Meta `_tix_team_organizer_id` (Rollen tix_organizer,
 *              tix_staff, tix_entrance, tix_dj; vergeben über `/team`)
 * Zählen nur veröffentlichte Veranstalter; ein noch nicht freigegebener
 * Veranstalter erscheint in `/me` mit seinem Status, hat aber keinen Zugriff.
 *
 * Eigene Routen: `GET/POST /organizer/profile`, `POST /organizer/profile/image`
 * (Veranstalter-Seite pflegen: Texte, Kontakt, Social, Module, Logo/Titelbild).
 */
class TIX_App_Scope {
    const NS            = 'tixomat/v1';
    const OPTION        = 'tix_multi_organizer';
    const META_TEAM_ORG = '_tix_team_organizer_id';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    // ──────────────────────────────────────────
    //  Grundlagen
    // ──────────────────────────────────────────

    /** Mehr-Veranstalter-Modus aktiv? */
    public static function multi() {
        return get_option(self::OPTION, '0') === '1';
    }

    public static function is_admin($user = null) {
        $user = $user ?: wp_get_current_user();
        return $user && $user->ID && $user->has_cap('manage_options');
    }

    /** Muss für den aktuellen Nutzer abgeschottet werden? (Modus an, kein Admin) */
    public static function scoped() {
        return self::multi() && !self::is_admin();
    }

    public static function deny($msg = 'Kein Zugriff.') {
        return new WP_Error('rest_forbidden', $msg, ['status' => 403]);
    }

    /** Veranstalter, bei dem der Nutzer Inhaber ist (beliebiger Status). */
    private static function owned_organizer($uid) {
        $uid = intval($uid);
        if (!$uid) return null;
        $orgs = get_posts([
            'post_type'      => 'tix_organizer',
            'post_status'    => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page' => 1,
            'meta_key'       => '_tix_org_user_id',
            'meta_value'     => $uid,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        return $orgs ? $orgs[0] : null;
    }

    /** Veranstalter, dem der Nutzer als Team-Mitglied zugeordnet ist. */
    private static function team_organizer($uid) {
        $oid = intval(get_user_meta(intval($uid), self::META_TEAM_ORG, true));
        if (!$oid) return null;
        $p = get_post($oid);
        return ($p && $p->post_type === 'tix_organizer') ? $p : null;
    }

    /** Veranstalter-Post des Nutzers (Inhaber vor Team), unabhängig vom Status. */
    public static function organizer_post_for_user($uid) {
        return self::owned_organizer($uid) ?: self::team_organizer($uid);
    }

    /** ID des freigegebenen Veranstalters des Nutzers oder 0. */
    public static function organizer_id_for_user($uid = 0) {
        $uid = $uid ?: get_current_user_id();
        $p = self::organizer_post_for_user($uid);
        return ($p && $p->post_status === 'publish') ? intval($p->ID) : 0;
    }

    /** Ist der Nutzer Inhaber (oder Team-Admin) seines Veranstalters? */
    public static function is_org_manager($uid = 0) {
        $uid = $uid ?: get_current_user_id();
        $own = self::owned_organizer($uid);
        if ($own && $own->post_status === 'publish') return true;
        $team = self::team_organizer($uid);
        if (!$team || $team->post_status !== 'publish') return false;
        $u = get_user_by('id', $uid);
        return $u && in_array('tix_organizer', (array) $u->roles, true);
    }

    /** Veranstalter-Infos für `/me` (oder null). */
    public static function me_payload($uid = 0) {
        $uid = $uid ?: get_current_user_id();
        $p = self::organizer_post_for_user($uid);
        if (!$p) return null;
        $owner = intval(get_post_meta($p->ID, '_tix_org_user_id', true)) === intval($uid);
        $logo = '';
        foreach (['_tix_org_landing_logo_id', '_tix_org_image_id'] as $k) {
            $aid = intval(get_post_meta($p->ID, $k, true));
            if ($aid && ($u = wp_get_attachment_image_url($aid, 'medium'))) { $logo = $u; break; }
        }
        return [
            'id'      => intval($p->ID),
            'name'    => html_entity_decode((string) $p->post_title, ENT_QUOTES, 'UTF-8'),
            'status'  => (string) $p->post_status,
            'owner'   => $owner,
            'manager' => self::is_org_manager($uid),
            'logo'    => $logo,
        ];
    }

    // ──────────────────────────────────────────
    //  Events, Bestellungen
    // ──────────────────────────────────────────

    /** Veranstalter-IDs eines Events (Haupt- und Co-Veranstalter). */
    public static function event_org_ids($event_id) {
        $ids = [];
        foreach (['_tix_organizer_id', '_tix_co_organizer_id'] as $k) {
            $v = intval(get_post_meta(intval($event_id), $k, true));
            if ($v) $ids[] = $v;
        }
        return $ids;
    }

    /** Darf der aktuelle Nutzer dieses Event sehen/bearbeiten? (Mehr-Veranstalter-Logik) */
    public static function event_allowed($event_id) {
        $event_id = intval($event_id);
        if (!$event_id) return false;
        if (self::is_admin()) return true;
        $oid = self::organizer_id_for_user();
        if (!$oid) return false;
        return in_array($oid, self::event_org_ids($event_id), true);
    }

    /** Meta-Query-Klausel „Event gehört dem Veranstalter“. */
    public static function event_meta_clause($oid) {
        return [
            'relation' => 'OR',
            ['key' => '_tix_organizer_id', 'value' => strval(intval($oid))],
            ['key' => '_tix_co_organizer_id', 'value' => strval(intval($oid))],
        ];
    }

    /** Alle Event-IDs des Veranstalters des aktuellen Nutzers. */
    public static function own_event_ids() {
        $oid = self::organizer_id_for_user();
        if (!$oid) return [];
        return array_map('intval', get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [self::event_meta_clause($oid)],
        ]));
    }

    /** Event-IDs, die an einer Bestellung hängen (Positionen, Kassen-Meta, Tickets). */
    public static function order_event_ids($order) {
        $ids = [];
        foreach ($order->get_items() as $item) {
            $eid = method_exists($item, 'get_event_id') ? intval($item->get_event_id()) : 0;
            if ($eid) $ids[$eid] = true;
        }
        $pos = get_option('_tix_pos_order_' . $order->get_id());
        if (is_array($pos) && !empty($pos['event_id'])) $ids[intval($pos['event_id'])] = true;
        $tickets = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'any',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_tix_ticket_order_id', 'value' => intval($order->get_id())]],
        ]);
        foreach ($tickets as $tid) {
            $eid = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
            if ($eid) $ids[$eid] = true;
        }
        return array_keys($ids);
    }

    /** Darf der aktuelle Nutzer die Bestellung sehen? Alle Events müssen eigene sein. */
    public static function order_allowed($order) {
        if (self::is_admin()) return true;
        if (!$order) return false;
        $ids = self::order_event_ids($order);
        if (!$ids) return false;
        foreach ($ids as $eid) {
            if (!self::event_allowed($eid)) return false;
        }
        return true;
    }

    /** Berechtigungs-Zusatz für Staff-Routen: im Modus braucht jeder Nicht-Admin einen Veranstalter. */
    public static function require_organizer() {
        if (!self::scoped()) return true;
        if (self::organizer_id_for_user()) return true;
        $p = self::organizer_post_for_user(get_current_user_id());
        if ($p) {
            return new WP_Error('organizer_pending', 'Dein Veranstalter-Konto wartet noch auf die Freigabe.', ['status' => 403]);
        }
        return new WP_Error('no_organizer', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
    }

    // ──────────────────────────────────────────
    //  Veranstalter-Seite pflegen
    // ──────────────────────────────────────────

    public static function register_routes() {
        register_rest_route(self::NS, '/organizer/profile', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'rest_profile'],      'permission_callback' => [__CLASS__, 'check_manager']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save_profile'], 'permission_callback' => [__CLASS__, 'check_manager']],
        ]);
        register_rest_route(self::NS, '/organizer/profile/image', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_profile_image'], 'permission_callback' => [__CLASS__, 'check_manager'],
        ]);
    }

    /** Inhaber oder Team-Admin eines freigegebenen Veranstalters. */
    public static function check_manager(WP_REST_Request $req) {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        }
        if (!self::organizer_id_for_user()) {
            $r = self::require_organizer();
            return is_wp_error($r) ? $r : self::deny('Dieses Konto ist keinem Veranstalter zugeordnet.');
        }
        if (!self::is_org_manager()) return self::deny('Nur der Veranstalter selbst kann die Seite bearbeiten.');
        return true;
    }

    const SOCIAL_KEYS = ['website', 'instagram', 'facebook', 'tiktok', 'youtube', 'spotify', 'soundcloud'];

    private static function profile_payload($oid) {
        $m = fn($k) => (string) get_post_meta($oid, $k, true);
        $img = function ($k, $size) use ($oid) {
            $aid = intval(get_post_meta($oid, $k, true));
            return $aid ? (wp_get_attachment_image_url($aid, $size) ?: '') : '';
        };
        $social_raw = get_post_meta($oid, '_tix_org_landing_social', true);
        $social = [];
        foreach (self::SOCIAL_KEYS as $k) {
            $social[$k] = is_array($social_raw) ? (string) ($social_raw[$k] ?? '') : '';
        }
        if ($social['website'] === '') $social['website'] = $m('_tix_org_website');
        $modules = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::modules($oid) : [];
        $p = get_post($oid);
        return [
            'ok'          => true,
            'id'          => intval($oid),
            'name'        => html_entity_decode((string) $p->post_title, ENT_QUOTES, 'UTF-8'),
            'slug'        => class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($oid) : (string) $p->post_name,
            'status'      => (string) $p->post_status,
            'tagline'     => $m('_tix_org_landing_tagline'),
            'short_desc'  => $m('_tix_org_short_desc'),
            'description' => (string) ($m('_tix_org_landing_description') ?: $m('_tix_org_description')),
            'city'        => $m('_tix_org_city'),
            'address'     => $m('_tix_org_address'),
            'email'       => $m('_tix_org_email'),
            'phone'       => $m('_tix_org_phone'),
            'social'      => (object) $social,
            'modules'     => (object) $modules,
            'logo'        => $img('_tix_org_landing_logo_id', 'medium') ?: $img('_tix_org_image_id', 'medium'),
            'hero'        => $img('_tix_org_landing_hero_id', 'large'),
        ];
    }

    public static function rest_profile(WP_REST_Request $req) {
        return rest_ensure_response(self::profile_payload(self::organizer_id_for_user()));
    }

    public static function rest_save_profile(WP_REST_Request $req) {
        $oid = self::organizer_id_for_user();
        $b = $req->get_json_params();
        if (!is_array($b)) $b = [];

        if (array_key_exists('name', $b)) {
            $name = sanitize_text_field((string) $b['name']);
            if ($name === '') return new WP_Error('missing_name', 'Bitte einen Namen eingeben.', ['status' => 400]);
            wp_update_post(['ID' => $oid, 'post_title' => $name]);
        }
        $text = [
            'tagline'    => '_tix_org_landing_tagline',
            'short_desc' => '_tix_org_short_desc',
            'city'       => '_tix_org_city',
            'address'    => '_tix_org_address',
            'phone'      => '_tix_org_phone',
        ];
        foreach ($text as $key => $meta) {
            if (array_key_exists($key, $b)) update_post_meta($oid, $meta, sanitize_text_field((string) $b[$key]));
        }
        if (array_key_exists('email', $b)) {
            $email = sanitize_email((string) $b['email']);
            if ($b['email'] !== '' && !is_email($email)) {
                return new WP_Error('bad_email', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400]);
            }
            update_post_meta($oid, '_tix_org_email', $email);
        }
        if (array_key_exists('description', $b)) {
            update_post_meta($oid, '_tix_org_landing_description', wp_kses_post(wpautop((string) $b['description'])));
        }
        if (array_key_exists('social', $b) && is_array($b['social'])) {
            $social = get_post_meta($oid, '_tix_org_landing_social', true);
            $social = is_array($social) ? $social : [];
            foreach (self::SOCIAL_KEYS as $k) {
                if (!array_key_exists($k, $b['social'])) continue;
                $v = trim((string) $b['social'][$k]);
                if ($v === '') { unset($social[$k]); continue; }
                if (!preg_match('#^https?://#i', $v)) $v = 'https://' . ltrim($v, '/');
                $social[$k] = esc_url_raw($v);
            }
            update_post_meta($oid, '_tix_org_landing_social', $social);
            if (array_key_exists('website', $b['social'])) update_post_meta($oid, '_tix_org_website', (string) ($social['website'] ?? ''));
        }
        if (array_key_exists('modules', $b) && is_array($b['modules']) && class_exists('TIX_Public_Platform')) {
            $mods = TIX_Public_Platform::modules($oid);
            foreach ($mods as $k => $v) {
                if (array_key_exists($k, $b['modules'])) $mods[$k] = filter_var($b['modules'][$k], FILTER_VALIDATE_BOOLEAN);
            }
            $mods['tickets'] = true; // Tickets sind immer an
            update_post_meta($oid, TIX_Public_Platform::META_MODULES, wp_json_encode($mods));
        }
        self::flush_public_cache();
        return self::rest_profile($req);
    }

    /** POST /organizer/profile/image (multipart `file`, Feld `kind` = logo|hero). */
    public static function rest_profile_image(WP_REST_Request $req) {
        $oid  = self::organizer_id_for_user();
        $kind = sanitize_key((string) $req->get_param('kind'));
        $meta = $kind === 'hero' ? '_tix_org_landing_hero_id' : '_tix_org_landing_logo_id';
        $files = $req->get_file_params();
        if (empty($files['file'])) return new WP_Error('no_file', 'Kein Bild übermittelt.', ['status' => 400]);
        $type = wp_check_filetype((string) ($files['file']['name'] ?? ''));
        if (!in_array($type['ext'] ?? '', ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) {
            return new WP_Error('bad_type', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.', ['status' => 400]);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $_FILES['tix_org_image'] = $files['file'];
        $aid = media_handle_upload('tix_org_image', 0);
        if (is_wp_error($aid)) return $aid;
        update_post_meta($oid, $meta, intval($aid));
        self::flush_public_cache();
        return self::rest_profile($req);
    }

    /** Öffentliche Katalog-Caches leeren (60-s-Transients `tix_pub_events_*`). */
    public static function flush_public_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_tix\\_pub\\_%' OR option_name LIKE '\\_transient\\_timeout\\_tix\\_pub\\_%'");
    }
}
