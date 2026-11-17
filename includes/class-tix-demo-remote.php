<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Demo_Remote – ein Demo-Zugang mit Fällen (Plattform / Demo-Shop / Demo-Club, beliebig kombiniert), Verbindung Plattform →
 * Demo-Instanzen (1.38.843, drei Fälle seit 1.38.844; Vertrag uebergaben/vertrag-demo-shop.md „Fälle“, DOCUMENTATION §228/§229).
 *
 *  - Ziele (Instanzen): shop = demo-shop.evendis.de (Vorlage openair, ein Event), club = demo-club.evendis.de (Vorlage club,
 *    mehrere Events). Je Ziel eigene Plattform-Optionen (URL, Schlüssel, Zwischenspeicher). Auf der Instanz selbst gibt es keine
 *    Rolle: sie nimmt immer den Schlüssel aus tix_demo_shop_key – welche Instanz „shop“ oder „club“ ist, legt nur die Plattform fest.
 *
 *  - Die Plattform (evendis.de, Mehr-Veranstalter-Modus) führt: Liste, Limit, Laufzeit und Eigentümer-Prüfung bleiben dort.
 *    Für den Fall „shop“ ruft sie die Instanz Server → Server auf, signiert mit dem gemeinsamen Schlüssel.
 *  - Die Instanz (demo-shop.evendis.de, TIX_Demo_Shop::on()) nimmt unter /demo-access/remote NUR signierte Aufrufe an –
 *    nie einen Nutzer-Token – und nur mit tix_demo_remote_enabled = 1. Sie verwaltet dort nur Zugänge, die per Signatur
 *    angelegt wurden (Meta _tix_demo_remote_id); eigene Zugänge der Instanz, Admins und Vertriebler bleiben unerreichbar.
 *  - Signatur: hex(HMAC-SHA256(key, Zeit\nMETHODE\nRoute mit Query\nBody)), Header X-Tix-Remote-Time und X-Tix-Remote-Sig.
 *    Route = REST-Route ab Namensraum (/tixomat/v1/demo-access/remote…), Query sortiert (RFC 3986, ohne rest_route);
 *    die Plattform hängt je Aufruf einen Zufallswert _n an (Teil der Signatur), damit gleiche Aufrufe nie gleich signiert sind.
 *    Zeitfenster ±300 s, jede Signatur nur einmal gültig (Eintrag in wp_options per INSERT IGNORE, 15 min).
 *  - Schlüssel: Option tix_demo_shop_key (beide Seiten, ≥ 32 Zeichen), nur per WP-CLI gesetzt
 *    (wp tixomat demo-shop remote-key generate --file=… | set < Datei | status | clear), nie als Argument, nie in Antworten.
 */
class TIX_Demo_Remote {

    const OPT_URL = 'tix_demo_shop_url';          // Plattform: https://demo-shop.evendis.de/wp-json/tixomat/v1
    const OPT_KEY = 'tix_demo_shop_key';          // Instanz (immer) und Plattform (Ziel shop): gemeinsames Geheimnis
    /** Plattform: Optionen je Ziel (1.38.844) */
    const TARGETS = [
        'shop' => ['url' => 'tix_demo_shop_url', 'key' => 'tix_demo_shop_key', 'cache' => 'tix_demo_shop_state', 'label' => 'Demo-Shop'],
        'club' => ['url' => 'tix_demo_club_url', 'key' => 'tix_demo_club_key', 'cache' => 'tix_demo_club_state', 'label' => 'Demo-Club'],
    ];
    const OPT_ENABLED = 'tix_demo_remote_enabled'; // Instanz: signierte Aufrufe annehmen
    const OPT_LINKS = 'tix_demo_links';           // {hub, platform, shop, club} für „Anderen Fall ansehen“
    const WINDOW = 300;
    const REPLAY_TTL = 900;
    const MIN_KEY = 32;
    const LINKS = ['hub' => 'https://demo.evendis.de/', 'platform' => 'https://demo-studio.evendis.de/', 'shop' => 'https://studio.demo-shop.evendis.de/', 'club' => 'https://studio.demo-club.evendis.de/'];
    const CASES = ['platform', 'shop', 'club'];
    /** Fälle, die auf einer Demo-Instanz liegen (= Ziele) */
    const INSTANCE_CASES = ['shop', 'club'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_post_dispatch', [__CLASS__, 'config_links'], 61, 3);
    }

