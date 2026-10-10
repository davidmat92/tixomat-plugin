<?php
/**
 * Öffentliche Plattform-Routen für die evendis-App (ohne Login) und
 * Gast-Routen für Merkliste/Folgen (Token). Ergänzt TIX_Public_Events.
 *
 *   GET  /public/organizers?q=&city=&category=&page=&per_page=
 *   GET  /public/organizers/{id|slug}
 *   GET  /public/categories
 *   GET  /public/cities
 *   GET  /customer/favorites · POST {event_id} · DELETE /customer/favorites/{event_id}
 *   GET  /customer/following · POST {organizer_id} · DELETE /customer/following/{organizer_id}
 *
 * Veranstalter = CPT `tix_organizer` (Felder der Landing-Page), Module je
 * Veranstalter in `_tix_org_modules` (JSON), Merkliste in `_tix_saved_events`
 * (wie die Website), Folgen in `_tix_app_following`.
 */
if (!defined('ABSPATH')) exit;

class TIX_Public_Platform {
    const NS             = 'tixomat/v1';
    const TTL            = 60;
    const META_MODULES   = '_tix_org_modules';
    const META_FOLLOWING = '_tix_app_following';
    const META_SAVED     = '_tix_saved_events';

    /** Module, die ein Veranstalter für seine Events anbieten kann (Standard: nur Tickets). */
    const MODULE_DEFAULTS = [
        'tickets'     => true,
        'music'       => false,
        'loyalty'     => false,
        'giftcards'   => false,
        'support'     => false,
        'muttizettel' => false,
    ];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        if (class_exists('TIX_Public_Events')) {
            add_action('save_post_tix_organizer', ['TIX_Public_Events', 'flush']);
            add_action('save_post_tix_location',  ['TIX_Public_Events', 'flush']);
        }
    }

    public static function register_routes() {
        $public = ['permission_callback' => '__return_true'];
        register_rest_route(self::NS, '/public/organizers', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_organizers']] + $public);
        register_rest_route(self::NS, '/public/organizers/(?P<key>[A-Za-z0-9_-]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_organizer']] + $public);
        register_rest_route(self::NS, '/public/categories', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_categories']] + $public);
        register_rest_route(self::NS, '/public/cities', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_cities']] + $public);

        $customer = ['permission_callback' => ['TIX_App_Account', 'check_customer']];
        register_rest_route(self::NS, '/customer/favorites', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_favorites_get']] + $customer);
        register_rest_route(self::NS, '/customer/favorites', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_favorites_add']] + $customer);
        register_rest_route(self::NS, '/customer/favorites/(?P<id>\d+)', ['methods' => ['DELETE', 'POST'], 'callback' => [__CLASS__, 'rest_favorites_remove']] + $customer);
        register_rest_route(self::NS, '/customer/following', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_following_get']] + $customer);
        register_rest_route(self::NS, '/customer/following', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_following_add']] + $customer);
        register_rest_route(self::NS, '/customer/following/(?P<id>\d+)', ['methods' => ['DELETE', 'POST'], 'callback' => [__CLASS__, 'rest_following_remove']] + $customer);
    }

    // ──────────────────────────────────────────
    //  Bausteine für das Event-Payload
    // ──────────────────────────────────────────

    private static function organizer_post($oid) {
        $oid = intval($oid);
        if (!$oid) return null;
        $p = get_post($oid);
        if (!$p || $p->post_type !== 'tix_organizer' || $p->post_status !== 'publish') return null;
        // Noch nicht freigegebene/gesperrte Veranstalter sind nicht öffentlich
        if (class_exists('TIX_Org_Approval') && !TIX_Org_Approval::is_public($oid)) return null;
        return $p;
    }

    public static function slug($oid) {
        $s = (string) get_post_meta($oid, '_tix_org_landing_slug', true);
        if ($s === '') { $p = get_post($oid); $s = $p ? (string) $p->post_name : ''; }
        return $s;
    }

    private static function image($oid, $keys, $size) {
        foreach ($keys as $k) {
            $id = intval(get_post_meta($oid, $k, true));
            if ($id) { $u = wp_get_attachment_image_url($id, $size); if ($u) return $u; }
        }
        return '';
    }

    /** Kurzreferenz auf den Veranstalter; ohne Veranstalter-Post nur der Name. */
    public static function organizer_ref($oid, $fallback_name = '') {
        $p = self::organizer_post($oid);
        if ($p) {
            return [
                'id'   => intval($p->ID),
                'slug' => self::slug($p->ID),
                'name' => (string) $p->post_title,
                'logo' => self::image($p->ID, ['_tix_org_landing_logo_id', '_tix_org_image_id'], 'medium'),
            ];
        }
        if ((string) $fallback_name !== '') return ['id' => 0, 'slug' => '', 'name' => (string) $fallback_name, 'logo' => ''];
        return null;
    }

    /** Module des Veranstalters (Meta `_tix_org_modules` als JSON oder Array) mit Vorgaben. */
    public static function modules($oid) {
        $raw = get_post_meta(intval($oid), self::META_MODULES, true);
        if (is_string($raw) && $raw !== '') $raw = json_decode($raw, true);
        $out = self::MODULE_DEFAULTS;
        if (is_array($raw)) {
            foreach ($out as $k => $v) {
                if (array_key_exists($k, $raw)) $out[$k] = (bool) $raw[$k];
            }
        }
        return $out;
    }

    /** Sparte des Events (erster Begriff der Taxonomie `event_category`). */
    public static function event_category($event_id) {
        $terms = get_the_terms($event_id, 'event_category');
        if (!is_array($terms) || !$terms) return null;
        $t = $terms[0];
        return ['id' => intval($t->term_id), 'slug' => (string) $t->slug, 'name' => (string) $t->name];
    }

    /** Stadt (und PLZ) aus einer Adresse „Straße 1, 50667 Köln“. */
    public static function parse_city($address) {
        if (preg_match('/(\d{5})\s+([^,]+)\s*$/u', trim((string) $address), $m)) {
            return [trim($m[2]), $m[1]];
        }
        return ['', ''];
    }

    /** Veranstaltungsort: Location-Post oder aus Ort/Adresse abgeleitet. */
    public static function venue($event_id) {
        $loc_id = intval(get_post_meta($event_id, '_tix_location_id', true));
        $name   = (string) get_post_meta($event_id, '_tix_location', true);
        $addr   = (string) get_post_meta($event_id, '_tix_address', true);
        $city = ''; $zip = ''; $lat = null; $lng = null;
        if ($loc_id && ($lp = get_post($loc_id)) && $lp->post_type === 'tix_location') {
            if ($name === '') $name = (string) $lp->post_title;
            $city = (string) get_post_meta($loc_id, '_tix_loc_city', true);
            $zip  = (string) get_post_meta($loc_id, '_tix_loc_zip', true);
            $la = get_post_meta($loc_id, '_tix_loc_lat', true);
            $ln = get_post_meta($loc_id, '_tix_loc_lng', true);
            if ($la !== '' && $ln !== '' && floatval($la) != 0) { $lat = floatval($la); $lng = floatval($ln); }
            if ($addr === '') $addr = (string) get_post_meta($loc_id, '_tix_loc_address', true);
        }
        if ($city === '') list($city, $zip2) = self::parse_city($addr);
        if ($zip === '' && !empty($zip2)) $zip = $zip2;
        // Ohne Location-Koordinaten: am Event gespeicherte (übernommene Events von der
        // Quelle bzw. aus der Event-Adresse ermittelt, TIX_Venues::maybe_geocode_event)
        if ($lat === null) {
            $la = get_post_meta($event_id, '_tix_venue_lat', true);
            $ln = get_post_meta($event_id, '_tix_venue_lng', true);
            if (is_numeric($la) && is_numeric($ln) && floatval($la) != 0) { $lat = floatval($la); $lng = floatval($ln); }
        }
        return [
            'id'   => $loc_id,
            'name' => $name,
            'city' => $city,
            'zip'  => $zip,
            'lat'  => $lat,
            'lng'  => $lng,
        ];
    }

    /** Filter der Event-Liste (category, organizer, city, q) auf ein Payload anwenden. */
    public static function matches(array $p, array $f) {
        if (!empty($f['category'])) {
            $c = $p['category'] ?? null;
            if (!$c) return false;
            if (!(strcasecmp((string) $c['slug'], $f['category']) === 0 || (string) $c['id'] === (string) $f['category'])) return false;
        }
        if (!empty($f['organizer'])) {
            $o = $p['organizer_info'] ?? null;
            if (!$o) return false;
            if (!((string) $o['id'] === (string) $f['organizer'] || ($o['slug'] !== '' && strcasecmp((string) $o['slug'], $f['organizer']) === 0))) return false;
        }
        if (!empty($f['city'])) {
            $city = (string) ($p['venue']['city'] ?? '');
            if ($city === '' || strcasecmp($city, $f['city']) !== 0) return false;
        }
        if (!empty($f['q'])) {
            $hay = strtolower(implode(' ', [
                (string) ($p['title'] ?? ''), (string) ($p['organizer'] ?? ''), (string) ($p['location'] ?? ''),
                (string) ($p['venue']['city'] ?? ''), (string) ($p['category']['name'] ?? ''),
            ]));
            if (strpos($hay, strtolower($f['q'])) === false) return false;
        }
        return true;
    }

    // ──────────────────────────────────────────
    //  Veranstalter
    // ──────────────────────────────────────────

    /** Alle kommenden Events (Payloads) – 60 s gecacht, gemeinsam mit dem Katalog verworfen. */
    private static function upcoming_payloads() {
        $key = 'tix_pub_events_all_upcoming';
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;
        $req = new WP_REST_Request('GET', '/' . self::NS . '/public/events');
        $req->set_param('filter', 'upcoming');
        $req->set_param('per_page', 200);
        $res = TIX_Public_Events::rest_list($req);
        $data = ($res instanceof WP_REST_Response) ? $res->get_data() : [];
        $events = is_array($data) ? ($data['events'] ?? []) : [];
        set_transient($key, $events, self::TTL);
        return $events;
    }

    private static function upcoming_for_organizer($oid, $events = null) {
        $events = $events ?? self::upcoming_payloads();
        return array_values(array_filter($events, function ($e) use ($oid) {
            return intval($e['organizer_info']['id'] ?? 0) === intval($oid);
        }));
    }

    public static function organizer_payload($oid, $detailed = false, $events = null) {
        $p = self::organizer_post($oid);
        if (!$p) return null;
        $oid = intval($p->ID);
        $mine = self::upcoming_for_organizer($oid, $events);
        $cats = [];
        $cities = [];
        foreach ($mine as $e) {
            if (!empty($e['category'])) $cats[$e['category']['slug']] = $e['category'];
            $c = (string) ($e['venue']['city'] ?? '');
            if ($c !== '') $cities[$c] = ($cities[$c] ?? 0) + 1;
        }
        arsort($cities);
        $city = (string) get_post_meta($oid, '_tix_org_city', true);
        if ($city === '') list($city) = self::parse_city((string) get_post_meta($oid, '_tix_org_address', true));
        if ($city === '' && $cities) $city = (string) array_key_first($cities);
        $social_raw = get_post_meta($oid, '_tix_org_landing_social', true);
        $social = is_array($social_raw) ? array_filter(array_map('strval', $social_raw)) : [];
        $website = (string) ($social['website'] ?? get_post_meta($oid, '_tix_org_website', true));
        $short = (string) (get_post_meta($oid, '_tix_org_short_desc', true) ?: get_post_meta($oid, '_tix_org_landing_tagline', true));
        $out = [
            'id'             => $oid,
            'slug'           => self::slug($oid),
            'name'           => (string) $p->post_title,
            'logo'           => self::image($oid, ['_tix_org_landing_logo_id', '_tix_org_image_id'], 'medium'),
            'hero'           => self::image($oid, ['_tix_org_landing_hero_id', '_tix_org_image_id'], 'large'),
            'tagline'        => (string) get_post_meta($oid, '_tix_org_landing_tagline', true),
            'short_desc'     => wp_strip_all_tags($short),
            'city'           => $city,
            'website'        => $website,
            'social'         => $social,
            'categories'     => array_values($cats),
            'upcoming_count' => count($mine),
            'modules'        => self::modules($oid),
        ];
        if ($detailed) {
            $desc = (string) (get_post_meta($oid, '_tix_org_landing_description', true) ?: get_post_meta($oid, '_tix_org_description', true));
            $out['description'] = wp_kses_post($desc);
            $out['email']       = (string) get_post_meta($oid, '_tix_org_email', true);
            $out['phone']       = (string) get_post_meta($oid, '_tix_org_phone', true);
            $out['address']     = (string) get_post_meta($oid, '_tix_org_address', true);
            $out['events']      = array_slice($mine, 0, 50);
        }
        return $out;
    }

    private static function organizer_ids() {
        return get_posts([
            'post_type'      => 'tix_organizer',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
    }

    /** GET /public/organizers */
    public static function rest_organizers(WP_REST_Request $req) {
        $q        = strtolower(sanitize_text_field((string) $req->get_param('q')));
        $city     = sanitize_text_field((string) $req->get_param('city'));
        $category = sanitize_text_field((string) $req->get_param('category'));
        $per_page = max(1, min(200, intval($req->get_param('per_page') ?: 50)));
        $page     = max(1, intval($req->get_param('page') ?: 1));
        $key = 'tix_pub_events_orgs_' . md5(wp_json_encode([$q, $city, $category, $per_page, $page]));
        $cached = get_transient($key);
        if (is_array($cached)) return rest_ensure_response($cached);
        $events = self::upcoming_payloads();
        $list = [];
        foreach (self::organizer_ids() as $oid) {
            $o = self::organizer_payload($oid, false, $events);
            if (!$o) continue;
            if ($q !== '' && strpos(strtolower($o['name'] . ' ' . $o['city'] . ' ' . $o['short_desc']), $q) === false) continue;
            if ($city !== '' && strcasecmp($o['city'], $city) !== 0) continue;
            if ($category !== '') {
                $hit = false;
                foreach ($o['categories'] as $c) { if (strcasecmp($c['slug'], $category) === 0 || (string) $c['id'] === $category) { $hit = true; break; } }
                if (!$hit) continue;
            }
            $list[] = $o;
        }
        // Veranstalter mit kommenden Events zuerst, dann alphabetisch
        usort($list, function ($a, $b) {
            $d = ($b['upcoming_count'] > 0) <=> ($a['upcoming_count'] > 0);
            return $d !== 0 ? $d : strcasecmp($a['name'], $b['name']);
        });
        $total = count($list);
        $items = array_slice($list, ($page - 1) * $per_page, $per_page);
        $resp = ['ok' => true, 'count' => count($items), 'total' => $total, 'page' => $page, 'has_more' => $page * $per_page < $total, 'organizers' => $items];
        set_transient($key, $resp, self::TTL);
        return rest_ensure_response($resp);
    }

    /** GET /public/organizers/{id|slug} */
    public static function rest_organizer(WP_REST_Request $req) {
        $keyparam = (string) $req['key'];
        $oid = 0;
        if (ctype_digit($keyparam)) {
            $oid = intval($keyparam);
        } else {
            $found = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_org_landing_slug', 'meta_value' => $keyparam]);
            if (!$found) $found = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'name' => $keyparam]);
            $oid = $found ? intval($found[0]) : 0;
        }
        $cache = 'tix_pub_events_org_' . $oid;
        $cached = get_transient($cache);
        if (is_array($cached)) return rest_ensure_response($cached);
        $o = $oid ? self::organizer_payload($oid, true) : null;
        if (!$o) return new WP_Error('tix_organizer', 'Veranstalter nicht gefunden.', ['status' => 404]);
        $resp = ['ok' => true, 'organizer' => $o];
        set_transient($cache, $resp, self::TTL);
        return rest_ensure_response($resp);
    }

    /** GET /public/categories – Sparten mit Anzahl kommender Events. */
    public static function rest_categories(WP_REST_Request $req) {
        $terms = get_terms(['taxonomy' => 'event_category', 'hide_empty' => false]);
        if (is_wp_error($terms)) $terms = [];
        $upcoming = [];
        foreach (self::upcoming_payloads() as $e) {
            $id = intval($e['category']['id'] ?? 0);
            if ($id) $upcoming[$id] = ($upcoming[$id] ?? 0) + 1;
        }
        $out = [];
        foreach ($terms as $t) {
            $out[] = [
                'id'       => intval($t->term_id),
                'slug'     => (string) $t->slug,
                'name'     => (string) $t->name,
                'count'    => intval($t->count),
                'upcoming' => intval($upcoming[$t->term_id] ?? 0),
            ];
        }
        usort($out, function ($a, $b) {
            $d = $b['upcoming'] <=> $a['upcoming'];
            return $d !== 0 ? $d : strcasecmp($a['name'], $b['name']);
        });
        return rest_ensure_response(['ok' => true, 'categories' => $out]);
    }

    /** GET /public/cities – Städte kommender Events. */
    public static function rest_cities(WP_REST_Request $req) {
        $cities = [];
        foreach (self::upcoming_payloads() as $e) {
            $c = trim((string) ($e['venue']['city'] ?? ''));
            if ($c === '') continue;
            $cities[$c] = ($cities[$c] ?? 0) + 1;
        }
        $out = [];
        foreach ($cities as $name => $n) $out[] = ['name' => $name, 'upcoming' => $n];
        usort($out, function ($a, $b) {
            $d = $b['upcoming'] <=> $a['upcoming'];
            return $d !== 0 ? $d : strcasecmp($a['name'], $b['name']);
        });
        return rest_ensure_response(['ok' => true, 'cities' => $out]);
    }

    // ──────────────────────────────────────────
    //  Merkliste und Folgen (Gast mit Token)
    // ──────────────────────────────────────────

    private static function id_list($user_id, $meta) {
        $raw = get_user_meta($user_id, $meta, true);
        if (!is_array($raw)) return [];
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    private static function user_id() {
        $u = wp_get_current_user();
        return $u && $u->ID ? intval($u->ID) : 0;
    }

    public static function rest_favorites_get(WP_REST_Request $req) {
        return rest_ensure_response(['ok' => true, 'event_ids' => self::id_list(self::user_id(), self::META_SAVED)]);
    }

    public static function rest_favorites_add(WP_REST_Request $req) {
        $uid = self::user_id();
        $id  = intval($req->get_param('event_id'));
        $post = $id ? get_post($id) : null;
        if (!$post || $post->post_type !== 'event') return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        $list = self::id_list($uid, self::META_SAVED);
        if (!in_array($id, $list, true)) $list[] = $id;
        update_user_meta($uid, self::META_SAVED, $list);
        return rest_ensure_response(['ok' => true, 'event_ids' => $list]);
    }

    public static function rest_favorites_remove(WP_REST_Request $req) {
        $uid = self::user_id();
        $id  = intval($req['id']);
        $list = array_values(array_diff(self::id_list($uid, self::META_SAVED), [$id]));
        update_user_meta($uid, self::META_SAVED, $list);
        return rest_ensure_response(['ok' => true, 'event_ids' => $list]);
    }

    public static function rest_following_get(WP_REST_Request $req) {
        $ids = self::id_list(self::user_id(), self::META_FOLLOWING);
        $orgs = [];
        foreach ($ids as $oid) { $o = self::organizer_payload($oid); if ($o) $orgs[] = $o; }
        return rest_ensure_response(['ok' => true, 'organizer_ids' => $ids, 'organizers' => $orgs]);
    }

    public static function rest_following_add(WP_REST_Request $req) {
        $uid = self::user_id();
        $id  = intval($req->get_param('organizer_id'));
        if (!self::organizer_post($id)) return new WP_Error('tix_organizer', 'Veranstalter nicht gefunden.', ['status' => 404]);
        $list = self::id_list($uid, self::META_FOLLOWING);
        if (!in_array($id, $list, true)) $list[] = $id;
        update_user_meta($uid, self::META_FOLLOWING, $list);
        return rest_ensure_response(['ok' => true, 'organizer_ids' => $list]);
    }

    public static function rest_following_remove(WP_REST_Request $req) {
        $uid = self::user_id();
        $id  = intval($req['id']);
        $list = array_values(array_diff(self::id_list($uid, self::META_FOLLOWING), [$id]));
        update_user_meta($uid, self::META_FOLLOWING, $list);
        return rest_ensure_response(['ok' => true, 'organizer_ids' => $list]);
    }
}
