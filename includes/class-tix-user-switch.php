<?php
/**
 * TIX_User_Switch — Admin kann sich temporär als Kunde X einloggen für Support-Zwecke.
 *
 * Sicherheits-Konzept:
 *  - Nur Admins (manage_options) dürfen switchen
 *  - Original-User-ID wird in einem signierten Cookie gespeichert
 *  - Switch + Switch-Back per admin-post.php mit Nonce
 *  - Frontend-Banner zeigt "Du bist als X eingeloggt — zurück zu Admin"
 *  - Nicht möglich auf Admins zu switchen (sonst Privilege Escalation)
 */
if (!defined('ABSPATH')) exit;

class TIX_User_Switch {

    const COOKIE_NAME = 'tix_user_switch';
    const NONCE_KEY   = 'tix_user_switch';

    public static function init() {
        // Switch-Endpoints
        add_action('admin_post_tix_user_switch_to',   [__CLASS__, 'handle_switch_to']);
        add_action('admin_post_tix_user_switch_back', [__CLASS__, 'handle_switch_back']);
        // Auch nopriv erlauben — wir verifizieren nur via Cookie + Nonce, nicht via Login-Status
        add_action('admin_post_nopriv_tix_user_switch_back', [__CLASS__, 'handle_switch_back']);

        // Frontend-Banner für aktiv-switched Sessions
        add_action('wp_footer',     [__CLASS__, 'render_banner']);
        add_action('admin_notices', [__CLASS__, 'render_banner_admin']);
        // Admin-Footer für admin-bar-lose Profile (tix_customer hat kein Admin-Bar)
        add_action('admin_footer',  [__CLASS__, 'render_banner_admin_footer']);

        // Row-Action in der WP-User-Liste: "Als diesen User einloggen"
        add_filter('user_row_actions', [__CLASS__, 'user_row_action'], 10, 2);
    }

    /**
     * HMAC-Signatur für ein Cookie-Payload (User-unabhängig, im Gegensatz zu wp_create_nonce!).
     * Wichtig: wp_create_nonce ist an current_user_id gebunden — nach dem Switch verifiziert
     * der neue (Kunden-)User die Signatur des Admins NICHT. Daher wp_salt() + hash_hmac.
     */
    private static function sign($admin_id) {
        return hash_hmac('sha256', 'tix-switch|' . intval($admin_id), wp_salt('auth'));
    }

