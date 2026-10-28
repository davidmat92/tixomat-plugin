<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_License – Lizenz und Updates im Selbstbetrieb (Kunde betreibt Tixomat auf seinem Server).
 *
 * Aktiv nur im Selbstbetrieb: `self-hosted.flag` im Plugin-Ordner (liegt nur im Paket vom Lizenz-Server)
 * oder ein eingetragener Schlüssel. Eigene, von uns betriebene Seiten (Auslieferung per Deploy) haben
 * die Datei nicht → kein Hinweis, keine Abfrage. Auf dem Lizenz-Server selbst nie aktiv.
 *
 * Ohne gültige Lizenz bricht nichts ab: nur ein Hinweis im Admin, keine Updates.
 * Update-Weg: Plugin-Header „Update URI“ → Filter `update_plugins_{host}`; Prüfung täglich (Cron)
 * und beim Speichern, Ergebnis in Option `tix_license_status`.
 *
 * Betriebsart (1.38.566, Option `tix_license_mode`): `selfhosted` (wie bisher), `managed` (von uns betriebene
 * Kundenseite, Schlüssel per Deploy) oder `none` (eigene Seiten wie evendis.de, KitchenKlub, Mallorca – keine
 * Prüfung). Ohne Option gilt: Flag/Schlüssel → selfhosted, sonst none. Stufen und Sperren: TIX_License_Stage.
 */
class TIX_License {

    const SERVER_DEFAULT = 'https://veranstalter.evendis.de';
    const UPDATE_HOST    = 'veranstalter.evendis.de'; // = Host im Plugin-Header „Update URI“
    const OPT_KEY        = 'tix_license_key';
    const OPT_STATUS     = 'tix_license_status';
    const CRON           = 'tix_license_check';
    const GRACE_DAYS     = 7;  // Server nicht erreichbar: letzte gültige Prüfung zählt so lange weiter (User 2026-10-29, L6)
    const OPT_MODE       = 'tix_license_mode';
    const MODES          = ['selfhosted', 'managed', 'none'];

    public static function self_hosted() {
        return file_exists(TIXOMAT_PATH . 'self-hosted.flag') || (string) get_option(self::OPT_KEY, '') !== '';
    }

    /** selfhosted | managed | none */
    public static function mode() {
        $m = (string) get_option(self::OPT_MODE, '');
        if (in_array($m, self::MODES, true)) return $m;
        return self::self_hosted() ? 'selfhosted' : 'none';
    }

    public static function active() {
        return self::mode() !== 'none' && !(class_exists('TIX_License_Server') && TIX_License_Server::enabled());
    }

    public static function server() {
        $url = defined('TIX_LICENSE_SERVER') && TIX_LICENSE_SERVER ? (string) TIX_LICENSE_SERVER : self::SERVER_DEFAULT;
        return untrailingslashit($url);
    }

