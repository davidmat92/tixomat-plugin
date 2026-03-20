<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Custom_URLs – Custom Login- und Veranstalter-URLs.
 */
class TIX_Custom_URLs {

    public static function init() {
        $login_slug     = tix_get_settings('login_slug');
        $organizer_slug = tix_get_settings('organizer_slug');

        if (!$login_slug && !$organizer_slug) return;

        // URL-Erkennung
        add_action('init', [__CLASS__, 'intercept_request'], 1);

        // wp-login.php → Custom Login URL
        if ($login_slug) {
            add_action('login_init', [__CLASS__, 'redirect_wp_login']);
            add_filter('login_url', [__CLASS__, 'filter_login_url'], 99, 3);
            add_filter('wp_login_url', [__CLASS__, 'filter_login_url'], 99, 3);
        }
    }

    private static function get_request_path() {
        $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        $home_path = trim(parse_url(home_url(), PHP_URL_PATH) ?: '', '/');
        if ($home_path && strpos($path, $home_path) === 0) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }
        return $path;
    }

    /**
     * Request abfangen. Läuft auf init (WP ist vollständig geladen, Nonces funktionieren).
     */
    public static function intercept_request() {
        // Nicht im Admin oder bei AJAX
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;

        $path = self::get_request_path();
        $login_slug     = tix_get_settings('login_slug');
        $organizer_slug = tix_get_settings('organizer_slug');

        // ── Login-Seite ──
        if ($login_slug && $path === $login_slug) {
            if (is_user_logged_in()) {
                $user = wp_get_current_user();
                if (in_array('tix_organizer', (array) $user->roles, true)) {
                    wp_redirect(admin_url('admin.php?page=tix-organizer-dashboard'));
                } else {
                    wp_redirect(admin_url());
                }
                exit;
            }
            self::render_login_page();
            exit;
        }

        // ── Veranstalter-Seite ──
        if ($organizer_slug && $path === $organizer_slug) {
            if (!is_user_logged_in()) {
                wp_redirect(home_url('/' . ($login_slug ?: 'wp-login.php') . '/'));
                exit;
            }
            wp_redirect(admin_url('admin.php?page=tix-organizer-dashboard'));
            exit;
        }
    }

    /**
     * wp-login.php → Custom Login URL.
     */
    public static function redirect_wp_login() {
        $login_slug = tix_get_settings('login_slug');
        if (!$login_slug) return;

        $action = $_REQUEST['action'] ?? '';
        if (in_array($action, ['logout', 'postpass', 'rp', 'resetpass', 'lostpassword', 'confirmaction'], true)) return;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') return;
        if (wp_doing_ajax() || wp_doing_cron()) return;

        wp_redirect(home_url('/' . $login_slug . '/'));
        exit;
    }

    public static function filter_login_url($url, $redirect = '', $force_reauth = false) {
        $login_slug = tix_get_settings('login_slug');
        if (!$login_slug) return $url;
        $new_url = home_url('/' . $login_slug . '/');
        if ($redirect) $new_url = add_query_arg('redirect_to', urlencode($redirect), $new_url);
        return $new_url;
    }

    /**
     * Login-Seite rendern.
     */
    private static function render_login_page() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $custom_logo = $s['admin_logo_url'] ?? '';
        $logo_url = $custom_logo ?: 'https://tixomat.de/wp-content/uploads/2026/03/logo-tixomat-light-500px.png';
        $error = '';

        // Login verarbeiten
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['log'])) {
            $user = wp_signon([
                'user_login'    => sanitize_text_field($_POST['log']),
                'user_password' => $_POST['pwd'] ?? '',
                'remember'      => !empty($_POST['rememberme']),
            ], is_ssl());

            if (!is_wp_error($user)) {
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID, !empty($_POST['rememberme']));

                $redirect = $_POST['redirect_to'] ?? '';
                if (!$redirect) {
                    $redirect = in_array('tix_organizer', (array) $user->roles, true)
                        ? admin_url('admin.php?page=tix-organizer-dashboard')
                        : admin_url();
                }
                wp_redirect($redirect);
                exit;
            }
            $error = $user->get_error_message();
        }

        $redirect_to = $_GET['redirect_to'] ?? '';

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Anmelden &ndash; <?php bloginfo('name'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        html,body{min-height:100vh}
        body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:#FAF8F4;display:flex;align-items:center;justify-content:center;-webkit-font-smoothing:antialiased}
        .tix-login{width:100%;max-width:400px;padding:20px}
        .tix-login-logo{text-align:center;margin-bottom:32px}
        .tix-login-logo img{height:36px;width:auto}
        .tix-login-card{background:#fff;border-radius:16px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.04),0 8px 24px rgba(0,0,0,.06)}
        .tix-login-title{font-size:20px;font-weight:700;color:#0D0B09;margin-bottom:24px;text-align:center}
        .tix-login-field{margin-bottom:16px}
        .tix-login-label{display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:6px}
        .tix-login-input{width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;font-family:inherit;color:#0D0B09;background:#fff;outline:none;transition:border-color .15s,box-shadow .15s}
        <?php $cp = $s['color_primary'] ?? '#FF5500'; $cpr = hexdec(substr($cp,1,2)); $cpg = hexdec(substr($cp,3,2)); $cpb = hexdec(substr($cp,5,2)); ?>
        .tix-login-input:focus{border-color:<?php echo esc_attr($cp); ?>;box-shadow:0 0 0 3px rgba(<?php echo "$cpr,$cpg,$cpb"; ?>,.12)}
        .tix-login-remember{display:flex;align-items:center;gap:8px;margin-bottom:20px;font-size:13px;color:#64748b}
        .tix-login-submit{width:100%;padding:12px;background:<?php echo esc_attr($s['color_primary'] ?? '#FF5500'); ?>;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;transition:background .15s,transform .1s}
        .tix-login-submit:hover{opacity:.88}
        .tix-login-submit:active{transform:scale(.98)}
        .tix-login-error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;font-size:13px;margin-bottom:16px}
        .tix-login-footer{text-align:center;margin-top:16px;font-size:12px;color:#94a3b8}
        .tix-login-footer a{color:#64748b;text-decoration:none}
        .tix-login-footer a:hover{color:<?php echo esc_attr($s['color_primary'] ?? '#FF5500'); ?>}
    </style>
</head>
<body>
    <div class="tix-login">
        <div class="tix-login-logo">
            <img src="<?php echo esc_url($logo_url); ?>" alt="Tixomat">
        </div>
        <div class="tix-login-card">
            <h1 class="tix-login-title">Anmelden</h1>
            <?php if ($error) : ?>
                <div class="tix-login-error"><?php echo wp_kses_post($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <?php if ($redirect_to) : ?>
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
                <?php endif; ?>
                <div class="tix-login-field">
                    <label class="tix-login-label" for="tix-log">E-Mail oder Benutzername</label>
                    <input type="text" id="tix-log" name="log" class="tix-login-input"
                           value="<?php echo esc_attr($_POST['log'] ?? ''); ?>" autocomplete="username" autofocus>
                </div>
                <div class="tix-login-field">
                    <label class="tix-login-label" for="tix-pwd">Passwort</label>
                    <input type="password" id="tix-pwd" name="pwd" class="tix-login-input" autocomplete="current-password">
                </div>
                <label class="tix-login-remember">
                    <input type="checkbox" name="rememberme" value="forever">
                    Angemeldet bleiben
                </label>
                <button type="submit" class="tix-login-submit">Anmelden</button>
            </form>
        </div>
        <div class="tix-login-footer">
            <a href="<?php echo esc_url(site_url('wp-login.php?action=lostpassword')); ?>">Passwort vergessen?</a>
        </div>
    </div>
</body>
</html><?php
    }
}