    /**
     * Cookie-Name für die Switch-Session inkl. der echten Admin-ID.
     */
    private static function set_switch_cookie($admin_id) {
        $payload = $admin_id . '|' . self::sign($admin_id);
        setcookie(
            self::COOKIE_NAME,
            $payload,
            time() + (4 * HOUR_IN_SECONDS),
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true // HttpOnly
        );
        // Auch im SiteCookiePath setzen (manche Themes nutzen Subverzeichnisse)
        if (defined('SITECOOKIEPATH') && SITECOOKIEPATH !== COOKIEPATH) {
            setcookie(self::COOKIE_NAME, $payload, time() + (4 * HOUR_IN_SECONDS), SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }
        $_COOKIE[self::COOKIE_NAME] = $payload;
    }

    private static function clear_switch_cookie() {
        setcookie(self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        if (defined('SITECOOKIEPATH') && SITECOOKIEPATH !== COOKIEPATH) {
            setcookie(self::COOKIE_NAME, '', time() - 3600, SITECOOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Liefert die Original-Admin-ID falls aktuell ein Switch aktiv ist, sonst 0.
     */
    public static function get_original_admin_id() {
        if (empty($_COOKIE[self::COOKIE_NAME])) return 0;
        $parts = explode('|', $_COOKIE[self::COOKIE_NAME], 2);
        if (count($parts) !== 2) return 0;
        $admin_id = intval($parts[0]);
        if (!$admin_id) return 0;
        // HMAC-Vergleich (timing-safe). User-unabhängig — funktioniert auch nach Switch.
        $expected = self::sign($admin_id);
        if (!hash_equals($expected, (string) $parts[1])) return 0;
        // Zusätzlicher Check: ist dieser User wirklich Admin?
        $u = get_user_by('id', $admin_id);
        if (!$u || !user_can($u, 'manage_options')) return 0;
        return $admin_id;
    }

    public static function handle_switch_to() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_KEY);

        $target_id = intval($_REQUEST['user_id'] ?? 0);
        if (!$target_id) wp_die('Kein Ziel-User angegeben.');

        $target = get_user_by('id', $target_id);
        if (!$target) wp_die('User nicht gefunden.');

        // SICHERHEIT: niemals auf andere Admins switchen
        if (user_can($target, 'manage_options')) {
            wp_die('Switch auf andere Admins ist nicht erlaubt (Sicherheit).');
        }

        $admin_id = get_current_user_id();
        self::set_switch_cookie($admin_id);

        // Logout + neu einloggen als Target
        wp_clear_auth_cookie();
        wp_set_current_user($target_id);
        wp_set_auth_cookie($target_id, false);

        // Redirect zur Frontend-Page (Mein-Konto)
        $redirect = home_url('/mein-konto/');
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_switch_back() {
        check_admin_referer(self::NONCE_KEY);

        $admin_id = self::get_original_admin_id();
        if (!$admin_id) wp_die('Keine aktive Switch-Session gefunden.');

        wp_clear_auth_cookie();
        wp_set_current_user($admin_id);
        wp_set_auth_cookie($admin_id, false);
        self::clear_switch_cookie();

        $redirect = $_REQUEST['redirect_to'] ?? admin_url('users.php');
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Banner unten am Frontend wenn aktiv-switched.
     */
    public static function render_banner() {
        if (is_admin()) return;
        $admin_id = self::get_original_admin_id();
        if (!$admin_id) return;
        $current = wp_get_current_user();
        if (!$current || $current->ID === $admin_id) return;

        $back_url = wp_nonce_url(
            admin_url('admin-post.php?action=tix_user_switch_back&redirect_to=' . urlencode(admin_url('users.php'))),
            self::NONCE_KEY
        );
        ?>
        <div id="tix-switch-banner" style="position:fixed;bottom:0;left:0;right:0;z-index:99998;background:linear-gradient(90deg,#FF5500,#dc2626);color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:14px;box-shadow:0 -4px 16px rgba(0,0,0,.2);font-family:system-ui,-apple-system,sans-serif;font-size:14px;">
            <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;">
                <span style="font-size:18px;">🎭</span>
                <span><strong>Support-Modus:</strong> Du bist eingeloggt als <strong><?php echo esc_html($current->display_name ?: $current->user_login); ?></strong> (<?php echo esc_html($current->user_email); ?>)</span>
            </div>
            <a href="<?php echo esc_url($back_url); ?>" style="background:#fff;color:#dc2626;padding:8px 18px;border-radius:8px;font-weight:700;text-decoration:none;white-space:nowrap;font-size:13px;">← Zurück zu Admin</a>
        </div>
        <style>body { padding-bottom: 60px !important; }</style>
        <?php
    }

    /**
     * Banner für admin-pages (z.B. wenn der user als Customer auf Profil-Seiten landet).
     */
    public static function render_banner_admin() {
        $admin_id = self::get_original_admin_id();
        if (!$admin_id) return;
        $current = wp_get_current_user();
        if (!$current || $current->ID === $admin_id) return;

        $back_url = wp_nonce_url(
            admin_url('admin-post.php?action=tix_user_switch_back&redirect_to=' . urlencode(admin_url('users.php'))),
            self::NONCE_KEY
        );
        ?>
        <div class="notice notice-warning" style="border-left-color:#dc2626;">
            <p>🎭 <strong>Support-Modus:</strong> Du bist eingeloggt als <strong><?php echo esc_html($current->display_name); ?></strong>.
                <a href="<?php echo esc_url($back_url); ?>" class="button button-small" style="margin-left:8px;">Zurück zu Admin</a>
            </p>
        </div>
        <?php
    }

    /**
     * Floating-Banner im Admin-Footer (auch sichtbar wenn admin_notices unterdrückt
     * werden — z.B. tix_customer-Rolle hat keine Admin-Bar und wenig Admin-UI).
     */
    public static function render_banner_admin_footer() {
        $admin_id = self::get_original_admin_id();
        if (!$admin_id) return;
        $current = wp_get_current_user();
        if (!$current || $current->ID === $admin_id) return;

        $back_url = wp_nonce_url(
            admin_url('admin-post.php?action=tix_user_switch_back&redirect_to=' . urlencode(admin_url('users.php'))),
            self::NONCE_KEY
        );
        ?>
        <div id="tix-switch-banner-admin" style="position:fixed;bottom:0;left:0;right:0;z-index:99998;background:linear-gradient(90deg,#FF5500,#dc2626);color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:14px;box-shadow:0 -4px 16px rgba(0,0,0,.2);font-family:system-ui,-apple-system,sans-serif;font-size:14px;">
            <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;">
                <span style="font-size:18px;">🎭</span>
                <span><strong>Support-Modus:</strong> Du bist eingeloggt als <strong><?php echo esc_html($current->display_name ?: $current->user_login); ?></strong> (<?php echo esc_html($current->user_email); ?>)</span>
            </div>
            <a href="<?php echo esc_url($back_url); ?>" style="background:#fff;color:#dc2626;padding:8px 18px;border-radius:8px;font-weight:700;text-decoration:none;white-space:nowrap;font-size:13px;">← Zur&uuml;ck zu Admin</a>
        </div>
        <style>#wpfooter,#wpadminbar{margin-bottom:60px;}body.wp-admin{padding-bottom:60px !important;}</style>
        <?php
    }

    /**
     * Row-Action "Als diesen User einloggen" in der WP-User-Liste.
     */
    public static function user_row_action($actions, $user) {
        if (!current_user_can('manage_options')) return $actions;
        if (user_can($user, 'manage_options')) return $actions; // keine andere Admins
        if ($user->ID === get_current_user_id()) return $actions;

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=tix_user_switch_to&user_id=' . $user->ID),
            self::NONCE_KEY
        );
        $actions['tix_switch'] = '<a href="' . esc_url($url) . '" style="color:#FF5500;font-weight:600;">🎭 Als Kunde einloggen</a>';
        return $actions;
    }

    /**
     * Helper: URL für Switch-To erzeugen — kann an beliebiger Stelle (Kunden-Liste etc.) genutzt werden.
     */
    public static function get_switch_url($user_id) {
        return wp_nonce_url(
            admin_url('admin-post.php?action=tix_user_switch_to&user_id=' . intval($user_id)),
            self::NONCE_KEY
        );
    }
}
