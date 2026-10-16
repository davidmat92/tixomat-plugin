<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Weather – Wettervorhersage über den eigenen Server (1.38.482).
 *
 * Die Apps (tixomat_core `BrightSkyWeatherApi`) fragten Bright Sky (api.brightsky.dev, Daten des Deutschen Wetterdienstes)
 * direkt vom Gerät ab. Über diese Route sieht Bright Sky nur noch den Server: übertragen werden Koordinaten (gerundet auf
 * 3 Stellen, rund 100 m) und der Zeitraum, keine IP, kein Gerät, keine Kennung des Nutzers.
 *
 *   GET /public/weather?lat=&lon=&date=&last_date=&tz=
 *       Parameter wie bei Bright Sky `/weather` (lng statt lon und from/to statt date/last_date gehen auch).
 *       date/last_date: YYYY-MM-DD oder YYYY-MM-DDTHH:MM; ohne last_date = date + 1 Tag; höchstens 10 Tage,
 *       nicht mehr als 3 Tage zurück und 12 Tage voraus. tz: Zeitzone (Vorgabe Europe/Berlin).
 *       → Antwort von Bright Sky 1:1 ({weather: [...], sources: [...]}), Kopf X-Tix-Weather: hit|miss|stale.
 *   Fehler: tix_weather_invalid (400, data.field), tix_weather_limit (429), tix_weather_none (404, keine Daten für den Ort),
 *           tix_weather_error (502, Bright Sky nicht erreichbar und kein Cache), tix_weather_off (404, per Filter aus).
 *
 * Cache je gerundeter Koordinate + Zeitraum + Zeitzone 30 min (Transient), bei Ausfall bis 6 h alte Antwort („stale“).
 * Limit je Aufrufer 60/min (Schlüssel = HMAC der IP mit dem Salt der Seite, nur 60 s gültig, keine IP gespeichert)
 * und gesamt je Tag `tix_weather_daily_limit` Abrufe bei Bright Sky (Vorgabe 5000, Cache-Treffer zählen nicht).
 */
class TIX_Weather {

