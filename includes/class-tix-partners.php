<?php
/**
 * Partner-Verzeichnis für geteilte Events (Plattform-Seite, z. B. evendis.de)
 * und gemeinsame Hilfen für beide Seiten der Partner-Anbindung.
 *
 * Plattform (Empfänger der Event-Verteilung):
 *   Option `tix_partners` = [ id => [
 *       id, name, api_base (REST-Basis der Quelle, …/wp-json/tixomat/v1),
 *       key_in  (Quelle → Plattform: Event-Verteilung + Webhooks),
 *       key_out (Plattform → Quelle: Partner-API),
 *       organizer_id (zugeordneter tix_organizer auf der Plattform),
 *       sales_enabled (Verkauf über die Plattform erlaubt), terms_url, created
 *   ] ]
 *   Verwaltung: Tixomat → Partner (nur Admins).
 *
 * Quelle (Sender, z. B. kitchenklub.de):
 *   Einstellungen `syndication_api_url` (Plattform), `syndication_api_key` (= key_in),
 *   `partner_api_enabled` + `partner_api_key` (= key_out). Die Plattform meldet sich mit
 *   `X-Tix-Partner-Id` = Hostname der Plattform und `X-Tix-Partner-Key`.
 *
 * Kopplung neuer Quellen (Tixomat gibt es nur über den Vertrieb; Verkauf erst nach Freigabe):
 *   Quelle: Einstellungen → Event-Verteilung → „Mit Plattform verbinden“ → POST {Plattform}/partner/connect
 *   {site_url, api_base, name, organizer, nonce}. Die Plattform prüft per Rückruf
 *   GET {api_base}/partner/connect-verify?nonce=…, dass die Anfrage wirklich von dieser Seite kommt,
 *   legt Partner + Veranstalter an – Verkauf AUS – und mailt dem Admin. Freigabe mit einem Klick unter
 *   Tixomat → Partner („Freigeben“). Bis dahin werden die Events nur angezeigt („Tickets beim
 *   Veranstalter“). Erneutes Verbinden derselben Seite erzeugt neue Schlüssel und behält
 *   Freigabe und Veranstalter. Auf der Plattform per `partner_autoconnect` (Vorgabe an) abschaltbar.
 *
 * Alles ist aus, bis es konfiguriert ist: leeres Verzeichnis = keine Vermittlung,
 * Partner-API ohne Schlüssel = gesperrt.
 */
if (!defined('ABSPATH')) exit;

class TIX_Partners {

