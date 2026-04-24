<?php
/**
 * TIX Organizer Landing
 *
 * Öffentliche Veranstalter-Landingpage als standalone Seite.
 *
 * URLs:
 *   Phase 1: https://evendis.de/v/{slug}/           (Pfad, sofort aktiv)
 *   Phase 2: https://{slug}.evendis.de/             (Subdomain, aktivierbar via Setting)
 *
 * Admin entscheidet pro Veranstalter, ob die Landing aktiv ist.
 * Der Veranstalter kann dann Inhalte (Logo, Hero, Farben, Social, Beschreibung)
 * selbst pflegen — siehe `class-tix-organizer-landing-admin.php` (Phase 1b).
 */
if (!defined('ABSPATH')) exit;

class TIX_Organizer_Landing {

    const QUERY_VAR = 'tix_org_landing';

    /** Verfügbare Landing-Sektionen (ID → Meta) */
    public static function sections_registry() {
        return [
            'events'   => ['label' => 'Events',              'icon' => '🎟'],
            'about'    => ['label' => 'Über uns',            'icon' => '📖'],
            'partners' => ['label' => 'Partner & Sponsoren', 'icon' => '🤝'],
        ];
    }

    /** Konfiguration (Reihenfolge + Enabled) pro Organizer — mit Defaults */
    public static function get_sections_config($org_id) {
        $saved = get_post_meta($org_id, '_tix_org_landing_sections', true);
        if (!is_array($saved) || empty($saved)) {
            $saved = [
                ['id' => 'events',   'enabled' => true],
                ['id' => 'about',    'enabled' => true],
                ['id' => 'partners', 'enabled' => false],
            ];
        }
        // Artists-Section (aus älteren Configs) rausfiltern
        $saved = array_values(array_filter($saved, function($row){
            return !empty($row['id']) && $row['id'] !== 'artists';
        }));
        // Sicherstellen, dass alle Registry-IDs vorhanden sind (auch neu hinzugefügte)
        $present_ids = array_column($saved, 'id');
        foreach (array_keys(self::sections_registry()) as $id) {
            if (!in_array($id, $present_ids, true)) {
                $saved[] = ['id' => $id, 'enabled' => false];
            }
        }
        return $saved;
    }

    // Subdomains/Pfade die wir NIE als Organizer-Slug akzeptieren
    const RESERVED_SLUGS = [
        'www', 'mail', 'api', 'cdn', 'admin', 'wp-admin', 'wp-login', 'wp-content',
        'login', 'logout', 'register', 'blog', 'events', 'event', 'v', 'shop',
        'cart', 'warenkorb', 'checkout', 'kasse', 'my-account', 'mein-konto',
        'test', 'dev', 'staging', 'preview', 'support', 'help', 'contact',
        'kontakt', 'impressum', 'datenschutz', 'agb', 'search', 'suche',
        'faq', 'about', 'ueber-uns', 'partner', 'presse', 'news', 'sitemap',
        'robots', 'favicon', 'feed', 'rss', 'xmlrpc',
    ];

    /** Public API — wird in tixomat.php via add_action('init') aufgerufen */
    public static function init() {
        // Subdomain-Kontext SOFORT erkennen (vor allen home_url-Aufrufen)
        self::detect_subdomain_context();

        // DB-Tabelle für Analytics bei Bedarf anlegen
        self::ensure_views_table();

        // Unbekannte Subdomain → 301 zur Hauptdomain (SEO, verhindert Duplicate Content)
        self::maybe_redirect_unknown_subdomain();

        // Rewrite-Rule IMMER registrieren
        add_action('init', [__CLASS__, 'register_rewrite'], 20);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);

        // Feature-Flag nur für Routing/Template — die Admin-Metabox bleibt auch
        // auf anderen Sites sichtbar (dort aber mit Hinweis "Feature nicht aktiv")
        if (self::is_feature_enabled()) {
            add_action('parse_request', [__CLASS__, 'maybe_dispatch_subdomain'], 1);
            add_action('template_redirect', [__CLASS__, 'maybe_render'], 1);

            // Attribution-Cookie + Order-Meta
            add_action('template_redirect', [__CLASS__, 'maybe_set_attribution_cookie'], 5);
            add_action('tix_order_created', [__CLASS__, 'save_attribution_to_order'], 10, 1);
            add_filter('woocommerce_new_order_data', [__CLASS__, 'save_attribution_to_wc_order'], 10, 1);

            // Wenn wir auf Subdomain sind: ALLE URLs (Permalinks, Assets, Checkout)
            // müssen auf dieser Subdomain bleiben → home_url/site_url umbiegen
            if (defined('TIX_ON_ORG_SUBDOMAIN')) {
                add_filter('home_url',         [__CLASS__, 'filter_host_url'], 10, 2);
                add_filter('site_url',         [__CLASS__, 'filter_host_url'], 10, 2);
                add_filter('network_home_url', [__CLASS__, 'filter_host_url'], 10, 2);
                add_filter('network_site_url', [__CLASS__, 'filter_host_url'], 10, 2);
                // WP's eingebaute "redirect to canonical host" abschalten
                add_filter('redirect_canonical', [__CLASS__, 'maybe_cancel_canonical_redirect'], 10, 2);
                // REST/Ajax URLs ebenfalls umbiegen
                add_filter('rest_url',         [__CLASS__, 'filter_host_url'], 10, 2);
                add_filter('admin_url',        [__CLASS__, 'filter_host_url_admin'], 10, 2);

                // Veranstalter-Branding auf ALLEN Subdomain-Seiten injizieren
                // (Event-Detail, Kasse, etc. bekommen eigenen Header/Footer + Farben)
                add_action('wp_head',       [__CLASS__, 'inject_branded_css'],    99);
                add_action('wp_body_open',  [__CLASS__, 'inject_branded_header'], 1);
                add_action('wp_footer',     [__CLASS__, 'inject_branded_footer'], 99);
                add_filter('body_class',    [__CLASS__, 'add_subdomain_body_class']);
                // Admin-Bar auf Subdomain komplett aus (Branding soll clean wirken)
                add_filter('show_admin_bar', '__return_false');
                // Event-Views auf Subdomain tracken
                add_action('template_redirect', [__CLASS__, 'maybe_track_event_view'], 20);
                // Archive/Search/404 auf Subdomain → zurück zur Landing
                add_action('template_redirect', [__CLASS__, 'maybe_redirect_to_landing'], 3);
            }
        }

        // Admin immer aktiv (UI soll auch auf anderen Sites sichtbar sein)
        if (is_admin()) {
            // Metabox-Fallback (falls normal-Context funktioniert)
            add_action('add_meta_boxes',              [__CLASS__, 'add_meta_box']);
            // Primäre Ausgabe direkt auf der Edit-Seite (umgeht Metabox-System)
            add_action('edit_form_after_title',       [__CLASS__, 'render_after_title']);

            add_action('save_post_tix_organizer',     [__CLASS__, 'save_meta'], 20, 2);
            add_action('wp_ajax_tix_org_landing_slug_check', [__CLASS__, 'ajax_slug_check']);
            add_action('admin_enqueue_scripts',       [__CLASS__, 'admin_assets']);

            // Organizer-Self-Service-Seite
            add_action('admin_menu', [__CLASS__, 'register_organizer_page'], 30);
            add_action('wp_ajax_tix_org_landing_save',       [__CLASS__, 'ajax_save_organizer_settings']);
            add_filter('tix_organizer_allowed_pages',        [__CLASS__, 'register_allowed_page']);

            // Admin-Only: Globale Landingpage-Einstellungen
            add_action('admin_menu',             [__CLASS__, 'register_admin_settings_page'], 40);
            add_action('admin_post_tix_landing_settings_save', [__CLASS__, 'handle_admin_settings_save']);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Globale Landingpage-Einstellungen (Admin-Only)
    // ══════════════════════════════════════════════════════════════════

    public static function register_admin_settings_page() {
        add_submenu_page(
            'tixomat',
            'Landingpage-Einstellungen',
            'Landingpage-Einstellungen',
            'manage_options',
            'tix-landing-settings',
            [__CLASS__, 'render_admin_settings_page']
        );
    }

    /**
     * Liefert den Footer-Credit als array [text, url]:
     * - Wenn der Admin einen Custom-Text in den Landing-Settings hinterlegt hat, nutze den
     * - Sonst: Auto-generiert aus site-Name + home_url
     */
    public static function get_footer_credit() {
        $cfg  = get_option('tix_landing_footer_credit', []);
        $text = isset($cfg['text']) ? trim($cfg['text']) : '';
        $url  = isset($cfg['url'])  ? trim($cfg['url'])  : '';

        if ($text === '') $text = get_bloginfo('name') ?: 'evendis.de';
        if ($url  === '') $url  = get_option('home');

        return ['text' => $text, 'url' => $url];
    }

    public static function render_admin_settings_page() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung');

        $cfg       = get_option('tix_landing_footer_credit', []);
        $text_val  = isset($cfg['text']) ? $cfg['text'] : '';
        $url_val   = isset($cfg['url'])  ? $cfg['url']  : '';
        $saved     = !empty($_GET['saved']);
        $credit    = self::get_footer_credit();

        ?>
        <div class="wrap" style="max-width: 700px;">
            <h1>Landingpage-Einstellungen</h1>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tix_landing_settings_save">
                <?php wp_nonce_field('tix_landing_settings_save', '_tixnonce'); ?>

                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:24px;margin-top:20px;">
                    <h2 style="margin-top:0;">Footer-Credit auf Veranstalter-Landingpages</h2>
                    <p style="color:#64748b;">Steuert den kleingedruckten Text ganz unten im Footer aller <code>*.evendis.de</code>-Subdomains und Pfad-Landings (<code>/v/{slug}/</code>).</p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="credit_text">Link-Text</label></th>
                            <td>
                                <input type="text" id="credit_text" name="credit_text"
                                       value="<?php echo esc_attr($text_val); ?>"
                                       placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"
                                       class="regular-text">
                                <p class="description">Leer lassen &rarr; automatisch Site-Name: <code><?php echo esc_html(get_bloginfo('name')); ?></code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="credit_url">Link-Ziel (URL)</label></th>
                            <td>
                                <input type="url" id="credit_url" name="credit_url"
                                       value="<?php echo esc_attr($url_val); ?>"
                                       placeholder="<?php echo esc_attr(get_option('home')); ?>"
                                       class="regular-text">
                                <p class="description">Leer lassen &rarr; automatisch Haupt-URL: <code><?php echo esc_html(get_option('home')); ?></code></p>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top:16px;padding:14px 16px;background:#f3f4f6;border-radius:6px;font-size:13px;">
                        <strong>Vorschau:</strong><br>
                        <span style="color:#6b7280;">Tickets &amp; Plattform by <a href="<?php echo esc_url($credit['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($credit['text']); ?></a></span>
                    </div>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Speichern</button>
                    </p>
                </div>
            </form>
        </div>
        <?php
    }

    public static function handle_admin_settings_save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung');
        check_admin_referer('tix_landing_settings_save', '_tixnonce');

        $text = sanitize_text_field($_POST['credit_text'] ?? '');
        $url  = esc_url_raw(trim($_POST['credit_url']  ?? ''));

        update_option('tix_landing_footer_credit', [
            'text' => $text,
            'url'  => $url,
        ]);