    const NS        = 'tixomat/v1';
    const HOST      = 'api.brightsky.dev';
    const TTL       = 30 * MINUTE_IN_SECONDS;
    const STALE_TTL = 6 * HOUR_IN_SECONDS;
    const OPT_LIMIT = 'tix_weather_daily_limit';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/public/weather', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_weather'], 'permission_callback' => '__return_true']);
    }

    private static function err($code, $msg, $status, $field = '') {
        return new WP_Error($code, $msg, ['status' => $status] + ($field !== '' ? ['field' => $field] : []));
    }

    /** Datum bzw. Datum+Uhrzeit → "YYYY-MM-DDTHH:MM" oder ''. */
    private static function date_param($v) {
        $v = trim((string) $v);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $v, $m)) $v = $m[1] . 'T00:00';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::\d{2})?$/', $v, $m)) return '';
        if (!checkdate(intval($m[2]), intval($m[3]), intval($m[1])) || intval($m[4]) > 23 || intval($m[5]) > 59) return '';
        return sprintf('%s-%s-%sT%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5]);
    }

    private static function limited($ip) {
        $k = 'tix_wx_ip_' . substr(hash_hmac('sha256', (string) $ip, wp_salt('nonce')), 0, 32);
        $d = get_transient($k);
        if (!is_array($d) || time() - intval($d['t']) >= MINUTE_IN_SECONDS) $d = ['n' => 0, 't' => time()];
        $d['n']++;
        set_transient($k, $d, MINUTE_IN_SECONDS);
        return $d['n'] > intval(apply_filters('tix_weather_per_minute', 60));
    }

    private static function day_count($add = false) {
        $k = 'tix_wx_day_' . gmdate('Ymd');
        $n = intval(get_transient($k));
        if ($add) set_transient($k, ++$n, DAY_IN_SECONDS + HOUR_IN_SECONDS);
        return $n;
    }

    private static function client_ip() {
        if (class_exists('TIX_Client_IP') && method_exists('TIX_Client_IP', 'get')) return (string) TIX_Client_IP::get();
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function respond($data, $mode) {
        $r = new WP_REST_Response($data, 200);
        $r->header('X-Tix-Weather', $mode);
        $r->header('Cache-Control', 'public, max-age=' . ($mode === 'stale' ? 300 : self::TTL));
        return $r;
    }

    public static function rest_weather(WP_REST_Request $req) {
        if (!apply_filters('tix_weather_enabled', true)) return self::err('tix_weather_off', 'Wetter ist hier nicht eingerichtet.', 404);

        $lat_raw = $req->get_param('lat');
        $lon_raw = $req->get_param('lon') ?? $req->get_param('lng');
        if (!is_numeric($lat_raw) || floatval($lat_raw) < -90 || floatval($lat_raw) > 90) return self::err('tix_weather_invalid', 'lat fehlt oder ist ungültig.', 400, 'lat');
        if (!is_numeric($lon_raw) || floatval($lon_raw) < -180 || floatval($lon_raw) > 180) return self::err('tix_weather_invalid', 'lon fehlt oder ist ungültig.', 400, 'lon');
        $lat = number_format(round(floatval($lat_raw), 3), 3, '.', '');
        $lon = number_format(round(floatval($lon_raw), 3), 3, '.', '');

        $tz_raw = (string) ($req->get_param('tz') ?? 'Europe/Berlin');
        if (!in_array($tz_raw, timezone_identifiers_list(), true) && $tz_raw !== 'UTC') return self::err('tix_weather_invalid', 'tz ist keine gültige Zeitzone.', 400, 'tz');
        $tz = new DateTimeZone($tz_raw);

        $date_in = $req->get_param('date') ?? $req->get_param('from');
        $date = self::date_param($date_in ?? '');
        if ($date_in === null || $date_in === '') $date = (new DateTimeImmutable('today', $tz))->format('Y-m-d\T00:00');
        if ($date === '') return self::err('tix_weather_invalid', 'date ist ungültig (YYYY-MM-DD oder YYYY-MM-DDTHH:MM).', 400, 'date');
        $last_in = $req->get_param('last_date') ?? $req->get_param('to');
        $start = new DateTimeImmutable($date, $tz);
        if ($last_in === null || $last_in === '') {
            $last = $start->modify('+1 day')->format('Y-m-d\TH:i');
        } else {
            $last = self::date_param($last_in);
            if ($last === '') return self::err('tix_weather_invalid', 'last_date ist ungültig (YYYY-MM-DD oder YYYY-MM-DDTHH:MM).', 400, 'last_date');
        }
        $end = new DateTimeImmutable($last, $tz);
        if ($end <= $start) return self::err('tix_weather_invalid', 'last_date muss nach date liegen.', 400, 'last_date');
        if ($end->getTimestamp() - $start->getTimestamp() > 10 * DAY_IN_SECONDS) return self::err('tix_weather_invalid', 'Höchstens 10 Tage auf einmal.', 400, 'last_date');
        $today = (new DateTimeImmutable('today', $tz))->getTimestamp();
        if ($start->getTimestamp() < $today - 3 * DAY_IN_SECONDS || $start->getTimestamp() > $today + 12 * DAY_IN_SECONDS) {
            return self::err('tix_weather_invalid', 'date liegt außerhalb des Vorhersage-Zeitraums (3 Tage zurück bis 12 Tage voraus).', 400, 'date');
        }

        if (self::limited(self::client_ip())) return self::err('tix_weather_limit', 'Zu viele Anfragen – bitte kurz warten.', 429);

        $q = ['lat' => $lat, 'lon' => $lon, 'date' => $date, 'last_date' => $last, 'tz' => $tz_raw];
        $ck = 'tix_wx_' . md5(wp_json_encode($q));
        $hit = get_transient($ck);
        if (is_array($hit)) return self::respond($hit, 'hit');

        $stale = get_transient($ck . '_s');
        $limit = intval(get_option(self::OPT_LIMIT, 5000));
        if (self::day_count() >= ($limit > 0 ? $limit : 5000)) {
            if (is_array($stale)) return self::respond($stale, 'stale');
            return self::err('tix_weather_limit', 'Das Wetter ist gerade nicht verfügbar.', 429);
        }
        self::day_count(true);

        $url = add_query_arg(array_map('rawurlencode', $q), 'https://' . self::HOST . '/weather');
        $r = wp_remote_get($url, [
            'timeout'    => 8,
            'user-agent' => 'Tixomat/' . (defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '1') . ' (+' . home_url('/') . ')',
            'headers'    => ['Accept' => 'application/json'],
        ]);
        $code = is_wp_error($r) ? 0 : intval(wp_remote_retrieve_response_code($r));
        $data = $code ? json_decode((string) wp_remote_retrieve_body($r), true) : null;

        if ($code === 200 && is_array($data) && isset($data['weather']) && is_array($data['weather'])) {
            set_transient($ck, $data, self::TTL);
            set_transient($ck . '_s', $data, self::STALE_TTL);
            return self::respond($data, 'miss');
        }
        if ($code === 404) return self::err('tix_weather_none', 'Für diesen Ort gibt es keine Wetterdaten.', 404);
        if ($code === 400 || $code === 422) {
            error_log('TIX Weather: Bright Sky ' . $code . ' für ' . wp_json_encode($q));
            return self::err('tix_weather_invalid', 'Die Wetter-Abfrage ist ungültig.', 400);
        }
        error_log('TIX Weather: ' . (is_wp_error($r) ? 'Verbindung: ' . $r->get_error_message() : 'HTTP ' . $code));
        if (is_array($stale)) return self::respond($stale, 'stale');
        return self::err('tix_weather_error', 'Das Wetter ist gerade nicht erreichbar.', 502);
    }
}
