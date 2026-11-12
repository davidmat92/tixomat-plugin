<?php
if (!defined('ABSPATH')) exit;

/**
 * Website live schalten durch den Kunden (1.38.742, Auftrag uebergaben/auftrag-live-schalten-durch-kunde-2026-11-10.md).
 *
 * Kunden-Seite (Studio, Rechte wie Marke):
 *   GET  /studio/readiness                 Checkliste {ready, live, request, items[…]}
 *   POST /studio/go-live {action: live|test}  Anfrage an die Plattform (signiert mit dem Lizenzschlüssel); prüft hart:
 *        Rechtstexte (nie übergehbar) und Zahlung (Anbieter oder „Keine Zahlungen“) → 409 sonst
 * Plattform (Lizenz-Server):
 *   POST /license/go-live                  Annahme der Anfrage (HMAC, 120 s, nonce) → Akte + Kunden-Dienst
 *   GET  /platform/customers/{key}/go-live Stand der Anfrage (Plattform-Admin, Kunden-Dienst)
 *   POST /platform/customers/{key}/go-live {status: done|failed|cancelled, message?, mode?}  Ergebnis eintragen
 *   Das Ergebnis geht signiert mit der nächsten Lizenzprüfung an die Seite (Ping löst sie sofort aus).
 */
class TIX_Go_Live {