    // ═══════════════ Schlüssel ═══════════════

    public static function target_ok($t) {
        return is_string($t) && isset(self::TARGETS[$t]);
    }

    private static function t($target) {
        return self::TARGETS[self::target_ok($target) ? $target : 'shop'];
    }

    /** Schlüssel: Instanz immer tix_demo_shop_key; Plattform je Ziel */
    private static function key($target = 'shop') {
        $k = trim((string) get_option(self::t($target)['key'], ''));
        return strlen($k) >= self::MIN_KEY ? $k : '';
    }

    /** Kurzer Fingerabdruck zum Vergleich beider Seiten (verrät den Schlüssel nicht) */
    public static function fingerprint($target = 'shop') {
        $k = self::key($target);
        return $k === '' ? '' : substr(hash('sha256', 'tix-demo-remote-fp|' . $k), 0, 12);
    }

    /** „Der Demo-Shop“ / „Der Demo-Club“ für Meldungen */
    public static function label($target) {
        return self::t($target)['label'];
    }

    // ═══════════════ Signatur ═══════════════

    /** Query kanonisch: sortiert, RFC 3986, ohne rest_route */
    public static function canon_query(array $q) {
        unset($q['rest_route']);
        if (!$q) return '';
        ksort($q, SORT_STRING);
        return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    public static function base_string($time, $method, $route, array $query, $body) {
        $cq = self::canon_query($query);
        return (string) $time . "\n" . strtoupper((string) $method) . "\n" . $route . ($cq !== '' ? '?' . $cq : '') . "\n" . (string) $body;
    }

    public static function sign($key, $time, $method, $route, array $query, $body) {
        return hash_hmac('sha256', self::base_string($time, $method, $route, $query, $body), (string) $key);
    }

    // ═══════════════ Plattform: Verbindung zur Instanz ═══════════════

    /** Plattform mit eingerichteter Instanz für dieses Ziel? (Mehr-Veranstalter-Modus, URL, Schlüssel) */
    public static function platform_ready($target = 'shop') {
        return self::target_ok($target) && class_exists('TIX_Demo_Access') && TIX_Demo_Access::multi() && self::base_url($target) !== '' && self::key($target) !== '';
    }

    /** Basis-URL der Instanz des Ziels; https Pflicht außer lokalen Testseiten (Host ohne Punkt, .test, .localhost) */
    public static function base_url($target = 'shop') {
        $u = trim((string) get_option(self::t($target)['url'], ''));
        if ($u === '') return '';
        $p = wp_parse_url($u);
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '') return '';
        $local = strpos($host, '.') === false || preg_match('/\.(test|localhost)$/', $host);
        if (($p['scheme'] ?? '') !== 'https' && !$local) return '';
        return rtrim($u, '/');
    }

