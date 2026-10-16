<?php
/**
 * TIX Organizer Landing
 *
 * Felder der Veranstalter-Seite (Logo, Titelbild, Farben, Social, Beschreibung, Sektionen, Partner, Video – Meta
 * `_tix_org_landing_*`) und ihre Pflege (Admin-Metabox, „Meine Landingpage“). Datenquelle für /public/organizers und
 * die Veranstalter-Seiten der Website (evendis.de/veranstalter/{slug}, eigene Domains: TIX_Org_Domains).
 *
 * Seit 1.38.426 rendert WordPress keine Landingpages mehr (keine /v/-Seite, keine Subdomain-Weiche, kein Branding,
 * kein Umbiegen von home_url). Alte Links leiten weiter: /v/{slug} und {slug}.<Kunden-Host> → 301 auf
 * <Kunden-Adresse>/veranstalter/{slug}/.
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

    /** Public API — wird in tixomat.php aufgerufen */
    public static function init() {
        // DB-Tabelle für die (bisherigen) Aufruf-Zahlen
        self::ensure_views_table();

        // /v/{slug} bleibt als Adresse erhalten, wird aber nur noch weitergeleitet (1.38.426)
        add_action('init', [__CLASS__, 'register_rewrite'], 20);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        if (self::is_feature_enabled()) {
            add_action('template_redirect', [__CLASS__, 'redirect_old_link'], 0);
            add_action('parse_request', [__CLASS__, 'redirect_old_subdomain'], 0);
        }

        // Admin immer aktiv (Pflege der Felder _tix_org_landing_* – Datenquelle für /public/organizers)
        if (is_admin()) {
            add_action('add_meta_boxes',              [__CLASS__, 'add_meta_box']);
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

    /** Ziel der Veranstalter-Seite: Kunden-Adresse (evendis.de) bzw. diese Seite + /veranstalter/{slug}/ */
    public static function public_page_url($slug) {
        $base = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::base() : '';
        if ($base === '') $base = untrailingslashit((string) get_option('home'));
        return $base . '/veranstalter/' . rawurlencode(sanitize_title($slug)) . '/';
    }

    /** Alte Adresse /v/{slug} → 301 auf die Veranstalter-Seite (mit Herkunft „veranstalter-link“, wenn keine eigene da ist). */
    public static function redirect_old_link() {
        $slug = (string) get_query_var(self::QUERY_VAR);
        if ($slug === '') return;
        $slug = sanitize_title($slug);
        $url = self::public_page_url($slug);
        // Vorhandene Parameter (z. B. eigene Herkunft vom Flyer) mitnehmen, sonst Herkunft „veranstalter-link“
        $keep = [];
        foreach ((array) $_GET as $k => $v) if (is_string($v) && preg_match('/^[a-z0-9_]{1,40}$/', (string) $k) && $k !== self::QUERY_VAR) $keep[$k] = rawurlencode(mb_substr(sanitize_text_field(wp_unslash($v)), 0, 120));
        if (!isset($keep['utm_source'])) $keep += ['utm_source' => 'veranstalter-link', 'utm_medium' => $slug];
        wp_redirect(add_query_arg($keep, $url), 301, 'Tixomat');
        exit;
    }

    /** Alte Subdomain {slug}.<Kunden-Host> (falls sie bei WordPress ankommt) → 301 auf die Veranstalter-Seite bzw. Startseite. */
    public static function redirect_old_subdomain($wp) {
        if (!class_exists('TIX_Customer_URL')) return;
        $parent = TIX_Customer_URL::host();
        if ($parent === '') return;
        $parent = preg_replace('/^www\./', '', $parent);
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if (!preg_match('/^([a-z0-9-]+)\.' . preg_quote($parent, '/') . '$/', $host, $m)) return;
        $sub = $m[1];
        if (in_array($sub, ['www', 'veranstalter', 'domains', 'api', 'admin', 'app'], true)) return;
        $org = self::get_organizer_by_slug($sub);
        wp_redirect($org ? self::public_page_url($sub) : TIX_Customer_URL::base() . '/', 301, 'Tixomat');
        exit;
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
    // Feature-Flag
    // ══════════════════════════════════════════════════════════════════

    public static function is_feature_enabled() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        if (!empty($s['landing_pages_enabled'])) return true;

        // Default-Whitelist: nur evendis.de (auch als Kunden-Adresse, wenn WordPress unter
        // einer Verwaltungs-Adresse wie veranstalter.evendis.de läuft)
        $hosts = [parse_url(home_url(), PHP_URL_HOST)];
        if (class_exists('TIX_Customer_URL') && TIX_Customer_URL::host() !== '') $hosts[] = TIX_Customer_URL::host();
        return (bool) array_intersect(array_map('strtolower', array_filter($hosts)), ['evendis.de', 'www.evendis.de']);
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
        $org = $q[0] ?? null;
        // Mehr-Veranstalter-Modus: Landingpage erst nach Freigabe des Veranstalters
        if ($org && class_exists('TIX_Org_Approval') && !TIX_Org_Approval::is_public($org->ID)) return null;
        return $org;
    }

    /**
     * Seite öffentlich? Mehr-Veranstalter-Modus: allein die Veranstalter-Freigabe zählt (seit 1.38.472, kein eigenes
     * Häkchen mehr; abgelehnt/gesperrt = nicht öffentlich). Sonst die frühere Admin-Freischaltung (Meta).
     */
    public static function is_approved($org_id) {
        if (class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi()) return TIX_Org_Approval::is_public(intval($org_id));
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

    /** Öffentliche Adresse der Veranstalter-Seite (eigene Domain, wenn aktiv, sonst Kunden-Adresse/veranstalter/{slug}/). */
    public static function canonical_url($slug) {
        $org = self::get_organizer_by_slug($slug);
        if ($org && class_exists('TIX_Org_Domains') && ($d = TIX_Org_Domains::active_host($org->ID))) return 'https://' . $d . '/';
        return self::public_page_url($slug);
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
        $approved = self::is_approved($post->ID);
        $slug     = get_post_meta($post->ID, '_tix_org_landing_slug', true);
        $by_approval = class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi();
        $is_admin = current_user_can('manage_options') && !$by_approval;
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
                        <?php echo $by_approval
                            ? ($approved ? '✓ Öffentlich – der Veranstalter ist freigegeben' : 'Nicht öffentlich – wird mit der Freigabe des Veranstalters sichtbar (Status: ' . esc_html(TIX_Org_Approval::label(TIX_Org_Approval::status($post->ID))) . ')')
                            : ($approved ? '✓ Landingpage ist freigeschaltet' : 'Landingpage ist noch nicht freigeschaltet. Wende dich an den Support.'); ?>
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
                        <strong>Pfad:</strong>&nbsp;&nbsp;<?php echo esc_html(self::canonical_url($slug)); ?><br>
                        <strong>Subdomain (Phase 2):</strong>&nbsp;&nbsp;https://<?php echo esc_html($slug); ?>.<?php echo esc_html(preg_replace('/^www\./','',parse_url(home_url(),PHP_URL_HOST))); ?>/
                    </div>
                </div>
            <?php endif; ?>

            <div class="row full">
                <?php if ($slug && $approved): ?>
                    <a class="preview-btn" href="<?php echo esc_url(self::canonical_url($slug)); ?>" target="_blank">
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
            // Mehr-Veranstalter-Modus: öffentlich über die Veranstalter-Freigabe, kein eigenes Häkchen (1.38.472)
            if (!(class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi())) {
                update_post_meta($post_id, '_tix_org_landing_approved', !empty($_POST['tix_org_landing_approved']) ? 1 : 0);
            }

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

        $approved = self::is_approved($org_id);
        $slug     = get_post_meta($org_id, '_tix_org_landing_slug', true);
        $d        = self::get_landing_data($org_id);

        $social = is_array($d['social']) ? $d['social'] : [];

        ?>
        <div class="wrap tix-ol-settings">
            <h1>Meine Landingpage</h1>

            <?php if (!$approved): ?>
                <div class="notice notice-warning">
                    <p><strong>Deine Seite ist noch nicht öffentlich.</strong> <?php echo (class_exists('TIX_Org_Approval') && TIX_Org_Approval::multi()) ? 'Sie wird mit der Freigabe deines Veranstalter-Kontos sichtbar.' : 'Sobald der Support sie freischaltet, ist sie sichtbar.'; ?> Die Einstellungen kannst du aber jetzt schon vorbereiten.</p>
                </div>
            <?php elseif (!$slug): ?>
                <div class="notice notice-warning">
                    <p>Freigeschaltet, aber kein URL-Slug gesetzt. Der Support kümmert sich darum.</p>
                </div>
            <?php else: ?>
                <?php $url = self::canonical_url($slug); ?>
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

                <?php if (class_exists('TIX_Organizer_Page') && TIX_Organizer_Page::multi()) echo TIX_Organizer_Page::design_picker($org_id); ?>

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

        // Design der Seite auf der Plattform (evendis)
        if (isset($_POST['page_design']) && class_exists('TIX_Organizer_Page') && TIX_Organizer_Page::multi()) {
            $r = TIX_Organizer_Page::set_design($org_id, sanitize_key(wp_unslash($_POST['page_design'])));
            if (is_wp_error($r)) wp_send_json_error(['message' => $r->get_error_message()]);
        }

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
            'ip_hash'      => substr(md5((class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : ($_SERVER['REMOTE_ADDR'] ?? '')) . date('Ymd')), 0, 32),
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