    public static function init() {
        if (!self::active()) {
            // Nicht mehr im Selbstbetrieb → geplante Prüfung entfernen
            if (wp_next_scheduled(self::CRON)) wp_clear_scheduled_hook(self::CRON);
            return;
        }
        add_action(self::CRON, [__CLASS__, 'check']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_License::CRON)) wp_schedule_event(time() + 300, 'daily', TIX_License::CRON);
        });
        add_filter('update_plugins_' . self::UPDATE_HOST, [__CLASS__, 'update_info'], 10, 4);
        add_filter('plugins_api', [__CLASS__, 'plugins_api'], 10, 3);
        add_action('admin_menu', [__CLASS__, 'menu'], 45);
        add_action('admin_post_tix_license', [__CLASS__, 'handle_save']);
        add_action('admin_notices', [__CLASS__, 'notice']);
    }

    public static function plugin_file() {
        return plugin_basename(TIXOMAT_PATH . 'tixomat.php');
    }

    public static function status() {
        $s = get_option(self::OPT_STATUS, []);
        return is_array($s) ? $s : [];
    }

    /** Gilt die Lizenz (inkl. Schonfrist bei nicht erreichbarem Server)? */
    public static function valid() {
        $s = self::status();
        if (empty($s['valid'])) return false;
        if (empty($s['unreachable'])) return true;
        return !empty($s['last_ok']) && (time() - intval($s['last_ok'])) < self::GRACE_DAYS * DAY_IN_SECONDS;
    }

    /** Beim Lizenz-Server prüfen. Netzwerkfehler ändern den letzten Stand nicht (nur Vermerk). */
    public static function check() {
        $key = (string) get_option(self::OPT_KEY, '');
        $prev = self::status();
        if ($key === '') {
            update_option(self::OPT_STATUS, ['valid' => false, 'status' => 'missing', 'message' => 'Kein Lizenzschlüssel eingetragen.', 'checked_at' => time()], false);
            return self::status();
        }
        global $wp_version;
        $res = wp_remote_post(self::server() . '/wp-json/tixomat/v1/license/check', [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
            'body'    => ['key' => $key, 'site' => home_url(), 'version' => TIXOMAT_VERSION, 'wp' => (string) $wp_version, 'php' => PHP_VERSION,
                          'mode' => self::mode(), 'last_event_date' => class_exists('TIX_License_Stage') ? (string) TIX_License_Stage::last_event_date() : ''],
        ]);
        $code = is_wp_error($res) ? 0 : intval(wp_remote_retrieve_response_code($res));
        $data = $code === 200 ? json_decode((string) wp_remote_retrieve_body($res), true) : null;
        if (!is_array($data) || !isset($data['status'])) {
            $prev['unreachable'] = true;
            $prev['checked_at']  = time();
            $prev['error']       = is_wp_error($res) ? $res->get_error_message() : ('HTTP ' . $code);
            update_option(self::OPT_STATUS, $prev, false);
            return self::status();
        }
        $status = [
            'valid'       => !empty($data['valid']),
            'status'      => sanitize_key((string) $data['status']),
            'message'     => sanitize_text_field((string) ($data['message'] ?? '')),
            'expires_at'  => isset($data['expires_at']) ? sanitize_text_field((string) $data['expires_at']) : null,
            'latest'      => is_array($data['latest'] ?? null) ? array_map('sanitize_text_field', array_map('strval', $data['latest'])) : [],
            'package_url' => !empty($data['package_url']) ? esc_url_raw((string) $data['package_url']) : null,
            'checked_at'  => time(),
            'last_ok'     => !empty($data['valid']) ? time() : intval($prev['last_ok'] ?? 0),
            'unreachable' => false,
        ];
        // Stufe (Fernschalter) nur mit gültiger Signatur übernehmen; sonst bleibt der letzte signierte Stand
        if (isset($data['stage']) && self::verify($key, $data)) {
            $status += [
                'stage'           => sanitize_key((string) $data['stage']),
                'enforce'         => !empty($data['enforce']),
                'notice_from'     => isset($data['notice_from']) ? sanitize_text_field((string) $data['notice_from']) : null,
                'last_event_date' => isset($data['last_event_date']) ? sanitize_text_field((string) $data['last_event_date']) : null,
                'basis_url'       => !empty($data['basis_url']) ? esc_url_raw((string) $data['basis_url']) : '',
                'signed_at'       => time(),
            ];
        } else {
            foreach (['stage', 'enforce', 'notice_from', 'last_event_date', 'basis_url', 'signed_at'] as $k) {
                if (array_key_exists($k, $prev)) $status[$k] = $prev[$k];
            }
        }
        // Module der Lizenz (null = alle); unbekannter Schlüssel ändert die zuletzt bekannte Freischaltung nicht
        if (array_key_exists('modules', $data) && ($data['status'] ?? '') !== 'invalid') {
            $status['modules'] = is_array($data['modules']) ? array_values(array_map('sanitize_key', $data['modules'])) : null;
        } elseif (array_key_exists('modules', $prev)) {
            $status['modules'] = $prev['modules'];
        }
        update_option(self::OPT_STATUS, $status, false);
        return $status;
    }

    /** Signatur der Stufen-Antwort prüfen (gleiche Grundlage wie TIX_License_Server::sign_base, Host = diese Seite). */
    public static function verify($key, array $data) {
        if (empty($data['signature']) || empty($data['issued_at'])) return false;
        if (abs(time() - intval($data['issued_at'])) > 2 * DAY_IN_SECONDS) return false; // alte Antwort nicht wiederverwenden
        $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)));
        $mods = array_key_exists('modules', $data) && is_array($data['modules']) ? implode(',', $data['modules']) : '*';
        $base = implode('|', ['tix-lic-v1', $host, (string) $data['stage'], !empty($data['enforce']) ? '1' : '0',
            (string) ($data['expires_at'] ?? ''), (string) ($data['last_event_date'] ?? ''), (string) ($data['basis_url'] ?? ''), $mods, intval($data['issued_at'])]);
        $norm = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim((string) $key)));
        return hash_equals(hash_hmac('sha256', $base, $norm), (string) $data['signature']);
    }

    /** Update-Angebot für WordPress (Filter `update_plugins_{host}`). */
    public static function update_info($update, $plugin_data, $plugin_file, $locales) {
        if ($plugin_file !== self::plugin_file()) return $update;
        $s = self::status();
        // Download-Links laufen nach 12 h ab → spätestens nach 6 h neu prüfen
        if (empty($s['checked_at']) || time() - intval($s['checked_at']) > 6 * HOUR_IN_SECONDS) $s = self::check();
        if (!self::valid() || empty($s['latest']['version']) || empty($s['package_url'])) return false;
        return [
            'id'           => self::UPDATE_HOST . '/tixomat',
            'slug'         => 'tixomat',
            'version'      => (string) $s['latest']['version'],
            'url'          => 'https://' . self::UPDATE_HOST . '/',
            'package'      => (string) $s['package_url'],
            'requires'     => (string) ($s['latest']['requires_wp'] ?? ''),
            'requires_php' => (string) ($s['latest']['requires_php'] ?? ''),
        ];
    }

    public static function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'tixomat') return $result;
        $s = self::status();
        $latest = (string) ($s['latest']['version'] ?? TIXOMAT_VERSION);
        return (object) [
            'name'          => 'Tixomat – Event & Ticket Management',
            'slug'          => 'tixomat',
            'version'       => $latest,
            'author'        => 'evendis',
            'homepage'      => 'https://' . self::UPDATE_HOST . '/',
            'requires'      => (string) ($s['latest']['requires_wp'] ?? ''),
            'requires_php'  => (string) ($s['latest']['requires_php'] ?? ''),
            'last_updated'  => (string) ($s['latest']['released_at'] ?? ''),
            'download_link' => self::valid() ? (string) ($s['package_url'] ?? '') : '',
            'sections'      => [
                'description' => '<p>Tixomat by evendis – Ticketing auf deiner eigenen Website.</p>',
                'changelog'   => '<p>Version ' . esc_html($latest) . '. Was neu ist, steht auf <a href="https://' . esc_attr(self::UPDATE_HOST) . '/" target="_blank" rel="noopener">' . esc_html(self::UPDATE_HOST) . '</a>.</p>',
            ],
        ];
    }

    // ── Admin ──

    public static function menu() {
        add_submenu_page('tixomat', 'Lizenz', 'Lizenz', 'manage_options', 'tix-license', [__CLASS__, 'render']);
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_license');
        $do = sanitize_key($_POST['do'] ?? 'save');
        if ($do === 'remove') {
            delete_option(self::OPT_KEY);
            delete_option(self::OPT_STATUS);
        } else {
            $key = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) wp_unslash($_POST['license_key'] ?? '')));
            if ($key !== '') update_option(self::OPT_KEY, $key, false);
            self::check();
            delete_site_transient('update_plugins'); // Update sofort neu suchen
        }
        wp_safe_redirect(admin_url('admin.php?page=tix-license&saved=1'));
        exit;
    }

    private static function mask($key) {
        return $key === '' ? '' : substr($key, 0, 4) . str_repeat('•', max(0, strlen($key) - 9)) . substr($key, -5);
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $key = (string) get_option(self::OPT_KEY, '');
        $s   = self::status();
        $valid = self::valid();
        $latest = (string) ($s['latest']['version'] ?? '');
        ?>
        <div class="wrap">
            <h1>Tixomat-Lizenz</h1>
            <?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Gespeichert und geprüft.</p></div><?php endif; ?>
            <p>Mit gültiger Lizenz bekommst du Updates direkt hier im WordPress-Backend. Ohne Lizenz läuft Tixomat weiter, nur ohne Updates.</p>
            <table class="form-table">
                <tr><th>Status</th><td><strong style="color:<?php echo $valid ? '#047857' : '#b45309'; ?>"><?php echo esc_html($valid ? 'Gültig' : 'Nicht gültig'); ?></strong>
                    <?php if (!empty($s['message'])) echo ' – ' . esc_html($s['message']); ?>
                    <?php if (!empty($s['unreachable'])) echo '<br><span style="color:#6b7280">Lizenz-Server zuletzt nicht erreichbar (' . esc_html((string) ($s['error'] ?? '')) . '). Der letzte Stand gilt bis zu ' . intval(self::GRACE_DAYS) . ' Tage weiter.</span>'; ?></td></tr>
                <?php if (!empty($s['expires_at'])) : ?><tr><th>Gültig bis</th><td><?php echo esc_html(date_i18n('d.m.Y', strtotime($s['expires_at']))); ?></td></tr><?php endif; ?>
                <tr><th>Installiert</th><td><?php echo esc_html(TIXOMAT_VERSION); ?><?php if ($latest && version_compare($latest, TIXOMAT_VERSION, '>')) echo ' · <strong>neu: ' . esc_html($latest) . '</strong> – <a href="' . esc_url(admin_url('update-core.php')) . '">zu den Aktualisierungen</a>'; ?></td></tr>
                <tr><th>Zuletzt geprüft</th><td><?php echo !empty($s['checked_at']) ? esc_html(date_i18n('d.m.Y H:i', intval($s['checked_at']) + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS))) : '–'; ?></td></tr>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tix_license'); ?><input type="hidden" name="action" value="tix_license">
                <p><label for="tix-license-key"><strong>Lizenzschlüssel</strong></label><br>
                   <input type="text" id="tix-license-key" name="license_key" class="regular-text" style="font-family:monospace" placeholder="<?php echo esc_attr($key ? self::mask($key) : 'TIX-XXXX-XXXX-XXXX-XXXX'); ?>" autocomplete="off"></p>
                <p><button class="button button-primary"><?php echo $key ? 'Neu prüfen / Schlüssel ändern' : 'Lizenz aktivieren'; ?></button></p>
            </form>
            <?php if ($key) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Lizenz von dieser Website entfernen?');">
                <?php wp_nonce_field('tix_license'); ?><input type="hidden" name="action" value="tix_license"><input type="hidden" name="do" value="remove">
                <p><button class="button-link-delete">Lizenz entfernen</button></p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Hinweis ohne gültige Lizenz bzw. kurz vor Ablauf – nur für Admins, nur auf Dashboard/Plugins/Tixomat-Seiten. */
    public static function notice() {
        if (!current_user_can('manage_options')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? (string) $screen->id : '';
        if (!in_array($id, ['dashboard', 'plugins', 'update-core'], true) && strpos($id, 'tixomat') === false && strpos($id, 'tix-') === false) return;
        if (strpos($id, 'tix-license') !== false) return;
        $link = '<a href="' . esc_url(admin_url('admin.php?page=tix-license')) . '">Lizenz</a>';
        $s = self::status();
        if (!self::valid()) {
            $why = !empty($s['message']) ? $s['message'] : 'Kein gültiger Lizenzschlüssel.';
            echo '<div class="notice notice-warning"><p><strong>Tixomat:</strong> ' . esc_html($why) . ' Tixomat läuft weiter, nur Updates gibt es ohne gültige Lizenz nicht. → ' . $link . '</p></div>';
            return;
        }
        if (!empty($s['expires_at']) && strtotime($s['expires_at']) - time() < 30 * DAY_IN_SECONDS) {
            echo '<div class="notice notice-info"><p><strong>Tixomat:</strong> Deine Lizenz läuft am ' . esc_html(date_i18n('d.m.Y', strtotime($s['expires_at']))) . ' ab. Danach gibt es keine Updates mehr. → ' . $link . '</p></div>';
        }
    }
}
