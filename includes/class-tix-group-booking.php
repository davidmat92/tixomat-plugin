<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat – Gemeinsam buchen (Group Booking)
 *
 * Shared Cart mit Token-basiertem Zugang.
 * Organisator erstellt Gruppe → Link teilen → Freunde wählen Tickets → eine Bestellung.
 */
class TIX_Group_Booking {

    /** Transient-TTL: 48 Stunden */
    const TTL = 172800;

    public static function init() {
        // AJAX Endpoints (alle nopriv, da Gäste auch Gruppen nutzen)
        $actions = ['create', 'add', 'status', 'remove', 'checkout'];
        foreach ($actions as $a) {
            add_action('wp_ajax_tix_group_' . $a,        [__CLASS__, 'ajax_' . $a]);
            add_action('wp_ajax_nopriv_tix_group_' . $a, [__CLASS__, 'ajax_' . $a]);
        }

        // Order-Meta speichern bei Checkout
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_line_item_meta'], 10, 4);
        add_action('woocommerce_checkout_create_order',           [__CLASS__, 'save_order_meta'], 10, 2);
    }

    // ══════════════════════════════════════
    // Session Helpers
    // ══════════════════════════════════════

    private static function get_session($token) {
        return get_transient('tix_group_' . $token);
    }

    private static function save_session($token, $data) {
        set_transient('tix_group_' . $token, $data, self::TTL);
    }

    private static function generate_token($length = 32) {
        return wp_generate_password($length, false, false);
    }

    // ══════════════════════════════════════
    // AJAX: Gruppe erstellen
    // ══════════════════════════════════════

    public static function ajax_create() {
        check_ajax_referer('tix_group_booking', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        $name     = sanitize_text_field($_POST['name'] ?? '');

        if (!$event_id || !$name) {
            wp_send_json_error(['message' => 'Bitte Event und Name angeben.']);
        }

        // Event prüfen
        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'event') {
            wp_send_json_error(['message' => 'Event nicht gefunden.']);
        }
        if (get_post_meta($event_id, '_tix_tickets_enabled', true) !== '1') {
            wp_send_json_error(['message' => 'Ticketverkauf nicht aktiv.']);
        }
        if (get_post_meta($event_id, '_tix_presale_active', true) !== '1') {
            wp_send_json_error(['message' => 'Vorverkauf beendet.']);
        }

        $token       = self::generate_token(32);
        $admin_token = self::generate_token(32);
        $member_id   = self::generate_token(8);

        $session = [
            'event_id'    => $event_id,
            'admin_token' => $admin_token,
            'status'      => 'open',
            'members'     => [
                $member_id => [
                    'name'     => $name,
                    'items'    => [],
                    'is_admin' => true,
                ],
            ],
            'created_at'  => time(),
        ];

        self::save_session($token, $session);

        $share_url = add_query_arg('tix_group', $token, get_permalink($event_id));

        wp_send_json_success([
            'token'       => $token,
            'admin_token' => $admin_token,
            'member_id'   => $member_id,
            'share_url'   => $share_url,
            'group_html'  => self::render_group_overview($session, $token, true, $member_id),
        ]);
    }

    // ══════════════════════════════════════
    // AJAX: Tickets zur Gruppe hinzufügen
    // ══════════════════════════════════════

    public static function ajax_add() {
        check_ajax_referer('tix_group_booking', 'nonce');

        $token     = sanitize_text_field($_POST['token'] ?? '');
        $name      = sanitize_text_field($_POST['name'] ?? '');
        $member_id = sanitize_text_field($_POST['member_id'] ?? '');
        $items     = json_decode(stripslashes($_POST['items'] ?? '[]'), true);

        if (!$token || (!$name && !$member_id)) {
            wp_send_json_error(['message' => 'Token und Name erforderlich.']);
        }

        $session = self::get_session($token);
        if (!$session || $session['status'] !== 'open') {
            wp_send_json_error(['message' => 'Gruppe nicht gefunden oder bereits abgeschlossen.']);
        }

        if (empty($items)) {
            wp_send_json_error(['message' => 'Keine Tickets ausgewählt.']);
        }

        // Sanitize items
        $clean_items = self::sanitize_items($items);
        if (empty($clean_items)) {
            wp_send_json_error(['message' => 'Ungültige Ticket-Daten.']);
        }

        // Bestehender Member → aktualisieren, sonst neuen anlegen
        if ($member_id && isset($session['members'][$member_id])) {
            $session['members'][$member_id]['name']  = $name;
            $session['members'][$member_id]['items'] = $clean_items;
        } else {
            $member_id = self::generate_token(8);
            $session['members'][$member_id] = [
                'name'  => $name,
                'items' => $clean_items,
            ];
        }

        self::save_session($token, $session);

        // Ist dieser Member der Admin?
        $admin_token = sanitize_text_field($_POST['admin_token'] ?? '');
        $is_admin    = $admin_token && $admin_token === $session['admin_token'];

        wp_send_json_success([
            'member_id'  => $member_id,
            'group_html' => self::render_group_overview($session, $token, $is_admin, $member_id),
            'message'    => 'Tickets zur Gruppe hinzugefügt!',
        ]);
    }

