<?php
/**
 * „Nur Verwaltung“: Die WordPress-Installation dient nur noch als Verwaltung (evendis:
 * veranstalter.evendis.de); Kunden sind ausschließlich auf der Kunden-Adresse (TIX_Customer_URL,
 * evendis.de). Feste Regel: Kunden landen nie auf der Verwaltungs-Adresse.
 *
 * Option `tix_admin_only` (Vorgabe aus; wirkt nur mit gesetzter Kunden-Adresse):
 *   enabled (bool)   Schalter
 *   root    (string) 'login' (Vorgabe): `/` der Verwaltung → Login bzw. Ziel je Rolle;
 *                    'customer': `/` für Gäste → Kunden-Adresse
 * Setzen: wp option update tix_admin_only '{"enabled":true}' --format=json
 *
 * Kaufablauf, Konto, Support, Danke-Seite usw. rendert WordPress weiter – aber nur über die
 * Durchleitung der Kundenseite (Host = Verwaltung, X-Forwarded-Host = Kunden-Host von einem
 * vertrauten Proxy). Solche Anfragen werden nie umgeleitet (sonst Schleife) und sehen sich
 * vollständig als Kunden-Adresse (TIX_Customer_URL::boot_proxied()).
 *
 * Direkte Aufrufe der Verwaltung:
 *   - immer erreichbar: /wp-json, wp-admin (inkl. admin-ajax/admin-post), Login/„Passwort
 *     vergessen“, AASA, Anfragen mit tix_*-Parametern, Seiten mit Verwaltungs-Shortcodes
 *     (Veranstalter werden, Promoter, Sponsoren, Check-in, Kasse am Eingang, DJ, Veranstalter-
 *     Dashboard) und die Vertragsseite; angemeldete Veranstalter/Team/Bearbeiter sehen alles
 *   - `/`: siehe `root`; angemeldet → Ziel je Rolle (Admin → wp-admin, Veranstalter/Team →
 *     Veranstalter-Dashboard, Promoter → Promoter-Seite, Kunde → Konto auf der Kunden-Adresse)
 *   - alle übrigen öffentlichen Seiten (Kasse, Konto, Support, Events, Veranstalter, Seiten,
 *     Archive, Feeds) → 301 auf die Kunden-Adresse, gleicher Pfad (Veranstalter-/Landingpages →
 *     /veranstalter/<slug>/, Archive/Suche/Feeds → /, Rechtstexte → /impressum/ usw.; Query bleibt)
 *   - noindex: wp_robots, X-Robots-Tag, robots.txt „Disallow: /“, WordPress-Sitemaps aus
 *   - Login (wp-login.php) im evendis-Look
 */
if (!defined('ABSPATH')) exit;

class TIX_Admin_Only {

    const OPTION = 'tix_admin_only';

    /** Seiten mit diesen Shortcodes gehören zur Verwaltung und bleiben immer erreichbar. */
    const ADMIN_SHORTCODES = [
        'tix_register_event', 'tix_promoter_dashboard', 'tix_promoter_signup', 'tix_sponsor_dashboard',
        'tix_checkin', 'tix_pos', 'tix_musikwunsch_dj', 'tix_organizer_dashboard',
    ];
    /** Rechtstexte → gleichnamige Seiten der Kunden-Adresse. */
    const LEGAL = [
        'tix_impressum' => '/impressum/', 'tix_datenschutz' => '/datenschutz/', 'tix_agb' => '/agb/',
        'tix_widerruf' => '/widerruf/', 'tix_zahlungsweisen' => '/zahlungsweisen/',
    ];

    public static function settings() {
        $raw = get_option(self::OPTION, []);
        if (is_string($raw)) $raw = json_decode($raw, true);
        $raw = is_array($raw) ? $raw : [];
        $flag = function ($v, $default) {
            if ($v === null) return $default;
            return !($v === false || $v === 0 || $v === '0' || $v === 'false' || $v === '');
        };
        return [
            'enabled' => $flag($raw['enabled'] ?? null, false),
            'root'    => (($raw['root'] ?? 'login') === 'customer') ? 'customer' : 'login',
        ];
    }

    public static function active() {
        return self::settings()['enabled'] && class_exists('TIX_Customer_URL') && TIX_Customer_URL::active();
    }