        wp_safe_redirect(admin_url('admin.php?page=tix-landing-settings&saved=1'));
        exit;
    }

    /**
     * Rendert die Landing-Einstellungen direkt auf der Organizer-Edit-Seite
     * (umgeht das Metabox-System, funktioniert auch wenn Metaboxen ausgeblendet sind).
     */
    public static function render_after_title($post) {
        if (!$post || $post->post_type !== 'tix_organizer') return;
        echo '<div style="margin:20px 0;padding:0;background:#fff;border:1px solid #c3c4c7;border-radius:4px;">';
        echo '<h2 class="hndle" style="margin:0;padding:12px 16px;border-bottom:1px solid #c3c4c7;font-size:14px;">Landingpage</h2>';
        echo '<div class="inside" style="margin:0;padding:0;">';
        self::render_meta_box($post);
        echo '</div></div>';
    }

    // ══════════════════════════════════════════════════════════════════
    // Feature-Flag
    // ══════════════════════════════════════════════════════════════════

    // ══════════════════════════════════════════════════════════════════
    // Subdomain-Context-Detection + URL-Scoping
    // ══════════════════════════════════════════════════════════════════

    /**
     * Wird sofort (synchron) beim Plugin-Laden aufgerufen.
     * Setzt TIX_ON_ORG_SUBDOMAIN + TIX_ORG_SUBDOMAIN_SLUG Konstanten,
     * wenn die Request von einer {slug}.evendis.de Subdomain kommt.
     * Die Konstanten steuern später die URL-Filter.
     */
    public static function detect_subdomain_context() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (empty($s['landing_use_subdomain'])) return;

        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        if (!$host) return;
        $base = parse_url(get_option('home'), PHP_URL_HOST);
        if (!$base) return;
        $base = preg_replace('/^www\./', '', $base);

        // Parent-Domain für Cross-Subdomain-SSO (Auth-Cookies teilen)
        if (!defined('TIX_LANDING_PARENT_HOST')) define('TIX_LANDING_PARENT_HOST', $base);

        // ── Cross-Subdomain SSO: Auth-Cookies mit Parent-Domain setzen ──
        // WP setzt Cookies normalerweise nur für die aktuelle Host-Domain. Wir hooken nach
        // WPs setcookie() und setzen sie nochmal mit Parent-Domain, damit evendis.de + alle
        // *.evendis.de Subdomains dieselbe Session teilen.
        add_action('set_auth_cookie',      [__CLASS__, 'share_auth_cookie'], 10, 5);
        add_action('set_logged_in_cookie', [__CLASS__, 'share_logged_in_cookie'], 10, 5);
        add_action('clear_auth_cookie',    [__CLASS__, 'clear_shared_cookies']);

        // Bestehende Sessions nachziehen: wenn User eingeloggt aber Parent-Cookie fehlt,
        // einmal neu Auth-Cookie ausstellen (damit SSO rückwirkend greift).
        add_action('init', [__CLASS__, 'maybe_resync_auth_cookie'], 1);

        if (!preg_match('/^([a-z0-9-]+)\.' . preg_quote($base, '/') . '$/', $host, $m)) return;
        $slug = $m[1];
        if (in_array($slug, self::RESERVED_SLUGS, true)) return;

        // DB-Check wäre hier zu früh (plugins_loaded, DB aber da).
        // Wir setzen optimistisch, maybe_render checkt später ob wirklich ein Organizer existiert.
        if (!defined('TIX_ON_ORG_SUBDOMAIN'))   define('TIX_ON_ORG_SUBDOMAIN', $host);
        if (!defined('TIX_ORG_SUBDOMAIN_SLUG')) define('TIX_ORG_SUBDOMAIN_SLUG', $slug);
    }

    /**
     * Setzt Auth-Cookie zusätzlich mit Parent-Domain-Scope (.evendis.de),
     * damit das Login auf evendis.de auch auf dpconnect.evendis.de etc. gilt.
     */
    public static function share_auth_cookie($cookie, $expire, $expiration, $user_id, $scheme) {
        if (!defined('TIX_LANDING_PARENT_HOST')) return;
        $parent = '.' . TIX_LANDING_PARENT_HOST;
        $secure = apply_filters('secure_auth_cookie', is_ssl(), $user_id);
        $cookie_name = ($scheme === 'secure_auth') ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
        // Admin-Cookie-Pfad + Plugins-Cookie-Pfad (WP-Standard)
        setcookie($cookie_name, $cookie, $expire, PLUGINS_COOKIE_PATH, $parent, $secure, true);
        setcookie($cookie_name, $cookie, $expire, ADMIN_COOKIE_PATH,   $parent, $secure, true);
    }

    public static function share_logged_in_cookie($cookie, $expire, $expiration, $user_id, $scheme) {
        if (!defined('TIX_LANDING_PARENT_HOST')) return;
        $parent = '.' . TIX_LANDING_PARENT_HOST;
        $secure_logged_in_cookie = is_ssl() && 'https' === parse_url(wp_login_url(), PHP_URL_SCHEME);
        setcookie(LOGGED_IN_COOKIE, $cookie, $expire, COOKIEPATH, $parent, $secure_logged_in_cookie, true);
        if (COOKIEPATH !== SITECOOKIEPATH) {
            setcookie(LOGGED_IN_COOKIE, $cookie, $expire, SITECOOKIEPATH, $parent, $secure_logged_in_cookie, true);
        }
    }

    /**
     * Bestehende WP-Sessions auf Parent-Domain-Cookie upgraden.
     *
     * Problem: User, die sich VOR Aktivierung unseres SSO-Hooks eingeloggt haben,
     * haben Auth-Cookies nur für ihre aktuelle Host-Domain (z.B. evendis.de).
     * Subdomains sehen diese Cookies nicht → kein Login.
     *
     * Lösung: Einmal pro Browser-Session prüfen, ob der User eingeloggt ist aber
     * noch kein Marker-Cookie existiert — dann wp_set_auth_cookie() erneut feuern.
     * Das triggert unsere share_auth_cookie-Hooks und setzt Parent-Domain-Cookies.
     */
    public static function maybe_resync_auth_cookie() {
        if (!defined('TIX_LANDING_PARENT_HOST')) return;
        if (is_admin() && !wp_doing_ajax()) return;   // Admin-Area eh meist auf Haupt-Host
        if (!is_user_logged_in()) return;

        // Marker-Cookie: zeigt dass SSO-Sync bereits gelaufen ist
        if (!empty($_COOKIE['tix_sso_synced'])) return;

        $user_id = get_current_user_id();
        if (!$user_id) return;

        // Auth-Cookies neu ausstellen (triggert unsere share_*-Hooks)
        // Remember = true damit Cookies lang genug leben
        wp_set_auth_cookie($user_id, true, is_ssl());

        // Marker setzen — Parent-Domain-Scope damit ALLE Subdomains synced sind
        $parent = '.' . TIX_LANDING_PARENT_HOST;
        setcookie('tix_sso_synced', '1', time() + 30 * DAY_IN_SECONDS, '/', $parent, is_ssl(), true);
    }

    public static function clear_shared_cookies() {
        if (!defined('TIX_LANDING_PARENT_HOST')) return;
        $parent = '.' . TIX_LANDING_PARENT_HOST;
        $past = time() - YEAR_IN_SECONDS;
        setcookie(AUTH_COOKIE,        ' ', $past, ADMIN_COOKIE_PATH,   $parent);
        setcookie(AUTH_COOKIE,        ' ', $past, PLUGINS_COOKIE_PATH, $parent);
        setcookie(SECURE_AUTH_COOKIE, ' ', $past, ADMIN_COOKIE_PATH,   $parent);
        setcookie(SECURE_AUTH_COOKIE, ' ', $past, PLUGINS_COOKIE_PATH, $parent);
        setcookie(LOGGED_IN_COOKIE,   ' ', $past, COOKIEPATH,          $parent);
        if (COOKIEPATH !== SITECOOKIEPATH) {
            setcookie(LOGGED_IN_COOKIE, ' ', $past, SITECOOKIEPATH, $parent);
        }
        // Sync-Marker auch löschen, damit nächster Login wieder nachgesynct wird
        setcookie('tix_sso_synced', ' ', $past, '/', $parent);
    }

    /**
     * 301-Redirect für Subdomains, die zu keinem freigeschalteten Organizer gehören.
     * Verhindert dass evendis.de-Homepage unter beliebigen Subdomain-URLs erscheint
     * (Duplicate Content → SEO-Risiko, Bot-Scraping → Ressourcenverschwendung).
     *
     * Ausnahme: wp-content/* + interne Tixomat-Debug-Files → durchlassen
     * (damit Asset-Requests von der Landing nicht rediretted werden)
     */
    public static function maybe_redirect_unknown_subdomain() {
        if (!defined('TIX_ORG_SUBDOMAIN_SLUG')) return; // nicht auf Subdomain

        // Asset-Requests nicht umleiten (Bilder, CSS, JS aus wp-content/wp-includes)
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path_only = strtok($uri, '?');
        if (preg_match('#^/(wp-content|wp-includes|wp-json|favicon\.ico|robots\.txt)#i', $path_only)) return;

        // Existiert ein freigeschalteter Organizer zu diesem Slug?
        $slug = TIX_ORG_SUBDOMAIN_SLUG;
        $org  = self::get_organizer_by_slug($slug);
        if ($org && self::is_approved($org->ID)) return; // valid → weitermachen

        // → unbekannte Subdomain: 301 zur Hauptdomain
        $main_host = parse_url(get_option('home'), PHP_URL_HOST) ?: 'evendis.de';
        $main_host = preg_replace('/^www\./', '', $main_host);
        $target    = 'https://' . $main_host . $uri;

        header('X-Tix-Redirect-Reason: unknown-subdomain');
        header('Location: ' . $target, true, 301);
        exit;
    }

    /**
     * Filter für home_url / site_url / rest_url:
     * Ersetzt den Host-Teil generierter URLs durch die aktuelle Subdomain,
     * damit alle Permalinks, Asset-URLs, Form-Actions auf der Subdomain bleiben.
     *
     * WICHTIG: admin-ajax.php hat is_admin()=true, trotzdem wollen wir die
     * URL-Rewrites machen — damit AJAX-Responses (z.B. checkout_url) Subdomain
     * zurückgeben und die Session nicht bricht.
     */
    public static function filter_host_url($url, $path = '') {
        if (!defined('TIX_ON_ORG_SUBDOMAIN')) return $url;
        if (!is_string($url) || $url === '') return $url;

        // Echte Admin-Seiten (Dashboard, Edit) nicht umbiegen —
        // AJAX schon, weil die Response URLs für Frontend-Navigation enthält
        if (is_admin() && !wp_doing_ajax()) return $url;

        return preg_replace('#^(https?://)[^/]+#i', '$1' . TIX_ON_ORG_SUBDOMAIN, $url, 1);
    }

    /**
     * admin_url() behandeln wir speziell:
     * wp-admin links sollen zurück zur Hauptdomain, NUR admin-ajax.php bleibt auf Subdomain
     * (damit AJAX-Requests mit Session/Cookies auf demselben Host laufen).
     */
    public static function filter_host_url_admin($url, $path = '') {
        if (!defined('TIX_ON_ORG_SUBDOMAIN')) return $url;
        if (is_admin() && !wp_doing_ajax()) return $url;

        // Nur admin-ajax.php auf Subdomain halten (wichtig für Checkout-AJAX)
        if (strpos($path, 'admin-ajax.php') !== false) {
            return preg_replace('#^(https?://)[^/]+#i', '$1' . TIX_ON_ORG_SUBDOMAIN, $url, 1);
        }
        // Alles andere (wp-admin/etc) bleibt auf Hauptdomain
        return $url;
    }

    /**
     * Body-Klasse für CSS-Scoping auf allen Subdomain-Seiten.
     */
    public static function add_subdomain_body_class($classes) {
        $classes[] = 'tix-org-subdomain';
        if (defined('TIX_ORG_SUBDOMAIN_SLUG')) {
            $classes[] = 'tix-org-' . sanitize_html_class(TIX_ORG_SUBDOMAIN_SLUG);

            // Farbschema-Klasse (light/dark)
            $org = self::get_organizer_by_slug(TIX_ORG_SUBDOMAIN_SLUG);
            if ($org) {
                $mode = get_post_meta($org->ID, '_tix_org_landing_color_mode', true) ?: 'light';
                $classes[] = 'tix-mode-' . $mode;
            }
        }
        // Auf der Landing selbst keinen Branding-Overlay (die rendert eigenes Template)
        if (get_query_var(self::QUERY_VAR) || self::is_subdomain_landing_request()) {
            $classes[] = 'tix-org-is-landing';
        }
        return $classes;
    }

    private static function is_subdomain_landing_request() {
        if (!defined('TIX_ORG_SUBDOMAIN_SLUG')) return false;
        $req = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        return ($req === '/' || $req === '');
    }

    /**
     * CSS auf Event/Kasse/sonst. Seiten auf der Subdomain:
     * - Versteckt Theme-Header/Footer (Breakdance)
     * - Appliziert Veranstalter-Farben via CSS-Variables (Light/Dark-Mode)
     * - Platz oben/unten für injizierten Branded-Header/-Footer
     */
    public static function inject_branded_css() {
        $slug = TIX_ORG_SUBDOMAIN_SLUG;
        $org  = self::get_organizer_by_slug($slug);
        if (!$org) return;
        $d = self::get_landing_data($org);

        // Custom Favicon pro Veranstalter (überschreibt den Default)
        if (!empty($d['favicon'])) {
            echo '<link rel="icon" href="' . esc_url($d['favicon']) . '">' . "\n";
        }

        $mode          = $d['color_mode'];                     // 'light' oder 'dark'
        $primary_light = esc_attr($d['primary_light']);
        $primary_dark  = esc_attr($d['primary_dark']);
        $bg_light      = esc_attr($d['bg_light']);
        $bg_dark       = esc_attr($d['bg_dark']);
        // Surface vom BG abgeleitet (leicht versetzt)
        $surface_light = self::shade_color($d['bg_light'], 0.03);
        $surface_dark  = self::shade_color($d['bg_dark'],  0.08);

        // Header-Hintergrund mit 88 % Alpha (Glaslook)
        $headerbg_light= self::color_with_alpha($d['header_bg_light'], 0.88);
        $headerbg_dark = self::color_with_alpha($d['header_bg_dark'],  0.88);
        ?>
<style id="tix-org-subdomain-branding">
/* ═══ Einheitliche Spacing-Variablen (matchen organizer-landing.css) ═══ */
body.tix-org-subdomain {
    --tix-ol-max-w:       1100px;
    --tix-ol-pad-y:       80px;
    --tix-ol-pad-y-mob:   56px;
    --tix-ol-pad-x:       24px;
    --tix-ol-pad-x-mob:   20px;
    --tix-ol-heading-gap: 48px;
}
/* ═══ Farb-Themen (Light + Dark) ═══ */
body.tix-org-subdomain.tix-mode-light {
    --tix-ol-primary:        <?php echo $primary_light; ?>;
    --tix-ol-bg:             <?php echo $bg_light; ?>;
    --tix-ol-surface:        <?php echo esc_attr($surface_light); ?>;
    --tix-ol-text:           #131020;
    --tix-ol-text-muted:     #6b7280;
    --tix-ol-border:         rgba(0,0,0,0.08);
    --tix-ol-header-bg:      <?php echo esc_attr($headerbg_light); ?>;
    --tix-ol-header-text:    <?php echo esc_attr($d['header_text_light']); ?>;
    --tix-ol-footer-bg:      <?php echo esc_attr($d['footer_bg_light']); ?>;
    --tix-ol-footer-text:    <?php echo esc_attr($d['footer_text_light']); ?>;
}
body.tix-org-subdomain.tix-mode-dark {
    --tix-ol-primary:        <?php echo $primary_dark; ?>;
    --tix-ol-bg:             <?php echo $bg_dark; ?>;
    --tix-ol-surface:        <?php echo esc_attr($surface_dark); ?>;
    --tix-ol-text:           #f5f5f7;
    --tix-ol-text-muted:     #a0a0a8;
    --tix-ol-border:         rgba(255,255,255,0.08);
    --tix-ol-header-bg:      <?php echo esc_attr($headerbg_dark); ?>;
    --tix-ol-header-text:    <?php echo esc_attr($d['header_text_dark']); ?>;
    --tix-ol-footer-bg:      <?php echo esc_attr($d['footer_bg_dark']); ?>;
    --tix-ol-footer-text:    <?php echo esc_attr($d['footer_text_dark']); ?>;
}

/* Body-Basis: Background + Text-Farbe */
body.tix-org-subdomain {
    background: var(--tix-ol-bg) !important;
    color: var(--tix-ol-text);
    padding-top: 72px;
}
body.tix-org-subdomain a { color: var(--tix-ol-primary); }

/* Theme-Header/Footer verstecken — Breakdance UND generische Marker */
body.tix-org-subdomain #wpadminbar,
body.tix-org-subdomain > header:not(.tix-org-brand-header),
body.tix-org-subdomain > footer:not(.tix-org-brand-footer),
body.tix-org-subdomain .bde-header-builder,
body.tix-org-subdomain .bde-footer-builder,
body.tix-org-subdomain [class*="bde-header-builder"],
body.tix-org-subdomain [class*="bde-footer-builder"],
body.tix-org-subdomain .breakdance-header,
body.tix-org-subdomain .breakdance-footer,
body.tix-org-subdomain section[id*="header" i],
body.tix-org-subdomain section[id*="footer" i] { display: none !important; }

/* Abstände auf Event-/Kasse-Seite straffen — alles was Breakdance an großem Padding addiert */
body.tix-org-subdomain .tse-wrap,
body.tix-org-subdomain .tix-ep {
    --tse-pad-top: 16px !important;
    --tse-pad-bottom: 24px !important;
    --tse-pad-x: 20px !important;
}
body.tix-org-subdomain main,
body.tix-org-subdomain #main,
body.tix-org-subdomain .site-main,
body.tix-org-subdomain [role="main"] {
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}
/* Breakdance-Sections mit großem vertikalen Padding reduzieren */
body.tix-org-subdomain [class*="bde-section-breakdance"],
body.tix-org-subdomain .bde-section {
    padding-top: 12px !important;
    padding-bottom: 12px !important;
    min-height: 0 !important;
}

/* ═══ Sticky Organizer-Header ═══ */
.tix-org-brand-header {
    position: fixed !important; top: 0; left: 0; right: 0; z-index: 9999;
    height: 72px;
    background: var(--tix-ol-header-bg);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--tix-ol-border);
    box-sizing: border-box;
}
/* Innere Breite matched die Single-Event-Breite + Landing-Sektionen */
.tix-org-brand-header-inner {
    max-width: var(--tix-ol-max-w, var(--ep-max-w, 1100px));
    height: 100%;
    margin: 0 auto;
    padding: 0 var(--tix-ol-pad-x, 24px);
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-sizing: border-box;
}
.tix-org-brand-header-left {
    display: flex !important; align-items: center; gap: 12px;
    min-width: 0; max-width: 70%;
    text-decoration: none !important;
    overflow: hidden;
}
.tix-org-brand-header-left img {
    height: 44px !important; width: auto !important; max-width: 180px !important;
    flex-shrink: 0; object-fit: contain !important;
    display: inline-block !important;
    position: static !important;
}
.tix-org-brand-header-left .tix-brand-name {
    font-weight: 700; font-size: 16px;
    color: var(--tix-ol-header-text, var(--tix-ol-text));
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tix-org-brand-header-nav {
    display: flex !important;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.tix-org-brand-header-back {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 18px; border-radius: 999px;
    background: var(--tix-ol-primary);
    color: #fff !important;
    text-decoration: none !important; font-weight: 600; font-size: 13px;
    transition: transform .15s, opacity .15s;
    flex-shrink: 0;
}
.tix-org-brand-header-back:hover { transform: translateY(-1px); opacity: 0.9; text-decoration: none !important; }
.tix-org-brand-header-back svg { width: 14px; height: 14px; }
.tix-org-brand-header-tickets {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 16px; border-radius: 999px;
    background: transparent;
    border: 1px solid var(--tix-ol-border);
    color: var(--tix-ol-header-text, var(--tix-ol-text)) !important;
    text-decoration: none !important; font-weight: 600; font-size: 13px;
    transition: transform .15s, background .15s, border-color .15s;
    flex-shrink: 0;
}
.tix-org-brand-header-tickets:hover,
.tix-org-brand-header-tickets.is-active {
    background: var(--tix-ol-primary);
    border-color: var(--tix-ol-primary);
    color: #fff !important;
    transform: translateY(-1px);
    text-decoration: none !important;
}
.tix-org-brand-header-tickets svg { width: 16px; height: 16px; flex-shrink: 0; }

/* Account-Icon: quadratisches Icon-Only-Button, konsistent zu Tickets */
.tix-org-brand-header-account {
    display: inline-flex; align-items: center; justify-content: center;
    width: 38px; height: 38px; border-radius: 999px;
    background: transparent;
    border: 1px solid var(--tix-ol-border);
    color: var(--tix-ol-header-text, var(--tix-ol-text)) !important;
    text-decoration: none !important;
    transition: transform .15s, background .15s, border-color .15s;
    flex-shrink: 0;
}
.tix-org-brand-header-account:hover,
.tix-org-brand-header-account.is-active {
    background: var(--tix-ol-primary);
    border-color: var(--tix-ol-primary);
    color: #fff !important;
    transform: translateY(-1px);
    text-decoration: none !important;
}
.tix-org-brand-header-account svg { width: 18px; height: 18px; flex-shrink: 0; }

@media (max-width: 560px) {
    .tix-org-brand-header-tickets-label,
    .tix-org-brand-header-back-label { display: none; }
    .tix-org-brand-header-tickets { padding: 10px 12px; }
    .tix-org-brand-header-back { padding: 10px 14px; }
    .tix-org-brand-header-account { width: 36px; height: 36px; }
}

/* ═══ Organizer-Footer (minimal, zentrierte Links) ═══ */
.tix-org-brand-footer {
    background: var(--tix-ol-footer-bg, var(--tix-ol-surface));
    color: var(--tix-ol-footer-text, var(--tix-ol-text));
    padding: 28px var(--tix-ol-pad-x, 24px);
    margin-top: 0 !important;
    border-top: 1px solid var(--tix-ol-border);
}
.tix-org-brand-footer-wrap {
    max-width: var(--tix-ol-max-w, var(--ep-max-w, 1100px));
    margin: 0 auto;
    text-align: center;
}
.tix-org-brand-footer-links {
    display: flex; justify-content: center;
    gap: 28px; flex-wrap: wrap; font-size: 14px; font-weight: 500;
    margin-bottom: 14px;
}
.tix-org-brand-footer-links a {
    color: var(--tix-ol-footer-text, var(--tix-ol-text));
    text-decoration: none; transition: color .15s; opacity: 0.9;
}
.tix-org-brand-footer-links a:hover { color: var(--tix-ol-primary); text-decoration: none; opacity: 1; }
.tix-org-brand-footer-meta {
    font-size: 11px;
    color: var(--tix-ol-footer-text, var(--tix-ol-text-muted));
    opacity: 0.65;
    padding-top: 10px;
    border-top: 1px solid var(--tix-ol-border);
}
.tix-org-brand-footer-meta a { color: inherit; }
.tix-org-brand-footer-meta a:hover { color: var(--tix-ol-primary); opacity: 1; }

/* ═══ Aggressive Empty-Space Kill: Breakdance-Leerraum vor Footer weg ═══ */
body.tix-org-subdomain,
body.tix-org-subdomain html { margin-bottom: 0 !important; }

/* Alle Breakdance-Sections auf Subdomain: keine übertriebenen Paddings */
body.tix-org-subdomain .bde-section,
body.tix-org-subdomain [class*="bde-section-"],
body.tix-org-subdomain .section-container,
body.tix-org-subdomain .bde-container,
body.tix-org-subdomain [class*="bde-container"] {
    padding-top: 16px !important;
    padding-bottom: 16px !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    min-height: 0 !important;
}
body.tix-org-subdomain main,
body.tix-org-subdomain article,
body.tix-org-subdomain > div {
    padding-bottom: 0 !important;
    margin-bottom: 0 !important;
    min-height: 0 !important;
}

/* Falls Breakdance nach dem Content ein leeres Section-Wrapper rendert */
body.tix-org-subdomain .tse-wrap + *,
body.tix-org-subdomain .tix-ep + * {
    display: none !important;
}

/* Checkout / Warenkorb / Thank-You Container: eigene Mindest-Höhe + Default-Margins killen,
   damit die Branded-Header nicht durch overflowenden Top-Space vom Content getrennt ist */
body.tix-org-subdomain .tix-co,
body.tix-org-subdomain .tix-cart,
body.tix-org-subdomain .tix-up,
body.tix-org-subdomain .tix-faq,
body.tix-org-subdomain .tix-ty-wrap {
    min-height: 0 !important;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}
/* Thank-You auf Subdomain: konsistente Atmung zum Checkout (20px top+bottom) */
body.tix-org-subdomain .tix-ty-wrap {
    padding: 20px var(--tix-ol-pad-x, 20px) 40px !important;
}
/* Breakdance inline SVG-Sprites (gradients etc.) sind ohne Dimensionen → rendern
   default 300×150px und erzeugen Leerraum. Muss immer versteckt sein. */
body.tix-org-subdomain .breakdance-global-gradients-sprite,
body.tix-org-subdomain svg[class*="breakdance-global"],
body.tix-org-subdomain svg[aria-hidden="true"]:not(.tix-ol-hero-cta-arrow):not([width]):not([height]) {
    position: absolute !important;
    width: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
    pointer-events: none !important;
}

/* Explicit: Footer darf NIE mehr versteckt werden */
body.tix-org-subdomain .tix-org-brand-footer { display: block !important; }
</style>
        <?php
    }

    public static function inject_branded_header() {
        $slug = TIX_ORG_SUBDOMAIN_SLUG;
        $org  = self::get_organizer_by_slug($slug);
        if (!$org) return;
        $d    = self::get_landing_data($org);
        $landing_url = self::canonical_url($slug);
        $is_landing  = self::is_subdomain_landing_request();

        // Sub-Path-URLs auf derselben Subdomain
        $tickets_url = 'https://' . TIX_ON_ORG_SUBDOMAIN . '/tickets/';
        $account_url = 'https://' . TIX_ON_ORG_SUBDOMAIN . '/account/';
        $req_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $req_trim = trim((string) $req_path, '/');
        $is_tickets_page = in_array($req_trim, ['tickets', 'meine-tickets', 'my-tickets'], true);
        $is_account_page = in_array($req_trim, ['account', 'mein-konto', 'konto'], true);
        ?>
        <header class="tix-org-brand-header">
            <div class="tix-org-brand-header-inner">
                <a href="<?php echo esc_url($landing_url); ?>" class="tix-org-brand-header-left">
                    <?php if ($d['logo']): ?>
                        <img src="<?php echo esc_url($d['logo']); ?>" alt="<?php echo esc_attr($org->post_title); ?>">
                    <?php endif; ?>
                    <span class="tix-brand-name"><?php echo esc_html($org->post_title); ?></span>
                </a>

                <nav class="tix-org-brand-header-nav">
                    <?php if (!$is_landing): ?>
                        <a href="<?php echo esc_url($landing_url); ?>" class="tix-org-brand-header-back">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                            <span class="tix-org-brand-header-back-label">Zu allen Events</span>
                        </a>
                    <?php endif; ?>

                    <a href="<?php echo esc_url($tickets_url); ?>"
                       class="tix-org-brand-header-tickets<?php echo $is_tickets_page ? ' is-active' : ''; ?>"
                       aria-label="Meine Tickets">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M2 9V7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>
                        <span class="tix-org-brand-header-tickets-label">Meine Tickets</span>
                    </a>

                    <button type="button"
                            class="tix-org-brand-header-account tix-org-brand-header-support"
                            onclick="tixOrgSupportToggle()"
                            aria-label="Hilfe &amp; Support"
                            title="Hilfe &amp; Support">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </button>

                    <a href="<?php echo esc_url($account_url); ?>"
                       class="tix-org-brand-header-account<?php echo $is_account_page ? ' is-active' : ''; ?>"
                       aria-label="Mein Konto">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="12" cy="8" r="4"/><path d="M6 21c0-3.31 2.69-6 6-6s6 2.69 6 6"/></svg>
                    </a>
                </nav>
            </div>
        </header>
        <?php
    }

    public static function inject_branded_footer() {
        $slug = TIX_ORG_SUBDOMAIN_SLUG;
        $org  = self::get_organizer_by_slug($slug);
        if (!$org) return;
        $main_host = parse_url(get_option('home'), PHP_URL_HOST) ?: 'evendis.de';
        $main_host = preg_replace('/^www\./', '', $main_host);
        $credit    = self::get_footer_credit();
        ?>
        <footer class="tix-org-brand-footer">
            <div class="tix-org-brand-footer-wrap">
                <nav class="tix-org-brand-footer-links">
                    <a href="<?php echo esc_url(self::canonical_url($slug)); ?>">Events</a>
                    <a href="https://<?php echo esc_html($main_host); ?>/impressum/">Impressum</a>
                    <a href="https://<?php echo esc_html($main_host); ?>/datenschutz/">Datenschutz</a>
                    <a href="https://<?php echo esc_html($main_host); ?>/agb/">AGB</a>
                </nav>
                <div class="tix-org-brand-footer-meta">
                    Tickets &amp; Plattform by <a href="<?php echo esc_url($credit['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($credit['text']); ?></a>
                </div>
            </div>
        </footer>

        <?php // ── Support-Modal (Toggle via Header-Button) ── ?>
        <div class="tix-org-support-overlay" data-tix-support-overlay onclick="tixOrgSupportClose(event)">
            <div class="tix-org-support-modal" role="dialog" aria-modal="true" aria-labelledby="tix-org-support-title">
                <button type="button" class="tix-org-support-close" onclick="tixOrgSupportClose()" aria-label="Schließen">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <h3 id="tix-org-support-title">Support &amp; Hilfe</h3>
                <div class="tix-org-support-body">
                    <?php echo do_shortcode('[tix_support]'); ?>
                </div>
            </div>
        </div>

        <style>
            /* Support-Button im Header: Icon-Only wie Account (nutzt tix-org-brand-header-account Styles) */
            .tix-org-brand-header-support {
                cursor: pointer;
                padding: 0;
                font-family: inherit;
            }

            .tix-org-support-overlay {
                position: fixed; inset: 0; z-index: 9999;
                background: rgba(15,23,42,.55);
                display: none; align-items: center; justify-content: center;
                padding: 20px;
                backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
            }
            .tix-org-support-overlay.open { display: flex; }
            .tix-org-support-modal {
                background: #fff; border-radius: 16px;
                max-width: 680px; width: 100%; max-height: 85vh;
                padding: 28px 24px 24px;
                position: relative; overflow-y: auto;
                box-shadow: 0 20px 60px rgba(0,0,0,.35);
                animation: tixSupportIn .25s ease;
            }
            @keyframes tixSupportIn {
                from { opacity: 0; transform: translateY(12px) scale(.98); }
                to   { opacity: 1; transform: translateY(0) scale(1); }
            }
            .tix-org-support-modal h3 {
                margin: 0 0 16px; font-size: 20px; font-weight: 700; color: #111;
            }
            .tix-org-support-close {
                position: absolute; top: 12px; right: 12px;
                width: 36px; height: 36px; border-radius: 50%;
                background: #f3f4f6; color: #111; border: 0;
                display: flex; align-items: center; justify-content: center;
                cursor: pointer;
            }
            .tix-org-support-close:hover { background: #e5e7eb; }
            .tix-org-support-body { font-size: 14px; line-height: 1.5; color: #374151; }
            @media (max-width: 640px) {
                .tix-org-support-fab { bottom: 16px; right: 16px; width: 48px; height: 48px; }
                .tix-org-support-modal { padding: 22px 18px; border-radius: 14px; }
            }
            @media print {
                .tix-org-brand-header-support, .tix-org-support-overlay { display: none !important; }
            }
        </style>

        <script>
            function tixOrgSupportToggle() {
                var o = document.querySelector('[data-tix-support-overlay]');
                if (o) { o.classList.add('open'); document.body.style.overflow = 'hidden'; }
            }
            function tixOrgSupportClose(e) {
                if (e && e.target && !e.target.hasAttribute('data-tix-support-overlay') && !e.target.closest('.tix-org-support-close')) return;
                var o = document.querySelector('[data-tix-support-overlay]');
                if (o) { o.classList.remove('open'); document.body.style.overflow = ''; }
            }
            document.addEventListener('keydown', function(ev){ if (ev.key === 'Escape') tixOrgSupportClose({target:{hasAttribute:function(){return true;}}}); });
        </script>
        <?php
    }

    /**
     * WP's redirect_canonical würde auf der Subdomain ständig zur Hauptdomain
     * umleiten (weil Permalink != Request-URL). Wir verhindern das.
     */
    public static function maybe_cancel_canonical_redirect($redirect_url, $requested_url) {
        if (!defined('TIX_ON_ORG_SUBDOMAIN')) return $redirect_url;
        if (!$redirect_url) return $redirect_url;
        $red_host = parse_url($redirect_url, PHP_URL_HOST);
        if ($red_host && $red_host !== TIX_ON_ORG_SUBDOMAIN) {
            return false; // Redirect abbrechen
        }
        return $redirect_url;
    }

    // ══════════════════════════════════════════════════════════════════
    // Feature-Flag
    // ══════════════════════════════════════════════════════════════════

    public static function is_feature_enabled() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (!empty($s['landing_pages_enabled'])) return true;

        // Default-Whitelist: nur evendis.de
        $host = parse_url(home_url(), PHP_URL_HOST);
        return ($host === 'evendis.de' || $host === 'www.evendis.de');
    }

    // ══════════════════════════════════════════════════════════════════
    // Routing
    // ══════════════════════════════════════════════════════════════════

    public static function register_rewrite() {
        add_rewrite_rule('^v/([a-z0-9-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
    }

    public static function add_query_vars($vars) {
        $vars[] = self::QUERY_VAR;
        $vars[] = 'tix_org_landing_ref';
        return $vars;
    }

    /**
     * Phase 2: Subdomain-Dispatcher.
     * Wenn eine Anfrage für {slug}.evendis.de reinkommt UND das Subdomain-Feature
     * aktiviert ist UND der Slug zu einem freigeschalteten Veranstalter gehört,
     * setzen wir die Query-Var manuell, damit `maybe_render` greift.
     *
     * Ohne Approval / ohne Feature-Flag wird die Subdomain an das reguläre
     * WP-Routing durchgereicht (Home-Page wird serviert).
     */
    public static function maybe_dispatch_subdomain($wp) {
        // Feature-Flag: Subdomain-Modus muss explizit aktiviert sein
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (empty($s['landing_use_subdomain'])) return;

        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $base = parse_url(home_url(), PHP_URL_HOST);
        if (!$base) return;
        $base = preg_replace('/^www\./', '', $base);

        if (!preg_match('/^([a-z0-9-]+)\.' . preg_quote($base, '/') . '$/', $host, $m)) return;

        $slug = $m[1];
        if (in_array($slug, self::RESERVED_SLUGS, true)) return;

        // Nur auf Root-Pfad umleiten — erlaubt /wp-admin/ etc. weiter
        $req = $wp->request ?? '';
        if ($req !== '' && $req !== '/') return;

        // Nur aktivieren wenn Slug tatsächlich zu freigeschaltetem Organizer gehört
        $org = self::get_organizer_by_slug($slug);
        if (!$org || !self::is_approved($org->ID)) return;

        $wp->query_vars[self::QUERY_VAR] = $slug;
    }

    // ══════════════════════════════════════════════════════════════════
    // Render
    // ══════════════════════════════════════════════════════════════════

    /**
     * Auf der Subdomain: alles was KEIN Einzel-Content ist (Archive, Search,
     * 404, Category/Tax-Listen, etc.) → Redirect zur Landing-Root.
     *
     * Erlaubt:
     * - Landing (Root /)
     * - Single Events, Pages (Kasse, Warenkorb, Thank-You)
     * - PayPal/Mollie-Return-URLs (haben tix_payment_return oder ähnlichen Query)
     * - Alles unter /wp-admin/, /wp-content/, /wp-includes/, /wp-json/
     */
    public static function maybe_redirect_to_landing() {
        if (!defined('TIX_ORG_SUBDOMAIN_SLUG')) return;
        if (get_query_var(self::QUERY_VAR)) return; // Landing wird gerade gerendert

        $req = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        if ($req === '/' || $req === '') return;

        // Asset-/System-Pfade nicht umleiten
        if (preg_match('#^/(wp-admin|wp-content|wp-includes|wp-json|wp-login|wp-cron|favicon\.ico|robots\.txt|sitemap)#i', $req)) return;

        // Tixomat-Query-Handler (Payment-Return, Thank-You, Ticket-Download, Magic-Link, …) durchlassen
        if (self::request_has_tix_handler_query()) return;

        // Einzel-Content grundsätzlich erlauben
        if (is_singular()) return;

        // Alles andere → Archive, Search, 404, Homepage-Als-Page mit Event-Listing, etc.
        if (is_archive() || is_search() || is_404() || is_post_type_archive() || is_tax() || is_category() || is_tag() || is_home() || is_front_page()) {
            $landing = self::canonical_url(TIX_ORG_SUBDOMAIN_SLUG);
            wp_redirect($landing, 302);
            exit;
        }
    }

    /** Event-View tracken, wenn Subdomain-Kontext aktiv und single-event gerendert wird */
    public static function maybe_track_event_view() {
        if (!defined('TIX_ORG_SUBDOMAIN_SLUG')) return;
        if (!is_singular('event')) return;
        $org = self::get_organizer_by_slug(TIX_ORG_SUBDOMAIN_SLUG);
        if (!$org) return;
        self::track_view($org->ID, get_the_ID());
    }

    /**
     * True wenn der Request einen Tixomat-Query-Handler triggert (Thank-You, Download, Magic-Link etc.).
     * Solche Requests sollen NICHT von der Landing überschrieben werden, sondern an den
     * eigentlichen template_redirect-Handler der Funktion durchgereicht werden.
     */
    private static function request_has_tix_handler_query() {
        $trigger_keys = [
            'tix_thankyou', 'tix_payment_return', 'tix_payment_cancel',
            'tix_dl', 'tix_ticket_code', 'tix_ticket_key',
            'tix_bundle', 'tix_view',
            'tix_mt_token', 'tix_recover_cart', 'tix_feedback',
            'tix_guest', 'tix_paypal_test', 'tix_preview', 'tix_embed',
            'tix_activate',
        ];
        foreach ($trigger_keys as $k) {
            if (!empty($_GET[$k])) return true;
        }
        return false;
    }

    public static function maybe_render() {
        // Fall 1: Pfad-URL /v/{slug}/ — query_var vom Rewrite gesetzt
        $slug = get_query_var(self::QUERY_VAR);
        $view = 'landing';

        // Fall 2: Subdomain-Root / Subpath — wir routen manuell
        if (!$slug && defined('TIX_ORG_SUBDOMAIN_SLUG')) {
            $req_path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
            $trimmed  = trim((string) $req_path, '/');

            if ($trimmed === '') {
                // ── FIX: Tixomat-Query-Handler NICHT vom Landing überschreiben
                //    (Thank-You, Payment-Return, Ticket-Download, Magic-Link etc. laufen alle
                //     per Query-Param auf Root-Pfad — an ihre template_redirect-Handler durchreichen)
                if (self::request_has_tix_handler_query()) {
                    return;
                }
                $slug = TIX_ORG_SUBDOMAIN_SLUG;
                $view = 'landing';
            } elseif (in_array($trimmed, ['tickets', 'meine-tickets', 'my-tickets'], true)) {
                // Virtueller Sub-Pfad /tickets/ — rendert [tix_my_tickets] im Branding
                $slug = TIX_ORG_SUBDOMAIN_SLUG;
                $view = 'tickets';
            } elseif (in_array($trimmed, ['account', 'mein-konto', 'konto'], true)) {
                // Virtueller Sub-Pfad /account/ — rendert [tix_account] im Branding
                $slug = TIX_ORG_SUBDOMAIN_SLUG;
                $view = 'account';
            }
        }

        if (!$slug) return;

        $org = self::get_organizer_by_slug($slug);
        if (!$org || !self::is_approved($org->ID)) {
            self::render_404();
            exit;
        }

        if ($view === 'tickets') {
            self::render_tickets_page($org);
        } elseif ($view === 'account') {
            self::render_account_page($org);
        } else {
            self::render_landing($org);
        }
        exit;
    }

    /**
     * Rendert die „Mein Konto"-Seite auf der Subdomain:
     * gebrandete Header/Footer + [tix_account] Shortcode.
     * Verhält sich konsistent zu render_tickets_page.
     */
    private static function render_account_page($org) {
        while (ob_get_level() > 0) ob_end_clean();

        $data = self::get_landing_data($org);

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Mein Konto – <?php echo esc_html($org->post_title); ?></title>
    <meta name="robots" content="noindex,nofollow">

    <link rel="stylesheet" href="<?php echo esc_url(TIXOMAT_URL . 'assets/css/event-cards.css?v=' . TIXOMAT_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TIXOMAT_URL . 'assets/css/organizer-landing.css?v=' . TIXOMAT_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TIXOMAT_URL . 'assets/css/tix-account.css?v=' . TIXOMAT_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TIXOMAT_URL . 'assets/css/my-tickets.css?v=' . TIXOMAT_VERSION); ?>">

    <style>
        <?php
        $mode       = $data['color_mode'] ?? 'light';
        $bg         = $data['bg_color'];
        $surface_l  = self::shade_color($data['bg_light'], 0.03);
        $surface_d  = self::shade_color($data['bg_dark'],  0.08);
        $surface    = ($mode === 'dark') ? $surface_d : $surface_l;
        ?>
        :root {
            --tix-ol-primary:    <?php echo esc_attr($data['primary_color']); ?>;
            --tix-ol-accent:     <?php echo esc_attr($data['accent_color']); ?>;
            --tix-ol-bg:         <?php echo esc_attr($bg); ?>;
            --tix-ol-surface:    <?php echo esc_attr($surface); ?>;
            <?php if ($mode === 'dark'): ?>
            --tix-ol-text:       #f5f5f7;
            --tix-ol-text-muted: #a0a0a8;
            --tix-ol-border:     rgba(255,255,255,0.08);
            <?php else: ?>
            --tix-ol-text:       #131020;
            --tix-ol-text-muted: #6b7280;
            --tix-ol-border:     rgba(0,0,0,0.08);
            <?php endif; ?>
        }
        body.tix-ol { background: var(--tix-ol-bg); color: var(--tix-ol-text); }
        /* Konsistenz zum Checkout + /tickets/-Page: identisches padding */
        .tix-org-account-main {
            max-width: var(--tix-ol-max-w, 1100px);
            margin: 0 auto;
            padding: 28px var(--tix-ol-pad-x, 24px) 56px;
        }
        /* Weißes Card-Feld um den Account-Inhalt, wie beim Checkout */
        .tix-org-account-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 14px rgba(0,0,0,0.04);
            padding: 24px 28px;
            overflow: hidden;
        }
        /* Inner-Content-Margin-Overrides für tix_account: große Leerfläche reduzieren */
        body.tix-org-account-page .tix-account,
        body.tix-org-account-page .tix-account-content,
        body.tix-org-account-page .tix-account-main { margin: 0 !important; }
        body.tix-org-account-page .tix-account-sidebar { margin: 0 !important; }
        body.tix-org-account-page .tix-account h1,
        body.tix-org-account-page .tix-account h2,
        body.tix-org-account-page .tix-account h3 { margin-top: 0 !important; }
        body.tix-org-account-page .tix-account-stats { margin: 16px 0 !important; gap: 12px !important; }
        body.tix-org-account-page .tix-account-quick-links { margin-top: 20px !important; }
        /* Account-CSS nutzt --tix-acc-primary für Akzentfarbe → auf Landing Organizer-Primary */
        body.tix-org-account-page .tix-account { --tix-acc-primary: var(--tix-ol-primary); }

        /* Meine-Tickets innerhalb des Account-Shortcodes: CARD-Layout */
        body.tix-org-account-page .tix-account-content {
            padding: 0 !important;
        }
        body.tix-org-account-page .tix-mt { padding: 0; background: transparent; max-width: none; }
        body.tix-org-account-page .tix-mt-card {
            background: #fafaf9 !important;
            border: 1px solid var(--tix-ol-border, #e5e7eb);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 14px;
        }

        @media (max-width: 768px) {
            .tix-org-account-main { padding: 20px 12px 40px; }
            .tix-org-account-card { padding: 10px 12px; border-radius: 12px; }
        }
    </style>

    <link rel="icon" type="image/x-icon" href="<?php echo esc_url($data['favicon'] ?: (get_site_icon_url() ?: '/favicon.ico')); ?>">

    <?php self::inject_branded_css(); ?>
    <?php if (class_exists('TIX_Settings') && method_exists('TIX_Settings', 'output_css')): TIX_Settings::output_css(); endif; ?>

    <script>
        window.ajaxurl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        window.tixMyTickets = {
            ajax:  <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
            nonce: <?php echo wp_json_encode(wp_create_nonce('tix_mt_guest_resend')); ?>
        };
    </script>
    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/tix-qr.js?v=' . TIXOMAT_VERSION); ?>" defer></script>
    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/tix-ticket-img.js?v=' . TIXOMAT_VERSION); ?>" defer></script>
    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/tix-wallet.js?v=' . TIXOMAT_VERSION); ?>" defer></script>
</head>
<body class="tix-ol tix-org-subdomain tix-org-<?php echo esc_attr($data['slug']); ?> tix-mode-<?php echo esc_attr($data['color_mode'] ?? 'light'); ?> tix-org-account-page">

    <?php self::inject_branded_header(); ?>

    <main class="tix-org-account-main">
        <div class="tix-org-account-card">
            <?php echo do_shortcode('[tix_account]'); ?>
        </div>
    </main>

    <?php self::inject_branded_footer(); ?>
</body>
</html><?php
    }

    /**
     * Rendert die „Meine Tickets"-Seite auf der Subdomain:
     * gebrandete Header/Footer + [tix_my_tickets] Shortcode.
     * Funktioniert für eingeloggte User UND für Gäste (Magic-Link-Token, Formular).
     */
    private static function render_tickets_page($org) {
        while (ob_get_level() > 0) ob_end_clean();

        $data = self::get_landing_data($org);

        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Meine Tickets – <?php echo esc_html($org->post_title); ?></title>
    <meta name="robots" content="noindex,nofollow">

    <link rel="stylesheet" href="<?php echo esc_url(TIXOMAT_URL . 'assets/css/event-cards.css?v=' . TIXOMAT_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TIXOMAT_URL . 'assets/css/organizer-landing.css?v=' . TIXOMAT_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TIXOMAT_URL . 'assets/css/my-tickets.css?v=' . TIXOMAT_VERSION); ?>">

    <style>
        <?php
        $mode       = $data['color_mode'] ?? 'light';
        $bg         = $data['bg_color'];
        $surface_l  = self::shade_color($data['bg_light'], 0.03);
        $surface_d  = self::shade_color($data['bg_dark'],  0.08);
        $surface    = ($mode === 'dark') ? $surface_d : $surface_l;
        ?>
        :root {
            --tix-ol-primary:    <?php echo esc_attr($data['primary_color']); ?>;
            --tix-ol-accent:     <?php echo esc_attr($data['accent_color']); ?>;
            --tix-ol-bg:         <?php echo esc_attr($bg); ?>;
            --tix-ol-surface:    <?php echo esc_attr($surface); ?>;
            <?php if ($mode === 'dark'): ?>
            --tix-ol-text:       #f5f5f7;
            --tix-ol-text-muted: #a0a0a8;
            --tix-ol-border:     rgba(255,255,255,0.08);
            <?php else: ?>
            --tix-ol-text:       #131020;
            --tix-ol-text-muted: #6b7280;
            --tix-ol-border:     rgba(0,0,0,0.08);
            <?php endif; ?>
        }
        body.tix-ol { background: var(--tix-ol-bg); color: var(--tix-ol-text); }
        /* Konsistent mit Landing-Sektionen (gleicher --tix-ol-max-w + pad-x) */
        .tix-org-tickets-main {
            max-width: var(--tix-ol-max-w, 1100px);
            margin: 0 auto;
            padding: 28px var(--tix-ol-pad-x, 24px) 56px;
        }
        .tix-org-tickets-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 14px rgba(0,0,0,0.04);
            padding: 14px 16px;
        }
        @media (max-width: 768px) {
            .tix-org-tickets-main { padding: 20px 12px 40px; }
            .tix-org-tickets-card { padding: 10px 12px; border-radius: 12px; }
        }
    </style>

    <link rel="icon" type="image/x-icon" href="<?php echo esc_url($data['favicon'] ?: (get_site_icon_url() ?: '/favicon.ico')); ?>">

    <?php self::inject_branded_css(); ?>
    <?php if (class_exists('TIX_Settings') && method_exists('TIX_Settings', 'output_css')): TIX_Settings::output_css(); endif; ?>

    <script>
        window.tixMyTickets = {
            ajax:  <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
            nonce: <?php echo wp_json_encode(wp_create_nonce('tix_mt_guest_resend')); ?>
        };
    </script>
    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/tix-qr.js?v=' . TIXOMAT_VERSION); ?>" defer></script>
    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/tix-ticket-img.js?v=' . TIXOMAT_VERSION); ?>" defer></script>
    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/tix-wallet.js?v=' . TIXOMAT_VERSION); ?>" defer></script>
</head>
<body class="tix-ol tix-org-subdomain tix-org-<?php echo esc_attr($data['slug']); ?> tix-mode-<?php echo esc_attr($data['color_mode'] ?? 'light'); ?> tix-org-tickets-page">

    <?php self::inject_branded_header(); ?>

    <main class="tix-org-tickets-main">
        <div class="tix-org-tickets-card">
            <?php echo do_shortcode('[tix_my_tickets]'); ?>
        </div>
    </main>

    <?php self::inject_branded_footer(); ?>
</body>
</html><?php
    }

    /** Robuster 404 ohne Theme-Template-Abhängigkeit */
    private static function render_404() {
        while (ob_get_level() > 0) ob_end_clean();
        status_header(404);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');

        $home = home_url('/');
        ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Seite nicht gefunden</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fafaf9;color:#1f2937;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.box{max-width:480px;text-align:center}
h1{font-size:clamp(48px,10vw,96px);margin:0 0 8px;font-weight:800;color:#131020;line-height:1}
p{font-size:16px;color:#6b7280;margin:0 0 24px}
a{display:inline-block;padding:12px 28px;background:#E8445A;color:#fff;border-radius:999px;text-decoration:none;font-weight:700}
</style>
</head>
<body><div class="box">
<h1>404</h1>
<p>Diese Seite existiert nicht oder wurde entfernt.</p>
<a href="<?php echo esc_url($home); ?>">Zur Startseite &rarr;</a>
</div></body></html><?php
    }

    private static function render_landing($org) {
        // Alte Output-Buffer leeren (falls WP schon was gesendet hat)
        while (ob_get_level() > 0) ob_end_clean();

        $data = self::get_landing_data($org);

        // Attribution-Cookie setzen
        self::set_attribution_cookie($data['slug']);

        // View tracken (Landing)
        self::track_view($org->ID, 0);

        status_header(200);
        header('Content-Type: text/html; charset=utf-8');

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?php echo esc_html($org->post_title); ?> – <?php echo esc_html(get_bloginfo('name')); ?></title>
    <meta name="description" content="<?php echo esc_attr(wp_strip_all_tags($data['tagline'] ?: $data['description'])); ?>">

    <?php // Canonical zeigt auf Subdomain (Phase 2), solange Phase 2 aus: Pfad ?>
    <link rel="canonical" href="<?php echo esc_url(self::canonical_url($data['slug'])); ?>">

    <meta property="og:title"       content="<?php echo esc_attr($org->post_title); ?>">
    <meta property="og:description" content="<?php echo esc_attr(wp_strip_all_tags($data['tagline'] ?: $data['description'])); ?>">
    <meta property="og:type"        content="website">
    <meta property="og:url"         content="<?php echo esc_url(self::canonical_url($data['slug'])); ?>">
    <?php if ($data['hero_image']): ?>
    <meta property="og:image"       content="<?php echo esc_url($data['hero_image']); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">

    <link rel="stylesheet" href="<?php echo esc_url(TIXOMAT_URL . 'assets/css/event-cards.css?v=' . TIXOMAT_VERSION); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TIXOMAT_URL . 'assets/css/organizer-landing.css?v=' . TIXOMAT_VERSION); ?>">

    <?php // Dynamisches Branding-CSS — Mode-aware Variables ?>
    <style>
        <?php
        $mode       = $data['color_mode'] ?? 'light';
        $bg         = $data['bg_color'];
        $surface_l  = self::shade_color($data['bg_light'], 0.03);
        $surface_d  = self::shade_color($data['bg_dark'],  0.08);
        $surface    = ($mode === 'dark') ? $surface_d : $surface_l;
        ?>
        :root {
            --tix-ol-primary:    <?php echo esc_attr($data['primary_color']); ?>;
            --tix-ol-accent:     <?php echo esc_attr($data['accent_color']); ?>;
            --tix-ol-bg:         <?php echo esc_attr($bg); ?>;
            --tix-ol-surface:    <?php echo esc_attr($surface); ?>;
            <?php if ($mode === 'dark'): ?>
            --tix-ol-text:       #f5f5f7;
            --tix-ol-text-muted: #a0a0a8;
            --tix-ol-border:     rgba(255,255,255,0.08);
            <?php else: ?>
            --tix-ol-text:       #131020;
            --tix-ol-text-muted: #6b7280;
            --tix-ol-border:     rgba(0,0,0,0.08);
            <?php endif; ?>
        }
        body.tix-ol { background: var(--tix-ol-bg); color: var(--tix-ol-text); }
    </style>
    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/event-cards.js?v=' . TIXOMAT_VERSION); ?>" defer></script>

    <?php // Favicon: eigenes Organizer-Favicon mit Fallback aufs Site-Favicon ?>
    <link rel="icon" type="image/x-icon" href="<?php echo esc_url($data['favicon'] ?: (get_site_icon_url() ?: '/favicon.ico')); ?>">

    <?php // Branded-CSS direkt einbinden (wp_head() wird auf der Standalone-Landing nicht aufgerufen) ?>
    <?php self::inject_branded_css(); ?>

    <?php // Globales Typografie- + Farben-CSS aus den Tixomat-Einstellungen ?>
    <?php if (class_exists('TIX_Settings') && method_exists('TIX_Settings', 'output_css')): ?>
        <?php TIX_Settings::output_css(); ?>
    <?php endif; ?>

    <?php do_action('tix_organizer_landing_head', $org, $data); ?>
</head>
<body class="tix-ol tix-org-subdomain tix-org-<?php echo esc_attr($data['slug']); ?> tix-mode-<?php echo esc_attr($data['color_mode'] ?? 'light'); ?> tix-org-is-landing">

    <?php self::inject_branded_header(); ?>

    <?php self::render_hero($org, $data); ?>

    <?php // Sektionen in der vom Veranstalter festgelegten Reihenfolge ?>
    <?php foreach (self::get_sections_config($org->ID) as $sec):
        if (empty($sec['enabled'])) continue;
        switch ($sec['id']) {
            case 'events':   self::render_events($org->ID, $data); break;
            case 'about':    if (!empty($data['description'])) self::render_about($data); break;
            case 'partners': self::render_partners($org->ID, $data); break;
        }
    endforeach; ?>

    <?php self::inject_branded_footer(); ?>

    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/organizer-landing.js?v=' . TIXOMAT_VERSION); ?>" defer></script>

    <?php do_action('tix_organizer_landing_footer', $org, $data); ?>
</body>
</html>
        <?php
    }

    // ══════════════════════════════════════════════════════════════════
    // Partials
    // ══════════════════════════════════════════════════════════════════

    private static function render_hero($org, $d) {
        // Bevorzugt Video, Fallback auf Bild
        $has_video = !empty($d['hero_video']);
        $vtype     = $d['hero_video_type'] ?? '';
        $bg_style  = (!$has_video && $d['hero_image'])
            ? 'background-image:url(' . esc_url($d['hero_image']) . ');'
            : '';

        // Countdown: nächstes Event finden (wenn aktiviert)
        $countdown_event = null;
        if (!empty($d['countdown'])) {
            $today = current_time('Y-m-d');
            $upcoming = get_posts([
                'post_type' => 'event', 'post_status' => 'publish',
                'posts_per_page' => 1,
                'orderby' => 'meta_value', 'order' => 'ASC', 'meta_key' => '_tix_date_start',
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                    ['relation' => 'OR',
                        ['key' => '_tix_organizer_id',    'value' => $org->ID, 'type' => 'NUMERIC'],
                        ['key' => '_tix_co_organizer_id', 'value' => $org->ID, 'type' => 'NUMERIC'],
                    ],
                ],
            ]);
            if (!empty($upcoming)) $countdown_event = $upcoming[0];
        }
        ?>
        <section class="tix-ol-hero" style="<?php echo $bg_style; ?>">
            <?php if ($has_video && ($vtype === 'youtube' || $vtype === 'vimeo')):
                $embed = self::get_video_embed_url($d['hero_video'], $vtype);
                if ($embed):
            ?>
                <div class="tix-ol-hero-embed-wrap" aria-hidden="true">
                    <iframe class="tix-ol-hero-embed"
                            src="<?php echo esc_url($embed); ?>"
                            frameborder="0"
                            allow="autoplay; fullscreen; picture-in-picture"
                            allowfullscreen
                            title="<?php echo esc_attr($org->post_title); ?>"></iframe>
                </div>
            <?php endif; elseif ($has_video): ?>
                <video class="tix-ol-hero-video" autoplay muted loop playsinline preload="auto"
                       <?php if ($d['hero_image']): ?>poster="<?php echo esc_url($d['hero_image']); ?>"<?php endif; ?>>
                    <source src="<?php echo esc_url($d['hero_video']); ?>">
                </video>
            <?php endif; ?>
            <div class="tix-ol-hero-overlay"></div>

            <div class="tix-ol-hero-inner">
                <?php if ($d['logo']): ?>
                    <img src="<?php echo esc_url($d['logo']); ?>" alt="<?php echo esc_attr($org->post_title); ?>" class="tix-ol-hero-logo">
                <?php endif; ?>
                <h1 class="tix-ol-hero-title"><?php echo esc_html($org->post_title); ?></h1>
                <?php if ($d['tagline']): ?>
                    <p class="tix-ol-hero-tagline"><?php echo esc_html($d['tagline']); ?></p>
                <?php endif; ?>

                <?php // Live-Countdown zum nächsten Event (wenn aktiviert + Event vorhanden) ?>
                <?php if ($countdown_event): ?>
                    <?php
                    $start_iso = get_post_meta($countdown_event->ID, '_tix_date_start', true);
                    $start_tm  = get_post_meta($countdown_event->ID, '_tix_time_start', true);
                    $target    = trim($start_iso . 'T' . ($start_tm ?: '20:00') . ':00');
                    ?>
                    <div class="tix-ol-hero-countdown" data-tix-target="<?php echo esc_attr($target); ?>">
                        <div class="tix-ol-cd-label">Nächstes Event in</div>
                        <div class="tix-ol-cd-values">
                            <div><strong data-d>--</strong><span>Tage</span></div>
                            <div><strong data-h>--</strong><span>Std</span></div>
                            <div><strong data-m>--</strong><span>Min</span></div>
                            <div><strong data-s>--</strong><span>Sek</span></div>
                        </div>
                        <div class="tix-ol-cd-event"><?php echo esc_html($countdown_event->post_title); ?></div>
                    </div>
                <?php endif; ?>

                <a href="<?php echo esc_url($d['cta_url']); ?>" class="tix-ol-hero-cta">
                    <?php echo esc_html($d['cta_text']); ?>
                    <?php if (substr($d['cta_url'], 0, 1) === '#'): ?>
                        <span class="tix-ol-hero-cta-arrow">↓</span>
                    <?php else: ?>
                        <span class="tix-ol-hero-cta-arrow">→</span>
                    <?php endif; ?>
                </a>
            </div>

            <a href="#events" class="tix-ol-scroll-hint" aria-label="Nach unten scrollen">
                <span></span>
            </a>
        </section>
        <?php
    }

    private static function render_about($d) {
        ?>
        <section class="tix-ol-about">
            <div class="tix-ol-wrap">
                <h2 class="tix-ol-section-heading">Über uns</h2>
                <div class="tix-ol-about-text"><?php echo wp_kses_post(wpautop($d['description'])); ?></div>
            </div>
        </section>
        <?php
    }

    /** Partner-Sektion — horizontaler Logo-Strip */
    private static function render_partners($org_id, $d) {
        $partners = get_post_meta($org_id, '_tix_org_landing_partners', true);
        if (!is_array($partners) || empty($partners)) return;
        $heading = trim(get_post_meta($org_id, '_tix_org_landing_partners_heading', true)) ?: 'Unsere Partner';
        ?>
        <section class="tix-ol-partners" id="partners">
            <div class="tix-ol-wrap">
                <h2 class="tix-ol-section-heading"><?php echo esc_html($heading); ?></h2>
                <div class="tix-ol-partners-strip">
                    <?php foreach ($partners as $p):
                        $name  = trim($p['name'] ?? '');
                        $logo  = intval($p['logo_id'] ?? 0);
                        $url   = trim($p['url']  ?? '');
                        $img   = $logo ? wp_get_attachment_image_url($logo, 'medium') : '';
                        if (!$img && !$name) continue;
                        $wrapper_open  = $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"' : '<div';
                        $wrapper_close = $url ? '</a>' : '</div>';
                    ?>
                        <?php echo $wrapper_open; ?> class="tix-ol-partner" title="<?php echo esc_attr($name); ?>">
                            <?php if ($img): ?>
                                <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($name); ?>">
                            <?php else: ?>
                                <span><?php echo esc_html($name); ?></span>
                            <?php endif; ?>
                        <?php echo $wrapper_close; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
    }

    private static function render_events($org_id, $d) {
        // Kommende Events: als Haupt-Organizer ODER Co-Organizer
        $today = current_time('Y-m-d');
        $upcoming = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_key'       => '_tix_date_start',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                [
                    'relation' => 'OR',
                    ['key' => '_tix_organizer_id',    'value' => $org_id, 'type' => 'NUMERIC'],
                    ['key' => '_tix_co_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'],
                ],
            ],
        ]);

        $past = [];
        if (!empty($d['show_past_events'])) {
            $past = get_posts([
                'post_type'      => 'event',
                'post_status'    => 'publish',
                'posts_per_page' => 12,
                'orderby'        => 'meta_value',
                'order'          => 'DESC',
                'meta_key'       => '_tix_date_start',
                'meta_query'     => [
                    'relation' => 'AND',
                    ['key' => '_tix_date_start', 'value' => $today, 'compare' => '<', 'type' => 'DATE'],
                    [
                        'relation' => 'OR',
                        ['key' => '_tix_organizer_id',    'value' => $org_id, 'type' => 'NUMERIC'],
                        ['key' => '_tix_co_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'],
                    ],
                ],
            ]);
        }
        ?>
        <section class="tix-ol-events" id="events">
            <div class="tix-ol-wrap">
                <div class="tix-ol-events-head">
                    <h2 class="tix-ol-section-heading">Kommende Events</h2>
                    <?php if (!empty($upcoming)): ?>
                    <div class="tix-ol-view-toggle" role="tablist">
                        <button class="tix-ol-view-btn active" data-view="list" aria-label="Listenansicht">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                            Liste
                        </button>
                        <button class="tix-ol-view-btn" data-view="calendar" aria-label="Kalenderansicht">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Kalender
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($upcoming)): ?>
                    <p class="tix-ol-empty">Derzeit sind keine Events geplant. Schau bald wieder vorbei.</p>
                <?php else: ?>
                    <div class="tix-ol-grid tix-ol-view-panel tix-ol-view-list active">
                        <?php foreach ($upcoming as $event):
                            self::render_event_card($event);
                        endforeach; ?>
                    </div>
                    <div class="tix-ol-view-panel tix-ol-view-calendar" hidden>
                        <?php self::render_events_calendar($upcoming); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($past)): ?>
                    <details class="tix-ol-past-details">
                        <summary>Vergangene Events anzeigen</summary>
                        <div class="tix-ol-grid tix-ol-grid-past">
                            <?php foreach ($past as $event):
                                self::render_event_card($event, true);
                            endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /** Monats-Kalender mit Events als Punkte — zeigt den aktuellen Monat */
    private static function render_events_calendar($events) {
        // Events nach Tag gruppieren (yyyy-mm-dd => array of events)
        $by_day = [];
        foreach ($events as $ev) {
            $d = get_post_meta($ev->ID, '_tix_date_start', true);
            if (!$d) continue;
            $key = date('Y-m-d', strtotime($d));
            $by_day[$key][] = $ev;
        }

        // Zeige ab aktuellem Monat: 3 Monate am Stück
        $cur = strtotime(date('Y-m-01'));
        $months = 3;
        ?>
        <div class="tix-ol-cal-wrap">
            <?php for ($mi = 0; $mi < $months; $mi++):
                $ts        = strtotime("+{$mi} month", $cur);
                $year      = date('Y', $ts);
                $month     = date('n', $ts);
                $label     = wp_date('F Y', $ts);
                $first_dow = date('N', strtotime($year . '-' . $month . '-01')); // 1=Mo..7=So
                $days_in   = date('t', $ts);
            ?>
            <div class="tix-ol-cal">
                <div class="tix-ol-cal-header"><?php echo esc_html($label); ?></div>
                <div class="tix-ol-cal-weekdays">
                    <span>Mo</span><span>Di</span><span>Mi</span><span>Do</span><span>Fr</span><span>Sa</span><span>So</span>
                </div>
                <div class="tix-ol-cal-grid">
                    <?php for ($i = 1; $i < $first_dow; $i++): ?>
                        <div class="tix-ol-cal-day tix-ol-cal-empty"></div>
                    <?php endfor; ?>
                    <?php for ($day = 1; $day <= $days_in; $day++):
                        $key = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $events_today = $by_day[$key] ?? [];
                        $has = count($events_today) > 0;
                    ?>
                        <div class="tix-ol-cal-day <?php echo $has ? 'has-events' : ''; ?>">
                            <span class="tix-ol-cal-num"><?php echo $day; ?></span>
                            <?php if ($has): ?>
                                <div class="tix-ol-cal-events">
                                    <?php foreach ($events_today as $ev): ?>
                                        <a href="<?php echo esc_url(get_permalink($ev)); ?>" class="tix-ol-cal-event" title="<?php echo esc_attr($ev->post_title); ?>">
                                            <span class="tix-ol-cal-dot"></span>
                                            <span class="tix-ol-cal-title"><?php echo esc_html($ev->post_title); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        <?php
    }

    private static function render_event_card($event, $is_past = false) {
        // Nutze vorhandene Kartenlogik wenn möglich, sonst Minimal-Fallback
        if (class_exists('TIX_Event_Cards') && method_exists('TIX_Event_Cards', 'render_card')) {
            echo TIX_Event_Cards::render_card($event, false, true);
            return;
        }

        // Minimal-Fallback
        $thumb    = get_the_post_thumbnail_url($event->ID, 'large') ?: '';
        $date     = get_post_meta($event->ID, '_tix_date_card', true)
                 ?: get_post_meta($event->ID, '_tix_date_display', true);
        $permalink = get_permalink($event->ID);
        ?>
        <a class="tix-ol-card <?php echo $is_past ? 'is-past' : ''; ?>" href="<?php echo esc_url($permalink); ?>">
            <?php if ($thumb): ?>
                <div class="tix-ol-card-img" style="background-image:url(<?php echo esc_url($thumb); ?>)"></div>
            <?php endif; ?>
            <div class="tix-ol-card-body">
                <div class="tix-ol-card-date"><?php echo esc_html($date); ?></div>
                <h3 class="tix-ol-card-title"><?php echo esc_html($event->post_title); ?></h3>
            </div>
        </a>
        <?php
    }

    private static function render_footer($org, $d) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $imprint_page = get_option('wp_page_for_privacy_policy'); // wird eh meist verwendet
        ?>
        <footer class="tix-ol-footer">
            <div class="tix-ol-wrap">
                <div class="tix-ol-footer-grid">
                    <div class="tix-ol-footer-col">
                        <?php if ($d['logo']): ?>
                            <img src="<?php echo esc_url($d['logo']); ?>" alt="" class="tix-ol-footer-logo">
                        <?php endif; ?>
                        <strong><?php echo esc_html($org->post_title); ?></strong>
                        <?php if ($d['tagline']): ?>
                            <p class="tix-ol-footer-tagline"><?php echo esc_html($d['tagline']); ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($d['social'])): ?>
                    <div class="tix-ol-footer-col">
                        <h4>Folge uns</h4>
                        <div class="tix-ol-socials">
                            <?php foreach ($d['social'] as $key => $url):
                                if (!$url) continue;
                                $label = self::social_label($key);
                                $icon  = self::social_icon($key);
                            ?>
                                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr($label); ?>">
                                    <?php echo $icon; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($d['contact_email']): ?>
                            <a href="mailto:<?php echo esc_attr($d['contact_email']); ?>" class="tix-ol-footer-link"><?php echo esc_html($d['contact_email']); ?></a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="tix-ol-footer-col">
                        <h4>Rechtliches</h4>
                        <ul class="tix-ol-footer-links">
                            <li><a href="<?php echo esc_url(home_url('/impressum/')); ?>">Impressum</a></li>
                            <li><a href="<?php echo esc_url(home_url('/datenschutz/')); ?>">Datenschutz</a></li>
                            <li><a href="<?php echo esc_url(home_url('/agb/')); ?>">AGB</a></li>
                            <li><a href="<?php echo esc_url(home_url('/')); ?>">Zu <?php echo esc_html(get_bloginfo('name')); ?> &rarr;</a></li>
                        </ul>
                    </div>
                </div>

                <?php $credit = self::get_footer_credit(); ?>
                <div class="tix-ol-footer-meta">
                    Tickets &amp; Plattform by <a href="<?php echo esc_url($credit['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($credit['text']); ?></a>
                </div>
            </div>
        </footer>
        <?php
    }

    private static function social_label($key) {
        return [
            'instagram' => 'Instagram',
            'facebook'  => 'Facebook',
            'website'   => 'Website',
            'tiktok'    => 'TikTok',
            'x'         => 'X / Twitter',
            'youtube'   => 'YouTube',
            'spotify'   => 'Spotify',
        ][$key] ?? $key;
    }

    private static function social_icon($key) {
        // Minimale SVG-Icons (18x18)
        $icons = [
            'instagram' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>',
            'facebook'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M22 12a10 10 0 10-11.56 9.87v-6.99H7.9V12h2.54V9.8c0-2.51 1.49-3.89 3.78-3.89 1.1 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.77l-.44 2.88h-2.33v6.99A10 10 0 0022 12z"/></svg>',
            'website'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 010 20M12 2a15.3 15.3 0 000 20"/></svg>',
            'tiktok'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19.6 6.3a4.8 4.8 0 01-3-1.7 4.9 4.9 0 01-1-2.6h-3.2V14a2.6 2.6 0 01-2.6 2.6 2.6 2.6 0 01-2.6-2.6A2.6 2.6 0 019.8 11.4V8.2a5.8 5.8 0 105.9 5.8V9.6a8 8 0 003.9 1z"/></svg>',
            'x'         => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.9 3h2.7l-5.9 6.8L22.6 21h-5.4l-4.3-5.5L7.8 21H5.1l6.3-7.2L4.6 3h5.6l3.9 5.1L18.9 3zm-1 16.3h1.5L8.2 4.6H6.6L17.9 19.3z"/></svg>',
            'youtube'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M23 7.3a3 3 0 00-2.1-2.1C19 4.7 12 4.7 12 4.7s-7 0-8.9.5A3 3 0 001 7.3C.5 9.2.5 12 .5 12s0 2.8.5 4.7a3 3 0 002.1 2.1C5 19.3 12 19.3 12 19.3s7 0 8.9-.5a3 3 0 002.1-2.1c.5-1.9.5-4.7.5-4.7s0-2.8-.5-4.7zM9.8 15.5v-7L15.5 12l-5.7 3.5z"/></svg>',
            'spotify'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm4.6 14.4a.6.6 0 01-.9.2c-2.4-1.5-5.4-1.8-9-1a.6.6 0 01-.3-1.2c3.9-.9 7.2-.5 9.9 1.1.3.2.4.6.3.9zm1.2-2.8a.8.8 0 01-1.1.3c-2.8-1.7-7-2.2-10.3-1.2a.8.8 0 11-.5-1.5c3.8-1.2 8.4-.6 11.6 1.4.4.2.5.7.3 1zm.1-2.9c-3.3-2-8.8-2.2-12-1.2a.9.9 0 11-.5-1.8c3.6-1.1 9.7-.9 13.5 1.4a.9.9 0 11-1 1.6z"/></svg>',
        ];
        return $icons[$key] ?? '';
    }

    // ══════════════════════════════════════════════════════════════════
    // Datenzugriff
    // ══════════════════════════════════════════════════════════════════

    public static function get_organizer_by_slug($slug) {
        $slug = sanitize_title($slug);
        $q = get_posts([
            'post_type'      => 'tix_organizer',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => '_tix_org_landing_slug',
            'meta_value'     => $slug,
            'no_found_rows'  => true,
        ]);
        return $q[0] ?? null;
    }

    public static function is_approved($org_id) {
        return (bool) get_post_meta($org_id, '_tix_org_landing_approved', true);
    }

    public static function get_landing_data($org) {
        $org_id = is_object($org) ? $org->ID : intval($org);

        $logo_id = intval(get_post_meta($org_id, '_tix_org_landing_logo_id', true));
        $hero_id = intval(get_post_meta($org_id, '_tix_org_landing_hero_id', true));

        // Fallback: reguläres Organizer-Bild als Hero, wenn kein Hero-Bild gesetzt
        if (!$hero_id) $hero_id = intval(get_post_meta($org_id, '_tix_org_image_id', true));

        $logo = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        $hero = $hero_id ? wp_get_attachment_image_url($hero_id, 'full')   : '';

        // Farbschema: Light oder Dark
        $color_mode = get_post_meta($org_id, '_tix_org_landing_color_mode', true) ?: 'light';

        // Primärfarbe pro Mode + Legacy-Fallback
        $primary_light = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_primary_light', true))
                      ?: sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_primary_color', true))
                      ?: '#E8445A';
        $primary_dark  = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_primary_dark', true))
                      ?: sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_primary_color', true))
                      ?: '#E8445A';

        // Hintergrundfarbe pro Mode
        $bg_light = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_bg_light', true)) ?: '#ffffff';
        $bg_dark  = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_bg_dark',  true)) ?: '#0b0a12';

        // Header/Footer-Farben pro Mode — Fallback: von Haupt-BG und -Text
        $default_text_light = '#131020';
        $default_text_dark  = '#f5f5f7';

        $header_bg_light   = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_header_bg_light',   true)) ?: $bg_light;
        $header_bg_dark    = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_header_bg_dark',    true)) ?: $bg_dark;
        $header_text_light = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_header_text_light', true)) ?: $default_text_light;
        $header_text_dark  = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_header_text_dark',  true)) ?: $default_text_dark;

        $footer_bg_light   = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_footer_bg_light',   true)) ?: self::shade_color($bg_light, 0.03);
        $footer_bg_dark    = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_footer_bg_dark',    true)) ?: self::shade_color($bg_dark,  0.08);
        $footer_text_light = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_footer_text_light', true)) ?: $default_text_light;
        $footer_text_dark  = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_footer_text_dark',  true)) ?: $default_text_dark;

        // Aktive Primärfarbe = die vom gewählten Mode
        $primary = ($color_mode === 'dark') ? $primary_dark : $primary_light;
        $bg      = ($color_mode === 'dark') ? $bg_dark      : $bg_light;

        // Legacy: Accent-Farbe bleibt für backward-compat der Landing
        $accent  = sanitize_hex_color(get_post_meta($org_id, '_tix_org_landing_accent_color',  true)) ?: '#131020';

        $desc    = get_post_meta($org_id, '_tix_org_landing_description', true)
                ?: get_post_meta($org_id, '_tix_org_description', true);
        $tagline = get_post_meta($org_id, '_tix_org_landing_tagline', true);

        $social_raw = get_post_meta($org_id, '_tix_org_landing_social', true);
        $social = is_array($social_raw) ? array_filter($social_raw) : [];

        // Fallback: bestehende Website aus Veranstalter-Kontakt
        if (empty($social['website'])) {
            $w = get_post_meta($org_id, '_tix_org_website', true);
            if ($w) $social['website'] = $w;
        }

        // Neue Felder
        $video_id        = intval(get_post_meta($org_id, '_tix_org_landing_hero_video_id', true));
        $video_url_meta  = trim(get_post_meta($org_id, '_tix_org_landing_hero_video_url', true));
        $favicon_id      = intval(get_post_meta($org_id, '_tix_org_landing_favicon_id', true));
        // URL hat Priorität vor Upload
        $video_url       = $video_url_meta ?: ($video_id ? wp_get_attachment_url($video_id) : '');
        $favicon_url     = $favicon_id ? wp_get_attachment_image_url($favicon_id, 'thumbnail') : '';
        $cta_text        = get_post_meta($org_id, '_tix_org_landing_cta_text', true) ?: 'Tickets sichern';
        $cta_url         = get_post_meta($org_id, '_tix_org_landing_cta_url',  true) ?: '#events';
        $countdown       = (bool) get_post_meta($org_id, '_tix_org_landing_countdown_enabled', true);

        // Video-Typ erkennen: mp4/webm | youtube | vimeo
        $video_type = self::detect_video_type($video_url);

        return [
            'slug'             => get_post_meta($org_id, '_tix_org_landing_slug', true),
            'logo'             => $logo,
            'hero_image'       => $hero,
            'hero_video'       => $video_url,
            'hero_video_type'  => $video_type,   // 'mp4' | 'youtube' | 'vimeo' | ''
            'favicon'          => $favicon_url,
            'cta_text'         => $cta_text,
            'cta_url'          => $cta_url,
            'countdown'        => $countdown,
            'tagline'          => $tagline,
            'description'      => $desc,
            'color_mode'       => $color_mode,
            'primary_color'    => $primary,
            'primary_light'    => $primary_light,
            'primary_dark'     => $primary_dark,
            'bg_color'         => $bg,
            'bg_light'         => $bg_light,
            'bg_dark'          => $bg_dark,
            'header_bg_light'  => $header_bg_light,
            'header_bg_dark'   => $header_bg_dark,
            'header_text_light'=> $header_text_light,
            'header_text_dark' => $header_text_dark,
            'footer_bg_light'  => $footer_bg_light,
            'footer_bg_dark'   => $footer_bg_dark,
            'footer_text_light'=> $footer_text_light,
            'footer_text_dark' => $footer_text_dark,
            'accent_color'     => $accent,
            'social'           => $social,
            'contact_email'    => get_post_meta($org_id, '_tix_org_landing_contact_email', true)
                               ?: get_post_meta($org_id, '_tix_org_email', true),
            'show_past_events' => (bool) get_post_meta($org_id, '_tix_org_landing_show_past_events', true),
        ];
    }

    /** Farb-Helper: Hex nach leichter Helligkeits-Anpassung. $amount in [-1..1] */
    public static function shade_color($hex, $amount = 0) {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) !== 6) return '#' . ($hex ?: '000000');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        // Für helle Farben: dunkler machen (amount > 0). Für dunkle: heller.
        $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        $direction = $lum > 0.5 ? -1 : 1;
        $r = max(0, min(255, intval($r + 255 * $amount * $direction)));
        $g = max(0, min(255, intval($g + 255 * $amount * $direction)));
        $b = max(0, min(255, intval($b + 255 * $amount * $direction)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /** Hex → rgba mit Alpha */
    public static function color_with_alpha($hex, $alpha = 1.0) {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) !== 6) return "rgba(255,255,255,{$alpha})";
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgba({$r},{$g},{$b},{$alpha})";
    }

    /** Erkennt den Video-Typ aus einer URL */
    public static function detect_video_type($url) {
        if (!$url) return '';
        if (preg_match('/^https?:\/\/(?:www\.)?(?:youtube\.com|youtu\.be)\//i', $url))     return 'youtube';
        if (preg_match('/^https?:\/\/(?:www\.|player\.)?vimeo\.com\//i', $url))            return 'vimeo';
        if (preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', $url))                             return 'mp4';
        // Fallback: wenn es wie ein direkt-File aussieht
        return 'mp4';
    }

    /** Extrahiert die YouTube-Video-ID */
    private static function extract_youtube_id($url) {
        if (preg_match('/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))([^&#?\/]+)/i', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Extrahiert die Vimeo-Video-ID */
    private static function extract_vimeo_id($url) {
        if (preg_match('/vimeo\.com\/(?:channels\/[^\/]+\/|video\/|)(\d+)/i', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Liefert die Embed-URL für YouTube/Vimeo mit Autoplay, Mute, Loop */
    public static function get_video_embed_url($url, $type) {
        if ($type === 'youtube') {
            $id = self::extract_youtube_id($url);
            if (!$id) return '';
            // playlist=VIDEO_ID ist für loop zwingend
            return 'https://www.youtube.com/embed/' . $id
                . '?autoplay=1&mute=1&loop=1&playlist=' . $id
                . '&controls=0&showinfo=0&rel=0&modestbranding=1&iv_load_policy=3&playsinline=1';
        }
        if ($type === 'vimeo') {
            $id = self::extract_vimeo_id($url);
            if (!$id) return '';
            return 'https://player.vimeo.com/video/' . $id
                . '?autoplay=1&muted=1&loop=1&background=1&controls=0';
        }
        return '';
    }

    public static function canonical_url($slug) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $use_subdomain = !empty($s['landing_use_subdomain']);
        if ($use_subdomain) {
            // Wichtig: get_option('home') statt home_url() — letzteres liefert auf
            // Subdomains schon den Subdomain-Host → würde doppelt werden
            $host = parse_url(get_option('home'), PHP_URL_HOST);
            $host = preg_replace('/^www\./', '', $host);
            return 'https://' . $slug . '.' . $host . '/';
        }
        $main = get_option('home');
        return rtrim($main, '/') . '/v/' . $slug . '/';
    }

    // ══════════════════════════════════════════════════════════════════
    // Attribution
    // ══════════════════════════════════════════════════════════════════

    private static function set_attribution_cookie($slug) {
        if (!$slug || headers_sent()) return;
        $cookie = 'tix_ol_src';
        // 7 Tage gültig, SameSite=Lax
        setcookie($cookie, $slug, [
            'expires'  => time() + 7 * DAY_IN_SECONDS,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_ssl(),
            'httponly' => false, // JS kann lesen (z.B. für Analytics)
            'samesite' => 'Lax',
        ]);
    }

    public static function maybe_set_attribution_cookie() {
        $slug = get_query_var(self::QUERY_VAR);
        if ($slug) self::set_attribution_cookie($slug);
    }

    /** Order-Hook (native Tixomat-Orders) */
    public static function save_attribution_to_order($order_id) {
        if (!empty($_COOKIE['tix_ol_src'])) {
            $slug = sanitize_title($_COOKIE['tix_ol_src']);
            if ($slug) {
                update_post_meta($order_id, '_tix_ol_source', $slug);
            }
        }
    }

    /** WC-Order-Filter */
    public static function save_attribution_to_wc_order($data) {
        if (!empty($_COOKIE['tix_ol_src'])) {
            $data['_tix_ol_source'] = sanitize_title($_COOKIE['tix_ol_src']);
        }
        return $data;
    }

    // ══════════════════════════════════════════════════════════════════
    // Admin-Metabox: Admin-Approval + Slug
    // ══════════════════════════════════════════════════════════════════

    public static function add_meta_box() {
        add_meta_box(
            'tix_org_landing',
            'Landingpage',
            [__CLASS__, 'render_meta_box'],
            'tix_organizer',
            'normal',   // Full-width Block (Tixomat versteckt side-Metaboxen teilweise)
            'low'       // Unter "Veranstalter Details"
        );
    }

    public static function render_meta_box($post) {
        // Nur einmal rendern, falls sowohl edit_form_after_title als auch metabox feuern
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;

        wp_nonce_field('tix_org_landing_save', 'tix_org_landing_nonce');
        $approved = (bool) get_post_meta($post->ID, '_tix_org_landing_approved', true);
        $slug     = get_post_meta($post->ID, '_tix_org_landing_slug', true);
        $is_admin = current_user_can('manage_options');
        $enabled  = self::is_feature_enabled();
        ?>
        <style>
        #tix_org_landing .inside { padding:0; margin:0; }
        .tix-ol-mb { padding:20px; font-size:13px; display:grid; grid-template-columns:repeat(2,1fr); gap:20px; }
        .tix-ol-mb .row { margin:0; }
        .tix-ol-mb .row.full { grid-column:1 / -1; }
        .tix-ol-mb label { display:block; font-weight:600; margin-bottom:6px; color:#131020; font-size:13px; }
        .tix-ol-mb input[type="text"] { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; }
        .tix-ol-mb .hint { font-size:11px; color:#6b7280; margin-top:6px; line-height:1.4; }
        .tix-ol-mb .status-box { padding:12px 14px; border-radius:8px; font-size:13px; font-weight:600; }
        .tix-ol-mb .status-on  { background:#d1fae5; color:#065f46; }
        .tix-ol-mb .status-off { background:#fef3c7; color:#92400e; }
        .tix-ol-mb .tix-ol-toggle { display:flex; align-items:center; gap:10px; cursor:pointer; user-select:none; font-weight:600; }
        .tix-ol-mb .tix-ol-toggle input { margin:0; width:18px; height:18px; }
        .tix-ol-mb .preview-btn { display:inline-block; padding:8px 16px; background:#E8445A; color:#fff; border-radius:6px; text-decoration:none; font-weight:600; font-size:13px; }
        .tix-ol-mb .preview-btn:hover { background:#d13a4f; }
        .tix-ol-mb .slug-status { font-size:12px; margin-top:6px; min-height:14px; font-weight:600; }
        .tix-ol-mb .slug-status.ok   { color:#059669; }
        .tix-ol-mb .slug-status.bad  { color:#ef4444; }
        .tix-ol-mb .url-preview { padding:10px 12px; background:#f3f4f6; border-radius:6px; font-family:monospace; font-size:11px; word-break:break-all; line-height:1.8; }
        @media (max-width: 900px) { .tix-ol-mb { grid-template-columns:1fr; } }
        </style>

        <div class="tix-ol-mb">
            <?php if (!$enabled): ?>
                <div class="row full">
                    <div class="status-box status-off">
                        Landingpage-Feature auf dieser Seite nicht aktiv.<br>
                        (Aktivierbar in Tixomat-Einstellungen → <em>landing_pages_enabled</em>, oder automatisch auf evendis.de)
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($is_admin): ?>
                <div class="row">
                    <label class="tix-ol-toggle">
                        <input type="checkbox" name="tix_org_landing_approved" value="1" <?php checked($approved); ?>>
                        <span>Landingpage freischalten</span>
                    </label>
                    <div class="hint">Nur Admins können diese Option setzen. Der Veranstalter kann danach seine Landingpage selbst gestalten.</div>
                </div>
            <?php else: ?>
                <div class="row full">
                    <div class="status-box <?php echo $approved ? 'status-on' : 'status-off'; ?>">
                        <?php echo $approved ? '✓ Landingpage ist freigeschaltet' : 'Landingpage ist noch nicht freigeschaltet. Wende dich an den Support.'; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row">
                <label for="tix_org_landing_slug">URL-Slug</label>
                <input type="text"
                       id="tix_org_landing_slug"
                       name="tix_org_landing_slug"
                       value="<?php echo esc_attr($slug); ?>"
                       placeholder="z.B. kitchen-klub"
                       pattern="[a-z0-9-]+"
                       <?php echo !$is_admin ? 'readonly' : ''; ?>>
                <div class="slug-status" id="tix-ol-slug-status"></div>
                <div class="hint">
                    Nur Kleinbuchstaben, Ziffern und Bindestriche.
                    <?php if (!$is_admin): ?>Änderungen nur durch Admin.<?php endif; ?>
                </div>
            </div>

            <?php if ($slug): ?>
                <div class="row full">
                    <label>URL-Vorschau</label>
                    <div class="url-preview">
                        <strong>Pfad:</strong>&nbsp;&nbsp;<?php echo esc_html(home_url('/v/' . $slug . '/')); ?><br>
                        <strong>Subdomain (Phase 2):</strong>&nbsp;&nbsp;https://<?php echo esc_html($slug); ?>.<?php echo esc_html(preg_replace('/^www\./','',parse_url(home_url(),PHP_URL_HOST))); ?>/
                    </div>
                </div>
            <?php endif; ?>

            <div class="row full">
                <?php if ($slug && $approved): ?>
                    <a class="preview-btn" href="<?php echo esc_url(home_url('/v/' . $slug . '/')); ?>" target="_blank">
                        Landingpage ansehen →
                    </a>
                <?php endif; ?>
                <?php if ($is_admin): ?>
                    <a class="preview-btn" style="background:#131020;margin-left:8px;"
                       href="<?php echo esc_url(admin_url('admin.php?page=tix-organizer-landing&org=' . $post->ID)); ?>">
                        Design &amp; Inhalte bearbeiten →
                    </a>
                    <div class="hint">Logo, Hero-Bild, Farben, Social Links, Beschreibung auf einer eigenen Seite.</div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function(){
            var input = document.getElementById('tix_org_landing_slug');
            if (!input) return;
            var status = document.getElementById('tix-ol-slug-status');
            var postId = <?php echo intval($post->ID); ?>;
            var timer = null;

            input.addEventListener('input', function(){
                clearTimeout(timer);
                var val = input.value.trim().toLowerCase();
                if (!val) { status.textContent = ''; return; }
                if (!/^[a-z0-9-]+$/.test(val)) {
                    status.className = 'slug-status bad';
                    status.textContent = 'Nur a-z, 0-9 und - erlaubt';
                    return;
                }
                status.textContent = 'Prüfe…';
                status.className = 'slug-status';
                timer = setTimeout(function(){
                    var fd = new FormData();
                    fd.append('action', 'tix_org_landing_slug_check');
                    fd.append('slug', val);
                    fd.append('post_id', postId);
                    fd.append('_wpnonce', '<?php echo wp_create_nonce('tix_org_landing_slug_check'); ?>');
                    fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                        .then(function(r){return r.json();})
                        .then(function(d){
                            if (d.success) {
                                status.className = 'slug-status ok';
                                status.textContent = '✓ verfügbar';
                            } else {
                                status.className = 'slug-status bad';
                                status.textContent = '✗ ' + (d.data && d.data.message ? d.data.message : 'nicht verfügbar');
                            }
                        });
                }, 400);
            });
        })();
        </script>
        <?php
    }

    public static function save_meta($post_id, $post) {
        if (!isset($_POST['tix_org_landing_nonce'])) return;
        if (!wp_verify_nonce($_POST['tix_org_landing_nonce'], 'tix_org_landing_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if ($post->post_type !== 'tix_organizer') return;

        // Nur Admin darf Approval + Slug setzen
        if (current_user_can('manage_options')) {
            update_post_meta($post_id, '_tix_org_landing_approved', !empty($_POST['tix_org_landing_approved']) ? 1 : 0);

            $new_slug = sanitize_title($_POST['tix_org_landing_slug'] ?? '');
            if ($new_slug && !in_array($new_slug, self::RESERVED_SLUGS, true)) {
                // Unique check (ausser eigener Post)
                $taken = self::slug_is_taken($new_slug, $post_id);
                if (!$taken) {
                    update_post_meta($post_id, '_tix_org_landing_slug', $new_slug);
                    // Rewrite-Rules flushen, weil neue Slug-URL hinzukommt
                    flush_rewrite_rules(false);
                }
            } elseif ($new_slug === '') {
                delete_post_meta($post_id, '_tix_org_landing_slug');
            }
        }
    }

    public static function ajax_slug_check() {
        check_ajax_referer('tix_org_landing_slug_check');
        $slug    = sanitize_title($_POST['slug'] ?? '');
        $post_id = intval($_POST['post_id'] ?? 0);

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            wp_send_json_error(['message' => 'reservierter Name']);
        }
        if (self::slug_is_taken($slug, $post_id)) {
            wp_send_json_error(['message' => 'bereits vergeben']);
        }
        wp_send_json_success();
    }

    private static function slug_is_taken($slug, $exclude_post_id = 0) {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_tix_org_landing_slug' AND meta_value = %s
             LIMIT 1",
            $slug
        ));
        if (!$id) return false;
        return intval($id) !== intval($exclude_post_id);
    }

    public static function admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;

        // Organizer-Self-Service Seite → WP Media-Uploader laden
        if ($screen->id === 'tixomat_page_tix-organizer-landing') {
            wp_enqueue_media();
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            wp_enqueue_script('jquery-ui-sortable'); // für Drag&Drop Section-Builder
        }
    }

    public static function register_allowed_page($pages) {
        $pages[] = 'tix-organizer-landing';
        return $pages;
    }

    // ══════════════════════════════════════════════════════════════════
    // Organizer-Self-Service Admin-Page
    // ══════════════════════════════════════════════════════════════════

    public static function register_organizer_page() {
        add_submenu_page(
            'tixomat',
            'Meine Landingpage',
            'Meine Landingpage',
            'edit_posts',
            'tix-organizer-landing',
            [__CLASS__, 'render_organizer_page']
        );
    }

    /**
     * Liefert die Organizer-ID des aktuell eingeloggten Users (falls Organizer).
     */
    private static function get_current_organizer_id() {
        $uid = get_current_user_id();
        if (!$uid) return 0;

        // Admin → nutzt aktuell "bearbeiteten" Organizer via ?org= Query
        if (current_user_can('manage_options') && !empty($_GET['org'])) {
            return intval($_GET['org']);
        }

        // Suche Organizer der diesem User zugeordnet ist
        $posts = get_posts([
            'post_type'      => 'tix_organizer',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_key'       => '_tix_org_user_id',
            'meta_value'     => $uid,
            'no_found_rows'  => true,
        ]);
        return !empty($posts) ? intval($posts[0]->ID) : 0;
    }

    public static function render_organizer_page() {
        $org_id = self::get_current_organizer_id();
        $org    = $org_id ? get_post($org_id) : null;

        if (!$org || $org->post_type !== 'tix_organizer') {
            echo '<div class="wrap"><h1>Meine Landingpage</h1><p>Kein Veranstalter-Profil gefunden.</p></div>';
            return;
        }

        $approved = (bool) get_post_meta($org_id, '_tix_org_landing_approved', true);
        $slug     = get_post_meta($org_id, '_tix_org_landing_slug', true);
        $d        = self::get_landing_data($org_id);

        $social = is_array($d['social']) ? $d['social'] : [];

        ?>
        <div class="wrap tix-ol-settings">
            <h1>Meine Landingpage</h1>

            <?php if (!$approved): ?>
                <div class="notice notice-warning">
                    <p><strong>Deine Landingpage ist noch nicht freigeschaltet.</strong> Sobald das Tixomat-Team sie aktiviert, kannst du sie hier live schalten. Die Einstellungen kannst du aber jetzt schon vorbereiten.</p>
                </div>
            <?php elseif (!$slug): ?>
                <div class="notice notice-warning">
                    <p>Freigeschaltet, aber kein URL-Slug gesetzt. Das Tixomat-Team wird sich darum kümmern.</p>
                </div>
            <?php else: ?>
                <?php $url = home_url('/v/' . $slug . '/'); ?>
                <div class="notice notice-success">
                    <p>
                        Deine Landingpage ist live:
                        <a href="<?php echo esc_url($url); ?>" target="_blank" style="font-weight:700;"><?php echo esc_html($url); ?> ↗</a>
                    </p>
                </div>
            <?php endif; ?>

            <form id="tix-ol-form">
                <?php wp_nonce_field('tix_org_landing_save', 'tix_ol_nonce'); ?>
                <input type="hidden" name="org_id" value="<?php echo esc_attr($org_id); ?>">

                <style>
                .tix-ol-settings .card-row { display:grid; grid-template-columns: repeat(2, 1fr); gap:20px; margin-bottom:20px; }
                .tix-ol-settings .card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:24px; }
                .tix-ol-settings .card.full { grid-column: 1 / -1; }
                .tix-ol-settings .card h2 { margin:0 0 6px; font-size:17px; font-family:"Sora",sans-serif; color:#131020; }
                .tix-ol-settings .card .desc { margin:0 0 18px; color:#6b7280; font-size:13px; }
                .tix-ol-settings .field { margin-bottom:16px; }
                .tix-ol-settings .field:last-child { margin-bottom:0; }
                .tix-ol-settings label { display:block; font-weight:600; font-size:13px; color:#131020; margin-bottom:6px; }
                .tix-ol-settings input[type=text], .tix-ol-settings input[type=email], .tix-ol-settings input[type=url], .tix-ol-settings textarea {
                    width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; font-family:inherit;
                }
                .tix-ol-settings textarea { min-height:120px; resize:vertical; }
                .tix-ol-settings .hint { font-size:11px; color:#9ca3af; margin-top:4px; }
                .tix-ol-settings .char-count { font-size:11px; color:#9ca3af; float:right; }

                /* Image picker */
                .tix-ol-img-picker { display:flex; gap:12px; align-items:flex-start; }
                .tix-ol-img-preview {
                    width:120px; height:120px; border-radius:12px; background:#f3f4f6;
                    background-size:cover; background-position:center; flex-shrink:0;
                    border:1px solid #e5e7eb; position:relative; overflow:hidden;
                }
                .tix-ol-img-preview.empty::before {
                    content:"🖼"; position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                    font-size:32px; opacity:0.2;
                }
                .tix-ol-img-actions { display:flex; flex-direction:column; gap:6px; }
                .tix-ol-img-actions .button { margin:0; }

                /* Switch */
                .tix-ol-switch { display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal; }
                .tix-ol-switch input { position:relative; width:40px; height:22px; appearance:none; background:#d1d5db; border-radius:11px; cursor:pointer; transition:background .15s; margin:0; }
                .tix-ol-switch input::before { content:""; position:absolute; top:2px; left:2px; width:18px; height:18px; background:#fff; border-radius:50%; transition:transform .15s; }
                .tix-ol-switch input:checked { background: var(--tix-ol-primary, #E8445A); }
                .tix-ol-switch input:checked::before { transform: translateX(18px); }

                /* Social rows */
                .tix-ol-social-row { display:grid; grid-template-columns:100px 1fr; gap:10px; align-items:center; margin-bottom:8px; }
                .tix-ol-social-row label { margin:0; font-size:12px; color:#6b7280; }

                /* Mode-Chips */
                .tix-ol-mode-chip { display:inline-flex; align-items:center; gap:8px; padding:10px 18px; border:2px solid #e5e7eb; border-radius:10px; cursor:pointer; font-weight:600; font-size:13px; transition:all .15s; }
                .tix-ol-mode-chip input { display:none; }
                .tix-ol-mode-chip:hover { border-color:#d1d5db; }
                .tix-ol-mode-chip.active { border-color:#E8445A; background:#E8445A; color:#fff; }

                .tix-ol-save-bar {
                    position: sticky; bottom:0; left:0; right:0;
                    background:#fff; padding:16px 24px; border-top:1px solid #e5e7eb;
                    display:flex; justify-content:space-between; align-items:center;
                    margin:24px -20px 0; z-index:10;
                }
                .tix-ol-save-bar .tix-ol-save-status { font-size:13px; color:#6b7280; }
                .tix-ol-save-bar button.tix-ol-save {
                    padding:10px 24px; background:#E8445A; color:#fff; border:0; border-radius:8px;
                    font-weight:700; cursor:pointer; font-size:14px;
                }
                .tix-ol-save-bar button.tix-ol-save:disabled { opacity:.5; cursor:not-allowed; }

                @media (max-width: 960px) {
                    .tix-ol-settings .card-row { grid-template-columns: 1fr; }
                }
                </style>

                <?php // Analytics-Snapshot ganz oben ?>
                <?php self::render_analytics_widget($org_id); ?>

                <div class="card-row">
                    <?php // Logo ?>
                    <div class="card">
                        <h2>Logo</h2>
                        <p class="desc">Wird im Hero und im Sticky-Header prominent angezeigt.</p>
                        <?php self::render_image_field('landing_logo_id', intval(get_post_meta($org_id, '_tix_org_landing_logo_id', true))); ?>
                    </div>

                    <?php // Favicon ?>
                    <div class="card">
                        <h2>Favicon</h2>
                        <p class="desc">Icon im Browser-Tab. Quadratisch (z.B. 512×512). PNG oder ICO.</p>
                        <?php self::render_image_field('landing_favicon_id', intval(get_post_meta($org_id, '_tix_org_landing_favicon_id', true))); ?>
                    </div>
                </div>

                <div class="card-row">
                    <?php // Hero-Bild ?>
                    <div class="card">
                        <h2>Hero-Hintergrund (Bild)</h2>
                        <p class="desc">Fallback-Bild für den Hero. Am besten quer, mind. 1920×1080.</p>
                        <?php self::render_image_field('landing_hero_id', intval(get_post_meta($org_id, '_tix_org_landing_hero_id', true))); ?>
                    </div>

                    <?php // Hero-Video ?>
                    <div class="card">
                        <h2>Hero-Video (optional)</h2>
                        <p class="desc">Wenn gesetzt, wird das Video statt des Bildes angezeigt (autoplay, stumm, loop). Unterstützt werden: MP4-Upload, MP4-URL, YouTube-Link, Vimeo-Link.</p>
                        <?php self::render_video_field('landing_hero_video_id', intval(get_post_meta($org_id, '_tix_org_landing_hero_video_id', true))); ?>
                        <div class="field" style="margin-top:16px;">
                            <label>oder Video-URL einfügen</label>
                            <input type="url" name="landing_hero_video_url"
                                   value="<?php echo esc_attr(get_post_meta($org_id, '_tix_org_landing_hero_video_url', true)); ?>"
                                   placeholder="https://... (MP4, YouTube, Vimeo)"
                                   style="width:100%;">
                            <div class="hint">
                                Eingetragene URL hat Vorrang vor hochgeladenem Video.<br>
                                YouTube-Beispiel: <code>https://www.youtube.com/watch?v=...</code><br>
                                Vimeo-Beispiel: <code>https://vimeo.com/...</code>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card full">
                    <h2>Call-to-Action Button (Hero)</h2>
                    <p class="desc">Der große Button im Hero. Standardmäßig "Tickets sichern" und scrollt zu den Events (<code>#events</code>).</p>
                    <div class="card-row" style="margin:0;gap:16px;">
                        <div class="field">
                            <label>Button-Text</label>
                            <input type="text" name="landing_cta_text"
                                   value="<?php echo esc_attr(get_post_meta($org_id, '_tix_org_landing_cta_text', true)); ?>"
                                   placeholder="Tickets sichern" maxlength="40">
                        </div>
                        <div class="field">
                            <label>Button-Ziel (URL oder #anker)</label>
                            <input type="text" name="landing_cta_url"
                                   value="<?php echo esc_attr(get_post_meta($org_id, '_tix_org_landing_cta_url', true)); ?>"
                                   placeholder="#events"
                                   pattern="(https?://.*|#.*)">
                            <div class="hint">Leer = <code>#events</code>. Externe URL möglich (z.B. zum Newsletter).</div>
                        </div>
                    </div>
                </div>

                <div class="card full">
                    <h2>Countdown zum nächsten Event</h2>
                    <p class="desc">Zeigt im Hero einen Live-Zähler bis zum Start des nächsten Events. Erzeugt Urgency.</p>
                    <label class="tix-ol-switch">
                        <input type="checkbox" name="landing_countdown_enabled" value="1"
                               <?php checked((bool)get_post_meta($org_id, '_tix_org_landing_countdown_enabled', true)); ?>>
                        <span>Countdown im Hero anzeigen</span>
                    </label>
                </div>

                <div class="card full">
                    <h2>Kurzer Einleitungstext</h2>
                    <p class="desc">1 Zeile unter deinem Namen — die prägnante Beschreibung (Tagline).</p>
                    <div class="field">
                        <input type="text" name="landing_tagline" id="landing_tagline"
                               maxlength="100"
                               value="<?php echo esc_attr(get_post_meta($org_id, '_tix_org_landing_tagline', true)); ?>"
                               placeholder="z.B. Club, Konzert & Event-Location in Remscheid">
                        <div class="hint"><span id="tagline-count">0</span>/100 Zeichen</div>
                    </div>
                </div>

                <div class="card full">
                    <h2>Über uns</h2>
                    <p class="desc">Ausführliche Beschreibung (erscheint als eigene Sektion unter dem Hero).</p>
                    <div class="field">
                        <textarea name="landing_description" rows="6"><?php echo esc_textarea(get_post_meta($org_id, '_tix_org_landing_description', true)); ?></textarea>
                    </div>
                </div>

                <div class="card full">
                    <h2>Farbschema</h2>
                    <p class="desc">Wähle Light oder Dark Mode — Schriften und Layout bleiben wie auf evendis.de, nur die Farben werden ersetzt. Beide Primärfarben speichern, falls du später umschaltest.</p>

                    <div class="field">
                        <label>Modus</label>
                        <div style="display:flex;gap:8px;">
                            <label class="tix-ol-mode-chip <?php echo ($d['color_mode'] === 'light') ? 'active' : ''; ?>">
                                <input type="radio" name="landing_color_mode" value="light" <?php checked($d['color_mode'], 'light'); ?>>
                                <span>☀ Light</span>
                            </label>
                            <label class="tix-ol-mode-chip <?php echo ($d['color_mode'] === 'dark') ? 'active' : ''; ?>">
                                <input type="radio" name="landing_color_mode" value="dark" <?php checked($d['color_mode'], 'dark'); ?>>
                                <span>☾ Dark</span>
                            </label>
                        </div>
                    </div>

                    <div class="card-row" style="margin:16px 0 0;gap:16px;">
                        <div style="padding:16px;background:<?php echo esc_attr($d['bg_light']); ?>;color:#131020;border:1px solid #e5e7eb;border-radius:12px;">
                            <div style="font-weight:600;margin-bottom:10px;font-size:13px;">☀ Light Mode</div>
                            <div style="margin-bottom:12px;">
                                <label style="font-size:11px;color:#6b7280;display:block;margin-bottom:4px;">Primärfarbe</label>
                                <input type="text" name="landing_primary_light" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['primary_light']); ?>"
                                       data-default-color="#E8445A">
                            </div>
                            <div>
                                <label style="font-size:11px;color:#6b7280;display:block;margin-bottom:4px;">Hintergrundfarbe</label>
                                <input type="text" name="landing_bg_light" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['bg_light']); ?>"
                                       data-default-color="#ffffff">
                            </div>
                        </div>
                        <div style="padding:16px;background:<?php echo esc_attr($d['bg_dark']); ?>;color:#f5f5f7;border:1px solid #1f1e2a;border-radius:12px;">
                            <div style="font-weight:600;margin-bottom:10px;font-size:13px;">☾ Dark Mode</div>
                            <div style="margin-bottom:12px;">
                                <label style="font-size:11px;color:#a0a0a8;display:block;margin-bottom:4px;">Primärfarbe</label>
                                <input type="text" name="landing_primary_dark" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['primary_dark']); ?>"
                                       data-default-color="#E8445A">
                            </div>
                            <div>
                                <label style="font-size:11px;color:#a0a0a8;display:block;margin-bottom:4px;">Hintergrundfarbe</label>
                                <input type="text" name="landing_bg_dark" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['bg_dark']); ?>"
                                       data-default-color="#0b0a12">
                            </div>
                        </div>
                    </div>
                    <div class="hint" style="margin-top:12px;">Textfarbe + Border werden passend zum Mode automatisch gesetzt. Der Surface-Ton (Cards) wird leicht vom Hintergrund abgeleitet.</div>
                </div>

                <?php // Header-Farben pro Mode ?>
                <div class="card full">
                    <h2>Header</h2>
                    <p class="desc">Der Sticky-Header oben. Leer lassen → automatisch wie Seiten-Hintergrund.</p>
                    <div class="card-row" style="margin:0;gap:16px;">
                        <div style="padding:16px;background:<?php echo esc_attr($d['header_bg_light']); ?>;color:<?php echo esc_attr($d['header_text_light']); ?>;border:1px solid #e5e7eb;border-radius:12px;">
                            <div style="font-weight:600;margin-bottom:10px;font-size:13px;">☀ Light Mode</div>
                            <div style="margin-bottom:12px;">
                                <label style="font-size:11px;opacity:.7;display:block;margin-bottom:4px;">Hintergrund</label>
                                <input type="text" name="landing_header_bg_light" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['header_bg_light']); ?>" data-default-color="#ffffff">
                            </div>
                            <div>
                                <label style="font-size:11px;opacity:.7;display:block;margin-bottom:4px;">Schrift</label>
                                <input type="text" name="landing_header_text_light" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['header_text_light']); ?>" data-default-color="#131020">
                            </div>
                        </div>
                        <div style="padding:16px;background:<?php echo esc_attr($d['header_bg_dark']); ?>;color:<?php echo esc_attr($d['header_text_dark']); ?>;border:1px solid #1f1e2a;border-radius:12px;">
                            <div style="font-weight:600;margin-bottom:10px;font-size:13px;">☾ Dark Mode</div>
                            <div style="margin-bottom:12px;">
                                <label style="font-size:11px;opacity:.7;display:block;margin-bottom:4px;">Hintergrund</label>
                                <input type="text" name="landing_header_bg_dark" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['header_bg_dark']); ?>" data-default-color="#0b0a12">
                            </div>
                            <div>
                                <label style="font-size:11px;opacity:.7;display:block;margin-bottom:4px;">Schrift</label>
                                <input type="text" name="landing_header_text_dark" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['header_text_dark']); ?>" data-default-color="#f5f5f7">
                            </div>
                        </div>
                    </div>
                </div>

                <?php // Footer-Farben pro Mode ?>
                <div class="card full">
                    <h2>Footer</h2>
                    <p class="desc">Der Footer-Bereich unten. Leer lassen → automatisch vom Hintergrund abgeleitet.</p>
                    <div class="card-row" style="margin:0;gap:16px;">
                        <div style="padding:16px;background:<?php echo esc_attr($d['footer_bg_light']); ?>;color:<?php echo esc_attr($d['footer_text_light']); ?>;border:1px solid #e5e7eb;border-radius:12px;">
                            <div style="font-weight:600;margin-bottom:10px;font-size:13px;">☀ Light Mode</div>
                            <div style="margin-bottom:12px;">
                                <label style="font-size:11px;opacity:.7;display:block;margin-bottom:4px;">Hintergrund</label>
                                <input type="text" name="landing_footer_bg_light" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['footer_bg_light']); ?>" data-default-color="#f9fafb">
                            </div>
                            <div>
                                <label style="font-size:11px;opacity:.7;display:block;margin-bottom:4px;">Schrift</label>
                                <input type="text" name="landing_footer_text_light" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['footer_text_light']); ?>" data-default-color="#131020">
                            </div>
                        </div>
                        <div style="padding:16px;background:<?php echo esc_attr($d['footer_bg_dark']); ?>;color:<?php echo esc_attr($d['footer_text_dark']); ?>;border:1px solid #1f1e2a;border-radius:12px;">
                            <div style="font-weight:600;margin-bottom:10px;font-size:13px;">☾ Dark Mode</div>
                            <div style="margin-bottom:12px;">
                                <label style="font-size:11px;opacity:.7;display:block;margin-bottom:4px;">Hintergrund</label>
                                <input type="text" name="landing_footer_bg_dark" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['footer_bg_dark']); ?>" data-default-color="#161522">
                            </div>
                            <div>
                                <label style="font-size:11px;opacity:.7;display:block;margin-bottom:4px;">Schrift</label>
                                <input type="text" name="landing_footer_text_dark" class="tix-color-picker"
                                       value="<?php echo esc_attr($d['footer_text_dark']); ?>" data-default-color="#f5f5f7">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card full">
                    <h2>Kontakt &amp; Optionen</h2>
                    <div class="card-row" style="margin:0;gap:16px;">
                        <div class="field">
                            <label>Kontakt-E-Mail (im Footer)</label>
                            <input type="email" name="landing_contact_email"
                                   value="<?php echo esc_attr($d['contact_email']); ?>"
                                   placeholder="info@...">
                        </div>
                        <div class="field">
                            <label class="tix-ol-switch">
                                <input type="checkbox" name="landing_show_past_events" value="1" <?php checked($d['show_past_events']); ?>>
                                <span>Vergangene Events anzeigen</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card full">
                    <h2>Social Media</h2>
                    <p class="desc">Links erscheinen im Footer. Leere Felder werden ignoriert.</p>
                    <?php
                    $channels = [
                        'website'   => ['Website', 'https://...'],
                        'instagram' => ['Instagram', 'https://instagram.com/...'],
                        'facebook'  => ['Facebook', 'https://facebook.com/...'],
                        'tiktok'    => ['TikTok', 'https://tiktok.com/@...'],
                        'x'         => ['X / Twitter', 'https://x.com/...'],
                        'youtube'   => ['YouTube', 'https://youtube.com/@...'],
                        'spotify'   => ['Spotify', 'https://open.spotify.com/artist/...'],
                    ];
                    foreach ($channels as $key => [$label, $placeholder]):
                        $val = $social[$key] ?? '';
                    ?>
                        <div class="tix-ol-social-row">
                            <label><?php echo esc_html($label); ?></label>
                            <input type="url" name="landing_social[<?php echo esc_attr($key); ?>]"
                                   value="<?php echo esc_attr($val); ?>"
                                   placeholder="<?php echo esc_attr($placeholder); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php // Section-Builder ?>
                <div class="card full">
                    <h2>Sektionen-Reihenfolge</h2>
                    <p class="desc">Ziehe die Sektionen in die gewünschte Reihenfolge. Schalte einzelne an/aus. Der Hero bleibt immer oben.</p>

                    <ul id="tix-ol-sections-sortable" class="tix-ol-sections-list">
                        <?php
                        $registry = self::sections_registry();
                        $config   = self::get_sections_config($org_id);
                        foreach ($config as $sec):
                            $id = $sec['id'];
                            if (!isset($registry[$id])) continue;
                            $label   = $registry[$id]['label'];
                            $icon    = $registry[$id]['icon'];
                            $enabled = !empty($sec['enabled']);
                        ?>
                        <li class="tix-ol-section-item" data-section-id="<?php echo esc_attr($id); ?>">
                            <span class="tix-ol-drag-handle" title="Ziehen zum Sortieren">⋮⋮</span>
                            <span class="tix-ol-section-icon"><?php echo $icon; ?></span>
                            <span class="tix-ol-section-label"><?php echo esc_html($label); ?></span>
                            <label class="tix-ol-switch">
                                <input type="checkbox" class="tix-ol-section-enabled" <?php checked($enabled); ?>>
                                <span><?php echo $enabled ? 'Aktiv' : 'Aus'; ?></span>
                            </label>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <input type="hidden" name="landing_sections_json" id="landing-sections-json" value="">
                </div>

                <?php // Partners-Repeater ?>
                <div class="card full">
                    <h2>Partner &amp; Sponsoren</h2>
                    <p class="desc">Logos werden als horizontaler Strip angezeigt (zentriert, mit Graustufen → Farbe bei Hover).</p>
                    <div class="field">
                        <label>Sektions-Überschrift</label>
                        <input type="text" name="landing_partners_heading"
                               value="<?php echo esc_attr(get_post_meta($org_id, '_tix_org_landing_partners_heading', true)); ?>"
                               placeholder="Unsere Partner">
                    </div>
                    <div id="tix-ol-partners-repeater"></div>
                    <button type="button" class="button button-secondary" id="tix-ol-partners-add">+ Partner hinzufügen</button>
                    <input type="hidden" name="landing_partners_json" id="landing-partners-json" value="">
                </div>

                <style>
                .tix-ol-sections-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:6px; }
                .tix-ol-section-item {
                    display:flex; align-items:center; gap:12px;
                    padding:12px 14px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;
                    user-select:none;
                }
                .tix-ol-drag-handle { cursor:grab; color:#9ca3af; font-size:16px; line-height:1; font-weight:700; }
                .tix-ol-drag-handle:active { cursor:grabbing; }
                .tix-ol-section-icon { font-size:18px; }
                .tix-ol-section-label { flex:1; font-weight:600; }
                .ui-sortable-placeholder { visibility:visible !important; background:#f3f4f6 !important; border:2px dashed #d1d5db !important; }
                .ui-sortable-helper { box-shadow:0 8px 24px rgba(0,0,0,0.15); }

                /* Repeater */
                .tix-ol-rep-row {
                    display:grid; grid-template-columns:90px 1fr auto; gap:12px;
                    background:#fff; border:1px solid #e5e7eb; border-radius:10px;
                    padding:12px; margin-bottom:10px;
                }
                .tix-ol-rep-img {
                    width:80px; height:80px; background:#f3f4f6; border-radius:8px;
                    background-size:cover; background-position:center;
                    display:flex; align-items:center; justify-content:center;
                    font-size:24px; color:#d1d5db; cursor:pointer;
                    border:1px dashed #d1d5db;
                }
                .tix-ol-rep-img.has-image { border-style:solid; }
                .tix-ol-rep-fields { display:grid; gap:8px; }
                .tix-ol-rep-fields input, .tix-ol-rep-fields textarea {
                    width:100%; padding:8px 10px; border:1px solid #d1d5db;
                    border-radius:6px; font-size:13px; font-family:inherit;
                }
                .tix-ol-rep-fields textarea { min-height:60px; resize:vertical; }
                .tix-ol-rep-fields .tix-ol-rep-socials { display:grid; grid-template-columns:repeat(5,1fr); gap:6px; }
                .tix-ol-rep-remove {
                    background:none; border:none; color:#ef4444; font-size:20px; cursor:pointer;
                    padding:8px; border-radius:6px; height:36px; align-self:start;
                }
                .tix-ol-rep-remove:hover { background:#fee2e2; }
                </style>

                <div class="tix-ol-save-bar">
                    <div class="tix-ol-save-status" id="tix-ol-save-status"></div>
                    <button type="button" class="tix-ol-save" id="tix-ol-save-btn">Speichern</button>
                </div>
            </form>
        </div>

        <script>
        // Initialdaten aus PHP an JS reichen
        var TIX_OL_PARTNERS_INIT = <?php echo wp_json_encode(get_post_meta($org_id, '_tix_org_landing_partners', true) ?: []); ?>;
        </script>
        <script>
        jQuery(function($){
            // Color pickers
            $('.tix-color-picker').each(function(){
                $(this).wpColorPicker();
            });

            // Mode-Chip Toggle (Light/Dark)
            $(document).on('change', 'input[name="landing_color_mode"]', function(){
                var val = $(this).val();
                $('.tix-ol-mode-chip').removeClass('active');
                $(this).closest('.tix-ol-mode-chip').addClass('active');
            });
            // Click auf Chip soll auch toggeln (da input display:none)
            $(document).on('click', '.tix-ol-mode-chip', function(e){
                if (e.target.tagName === 'INPUT') return;
                var $input = $(this).find('input[type=radio]');
                $input.prop('checked', true).trigger('change');
            });

            // Char counter
            var tag = document.getElementById('landing_tagline');
            var tcnt = document.getElementById('tagline-count');
            function updateCount() { if (tcnt && tag) tcnt.textContent = tag.value.length; }
            if (tag) { tag.addEventListener('input', updateCount); updateCount(); }

            // Image pickers
            $('.tix-ol-img-select').on('click', function(e){
                e.preventDefault();
                var $btn = $(this);
                var inputId = $btn.data('input');
                var previewId = $btn.data('preview');
                var frame = wp.media({ title:'Bild wählen', multiple:false, library:{type:'image'} });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    $('#' + inputId).val(att.id);
                    var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                    $('#' + previewId).css('background-image','url(' + url + ')').removeClass('empty');
                });
                frame.open();
            });
            $('.tix-ol-img-remove').on('click', function(e){
                e.preventDefault();
                var $btn = $(this);
                $('#' + $btn.data('input')).val('');
                $('#' + $btn.data('preview')).css('background-image','').addClass('empty');
            });

            // ═══ Section-Builder: Drag-Sortierung + Toggle ═══
            if ($.fn.sortable) {
                $('#tix-ol-sections-sortable').sortable({
                    handle: '.tix-ol-drag-handle',
                    placeholder: 'ui-sortable-placeholder',
                    tolerance: 'pointer',
                    update: function(){ serializeSections(); }
                });
            }
            $(document).on('change', '.tix-ol-section-enabled', function(){
                var $lbl = $(this).closest('.tix-ol-switch').find('span');
                $lbl.text(this.checked ? 'Aktiv' : 'Aus');
                serializeSections();
            });
            function serializeSections() {
                var arr = [];
                $('#tix-ol-sections-sortable .tix-ol-section-item').each(function(){
                    arr.push({
                        id: $(this).data('section-id'),
                        enabled: $(this).find('.tix-ol-section-enabled').is(':checked')
                    });
                });
                $('#landing-sections-json').val(JSON.stringify(arr));
            }
            serializeSections();

            function esc(s){ return String(s || '').replace(/"/g, '&quot;'); }

            // ═══ Partners-Repeater ═══
            function renderPartnersRepeater() {
                var $wrap = $('#tix-ol-partners-repeater').empty();
                TIX_OL_PARTNERS_INIT.forEach(function(p, idx){
                    $wrap.append(partnerRow(p, idx));
                });
            }
            function partnerRow(p, idx) {
                p = p || {};
                var imgUrl = p.logo_url || '';
                return $(
                    '<div class="tix-ol-rep-row tix-ol-partner-row" data-idx="' + idx + '">' +
                        '<div class="tix-ol-rep-img ' + (imgUrl ? 'has-image' : '') + '" data-field="logo_id" ' +
                            (imgUrl ? 'style="background-image:url(' + imgUrl + ')"' : '') + '>' +
                            (imgUrl ? '' : '🖼') +
                        '</div>' +
                        '<div class="tix-ol-rep-fields">' +
                            '<input type="text" placeholder="Partner-Name" data-field="name" value="' + esc(p.name || '') + '">' +
                            '<input type="url"  placeholder="Website-URL (optional)" data-field="url" value="' + esc(p.url || '') + '">' +
                            '<input type="hidden" data-field="logo_id" value="' + (p.logo_id || '') + '">' +
                        '</div>' +
                        '<button type="button" class="tix-ol-rep-remove" title="Entfernen">×</button>' +
                    '</div>'
                );
            }
            renderPartnersRepeater();

            $('#tix-ol-partners-add').on('click', function(){
                TIX_OL_PARTNERS_INIT.push({});
                renderPartnersRepeater();
            });
            $(document).on('click', '.tix-ol-partner-row .tix-ol-rep-remove', function(){
                var idx = $(this).closest('.tix-ol-partner-row').data('idx');
                TIX_OL_PARTNERS_INIT.splice(idx, 1);
                renderPartnersRepeater();
            });
            // Image Picker für Partners
            $(document).on('click', '.tix-ol-partner-row .tix-ol-rep-img', function(){
                var $row = $(this).closest('.tix-ol-partner-row');
                var idx  = $row.data('idx');
                var $box = $(this);
                var frame = wp.media({ title:'Partner-Logo wählen', multiple:false, library:{ type:'image' } });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                    TIX_OL_PARTNERS_INIT[idx].logo_id  = att.id;
                    TIX_OL_PARTNERS_INIT[idx].logo_url = url;
                    $box.css('background-image', 'url(' + url + ')').addClass('has-image').html('');
                    $row.find('input[data-field=logo_id]').val(att.id);
                });
                frame.open();
            });

            // Serializer für Partners beim Save
            function serializePartners() {
                $('.tix-ol-partner-row').each(function(){
                    var idx = $(this).data('idx');
                    var p = TIX_OL_PARTNERS_INIT[idx] || {};
                    $(this).find('input[data-field]').each(function(){
                        p[$(this).data('field')] = $(this).val();
                    });
                    TIX_OL_PARTNERS_INIT[idx] = p;
                });
                $('#landing-partners-json').val(JSON.stringify(TIX_OL_PARTNERS_INIT));
            }

            // Save
            $('#tix-ol-save-btn').on('click', function(){
                serializeSections();
                serializePartners();
                var $btn = $(this);
                var $status = $('#tix-ol-save-status');
                $btn.prop('disabled', true);
                $status.css('color','#6b7280').text('Speichere…');

                var fd = new FormData(document.getElementById('tix-ol-form'));
                fd.append('action', 'tix_org_landing_save');

                fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        $btn.prop('disabled', false);
                        if (d.success) {
                            $status.css('color','#10b981').text('✓ Gespeichert');
                            setTimeout(function(){ $status.text(''); }, 3000);
                        } else {
                            $status.css('color','#ef4444').text('✗ ' + (d.data && d.data.message ? d.data.message : 'Fehler'));
                        }
                    })
                    .catch(function(){
                        $btn.prop('disabled', false);
                        $status.css('color','#ef4444').text('✗ Netzwerkfehler');
                    });
            });
        });
        </script>
        <?php
    }

    /** Hilfsfunktion: Video-Upload-Feld (mp4) */
    private static function render_video_field($name, $attachment_id) {
        $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        $input_id   = 'vid_input_' . $name;
        $preview_id = 'vid_preview_' . $name;
        ?>
        <div class="tix-ol-img-picker">
            <div id="<?php echo esc_attr($preview_id); ?>" class="tix-ol-img-preview <?php echo $url ? '' : 'empty'; ?>" style="background:#111; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px;">
                <?php if ($url): ?>
                    <video style="width:100%;height:100%;object-fit:cover;border-radius:12px;" autoplay muted loop playsinline><source src="<?php echo esc_url($url); ?>" type="video/mp4"></video>
                <?php else: ?>
                    🎬
                <?php endif; ?>
            </div>
            <div class="tix-ol-img-actions">
                <input type="hidden" id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($attachment_id); ?>">
                <button type="button" class="button tix-ol-vid-select"
                        data-input="<?php echo esc_attr($input_id); ?>"
                        data-preview="<?php echo esc_attr($preview_id); ?>">Video wählen</button>
                <button type="button" class="button tix-ol-vid-remove"
                        data-input="<?php echo esc_attr($input_id); ?>"
                        data-preview="<?php echo esc_attr($preview_id); ?>">Entfernen</button>
            </div>
        </div>
        <script>
        jQuery(function($){
            $('.tix-ol-vid-select').off('click.tix').on('click.tix', function(e){
                e.preventDefault();
                var $btn = $(this);
                var frame = wp.media({ title:'Video wählen', multiple:false, library:{ type:'video' } });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    $('#' + $btn.data('input')).val(att.id);
                    $('#' + $btn.data('preview')).html('<video style="width:100%;height:100%;object-fit:cover;border-radius:12px;" autoplay muted loop playsinline><source src="' + att.url + '" type="video/mp4"></video>').removeClass('empty');
                });
                frame.open();
            });
            $('.tix-ol-vid-remove').off('click.tix').on('click.tix', function(e){
                e.preventDefault();
                var $btn = $(this);
                $('#' + $btn.data('input')).val('');
                $('#' + $btn.data('preview')).html('🎬').addClass('empty');
            });
        });
        </script>
        <?php
    }

    /** Analytics-Widget auf der Meine-Landingpage-Seite */
    private static function render_analytics_widget($org_id) {
        $days = 30;
        $s = self::analytics_summary($org_id, $days);
        // Mini-Chart-Daten (SVG)
        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $daily[$date] = 0;
        }
        foreach ($s['daily'] as $row) {
            $daily[$row->day] = intval($row->views);
        }
        $max = max(1, max($daily));
        $w   = 600; $h = 60; $step = $w / max(1, count($daily) - 1);
        $points = [];
        $i = 0;
        foreach ($daily as $v) {
            $x = round($i * $step);
            $y = round($h - ($v / $max) * $h);
            $points[] = "{$x},{$y}";
            $i++;
        }
        $path = implode(' ', $points);
        ?>
        <div class="card full" style="background: linear-gradient(135deg,#131020,#1f1b2e); color:#fff;">
            <h2 style="color:#fff;">📊 Landingpage-Analytics (letzte 30 Tage)</h2>
            <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin:16px 0;">
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:rgba(255,255,255,0.6);">Views</div>
                    <div style="font-size:28px; font-weight:800; font-family:'Sora',sans-serif;"><?php echo number_format($s['total_views'], 0, ',', '.'); ?></div>
                </div>
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:rgba(255,255,255,0.6);">Unique (IP-Tag)</div>
                    <div style="font-size:28px; font-weight:800; font-family:'Sora',sans-serif;"><?php echo number_format($s['unique'], 0, ',', '.'); ?></div>
                </div>
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:rgba(255,255,255,0.6);">Verkäufe</div>
                    <div style="font-size:28px; font-weight:800; font-family:'Sora',sans-serif; color:#10b981;"><?php echo $s['conversions']; ?></div>
                </div>
                <div>
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:rgba(255,255,255,0.6);">Conversion</div>
                    <div style="font-size:28px; font-weight:800; font-family:'Sora',sans-serif; color:<?php echo $s['conv_rate'] > 1 ? '#10b981' : '#f59e0b'; ?>;"><?php echo number_format($s['conv_rate'], 2, ',', '.'); ?>%</div>
                </div>
            </div>
            <svg viewBox="0 0 <?php echo $w; ?> <?php echo $h; ?>" style="width:100%;height:60px;" preserveAspectRatio="none">
                <polyline points="<?php echo $path; ?>" fill="none" stroke="#E8445A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:20px;">
                <div>
                    <h3 style="font-size:14px; color:#fff; margin:0 0 10px;">🎯 Top-Events (nach Views)</h3>
                    <?php if (empty($s['top_events'])): ?>
                        <p style="color:rgba(255,255,255,0.6); font-size:13px;">Noch keine Daten.</p>
                    <?php else: ?>
                        <ol style="padding-left:20px; margin:0; font-size:13px;">
                            <?php foreach ($s['top_events'] as $e): ?>
                                <li style="margin-bottom:6px; color:rgba(255,255,255,0.9);">
                                    <a href="<?php echo esc_url($e->url); ?>" target="_blank" style="color:#fff; text-decoration:none;"><?php echo esc_html($e->title); ?></a>
                                    <span style="color:rgba(255,255,255,0.5); float:right;"><?php echo $e->views; ?> Views</span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 style="font-size:14px; color:#fff; margin:0 0 10px;">🌐 Top-Traffic-Quellen</h3>
                    <?php if (empty($s['referrers'])): ?>
                        <p style="color:rgba(255,255,255,0.6); font-size:13px;">Kein externer Traffic registriert.</p>
                    <?php else: ?>
                        <ul style="padding-left:0; margin:0; font-size:13px; list-style:none;">
                            <?php foreach ($s['referrers'] as $r): ?>
                                <li style="margin-bottom:6px; color:rgba(255,255,255,0.9); display:flex; justify-content:space-between;">
                                    <span><?php echo esc_html($r->host); ?></span>
                                    <span style="color:rgba(255,255,255,0.5);"><?php echo $r->views; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /** Hilfsfunktion: Bild-Upload-Feld mit WP-Media-Picker */
    private static function render_image_field($name, $attachment_id) {
        $url = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'medium') : '';
        $input_id   = 'img_input_' . $name;
        $preview_id = 'img_preview_' . $name;
        ?>
        <div class="tix-ol-img-picker">
            <div id="<?php echo esc_attr($preview_id); ?>"
                 class="tix-ol-img-preview <?php echo $url ? '' : 'empty'; ?>"
                 style="<?php echo $url ? 'background-image:url(' . esc_url($url) . ')' : ''; ?>"></div>
            <div class="tix-ol-img-actions">
                <input type="hidden" id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($attachment_id); ?>">
                <button type="button" class="button tix-ol-img-select"
                        data-input="<?php echo esc_attr($input_id); ?>"
                        data-preview="<?php echo esc_attr($preview_id); ?>">Bild wählen</button>
                <button type="button" class="button tix-ol-img-remove"
                        data-input="<?php echo esc_attr($input_id); ?>"
                        data-preview="<?php echo esc_attr($preview_id); ?>">Entfernen</button>
            </div>
        </div>
        <?php
    }

    public static function ajax_save_organizer_settings() {
        if (!check_ajax_referer('tix_org_landing_save', 'tix_ol_nonce', false)) {
            wp_send_json_error(['message' => 'Nonce ungültig']);
        }

        $org_id = intval($_POST['org_id'] ?? 0);
        $uid    = get_current_user_id();

        // Berechtigung: Admin oder User ist dem Organizer zugeordnet
        $is_admin = current_user_can('manage_options');
        $linked   = intval(get_post_meta($org_id, '_tix_org_user_id', true)) === $uid;
        if (!$is_admin && !$linked) {
            wp_send_json_error(['message' => 'Keine Berechtigung']);
        }

        $org = get_post($org_id);
        if (!$org || $org->post_type !== 'tix_organizer') {
            wp_send_json_error(['message' => 'Veranstalter nicht gefunden']);
        }

        // Speichern
        update_post_meta($org_id, '_tix_org_landing_logo_id',  intval($_POST['landing_logo_id']  ?? 0));
        update_post_meta($org_id, '_tix_org_landing_hero_id',  intval($_POST['landing_hero_id']  ?? 0));
        update_post_meta($org_id, '_tix_org_landing_tagline',  sanitize_text_field($_POST['landing_tagline'] ?? ''));
        update_post_meta($org_id, '_tix_org_landing_description', wp_kses_post($_POST['landing_description'] ?? ''));

        // Farbschema
        $mode = ($_POST['landing_color_mode'] ?? 'light') === 'dark' ? 'dark' : 'light';
        update_post_meta($org_id, '_tix_org_landing_color_mode', $mode);

        $primary_light = sanitize_hex_color($_POST['landing_primary_light'] ?? '');
        $primary_dark  = sanitize_hex_color($_POST['landing_primary_dark']  ?? '');
        if ($primary_light) update_post_meta($org_id, '_tix_org_landing_primary_light', $primary_light);
        if ($primary_dark)  update_post_meta($org_id, '_tix_org_landing_primary_dark',  $primary_dark);

        // Hintergrundfarben pro Mode
        $bg_light = sanitize_hex_color($_POST['landing_bg_light'] ?? '');
        $bg_dark  = sanitize_hex_color($_POST['landing_bg_dark']  ?? '');
        if ($bg_light) update_post_meta($org_id, '_tix_org_landing_bg_light', $bg_light);
        if ($bg_dark)  update_post_meta($org_id, '_tix_org_landing_bg_dark',  $bg_dark);

        // Header + Footer Farben pro Mode (falls leer, später Fallback auf BG)
        foreach ([
            'landing_header_bg_light', 'landing_header_bg_dark',
            'landing_header_text_light', 'landing_header_text_dark',
            'landing_footer_bg_light', 'landing_footer_bg_dark',
            'landing_footer_text_light', 'landing_footer_text_dark',
        ] as $key) {
            $val = sanitize_hex_color($_POST[$key] ?? '');
            if ($val) {
                update_post_meta($org_id, '_tix_org_' . $key, $val);
            } else {
                delete_post_meta($org_id, '_tix_org_' . $key);
            }
        }

        // Legacy-Kompat: alte Felder synchronisieren (damit Landing-Template weiterhin greift)
        $active_primary = ($mode === 'dark') ? $primary_dark : $primary_light;
        if ($active_primary) update_post_meta($org_id, '_tix_org_landing_primary_color', $active_primary);

        update_post_meta($org_id, '_tix_org_landing_contact_email',
            sanitize_email($_POST['landing_contact_email'] ?? ''));
        update_post_meta($org_id, '_tix_org_landing_show_past_events',
            !empty($_POST['landing_show_past_events']) ? 1 : 0);

        // Social: Array sanitizen
        $social_in = $_POST['landing_social'] ?? [];
        $social    = [];
        if (is_array($social_in)) {
            foreach ($social_in as $key => $url) {
                $key = sanitize_key($key);
                $url = esc_url_raw(trim($url));
                if ($url) $social[$key] = $url;
            }
        }
        update_post_meta($org_id, '_tix_org_landing_social', $social);

        // Neue Felder: Countdown, Hero-Video, CTA, Favicon
        update_post_meta($org_id, '_tix_org_landing_countdown_enabled',
            !empty($_POST['landing_countdown_enabled']) ? 1 : 0);
        update_post_meta($org_id, '_tix_org_landing_hero_video_id',
            intval($_POST['landing_hero_video_id'] ?? 0));
        update_post_meta($org_id, '_tix_org_landing_hero_video_url',
            esc_url_raw(trim($_POST['landing_hero_video_url'] ?? '')));
        update_post_meta($org_id, '_tix_org_landing_favicon_id',
            intval($_POST['landing_favicon_id'] ?? 0));
        update_post_meta($org_id, '_tix_org_landing_cta_text',
            sanitize_text_field($_POST['landing_cta_text'] ?? ''));
        update_post_meta($org_id, '_tix_org_landing_cta_url',
            esc_url_raw(trim($_POST['landing_cta_url'] ?? '')));

        // Section-Builder: Reihenfolge + Enabled pro Section
        $sections_raw = $_POST['landing_sections_json'] ?? '';
        if ($sections_raw) {
            $decoded = json_decode(stripslashes($sections_raw), true);
            if (is_array($decoded)) {
                $registry = array_keys(self::sections_registry());
                $clean = [];
                foreach ($decoded as $row) {
                    if (empty($row['id']) || !in_array($row['id'], $registry, true)) continue;
                    $clean[] = ['id' => $row['id'], 'enabled' => !empty($row['enabled'])];
                }
                update_post_meta($org_id, '_tix_org_landing_sections', $clean);
            }
        }

        // Partners
        $partners_raw = $_POST['landing_partners_json'] ?? '';
        if ($partners_raw) {
            $decoded = json_decode(stripslashes($partners_raw), true);
            $clean = [];
            if (is_array($decoded)) {
                foreach ($decoded as $p) {
                    if (!is_array($p)) continue;
                    $name    = trim($p['name'] ?? '');
                    $logo_id = intval($p['logo_id'] ?? 0);
                    if ($name === '' && !$logo_id) continue;
                    $clean[] = [
                        'name'    => sanitize_text_field($name),
                        'logo_id' => $logo_id,
                        'url'     => esc_url_raw($p['url'] ?? ''),
                    ];
                }
            }
            update_post_meta($org_id, '_tix_org_landing_partners', $clean);
        }

        update_post_meta($org_id, '_tix_org_landing_partners_heading',
            sanitize_text_field($_POST['landing_partners_heading'] ?? ''));

        wp_send_json_success(['message' => 'Gespeichert']);
    }

    // ══════════════════════════════════════════════════════════════════
    // Analytics: Views-Tracking
    // ══════════════════════════════════════════════════════════════════

    private static $views_table_checked = false;

    private static function views_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'tix_landing_views';
    }

    /** Legt Tabelle an wenn nötig (checked per transient) */
    public static function ensure_views_table() {
        if (self::$views_table_checked) return;
        self::$views_table_checked = true;

        global $wpdb;
        $t = self::views_table_name();

        // Schneller Check via Transient (1 Tag)
        if (get_transient('tix_landing_views_table_ok')) return;

        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$t}'") === $t;
        if (!$exists) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$t} (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                org_id bigint(20) UNSIGNED NOT NULL,
                slug varchar(64) NOT NULL,
                event_id bigint(20) UNSIGNED DEFAULT 0,
                url_path varchar(255) DEFAULT '',
                referrer varchar(500) DEFAULT '',
                utm_source varchar(100) DEFAULT '',
                utm_medium varchar(100) DEFAULT '',
                utm_campaign varchar(100) DEFAULT '',
                ip_hash char(32) DEFAULT '',
                is_bot tinyint(1) DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY org_idx (org_id, created_at),
                KEY event_idx (event_id, created_at),
                KEY slug_idx (slug, created_at)
            ) {$charset};";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
        set_transient('tix_landing_views_table_ok', 1, DAY_IN_SECONDS);
    }

    /**
     * Registriert einen View. Wird automatisch aufgerufen:
     * - beim Rendern der Landing (render_landing)
     * - beim Render einer Event-Seite auf einer Organizer-Subdomain
     */
    public static function track_view($org_id, $event_id = 0) {
        if (!$org_id) return;
        if (!defined('TIX_ORG_SUBDOMAIN_SLUG') && !get_query_var(self::QUERY_VAR)) {
            // Nur tracken wenn Kontext = Landing oder Subdomain
            return;
        }

        // Bots aus grobem User-Agent-Check ausschließen (können aber in Reports inkludiert)
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $is_bot = preg_match('/bot|crawl|spider|archive|preview|http|ruby|python|curl|wget/i', $ua) ? 1 : 0;

        $slug = defined('TIX_ORG_SUBDOMAIN_SLUG') ? TIX_ORG_SUBDOMAIN_SLUG : (get_query_var(self::QUERY_VAR) ?: '');

        global $wpdb;
        $wpdb->insert(self::views_table_name(), [
            'org_id'       => intval($org_id),
            'slug'         => sanitize_key($slug),
            'event_id'     => intval($event_id),
            'url_path'     => substr(sanitize_text_field($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
            'referrer'     => substr(esc_url_raw($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
            'utm_source'   => sanitize_text_field($_GET['utm_source']   ?? ''),
            'utm_medium'   => sanitize_text_field($_GET['utm_medium']   ?? ''),
            'utm_campaign' => sanitize_text_field($_GET['utm_campaign'] ?? ''),
            'ip_hash'      => substr(md5(($_SERVER['REMOTE_ADDR'] ?? '') . date('Ymd')), 0, 32),
            'is_bot'       => $is_bot,
            'created_at'   => current_time('mysql'),
        ]);
    }

    /** Analytics-Query: Zusammenfassung der letzten N Tage */
    public static function analytics_summary($org_id, $days = 30) {
        global $wpdb;
        $t     = self::views_table_name();
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        // Gesamt-Views (nicht-Bot)
        $total_views = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE org_id = %d AND is_bot = 0 AND created_at >= %s",
            $org_id, $since
        )));

        // Unique Besucher (ip_hash)
        $unique = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ip_hash) FROM {$t} WHERE org_id = %d AND is_bot = 0 AND created_at >= %s",
            $org_id, $since
        )));

        // Tages-Timeline
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as day, COUNT(*) as views
             FROM {$t}
             WHERE org_id = %d AND is_bot = 0 AND created_at >= %s
             GROUP BY DATE(created_at) ORDER BY day",
            $org_id, $since
        ));

        // Top-Events nach Views
        $top_events = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id, COUNT(*) as views
             FROM {$t}
             WHERE org_id = %d AND is_bot = 0 AND event_id > 0 AND created_at >= %s
             GROUP BY event_id
             ORDER BY views DESC
             LIMIT 5",
            $org_id, $since
        ));
        foreach ($top_events as $e) {
            $e->title = get_the_title($e->event_id) ?: '(gelöscht)';
            $e->url   = get_permalink($e->event_id);
        }

        // Top-Referrer (ohne eigene Seite)
        $main_host = parse_url(get_option('home'), PHP_URL_HOST);
        $main_host_like = '%' . $main_host . '%';
        $refs = $wpdb->get_results($wpdb->prepare(
            "SELECT referrer, COUNT(*) as views
             FROM {$t}
             WHERE org_id = %d AND is_bot = 0 AND created_at >= %s AND referrer != ''
               AND referrer NOT LIKE %s
             GROUP BY referrer
             ORDER BY views DESC
             LIMIT 8",
            $org_id, $since, $main_host_like
        ));
        // Normalize referrer → nur Host
        foreach ($refs as $r) {
            $h = parse_url($r->referrer, PHP_URL_HOST);
            $r->host = $h ?: $r->referrer;
        }

        // Konversions: Orders aus Attribution-Meta _tix_ol_source = slug
        $slug = get_post_meta($org_id, '_tix_org_landing_slug', true);
        $orders_table = $wpdb->prefix . 'tix_orders';
        $conversions = 0;
        if ($slug) {
            // Order-IDs mit matching meta
            $order_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_tix_ol_source' AND meta_value = %s",
                $slug
            ));
            if (!empty($order_ids)) {
                $ids_str = implode(',', array_map('intval', $order_ids));
                $conversions = intval($wpdb->get_var(
                    "SELECT COUNT(*) FROM {$orders_table}
                     WHERE id IN ({$ids_str})
                       AND status IN ('completed','processing')
                       AND date_created >= '" . esc_sql($since) . "'"
                ));
            }
        }

        $conv_rate = $total_views > 0 ? round(($conversions / $total_views) * 100, 2) : 0;

        return [
            'days'         => $days,
            'total_views'  => $total_views,
            'unique'       => $unique,
            'daily'        => $daily,
            'top_events'   => $top_events,
            'referrers'    => $refs,
            'conversions'  => $conversions,
            'conv_rate'    => $conv_rate,
        ];
    }
}
