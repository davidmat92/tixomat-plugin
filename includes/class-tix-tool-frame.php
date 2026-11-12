<?php
/**
 * Werkzeugseiten ohne Theme (F7 Stufe 1, Breakdance-Ablösung, 1.38.774).
 *
 * Einlass (`/checkin/`), Kasse (`/pos/`), Promoter- und Sponsoren-Bereich, Konto-Seiten und die Danke-Seite nach der Zahlung
 * hingen bisher am Theme: Der Shortcode stand oft nur im Breakdance-Layout (`_breakdance_data`), die Danke-Seite rief
 * get_header()/get_footer(). Ohne Breakdance wären sie leer gewesen.
 *
 * Jetzt liefert das Plugin sie selbst aus – wie `/dj-musikwuensche/` – in einem schlanken Rahmen:
 * eigenes HTML-Gerüst, Markenleiste (Logo/Name aus der Site-Config, Link zur Startseite), wp_head()/wp_footer()
 * nur für die Plugin-Assets, kein Theme-Template.
 *
 * Erkennung einer Werkzeugseite: bekannter Slug (SLUGS) oder ein Werkzeug-Shortcode (TOOLS) im Seiteninhalt bzw. im
 * Breakdance-Layout. Der Shortcode samt Attributen wird von dort übernommen, der Seiteninhalt sonst nicht.
 * Rückweg: Option `tix_tool_frame` = '0' (bzw. Filter `tix_tool_frame_enabled`) → wieder das Theme.
 */
if (!defined('ABSPATH')) exit;

class TIX_Tool_Frame {

    const OPT = 'tix_tool_frame';

    /** Werkzeug-Shortcodes, die eine ganze Seite tragen (keine Inhalts-Bausteine wie tix_event_*). */
    const TOOLS = ['tix_checkin', 'tix_pos', 'tix_promoter_dashboard', 'tix_promoter_signup', 'tix_sponsor_dashboard', 'tix_account',
        'tix_my_tickets', 'tix_order_history', 'tix_ticket_transfer', 'tix_support', 'tix_feedback', 'tix_organizer_dashboard', 'tix_register_event'];

    /** Bekannte Slugs → Shortcode, falls die Seite keinen erkennbaren Shortcode trägt (z. B. Inhalt leer, Layout fehlt). */
    const SLUGS = ['checkin' => 'tix_checkin', 'pos' => 'tix_pos', 'promoter' => 'tix_promoter_dashboard', 'sponsoren-dashboard' => 'tix_sponsor_dashboard'];

    /** Werkzeuge, die nie in Suchmaschinen gehören */
    const NOINDEX = ['tix_checkin', 'tix_pos', 'tix_promoter_dashboard', 'tix_sponsor_dashboard', 'tix_organizer_dashboard', 'tix_account', 'tix_my_tickets', 'tix_order_history', 'tix_ticket_transfer'];

    public static function init() {
        // Nach den eigenen ?tix_…-Handlern (die selbst antworten und beenden), vor dem Theme (template_include)
        add_action('template_redirect', [__CLASS__, 'maybe_render'], 50);
    }

    public static function enabled() {
        return (bool) apply_filters('tix_tool_frame_enabled', get_option(self::OPT, '1') !== '0');
    }

    /** Im Breakdance-Editor bzw. in der Vorschau nie übernehmen. */
    private static function builder_request() {
        foreach (array_keys($_GET) as $k) if (stripos((string) $k, 'breakdance') !== false) return true;
        return is_customize_preview() || (isset($_GET['preview']) && $_GET['preview'] === 'true');
    }

    /**
     * Werkzeug einer Seite: ['tag' => Shortcode-Name, 'sc' => „[tix_checkin …]“, 'source' => content|breakdance|slug] oder null.
     * Reihenfolge: Seiteninhalt (dann wird der ganze Inhalt ausgegeben, mit Text drumherum), Breakdance-Layout bzw. bekannter
     * Slug (dann nur der Shortcode – das übrige Layout gehört zum Theme).
     */
    public static function tool($post) {
        $post = get_post($post);
        if (!$post || $post->post_type !== 'page') return null;
        $re = '/\[(' . implode('|', array_map('preg_quote', self::TOOLS)) . ')(\s[^\]]*)?\]/';
        if (preg_match($re, (string) $post->post_content, $m)) return ['tag' => $m[1], 'sc' => $m[0], 'source' => 'content'];
        $bd = get_post_meta($post->ID, '_breakdance_data', true);
        if ($bd !== '' && $bd !== null) {
            $raw = is_string($bd) ? $bd : wp_json_encode($bd);
            // JSON im Layout: Anführungszeichen und Schrägstriche sind maskiert
            $raw = str_replace(['\\"', '\\/', '\\u0022'], ['"', '/', '"'], $raw);
            if (preg_match($re, $raw, $m)) return ['tag' => $m[1], 'sc' => $m[0], 'source' => 'breakdance'];
        }
        $slug = (string) $post->post_name;
        return isset(self::SLUGS[$slug]) ? ['tag' => self::SLUGS[$slug], 'sc' => '[' . self::SLUGS[$slug] . ']', 'source' => 'slug'] : null;
    }

