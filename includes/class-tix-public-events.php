<?php
/**
 * Öffentlicher Event-Katalog für die KitchenKlub-App (ohne Login).
 *
 *   GET /public/events?filter=upcoming|past|all&per_page=50
 *       [&from=YYYY-MM-DD&to=YYYY-MM-DD][&near=LAT,LNG&radius=KM]   (1.38.367)
 *   GET /public/events/days?from=YYYY-MM-DD&to=YYYY-MM-DD[&city=][&category=]
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
        // Anzahl Events je Tag (Kalender-Leiste der evendis-App)
        register_rest_route(self::NS, '/public/events/days', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_days'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/public/events/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_detail'],
            'permission_callback' => '__return_true',
        ]);
        // Mitmachen ohne Login (Apps; Web nutzt dieselbe Logik per admin-ajax)
        register_rest_route(self::NS, '/public/events/(?P<id>\d+)/raffle', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_raffle_enter'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/public/events/(?P<id>\d+)/waitlist', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_waitlist_join'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** POST /public/events/{id}/raffle  {name, email, consent} */
    public static function rest_raffle_enter(WP_REST_Request $req) {
        $id = intval($req['id']);
        if (!class_exists('TIX_Raffle') || !self::payload($id, false)) return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        $r = TIX_Raffle::enter($id, $req->get_param('name'), $req->get_param('email'), (bool) $req->get_param('consent'));
        if (!$r['ok']) return new WP_Error('tix_raffle', $r['message'], ['status' => 400]);
        self::flush();
        return rest_ensure_response(['ok' => true, 'message' => $r['message'], 'entries' => $r['count']]);
    }

    /** POST /public/events/{id}/waitlist  {email, type: presale|soldout} */
    public static function rest_waitlist_join(WP_REST_Request $req) {
        $id = intval($req['id']);
        if (!class_exists('TIX_Waitlist') || !self::payload($id, false)) return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        $type = (string) $req->get_param('type');
        if (!TIX_Waitlist::available($id, $type === 'soldout' ? 'soldout' : 'presale')) {
            return new WP_Error('tix_waitlist', 'Die Warteliste ist für dieses Event nicht verfügbar.', ['status' => 400]);
        }
        $r = TIX_Waitlist::join($id, $req->get_param('email'), $type);
        if (!$r['ok']) return new WP_Error('tix_waitlist', $r['message'], ['status' => $r['code'] ?? 400]);
        return rest_ensure_response(['ok' => true, 'message' => $r['message']]);
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
        if (class_exists('TIX_Org_Approval') && !TIX_Org_Approval::event_allowed($id)) return null;
        list($start, $end) = self::times($id);
        $cats  = self::categories($id);
        $prices = array_map(function ($c) { return floatval($c['price'] ?? 0); }, $cats);
        $enabled = get_post_meta($id, '_tix_tickets_enabled', true) === '1' && !empty($cats);
        // Geteiltes Event: Tickets gibt es bei der Quelle (App zeigt „Zur Veranstaltung“)
        $syndicated = class_exists('TIX_App_Checkout') ? TIX_App_Checkout::syndicated_info($id) : null;
        // (außer die Plattform verkauft über die Vermittlung: sale_via_app)
        if ($syndicated && empty($syndicated['sale_via_app'])) $enabled = false;
        $status  = (string) (get_post_meta($id, '_tix_status', true) ?: 'available');
        $organizer = (string) (get_post_meta($id, '_tix_organizer_display', true) ?: get_post_meta($id, '_tix_organizer', true));
        if ($organizer === '' && ($oid = intval(get_post_meta($id, '_tix_organizer_id', true)))) $organizer = (string) get_the_title($oid);
        // Plattform-Felder (evendis): Veranstalter-Referenz, Sparte, Ort mit Stadt/Koordinaten, Module
        $org_id   = intval(get_post_meta($id, '_tix_organizer_id', true));
        $platform = class_exists('TIX_Public_Platform');
        $org_info = $platform ? TIX_Public_Platform::organizer_ref($org_id, $organizer) : null;
        $out = [
            'id'              => intval($id),
            'title'           => (string) $post->post_title,
            'slug'            => (string) $post->post_name,
            'url'             => get_permalink($id),
            // Bildgrößen passend zur App: Detail/Karten „large“ (1024), Slider/Karten
            // „medium_large“ (768), Zeilen + Blur-Platzhalter „medium“ (300)
            'image'           => get_the_post_thumbnail_url($id, 'large') ?: (get_the_post_thumbnail_url($id, 'full') ?: ''),
            'thumbnail'       => get_the_post_thumbnail_url($id, 'medium_large') ?: (get_the_post_thumbnail_url($id, 'medium') ?: ''),
            'image_small'     => get_the_post_thumbnail_url($id, 'medium') ?: (get_the_post_thumbnail_url($id, 'thumbnail') ?: ''),
            'date_start'      => (string) get_post_meta($id, '_tix_date_start', true),
            'date_end'        => (string) get_post_meta($id, '_tix_date_end', true),
            'time_start'      => (string) get_post_meta($id, '_tix_time_start', true),
            'time_end'        => (string) get_post_meta($id, '_tix_time_end', true),
            'time_doors'      => (string) get_post_meta($id, '_tix_time_doors', true),
            'location'        => (string) get_post_meta($id, '_tix_location', true),
            'address'         => (string) get_post_meta($id, '_tix_address', true),
            'organizer'       => $organizer,
            'organizer_info'  => $org_info,
            'category'        => $platform ? TIX_Public_Platform::event_category($id) : null,
            'venue'           => $platform ? TIX_Public_Platform::venue($id) : null,
            'modules'         => ($platform && !empty($org_info['id'])) ? TIX_Public_Platform::modules($org_info['id']) : null,
            'age_label'       => self::age_label($id),
            'status'          => $status,
            'status_label'    => (string) get_post_meta($id, '_tix_status_label', true),
            'tickets_enabled' => $enabled,
            'syndicated'      => $syndicated,
            'price_from'      => $prices ? round(min($prices), 2) : null,
            'price_range'     => (string) get_post_meta($id, '_tix_price_range', true),
            'categories'      => $cats,
            'is_past'         => $end > 0 && $end < self::now(),
            'excerpt'         => (string) $post->post_excerpt,
            // Freier Eintritt ohne Tickets (1.38.363): Apps zeigen „Eintritt frei“ statt Preis/Kasse
            'free_entry'      => class_exists('TIX_Event_Extras') && TIX_Event_Extras::free_entry($id) !== null,
            'free_entry_note' => class_exists('TIX_Event_Extras') ? (string) (TIX_Event_Extras::free_entry($id)['note'] ?? '') : '',
        ];
        // Wiederkehrende Events (1.38.367): Serie (Master) oder Termin daraus
        $rec = class_exists('TIX_Recurrence') ? TIX_Recurrence::info($id) : ['recurring' => false, 'parent' => 0];
        $out['recurring']         = $rec['recurring'];
        $out['recurrence_parent'] = $rec['parent'];
        if ($detailed) {
            $out['description'] = self::html($id, '_tix_info_description');
            $out['lineup']      = self::html($id, '_tix_info_lineup');
            $out['specials']    = self::html($id, '_tix_info_specials');
            $out['extra_info']  = self::html($id, '_tix_info_extra_info');
            $out['gallery']     = self::gallery($id);
            // Optionale Inhalte (1.38.351, App-Vertrag: nur ergänzt) – leer/null, wenn nicht gepflegt
            if (class_exists('TIX_Event_Extras')) {
                $notes = TIX_Event_Extras::notes($id);
                $out['gallery_items'] = TIX_Event_Extras::gallery($id);
                $out['faq']           = TIX_Event_Extras::faq($id);
                $out['timetable']     = TIX_Event_Extras::timetable($id);
                $out['video']         = TIX_Event_Extras::video($id);
                $out['dresscode']     = $notes['dresscode'];
                $out['entry_rules']   = $notes['entry_rules'];
                $out['ticket_notes']  = $notes['ticket_notes'];
                $out['charity']       = TIX_Event_Extras::charity($id);
                $out['series']        = TIX_Event_Extras::series($id);
                $out['box_office']    = TIX_Event_Extras::box_office($id);
                $out['external_shop'] = TIX_Event_Extras::external_shop($id);
                $out['presale']       = TIX_Event_Extras::presale($id);
                // Audit 1.38.361: was die Website zeigt, die API aber noch nicht lieferte
                $out['upsell']          = TIX_Event_Extras::upsell($id);
                $out['venue_info']      = TIX_Event_Extras::venue_info($id);
                $out['ticket_sponsor']  = TIX_Event_Extras::ticket_sponsor($id);
                $out['ticket_transfer'] = TIX_Event_Extras::ticket_transfer($id);
            }
            $out['raffle'] = class_exists('TIX_Raffle') ? TIX_Raffle::public_info($id) : null;
            // Ticket-Optionen (1.38.353): Mengenrabatt-Staffeln; Phasen/Pakete stehen an categories[]
            $out['group_discount'] = class_exists('TIX_Cart_Pricing') ? TIX_Cart_Pricing::group_discount($id) : null;
            $out['specials_offer'] = class_exists('TIX_App_Checkout') ? TIX_App_Checkout::specials($id) : [];
            $out['combos']         = class_exists('TIX_App_Checkout') ? TIX_App_Checkout::combos($id) : [];
            // Tischreservierung: true → Kategorien über /public/events/{id}/tables, Buchung per /customer/table-reservations
            $tr = get_post_meta($id, '_tix_table_reservation', true);
            $out['tables'] = class_exists('TIX_Table_Reservation') && is_array($tr) && !empty($tr['enabled']);
            // Saalplan: true → Plätze über /public/events/{id}/seatmap wählen
            $out['seatmap'] = class_exists('TIX_Seatmap') && TIX_Seatmap::event_seatmap($id) > 0;
        }
        return $out;
    }

    // ──────────────────────────────────────────
    //  Tage, Zeiträume, Umkreis (1.38.367)
    // ──────────────────────────────────────────

    /** Höchstens so viele Tage fragt /public/events/days auf einmal ab. */
    const MAX_DAYS_RANGE = 62;

    /** Bis zu dieser Uhrzeit zählt ein Ende am Folgetag nicht als weiterer Tag (Party 23–5 Uhr). */
    const DAY_CUTOFF_MINUTES = 360; // 06:00

    private static function valid_date($s) {
        $s = (string) $s;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
        return checkdate(intval($m[2]), intval($m[3]), intval($m[1]));
    }

    private static function add_days($date, $n) {
        return gmdate('Y-m-d', strtotime($date . ' 00:00:00 UTC') + intval($n) * DAY_IN_SECONDS);
    }

    /**
     * Tage eines Events als [erster, letzter] (Y-m-d) oder null.
     * Starttag bis Endtag; ein Ende vor 06:00 Uhr zählt nicht als weiterer Tag.
     * Ohne Enddatum nur der Starttag.
     */
    public static function day_span($id) {
        $ds = (string) get_post_meta($id, '_tix_date_start', true);
        if (!self::valid_date($ds)) return null;
        $de = (string) get_post_meta($id, '_tix_date_end', true);
        if (!self::valid_date($de) || $de < $ds) $de = $ds;
        if ($de > $ds) {
            $te = (string) get_post_meta($id, '_tix_time_end', true);
            if (preg_match('/^(\d{1,2}):(\d{2})/', $te, $m) && (intval($m[1]) * 60 + intval($m[2])) < self::DAY_CUTOFF_MINUTES) {
                $de = self::add_days($de, -1);
            }
        }
        return [$ds, $de];
    }

    /** Alle Tage (Y-m-d) eines Events innerhalb [from, to]; höchstens MAX_DAYS_RANGE. */
    public static function days_in_range($span, $from, $to) {
        if (!$span) return [];
        $a = max($span[0], $from);
        $b = min($span[1], $to);
        $out = [];
        for ($d = $a, $i = 0; $d <= $b && $i < self::MAX_DAYS_RANGE; $d = self::add_days($d, 1), $i++) $out[] = $d;
        return $out;
    }

    /** "LAT,LNG" → [lat, lng] oder null. */
    public static function parse_near($raw) {
        $raw = trim((string) $raw);
        if (!preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $raw, $m)) return null;
        $lat = floatval($m[1]);
        $lng = floatval($m[2]);
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
        return [$lat, $lng];
    }

    /** Umkreis in km: Standard 30, höchstens 200. */
    public static function parse_radius($raw) {
        $r = ($raw === null || $raw === '') ? 30.0 : floatval($raw);
        if ($r <= 0) $r = 30.0;
        return min(200.0, $r);
    }

    /** Luftlinie in km (Haversine). */
    public static function distance_km($lat1, $lng1, $lat2, $lng2) {
        $r = 6371.0;
        $dlat = deg2rad($lat2 - $lat1);
        $dlng = deg2rad($lng2 - $lng1);
        $a = sin($dlat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** Zeitraum/Umkreis aus der Anfrage; WP_Error bei falschem Format. */
    private static function geo_date_params(WP_REST_Request $req) {
        $from = (string) $req->get_param('from');
        $to   = (string) $req->get_param('to');
        $range = null;
        if ($from !== '' || $to !== '') {
            if ($from === '') $from = $to;
            if ($to === '') $to = $from;
            if (!self::valid_date($from) || !self::valid_date($to) || $to < $from) {
                return new WP_Error('tix_range', 'Bitte from und to als JJJJ-MM-TT angeben (to nicht vor from).', ['status' => 400]);
            }
            $range = [$from, $to];
        }
        $near = null;
        $radius = null;
        $near_raw = (string) $req->get_param('near');
        if ($near_raw !== '') {
            $near = self::parse_near($near_raw);
            if (!$near) return new WP_Error('tix_near', 'Bitte near als „Breite,Länge“ angeben, z. B. 50.94,6.96.', ['status' => 400]);
            $radius = self::parse_radius($req->get_param('radius'));
        }
        return ['range' => $range, 'near' => $near, 'radius' => $radius];
    }

    // ──────────────────────────────────────────
    //  REST
    // ──────────────────────────────────────────

    /** GET /public/events */
    public static function rest_list(WP_REST_Request $req) {
        $filter   = in_array($req->get_param('filter'), ['past', 'all'], true) ? $req->get_param('filter') : 'upcoming';
        $per_page = max(1, min(200, intval($req->get_param('per_page') ?: 50)));
        $page     = max(1, intval($req->get_param('page') ?: 1));
        $f = [
            'category'  => sanitize_text_field((string) $req->get_param('category')),
            'organizer' => sanitize_text_field((string) $req->get_param('organizer')),
            'city'      => sanitize_text_field((string) $req->get_param('city')),
            'q'         => sanitize_text_field((string) $req->get_param('q')),
        ];
        $geo = self::geo_date_params($req);
        if (is_wp_error($geo)) return $geo;
        $cache_extra = '';
        if ($geo['range'] || $geo['near']) {
            // Nur mit den neuen Parametern ändert sich der Schlüssel (alte Antworten bleiben gleich)
            $cache_extra = '|' . wp_json_encode([$geo['range'], $geo['near'], $geo['radius']]);
        }
        $key      = 'tix_pub_events_' . md5($filter . '|' . $per_page . '|' . $page . '|' . wp_json_encode($f) . $cache_extra);
        $cached   = get_transient($key);
        if (is_array($cached)) return rest_ensure_response($cached);

        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 1000,
            'fields'         => 'ids',
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => $filter === 'past' ? 'DESC' : 'ASC',
        ]);
        $now = self::now();
        $matched = [];
        $filtering = class_exists('TIX_Public_Platform') && array_filter($f);
        foreach ($ids as $id) {
            list($start, $end) = self::times($id);
            if ($filter === 'upcoming' && $end && $end < $now) continue;
            if ($filter === 'past' && (!$end || $end >= $now)) continue;
            // Zeitraum: Tage des Events schneiden [from, to]
            if ($geo['range']) {
                $span = self::day_span($id);
                if (!$span || $span[0] > $geo['range'][1] || $span[1] < $geo['range'][0]) continue;
            }
            // Umkreis: nur Orte mit Koordinaten
            $dist = null;
            if ($geo['near']) {
                $v = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::venue($id) : null;
                if (!$v || $v['lat'] === null || $v['lng'] === null) continue;
                $dist = self::distance_km($geo['near'][0], $geo['near'][1], $v['lat'], $v['lng']);
                if ($dist > $geo['radius']) continue;
            }
            $p = self::payload($id, false);
            if (!$p) continue;
            if ($filtering && !TIX_Public_Platform::matches($p, $f)) continue;
            if ($dist !== null) $p['distance_km'] = round($dist, 1);
            $matched[] = $p;
        }
        $total  = count($matched);
        $events = array_slice($matched, ($page - 1) * $per_page, $per_page);
        $resp = [
            'ok'       => true,
            'filter'   => $filter,
            'count'    => count($events),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'has_more' => $page * $per_page < $total,
            'events'   => $events,
        ];
        set_transient($key, $resp, self::TTL);
        return rest_ensure_response($resp);
    }

    /**
     * GET /public/events/days?from=&to=[&city=][&category=][&organizer=]
     * {"days":{"2026-10-16":3}} – kommende, veröffentlichte Events je Tag (Tage-Regel wie day_span).
     */
    public static function rest_days(WP_REST_Request $req) {
        $today = current_time('Y-m-d');
        $from = (string) $req->get_param('from');
        $to   = (string) $req->get_param('to');
        if ($from === '') $from = $today;
        if ($to === '') $to = self::add_days($from, 30);
        if (!self::valid_date($from) || !self::valid_date($to) || $to < $from) {
            return new WP_Error('tix_range', 'Bitte from und to als JJJJ-MM-TT angeben (to nicht vor from).', ['status' => 400]);
        }
        $span_days = intval(round((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / DAY_IN_SECONDS)) + 1;
        if ($span_days > self::MAX_DAYS_RANGE) {
            return new WP_Error('tix_range', 'Der Zeitraum darf höchstens ' . self::MAX_DAYS_RANGE . ' Tage umfassen.', ['status' => 400]);
        }
        $f = [
            'category'  => sanitize_text_field((string) $req->get_param('category')),
            'organizer' => sanitize_text_field((string) $req->get_param('organizer')),
            'city'      => sanitize_text_field((string) $req->get_param('city')),
        ];
        $key = 'tix_pub_events_days_' . md5($from . '|' . $to . '|' . $today . '|' . wp_json_encode($f));
        $cached = get_transient($key);
        if (is_array($cached)) return rest_ensure_response(self::days_response($cached));

        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 1000,
            'fields'         => 'ids',
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ]);
        $now = self::now();
        $lo = max($from, $today); // vergangene Tage zählen nicht
        $counts = [];
        $filtering = class_exists('TIX_Public_Platform') && array_filter($f);
        foreach ($ids as $id) {
            list($start, $end) = self::times($id);
            if ($end && $end < $now) continue;
            $span = self::day_span($id);
            if (!$span || $span[0] > $to || $span[1] < $lo) continue;
            $p = self::payload($id, false);
            if (!$p) continue;
            if ($filtering && !TIX_Public_Platform::matches($p, $f)) continue;
            foreach (self::days_in_range($span, $lo, $to) as $d) $counts[$d] = ($counts[$d] ?? 0) + 1;
        }
        ksort($counts);
        $data = ['from' => $from, 'to' => $to, 'days' => $counts];
        set_transient($key, $data, self::TTL);
        return rest_ensure_response(self::days_response($data));
    }

    private static function days_response(array $data) {
        // Leere Liste als JSON-Objekt {} (nicht [])
        return ['ok' => true, 'from' => $data['from'], 'to' => $data['to'], 'days' => (object) $data['days']];
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
