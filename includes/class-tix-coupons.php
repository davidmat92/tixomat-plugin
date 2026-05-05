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
        add_action('admin_post_tix_coupon_save',         [__CLASS__, 'handle_save']);
        add_action('admin_post_tix_coupon_delete',       [__CLASS__, 'handle_delete']);
        add_action('admin_post_tix_coupon_popup_save',   [__CLASS__, 'handle_popup_save']);
    }

    /**
     * Save-Handler für Popup-Settings (separater Form auf der Coupon-Seite)
     */
    public static function handle_popup_save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_coupon_popup_save');

        $s = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        if (!is_array($s)) $s = [];

        $s['coupon_popup_enabled']  = !empty($_POST['coupon_popup_enabled']) ? 1 : 0;
        $s['coupon_popup_headline'] = sanitize_text_field($_POST['coupon_popup_headline'] ?? '');
        $s['coupon_popup_subtext']  = sanitize_textarea_field($_POST['coupon_popup_subtext'] ?? '');
        $s['coupon_popup_cta']      = sanitize_text_field($_POST['coupon_popup_cta'] ?? '');
        $s['coupon_popup_cta_url']  = esc_url_raw($_POST['coupon_popup_cta_url'] ?? '');

        update_option('tix_settings', $s);

        wp_redirect(add_query_arg(['page' => 'tix-coupons', 'msg' => 'popup_saved'], admin_url('admin.php')));
        exit;
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

        $code       = strtoupper(trim(sanitize_text_field($_POST['code'] ?? '')));
        $type       = $_POST['discount_type'] ?? 'percent';
        $value      = floatval(str_replace(',', '.', $_POST['value'] ?? '0'));
        $expires    = sanitize_text_field($_POST['expires'] ?? '');
        $max_uses   = intval($_POST['max_uses'] ?? 0);
        $desc       = sanitize_text_field($_POST['description'] ?? '');
        $auto_apply = !empty($_POST['auto_apply']) ? 1 : 0;
        $orig_code  = strtoupper(trim(sanitize_text_field($_POST['orig_code'] ?? '')));

        // Restrictions (WC-Style)
        $min_amount       = max(0, floatval(str_replace(',', '.', $_POST['min_amount'] ?? '0')));
        $max_amount       = max(0, floatval(str_replace(',', '.', $_POST['max_amount'] ?? '0')));
        $allowed_events   = array_filter(array_map('intval', (array) ($_POST['allowed_events'] ?? [])));
        $excluded_events  = array_filter(array_map('intval', (array) ($_POST['excluded_events'] ?? [])));
        $allowed_cats     = array_filter(array_map('intval', (array) ($_POST['allowed_categories'] ?? [])));
        $excluded_cats    = array_filter(array_map('intval', (array) ($_POST['excluded_categories'] ?? [])));
        $one_per_email    = !empty($_POST['one_per_email']) ? 1 : 0;
        $individual_use   = !empty($_POST['individual_use']) ? 1 : 0;

        if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            wp_redirect(add_query_arg(['page' => 'tix-coupons', 'msg' => 'invalid_code'], admin_url('admin.php')));
            exit;
        }
        if (!in_array($type, ['percent', 'fixed', 'per_ticket_percent', 'per_ticket_fixed'], true)) $type = 'percent';
        if ($value <= 0) {
            wp_redirect(add_query_arg(['page' => 'tix-coupons', 'msg' => 'invalid_value'], admin_url('admin.php')));
            exit;
        }
        // Prozent-Caps (Cart-weit + pro Ticket)
        if (in_array($type, ['percent', 'per_ticket_percent'], true) && $value > 100) $value = 100;

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

        // ── Exklusivität: nur EIN Coupon darf auto_apply=1 haben ──
        // Wenn dieser Coupon auto-apply ist → alle anderen auf 0 setzen
        if ($auto_apply) {
            foreach ($coupons as $k => &$c) {
                if ($k !== $code && !empty($c['auto_apply'])) {
                    $c['auto_apply'] = 0;
                }
            }
            unset($c);
        }

        $coupons[$code] = [
            'code'                 => $code,
            'discount_type'        => $type,
            'value'                => round($value, 2),
            'expires'              => $expires,
            'max_uses'             => $max_uses,
            'used'                 => $used,
            'description'          => $desc,
            'auto_apply'           => $auto_apply,
            // Restrictions
            'min_amount'           => round($min_amount, 2),
            'max_amount'           => round($max_amount, 2),
            'allowed_events'       => array_values($allowed_events),
            'excluded_events'      => array_values($excluded_events),
            'allowed_categories'   => array_values($allowed_cats),
            'excluded_categories'  => array_values($excluded_cats),
            'one_per_email'        => $one_per_email,
            'individual_use'       => $individual_use,
        ];

        update_option(self::OPTION, $coupons);

        wp_redirect(add_query_arg(['page' => 'tix-coupons', 'msg' => 'saved'], admin_url('admin.php')));
        exit;
    }

    /**
     * Liefert den aktiven Auto-Apply-Coupon (oder null wenn keiner).
     * Filtert auch abgelaufene und ausgeschöpfte Codes raus.
     */
    public static function get_auto_apply_coupon() {
        $coupons = get_option(self::OPTION, []);
        if (!is_array($coupons)) return null;

        foreach ($coupons as $code => $c) {
            if (empty($c['auto_apply'])) continue;

            // Expiry-Check
            if (!empty($c['expires'])) {
                $ts = strtotime($c['expires']);
                if ($ts && $ts < time()) continue;
            }
            // Max-Uses-Check
            $used = intval($c['used'] ?? 0);
            $max  = intval($c['max_uses'] ?? 0);
            if ($max > 0 && $used >= $max) continue;

            return $c;
        }
        return null;
    }

    /**
     * Validiert einen Coupon gegen einen Cart-Kontext.
     *
     * @param array $coupon Coupon-Definition (aus tix_coupons-Option)
     * @param array $context [
     *     'items_total' => float,                  // Cart-Summe in EUR
     *     'event_ids'   => int[],                  // Alle Event-IDs im Cart
     *     'email'       => string (optional),      // Email für one_per_email-Check
     * ]
     * @return true|string  true wenn gültig, sonst Fehlermeldung
     */
    public static function validate_against_cart(array $coupon, array $context) {
        $items_total = floatval($context['items_total'] ?? 0);
        $event_ids   = array_map('intval', (array) ($context['event_ids'] ?? []));
        $email       = strtolower(trim((string) ($context['email'] ?? '')));

        // Min-Amount
        $min = floatval($coupon['min_amount'] ?? 0);
        if ($min > 0 && $items_total < $min) {
            return sprintf('Mindestbestellwert %s €. Aktuell: %s €.',
                number_format($min, 2, ',', '.'),
                number_format($items_total, 2, ',', '.')
            );
        }

        // Max-Amount
        $max = floatval($coupon['max_amount'] ?? 0);
        if ($max > 0 && $items_total > $max) {
            return sprintf('Höchstbestellwert %s €. Aktuell: %s €.',
                number_format($max, 2, ',', '.'),
                number_format($items_total, 2, ',', '.')
            );
        }

        // Allowed-Events: wenn gesetzt → mind. ein Event aus Cart muss matchen (Allow-list)
        $allowed_events = (array) ($coupon['allowed_events'] ?? []);
        if (!empty($allowed_events)) {
            $intersect = array_intersect($event_ids, array_map('intval', $allowed_events));
            if (empty($intersect)) {
                return 'Dieser Gutschein ist nicht für deine ausgewählten Events gültig.';
            }
        }

        // Excluded-Events: wenn ein Cart-Event in Block-list → ungültig
        $excluded_events = (array) ($coupon['excluded_events'] ?? []);
        if (!empty($excluded_events)) {
            $intersect = array_intersect($event_ids, array_map('intval', $excluded_events));
            if (!empty($intersect)) {
                return 'Dieser Gutschein ist für eines deiner Events ausgeschlossen.';
            }
        }

        // Allowed-Categories
        $allowed_cats = array_map('intval', (array) ($coupon['allowed_categories'] ?? []));
        $excluded_cats = array_map('intval', (array) ($coupon['excluded_categories'] ?? []));
        if (!empty($allowed_cats) || !empty($excluded_cats)) {
            $cart_cat_ids = [];
            foreach ($event_ids as $eid) {
                $terms = get_the_terms($eid, 'event_category');
                if (is_array($terms)) {
                    foreach ($terms as $t) $cart_cat_ids[] = intval($t->term_id);
                }
            }
            $cart_cat_ids = array_unique($cart_cat_ids);
            if (!empty($allowed_cats) && empty(array_intersect($cart_cat_ids, $allowed_cats))) {
                return 'Dieser Gutschein gilt nicht für die Kategorien deiner Events.';
            }
            if (!empty($excluded_cats) && !empty(array_intersect($cart_cat_ids, $excluded_cats))) {
                return 'Dieser Gutschein ist für mindestens eine Kategorie deiner Events ausgeschlossen.';
            }
        }

        // One-per-Email: prüft ob diese Email den Code schon eingelöst hat
        if (!empty($coupon['one_per_email']) && $email && is_email($email)) {
            global $wpdb;
            $t = $wpdb->prefix . 'tix_orders';
            // Suche Orders mit dieser Email + completed/processing-Status, die diesen Coupon-Code im Notes-Feld haben
            // Da wir aktuell keine eigene Coupon-Verwendungs-Tabelle haben, nutzen wir die order_notes
            $tn = $wpdb->prefix . 'tix_order_notes';
            if ($wpdb->get_var("SHOW TABLES LIKE '$tn'") === $tn) {
                $count = intval($wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(DISTINCT n.order_id)
                    FROM $tn n
                    INNER JOIN $t o ON o.id = n.order_id
                    WHERE o.billing_email = %s
                      AND o.status IN ('completed','processing')
                      AND n.note LIKE %s
                ", $email, '%' . $wpdb->esc_like('Gutschein "' . $coupon['code'] . '"') . '%')));
                if ($count > 0) {
                    return 'Du hast diesen Gutschein bereits eingelöst.';
                }
            }
        }

        return true;
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
            case 'popup_saved':   $msg_text = 'Popup-Einstellungen gespeichert.'; break;
        }

        // Popup-Settings für Form
        $tix_s = function_exists('tix_get_settings') ? tix_get_settings() : get_option('tix_settings', []);
        if (!is_array($tix_s)) $tix_s = [];
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
                                    <?php $current_type = $edit['discount_type'] ?? 'percent'; ?>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;max-width:640px;">
                                        <label style="display:flex;align-items:flex-start;gap:8px;padding:12px 14px;border:1.5px solid <?php echo $current_type === 'percent' ? '#FF5500' : '#e5e7eb'; ?>;border-radius:8px;cursor:pointer;background:<?php echo $current_type === 'percent' ? '#fff7ed' : '#fff'; ?>;">
                                            <input type="radio" name="discount_type" value="percent" <?php checked($current_type, 'percent'); ?> style="margin-top:2px;">
                                            <div>
                                                <div style="font-weight:600;font-size:13px;color:#0f172a;">📊 Prozent auf Gesamtbetrag</div>
                                                <div style="font-size:11px;color:#64748b;margin-top:2px;">z.B. 10% auf den ganzen Warenkorb</div>
                                            </div>
                                        </label>
                                        <label style="display:flex;align-items:flex-start;gap:8px;padding:12px 14px;border:1.5px solid <?php echo $current_type === 'fixed' ? '#FF5500' : '#e5e7eb'; ?>;border-radius:8px;cursor:pointer;background:<?php echo $current_type === 'fixed' ? '#fff7ed' : '#fff'; ?>;">
                                            <input type="radio" name="discount_type" value="fixed" <?php checked($current_type, 'fixed'); ?> style="margin-top:2px;">
                                            <div>
                                                <div style="font-weight:600;font-size:13px;color:#0f172a;">💶 Fixbetrag auf Gesamtbetrag</div>
                                                <div style="font-size:11px;color:#64748b;margin-top:2px;">z.B. 5 € pauschal vom Warenkorb</div>
                                            </div>
                                        </label>
                                        <label style="display:flex;align-items:flex-start;gap:8px;padding:12px 14px;border:1.5px solid <?php echo $current_type === 'per_ticket_percent' ? '#FF5500' : '#e5e7eb'; ?>;border-radius:8px;cursor:pointer;background:<?php echo $current_type === 'per_ticket_percent' ? '#fff7ed' : '#fff'; ?>;">
                                            <input type="radio" name="discount_type" value="per_ticket_percent" <?php checked($current_type, 'per_ticket_percent'); ?> style="margin-top:2px;">
                                            <div>
                                                <div style="font-weight:600;font-size:13px;color:#0f172a;">🎫 Prozent pro Ticket</div>
                                                <div style="font-size:11px;color:#64748b;margin-top:2px;">z.B. 15% auf jedes einzelne Ticket</div>
                                            </div>
                                        </label>
                                        <label style="display:flex;align-items:flex-start;gap:8px;padding:12px 14px;border:1.5px solid <?php echo $current_type === 'per_ticket_fixed' ? '#FF5500' : '#e5e7eb'; ?>;border-radius:8px;cursor:pointer;background:<?php echo $current_type === 'per_ticket_fixed' ? '#fff7ed' : '#fff'; ?>;">
                                            <input type="radio" name="discount_type" value="per_ticket_fixed" <?php checked($current_type, 'per_ticket_fixed'); ?> style="margin-top:2px;">
                                            <div>
                                                <div style="font-weight:600;font-size:13px;color:#0f172a;">🏷️ Fixbetrag pro Ticket</div>
                                                <div style="font-size:11px;color:#64748b;margin-top:2px;">z.B. 5 € Rabatt pro Ticket → bei 3 Tickets = 15 €</div>
                                            </div>
                                        </label>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ttc-value">Wert</label></th>
                                <td>
                                    <input type="text" id="ttc-value" name="value" required
                                           value="<?php echo esc_attr($edit['value'] ?? ''); ?>"
                                           placeholder="z.B. 10 oder 5,00"
                                           style="width:120px;font-family:monospace;">
                                    <p class="description">Bei Prozent: 1–100. Bei Fix-Betrag: Wert in Euro.</p>
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
                            <tr>
                                <th colspan="2" style="padding-top:24px;">
                                    <h3 style="margin:0 0 4px;font-size:14px;text-transform:uppercase;letter-spacing:0.05em;color:#475569;border-top:1px solid #e5e7eb;padding-top:14px;">🔒 Beschränkungen (optional)</h3>
                                </th>
                            </tr>
                            <tr>
                                <th><label for="ttc-min">Mindestbestellwert (€)</label></th>
                                <td>
                                    <input type="text" id="ttc-min" name="min_amount"
                                           value="<?php echo esc_attr($edit['min_amount'] ?? ''); ?>"
                                           placeholder="0,00" style="width:120px;font-family:monospace;">
                                    <p class="description">Coupon greift nur ab diesem Cart-Wert. 0 oder leer = kein Minimum.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ttc-max">Höchstbestellwert (€)</label></th>
                                <td>
                                    <input type="text" id="ttc-max-amount" name="max_amount"
                                           value="<?php echo esc_attr($edit['max_amount'] ?? ''); ?>"
                                           placeholder="0,00" style="width:120px;font-family:monospace;">
                                    <p class="description">Coupon greift nur bis zu diesem Cart-Wert. 0 oder leer = kein Maximum.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label>Beinhaltete Events</label></th>
                                <td>
                                    <?php
                                    $all_events = get_posts(['post_type' => 'event', 'posts_per_page' => 200, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC']);
                                    $sel_allowed = (array) ($edit['allowed_events'] ?? []);
                                    ?>
                                    <select name="allowed_events[]" multiple style="width:100%;max-width:520px;height:160px;font-size:12px;">
                                        <?php foreach ($all_events as $ev): ?>
                                            <option value="<?php echo $ev->ID; ?>" <?php echo in_array($ev->ID, $sel_allowed) ? 'selected' : ''; ?>><?php echo esc_html($ev->post_title); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Coupon gilt <strong>nur</strong> für ausgewählte Events. Leer = alle Events erlaubt. (Strg/Cmd zum Multi-Select)</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label>Ausgeschlossene Events</label></th>
                                <td>
                                    <?php $sel_excluded = (array) ($edit['excluded_events'] ?? []); ?>
                                    <select name="excluded_events[]" multiple style="width:100%;max-width:520px;height:120px;font-size:12px;">
                                        <?php foreach ($all_events as $ev): ?>
                                            <option value="<?php echo $ev->ID; ?>" <?php echo in_array($ev->ID, $sel_excluded) ? 'selected' : ''; ?>><?php echo esc_html($ev->post_title); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">Coupon gilt <strong>nicht</strong> für ausgewählte Events. Hat Vorrang vor "Beinhaltet".</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label>Beinhaltete Kategorien</label></th>
                                <td>
                                    <?php
                                    $all_cats = get_terms(['taxonomy' => 'event_category', 'hide_empty' => false]);
                                    $sel_allowed_cats = (array) ($edit['allowed_categories'] ?? []);
                                    $sel_excluded_cats = (array) ($edit['excluded_categories'] ?? []);
                                    ?>
                                    <?php if (!is_wp_error($all_cats) && !empty($all_cats)): ?>
                                        <select name="allowed_categories[]" multiple style="width:100%;max-width:340px;height:100px;font-size:12px;">
                                            <?php foreach ($all_cats as $cat): ?>
                                                <option value="<?php echo $cat->term_id; ?>" <?php echo in_array($cat->term_id, $sel_allowed_cats) ? 'selected' : ''; ?>><?php echo esc_html($cat->name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">Coupon gilt nur für Events in diesen Kategorien. Leer = alle.</p>
                                    <?php else: ?>
                                        <em style="color:#9ca3af;">Keine Event-Kategorien angelegt.</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label>Ausgeschlossene Kategorien</label></th>
                                <td>
                                    <?php if (!is_wp_error($all_cats) && !empty($all_cats)): ?>
                                        <select name="excluded_categories[]" multiple style="width:100%;max-width:340px;height:80px;font-size:12px;">
                                            <?php foreach ($all_cats as $cat): ?>
                                                <option value="<?php echo $cat->term_id; ?>" <?php echo in_array($cat->term_id, $sel_excluded_cats) ? 'selected' : ''; ?>><?php echo esc_html($cat->name); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <em style="color:#9ca3af;">—</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Sonstiges</th>
                                <td>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" name="one_per_email" value="1" <?php checked(!empty($edit['one_per_email'])); ?>>
                                        <strong>Nur einmal pro Kunde (Email)</strong>
                                    </label>
                                    <p class="description" style="margin:0 0 12px 22px;">Wenn ein Kunde mit dieser Email schon eine Bestellung mit diesem Code hat, ist er gesperrt.</p>

                                    <label style="display:block;">
                                        <input type="checkbox" name="individual_use" value="1" <?php checked(!empty($edit['individual_use'])); ?>>
                                        <strong>Individuelle Verwendung</strong>
                                    </label>
                                    <p class="description" style="margin:0 0 0 22px;">Kann nicht mit anderen Coupons kombiniert werden.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Automatisch anwenden</th>
                                <td>
                                    <?php
                                    $current_auto = !empty($edit['auto_apply']);
                                    // Anderen aktiven Auto-Apply-Coupon (außer diesem) finden — für Hinweis
                                    $other_auto = null;
                                    foreach ($coupons as $oc) {
                                        if (!empty($oc['auto_apply']) && $oc['code'] !== ($edit['code'] ?? '')) {
                                            $other_auto = $oc;
                                            break;
                                        }
                                    }
                                    ?>
                                    <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                                        <input type="checkbox" name="auto_apply" value="1" <?php checked($current_auto); ?>>
                                        <span><strong>🪄 Automatisch im Warenkorb anwenden</strong></span>
                                    </label>
                                    <p class="description" style="margin-top:8px;">
                                        Wird beim ersten Hinzufügen eines Tickets automatisch auf den Warenkorb angewendet — der Kunde muss den Code nicht selbst eingeben.<br>
                                        <strong>⚠️ Nur ein Coupon</strong> kann gleichzeitig automatisch angewendet werden. Beim Aktivieren wird der bisherige Auto-Apply-Coupon deaktiviert.
                                    </p>
                                    <?php if ($other_auto && !$current_auto): ?>
                                        <p style="margin-top:8px;font-size:12px;background:#fef3c7;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:4px;color:#78350f;">
                                            Aktuell ist <strong style="font-family:monospace;"><?php echo esc_html($other_auto['code']); ?></strong> als Auto-Apply-Coupon aktiv. Wenn du diesen aktivierst, wird der andere automatisch deaktiviert.
                                        </p>
                                    <?php endif; ?>
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
                                        <?php if (!empty($c['auto_apply'])): ?>
                                            <span style="background:#dcfce7;color:#166534;padding:1px 7px;border-radius:6px;font-size:10px;font-weight:700;margin-left:4px;" title="Wird automatisch im Warenkorb angewendet">🪄 AUTO</span>
                                        <?php endif; ?>
                                        <?php if (!empty($c['description'])): ?>
                                            <div style="font-size:11px;color:#9ca3af;"><?php echo esc_html($c['description']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        switch ($type) {
                                            case 'percent':
                                                echo '<strong>' . number_format($val, 0, ',', '.') . ' %</strong>';
                                                echo '<div style="font-size:10px;color:#9ca3af;">auf Gesamt</div>';
                                                break;
                                            case 'fixed':
                                                echo '<strong>' . number_format($val, 2, ',', '.') . ' €</strong>';
                                                echo '<div style="font-size:10px;color:#9ca3af;">auf Gesamt</div>';
                                                break;
                                            case 'per_ticket_percent':
                                                echo '<strong>' . number_format($val, 0, ',', '.') . ' %</strong>';
                                                echo '<div style="font-size:10px;color:#9ca3af;">pro Ticket</div>';
                                                break;
                                            case 'per_ticket_fixed':
                                                echo '<strong>' . number_format($val, 2, ',', '.') . ' €</strong>';
                                                echo '<div style="font-size:10px;color:#9ca3af;">pro Ticket</div>';
                                                break;
                                            default:
                                                echo '<strong>' . esc_html($val) . '</strong>';
                                        }
                                        ?>
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

            <?php // ── Popup-Einstellungen ── ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-top:24px;">
                <h2 style="margin-top:0;font-size:18px;display:flex;align-items:center;gap:10px;">
                    🎁 Auto-Coupon-Popup
                    <span style="font-size:11px;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:6px;font-weight:600;">FRONTEND</span>
                </h2>
                <p style="color:#6b7280;font-size:13px;margin:0 0 18px;">
                    Wenn ein Coupon mit „🪄 Automatisch anwenden" aktiv ist, erscheint beim ersten Seitenaufruf ein auffälliges Popup für den Besucher. Cookie-gesteuert: zeigt sich pro Code nur einmal alle 24h.
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('tix_coupon_popup_save'); ?>
                    <input type="hidden" name="action" value="tix_coupon_popup_save">

                    <table class="form-table">
                        <tr>
                            <th>Aktiviert</th>
                            <td>
                                <label style="display:inline-flex;align-items:center;gap:8px;">
                                    <input type="checkbox" name="coupon_popup_enabled" value="1" <?php checked(!empty($tix_s['coupon_popup_enabled'])); ?>>
                                    <span>Popup im Frontend anzeigen wenn Auto-Coupon aktiv</span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cp-headline">Überschrift</label></th>
                            <td>
                                <input type="text" id="cp-headline" name="coupon_popup_headline" class="regular-text" style="width:100%;max-width:520px;"
                                       value="<?php echo esc_attr($tix_s['coupon_popup_headline'] ?? '🎁 Dein Rabatt ist aktiv!'); ?>"
                                       placeholder="🎁 Dein Rabatt ist aktiv!">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cp-subtext">Unterzeile</label></th>
                            <td>
                                <textarea id="cp-subtext" name="coupon_popup_subtext" rows="2" class="large-text" style="width:100%;max-width:520px;"
                                          placeholder="Wir haben dir bereits einen Gutschein im Warenkorb hinterlegt — du sparst beim Checkout automatisch."><?php echo esc_textarea($tix_s['coupon_popup_subtext'] ?? 'Wir haben dir bereits einen Gutschein im Warenkorb hinterlegt — du sparst beim Checkout automatisch.'); ?></textarea>
                                <p class="description">Der Coupon-Wert (z.B. „−10%" oder „−15€") wird automatisch oben groß angezeigt — schreib hier nur Text drumrum.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cp-cta">CTA-Button-Text</label></th>
                            <td>
                                <input type="text" id="cp-cta" name="coupon_popup_cta" class="regular-text" style="width:100%;max-width:340px;"
                                       value="<?php echo esc_attr($tix_s['coupon_popup_cta'] ?? 'Jetzt Tickets sichern'); ?>"
                                       placeholder="Jetzt Tickets sichern">
                                <p class="description">Tipp: Bei leerer URL einen passenden Text wie "Verstanden" oder "Schließen" verwenden.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="cp-cta-url">CTA-Ziel-URL</label></th>
                            <td>
                                <input type="url" id="cp-cta-url" name="coupon_popup_cta_url" class="regular-text" style="width:100%;max-width:520px;"
                                       value="<?php echo esc_attr($tix_s['coupon_popup_cta_url'] ?? ''); ?>"
                                       placeholder="https://deinedomain.de/events/  —  oder leer lassen">
                                <p class="description"><strong>Optional</strong> — wenn leer, schließt der Button nur das Popup (Kunde bleibt auf der aktuellen Seite). Mit URL führt der Klick z.B. zur Event-Seite.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Farben</label></th>
                            <td>
                                <p style="margin:0;font-size:13px;">
                                    Alle Farben des Popups konfigurierst du unter
                                    <a href="<?php echo admin_url('admin.php?page=tix-settings#colors'); ?>" target="_blank" style="font-weight:600;">
                                        Einstellungen → Farben → Gruppe „Coupon-Popup"
                                    </a>
                                    (13 Klassen für Banner, Code-Box, Buttons etc.).
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">Popup-Einstellungen speichern</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}
