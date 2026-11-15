<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Demo_Remote – ein Demo-Zugang mit Fällen (Plattform / Eigener Shop / beide), Verbindung Plattform → Demo-Instanz
 * (1.38.843, Vorschlag uebergaben/vorschlag-demo-zugaenge-beide.md, Vertrag uebergaben/vertrag-demo-shop.md „Fälle“, DOCUMENTATION §228).
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
    const OPT_KEY = 'tix_demo_shop_key';          // beide Seiten: gemeinsames Geheimnis
    const OPT_ENABLED = 'tix_demo_remote_enabled'; // Instanz: signierte Aufrufe annehmen
    const OPT_LINKS = 'tix_demo_links';           // {hub, platform, shop} für „Anderen Fall ansehen“
    const WINDOW = 300;
    const REPLAY_TTL = 900;
    const MIN_KEY = 32;
    const CACHE = 'tix_demo_shop_state';
    const CACHE_LAST = 'tix_demo_shop_state_last';
    const LINKS = ['hub' => 'https://demo.evendis.de/', 'platform' => 'https://demo-studio.evendis.de/', 'shop' => 'https://studio.demo-shop.evendis.de/'];
    const CASES = ['platform', 'shop'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_post_dispatch', [__CLASS__, 'config_links'], 61, 3);
    }

    // ═══════════════ Schlüssel ═══════════════

    private static function key() {
        $k = trim((string) get_option(self::OPT_KEY, ''));
        return strlen($k) >= self::MIN_KEY ? $k : '';
    }

    /** Kurzer Fingerabdruck zum Vergleich beider Seiten (verrät den Schlüssel nicht) */
    public static function fingerprint() {
        $k = self::key();
        return $k === '' ? '' : substr(hash('sha256', 'tix-demo-remote-fp|' . $k), 0, 12);
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

    /** Plattform mit eingerichteter Instanz? (Mehr-Veranstalter-Modus, URL, Schlüssel) */
    public static function platform_ready() {
        return class_exists('TIX_Demo_Access') && TIX_Demo_Access::multi() && self::base_url() !== '' && self::key() !== '';
    }

    /** Basis-URL der Instanz; https Pflicht außer lokalen Testseiten (Host ohne Punkt, .test, .localhost) */
    public static function base_url() {
        $u = trim((string) get_option(self::OPT_URL, ''));
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
    public static function call($method, $path, array $query = [], $body = null, $timeout = 15) {
        $base = self::base_url();
        $key = self::key();
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

    /** Fehler für die Plattform-Antwort: 502 tix_demo_shop_unreachable (oder die Feldmeldung der Instanz, z. B. E-Mail vergeben) */
    public static function error($r) {
        if (($r['error'] ?? '') === 'not_configured') return new WP_Error('tix_demo_shop_unreachable', 'Der Demo-Shop ist mit dieser Plattform noch nicht verbunden.', ['status' => 502]);
        $d = (array) ($r['data'] ?? []);
        if (intval($r['status'] ?? 0) === 409 && ($d['code'] ?? '') === 'tix_invalid_field') {
            return new WP_Error('tix_invalid_field', (string) ($d['message'] ?? 'Im Demo-Shop gibt es diese E-Mail schon.'), ['status' => 409, 'field' => (string) ($d['data']['field'] ?? 'email')]);
        }
        if (intval($r['status'] ?? 0) === 409 && in_array($d['code'] ?? '', ['tix_demo_busy', 'tix_demo_reset'], true)) {
            return new WP_Error('tix_demo_reset', (string) ($d['message'] ?? 'Zurücksetzen fehlgeschlagen.'), ['status' => 409]);
        }
        if (intval($r['status'] ?? 0) === 429) return new WP_Error('tix_rate_limit', 'Der Demo-Shop wurde heute schon oft zurückgesetzt. Bitte später noch einmal.', ['status' => 429]);
        return new WP_Error('tix_demo_shop_unreachable', 'Der Demo-Shop ist gerade nicht erreichbar. Es wurde nichts geändert.', ['status' => 502]);
    }

    /** Stand der Instanz (alle per Plattform angelegten Zugänge + Kopfdaten), 60 s zwischengespeichert → array|null */
    public static function state($fresh = false) {
        if (!$fresh) {
            $c = get_transient(self::CACHE);
            if (is_array($c)) return $c;
        }
        $r = self::call('GET', '/demo-access/remote');
        if (!$r['ok']) return null;
        $s = ['accesses' => [], 'shop' => (array) ($r['data']['shop'] ?? [])];
        foreach ((array) ($r['data']['accesses'] ?? []) as $a) {
            if (is_array($a) && intval($a['remote_id'] ?? 0) > 0) $s['accesses'][intval($a['remote_id'])] = $a;
        }
        set_transient(self::CACHE, $s, 60);
        set_transient(self::CACHE_LAST, $s, DAY_IN_SECONDS);
        return $s;
    }

    public static function forget() {
        delete_transient(self::CACHE);
    }

    /** Letzter bekannter Stand (Liste bleibt vollständig, wenn die Instanz gerade nicht antwortet) */
    public static function last_state() {
        $c = get_transient(self::CACHE_LAST);
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
        if ($invite) TIX_Demo_Access::send_link($u, true);
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
     * Ausgabe: eine JSON-Zeile ohne Schlüssel.
     */
    public static function cli_key($args, $assoc) {
        $do = (string) ($args[1] ?? '');
        $line = function ($a, $fail = false) { WP_CLI::line(wp_json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); if ($fail) WP_CLI::halt(1); };
        if (isset($args[2])) return $line(['ok' => false, 'error' => 'tix_usage', 'message' => 'Den Schlüssel nie als Argument übergeben – nur über stdin (set) oder --file (generate).'], true);
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
            update_option(self::OPT_KEY, $k, false);
            return $line(['ok' => true, 'action' => 'generate', 'file' => $file, 'length' => strlen($k), 'fingerprint' => self::fingerprint()]);
        }
        if ($do === 'set') {
            $in = function_exists('posix_isatty') && @posix_isatty(STDIN) ? '' : (string) stream_get_contents(STDIN);
            $k = trim($in);
            if (strlen($k) < self::MIN_KEY || preg_match('/\s/', $k)) return $line(['ok' => false, 'error' => 'tix_invalid_key', 'message' => 'Kein gültiger Schlüssel auf stdin (mindestens ' . self::MIN_KEY . ' Zeichen, ohne Leerzeichen). Aufruf: wp tixomat demo-shop remote-key set < datei'], true);
            update_option(self::OPT_KEY, $k, false);
            return $line(['ok' => true, 'action' => 'set', 'length' => strlen($k), 'fingerprint' => self::fingerprint()]);
        }
        if ($do === 'clear') {
            delete_option(self::OPT_KEY);
            return $line(['ok' => true, 'action' => 'clear']);
        }
        if ($do === 'status' || $do === '') {
            $multi = class_exists('TIX_Demo_Access') && TIX_Demo_Access::multi();
            return $line(['ok' => self::key() !== '', 'action' => 'status', 'set' => self::key() !== '', 'length' => strlen(self::key()), 'fingerprint' => self::fingerprint(),
                'role' => $multi ? 'platform' : (class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::on() ? 'instance' : 'none'),
                'platform_ready' => self::platform_ready(), 'url' => $multi ? self::base_url() : null,
                'instance_ready' => self::instance_ready(), 'remote_enabled' => get_option(self::OPT_ENABLED, '0') === '1'], self::key() === '');
        }
        return $line(['ok' => false, 'error' => 'tix_usage', 'message' => 'Aufruf: wp tixomat demo-shop remote-key generate --file=<pfad> | set < datei | status | clear'], true);
    }
}

TIX_Demo_Remote::init();
