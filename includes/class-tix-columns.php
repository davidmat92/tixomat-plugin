<?php
if (!defined('ABSPATH')) exit;

class TIX_Columns {

    public static function add($columns) {
        $new = [];
        foreach ($columns as $key => $val) {
            $new[$key] = $val;
            if ($key === 'title') {
                $new['tix_date']     = 'Zeitraum';
                $new['tix_location'] = 'Veranstaltungsort';
                $new['tix_status']   = 'Status';
                $new['tix_tickets']  = 'Tickets';
                $new['tix_stock']    = 'Stock';
                $new['tix_presale']  = 'Vorverkauf';
                if (tix_get_settings('ai_guard_enabled')) {
                    $new['tix_ai'] = '<span title="KI-Schutz" class="dashicons dashicons-shield" style="font-size:16px;width:16px;"></span>';
                }
            }
        }
        return $new;
    }

    public static function render($column, $post_id) {
        switch ($column) {

            case 'tix_date':
                $ds = get_post_meta($post_id, '_tix_date_start', true);
                $de = get_post_meta($post_id, '_tix_date_end', true);
                $ts = get_post_meta($post_id, '_tix_time_start', true);
                $te = get_post_meta($post_id, '_tix_time_end', true);
                $td = get_post_meta($post_id, '_tix_time_doors', true);

                // Serien-Badge
                $is_master = get_post_meta($post_id, '_tix_series_enabled', true) === '1';
                if ($is_master) {
                    $children = get_post_meta($post_id, '_tix_series_children', true) ?: [];
                    $cnt = count($children);
                    echo '<span style="display:inline-block;padding:1px 8px;border-radius:4px;font-size:11px;font-weight:600;color:#1e40af;background:#dbeafe;margin-bottom:4px;">Serie (' . $cnt . ')</span><br>';
                }

                if (!$ds) { echo '—'; break; }

                // Heute / Morgen Prefix
                $today    = wp_date('Y-m-d');
                $tomorrow = wp_date('Y-m-d', strtotime('+1 day'));
                $prefix   = '';
                if ($ds === $today)         $prefix = '<strong>Heute</strong>, ';
                elseif ($ds === $tomorrow)  $prefix = '<strong>Morgen</strong>, ';

                $same_day = ($ds === $de);
                if ($same_day) {
                    echo $prefix . esc_html(date_i18n('d.m.Y', strtotime($ds)));
                    echo '<br><span class="tix-col-time">' . esc_html("{$ts} – {$te}") . '</span>';
                } else {
                    echo $prefix . esc_html(date_i18n('d.m.Y', strtotime($ds)) . ' ' . $ts);
                    echo '<br>– ' . esc_html(date_i18n('d.m.Y', strtotime($de)) . ' ' . $te);
                }
                if ($td) {
                    echo '<br><span class="tix-col-time">Doors: ' . esc_html($td) . '</span>';
                }
                break;

            case 'tix_status':
                $status = get_post_meta($post_id, '_tix_status', true);
                $label  = get_post_meta($post_id, '_tix_status_label', true);
                if (!$status || !$label) { echo '—'; break; }
                $colors = [
                    'available'   => '#2e7d32',
                    'few_tickets' => '#e65100',
                    'sold_out'    => '#b32d2e',
                    'cancelled'   => '#616161',
                    'postponed'   => '#7b1fa2',
                    'past'        => '#9e9e9e',
                ];
                $c = $colors[$status] ?? '#666';
                $manual = get_post_meta($post_id, '_tix_event_status', true);
                $auto_hint = $manual ? '' : ' <span class="tix-col-time">(auto)</span>';
                echo "<span style=\"color:{$c};font-weight:600\">● " . esc_html($label) . "</span>{$auto_hint}";
                break;

            case 'tix_location':
                echo esc_html(get_post_meta($post_id, '_tix_location', true) ?: '—');
                break;

            case 'tix_tickets':
                $enabled = get_post_meta($post_id, '_tix_tickets_enabled', true);
                if ($enabled !== '1') {
                    echo '<span style="opacity:0.4">Keine Tickets</span>';
                    break;
                }
                $cats = get_post_meta($post_id, '_tix_ticket_categories', true);
                if (is_array($cats) && !empty($cats)) {
                    $online  = array_filter($cats, fn($c) => ($c['online'] ?? '1') === '1');
                    $offline = count($cats) - count($online);
                    echo esc_html(implode(', ', array_column($cats, 'name')));
                    if ($offline > 0) {
                        echo '<br><span class="tix-col-time">' . $offline . ' nur Abendkasse</span>';
                    }
                } else {
                    echo '—';
                }
                break;

            case 'tix_stock':
                $enabled = get_post_meta($post_id, '_tix_tickets_enabled', true);
                if ($enabled !== '1') { echo '—'; break; }

                $cats = get_post_meta($post_id, '_tix_ticket_categories', true);
                if (!is_array($cats)) { echo '—'; break; }

                $total_sold = 0; $total_cap = 0;
                foreach ($cats as $cat) {
                    if (!empty($cat['offline_ticket'])) continue; // Abendkasse überspringen
                    if (($cat['online'] ?? '1') !== '1') continue; // Deaktivierte überspringen

                    $pid = intval($cat['product_id'] ?? 0);
                    $cap = intval($cat['qty'] ?? 0);
                    $total_cap += $cap;

                    if (!$pid) continue;
                    $product = wc_get_product($pid);
                    if (!$product) continue;

                    // Methode 1: Stock-basiert (cap - remaining)
                    $stk = $product->get_stock_quantity();
                    if ($stk !== null && $product->get_manage_stock()) {
                        $sold = max(0, $cap - $stk);
                        $total_sold += $sold;
                    } else {
                        // Methode 2: Fallback – WC Order Items zählen
                        $sold = self::count_sold_for_product($pid);
                        $total_sold += $sold;
                    }
                }
                if ($total_cap > 0) {
                    $pct = round(($total_sold / $total_cap) * 100);
                    echo "<strong>{$total_sold}</strong>/{$total_cap}";
                    echo '<br><span class="tix-col-time">' . $pct . '% verkauft</span>';
                } else {
                    echo '—';
                }
                break;

            case 'tix_presale':
                $enabled = get_post_meta($post_id, '_tix_tickets_enabled', true);
                if ($enabled !== '1') { echo '—'; break; }

                $presale = get_post_meta($post_id, '_tix_presale_active', true);
                if ($presale === '1') {
                    echo '<span style="color:#2e7d32;font-weight:600">● Aktiv</span>';
                } else {
                    echo '<span style="color:#b32d2e;font-weight:600">● Beendet</span>';
                }

                // Presale-Ende anzeigen
                $mode = get_post_meta($post_id, '_tix_presale_end_mode', true) ?: 'manual';
                if ($mode !== 'manual') {
                    $computed = get_post_meta($post_id, '_tix_presale_end_computed', true);
                    if ($computed) {
                        $end_label = date_i18n('d.m.Y H:i', strtotime($computed));
                        $mode_label = $mode === 'before_event' ? 'auto' : 'fest';
                        echo '<br><span class="tix-col-time">Ende: ' . esc_html($end_label) . ' (' . $mode_label . ')</span>';
                    }
                }
                break;

            case 'tix_ai':
                $flagged  = get_post_meta($post_id, '_tix_ai_flagged', true);
                $approved = get_post_meta($post_id, '_tix_ai_approved', true);
                if ($flagged) {
                    $reason = esc_attr(get_post_meta($post_id, '_tix_ai_flag_reason', true) ?: 'Inhalt markiert');
                    echo '<span title="' . $reason . '" style="color:#ef4444;cursor:help;font-size:14px;">⚠️</span>';
                } elseif ($approved) {
                    echo '<span title="KI-geprüft" style="color:#22c55e;font-size:14px;">✓</span>';
                } else {
                    echo '<span style="color:#94a3b8;">—</span>';
                }
                break;
        }
    }

