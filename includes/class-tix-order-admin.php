<?php
/**
 * TIX Order Admin – Bestellverwaltung für native Orders (ohne WooCommerce)
 *
 * Admin-Seite unter Tixomat → Bestellungen mit Listenansicht + Detailansicht.
 */
if (!defined('ABSPATH')) exit;

class TIX_Order_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 25);
        add_action('wp_ajax_tix_order_update_status', [__CLASS__, 'ajax_update_status']);
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Bestellungen',
            'Bestellungen',
            'edit_posts',
            'tix-orders',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        // Einzelbestellung anzeigen?
        if (isset($_GET['order_id'])) {
            self::render_detail(intval($_GET['order_id']));
            return;
        }

        self::render_list();
    }

    // ──────────────────────────────────────────
    // Listenansicht
    // ──────────────────────────────────────────
    private static function render_list() {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';

        $status_filter = sanitize_text_field($_GET['status'] ?? '');
        $search = sanitize_text_field($_GET['s'] ?? '');
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;

        $where = '1=1';
        $params = [];

        if ($status_filter) {
            $where .= ' AND status = %s';
            $params[] = $status_filter;
        }
        if ($search) {
            $where .= ' AND (order_number LIKE %s OR billing_email LIKE %s OR billing_last_name LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $total = $wpdb->get_var($params
            ? $wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $where", ...$params)
            : "SELECT COUNT(*) FROM $t WHERE $where"
        );
        $orders = $params
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY date_created DESC LIMIT %d OFFSET %d", ...array_merge($params, [$per_page, $offset])))
            : $wpdb->get_results("SELECT * FROM $t WHERE $where ORDER BY date_created DESC LIMIT $per_page OFFSET $offset");

        $total_pages = ceil($total / $per_page);

        $statuses = [
            ''           => 'Alle',
            'completed'  => 'Abgeschlossen',
            'processing' => 'In Bearbeitung',
            'pending'    => 'Ausstehend',
            'cancelled'  => 'Storniert',
            'failed'     => 'Fehlgeschlagen',
            'refunded'   => 'Erstattet',
        ];

        $status_colors = [
            'completed'  => '#22c55e',
            'processing' => '#3b82f6',
            'pending'    => '#f59e0b',
            'cancelled'  => '#6b7280',
            'failed'     => '#ef4444',
            'refunded'   => '#8b5cf6',
        ];

        ?>
        <div class="wrap" style="max-width:1200px;">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                <span class="dashicons dashicons-cart" style="font-size:28px;width:28px;height:28px;color:var(--tix-primary, #FF5500);"></span>
                Bestellungen
                <span style="font-size:14px;color:#6b7280;font-weight:400;"><?php echo intval($total); ?> gesamt</span>
            </h1>

            <?php // ── Filter + Suche ── ?>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;gap:4px;">
                    <?php foreach ($statuses as $val => $label):
                        $active = ($status_filter === $val) ? 'font-weight:700;color:var(--tix-primary, #FF5500);' : '';
                        $url = admin_url('admin.php?page=tix-orders' . ($val ? '&status=' . $val : ''));
                    ?>
                        <a href="<?php echo esc_url($url); ?>" style="padding:4px 12px;font-size:13px;text-decoration:none;border-radius:6px;<?php echo $active; ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>
                <form method="get" style="display:flex;gap:6px;">
                    <input type="hidden" name="page" value="tix-orders">
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Suche nach Nr., E-Mail, Name…" style="padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:240px;">
                    <button type="submit" class="button">Suchen</button>
                </form>
            </div>

            <?php // ── Tabelle ── ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                <table class="widefat" style="border:none;margin:0;">
                    <thead>
                        <tr style="background:#fafafa;">
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Bestellung</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Datum</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Status</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Kunde</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Zahlung</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;text-align:right;">Betrag</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="6" style="padding:40px;text-align:center;color:#9ca3af;">Keine Bestellungen gefunden.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($orders as $o):
                            $color = $status_colors[$o->status] ?? '#6b7280';
                            $detail_url = admin_url('admin.php?page=tix-orders&order_id=' . $o->id);
                        ?>
                            <tr style="border-top:1px solid #f3f4f6;">
                                <td style="padding:12px 16px;">
                                    <a href="<?php echo esc_url($detail_url); ?>" style="font-weight:600;color:#1e293b;text-decoration:none;">
                                        <?php echo esc_html($o->order_number); ?>
                                    </a>
                                </td>
                                <td style="padding:12px 16px;font-size:13px;color:#6b7280;">
                                    <?php echo date_i18n('d.m.Y, H:i', strtotime($o->date_created)); ?>
                                </td>
                                <td style="padding:12px 16px;">
                                    <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:<?php echo $color; ?>;background:color-mix(in srgb, <?php echo $color; ?> 10%, #fff);padding:3px 10px;border-radius:20px;">
                                        <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $color; ?>;"></span>
                                        <?php echo esc_html(ucfirst($statuses[$o->status] ?? $o->status)); ?>
                                    </span>
                                </td>
                                <td style="padding:12px 16px;font-size:13px;">
                                    <?php echo esc_html($o->billing_first_name . ' ' . $o->billing_last_name); ?>
                                    <br><span style="color:#9ca3af;font-size:12px;"><?php echo esc_html($o->billing_email); ?></span>
                                </td>
                                <td style="padding:12px 16px;font-size:13px;color:#6b7280;">
                                    <?php echo esc_html($o->payment_method_title ?: $o->payment_method ?: '—'); ?>
                                </td>
                                <td style="padding:12px 16px;text-align:right;font-weight:600;font-size:14px;">
                                    <?php echo number_format($o->total, 2, ',', '.'); ?>&nbsp;&euro;
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php // ── Pagination ── ?>
            <?php if ($total_pages > 1): ?>
                <div style="display:flex;justify-content:center;gap:4px;margin-top:16px;">
                    <?php for ($p = 1; $p <= $total_pages; $p++):
                        $url = admin_url('admin.php?page=tix-orders&paged=' . $p . ($status_filter ? '&status=' . $status_filter : '') . ($search ? '&s=' . urlencode($search) : ''));
                        $active_pg = $p === $paged ? 'background:var(--tix-primary, #FF5500);color:#fff;' : 'background:#fff;border:1px solid #e5e7eb;';
                    ?>
                        <a href="<?php echo esc_url($url); ?>" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:13px;text-decoration:none;<?php echo $active_pg; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // Detailansicht
    // ──────────────────────────────────────────
    private static function render_detail($order_id) {
        global $wpdb;
        $t = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';

        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $order_id));
        if (!$order) {
            echo '<div class="wrap"><h1>Bestellung nicht gefunden</h1></div>';
            return;
        }

        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $ti WHERE order_id = %d ORDER BY id ASC", $order_id));

        // Tickets
        $tickets = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_tix_ticket_order_id',
            'meta_value'     => $order_id,
        ]);

        $statuses = [
            'completed'  => ['label' => 'Abgeschlossen',  'color' => '#22c55e'],
            'processing' => ['label' => 'In Bearbeitung',  'color' => '#3b82f6'],
            'pending'    => ['label' => 'Ausstehend',      'color' => '#f59e0b'],
            'cancelled'  => ['label' => 'Storniert',       'color' => '#6b7280'],
            'failed'     => ['label' => 'Fehlgeschlagen',  'color' => '#ef4444'],
            'refunded'   => ['label' => 'Erstattet',       'color' => '#8b5cf6'],
        ];

        $s = $statuses[$order->status] ?? ['label' => $order->status, 'color' => '#6b7280'];
        $nonce = wp_create_nonce('tix_order_action');

        ?>
        <div class="wrap" style="max-width:900px;">
            <a href="<?php echo admin_url('admin.php?page=tix-orders'); ?>" style="text-decoration:none;font-size:13px;color:#6b7280;display:inline-flex;align-items:center;gap:4px;margin-bottom:12px;">
                &larr; Alle Bestellungen
            </a>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                <h1 style="margin:0;display:flex;align-items:center;gap:12px;">
                    <?php echo esc_html($order->order_number); ?>
                    <span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:<?php echo $s['color']; ?>;background:color-mix(in srgb, <?php echo $s['color']; ?> 10%, #fff);padding:4px 12px;border-radius:20px;">
                        <span style="width:8px;height:8px;border-radius:50%;background:<?php echo $s['color']; ?>;"></span>
                        <?php echo esc_html($s['label']); ?>
                    </span>
                </h1>
                <div style="display:flex;gap:6px;">
                    <select id="tix-order-status-select" style="font-size:13px;padding:6px 10px;border-radius:6px;border:1px solid #d1d5db;">
                        <?php foreach ($statuses as $val => $info): ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($order->status, $val); ?>><?php echo esc_html($info['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" id="tix-order-status-btn"
                            data-order="<?php echo $order_id; ?>" data-nonce="<?php echo $nonce; ?>"
                            onclick="
                                var s = document.getElementById('tix-order-status-select').value;
                                fetch(ajaxurl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=tix_order_update_status&nonce=<?php echo $nonce; ?>&order_id=<?php echo $order_id; ?>&status='+s})
                                .then(function(r){return r.json()})
                                .then(function(r){if(r.success)location.reload(); else alert(r.data.message||'Fehler');});
                            ">Status ändern</button>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

                <?php // ── Bestellübersicht ── ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;">
                    <h3 style="margin:0 0 14px;font-size:14px;color:#374151;">Bestelldetails</h3>
                    <table style="width:100%;font-size:13px;">
                        <tr><td style="padding:4px 0;color:#6b7280;">Datum</td><td style="padding:4px 0;font-weight:500;"><?php echo date_i18n('d.m.Y, H:i', strtotime($order->date_created)); ?> Uhr</td></tr>
                        <tr><td style="padding:4px 0;color:#6b7280;">Zahlungsart</td><td style="padding:4px 0;font-weight:500;"><?php echo esc_html($order->payment_method_title ?: $order->payment_method ?: '—'); ?></td></tr>
                        <tr><td style="padding:4px 0;color:#6b7280;">Gesamt</td><td style="padding:4px 0;font-weight:700;font-size:16px;"><?php echo number_format($order->total, 2, ',', '.'); ?> &euro;</td></tr>
                        <?php if ($order->wc_order_id): ?>
                            <tr><td style="padding:4px 0;color:#6b7280;">WC-Bestellung</td><td style="padding:4px 0;"><a href="<?php echo admin_url('post.php?post=' . $order->wc_order_id . '&action=edit'); ?>">#<?php echo $order->wc_order_id; ?></a></td></tr>
                        <?php endif; ?>
                    </table>
                </div>

                <?php // ── Rechnungsadresse ── ?>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;">
                    <h3 style="margin:0 0 14px;font-size:14px;color:#374151;">Rechnungsadresse</h3>
                    <div style="font-size:13px;line-height:1.6;">
                        <strong><?php echo esc_html($order->billing_first_name . ' ' . $order->billing_last_name); ?></strong><br>
                        <?php if ($order->billing_company): ?><?php echo esc_html($order->billing_company); ?><br><?php endif; ?>
                        <?php if ($order->billing_address_1): ?><?php echo esc_html($order->billing_address_1); ?><br><?php endif; ?>
                        <?php if ($order->billing_postcode || $order->billing_city): ?><?php echo esc_html($order->billing_postcode . ' ' . $order->billing_city); ?><br><?php endif; ?>
                        <?php if ($order->billing_country): ?><?php echo esc_html($order->billing_country); ?><br><?php endif; ?>
                        <br>
                        <a href="mailto:<?php echo esc_attr($order->billing_email); ?>"><?php echo esc_html($order->billing_email); ?></a><br>
                        <?php if ($order->billing_phone): ?><?php echo esc_html($order->billing_phone); ?><?php endif; ?>
                    </div>
                </div>
            </div>

            <?php // ── Positionen ── ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:16px;">
                <h3 style="margin:0 0 14px;font-size:14px;color:#374151;">Positionen</h3>
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid #f3f4f6;">
                            <th style="text-align:left;padding:8px 0;color:#6b7280;font-weight:600;">Artikel</th>
                            <th style="text-align:center;padding:8px 0;color:#6b7280;font-weight:600;width:60px;">Menge</th>
                            <th style="text-align:right;padding:8px 0;color:#6b7280;font-weight:600;width:100px;">Betrag</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $event_link = $item->event_id ? get_edit_post_link($item->event_id) : '';
                        ?>
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:10px 0;">
                                    <?php if ($event_link): ?><a href="<?php echo esc_url($event_link); ?>" style="text-decoration:none;color:#1e293b;font-weight:500;"><?php endif; ?>
                                    <?php echo esc_html($item->name); ?>
                                    <?php if ($event_link): ?></a><?php endif; ?>
                                    <?php if ($item->cat_name): ?>
                                        <span style="color:#9ca3af;font-size:12px;">(<?php echo esc_html($item->cat_name); ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;padding:10px 0;"><?php echo intval($item->quantity); ?></td>
                                <td style="text-align:right;padding:10px 0;font-weight:500;"><?php echo number_format($item->total, 2, ',', '.'); ?> &euro;</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <?php if ($order->discount > 0): ?>
                        <tr><td colspan="2" style="text-align:right;padding:6px 0;color:#6b7280;">Rabatt</td><td style="text-align:right;padding:6px 0;color:#22c55e;">-<?php echo number_format($order->discount, 2, ',', '.'); ?> &euro;</td></tr>
                        <?php endif; ?>
                        <?php if ($order->tax > 0): ?>
                        <tr><td colspan="2" style="text-align:right;padding:6px 0;color:#6b7280;">MwSt.</td><td style="text-align:right;padding:6px 0;"><?php echo number_format($order->tax, 2, ',', '.'); ?> &euro;</td></tr>
                        <?php endif; ?>
                        <tr style="border-top:2px solid #e5e7eb;">
                            <td colspan="2" style="text-align:right;padding:10px 0;font-weight:700;font-size:14px;">Gesamt</td>
                            <td style="text-align:right;padding:10px 0;font-weight:700;font-size:16px;"><?php echo number_format($order->total, 2, ',', '.'); ?> &euro;</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php // ── Tickets ── ?>
            <?php if (!empty($tickets)): ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:16px;">
                <h3 style="margin:0 0 14px;font-size:14px;color:#374151;">Tickets (<?php echo count($tickets); ?>)</h3>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($tickets as $ticket):
                        $code = get_post_meta($ticket->ID, '_tix_ticket_code', true);
                        $event_name = get_post_meta($ticket->ID, '_tix_ticket_event_name', true);
                        $checked_in = get_post_meta($ticket->ID, '_tix_ticket_checked_in', true);
                        $token = get_post_meta($ticket->ID, '_tix_ticket_download_token', true);
                    ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border:1px solid #f3f4f6;border-radius:8px;">
                            <div>
                                <span style="font-family:monospace;font-size:13px;font-weight:600;color:#374151;"><?php echo esc_html($code); ?></span>
                                <span style="font-size:12px;color:#9ca3af;margin-left:8px;"><?php echo esc_html($event_name); ?></span>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <?php if ($checked_in): ?>
                                    <span style="font-size:11px;color:#22c55e;font-weight:600;">✓ Eingecheckt</span>
                                <?php else: ?>
                                    <span style="font-size:11px;color:#9ca3af;">Nicht eingecheckt</span>
                                <?php endif; ?>
                                <?php if ($token): ?>
                                    <a href="<?php echo esc_url(add_query_arg('tix_dl', $token, home_url('/'))); ?>" class="button" style="font-size:11px;padding:2px 8px;">PDF</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // AJAX: Status ändern
    // ──────────────────────────────────────────
    public static function ajax_update_status() {
        check_ajax_referer('tix_order_action', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $order_id = intval($_POST['order_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');
        $allowed = ['completed', 'processing', 'pending', 'cancelled', 'failed', 'refunded'];

        if (!$order_id || !in_array($status, $allowed)) {
            wp_send_json_error(['message' => 'Ungültige Parameter.']);
        }

        if (class_exists('TIX_Native_Checkout')) {
            TIX_Native_Checkout::update_order_status($order_id, $status, 'admin');
        } else {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'tix_orders', ['status' => $status], ['id' => $order_id]);
        }

        wp_send_json_success();
    }
}