    public static function init() {
        if (!self::active()) return;
        add_action('template_redirect', [__CLASS__, 'route'], 0);
        add_filter('wp_robots', [__CLASS__, 'robots_meta'], 999);
        add_action('send_headers', [__CLASS__, 'robots_header']);
        add_action('login_init', [__CLASS__, 'robots_header']);
        add_filter('robots_txt', [__CLASS__, 'robots_txt'], 999, 2);
        add_filter('wp_sitemaps_enabled', '__return_false');
        add_action('login_enqueue_scripts', [__CLASS__, 'login_style']);
        add_filter('login_headerurl', [__CLASS__, 'login_logo_url']);
        add_filter('login_headertext', [__CLASS__, 'login_logo_text']);
        // Nach dem Login (wp-login.php) ins passende Ziel, sofern kein anderes verlangt ist
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 30, 3);
    }

    // ── Weiterleitungen ──

    /** Anfrage mit tix_*-Parameter (Handler, Magic Links, Rücksprünge) → nie umleiten. */
    private static function has_tix_query() {
        foreach (array_keys($_GET) as $k) {
            if (stripos((string) $k, 'tix_') === 0) return true;
        }
        return false;
    }

    /** Shortcodes einer Seite (Inhalt + Breakdance-Daten). */
    private static function page_has($post, array $tags) {
        if (!$post) return false;
        $hay = (string) $post->post_content . ' ' . (string) get_post_meta($post->ID, '_breakdance_data', true);
        foreach ($tags as $t) {
            if (strpos($hay, '[' . $t) !== false) return $t;
        }
        return false;
    }

    private static function redirect_customer($path) {
        $base = TIX_Customer_URL::base();
        if ($base === '') return;
        $q = $_GET ? '?' . http_build_query(wp_unslash($_GET)) : '';
        if ($q !== '' && strpos($path, '?') !== false) $q = '&' . substr($q, 1);
        wp_redirect($base . $path . $q, 301, 'Tixomat');
        exit;
    }

    private static function path_of($url) {
        $p = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if ($home !== '/' && strpos($p, rtrim($home, '/')) === 0) $p = substr($p, strlen(rtrim($home, '/')));
        return $p !== '' ? $p : '/';
    }

    /** Veranstalter, Team, Bearbeiter oder Admin? */
    private static function is_staff($user) {
        if (!$user || !$user->ID) return false;
        if (user_can($user, 'edit_posts')) return true;
        return (bool) array_intersect((array) $user->roles, ['tix_organizer', 'tix_staff', 'tix_entrance', 'tix_dj', 'tix_promoter']);
    }

    public static function route() {
        if (is_admin() || wp_doing_ajax() || is_robots() || is_favicon()) return;
        // Durchleitung der Kundenseite: nie umleiten (läuft schon unter der Kunden-Adresse)
        if (TIX_Customer_URL::is_proxied()) return;
        if (self::has_tix_query()) return;
        // Breakdance-Editor/-Vorschau
        if (isset($_GET['breakdance']) || isset($_GET['breakdance_iframe']) || isset($_GET['preview'])) return;

        $user = wp_get_current_user();
        $path = self::path_of($_SERVER['REQUEST_URI'] ?? '/');
        $is_root = ($path === '/' || is_front_page()) && !is_feed();

        if ($is_root) {
            if ($user && $user->ID) {
                wp_safe_redirect(self::landing_for_user($user));
                exit;
            }
            // Gäste: alte Kunden-Links (?ref=, utm_* …) bzw. Einstellung „customer“ → Kundenseite
            if ($_GET || self::settings()['root'] === 'customer') self::redirect_customer('/');
            wp_safe_redirect(wp_login_url());
            exit;
        }

        // Veranstalter, Team, Bearbeiter: alles sichtbar (Vorschau, Gestaltung, Verwaltung)
        if (self::is_staff($user)) return;

        if (is_page()) {
            $post = get_queried_object();
            if (self::page_has($post, self::ADMIN_SHORTCODES)) return;
            if (self::is_contract_page(intval($post->ID ?? 0))) return;
        }
        if (is_404()) return; // bleibt 404 (noindex)
        self::redirect_customer(self::customer_path());
    }

    /** Pfad auf der Kunden-Adresse für die aktuelle (öffentliche) Anfrage. */
    private static function customer_path() {
        if (is_feed()) return '/';
        $landing = (string) get_query_var('tix_org_landing');
        if ($landing !== '') return '/veranstalter/' . sanitize_title($landing) . '/';
        if (is_singular('tix_organizer')) {
            $id = get_queried_object_id();
            $slug = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($id) : get_post_field('post_name', $id);
            return '/veranstalter/' . $slug . '/';
        }
        if (is_singular('event')) return self::path_of(get_permalink());
        if (is_page()) {
            $post = get_queried_object();
            if (intval($post->ID ?? 0) === intval(get_option('page_on_front'))) return '/';
            $legal = self::page_has($post, array_keys(self::LEGAL));
            if ($legal) return self::LEGAL[$legal];
            return self::path_of(get_permalink($post));
        }
        return '/'; // Archive, Sparten, Suche, Autoren, Datum, Blog
    }

    private static function is_contract_page($id) {
        if (!$id || !class_exists('TIX_Org_Approval')) return false;
        $s = get_option(TIX_Org_Approval::OPTION, []);
        return is_array($s) && intval($s['terms_page_id'] ?? 0) === $id;
    }

    /** Erste veröffentlichte Seite mit einem der Shortcodes. */
    private static function page_url_with($tag) {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
            '%[' . $wpdb->esc_like($tag) . '%'
        ));
        return $id ? (string) get_permalink($id) : '';
    }

    /** Ziel nach dem Login bzw. auf `/` je Rolle. */
    public static function landing_for_user($user) {
        if (!$user || !$user->ID) return wp_login_url();
        if (user_can($user, 'manage_options') || user_can($user, 'edit_others_posts')) return admin_url();
        $roles = (array) $user->roles;
        if (array_intersect($roles, ['tix_organizer', 'tix_staff', 'tix_entrance', 'tix_dj'])) {
            return tix_get_settings('organizer_dashboard_enabled') ? admin_url('admin.php?page=tix-organizer-dashboard') : admin_url();
        }
        if (in_array('tix_promoter', $roles, true) && class_exists('TIX_Promoter_Auth')) {
            return TIX_Promoter_Auth::get_promoter_page_url();
        }
        // Kunden: Konto auf der Kunden-Adresse (WordPress-Konto über die Durchleitung)
        $acc = self::page_url_with('tix_account');
        return $acc !== '' ? TIX_Customer_URL::base() . self::path_of($acc) : TIX_Customer_URL::base() . '/';
    }

    public static function login_redirect($redirect_to, $requested, $user) {
        if (!$user || is_wp_error($user)) return $redirect_to;
        // Ausdrücklich verlangtes Ziel (außer Standard wp-admin) behalten
        if ($requested !== '' && $requested !== admin_url() && $requested !== admin_url('/')) return $redirect_to;
        return self::landing_for_user($user);
    }

    // ── Indexierung ──

    public static function robots_meta($robots) {
        return ['noindex' => true, 'nofollow' => true] + array_diff_key((array) $robots, ['index' => 1, 'follow' => 1, 'max-image-preview' => 1]);
    }

    public static function robots_header() {
        if (!headers_sent()) header('X-Robots-Tag: noindex, nofollow', true);
    }

    public static function robots_txt($output, $public) {
        return "User-agent: *\nDisallow: /\n";
    }

    // ── Login im evendis-Look ──

    public static function login_logo_url() {
        return TIX_Customer_URL::base() . '/';
    }

    public static function login_logo_text() {
        return get_bloginfo('name');
    }

    public static function login_style() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $accent = preg_match('/^#[0-9a-f]{3,8}$/i', (string) ($s['color_primary'] ?? '')) ? $s['color_primary'] : '#FC2F55';
        $logo = esc_url_raw((string) ($s['admin_logo_url'] ?? ''));
        if ($logo === '' && function_exists('get_site_icon_url')) $logo = (string) get_site_icon_url(192);
        wp_enqueue_style('tix-login', TIXOMAT_URL . 'assets/css/login-evendis.css', [], TIXOMAT_VERSION);
        $css = ':root{--tix-login-accent:' . $accent . '}';
        if ($logo !== '') {
            $css .= '.login h1 a{background-image:url("' . esc_url($logo) . '")!important;background-size:contain!important;width:200px!important;height:72px!important}';
        }
        wp_add_inline_style('tix-login', $css);
    }
}
