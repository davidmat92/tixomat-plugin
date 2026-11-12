<?php
if (!defined('ABSPATH')) exit;

/**
 * Vertragsdaten mit evendis und Seitenname (1.38.764, Vertrag uebergaben/vertrag-vertragsdaten.md, Doku §168).
 *
 * Eine Quelle: an der Lizenz auf evendis (Option `tix_lic_contract_{id}`). Die Kunden-Seite hält eine Kopie und schreibt nur über evendis
 * (signiert mit dem Lizenzschlüssel, 120 s, nonce – wie TIX_Go_Live). Änderungen aus der Akte kommen signiert mit der Lizenzprüfung zurück.
 *   Kunden-Seite:  GET/POST /studio/contract-data            (Inhaber/Admin, TIX_Operator_Access::perm_owner)
 *   Lizenz-Server: POST /license/contract-data              {action: get|set, host, data?, by, ts, nonce}
 *   Plattform:     GET/POST /platform/customers/{key}/contract-data (Plattform-Admin)
 * `site_name` wird der WordPress-Seitenname (blogname) – Website, Mails, Studio.
 */
class TIX_Contract_Data {

    const NS = 'tixomat/v1';
    const OPT_COPY = 'tix_contract_data';       // Kunden-Seite: {data, updated_at, updated_by, synced_at}
    const OPT_LIC = 'tix_lic_contract_';        // Lizenz-Server: je Lizenz {data, updated_at, updated_by, log[]}
    const OPT_NONCES = 'tix_contract_nonces';
    const TTL = 120;
    const REQUIRED = ['company', 'site_name', 'address.street', 'address.zip', 'address.city', 'contact_person', 'email'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'customer_hook'], 30, 3);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function host() {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
    }

    private static function bad($field, $msg, $status = 400, $code = 'tix_invalid_field') {
        return new WP_Error($code, $msg, ['status' => $status] + ($field ? ['field' => $field] : []));
    }

    public static function routes() {
        $own = class_exists('TIX_Operator_Access') && method_exists('TIX_Operator_Access', 'perm_owner') ? ['TIX_Operator_Access', 'perm_owner'] : ['TIX_Studio', 'perm_brand'];
        register_rest_route(self::NS, '/studio/contract-data', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_site_get'], 'permission_callback' => $own],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_site_set'], 'permission_callback' => $own],
        ]);
        register_rest_route(self::NS, '/license/contract-data', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_receive'], 'permission_callback' => '__return_true']);
        if (class_exists('TIX_Platform_Customers')) {
            $p = ['permission_callback' => ['TIX_Platform_Customers', 'perm']];
            register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/contract-data', [
                ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_platform_get']] + $p,
                ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_platform_set']] + $p,
            ]);
        }
    }

    // ═══════════════ Felder ═══════════════

    private static function str($v, $max) {
        return mb_substr(trim(sanitize_text_field((string) $v)), 0, $max);
    }

    private static function addr($in, $prefix, $with_company = false) {
        if (!is_array($in)) return self::bad($prefix, 'Bitte eine Anschrift angeben.');
        $a = ['street' => self::str($in['street'] ?? '', 120), 'zip' => self::str($in['zip'] ?? '', 12), 'city' => self::str($in['city'] ?? '', 80),
            'country' => strtoupper(self::str(($in['country'] ?? '') ?: 'DE', 2))];
        if ($with_company) $a = ['company' => self::str($in['company'] ?? '', 120)] + $a;
        if (!preg_match('/^[A-Z]{2}$/', $a['country'])) return self::bad($prefix . '.country', 'Land bitte als Ländercode (z. B. DE).');
        foreach (['street' => 'Straße und Hausnummer', 'zip' => 'Postleitzahl', 'city' => 'Ort'] as $k => $l) if ($a[$k] === '') return self::bad($prefix . '.' . $k, 'Bitte ' . $l . ' angeben.');
        if ($a['country'] === 'DE' && !preg_match('/^\d{5}$/', $a['zip'])) return self::bad($prefix . '.zip', 'Die Postleitzahl hat in Deutschland 5 Ziffern.');
        return $a;
    }

    /** Body prüfen und auf die vorhandenen Daten legen → vollständige Daten | WP_Error */
    public static function merge($old, array $b) {
        $d = is_array($old) ? $old : [];
        $d += ['company' => '', 'site_name' => '', 'address' => ['street' => '', 'zip' => '', 'city' => '', 'country' => 'DE'], 'contact_person' => '', 'email' => '', 'phone' => '', 'vat_id' => '', 'tax_number' => '', 'billing_address' => null];
        foreach (['company' => [2, 120, 'Firma mit Rechtsform'], 'site_name' => [2, 60, 'Name deiner Seite'], 'contact_person' => [2, 120, 'Ansprechpartner']] as $k => [$min, $max, $l]) {
            if (!array_key_exists($k, $b)) continue;
            $v = self::str($b[$k], 500);
            if (mb_strlen($v) < $min || mb_strlen($v) > $max) return self::bad($k, $l . ': ' . $min . ' bis ' . $max . ' Zeichen.');
            $d[$k] = $v;
        }
        if (array_key_exists('email', $b)) {
            $e = sanitize_email((string) $b['email']);
            if (!is_email($e)) return self::bad('email', 'Bitte eine gültige E-Mail-Adresse angeben.');
            $d['email'] = $e;
        }
        if (array_key_exists('phone', $b)) {
            $ph = trim((string) $b['phone']);
            if ($ph !== '' && (mb_strlen($ph) > 40 || !preg_match('#^[0-9 +/()\-]+$#', $ph))) return self::bad('phone', 'Telefon: Ziffern, Leerzeichen und + / ( ) -, höchstens 40 Zeichen.');
            $d['phone'] = $ph;
        }
        if (array_key_exists('vat_id', $b)) {
            $v = strtoupper(preg_replace('/\s+/', '', (string) $b['vat_id']));
            if ($v !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $v)) return self::bad('vat_id', 'Die USt-IdNr. beginnt mit dem Ländercode, z. B. DE123456789.');
            $d['vat_id'] = $v;
        }
        if (array_key_exists('tax_number', $b)) {
            $t = self::str($b['tax_number'], 100);
            if (mb_strlen($t) > 30) return self::bad('tax_number', 'Steuernummer: höchstens 30 Zeichen.');
            $d['tax_number'] = $t;
        }
        if (array_key_exists('address', $b)) {
            $a = self::addr(array_merge((array) $d['address'], is_array($b['address']) ? $b['address'] : []), 'address');
            if (is_wp_error($a)) return $a;
            $d['address'] = $a;
        }
        if (array_key_exists('billing_address', $b)) {
            if ($b['billing_address'] === null || $b['billing_address'] === false || $b['billing_address'] === []) $d['billing_address'] = null;
            else {
                $a = self::addr($b['billing_address'], 'billing_address', true);
                if (is_wp_error($a)) return $a;
                $d['billing_address'] = $a;
            }
        }
        return $d;
    }

    public static function missing($d) {
        if (!is_array($d)) return self::REQUIRED;
        $out = [];
        foreach (self::REQUIRED as $f) {
            $v = strpos($f, '.') ? ($d[strtok($f, '.')][substr($f, strpos($f, '.') + 1)] ?? '') : ($d[$f] ?? '');
            if (trim((string) $v) === '') $out[] = $f;
        }
        return $out;
    }

    private static function changed($old, $new) {
        $out = [];
        foreach ((array) $new as $k => $v) if (wp_json_encode($v) !== wp_json_encode(is_array($old) ? ($old[$k] ?? null) : null)) $out[] = $k;
        return $out;
    }

    // ═══════════════ Kunden-Seite ═══════════════

    private static function available() {
        return !self::multi() && class_exists('TIX_License') && TIX_License::active();
    }

    public static function copy() {
        $c = get_option(self::OPT_COPY, []);
        return is_array($c) ? $c : [];
    }

    private static function site_payload(array $c, $stale = false) {
        $d = $c['data'] ?? null;
        return ['ok' => true, 'available' => self::available(), 'data' => $d ?: null, 'complete' => $d ? !self::missing($d) : false, 'missing' => self::missing($d ?: null),
            'updated_at' => $c['updated_at'] ?? null, 'updated_by' => $c['updated_by'] ?? null, 'stale' => (bool) $stale];
    }

    public static function sign($raw, $host, $key) {
        return hash_hmac('sha256', 'tix-contract-v1|' . strtolower((string) $host) . '|' . $raw, strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim((string) $key))));
    }

    /** Signierte Anfrage an evendis → Antwort-Array | WP_Error */
    private static function remote($action, $data = null) {
        $key = (string) get_option(TIX_License::OPT_KEY, '');
        $u = wp_get_current_user();
        $raw = wp_json_encode(['action' => $action, 'host' => self::host(), 'data' => $data, 'by' => ['name' => (string) $u->display_name, 'email' => (string) $u->user_email], 'ts' => time(), 'nonce' => wp_generate_password(20, false, false)]);
        $resp = wp_remote_post(TIX_License::server() . '/wp-json/' . self::NS . '/license/contract-data', ['timeout' => 15, 'body' => $raw,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Tix-Signature' => self::sign($raw, self::host(), $key)]]);
        if (is_wp_error($resp)) return new WP_Error('tix_contract_unreachable', 'evendis ist gerade nicht erreichbar. Bitte später noch einmal.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($resp));
        $d = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || !is_array($d)) return new WP_Error(is_array($d) && !empty($d['code']) ? (string) $d['code'] : 'tix_contract_error', is_array($d) && !empty($d['message']) ? (string) $d['message'] : 'evendis hat die Anfrage abgelehnt.',
            ['status' => in_array($code, [400, 401, 404, 409, 429], true) ? $code : 502] + (is_array($d) && isset($d['data']['field']) ? ['field' => $d['data']['field']] : []));
        return $d;
    }

    /** Kopie übernehmen und Seitenname setzen. */
    public static function store_copy(array $rec) {
        update_option(self::OPT_COPY, ['data' => $rec['data'] ?? null, 'updated_at' => $rec['updated_at'] ?? null, 'updated_by' => $rec['updated_by'] ?? null, 'synced_at' => gmdate('c')], false);
        $name = (string) ($rec['data']['site_name'] ?? '');
        if ($name !== '') self::apply_site_name($name); // ohne Änderung keine Wirkung
    }

    /** Seitenname setzen (blogname); der Name für geteilte Events folgt, wenn er leer oder gleich dem alten Namen war. */
    public static function apply_site_name($name) {
        $name = self::str($name, 60);
        if ($name === '') return;
        $old = html_entity_decode((string) get_option('blogname'), ENT_QUOTES, 'UTF-8');
        if ($old === $name) return;
        update_option('blogname', $name);
        $s = get_option('tix_settings', []);
        if (is_array($s)) {
            $syn = trim((string) ($s['syndication_site_name'] ?? ''));
            if ($syn === '' || $syn === $old) { $s['syndication_site_name'] = $name; update_option('tix_settings', $s); }
        }
        do_action('tix_site_name_changed', $name, $old);
    }

    public static function rest_site_get(WP_REST_Request $req) {
        if (self::multi()) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        if (!self::available()) return rest_ensure_response(self::site_payload([]) + ['available' => false]);
        $r = self::remote('get');
        if (is_wp_error($r)) return rest_ensure_response(self::site_payload(self::copy(), true));
        if (is_array($r['record'] ?? null)) self::store_copy($r['record']);
        return rest_ensure_response(self::site_payload(self::copy()));
    }

    public static function rest_site_set(WP_REST_Request $req) {
        if (self::multi()) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        if (!self::available()) return new WP_Error('tix_contract_unavailable', 'Diese Seite hat keine aktive Lizenz von evendis.', ['status' => 409]);
        $b = $req->get_json_params();
        $b = is_array($b) ? $b : (array) $req->get_body_params();
        $base = self::copy()['data'] ?? [];
        $d = self::merge($base, $b);
        if (is_wp_error($d)) return $d;
        $r = self::remote('set', $d);
        if (is_wp_error($r)) return $r;
        if (is_array($r['record'] ?? null)) self::store_copy($r['record']);
        return rest_ensure_response(self::site_payload(self::copy()));
    }

    /** Lizenzprüfung: Daten aus der Akte übernehmen (signiert). */
    public static function from_check($key, array $data) {
        $rec = $data['contract_data'] ?? null;
        if (!is_array($rec) || empty($data['contract_data_sig'])) return;
        $base = implode('|', ['tix-contract-res-v1', self::host(), hash('sha256', wp_json_encode($rec)), intval($data['issued_at'] ?? 0)]);
        if (!hash_equals(hash_hmac('sha256', $base, strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim((string) $key)))), (string) $data['contract_data_sig'])) return;
        $c = self::copy();
        if (hash('sha256', wp_json_encode($rec)) === hash('sha256', wp_json_encode(['data' => $c['data'] ?? null, 'updated_at' => $c['updated_at'] ?? null, 'updated_by' => $c['updated_by'] ?? null]))) return; // unverändert
        self::store_copy($rec);
    }

    // ═══════════════ Lizenz-Server (evendis) ═══════════════

    public static function record($license_id) {
        $r = get_option(self::OPT_LIC . intval($license_id), null);
        return is_array($r) ? $r : null;
    }

    /** Speichern mit Protokoll; $source site|platform. → Datensatz */
    public static function save($license_id, array $data, array $by, $source) {
        $old = self::record($license_id);
        $changed = self::changed($old['data'] ?? null, $data);
        $rec = ['data' => $data, 'updated_at' => gmdate('c'), 'updated_by' => ['name' => (string) ($by['name'] ?? ''), 'email' => (string) ($by['email'] ?? ''), 'source' => $source],
            'log' => array_slice(array_merge((array) ($old['log'] ?? []), [['at' => gmdate('c'), 'by' => (string) (($by['email'] ?? '') ?: ($by['name'] ?? '')), 'source' => $source, 'changed' => $changed]]), -50)];
        update_option(self::OPT_LIC . intval($license_id), $rec, false);
        if (class_exists('TIX_License_Server')) TIX_License_Server::log(intval($license_id), 'contract_data', ['source' => $source, 'by' => $by['email'] ?? '', 'changed' => $changed]);
        if ($source === 'site' && $changed) self::notify_platform(intval($license_id), $rec, $changed);
        do_action('tix_contract_data_changed', intval($license_id), $rec, $changed, $source);
        return $rec;
    }

    private static function public_record(array $rec) {
        return ['data' => $rec['data'] ?? null, 'updated_at' => $rec['updated_at'] ?? null, 'updated_by' => isset($rec['updated_by']) ? ['name' => $rec['updated_by']['name'] ?? '', 'source' => $rec['updated_by']['source'] ?? ''] : null];
    }

    private static function notify_platform($license_id, array $rec, array $changed) {
        $s = (array) get_option('tix_settings', []);
        $to = sanitize_email((string) ($s['tix_contract_notify_email'] ?? get_option('tix_contract_notify_email', ''))) ?: (string) get_option('admin_email');
        if (!is_email($to)) return;
        $lic = class_exists('TIX_License_Server') ? TIX_License_Server::get($license_id) : null;
        $name = $lic ? (string) ($lic->customer ?? '') : '';
        $labels = ['company' => 'Firma', 'site_name' => 'Name der Seite', 'address' => 'Anschrift', 'contact_person' => 'Ansprechpartner', 'email' => 'E-Mail', 'phone' => 'Telefon',
            'vat_id' => 'USt-IdNr.', 'tax_number' => 'Steuernummer', 'billing_address' => 'Rechnungsanschrift'];
        $list = implode(', ', array_map(function ($k) use ($labels) { return $labels[$k] ?? $k; }, $changed));
        $subject = 'Vertragsdaten geändert: ' . ($name ?: ('Lizenz #' . $license_id));
        $html = '<p>' . esc_html(($rec['updated_by']['name'] ?? '') ?: 'Der Kunde') . ' hat die Vertragsdaten geändert (' . esc_html($name ?: '#' . $license_id) . ').</p><p>Geändert: ' . esc_html($list) . '</p><p>Die Daten stehen in der Akte unter Stammdaten.</p>';
        if (class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::send($to, $subject, ['title' => $subject, 'blocks' => [['type' => 'html', 'html' => $html]], 'kind' => 'account'], ['customer' => false]);
        else wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    private static function license_for_host($host) {
        if ($host === '' || !class_exists('TIX_License_Server')) return null;
        global $wpdb;
        $t = $wpdb->prefix . 'tix_license_sites';
        $lid = intval($wpdb->get_var($wpdb->prepare("SELECT license_id FROM $t WHERE host = %s AND dev = 0 ORDER BY id ASC LIMIT 1", $host)));
        return $lid ? TIX_License_Server::get($lid) : null;
    }

    public static function rest_receive(WP_REST_Request $req) {
        if (!class_exists('TIX_License_Server') || !TIX_License_Server::enabled()) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $raw = (string) $req->get_body();
        $b = json_decode($raw, true);
        if (!is_array($b) || abs(time() - intval($b['ts'] ?? 0)) > self::TTL || empty($b['nonce'])) return new WP_Error('tix_contract_auth', 'Anfrage abgelaufen.', ['status' => 401]);
        $host = strtolower(preg_replace('/^www\./', '', (string) ($b['host'] ?? '')));
        $lic = self::license_for_host($host);
        if (!$lic || !hash_equals(self::sign($raw, $host, $lic->license_key), (string) $req->get_header('X-Tix-Signature'))) return new WP_Error('tix_contract_auth', 'Ungültige Signatur.', ['status' => 401]);
        $used = array_filter((array) get_option(self::OPT_NONCES, []), function ($t) { return intval($t) > time() - 2 * self::TTL; });
        if (isset($used[(string) $b['nonce']])) return new WP_Error('tix_contract_auth', 'Anfrage schon angenommen.', ['status' => 401]);
        $used[(string) $b['nonce']] = time();
        update_option(self::OPT_NONCES, $used, false);
        $rec = self::record($lic->id);
        if (($b['action'] ?? '') === 'set') {
            $d = self::merge($rec['data'] ?? [], is_array($b['data'] ?? null) ? $b['data'] : []);
            if (is_wp_error($d)) return $d;
            $by = ['name' => mb_substr(sanitize_text_field((string) ($b['by']['name'] ?? '')), 0, 120), 'email' => sanitize_email((string) ($b['by']['email'] ?? ''))];
            $rec = self::save(intval($lic->id), $d, $by, 'site');
        }
        return rest_ensure_response(['ok' => true, 'record' => $rec ? self::public_record($rec) : ['data' => null, 'updated_at' => null, 'updated_by' => null]]);
    }

    private static function customer($key) {
        $c = TIX_Platform_Customers::raw($key);
        if (!$c) return new WP_Error('tix_not_found', 'Diesen Kunden gibt es nicht.', ['status' => 404]);
        if (empty($c['license_id'])) return new WP_Error('tix_no_license', 'Dieser Kunde hat keine Lizenz.', ['status' => 409]);
        return $c;
    }

    private static function platform_payload($license_id) {
        $rec = self::record($license_id) ?: [];
        $d = $rec['data'] ?? null;
        return ['ok' => true, 'data' => $d, 'complete' => $d ? !self::missing($d) : false, 'missing' => self::missing($d), 'updated_at' => $rec['updated_at'] ?? null,
            'updated_by' => $rec['updated_by'] ?? null, 'log' => array_slice(array_reverse((array) ($rec['log'] ?? [])), 0, 20)];
    }

    public static function rest_platform_get(WP_REST_Request $req) {
        $c = self::customer($req['key']);
        if (is_wp_error($c)) return $c;
        return rest_ensure_response(self::platform_payload(intval($c['license_id'])));
    }

    public static function rest_platform_set(WP_REST_Request $req) {
        $c = self::customer($req['key']);
        if (is_wp_error($c)) return $c;
        $b = $req->get_json_params();
        $b = is_array($b) ? $b : (array) $req->get_body_params();
        $rec = self::record($c['license_id']);
        $d = self::merge($rec['data'] ?? [], $b);
        if (is_wp_error($d)) return $d;
        $u = wp_get_current_user();
        self::save(intval($c['license_id']), $d, ['name' => (string) $u->display_name, 'email' => (string) $u->user_email], 'platform');
        // Seite sofort prüfen lassen, damit die Daten (und der Seitenname) ankommen
        if (class_exists('TIX_License_Server') && method_exists('TIX_License_Server', 'rest_admin_ping')) {
            $pr = new WP_REST_Request('POST', '/' . self::NS . '/license/admin/licenses/' . intval($c['license_id']) . '/ping');
            $pr->set_param('id', intval($c['license_id']));
            TIX_License_Server::rest_admin_ping($pr);
        }
        return rest_ensure_response(self::platform_payload(intval($c['license_id'])));
    }

    /** Antwort der Lizenzprüfung ergänzen: Vertragsdaten signiert (nur für den gebundenen Host). */
    public static function add_to_check(array $out, $lic, $host) {
        $rec = self::record($lic->id);
        if (!$rec || $host === '') return $out;
        $pub = self::public_record($rec);
        $out['contract_data'] = $pub;
        $out['contract_data_sig'] = hash_hmac('sha256', implode('|', ['tix-contract-res-v1', $host, hash('sha256', wp_json_encode($pub)), intval($out['issued_at'] ?? time())]), TIX_License_Server::normalize_key($lic->license_key));
        return $out;
    }

    /** GET /platform/customers/{key}: Übersicht contract_data ergänzen. */
    public static function customer_hook($response, $handler, $request) {
        if ($request->get_method() !== 'GET' || is_wp_error($response)) return $response;
        if (!preg_match('#^/tixomat/v1/platform/customers/([a-z]-[a-z0-9_-]{1,60})$#', (string) $request->get_route(), $m)) return $response;
        $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
        if (!is_array($data) || !class_exists('TIX_Platform_Customers')) return $response;
        $c = TIX_Platform_Customers::raw($m[1]);
        $rec = $c && !empty($c['license_id']) ? self::record($c['license_id']) : null;
        $d = $rec['data'] ?? null;
        $data['contract_data'] = $c && !empty($c['license_id']) ? ['complete' => $d ? !self::missing($d) : false, 'site_name' => $d['site_name'] ?? null, 'company' => $d['company'] ?? null, 'updated_at' => $rec['updated_at'] ?? null] : null;
        if ($response instanceof WP_REST_Response) { $response->set_data($data); return $response; }
        return $data;
    }
}

TIX_Contract_Data::init();
