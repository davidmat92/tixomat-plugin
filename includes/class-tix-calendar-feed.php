<?php
/**
 * TIX_Calendar_Feed – abonnierbarer Kalender (ICS) je Veranstalter, Künstler, Ort bzw. Seite (R3, Auftrag
 * Marketing/Reichweite/Barrierefreiheit 13.11.2026; ab 1.38.811).
 *
 *   GET /wp-json/tixomat/v1/public/calendar.ics (gleich: /public/calendar)[?organizer=<id|slug>][&artist=<id>][&venue=<id>][&city=…][&category=…]
 *   → text/calendar (RFC 5545), kommende Events (bis 200), je Event UID event-<id>@<host>, SEQUENCE aus der Änderungszeit,
 *     STATUS:CANCELLED bei abgesagten Events. In Apple-/Google-Kalender als Abo-Adresse (webcal://…) eintragbar.
 * Event-Auswahl über /public/events (gleiche Sichtbarkeit: veröffentlicht, freigegebene Veranstalter, ohne Sandbox),
 * Künstler über TIX_Artists::event_ids. Der offene JSON-Feed für Partner ist /public/events (organizer, city, venue,
 * category, from/to, per_page bis 200) – dafür gibt es keine eigene Route.
 */
if (!defined('ABSPATH')) exit;

class TIX_Calendar_Feed {

    const NS = 'tixomat/v1';
    const ROUTE = '/public/calendar.ics';

    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route(self::NS, self::ROUTE, ['methods' => 'GET', 'callback' => [__CLASS__, 'rest'], 'permission_callback' => '__return_true']);
            // ohne Endung, falls ein Server .ics-Pfade als Datei behandelt
            register_rest_route(self::NS, '/public/calendar', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest'], 'permission_callback' => '__return_true']);
        });
        add_filter('rest_pre_serve_request', [__CLASS__, 'serve_raw'], 10, 4);
    }

    /** Event-IDs für den Feed (kommende, öffentlich sichtbare) */
    public static function event_ids(WP_REST_Request $req) {
        $aid = absint($req->get_param('artist'));
        if ($aid && class_exists('TIX_Artists') && method_exists('TIX_Artists', 'event_ids')) {
            $today = current_time('Y-m-d');
            $ids = array_values(array_filter(TIX_Artists::event_ids($aid), function ($e) use ($today) { return (string) get_post_meta($e, '_tix_date_start', true) >= $today; }));
            usort($ids, function ($a, $b) { return strcmp((string) get_post_meta($a, '_tix_date_start', true), (string) get_post_meta($b, '_tix_date_start', true)); });
            return array_slice($ids, 0, 200);
        }
        $q = new WP_REST_Request('GET', '/' . self::NS . '/public/events');
        $params = ['per_page' => 200, 'filter' => 'upcoming'];
        foreach (['organizer', 'venue', 'city', 'category'] as $k) if ($req->get_param($k) !== null && $req->get_param($k) !== '') $params[$k] = $req->get_param($k);
        $q->set_query_params($params);
        $res = rest_do_request($q);
        if ($res->is_error()) return [];
        $d = $res->get_data();
        $list = is_array($d) && isset($d['events']) ? $d['events'] : (is_array($d) ? $d : []);
        $ids = [];
        foreach ((array) $list as $e) { $id = intval(is_array($e) ? ($e['id'] ?? 0) : 0); if ($id) $ids[] = $id; }
        return $ids;
    }

    public static function build(array $ids, $name) {
        if (!class_exists('TIX_Calendar')) require_once TIXOMAT_PATH . 'includes/class-tix-calendar.php';
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $now = gmdate('Ymd\THis\Z');
        $L = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//evendis//Kalender//DE", "CALSCALE:GREGORIAN", "METHOD:PUBLISH",
            "X-WR-CALNAME:" . TIX_Calendar::ics_escape($name), "X-WR-TIMEZONE:" . wp_timezone_string(), "REFRESH-INTERVAL;VALUE=DURATION:PT6H", "X-PUBLISHED-TTL:PT6H"];
        foreach ($ids as $id) {
            $d = TIX_Calendar::get_event_data($id);
            if (!$d) continue;
            $mod = strtotime((string) get_post_field('post_modified_gmt', $id) . ' UTC') ?: time();
            $L[] = "BEGIN:VEVENT";
            $L[] = "UID:event-$id@$host";
            $L[] = "DTSTAMP:$now";
            $L[] = "SEQUENCE:" . max(0, intval(($mod - 1700000000) / 60));
            $L[] = "DTSTART;" . $d['ics_start'];
            $L[] = "DTEND;" . $d['ics_end'];
            $L[] = "SUMMARY:" . TIX_Calendar::ics_escape(html_entity_decode($d['title'], ENT_QUOTES, 'UTF-8'));
            if ($d['description']) $L[] = "DESCRIPTION:" . TIX_Calendar::ics_escape($d['description']);
            if ($d['location']) $L[] = "LOCATION:" . TIX_Calendar::ics_escape($d['location']);
            if ($d['url']) $L[] = "URL:" . $d['url'];
            if ((string) get_post_meta($id, '_tix_status', true) === 'cancelled') $L[] = "STATUS:CANCELLED";
            $L[] = "END:VEVENT";
        }
        $L[] = "END:VCALENDAR";
        // ics_datetime liefert „TZID=…:…“ bzw. „…Z“; vor UTC-Werten darf kein „;“ stehen
        $out = implode("\r\n", $L) . "\r\n";
        return preg_replace('/^(DTSTART|DTEND);(\d{8}T\d{6}Z)/m', '$1:$2', $out);
    }

    public static function rest(WP_REST_Request $req) {
        $name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $org = (string) $req->get_param('organizer');
        if ($org !== '') {
            $oid = ctype_digit($org) ? intval($org) : intval((get_page_by_path(sanitize_title($org), OBJECT, 'tix_organizer') ?: (object) ['ID' => 0])->ID);
            if ($oid && get_post_type($oid) === 'tix_organizer') $name = html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8');
        }
        if (absint($req->get_param('artist'))) $name = html_entity_decode(get_the_title(absint($req->get_param('artist'))), ENT_QUOTES, 'UTF-8') ?: $name;
        $r = new WP_REST_Response(self::build(self::event_ids($req), $name . ' – Events'), 200);
        $r->header('Content-Type', 'text/calendar; charset=utf-8');
        $r->header('Cache-Control', 'public, max-age=1800');
        return $r;
    }

    /** ICS roh ausliefern statt als JSON-Zeichenkette */
    public static function serve_raw($served, $result, $request, $server) {
        if ($served || !($request instanceof WP_REST_Request) || !in_array($request->get_route(), ['/' . self::NS . self::ROUTE, '/' . self::NS . '/public/calendar'], true)) return $served;
        if (!($result instanceof WP_REST_Response) || $result->get_status() !== 200 || !is_string($result->get_data())) return $served;
        foreach ($result->get_headers() as $k => $v) header("$k: $v");
        header('Content-Disposition: inline; filename="events.ics"');
        echo $result->get_data();
        return true;
    }
}

TIX_Calendar_Feed::init();
