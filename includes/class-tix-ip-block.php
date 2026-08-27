<?php
/**
 * TIX IP-Block — einfache IP-Sperrliste gegen Bot-/Abuse-Traffic.
 *
 * Admin: Tixomat → 🛡️ IP-Sperren (eine IP pro Zeile, optional Praefix mit
 * abschliessendem Punkt, z.B. "84.143." sperrt das ganze /16-artige Praefix).
 *
 * Wirkung: Gesperrte IPs bekommen auf Frontend + AJAX sofort 403.
 * Eingeloggte Admins (manage_options) werden NIE geblockt — kein Selbst-Aussperren.
 */
if (!defined('ABSPATH')) exit;

class TIX_IP_Block {

    const OPTION     = 'tix_blocked_ips';
    const CAPABILITY = 'manage_options';

    public static function init() {
        // Frueh blocken — vor Cart/Checkout-Handlern
        add_action('init',        [__CLASS__, 'maybe_block'], 0);
        add_action('admin_menu',  [__CLASS__, 'register_menu'], 66);
        add_action('admin_post_tix_ip_block_save', [__CLASS__, 'handle_save']);
    }

    /* ─────────── Blocklogik ─────────── */

    public static function get_client_ip(): string {
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    public static function get_blocked(): array {
        $v = get_option(self::OPTION, []);
        return is_array($v) ? $v : [];
    }

    public static function is_blocked(string $ip): bool {
        if ($ip === '') return false;
        foreach (self::get_blocked() as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            if (substr($entry, -1) === '.') {
                // Praefix-Match: "84.143." trifft 84.143.*.*
                if (strpos($ip, $entry) === 0) return true;
            } elseif (strcasecmp($ip, $entry) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function maybe_block() {
        // Admins nie aussperren
        if (is_user_logged_in() && current_user_can(self::CAPABILITY)) return;
        // Cron/CLI nicht anfassen
        if ((defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) return;

        $ip = self::get_client_ip();
        if (!self::is_blocked($ip)) return;

        status_header(403);
        nocache_headers();
        exit('Forbidden');
    }

    /* ─────────── Admin-Seite ─────────── */

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'IP-Sperren',
            '🛡️ IP-Sperren',
            self::CAPABILITY,
            'tix-ip-block',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        if (!current_user_can(self::CAPABILITY)) return;
        $blocked = self::get_blocked();
        $own_ip  = self::get_client_ip();
        $saved   = isset($_GET['saved']);
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">🛡️ IP-Sperren</h1>
            <p style="max-width:820px;color:#475569;">
                Gesperrte IPs bekommen auf der gesamten Website sofort <code>403 Forbidden</code> —
                kein Warenkorb, kein Checkout, keine Seiten. Eingeloggte Administratoren werden nie geblockt.
            </p>
            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p><strong>Gespeichert.</strong> <?php echo count($blocked); ?> Eintraege aktiv.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:560px;">
                <input type="hidden" name="action" value="tix_ip_block_save">
                <?php wp_nonce_field('tix_ip_block'); ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;">
                    <label style="font-weight:600;display:block;margin-bottom:6px;">Gesperrte IPs <small style="color:#94a3b8;font-weight:400;">(eine pro Zeile)</small></label>
                    <textarea name="blocked_ips" rows="10" style="width:100%;font-family:monospace;font-size:13px;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="84.143.74.187&#10;203.0.113.5&#10;84.143.   ← Praefix (mit Punkt am Ende) sperrt den ganzen Bereich"><?php echo esc_textarea(implode("\n", $blocked)); ?></textarea>
                    <p style="color:#64748b;font-size:12px;margin:8px 0 0;">
                        Exakte IPv4/IPv6-Adressen, oder Praefix mit Punkt am Ende (z.B. <code>84.143.</code>).<br>
                        Deine aktuelle IP: <code><?php echo esc_html($own_ip); ?></code> — wird als eingeloggter Admin nie geblockt.
                    </p>
                </div>
                <p><input type="submit" class="button button-primary" value="Speichern"></p>
            </form>
        </div>
        <?php
    }

    public static function handle_save() {
        if (!current_user_can(self::CAPABILITY)) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_ip_block');

        $raw = (string) ($_POST['blocked_ips'] ?? '');
        $lines = preg_split('/[\r\n]+/', $raw);
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Erlaubt: valide IP ODER Praefix (Ziffern/Punkte/Hex/Doppelpunkte, endet mit . )
            if (filter_var($line, FILTER_VALIDATE_IP)) {
                $clean[] = $line;
            } elseif (preg_match('/^[0-9a-fA-F.:]+\.$/', $line)) {
                $clean[] = $line;
            }
        }
        $clean = array_values(array_unique($clean));
        update_option(self::OPTION, $clean, false);

        wp_safe_redirect(add_query_arg(['page' => 'tix-ip-block', 'saved' => 1], admin_url('admin.php')));
        exit;
    }
}

TIX_IP_Block::init();
