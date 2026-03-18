<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat Organizer Shell – Fullscreen-Modus für das Frontend-Veranstalter-Dashboard.
 *
 * Übernimmt die gesamte Seite per template_redirect wenn:
 * - Die Seite den [tix_organizer_dashboard] Shortcode enthält
 * - Der User eingeloggt ist und ein Veranstalter-Profil hat
 * - Das Setting organizer_fullscreen aktiv ist
 *
 * Rendert ein minimales HTML-Template ohne Theme-Chrome (Header/Footer/Sidebar).
 */
class TIX_Organizer_Shell {

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'maybe_render_fullscreen'], 5);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 99);
    }

    /**
     * Prüft ob die aktuelle Seite den Organizer-Dashboard-Shortcode enthält.
     * Berücksichtigt sowohl post_content als auch Breakdance-Daten.
     */
    private static function page_has_dashboard() {
        global $post;
        if (!$post) return false;

        // Standard WordPress Shortcode-Detection
        if (has_shortcode($post->post_content, 'tix_organizer_dashboard')) return true;

        // Breakdance: Shortcode steckt im JSON-Meta
        $bd = get_post_meta($post->ID, '_breakdance_data', true);
        if ($bd && is_string($bd) && strpos($bd, 'tix_organizer_dashboard') !== false) return true;

        return false;
    }

    /**
     * Shell-CSS laden wenn Fullscreen aktiv.
     */
    public static function enqueue() {
        if (!tix_get_settings('organizer_fullscreen')) return;
        if (!tix_get_settings('organizer_dashboard_enabled')) return;
        if (!is_user_logged_in()) return;
        if (!self::page_has_dashboard()) return;

        if (!class_exists('TIX_Organizer_Dashboard')) return;
        $org = TIX_Organizer_Dashboard::get_organizer_by_user(get_current_user_id());
        if (!$org) return;

        wp_enqueue_style(
            'tix-organizer-shell',
            TIXOMAT_URL . 'assets/css/organizer-shell.css',
            ['tix-organizer-dashboard'],
            TIXOMAT_VERSION
        );
    }

    /**
     * Prüft ob Fullscreen-Template geladen werden soll.
     */
    public static function maybe_render_fullscreen() {
        if (!tix_get_settings('organizer_fullscreen')) return;
        if (!tix_get_settings('organizer_dashboard_enabled')) return;
        if (!is_user_logged_in()) return;
        if (!self::page_has_dashboard()) return;

        if (!class_exists('TIX_Organizer_Dashboard')) return;
        $org = TIX_Organizer_Dashboard::get_organizer_by_user(get_current_user_id());
        if (!$org) return;

        self::render_template();
        exit;
    }

    /**
     * Minimales Fullscreen-Template ohne Theme-Chrome.
     */
    private static function render_template() {
        $logo_url = 'https://tixomat.de/wp-content/uploads/2026/03/logo-tixomat-light-500px.png';
        $user = wp_get_current_user();

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Tixomat &ndash; Veranstalter Dashboard</title>
    <?php wp_head(); ?>
    <style>
        /* Fullscreen Reset */
        html, body { margin: 0; padding: 0; min-height: 100vh; }
        body { background: #f4f1ec; }
        #wpadminbar { display: none !important; }
        html { margin-top: 0 !important; }
    </style>
</head>
<body class="tix-organizer-fullscreen">

    <!-- ── Top Bar ── -->
    <header class="tix-ofs-header">
        <div class="tix-ofs-brand">
            <img src="<?php echo esc_url($logo_url); ?>" alt="Tixomat" class="tix-ofs-logo">
        </div>
        <div class="tix-ofs-user">
            <span class="tix-ofs-user-name"><?php echo esc_html($user->display_name); ?></span>
            <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="tix-ofs-logout" title="Abmelden">
                <span class="dashicons dashicons-exit"></span>
            </a>
        </div>
    </header>

    <!-- ── Dashboard Content ── -->
    <main class="tix-ofs-main">
        <?php echo do_shortcode('[tix_organizer_dashboard]'); ?>
    </main>

    <?php wp_footer(); ?>
</body>
</html><?php
    }
}
