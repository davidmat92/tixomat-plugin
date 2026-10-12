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
 * Das Update-Paket ist die hier installierte Version (Whitelist: tixomat.php, includes/, assets/, templates/)
 * plus `self-hosted.flag` – daran erkennt das Plugin den Selbstbetrieb (TIX_License).
 */
class TIX_License_Server {

    const NS          = 'tixomat/v1';
    const DB_VERSION  = '1';
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
            'created_at'  => current_time('mysql'),
        ]);
        return $wpdb->insert_id ? self::get(intval($wpdb->insert_id)) : null;
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

    /** Status einer Lizenz ohne Website: active | expired | suspended | revoked. */
    public static function license_state($lic) {
        if (!$lic) return 'invalid';
        if (in_array($lic->status, ['suspended', 'revoked'], true)) return $lic->status;
        if ($lic->valid_until && $lic->valid_until < current_time('Y-m-d')) return 'expired';
        return 'active';
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
            'message'     => $msgs[$state] ?? '',
        ];
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
