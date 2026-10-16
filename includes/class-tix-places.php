<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Places – Orts- und Adresssuche über Google Places API (New), serverseitig (1.38.474).
 *
 * Der Schlüssel (Option `tix_google_places_key`, trägt der User ein) verlässt nie den Server; Browser und Apps fragen
 * nur diese Routen. Besucher-IPs gehen nicht an Google (kein X-Forwarded-For, keine userIp), Aufrufe über IPv4
 * (der Schlüssel ist auf die IPv4 des Servers beschränkt).
 *
 *   GET /places/autocomplete?q=&session=&type=venue|address → {results: [{place_id, name, address}], session}
 *       q unter 3 Zeichen → {results: []} ohne Google-Aufruf (auch ohne Schlüssel – so erkennen Clients die Route).
 *   GET /places/details?place_id=&session=                  → {place: {name, address, street, house_number, zip, city,
 *       country, country_code, lat, lng, place_id}}
 *   Fehler: tix_places_off (404, kein Schlüssel bzw. aus), tix_places_limit (429), tix_places_session (400),
 *           tix_places_origin (403, fremde Herkunft), tix_places_error (502, Google-Fehler; geloggt ohne Schlüssel).
 *
 * Schutz (auch ohne Anmeldung nutzbar, z. B. Kasse, Registrierung): Session-Token Pflicht, Limits je IP (60/min,
 * angemeldet 120/min), je Session (40 Vorschläge), gesamt je Tag (Option `tix_places_daily_limit`, Vorgabe 2000;
 * Cache-Treffer zählen nicht), Herkunft (Origin/Referer, falls gesendet) nur eigene Hosts, Antwort-Cache
 * (Vorschläge 1 Tag, Details 7 Tage). Zählung je Tag in `tix_places_stats` (Admin: Tixomat → Ortssuche).
 */
class TIX_Places {