    /**
     * Signierter Aufruf an die Instanz → ['ok' => bool, 'status' => int, 'data' => array|null, 'error' => string]
     * Fehlertexte enthalten nie den Schlüssel oder die Signatur.
     */
    public static function call($method, $path, array $query = [], $body = null, $timeout = 15, $target = 'shop') {
        if (!self::target_ok($target)) return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'not_configured'];
        $base = self::base_url($target);
        $key = self::key($target);
        if ($base === '' || $key === '') return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'not_configured'];
        $method = strtoupper($method);
        $route = '/' . TIX_Demo_Access::NS . $path;
        // Zufallswert in der (signierten) Query: zwei gleiche Aufrufe in derselben Sekunde ergeben verschiedene Signaturen
        $query['_n'] = bin2hex(random_bytes(8));
        $raw = $body === null ? '' : wp_json_encode($body);
        $time = (string) time();
        $sig = self::sign($key, $time, $method, $route, $query, $raw);
        $cq = self::canon_query($query);
        // Basis mit ?rest_route=/tixomat/v1 (Server ohne schöne Permalinks) oder …/wp-json/tixomat/v1
        $url = strpos($base, 'rest_route=') !== false ? $base . $path . ($cq !== '' ? '&' . $cq : '') : $base . $path . ($cq !== '' ? '?' . $cq : '');
        $args = ['method' => $method, 'timeout' => $timeout, 'redirection' => 0, 'sslverify' => true,
            'headers' => ['Accept' => 'application/json', 'X-Tix-Remote-Time' => $time, 'X-Tix-Remote-Sig' => $sig]];
        if ($raw !== '') { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = $raw; }
        $args = apply_filters('tix_demo_remote_http_args', $args, $url);
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'unreachable'];
        $code = intval(wp_remote_retrieve_response_code($res));
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        return ['ok' => $code >= 200 && $code < 300 && is_array($data), 'status' => $code, 'data' => is_array($data) ? $data : null,
            'error' => $code >= 200 && $code < 300 ? (is_array($data) ? '' : 'bad_response') : (is_array($data) ? (string) ($data['code'] ?? 'http_' . $code) : 'http_' . $code)];
    }

    /**
     * Fehler für die Plattform-Antwort: 502 tix_demo_shop_unreachable (beide Ziele, data.target nennt die Instanz) oder die
     * Feldmeldung der Instanz (z. B. E-Mail vergeben, 409 tix_invalid_field mit data.target).
     */
    public static function error($r, $target = 'shop') {
        $l = self::label($target);
        $t = self::target_ok($target) ? $target : 'shop';
        if (($r['error'] ?? '') === 'not_configured') return new WP_Error('tix_demo_shop_unreachable', 'Der ' . $l . ' ist mit dieser Plattform noch nicht verbunden.', ['status' => 502, 'target' => $t]);
        $d = (array) ($r['data'] ?? []);
        if (intval($r['status'] ?? 0) === 409 && ($d['code'] ?? '') === 'tix_invalid_field') {
            $msg = (string) ($d['message'] ?? 'Im ' . $l . ' gibt es diese E-Mail schon.');
            if ($t === 'club') $msg = str_replace('Im Demo-Shop', 'Im Demo-Club', $msg);
            return new WP_Error('tix_invalid_field', $msg, ['status' => 409, 'field' => (string) ($d['data']['field'] ?? 'email'), 'target' => $t]);
        }
        if (intval($r['status'] ?? 0) === 409 && in_array($d['code'] ?? '', ['tix_demo_busy', 'tix_demo_reset'], true)) {
            return new WP_Error('tix_demo_reset', (string) ($d['message'] ?? 'Zurücksetzen fehlgeschlagen.'), ['status' => 409, 'target' => $t]);
        }
        if (intval($r['status'] ?? 0) === 429) return new WP_Error('tix_rate_limit', 'Der ' . $l . ' wurde heute schon oft zurückgesetzt. Bitte später noch einmal.', ['status' => 429, 'target' => $t]);
        return new WP_Error('tix_demo_shop_unreachable', 'Der ' . $l . ' ist gerade nicht erreichbar. Es wurde nichts geändert.', ['status' => 502, 'target' => $t]);
    }

    /** Stand der Instanz des Ziels (alle per Plattform angelegten Zugänge + Kopfdaten), 60 s zwischengespeichert → array|null */
    public static function state($fresh = false, $target = 'shop') {
        if (!self::target_ok($target)) return null;
        $ck = self::TARGETS[$target]['cache'];
        if (!$fresh) {
            $c = get_transient($ck);
            if (is_array($c)) return $c;
        }
        $r = self::call('GET', '/demo-access/remote', [], null, 15, $target);
        if (!$r['ok']) return null;
        $s = ['accesses' => [], 'shop' => (array) ($r['data']['shop'] ?? [])];
        foreach ((array) ($r['data']['accesses'] ?? []) as $a) {
            if (is_array($a) && intval($a['remote_id'] ?? 0) > 0) $s['accesses'][intval($a['remote_id'])] = $a;
        }
        set_transient($ck, $s, 60);
        set_transient($ck . '_last', $s, DAY_IN_SECONDS);
        return $s;
    }

    /** Zwischenspeicher eines Ziels (null = beider) verwerfen */
    public static function forget($target = null) {
        foreach (self::TARGETS as $t => $o) if ($target === null || $target === $t) delete_transient($o['cache']);
    }

    /** Letzter bekannter Stand (Liste bleibt vollständig, wenn die Instanz gerade nicht antwortet) */
    public static function last_state($target = 'shop') {
        if (!self::target_ok($target)) return null;
        $c = get_transient(self::TARGETS[$target]['cache'] . '_last');
        return is_array($c) ? $c : null;
    }

    // ═══════════════ Fälle ═══════════════

    /** Fälle aus der Anfrage: null = nicht übergeben (ältere Studios), [] = leer/ungültig */
    public static function clean_cases($v) {
        if ($v === null) return null;
        if (is_string($v)) $v = array_filter(array_map('trim', explode(',', $v)));
        if (!is_array($v)) return [];
        $v = array_map('strval', $v);
        return array_values(array_filter(self::CASES, function ($c) use ($v) { return in_array($c, $v, true); }));
    }

    /** Ziele (Instanz-Fälle) einer Fall-Liste in fester Reihenfolge shop, club */
    public static function instance_cases(array $cases) {
        return array_values(array_filter(self::INSTANCE_CASES, function ($c) use ($cases) { return in_array($c, $cases, true); }));
    }

    // ═══════════════ demo_links in /studio/config ═══════════════

    public static function links() {
        $o = get_option(self::OPT_LINKS, []);
        $o = is_array($o) ? $o : [];
        $out = [];
        foreach (self::LINKS as $k => $d) {
            $v = trim((string) ($o[$k] ?? ''));
            $out[$k] = preg_match('#^https://[^\s"<>]+$#', $v) ? $v : $d;
        }
        return $out;
    }

    public static function config_links($response, $server, $request) {
        if (!($response instanceof WP_REST_Response) || !($request instanceof WP_REST_Request)) return $response;
        if ((string) $request->get_route() !== '/tixomat/v1/studio/config' || $request->get_method() !== 'GET' || $response->get_status() >= 400) return $response;
        if (!class_exists('TIX_Demo_Access') || !TIX_Demo_Access::enabled()) return $response;
        $d = $response->get_data();
        if (!is_array($d)) return $response;
        $d['demo_links'] = self::links();
        $response->set_data($d);
        return $response;
    }

    // ═══════════════ Instanz: signierte Routen ═══════════════

    public static function instance_ready() {
        return class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::on() && get_option(self::OPT_ENABLED, '0') === '1' && self::key() !== '';
    }

    public static function routes() {
        $p = [__CLASS__, 'permission'];
        register_rest_route(TIX_Demo_Access::NS, '/demo-access/remote', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'r_list'], 'permission_callback' => $p],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'r_create'], 'permission_callback' => $p],
        ]);
        register_rest_route(TIX_Demo_Access::NS, '/demo-access/remote/reset', ['methods' => 'POST', 'callback' => [__CLASS__, 'r_reset'], 'permission_callback' => $p]);
        register_rest_route(TIX_Demo_Access::NS, '/demo-access/remote/(?P<rid>\d+)', [
            ['methods' => 'PATCH,POST', 'callback' => [__CLASS__, 'r_update'], 'permission_callback' => $p],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'r_delete'], 'permission_callback' => $p],
        ]);
        register_rest_route(TIX_Demo_Access::NS, '/demo-access/remote/(?P<rid>\d+)/link', ['methods' => 'POST', 'callback' => [__CLASS__, 'r_link'], 'permission_callback' => $p]);
    }

    private static function sig_error() {
        return new WP_Error('tix_remote_signature', 'Signatur fehlt oder ist ungültig.', ['status' => 401]);
    }

    /** Nur Instanz mit Schalter; nur gültige, frische, unverbrauchte Signatur; nie mit Nutzer-Token. */
    public static function permission(WP_REST_Request $req) {
        if (!self::instance_ready()) return new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]);
        if (self::too_many_failures(false)) return new WP_Error('tix_rate_limit', 'Zu viele ungültige Aufrufe.', ['status' => 429]);
        if (is_user_logged_in()) { self::too_many_failures(true); return self::sig_error(); }
        $time = trim((string) $req->get_header('x_tix_remote_time'));
        $sig = strtolower(trim((string) $req->get_header('x_tix_remote_sig')));
        if (!preg_match('/^\d{9,11}$/', $time) || !preg_match('/^[0-9a-f]{64}$/', $sig)) { self::too_many_failures(true); return self::sig_error(); }
        $exp = self::sign(self::key(), $time, $req->get_method(), (string) $req->get_route(), (array) $req->get_query_params(), (string) $req->get_body());
        if (!hash_equals($exp, $sig)) { self::too_many_failures(true); return self::sig_error(); }
        if (abs(time() - intval($time)) > self::WINDOW) return new WP_Error('tix_remote_signature', 'Signatur abgelaufen (Uhrzeit prüfen).', ['status' => 401]);
        if (!self::consume($sig)) return new WP_Error('tix_remote_signature', 'Signatur wurde schon benutzt.', ['status' => 401]);
        return true;
    }

    /** Signatur einmal verbrauchen: atomar per INSERT IGNORE (läuft als Transient ab, WP räumt abgelaufene auf) */
    private static function consume($sig) {
        global $wpdb;
        $h = 'tix_drs_' . substr(hash('sha256', $sig), 0, 40);
        $n = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", '_transient_timeout_' . $h, (string) (time() + self::REPLAY_TTL)));
        if (intval($n) !== 1) return false;
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')", '_transient_' . $h));
        return true;
    }

    /** Fehlversuche je IP begrenzen (30 in 10 min) */
    private static function too_many_failures($count) {
        $k = 'tix_drs_fail_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $n = intval(get_transient($k));
        if ($count) set_transient($k, $n + 1, 600);
        return $n >= 30;
    }

    private static function out($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    /** Per Plattform angelegter Interessent zu remote_id (nur solche – nie eigene Zugänge, Admins, Vertriebler) */
    private static function user_for($rid) {
        $rid = intval($rid);
        if ($rid <= 0) return null;
        $ids = get_users(['role' => TIX_Demo_Access::ROLE, 'meta_key' => '_tix_demo_remote_id', 'meta_value' => (string) $rid, 'number' => 1, 'fields' => 'ids']);
        $u = $ids ? get_userdata(intval($ids[0])) : null;
        return $u && TIX_Demo_Access::is_prospect($u) ? $u : null;
    }

    private static function stand(WP_User $u) {
        $last = intval(get_user_meta($u->ID, '_tix_demo_last_seen', true));
        $exp = TIX_Demo_Access::expires($u->ID);
        return [
            'remote_id'   => intval(get_user_meta($u->ID, '_tix_demo_remote_id', true)),
            'status'      => TIX_Demo_Access::status($u->ID),
            'last_seen'   => $last ? wp_date('c', $last) : null,
            'test_orders' => TIX_Demo_Access::test_orders_of($u),
            'expires_at'  => $exp ? wp_date('c', $exp) : null,
            'extended'    => (bool) get_user_meta($u->ID, '_tix_demo_extended', true),
        ];
    }

    /** Ablauf von der Plattform übernehmen: Zukunft, höchstens 2 × Laufzeit + 1 Tag */
    private static function clean_expires($v) {
        $ts = is_numeric($v) ? intval($v) : (is_string($v) && $v !== '' ? intval(strtotime($v)) : 0);
        if ($ts <= 0) return 0;
        $max = time() + (2 * TIX_Demo_Access::DAYS + 1) * DAY_IN_SECONDS;
        return min($ts, $max);
    }

    /** GET /demo-access/remote?ids=1,2,3 → {ok, accesses:[{remote_id, status, last_seen, test_orders, expires_at, extended}], shop:{…}} */
    public static function r_list(WP_REST_Request $req) {
        $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $req->get_param('ids'))))), 0, 500);
        $args = ['role' => TIX_Demo_Access::ROLE, 'number' => 1000];
        $args['meta_query'] = $ids ? [['key' => '_tix_demo_remote_id', 'value' => array_map('strval', $ids), 'compare' => 'IN']] : [['key' => '_tix_demo_remote_id', 'compare' => 'EXISTS']];
        $rows = [];
        foreach (get_users($args) as $u) if (TIX_Demo_Access::is_prospect($u)) $rows[] = self::stand($u);
        // Kopfzahlen der ganzen Instanz (Admin der Plattform); Vertriebler bekommen auf der Plattform nur eigene Zahlen
        $all = [];
        foreach (get_users(['role' => TIX_Demo_Access::ROLE, 'number' => 1000]) as $u) {
            if (!TIX_Demo_Access::is_prospect($u)) continue;
            $last = intval(get_user_meta($u->ID, '_tix_demo_last_seen', true));
            $all[] = ['usable' => TIX_Demo_Access::usable($u->ID), 'seen' => $last >= time() - 7 * DAY_IN_SECONDS, 'orders' => TIX_Demo_Access::test_orders_of($u)];
        }
        return self::out(['ok' => true, 'accesses' => $rows, 'shop' => [
            'studio' => TIX_Demo_Shop::studio_url(), 'home' => TIX_Demo_Shop::home_url(), 'vorlage' => TIX_Demo_Shop::vorlage(),
            'last_reset' => TIX_Demo_Shop::last_reset(), 'reset_at' => wp_date('c', TIX_Demo_Shop::next_reset_ts()),
            'summary' => ['active' => count(array_filter($all, function ($a) { return $a['usable']; })), 'seen_week' => count(array_filter($all, function ($a) { return $a['seen']; })),
                'test_orders' => array_sum(array_column($all, 'orders'))],
        ]]);
    }

    /** POST /demo-access/remote {remote_id, owner{id,name,email}, name, email, company?, expires_at, extended?, invite:false} → 201 {ok, access, login_url} */
    public static function r_create(WP_REST_Request $req) {
        $rid = intval($req->get_param('remote_id'));
        if ($rid <= 0) return new WP_Error('tix_invalid_field', 'remote_id fehlt.', ['status' => 400, 'field' => 'remote_id']);
        if (self::user_for($rid)) return new WP_Error('tix_demo_remote_exists', 'Diesen Zugang gibt es im Demo-Shop schon.', ['status' => 409]);
        $email = sanitize_email((string) $req->get_param('email'));
        $name = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 80);
        $company = mb_substr(trim(sanitize_text_field((string) $req->get_param('company'))), 0, 80);
        if ($name === '') return new WP_Error('tix_invalid_field', 'Bitte einen Namen angeben.', ['status' => 400, 'field' => 'name']);
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail angeben.', ['status' => 400, 'field' => 'email']);
        $exp = self::clean_expires($req->get_param('expires_at'));
        if ($exp <= time()) return new WP_Error('tix_invalid_field', 'Ablauf fehlt oder liegt in der Vergangenheit.', ['status' => 400, 'field' => 'expires_at']);
        if (get_user_by('email', $email)) return new WP_Error('tix_invalid_field', 'Im Demo-Shop gibt es zu dieser E-Mail schon ein Konto. Bitte eine andere Adresse nehmen.', ['status' => 409, 'field' => 'email']);
        $u = TIX_Demo_Access::insert_prospect($name, $email, $company, $exp);
        if (is_wp_error($u)) return $u;
        update_user_meta($u->ID, '_tix_demo_remote_id', $rid);
        $o = (array) $req->get_param('owner');
        update_user_meta($u->ID, '_tix_demo_remote_owner', ['id' => intval($o['id'] ?? 0), 'name' => mb_substr(sanitize_text_field((string) ($o['name'] ?? '')), 0, 80), 'email' => sanitize_email((string) ($o['email'] ?? ''))]);
        if (filter_var($req->get_param('extended'), FILTER_VALIDATE_BOOLEAN)) update_user_meta($u->ID, '_tix_demo_extended', 1);
        // invite:false (Vorgabe für signierte Aufrufe) – die Plattform schickt die eine Mail mit allen Links
        $invite = $req->get_param('invite') !== null && filter_var($req->get_param('invite'), FILTER_VALIDATE_BOOLEAN);
        $login = $invite ? '' : TIX_Demo_Access::login_url($u, true);
        if ($invite) TIX_Demo_Access::send_credentials($u, true); // Zugang direkt auf der Instanz (ohne Plattform): Benutzername + Passwort
        return self::out(['ok' => true, 'access' => self::stand(get_userdata($u->ID)), 'login_url' => $login], 201);
    }

    /** PATCH /demo-access/remote/{rid} {expires_at?, extended?, blocked?, name?, company?} */
    public static function r_update(WP_REST_Request $req) {
        $u = self::user_for($req['rid']);
        if (!$u) return new WP_Error('tix_not_found', 'Demo-Zugang nicht gefunden.', ['status' => 404]);
        if ($req->get_param('expires_at') !== null) {
            $exp = self::clean_expires($req->get_param('expires_at'));
            if ($exp <= 0) return new WP_Error('tix_invalid_field', 'Ungültiger Ablauf.', ['status' => 400, 'field' => 'expires_at']);
            update_user_meta($u->ID, '_tix_demo_expires', $exp);
        }
        if ($req->get_param('extended') !== null) {
            if (filter_var($req->get_param('extended'), FILTER_VALIDATE_BOOLEAN)) update_user_meta($u->ID, '_tix_demo_extended', 1);
            else delete_user_meta($u->ID, '_tix_demo_extended');
        }
        if ($req->get_param('blocked') !== null) {
            if (filter_var($req->get_param('blocked'), FILTER_VALIDATE_BOOLEAN)) {
                update_user_meta($u->ID, '_tix_demo_blocked', 1);
                delete_user_meta($u->ID, '_tix_app_tokens');
                delete_user_meta($u->ID, '_tix_demo_link');
            } else {
                delete_user_meta($u->ID, '_tix_demo_blocked');
            }
        }
        if (($n = trim(sanitize_text_field((string) $req->get_param('name')))) !== '') wp_update_user(['ID' => $u->ID, 'display_name' => mb_substr($n, 0, 80)]);
        if ($req->get_param('company') !== null) update_user_meta($u->ID, '_tix_demo_company', mb_substr(trim(sanitize_text_field((string) $req->get_param('company'))), 0, 80));
        return self::out(['ok' => true, 'access' => self::stand(get_userdata($u->ID))]);
    }

    /** POST /demo-access/remote/{rid}/link → {ok, login_url} (keine eigene Mail) */
    public static function r_link(WP_REST_Request $req) {
        $u = self::user_for($req['rid']);
        if (!$u) return new WP_Error('tix_not_found', 'Demo-Zugang nicht gefunden.', ['status' => 404]);
        if (!TIX_Demo_Access::usable($u->ID)) return new WP_Error('tix_demo_expired', 'Der Zugang ist abgelaufen oder gesperrt.', ['status' => 409]);
        // 1.38.872: Passwort der Plattform übernehmen (nur der WordPress-Hash kommt an, nie das Passwort)
        $h = (string) $req->get_param('password_hash');
        if ($h !== '') return self::out(['ok' => true, 'password_set' => TIX_Demo_Access::set_hash($u->ID, $h)]);
        return self::out(['ok' => true, 'login_url' => TIX_Demo_Access::login_url($u, true)]);
    }

    /** DELETE /demo-access/remote/{rid} */
    public static function r_delete(WP_REST_Request $req) {
        $u = self::user_for($req['rid']);
        if (!$u) return new WP_Error('tix_not_found', 'Demo-Zugang nicht gefunden.', ['status' => 404]);
        TIX_Demo_Access::delete_prospect($u->ID);
        return self::out(['ok' => true, 'deleted' => true]);
    }

    /** POST /demo-access/remote/reset {vorlage?} – höchstens 24 in 6 h (die Plattform begrenzt Vertriebler zusätzlich auf 6) */
    public static function r_reset(WP_REST_Request $req) {
        $v = (string) $req->get_param('vorlage');
        if ($v !== '' && !in_array($v, TIX_Demo_Shop::VORLAGEN, true)) return new WP_Error('tix_invalid_field', 'Vorlage: openair oder club.', ['status' => 400, 'field' => 'vorlage']);
        $k = 'tix_demo_remote_reset_rl';
        $n = intval(get_transient($k));
        if ($n >= 24) return new WP_Error('tix_rate_limit', 'Heute schon oft zurückgesetzt.', ['status' => 429]);
        set_transient($k, $n + 1, 6 * HOUR_IN_SECONDS);
        $r = TIX_Demo_Shop::reset($v);
        if (empty($r['ok'])) return new WP_Error((string) ($r['error'] ?? 'tix_demo_reset'), (string) ($r['message'] ?? 'Zurücksetzen fehlgeschlagen.'), ['status' => 409]);
        return self::out(['ok' => true, 'last_reset' => $r['last_reset'], 'next_reset' => $r['next_reset'], 'vorlage' => $r['vorlage'],
            'studio' => TIX_Demo_Shop::studio_url(), 'home' => TIX_Demo_Shop::home_url(), 'seeded' => $r['seeded'], 'wiped' => $r['wiped']]);
    }

    // ═══════════════ WP-CLI: wp tixomat demo-shop remote-key … ═══════════════

    /**
     * remote-key generate --file=<pfad>  neuen Schlüssel erzeugen, speichern und (0600) in die Datei schreiben – für die Gegenseite
     * remote-key set                     Schlüssel von stdin lesen (z. B. < Datei), nie als Argument
     * remote-key status                  gesetzt? Länge, Fingerabdruck (beide Seiten müssen gleich sein), Rolle, Schalter
     * remote-key clear                   Schlüssel entfernen
     * --target=shop|club (1.38.844): auf der Plattform das Ziel (Vorgabe shop; Optionen tix_demo_{shop|club}_key). Auf einer
     * Instanz gilt immer tix_demo_shop_key – ein Ziel wird dort angenommen und nur in der Ausgabe vermerkt (target_ignored).
     * Ausgabe: eine JSON-Zeile ohne Schlüssel.
     */
    public static function cli_key($args, $assoc) {
        $do = (string) ($args[1] ?? '');
        $line = function ($a, $fail = false) { WP_CLI::line(wp_json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); if ($fail) WP_CLI::halt(1); };
        if (isset($args[2])) return $line(['ok' => false, 'error' => 'tix_usage', 'message' => 'Den Schlüssel nie als Argument übergeben – nur über stdin (set) oder --file (generate).'], true);
        $target = array_key_exists('target', $assoc) ? (string) $assoc['target'] : 'shop';
        if (!self::target_ok($target)) return $line(['ok' => false, 'error' => 'tix_usage', 'message' => 'Ziel: --target=shop oder --target=club.'], true);
        $multi = class_exists('TIX_Demo_Access') && TIX_Demo_Access::multi();
        // Instanz: immer der eine Schlüssel tix_demo_shop_key (die Rolle shop/club kennt nur die Plattform)
        $opt = $multi ? self::TARGETS[$target]['key'] : self::OPT_KEY;
        $kt = $multi ? $target : 'shop';
        $extra = ['target' => $multi ? $target : null, 'option' => $opt] + (!$multi && $target !== 'shop' ? ['target_ignored' => true] : []);
        if ($do === 'generate') {
            $file = (string) ($assoc['file'] ?? '');
            if ($file === '') return $line(['ok' => false, 'error' => 'tix_usage', 'message' => 'Aufruf: wp tixomat demo-shop remote-key generate --file=<pfad> (Datei wird mit Rechten 0600 angelegt).'], true);
            if (file_exists($file)) return $line(['ok' => false, 'error' => 'tix_exists', 'message' => 'Die Datei gibt es schon – nichts überschrieben.'], true);
            $k = bin2hex(random_bytes(32));
            $old = umask(0077);
            $ok = @file_put_contents($file, $k . "\n", LOCK_EX);
            umask($old);
            if ($ok === false) return $line(['ok' => false, 'error' => 'tix_write', 'message' => 'Datei konnte nicht geschrieben werden – Schlüssel nicht gespeichert.'], true);
            @chmod($file, 0600);
            update_option($opt, $k, false);
            return $line(['ok' => true, 'action' => 'generate', 'file' => $file, 'length' => strlen($k), 'fingerprint' => self::fingerprint($kt)] + $extra);
        }
        if ($do === 'set') {
            $in = function_exists('posix_isatty') && @posix_isatty(STDIN) ? '' : (string) stream_get_contents(STDIN);
            $k = trim($in);
            if (strlen($k) < self::MIN_KEY || preg_match('/\s/', $k)) return $line(['ok' => false, 'error' => 'tix_invalid_key', 'message' => 'Kein gültiger Schlüssel auf stdin (mindestens ' . self::MIN_KEY . ' Zeichen, ohne Leerzeichen). Aufruf: wp tixomat demo-shop remote-key set < datei'], true);
            update_option($opt, $k, false);
            return $line(['ok' => true, 'action' => 'set', 'length' => strlen($k), 'fingerprint' => self::fingerprint($kt)] + $extra);
        }
        if ($do === 'clear') {
            delete_option($opt);
            return $line(['ok' => true, 'action' => 'clear'] + $extra);
        }
        if ($do === 'status' || $do === '') {
            $k = self::key($kt);
            $out = ['ok' => $k !== '', 'action' => 'status', 'set' => $k !== '', 'length' => strlen($k), 'fingerprint' => self::fingerprint($kt),
                'role' => $multi ? 'platform' : (class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::on() ? 'instance' : 'none'),
                'platform_ready' => self::platform_ready($kt), 'url' => $multi ? self::base_url($kt) : null,
                'instance_ready' => self::instance_ready(), 'remote_enabled' => get_option(self::OPT_ENABLED, '0') === '1'] + $extra;
            // Plattform: Überblick über beide Ziele (ohne Schlüssel)
            if ($multi) foreach (array_keys(self::TARGETS) as $t) $out['targets'][$t] = ['set' => self::key($t) !== '', 'fingerprint' => self::fingerprint($t), 'url' => self::base_url($t), 'ready' => self::platform_ready($t)];
            return $line($out, $k === '');
        }
        return $line(['ok' => false, 'error' => 'tix_usage', 'message' => 'Aufruf: wp tixomat demo-shop remote-key generate --file=<pfad> | set < datei | status | clear [--target=shop|club]'], true);
    }
}

TIX_Demo_Remote::init();
