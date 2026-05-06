<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat Promoter Shell – Fullscreen-Modus für das Frontend-Promoter-Dashboard.
 *
 * Übernimmt die gesamte Seite per template_redirect wenn:
 * - Die Seite den [tix_promoter_dashboard] Shortcode enthält
 * - Der Besucher als Promoter eingeloggt ist (Magic-Link-Cookie ODER WP-User-Promoter)
 * - Das Setting promoter_fullscreen aktiv ist
 *
 * Rendert ein minimales HTML-Template ohne Theme-Chrome (Header/Footer/Sidebar).
 *
 * @since 1.38.163
 */
class TIX_Promoter_Shell {

    public static function init() {
        add_action('template_redirect',  [__CLASS__, 'maybe_render_fullscreen'], 5);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 99);
    }

    /**
     * Prüft ob die aktuelle Seite den Promoter-Dashboard-Shortcode enthält.
     */
    private static function page_has_dashboard() {
        global $post;
        if (!$post) return false;

        // Ist die Seite explizit als Promoter-Page in den Settings hinterlegt?
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $page_id = intval($s['promoter_page_id'] ?? 0);
        if ($page_id > 0 && intval($post->ID) === $page_id) return true;

        // Standard WordPress Shortcode-Detection
        if (has_shortcode($post->post_content, 'tix_promoter_dashboard')) return true;

        // Breakdance: Shortcode steckt im JSON-Meta
        $bd = get_post_meta($post->ID, '_breakdance_data', true);
        if ($bd && is_string($bd) && strpos($bd, 'tix_promoter_dashboard') !== false) return true;

        return false;
    }

    /**
     * Liefert den aktuellen Promoter (via Cookie ODER WP-Login).
     */
    private static function current_promoter() {
        if (!class_exists('TIX_Promoter_DB')) return null;
        if (class_exists('TIX_Promoter_Auth')) {
            return TIX_Promoter_Auth::get_current_promoter();
        }
        if (is_user_logged_in()) {
            return TIX_Promoter_DB::get_promoter_by_user(get_current_user_id());
        }
        return null;
    }

    /**
     * Shell-CSS laden wenn Fullscreen aktiv.
     */
    public static function enqueue() {
        if (!tix_get_settings('promoter_fullscreen')) return;
        if (!tix_get_settings('promoter_enabled')) return;
        if (!self::page_has_dashboard()) return;
        if (!self::current_promoter()) return;

        wp_enqueue_style(
            'tix-promoter-shell',
            TIXOMAT_URL . 'assets/css/promoter-shell.css',
            ['tix-promoter-dashboard'],
            TIXOMAT_VERSION
        );
    }

    /**
     * Prüft ob Fullscreen-Template geladen werden soll.
     */
    public static function maybe_render_fullscreen() {
        if (!tix_get_settings('promoter_fullscreen')) return;
        if (!tix_get_settings('promoter_enabled')) return;
        if (!self::page_has_dashboard()) return;

        $promoter = self::current_promoter();
        if (!$promoter || $promoter->status !== 'active') return; // nicht eingeloggt → reguläres Login-Form via Theme

        self::render_template($promoter);
        exit;
    }

    /**
     * Minimales Fullscreen-Template ohne Theme-Chrome.
     */
    private static function render_template($promoter) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $custom_logo = $s['admin_logo_url'] ?? '';
        $logo_url = $custom_logo ?: 'https://tixomat.de/wp-content/uploads/2026/03/logo-tixomat-light-500px.png';

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Promoter Dashboard &ndash; <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <style>
        html, body { margin: 0; padding: 0; min-height: 100vh; }
        body { background: #f4f1ec; }
        #wpadminbar { display: none !important; }
        html { margin-top: 0 !important; }
    </style>
</head>
<body class="tix-promoter-fullscreen">

    <!-- ── Top Bar ── -->
    <header class="tix-pfs-header">
        <div class="tix-pfs-brand">
            <img src="<?php echo esc_url($logo_url); ?>" alt="Logo" class="tix-pfs-logo">
        </div>
        <div class="tix-pfs-user">
            <span class="tix-pfs-user-name"><?php echo esc_html($promoter->display_name ?: ($promoter->email ?: 'Promoter')); ?></span>
            <?php
            $logout_url = class_exists('TIX_Promoter_Auth')
                ? TIX_Promoter_Auth::logout_url()
                : wp_logout_url(home_url());
            ?>
            <a href="<?php echo esc_url($logout_url); ?>" class="tix-pfs-logout" title="Abmelden">
                <span class="dashicons dashicons-exit"></span>
            </a>
        </div>
    </header>

    <!-- ── Dashboard Content ── -->
    <main class="tix-pfs-main">
        <?php echo do_shortcode('[tix_promoter_dashboard]'); ?>
    </main>

    <?php wp_footer(); ?>
</body>
</html><?php
    }
}
