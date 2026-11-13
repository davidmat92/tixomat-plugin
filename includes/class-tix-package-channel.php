<?php
/**
 * TIX_Package_Channel – Update-Kanal für den Dienst „evendis-api“ über den Lizenzserver
 * (WordPress-Ablösung Phase 1, Paket 1b, Vertrag V2; ab 1.38.808). Läuft nur auf dem Lizenzserver
 * (Option tix_license_server = 1, wie TIX_License_Server).
 *
 * POST /license/package/check {key_id, site, component, version, digest, ts, sig}
 *   key_id = erste 16 Hex-Zeichen von sha256(Lizenzschlüssel, normalisiert) – der Schlüssel selbst geht nie über die Leitung.
 *   sig    = HMAC-SHA256 über „evendis-pkg-v1|key_id|site|component|version|digest|ts“ mit dem Lizenzschlüssel
 *            (Schlüssel wie bei den Lizenz-Aufrufen, TIX_License_Server::normalize_key). ts höchstens 5 min alt.
 *   → {ok, license{state, valid_until}, target{version, image, digest, min_version, channel}|null, notices[], ts, sig}
 *     state: valid · notice (läuft bald ab) · locked · basis · invalid (pausiert/beendet/abgelaufen) · unknown (key_id unbekannt).
 *     target nur bei valid/notice (ohne Lizenz: weiterlaufen, keine Updates). sig = HMAC über
 *     „evendis-pkg-res-v1|ts|state|version|digest“ (nur bei bekannter Lizenz), damit der Dienst die Soll-Version prüfen kann.
 *     Falsche bzw. abgelaufene Signatur → 401 tix_bad_signature. Unbekannte Lizenz → 200, state unknown.
 * Admin (manage_options):
 *   POST /license/package/target {channel: stable|beta, version, image? (Vorgabe ghcr.io/davidmat92/evendis-api), digest, min_version?, component?}
 *        bzw. je Lizenz {license_id, channel?, version?, image?, digest?, min_version?} (eigene Soll-Version oder nur Kanal)
 *        bzw. {license_id, clear: true} (Lizenz folgt wieder stable)
 *   GET  /license/package/status → {ok, instances[…], targets{stable, beta, licenses[]}}
 * Tabellen (eigene, JSON statt serialize, angelegt beim ersten Aufruf): {prefix}tix_package_instances, {prefix}tix_package_targets.
 */
if (!defined('ABSPATH')) exit;

class TIX_Package_Channel {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const COMPONENTS = ['evendis-api'];
    const CHANNELS = ['stable', 'beta'];
    const MAX_SKEW = 300;
    const STALE_HOURS = 26;
    const VERSION_RE = '/^\d{1,4}\.\d{1,4}\.\d{1,6}(?:-[0-9a-z.]{1,20})?$/';
    const DIGEST_RE = '/^sha256:[a-f0-9]{64}$/';
    const IMAGE_RE = '/^[a-z0-9][a-z0-9._\/:-]{2,199}$/';
    const DEFAULT_IMAGE = 'ghcr.io/davidmat92/evendis-api'; // Repo evendis-api (Zentrale 13.11.)