    public static function maybe_render() {
        if (!self::enabled() || is_admin() || wp_doing_ajax() || self::builder_request()) return;
        if (!is_page()) return;
        $post = get_queried_object();
        $t = self::tool($post);
        if (!$t || !shortcode_exists($t['tag'])) return;
        $content = $t['source'] === 'content'
            ? function () use ($post) { return do_shortcode(shortcode_unautop(wpautop((string) $post->post_content))); } // ohne the_content: Breakdance hängt sich dort ein
            : function () use ($t) { return do_shortcode($t['sc']); };
        self::render(html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'), $content, ['noindex' => in_array($t['tag'], self::NOINDEX, true), 'class' => 'tix-frame-' . str_replace('_', '-', $t['tag'])]);
        exit;
    }

    /** Marke für die Leiste: Name, Logo (für hellen Grund), Akzentfarbe, Rechtslinks. */
    public static function brand() {
        $p = class_exists('TIX_Site_Config') && method_exists('TIX_Site_Config', 'payload') ? (array) TIX_Site_Config::payload() : [];
        $logo = $p['logo']['on_light'] ?? ($p['logo_on_light'] ?? null) ?: ($p['logo']['light'] ?? null);
        $accent = (string) ($p['colors']['accent'] ?? '');
        if (!preg_match('/^#[0-9a-f]{3,8}$/i', $accent)) $accent = '#111827';
        $legal = [];
        foreach (['imprint' => 'Impressum', 'privacy' => 'Datenschutz'] as $k => $label) {
            $u = $p['legal'][$k] ?? ($p['legal'][$k . '_url'] ?? null);
            if (is_string($u) && $u !== '') $legal[] = ['url' => $u, 'label' => $label];
        }
        return ['name' => (string) ($p['name'] ?? html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8')), 'logo' => is_string($logo) ? $logo : '', 'accent' => $accent, 'legal' => $legal];
    }

    /**
     * Seite im Rahmen ausgeben. $content: HTML oder Callable (wird VOR wp_head() ausgeführt, damit Shortcodes ihre
     * Skripte/Styles noch registrieren können).
     * $opts: noindex (bool), class (Body-Klasse), plain (ohne Markenleiste, z. B. Kasse/Einlass im Vollbild).
     */
    public static function render($title, $content, array $opts = []) {
        $html = is_callable($content) ? (string) call_user_func($content) : (string) $content;
        $b = self::brand();
        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: text/html; charset=UTF-8');
            if (!empty($opts['noindex'])) header('X-Robots-Tag: noindex, nofollow');
        }
        add_filter('show_admin_bar', '__return_false');
        $body_class = trim('tix-frame ' . sanitize_html_class((string) ($opts['class'] ?? '')));
        echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . (!empty($opts['noindex']) ? '<meta name="robots" content="noindex,nofollow">' : '')
            . '<title>' . esc_html(trim($title . ' – ' . $b['name'], ' –')) . '</title>';
        echo '<style id="tix-frame">'
            . 'html,body{margin:0;padding:0}body.tix-frame{background:#f6f7f9;color:#111827;font-family:-apple-system,BlinkMacSystemFont,"Inter","Segoe UI",Roboto,sans-serif;min-height:100vh;display:flex;flex-direction:column}'
            . '.tix-frame-bar{background:#fff;border-bottom:1px solid #e5e7eb;padding:10px 16px;display:flex;align-items:center;gap:10px}'
            . '.tix-frame-bar a{display:inline-flex;align-items:center;gap:10px;color:#111827;text-decoration:none;font-weight:700;font-size:16px}'
            . '.tix-frame-bar img{max-height:36px;width:auto;display:block}'
            . '.tix-frame-main{flex:1;width:100%;max-width:1200px;margin:0 auto;padding:16px;box-sizing:border-box}'
            . '.tix-frame-foot{padding:14px 16px;font-size:12px;color:#6b7280;text-align:center}.tix-frame-foot a{color:#6b7280;margin:0 8px}'
            . ':root{--tix-frame-accent:' . esc_attr($b['accent']) . '}'
            . '</style>';
        wp_head();
        echo '</head><body class="' . esc_attr($body_class) . '">';
        if (function_exists('wp_body_open')) wp_body_open();
        if (empty($opts['plain'])) {
            echo '<header class="tix-frame-bar"><a href="' . esc_url(home_url('/')) . '">'
                . ($b['logo'] !== '' ? '<img src="' . esc_url($b['logo']) . '" alt="' . esc_attr($b['name']) . '">' : '<span>' . esc_html($b['name']) . '</span>')
                . '</a></header>';
        }
        echo '<main class="tix-frame-main">' . $html . '</main>';
        if (empty($opts['plain']) && $b['legal']) {
            echo '<footer class="tix-frame-foot">';
            foreach ($b['legal'] as $l) echo '<a href="' . esc_url($l['url']) . '">' . esc_html($l['label']) . '</a>';
            echo '</footer>';
        }
        wp_footer();
        echo '</body></html>';
    }
}
