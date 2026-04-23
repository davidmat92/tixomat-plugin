<?php
/**
 * TIX Account Activation
 *
 * Kontoaktivierung für neu angelegte Kunden (über Checkout-Checkbox).
 * Fluss:
 *   1. Checkout: User anlegen → assign_customer_role → trigger_activation($user_id, $order_id)
 *   2. Email mit Magic-Link: /?tix_activate=1&key=KEY&login=USER
 *   3. Empfänger klickt Link → branded Fullscreen-Page zum Passwort setzen
 *   4. Passwort-Submit → Passwort speichern → einloggen → auf /tickets/ redirect
 *
 * Die Aktivierungs-Seite holt das Veranstalter-Logo über die _tix_ol_source-
 * Order-Meta (Attribution aus Landing-Subdomain) — Fallback: Site-Logo.
 */
if (!defined('ABSPATH')) exit;

class TIX_Account_Activation {

    const QUERY_FLAG = 'tix_activate';
    const NONCE      = 'tix_activate_set_password';

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'maybe_handle'], 5);

        // WooCommerce: wenn User via WC-Checkout "Konto anlegen" tickt,
        // bekommt er normalerweise eine WC-Mail (Login-Daten). Wir senden ZUSÄTZLICH
        // unsere gebrandete Aktivierungs-Mail mit Passwort-Setup-Link.
        add_action('woocommerce_created_customer', [__CLASS__, 'handle_wc_created_customer'], 20, 3);
    }

    /**
     * Hook-Handler: WC hat einen neuen Kunden angelegt (z.B. via Checkout-Checkbox).
     * Rollt Kunden-Rolle aus + schickt branded Activation-Mail.
     */
    public static function handle_wc_created_customer($customer_id, $new_customer_data = [], $password_generated = false) {
        if (!$customer_id) return;

        // Kunden-Rolle zuweisen (statt Default subscriber/customer)
        if (class_exists('TIX_Customer_Role')) {
            TIX_Customer_Role::assign_to_user($customer_id);
        }

        // Jüngste Order des Customers für Branding ermitteln
        $order_id = 0;
        global $wpdb;
        $wc_t = $wpdb->prefix . 'wc_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '$wc_t'") === $wc_t) {
            $order_id = intval($wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $wc_t WHERE customer_id = %d ORDER BY date_created_gmt DESC LIMIT 1",
                $customer_id
            )));
        }
        if (!$order_id) {
            $t = $wpdb->prefix . 'tix_orders';
            if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
                $order_id = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $t WHERE customer_id = %d ORDER BY date_created DESC LIMIT 1",
                    $customer_id
                )));
            }
        }

        // Aktivierungs-Mail senden
        self::trigger_activation($customer_id, $order_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // TRIGGER — vom Checkout aufgerufen nach User-Anlage
    // ═══════════════════════════════════════════════════════════════

    /**
     * Schickt die Aktivierungs-Email an einen frisch angelegten Kunden.
     * Wenn $order_id gesetzt: wir versuchen das Veranstalter-Logo aus der Order-Attribution zu ziehen.
     */
    public static function trigger_activation($user_id, $order_id = 0) {
        $user = get_userdata($user_id);
        if (!$user) return false;

        $url = self::build_activation_url($user);
        if (!$url) return false;

        $branding = self::get_branding_for_order($order_id);

        $subject = sprintf('Willkommen bei %s — Passwort vergeben', $branding['site_name']);

        $body  = '<p>Hallo ' . esc_html($user->first_name ?: $user->display_name) . ',</p>';
        $body .= '<p>vielen Dank für deine Bestellung! Wir haben ein Konto für dich angelegt, damit du jederzeit auf deine Tickets zugreifen kannst.</p>';
        $body .= '<p><strong>Klicke auf den Button, um dein Passwort zu vergeben und dein Konto zu aktivieren:</strong></p>';
        $body .= '<p style="text-align:center;margin:32px 0;">';
        $body .= '<a href="' . esc_url($url) . '" style="display:inline-block;padding:14px 32px;background:' . esc_attr($branding['primary']) . ';color:#fff;border-radius:10px;text-decoration:none;font-weight:600;font-size:15px;">Konto aktivieren</a>';
        $body .= '</p>';
        $body .= '<p style="color:#64748b;font-size:13px;line-height:1.6;">Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:<br><span style="word-break:break-all;color:#334155;">' . esc_html($url) . '</span></p>';
        $body .= '<p style="color:#94a3b8;font-size:12px;margin-top:24px;">Wenn du kein Konto erstellen wolltest, kannst du diese E-Mail ignorieren.</p>';

        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html('Konto aktivieren', $body, 'Nur noch ein Schritt')
            : '<html><body>' . $body . '</body></html>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        return wp_mail($user->user_email, $subject, $html, $headers);
    }

    /**
     * Baut die Aktivierungs-URL mit Password-Reset-Key.
     */
    private static function build_activation_url($user) {
        $key = get_password_reset_key($user);
        if (is_wp_error($key)) return '';

        $args = [
            self::QUERY_FLAG => '1',
            'key'            => $key,
            'login'          => rawurlencode($user->user_login),
        ];
        return add_query_arg($args, home_url('/'));
    }

    // ═══════════════════════════════════════════════════════════════
    // BRANDING — hole Logo + Farben aus Order-Attribution
    // ═══════════════════════════════════════════════════════════════

    /**
     * Liefert Branding-Daten:
     *   - logo: URL zum Logo (Veranstalter > Site)
     *   - site_name: Name (Veranstalter > Site)
     *   - primary: Primärfarbe
     *   - bg: Hintergrund
     *
     * @param int $order_id 0 = nur Site-Default
     */
    private static function get_branding_for_order($order_id = 0) {
        $defaults = [
            'logo'      => get_site_icon_url(256) ?: '',
            'site_name' => get_bloginfo('name'),
            'primary'   => '#0f172a',
            'bg'        => '#f8fafc',
            'text'      => '#131020',
        ];

        if (!$order_id) return $defaults;

        // Organizer-Slug aus Order-Meta (Attribution-Cookie)
        $slug = get_post_meta($order_id, '_tix_ol_source', true);
        if (!$slug && class_exists('TIX_Order')) {
            // Auch bei native orders checken (Meta dort ggf. anders)
            $order = TIX_Order::get($order_id);
            if ($order) {
                // Fallback: erstes Event im Order → Organizer ID
                $items = method_exists($order, 'get_items') ? $order->get_items() : [];
                foreach ($items as $it) {
                    $eid = method_exists($it, 'get_event_id') ? intval($it->get_event_id()) : 0;
                    if (!$eid) continue;
                    $org_id = intval(get_post_meta($eid, '_tix_organizer_id', true));
                    if ($org_id) {
                        $maybe_slug = get_post_meta($org_id, '_tix_org_landing_slug', true);
                        if ($maybe_slug) { $slug = $maybe_slug; break; }
                    }
                }
            }
        }

        if (!$slug || !class_exists('TIX_Organizer_Landing')) return $defaults;

        $org = TIX_Organizer_Landing::get_organizer_by_slug($slug);
        if (!$org) return $defaults;

        $data = TIX_Organizer_Landing::get_landing_data($org);
        if (!is_array($data)) return $defaults;

        return [
            'logo'      => !empty($data['logo'])          ? $data['logo']          : $defaults['logo'],
            'site_name' => $org->post_title                ?: $defaults['site_name'],
            'primary'   => !empty($data['primary_color']) ? $data['primary_color'] : $defaults['primary'],
            'bg'        => !empty($data['bg_color'])      ? $data['bg_color']      : $defaults['bg'],
            'text'      => !empty($data['color_mode']) && $data['color_mode'] === 'dark' ? '#f5f5f7' : '#131020',
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // HANDLER — rendert Fullscreen-Page + behandelt Password-Set
    // ═══════════════════════════════════════════════════════════════

    public static function maybe_handle() {
        if (empty($_GET[self::QUERY_FLAG])) return;

        $key   = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        $login = sanitize_user(wp_unslash($_GET['login'] ?? ''), true);

        $user = !empty($key) && !empty($login) ? check_password_reset_key($key, $login) : new WP_Error('missing', 'Link unvollständig.');

        // POST: Neues Passwort setzen
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_wp_error($user)) {
            self::handle_password_set($user);
            // kein return — fall through falls handle_password_set nicht exit
        }

        self::render_page($user, $key, $login);
        exit;
    }

    private static function handle_password_set($user) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], self::NONCE)) {
            self::render_page($user, $_POST['key'] ?? '', $_POST['login'] ?? '', 'Sicherheits-Token abgelaufen. Lade die Seite neu.');
            exit;
        }

        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            self::render_page($user, $_POST['key'] ?? '', $_POST['login'] ?? '', 'Das Passwort muss mindestens 8 Zeichen lang sein.');
            exit;
        }
        if ($password !== $confirm) {
            self::render_page($user, $_POST['key'] ?? '', $_POST['login'] ?? '', 'Die Passwörter stimmen nicht überein.');
            exit;
        }

        reset_password($user, $password);

        // Direkt einloggen
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true, is_ssl());

        // Redirect zur Tickets-Seite (auf Subdomain bleibt Subdomain, sonst Haupt-Site)
        $target = home_url('/tickets/');
        wp_safe_redirect($target);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════
    // RENDER — Fullscreen branded Page
    // ═══════════════════════════════════════════════════════════════

    private static function render_page($user_or_error, $key = '', $login = '', $inline_error = '') {
        while (ob_get_level() > 0) ob_end_clean();

        status_header(is_wp_error($user_or_error) ? 400 : 200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');

        $is_error = is_wp_error($user_or_error);
        $user     = $is_error ? null : $user_or_error;

        // Branding aus letzter Order des Users (wenn verfügbar)
        $branding = $user ? self::get_branding_for_user($user->ID) : self::get_branding_for_order(0);

        $logo     = $branding['logo'];
        $primary  = $branding['primary'];
        $site     = $branding['site_name'];

        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Konto aktivieren – <?php echo esc_html($site); ?></title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        *,*::before,*::after { box-sizing: border-box; }
        html,body { margin: 0; padding: 0; min-height: 100vh; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            background: <?php echo esc_attr($branding['bg']); ?>;
            color: <?php echo esc_attr($branding['text']); ?>;
            display: flex; align-items: center; justify-content: center;
            padding: 24px;
        }
        .tix-act-wrap {
            width: 100%;
            max-width: 440px;
            text-align: center;
        }
        .tix-act-logo {
            margin: 0 auto 28px;
            max-height: 72px;
            width: auto;
            max-width: 220px;
            display: block;
            object-fit: contain;
        }
        .tix-act-no-logo {
            font-size: 24px; font-weight: 800; margin-bottom: 28px;
            color: <?php echo esc_attr($primary); ?>;
        }
        .tix-act-card {
            background: #fff;
            border-radius: 18px;
            padding: 36px 32px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
            text-align: left;
        }
        .tix-act-card.is-error-view { text-align: center; }
        .tix-act-title {
            font-size: 22px; font-weight: 700;
            margin: 0 0 8px;
            color: #0f172a;
            text-align: center;
        }
        .tix-act-sub {
            font-size: 14px; color: #64748b;
            margin: 0 0 24px;
            text-align: center;
            line-height: 1.5;
        }
        .tix-act-field { margin-bottom: 14px; }
        .tix-act-label {
            display: block;
            font-size: 12px; font-weight: 600;
            color: #334155; margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .tix-act-input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            background: #fff;
            color: #0f172a;
        }
        .tix-act-input:focus {
            outline: none;
            border-color: <?php echo esc_attr($primary); ?>;
            box-shadow: 0 0 0 3px <?php echo esc_attr(self::hex_to_rgba($primary, 0.15)); ?>;
        }
        .tix-act-btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            background: <?php echo esc_attr($primary); ?>;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: opacity 0.15s, transform 0.15s;
        }
        .tix-act-btn:hover { opacity: 0.92; transform: translateY(-1px); }
        .tix-act-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px;
            line-height: 1.4;
        }
        .tix-act-meta {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 24px;
        }
        .tix-act-hint {
            font-size: 12px; color: #64748b;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="tix-act-wrap">
        <?php if ($logo): ?>
            <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($site); ?>" class="tix-act-logo">
        <?php else: ?>
            <div class="tix-act-no-logo"><?php echo esc_html($site); ?></div>
        <?php endif; ?>

        <?php if ($is_error): ?>
            <div class="tix-act-card is-error-view">
                <h1 class="tix-act-title">Link ungültig oder abgelaufen</h1>
                <p class="tix-act-sub">Dieser Aktivierungs-Link funktioniert nicht mehr. Links sind aus Sicherheitsgründen zeitlich begrenzt gültig.</p>
                <p class="tix-act-sub">Melde dich bei uns, wir schicken dir einen neuen.</p>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="tix-act-btn" style="text-decoration:none;display:inline-block;width:auto;padding:12px 24px;margin-top:8px;">Zur Startseite</a>
            </div>
        <?php else: ?>
            <div class="tix-act-card">
                <h1 class="tix-act-title">Willkommen, <?php echo esc_html($user->first_name ?: $user->display_name); ?>!</h1>
                <p class="tix-act-sub">Vergib ein Passwort, um dein Konto zu aktivieren und auf deine Tickets zuzugreifen.</p>

                <?php if ($inline_error): ?>
                    <div class="tix-act-error"><?php echo esc_html($inline_error); ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="on">
                    <input type="hidden" name="key"   value="<?php echo esc_attr($key); ?>">
                    <input type="hidden" name="login" value="<?php echo esc_attr($login); ?>">
                    <?php wp_nonce_field(self::NONCE); ?>

                    <div class="tix-act-field">
                        <label class="tix-act-label" for="tix-act-pw">Neues Passwort</label>
                        <input class="tix-act-input" type="password" id="tix-act-pw" name="password" required minlength="8" autocomplete="new-password">
                        <div class="tix-act-hint">Mindestens 8 Zeichen.</div>
                    </div>

                    <div class="tix-act-field">
                        <label class="tix-act-label" for="tix-act-pw2">Passwort bestätigen</label>
                        <input class="tix-act-input" type="password" id="tix-act-pw2" name="password_confirm" required minlength="8" autocomplete="new-password">
                    </div>

                    <button type="submit" class="tix-act-btn">Konto aktivieren</button>
                </form>
            </div>

            <div class="tix-act-meta">Danach wirst du automatisch eingeloggt.</div>
        <?php endif; ?>
    </div>
</body>
</html><?php
    }

    /**
     * Branding für einen User ermitteln — schaut auf die jüngste Order.
     */
    private static function get_branding_for_user($user_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';
        $oid = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            $oid = intval($wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $t WHERE customer_id = %d ORDER BY date_created DESC LIMIT 1",
                $user_id
            )));
        }
        return self::get_branding_for_order($oid);
    }

    /** Hex → rgba Conversion für Focus-Ring */
    private static function hex_to_rgba($hex, $alpha = 0.15) {
        $hex = trim((string) $hex, '#');
        if (strlen($hex) !== 6) return 'rgba(99,102,241,0.15)';
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "rgba($r,$g,$b,$alpha)";
    }
}