    public static function init() {
        if (!class_exists('TIX_License_Server') || !TIX_License_Server::enabled()) return;
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function ti() { global $wpdb; return $wpdb->prefix . 'tix_package_instances'; }
    private static function tt() { global $wpdb; return $wpdb->prefix . 'tix_package_targets'; }

    public static function maybe_install() {
        if (get_option('tix_package_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::ti() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            key_id VARCHAR(16) NOT NULL,
            host VARCHAR(190) NOT NULL,
            component VARCHAR(40) NOT NULL,
            version VARCHAR(40) NOT NULL DEFAULT '',
            digest VARCHAR(80) NOT NULL DEFAULT '',
            state VARCHAR(16) NOT NULL DEFAULT '',
            data LONGTEXT NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY inst (key_id, host, component),
            KEY license (license_id)
        ) $c;");
        dbDelta("CREATE TABLE " . self::tt() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope VARCHAR(40) NOT NULL,
            component VARCHAR(40) NOT NULL,
            data LONGTEXT NOT NULL,
            updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scope_comp (scope, component)
        ) $c;");
        update_option('tix_package_db', self::DB_VERSION, false);
    }

    public static function routes() {
        register_rest_route(self::NS, '/license/package/check', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_check'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/license/package/target', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_target'], 'permission_callback' => ['TIX_License_Server', 'perm_admin']]);
        register_rest_route(self::NS, '/license/package/status', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_status'], 'permission_callback' => ['TIX_License_Server', 'perm_admin']]);
    }

    private static function bad($field, $msg) { return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]); }

    // ═══════════════ Schlüssel-ID und Signatur ═══════════════

    public static function key_id($license_key) { return substr(hash('sha256', TIX_License_Server::normalize_key($license_key)), 0, 16); }

    public static function sign_base(array $p) {
        return implode('|', ['evendis-pkg-v1', (string) ($p['key_id'] ?? ''), (string) ($p['site'] ?? ''), (string) ($p['component'] ?? ''), (string) ($p['version'] ?? ''), (string) ($p['digest'] ?? ''), (string) intval($p['ts'] ?? 0)]);
    }

    public static function signature($license_key, array $p) { return hash_hmac('sha256', self::sign_base($p), TIX_License_Server::normalize_key($license_key)); }

    public static function response_signature($license_key, $ts, $state, $version, $digest) {
        return hash_hmac('sha256', implode('|', ['evendis-pkg-res-v1', intval($ts), (string) $state, (string) $version, (string) $digest]), TIX_License_Server::normalize_key($license_key));
    }

    /** Lizenz zur Schlüssel-ID (Lizenzen sind wenige; Zuordnung 10 min zwischengespeichert) */
    private static function license_by_key_id($kid) {
        global $wpdb;
        $map = get_transient('tix_pkg_kid_map');
        if (!is_array($map) || !isset($map[$kid])) {
            $map = [];
            foreach ((array) $wpdb->get_results("SELECT id, license_key FROM {$wpdb->prefix}tix_licenses") as $l) $map[self::key_id($l->license_key)] = intval($l->id);
            set_transient('tix_pkg_kid_map', $map, 10 * MINUTE_IN_SECONDS);
        }
        return isset($map[$kid]) ? TIX_License_Server::get($map[$kid]) : null;
    }

    /** Lizenz → Zustand für den Dienst */
    public static function state_of($lic) {
        if (!$lic) return 'unknown';
        if (TIX_License_Server::license_state($lic) !== 'active') {
            $stage = TIX_License_Server::stage_for($lic);
            return in_array($stage, ['locked', 'basis'], true) && !empty($lic->enforce) ? $stage : 'invalid';
        }
        $stage = TIX_License_Server::stage_for($lic);
        return $stage === 'notice' ? 'notice' : ($stage === 'locked' || $stage === 'basis' ? $stage : 'valid');
    }

    // ═══════════════ Soll-Versionen ═══════════════

    private static function target_row($scope, $component) {
        global $wpdb;
        $r = $wpdb->get_var($wpdb->prepare("SELECT data FROM " . self::tt() . " WHERE scope = %s AND component = %s", $scope, $component));
        $d = $r ? json_decode((string) $r, true) : null;
        return is_array($d) ? $d : null;
    }

    /** Soll-Version für eine Lizenz: eigene Soll-Version › Kanal der Lizenz (stable, sonst beta) */
    public static function target_for($license_id, $component) {
        $own = $license_id ? self::target_row('license:' . intval($license_id), $component) : null;
        $channel = 'stable';
        if ($own) {
            if (!empty($own['version'])) return ['version' => $own['version'], 'image' => $own['image'], 'digest' => $own['digest'], 'min_version' => $own['min_version'] ?? null, 'channel' => 'license'];
            $channel = in_array($own['channel'] ?? '', self::CHANNELS, true) ? $own['channel'] : 'stable';
        }
        $t = self::target_row('channel:' . $channel, $component);
        if (!$t && $channel !== 'stable') { $t = self::target_row('channel:stable', $component); $channel = 'stable'; }
        return $t ? ['version' => $t['version'], 'image' => $t['image'], 'digest' => $t['digest'], 'min_version' => $t['min_version'] ?? null, 'channel' => $channel] : null;
    }

    // ═══════════════ POST /license/package/check ═══════════════

    public static function rest_check(WP_REST_Request $req) {
        self::maybe_install();
        global $wpdb;
        $kid = strtolower(trim((string) $req->get_param('key_id')));
        $host = TIX_License_Server::normalize_host((string) $req->get_param('site'));
        $comp = (string) $req->get_param('component');
        $ver = trim((string) $req->get_param('version'));
        $dig = strtolower(trim((string) $req->get_param('digest')));
        $ts = intval($req->get_param('ts'));
        $sig = strtolower(trim((string) $req->get_param('sig')));
        if (!preg_match('/^[a-f0-9]{16}$/', $kid)) return self::bad('key_id', 'Schlüssel-ID fehlt.');
        if ($host === '') return self::bad('site', 'Seite fehlt.');
        if (!in_array($comp, self::COMPONENTS, true)) return self::bad('component', 'Unbekannte Komponente.');
        if (!preg_match(self::VERSION_RE, $ver)) return self::bad('version', 'Version im Format 1.2.3.');
        if ($dig !== '' && !preg_match(self::DIGEST_RE, $dig)) return self::bad('digest', 'Digest im Format sha256:<64 Hex>.');
        // Bremse je Schlüssel-ID + Seite (viele Instanzen teilen sich eine IP), grob je IP für Raten
        if (self::limited('pkg_' . $kid . '|' . $host, 60) || self::limited('pkg_ip_' . (class_exists('TIX_App_Account') ? TIX_App_Account::client_ip() : ''), 300)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte später erneut versuchen.', ['status' => 429]);
        }
        $lic = self::license_by_key_id($kid);
        $p = ['key_id' => $kid, 'site' => $host, 'component' => $comp, 'version' => $ver, 'digest' => $dig, 'ts' => $ts];
        if ($lic) {
            if (abs(time() - $ts) > self::MAX_SKEW || $sig === '' || !hash_equals(self::signature($lic->license_key, $p), $sig)) {
                return new WP_Error('tix_bad_signature', 'Signatur ungültig oder abgelaufen.', ['status' => 401]);
            }
        }
        $state = self::state_of($lic);
        $target = in_array($state, ['valid', 'notice'], true) ? self::target_for($lic ? intval($lic->id) : 0, $comp) : null;
        $notices = [];
        if ($state === 'unknown') $notices[] = ['code' => 'license_unknown', 'text' => 'Diese Lizenz ist unbekannt. Der Dienst läuft weiter, Updates gibt es erst mit gültiger Lizenz.'];
        elseif (!in_array($state, ['valid', 'notice'], true)) $notices[] = ['code' => 'license_' . $state, 'text' => 'Keine gültige Lizenz. Der Dienst läuft weiter, Updates gibt es erst mit gültiger Lizenz.'];
        if ($state === 'notice') $notices[] = ['code' => 'license_notice', 'text' => 'Die Lizenz läuft am ' . wp_date('d.m.Y', strtotime((string) $lic->valid_until)) . ' ab.'];
        if ($target && !empty($target['min_version']) && version_compare($ver, $target['min_version'], '<')) $notices[] = ['code' => 'below_min_version', 'text' => 'Diese Version ist zu alt und muss aktualisiert werden.'];
        // Instanz merken (auch unbekannte Lizenzen, für die Wache)
        $now = current_time('mysql', true);
        $data = wp_json_encode(['ip' => class_exists('TIX_App_Account') ? hash_hmac('sha256', (string) TIX_App_Account::client_ip(), wp_salt('auth')) : '', 'target' => $target['version'] ?? null]);
        $wpdb->query($wpdb->prepare("INSERT INTO " . self::ti() . " (license_id, key_id, host, component, version, digest, state, data, first_seen, last_seen) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE license_id = VALUES(license_id), version = VALUES(version), digest = VALUES(digest), state = VALUES(state), data = VALUES(data), last_seen = VALUES(last_seen)",
            $lic ? intval($lic->id) : 0, $kid, $host, $comp, $ver, $dig, $state, $data, $now, $now));
        $out = ['ok' => true, 'license' => ['state' => $state, 'valid_until' => $lic && $lic->valid_until ? (string) $lic->valid_until : null], 'target' => $target, 'notices' => $notices, 'ts' => time()];
        if ($lic) $out['sig'] = self::response_signature($lic->license_key, $out['ts'], $state, $target['version'] ?? '', $target['digest'] ?? '');
        $r = rest_ensure_response($out);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    private static function limited($id, $max) {
        if (class_exists('TIX_Shadow') && TIX_Shadow::active()) return false;
        $k = 'tix_pkg_rl_' . substr(md5($id), 0, 24);
        $n = intval(get_transient($k));
        if ($n >= $max) return true;
        set_transient($k, $n + 1, 600);
        return false;
    }

    // ═══════════════ Admin ═══════════════

    /** POST /license/package/target */
    public static function rest_target(WP_REST_Request $req) {
        self::maybe_install();
        global $wpdb;
        $comp = (string) ($req->get_param('component') ?: 'evendis-api');
        if (!in_array($comp, self::COMPONENTS, true)) return self::bad('component', 'Unbekannte Komponente.');
        $lid = absint($req->get_param('license_id'));
        if ($lid && !TIX_License_Server::get($lid)) return self::bad('license_id', 'Lizenz nicht gefunden.');
        $channel = (string) $req->get_param('channel');
        if ($lid && rest_sanitize_boolean($req->get_param('clear'))) {
            $wpdb->delete(self::tt(), ['scope' => 'license:' . $lid, 'component' => $comp]);
            TIX_License_Server::log($lid, 'package_target', ['clear' => true, 'component' => $comp]);
            return self::rest_status($req);
        }
        $ver = trim((string) $req->get_param('version'));
        $data = [];
        if ($ver !== '' || !$lid) {
            if (!preg_match(self::VERSION_RE, $ver)) return self::bad('version', 'Version im Format 1.2.3.');
            $img = trim((string) $req->get_param('image')) ?: self::DEFAULT_IMAGE;
            $dig = strtolower(trim((string) $req->get_param('digest')));
            $min = trim((string) $req->get_param('min_version'));
            if (!preg_match(self::IMAGE_RE, $img)) return self::bad('image', 'Image wie ' . self::DEFAULT_IMAGE . '.');
            if (!preg_match(self::DIGEST_RE, $dig)) return self::bad('digest', 'Digest im Format sha256:<64 Hex>.');
            if ($min !== '' && (!preg_match(self::VERSION_RE, $min) || version_compare($min, $ver, '>'))) return self::bad('min_version', 'Mindest-Version im Format 1.2.3, nicht höher als die Soll-Version.');
            $data = ['version' => $ver, 'image' => $img, 'digest' => $dig, 'min_version' => $min !== '' ? $min : null];
        }
        if (!$lid) {
            if (!in_array($channel, self::CHANNELS, true)) return self::bad('channel', 'Kanal stable oder beta.');
            $scope = 'channel:' . $channel;
        } else {
            if ($channel !== '' && !in_array($channel, self::CHANNELS, true)) return self::bad('channel', 'Kanal stable oder beta.');
            if (!$data && $channel === '') return self::bad('channel', 'Bitte einen Kanal oder eine eigene Soll-Version angeben.');
            if ($channel !== '') $data['channel'] = $channel;
            $scope = 'license:' . $lid;
        }
        $wpdb->query($wpdb->prepare("INSERT INTO " . self::tt() . " (scope, component, data, updated_by, updated_at) VALUES (%s, %s, %s, %d, %s)
            ON DUPLICATE KEY UPDATE data = VALUES(data), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)", $scope, $comp, wp_json_encode($data), get_current_user_id(), current_time('mysql', true)));
        if ($lid) TIX_License_Server::log($lid, 'package_target', $data + ['component' => $comp]);
        return self::rest_status($req);
    }

    /** GET /license/package/status – alle Instanzen mit Soll-Version und Abweichung (Akte, Wache) */
    public static function rest_status(WP_REST_Request $req) {
        self::maybe_install();
        global $wpdb;
        $rows = (array) $wpdb->get_results("SELECT * FROM " . self::ti() . " ORDER BY last_seen DESC LIMIT 1000", ARRAY_A);
        $names = [];
        foreach ((array) $wpdb->get_results("SELECT id, customer FROM {$wpdb->prefix}tix_licenses") as $l) $names[intval($l->id)] = (string) $l->customer;
        $iso = function ($d) { return $d ? mysql_to_rfc3339($d) : null; };
        $inst = [];
        foreach ($rows as $r) {
            $lid = intval($r['license_id']);
            $t = in_array($r['state'], ['valid', 'notice'], true) ? self::target_for($lid, $r['component']) : null;
            $tv = $t['version'] ?? null;
            $inst[] = ['id' => intval($r['id']), 'license_id' => $lid ?: null, 'customer' => $lid ? ($names[$lid] ?? null) : null, 'key_id' => $r['key_id'], 'site' => $r['host'],
                'component' => $r['component'], 'version' => $r['version'], 'digest' => $r['digest'] ?: null, 'license_state' => $r['state'],
                'target_version' => $tv, 'channel' => $t['channel'] ?? null,
                'deviation' => $tv !== null && ($r['version'] !== $tv || ($r['digest'] !== '' && $t['digest'] !== $r['digest'])),
                'behind' => $tv !== null && version_compare($r['version'], $tv, '<'),
                'below_min' => !empty($t['min_version']) && version_compare($r['version'], $t['min_version'], '<'),
                'first_seen' => $iso($r['first_seen']), 'last_seen' => $iso($r['last_seen']),
                'stale' => strtotime($r['last_seen'] . ' UTC') < time() - self::STALE_HOURS * HOUR_IN_SECONDS];
        }
        $targets = ['stable' => null, 'beta' => null, 'licenses' => []];
        foreach ((array) $wpdb->get_results("SELECT * FROM " . self::tt() . " ORDER BY scope", ARRAY_A) as $t) {
            $d = json_decode((string) $t['data'], true) ?: [];
            $d += ['component' => $t['component'], 'updated_at' => $iso($t['updated_at'])];
            if (strpos($t['scope'], 'channel:') === 0) $targets[substr($t['scope'], 8)] = $d;
            else { $lid = intval(substr($t['scope'], 8)); $targets['licenses'][] = ['license_id' => $lid, 'customer' => $names[$lid] ?? null] + $d; }
        }
        $r = rest_ensure_response(['ok' => true, 'instances' => $inst, 'targets' => $targets, 'stale_hours' => self::STALE_HOURS]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
}

TIX_Package_Channel::init();
