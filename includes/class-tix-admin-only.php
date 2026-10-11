<?php
/**
 * „Nur Verwaltung“: Die WordPress-Installation dient als Verwaltung mit öffentlicher Infoseite
 * für Veranstalter (evendis: veranstalter.evendis.de); Kunden sind ausschließlich auf der
 * Kunden-Adresse (TIX_Customer_URL, evendis.de). Feste Regel: Kunden landen nie auf der Verwaltung.
 *
 * Option `tix_admin_only` (Vorgabe aus; wirkt nur mit gesetzter Kunden-Adresse):
 *   enabled      (bool)  Schalter
 *   public_pages (Liste) zusätzliche öffentliche, indexierbare Seiten der Infoseite (IDs oder
 *                        Slugs/Pfade, z. B. ["funktionen","preise","faq","kontakt"]);
 *                        Filter `tix_admin_only_public_pages` (Array von Seiten-IDs)
 * Setzen: wp option update tix_admin_only '{"enabled":true,"public_pages":["preise","faq"]}' --format=json
 *
 * Öffentlich + indexierbar: Startseite (Infoseite), die Seiten aus `public_pages`, „Veranstalter
 * werden“ (`[tix_register_event]`), die Vertragsseite, Login/„Passwort vergessen“.
 * Erreichbar, aber noindex: Verwaltungsseiten (Promoter, Sponsoren, Check-in, Kasse am Eingang,
 * DJ, Veranstalter-Dashboard), Anfragen mit tix_*-Parametern, AASA, /wp-json, wp-admin inkl.
 * admin-ajax/admin-post (Webhooks). Angemeldete Veranstalter/Team/Bearbeiter sehen alles.
 * Kundenpfade (Kasse, Konto, Support, Events, Veranstalter-Seiten /veranstalter/<slug>/,
 * Landingpages /v/<slug>/, Archive, Feeds und alle übrigen Seiten) → 301 auf die Kunden-Adresse,
 * gleicher Pfad (Rechtstext-Seiten außerhalb der Liste → /impressum/ usw.; Query bleibt).
 * Angemeldete Kunden auf `/` → Konto auf der Kunden-Adresse.
 *
 * Kaufablauf, Konto, Support, Danke-Seite usw. rendert WordPress weiter – aber nur über die
 * Durchleitung der Kundenseite (TIX_Customer_URL::boot_proxied()); solche Anfragen werden nie
 * umgeleitet. Sitemap: nur die öffentlichen Seiten. Login (wp-login.php) im evendis-Look.
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
        $pages = $raw['public_pages'] ?? [];
        if (is_string($pages)) $pages = preg_split('/[\s,]+/', $pages);
        return [
            'enabled'      => $flag($raw['enabled'] ?? null, false),
            'public_pages' => array_values(array_filter(array_map('strval', (array) $pages))),
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
        // Sitemap: nur die öffentlichen Seiten der Infoseite (keine Events/Veranstalter/Benutzer)
        add_filter('wp_sitemaps_post_types', [__CLASS__, 'sitemap_post_types']);
        add_filter('wp_sitemaps_add_provider', [__CLASS__, 'sitemap_providers'], 10, 2);
        add_filter('wp_sitemaps_posts_query_args', [__CLASS__, 'sitemap_query_args'], 10, 2);
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
    public static function is_staff($user) {
        if (!$user || !$user->ID) return false;
        if (user_can($user, 'edit_posts')) return true;
        return (bool) array_intersect((array) $user->roles, ['tix_organizer', 'tix_staff', 'tix_entrance', 'tix_dj', 'tix_promoter']);
    }

    /** IDs der öffentlichen Seiten: Startseite, Liste aus der Option, Registrierung, Vertrag. */
    public static function public_page_ids() {
        static $ids = null;
        if ($ids !== null) return $ids;
        $ids = [];
        $front = intval(get_option('page_on_front'));
        if ($front) $ids[] = $front;
        foreach (self::settings()['public_pages'] as $p) {
            if (ctype_digit($p)) { $ids[] = intval($p); continue; }
            $page = get_page_by_path(trim($p, '/'));
            if ($page) $ids[] = intval($page->ID);
        }
        global $wpdb;
        $reg = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[tix_register_event%'");
        foreach ((array) $reg as $r) $ids[] = intval($r);
        if (class_exists('TIX_Org_Approval')) {
            $s = get_option(TIX_Org_Approval::OPTION, []);
            if (is_array($s) && intval($s['terms_page_id'] ?? 0)) $ids[] = intval($s['terms_page_id']);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) apply_filters('tix_admin_only_public_pages', $ids)))));
        return $ids;
    }

    /** Ist die aktuelle Anfrage eine öffentliche Seite der Infoseite? */
    private static function is_public_request() {
        if (is_front_page()) return true;
        return is_page() && in_array(intval(get_queried_object_id()), self::public_page_ids(), true);
    }

    public static function route() {
        if (is_admin() || wp_doing_ajax() || is_robots() || is_favicon()) return;
        // Durchleitung der Kundenseite: nie umleiten (läuft schon unter der Kunden-Adresse)
        if (TIX_Customer_URL::is_proxied()) return;
        if (self::has_tix_query()) return;
        // Breakdance-Editor/-Vorschau
        if (isset($_GET['breakdance']) || isset($_GET['breakdance_iframe']) || isset($_GET['preview'])) return;
        if (function_exists('is_sitemap') && is_sitemap()) return;

        $user = wp_get_current_user();
        $logged = $user && $user->ID;

        // Angemeldete Kunden gehören auf die Kundenseite (Konto)
        if ($logged && !self::is_staff($user) && (is_front_page() || self::path_of($_SERVER['REQUEST_URI'] ?? '/') === '/')) {
            wp_redirect(self::landing_for_user($user), 302);
            exit;
        }
        // Gäste auf der Startseite mit alten Kunden-Parametern (?ref=, ?coupon=, utm_*) → Kundenseite
        if (!$logged && is_front_page() && (isset($_GET['ref']) || isset($_GET['coupon']) || isset($_GET['utm_source']))) {
            self::redirect_customer('/');
        }
        // Öffentliche Infoseite (Startseite, Liste, Registrierung, Vertrag)
        if (self::is_public_request()) return;
        // Veranstalter, Team, Bearbeiter: alles sichtbar (Vorschau, Gestaltung, Verwaltung)
        if (self::is_staff($user)) return;

        if (is_page()) {
            $post = get_queried_object();
            if (self::page_has($post, self::ADMIN_SHORTCODES)) return;
        }
        if (is_404()) return; // bleibt 404
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

    /** Nicht öffentlich (Verwaltungsseiten, tix_-Handler, durchgeleitete Kundenseiten)? */
    private static function wants_noindex() {
        if (TIX_Customer_URL::is_proxied() || self::has_tix_query()) return true;
        if (is_admin()) return true;
        if (did_action('wp') && !self::is_public_request()) return true;
        return false;
    }

    public static function robots_meta($robots) {
        if (!self::wants_noindex()) return $robots;
        return ['noindex' => true, 'nofollow' => true] + array_diff_key((array) $robots, ['index' => 1, 'follow' => 1, 'max-image-preview' => 1]);
    }

    public static function robots_header() {
        if (headers_sent()) return;
        // send_headers läuft vor der Abfrage: Handler- und Durchleitungs-Anfragen sicher kennzeichnen
        if (TIX_Customer_URL::is_proxied() || self::has_tix_query()) header('X-Robots-Tag: noindex, nofollow', true);
    }

    public static function sitemap_post_types($types) {
        return array_intersect_key((array) $types, ['page' => 1]);
    }

    public static function sitemap_providers($provider, $name) {
        return in_array($name, ['users', 'taxonomies'], true) ? false : $provider;
    }

    public static function sitemap_query_args($args, $post_type) {
        if ($post_type !== 'page') return $args;
        // Vertragsplatzhalter bleibt erreichbar, ist aber noindex (TIX_Org_Approval) → nicht in die Sitemap
        $ids = array_values(array_filter(self::public_page_ids(), function ($id) { return !self::is_contract_page($id); }));
        $args['post__in'] = $ids ?: [0];
        return $args;
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
