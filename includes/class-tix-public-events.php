<?php
/**
 * Öffentlicher Event-Katalog für die KitchenKlub-App (ohne Login).
 *
 *   GET /public/events?filter=upcoming|past|all&per_page=50
 *   GET /public/events/{id}
 *
 * Feldnamen = App-Modell `PublicEvent` (siehe BACKEND-TODO.md der App):
 * Datum/Zeit, Ort, Veranstalter, Altersfreigabe, Status, Ticket-Infos
 * (tickets_enabled, price_from, categories mit Preisphasen/Bestand),
 * Info-Texte (`_tix_info_*`, HTML) und Galerie. Antwort 60 s im Transient.
 */
if (!defined('ABSPATH')) exit;

class TIX_Public_Events {

    const NS  = 'tixomat/v1';
    const TTL = 60;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Cache bei Event-Änderungen verwerfen
        add_action('save_post_event', [__CLASS__, 'flush']);
        add_action('deleted_post', [__CLASS__, 'flush']);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/public/events', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_list'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/public/events/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_detail'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function flush() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tix_pub_events_%' OR option_name LIKE '_transient_timeout_tix_pub_events_%'");
    }

    // ──────────────────────────────────────────
    //  Hilfen
    // ──────────────────────────────────────────

    private static function now() {
        return intval(current_time('timestamp'));
    }

    /** Beginn/Ende als lokale Timestamps (Konvention wie TIX_Music). */
    private static function times($id) {
        $date  = (string) get_post_meta($id, '_tix_date_start', true);
        if ($date === '') return [0, 0];
        $time  = (string) get_post_meta($id, '_tix_time_start', true);
        $start = strtotime($date . ' ' . ($time !== '' ? $time : '00:00')) ?: 0;
        $date_end = (string) get_post_meta($id, '_tix_date_end', true);
        $time_end = (string) get_post_meta($id, '_tix_time_end', true);
        $end = 0;
        if ($date_end !== '' || $time_end !== '') {
            $end = strtotime(($date_end !== '' ? $date_end : $date) . ' ' . ($time_end !== '' ? $time_end : '23:59')) ?: 0;
            if ($end && $end <= $start) $end += DAY_IN_SECONDS;
        }
        if (!$end) $end = $start + ($time !== '' ? 8 * HOUR_IN_SECONDS : DAY_IN_SECONDS);
        return [$start, $end];
    }

    private static function age_label($id) {
        $n = (string) get_post_meta($id, '_tix_info_age_limit', true);
        if ($n !== '' && intval($n) > 0) return 'ab ' . intval($n) . ' Jahren';
        $d = trim((string) get_post_meta($id, '_tix_age_limit_display', true));
        if ($d === '') return '';
        if (preg_match('/^(\d+)\s*\+$/', $d, $m)) return 'ab ' . intval($m[1]) . ' Jahren';
        return $d;
    }

    private static function categories($id) {
        if (class_exists('TIX_App_Checkout') && method_exists('TIX_App_Checkout', 'public_categories')) {
            return TIX_App_Checkout::public_categories($id);
        }
        return [];
    }

    private static function gallery($id) {
        $raw = get_post_meta($id, '_tix_gallery', true);
        $out = [];
        foreach ((array) $raw as $item) {
            $url = '';
            if (is_numeric($item)) $url = wp_get_attachment_image_url(intval($item), 'large') ?: '';
            elseif (is_string($item)) $url = $item;
            elseif (is_array($item)) $url = (string) ($item['url'] ?? '');
            if ($url !== '') $out[] = $url;
        }
        return $out;
    }

    private static function html($id, $key) {
        $v = (string) get_post_meta($id, $key, true);
        if (trim(wp_strip_all_tags($v)) === '') return '';
        return wp_kses_post($v);
    }

    /** Event im App-Format; $detailed = inkl. Info-Texte + Galerie. */
    public static function payload($id, $detailed = false) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'event' || $post->post_status !== 'publish') return null;
        list($start, $end) = self::times($id);
        $cats  = self::categories($id);
        $prices = array_map(function ($c) { return floatval($c['price'] ?? 0); }, $cats);
        $enabled = get_post_meta($id, '_tix_tickets_enabled', true) === '1' && !empty($cats);
        $status  = (string) (get_post_meta($id, '_tix_status', true) ?: 'available');
        $organizer = (string) (get_post_meta($id, '_tix_organizer_display', true) ?: get_post_meta($id, '_tix_organizer', true));
        if ($organizer === '' && ($oid = intval(get_post_meta($id, '_tix_organizer_id', true)))) $organizer = (string) get_the_title($oid);
        $out = [
            'id'              => intval($id),
            'title'           => (string) $post->post_title,
            'slug'            => (string) $post->post_name,
            'url'             => get_permalink($id),
            'image'           => get_the_post_thumbnail_url($id, 'large') ?: (get_the_post_thumbnail_url($id, 'full') ?: ''),
            'thumbnail'       => get_the_post_thumbnail_url($id, 'medium_large') ?: (get_the_post_thumbnail_url($id, 'medium') ?: ''),
            'date_start'      => (string) get_post_meta($id, '_tix_date_start', true),
            'date_end'        => (string) get_post_meta($id, '_tix_date_end', true),
            'time_start'      => (string) get_post_meta($id, '_tix_time_start', true),
            'time_end'        => (string) get_post_meta($id, '_tix_time_end', true),
            'time_doors'      => (string) get_post_meta($id, '_tix_time_doors', true),
            'location'        => (string) get_post_meta($id, '_tix_location', true),
            'address'         => (string) get_post_meta($id, '_tix_address', true),
            'organizer'       => $organizer,
            'age_label'       => self::age_label($id),
            'status'          => $status,
            'status_label'    => (string) get_post_meta($id, '_tix_status_label', true),
            'tickets_enabled' => $enabled,
            'price_from'      => $prices ? round(min($prices), 2) : null,
            'price_range'     => (string) get_post_meta($id, '_tix_price_range', true),
            'categories'      => $cats,
            'is_past'         => $end > 0 && $end < self::now(),
            'excerpt'         => (string) $post->post_excerpt,
        ];
        if ($detailed) {
            $out['description'] = self::html($id, '_tix_info_description');
            $out['lineup']      = self::html($id, '_tix_info_lineup');
            $out['specials']    = self::html($id, '_tix_info_specials');
            $out['extra_info']  = self::html($id, '_tix_info_extra_info');
            $out['gallery']     = self::gallery($id);
        }
        return $out;
    }

    // ──────────────────────────────────────────
    //  REST
    // ──────────────────────────────────────────

    /** GET /public/events */
    public static function rest_list(WP_REST_Request $req) {
        $filter   = in_array($req->get_param('filter'), ['past', 'all'], true) ? $req->get_param('filter') : 'upcoming';
        $per_page = max(1, min(100, intval($req->get_param('per_page') ?: 50)));
        $key      = 'tix_pub_events_' . md5($filter . '|' . $per_page);
        $cached   = get_transient($key);
        if (is_array($cached)) return rest_ensure_response($cached);

        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'fields'         => 'ids',
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => $filter === 'past' ? 'DESC' : 'ASC',
        ]);
        $now = self::now();
        $events = [];
        foreach ($ids as $id) {
            list($start, $end) = self::times($id);
            if ($filter === 'upcoming' && $end && $end < $now) continue;
            if ($filter === 'past' && (!$end || $end >= $now)) continue;
            $p = self::payload($id, false);
            if ($p) $events[] = $p;
            if (count($events) >= $per_page) break;
        }
        $resp = ['ok' => true, 'filter' => $filter, 'count' => count($events), 'events' => $events];
        set_transient($key, $resp, self::TTL);
        return rest_ensure_response($resp);
    }

    /** GET /public/events/{id} */
    public static function rest_detail(WP_REST_Request $req) {
        $id = intval($req['id']);
        $key = 'tix_pub_events_d' . $id;
        $cached = get_transient($key);
        if (is_array($cached)) return rest_ensure_response($cached);
        $p = self::payload($id, true);
        if (!$p) return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        $resp = ['ok' => true, 'event' => $p];
        set_transient($key, $resp, self::TTL);
        return rest_ensure_response($resp);
    }
}
