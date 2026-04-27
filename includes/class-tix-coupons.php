<?php
/**
 * Tixomat Gutscheine
 *
 * Globale Gutschein-Codes für den nativen Checkout (ohne WooCommerce-Abhängigkeit).
 * Wird unter `tix_coupons` als Option gespeichert.
 *
 * Format pro Code:
 *   [
 *     'code'          => 'SOMMER25',
 *     'discount_type' => 'percent' | 'fixed',
 *     'value'         => 25.0,
 *     'expires'       => '2026-09-30' | '',
 *     'max_uses'      => 100 | 0 (= unbegrenzt),
 *     'used'          => 0,
 *     'description'   => 'optional internal Notiz',
 *   ]
 */
if (!defined('ABSPATH')) exit;

class TIX_Coupons {

    const OPTION = 'tix_coupons';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 60);
        add_action('admin_post_tix_coupon_save',   [__CLASS__, 'handle_save']);
        add_action('admin_post_tix_coupon_delete', [__CLASS__, 'handle_delete']);
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Gutscheine',
            'Gutscheine',
            'manage_options',
            'tix-coupons',
            [__CLASS__, 'render_admin_page']
        );
    }

    // ════════════════════════════════════════════════════════════
    // Save handler (admin-post.php?action=tix_coupon_save)
    // ════════════════════════════════════════════════════════════

    public static function handle_save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_coupon_save');

        $code     = strtoupper(trim(sanitize_text_field($_POST['code'] ?? '')));
        $type     = $_POST['discount_type'] ?? 'percent';
        $value    = floatval(str_replace(',', '.', $_POST['value'] ?? '0'));
        $expires  = sanitize_text_field($_POST['expires'] ?? '');
        $max_uses = intval($_POST['max_uses'] ?? 0);
        $desc     = sanitize_text_field($_POST['description'] ?? '');
        $orig_code = strtoupper(trim(sanitize_text_field($_POST['orig_code'] ?? '')));

        if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            wp_redirect(add_query_arg(['page' => 'tix-coupons', 'msg' => 'invalid_code'], admin_url('admin.php')));
            exit;
        }
        if (!in_array($type, ['percent', 'fixed'], true)) $type = 'percent';
        if ($value <= 0) {
            wp_redirect(add_query_arg(['page' => 'tix-coupons', 'msg' => 'invalid_value'], admin_url('admin.php')));
            exit;
        }
        if ($type === 'percent' && $value > 100) $value = 100;

        $coupons = get_option(self::OPTION, []);
        if (!is_array($coupons)) $coupons = [];

        // Beim Bearbeiten: alten Code entfernen wenn umbenannt
        if ($orig_code && $orig_code !== $code && isset($coupons[$orig_code])) {
            $existing = $coupons[$orig_code];
            unset($coupons[$orig_code]);
            $used = intval($existing['used'] ?? 0); // Nutzungs-Counter erhalten
        } else {
            $used = intval($coupons[$code]['used'] ?? 0);
        }

        $coupons[$code] = [
            'code'          => $code,
            'discount_type' => $type,
            'value'         => round($value, 2),
            'expires'       => $expires,
            'max_uses'      => $max_uses,
            'used'          => $used,
            'description'   => $desc,
        ];

        update_option(self::OPTION, $coupons);

        wp_redirect(add_query_arg(['page' => 'tix-coupons', 'msg' => 'saved'], admin_url('admin.php')));
        exit;
    }

    public static function handle_delete() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_coupon_delete');

        $code = strtoupper(trim(sanitize_text_field($_REQUEST['code'] ?? '')));
        $coupons = get_option(self::OPTION, []);
        if (is_array($coupons) && isset($coupons[$code])) {
            unset($coupons[$code]);
            update_option(self::OPTION, $coupons);
        }
        wp_redirect(add_query_arg(['page' => 'tix-coupons', 'msg' => 'deleted'], admin_url('admin.php')));
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // Admin Page
    // ════════════════════════════════════════════════════════════

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');

        $coupons = get_option(self::OPTION, []);
        if (!is_array($coupons)) $coupons = [];
        ksort($coupons);

        $edit_code = strtoupper(trim(sanitize_text_field($_GET['edit'] ?? '')));
        $edit = $edit_code && isset($coupons[$edit_code]) ? $coupons[$edit_code] : null;

        $msg = $_GET['msg'] ?? '';
        $msg_text = '';
        $msg_type = 'success';
        switch ($msg) {
            case 'saved':         $msg_text = 'Gutschein gespeichert.'; break;
            case 'deleted':       $msg_text = 'Gutschein gelöscht.'; break;
            case 'invalid_code':  $msg_text = 'Ungültiger Code (3–40 Zeichen, A–Z, 0–9, _, -).'; $msg_type = 'error'; break;
            case 'invalid_value': $msg_text = 'Ungültiger Rabattwert.'; $msg_type = 'error'; break;
        }
        ?>
        <div class="wrap" style="max-width:1200px;">
            <h1>Gutscheine</h1>
            <p style="color:#6b7280;font-size:14px;margin:8px 0 24px;">
                Globale Gutscheincodes für alle Tickets. Kunden geben sie im Checkout im Feld „Gutscheincode" ein.
            </p>

            <?php if ($msg_text): ?>
                <div class="notice notice-<?php echo $msg_type; ?> is-dismissible"><p><?php echo esc_html($msg_text); ?></p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:24px;align-items:start;">

                <?php // ── Form: Anlegen / Bearbeiten ── ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;">
                    <h2 style="margin-top:0;font-size:18px;">
                        <?php echo $edit ? 'Gutschein bearbeiten' : 'Neuer Gutschein'; ?>
                    </h2>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('tix_coupon_save'); ?>
                        <input type="hidden" name="action" value="tix_coupon_save">
                        <?php if ($edit): ?>
                            <input type="hidden" name="orig_code" value="<?php echo esc_attr($edit['code']); ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th><label for="ttc-code">Code</label></th>
                                <td>
                                    <input type="text" id="ttc-code" name="code" class="regular-text" required
                                           value="<?php echo esc_attr($edit['code'] ?? ''); ?>"
                                           placeholder="z.B. SOMMER25"
                                           style="text-transform:uppercase;font-family:monospace;letter-spacing:1px;">
                                    <p class="description">3–40 Zeichen, A–Z, 0–9, _, -. Wird automatisch in Großbuchstaben umgewandelt.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Rabatt-Typ</th>
                                <td>
                                    <label style="margin-right:18px;">
                                        <input type="radio" name="discount_type" value="percent" <?php checked(($edit['discount_type'] ?? 'percent'), 'percent'); ?>>
                                        Prozent (%)
                                    </label>
                                    <label>
                                        <input type="radio" name="discount_type" value="fixed" <?php checked(($edit['discount_type'] ?? ''), 'fixed'); ?>>
                                        Fester Betrag (€)
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ttc-value">Wert</label></th>
                                <td>
                                    <input type="text" id="ttc-value" name="value" required
                                           value="<?php echo esc_attr($edit['value'] ?? ''); ?>"
                                           placeholder="z.B. 10 oder 5,00"
                                           style="width:120px;font-family:monospace;">
                                    <p class="description">Bei Prozent: 1–100. Bei Fest: Betrag in Euro.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ttc-expires">Gültig bis</label></th>
                                <td>
                                    <input type="date" id="ttc-expires" name="expires"
                                           value="<?php echo esc_attr($edit['expires'] ?? ''); ?>">
                                    <p class="description">Leer = nie ablaufend.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ttc-max">Max. Einlösungen</label></th>
                                <td>
                                    <input type="number" id="ttc-max" name="max_uses" min="0" step="1"
                                           value="<?php echo esc_attr($edit['max_uses'] ?? 0); ?>"
                                           style="width:120px;">
                                    <p class="description">0 = unbegrenzt. Bisher eingelöst: <strong><?php echo esc_html($edit['used'] ?? 0); ?></strong></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ttc-desc">Beschreibung</label></th>
                                <td>
                                    <input type="text" id="ttc-desc" name="description" class="regular-text"
                                           value="<?php echo esc_attr($edit['description'] ?? ''); ?>"
                                           placeholder="optional, intern">
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary">
                                <?php echo $edit ? 'Änderungen speichern' : 'Gutschein anlegen'; ?>
                            </button>
                            <?php if ($edit): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=tix-coupons')); ?>" class="button" style="margin-left:8px;">Abbrechen</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <?php // ── Liste ── ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;font-size:18px;">Bestehende Gutscheine (<?php echo count($coupons); ?>)</h2>
                    <?php if (empty($coupons)): ?>
                        <p style="color:#9ca3af;font-style:italic;">Noch keine Gutscheine angelegt.</p>
                    <?php else: ?>
                        <table class="wp-list-table widefat striped" style="margin-top:8px;">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Rabatt</th>
                                    <th>Gültig bis</th>
                                    <th>Einlösungen</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($coupons as $c):
                                $code   = $c['code'];
                                $type   = $c['discount_type'] ?? 'percent';
                                $val    = floatval($c['value'] ?? 0);
                                $exp    = $c['expires'] ?? '';
                                $exp_ts = $exp ? strtotime($exp) : 0;
                                $expired = $exp_ts && $exp_ts < time();
                                $used   = intval($c['used'] ?? 0);
                                $max    = intval($c['max_uses'] ?? 0);
                                $exhausted = $max > 0 && $used >= $max;
                                $disabled = $expired || $exhausted;
                                $edit_url   = admin_url('admin.php?page=tix-coupons&edit=' . urlencode($code));
                                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=tix_coupon_delete&code=' . urlencode($code)), 'tix_coupon_delete');
                            ?>
                                <tr<?php echo $disabled ? ' style="opacity:.55;"' : ''; ?>>
                                    <td>
                                        <strong style="font-family:monospace;font-size:13px;letter-spacing:1px;"><?php echo esc_html($code); ?></strong>
                                        <?php if (!empty($c['description'])): ?>
                                            <div style="font-size:11px;color:#9ca3af;"><?php echo esc_html($c['description']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($type === 'percent'): ?>
                                            <strong><?php echo number_format($val, 0, ',', '.'); ?> %</strong>
                                        <?php else: ?>
                                            <strong><?php echo number_format($val, 2, ',', '.'); ?> €</strong>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$exp): ?>
                                            <span style="color:#9ca3af;">unbegrenzt</span>
                                        <?php else: ?>
                                            <?php echo date_i18n('d.m.Y', $exp_ts); ?>
                                            <?php if ($expired): ?>
                                                <span style="background:#fee2e2;color:#b91c1c;padding:1px 7px;border-radius:6px;font-size:10px;font-weight:700;margin-left:4px;">ABGELAUFEN</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $used; ?> / <?php echo $max > 0 ? $max : '∞'; ?>
                                        <?php if ($exhausted): ?>
                                            <span style="background:#fef3c7;color:#b45309;padding:1px 7px;border-radius:6px;font-size:10px;font-weight:700;margin-left:4px;">AUSGESCHÖPFT</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;white-space:nowrap;">
                                        <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">Bearbeiten</a>
                                        <a href="<?php echo esc_url($delete_url); ?>" class="button button-small" style="color:#b91c1c;"
                                           onclick="return confirm('Gutschein <?php echo esc_js($code); ?> wirklich löschen?');">×</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php
    }
}