    const NS          = 'tixomat/v1';
    const OPT_KEY     = 'tix_google_places_key';
    const OPT_LIMIT   = 'tix_places_daily_limit';
    const OPT_STATS   = 'tix_places_stats';
    const HOST        = 'places.googleapis.com';
    const MIN_CHARS   = 3;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('http_api_curl', [__CLASS__, 'force_ipv4'], 10, 3);
        add_action('admin_menu', [__CLASS__, 'menu'], 31);
        add_action('admin_post_tix_places_save', [__CLASS__, 'save']);
    }

    public static function key() {
        return trim((string) get_option(self::OPT_KEY, ''));
    }

    public static function enabled() {
        return self::key() !== '' && (bool) apply_filters('tix_places_enabled', true);
    }

    public static function daily_limit() {
        $v = intval(get_option(self::OPT_LIMIT, 2000));
        return $v > 0 ? $v : 2000;
    }

    /** Nur für places.googleapis.com: IPv4 erzwingen. */
    public static function force_ipv4($handle, $args, $url) {
        if (strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST)) === self::HOST && defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
    }

    public static function routes() {
        $open = ['permission_callback' => '__return_true'];
        register_rest_route(self::NS, '/places/autocomplete', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_autocomplete'], 'args' => [
            'q' => ['type' => 'string'], 'session' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['venue', 'address'], 'default' => 'venue'],
        ]] + $open);
        register_rest_route(self::NS, '/places/details', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_details'], 'args' => [
            'place_id' => ['type' => 'string', 'required' => true], 'session' => ['type' => 'string'],
        ]] + $open);
    }

    // ═══════════════ Schutz ═══════════════

    private static function err($code, $msg, $status, $field = '') {
        return new WP_Error($code, $msg, ['status' => $status] + ($field !== '' ? ['field' => $field] : []));
    }

    private static function client_ip() {
        if (class_exists('TIX_Client_IP') && method_exists('TIX_Client_IP', 'get')) return (string) TIX_Client_IP::get();
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** Eigene Hosts: WordPress, Kunden-Adresse, Studio, eigene Domains der Veranstalter. */
    public static function own_hosts() {
        $hosts = [wp_parse_url(home_url(), PHP_URL_HOST), wp_parse_url(site_url(), PHP_URL_HOST)];
        if (class_exists('TIX_Customer_URL')) $hosts[] = wp_parse_url((string) TIX_Customer_URL::base(), PHP_URL_HOST);
        $studio = trim((string) get_option('tix_studio_url', ''));
        if ($studio !== '') $hosts[] = wp_parse_url($studio, PHP_URL_HOST);
        $doms = get_option(defined('TIX_Customer_URL::OPTION_DOMAINS') ? TIX_Customer_URL::OPTION_DOMAINS : 'tix_org_domains_active', []);
        if (is_array($doms)) $hosts = array_merge($hosts, array_keys($doms));
        $out = [];
        foreach ($hosts as $h) {
            $h = strtolower(trim((string) $h));
            if ($h === '') continue;
            $out[] = $h;
            $out[] = preg_match('/^www\./', $h) ? substr($h, 4) : 'www.' . $h;
        }
        return array_values(array_unique(array_merge($out, (array) apply_filters('tix_places_hosts', []))));
    }

    /** Origin/Referer, falls gesendet, muss ein eigener Host sein (Apps senden keinen – erlaubt). */
    private static function origin_ok(WP_REST_Request $req) {
        $src = (string) ($req->get_header('origin') ?: $req->get_header('referer'));
        if ($src === '' || $src === 'null') return true;
        $host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
        if ($host === '') return true;
        if (in_array($host, ['localhost', '127.0.0.1'], true) && (defined('WP_DEBUG') && WP_DEBUG)) return true;
        foreach (self::own_hosts() as $h) {
            if ($host === $h) return true;
            // Unterdomains der Kunden-Adresse (z. B. studio.evendis.de, <slug>.evendis.de)
            if (substr($h, 0, 4) !== 'www.' && substr($host, -strlen('.' . $h)) === '.' . $h) return true;
        }
        return false;
    }

    private static function session(WP_REST_Request $req) {
        $s = (string) $req->get_param('session');
        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $s) ? $s : '';
    }

    private static function bucket($name, $max, $window, $key) {
        $k = 'tix_pl_' . $name . '_' . md5($key);
        $d = get_transient($k);
        if (!is_array($d) || time() - intval($d['t']) >= $window) $d = ['n' => 0, 't' => time()];
        $d['n']++;
        set_transient($k, $d, $window);
        return $d['n'] > $max;
    }

    /** Gemeinsame Prüfung; null = ok, sonst WP_Error. */
    private static function guard(WP_REST_Request $req, $session, $kind) {
        if (!self::enabled()) return self::err('tix_places_off', 'Die Adresssuche ist hier nicht eingerichtet.', 404);
        if (!self::origin_ok($req)) return self::err('tix_places_origin', 'Nicht erlaubt.', 403);
        if ($session === '') return self::err('tix_places_session', 'Bitte ein Session-Token mitsenden (8–64 Zeichen).', 400, 'session');
        $ip = self::client_ip();
        $per_min = is_user_logged_in() ? 120 : 60;
        if (self::bucket('ip', $per_min, MINUTE_IN_SECONDS, $ip . '|' . get_current_user_id())) return self::err('tix_places_limit', 'Zu viele Anfragen – bitte kurz warten.', 429);
        if ($kind === 'auto' && self::bucket('sess', 40, HOUR_IN_SECONDS, $session)) return self::err('tix_places_limit', 'Zu viele Anfragen in dieser Sitzung.', 429);
        if ($kind === 'details' && self::bucket('sessd', 5, HOUR_IN_SECONDS, $session)) return self::err('tix_places_limit', 'Zu viele Anfragen in dieser Sitzung.', 429);
        return null;
    }

    private static function stats() {
        $s = get_option(self::OPT_STATS, []);
        return is_array($s) ? $s : [];
    }

    /** Tageszähler (nur echte Google-Aufrufe); true = Tageslimit erreicht. */
    private static function count_call($kind, $error = false) {
        $s = self::stats();
        $day = current_time('Y-m-d');
        $row = $s[$day] ?? ['auto' => 0, 'details' => 0, 'errors' => 0];
        if ($error) $row['errors'] = intval($row['errors'] ?? 0) + 1;
        else $row[$kind] = intval($row[$kind] ?? 0) + 1;
        $s[$day] = $row;
        if (count($s) > 400) { ksort($s); $s = array_slice($s, -400, null, true); }
        update_option(self::OPT_STATS, $s, false);
    }

    private static function day_total() {
        $row = self::stats()[current_time('Y-m-d')] ?? [];
        return intval($row['auto'] ?? 0) + intval($row['details'] ?? 0);
    }

    // ═══════════════ Google ═══════════════

    private static function google($method, $path, array $body = null, array $headers = []) {
        $url = 'https://' . self::HOST . $path;
        $args = ['method' => $method, 'timeout' => 8, 'headers' => array_merge(['X-Goog-Api-Key' => self::key(), 'Content-Type' => 'application/json'], $headers)];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $r = wp_remote_request($url, $args);
        if (is_wp_error($r)) return self::google_error('Verbindung: ' . $r->get_error_message());
        $code = intval(wp_remote_retrieve_response_code($r));
        $data = json_decode((string) wp_remote_retrieve_body($r), true);
        if ($code !== 200 || !is_array($data)) {
            $msg = is_array($data) && isset($data['error']) ? (string) ($data['error']['status'] ?? '') . ' ' . (string) ($data['error']['message'] ?? '') : 'HTTP ' . $code;
            return self::google_error($msg, $code);
        }
        return $data;
    }

    private static function google_error($msg, $code = 0) {
        $msg = str_replace(self::key(), '***', (string) $msg); // nie den Schlüssel loggen
        error_log('TIX Places: ' . ($code ? $code . ' ' : '') . mb_substr($msg, 0, 300));
        self::count_call('', true);
        $off = in_array($code, [401, 403], true) || stripos($msg, 'PERMISSION_DENIED') !== false || stripos($msg, 'API_KEY') !== false || stripos($msg, 'API key') !== false;
        return $off ? self::err('tix_places_off', 'Die Adresssuche ist gerade nicht verfügbar.', 404) : self::err('tix_places_error', 'Die Adresssuche ist gerade nicht erreichbar.', 502);
    }

    // ═══════════════ REST ═══════════════

    public static function rest_autocomplete(WP_REST_Request $req) {
        $q = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $req->get_param('q'))));
        $session = self::session($req);
        if (mb_strlen($q) < self::MIN_CHARS) return rest_ensure_response(['results' => [], 'session' => $session !== '' ? $session : null]);
        $q = mb_substr($q, 0, 120);
        $type = $req->get_param('type') === 'address' ? 'address' : 'venue';
        if ($g = self::guard($req, $session, 'auto')) return $g;
        $ck = 'tix_pl_a_' . md5($type . '|' . mb_strtolower($q));
        $cached = get_transient($ck);
        if (is_array($cached)) return rest_ensure_response(['results' => $cached, 'session' => $session]);
        if (self::day_total() >= self::daily_limit()) return self::err('tix_places_limit', 'Die Adresssuche ist für heute ausgeschöpft.', 429);
        $body = ['input' => $q, 'sessionToken' => $session, 'languageCode' => 'de', 'regionCode' => 'DE',
            'includedPrimaryTypes' => $type === 'address' ? ['street_address', 'premise', 'subpremise', 'route'] : ['establishment']];
        $data = self::google('POST', '/v1/places:autocomplete', $body);
        if (is_wp_error($data)) return $data;
        self::count_call('auto');
        $out = [];
        foreach ((array) ($data['suggestions'] ?? []) as $s) {
            $p = $s['placePrediction'] ?? null;
            if (!is_array($p) || empty($p['placeId'])) continue;
            $out[] = ['place_id' => (string) $p['placeId'], 'name' => (string) ($p['structuredFormat']['mainText']['text'] ?? $p['text']['text'] ?? ''),
                'address' => (string) ($p['structuredFormat']['secondaryText']['text'] ?? '')];
        }
        set_transient($ck, $out, DAY_IN_SECONDS);
        return rest_ensure_response(['results' => $out, 'session' => $session]);
    }

    public static function rest_details(WP_REST_Request $req) {
        $pid = (string) $req->get_param('place_id');
        if (!preg_match('/^[A-Za-z0-9_-]{10,300}$/', $pid)) return self::err('tix_invalid_field', 'Ungültige place_id.', 400, 'place_id');
        $session = self::session($req);
        if ($g = self::guard($req, $session, 'details')) return $g;
        $ck = 'tix_pl_d_' . md5($pid);
        $cached = get_transient($ck);
        if (is_array($cached)) return rest_ensure_response(['place' => $cached]);
        if (self::day_total() >= self::daily_limit()) return self::err('tix_places_limit', 'Die Adresssuche ist für heute ausgeschöpft.', 429);
        $data = self::google('GET', '/v1/places/' . rawurlencode($pid) . '?languageCode=de&regionCode=DE&sessionToken=' . rawurlencode($session), null,
            ['X-Goog-FieldMask' => 'id,displayName,formattedAddress,addressComponents,location']);
        if (is_wp_error($data)) return $data;
        self::count_call('details');
        $place = self::normalize_place($data);
        set_transient($ck, $place, 7 * DAY_IN_SECONDS);
        return rest_ensure_response(['place' => $place]);
    }

    /** Google-Antwort → zerlegte Adresse. Öffentlich für Tests und für die Orte-Verwaltung. */
    public static function normalize_place(array $d) {
        $c = [];
        foreach ((array) ($d['addressComponents'] ?? []) as $ac) {
            foreach ((array) ($ac['types'] ?? []) as $t) if (!isset($c[$t])) $c[$t] = ['long' => (string) ($ac['longText'] ?? ''), 'short' => (string) ($ac['shortText'] ?? '')];
        }
        $g = function ($t, $f = 'long') use ($c) { return $c[$t][$f] ?? ''; };
        $city = $g('locality') ?: ($g('postal_town') ?: ($g('administrative_area_level_3') ?: $g('sublocality')));
        return [
            'name' => (string) ($d['displayName']['text'] ?? ''),
            'address' => (string) ($d['formattedAddress'] ?? ''),
            'street' => $g('route'),
            'house_number' => $g('street_number'),
            'zip' => $g('postal_code'),
            'city' => $city,
            'country' => $g('country'),
            'country_code' => strtoupper($g('country', 'short')),
            'lat' => isset($d['location']['latitude']) ? round(floatval($d['location']['latitude']), 7) : null,
            'lng' => isset($d['location']['longitude']) ? round(floatval($d['location']['longitude']), 7) : null,
            'place_id' => (string) ($d['id'] ?? ''),
        ];
    }

    // ═══════════════ Admin: Kosten sichtbar ═══════════════

    public static function menu() {
        add_submenu_page('tixomat', 'Ortssuche (Google)', 'Ortssuche (Google)', 'manage_options', 'tix-places', [__CLASS__, 'render']);
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_places_save');
        update_option(self::OPT_LIMIT, max(1, intval($_POST['limit'] ?? 2000)), false);
        wp_safe_redirect(add_query_arg(['page' => 'tix-places', 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $s = self::stats();
        krsort($s);
        $months = [];
        foreach ($s as $day => $r) {
            $m = substr($day, 0, 7);
            $months[$m] = ($months[$m] ?? ['auto' => 0, 'details' => 0, 'errors' => 0]);
            foreach (['auto', 'details', 'errors'] as $k) $months[$m][$k] += intval($r[$k] ?? 0);
        }
        ?>
        <div class="wrap">
            <h1>Ortssuche (Google Places)</h1>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <p>Schlüssel: <strong><?php echo self::key() !== '' ? 'eingetragen' : 'fehlt (Option tix_google_places_key)'; ?></strong>. Gezählt werden nur echte Aufrufe bei Google (Treffer aus dem Zwischenspeicher sind kostenlos). Vorschläge innerhalb einer Sitzung mit anschließender Detail-Abfrage rechnet Google als eine Sitzung ab.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tix_places_save"><?php wp_nonce_field('tix_places_save'); ?>
                <label>Tageslimit (Aufrufe gesamt): <input type="number" min="1" name="limit" value="<?php echo intval(self::daily_limit()); ?>" style="width:100px;"></label>
                <?php submit_button('Speichern', 'secondary', 'submit', false); ?>
            </form>
            <h2>Je Monat</h2>
            <table class="widefat striped" style="max-width:640px;"><thead><tr><th>Monat</th><th>Vorschläge</th><th>Details</th><th>Fehler</th></tr></thead><tbody>
            <?php foreach ($months as $m => $r): ?><tr><td><?php echo esc_html($m); ?></td><td><?php echo intval($r['auto']); ?></td><td><?php echo intval($r['details']); ?></td><td><?php echo intval($r['errors']); ?></td></tr><?php endforeach; ?>
            <?php if (!$months): ?><tr><td colspan="4">Noch keine Aufrufe.</td></tr><?php endif; ?>
            </tbody></table>
            <h2>Letzte 30 Tage</h2>
            <table class="widefat striped" style="max-width:640px;"><thead><tr><th>Tag</th><th>Vorschläge</th><th>Details</th><th>Fehler</th></tr></thead><tbody>
            <?php foreach (array_slice($s, 0, 30, true) as $d => $r): ?><tr><td><?php echo esc_html($d); ?></td><td><?php echo intval($r['auto'] ?? 0); ?></td><td><?php echo intval($r['details'] ?? 0); ?></td><td><?php echo intval($r['errors'] ?? 0); ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }
}