    const NS = 'tixomat/v1';
    const OPT_STATE = 'tix_site_live';      // Kunden-Seite: {status: test|requested|live|failed, action, at, by, message, nonce}
    const OPT_REQ = 'tix_lic_golive_';      // Lizenz-Server: je Lizenz {action, status: pending|done|failed|cancelled, at, host, by, checks, message, nonce, done_at}
    const OPT_NONCES = 'tix_golive_nonces';
    const TTL = 120;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat golive', [__CLASS__, 'cli']); // Abholer des Kunden-Dienstes (1.38.765)
    }

    /** Offene Anfragen (Lizenz-Server), Vertrag uebergaben/vertrag-golive-abholen.md: [{license_id, nonce, action, host, by {name, email}, at}] – älteste zuerst. */
    public static function open_requests() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::OPT_REQ) . '%'));
        $out = [];
        foreach ((array) $rows as $r) {
            $lid = intval(substr($r->option_name, strlen(self::OPT_REQ)));
            $q = maybe_unserialize($r->option_value);
            if (!$lid || !is_array($q) || ($q['status'] ?? '') !== 'pending') continue;
            $out[] = ['license_id' => $lid, 'nonce' => (string) ($q['nonce'] ?? ''), 'action' => (string) ($q['action'] ?? 'live'),
                'host' => strtolower(preg_replace('/^www\./', '', (string) ($q['host'] ?? ''))),
                'by' => ['name' => (string) ($q['by']['name'] ?? ''), 'email' => (string) ($q['by']['email'] ?? '')], 'at' => (string) ($q['at'] ?? '')];
        }
        usort($out, function ($a, $b) { return strcmp($a['at'], $b['at']); });
        return $out;
    }

    /**
     * Ergebnis eintragen (REST-Route und Kunden-Dienst): Status, Nachricht (300 Zeichen), done_at, Protokoll, Ping an die Seite.
     * $nonce gesetzt: nur, wenn die Anfrage noch pending ist und die nonce passt (sonst tix_golive_changed, nichts geschrieben).
     * → Anfrage | WP_Error
     */
    public static function set_result($license_id, $status, $message = null, $by = '', $nonce = null) {
        $license_id = intval($license_id);
        if (!class_exists('TIX_License_Server') || !$license_id || !TIX_License_Server::get($license_id)) return new WP_Error('tix_not_found', 'Diese Lizenz gibt es nicht.', ['status' => 404]);
        $r = self::request_of($license_id);
        if (!$r) return new WP_Error('tix_golive_none', 'Es liegt keine Anfrage vor.', ['status' => 409]);
        if (!in_array($status, ['done', 'failed', 'cancelled'], true)) return new WP_Error('tix_invalid_field', 'status: done, failed oder cancelled.', ['status' => 400, 'field' => 'status']);
        if ($nonce !== null && (($r['status'] ?? '') !== 'pending' || !hash_equals((string) ($r['nonce'] ?? ''), (string) $nonce))) {
            return new WP_Error('tix_golive_changed', 'Die Anfrage ist nicht mehr offen oder wurde neu gestellt.', ['status' => 409]);
        }
        $r['status'] = $status;
        $r['message'] = $message !== null ? (mb_substr(sanitize_text_field((string) $message), 0, 300) ?: null) : null;
        $r['done_at'] = gmdate('c');
        update_option(self::OPT_REQ . $license_id, $r, false);
        TIX_License_Server::log($license_id, 'go_live_' . $status, ['action' => $r['action'], 'by' => $by]);
        // Seite sofort prüfen lassen, damit das Ergebnis ankommt
        if (method_exists('TIX_License_Server', 'rest_admin_ping')) {
            $pr = new WP_REST_Request('POST', '/' . self::NS . '/license/admin/licenses/' . $license_id . '/ping');
            $pr->set_param('id', $license_id);
            TIX_License_Server::rest_admin_ping($pr);
        }
        return $r;
    }

    /**
     * Kunden-Dienst (Vertrag uebergaben/vertrag-golive-abholen.md):
     *   wp tixomat golive offen                                         → ERGEBNIS [{license_id, nonce, action, host, by, at}]
     *   wp tixomat golive ergebnis <license_id> <done|failed|cancelled> --nonce=<nonce> [--message=<text>]
     *                                                                   → ERGEBNIS {"ok":true,"license_id":…,"status":…}
     */
    public static function cli($args, $assoc) {
        $cmd = (string) ($args[0] ?? '');
        $on = class_exists('TIX_License_Server') && TIX_License_Server::enabled();
        if ($cmd === 'offen') {
            WP_CLI::line('ERGEBNIS ' . wp_json_encode($on ? self::open_requests() : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($cmd === 'ergebnis' && isset($args[1], $args[2])) {
            if (!$on) WP_CLI::error('tix_not_found Lizenz-Server ist hier nicht aktiv.');
            if (!isset($assoc['nonce']) || (string) $assoc['nonce'] === '') WP_CLI::error('tix_invalid_field --nonce fehlt.');
            $r = self::set_result(intval($args[1]), (string) $args[2], isset($assoc['message']) ? (string) $assoc['message'] : null, 'kunden-dienst', (string) $assoc['nonce']);
            if (is_wp_error($r)) WP_CLI::error($r->get_error_code() . ' ' . $r->get_error_message());
            WP_CLI::line('ERGEBNIS ' . wp_json_encode(['ok' => true, 'license_id' => intval($args[1]), 'status' => (string) $r['status']]));
            return;
        }
        WP_CLI::error('Aufruf: wp tixomat golive offen | ergebnis <license_id> <done|failed|cancelled> --nonce=<nonce> [--message=<text>]');
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function host() {
        return strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
    }

    public static function routes() {
        if (class_exists('TIX_Studio')) {
            register_rest_route(self::NS, '/studio/readiness', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_readiness'], 'permission_callback' => ['TIX_Studio', 'perm_brand']]);
            register_rest_route(self::NS, '/studio/go-live', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_request'], 'permission_callback' => ['TIX_Studio', 'perm_brand']]);
        }
        register_rest_route(self::NS, '/license/go-live', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_receive'], 'permission_callback' => '__return_true']);
        if (class_exists('TIX_Platform_Customers')) {
            $p = ['permission_callback' => ['TIX_Platform_Customers', 'perm']];
            register_rest_route(self::NS, '/platform/customers/(?P<key>[a-z]-[a-z0-9_-]{1,60})/go-live', [
                ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_platform_get']] + $p,
                ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_platform_set']] + $p,
            ]);
        }
    }

    // ═══════════════ Kunden-Seite ═══════════════

    public static function state() {
        $s = get_option(self::OPT_STATE, []);
        $s = is_array($s) ? $s : [];
        return $s + ['status' => 'test', 'action' => null, 'at' => null, 'by' => null, 'message' => null];
    }

    /** Checkliste. overridable: nur die Zahlung darf die Plattform übergehen, die Rechtstexte nie. */
    public static function checklist() {
        $items = [];
        // Zahlung
        $pm = class_exists('TIX_Studio_Payments') && method_exists('TIX_Studio_Payments', 'state_of') ? TIX_Studio_Payments::state_of() : 'missing';
        // 1.38.744: erfüllt nur mit Anbieter (ok) oder bewusst „Keine Zahlungen anbieten“ (none); undecided wie missing (nur mit „trotzdem“ durch die Plattform)
        $items[] = ['key' => 'payment', 'ok' => in_array($pm, ['ok', 'none'], true), 'state' => $pm, 'required' => true, 'overridable' => true,
            'payments_needed' => class_exists('TIX_Co_Sale') ? TIX_Co_Sale::payments_needed() : true,
            'label' => ['ok' => 'Zahlungsanbieter eingerichtet', 'none' => 'Keine Zahlungen anbieten (bewusst gewählt)', 'undecided' => 'Zahlung noch nicht entschieden', 'missing' => 'Zahlungsanbieter fehlt'][$pm] ?? 'Zahlung',
            'hint' => $pm === 'missing' ? 'Einstellungen → Zahlungen: Anbieter einrichten oder „Keine Zahlungen anbieten“ wählen.' : ($pm === 'undecided' ? TIX_Studio_Payments::UNDECIDED_HINT : null), 'link' => '/einstellungen/zahlungen/'];
        // Rechtstexte (hart)
        $ls = class_exists('TIX_Studio_Legal') && method_exists('TIX_Studio_Legal', 'legal_status') ? TIX_Studio_Legal::legal_status() : ['ok' => false, 'items' => []];
        $miss = [];
        foreach ($ls['items'] as $i) if (!$i['ok']) $miss[] = $i['title'] . ': ' . implode(', ', array_slice($i['missing'], 0, 3));
        $items[] = ['key' => 'legal', 'ok' => (bool) $ls['ok'], 'state' => $ls['ok'] ? 'ok' : 'missing', 'required' => true, 'overridable' => false,
            'label' => 'Rechtstexte vollständig, ohne Platzhalter', 'hint' => $miss ? implode(' · ', $miss) : null, 'link' => '/rechtstexte/', 'detail' => $ls['items']];
        // Homepage, Domain, Inhaber
        $r = class_exists('TIX_Owner_Access') ? TIX_Owner_Access::readiness() : [];
        $items[] = ['key' => 'homepage', 'ok' => !empty($r['homepage']), 'state' => !empty($r['homepage']) ? 'ok' : 'missing', 'required' => false, 'overridable' => true, 'label' => 'Homepage eingerichtet', 'hint' => null, 'link' => '/website/'];
        $dom = class_exists('TIX_Website') ? (array) TIX_Website::domain() : [];
        $host = (string) ($dom['live_host'] ?? '') ?: (class_exists('TIX_Customer_URL') ? TIX_Customer_URL::host() : '');
        $items[] = ['key' => 'domain', 'ok' => $host !== '', 'state' => $host !== '' ? 'ok' : 'missing', 'required' => true, 'overridable' => true, 'label' => $host !== '' ? 'Adresse: ' . $host : 'Adresse (Domain) fehlt', 'hint' => null, 'link' => '/website/adresse/'];
        $items[] = ['key' => 'owner', 'ok' => !empty($r['owner']), 'state' => !empty($r['owner']) ? 'ok' : 'missing', 'required' => true, 'overridable' => true, 'label' => 'Zugang für den Inhaber', 'hint' => null, 'link' => '/team/'];
        $ready = !array_filter($items, function ($i) { return $i['required'] && !$i['ok']; });
        return ['ready' => $ready, 'items' => $items];
    }

    public static function rest_readiness(WP_REST_Request $req) {
        $c = self::checklist();
        $r = rest_ensure_response(['ok' => true, 'ready' => $c['ready'], 'live' => self::state()['status'] === 'live', 'state' => self::state(), 'items' => $c['items'],
            'available' => !self::multi() && class_exists('TIX_License') && TIX_License::active()]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    public static function sign($raw, $host, $key) {
        return hash_hmac('sha256', 'tix-golive-v1|' . strtolower((string) $host) . '|' . $raw, strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim((string) $key))));
    }

    public static function rest_request(WP_REST_Request $req) {
        if (self::multi() || !class_exists('TIX_License') || !TIX_License::active()) return new WP_Error('tix_golive_unavailable', 'Diese Seite wird über die Plattform live geschaltet.', ['status' => 404]);
        $action = (string) $req->get_param('action');
        if (!in_array($action, ['live', 'test'], true)) return new WP_Error('tix_invalid_field', 'action: live oder test.', ['status' => 400, 'field' => 'action']);
        $c = self::checklist();
        if ($action === 'live') {
            $legal = array_values(array_filter($c['items'], function ($i) { return $i['key'] === 'legal'; }))[0];
            if (!$legal['ok']) return new WP_Error('tix_legal_incomplete', 'Die Rechtstexte sind noch nicht vollständig (Pflicht, ohne Platzhalter).', ['status' => 409, 'items' => $legal['detail']]);
            $miss = array_values(array_filter($c['items'], function ($i) { return $i['required'] && !$i['ok']; }));
            if ($miss) return new WP_Error('tix_not_ready', 'Noch nicht alles bereit: ' . implode(', ', array_column($miss, 'label')) . '.', ['status' => 409, 'items' => $miss]);
        }
        $key = (string) get_option(TIX_License::OPT_KEY, '');
        $u = wp_get_current_user();
        $nonce = wp_generate_password(20, false, false);
        $raw = wp_json_encode(['action' => $action, 'host' => self::host(), 'by' => ['name' => (string) $u->display_name, 'email' => (string) $u->user_email], 'checks' => array_map(function ($i) { return ['key' => $i['key'], 'ok' => $i['ok'], 'state' => $i['state']]; }, $c['items']), 'ts' => time(), 'nonce' => $nonce]);
        $resp = wp_remote_post(TIX_License::server() . '/wp-json/' . self::NS . '/license/go-live', ['timeout' => 15, 'body' => $raw,
            'headers' => ['Content-Type' => 'application/json', 'X-Tix-Signature' => self::sign($raw, self::host(), $key)]]);
        if (is_wp_error($resp)) return new WP_Error('tix_golive_unreachable', 'Die Plattform ist gerade nicht erreichbar. Bitte später noch einmal.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($resp));
        $d = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code !== 200) return new WP_Error(is_array($d) && !empty($d['code']) ? (string) $d['code'] : 'tix_golive_error', is_array($d) && !empty($d['message']) ? (string) $d['message'] : 'Die Plattform hat die Anfrage abgelehnt.', ['status' => in_array($code, [400, 401, 404, 409, 429], true) ? $code : 502]);
        update_option(self::OPT_STATE, ['status' => 'requested', 'action' => $action, 'at' => gmdate('c'), 'by' => (string) $u->display_name, 'message' => null, 'nonce' => $nonce], false);
        return rest_ensure_response(['ok' => true, 'requested' => true, 'state' => self::state()]);
    }

    /** Lizenzprüfung: Ergebnis der Plattform übernehmen (signiert). */
    public static function from_check($key, array $data) {
        $g = $data['go_live'] ?? null;
        if (!is_array($g) || empty($g['nonce']) || empty($data['go_live_sig'])) return;
        $base = implode('|', ['tix-golive-res-v1', self::host(), (string) $g['nonce'], (string) ($g['status'] ?? ''), (string) ($g['action'] ?? ''), intval($data['issued_at'] ?? 0)]);
        if (!hash_equals(hash_hmac('sha256', $base, strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim((string) $key)))), (string) $data['go_live_sig'])) return;
        $st = self::state();
        if (($st['nonce'] ?? '') !== (string) $g['nonce'] && $st['status'] !== 'requested') { /* ältere Anfrage: trotzdem Ergebnis übernehmen */ }
        $status = (string) $g['status'];
        $new = $status === 'done' ? ((string) $g['action'] === 'live' ? 'live' : 'test') : ($status === 'failed' ? 'failed' : ($status === 'cancelled' ? 'test' : $st['status']));
        update_option(self::OPT_STATE, ['status' => $new, 'action' => (string) $g['action'], 'at' => gmdate('c'), 'by' => $st['by'] ?? null, 'message' => ($g['message'] ?? null) ?: null, 'nonce' => (string) $g['nonce']], false);
    }

    // ═══════════════ Plattform (Lizenz-Server) ═══════════════

    public static function rest_receive(WP_REST_Request $req) {
        if (!class_exists('TIX_License_Server') || !TIX_License_Server::enabled()) return new WP_Error('rest_no_route', 'Nicht verfügbar.', ['status' => 404]);
        $raw = (string) $req->get_body();
        $b = json_decode($raw, true);
        if (!is_array($b) || abs(time() - intval($b['ts'] ?? 0)) > self::TTL || empty($b['nonce'])) return new WP_Error('tix_golive_auth', 'Anfrage abgelaufen.', ['status' => 401]);
        $host = strtolower(preg_replace('/^www\./', '', (string) ($b['host'] ?? '')));
        $lic = self::license_for_host($host);
        if (!$lic || !hash_equals(self::sign($raw, $host, $lic->license_key), (string) $req->get_header('X-Tix-Signature'))) return new WP_Error('tix_golive_auth', 'Ungültige Signatur.', ['status' => 401]);
        $used = array_filter((array) get_option(self::OPT_NONCES, []), function ($t) { return intval($t) > time() - 2 * self::TTL; });
        if (isset($used[(string) $b['nonce']])) return new WP_Error('tix_golive_auth', 'Anfrage schon angenommen.', ['status' => 401]);
        $used[(string) $b['nonce']] = time();
        update_option(self::OPT_NONCES, $used, false);
        $action = in_array($b['action'] ?? '', ['live', 'test'], true) ? $b['action'] : 'live';
        // Rechtstexte prüft auch die Plattform anhand der Meldung der Seite – nie übergehbar
        foreach ((array) ($b['checks'] ?? []) as $c) if (($c['key'] ?? '') === 'legal' && empty($c['ok']) && $action === 'live') return new WP_Error('tix_legal_incomplete', 'Die Rechtstexte sind noch nicht vollständig.', ['status' => 409]);
        $r = ['action' => $action, 'status' => 'pending', 'at' => gmdate('c'), 'host' => $host, 'by' => array_map('sanitize_text_field', (array) ($b['by'] ?? [])),
            'checks' => array_values(array_map(function ($c) { return ['key' => sanitize_key((string) ($c['key'] ?? '')), 'ok' => !empty($c['ok']), 'state' => sanitize_key((string) ($c['state'] ?? ''))]; }, (array) ($b['checks'] ?? []))),
            'message' => null, 'nonce' => sanitize_text_field((string) $b['nonce']), 'done_at' => null];
        update_option(self::OPT_REQ . intval($lic->id), $r, false);
        TIX_License_Server::log(intval($lic->id), 'go_live_request', ['action' => $action, 'host' => $host, 'by' => $r['by']['email'] ?? '']);
        do_action('tix_go_live_requested', intval($lic->id), $r);
        return rest_ensure_response(['ok' => true, 'status' => 'pending']);
    }

    private static function license_for_host($host) {
        if ($host === '') return null;
        global $wpdb;
        $t = $wpdb->prefix . 'tix_license_sites';
        $lid = intval($wpdb->get_var($wpdb->prepare("SELECT license_id FROM $t WHERE host = %s AND dev = 0 ORDER BY id ASC LIMIT 1", $host)));
        return $lid ? TIX_License_Server::get($lid) : null;
    }

    public static function request_of($license_id) {
        $r = get_option(self::OPT_REQ . intval($license_id), null);
        return is_array($r) ? $r : null;
    }

    public static function rest_platform_get(WP_REST_Request $req) {
        $c = TIX_Platform_Customers::raw($req['key']);
        if (!$c || empty($c['license_id'])) return new WP_Error('tix_not_found', 'Diesen Kunden (mit Lizenz) gibt es nicht.', ['status' => 404]);
        return rest_ensure_response(['ok' => true, 'request' => self::request_of($c['license_id'])]);
    }

    /** POST …/go-live {status: done|failed|cancelled, message?} – Zentrale/Kunden-Dienst trägt das Ergebnis ein; danach Ping an die Seite. */
    public static function rest_platform_set(WP_REST_Request $req) {
        $c = TIX_Platform_Customers::raw($req['key']);
        if (!$c || empty($c['license_id'])) return new WP_Error('tix_not_found', 'Diesen Kunden (mit Lizenz) gibt es nicht.', ['status' => 404]);
        $r = self::set_result(intval($c['license_id']), (string) $req->get_param('status'), $req->get_param('message'), wp_get_current_user()->user_email);
        if (is_wp_error($r)) return $r;
        return rest_ensure_response(['ok' => true, 'request' => $r]);
    }

    /** Antwort der Lizenzprüfung ergänzen: Ergebnis signiert (nur für den gebundenen Host). */
    public static function add_to_check(array $out, $lic, $host) {
        $r = self::request_of($lic->id);
        if (!$r || $host === '' || $r['status'] === 'pending') return $out;
        $issued = intval($out['issued_at'] ?? time());
        $out['go_live'] = ['nonce' => $r['nonce'], 'status' => $r['status'], 'action' => $r['action'], 'message' => $r['message']];
        $out['go_live_sig'] = hash_hmac('sha256', implode('|', ['tix-golive-res-v1', $host, (string) $r['nonce'], (string) $r['status'], (string) $r['action'], $issued]), TIX_License_Server::normalize_key($lic->license_key));
        return $out;
    }
}

TIX_Go_Live::init();
