<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Studio – Anbindung des Veranstalter-Dashboards „evendis Studio“ (Next.js, z. B. veranstalter.evendis.de/studio/).
 *
 * Alles hängt an der Option `tix_studio_url` (Basis-Adresse ohne Schrägstrich am Ende). Leer = altes Verhalten.
 *
 * - Einmal-Code (60 s, einmal nutzbar, an den Nutzer gebunden) nach dem WordPress-Login eines Veranstalters bzw.
 *   Team-Kontos und nach der Registrierung auf /veranstalter-werden/ → Weiterleitung auf <studio>/anmelden/?code=…
 * - POST /auth/handoff {code, device} → {success, token, user} wie /auth/login
 * - Experten-Modus (klassische Veranstalter-Seite in wp-admin): feste Rückweg-Leiste „← Zurück zur einfachen Ansicht“
 * - GET /organizer/tasks: offene Aufgaben des Veranstalters für die Studio-Startseite
 * - GET/POST /studio/config (1.38.445): Name, Akzentfarbe, Logo, Profil und Funktionen je Seite (Studio auf allen Seiten);
 *   ändern lassen sich nur `accent` und `logo` (Option `tix_studio_brand`)
 */
class TIX_Studio {

    const NS          = 'tixomat/v1';
    const OPTION      = 'tix_studio_url';
    const CODE_PREFIX = 'tix_sho_';
    const CODE_TTL    = 60;
    const BRAND       = 'tix_studio_brand';
    const LOGO_MAX    = 2097152;

    public static function init() {
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 99, 3);
        add_filter('allowed_redirect_hosts', [__CLASS__, 'allowed_hosts']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_post_tix_studio_open', [__CLASS__, 'open']);
        // Platzhalterbild für Events ohne Bild (1.38.458): nur auf der Website, nie in REST-Antworten
        add_filter('get_post_metadata', [__CLASS__, 'placeholder_thumbnail'], 10, 4);
        // Experten-Modus: Rückweg-Leiste + evendis-Look
        add_filter('admin_body_class', [__CLASS__, 'body_class']);
        add_action('admin_head', [__CLASS__, 'bar_styles']);
        add_action('in_admin_header', [__CLASS__, 'render_bar'], 0);
        add_action('admin_menu', [__CLASS__, 'cleanup_menu'], 1000);
        add_action('admin_bar_menu', [__CLASS__, 'admin_bar_node'], 90);
    }

    // ──────────────────────────────────────────
    //  Grundlagen
    // ──────────────────────────────────────────

    /** Basis-Adresse des Studios ('' = aus). */
    public static function base() {
        $u = trim((string) get_option(self::OPTION, ''));
        if ($u === '' || !preg_match('#^https?://[^/\s]+#i', $u)) return '';
        return untrailingslashit(esc_url_raw($u));
    }

    public static function active() {
        return self::base() !== '';
    }

    /** Studio-Adresse für einen Pfad (z. B. '/events/12/'). */
    public static function url($path = '/') {
        return self::base() . '/' . ltrim((string) $path, '/');
    }

    /** Veranstalter bzw. Team-Konto (kein Admin)? */
    public static function is_studio_user($user = null) {
        $user = $user ?: wp_get_current_user();
        if (!$user || !$user->ID) return false;
        if (user_can($user, 'manage_options')) return false;
        if (in_array('tix_organizer', (array) $user->roles, true)) return true;
        return class_exists('TIX_App_Scope') && (bool) TIX_App_Scope::organizer_post_for_user($user->ID);
    }

    // ──────────────────────────────────────────
    //  Einmal-Code
    // ──────────────────────────────────────────

