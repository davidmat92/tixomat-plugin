<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_License_Server – Lizenzen für den Selbstbetrieb („Tixomat by evendis“, Paket 2b).
 *
 * Läuft nur, wenn die Option `tix_license_server` = '1' ist (veranstalter.evendis.de).
 * Tabellen `{prefix}tix_licenses` + `{prefix}tix_license_sites`, Admin Tixomat → Lizenzen.
 * REST (öffentlich, begrenzt):
 *   POST /license/check   {key, site, version, wp, php} → Status + neueste Version + signierter Download-Link
 *   GET  /license/package ?l&h&v&e&sig                → ZIP der hier laufenden Plugin-Version
 *   GET  /license/releases                            → [{version, released_at}] (für die Mindest-Version der Apps)
 * Module je Lizenz (`modules`, JSON-Liste; NULL = alle) → Antwort `modules`, beim Kunden TIX_Modules.
 * Stufen (1.38.566, Lizenz-Fernschalter): active · notice (14 Tage vor Ablauf) · locked (kein Verkauf, keine
 * Änderungen; Einlass + Tickets bis zum letzten Event mit verkauften Tickets) · basis (Basis-Modus). Durchgesetzt
 * wird nur bei `enforce` = 1 (betriebene Seiten bzw. Lizenzen mit Vertrag); ältere Selbstbetriebs-Lizenzen
 * behalten „ohne Lizenz nur keine Updates“. Antwort signiert (HMAC mit dem Lizenzschlüssel).
 * Admin-REST für das Plattform-Studio: /license/admin/licenses[/{id}[/log|/ping]] (manage_options).
 * Das Update-Paket ist die hier installierte Version (Whitelist: tixomat.php, includes/, assets/, templates/)
 * plus `self-hosted.flag` – daran erkennt das Plugin den Selbstbetrieb (TIX_License).
 */
class TIX_License_Server {

    const NS          = 'tixomat/v1';
    const DB_VERSION  = '3';
    const NOTICE_DAYS = 14;  // Hinweis-Stufe vor Ablauf
    const STAGES      = ['active', 'notice', 'locked', 'basis'];
    const LINK_TTL    = 43200; // Download-Link 12 h gültig
    const REQUIRES_PHP = '8.1';
    const REQUIRES_WP  = '6.4';

    public static function enabled() {
        return get_option('tix_license_server', '0') === '1';
    }

    public static function init() {
        if (!self::enabled()) return;
        add_action('init', [__CLASS__, 'record_release'], 20);
        add_action('admin_init', [__CLASS__, 'maybe_install']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_menu', [__CLASS__, 'menu'], 40);
        add_action('admin_post_tix_license_admin', [__CLASS__, 'handle_admin']);
    }

    // ── Datenbank ──

    private static function t($name) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function maybe_install() {
        if (get_option('tix_license_db') === self::DB_VERSION) return;
        self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t('tix_licenses') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_key varchar(40) NOT NULL,
            customer varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            max_sites int(11) NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'active',
            valid_until date NULL,
            note text NULL,
            modules text NULL,
            enforce tinyint(1) NOT NULL DEFAULT 0,
            stage_override varchar(20) NOT NULL DEFAULT '',
            basis_url varchar(255) NOT NULL DEFAULT '',
            last_event_date date NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY license_key (license_key)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('tix_license_sites') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_id bigint(20) unsigned NOT NULL,
            host varchar(190) NOT NULL,
            dev tinyint(1) NOT NULL DEFAULT 0,
            version varchar(20) NOT NULL DEFAULT '',
            wp varchar(20) NOT NULL DEFAULT '',
            php varchar(20) NOT NULL DEFAULT '',
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            last_download datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY license_host (license_id, host)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('tix_license_log') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            license_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(40) NOT NULL,
            data text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY license_id (license_id)
        ) $c;");
        update_option('tix_license_db', self::DB_VERSION, false);
    }

    // ── Lizenzen ──

    public static function generate_key() {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $parts = [];
        for ($p = 0; $p < 4; $p++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $parts[] = $s;
        }
        return 'TIX-' . implode('-', $parts);
    }