    // ══════════════════════════════════════
    // AJAX: Gruppen-Status abrufen (Polling)
    // ══════════════════════════════════════

    public static function ajax_status() {
        check_ajax_referer('tix_group_booking', 'nonce');

        $token       = sanitize_text_field($_POST['token'] ?? '');
        $admin_token = sanitize_text_field($_POST['admin_token'] ?? '');
        $member_id   = sanitize_text_field($_POST['member_id'] ?? '');

        $session = self::get_session($token);
        if (!$session) {
            wp_send_json_error(['message' => 'Gruppe nicht gefunden.']);
        }

        $is_admin = $admin_token && $admin_token === $session['admin_token'];

        wp_send_json_success([
            'status'      => $session['status'],
            'member_count' => count($session['members']),
            'group_html'  => self::render_group_overview($session, $token, $is_admin, $member_id),
        ]);
    }

    // ══════════════════════════════════════
    // AJAX: Mitglied entfernen (nur Admin)
    // ══════════════════════════════════════

    public static function ajax_remove() {
        check_ajax_referer('tix_group_booking', 'nonce');

        $token          = sanitize_text_field($_POST['token'] ?? '');
        $admin_token    = sanitize_text_field($_POST['admin_token'] ?? '');
        $remove_member  = sanitize_text_field($_POST['remove_member_id'] ?? '');
        $own_member_id  = sanitize_text_field($_POST['member_id'] ?? '');

        $session = self::get_session($token);
        if (!$session) {
            wp_send_json_error(['message' => 'Gruppe nicht gefunden.']);
        }

        if (!$admin_token || $admin_token !== $session['admin_token']) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        if (isset($session['members'][$remove_member])) {
            // Admin darf sich nicht selbst entfernen
            if (!empty($session['members'][$remove_member]['is_admin'])) {
                wp_send_json_error(['message' => 'Der Organisator kann sich nicht selbst entfernen.']);
            }
            unset($session['members'][$remove_member]);
            self::save_session($token, $session);
        }

        wp_send_json_success([
            'group_html' => self::render_group_overview($session, $token, true, $own_member_id),
        ]);
    }

    // ══════════════════════════════════════
    // AJAX: Für alle bestellen (nur Admin)
    // ══════════════════════════════════════

    public static function ajax_checkout() {
        check_ajax_referer('tix_group_booking', 'nonce');

        $token       = sanitize_text_field($_POST['token'] ?? '');
        $admin_token = sanitize_text_field($_POST['admin_token'] ?? '');

        $session = self::get_session($token);
        if (!$session || $session['status'] !== 'open') {
            wp_send_json_error(['message' => 'Gruppe nicht gefunden oder bereits abgeschlossen.']);
        }

        if (!$admin_token || $admin_token !== $session['admin_token']) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        // Prüfen ob überhaupt Items vorhanden
        $has_items = false;
        foreach ($session['members'] as $m) {
            if (!empty($m['items'])) { $has_items = true; break; }
        }
        if (!$has_items) {
            wp_send_json_error(['message' => 'Noch keine Tickets in der Gruppe.']);
        }

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(['message' => 'WooCommerce nicht verfügbar.']);
        }

        // Cart leeren und mit Gruppen-Items befüllen
        WC()->cart->empty_cart();

        $group_data_for_order = [];

