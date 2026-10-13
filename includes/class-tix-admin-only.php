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
        add_filter('login_message', [__CLASS__, 'login_head'], 5);
        // SEO der öffentlichen Seiten (Infoseite): Titel, Beschreibung, OG, Canonical, FAQ als JSON-LD
        add_filter('pre_get_document_title', [__CLASS__, 'seo_title'], 20);
        add_action('wp_head', [__CLASS__, 'seo_head'], 2);
        // Google Fonts (Breakdance-Global) → lokale Schriften des Plugins
        add_action('template_redirect', [__CLASS__, 'start_font_rewrite'], -999);
        add_action('login_init', [__CLASS__, 'start_font_rewrite'], -999);
        add_action('login_footer', [__CLASS__, 'login_footer']);
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
        return (bool) array_intersect((array) $user->roles, ['tix_organizer', 'tix_staff', 'tix_entrance', 'tix_dj', 'tix_awareness', 'tix_promoter']);
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
        if (user_can($user, 'manage_options')) return admin_url();
        $roles = (array) $user->roles;
        // Veranstalter (Inhaber, Team-Admin): Dashboard – vor edit_others_posts prüfen, die Rolle bekommt
        // dieses Recht für die Mediathek (TIX_Organizer_Admin::ensure_capabilities)
        if (in_array('tix_organizer', $roles, true)) {
            return tix_get_settings('organizer_dashboard_enabled') ? admin_url('admin.php?page=tixomat') : admin_url();
        }
        // App-Rollen ohne wp-admin-Menüs: Check-in- bzw. DJ-Seite, sonst Profil
        if (in_array('tix_awareness', $roles, true) && !array_intersect($roles, ['tix_staff', 'tix_entrance', 'tix_dj'])) {
            return admin_url('profile.php'); // Awareness-Team arbeitet in der App
        }
        if (array_intersect($roles, ['tix_staff', 'tix_entrance', 'tix_dj'])) {
            $tag = in_array('tix_dj', $roles, true) && !array_intersect($roles, ['tix_staff', 'tix_entrance']) ? 'tix_musikwunsch_dj' : 'tix_checkin';
            $page = self::page_url_with($tag);
            return $page !== '' ? $page : admin_url('profile.php');
        }
        if (user_can($user, 'edit_others_posts')) return admin_url();
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

    // ── SEO der öffentlichen Seiten ──

    /** Eigene SEO-Ausgabe nur auf öffentlichen Seiten der Verwaltung und ohne SEO-Plugin. */
    private static function seo_active() {
        if (TIX_Customer_URL::is_proxied() || is_admin() || !is_page()) return false;
        if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION')) return false;
        return self::is_public_request();
    }

    public static function seo_title($title) {
        if (!self::seo_active()) return $title;
        $post = get_queried_object();
        if (!$post || empty($post->post_title)) return $title;
        $t = wp_strip_all_tags(html_entity_decode((string) $post->post_title, ENT_QUOTES, 'UTF-8'));
        // Startseite: Seitentitel pur; Unterseiten mit Marke
        return is_front_page() ? $t : $t . ' – ' . get_bloginfo('name');
    }

    private static function seo_description($post) {
        $text = (string) $post->post_excerpt;
        if ($text === '') $text = wp_strip_all_tags(strip_shortcodes((string) $post->post_content));
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES, 'UTF-8'))));
        if (mb_strlen($text) > 160) $text = rtrim(mb_substr($text, 0, 157)) . '…';
        return $text;
    }

    public static function seo_head() {
        if (!self::seo_active()) return;
        $post = get_queried_object();
        if (!$post) return;
        $title = self::seo_title('');
        $desc  = self::seo_description($post);
        $url   = is_front_page() ? home_url('/') : (string) get_permalink($post);
        $img   = has_post_thumbnail($post) ? (string) get_the_post_thumbnail_url($post, 'large') : self::media_url('konzert');
        echo "\n<!-- Tixomat: SEO der Infoseite -->\n";
        if ($desc !== '') echo '<meta name="description" content="' . esc_attr($desc) . "\">\n";
        if (!has_action('wp_head', 'rel_canonical')) echo '<link rel="canonical" href="' . esc_url($url) . "\">\n";
        $og = ['og:type' => 'website', 'og:locale' => 'de_DE', 'og:site_name' => get_bloginfo('name'), 'og:title' => $title, 'og:description' => $desc, 'og:url' => $url, 'og:image' => $img];
        foreach ($og as $k => $v) {
            if ($v !== '') echo '<meta property="' . esc_attr($k) . '" content="' . esc_attr($v) . "\">\n";
        }
        echo '<meta name="twitter:card" content="' . ($img !== '' ? 'summary_large_image' : 'summary') . "\">\n";
        $faq = self::faq_items($post->ID);
        if ($faq) {
            $ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($q) {
                return ['@type' => 'Question', 'name' => $q['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a']]];
            }, $faq)];
            echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
        }
    }

    /** Fragen/Antworten aus dem Breakdance-Element „FrequentlyAskedQuestions“ der Seite. */
    public static function faq_items($post_id) {
        $raw = get_post_meta(intval($post_id), '_breakdance_data', true);
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (is_array($data) && isset($data['tree_json_string'])) $data = json_decode((string) $data['tree_json_string'], true);
        if (!is_array($data) || empty($data['root'])) return [];
        $out = [];
        $walk = function ($node) use (&$walk, &$out) {
            if (!is_array($node)) return;
            $type = (string) ($node['data']['type'] ?? '');
            if (substr($type, -strlen('FrequentlyAskedQuestions')) === 'FrequentlyAskedQuestions') {
                foreach ((array) ($node['data']['properties']['content']['settings']['items'] ?? []) as $it) {
                    $q = trim(wp_strip_all_tags((string) ($it['question'] ?? '')));
                    $a = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) ($it['answer'] ?? ''))));
                    if ($q !== '' && $a !== '') $out[] = ['q' => $q, 'a' => $a];
                }
            }
            foreach ((array) ($node['children'] ?? []) as $c) $walk($c);
        };
        $walk($data['root']);
        return $out;
    }

    // ── Google Fonts → lokale Schriften ──

    public static function start_font_rewrite() {
        static $started = false;
        if ($started || is_admin() || wp_doing_ajax()) return;
        $started = true;
        ob_start([__CLASS__, 'local_fonts']);
    }

    /** <link> auf fonts.googleapis.com → fonts-local.css (Outfit, Inter, Sora, DM Sans lokal); Preconnects und @import entfernen. */
    public static function local_fonts($html) {
        if (!is_string($html) || stripos($html, 'fonts.g') === false) return $html;
        $local = '<link rel="stylesheet" id="tix-fonts-local-css" href="' . esc_url(TIXOMAT_URL . 'assets/css/fonts-local.css?ver=' . TIXOMAT_VERSION) . '" media="all" />';
        $done = false;
        $html = preg_replace_callback('~<link\b[^>]*fonts\.(?:googleapis|gstatic)\.com[^>]*>~i', function ($m) use (&$done, $local) {
            if (stripos($m[0], 'stylesheet') === false || $done) return '';
            $done = true;
            return $local;
        }, $html);
        $html = preg_replace('~@import\s+url\(\s*[\'"]?https?://fonts\.googleapis\.com[^)]*\)\s*;?~i', '', $html);
        return $html;
    }

    // ── Login im evendis-Look (login-vorgabe.jpg) ──

    /** Bild aus der Mediathek, das der Infoseiten-Installer mit `_ev_org_img` markiert hat ('' wenn keins). */
    private static function media_url($key) {
        $ids = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids',
                          'meta_key' => '_ev_org_img', 'meta_value' => $key]);
        return $ids ? (string) wp_get_attachment_url($ids[0]) : '';
    }

    private static function login_logo() {
        $logo = self::media_url('logo');
        if ($logo === '') {
            $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
            $logo = esc_url_raw((string) ($s['admin_logo_url'] ?? ''));
        }
        return $logo;
    }

    /** Logo → Infoseite (Startseite der Verwaltung). */
    public static function login_logo_url() {
        return home_url('/');
    }

    public static function login_logo_text() {
        return get_bloginfo('name');
    }

    private static function login_action() {
        return sanitize_key((string) ($_REQUEST['action'] ?? 'login'));
    }

    /** Titel + Untertitel über dem Formular (Anmelden, Passwort vergessen, Neues Passwort). */
    public static function login_head($message) {
        $texts = [
            'login'        => ['Willkommen zurück', 'Melde dich an, um deine Events, Verkäufe und dein Team zu verwalten.'],
            'lostpassword' => ['Passwort vergessen?', 'Gib deine E-Mail-Adresse ein – wir schicken dir einen Link für ein neues Passwort.'],
            'rp'           => ['Neues Passwort', 'Lege ein neues Passwort für dein Konto fest.'],
            'resetpass'    => ['Neues Passwort', 'Lege ein neues Passwort für dein Konto fest.'],
        ];
        $a = self::login_action() ?: 'login';
        if ($a === 'retrievepassword') $a = 'lostpassword';
        if (!isset($texts[$a])) return $message;
        // WordPress-Hinweis („Bitte gib deinen Benutzernamen …“) ersetzt die Unterzeile
        if ($a === 'lostpassword') $message = '';
        return '<div class="tix-login-head"><h2>' . esc_html($texts[$a][0]) . '</h2><p>' . esc_html($texts[$a][1]) . '</p></div>' . $message;
    }

    public static function login_style() {
        wp_enqueue_style('tix-login', TIXOMAT_URL . 'assets/css/login-evendis.css', [], TIXOMAT_VERSION);
        // Farben fest im evendis-Look (color_primary der Seite kann abweichen, z. B. Orange)
        $css = '';
        $logo = self::login_logo();
        if ($logo !== '') $css .= '.login h1 a.tix-login-has-logo{background-image:url("' . esc_url($logo) . '")}';
        $photo = self::media_url('comedy') ?: self::media_url('publikum');
        if ($photo !== '') $css .= '.tix-login-photo{background-image:url("' . esc_url($photo) . '")}';
        wp_add_inline_style('tix-login', $css);
    }

    /** Foto-Karte, „Jetzt Veranstalter werden“, Fußzeile; kleine Umstellungen im Formular. */
    public static function login_footer() {
        $register = self::page_url_with('tix_register_event');
        $base = TIX_Customer_URL::base();
        $lost = wp_lostpassword_url();
        $action = self::login_action();
        ?>
        <div class="tix-login-extra" hidden>
            <a class="tix-login-lost" href="<?php echo esc_url($lost); ?>">Passwort vergessen?</a>
            <?php if ($register !== '' && in_array($action, ['login', ''], true)) : ?>
                <p class="tix-login-register">Noch kein Veranstalter-Konto? <a href="<?php echo esc_url($register); ?>">Jetzt Veranstalter werden</a></p>
            <?php elseif ($action !== 'login' && $action !== '') : ?>
                <p class="tix-login-register"><a href="<?php echo esc_url(wp_login_url()); ?>">Zurück zur Anmeldung</a></p>
            <?php endif; ?>
            <nav class="tix-login-foot">
                <a href="<?php echo esc_url(home_url('/')); ?>">&larr; Zur Infoseite</a>
                <?php if ($base !== '') : ?>
                    <a href="<?php echo esc_url($base . '/impressum/'); ?>">Impressum</a>
                    <a href="<?php echo esc_url($base . '/datenschutz/'); ?>">Datenschutz</a>
                <?php endif; ?>
            </nav>
        </div>
        <aside class="tix-login-photo" aria-hidden="true">
            <strong>Dein Event. Deine Gäste. Ein Konto.</strong>
            <span class="tix-login-photo-text">Tickets, Einlass, Abendkasse und Auszahlung – auf evendis.de und in der evendis-App.</span>
            <div class="tix-login-pills"><span>Verkäufe</span><span>Check-in</span><span>Abendkasse</span><span>Auszahlung</span></div>
        </aside>
        <script>
        (function () {
            var x = document.querySelector('.tix-login-extra'), login = document.getElementById('login');
            if (!x || !login) return;
            var logo = document.querySelector('.login h1 a');
            if (logo && <?php echo self::login_logo() !== '' ? 'true' : 'false'; ?>) logo.classList.add('tix-login-has-logo');
            var label = document.querySelector('label[for="user_login"]');
            if (label && document.getElementById('loginform')) label.textContent = 'E-Mail oder Benutzername';
            var row = document.querySelector('#loginform .forgetmenot'), lost = x.querySelector('.tix-login-lost');
            if (row && lost) row.appendChild(lost); else if (lost) lost.remove();
            var form = login.querySelector('form'), reg = x.querySelector('.tix-login-register');
            if (form && reg) form.parentNode.insertBefore(reg, form.nextSibling);
            var foot = x.querySelector('.tix-login-foot');
            if (foot) login.parentNode.insertBefore(foot, login.nextSibling);
            x.remove();
        })();
        </script>
        <?php
    }
}