    public static function normalize_key($key) {
        return strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim((string) $key)));
    }

    /** Host aus URL, ohne „www.“, klein. */
    public static function normalize_host($site) {
        $site = trim((string) $site);
        if ($site === '') return '';
        if (strpos($site, '://') === false) $site = 'https://' . $site;
        $host = strtolower((string) wp_parse_url($site, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        return preg_match('/^[a-z0-9.-]{1,190}$/', $host) ? $host : '';
    }

    /** Test- und Staging-Umgebungen belegen keinen Platz. */
    public static function is_dev_host($host) {
        return $host === 'localhost' || preg_match('/(^|\.)(local|test|localhost|invalid)$/', $host)
            || preg_match('/^(staging|stage|dev|test)\./', $host) || filter_var($host, FILTER_VALIDATE_IP);
    }

    public static function create($args) {
        global $wpdb;
        self::maybe_install();
        $key = self::generate_key();
        $wpdb->insert(self::t('tix_licenses'), [
            'license_key' => $key,
            'customer'    => mb_substr(sanitize_text_field($args['customer'] ?? ''), 0, 190),
            'email'       => sanitize_email($args['email'] ?? ''),
            'max_sites'   => max(1, intval($args['max_sites'] ?? 1)),
            'status'      => 'active',
            'valid_until' => self::date_or_null($args['valid_until'] ?? gmdate('Y-m-d', strtotime('+1 year'))),
            'note'        => sanitize_textarea_field($args['note'] ?? ''),
            'enforce'     => !empty($args['enforce']) ? 1 : 0,
            'created_at'  => current_time('mysql'),
        ]);
        $id = intval($wpdb->insert_id);
        if (!$id) return null;
        self::log($id, 'create', ['enforce' => !empty($args['enforce'])]);
        return self::get($id);
    }

    private static function date_or_null($d) {
        $d = trim((string) $d);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('tix_licenses') . " WHERE id = %d", $id));
    }

    public static function get_by_key($key) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('tix_licenses') . " WHERE license_key = %s", self::normalize_key($key)));
    }

    public static function sites($license_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('tix_license_sites') . " WHERE license_id = %d ORDER BY dev ASC, first_seen ASC", $license_id));
    }

    /** Module der Lizenz: Liste oder null = alle. */
    public static function license_modules($lic) {
        if (!$lic || $lic->modules === null || $lic->modules === '') return null;
        $m = json_decode((string) $lic->modules, true);
        return is_array($m) ? array_values(array_intersect(TIX_Modules::keys(), $m)) : null;
    }

    /** Aus dem Formular: „alle“ → NULL, sonst JSON-Liste. */
    private static function modules_from_post() {
        if (($_POST['modules_mode'] ?? 'all') === 'all') return null;
        $sel = array_map('sanitize_key', (array) ($_POST['modules'] ?? []));
        return wp_json_encode(array_values(array_intersect(TIX_Modules::keys(), $sel)));
    }

    /** Status einer Lizenz ohne Website: active | expired | suspended | revoked. */
    public static function license_state($lic) {
        if (!$lic) return 'invalid';
        if (in_array($lic->status, ['suspended', 'revoked'], true)) return $lic->status;
        if ($lic->valid_until && $lic->valid_until < current_time('Y-m-d')) return 'expired';
        return 'active';
    }

    // ── Stufen (Fernschalter) ──

    /**
     * Stufe einer Lizenz. $reported = letztes Event mit verkauften Tickets laut Website (Y-m-d|null).
     * Gesperrt wird durch Ablauf, Pause/Ende oder Fernschalter „sperren“; die Sperre endet mit dem letzten
     * Event, danach Basis. Fernschalter „aktiv“ hebt einen Ablauf auf (Kulanz), „basis“ springt direkt.
     */
    public static function stage_for($lic, $reported = null, $today = null) {
        if (!$lic) return null;
        $today = $today ?: current_time('Y-m-d');
        $ov = (string) ($lic->stage_override ?? '');
        if ($ov === 'basis') return 'basis';
        $expired = $lic->valid_until && $lic->valid_until < $today;
        $lock = $ov === 'locked' || in_array($lic->status, ['suspended', 'revoked'], true) || ($expired && $ov !== 'active');
        if ($lock) {
            $led = max((string) ($lic->last_event_date ?? ''), (string) ($reported ?? ''));
            return ($led !== '' && $led >= $today) ? 'locked' : 'basis';
        }
        if ($ov !== 'active' && $lic->valid_until && (strtotime($lic->valid_until) - strtotime($today)) <= self::NOTICE_DAYS * DAY_IN_SECONDS) {
            return 'notice';
        }
        return 'active';
    }

    /** Signatur der Stufen-Antwort (prüft TIX_License::verify mit demselben Schlüssel). */
    public static function signature($key, array $p) {
        return hash_hmac('sha256', self::sign_base($p), self::normalize_key($key));
    }

    public static function sign_base(array $p) {
        $mods = array_key_exists('modules', $p) && is_array($p['modules']) ? implode(',', $p['modules']) : '*';
        return implode('|', ['tix-lic-v1', (string) ($p['host'] ?? ''), (string) ($p['stage'] ?? ''), !empty($p['enforce']) ? '1' : '0',
            (string) ($p['expires_at'] ?? ''), (string) ($p['last_event_date'] ?? ''), (string) ($p['basis_url'] ?? ''), $mods, intval($p['issued_at'] ?? 0)]);
    }

    public static function log($license_id, $action, array $data = []) {
        global $wpdb;
        $wpdb->insert(self::t('tix_license_log'), [
            'license_id' => intval($license_id), 'user_id' => get_current_user_id(), 'action' => mb_substr(sanitize_key($action), 0, 40),
            'data' => $data ? wp_json_encode($data) : null, 'created_at' => current_time('mysql'),
        ]);
    }

    // ── Versionen ──

    /** Erscheinungsdatum jeder hier laufenden Version merken. */
    public static function record_release() {
        $dates = get_option('tix_release_dates', []);
        if (!is_array($dates)) $dates = [];
        if (isset($dates[TIXOMAT_VERSION])) return;
        $dates[TIXOMAT_VERSION] = current_time('Y-m-d');
        uksort($dates, 'version_compare');
        if (count($dates) > 300) $dates = array_slice($dates, -300, null, true);
        update_option('tix_release_dates', $dates, false);
    }

    public static function released_at($version) {
        $dates = get_option('tix_release_dates', []);
        return is_array($dates) && isset($dates[$version]) ? (string) $dates[$version] : null;
    }

    // ── REST ──

    public static function register_routes() {
        register_rest_route(self::NS, '/license/check', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_check'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/license/package', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_package'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/license/releases', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_releases'], 'permission_callback' => '__return_true',
        ]);
        // Plattform-Studio (nur Admins dieser Seite)
        $admin = [__CLASS__, 'perm_admin'];
        register_rest_route(self::NS, '/license/admin/licenses', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_admin_list'], 'permission_callback' => $admin],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_admin_create'], 'permission_callback' => $admin],
        ]);
        register_rest_route(self::NS, '/license/admin/licenses/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_admin_get'], 'permission_callback' => $admin],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_admin_save'], 'permission_callback' => $admin],
        ]);
        register_rest_route(self::NS, '/license/admin/licenses/(?P<id>\d+)/log', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_admin_log'], 'permission_callback' => $admin,
        ]);
        register_rest_route(self::NS, '/license/admin/licenses/(?P<id>\d+)/ping', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_admin_ping'], 'permission_callback' => $admin,
        ]);
    }

    private static function limited($bucket, $max, $window) {
        return class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('lic_' . $bucket, $max, $window);
    }

    private static function messages() {
        return [
            'active'     => 'Lizenz gültig.',
            'invalid'    => 'Dieser Lizenzschlüssel ist unbekannt.',
            'expired'    => 'Die Lizenz ist abgelaufen. Tixomat läuft weiter, Updates gibt es nach der Verlängerung.',
            'suspended'  => 'Die Lizenz ist pausiert. Bitte melde dich bei uns.',
            'revoked'    => 'Die Lizenz wurde beendet.',
            'site_limit' => 'Der Schlüssel ist schon für eine andere Website aktiv. Bitte melde dich bei uns, wenn du umziehst.',
            'bad_site'   => 'Die Adresse der Website fehlt oder ist ungültig.',
        ];
    }

    public static function rest_check(WP_REST_Request $req) {
        if (self::limited('check', 30, 600)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Anfragen. Bitte später erneut versuchen.', ['status' => 429]);
        }
        self::maybe_install();
        $lic   = self::get_by_key((string) $req->get_param('key'));
        $host  = self::normalize_host((string) $req->get_param('site'));
        $state = self::license_state($lic);
        $msgs  = self::messages();
        $out = [
            'ok'         => true,
            'valid'      => false,
            'status'     => $state,
            'expires_at' => $lic && $lic->valid_until ? (string) $lic->valid_until : null,
            'latest'     => [
                'version'      => TIXOMAT_VERSION,
                'released_at'  => self::released_at(TIXOMAT_VERSION),
                'requires_php' => self::REQUIRES_PHP,
                'requires_wp'  => self::REQUIRES_WP,
            ],
            'package_url' => null,
            'modules'     => $lic ? self::license_modules($lic) : null,
            'message'     => $msgs[$state] ?? '',
        ];
        if ($lic) {
            $reported = self::date_or_null((string) $req->get_param('last_event_date'));
            if ($reported && $reported !== (string) $lic->last_event_date) {
                global $wpdb;
                $wpdb->update(self::t('tix_licenses'), ['last_event_date' => $reported], ['id' => $lic->id]);
                $lic->last_event_date = $reported;
            }
            $stage = self::stage_for($lic, $reported);
            $out += [
                'stage'           => $stage,
                'enforce'         => !empty($lic->enforce),
                'notice_from'     => $lic->valid_until ? gmdate('Y-m-d', strtotime($lic->valid_until) - self::NOTICE_DAYS * DAY_IN_SECONDS) : null,
                'last_event_date' => $lic->last_event_date ? (string) $lic->last_event_date : null,
                'basis_url'       => (string) $lic->basis_url,
                'issued_at'       => time(),
            ];
            if ($host !== '') {
                $out['signature'] = self::signature($lic->license_key, $out + ['host' => $host]);
                // Betriebene Seiten (enforce) binden auch in Sperre/Basis, damit der Heartbeat sichtbar bleibt
                if ($state !== 'active' && !empty($lic->enforce)) self::bind_site($lic, $host, $req);
            }
        }
        if ($state !== 'active') return rest_ensure_response($out);
        if ($host === '') {
            $out['status'] = 'bad_site'; $out['message'] = $msgs['bad_site'];
            return rest_ensure_response($out);
        }
        $site = self::bind_site($lic, $host, $req);
        if (!$site) {
            $out['status'] = 'site_limit'; $out['message'] = $msgs['site_limit'];
            return rest_ensure_response($out);
        }
        $out['valid']       = true;
        $out['package_url'] = self::package_url($lic, $host, TIXOMAT_VERSION);
        return rest_ensure_response($out);
    }

    /** Website an die Lizenz binden (erste Prüfung) bzw. aktualisieren. Null = kein Platz frei. */
    private static function bind_site($lic, $host, WP_REST_Request $req) {
        global $wpdb;
        $t   = self::t('tix_license_sites');
        $now = current_time('mysql');
        $info = [
            'version' => mb_substr(sanitize_text_field((string) $req->get_param('version')), 0, 20),
            'wp'      => mb_substr(sanitize_text_field((string) $req->get_param('wp')), 0, 20),
            'php'     => mb_substr(sanitize_text_field((string) $req->get_param('php')), 0, 20),
        ];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE license_id = %d AND host = %s", $lic->id, $host));
        if ($row) {
            $wpdb->update($t, $info + ['last_seen' => $now], ['id' => $row->id]);
            return $row;
        }
        $dev = self::is_dev_host($host) ? 1 : 0;
        if (!$dev) {
            $used = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE license_id = %d AND dev = 0", $lic->id)));
            if ($used >= intval($lic->max_sites)) return null;
        }
        $wpdb->insert($t, $info + ['license_id' => $lic->id, 'host' => $host, 'dev' => $dev, 'first_seen' => $now, 'last_seen' => $now]);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $wpdb->insert_id));
    }

    private static function sign($license_id, $host_hash, $version, $exp) {
        return hash_hmac('sha256', 'tix-license|' . intval($license_id) . '|' . $host_hash . '|' . $version . '|' . intval($exp), wp_salt('auth'));
    }

    public static function package_url($lic, $host, $version) {
        $exp = time() + self::LINK_TTL;
        $h   = substr(hash('sha256', $host), 0, 16);
        return add_query_arg([
            'l' => intval($lic->id), 'h' => $h, 'v' => $version, 'e' => $exp,
            'sig' => self::sign($lic->id, $h, $version, $exp),
        ], rest_url(self::NS . '/license/package'));
    }

    public static function rest_package(WP_REST_Request $req) {
        if (self::limited('pkg', 20, 600)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Downloads. Bitte später erneut versuchen.', ['status' => 429]);
        }
        $id  = intval($req->get_param('l'));
        $h   = preg_replace('/[^a-f0-9]/', '', (string) $req->get_param('h'));
        $v   = (string) $req->get_param('v');
        $exp = intval($req->get_param('e'));
        $sig = (string) $req->get_param('sig');
        if (!$id || strlen($h) !== 16 || $exp < time() || !hash_equals(self::sign($id, $h, $v, $exp), $sig)) {
            return new WP_Error('tix_license_link', 'Der Download-Link ist abgelaufen oder ungültig. Bitte im WordPress-Backend erneut nach Updates suchen.', ['status' => 403]);
        }
        $lic = self::get($id);
        if (self::license_state($lic) !== 'active') {
            return new WP_Error('tix_license_inactive', self::messages()[self::license_state($lic)] ?? 'Lizenz nicht aktiv.', ['status' => 403]);
        }
        if ($v !== TIXOMAT_VERSION) {
            return new WP_Error('tix_license_version', 'Diese Version gibt es nicht mehr. Bitte erneut nach Updates suchen.', ['status' => 410]);
        }
        $file = self::build_package();
        if (is_wp_error($file)) return $file;
        global $wpdb;
        foreach (self::sites($id) as $s) {
            if (substr(hash('sha256', $s->host), 0, 16) === $h) {
                $wpdb->update(self::t('tix_license_sites'), ['last_download' => current_time('mysql')], ['id' => $s->id]);
            }
        }
        self::stream($file, 'tixomat-' . TIXOMAT_VERSION . '.zip');
    }

    /** @return never */
    private static function stream($file, $name) {
        while (ob_get_level()) ob_end_clean();
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    private static function package_dir() {
        $base = class_exists('TIX_Support') && method_exists('TIX_Support', 'private_base')
            ? TIX_Support::private_base()
            : wp_normalize_path(wp_upload_dir()['basedir']) . '/tix-private';
        $dir = $base . '/license';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        // Sperrdatei für den Basisordner (wichtig beim Rückfall uploads/tix-private im Webroot)
        if (class_exists('TIX_Support') && method_exists('TIX_Support', 'private_dir')) TIX_Support::private_dir();
        if (!file_exists($dir . '/index.php')) @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        return $dir;
    }

    /** ZIP der installierten Version (einmal je Version gebaut, alte werden entfernt). */
    public static function build_package() {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('tix_license_zip', 'Auf dem Lizenz-Server fehlt die PHP-Erweiterung zip.', ['status' => 500]);
        }
        $dir  = self::package_dir();
        $name = 'tixomat-' . TIXOMAT_VERSION . '-' . substr(hash_hmac('sha256', TIXOMAT_VERSION, wp_salt('auth')), 0, 20) . '.zip';
        $file = $dir . '/' . $name;
        if (file_exists($file) && filesize($file) > 0) return $file;
        foreach ((array) glob($dir . '/tixomat-*.zip') as $old) { if ($old !== $file) @unlink($old); }
        $root = untrailingslashit(wp_normalize_path(TIXOMAT_PATH));
        $tmp  = $file . '.tmp';
        $zip  = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('tix_license_zip', 'Das Update-Paket konnte nicht erstellt werden.', ['status' => 500]);
        }
        $zip->addFile($root . '/tixomat.php', 'tixomat/tixomat.php');
        foreach (['includes', 'assets', 'templates'] as $sub) {
            if (!is_dir($root . '/' . $sub)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $sub, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $path = wp_normalize_path($f->getPathname());
                $rel  = substr($path, strlen($root) + 1);
                if (preg_match('#(^|/)\.#', $rel)) continue; // keine versteckten Dateien
                $zip->addFile($path, 'tixomat/' . $rel);
            }
        }
        $zip->addFromString('tixomat/self-hosted.flag', "Tixomat im Selbstbetrieb – Updates über die Lizenz (Tixomat → Lizenz).\n");
        if (!$zip->close() || !@rename($tmp, $file)) {
            @unlink($tmp);
            return new WP_Error('tix_license_zip', 'Das Update-Paket konnte nicht gespeichert werden.', ['status' => 500]);
        }
        return $file;
    }

    public static function rest_releases(WP_REST_Request $req) {
        $dates = get_option('tix_release_dates', []);
        $out = [];
        foreach (array_reverse(is_array($dates) ? $dates : [], true) as $v => $d) {
            $out[] = ['version' => (string) $v, 'released_at' => (string) $d];
            if (count($out) >= 100) break;
        }
        return rest_ensure_response(['ok' => true, 'latest' => TIXOMAT_VERSION, 'releases' => $out]);
    }

    // ── Admin-REST (Plattform-Studio) ──

    public static function perm_admin() {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur Admins verwalten Lizenzen.', ['status' => 403]);
    }

    /** Lizenz für das Studio – ohne vollen Schlüssel (nur die letzten 4 Zeichen). */
    public static function admin_payload($lic) {
        $sites = [];
        $last = null;
        foreach (self::sites($lic->id) as $s) {
            $sites[] = ['id' => intval($s->id), 'host' => (string) $s->host, 'dev' => (bool) $s->dev, 'version' => (string) $s->version,
                'first_seen' => (string) $s->first_seen, 'last_seen' => (string) $s->last_seen];
            if (!$s->dev && (!$last || $s->last_seen > $last)) $last = (string) $s->last_seen;
        }
        $hb_age = $last ? (current_time('timestamp') - strtotime($last)) : null;
        return [
            'id' => intval($lic->id), 'key_hint' => substr((string) $lic->license_key, -4), 'customer' => (string) $lic->customer, 'email' => (string) $lic->email,
            'status' => (string) $lic->status, 'state' => self::license_state($lic), 'stage' => self::stage_for($lic),
            'stage_override' => (string) $lic->stage_override, 'enforce' => !empty($lic->enforce),
            'valid_until' => $lic->valid_until ? (string) $lic->valid_until : null, 'last_event_date' => $lic->last_event_date ? (string) $lic->last_event_date : null,
            'basis_url' => (string) $lic->basis_url, 'max_sites' => intval($lic->max_sites), 'note' => (string) $lic->note,
            'modules' => self::license_modules($lic), 'sites' => $sites,
            'heartbeat' => ['last' => $last, 'overdue' => $hb_age === null || $hb_age > 2 * DAY_IN_SECONDS],
            'created_at' => (string) $lic->created_at,
        ];
    }

    public static function rest_admin_list(WP_REST_Request $req) {
        global $wpdb;
        self::maybe_install();
        $rows = $wpdb->get_results("SELECT * FROM " . self::t('tix_licenses') . " ORDER BY created_at DESC LIMIT 500");
        return rest_ensure_response(['ok' => true, 'licenses' => array_map([__CLASS__, 'admin_payload'], $rows ?: []),
            'notice_days' => self::NOTICE_DAYS, 'modules' => class_exists('TIX_Modules') ? TIX_Modules::keys() : []]);
    }

    public static function rest_admin_create(WP_REST_Request $req) {
        self::maybe_install();
        $lic = self::create([
            'customer' => (string) $req->get_param('customer'), 'email' => (string) $req->get_param('email'),
            'max_sites' => $req->get_param('max_sites') ?? 1, 'valid_until' => (string) ($req->get_param('valid_until') ?? gmdate('Y-m-d', strtotime('+1 year'))),
            'note' => (string) $req->get_param('note'), 'enforce' => filter_var($req->get_param('enforce'), FILTER_VALIDATE_BOOLEAN),
        ]);
        if (!$lic) return new WP_Error('tix_license_create', 'Lizenz konnte nicht angelegt werden.', ['status' => 500]);
        // Schlüssel nur bei der Anlage vollständig (für den Deploy der Seite)
        return rest_ensure_response(['ok' => true, 'license' => self::admin_payload($lic), 'key' => (string) $lic->license_key]);
    }

    public static function rest_admin_get(WP_REST_Request $req) {
        $lic = self::get(intval($req['id']));
        if (!$lic) return new WP_Error('tix_not_found', 'Lizenz nicht gefunden.', ['status' => 404]);
        return rest_ensure_response(['ok' => true, 'license' => self::admin_payload($lic)]);
    }

    /** Ändern: Fernschalter (stage_override), Laufzeit, Status, Durchsetzung, Module, Basis-Adresse. */
    public static function rest_admin_save(WP_REST_Request $req) {
        global $wpdb;
        $lic = self::get(intval($req['id']));
        if (!$lic) return new WP_Error('tix_not_found', 'Lizenz nicht gefunden.', ['status' => 404]);
        $p = $req->get_json_params() ?: $req->get_params();
        $up = [];
        if (array_key_exists('stage_override', $p)) {
            $ov = sanitize_key((string) $p['stage_override']);
            if (!in_array($ov, ['', 'active', 'locked', 'basis'], true)) return new WP_Error('tix_invalid_field', 'Unbekannter Schalter.', ['status' => 400, 'field' => 'stage_override']);
            $up['stage_override'] = $ov;
        }
        if (array_key_exists('valid_until', $p)) {
            $d = self::date_or_null((string) $p['valid_until']);
            if ((string) $p['valid_until'] !== '' && !$d) return new WP_Error('tix_invalid_field', 'Datum bitte als JJJJ-MM-TT.', ['status' => 400, 'field' => 'valid_until']);
            $up['valid_until'] = $d;
        }
        if (array_key_exists('status', $p)) {
            $st = sanitize_key((string) $p['status']);
            if (!in_array($st, ['active', 'suspended', 'revoked'], true)) return new WP_Error('tix_invalid_field', 'Unbekannter Status.', ['status' => 400, 'field' => 'status']);
            $up['status'] = $st;
        }
        if (array_key_exists('enforce', $p)) $up['enforce'] = filter_var($p['enforce'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if (array_key_exists('basis_url', $p)) {
            $u = trim((string) $p['basis_url']);
            if ($u !== '' && !preg_match('#^https://#i', $u)) return new WP_Error('tix_invalid_field', 'Die Basis-Adresse muss mit https:// beginnen.', ['status' => 400, 'field' => 'basis_url']);
            $up['basis_url'] = $u === '' ? '' : esc_url_raw($u);
        }
        if (array_key_exists('modules', $p)) {
            $up['modules'] = is_array($p['modules']) && class_exists('TIX_Modules')
                ? wp_json_encode(array_values(array_intersect(TIX_Modules::keys(), array_map('sanitize_key', $p['modules'])))) : null;
        }
        foreach (['customer' => 190, 'note' => 2000] as $f => $len) {
            if (array_key_exists($f, $p)) $up[$f] = mb_substr($f === 'note' ? sanitize_textarea_field((string) $p[$f]) : sanitize_text_field((string) $p[$f]), 0, $len);
        }
        if (array_key_exists('email', $p)) $up['email'] = sanitize_email((string) $p['email']);
        if (array_key_exists('max_sites', $p)) $up['max_sites'] = max(1, intval($p['max_sites']));
        if (!$up) return new WP_Error('tix_invalid_field', 'Nichts zu ändern.', ['status' => 400]);
        $before = self::stage_for($lic);
        $wpdb->update(self::t('tix_licenses'), $up, ['id' => $lic->id]);
        $lic = self::get($lic->id);
        self::log($lic->id, 'save', $up + ['stage_before' => $before, 'stage_after' => self::stage_for($lic)]);
        return rest_ensure_response(['ok' => true, 'license' => self::admin_payload($lic)]);
    }

    public static function rest_admin_log(WP_REST_Request $req) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('tix_license_log') . " WHERE license_id = %d ORDER BY id DESC LIMIT 200", intval($req['id'])));
        $out = [];
        foreach ($rows ?: [] as $r) {
            $u = $r->user_id ? get_userdata($r->user_id) : null;
            $out[] = ['action' => (string) $r->action, 'user' => $u ? $u->display_name : '', 'data' => $r->data ? json_decode($r->data, true) : null, 'created_at' => (string) $r->created_at];
        }
        return rest_ensure_response(['ok' => true, 'log' => $out]);
    }

    /** „Jetzt prüfen“: bittet jede gebundene Website, sofort den Heartbeat zu schicken. */
    public static function rest_admin_ping(WP_REST_Request $req) {
        $lic = self::get(intval($req['id']));
        if (!$lic) return new WP_Error('tix_not_found', 'Lizenz nicht gefunden.', ['status' => 404]);
        $res = [];
        foreach (self::sites($lic->id) as $s) {
            if ($s->dev) continue;
            $r = wp_remote_post('https://' . $s->host . '/wp-json/tixomat/v1/license/ping', ['timeout' => 20, 'headers' => ['Accept' => 'application/json']]);
            $code = is_wp_error($r) ? 0 : intval(wp_remote_retrieve_response_code($r));
            $d = $code === 200 ? json_decode((string) wp_remote_retrieve_body($r), true) : null;
            $res[] = ['host' => (string) $s->host, 'ok' => $code === 200, 'stage' => is_array($d) ? ($d['stage'] ?? null) : null,
                'error' => $code === 200 ? null : (is_wp_error($r) ? $r->get_error_message() : 'HTTP ' . $code)];
        }
        self::log($lic->id, 'ping', ['sites' => count($res)]);
        return rest_ensure_response(['ok' => true, 'sites' => $res]);
    }

    // ── Admin ──

    public static function menu() {
        add_submenu_page('tixomat', 'Lizenzen (Selbstbetrieb)', 'Lizenzen', 'manage_options', 'tix-licenses', [__CLASS__, 'render_admin']);
    }

    public static function handle_admin() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_license_admin');
        global $wpdb;
        $do  = sanitize_key($_POST['do'] ?? '');
        $id  = intval($_POST['id'] ?? 0);
        $msg = '';
        if ($do === 'create') {
            $lic = self::create([
                'customer' => wp_unslash($_POST['customer'] ?? ''), 'email' => wp_unslash($_POST['email'] ?? ''),
                'max_sites' => $_POST['max_sites'] ?? 1, 'valid_until' => $_POST['valid_until'] ?? '', 'note' => wp_unslash($_POST['note'] ?? ''),
            ]);
            $msg = $lic ? 'created' : 'error';
            $id  = $lic ? intval($lic->id) : 0;
        } elseif ($id && ($lic = self::get($id))) {
            if ($do === 'extend') {
                $from = ($lic->valid_until && $lic->valid_until > current_time('Y-m-d')) ? $lic->valid_until : current_time('Y-m-d');
                $wpdb->update(self::t('tix_licenses'), ['valid_until' => gmdate('Y-m-d', strtotime($from . ' +1 year'))], ['id' => $id]);
                $msg = 'extended';
            } elseif ($do === 'status') {
                $st = sanitize_key($_POST['status'] ?? '');
                if (in_array($st, ['active', 'suspended', 'revoked'], true)) $wpdb->update(self::t('tix_licenses'), ['status' => $st], ['id' => $id]);
                $msg = 'saved';
            } elseif ($do === 'save') {
                $wpdb->update(self::t('tix_licenses'), [
                    'customer'    => mb_substr(sanitize_text_field(wp_unslash($_POST['customer'] ?? '')), 0, 190),
                    'email'       => sanitize_email(wp_unslash($_POST['email'] ?? '')),
                    'max_sites'   => max(1, intval($_POST['max_sites'] ?? 1)),
                    'valid_until' => self::date_or_null($_POST['valid_until'] ?? ''),
                    'note'        => sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')),
                    'modules'     => self::modules_from_post(),
                ], ['id' => $id]);
                $msg = 'saved';
            } elseif ($do === 'unbind') {
                $wpdb->delete(self::t('tix_license_sites'), ['id' => intval($_POST['site_id'] ?? 0), 'license_id' => $id]);
                $msg = 'unbound';
            }
        }
        wp_safe_redirect(add_query_arg(array_filter(['page' => 'tix-licenses', 'id' => $id ?: null, 'msg' => $msg]), admin_url('admin.php')));
        exit;
    }

    private static function state_label($st) {
        $l = ['active' => 'Aktiv', 'expired' => 'Abgelaufen', 'suspended' => 'Pausiert', 'revoked' => 'Beendet', 'invalid' => 'Unbekannt'];
        return $l[$st] ?? $st;
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        self::maybe_install();
        $notes = ['created' => 'Lizenz angelegt.', 'extended' => 'Um ein Jahr verlängert.', 'saved' => 'Gespeichert.', 'unbound' => 'Website gelöst – der Schlüssel kann auf einer neuen Website aktiviert werden.', 'error' => 'Fehler beim Speichern.'];
        $msg  = sanitize_key($_GET['msg'] ?? '');
        $id   = intval($_GET['id'] ?? 0);
        $rows = $wpdb->get_results("SELECT * FROM " . self::t('tix_licenses') . " ORDER BY id DESC LIMIT 500");
        $post = esc_url(admin_url('admin-post.php'));
        $nonce = wp_nonce_field('tix_license_admin', '_wpnonce', true, false);
        ?>
        <div class="wrap">
            <h1>Lizenzen (Selbstbetrieb)</h1>
            <?php if (isset($notes[$msg])) : ?><div class="notice notice-<?php echo $msg === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html($notes[$msg]); ?></p></div><?php endif; ?>
            <p>Je Lizenz eine Website (Staging/Test-Adressen zählen nicht). Ohne gültige Lizenz läuft Tixomat beim Kunden weiter, nur ohne Updates.
               Update-Paket = diese Installation (<?php echo esc_html(TIXOMAT_VERSION); ?>).</p>

            <?php if ($id && ($lic = self::get($id))) : $st = self::license_state($lic); ?>
            <h2>Lizenz <?php echo esc_html($lic->license_key); ?></h2>
            <p><input type="text" readonly value="<?php echo esc_attr($lic->license_key); ?>" style="width:260px;font-family:monospace;" onclick="this.select()"> · Status: <strong><?php echo esc_html(self::state_label($st)); ?></strong></p>
            <form method="post" action="<?php echo $post; ?>" style="max-width:640px;">
                <?php echo $nonce; ?><input type="hidden" name="action" value="tix_license_admin"><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?php echo intval($lic->id); ?>">
                <table class="form-table">
                    <tr><th>Kunde</th><td><input type="text" name="customer" value="<?php echo esc_attr($lic->customer); ?>" class="regular-text"></td></tr>
                    <tr><th>E-Mail</th><td><input type="email" name="email" value="<?php echo esc_attr($lic->email); ?>" class="regular-text"></td></tr>
                    <tr><th>Gültig bis</th><td><input type="date" name="valid_until" value="<?php echo esc_attr((string) $lic->valid_until); ?>"></td></tr>
                    <tr><th>Websites</th><td><input type="number" name="max_sites" min="1" value="<?php echo intval($lic->max_sites); ?>" style="width:80px;"></td></tr>
                    <tr><th>Notiz</th><td><textarea name="note" rows="3" class="large-text"><?php echo esc_textarea((string) $lic->note); ?></textarea></td></tr>
                    <?php $lm = self::license_modules($lic); ?>
                    <tr><th>Module</th><td>
                        <label><input type="radio" name="modules_mode" value="all" <?php checked($lm === null); ?>> Alle Module</label>
                        <label style="margin-left:14px;"><input type="radio" name="modules_mode" value="select" <?php checked($lm !== null); ?>> Nur diese:</label>
                        <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:12px;">
                        <?php foreach (TIX_Modules::MODULES as $mk => $mv) : ?>
                            <label><input type="checkbox" name="modules[]" value="<?php echo esc_attr($mk); ?>" <?php checked($lm !== null && in_array($mk, $lm, true)); ?>> <?php echo esc_html($mv[0]); ?></label>
                        <?php endforeach; ?>
                        </div>
                        <p class="description">Grundlage für Modul-Abos. Die Website übernimmt die Auswahl bei der nächsten Lizenzprüfung (täglich bzw. „Neu prüfen“).</p>
                    </td></tr>
                </table>
                <p><button class="button button-primary">Speichern</button></p>
            </form>
            <form method="post" action="<?php echo $post; ?>" style="display:inline;"><?php echo $nonce; ?><input type="hidden" name="action" value="tix_license_admin"><input type="hidden" name="do" value="extend"><input type="hidden" name="id" value="<?php echo intval($lic->id); ?>"><button class="button">Um 1 Jahr verlängern</button></form>
            <?php foreach (['active' => 'Aktivieren', 'suspended' => 'Pausieren', 'revoked' => 'Beenden'] as $k => $label) : if ($lic->status === $k) continue; ?>
            <form method="post" action="<?php echo $post; ?>" style="display:inline;"><?php echo $nonce; ?><input type="hidden" name="action" value="tix_license_admin"><input type="hidden" name="do" value="status"><input type="hidden" name="status" value="<?php echo esc_attr($k); ?>"><input type="hidden" name="id" value="<?php echo intval($lic->id); ?>"><button class="button"><?php echo esc_html($label); ?></button></form>
            <?php endforeach; ?>
            <h3>Websites</h3>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th>Website</th><th>Version</th><th>WordPress / PHP</th><th>Zuerst</th><th>Zuletzt</th><th>Letzter Download</th><th></th></tr></thead>
                <tbody>
                <?php $sites = self::sites($lic->id); if (!$sites) : ?><tr><td colspan="7">Noch nicht aktiviert.</td></tr><?php endif; ?>
                <?php foreach ($sites as $s) : ?>
                    <tr><td><?php echo esc_html($s->host); ?><?php echo $s->dev ? ' <em>(Test, zählt nicht)</em>' : ''; ?></td><td><?php echo esc_html($s->version); ?></td><td><?php echo esc_html($s->wp . ' / ' . $s->php); ?></td>
                        <td><?php echo esc_html($s->first_seen); ?></td><td><?php echo esc_html($s->last_seen); ?></td><td><?php echo esc_html((string) $s->last_download); ?></td>
                        <td><form method="post" action="<?php echo $post; ?>"><?php echo $nonce; ?><input type="hidden" name="action" value="tix_license_admin"><input type="hidden" name="do" value="unbind"><input type="hidden" name="id" value="<?php echo intval($lic->id); ?>"><input type="hidden" name="site_id" value="<?php echo intval($s->id); ?>"><button class="button-link-delete">Lösen</button></form></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=tix-licenses')); ?>">← Alle Lizenzen</a></p>
            <?php else : ?>
            <h2>Neue Lizenz</h2>
            <form method="post" action="<?php echo $post; ?>" style="max-width:640px;">
                <?php echo $nonce; ?><input type="hidden" name="action" value="tix_license_admin"><input type="hidden" name="do" value="create">
                <table class="form-table">
                    <tr><th>Kunde</th><td><input type="text" name="customer" required class="regular-text"></td></tr>
                    <tr><th>E-Mail</th><td><input type="email" name="email" class="regular-text"></td></tr>
                    <tr><th>Gültig bis</th><td><input type="date" name="valid_until" value="<?php echo esc_attr(gmdate('Y-m-d', strtotime('+1 year'))); ?>"></td></tr>
                    <tr><th>Websites</th><td><input type="number" name="max_sites" min="1" value="1" style="width:80px;"></td></tr>
                    <tr><th>Notiz</th><td><textarea name="note" rows="2" class="large-text"></textarea></td></tr>
                </table>
                <p><button class="button button-primary">Lizenz anlegen</button></p>
            </form>
            <h2>Alle Lizenzen</h2>
            <table class="widefat striped">
                <thead><tr><th>Schlüssel</th><th>Kunde</th><th>Status</th><th>Gültig bis</th><th>Websites</th></tr></thead>
                <tbody>
                <?php if (!$rows) : ?><tr><td colspan="5">Noch keine Lizenzen.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r) : $hosts = wp_list_pluck(self::sites($r->id), 'host'); ?>
                    <tr><td><a href="<?php echo esc_url(add_query_arg(['page' => 'tix-licenses', 'id' => $r->id], admin_url('admin.php'))); ?>"><code><?php echo esc_html($r->license_key); ?></code></a></td>
                        <td><?php echo esc_html($r->customer); ?></td><td><?php echo esc_html(self::state_label(self::license_state($r))); ?></td>
                        <td><?php echo esc_html((string) $r->valid_until); ?></td><td><?php echo esc_html($hosts ? implode(', ', $hosts) : '–'); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
