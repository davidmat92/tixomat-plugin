<?php
if (!defined('ABSPATH')) exit;

/**
 * Embed Widget – Ticket-Selector als iFrame auf fremden Seiten.
 *
 * URL-Schema: ?tix_embed=EVENT_ID[&theme=light|dark]
 * Konfiguration pro Event über Post-Meta (_tix_embed_enabled, _tix_embed_domains).
 */
class TIX_Embed {

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'handle_embed'], 1);
    }

    /**
     * Prüft ob ein Embed-Request vorliegt und rendert ggf. die Standalone-Seite.
     */
    public static function handle_embed() {
        if (!isset($_GET['tix_embed'])) return;

        $event_id = intval($_GET['tix_embed']);
        if (!$event_id || get_post_type($event_id) !== 'event') {
            wp_die('Event nicht gefunden.', 'Nicht gefunden', ['response' => 404]);
        }

        // Per-Event Embed-Freigabe prüfen
        $embed_enabled = get_post_meta($event_id, '_tix_embed_enabled', true);
        if ($embed_enabled !== '1') {
            wp_die('Embed ist für dieses Event deaktiviert.', 'Nicht erlaubt', ['response' => 403]);
        }

        // Domain-Prüfung (per Event)
        $allowed = trim(get_post_meta($event_id, '_tix_embed_domains', true) ?: '');
        if ($allowed !== '') {
            $referer = wp_get_referer() ?: ($_SERVER['HTTP_REFERER'] ?? '');
            $referer_host = $referer ? parse_url($referer, PHP_URL_HOST) : '';
            $allowed_list = array_map('trim', explode(',', $allowed));
            $allowed_list = array_filter($allowed_list);

            if ($referer_host) {
                $match = false;
                foreach ($allowed_list as $domain) {
                    if ($referer_host === $domain || substr($referer_host, -strlen('.' . $domain)) === '.' . $domain) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    wp_die('Domain nicht erlaubt.', 'Zugriff verweigert', ['response' => 403]);
                }
            }
        }

        // X-Frame-Options entfernen für Embed
        header_remove('X-Frame-Options');

        // CSP frame-ancestors setzen
        if ($allowed !== '') {
            $domains = array_map('trim', explode(',', $allowed));
            $domains = array_filter($domains);
            $ancestors = implode(' ', array_map(function($d) { return "https://{$d} http://{$d}"; }, $domains));
            header("Content-Security-Policy: frame-ancestors 'self' {$ancestors}");
        }

        // Theme
        $theme = sanitize_text_field($_GET['theme'] ?? 'auto');
        if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
            $theme = 'auto';
        }

        self::render_embed_page($event_id, $theme);
        exit;
    }

    /**
     * Rendert die minimalistische Embed-Seite.
     */
    private static function render_embed_page($event_id, $theme) {
        // WordPress Header laden (für wp_head, Styles, Scripts)
        // Aber kein Theme-Template verwenden

        $event_title = get_the_title($event_id);

        // Theme-Farben
        $bg    = '#ffffff';
        $color = '#1a1a1a';
        if ($theme === 'dark') {
            $bg    = '#121212';
            $color = '#f0f0f0';
        }
        $auto_dark = ($theme === 'auto');

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($event_title); ?> – Tickets</title>
<?php wp_head(); ?>
<style>
    /* Embed Reset */
    * { box-sizing: border-box; }
    body {
        margin: 0;
        padding: 16px;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
        font-size: 15px;
        line-height: 1.5;
        background: <?php echo esc_attr($bg); ?>;
        color: <?php echo esc_attr($color); ?>;
    }
    <?php if ($auto_dark): ?>
    @media (prefers-color-scheme: dark) {
        body { background: #121212; color: #f0f0f0; }
    }
    <?php endif; ?>
    .tix-embed-wrap {
        max-width: 600px;
        margin: 0 auto;
    }
    .tix-embed-powered {
        text-align: center;
        margin-top: 16px;
        font-size: 11px;
        opacity: 0.35;
    }
    .tix-embed-powered a {
        color: inherit;
        text-decoration: none;
    }
    .tix-embed-powered a:hover { opacity: 0.7; }
</style>
</head>
<body class="tix-embed-body" data-theme="<?php echo esc_attr($theme); ?>">
<div class="tix-embed-wrap">
    <?php
    // Ticket Selector Shortcode rendern
    echo do_shortcode('[tix_ticket_selector id="' . $event_id . '"]');
    ?>
    <div class="tix-embed-powered">
        <a href="<?php echo esc_url(home_url()); ?>" target="_blank" rel="noopener">
            Powered by Tixomat
        </a>
    </div>
</div>
<?php wp_footer(); ?>
<script>
(function() {
    'use strict';

    // Auto-Resize: Höhe an Parent-Window senden
    function sendHeight() {
        var h = document.documentElement.scrollHeight;
        window.parent.postMessage({ type: 'tix-embed-resize', height: h }, '*');
    }

    // Initial + bei Änderungen
    sendHeight();
    var observer = new MutationObserver(sendHeight);
    observer.observe(document.body, { childList: true, subtree: true, attributes: true });
    window.addEventListener('resize', sendHeight);

    // Links im Top-Fenster öffnen (nicht im iFrame bleiben)
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a[href]');
        if (link && !link.href.includes('tix_embed')) {
            e.preventDefault();
            window.top.location.href = link.href;
        }
    });
})();
</script>
</body>
</html><?php
    }
}