    /** Code für den Nutzer erzeugen (Klartext nur hier; gespeichert wird der SHA-256). */
    public static function issue_code($user_id, $next = '') {
        global $wpdb;
        $user_id = intval($user_id);
        if (!$user_id) return '';
        $now = time();
        // Abgelaufene Codes wegräumen (Wert = "<uid>:<ablauf>[:<next>]")
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, ':', 2), ':', -1) AS UNSIGNED) < %d",
            $wpdb->esc_like(self::CODE_PREFIX) . '%', $now
        ));
        $code = wp_generate_password(40, false, false);
        $val  = $user_id . ':' . ($now + self::CODE_TTL) . ($next !== '' ? ':' . $next : '');
        $wpdb->insert($wpdb->options, ['option_name' => self::CODE_PREFIX . hash('sha256', $code), 'option_value' => $val, 'autoload' => 'no']);
        return $code;
    }

    /** Code einlösen: liefert ['uid' => …, 'next' => …] oder null. Atomar – nur ein Aufruf gewinnt. */
    public static function consume_code($code) {
        global $wpdb;
        $code = trim((string) $code);
        if ($code === '' || !preg_match('/^[A-Za-z0-9]{20,80}$/', $code)) return null;
        $name = self::CODE_PREFIX . hash('sha256', $code);
        $val  = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($val === null) return null;
        $gone = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name));
        if (intval($gone) !== 1) return null;
        $parts = explode(':', (string) $val, 3);
        $uid = intval($parts[0] ?? 0);
        $exp = intval($parts[1] ?? 0);
        if (!$uid || $exp < time()) return null;
        return ['uid' => $uid, 'next' => (string) ($parts[2] ?? '')];
    }

    /** Erlaubter Folgepfad im Studio (nur relativ, z. B. /studio/events/12/). */
    private static function clean_next($next) {
        $next = trim((string) $next);
        if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || preg_match('/[\s\\\\]/', $next)) return '';
        $base_path = (string) wp_parse_url(self::base(), PHP_URL_PATH);
        if ($base_path !== '' && strpos($next, untrailingslashit($base_path) . '/') !== 0) return '';
        return mb_substr($next, 0, 200);
    }

    /** Anmelde-Adresse im Studio mit frischem Code. */
    public static function handoff_url($user_id, $next = '') {
        if (!self::active()) return '';
        $next = self::clean_next($next);
        $code = self::issue_code($user_id, $next);
        if ($code === '') return '';
        $args = ['code' => $code];
        if ($next !== '') $args['next'] = $next;
        return add_query_arg(array_map('rawurlencode', $args), self::url('/anmelden/'));
    }

    // ──────────────────────────────────────────
    //  Weiterleitung nach Login / Registrierung
    // ──────────────────────────────────────────

    /** Standard-Ziele (kein ausdrücklich verlangtes Ziel) – dann geht es ins Studio. */
    private static function is_default_target($requested) {
        $requested = (string) $requested;
        if ($requested === '') return true;
        $defaults = [admin_url(), admin_url('/'), admin_url('index.php'), admin_url('admin.php?page=tixomat'), admin_url('profile.php')];
        if (class_exists('TIX_Org_Approval')) $defaults[] = admin_url('admin.php?page=' . TIX_Org_Approval::ORG_SLUG);
        return in_array($requested, $defaults, true);
    }

    public static function login_redirect($redirect_to, $requested, $user) {
        if (!self::active() || !$user || is_wp_error($user) || !($user instanceof WP_User)) return $redirect_to;
        if (!self::is_studio_user($user)) return $redirect_to;
        if (!self::is_default_target($requested)) {
            // Ausdrücklich verlangte Seite (z. B. Experten-Modus-Link aus dem Studio) behalten
            $admin_host = (string) wp_parse_url(admin_url(), PHP_URL_HOST);
            if (strpos((string) $requested, admin_url()) === 0 && wp_parse_url((string) $requested, PHP_URL_HOST) === $admin_host) return $requested;
            return $redirect_to;
        }
        $url = self::handoff_url($user->ID);
        return $url !== '' ? $url : $redirect_to;
    }

    public static function allowed_hosts($hosts) {
        $h = self::active() ? (string) wp_parse_url(self::base(), PHP_URL_HOST) : '';
        if ($h !== '') $hosts[] = $h;
        return $hosts;
    }

    /** Ziel nach der Registrierung auf /veranstalter-werden/ ('' = bisheriges Ziel). */
    public static function registration_target($user_id) {
        $u = get_user_by('id', intval($user_id));
        if (!self::active() || !$u || user_can($u, 'manage_options')) return '';
        return self::handoff_url($u->ID);
    }

    /** admin-post.php?action=tix_studio_open[&next=/studio/…] – angemeldet ins Studio wechseln (auch für Admins). */
    public static function open() {
        if (!is_user_logged_in()) auth_redirect();
        if (!self::active()) {
            wp_safe_redirect(admin_url());
            exit;
        }
        $u = wp_get_current_user();
        if (!user_can($u, 'manage_options') && !self::is_studio_user($u)) {
            wp_safe_redirect(self::url('/'));
            exit;
        }
        $url = self::handoff_url($u->ID, (string) wp_unslash($_GET['next'] ?? ''));
        wp_safe_redirect($url !== '' ? $url : self::url('/'));
        exit;
    }

    // ──────────────────────────────────────────
    //  REST
    // ──────────────────────────────────────────

    public static function register_routes() {
        register_rest_route(self::NS, '/auth/handoff', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_handoff'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/studio/placeholder', [
            ['methods' => 'POST',   'callback' => [__CLASS__, 'rest_placeholder_upload'], 'permission_callback' => [__CLASS__, 'perm_brand']],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_placeholder_delete'], 'permission_callback' => [__CLASS__, 'perm_brand']],
        ]);
        register_rest_route(self::NS, '/studio/config', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'rest_config'],      'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save_config'], 'permission_callback' => [__CLASS__, 'perm_config']],
        ]);
        // Bestehende kommende Events auf evendis teilen (einmalige Aktion, 1.38.465): {dry_run: true} listet nur
        register_rest_route(self::NS, '/studio/syndicate-upcoming', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_syndicate_upcoming'], 'permission_callback' => [__CLASS__, 'perm_brand'],
        ]);
        register_rest_route(self::NS, '/organizer/tasks', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_tasks'],
            'permission_callback' => [__CLASS__, 'perm_tasks'],
        ]);
    }

    /** POST /auth/handoff {code, device} → {success, token, user[, next]} wie /auth/login. */
    public static function rest_handoff(WP_REST_Request $req) {
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('handoff', 20, 60)) {
            return new WP_Error('tix_rate_limit', 'Zu viele Versuche. Bitte warte kurz und versuche es dann erneut.', ['status' => 429]);
        }
        $hit  = self::consume_code((string) ($req->get_param('code') ?? ''));
        $user = $hit ? get_user_by('id', $hit['uid']) : null;
        if (!$user) {
            return new WP_Error('tix_handoff_invalid', 'Der Anmelde-Link ist abgelaufen oder wurde schon benutzt. Bitte melde dich erneut an.', ['status' => 401]);
        }
        $token = TIX_REST_API::issue_app_token($user->ID, (string) ($req->get_param('device') ?: 'evendis Studio'));
        $out = [
            'success' => true,
            'token'   => $token,
            'user'    => TIX_REST_API::guest_user_payload($user),
        ];
        if ($hit['next'] !== '') $out['next'] = $hit['next'];
        return rest_ensure_response($out);
    }

    // ── Studio auf allen Seiten: Marke und Funktionen (1.38.445) ──

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    private static function hex($v) {
        $v = trim((string) $v);
        if (preg_match('/^#?([0-9a-f]{3})$/i', $v, $m)) $v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        return preg_match('/^#?([0-9a-f]{6})$/i', $v, $m) ? '#' . strtoupper($m[1]) : '';
    }

    private static function brand() {
        $b = get_option(self::BRAND, []);
        return is_array($b) ? $b : [];
    }

    /** Akzent: gespeichert › Plattform evendis-Rot › Primärfarbe der Seite › #FC2F55. */
    public static function accent() {
        $b = self::brand();
        if (($h = self::hex($b['accent'] ?? '')) !== '') return $h;
        if (!self::multi() && function_exists('tix_get_settings') && ($h = self::hex(tix_get_settings('color_primary'))) !== '') return $h;
        return '#FC2F55';
    }

    /** Selbst gesetzte Marke: {accent: Hex|null, logo: URL|null}. */
    private static function custom_brand() {
        $b = self::brand();
        $id = intval($b['logo_id'] ?? 0);
        $logo = $id ? (wp_get_attachment_image_url($id, 'medium') ?: wp_get_attachment_url($id)) : '';
        $acc = self::hex($b['accent'] ?? '');
        return ['accent' => $acc !== '' ? $acc : null, 'logo' => $logo ? (string) $logo : null, 'placeholder' => self::placeholder() !== null];
    }

    /** Platzhalterbild der Seite für Events ohne Bild: {id, url (large), url_small (medium)} | null. */
    public static function placeholder() {
        $id = intval(self::brand()['placeholder_id'] ?? 0);
        if (!$id || get_post_type($id) !== 'attachment') return null;
        $url = (string) (wp_get_attachment_image_url($id, 'large') ?: wp_get_attachment_url($id));
        if ($url === '') return null;
        return ['id' => $id, 'url' => $url, 'url_small' => (string) (wp_get_attachment_image_url($id, 'medium') ?: $url)];
    }

    // ── Mail-Marke (1.38.473): Kopf, Logos, Absender – Felder der Studio-Marke ──
    const MAIL_KEYS = ['mail_header', 'mail_header_color', 'mail_logo_id', 'mail_logo_on_color_id', 'mail_from', 'mail_from_name', 'mail_reply_to'];

    /** Gespeicherte Mail-Felder (nur gesetzte). */
    public static function mail_settings() {
        return array_intersect_key(self::brand(), array_flip(self::MAIL_KEYS));
    }

    /** Mail-Teil für /studio/config: wirksame Werte + was selbst gesetzt ist. */
    private static function mail_payload() {
        if (!class_exists('TIX_Mail_Layout')) return null;
        $m = TIX_Mail_Layout::brand();
        $o = self::mail_settings();
        return [
            'header' => (string) ($m['header_mode'] ?? ($m['header'] === 'accent' ? 'brand' : 'light')),
            'header_set' => isset($o['mail_header']) ? (string) $o['mail_header'] : null,
            // 1.38.479: eigene Kopf-Farbe (gespeichert bzw. null) und wirksame Kopf-Farbe
            'header_color' => isset($o['mail_header_color']) ? (string) $o['mail_header_color'] : null,
            'head_color' => $m['header'] === 'accent' ? (string) ($m['head_bg'] ?? $m['accent']) : null,
            'logo' => $m['logo'] !== '' ? $m['logo'] : null,
            'logo_custom' => !empty($o['mail_logo_id']),
            'logo_on_color' => $m['logo_on_accent'] !== '' ? $m['logo_on_accent'] : null,
            'from' => $m['from_email'], 'from_name' => $m['from_name'], 'reply_to' => $m['support_email'] !== '' ? $m['support_email'] : null,
            'custom' => ['from' => $o['mail_from'] ?? null, 'from_name' => $o['mail_from_name'] ?? null, 'reply_to' => $o['mail_reply_to'] ?? null],
        ];
    }

    /** Mail-Felder prüfen und in $b übernehmen ($in: Feld → Wert, null/'' = zurück auf Vorgabe). WP_Error oder neues $b. */
    public static function apply_mail_fields(array $b, array $in) {
        foreach ($in as $k => $v) {
            $empty = $v === null || $v === '' || $v === 0 || $v === '0';
            switch ($k) {
                case 'mail_header':
                    if ($empty) { unset($b[$k]); break; }
                    if (!in_array($v, ['light', 'brand', 'color'], true)) return new WP_Error('tix_invalid_field', 'Kopf: light, brand oder color.', ['status' => 400, 'field' => $k]);
                    $b[$k] = $v; break;
                case 'mail_header_color':
                    if ($empty) { unset($b[$k]); break; }
                    $h = self::hex($v);
                    if ($h === '') return new WP_Error('tix_invalid_field', 'Bitte die Kopf-Farbe als Hex-Wert angeben, z. B. #1A1A2E.', ['status' => 400, 'field' => $k]);
                    $b[$k] = $h; break;
                case 'mail_logo_id': case 'mail_logo_on_color_id':
                    if ($empty) { unset($b[$k]); break; }
                    $ok = self::check_logo_attachment(intval($v));
                    if (is_wp_error($ok)) return new WP_Error($ok->get_error_code(), $ok->get_error_message(), ['status' => 400, 'field' => $k]);
                    $b[$k] = intval($v); break;
                case 'mail_from': case 'mail_reply_to':
                    if ($empty) { unset($b[$k]); break; }
                    $e = sanitize_email((string) $v);
                    if (!is_email($e)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse angeben.', ['status' => 400, 'field' => $k]);
                    $b[$k] = $e; break;
                case 'mail_from_name':
                    if ($empty) { unset($b[$k]); break; }
                    $b[$k] = mb_substr(trim(str_replace(['"', '<', '>', "\r", "\n"], '', sanitize_text_field((string) $v))), 0, 80);
                    if ($b[$k] === '') unset($b[$k]);
                    break;
            }
        }
        return $b;
    }

    /**
     * Platzhalter eines Events (1.38.465): geteiltes Event → Platzhalter der Ursprungsseite (`_tix_source_placeholder_id`),
     * Mehr-Veranstalter-Modus → Platzhalter des Veranstalters (`_tix_org_placeholder_id`), sonst der Seite. 0 = keiner.
     */
    public static function event_placeholder_id($event_id) {
        $ok = function ($id) { $id = intval($id); return ($id && get_post_type($id) === 'attachment') ? $id : 0; };
        if ($id = $ok(get_post_meta($event_id, '_tix_source_placeholder_id', true))) return $id;
        if (self::multi() && ($oid = intval(get_post_meta($event_id, '_tix_organizer_id', true))) && ($id = $ok(get_post_meta($oid, '_tix_org_placeholder_id', true)))) return $id;
        return $ok(self::brand()['placeholder_id'] ?? 0);
    }

    /** {id, url, url_small} des Event-Platzhalters oder null. */
    public static function event_placeholder($event_id) {
        $id = self::event_placeholder_id($event_id);
        if (!$id) return null;
        $url = (string) (wp_get_attachment_image_url($id, 'large') ?: wp_get_attachment_url($id));
        return $url === '' ? null : ['id' => $id, 'url' => $url, 'url_small' => (string) (wp_get_attachment_image_url($id, 'medium') ?: $url)];
    }

    /**
     * Website: Event ohne Beitragsbild → Platzhalter als Beitragsbild (Theme, Breakdance, Plugin-Vorlagen).
     * Nicht in REST, Verwaltung, Cron und WP-CLI – die Apps erkennen „ohne Bild“ an einem leeren `image`.
     * Seit 1.38.465 je Event (Ursprungsseite bzw. Veranstalter, sonst Seite).
     */
    public static function placeholder_thumbnail($value, $object_id, $meta_key, $single) {
        if ($meta_key !== '_thumbnail_id' || $value !== null) return $value;
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return $value;
        if (get_post_type($object_id) !== 'event') return $value;
        remove_filter('get_post_metadata', [__CLASS__, 'placeholder_thumbnail'], 10);
        $own = get_post_meta($object_id, '_thumbnail_id', true);
        $pid = intval($own) > 0 ? 0 : self::event_placeholder_id($object_id);
        add_filter('get_post_metadata', [__CLASS__, 'placeholder_thumbnail'], 10, 4);
        if (!$pid) return $value;
        return $single ? (string) $pid : [(string) $pid];
    }

    /** POST /studio/placeholder (multipart `image` bzw. `file`, JPG/PNG/WebP ≤ 5 MB; empfohlen 16:9, 1920×1080) → config */
    public static function rest_placeholder_upload(WP_REST_Request $req) {
        $files = $req->get_file_params();
        if (intval($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$files && !$req->get_body_params()) return new WP_Error('tix_too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => 'image']);
        $aid = self::store_placeholder($files['image'] ?? ($files['file'] ?? ($files['placeholder'] ?? null)), 'image');
        if (is_wp_error($aid)) return $aid;
        $b = self::brand();
        $b['placeholder_id'] = intval($aid);
        update_option(self::BRAND, $b, false);
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
        return rest_ensure_response(self::config_payload());
    }

    /** Platzhalter-Datei prüfen und speichern → Anhang-ID oder WP_Error (Feld $field). */
    private static function store_placeholder($file, $field) {
        if (!empty($file['error']) && intval($file['error']) !== UPLOAD_ERR_NO_FILE) return new WP_Error('upload_failed', 'Upload fehlgeschlagen (höchstens 5 MB).', ['status' => in_array(intval($file['error']), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400, 'field' => $field]);
        if (empty($file['tmp_name'])) return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400, 'field' => $field]);
        if (intval($file['size'] ?? 0) > 5 * MB_IN_BYTES) return new WP_Error('tix_too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => $field]);
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return new WP_Error('bad_type', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.', ['status' => 400, 'field' => $field]);
        if ($info[0] < 200 || $info[1] < 100 || $info[0] > 8000 || $info[1] > 8000) return new WP_Error('bad_size', 'Das Bild muss mindestens 200×100 und höchstens 8000 Pixel groß sein (empfohlen 1920×1080).', ['status' => 400, 'field' => $field]);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $aid = media_handle_sideload(['name' => 'event-platzhalter-' . time() . '.' . $ext, 'tmp_name' => $file['tmp_name'], 'type' => $mime, 'size' => $file['size'] ?? 0], 0, 'Event-Platzhalter');
        if (is_wp_error($aid)) return new WP_Error('upload_failed', $aid->get_error_message(), ['status' => 500]);
        return intval($aid);
    }

    /** DELETE /studio/placeholder → zurück auf den Standard (kein eigenes Bild). */
    public static function rest_placeholder_delete(WP_REST_Request $req) {
        $b = self::brand();
        unset($b['placeholder_id']);
        update_option(self::BRAND, $b, false);
        return rest_ensure_response(self::config_payload());
    }

    /** Logo: gespeichert › Custom Logo des Themes › Logo der Verwaltung/Anmeldung › Website-Icon. */
    public static function logo() {
        $id = intval(self::brand()['logo_id'] ?? 0);
        if ($id && ($u = wp_get_attachment_image_url($id, 'medium') ?: wp_get_attachment_url($id))) return (string) $u;
        $cl = intval(get_theme_mod('custom_logo'));
        if ($cl && ($u = wp_get_attachment_image_url($cl, 'medium'))) return (string) $u;
        if (class_exists('TIX_Admin_Only') && method_exists('TIX_Admin_Only', 'login_logo') && ($u = TIX_Admin_Only::login_logo()) !== '') return $u;
        return (string) get_site_icon_url(256);
    }

    private static function config_payload() {
        $multi = self::multi();
        $wallet = class_exists('TIX_Wallet_Balance') && method_exists('TIX_Wallet_Balance', 'settings') ? !empty(TIX_Wallet_Balance::settings()['enabled']) : false;
        return [
            'ok'         => true,
            'name'       => html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8') . ' Studio',
            'accent'     => self::accent(),
            'logo'       => self::logo(),
            // Im Studio gesetzte Werte (null = nur Rückfall, z. B. Website-Icon) – 1.38.446
            'custom'     => self::custom_brand(),
            // 1.38.458: Marke gesammelt, mit Platzhalterbild für Events ohne Bild ({url, url_small} | null)
            'brand'      => ['accent' => self::accent(), 'logo' => self::logo(), 'placeholder' => (function () { $p = self::placeholder(); return $p ? ['url' => $p['url'], 'url_small' => $p['url_small']] : null; })(),
                // 1.38.473: Mail-Marke (Kopf light|brand, Logos, Absender, Antworten an)
                'mail' => self::mail_payload()],
            // 1.38.481: Bestellnummern (legacy = bisheriges Format, short = KK-7HQ4M2)
            'order_number' => class_exists('TIX_Order_Number') ? ['mode' => TIX_Order_Number::mode(), 'code' => TIX_Order_Number::code(), 'example' => TIX_Order_Number::make()] : null,
            // Rechnungen (1.38.487): Menüpunkt/Hinweis im Studio; Details unter GET /studio/invoice-config
            'invoices' => class_exists('TIX_Invoices') ? ['enabled' => TIX_Invoices::enabled(), 'model' => TIX_Invoices::multi() ? TIX_Invoices::model() : null] : null,
            // Auto-Archiv (1.38.492): {enabled, grace_hours, skip_drafts, interval, next_run} – ändern nur Inhaber/Admin (auf evendis Admin)
            'archive' => class_exists('TIX_Archive') && method_exists('TIX_Archive', 'payload') ? TIX_Archive::payload(self::archive_scope_org()) : null,
            // App-Funktionen an/aus (1.38.498): {scope, organizer_id, features {<key>: {key, label, enabled, status, can_edit, scope, available, hint}}, order[]} (awareness zusätzlich report/alarm)
            'app_features' => class_exists('TIX_Features') ? TIX_Features::payload() : null,
            'profile'    => $multi ? 'platform' : 'single',
            // Demo Club (Vertrieb, 1.38.508): Studio des Aufrufers gehört zum Demo-Veranstalter
            'demo'       => $multi && class_exists('TIX_Sales') && TIX_Sales::is_demo_organizer(intval(TIX_App_Scope::organizer_id_for_user())),
            'features'   => [
                'register'  => $multi && class_exists('TIX_Register_API'),
                'money'     => $multi && class_exists('TIX_Payout_Details'),
                'approval'  => class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi(),
                'wallet'    => $multi ? class_exists('TIX_Wallet_Balance') : $wallet,
                'awareness' => class_exists('TIX_Awareness'),
                'team'      => class_exists('TIX_Team'),
                'pos'       => true,
                'coupons'   => class_exists('TIX_Coupons'),
                'tasks'     => $multi,
            ],
            // Eigene App der Seite (1.38.454): KitchenKlub/Mallorca ja, evendis nein (die evendis-App ist die Plattform-App)
            'app'        => self::app_info(),
            // Verteilung auf evendis (1.38.465): Vorgabe „alle Events teilen“ (null = nicht eingestellt, wie bisher)
            'syndication' => self::syndication_info(),
            'site_url'   => (class_exists('TIX_Customer_URL') ? TIX_Customer_URL::base() : '') ?: untrailingslashit(home_url()),
            'expert_url' => admin_url('admin.php?page=tixomat'),
            'studio_url' => self::active() ? self::url('/') : '',
        ];
    }

    /** {available, default (true|false|null), default_partner_sales, partner_sales_available} */
    private static function syndication_info() {
        $avail = class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::is_configured() && !self::multi();
        return [
            'available' => $avail,
            'default' => $avail ? TIX_Syndication_Push::default_setting() : null,
            'default_partner_sales' => class_exists('TIX_Syndication_Push') ? TIX_Syndication_Push::default_partner_sales() : true,
            'partner_sales_available' => class_exists('TIX_Partners') && method_exists('TIX_Partners', 'source_api_enabled') && TIX_Partners::source_api_enabled(),
        ];
    }

    /** POST /studio/syndicate-upcoming {dry_run = true} → {ok, dry_run, count, events [{id, title, date_start}]} */
    public static function rest_syndicate_upcoming(WP_REST_Request $req) {
        if (!(class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::is_configured()) || self::multi()) {
            return new WP_Error('tix_syndication_unavailable', 'Die Verteilung auf evendis ist hier nicht eingerichtet.', ['status' => 409]);
        }
        $dry = $req->get_param('dry_run');
        $dry = ($dry === null || $dry === '') ? true : filter_var($dry, FILTER_VALIDATE_BOOLEAN);
        return rest_ensure_response(['ok' => true, 'dry_run' => $dry] + TIX_Syndication_Push::share_upcoming($dry));
    }

    const HAS_APP = 'tix_studio_has_app';
    const APP_LAYOUT = 'tix_app_layout';

    /**
     * Profil der eigenen App (1.38.455): club | festival | festivalTour (1.38.467, Mallorca). Admin-Option tix_app_layout › `layout` bzw. `profile` in den App-Inhalten
     * (_tix_app_config) › „festival“ im Seitennamen bzw. in der Adresse (z. B. mallorca-festival-xxl.de) › club.
     */
    const LAYOUTS = ['club', 'festival', 'festivalTour'];

    public static function app_layout() {
        $o = (string) get_option(self::APP_LAYOUT, '');
        if (in_array($o, self::LAYOUTS, true)) return $o;
        $c = class_exists('TIX_App_Config') ? TIX_App_Config::stored() : [];
        foreach (['layout', 'profile'] as $k) if (isset($c[$k]) && in_array($c[$k], self::LAYOUTS, true)) return $c[$k];
        $hay = strtolower(get_bloginfo('name') . ' ' . (string) wp_parse_url(home_url(), PHP_URL_HOST) . ' ' . (class_exists('TIX_Customer_URL') ? TIX_Customer_URL::host() : ''));
        return strpos($hay, 'festival') !== false ? 'festival' : 'club';
    }

    /**
     * {has_app, name, ios_url, android_url, icon}. has_app: Admin-Schalter (Option tix_studio_has_app '1'|'0'), sonst automatisch:
     * Einzel-Veranstalter-Seite mit App-IDs (Universal Links), Store-Link oder eingerichtetem Push.
     */
    public static function app_info() {
        $o = (string) get_option(self::HAS_APP, '');
        if ($o === '1' || $o === '0') {
            $has = $o === '1';
        } else {
            $has = !self::multi() && (
                (class_exists('TIX_App_Links') && TIX_App_Links::ids())
                || (class_exists('TIX_App_Coupons') && (TIX_App_Coupons::store_url() || TIX_App_Coupons::play_url()))
                || (class_exists('TIX_Notifications') && TIX_Notifications::apns_configured()));
        }
        $icon = (string) get_site_icon_url(512);
        return [
            'has_app'     => (bool) $has,
            'override'    => $o === '' ? null : $o === '1',
            'layout'      => self::app_layout(),
            'layout_override' => in_array((string) get_option(self::APP_LAYOUT, ''), self::LAYOUTS, true),
            'name'        => class_exists('TIX_App_Coupons') ? TIX_App_Coupons::app_name() : html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8'),
            'ios_url'     => class_exists('TIX_App_Coupons') ? TIX_App_Coupons::store_url() : null,
            'android_url' => class_exists('TIX_App_Coupons') ? TIX_App_Coupons::play_url() : null,
            'icon'        => $icon !== '' ? $icon : (self::logo() ?: null),
        ];
    }

    /** GET /studio/config – öffentlich, 60 s cachebar. */
    public static function rest_config(WP_REST_Request $req) {
        $r = new WP_REST_Response(self::config_payload());
        $r->header('Cache-Control', 'public, max-age=60');
        return $r;
    }

    /** Ändern: Admin; auf Ein-Veranstalter-Seiten auch der Inhaber (Rolle Veranstalter). */
    public static function perm_brand(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options')) return true;
        if (!self::multi() && in_array('tix_organizer', (array) $u->roles, true)) return true;
        return new WP_Error('rest_forbidden', 'Nur der Inhaber der Seite kann Farbe und Logo ändern.', ['status' => 403]);
    }

    /**
     * POST /studio/config: wie perm_brand; auf evendis zusätzlich Inhaber/Team-Admin eines Veranstalters – die dürfen dort nur
     * das Auto-Archiv ihrer eigenen Events setzen (1.38.493, User-Entscheid „Jeder Veranstalter für seine Events“).
     */
    public static function perm_config(WP_REST_Request $req) {
        $r = self::perm_brand($req);
        if ($r === true) return true;
        if (self::multi() && is_user_logged_in() && class_exists('TIX_App_Scope') && TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user()) return true;
        return $r;
    }

    /** Veranstalter, dessen Archiv-Einstellung der Aufrufer sieht bzw. setzt (evendis, kein Admin), sonst 0 = Seite/Plattform. */
    private static function archive_scope_org() {
        if (!self::multi() || !is_user_logged_in() || current_user_can('manage_options') || !class_exists('TIX_App_Scope')) return 0;
        return TIX_App_Scope::is_org_manager() ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
    }

    /** POST /studio/config {accent?, logo (Datei) | logo_id?} – nur Akzentfarbe und Logo; null/"" setzt auf die Vorgabe zurück. */
    public static function rest_save_config(WP_REST_Request $req) {
        // Über post_max_size: PHP verwirft den ganzen Inhalt – klar melden statt still nichts zu ändern
        if (intval($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$req->get_file_params() && !$req->get_body_params() && !$req->get_json_params()) {
            return new WP_Error('tix_too_large', 'Die Datei ist zu groß (höchstens 2 MB).', ['status' => 413, 'field' => 'logo']);
        }
        $files = $req->get_file_params();
        if (!empty($files['logo']['error']) && intval($files['logo']['error']) !== UPLOAD_ERR_OK) {
            return new WP_Error('tix_too_large', 'Das Logo konnte nicht hochgeladen werden (höchstens 2 MB).', ['status' => in_array(intval($files['logo']['error']), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400, 'field' => 'logo']);
        }
        $b = self::brand();
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : $req->get_body_params();
        $has = function ($k) use ($p, $req) { return is_array($p) && array_key_exists($k, $p) || $req->get_param($k) !== null; };
        // Auto-Archiv (1.38.492/493): {archive: {…}} oder flach (Multipart) archive_enabled / archive_grace_hours / archive_skip_drafts
        $archive_in = $has('archive') ? $req->get_param('archive') : (class_exists('TIX_Archive') && method_exists('TIX_Archive', 'from_flat') ? TIX_Archive::from_flat(function ($k) use ($req) { return $req->get_param($k); }) : null);
        $archive_org = self::archive_scope_org();
        // App-Funktionen an/aus (1.38.498): {app_features: {<key>: bool}} (Multipart: JSON-Text)
        $features_in = $has('app_features') ? $req->get_param('app_features') : null;
        if (is_string($features_in)) { $dec = json_decode($features_in, true); $features_in = is_array($dec) ? $dec : $features_in; }
        // Multipart feature_<key>=1|0
        $flat_features = class_exists('TIX_Features') ? TIX_Features::from_flat((array) $p) : [];
        if ($flat_features) $features_in = array_merge(is_array($features_in) ? $features_in : [], $flat_features);
        $feature_params = array_map(function ($k) { return 'feature_' . $k; }, array_keys($flat_features));
        if (self::multi() && !current_user_can('manage_options')) {
            // Veranstalter auf evendis: Auto-Archiv der eigenen Events und die eigenen Module
            $other = array_diff(array_keys((array) $p), array_merge(['archive', 'archive_enabled', 'archive_grace_hours', 'archive_skip_drafts', 'app_features'], $feature_params));
            if ($other || $req->get_file_params()) return new WP_Error('rest_forbidden', 'Hier kannst du nur das Auto-Archiv und die Funktionen deines Veranstalters einstellen.', ['status' => 403]);
            if ($archive_in === null && $features_in === null) return new WP_Error('tix_invalid_field', 'Keine Archiv-Einstellung übergeben.', ['status' => 400, 'field' => 'archive']);
            if ($features_in !== null && class_exists('TIX_Features')) {
                $fr = TIX_Features::apply($features_in, $req);
                if (is_wp_error($fr)) return $fr;
            }
            if ($archive_in !== null) {
                $ar = TIX_Archive::save($archive_in, $archive_org);
                if (is_wp_error($ar)) return $ar;
            }
            return rest_ensure_response(self::config_payload());
        }
        if ($has('accent')) {
            $raw = $req->get_param('accent');
            if ($raw === null || $raw === '') unset($b['accent']);
            else {
                $h = self::hex($raw);
                if ($h === '') return new WP_Error('tix_invalid_field', 'Bitte eine Farbe als Hex-Wert angeben, z. B. #FC2F55.', ['status' => 400, 'field' => 'accent']);
                $b['accent'] = $h;
            }
        }
        if (!empty($files['logo']['tmp_name'])) {
            $id = self::store_logo($files['logo']);
            if (is_wp_error($id)) return $id;
            $b['logo_id'] = $id;
        } elseif ($has('logo_id')) {
            $id = intval($req->get_param('logo_id'));
            if ($id === 0) unset($b['logo_id']);
            else {
                $ok = self::check_logo_attachment($id);
                if (is_wp_error($ok)) return $ok;
                $b['logo_id'] = $id;
            }
        }
        // Platzhalter als Datei über die Marken-Route (Studio, 1.38.460): placeholder=<Datei>
        if (!empty($files['placeholder']['tmp_name']) || (!empty($files['placeholder']['error']) && intval($files['placeholder']['error']) !== UPLOAD_ERR_NO_FILE)) {
            $pa = self::store_placeholder($files['placeholder'], 'placeholder');
            if (is_wp_error($pa)) return $pa;
            $b['placeholder_id'] = intval($pa);
        } elseif ($has('placeholder_id')) {
            $pid = intval($req->get_param('placeholder_id'));
            if ($pid === 0) unset($b['placeholder_id']);
            elseif (get_post_type($pid) !== 'attachment' || !wp_attachment_is_image($pid)) return new WP_Error('tix_invalid_field', 'Bild nicht gefunden.', ['status' => 400, 'field' => 'placeholder_id']);
            else $b['placeholder_id'] = $pid;
        }
        // Mail-Marke (1.38.473): Logos als Datei (mail_logo, mail_logo_on_color) oder *_id, übrige Felder als Wert
        foreach (['mail_logo' => 'mail_logo_id', 'mail_logo_on_color' => 'mail_logo_on_color_id'] as $fk => $ik) {
            if (!empty($files[$fk]['error']) && intval($files[$fk]['error']) !== UPLOAD_ERR_NO_FILE && intval($files[$fk]['error']) !== UPLOAD_ERR_OK) {
                return new WP_Error('tix_too_large', 'Das Logo konnte nicht hochgeladen werden (höchstens 2 MB).', ['status' => 413, 'field' => $fk]);
            }
            if (!empty($files[$fk]['tmp_name'])) {
                $mid = self::store_logo($files[$fk]);
                if (is_wp_error($mid)) return new WP_Error($mid->get_error_code(), $mid->get_error_message(), ['status' => 400, 'field' => $fk]);
                $b[$ik] = intval($mid);
            }
        }
        // Bestellnummern (1.38.481): order_number_mode legacy|short, order_number_code (2–5 Zeichen A–Z/0–9; auf evendis fest EV)
        if ($has('order_number_mode')) {
            $om = (string) $req->get_param('order_number_mode');
            if (!in_array($om, ['legacy', 'short'], true)) return new WP_Error('tix_invalid_field', 'Bestellnummer: legacy oder short.', ['status' => 400, 'field' => 'order_number_mode']);
        }
        if ($has('order_number_code')) {
            $oc = strtoupper(trim((string) $req->get_param('order_number_code')));
            if ($oc !== '' && !preg_match('/^[A-Z0-9]{2,5}$/', $oc)) return new WP_Error('tix_invalid_field', 'Kürzel: 2 bis 5 Zeichen (A–Z, 0–9).', ['status' => 400, 'field' => 'order_number_code']);
            if (self::multi() && $oc !== '' && $oc !== 'EV') return new WP_Error('tix_invalid_field', 'Auf evendis ist das Kürzel immer EV.', ['status' => 400, 'field' => 'order_number_code']);
        }
        if ($archive_in !== null && class_exists('TIX_Archive') && method_exists('TIX_Archive', 'save')) {
            $ar = TIX_Archive::save($archive_in);
            if (is_wp_error($ar)) return $ar;
        }
        if ($features_in !== null && class_exists('TIX_Features')) {
            $fr = TIX_Features::apply($features_in, $req);
            if (is_wp_error($fr)) return $fr;
        }
        if ($has('order_number_mode')) update_option('tix_order_number_mode', (string) $req->get_param('order_number_mode'), false);
        if ($has('order_number_code')) { if ($oc === '') delete_option('tix_order_number_code'); else update_option('tix_order_number_code', $oc, false); }
        $mail_in = [];
        foreach (self::MAIL_KEYS as $mk) if ($has($mk)) $mail_in[$mk] = $req->get_param($mk);
        if ($mail_in) {
            $nb = self::apply_mail_fields($b, $mail_in);
            if (is_wp_error($nb)) return $nb;
            $b = $nb;
        }
        $unknown = array_diff(array_keys((array) $p), array_merge(['accent', 'logo_id', 'logo', 'has_app', 'app_layout', 'placeholder_id', 'placeholder', 'syndicate_default', 'syndicate_default_partner_sales', 'mail_logo', 'mail_logo_on_color', 'order_number_mode', 'order_number_code', 'archive', 'archive_enabled', 'archive_grace_hours', 'archive_skip_drafts', 'app_features', 'organizer_id'], self::MAIL_KEYS, $feature_params));
        // Vorgabe „alle Events auf evendis teilen“ (1.38.465): true | false | null (nicht eingestellt)
        if ($has('syndicate_default') || $has('syndicate_default_partner_sales')) {
            $avail = class_exists('TIX_Syndication_Push') && TIX_Syndication_Push::is_configured() && !self::multi();
            $sd = $req->get_param('syndicate_default');
            if ($has('syndicate_default') && filter_var($sd, FILTER_VALIDATE_BOOLEAN) && !$avail) {
                return new WP_Error('tix_syndication_unavailable', 'Die Verteilung auf evendis ist hier nicht eingerichtet.', ['status' => 409, 'field' => 'syndicate_default']);
            }
        }
        if ($has('app_layout')) {
            $lv = $req->get_param('app_layout');
            if (!current_user_can('manage_options')) return new WP_Error('rest_forbidden', 'Nur ein Admin legt das Profil der App fest.', ['status' => 403]);
            if ($lv !== null && $lv !== '' && !in_array($lv, self::LAYOUTS, true)) return new WP_Error('tix_invalid_field', 'Profil: club, festival oder festivalTour.', ['status' => 400, 'field' => 'app_layout']);
        }
        if ($unknown) return new WP_Error('tix_invalid_field', 'Nur Akzentfarbe, Logo, Mail-Marke und (Admin) has_app lassen sich hier ändern.', ['status' => 400, 'field' => (string) reset($unknown)]);
        // Eigene App übersteuern (nur Admin): true | false | null (automatisch)
        if ($has('has_app')) {
            if (!current_user_can('manage_options')) return new WP_Error('rest_forbidden', 'Nur ein Admin legt fest, ob die Seite eine eigene App hat.', ['status' => 403]);
            $v = $req->get_param('has_app');
            if ($v === null || $v === '') delete_option(self::HAS_APP);
            else update_option(self::HAS_APP, filter_var($v, FILTER_VALIDATE_BOOLEAN) ? '1' : '0', false);
        }
        if ($has('app_layout')) {
            $lv = $req->get_param('app_layout');
            if ($lv === null || $lv === '') delete_option(self::APP_LAYOUT); else update_option(self::APP_LAYOUT, $lv, false);
        }
        if ($has('syndicate_default') && class_exists('TIX_Syndication_Push')) {
            $sd = $req->get_param('syndicate_default');
            if ($sd === null || $sd === '') delete_option(TIX_Syndication_Push::DEFAULT_OPT);
            else update_option(TIX_Syndication_Push::DEFAULT_OPT, filter_var($sd, FILTER_VALIDATE_BOOLEAN) ? '1' : '0', false);
        }
        if ($has('syndicate_default_partner_sales') && class_exists('TIX_Syndication_Push')) {
            update_option(TIX_Syndication_Push::DEFAULT_SALES_OPT, filter_var($req->get_param('syndicate_default_partner_sales'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0', false);
        }
        update_option(self::BRAND, $b, false);
        if (class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::flush();
        return rest_ensure_response(self::config_payload());
    }

    /** Erlaubte Logos: PNG, JPEG, WebP, GIF bis 2 MB und 4000 px; SVG nicht (Skripte). */
    private static function check_image_file($path, $size) {
        if ($size > self::LOGO_MAX) return new WP_Error('tix_invalid_field', 'Das Logo ist zu groß (höchstens 2 MB).', ['status' => 400, 'field' => 'logo']);
        $info = @getimagesize($path);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
            return new WP_Error('tix_invalid_field', 'Bitte ein Logo als PNG, JPG, WebP oder GIF hochladen (SVG ist nicht erlaubt).', ['status' => 400, 'field' => 'logo']);
        }
        if ($info[0] > 4000 || $info[1] > 4000 || $info[0] < 16 || $info[1] < 16) return new WP_Error('tix_invalid_field', 'Das Logo muss zwischen 16 und 4000 Pixel groß sein.', ['status' => 400, 'field' => 'logo']);
        return $mime;
    }

    private static function check_logo_attachment($id) {
        if (get_post_type($id) !== 'attachment') return new WP_Error('tix_invalid_field', 'Bild nicht gefunden.', ['status' => 400, 'field' => 'logo_id']);
        $file = get_attached_file($id);
        if (!$file || !file_exists($file)) return new WP_Error('tix_invalid_field', 'Bild nicht gefunden.', ['status' => 400, 'field' => 'logo_id']);
        $r = self::check_image_file($file, filesize($file));
        if (is_wp_error($r)) return new WP_Error($r->get_error_code(), $r->get_error_message(), ['status' => 400, 'field' => 'logo_id']);
        return true;
    }

    private static function store_logo(array $f) {
        if (!is_uploaded_file($f['tmp_name'])) return new WP_Error('tix_invalid_field', 'Upload fehlgeschlagen.', ['status' => 400, 'field' => 'logo']);
        $mime = self::check_image_file($f['tmp_name'], intval($f['size'] ?? filesize($f['tmp_name'])));
        if (is_wp_error($mime)) return $mime;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime];
        $up = wp_upload_bits('studio-logo-' . wp_generate_password(6, false, false) . '.' . $ext, null, (string) file_get_contents($f['tmp_name']));
        if (!empty($up['error'])) return new WP_Error('tix_upload_failed', 'Das Logo konnte nicht gespeichert werden.', ['status' => 500]);
        $id = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => 'Studio-Logo', 'post_status' => 'inherit', 'post_author' => get_current_user_id()], $up['file']);
        if (!$id || is_wp_error($id)) return new WP_Error('tix_upload_failed', 'Das Logo konnte nicht gespeichert werden.', ['status' => 500]);
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $up['file']));
        return intval($id);
    }

    /** Veranstalter des Aufrufs (Admins: ?organizer=<id>). */
    private static function task_org_id(WP_REST_Request $req) {
        if (current_user_can('manage_options')) {
            $oid = intval($req->get_param('organizer'));
            return ($oid && get_post_type($oid) === 'tix_organizer') ? $oid : 0;
        }
        $p = class_exists('TIX_App_Scope') ? TIX_App_Scope::organizer_post_for_user(get_current_user_id()) : null;
        return $p ? intval($p->ID) : 0;
    }

    public static function perm_tasks(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (!class_exists('TIX_App_Scope') || !TIX_App_Scope::multi()) {
            return new WP_Error('tix_not_multi', 'Nur auf Plattformen mit mehreren Veranstaltern verfügbar.', ['status' => 403]);
        }
        if (!self::task_org_id($req)) {
            return current_user_can('manage_options')
                ? new WP_Error('tix_invalid_field', 'Bitte gib den Veranstalter an (?organizer=<id>).', ['status' => 400, 'field' => 'organizer'])
                : new WP_Error('no_organizer', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
        }
        return true;
    }

    /** Offene Aufgaben: [{key, title, done, target, hint[, event_id]}]. */
    public static function tasks($oid) {
        $oid = intval($oid);
        $tasks = [];
        $reqs = [];
        if (class_exists('TIX_Org_Approval')) {
            foreach (TIX_Org_Approval::requirements($oid) as $r) $reqs[$r['key']] = $r;
        }

        // Auszahlungsdaten (Rechnungsadresse, Steuer, IBAN)
        $pay_keys = array_values(array_intersect(['name', 'address', 'tax', 'payout'], array_keys($reqs)));
        if ($pay_keys) {
            $open = array_values(array_filter($pay_keys, function ($k) use ($reqs) { return !$reqs[$k]['done']; }));
            $tasks[] = [
                'key' => 'payout', 'title' => 'Auszahlungsdaten hinterlegen', 'done' => !$open, 'target' => 'payout',
                'hint' => $open ? 'Fehlt noch: ' . implode(', ', array_map(function ($k) use ($reqs) { return $reqs[$k]['label']; }, $open)) : '',
            ];
        }

        // Vertrag
        if (isset($reqs['terms'])) {
            $done = (bool) $reqs['terms']['done'];
            if ($done && class_exists('TIX_Contract_Template') && TIX_Contract_Template::active() && TIX_Contract_Template::outdated($oid)) $done = false;
            $tasks[] = ['key' => 'terms', 'title' => 'Vertrag annehmen', 'done' => $done, 'target' => 'terms', 'hint' => $done ? '' : (string) $reqs['terms']['hint']];
        }

        // Profil (Telefon, Logo, Beschreibung)
        $m = function ($k) use ($oid) { return trim((string) get_post_meta($oid, $k, true)); };
        $miss = [];
        if ($m('_tix_org_phone') === '') $miss[] = 'Telefon';
        if (!intval($m('_tix_org_landing_logo_id')) && !intval($m('_tix_org_image_id'))) $miss[] = 'Logo';
        if ($m('_tix_org_landing_description') === '' && $m('_tix_org_description') === '' && $m('_tix_org_short_desc') === '') $miss[] = 'Beschreibung';
        $tasks[] = ['key' => 'profile', 'title' => 'Profil vervollständigen', 'done' => !$miss, 'target' => 'profile', 'hint' => $miss ? 'Fehlt noch: ' . implode(', ', $miss) : ''];

        // Entwürfe (nicht die wegen der Freigabe zurückgehaltenen Events)
        $drafts = class_exists('TIX_App_Scope') ? get_posts([
            'post_type' => 'event', 'post_status' => 'draft', 'posts_per_page' => 5, 'orderby' => 'modified', 'order' => 'DESC',
            'meta_query' => [TIX_App_Scope::event_meta_clause($oid)], 'suppress_filters' => true,
        ]) : [];
        foreach ($drafts as $d) {
            $title = html_entity_decode((string) get_the_title($d), ENT_QUOTES, 'UTF-8');
            $tasks[] = [
                'key' => 'draft:' . intval($d->ID), 'title' => 'Entwurf fertigstellen' . ($title !== '' ? ': ' . $title : ''),
                'done' => false, 'target' => 'event', 'hint' => 'Noch nicht veröffentlicht', 'event_id' => intval($d->ID),
            ];
        }

        // Freigabe des Kontos
        if (class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi()) {
            $st = TIX_Org_Approval::status($oid);
            $titles = [
                'approved' => ['Konto freigegeben', ''],
                'pending'  => ['Wartet auf Freigabe', 'Wir prüfen deine Angaben – du kannst schon alles vorbereiten.'],
                'rejected' => ['Angaben ergänzen und erneut einreichen', 'Dein Konto wurde nicht freigegeben.'],
                'blocked'  => ['Konto gesperrt', 'Bitte wende dich an den Support.'],
            ];
            $t = $titles[$st] ?? $titles['pending'];
            $tasks[] = ['key' => 'approval', 'title' => $t[0], 'done' => $st === 'approved', 'target' => 'approval', 'hint' => $t[1], 'status' => $st];
        }
        return $tasks;
    }

    public static function rest_tasks(WP_REST_Request $req) {
        $oid = self::task_org_id($req);
        $tasks = self::tasks($oid);
        $open = count(array_filter($tasks, function ($t) { return !$t['done']; }));
        return rest_ensure_response(['organizer_id' => $oid, 'open' => $open, 'all_done' => $open === 0, 'tasks' => $tasks]);
    }

    // ──────────────────────────────────────────
    //  Experten-Modus (klassische Seite in wp-admin)
    // ──────────────────────────────────────────

    private static function bar_visible() {
        return is_admin() && self::active() && self::is_studio_user() && !wp_doing_ajax();
    }

    /** Event, aus dem heraus die Leiste aufgerufen wird (0 = keins). */
    private static function current_event_id() {
        $id = 0;
        if (isset($_GET['post'])) $id = intval($_GET['post']);
        elseif (isset($_GET['event_id'])) $id = intval($_GET['event_id']);
        elseif (isset($_GET['event'])) $id = intval($_GET['event']);
        return ($id && get_post_type($id) === 'event') ? $id : 0;
    }

    public static function body_class($classes) {
        return self::bar_visible() ? $classes . ' tix-studio-bar' : $classes;
    }

    public static function cleanup_menu() {
        if (!self::bar_visible()) return;
        // Was Veranstalter nicht brauchen (Veranstalter-Rolle ist schon auf Tixomat, Medien, Profil begrenzt)
        foreach (['index.php', 'edit.php', 'edit-comments.php', 'tools.php', 'themes.php', 'plugins.php', 'options-general.php'] as $slug) {
            remove_menu_page($slug);
        }
    }

    public static function bar_styles() {
        if (!self::bar_visible()) return;
        ?>
<style id="tix-studio-bar-css">
html.wp-toolbar{padding-top:56px!important}
body.tix-studio-bar #wpadminbar,body.tix-studio-bar #screen-meta-links,body.tix-studio-bar #wpfooter{display:none!important}
body.tix-studio-bar .tix-shell-sidebar{top:56px!important;height:calc(100vh - 56px)!important}
body.tix-studio-bar #adminmenuback,body.tix-studio-bar #adminmenuwrap{top:56px}
.tix-studio-topbar{position:fixed;top:0;left:0;right:0;height:56px;z-index:100100;display:flex;align-items:center;gap:16px;padding:0 20px;background:#2C2F3F;color:#fff;font-family:'Figtree','Manrope',system-ui,-apple-system,'Segoe UI',sans-serif;box-shadow:0 2px 12px rgba(44,47,63,.18);box-sizing:border-box}
.tix-studio-topbar *{box-sizing:border-box}
.tix-studio-logo{font-size:21px;font-weight:800;letter-spacing:-.02em;color:#fff;text-decoration:none;line-height:1}
.tix-studio-logo:hover,.tix-studio-logo:focus{color:#fff}
.tix-studio-logo span{color:<?php echo esc_attr(self::accent()); ?>}
.tix-studio-logo img{display:block;max-height:30px;max-width:160px;width:auto;height:auto}
.tix-studio-mode{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#A0A4B4;padding:5px 10px;border:1px solid rgba(255,255,255,.18);border-radius:999px;white-space:nowrap}
.tix-studio-back{margin-left:auto;display:inline-flex;align-items:center;gap:8px;height:38px;padding:0 18px;border-radius:12px;background:<?php echo esc_attr(self::accent()); ?>;color:#fff!important;font-size:14px;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s}
.tix-studio-back:hover,.tix-studio-back:focus{filter:brightness(.92);color:#fff;box-shadow:none}
@media (max-width:782px){body.tix-studio-bar.tix-fullscreen #wpbody{padding-top:0}}
@media (max-width:600px){.tix-studio-mode{display:none}.tix-studio-topbar{padding:0 12px;gap:10px}.tix-studio-back{padding:0 12px;font-size:13px}}
</style>
        <?php
    }

    public static function render_bar() {
        if (!self::bar_visible()) return;
        $eid  = self::current_event_id();
        $back = $eid ? self::url('/events/' . $eid . '/') : self::url('/');
        ?>
<div class="tix-studio-topbar" role="banner">
    <a class="tix-studio-logo" href="<?php echo esc_url(self::url('/')); ?>"><?php
        // Plattform: Wortmarke „evendis.“; sonst Logo bzw. Name der Seite (Studio auf allen Seiten, 1.38.445)
        $logo = self::multi() ? '' : self::logo();
        if ($logo !== '') echo '<img src="' . esc_url($logo) . '" alt="' . esc_attr(get_bloginfo('name')) . '">';
        elseif (self::multi()) echo 'evendis<span>.</span>';
        else echo esc_html(get_bloginfo('name')) . '<span>.</span>';
    ?></a>
    <span class="tix-studio-mode">Experten-Modus</span>
    <a class="tix-studio-back" href="<?php echo esc_url($back); ?>">&larr; Zurück zur einfachen Ansicht</a>
</div>
        <?php
    }

    /** Admins: Eintrag „evendis Studio“ in der Admin-Leiste (angemeldet per Einmal-Code). */
    public static function admin_bar_node($bar) {
        if (!self::active() || !current_user_can('manage_options')) return;
        $bar->add_node([
            'id'    => 'tix-studio',
            'title' => esc_html(html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8') . ' Studio'),
            'href'  => admin_url('admin-post.php?action=tix_studio_open'),
            'meta'  => ['target' => '_blank'],
        ]);
    }
}