    const OPTION = 'tix_partners';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 40);
        add_action('admin_post_tix_partner_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_tix_partner_delete', [__CLASS__, 'handle_delete']);
        add_action('admin_post_tix_partner_approve', [__CLASS__, 'handle_approve']);
        // Kopplung neuer Quellen
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_tix_partner_connect', [__CLASS__, 'handle_connect']);
        add_action('admin_notices', [__CLASS__, 'connect_notice']);
    }

    public static function register_routes() {
        // Plattform: neue Quelle meldet sich an (Prüfung per Rückruf im Callback)
        register_rest_route('tixomat/v1', '/partner/connect', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_connect'], 'permission_callback' => '__return_true',
        ]);
        // Quelle: bestätigt nur die eigene, gerade laufende Kopplung
        register_rest_route('tixomat/v1', '/partner/connect-verify', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_connect_verify'], 'permission_callback' => '__return_true',
        ]);
    }

    // ──────────────────────────────────────────
    //  Verzeichnis (Plattform)
    // ──────────────────────────────────────────

    private static function defaults() {
        return [
            'id'            => '',
            'name'          => '',
            'api_base'      => '',
            'key_in'        => '',
            'key_out'       => '',
            'organizer_id'  => 0,
            'sales_enabled' => 0,
            'terms_url'     => '',
            'created'       => '',
            'auto'          => 0,   // über „Mit Plattform verbinden“ gekoppelt
            'connected'     => '',
            'pending'       => 0,   // gekoppelt, wartet auf Freigabe des Verkaufs
        ];
    }

    public static function all() {
        $all = get_option(self::OPTION, []);
        if (!is_array($all)) return [];
        $out = [];
        foreach ($all as $id => $p) {
            if (is_array($p)) $out[$id] = wp_parse_args($p, self::defaults());
        }
        return $out;
    }

    public static function get($id) {
        $id = sanitize_key((string) $id);
        if ($id === '') return null;
        $all = self::all();
        return $all[$id] ?? null;
    }

    public static function generate_key($prefix) {
        return $prefix . wp_generate_password(32, false);
    }

    /** Kurzer, nicht umkehrbarer Fingerabdruck eines Schlüssels (Zuordnung von Webhooks). */
    public static function kid($key) {
        return substr(hash('sha256', (string) $key), 0, 16);
    }

    /** Partner zu einem eingehenden Schlüssel (Event-Verteilung). */
    public static function find_by_key($key) {
        $key = (string) $key;
        if (strlen($key) < 20) return null;
        foreach (self::all() as $p) {
            if ($p['key_in'] !== '' && hash_equals($p['key_in'], $key)) return $p;
        }
        return null;
    }

    /** Partner zum Fingerabdruck seines eingehenden Schlüssels (Webhooks). */
    public static function find_by_kid($kid) {
        $kid = (string) $kid;
        if (strlen($kid) !== 16) return null;
        foreach (self::all() as $p) {
            if ($p['key_in'] !== '' && hash_equals(self::kid($p['key_in']), $kid)) return $p;
        }
        return null;
    }

    /** Darf die Plattform für diesen Partner verkaufen (Schalter + Zugangsdaten)? */
    public static function sales_ready($p) {
        return is_array($p) && !empty($p['sales_enabled']) && $p['api_base'] !== '' && strlen($p['key_out']) >= 20;
    }

    /** Hostname dieser Seite – Kennung der Plattform gegenüber der Quelle. */
    public static function platform_id() {
        return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    // ──────────────────────────────────────────
    //  Signatur (Webhooks Quelle → Plattform)
    // ──────────────────────────────────────────

    public static function sign($body, $key, $ts = null) {
        $ts = $ts ?: time();
        return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, (string) $key);
    }

    public static function verify($body, $header, $key, $tolerance = 300) {
        if (!preg_match('/t=(\d+),v1=([a-f0-9]{64})/', (string) $header, $m)) return false;
        if (abs(time() - intval($m[1])) > $tolerance) return false;
        return hash_equals(hash_hmac('sha256', $m[1] . '.' . $body, (string) $key), $m[2]);
    }

    // ──────────────────────────────────────────
    //  Quelle: Einstellungen der Partner-API
    // ──────────────────────────────────────────

    /** Partner-API auf dieser Seite freigeschaltet (Schalter + Schlüssel + Plattform)? */
    public static function source_api_enabled() {
        return !empty(tix_get_settings('partner_api_enabled'))
            && strlen((string) tix_get_settings('partner_api_key')) >= 20
            && self::source_platform_id() !== '';
    }

    /** Erwartete Kennung der Plattform = Hostname aus `syndication_api_url`. */
    public static function source_platform_id() {
        return strtolower((string) wp_parse_url((string) tix_get_settings('syndication_api_url'), PHP_URL_HOST));
    }

    // ──────────────────────────────────────────
    //  Kopplung – Plattform
    // ──────────────────────────────────────────

    private static function host($url) {
        return preg_replace('/^www\./', '', strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST)));
    }

    private static function find_by_host($host) {
        foreach (self::all() as $p) {
            if ($host !== '' && self::host($p['api_base']) === $host) return $p;
        }
        return null;
    }

    /**
     * POST /partner/connect – Quelle koppeln. Neuer Partner: Verkauf aus bis zur Freigabe durch
     * den Admin. Bekannte Seite: neue Schlüssel, Freigabe und Veranstalter bleiben.
     */
    public static function rest_connect(WP_REST_Request $req) {
        if (!tix_get_settings('syndication_receive_enabled')) {
            return new WP_Error('tix_connect_disabled', 'Diese Seite empfängt keine geteilten Events.', ['status' => 403]);
        }
        $auto = tix_get_settings('partner_autoconnect');
        if ($auto !== null && empty($auto)) {
            return new WP_Error('tix_connect_disabled', 'Kopplung ist hier ausgeschaltet – bitte beim Betreiber melden.', ['status' => 403]);
        }
        $ip = class_exists('TIX_App_Account') ? TIX_App_Account::client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
        $rk = 'tix_pconn_' . md5((string) $ip);
        $n  = intval(get_transient($rk)) + 1;
        set_transient($rk, $n, HOUR_IN_SECONDS);
        if ($n > 10) return new WP_Error('tix_rate_limit', 'Zu viele Kopplungsversuche. Bitte später erneut.', ['status' => 429]);

        $api   = untrailingslashit(esc_url_raw((string) $req->get_param('api_base')));
        $site  = esc_url_raw((string) $req->get_param('site_url'));
        $nonce = sanitize_text_field((string) $req->get_param('nonce'));
        $host  = self::host($api);
        $https = stripos($api, 'https://') === 0 || wp_get_environment_type() === 'local';
        if (!$https || $host === '' || self::host($site) !== $host || strlen($nonce) < 32) {
            return new WP_Error('tix_connect_request', 'Ungültige Kopplungsanfrage (HTTPS-Adresse und Seiten-URL müssen zusammenpassen).', ['status' => 400]);
        }
        if ($host === self::host(home_url())) {
            return new WP_Error('tix_connect_request', 'Eine Seite kann sich nicht mit sich selbst koppeln.', ['status' => 400]);
        }

        // Rückruf: Kommt die Anfrage wirklich von dieser Seite?
        $check = wp_remote_get(add_query_arg('nonce', rawurlencode($nonce), $api . '/partner/connect-verify'), ['timeout' => 15, 'redirection' => 0]);
        $ok = !is_wp_error($check) && intval(wp_remote_retrieve_response_code($check)) === 200
            && !empty(json_decode(wp_remote_retrieve_body($check), true)['ok']);
        if (!$ok) {
            return new WP_Error('tix_connect_verify', 'Die Seite konnte nicht bestätigt werden (Rückruf an ' . $host . ' fehlgeschlagen).', ['status' => 403]);
        }

        $name = mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 80) ?: $host;
        $existing = self::find_by_host($host);
        $all = self::all();
        if ($existing) {
            $id = $existing['id'];
        } else {
            $base = sanitize_key(str_replace('.', '-', $host));
            $id = $base;
            for ($i = 2; isset($all[$id]); $i++) $id = $base . '-' . $i;
        }
        $org_id = $existing ? intval($existing['organizer_id']) : 0;
        if (!$org_id) {
            $org = $req->get_param('organizer');
            $org_id = self::create_organizer(is_array($org) ? $org : [], $name, $site);
        }
        $all[$id] = [
            'id'            => $id,
            'name'          => $existing ? $existing['name'] : $name,
            'api_base'      => $api,
            'key_in'        => self::generate_key('tix_pin_'),
            'key_out'       => self::generate_key('tix_pout_'),
            'organizer_id'  => $org_id,
            // Verkauf erst nach Freigabe durch den Admin; bekannte Partner behalten ihren Stand
            'sales_enabled' => $existing ? intval($existing['sales_enabled']) : 0,
            'terms_url'     => $existing && $existing['terms_url'] !== '' ? $existing['terms_url'] : esc_url_raw((string) $req->get_param('terms_url')),
            'created'       => $existing['created'] ?? current_time('mysql'),
            'auto'          => 1,
            'connected'     => current_time('mysql'),
            'pending'       => $existing ? intval($existing['pending'] ?? 0) : 1,
        ];
        update_option(self::OPTION, $all, false);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();

        $admin = get_option('admin_email');
        if (is_email($admin)) {
            $pending = !empty($all[$id]['pending']);
            wp_mail($admin,
                ($pending ? 'Freigabe nötig – neuer Partner: ' : 'Partner neu verbunden: ') . $all[$id]['name'],
                "Eine Tixomat-Seite hat sich für geteilte Events gekoppelt.\n\n"
                . 'Seite: ' . $site . "\n"
                . 'Name: ' . $all[$id]['name'] . "\n"
                . 'Veranstalter: ' . ($org_id ? get_the_title($org_id) . ' (#' . $org_id . ')' : '–') . "\n"
                . 'Verkauf über die App: ' . ($all[$id]['sales_enabled'] ? 'an' : 'aus – wartet auf Freigabe') . "\n\n"
                . ($pending ? 'Freigeben: ' : 'Verwalten: ') . admin_url('admin.php?page=tix-partners&edit=' . $id) . "\n");
        }

        return rest_ensure_response([
            'ok'         => true,
            'partner_id' => $id,
            'name'       => $all[$id]['name'],
            'key_in'     => $all[$id]['key_in'],
            'key_out'    => $all[$id]['key_out'],
            'platform'   => rest_url('tixomat/v1'),
            'sales'      => (bool) $all[$id]['sales_enabled'],
            'pending'    => !empty($all[$id]['pending']),
            'reconnect'  => (bool) $existing,
        ]);
    }

    /** Veranstalter auf der Plattform aus den Angaben der Quelle anlegen. */
    private static function create_organizer(array $o, $fallback_name, $site) {
        $title = mb_substr(sanitize_text_field((string) ($o['name'] ?? '')), 0, 120) ?: $fallback_name;
        $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'post_title' => $title]);
        if (!$id || is_wp_error($id)) return 0;
        $address = sanitize_text_field((string) ($o['address'] ?? ''));
        $city    = sanitize_text_field((string) ($o['city'] ?? ''));
        if ($city === '' && preg_match('/\b\d{5}\s+(.+)$/u', $address, $m)) $city = trim($m[1]);
        $meta = [
            '_tix_org_address'    => $address,
            '_tix_org_city'       => $city,
            '_tix_org_website'    => esc_url_raw((string) ($o['website'] ?? $site)),
            '_tix_org_email'      => sanitize_email((string) ($o['email'] ?? '')),
            '_tix_org_phone'      => sanitize_text_field((string) ($o['phone'] ?? '')),
            '_tix_org_short_desc' => mb_substr(sanitize_textarea_field((string) ($o['description'] ?? '')), 0, 500),
        ];
        foreach ($meta as $k => $v) if ($v !== '') update_post_meta($id, $k, $v);
        $logo = esc_url_raw((string) ($o['logo_url'] ?? ''));
        if ($logo !== '' && stripos($logo, 'https://') === 0) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $att = media_sideload_image($logo, $id, $title . ' Logo', 'id');
            if (!is_wp_error($att)) {
                update_post_meta($id, '_tix_org_image_id', $att);
                set_post_thumbnail($id, $att);
            }
        }
        return intval($id);
    }

    /** Tixomat → Partner → „Freigeben“: Verkauf über die App einschalten. */
    public static function handle_approve() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_partner_approve');
        $id  = sanitize_key($_GET['id'] ?? '');
        $all = self::all();
        if (isset($all[$id])) {
            $all[$id]['sales_enabled'] = 1;
            $all[$id]['pending'] = 0;
            update_option(self::OPTION, $all, false);
            if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        }
        wp_safe_redirect(admin_url('admin.php?page=tix-partners&msg=approved'));
        exit;
    }

    // ──────────────────────────────────────────
    //  Kopplung – Quelle
    // ──────────────────────────────────────────

    /** GET /partner/connect-verify?nonce= – nur die gerade laufende eigene Kopplung bestätigen. */
    public static function rest_connect_verify(WP_REST_Request $req) {
        $pending = (string) get_transient('tix_partner_connect_nonce');
        $nonce   = (string) $req->get_param('nonce');
        if ($pending === '' || $nonce === '' || !hash_equals($pending, $nonce)) {
            return new WP_Error('tix_connect_unknown', 'Keine laufende Kopplung.', ['status' => 404]);
        }
        return rest_ensure_response(['ok' => true]);
    }

    /** Veranstalter-Angaben dieser Seite für die Plattform (Veranstalter mit den meisten Events). */
    private static function source_organizer() {
        global $wpdb;
        $oid = intval($wpdb->get_var(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.meta_key = '_tix_organizer_id' AND m.meta_value = p.ID
             WHERE p.post_type = 'tix_organizer' AND p.post_status = 'publish'
             GROUP BY p.ID ORDER BY COUNT(m.meta_id) DESC, p.ID ASC LIMIT 1"
        ));
        $name = (string) (tix_get_settings('syndication_site_name') ?: get_bloginfo('name'));
        $logo = '';
        if ($oid) {
            foreach (['_tix_org_landing_logo_id', '_tix_org_image_id'] as $k) {
                $att = intval(get_post_meta($oid, $k, true));
                if ($att && ($u = wp_get_attachment_url($att))) { $logo = $u; break; }
            }
        }
        if ($logo === '') $logo = (string) get_site_icon_url(512);
        $g = function ($k) use ($oid) { return $oid ? (string) get_post_meta($oid, $k, true) : ''; };
        $desc = $g('_tix_org_short_desc') ?: wp_strip_all_tags($g('_tix_org_description'));
        return [
            'name'        => $name,
            'address'     => $g('_tix_org_address'),
            'city'        => $g('_tix_org_city'),
            'website'     => $g('_tix_org_website') ?: home_url('/'),
            'email'       => $g('_tix_org_email'),
            'phone'       => $g('_tix_org_phone'),
            'description' => mb_substr($desc, 0, 500),
            'logo_url'    => $logo,
        ];
    }

    /** REST-Basis der Plattform aus einer Eingabe wie „evendis.de“ oder einer vollen URL. */
    private static function platform_rest($input) {
        $input = trim((string) $input);
        if ($input === '') $input = 'https://evendis.de';
        if (!preg_match('#^https?://#i', $input)) $input = 'https://' . $input;
        $input = untrailingslashit($input);
        if (strpos($input, '/wp-json/') === false) $input .= '/wp-json/tixomat/v1';
        return esc_url_raw($input);
    }

    /** Einstellungen → „Mit Plattform verbinden“ */
    public static function handle_connect() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        if (!isset($_POST['tix_connect_nonce']) || !wp_verify_nonce($_POST['tix_connect_nonce'], 'tix_partner_connect')) wp_die('Sitzung abgelaufen.');
        $back = admin_url('admin.php?page=tix-settings');
        $platform = self::platform_rest(wp_unslash($_POST['tix_connect_platform'] ?? ''));

        $nonce = wp_generate_password(48, false);
        set_transient('tix_partner_connect_nonce', $nonce, 10 * MINUTE_IN_SECONDS);
        $terms = (string) tix_get_settings('terms_url');
        $resp = wp_remote_post($platform . '/partner/connect', [
            'timeout' => 45,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'site_url'  => home_url('/'),
                'api_base'  => rest_url('tixomat/v1'),
                'name'      => (string) (tix_get_settings('syndication_site_name') ?: get_bloginfo('name')),
                'terms_url' => $terms === '' ? '' : (preg_match('#^https?://#i', $terms) ? $terms : home_url($terms)),
                'organizer' => self::source_organizer(),
                'nonce'     => $nonce,
            ]),
        ]);
        delete_transient('tix_partner_connect_nonce');
        $code = is_wp_error($resp) ? 0 : intval(wp_remote_retrieve_response_code($resp));
        $data = is_wp_error($resp) ? [] : (array) json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || empty($data['ok']) || strlen((string) ($data['key_in'] ?? '')) < 20 || strlen((string) ($data['key_out'] ?? '')) < 20) {
            $msg = is_wp_error($resp) ? $resp->get_error_message() : (string) ($data['message'] ?? ('HTTP ' . $code));
            set_transient('tix_partner_connect_result', ['ok' => false, 'msg' => $msg], 300);
            wp_safe_redirect($back);
            exit;
        }
        $s = get_option('tix_settings', []);
        if (!is_array($s)) $s = [];
        $s['syndication_enabled'] = 1;
        $s['syndication_api_url'] = esc_url_raw((string) ($data['platform'] ?? $platform));
        $s['syndication_api_key'] = sanitize_text_field((string) $data['key_in']);
        if (empty($s['syndication_site_name'])) $s['syndication_site_name'] = sanitize_text_field((string) $data['name']);
        $s['partner_api_enabled'] = 1;
        $s['partner_api_key']     = sanitize_text_field((string) $data['key_out']);
        update_option('tix_settings', $s);
        // Bereits markierte Events (Häkchen „Auf Plattform veröffentlichen“) gleich verteilen
        wp_schedule_single_event(time() + 5, 'tix_syndication_push_all');
        set_transient('tix_partner_connect_result', [
            'ok'  => true,
            'msg' => sprintf('Verbunden mit %s als „%s“. %s Events mit Häkchen „Auf Plattform veröffentlichen“ werden jetzt verteilt.',
                self::host($platform), (string) $data['name'],
                !empty($data['sales']) ? 'Ticketverkauf über die App ist aktiv.' : 'Ticketverkauf über die App startet nach Freigabe durch den Betreiber; bis dahin werden die Events angezeigt.'),
        ], 300);
        wp_safe_redirect($back);
        exit;
    }

    public static function connect_notice() {
        if (!current_user_can('manage_options')) return;
        $r = get_transient('tix_partner_connect_result');
        if (is_array($r)) {
            delete_transient('tix_partner_connect_result');
            printf('<div class="notice notice-%s is-dismissible"><p><strong>Plattform-Kopplung:</strong> %s</p></div>',
                $r['ok'] ? 'success' : 'error', esc_html((string) $r['msg']));
        }
        // Plattform: gekoppelte Partner, die auf Freigabe warten
        $waiting = array_filter(self::all(), function ($p) { return !empty($p['pending']); });
        if ($waiting) {
            printf('<div class="notice notice-warning"><p><strong>Partner warten auf Freigabe:</strong> %s – <a href="%s">Tixomat → Partner</a></p></div>',
                esc_html(implode(', ', array_column($waiting, 'name'))), esc_url(admin_url('admin.php?page=tix-partners')));
        }
    }

    // ──────────────────────────────────────────
    //  Admin: Tixomat → Partner
    // ──────────────────────────────────────────

    public static function admin_menu() {
        add_submenu_page('tixomat', 'Partner (geteilte Events)', 'Partner', 'manage_options', 'tix-partners', [__CLASS__, 'render_page']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        $all  = self::all();
        $edit = isset($_GET['edit']) ? self::get(sanitize_key($_GET['edit'])) : null;
        $new  = isset($_GET['new']);
        $orgs = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']);
        $msg  = sanitize_key($_GET['msg'] ?? '');
        $receive_on = !empty(tix_get_settings('syndication_receive_enabled'));
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Partner (geteilte Events)</h1>
            <?php if (!$edit && !$new): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=tix-partners&new=1')); ?>" class="page-title-action">Partner anlegen</a>
            <?php endif; ?>
            <hr class="wp-header-end">
            <?php if ($msg === 'saved'): ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <?php if ($msg === 'approved'): ?><div class="notice notice-success is-dismissible"><p>Freigegeben – Tickets dieses Partners sind jetzt in der App kaufbar.</p></div><?php endif; ?>
            <?php if ($msg === 'deleted'): ?><div class="notice notice-success is-dismissible"><p>Partner entfernt.</p></div><?php endif; ?>
            <?php if ($msg === 'invalid'): ?><div class="notice notice-error"><p>Bitte Kennung, Namen und API-Basis angeben.</p></div><?php endif; ?>
            <?php if (!$receive_on): ?>
                <div class="notice notice-warning"><p>Der Empfang verteilter Events ist in den Einstellungen (Event-Verteilung → Empfang) ausgeschaltet.</p></div>
            <?php endif; ?>

            <p style="max-width:860px;">Quellseiten (andere Tixomat-Installationen) teilen Events mit dieser Seite. Jede Quelle meldet sich mit ihrem eigenen Schlüssel.
            Ist „Verkauf über diese Seite“ an, verkauft die App Tickets für geteilte Events dieser Quelle: Bestellung und Zahlung laufen beim Veranstalter (Quelle),
            die Tickets werden hier gespiegelt und erscheinen unter „Meine Tickets“. Der Einlass erfolgt bei der Quelle.</p>

            <?php if ($edit || $new):
                $p = $edit ?: wp_parse_args(['key_in' => self::generate_key('tix_pin_'), 'key_out' => self::generate_key('tix_pout_')], self::defaults());
                ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:860px;">
                    <input type="hidden" name="action" value="tix_partner_save">
                    <input type="hidden" name="is_new" value="<?php echo $edit ? '0' : '1'; ?>">
                    <?php wp_nonce_field('tix_partner_save'); ?>
                    <table class="form-table" role="presentation">
                        <tr><th><label for="tpid">Kennung</label></th><td>
                            <input id="tpid" name="id" type="text" class="regular-text" value="<?php echo esc_attr($p['id']); ?>" <?php echo $edit ? 'readonly' : 'required'; ?> pattern="[a-z0-9_\-]+" placeholder="kitchenklub">
                            <p class="description">Kleinbuchstaben, Ziffern, - und _. Nicht änderbar.</p></td></tr>
                        <tr><th><label for="tpname">Anzeigename</label></th><td>
                            <input id="tpname" name="name" type="text" class="regular-text" value="<?php echo esc_attr($p['name']); ?>" required placeholder="KitchenKlub">
                            <p class="description">Wird in der App angezeigt („Tickets über …“).</p></td></tr>
                        <tr><th><label for="tpapi">API-Basis der Quelle</label></th><td>
                            <input id="tpapi" name="api_base" type="url" class="regular-text" style="width:100%;" value="<?php echo esc_attr($p['api_base']); ?>" required placeholder="https://kitchenklub.de/wp-json/tixomat/v1"></td></tr>
                        <tr><th><label for="tporg">Veranstalter hier</label></th><td>
                            <select id="tporg" name="organizer_id">
                                <option value="0">– keiner –</option>
                                <?php foreach ($orgs as $o): ?>
                                    <option value="<?php echo intval($o->ID); ?>" <?php selected(intval($p['organizer_id']), intval($o->ID)); ?>><?php echo esc_html($o->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Geteilte Events dieser Quelle werden diesem Veranstalter zugeordnet.</p></td></tr>
                        <tr><th>Verkauf über diese Seite</th><td>
                            <label><input type="checkbox" name="sales_enabled" value="1" <?php checked(!empty($p['sales_enabled'])); ?>> Tickets für geteilte Events dieser Quelle in der App verkaufen (Zahlung beim Veranstalter)</label></td></tr>
                        <tr><th><label for="tpterms">AGB des Veranstalters</label></th><td>
                            <input id="tpterms" name="terms_url" type="url" class="regular-text" style="width:100%;" value="<?php echo esc_attr($p['terms_url']); ?>" placeholder="https://kitchenklub.de/agb">
                            <p class="description">Optional; sonst wird der AGB-Link aus dem Angebot der Quelle genutzt.</p></td></tr>
                        <tr><th>Schlüssel für die Quelle</th><td>
                            <p><strong>API Key</strong> (Quelle → hier, bei der Quelle unter Event-Verteilung → Senden eintragen):</p>
                            <input type="text" name="key_in" class="regular-text" style="width:100%;font-family:monospace;" value="<?php echo esc_attr($p['key_in']); ?>" readonly onclick="this.select();">
                            <p style="margin-top:12px;"><strong>Partner-Schlüssel</strong> (hier → Quelle, bei der Quelle unter „Verkauf über die Plattform“ eintragen):</p>
                            <input type="text" name="key_out" class="regular-text" style="width:100%;font-family:monospace;" value="<?php echo esc_attr($p['key_out']); ?>" readonly onclick="this.select();">
                            <?php if ($edit): ?>
                                <p><label><input type="checkbox" name="rotate" value="1"> Beide Schlüssel neu erzeugen (Quelle muss sie danach neu eintragen)</label></p>
                            <?php endif; ?>
                            <p class="description">Plattform-URL für die Quelle: <code><?php echo esc_html(rest_url('tixomat/v1')); ?></code></p>
                        </td></tr>
                    </table>
                    <?php submit_button($edit ? 'Speichern' : 'Partner anlegen'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tix-partners')); ?>">Zurück</a>
                </form>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1100px;">
                    <thead><tr><th>Kennung</th><th>Name</th><th>API-Basis</th><th>Veranstalter</th><th>Verkauf</th><th>Geteilte Events</th><th></th></tr></thead>
                    <tbody>
                    <?php if (empty($all)): ?>
                        <tr><td colspan="7">Noch keine Partner. Geteilte Events werden bis dahin nur angezeigt; Tickets gibt es bei der Quelle.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($all as $p):
                        global $wpdb;
                        $count = intval($wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tix_source_partner' AND meta_value = %s", $p['id']
                        )));
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($p['id']); ?></code><?php echo !empty($p['auto']) ? '<br><small>gekoppelt' . (!empty($p['connected']) ? ' ' . esc_html(date_i18n('d.m.Y', strtotime($p['connected']))) : '') . '</small>' : ''; ?></td>
                            <td><?php echo esc_html($p['name']); ?></td>
                            <td><?php echo esc_html($p['api_base']); ?></td>
                            <td><?php echo $p['organizer_id'] ? esc_html(get_the_title($p['organizer_id'])) : '–'; ?></td>
                            <td><?php if (!empty($p['pending'])): ?>
                                    <a class="button button-primary button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_partner_approve&id=' . rawurlencode($p['id'])), 'tix_partner_approve')); ?>">Freigeben</a>
                                <?php else: echo self::sales_ready($p) ? '✓ an' : 'aus'; endif; ?></td>
                            <td><?php echo $count; ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=tix-partners&edit=' . rawurlencode($p['id']))); ?>">Bearbeiten</a> ·
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_partner_delete&id=' . rawurlencode($p['id'])), 'tix_partner_delete')); ?>" onclick="return confirm('Partner entfernen? Geteilte Events bleiben, die Quelle kann aber nichts mehr senden.');">Entfernen</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_partner_save');
        $id   = sanitize_key(wp_unslash($_POST['id'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $api  = esc_url_raw(trim(wp_unslash($_POST['api_base'] ?? '')));
        if ($id === '' || $name === '' || $api === '' || stripos($api, 'https://') !== 0) {
            wp_safe_redirect(admin_url('admin.php?page=tix-partners&msg=invalid' . ($id !== '' && empty($_POST['is_new']) ? '&edit=' . $id : '&new=1')));
            exit;
        }
        $all = self::all();
        $old = $all[$id] ?? null;
        $is_new = !empty($_POST['is_new']);
        if ($is_new && $old) {
            wp_safe_redirect(admin_url('admin.php?page=tix-partners&msg=invalid&new=1'));
            exit;
        }
        $key_in  = sanitize_text_field(wp_unslash($_POST['key_in'] ?? ''));
        $key_out = sanitize_text_field(wp_unslash($_POST['key_out'] ?? ''));
        if ($old) {
            // Schlüssel nur über „neu erzeugen“ ändern, nie aus dem Formular übernehmen
            $key_in  = $old['key_in'];
            $key_out = $old['key_out'];
        }
        if (!empty($_POST['rotate']) || strlen($key_in) < 20)  $key_in  = self::generate_key('tix_pin_');
        if (!empty($_POST['rotate']) || strlen($key_out) < 20) $key_out = self::generate_key('tix_pout_');
        $all[$id] = [
            'id'            => $id,
            'name'          => $name,
            'api_base'      => untrailingslashit($api),
            'key_in'        => $key_in,
            'key_out'       => $key_out,
            'organizer_id'  => intval($_POST['organizer_id'] ?? 0),
            'sales_enabled' => !empty($_POST['sales_enabled']) ? 1 : 0,
            'terms_url'     => esc_url_raw(trim(wp_unslash($_POST['terms_url'] ?? ''))),
            'created'       => $old['created'] ?? current_time('mysql'),
        ];
        if (!empty($old['auto'])) {
            $all[$id]['auto'] = 1;
            $all[$id]['connected'] = $old['connected'] ?? '';
        }
        // Speichern mit „Verkauf“ an gilt als Freigabe
        $all[$id]['pending'] = (!empty($old['pending']) && empty($all[$id]['sales_enabled'])) ? 1 : 0;
        update_option(self::OPTION, $all, false);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        wp_safe_redirect(admin_url('admin.php?page=tix-partners&msg=saved&edit=' . $id));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_partner_delete');
        $id  = sanitize_key($_GET['id'] ?? '');
        $all = self::all();
        unset($all[$id]);
        update_option(self::OPTION, $all, false);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        wp_safe_redirect(admin_url('admin.php?page=tix-partners&msg=deleted'));
        exit;
    }
}