    /**
     * Verkaufte Menge für ein Produkt aus WC-Bestellungen zählen (Fallback)
     */
    private static function count_sold_for_product($product_id) {
        global $wpdb;

        // HPOS-kompatibel: Prüfe ob Custom Order Tables aktiv
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $sold = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(oim.meta_value), 0)
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oip ON oi.order_item_id = oip.order_item_id AND oip.meta_key = '_product_id' AND oip.meta_value = %d
                 INNER JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id AND o.status IN ('wc-completed','wc-processing','wc-on-hold')
                 WHERE oi.order_item_type = 'line_item'",
                $product_id
            ));
        } else {
            $sold = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(oim.meta_value), 0)
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oip ON oi.order_item_id = oip.order_item_id AND oip.meta_key = '_product_id' AND oip.meta_value = %d
                 INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID AND p.post_status IN ('wc-completed','wc-processing','wc-on-hold')
                 WHERE oi.order_item_type = 'line_item'",
                $product_id
            ));
        }
        return intval($sold);
    }

    // ══════════════════════════════════════
    // NEWSLETTER-SUBSCRIBER SPALTEN
    // ══════════════════════════════════════

    public static function add_subscriber_columns($columns) {
        return [
            'cb'               => $columns['cb'] ?? '<input type="checkbox" />',
            'title'            => 'Kontakt',
            'tix_sub_type'      => 'Typ',
            'tix_sub_name'      => 'Name',
            'tix_sub_address'   => 'Adresse',
            'tix_sub_purchases' => 'Käufe',
            'tix_sub_date'      => 'Anmeldung',
        ];
    }

    public static function render_subscriber_column($column, $post_id) {
        switch ($column) {
            case 'tix_sub_type':
                $type = get_post_meta($post_id, '_tix_sub_type', true);
                echo $type === 'whatsapp' ? '📱 WhatsApp' : '✉️ E-Mail';
                break;
            case 'tix_sub_name':
                echo esc_html(get_post_meta($post_id, '_tix_sub_name', true) ?: '—');
                break;
            case 'tix_sub_address':
                echo esc_html(get_post_meta($post_id, '_tix_sub_address', true) ?: '—');
                break;
            case 'tix_sub_purchases':
                $purchases = get_post_meta($post_id, '_tix_sub_purchases', true);
                if (!empty($purchases) && is_array($purchases)) {
                    $parts = [];
                    foreach ($purchases as $p) {
                        $items_str = implode(', ', $p['items'] ?? []);
                        $parts[] = '#' . $p['order_id'] . ': ' . $items_str . ' (' . $p['total'] . '€)';
                    }
                    echo '<span style="font-size:12px;line-height:1.4;">' . implode('<br>', array_map('esc_html', $parts)) . '</span>';
                } else {
                    // Fallback: alte _tix_sub_order Daten
                    $order_id = get_post_meta($post_id, '_tix_sub_order', true);
                    echo $order_id ? '#' . esc_html($order_id) : '—';
                }
                break;
            case 'tix_sub_date':
                $date = get_post_meta($post_id, '_tix_sub_date', true);
                if ($date) {
                    echo esc_html(date_i18n('d.m.Y H:i', strtotime($date)));
                } else {
                    echo '—';
                }
                break;
        }
    }

    // ══════════════════════════════════════
    // NEWSLETTER CSV-EXPORT
    // ══════════════════════════════════════

    public static function subscriber_csv_button($post_type) {
        if ($post_type !== 'tix_subscriber') return;
        echo '<a href="' . esc_url(admin_url('admin-post.php?action=tix_export_subscribers')) . '" class="button" style="margin-left:8px;">CSV exportieren</a>';
    }

    public static function subscriber_csv_export() {
        if (empty($_GET['action']) || $_GET['action'] !== 'tix_export_subscribers') return;
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');

        $subscribers = get_posts([
            'post_type'   => 'tix_subscriber',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=newsletter-abonnenten-' . date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // BOM für Excel UTF-8
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Typ', 'Kontakt', 'Name', 'Adresse', 'Käufe', 'Datum'], ';');

        foreach ($subscribers as $sub) {
            $purchases = get_post_meta($sub->ID, '_tix_sub_purchases', true);
            $purchases_str = '';
            if (!empty($purchases) && is_array($purchases)) {
                $parts = [];
                foreach ($purchases as $p) {
                    $parts[] = '#' . $p['order_id'] . ': ' . implode(', ', $p['items'] ?? []) . ' (' . $p['total'] . '€)';
                }
                $purchases_str = implode(' | ', $parts);
            } else {
                $order_id = get_post_meta($sub->ID, '_tix_sub_order', true);
                if ($order_id) $purchases_str = '#' . $order_id;
            }

            fputcsv($out, [
                get_post_meta($sub->ID, '_tix_sub_type', true) === 'whatsapp' ? 'WhatsApp' : 'E-Mail',
                get_post_meta($sub->ID, '_tix_sub_contact', true),
                get_post_meta($sub->ID, '_tix_sub_name', true),
                get_post_meta($sub->ID, '_tix_sub_address', true),
                $purchases_str,
                get_post_meta($sub->ID, '_tix_sub_date', true),
            ], ';');
        }
        fclose($out);
        exit;
    }

    // ══════════════════════════════════════════════
    // ABANDONED CART SPALTEN
    // ══════════════════════════════════════════════

    public static function add_abandoned_cart_columns($columns) {
        $new = [];
        foreach ($columns as $key => $val) {
            if ($key === 'title') {
                $new[$key] = 'E-Mail';
            } else {
                $new[$key] = $val;
            }
            if ($key === 'title') {
                $new['tix_ac_event']  = 'Event';
                $new['tix_ac_status'] = 'Status';
                $new['tix_ac_date']   = 'Erstellt';
                $new['tix_ac_sent']   = 'Gesendet';
            }
        }
        return $new;
    }

    public static function render_abandoned_cart_column($column, $post_id) {
        switch ($column) {
            case 'tix_ac_event':
                $eid = intval(get_post_meta($post_id, '_tix_ac_event_id', true));
                if ($eid) {
                    $ev = get_post($eid);
                    echo $ev ? '<a href="' . get_edit_post_link($eid) . '">' . esc_html($ev->post_title) . '</a>' : '—';
                } else {
                    echo '—';
                }
                break;

            case 'tix_ac_status':
                $status = get_post_meta($post_id, '_tix_ac_status', true) ?: 'pending';
                $labels = [
                    'pending'   => ['Ausstehend', '#f59e0b'],
                    'sent'      => ['Gesendet', '#3b82f6'],
                    'recovered' => ['Wiederhergestellt', '#22c55e'],
                    'expired'   => ['Abgelaufen', '#9ca3af'],
                ];
                $l = $labels[$status] ?? ['Unbekannt', '#6b7280'];
                echo '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;color:#fff;background:' . $l[1] . ';">' . esc_html($l[0]) . '</span>';
                break;

            case 'tix_ac_date':
                $date = get_the_date('d.m.Y H:i', $post_id);
                echo esc_html($date);
                break;

            case 'tix_ac_sent':
                $sent = get_post_meta($post_id, '_tix_ac_sent_at', true);
                if ($sent) {
                    echo esc_html(date_i18n('d.m.Y H:i', strtotime($sent)));
                } else {
                    echo '—';
                }
                break;
        }
    }

    // ══════════════════════════════════════════════
    // SERIENTERMINE — Filter & Badges
    // ══════════════════════════════════════════════

    public static function series_filter($post_type) {
        if ($post_type !== 'event') return;
        $current = $_GET['tix_series_filter'] ?? '';
        ?>
        <select name="tix_series_filter">
            <option value="">Alle Events</option>
            <option value="single" <?php selected($current, 'single'); ?>>Einzeltermine</option>
            <option value="master" <?php selected($current, 'master'); ?>>Serien-Master</option>
            <option value="children" <?php selected($current, 'children'); ?>>Serientermine</option>
        </select>
        <?php
        // Orphan-Cleanup Button (nur für Admins)
        if (current_user_can('manage_options')) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=tix_cleanup_orphans'), 'tix_cleanup_orphans');
            printf(
                '<a href="%s" class="button" style="margin-left:4px;" onclick="return confirm(\'Verwaiste Einträge (Events, Produkte, TC-Events, API-Keys) endgültig löschen?\')">Orphan-Cleanup</a>',
                esc_url($url)
            );
        }
    }

    public static function filter_series_query($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'event') return;

        $filter = $_GET['tix_series_filter'] ?? '';
        $parent = $_GET['tix_series_parent'] ?? '';

        $mq = $query->get('meta_query') ?: [];

        // Kinder eines bestimmten Masters anzeigen
        if ($parent) {
            $mq[] = ['key' => '_tix_series_parent', 'value' => intval($parent)];
            $query->set('meta_query', $mq);
            return;
        }

        switch ($filter) {
            case 'single':
                $mq[] = [
                    'relation' => 'AND',
                    ['key' => '_tix_series_enabled', 'compare' => 'NOT EXISTS'],
                    ['key' => '_tix_series_parent', 'compare' => 'NOT EXISTS'],
                ];
                break;
            case 'master':
                $mq[] = ['key' => '_tix_series_enabled', 'value' => '1'];
                break;
            case 'children':
                $mq[] = ['key' => '_tix_series_parent', 'compare' => 'EXISTS'];
                break;
            default:
                // Standard: Kinder ausblenden
                $mq[] = ['key' => '_tix_series_parent', 'compare' => 'NOT EXISTS'];
                break;
        }

        $query->set('meta_query', $mq);
    }

    // ══════════════════════════════════════════════
    // EVENT DUPLIZIEREN
    // ══════════════════════════════════════════════

    public static function duplicate_link($actions, $post) {
        if ($post->post_type !== 'event' || !current_user_can('edit_posts')) return $actions;
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=tix_duplicate_event&post=' . $post->ID),
            'tix_duplicate_' . $post->ID
        );
        $actions['tix_duplicate'] = '<a href="' . esc_url($url) . '" title="Event duplizieren">Duplizieren</a>';

        // Serien-Row-Actions
        if (get_post_meta($post->ID, '_tix_series_enabled', true) === '1') {
            $url = admin_url('edit.php?post_type=event&tix_series_parent=' . $post->ID);
            $actions['tix_series_children'] = '<a href="' . esc_url($url) . '">Termine anzeigen</a>';
        }
        $parent = get_post_meta($post->ID, '_tix_series_parent', true);
        if ($parent) {
            $actions['tix_series_master'] = '<a href="' . esc_url(get_edit_post_link($parent)) . '">Master bearbeiten</a>';
        }

        // Endgültig löschen (rotes X)
        if (current_user_can('delete_posts')) {
            $delete_url = wp_nonce_url(
                admin_url('admin-post.php?action=tix_force_delete_event&post=' . $post->ID),
                'tix_force_delete_' . $post->ID
            );
            $is_master = get_post_meta($post->ID, '_tix_series_enabled', true) === '1';
            $confirm_msg = $is_master
                ? 'Serien-Master und ALLE Serientermine endgültig löschen? Inkl. aller WC-Produkte. Dies kann nicht rückgängig gemacht werden.'
                : 'Event endgültig löschen inkl. aller WC-Produkte? Dies kann nicht rückgängig gemacht werden.';
            $actions['tix_force_delete'] = '<a href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js($confirm_msg) . '\')" style="color:#b32d2e;">✕ Endgültig löschen</a>';
        }

        return $actions;
    }

    // ══════════════════════════════════════════════
    // ENDGÜLTIG LÖSCHEN (Force Delete)
    // ══════════════════════════════════════════════

    public static function handle_force_delete() {
        $post_id = intval($_GET['post'] ?? 0);
        if (!$post_id) wp_die('Ungültig.');
        check_admin_referer('tix_force_delete_' . $post_id);
        if (!current_user_can('delete_posts')) wp_die('Keine Berechtigung.');

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'event') wp_die('Event nicht gefunden.');

        // ALLE Hooks deaktivieren — komplette Kontrolle über die Löschung
        remove_action('wp_trash_post',      ['TIX_Cleanup', 'delete_generated']);
        remove_action('before_delete_post',  ['TIX_Cleanup', 'delete_generated']);
        remove_action('wp_trash_post',      ['TIX_Cleanup', 'protect_product']);
        remove_action('before_delete_post',  ['TIX_Cleanup', 'protect_product']);
        remove_action('wp_trash_post',      ['TIX_Series', 'on_trash']);
        remove_action('before_delete_post', ['TIX_Series', 'on_trash']);
        remove_action('save_post_event',    ['TIX_Metabox', 'save'], 10);
        remove_action('save_post_event',    ['TIX_Sync', 'sync'], 20);
        remove_action('save_post_event',    ['TIX_Series', 'on_save'], 25);

        $deleted_children = 0;
        $deleted_products = 0;

        // Serien-Master: alle Kinder zuerst löschen
        $children = get_post_meta($post_id, '_tix_series_children', true);
        if (is_array($children) && !empty($children)) {
            foreach ($children as $child_id) {
                $d = self::delete_event_artifacts(intval($child_id));
                $deleted_products += $d['products'];
                wp_delete_post(intval($child_id), true);
                $deleted_children++;
            }
        }

        // Serien-Kind: aus Parent-Array entfernen
        $parent_id = get_post_meta($post_id, '_tix_series_parent', true);
        if ($parent_id) {
            $parent_children = get_post_meta(intval($parent_id), '_tix_series_children', true);
            if (is_array($parent_children)) {
                $parent_children = array_values(array_filter($parent_children, fn($c) => intval($c) !== $post_id));
                update_post_meta(intval($parent_id), '_tix_series_children', $parent_children);
            }
        }

        // Dieses Event: Artefakte löschen
        $d = self::delete_event_artifacts($post_id);
        $deleted_products += $d['products'];

        // Event endgültig löschen
        wp_delete_post($post_id, true);

        // Automatischer Orphan-Cleanup nach Force Delete
        $orphans = TIX_Cleanup::run_cleanup();

        // Ergebnis für Admin-Notice speichern
        set_transient('tix_force_delete_result_' . get_current_user_id(), [
            'children' => $deleted_children,
            'products' => $deleted_products + ($orphans['products'] ?? 0),
            'orphan_events' => $orphans['events'] ?? 0,
        ], 30);

        wp_safe_redirect(admin_url('edit.php?post_type=event&tix_force_deleted=1'));
        exit;
    }

    /**
     * Alle WC-Produkte + verknüpfte Daten eines Events löschen
     */
    private static function delete_event_artifacts($event_id) {
        $deleted_products = 0;

        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (is_array($cats)) {
            foreach ($cats as $cat) {
                if (!empty($cat['product_id']) && function_exists('wc_get_product')) {
                    $product = wc_get_product(intval($cat['product_id']));
                    if ($product) {
                        $product->delete(true);
                        $deleted_products++;
                    }
                }
            }
        }

        // Custom Tables + verknüpfte CPTs + Crons + Transients löschen
        TIX_Cleanup::purge_event_data($event_id);

        return ['products' => $deleted_products];
    }

    /**
     * Admin-Notice nach Force Delete
     */
    public static function force_delete_notice() {
        if (!isset($_GET['tix_force_deleted'])) return;

        $data = get_transient('tix_force_delete_result_' . get_current_user_id());
        if (!$data) return;
        delete_transient('tix_force_delete_result_' . get_current_user_id());

        $parts = [];
        if (($data['children'] ?? 0) > 0)      $parts[] = $data['children'] . ' Serientermine';
        if (($data['products'] ?? 0) > 0)       $parts[] = $data['products'] . ' WC-Produkte';
        if (($data['orphan_events'] ?? 0) > 0)  $parts[] = $data['orphan_events'] . ' verwaiste Events';

        $msg = 'Event endgültig gelöscht.';
        if (!empty($parts)) $msg .= ' Mitgelöscht: ' . implode(', ', $parts) . '.';

        printf(
            '<div class="notice notice-success is-dismissible"><p><strong>Tixomat:</strong> %s</p></div>',
            esc_html($msg)
        );
    }

    // ══════════════════════════════════════════════
    // EVENT DUPLIZIEREN
    // ══════════════════════════════════════════════

    public static function handle_duplicate() {
        $post_id = intval($_GET['post'] ?? 0);
        if (!$post_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tix_duplicate_' . $post_id)) wp_die('Ungültig.');
        if (!current_user_can('edit_posts')) wp_die('Keine Berechtigung.');

        $original = get_post($post_id);
        if (!$original || $original->post_type !== 'event') wp_die('Event nicht gefunden.');

        // Neuen Post als Entwurf erstellen
        $new_id = wp_insert_post([
            'post_type'    => 'event',
            'post_title'   => $original->post_title . ' (Kopie)',
            'post_content' => $original->post_content,
            'post_status'  => 'draft',
            'post_author'  => get_current_user_id(),
        ]);

        if (is_wp_error($new_id)) wp_die('Fehler beim Erstellen.');

        // Alle Meta kopieren AUSSER Sync-IDs, API-Key und Serien-Links
        $skip = ['_tix_api_key', '_tix_tc_event_id', '_tix_product_ids', '_tix_synced_at',
                 '_tix_series_children', '_tix_series_parent', '_tix_series_index', '_tix_series_detached'];
        $meta = get_post_meta($post_id);
        foreach ($meta as $key => $values) {
            if (in_array($key, $skip)) continue;
            // Ticket-Kategorien: product_id und tc_event_id zurücksetzen
            if ($key === '_tix_ticket_categories') {
                $cats = maybe_unserialize($values[0]);
                if (is_array($cats)) {
                    foreach ($cats as &$cat) {
                        $cat['product_id']  = '';
                        $cat['tc_event_id'] = '';
                        $cat['sku']         = '';
                    }
                    unset($cat);
                    add_post_meta($new_id, $key, $cats);
                }
                continue;
            }
            // Guest list nicht kopieren
            if ($key === '_tix_guest_list') continue;
            foreach ($values as $v) {
                add_post_meta($new_id, $key, maybe_unserialize($v));
            }
        }

        // Taxonomien kopieren
        $terms = wp_get_object_terms($post_id, 'event_category', ['fields' => 'ids']);
        if (!empty($terms) && !is_wp_error($terms)) {
            wp_set_object_terms($new_id, $terms, 'event_category');
        }

        // Beitragsbild kopieren
        $thumb = get_post_thumbnail_id($post_id);
        if ($thumb) set_post_thumbnail($new_id, $thumb);

        wp_redirect(admin_url('post.php?action=edit&post=' . $new_id));
        exit;
    }

    // ══════════════════════════════════════
    // TICKET-SPALTEN (Verkaufte Tickets)
    // ══════════════════════════════════════

    public static function add_ticket_columns($columns) {
        $new = [];
        foreach ($columns as $key => $val) {
            if ($key === 'title') {
                $new[$key] = 'Ticket-Code';
            } elseif ($key === 'date') {
                // Datum am Ende, davor unsere Spalten
                $new['tix_t_event']  = 'Event';
                $new['tix_t_owner']  = 'Käufer';
                $new['tix_t_cat']    = 'Kategorie';
                $new['tix_t_seat']   = 'Sitzplatz';
                $new['tix_t_order']  = 'Bestellung';
                $new['tix_t_status'] = 'Status';
                $new[$key] = $val;
            } else {
                $new[$key] = $val;
            }
        }
        return $new;
    }

    public static function render_ticket_column($column, $post_id) {
        switch ($column) {

            case 'tix_t_event':
                $event_id = intval(get_post_meta($post_id, '_tix_ticket_event_id', true));
                if ($event_id) {
                    $event = get_post($event_id);
                    if ($event) {
                        echo '<a href="' . get_edit_post_link($event_id) . '">' . esc_html($event->post_title) . '</a>';
                    } else {
                        echo '<span style="color:#94a3b8">#' . $event_id . ' (gelöscht)</span>';
                    }
                } else {
                    echo '—';
                }
                break;

            case 'tix_t_owner':
                $name  = get_post_meta($post_id, '_tix_ticket_owner_name', true);
                $email = get_post_meta($post_id, '_tix_ticket_owner_email', true);
                if ($name) {
                    echo '<strong>' . esc_html($name) . '</strong>';
                    if ($email) echo '<br><span style="color:#64748b;font-size:12px;">' . esc_html($email) . '</span>';
                } elseif ($email) {
                    echo esc_html($email);
                } else {
                    echo '—';
                }
                break;

            case 'tix_t_cat':
                $event_id  = intval(get_post_meta($post_id, '_tix_ticket_event_id', true));
                $cat_index = intval(get_post_meta($post_id, '_tix_ticket_cat_index', true));
                $cats      = get_post_meta($event_id, '_tix_ticket_categories', true);
                if (is_array($cats) && isset($cats[$cat_index])) {
                    echo esc_html($cats[$cat_index]['name'] ?? '—');
                } else {
                    echo '—';
                }
                break;

            case 'tix_t_seat':
                $seat = get_post_meta($post_id, '_tix_ticket_seat_id', true);
                if ($seat) {
                    // Format: "section_1_A5" → "A5"
                    $parts = explode('_', $seat);
                    $last  = end($parts);
                    echo '<span style="background:rgba(255,85,0,0.1);color:' . tix_primary() . ';padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;">' . esc_html($last) . '</span>';
                } else {
                    echo '<span style="color:#cbd5e1">—</span>';
                }
                break;

            case 'tix_t_order':
                $order_id = intval(get_post_meta($post_id, '_tix_ticket_order_id', true));
                if ($order_id) {
                    $order_url = admin_url('post.php?post=' . $order_id . '&action=edit');
                    echo '<a href="' . esc_url($order_url) . '">#' . $order_id . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'tix_t_status':
                $status = get_post_meta($post_id, '_tix_ticket_status', true) ?: 'valid';
                $labels = [
                    'valid'     => ['✓ Gültig',     '#10b981', 'rgba(16,185,129,0.1)'],
                    'used'      => ['✓ Eingelöst',  '' . tix_primary() . '', 'rgba(255,85,0,0.1)'],
                    'cancelled' => ['✕ Storniert',  '#ef4444', 'rgba(239,68,68,0.1)'],
                ];
                $l = $labels[$status] ?? ['? ' . $status, '#64748b', 'rgba(100,116,139,0.1)'];
                echo '<span style="background:' . $l[2] . ';color:' . $l[1] . ';padding:3px 10px;border-radius:10px;font-size:12px;font-weight:600;white-space:nowrap;">' . esc_html($l[0]) . '</span>';
                break;
        }
    }

    /**
     * Ticket-Liste: Filter-Dropdowns (Event, Status, Kategorie) + Export-Button
     */
    public static function ticket_event_filter() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'tix_ticket') return;

        $selected = intval($_GET['tix_filter_event'] ?? 0);
        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        echo '<select name="tix_filter_event">';
        echo '<option value="">Alle Events</option>';
        foreach ($events as $ev) {
            $sel = selected($selected, $ev->ID, false);
            echo '<option value="' . $ev->ID . '" ' . $sel . '>' . esc_html($ev->post_title) . '</option>';
        }
        echo '</select>';

        // Status-Filter
        $status_sel = sanitize_text_field($_GET['tix_filter_status'] ?? '');
        echo '<select name="tix_filter_status">';
        echo '<option value="">Alle Status</option>';
        echo '<option value="valid" ' . selected($status_sel, 'valid', false) . '>Gültig</option>';
        echo '<option value="used" ' . selected($status_sel, 'used', false) . '>Eingelöst</option>';
        echo '<option value="cancelled" ' . selected($status_sel, 'cancelled', false) . '>Storniert</option>';
        echo '</select>';

        // Kategorie-Filter (nur wenn Event gewählt)
        if ($selected) {
            $cats = get_post_meta($selected, '_tix_ticket_categories', true);
            $cat_sel = sanitize_text_field($_GET['tix_filter_cat'] ?? '');
            if (is_array($cats) && !empty($cats)) {
                echo '<select name="tix_filter_cat">';
                echo '<option value="">Alle Kategorien</option>';
                foreach ($cats as $i => $cat) {
                    $sel = selected($cat_sel, (string) $i, false);
                    echo '<option value="' . $i . '" ' . $sel . '>' . esc_html($cat['name'] ?? 'Kat. ' . $i) . '</option>';
                }
                echo '</select>';
            }

            // CSV-Export Button
            $export_url = wp_nonce_url(
                admin_url('admin-post.php?action=tix_export_tickets_csv&event_id=' . $selected),
                'tix_export_tickets'
            );
            echo ' <a href="' . esc_url($export_url) . '" class="button tix-export-btn">&#x1F4E5; Teilnehmerliste CSV</a>';
        }
    }

    /**
     * Ticket-Filter + Sortierung anwenden
     */
    public static function filter_ticket_query($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if (($query->get('post_type') ?? '') !== 'tix_ticket') return;

        $meta_query = $query->get('meta_query') ?: [];

        // Event-Filter
        $event_id = intval($_GET['tix_filter_event'] ?? 0);
        if ($event_id) {
            $meta_query[] = [
                'key'   => '_tix_ticket_event_id',
                'value' => $event_id,
                'type'  => 'NUMERIC',
            ];
        }

        // Status-Filter
        $status = sanitize_text_field($_GET['tix_filter_status'] ?? '');
        if ($status) {
            $meta_query[] = [
                'key'   => '_tix_ticket_status',
                'value' => $status,
            ];
        }

        // Kategorie-Filter
        $cat = $_GET['tix_filter_cat'] ?? '';
        if ($cat !== '' && $cat !== null) {
            $meta_query[] = [
                'key'   => '_tix_ticket_cat_index',
                'value' => intval($cat),
                'type'  => 'NUMERIC',
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }

        // Sortierbare Spalten
        $orderby = $query->get('orderby');
        switch ($orderby) {
            case 'tix_t_event':
                $query->set('meta_key', '_tix_ticket_event_id');
                $query->set('orderby', 'meta_value_num');
                break;
            case 'tix_t_owner':
                $query->set('meta_key', '_tix_ticket_owner_name');
                $query->set('orderby', 'meta_value');
                break;
            case 'tix_t_status':
                $query->set('meta_key', '_tix_ticket_status');
                $query->set('orderby', 'meta_value');
                break;
            case 'tix_t_order':
                $query->set('meta_key', '_tix_ticket_order_id');
                $query->set('orderby', 'meta_value_num');
                break;
        }
    }

    // ══════════════════════════════════════════════
    // TICKET: SORTIERBARE SPALTEN
    // ══════════════════════════════════════════════

    public static function sortable_ticket_columns($columns) {
        $columns['tix_t_event']  = 'tix_t_event';
        $columns['tix_t_owner']  = 'tix_t_owner';
        $columns['tix_t_status'] = 'tix_t_status';
        $columns['tix_t_order']  = 'tix_t_order';
        return $columns;
    }

    // ══════════════════════════════════════════════
    // TICKET: ROW ACTIONS
    // ══════════════════════════════════════════════

    public static function ticket_row_actions($actions, $post) {
        if ($post->post_type !== 'tix_ticket' || !current_user_can('edit_posts')) return $actions;

        $ticket_id = $post->ID;
        $status    = get_post_meta($ticket_id, '_tix_ticket_status', true) ?: 'valid';
        $email     = get_post_meta($ticket_id, '_tix_ticket_owner_email', true);
        $order_id  = intval(get_post_meta($ticket_id, '_tix_ticket_order_id', true));

        // Download Ticket-PDF
        $download_url = '';
        if (class_exists('TIX_Tickets')) {
            $download_url = TIX_Tickets::get_download_url($ticket_id);
        }
        if ($download_url) {
            $actions['tix_download'] = '<a href="' . esc_url($download_url) . '" target="_blank" title="Ticket-PDF herunterladen">&#x2B07; Download</a>';
        }

        // Erneut senden (einzelnes Ticket)
        $actions['tix_resend'] = '<a href="#" class="tix-resend-ticket" data-ticket-id="' . $ticket_id . '" data-email="' . esc_attr($email) . '" title="Ticket per E-Mail erneut senden">&#x2709; Erneut senden</a>';

        // Alle Tickets der Bestellung erneut senden
        if ($order_id) {
            $actions['tix_resend_order'] = '<a href="#" class="tix-resend-order" data-order-id="' . $order_id . '" data-email="' . esc_attr($email) . '" title="Alle Tickets dieser Bestellung erneut senden">&#x2709; Bestellung senden</a>';
        }

        // Quick Status Toggle
        if ($status === 'valid') {
            $actions['tix_cancel'] = '<a href="#" class="tix-toggle-status" data-ticket-id="' . $ticket_id . '" data-new-status="cancelled" style="color:#ef4444;" title="Ticket stornieren">&#x2715; Stornieren</a>';
        } elseif ($status === 'cancelled') {
            $actions['tix_reactivate'] = '<a href="#" class="tix-toggle-status" data-ticket-id="' . $ticket_id . '" data-new-status="valid" style="color:#10b981;" title="Ticket reaktivieren">&#x2713; Reaktivieren</a>';
        }

        return $actions;
    }

    // ══════════════════════════════════════════════
    // TICKET: BULK ACTIONS
    // ══════════════════════════════════════════════

    public static function register_ticket_bulk_actions($actions) {
        $actions['tix_export_csv']      = 'CSV exportieren';
        $actions['tix_resend_bulk']     = 'Tickets erneut senden';
        $actions['tix_cancel_bulk']     = 'Stornieren';
        $actions['tix_reactivate_bulk'] = 'Reaktivieren';
        return $actions;
    }

    public static function handle_ticket_bulk_actions($redirect_to, $doaction, $post_ids) {
        if (!in_array($doaction, ['tix_export_csv', 'tix_resend_bulk', 'tix_cancel_bulk', 'tix_reactivate_bulk'])) return $redirect_to;

        $count = 0;

        switch ($doaction) {
            case 'tix_export_csv':
                set_transient('tix_bulk_export_' . get_current_user_id(), $post_ids, 60);
                wp_redirect(admin_url('admin-post.php?action=tix_export_tickets_csv&bulk=1'));
                exit;

            case 'tix_resend_bulk':
                // Tickets nach Bestellung gruppieren
                $orders = [];
                foreach ($post_ids as $pid) {
                    $oid = get_post_meta($pid, '_tix_ticket_order_id', true);
                    if ($oid) $orders[$oid][] = $pid;
                }
                foreach ($orders as $oid => $tids) {
                    $email = '';
                    foreach ($tids as $tid) {
                        $email = get_post_meta($tid, '_tix_ticket_owner_email', true);
                        if ($email) break;
                    }
                    if (!$email && function_exists('wc_get_order')) {
                        $order = wc_get_order($oid);
                        if ($order) $email = $order->get_billing_email();
                    }
                    if ($email) {
                        self::send_order_tickets_email($oid, $email);
                        $count += count($tids);
                    }
                }
                $redirect_to = add_query_arg('tix_bulk_resent', $count, $redirect_to);
                break;

            case 'tix_cancel_bulk':
                foreach ($post_ids as $pid) {
                    $s = get_post_meta($pid, '_tix_ticket_status', true);
                    if ($s === 'valid') {
                        update_post_meta($pid, '_tix_ticket_status', 'cancelled');
                        $count++;
                    }
                }
                $redirect_to = add_query_arg('tix_bulk_cancelled', $count, $redirect_to);
                break;

            case 'tix_reactivate_bulk':
                foreach ($post_ids as $pid) {
                    $s = get_post_meta($pid, '_tix_ticket_status', true);
                    if ($s === 'cancelled') {
                        update_post_meta($pid, '_tix_ticket_status', 'valid');
                        $count++;
                    }
                }
                $redirect_to = add_query_arg('tix_bulk_reactivated', $count, $redirect_to);
                break;
        }

        return $redirect_to;
    }

    // ══════════════════════════════════════════════
    // TICKET: ADMIN NOTICES (Bulk-Ergebnisse)
    // ══════════════════════════════════════════════

    public static function ticket_bulk_notices() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'tix_ticket') return;

        $messages = [
            'tix_bulk_resent'      => '%d Ticket(s) wurden erneut per E-Mail gesendet.',
            'tix_bulk_cancelled'   => '%d Ticket(s) wurden storniert.',
            'tix_bulk_reactivated' => '%d Ticket(s) wurden reaktiviert.',
        ];

        foreach ($messages as $key => $msg) {
            if (isset($_GET[$key]) && intval($_GET[$key]) > 0) {
                printf('<div class="notice notice-success is-dismissible"><p><strong>Tixomat:</strong> ' . $msg . '</p></div>', intval($_GET[$key]));
            }
        }
    }

    // ══════════════════════════════════════════════
    // TICKET: SUMMARY-LEISTE
    // ══════════════════════════════════════════════

    public static function ticket_summary_bar() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'tix_ticket') return;

        global $wpdb;
        $event_id = intval($_GET['tix_filter_event'] ?? 0);

        $where = "WHERE p.post_type = 'tix_ticket' AND p.post_status = 'publish'";
        if ($event_id) {
            $where .= $wpdb->prepare(
                " AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tix_ticket_event_id' AND meta_value = %d)",
                $event_id
            );
        }

        $results = $wpdb->get_results("
            SELECT COALESCE(pm.meta_value, 'valid') AS ticket_status, COUNT(*) AS cnt
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_ticket_status'
            {$where}
            GROUP BY ticket_status
        ");

        $counts = ['valid' => 0, 'used' => 0, 'cancelled' => 0];
        $total = 0;
        foreach ($results as $r) {
            $s = $r->ticket_status ?: 'valid';
            if (isset($counts[$s])) $counts[$s] = intval($r->cnt);
            $total += intval($r->cnt);
        }

        echo '<div class="tix-ticket-summary">';
        echo '<div class="tix-summary-card"><span class="tix-summary-number">' . $total . '</span><span class="tix-summary-label">Gesamt</span></div>';
        echo '<div class="tix-summary-card"><span class="tix-summary-number" style="color:#10b981">' . $counts['valid'] . '</span><span class="tix-summary-label">Gültig</span></div>';
        echo '<div class="tix-summary-card"><span class="tix-summary-number" style="color:' . tix_primary() . '">' . $counts['used'] . '</span><span class="tix-summary-label">Eingelöst</span></div>';
        echo '<div class="tix-summary-card"><span class="tix-summary-number" style="color:#ef4444">' . $counts['cancelled'] . '</span><span class="tix-summary-label">Storniert</span></div>';
        echo '</div>';
    }

    // ══════════════════════════════════════════════
    // TICKET: CSV-EXPORT (Teilnehmerliste)
    // ══════════════════════════════════════════════

    public static function export_tickets_csv() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');

        $event_id = intval($_GET['event_id'] ?? 0);
        $bulk     = !empty($_GET['bulk']);

        if ($bulk) {
            $ticket_ids = get_transient('tix_bulk_export_' . get_current_user_id());
            delete_transient('tix_bulk_export_' . get_current_user_id());
            if (!$ticket_ids) wp_die('Keine Tickets ausgewählt.');

            $tickets = get_posts([
                'post_type'   => 'tix_ticket',
                'post_status' => 'publish',
                'post__in'    => $ticket_ids,
                'numberposts' => -1,
            ]);
            $filename = 'tickets-auswahl-' . date('Y-m-d') . '.csv';
        } elseif ($event_id) {
            check_admin_referer('tix_export_tickets');
            $tickets = class_exists('TIX_Tickets') ? TIX_Tickets::get_tickets_by_event($event_id) : [];
            $event   = get_post($event_id);
            $slug    = $event ? sanitize_title($event->post_title) : $event_id;
            $filename = 'teilnehmer-' . $slug . '-' . date('Y-m-d') . '.csv';
        } else {
            wp_die('Event-ID oder Auswahl fehlt.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM
        fputcsv($out, ['Ticket-Code', 'Event', 'Name', 'E-Mail', 'Kategorie', 'Sitzplatz', 'Bestellung', 'Status', 'Datum'], ';');

        $status_map = ['valid' => 'Gültig', 'used' => 'Eingelöst', 'cancelled' => 'Storniert'];

        foreach ($tickets as $t) {
            $tid = $t->ID;
            $eid = intval(get_post_meta($tid, '_tix_ticket_event_id', true));
            $ev  = get_post($eid);

            $cat_index = intval(get_post_meta($tid, '_tix_ticket_cat_index', true));
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            $cat_name = (is_array($cats) && isset($cats[$cat_index])) ? ($cats[$cat_index]['name'] ?? '') : '';

            $seat   = get_post_meta($tid, '_tix_ticket_seat_id', true);
            $status = get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid';

            fputcsv($out, [
                get_post_meta($tid, '_tix_ticket_code', true),
                $ev ? $ev->post_title : '#' . $eid,
                get_post_meta($tid, '_tix_ticket_owner_name', true),
                get_post_meta($tid, '_tix_ticket_owner_email', true),
                $cat_name,
                $seat ?: '',
                '#' . get_post_meta($tid, '_tix_ticket_order_id', true),
                $status_map[$status] ?? $status,
                get_the_date('d.m.Y H:i', $tid),
            ], ';');
        }
        fclose($out);
        exit;
    }

    // ══════════════════════════════════════════════
    // TICKET: AJAX – Einzelnes Ticket erneut senden
    // ══════════════════════════════════════════════

    public static function ajax_resend_ticket() {
        check_ajax_referer('tix_ticket_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $email     = sanitize_email($_POST['email'] ?? '');

        if (!$ticket_id) wp_send_json_error('Ticket-ID fehlt.');

        $ticket = get_post($ticket_id);
        if (!$ticket || $ticket->post_type !== 'tix_ticket') wp_send_json_error('Ticket nicht gefunden.');

        if (!$email) $email = get_post_meta($ticket_id, '_tix_ticket_owner_email', true);
        if (!$email) {
            $order_id = get_post_meta($ticket_id, '_tix_ticket_order_id', true);
            if ($order_id && function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if ($order) $email = $order->get_billing_email();
            }
        }
        if (!is_email($email)) wp_send_json_error('Keine gültige E-Mail-Adresse.');

        $code         = get_post_meta($ticket_id, '_tix_ticket_code', true);
        $download_url = class_exists('TIX_Tickets') ? TIX_Tickets::get_download_url($ticket_id) : '';
        $event_id     = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        $event        = get_post($event_id);
        $event_name   = $event ? $event->post_title : '';

        $body  = '<p>Hier ist dein Ticket' . ($event_name ? ' für <strong>' . esc_html($event_name) . '</strong>' : '') . ':</p>';
        if ($download_url) {
            $body .= '<p style="margin:20px 0;"><a href="' . esc_url($download_url) . '" style="display:inline-block;padding:14px 28px;' . tix_btn_style() . 'text-decoration:none;font-weight:700;font-size:16px;">Ticket herunterladen</a></p>';
        }
        $body .= '<p style="color:#64748b;font-size:13px;">Ticket-Code: <strong>' . esc_html($code) . '</strong></p>';

        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html('Dein Ticket', $body, 'Ticket erneut gesendet')
            : '<html><body>' . $body . '</body></html>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($email, 'Dein Ticket – ' . $code, $html, $headers);

        wp_send_json_success(['message' => 'Ticket gesendet an ' . $email]);
    }

    // ══════════════════════════════════════════════
    // TICKET: AJAX – Alle Tickets einer Bestellung senden
    // ══════════════════════════════════════════════

    public static function ajax_resend_order_tickets() {
        check_ajax_referer('tix_ticket_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $order_id = intval($_POST['order_id'] ?? 0);
        $email    = sanitize_email($_POST['email'] ?? '');

        if (!$order_id) wp_send_json_error('Bestellungs-ID fehlt.');

        if (!$email && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) $email = $order->get_billing_email();
        }
        if (!is_email($email)) wp_send_json_error('Keine gültige E-Mail-Adresse.');

        $sent = self::send_order_tickets_email($order_id, $email);
        if ($sent > 0) {
            wp_send_json_success(['message' => $sent . ' Ticket(s) gesendet an ' . $email]);
        } else {
            wp_send_json_error('Keine gültigen Tickets für diese Bestellung gefunden.');
        }
    }

    /**
     * Hilfsfunktion: Alle Tickets einer Bestellung per E-Mail senden
     */
    private static function send_order_tickets_email($order_id, $email) {
        if (!class_exists('TIX_Tickets')) return 0;

        $tickets = TIX_Tickets::get_tickets_by_order($order_id);
        if (empty($tickets)) return 0;

        $body  = '<p>Hier sind deine Tickets für Bestellung <strong>#' . $order_id . '</strong>:</p>';
        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;">';
        $count = 0;

        foreach ($tickets as $t) {
            $status = get_post_meta($t->ID, '_tix_ticket_status', true) ?: 'valid';
            if ($status === 'cancelled') continue;

            $code      = get_post_meta($t->ID, '_tix_ticket_code', true);
            $url       = TIX_Tickets::get_download_url($t->ID);
            $event_id  = intval(get_post_meta($t->ID, '_tix_ticket_event_id', true));
            $event     = get_post($event_id);
            $ev_name   = $event ? $event->post_title : '';

            $body .= '<tr><td style="padding:10px 0;border-bottom:1px solid #eee;">';
            $body .= '<strong>' . esc_html($ev_name) . '</strong><br>';
            $body .= '<span style="color:#64748b;font-size:13px;">Code: ' . esc_html($code) . '</span>';
            $body .= '</td><td style="padding:10px 0;border-bottom:1px solid #eee;text-align:right;">';
            if ($url) {
                $body .= '<a href="' . esc_url($url) . '" style="display:inline-block;padding:8px 18px;' . tix_btn_style() . 'text-decoration:none;font-size:13px;font-weight:600;">Download</a>';
            }
            $body .= '</td></tr>';
            $count++;
        }
        $body .= '</table>';

        if ($count === 0) return 0;

        $html = class_exists('TIX_Emails')
            ? TIX_Emails::build_generic_email_html('Deine Tickets', $body, 'Bestellung #' . $order_id)
            : '<html><body>' . $body . '</body></html>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($email, 'Deine Tickets – Bestellung #' . $order_id, $html, $headers);

        return $count;
    }

    // ══════════════════════════════════════════════
    // TICKET: AJAX – Status umschalten
    // ══════════════════════════════════════════════

    public static function ajax_toggle_ticket_status() {
        check_ajax_referer('tix_ticket_action', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $ticket_id  = intval($_POST['ticket_id'] ?? 0);
        $new_status = sanitize_text_field($_POST['new_status'] ?? '');

        if (!$ticket_id || !in_array($new_status, ['valid', 'cancelled'])) {
            wp_send_json_error('Ungültige Parameter.');
        }

        $ticket = get_post($ticket_id);
        if (!$ticket || $ticket->post_type !== 'tix_ticket') wp_send_json_error('Ticket nicht gefunden.');

        update_post_meta($ticket_id, '_tix_ticket_status', $new_status);

        $labels = ['valid' => 'Gültig', 'used' => 'Eingelöst', 'cancelled' => 'Storniert'];
        wp_send_json_success([
            'status' => $new_status,
            'label'  => $labels[$new_status] ?? $new_status,
        ]);
    }

    // ══════════════════════════════════════════════
    // TICKET: META-SUCHE (E-Mail, Name, Code)
    // ══════════════════════════════════════════════

    public static function extend_ticket_search($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'tix_ticket') return;

        $search = $query->get('s');
        if (empty($search)) return;

        // Suchbegriff in Meta-Felder suchen via posts_where Filter
        add_filter('posts_where', function($where) use ($search) {
            global $wpdb;
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where = preg_replace(
                "/\({$wpdb->posts}\.post_title LIKE[^)]+\)/",
                "({$wpdb->posts}.post_title LIKE {$wpdb->prepare('%s', $like)} OR {$wpdb->posts}.ID IN (
                    SELECT post_id FROM {$wpdb->postmeta}
                    WHERE meta_key IN ('_tix_ticket_owner_email', '_tix_ticket_owner_name', '_tix_ticket_code')
                    AND meta_value LIKE {$wpdb->prepare('%s', $like)}
                ))",
                $where,
                1 // nur einmal ersetzen
            );
            return $where;
        });
    }
}
