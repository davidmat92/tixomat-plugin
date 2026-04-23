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
        add_action('wp_ajax_tix_order_refund', [__CLASS__, 'ajax_process_refund']);
        add_action('wp_ajax_tix_order_add_note', [__CLASS__, 'ajax_add_note']);
        add_action('wp_ajax_tix_order_bulk_action', [__CLASS__, 'ajax_bulk_action']);
        add_action('wp_ajax_tix_order_export_csv', [__CLASS__, 'ajax_export_csv']);
        add_action('wp_ajax_tix_order_invoice', [__CLASS__, 'render_invoice']);
        add_action('wp_ajax_tix_order_resend_email', [__CLASS__, 'ajax_resend_email']);

        // Manuelle Bestellerstellung
        add_action('wp_ajax_tix_order_get_categories', [__CLASS__, 'ajax_get_categories']);
        add_action('wp_ajax_tix_order_create_manual', [__CLASS__, 'ajax_create_manual']);
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Bestellungen',
            'Bestellungen',
            'manage_options',
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

        // Neue Bestellung erstellen
        if (isset($_GET['action']) && $_GET['action'] === 'create') {
            self::render_create_form();
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
        $event_filter = intval($_GET['event_id'] ?? 0);
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;

        $where = '1=1';
        $params = [];

        if ($status_filter) {
            $where .= ' AND status = %s';
            $params[] = $status_filter;
        }
        if ($event_filter) {
            $ti = $wpdb->prefix . 'tix_order_items';
            $where .= " AND id IN (SELECT order_id FROM $ti WHERE event_id = %d)";
            $params[] = $event_filter;
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
            'processing' => 'Bearbeitung',
            'pending'    => 'Ausstehend',
            'on-hold'    => 'Wartend (Banküberweisung)',
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
        <div class="wrap" style="max-width:100%;">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                <span class="dashicons dashicons-cart" style="font-size:28px;width:28px;height:28px;color:var(--tix-primary, #FF5500);"></span>
                Bestellungen
                <?php if ($event_filter) : ?>
                    <span style="font-size:14px;color:#6b7280;font-weight:400;">f&uuml;r <?php echo esc_html(get_the_title($event_filter)); ?></span>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tix-orders')); ?>" style="font-size:12px;text-decoration:none;color:#6b7280;">&times; Filter entfernen</a>
                <?php else : ?>
                    <span style="font-size:14px;color:#6b7280;font-weight:400;"><?php echo intval($total); ?> gesamt</span>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=tix-orders&action=create')); ?>" class="page-title-action" style="margin-left:auto;background:var(--tix-primary, #FF5500);color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:13px;text-decoration:none;">+ Neue Bestellung</a>
            </h1>

            <?php // ── Filter + Suche ── ?>
            <?php
            // Events mit Bestellungen ermitteln
            $ti = $wpdb->prefix . 'tix_order_items';
            $event_ids_with_orders = $wpdb->get_col("SELECT DISTINCT event_id FROM $ti WHERE event_id > 0 ORDER BY event_id DESC");
            $filter_events = [];
            foreach ($event_ids_with_orders as $eid) {
                $title = get_the_title($eid);
                if ($title) $filter_events[$eid] = $title;
            }
            ?>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                    <?php foreach ($statuses as $val => $label):
                        $active = ($status_filter === $val) ? 'font-weight:700;color:var(--tix-primary, #FF5500);' : '';
                        $url = admin_url('admin.php?page=tix-orders' . ($val ? '&status=' . $val : '') . ($event_filter ? '&event_id=' . $event_filter : ''));
                    ?>
                        <a href="<?php echo esc_url($url); ?>" style="padding:4px 12px;font-size:13px;text-decoration:none;border-radius:6px;<?php echo $active; ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                    <?php if (!empty($filter_events)) : ?>
                    <span style="color:#d1d5db;margin:0 4px;">|</span>
                    <form method="get" style="display:flex;gap:4px;align-items:center;" id="tix-event-filter-form">
                        <input type="hidden" name="page" value="tix-orders">
                        <?php if ($status_filter) : ?><input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>"><?php endif; ?>
                        <select name="event_id" onchange="this.form.submit()" style="padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;max-width:220px;">
                            <option value="">Alle Events</option>
                            <?php foreach ($filter_events as $eid => $title) : ?>
                                <option value="<?php echo esc_attr($eid); ?>" <?php selected($event_filter, $eid); ?>><?php echo esc_html(wp_trim_words($title, 6, '…')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                </div>
                <form method="get" style="display:flex;gap:6px;">
                    <input type="hidden" name="page" value="tix-orders">
                    <?php if ($event_filter) : ?><input type="hidden" name="event_id" value="<?php echo esc_attr($event_filter); ?>"><?php endif; ?>
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Suche nach Nr., E-Mail, Name…" style="padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:240px;">
                    <button type="submit" class="button">Suchen</button>
                </form>
            </div>

            <?php // ── Bulk Action Bar ── ?>
            <?php $bulk_nonce = wp_create_nonce('tix_bulk_action'); ?>
            <div id="tix-bulk-bar" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 16px;margin-bottom:12px;align-items:center;gap:12px;">
                <span id="tix-bulk-count">0</span> ausgewählt
                <select id="tix-bulk-action">
                    <option value="">Aktion wählen…</option>
                    <option value="completed">→ Abgeschlossen</option>
                    <option value="cancelled">→ Storniert</option>
                    <option value="refunded">→ Erstattet</option>
                </select>
                <button type="button" class="button" id="tix-bulk-apply">Ausführen</button>
                <button type="button" class="button" id="tix-bulk-export" style="margin-left:auto;">CSV Export</button>
            </div>

            <?php // ── Tabelle ── ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                <table class="widefat" style="border:none;margin:0;">
                    <thead>
                        <tr style="background:#fafafa;">
                            <th style="width:40px;padding:12px 8px;"><input type="checkbox" id="tix-bulk-all"></th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Bestellung</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Datum</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Status</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Kunde</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;">Zahlung</th>
                            <th style="padding:12px 16px;font-size:13px;font-weight:600;text-align:right;">Betrag</th>
                            <th style="padding:12px 8px;font-size:13px;font-weight:600;width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;">Keine Bestellungen gefunden.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($orders as $o):
                            $color = $status_colors[$o->status] ?? '#6b7280';
                            $detail_url = admin_url('admin.php?page=tix-orders&order_id=' . $o->id);
                        ?>
                            <tr style="border-top:1px solid #f3f4f6;">
                                <td style="padding:12px 8px;"><input type="checkbox" class="tix-bulk-check" value="<?php echo $o->id; ?>"></td>
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
                                <td style="padding:12px 8px;text-align:center;">
                                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=tix_order_invoice&order_id=' . $o->id), 'tix_invoice')); ?>"
                                       target="_blank" title="Rechnung" style="color:#6b7280;text-decoration:none;">
                                        <span class="dashicons dashicons-media-document" style="font-size:18px;width:18px;height:18px;"></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php // ── Pagination ── ?>
            <?php if ($total_pages > 1): ?>
                <div style="display:flex;justify-content:center;gap:4px;margin-top:16px;">
                    <?php
                    // Truncated pagination
                    $range = 2;
                    $show_pages = [];
                    for ($p = 1; $p <= $total_pages; $p++) {
                        if ($p === 1 || $p === $total_pages || abs($p - $paged) <= $range) {
                            $show_pages[] = $p;
                        } elseif (end($show_pages) !== '...') {
                            $show_pages[] = '...';
                        }
                    }
                    foreach ($show_pages as $p):
                        if ($p === '...'):
                    ?>
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;font-size:13px;">…</span>
                    <?php else:
                        $url = admin_url('admin.php?page=tix-orders&paged=' . $p . ($status_filter ? '&status=' . $status_filter : '') . ($search ? '&s=' . urlencode($search) : '') . ($event_filter ? '&event_id=' . $event_filter : ''));
                        $active_pg = $p === $paged ? 'background:var(--tix-primary, #FF5500);color:#fff;' : 'background:#fff;border:1px solid #e5e7eb;';
                    ?>
                        <a href="<?php echo esc_url($url); ?>" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:13px;text-decoration:none;<?php echo $active_pg; ?>"><?php echo $p; ?></a>
                    <?php
                        endif;
                    endforeach;
                    ?>
                </div>
            <?php endif; ?>

            <script>
            (function(){
                var allCb = document.getElementById('tix-bulk-all');
                var bar = document.getElementById('tix-bulk-bar');
                var countEl = document.getElementById('tix-bulk-count');
                var checks = document.querySelectorAll('.tix-bulk-check');

                function updateBar(){
                    var sel = document.querySelectorAll('.tix-bulk-check:checked');
                    countEl.textContent = sel.length;
                    bar.style.display = sel.length > 0 ? 'flex' : 'none';
                }

                if(allCb){
                    allCb.addEventListener('change', function(){
                        checks.forEach(function(c){ c.checked = allCb.checked; });
                        updateBar();
                    });
                }

                checks.forEach(function(c){
                    c.addEventListener('change', function(){
                        if(!c.checked) allCb.checked = false;
                        else {
                            var all = true;
                            checks.forEach(function(x){ if(!x.checked) all = false; });
                            allCb.checked = all;
                        }
                        updateBar();
                    });
                });

                document.getElementById('tix-bulk-apply').addEventListener('click', function(){
                    var action = document.getElementById('tix-bulk-action').value;
                    if(!action){ alert('Bitte eine Aktion wählen.'); return; }
                    var ids = [];
                    document.querySelectorAll('.tix-bulk-check:checked').forEach(function(c){ ids.push(c.value); });
                    if(ids.length === 0){ alert('Keine Bestellungen ausgewählt.'); return; }
                    if(!confirm(ids.length + ' Bestellung(en) auf "' + action + '" setzen?')) return;

                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = 'Wird ausgeführt…';

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=tix_order_bulk_action&nonce=<?php echo $bulk_nonce; ?>&ids=' + encodeURIComponent(JSON.stringify(ids)) + '&bulk_status=' + encodeURIComponent(action)
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if(r.success){
                            location.reload();
                        } else {
                            alert(r.data.message || 'Fehler');
                            btn.disabled = false;
                            btn.textContent = 'Ausführen';
                        }
                    })
                    .catch(function(){
                        alert('Netzwerkfehler.');
                        btn.disabled = false;
                        btn.textContent = 'Ausführen';
                    });
                });

                document.getElementById('tix-bulk-export').addEventListener('click', function(){
                    var ids = [];
                    document.querySelectorAll('.tix-bulk-check:checked').forEach(function(c){ ids.push(c.value); });
                    var url = ajaxurl + '?action=tix_order_export_csv&nonce=<?php echo $bulk_nonce; ?>';
                    if(ids.length > 0) url += '&ids=' + encodeURIComponent(JSON.stringify(ids));
                    window.location.href = url;
                });
            })();
            </script>
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
            'processing' => ['label' => 'Bearbeitung',  'color' => '#3b82f6'],
            'pending'    => ['label' => 'Ausstehend',      'color' => '#f59e0b'],
            'on-hold'    => ['label' => 'Wartend',          'color' => '#f97316'],
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
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                    <?php
                    // Quick-Confirm für Bank-/Warten-Bestellungen: direkt als bezahlt markieren
                    $is_waiting       = in_array($order->status, ['on-hold', 'pending'], true);
                    $is_bank_transfer = in_array($order->payment_method, ['bank', 'bacs'], true);
                    if ($is_waiting):
                    ?>
                        <button type="button" id="tix-order-confirm-bank-btn"
                                style="background:#16a34a;border:1px solid #16a34a;color:#fff;padding:7px 14px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"
                                onclick="
                                    if (!confirm('Bestellung als <?php echo $is_bank_transfer ? 'Zahlungseingang' : 'bezahlt'; ?> bestätigen und abschließen?\n\nTickets werden automatisch generiert und per E-Mail versendet.')) return;
                                    this.disabled = true; this.innerHTML = 'Bestätige…';
                                    fetch(ajaxurl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=tix_order_update_status&nonce=<?php echo $nonce; ?>&order_id=<?php echo $order_id; ?>&status=completed'})
                                    .then(function(r){return r.json()})
                                    .then(function(r){if(r.success)location.reload(); else{alert(r.data.message||'Fehler'); this.disabled=false;}}.bind(this));
                                ">
                            <span class="dashicons dashicons-yes-alt" style="width:16px;height:16px;font-size:16px;"></span>
                            <?php echo $is_bank_transfer ? 'Zahlungseingang bestätigen' : 'Als bezahlt markieren'; ?>
                        </button>
                    <?php endif; ?>

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
                    <?php if (in_array($order->status, ['completed', 'processing'])): ?>
                        <button type="button" class="button" id="tix-order-refund-btn" style="color:#ef4444;border-color:#ef4444;">Erstatten</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php // ── Erstattungs-Dialog ── ?>
            <?php if (in_array($order->status, ['completed', 'processing'])): ?>
            <div id="tix-refund-panel" style="display:none;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:20px;">
                <h3 style="margin:0 0 16px;font-size:15px;color:#374151;display:flex;align-items:center;gap:8px;">
                    <span class="dashicons dashicons-undo" style="color:#ef4444;"></span>
                    Erstattung durchführen
                </h3>

                <div style="display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:6px;">
                            <input type="radio" name="tix_refund_type" value="full" checked> Vollständige Erstattung (<?php echo number_format($order->total, 2, ',', '.'); ?> &euro;)
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="radio" name="tix_refund_type" value="partial"> Teilerstattung
                        </label>
                    </div>

                    <div id="tix-refund-amount-wrap" style="display:none;">
                        <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">Erstattungsbetrag (&euro;)</label>
                        <input type="number" id="tix-refund-amount" step="0.01" min="0.01" max="<?php echo esc_attr($order->total); ?>"
                               placeholder="0,00" style="width:160px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                    </div>

                    <div>
                        <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">Grund (optional)</label>
                        <textarea id="tix-refund-reason" rows="2" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;resize:vertical;" placeholder="Grund für die Erstattung..."></textarea>
                    </div>

                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                        <input type="checkbox" id="tix-refund-cancel-tickets" checked> Tickets stornieren
                    </label>

                    <div style="display:flex;gap:8px;margin-top:4px;">
                        <button type="button" id="tix-refund-submit" class="button" style="background:#ef4444;border-color:#ef4444;color:#fff;">Erstattung durchführen</button>
                        <button type="button" id="tix-refund-cancel" class="button">Abbrechen</button>
                    </div>
                </div>
            </div>

            <script>
            (function(){
                var panel = document.getElementById('tix-refund-panel');
                var btn = document.getElementById('tix-order-refund-btn');
                var amountWrap = document.getElementById('tix-refund-amount-wrap');
                var radios = document.querySelectorAll('input[name="tix_refund_type"]');

                btn.addEventListener('click', function(){ panel.style.display = 'block'; btn.style.display = 'none'; });
                document.getElementById('tix-refund-cancel').addEventListener('click', function(){ panel.style.display = 'none'; btn.style.display = ''; });

                radios.forEach(function(r){
                    r.addEventListener('change', function(){ amountWrap.style.display = this.value === 'partial' ? 'block' : 'none'; });
                });

                document.getElementById('tix-refund-submit').addEventListener('click', function(){
                    var submitBtn = this;
                    var type = document.querySelector('input[name="tix_refund_type"]:checked').value;
                    var amount = type === 'partial' ? document.getElementById('tix-refund-amount').value : '';
                    var reason = document.getElementById('tix-refund-reason').value;
                    var cancelTickets = document.getElementById('tix-refund-cancel-tickets').checked ? '1' : '0';

                    if (type === 'partial' && (!amount || parseFloat(amount) <= 0)) {
                        alert('Bitte einen gültigen Erstattungsbetrag eingeben.');
                        return;
                    }

                    if (!confirm('Erstattung wirklich durchführen? Dieser Vorgang kann nicht rückgängig gemacht werden.')) return;

                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Wird verarbeitet…';

                    var body = 'action=tix_order_refund&nonce=<?php echo $nonce; ?>&order_id=<?php echo $order_id; ?>&refund_type=' + type + '&cancel_tickets=' + cancelTickets;
                    if (amount) body += '&amount=' + encodeURIComponent(amount);
                    if (reason) body += '&reason=' + encodeURIComponent(reason);

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: body
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            location.reload();
                        } else {
                            alert(r.data.message || 'Fehler bei der Erstattung.');
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Erstattung durchführen';
                        }
                    })
                    .catch(function(){
                        alert('Netzwerkfehler. Bitte erneut versuchen.');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Erstattung durchführen';
                    });
                });
            })();
            </script>
            <?php endif; ?>

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
                        <?php
                        $refund_info = get_option('_tix_refund_' . $order_id);
                        if ($refund_info && is_array($refund_info)):
                            $refund_user = get_userdata($refund_info['admin_user'] ?? 0);
                        ?>
                            <tr><td colspan="2" style="padding:10px 0 2px;"><hr style="border:none;border-top:1px solid #f3f4f6;margin:0;"></td></tr>
                            <tr><td style="padding:4px 0;color:#8b5cf6;font-weight:600;" colspan="2">Erstattung</td></tr>
                            <tr><td style="padding:4px 0;color:#6b7280;">Betrag</td><td style="padding:4px 0;font-weight:600;color:#8b5cf6;"><?php echo number_format($refund_info['amount'], 2, ',', '.'); ?> &euro; (<?php echo $refund_info['type'] === 'full' ? 'Voll' : 'Teil'; ?>)</td></tr>
                            <tr><td style="padding:4px 0;color:#6b7280;">Datum</td><td style="padding:4px 0;font-weight:500;"><?php echo date_i18n('d.m.Y, H:i', strtotime($refund_info['date'])); ?></td></tr>
                            <?php if (!empty($refund_info['reason'])): ?>
                                <tr><td style="padding:4px 0;color:#6b7280;">Grund</td><td style="padding:4px 0;"><?php echo esc_html($refund_info['reason']); ?></td></tr>
                            <?php endif; ?>
                            <?php if ($refund_user): ?>
                                <tr><td style="padding:4px 0;color:#6b7280;">Bearbeiter</td><td style="padding:4px 0;"><?php echo esc_html($refund_user->display_name); ?></td></tr>
                            <?php endif; ?>
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

            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=tix_order_invoice&order_id=' . $order_id), 'tix_invoice')); ?>"
               target="_blank" class="button" style="margin-top:8px;display:inline-flex;align-items:center;gap:5px;">
                <span class="dashicons dashicons-media-document" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span> Rechnung
            </a>
            <button type="button" class="button" id="tix-resend-email-btn" style="margin-top:8px;display:inline-flex;align-items:center;gap:5px;"
                    onclick="
                        var btn = this; btn.disabled = true; btn.textContent = 'Wird gesendet…';
                        fetch(ajaxurl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=tix_order_resend_email&nonce=<?php echo $nonce; ?>&order_id=<?php echo $order_id; ?>'})
                        .then(function(r){return r.json()})
                        .then(function(r){ alert(r.success ? (r.data.message || 'Gesendet.') : (r.data.message || 'Fehler')); btn.disabled = false; btn.innerHTML = '<span class=\'dashicons dashicons-email\' style=\'font-size:16px;width:16px;height:16px;vertical-align:middle;\'></span> E-Mail erneut senden'; })
                        .catch(function(){ alert('Netzwerkfehler.'); btn.disabled = false; btn.innerHTML = '<span class=\'dashicons dashicons-email\' style=\'font-size:16px;width:16px;height:16px;vertical-align:middle;\'></span> E-Mail erneut senden'; });
                    ">
                <span class="dashicons dashicons-email" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span> E-Mail erneut senden
            </button>

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
                                <?php if ($token):
                                    $ticket_label = class_exists('TIX_Tickets') ? TIX_Tickets::ticket_type_label($ticket->ID) : 'Ticket';
                                ?>
                                    <a href="<?php echo esc_url(add_query_arg('tix_dl', $token, home_url('/'))); ?>" class="button" style="font-size:11px;padding:2px 8px;" target="_blank"><?php echo esc_html($ticket_label); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── Notizen / Timeline ── ?>
            <?php
                $notes = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}tix_order_notes WHERE order_id = %d ORDER BY date_created ASC",
                    $order_id
                ));
                $note_type_styles = [
                    'internal'      => ['bg' => '#f3f4f6', 'border' => '#d1d5db', 'icon' => '📝', 'label' => 'Intern'],
                    'status_change' => ['bg' => '#eff6ff', 'border' => '#93c5fd', 'icon' => '🔄', 'label' => 'Statusänderung'],
                    'email'         => ['bg' => '#f0fdf4', 'border' => '#86efac', 'icon' => '📧', 'label' => 'E-Mail'],
                    'refund'        => ['bg' => '#faf5ff', 'border' => '#c4b5fd', 'icon' => '💰', 'label' => 'Erstattung'],
                ];
            ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-top:16px;">
                <h3 style="margin:0 0 14px;font-size:14px;color:#374151;">Notizen / Verlauf</h3>

                <div id="tix-order-notes" style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
                    <?php if (empty($notes)): ?>
                        <p style="color:#9ca3af;font-size:13px;" id="tix-notes-empty">Noch keine Notizen vorhanden.</p>
                    <?php endif; ?>
                    <?php foreach ($notes as $note):
                        $nts = $note_type_styles[$note->note_type] ?? $note_type_styles['internal'];
                    ?>
                        <div style="padding:10px 14px;border-radius:8px;background:<?php echo $nts['bg']; ?>;border:1px solid <?php echo $nts['border']; ?>;font-size:13px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                                <span style="font-weight:600;font-size:12px;">
                                    <?php echo $nts['icon']; ?> <?php echo esc_html($nts['label']); ?>
                                    <?php if ($note->author): ?>
                                        <span style="font-weight:400;color:#6b7280;"> &mdash; <?php echo esc_html($note->author); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span style="font-size:11px;color:#9ca3af;"><?php echo date_i18n('d.m.Y, H:i', strtotime($note->date_created)); ?></span>
                            </div>
                            <div style="color:#374151;"><?php echo esc_html($note->note); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="display:flex;gap:8px;">
                    <input type="text" id="tix-note-input" placeholder="Notiz hinzufügen…" style="flex:1;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                    <button type="button" class="button" id="tix-note-add-btn">Hinzufügen</button>
                </div>
            </div>
            <script>
            (function(){
                var addBtn = document.getElementById('tix-note-add-btn');
                var input  = document.getElementById('tix-note-input');
                if (!addBtn || !input) return;

                addBtn.addEventListener('click', function(){
                    var text = input.value.trim();
                    if (!text) return;

                    addBtn.disabled = true;
                    addBtn.textContent = '…';

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=tix_order_add_note&nonce=<?php echo $nonce; ?>&order_id=<?php echo $order_id; ?>&note=' + encodeURIComponent(text)
                    })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            // Append note to timeline
                            var container = document.getElementById('tix-order-notes');
                            var empty = document.getElementById('tix-notes-empty');
                            if (empty) empty.remove();

                            var div = document.createElement('div');
                            div.style.cssText = 'padding:10px 14px;border-radius:8px;background:#f3f4f6;border:1px solid #d1d5db;font-size:13px;';
                            div.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;"><span style="font-weight:600;font-size:12px;">📝 Intern &mdash; ' + (r.data.author || '') + '</span><span style="font-size:11px;color:#9ca3af;">' + (r.data.date || 'Gerade eben') + '</span></div><div style="color:#374151;">' + text.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
                            container.appendChild(div);
                            input.value = '';
                        } else {
                            alert(r.data.message || 'Fehler');
                        }
                        addBtn.disabled = false;
                        addBtn.textContent = 'Hinzufügen';
                    })
                    .catch(function(){
                        alert('Netzwerkfehler');
                        addBtn.disabled = false;
                        addBtn.textContent = 'Hinzufügen';
                    });
                });

                input.addEventListener('keypress', function(e){
                    if (e.key === 'Enter') { e.preventDefault(); addBtn.click(); }
                });
            })();
            </script>

        </div>
        <?php
    }

    // ──────────────────────────────────────────
    // ORDER NOTES
    // ──────────────────────────────────────────

    /**
     * Add a note to an order.
     */
    public static function add_note($order_id, $text, $type = 'internal', $author = '') {
        global $wpdb;

        if (!$author) {
            $user = wp_get_current_user();
            $author = $user && $user->ID ? $user->display_name : 'System';
        }

        $wpdb->insert($wpdb->prefix . 'tix_order_notes', [
            'order_id'     => intval($order_id),
            'note'         => sanitize_textarea_field($text),
            'note_type'    => sanitize_key($type),
            'author'       => sanitize_text_field($author),
            'date_created' => current_time('mysql'),
        ]);

        return $wpdb->insert_id;
    }

    /**
     * AJAX: Add note from admin UI.
     */
    public static function ajax_add_note() {
        check_ajax_referer('tix_order_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $note     = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$order_id || !$note) {
            wp_send_json_error(['message' => 'Ungültige Parameter.']);
        }

        $user = wp_get_current_user();
        $author = $user->display_name ?: 'Admin';

        self::add_note($order_id, $note, 'internal', $author);

        wp_send_json_success([
            'author' => $author,
            'date'   => date_i18n('d.m.Y, H:i'),
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: E-Mail erneut senden
    // ──────────────────────────────────────────
    public static function ajax_resend_email() {
        check_ajax_referer('tix_order_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }
        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) wp_send_json_error(['message' => 'Ungültige Bestellnummer.']);

        if (class_exists('TIX_Emails')) {
            TIX_Emails::send_native_completed($order_id);
            self::add_note($order_id, 'Bestätigungsmail erneut gesendet.', 'email');
        }

        wp_send_json_success(['message' => 'E-Mail wurde erneut gesendet.']);
    }

    // ──────────────────────────────────────────
    // AJAX: Status ändern
    // ──────────────────────────────────────────
    public static function ajax_update_status() {
        check_ajax_referer('tix_order_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $order_id = intval($_POST['order_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');
        $allowed = ['completed', 'processing', 'pending', 'on-hold', 'cancelled', 'failed', 'refunded'];

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

    // ──────────────────────────────────────────
    // AJAX: Erstattung verarbeiten
    // ──────────────────────────────────────────
    public static function ajax_process_refund() {
        check_ajax_referer('tix_order_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        $order_id       = intval($_POST['order_id'] ?? 0);
        $refund_type     = sanitize_text_field($_POST['refund_type'] ?? 'full');
        $amount          = floatval($_POST['amount'] ?? 0);
        $reason          = sanitize_textarea_field($_POST['reason'] ?? '');
        $cancel_tickets  = ($_POST['cancel_tickets'] ?? '1') === '1';

        if (!$order_id) {
            wp_send_json_error(['message' => 'Ungültige Bestellnummer.']);
        }

        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id
        ));

        if (!$order) {
            wp_send_json_error(['message' => 'Bestellung nicht gefunden.']);
        }

        if (!in_array($order->status, ['completed', 'processing'])) {
            wp_send_json_error(['message' => 'Erstattung nur für abgeschlossene oder in Bearbeitung befindliche Bestellungen möglich.']);
        }

        // Determine refund amount
        $refund_amount = ($refund_type === 'partial' && $amount > 0) ? $amount : floatval($order->total);

        if ($refund_amount <= 0 || $refund_amount > floatval($order->total)) {
            wp_send_json_error(['message' => 'Ungültiger Erstattungsbetrag.']);
        }

        $is_full_refund = ($refund_amount >= floatval($order->total));
        $gateway_amount = $is_full_refund ? null : $refund_amount;

        // Process refund via payment gateway
        $gateway = $order->payment_method;
        $result  = ['success' => true]; // default for non-API gateways

        if ($gateway === 'mollie' && class_exists('TIX_Gateway_Mollie')) {
            $result = TIX_Gateway_Mollie::refund($order_id, $gateway_amount);
        } elseif ($gateway === 'paypal' && class_exists('TIX_Gateway_PayPal')) {
            $result = TIX_Gateway_PayPal::refund($order_id, $gateway_amount);
        }
        // bank / free / unknown → no API call, just update status

        if (!empty($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        // Update order status — only set to refunded for full refunds
        $new_status = $is_full_refund ? 'refunded' : $order->status;
        if ($is_full_refund) {
            if (class_exists('TIX_Native_Checkout')) {
                TIX_Native_Checkout::update_order_status($order_id, 'refunded', 'admin_refund');
            } else {
                $wpdb->update($wpdb->prefix . 'tix_orders', ['status' => 'refunded'], ['id' => $order_id]);
            }
        }

        // Store refund metadata
        $refund_data = [
            'amount'     => $refund_amount,
            'reason'     => $reason,
            'type'       => $is_full_refund ? 'full' : 'partial',
            'date'       => current_time('mysql'),
            'admin_user' => get_current_user_id(),
            'gateway'    => $gateway,
        ];
        update_option('_tix_refund_' . $order_id, $refund_data, false);

        // Write refund order note
        $refund_note = ($is_full_refund ? 'Vollständige' : 'Teilweise') . ' Erstattung: ' . number_format($refund_amount, 2, ',', '.') . ' €';
        if ($reason) $refund_note .= ' — Grund: ' . $reason;
        self::add_note($order_id, $refund_note, 'refund');

        // Cancel tickets if requested
        if ($cancel_tickets) {
            do_action('tix_order_cancelled', $order_id);
        }

        wp_send_json_success(['message' => 'Erstattung erfolgreich durchgeführt.']);
    }

    // ──────────────────────────────────────────
    // Bulk Action AJAX
    // ──────────────────────────────────────────
    public static function ajax_bulk_action() {
        check_ajax_referer('tix_bulk_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        $ids = json_decode(stripslashes($_POST['ids'] ?? '[]'), true);
        $new_status = sanitize_text_field($_POST['bulk_status'] ?? '');

        if (empty($ids) || !is_array($ids)) {
            wp_send_json_error(['message' => 'Keine Bestellungen ausgewählt.']);
        }
        $allowed = ['completed', 'processing', 'pending', 'on-hold', 'cancelled', 'failed', 'refunded'];
        if (!in_array($new_status, $allowed, true)) {
            wp_send_json_error(['message' => 'Ungültiger Status.']);
        }

        $updated = 0;
        foreach ($ids as $id) {
            $id = intval($id);
            if ($id > 0) {
                TIX_Native_Checkout::update_order_status($id, $new_status);
                $updated++;
            }
        }

        wp_send_json_success(['message' => $updated . ' Bestellung(en) aktualisiert.', 'count' => $updated]);
    }

    // ──────────────────────────────────────────
    // CSV Export AJAX
    // ──────────────────────────────────────────
    public static function ajax_export_csv() {
        check_ajax_referer('tix_bulk_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }

        global $wpdb;
        $t  = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';

        $ids_raw = json_decode(stripslashes($_GET['ids'] ?? '[]'), true);

        if (!empty($ids_raw) && is_array($ids_raw)) {
            $placeholders = implode(',', array_fill(0, count($ids_raw), '%d'));
            $orders = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t WHERE id IN ($placeholders) ORDER BY date_created DESC",
                ...array_map('intval', $ids_raw)
            ));
        } else {
            $orders = $wpdb->get_results("SELECT * FROM $t ORDER BY date_created DESC");
        }

        $statuses = [
            'completed'  => 'Abgeschlossen',
            'processing' => 'Bearbeitung',
            'pending'    => 'Ausstehend',
            'on-hold'    => 'Wartend',
            'cancelled'  => 'Storniert',
            'failed'     => 'Fehlgeschlagen',
            'refunded'   => 'Erstattet',
        ];

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="bestellungen-' . date('Y-m-d-His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, [
            'Bestellnummer', 'Datum', 'Status', 'Kunde', 'E-Mail',
            'Zahlungsart', 'Zwischensumme', 'MwSt', 'Rabatt', 'Gesamt', 'Positionen'
        ], ';');

        // Batch-load all items for these orders
        $order_ids = wp_list_pluck($orders, 'id');
        $all_items = [];
        if (!empty($order_ids)) {
            $ids_str = implode(',', array_map('intval', $order_ids));
            $item_rows = $wpdb->get_results("SELECT * FROM $ti WHERE order_id IN ($ids_str) ORDER BY order_id, id");
            foreach ($item_rows as $ir) {
                $all_items[$ir->order_id][] = $ir;
            }
        }

        foreach ($orders as $o) {
            $items = $all_items[$o->id] ?? [];
            $item_names = [];
            foreach ($items as $item) {
                $item_names[] = $item->name . ' x' . intval($item->quantity);
            }

            fputcsv($out, [
                $o->order_number,
                date_i18n('d.m.Y H:i', strtotime($o->date_created)),
                $statuses[$o->status] ?? $o->status,
                $o->billing_first_name . ' ' . $o->billing_last_name,
                $o->billing_email,
                $o->payment_method_title ?: $o->payment_method ?: '',
                number_format($o->subtotal ?? ($o->total - ($o->tax ?? 0) + ($o->discount ?? 0)), 2, ',', '.'),
                number_format($o->tax ?? 0, 2, ',', '.'),
                number_format($o->discount ?? 0, 2, ',', '.'),
                number_format($o->total, 2, ',', '.'),
                implode(', ', $item_names),
            ], ';');
        }

        fclose($out);
        die();
    }

    // ──────────────────────────────────────────
    // Rechnung (Invoice) – HTML + Print
    // ──────────────────────────────────────────
    public static function render_invoice() {
        if (!check_ajax_referer('tix_invoice', '_wpnonce', false)) {
            wp_die('Ungültiger Sicherheitstoken.', 'Fehler', ['response' => 403]);
        }
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.', 'Fehler', ['response' => 403]);
        }

        $order_id = intval($_GET['order_id'] ?? 0);
        if (!$order_id) {
            wp_die('Ungültige Bestell-ID.', 'Fehler', ['response' => 400]);
        }

        global $wpdb;
        $t  = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';

        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $order_id));
        if (!$order) {
            wp_die('Bestellung nicht gefunden.', 'Fehler', ['response' => 404]);
        }

        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $ti WHERE order_id = %d ORDER BY id ASC", $order_id));

        // Settings
        $s = get_option('tix_settings', []);
        $defaults = class_exists('TIX_Settings') ? TIX_Settings::defaults() : [];
        $s = wp_parse_args($s, $defaults);

        $company_name    = $s['invoice_company_name'] ?? '';
        $company_address = $s['invoice_company_address'] ?? '';
        $company_tax_id  = $s['invoice_company_tax_id'] ?? '';
        $footer_text     = $s['invoice_footer_text'] ?? '';
        $site_name       = get_bloginfo('name');

        $statuses_map = [
            'completed'  => 'Bezahlt',
            'processing' => 'Bearbeitung',
            'pending'    => 'Ausstehend',
            'on-hold'    => 'Wartend',
            'cancelled'  => 'Storniert',
            'failed'     => 'Fehlgeschlagen',
            'refunded'   => 'Erstattet',
        ];
        $status_label = $statuses_map[$order->status] ?? ucfirst($order->status);

        // Calculate subtotal from items
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += floatval($item->total);
        }

        // Output standalone HTML
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rechnung <?php echo esc_html($order->order_number); ?></title>
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        font-size: 14px;
        line-height: 1.6;
        color: #1a1a1a;
        background: #f3f4f6;
        padding: 40px 20px;
    }
    .invoice {
        max-width: 800px;
        margin: 0 auto;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        padding: 48px;
    }
    .invoice-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 40px;
        padding-bottom: 24px;
        border-bottom: 2px solid #e5e7eb;
    }
    .invoice-company h1 {
        font-size: 22px;
        font-weight: 700;
        color: #111;
        margin-bottom: 6px;
    }
    .invoice-company p {
        font-size: 13px;
        color: #6b7280;
        white-space: pre-line;
        line-height: 1.5;
    }
    .invoice-meta {
        text-align: right;
    }
    .invoice-meta .label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #9ca3af;
        font-weight: 600;
    }
    .invoice-meta .invoice-number {
        font-size: 20px;
        font-weight: 700;
        font-family: 'SF Mono', 'Fira Code', 'Fira Mono', Menlo, Consolas, monospace;
        color: #111;
        margin-bottom: 8px;
    }
    .invoice-meta .invoice-date {
        font-size: 13px;
        color: #6b7280;
    }
    .invoice-meta .invoice-status {
        display: inline-block;
        margin-top: 8px;
        font-size: 12px;
        font-weight: 600;
        padding: 3px 12px;
        border-radius: 20px;
    }
    .status-completed, .status-processing { background: #dcfce7; color: #166534; }
    .status-pending, .status-on-hold { background: #fef9c3; color: #854d0e; }
    .status-cancelled, .status-failed { background: #fef2f2; color: #991b1b; }
    .status-refunded { background: #f3e8ff; color: #6b21a8; }

    .invoice-addresses {
        display: flex;
        justify-content: space-between;
        gap: 40px;
        margin-bottom: 36px;
    }
    .invoice-addresses .addr-block {
        flex: 1;
    }
    .addr-block .addr-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #9ca3af;
        font-weight: 600;
        margin-bottom: 6px;
    }
    .addr-block .addr-name {
        font-weight: 600;
        font-size: 14px;
        color: #111;
    }
    .addr-block p {
        font-size: 13px;
        color: #374151;
        line-height: 1.5;
    }

    table.items {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 24px;
    }
    table.items thead th {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #9ca3af;
        font-weight: 600;
        padding: 10px 0;
        border-bottom: 2px solid #e5e7eb;
        text-align: left;
    }
    table.items thead th.r { text-align: right; }
    table.items thead th.c { text-align: center; }
    table.items tbody td {
        padding: 12px 0;
        border-bottom: 1px solid #f3f4f6;
        font-size: 13px;
        color: #374151;
    }
    table.items tbody td.item-name { font-weight: 500; color: #111; }
    table.items tbody td.item-cat { font-size: 12px; color: #9ca3af; }
    table.items tbody td.c { text-align: center; }
    table.items tbody td.r {
        text-align: right;
        font-family: 'SF Mono', 'Fira Code', 'Fira Mono', Menlo, Consolas, monospace;
        font-size: 13px;
        font-weight: 500;
    }

    .invoice-totals {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 32px;
    }
    .invoice-totals table {
        border-collapse: collapse;
        min-width: 280px;
    }
    .invoice-totals td {
        padding: 6px 0;
        font-size: 13px;
    }
    .invoice-totals .label-col {
        text-align: right;
        padding-right: 24px;
        color: #6b7280;
    }
    .invoice-totals .value-col {
        text-align: right;
        font-family: 'SF Mono', 'Fira Code', 'Fira Mono', Menlo, Consolas, monospace;
        font-weight: 500;
        min-width: 100px;
    }
    .invoice-totals .total-row td {
        padding-top: 10px;
        border-top: 2px solid #111;
        font-size: 16px;
        font-weight: 700;
        color: #111;
    }
    .invoice-totals .discount-row .value-col { color: #22c55e; }

    .invoice-payment {
        background: #f9fafb;
        border-radius: 6px;
        padding: 14px 18px;
        margin-bottom: 32px;
        font-size: 13px;
        color: #374151;
        display: flex;
        gap: 32px;
    }
    .invoice-payment .pay-item .pay-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #9ca3af;
        font-weight: 600;
    }

    .invoice-footer {
        border-top: 1px solid #e5e7eb;
        padding-top: 20px;
        font-size: 12px;
        color: #9ca3af;
        line-height: 1.6;
        text-align: center;
        white-space: pre-line;
    }

    .no-print {
        text-align: center;
        margin-bottom: 20px;
    }
    .no-print button {
        background: #111;
        color: #fff;
        border: none;
        padding: 10px 28px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
    }
    .no-print button:hover { background: #333; }

    @media print {
        body {
            background: #fff;
            padding: 0;
            margin: 0;
        }
        .invoice {
            box-shadow: none;
            border-radius: 0;
            padding: 24px 32px;
            max-width: none;
        }
        .no-print { display: none !important; }
        @page {
            margin: 15mm 10mm;
            size: A4;
        }
    }
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">Rechnung drucken / als PDF speichern</button>
</div>

<div class="invoice">

    <div class="invoice-header">
        <div class="invoice-company">
            <h1><?php echo esc_html($company_name ?: $site_name); ?></h1>
            <?php if ($company_address): ?>
                <p><?php echo esc_html($company_address); ?></p>
            <?php endif; ?>
            <?php if ($company_tax_id): ?>
                <p style="margin-top:4px;">USt-IdNr.: <?php echo esc_html($company_tax_id); ?></p>
            <?php endif; ?>
        </div>
        <div class="invoice-meta">
            <div class="label">Rechnung</div>
            <div class="invoice-number"><?php echo esc_html($order->order_number); ?></div>
            <div class="invoice-date"><?php echo date_i18n('d. F Y', strtotime($order->date_created)); ?></div>
            <div>
                <span class="invoice-status status-<?php echo esc_attr($order->status); ?>">
                    <?php echo esc_html($status_label); ?>
                </span>
            </div>
        </div>
    </div>

    <div class="invoice-addresses">
        <div class="addr-block">
            <div class="addr-label">Rechnungsadresse</div>
            <div class="addr-name"><?php echo esc_html($order->billing_first_name . ' ' . $order->billing_last_name); ?></div>
            <?php if (!empty($order->billing_company)): ?>
                <p><?php echo esc_html($order->billing_company); ?></p>
            <?php endif; ?>
            <?php if (!empty($order->billing_address_1)): ?>
                <p><?php echo esc_html($order->billing_address_1); ?></p>
            <?php endif; ?>
            <?php if (!empty($order->billing_postcode) || !empty($order->billing_city)): ?>
                <p><?php echo esc_html(trim($order->billing_postcode . ' ' . $order->billing_city)); ?></p>
            <?php endif; ?>
            <?php if (!empty($order->billing_country)): ?>
                <p><?php echo esc_html($order->billing_country); ?></p>
            <?php endif; ?>
        </div>
        <div class="addr-block" style="text-align:right;">
            <div class="addr-label">Kontakt</div>
            <p><?php echo esc_html($order->billing_email); ?></p>
            <?php if (!empty($order->billing_phone)): ?>
                <p><?php echo esc_html($order->billing_phone); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:50%;">Artikel</th>
                <th class="c" style="width:80px;">Menge</th>
                <th class="r" style="width:120px;">Einzelpreis</th>
                <th class="r" style="width:120px;">Betrag</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item):
                $unit_price = $item->quantity > 0 ? floatval($item->total) / intval($item->quantity) : floatval($item->total);
            ?>
            <tr>
                <td>
                    <span class="item-name"><?php echo esc_html($item->name); ?></span>
                    <?php if (!empty($item->cat_name)): ?>
                        <br><span class="item-cat"><?php echo esc_html($item->cat_name); ?></span>
                    <?php endif; ?>
                </td>
                <td class="c"><?php echo intval($item->quantity); ?></td>
                <td class="r"><?php echo number_format($unit_price, 2, ',', '.'); ?>&nbsp;&euro;</td>
                <td class="r"><?php echo number_format($item->total, 2, ',', '.'); ?>&nbsp;&euro;</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="invoice-totals">
        <table>
            <tr>
                <td class="label-col">Zwischensumme</td>
                <td class="value-col"><?php echo number_format($subtotal, 2, ',', '.'); ?>&nbsp;&euro;</td>
            </tr>
            <?php if (floatval($order->discount) > 0): ?>
            <tr class="discount-row">
                <td class="label-col">Rabatt</td>
                <td class="value-col">-<?php echo number_format($order->discount, 2, ',', '.'); ?>&nbsp;&euro;</td>
            </tr>
            <?php endif; ?>
            <?php if (floatval($order->tax) > 0): ?>
            <tr>
                <td class="label-col">MwSt.</td>
                <td class="value-col"><?php echo number_format($order->tax, 2, ',', '.'); ?>&nbsp;&euro;</td>
            </tr>
            <?php endif; ?>
            <tr class="total-row">
                <td class="label-col">Gesamt</td>
                <td class="value-col"><?php echo number_format($order->total, 2, ',', '.'); ?>&nbsp;&euro;</td>
            </tr>
        </table>
    </div>

    <div class="invoice-payment">
        <div class="pay-item">
            <div class="pay-label">Zahlungsart</div>
            <div><?php echo esc_html($order->payment_method_title ?: $order->payment_method ?: '—'); ?></div>
        </div>
        <div class="pay-item">
            <div class="pay-label">Status</div>
            <div><?php echo esc_html($status_label); ?></div>
        </div>
        <div class="pay-item">
            <div class="pay-label">Bestelldatum</div>
            <div><?php echo date_i18n('d.m.Y, H:i', strtotime($order->date_created)); ?> Uhr</div>
        </div>
    </div>

    <?php if ($footer_text): ?>
    <div class="invoice-footer"><?php echo esc_html($footer_text); ?></div>
    <?php endif; ?>

</div>

<script>
window.addEventListener('load', function() {
    // Small delay so the page renders fully before print dialog
    setTimeout(function(){ window.print(); }, 400);
});
</script>

</body>
</html>
        <?php
        die();
    }

    // ──────────────────────────────────────────
    // Manuelle Bestellerstellung
    // ──────────────────────────────────────────

    /**
     * AJAX: Ticket-Kategorien eines Events laden.
     */
    public static function ajax_get_categories() {
        check_ajax_referer('tix_manual_order', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id || get_post_type($event_id) !== 'event') {
            wp_send_json_error(['message' => 'Ungültiges Event.']);
        }

        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats) || empty($cats)) {
            wp_send_json_error(['message' => 'Keine Ticket-Kategorien gefunden.']);
        }

        $result = [];
        foreach ($cats as $i => $cat) {
            $price = floatval($cat['sale_price'] ?? 0) > 0 ? floatval($cat['sale_price']) : floatval($cat['price'] ?? 0);
            $stock = isset($cat['stock']) ? intval($cat['stock']) : -1;
            $result[] = [
                'index' => $i,
                'name'  => sanitize_text_field($cat['name'] ?? 'Ticket'),
                'price' => $price,
                'stock' => $stock,
            ];
        }

        wp_send_json_success(['categories' => $result]);
    }

    /**
     * AJAX: Manuelle Bestellung erstellen.
     */
    public static function ajax_create_manual() {
        check_ajax_referer('tix_manual_order', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung.']);
        }

        global $wpdb;

        $event_id   = intval($_POST['event_id'] ?? 0);
        $cat_index  = intval($_POST['cat_index'] ?? 0);
        $qty        = max(1, intval($_POST['qty'] ?? 1));
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name  = sanitize_text_field($_POST['last_name'] ?? '');
        $email      = sanitize_email($_POST['email'] ?? '');
        $phone      = sanitize_text_field($_POST['phone'] ?? '');
        $company    = sanitize_text_field($_POST['company'] ?? '');
        $address_1  = sanitize_text_field($_POST['address_1'] ?? '');
        $postcode   = sanitize_text_field($_POST['postcode'] ?? '');
        $city       = sanitize_text_field($_POST['city'] ?? '');
        $country    = sanitize_text_field($_POST['country'] ?? 'DE');
        $discount   = max(0, min(100, floatval($_POST['discount'] ?? 0)));
        $comment    = sanitize_textarea_field($_POST['comment'] ?? '');

        // Validierung
        if (!$event_id || get_post_type($event_id) !== 'event') {
            wp_send_json_error(['message' => 'Bitte ein Event auswählen.']);
        }
        if (!$first_name) {
            wp_send_json_error(['message' => 'Vorname ist ein Pflichtfeld.']);
        }
        if (!$email || !is_email($email)) {
            wp_send_json_error(['message' => 'Bitte eine gültige E-Mail-Adresse eingeben.']);
        }

        // Ticket-Kategorie laden
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats) || !isset($cats[$cat_index])) {
            wp_send_json_error(['message' => 'Ticket-Kategorie nicht gefunden.']);
        }

        $cat = $cats[$cat_index];
        $unit_price = floatval($cat['sale_price'] ?? 0) > 0 ? floatval($cat['sale_price']) : floatval($cat['price'] ?? 0);
        $cat_name   = sanitize_text_field($cat['name'] ?? 'Ticket');
        $product_id = intval($cat['product_id'] ?? 0);
        $event_title = get_the_title($event_id);

        // Preis berechnen
        $subtotal = round($unit_price * $qty, 2);
        $discount_amount = round($subtotal * $discount / 100, 2);
        $total = round($subtotal - $discount_amount, 2);

        // Steuer berechnen
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $tax_enabled  = !empty($s['tax_enabled']);
        $tax_rate     = floatval($s['tax_rate'] ?? 0);
        $tax_inclusive = !empty($s['tax_inclusive']);
        $tax = 0;

        if ($tax_enabled && $tax_rate > 0) {
            if ($tax_inclusive) {
                $tax = round($total - ($total / (1 + $tax_rate / 100)), 2);
            } else {
                $tax = round($total * $tax_rate / 100, 2);
                $total = $total + $tax;
            }
        }

        // Bestellnummer generieren
        $order_number = TIX_Order::next_order_number();
        $order_key = 'tix_' . wp_generate_password(16, false);

        // Payment method
        $is_free = ($total <= 0);
        $payment_method = $is_free ? 'free' : 'manual';
        $payment_method_title = $is_free ? 'Kostenlos' : 'Manuelle Bestellung';

        $t  = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';

        // WP-User anlegen oder verknüpfen
        $customer_id = 0;
        $existing_user = get_user_by('email', $email);
        if ($existing_user) {
            $customer_id = $existing_user->ID;
            // Userdaten aktualisieren
            wp_update_user([
                'ID'         => $customer_id,
                'first_name' => $first_name,
                'last_name'  => $last_name,
            ]);
        } else {
            // Neuen User erstellen
            $username = sanitize_user(strtolower($first_name . ($last_name ? '.' . $last_name : '')));
            if (username_exists($username)) {
                $username .= '.' . wp_rand(10, 999);
            }
            $password = wp_generate_password(16, true, true);
            $customer_id = wp_create_user($username, $password, $email);
            if (!is_wp_error($customer_id)) {
                wp_update_user([
                    'ID'           => $customer_id,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'display_name' => trim($first_name . ' ' . $last_name),
                    'role'         => 'customer',
                ]);
                // Willkommensmail mit Passwort-Reset-Link
                wp_new_user_notification($customer_id, null, 'user');
            } else {
                $customer_id = 0;
            }
        }

        // Billing-Daten auf WP-User synchronisieren
        if ($customer_id) {
            $billing_meta = [
                'billing_first_name' => $first_name,
                'billing_last_name'  => $last_name,
                'billing_email'      => $email,
                'billing_phone'      => $phone,
                'billing_company'    => $company,
                'billing_address_1'  => $address_1,
                'billing_postcode'   => $postcode,
                'billing_city'       => $city,
                'billing_country'    => $country,
            ];
            foreach ($billing_meta as $meta_key => $meta_value) {
                if ($meta_value !== '') {
                    update_user_meta($customer_id, $meta_key, $meta_value);
                }
            }
        }

        // Order erstellen
        $wpdb->insert($t, [
            'order_number'          => $order_number,
            'status'                => 'completed',
            'total'                 => $total,
            'subtotal'              => round($subtotal - $discount_amount, 2),
            'tax'                   => $tax,
            'discount'              => $discount_amount,
            'payment_method'        => $payment_method,
            'payment_method_title'  => $payment_method_title,
            'billing_first_name'    => $first_name,
            'billing_last_name'     => $last_name,
            'billing_email'         => $email,
            'billing_phone'         => $phone,
            'billing_company'       => $company,
            'billing_address_1'     => $address_1,
            'billing_address_2'     => '',
            'billing_city'          => $city,
            'billing_postcode'      => $postcode,
            'billing_country'       => $country,
            'customer_id'           => $customer_id,
            'wc_order_id'           => 0,
            'order_key'             => $order_key,
            'date_created'          => current_time('mysql'),
        ]);

        $order_id = $wpdb->insert_id;
        if (!$order_id) {
            wp_send_json_error(['message' => 'Fehler beim Erstellen der Bestellung.']);
        }

        // Order Item
        $wpdb->insert($ti, [
            'order_id'   => $order_id,
            'product_id' => $product_id,
            'event_id'   => $event_id,
            'quantity'   => $qty,
            'total'      => round($unit_price * $qty - $discount_amount, 2),
            'tax'        => 0,
            'name'       => $event_title . ' – ' . $cat_name,
            'cat_name'   => $cat_name,
            'meta'       => $discount > 0 ? json_encode(['discount_percent' => $discount]) : null,
        ]);

        // Stock reduzieren
        wp_cache_delete($event_id, 'post_meta');
        $categories = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (is_array($categories) && isset($categories[$cat_index])) {
            $current_stock = isset($categories[$cat_index]['stock']) ? intval($categories[$cat_index]['stock']) : -1;
            if ($current_stock >= 0) {
                $categories[$cat_index]['stock'] = max(0, $current_stock - $qty);
                update_post_meta($event_id, '_tix_ticket_categories', $categories);
            }
        }

        // Notiz hinzufügen
        $user = wp_get_current_user();
        $note_parts = [];
        $note_parts[] = 'Manuelle Bestellung erstellt von ' . ($user->display_name ?: 'Admin');
        if ($discount > 0) {
            $note_parts[] = 'Rabatt: ' . number_format($discount, 0) . '% (' . number_format($discount_amount, 2, ',', '.') . ' €)';
        }
        if ($comment) {
            $note_parts[] = 'Kommentar: ' . $comment;
        }
        self::add_note($order_id, implode("\n", $note_parts), 'internal', $user->display_name ?: 'Admin');

        // Admin-Mail unterdrücken wenn gewünscht
        $skip_admin_mail = intval($_POST['skip_admin_mail'] ?? 0);
        if ($skip_admin_mail) {
            set_transient('_tix_skip_admin_email_' . $order_id, 1, 60);
        }

        // Status-Hook feuern (für Tickets etc.)
        do_action('tix_order_status_changed', $order_id, 'completed', 'pending', $payment_method);
        do_action('tix_order_completed', $order_id);

        wp_send_json_success([
            'message'      => 'Bestellung ' . $order_number . ' wurde erstellt.',
            'order_id'     => $order_id,
            'order_number' => $order_number,
            'redirect'     => admin_url('admin.php?page=tix-orders&order_id=' . $order_id),
        ]);
    }

    /**
     * Formular für manuelle Bestellerstellung.
     */
    private static function render_create_form() {
        $nonce = wp_create_nonce('tix_manual_order');

        // Alle published Events laden
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [[
                'key'     => '_tix_archived',
                'compare' => 'NOT EXISTS',
            ]],
        ]);

        ?>
        <div class="wrap" style="max-width:720px;">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:24px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=tix-orders')); ?>" style="color:#6b7280;text-decoration:none;" title="Zurück">
                    <span class="dashicons dashicons-arrow-left-alt" style="font-size:24px;width:24px;height:24px;"></span>
                </a>
                <span class="dashicons dashicons-plus-alt" style="font-size:28px;width:28px;height:28px;color:var(--tix-primary, #FF5500);"></span>
                Neue Bestellung erstellen
            </h1>

            <div id="tix-create-order-form" style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;">

                <?php // ── Event ── ?>
                <div style="margin-bottom:20px;">
                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Event <span style="color:#ef4444;">*</span></label>
                    <select id="tix-mo-event" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                        <option value="">— Event auswählen —</option>
                        <?php foreach ($events as $ev): ?>
                            <option value="<?php echo $ev->ID; ?>"><?php echo esc_html($ev->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php // ── Ticket-Kategorie (wird per AJAX geladen) ── ?>
                <div style="margin-bottom:20px;">
                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Ticket-Kategorie <span style="color:#ef4444;">*</span></label>
                    <select id="tix-mo-cat" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;" disabled>
                        <option value="">— Erst Event auswählen —</option>
                    </select>
                </div>

                <?php // ── Anzahl ── ?>
                <div style="margin-bottom:20px;">
                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Anzahl</label>
                    <input type="number" id="tix-mo-qty" value="1" min="1" max="100" style="width:100px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;text-align:center;">
                </div>

                <hr style="border:none;border-top:1px solid #f3f4f6;margin:20px 0;">

                <?php // ── Kunde ── ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Vorname <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="tix-mo-fname" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;" placeholder="Max">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Nachname</label>
                        <input type="text" id="tix-mo-lname" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;" placeholder="Mustermann">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">E-Mail <span style="color:#ef4444;">*</span></label>
                        <input type="email" id="tix-mo-email" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;" placeholder="max@beispiel.de">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Telefon</label>
                        <input type="text" id="tix-mo-phone" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;" placeholder="+49 170 1234567">
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Firma</label>
                    <input type="text" id="tix-mo-company" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;" placeholder="Firma (optional)">
                </div>

                <div style="margin-bottom:12px;">
                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Straße / Hausnummer</label>
                    <input type="text" id="tix-mo-address" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;" placeholder="Musterstraße 1">
                </div>

                <div style="display:grid;grid-template-columns:120px 1fr 1fr;gap:12px;margin-bottom:20px;">
                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">PLZ</label>
                        <input type="text" id="tix-mo-postcode" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;" placeholder="12345">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Ort</label>
                        <input type="text" id="tix-mo-city" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;" placeholder="Berlin">
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Land</label>
                        <select id="tix-mo-country" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
                            <option value="DE" selected>Deutschland</option>
                            <option value="AT">Österreich</option>
                            <option value="CH">Schweiz</option>
                            <option value="NL">Niederlande</option>
                            <option value="BE">Belgien</option>
                            <option value="LU">Luxemburg</option>
                            <option value="FR">Frankreich</option>
                            <option value="PL">Polen</option>
                            <option value="DK">Dänemark</option>
                            <option value="CZ">Tschechien</option>
                            <option value="IT">Italien</option>
                            <option value="ES">Spanien</option>
                            <option value="GB">Vereinigtes Königreich</option>
                        </select>
                    </div>
                </div>

                <hr style="border:none;border-top:1px solid #f3f4f6;margin:20px 0;">

                <?php // ── Rabatt + Kommentar ── ?>
                <div style="display:grid;grid-template-columns:140px 1fr;gap:12px;margin-bottom:20px;">
                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Rabatt (%)</label>
                        <div style="position:relative;">
                            <input type="number" id="tix-mo-discount" value="0" min="0" max="100" step="1" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;text-align:center;">
                            <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:14px;pointer-events:none;">%</span>
                        </div>
                    </div>
                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Kommentar / Notiz</label>
                        <textarea id="tix-mo-comment" rows="2" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;resize:vertical;" placeholder="Optionaler Kommentar zur Bestellung…"></textarea>
                    </div>
                </div>

                <?php // ── Preisvorschau ── ?>
                <div id="tix-mo-preview" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:20px;display:none;">
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:#6b7280;margin-bottom:4px;">
                        <span id="tix-mo-prev-line">—</span>
                        <span id="tix-mo-prev-subtotal">0,00 €</span>
                    </div>
                    <div id="tix-mo-prev-discount-row" style="display:none;justify-content:space-between;font-size:13px;color:#22c55e;margin-bottom:4px;">
                        <span>Rabatt</span>
                        <span id="tix-mo-prev-discount">-0,00 €</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:700;border-top:1px solid #e5e7eb;padding-top:8px;margin-top:4px;">
                        <span>Gesamt</span>
                        <span id="tix-mo-prev-total">0,00 €</span>
                    </div>
                </div>

                <?php // ── Admin-Mail Toggle ── ?>
                <div style="margin-bottom:20px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                        <input type="checkbox" id="tix-mo-admin-mail" checked style="width:16px;height:16px;">
                        <span>Admin-Benachrichtigung per E-Mail senden</span>
                    </label>
                </div>

                <?php // ── Submit ── ?>
                <div style="display:flex;gap:12px;align-items:center;">
                    <button type="button" id="tix-mo-submit" class="button button-primary" style="background:var(--tix-primary, #FF5500);border-color:var(--tix-primary, #FF5500);padding:8px 24px;font-size:14px;border-radius:8px;">
                        Bestellung erstellen
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tix-orders')); ?>" class="button" style="padding:8px 16px;font-size:14px;border-radius:8px;">Abbrechen</a>
                    <span id="tix-mo-status" style="font-size:13px;color:#6b7280;"></span>
                </div>
            </div>
        </div>

        <script>
        (function($){
            var nonce = <?php echo json_encode($nonce); ?>;
            var catData = [];

            // ── Event Change → Kategorien laden ──
            $('#tix-mo-event').on('change', function(){
                var eid = $(this).val();
                var catSel = $('#tix-mo-cat');
                catSel.html('<option value="">Laden…</option>').prop('disabled', true);
                catData = [];
                updatePreview();

                if (!eid) {
                    catSel.html('<option value="">— Erst Event auswählen —</option>');
                    return;
                }

                $.post(ajaxurl, {
                    action: 'tix_order_get_categories',
                    nonce: nonce,
                    event_id: eid
                }, function(r){
                    if (!r.success) {
                        catSel.html('<option value="">Keine Kategorien</option>');
                        return;
                    }
                    catData = r.data.categories;
                    var html = '<option value="">— Kategorie wählen —</option>';
                    catData.forEach(function(c){
                        var stockInfo = c.stock >= 0 ? ' (Bestand: ' + c.stock + ')' : '';
                        html += '<option value="' + c.index + '">' + c.name + ' – ' + formatPrice(c.price) + stockInfo + '</option>';
                    });
                    catSel.html(html).prop('disabled', false);
                });
            });

            // ── Preis-Preview aktualisieren ──
            $('#tix-mo-cat, #tix-mo-qty, #tix-mo-discount').on('change input', updatePreview);

            function updatePreview() {
                var catIdx = parseInt($('#tix-mo-cat').val());
                var qty = parseInt($('#tix-mo-qty').val()) || 1;
                var disc = parseFloat($('#tix-mo-discount').val()) || 0;
                var preview = $('#tix-mo-preview');

                if (isNaN(catIdx)) {
                    preview.hide();
                    return;
                }

                var cat = null;
                catData.forEach(function(c){ if (c.index === catIdx) cat = c; });
                if (!cat) { preview.hide(); return; }

                var subtotal = cat.price * qty;
                var discAmount = Math.round(subtotal * disc / 100 * 100) / 100;
                var total = Math.round((subtotal - discAmount) * 100) / 100;

                $('#tix-mo-prev-line').text(qty + '× ' + cat.name + ' à ' + formatPrice(cat.price));
                $('#tix-mo-prev-subtotal').text(formatPrice(subtotal));

                if (discAmount > 0) {
                    $('#tix-mo-prev-discount').text('-' + formatPrice(discAmount));
                    $('#tix-mo-prev-discount-row').css('display', 'flex');
                } else {
                    $('#tix-mo-prev-discount-row').hide();
                }

                $('#tix-mo-prev-total').text(formatPrice(total));
                preview.show();
            }

            function formatPrice(v) {
                return v.toFixed(2).replace('.', ',') + ' €';
            }

            // ── Submit ──
            $('#tix-mo-submit').on('click', function(){
                var btn = $(this);
                var status = $('#tix-mo-status');

                btn.prop('disabled', true).text('Erstelle…');
                status.text('').css('color', '#6b7280');

                $.post(ajaxurl, {
                    action: 'tix_order_create_manual',
                    nonce: nonce,
                    event_id:   $('#tix-mo-event').val(),
                    cat_index:  $('#tix-mo-cat').val(),
                    qty:        $('#tix-mo-qty').val(),
                    first_name: $('#tix-mo-fname').val(),
                    last_name:  $('#tix-mo-lname').val(),
                    email:      $('#tix-mo-email').val(),
                    phone:      $('#tix-mo-phone').val(),
                    company:    $('#tix-mo-company').val(),
                    address_1:  $('#tix-mo-address').val(),
                    postcode:   $('#tix-mo-postcode').val(),
                    city:       $('#tix-mo-city').val(),
                    country:    $('#tix-mo-country').val(),
                    discount:   $('#tix-mo-discount').val(),
                    comment:    $('#tix-mo-comment').val(),
                    skip_admin_mail: $('#tix-mo-admin-mail').is(':checked') ? 0 : 1
                }, function(r){
                    if (r.success) {
                        status.text(r.data.message).css('color', '#22c55e');
                        setTimeout(function(){ window.location = r.data.redirect; }, 600);
                    } else {
                        status.text(r.data.message || 'Fehler').css('color', '#ef4444');
                        btn.prop('disabled', false).text('Bestellung erstellen');
                    }
                }).fail(function(){
                    status.text('Netzwerkfehler').css('color', '#ef4444');
                    btn.prop('disabled', false).text('Bestellung erstellen');
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}