        foreach ($session['members'] as $mid => $member) {
            if (empty($member['items'])) continue;

            $member_order_data = [
                'name'     => $member['name'],
                'items'    => [],
                'subtotal' => 0,
            ];

            foreach ($member['items'] as $item) {
                // ── Kombi-Ticket ──
                if (!empty($item['combo'])) {
                    $combo_id    = sanitize_text_field($item['combo_id'] ?? '');
                    $combo_label = sanitize_text_field($item['combo_label'] ?? '');
                    $combo_price = floatval($item['combo_price'] ?? 0);
                    $combo_qty   = intval($item['quantity'] ?? 0);
                    $products    = $item['products'] ?? [];

                    if (empty($combo_id) || $combo_qty <= 0 || empty($products)) continue;

                    $group_id  = 'combo_' . time() . '_' . wp_generate_password(6, false, false);
                    $item_count = count($products);

                    foreach ($products as $p) {
                        $p_id = intval($p['product_id'] ?? 0);
                        if ($p_id <= 0) continue;

                        $cart_item_data = [
                            '_tix_combo' => [
                                'group_id'    => $group_id,
                                'combo_id'    => $combo_id,
                                'label'       => $combo_label,
                                'total_price' => $combo_price,
                                'item_count'  => $item_count,
                            ],
                            '_tix_group_member' => $member['name'],
                        ];
                        WC()->cart->add_to_cart($p_id, $combo_qty, 0, [], $cart_item_data);
                    }

                    $member_order_data['items'][] = $combo_label . ' × ' . $combo_qty;
                    $member_order_data['subtotal'] += $combo_price * $combo_qty;
                    continue;
                }

                // ── Einzel- / Bundle-Ticket ──
                $product_id = intval($item['product_id'] ?? 0);
                $quantity   = intval($item['quantity'] ?? 0);
                if ($product_id <= 0 || $quantity <= 0) continue;

                $cart_item_data = [
                    '_tix_group_member' => $member['name'],
                ];

                if (!empty($item['bundle'])) {
                    $cart_item_data['_tix_bundle'] = [
                        'buy'   => intval($item['bundle_buy'] ?? 0),
                        'pay'   => intval($item['bundle_pay'] ?? 0),
                        'label' => sanitize_text_field($item['bundle_label'] ?? ''),
                    ];
                }

                WC()->cart->add_to_cart($product_id, $quantity, 0, [], $cart_item_data);

                // Preis für Kostensplit berechnen
                $product = wc_get_product($product_id);
                $price   = $product ? floatval($product->get_price()) : 0;
                $pname   = $product ? $product->get_name() : 'Ticket';
                $member_order_data['items'][] = $pname . ' × ' . $quantity;
                $member_order_data['subtotal'] += $price * $quantity;
            }

            $group_data_for_order[] = $member_order_data;
        }

        // Gruppen-Daten für Order-Meta speichern (via Transient, da wir im AJAX sind)
        set_transient('tix_group_order_data_' . get_current_user_id() . '_' . wp_get_session_token(), [
            'members' => $group_data_for_order,
            'token'   => $token,
        ], 3600);

        // Session als completed markieren
        $session['status'] = 'completed';
        self::save_session($token, $session);

        // Session speichern
        if (WC()->session) {
            WC()->session->save_data();
        }

        $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');

        wp_send_json_success([
            'checkout_url' => $checkout_url,
            'message'      => 'Alle Tickets wurden in den Warenkorb gelegt!',
        ]);
    }

    // ══════════════════════════════════════
    // Order-Meta speichern
    // ══════════════════════════════════════

    /**
     * Speichert _tix_group_member als Order-Line-Item-Meta
     */
    public static function save_line_item_meta($item, $cart_item_key, $values, $order) {
        if (!empty($values['_tix_group_member'])) {
            $item->add_meta_data('_tix_group_member', $values['_tix_group_member'], true);
        }
    }

    /**
     * Speichert Gruppen-Daten als Order-Meta
     */
    public static function save_order_meta($order, $data) {
        // Gruppen-Daten aus Transient laden
        $key = 'tix_group_order_data_' . get_current_user_id() . '_' . wp_get_session_token();
        $group_data = get_transient($key);

        if ($group_data) {
            $order->update_meta_data('_tix_group_data', $group_data);
            delete_transient($key);
        }
    }

    // ══════════════════════════════════════
    // Items Sanitize
    // ══════════════════════════════════════

    private static function sanitize_items($items) {
        $clean = [];
        foreach ($items as $item) {
            if (!empty($item['combo'])) {
                $products = [];
                foreach (($item['products'] ?? []) as $p) {
                    $pid = intval($p['product_id'] ?? 0);
                    if ($pid > 0) {
                        $products[] = ['product_id' => $pid, 'price' => floatval($p['price'] ?? 0)];
                    }
                }
                if (!empty($products)) {
                    $clean[] = [
                        'combo'       => 1,
                        'combo_id'    => sanitize_text_field($item['combo_id'] ?? ''),
                        'combo_label' => sanitize_text_field($item['combo_label'] ?? ''),
                        'combo_price' => floatval($item['combo_price'] ?? 0),
                        'quantity'    => max(1, intval($item['quantity'] ?? 1)),
                        'products'    => $products,
                    ];
                }
            } else {
                $pid = intval($item['product_id'] ?? 0);
                $qty = intval($item['quantity'] ?? 0);
                if ($pid > 0 && $qty > 0) {
                    $entry = [
                        'product_id' => $pid,
                        'quantity'   => $qty,
                    ];
                    if (!empty($item['bundle'])) {
                        $entry['bundle']       = 1;
                        $entry['bundle_buy']   = intval($item['bundle_buy'] ?? 0);
                        $entry['bundle_pay']   = intval($item['bundle_pay'] ?? 0);
                        $entry['bundle_label'] = sanitize_text_field($item['bundle_label'] ?? '');
                    }
                    $clean[] = $entry;
                }
            }
        }
        return $clean;
    }

    // ══════════════════════════════════════
    // Gruppenübersicht rendern
    // ══════════════════════════════════════

    /**
     * Gibt HTML für die Gruppenübersicht zurück
     */
    public static function render_group_overview($session, $token, $is_admin = false, $own_member_id = '') {
        $event_id   = $session['event_id'];
        $members    = $session['members'] ?? [];
        $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
        $cat_map    = [];
        if (is_array($categories)) {
            foreach ($categories as $cat) {
                $pid = intval($cat['product_id'] ?? 0);
                if ($pid > 0) $cat_map[$pid] = $cat['name'] ?? 'Ticket';
            }
        }

        $total       = 0;
        $member_count = 0;
        $has_items   = false;

        ob_start();
        ?>
        <div class="tix-group-members">
        <?php foreach ($members as $mid => $member):
            $is_self  = ($mid === $own_member_id);
            $is_org   = !empty($member['is_admin']);
            $subtotal = self::calculate_member_subtotal($member['items'], $cat_map);
            $total   += $subtotal;
            $member_count++;
            if (!empty($member['items'])) $has_items = true;
        ?>
            <div class="tix-group-member<?php echo $is_self ? ' tix-group-member-self' : ''; ?>" data-member-id="<?php echo esc_attr($mid); ?>">
                <div class="tix-group-member-header">
                    <span class="tix-group-member-name">
                        <?php echo esc_html($member['name']); ?>
                        <?php if ($is_self): ?><span class="tix-group-member-you">(Du)</span><?php endif; ?>
                        <?php if ($is_org): ?><span class="tix-group-member-org">Organisator</span><?php endif; ?>
                    </span>
                    <span class="tix-group-member-subtotal"><?php echo self::format_price($subtotal); ?></span>
                    <?php if ($is_admin && !$is_org): ?>
                        <button type="button" class="tix-group-member-remove" data-member-id="<?php echo esc_attr($mid); ?>" title="Entfernen">✕</button>
                    <?php endif; ?>
                </div>
                <?php if (!empty($member['items'])): ?>
                <div class="tix-group-member-items">
                    <?php echo esc_html(self::format_member_items($member['items'], $cat_map)); ?>
                </div>
                <?php else: ?>
                <div class="tix-group-member-items tix-group-member-pending">Noch keine Tickets gewählt</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>

        <div class="tix-group-summary">
            <span class="tix-group-summary-count"><?php echo $member_count; ?> <?php echo $member_count === 1 ? 'Person' : 'Personen'; ?></span>
            <span class="tix-group-summary-total"><?php echo self::format_price($total); ?></span>
        </div>

        <?php if ($is_admin && $has_items): ?>
        <button type="button" class="tix-group-checkout-btn">Jetzt für alle bestellen</button>
        <?php elseif ($is_admin && !$has_items): ?>
        <div class="tix-group-wait">Warte auf Mitglieder…</div>
        <?php elseif (!$is_admin):
            // Nur "Warte auf Organisator" wenn das Mitglied bereits Tickets hinzugefügt hat
            $own_has_items = $own_member_id && !empty($members[$own_member_id]['items']);
            if ($own_has_items): ?>
        <div class="tix-group-wait">✓ Deine Tickets wurden hinzugefügt. Warte auf den Organisator…</div>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        return ob_get_clean();
    }

    /**
     * Berechnet Subtotal eines Members aus seinen Items
     */
    private static function calculate_member_subtotal($items, $cat_map = []) {
        $subtotal = 0;
        foreach ($items as $item) {
            if (!empty($item['combo'])) {
                $subtotal += floatval($item['combo_price'] ?? 0) * intval($item['quantity'] ?? 1);
            } else {
                $pid     = intval($item['product_id'] ?? 0);
                $qty     = intval($item['quantity'] ?? 0);
                $product = wc_get_product($pid);
                if ($product) {
                    $price = floatval($product->get_price());
                    if (!empty($item['bundle'])) {
                        $buy = intval($item['bundle_buy'] ?? 0);
                        $pay = intval($item['bundle_pay'] ?? 0);
                        // qty = Gesamttickets, Pakete = qty / buy, Kosten = Pakete * pay * Einzelpreis
                        if ($buy > 0) {
                            $packages = floor($qty / $buy);
                            $rest     = $qty % $buy;
                            $subtotal += ($packages * $pay * $price) + ($rest * $price);
                        } else {
                            $subtotal += $qty * $price;
                        }
                    } else {
                        $subtotal += $qty * $price;
                    }
                }
            }
        }
        return $subtotal;
    }

    /**
     * Formatiert Items eines Members als Text-Zeile
     */
    private static function format_member_items($items, $cat_map) {
        $parts = [];
        foreach ($items as $item) {
            if (!empty($item['combo'])) {
                $label = $item['combo_label'] ?? 'Kombi';
                $qty   = intval($item['quantity'] ?? 1);
                $parts[] = $qty . '× ' . $label;
            } else {
                $pid  = intval($item['product_id'] ?? 0);
                $qty  = intval($item['quantity'] ?? 0);
                $name = $cat_map[$pid] ?? 'Ticket';
                if (!empty($item['bundle'])) {
                    $bl = $item['bundle_label'] ?? '';
                    $name = $bl ?: $name . ' (Paket)';
                }
                $parts[] = $qty . '× ' . $name;
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * Preis formatieren (deutsch)
     */
    private static function format_price($amount) {
        return number_format($amount, 2, ',', '.') . ' €';
    }

    // ══════════════════════════════════════
    // Kostensplit rendern (für Thank-You + Email)
    // ══════════════════════════════════════

    /**
     * Kostensplit-HTML aus Order-Meta
     */
    public static function render_cost_split_html($order) {
        $group_data = $order->get_meta('_tix_group_data');
        if (empty($group_data) || empty($group_data['members'])) return '';

        $html = '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 20px;">'
              . '<tr><td style="padding-bottom: 10px;"><strong style="font-size: 15px;">Kostensplit</strong></td></tr>'
              . '<tr><td><table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">';

        foreach ($group_data['members'] as $member) {
            $name     = esc_html($member['name'] ?? '');
            $items    = esc_html(implode(', ', $member['items'] ?? []));
            $subtotal = self::format_price(floatval($member['subtotal'] ?? 0));

            $html .= '<tr><td style="padding: 10px 14px; border-bottom: 1px solid #e5e7eb;">'
                    . '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                    . '<td style="vertical-align: top;"><strong style="font-size: 14px;">' . $name . '</strong>'
                    . '<br><span style="font-size: 12px; color: #6b7280;">' . $items . '</span></td>'
                    . '<td style="vertical-align: top; text-align: right; white-space: nowrap; font-weight: 700; font-size: 14px;">' . esc_html($subtotal) . '</td>'
                    . '</tr></table></td></tr>';
        }

        $html .= '</table></td></tr></table>';
        return $html;
    }

    /**
     * Kostensplit für Thank-You-Page (Frontend-HTML)
     */
    public static function render_cost_split_frontend($order) {
        $group_data = $order->get_meta('_tix_group_data');
        if (empty($group_data) || empty($group_data['members'])) return '';

        ob_start();
        ?>
        <div class="tix-co-cost-split">
            <h3 class="tix-co-cost-split-title">Kostensplit</h3>
            <div class="tix-co-cost-split-list">
            <?php foreach ($group_data['members'] as $member):
                $subtotal = floatval($member['subtotal'] ?? 0);
            ?>
                <div class="tix-co-cost-split-member">
                    <div class="tix-co-cost-split-info">
                        <span class="tix-co-cost-split-name"><?php echo esc_html($member['name']); ?></span>
                        <span class="tix-co-cost-split-items"><?php echo esc_html(implode(', ', $member['items'] ?? [])); ?></span>
                    </div>
                    <span class="tix-co-cost-split-amount"><?php echo esc_html(self::format_price($subtotal)); ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
