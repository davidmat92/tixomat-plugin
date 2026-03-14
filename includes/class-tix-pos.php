<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_POS – Professionelles POS-System (Abendkasse / Vor-Ort-Verkauf).
 *
 * Tablet-optimiertes Fullscreen-Interface für Vor-Ort-Ticketverkauf.
 * Shortcode [tix_pos], PIN-Auth, WC-Order-Erstellung, Kassenbericht.
 *
 * @since 1.29.0
 */
class TIX_POS {

    public static function init() {
        add_shortcode('tix_pos', [__CLASS__, 'render_shortcode']);

        // AJAX endpoints (logged in only – POS is admin-level)
        $actions = [
            'tix_pos_auth',
            'tix_pos_events',
            'tix_pos_event_tickets',
            'tix_pos_create_order',
            'tix_pos_send_email',
            'tix_pos_void_order',
            'tix_pos_daily_report',
            'tix_pos_transactions',
        ];
        foreach ($actions as $a) {
            add_action('wp_ajax_' . $a, [__CLASS__, 'handle_' . str_replace('tix_pos_', '', $a)]);
        }

        // User profile: POS PIN field
        add_action('show_user_profile', [__CLASS__, 'render_pin_field']);
        add_action('edit_user_profile', [__CLASS__, 'render_pin_field']);
        add_action('personal_options_update', [__CLASS__, 'save_pin_field']);
        add_action('edit_user_profile_update', [__CLASS__, 'save_pin_field']);
    }

    // ──────────────────────────────────────────
    // Shortcode
    // ──────────────────────────────────────────

    public static function render_shortcode($atts) {
        if (!is_user_logged_in() && tix_get_settings('pos_pin_required')) {
            // Even with PIN auth, the WP user needs to be logged in for AJAX
            // But we allow nopriv access for PIN-based auth
        }

        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];

        wp_enqueue_style('tix-pos', TIXOMAT_URL . 'assets/css/pos.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-pos', TIXOMAT_URL . 'assets/js/pos.js', [], TIXOMAT_VERSION, true);

        // QR library
        wp_enqueue_script('tix-qr', TIXOMAT_URL . 'assets/js/tix-qr.js', [], TIXOMAT_VERSION, true);

        wp_localize_script('tix-pos', 'tixPOS', [
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('tix_pos'),
            'pinRequired'      => intval($s['pos_pin_required'] ?? 1),
            'autoReset'        => intval($s['pos_auto_reset_seconds'] ?? 10),
            'defaultPayment'   => $s['pos_default_payment'] ?? 'cash',
            'allowFree'        => intval($s['pos_allow_free'] ?? 1),
            'requireEmail'     => intval($s['pos_require_email'] ?? 0),
            'requireName'      => intval($s['pos_require_name'] ?? 0),
            'currency'         => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '€',
            'siteUrl'          => home_url(),
            'logoUrl'          => 'https://tixomat.de/wp-content/uploads/2026/03/logo-tixomat-dark-500px.png',
        ]);

        return '<div id="tix-pos-app"></div>';
    }

    // ──────────────────────────────────────────
    // User Profile: PIN Field
    // ──────────────────────────────────────────

    public static function render_pin_field($user) {
        if (!current_user_can('manage_options') && $user->ID !== get_current_user_id()) return;
        $has_pin = !empty(get_user_meta($user->ID, '_tix_pos_pin', true));
        ?>
        <h3>POS / Abendkasse</h3>
        <table class="form-table">
            <tr>
                <th><label for="tix_pos_pin">POS-PIN</label></th>
                <td>
                    <input type="password" name="tix_pos_pin" id="tix_pos_pin" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_pin ? '••••••' : '4-6 Ziffern eingeben'; ?>">
                    <p class="description">
                        <?php if ($has_pin): ?>
                            PIN ist gesetzt. Leer lassen um nicht zu ändern, oder neuen PIN eingeben.
                            <br><label><input type="checkbox" name="tix_pos_pin_remove" value="1"> PIN entfernen</label>
                        <?php else: ?>
                            Setze einen 4-6-stelligen PIN für die POS-Anmeldung.
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public static function save_pin_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) return;

        if (!empty($_POST['tix_pos_pin_remove'])) {
            delete_user_meta($user_id, '_tix_pos_pin');
            return;
        }

        $pin = sanitize_text_field($_POST['tix_pos_pin'] ?? '');
        if (empty($pin)) return; // Don't change if empty

        if (!preg_match('/^\d{4,6}$/', $pin)) return; // Must be 4-6 digits

        update_user_meta($user_id, '_tix_pos_pin', wp_hash_password($pin));
    }

    // ──────────────────────────────────────────
    // AJAX: PIN Auth
    // ──────────────────────────────────────────

    public static function handle_auth() {
        check_ajax_referer('tix_pos', 'nonce');

        $pin = sanitize_text_field($_POST['pin'] ?? '');
        if (empty($pin)) wp_send_json_error(['message' => 'PIN fehlt.']);

        // Find user with matching PIN
        $users = get_users([
            'meta_key'   => '_tix_pos_pin',
            'meta_compare' => 'EXISTS',
            'fields'     => ['ID', 'display_name', 'user_email'],
        ]);

        foreach ($users as $u) {
            $hash = get_user_meta($u->ID, '_tix_pos_pin', true);
            if ($hash && wp_check_password($pin, $hash)) {
                wp_send_json_success([
                    'user_id'   => $u->ID,
                    'name'      => $u->display_name,
                    'email'     => $u->user_email,
                    'nonce'     => wp_create_nonce('tix_pos'),
                ]);
            }
        }

        wp_send_json_error(['message' => 'PIN ungültig.']);
    }

    // ──────────────────────────────────────────
    // AJAX: Events laden
    // ──────────────────────────────────────────

    public static function handle_events() {
        check_ajax_referer('tix_pos', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $filter = sanitize_text_field($_POST['filter'] ?? 'today');
        $search = sanitize_text_field($_POST['search'] ?? '');

        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'meta_value',
            'meta_key'       => '_tix_date_start',
            'order'          => 'ASC',
        ];

        if ($search) {
            $args['s'] = $search;
        }

        $today = date('Y-m-d');
        $week_end = date('Y-m-d', strtotime('+7 days'));

        if ($filter === 'today') {
            $args['meta_query'] = [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '='],
            ];
        } elseif ($filter === 'week') {
            $args['meta_query'] = [
                ['key' => '_tix_date_start', 'value' => [$today, $week_end], 'compare' => 'BETWEEN'],
            ];
        }

        $posts = get_posts($args);
        $events = [];

        foreach ($posts as $p) {
            $date_start = get_post_meta($p->ID, '_tix_date_start', true);
            $time_start = get_post_meta($p->ID, '_tix_time_start', true);
            $location   = get_post_meta($p->ID, '_tix_location_name', true);
            $thumb      = get_the_post_thumbnail_url($p->ID, 'medium');

            // Get ticket stats
            $cats = get_post_meta($p->ID, '_tix_ticket_categories', true);
            $total_cap = 0;
            $total_sold = 0;
            if (is_array($cats)) {
                foreach ($cats as $cat) {
                    $total_cap += intval($cat['quantity'] ?? 0);
                    $pid = intval($cat['product_id'] ?? 0);
                    if ($pid) {
                        $product = wc_get_product($pid);
                        if ($product) {
                            $stock = $product->get_stock_quantity();
                            $total_sold += max(0, intval($cat['quantity'] ?? 0) - intval($stock));
                        }
                    }
                }
            }

            $events[] = [
                'id'       => $p->ID,
                'title'    => $p->post_title,
                'date'     => $date_start ?: '',
                'time'     => $time_start ?: '',
                'location' => $location ?: '',
                'image'    => $thumb ?: '',
                'sold'     => $total_sold,
                'capacity' => $total_cap,
                'is_today' => $date_start === $today,
            ];
        }

        wp_send_json_success(['events' => $events]);
    }

    // ──────────────────────────────────────────
    // AJAX: Ticket-Kategorien für ein Event
    // ──────────────────────────────────────────

    public static function handle_event_tickets() {
        check_ajax_referer('tix_pos', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error(['message' => 'Event fehlt.']);

        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats) || empty($cats)) {
            wp_send_json_error(['message' => 'Keine Ticket-Kategorien.']);
        }

        $categories = [];
        foreach ($cats as $idx => $cat) {
            $pid = intval($cat['product_id'] ?? 0);
            if (!$pid) continue;

            $product = wc_get_product($pid);
            if (!$product) continue;

            $stock = $product->get_stock_quantity();
            $total_qty = intval($cat['quantity'] ?? 0);

            // null = stock management disabled → unlimited
            if ($stock === null || $stock === '') {
                $available = -1; // -1 = unlimited
                $sold = 0;
            } else {
                $available = max(0, intval($stock));
                $sold = max(0, $total_qty - intval($stock));
            }

            $categories[] = [
                'index'      => $idx,
                'product_id' => $pid,
                'name'       => $cat['name'] ?? 'Ticket',
                'price'      => floatval($product->get_price()),
                'stock'      => $available,
                'sold'       => $sold,
                'total'      => $total_qty,
            ];
        }

        wp_send_json_success(['categories' => $categories, 'event_title' => get_the_title($event_id)]);
    }

    // ──────────────────────────────────────────
    // AJAX: Order erstellen
    // ──────────────────────────────────────────

    public static function handle_create_order() {
        check_ajax_referer('tix_pos', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $event_id     = intval($_POST['event_id'] ?? 0);
        $items        = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
        $payment      = sanitize_key($_POST['payment'] ?? 'cash');
        $customer_name  = sanitize_text_field($_POST['customer_name'] ?? '');
        $customer_email = sanitize_email($_POST['customer_email'] ?? '');
        $staff_id     = intval($_POST['staff_id'] ?? get_current_user_id());
        $coupon_code  = sanitize_text_field($_POST['coupon_code'] ?? '');

        if (!$event_id || empty($items)) {
            wp_send_json_error(['message' => 'Event oder Artikel fehlen.']);
        }

        // Validate payment method
        $valid_payments = ['cash', 'card', 'free'];
        if (!in_array($payment, $valid_payments)) $payment = 'cash';

        $payment_methods = [
            'cash' => 'Barzahlung (POS)',
            'card' => 'EC-Karte (POS)',
            'free' => 'Kostenlos (POS)',
        ];

        // Stock check
        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $qty = intval($item['qty'] ?? 0);
            if (!$pid || $qty <= 0) continue;

            $product = wc_get_product($pid);
            if (!$product) {
                wp_send_json_error(['message' => 'Produkt #' . $pid . ' nicht gefunden.']);
            }
            $stock = $product->get_stock_quantity();
            if ($stock !== null && $stock !== '' && intval($stock) < $qty) {
                wp_send_json_error(['message' => $product->get_name() . ': Nur noch ' . $stock . ' verfügbar.']);
            }
        }

        // Create WC Order
        $order = wc_create_order();
        if (is_wp_error($order)) {
            wp_send_json_error(['message' => 'Order-Erstellung fehlgeschlagen.']);
        }

        foreach ($items as $item) {
            $pid = intval($item['product_id'] ?? 0);
            $qty = intval($item['qty'] ?? 0);
            if (!$pid || $qty <= 0) continue;

            $product = wc_get_product($pid);
            if (!$product) continue;

            $order->add_product($product, $qty);
        }

        // Billing
        $billing_name = $customer_name ?: 'POS Kunde';
        $name_parts = explode(' ', $billing_name, 2);
        $order->set_billing_first_name($name_parts[0]);
        $order->set_billing_last_name($name_parts[1] ?? '');
        $order->set_billing_email($customer_email ?: get_option('admin_email'));

        // Payment method (string only, no gateway class needed)
        $order->set_payment_method('tix_pos_' . $payment);
        $order->set_payment_method_title($payment_methods[$payment]);

        // POS meta
        $order->update_meta_data('_tix_pos_order', 1);
        $order->update_meta_data('_tix_pos_payment_type', $payment);
        $order->update_meta_data('_tix_pos_staff_id', $staff_id);
        $staff_user = get_userdata($staff_id);
        if ($staff_user) {
            $order->update_meta_data('_tix_pos_staff_name', $staff_user->display_name);
        }

        // Apply coupon
        if ($coupon_code) {
            $result = $order->apply_coupon($coupon_code);
            if (is_wp_error($result)) {
                // Don't fail the order, just skip the coupon
            }
        }

        $order->calculate_totals();
        $order->set_status('completed', 'POS-Verkauf');
        $order->save();

        // Tickets should be created automatically by TIX_Tickets::on_order_completed()
        // Wait a moment and then fetch tickets
        $order_id = $order->get_id();

        // Get tickets from DB
        global $wpdb;
        $tickets = [];

        // Try custom ticket table first
        $table = $wpdb->prefix . 'tixomat_tickets';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $ticket_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ticket_code, event_name, ticket_category, ticket_price FROM $table WHERE order_id = %d AND ticket_status = 'valid'",
                $order_id
            ), ARRAY_A);

            foreach ($ticket_rows as $tr) {
                $tickets[] = [
                    'code'     => $tr['ticket_code'],
                    'event'    => $tr['event_name'],
                    'category' => $tr['ticket_category'],
                    'price'    => floatval($tr['ticket_price']),
                    'qr_data'  => 'GL-' . $event_id . '-' . $tr['ticket_code'],
                ];
            }
        }

        // Fallback: get from CPT
        if (empty($tickets)) {
            $ticket_posts = get_posts([
                'post_type'  => 'tix_ticket',
                'meta_query' => [
                    ['key' => '_tix_order_id', 'value' => $order_id],
                ],
                'posts_per_page' => -1,
            ]);
            foreach ($ticket_posts as $tp) {
                $code = get_post_meta($tp->ID, '_tix_ticket_code', true);
                $tickets[] = [
                    'code'     => $code,
                    'event'    => get_the_title($event_id),
                    'category' => get_post_meta($tp->ID, '_tix_ticket_category', true) ?: 'Ticket',
                    'price'    => floatval(get_post_meta($tp->ID, '_tix_ticket_price', true)),
                    'qr_data'  => 'GL-' . $event_id . '-' . $code,
                ];
            }
        }

        wp_send_json_success([
            'order_id' => $order_id,
            'total'    => floatval($order->get_total()),
            'tickets'  => $tickets,
            'payment'  => $payment,
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Ticket per E-Mail senden
    // ──────────────────────────────────────────

    public static function handle_send_email() {
        check_ajax_referer('tix_pos', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $order_id = intval($_POST['order_id'] ?? 0);
        $email    = sanitize_email($_POST['email'] ?? '');

        if (!$order_id || !$email) {
            wp_send_json_error(['message' => 'Order-ID oder E-Mail fehlt.']);
        }

        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error(['message' => 'Bestellung nicht gefunden.']);

        // Update billing email if different
        if ($email !== $order->get_billing_email()) {
            $order->set_billing_email($email);
            $order->save();
        }

        // Trigger WC email
        do_action('woocommerce_order_status_completed_notification', $order_id, $order);

        // Also trigger our ticket email if available
        if (class_exists('TIX_Emails')) {
            TIX_Emails::send_ticket_email($order_id);
        }

        wp_send_json_success(['message' => 'E-Mail gesendet an ' . $email]);
    }

    // ──────────────────────────────────────────
    // AJAX: Storno
    // ──────────────────────────────────────────

    public static function handle_void_order() {
        check_ajax_referer('tix_pos', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) wp_send_json_error(['message' => 'Order-ID fehlt.']);

        $order = wc_get_order($order_id);
        if (!$order) wp_send_json_error(['message' => 'Bestellung nicht gefunden.']);

        // Only void POS orders
        if (!$order->get_meta('_tix_pos_order')) {
            wp_send_json_error(['message' => 'Keine POS-Bestellung.']);
        }

        $order->set_status('cancelled', 'POS-Storno');
        $order->save();

        // Invalidate tickets
        global $wpdb;
        $table = $wpdb->prefix . 'tixomat_tickets';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $wpdb->update($table, ['ticket_status' => 'cancelled'], ['order_id' => $order_id]);
        }

        // Also cancel ticket CPTs
        $ticket_posts = get_posts([
            'post_type'  => 'tix_ticket',
            'meta_query' => [['key' => '_tix_order_id', 'value' => $order_id]],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        foreach ($ticket_posts as $tid) {
            update_post_meta($tid, '_tix_ticket_status', 'cancelled');
        }

        wp_send_json_success(['message' => 'Bestellung storniert.']);
    }

    // ──────────────────────────────────────────
    // AJAX: Tagesbericht
    // ──────────────────────────────────────────

    public static function handle_daily_report() {
        check_ajax_referer('tix_pos', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $date = sanitize_text_field($_POST['date'] ?? date('Y-m-d'));
        $event_id = intval($_POST['event_id'] ?? 0);

        global $wpdb;

        // Use HPOS if available
        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $meta_table   = $wpdb->prefix . 'wc_orders_meta';

            $base_sql = "SELECT o.id FROM $orders_table o
                INNER JOIN $meta_table m ON o.id = m.order_id AND m.meta_key = '_tix_pos_order'
                WHERE o.status = 'wc-completed'
                AND DATE(o.date_created_gmt) = %s";
        } else {
            $base_sql = "SELECT p.ID as id FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_pos_order'
                WHERE p.post_type = 'shop_order' AND p.post_status = 'wc-completed'
                AND DATE(p.post_date) = %s";
        }

        $order_ids = $wpdb->get_col($wpdb->prepare($base_sql, $date));

        $report = [
            'date'           => $date,
            'total_revenue'  => 0,
            'total_tickets'  => 0,
            'total_orders'   => 0,
            'by_payment'     => ['cash' => 0, 'card' => 0, 'free' => 0],
            'by_category'    => [],
            'by_hour'        => [],
            'cancelled'      => 0,
        ];

        if (empty($order_ids)) {
            wp_send_json_success(['report' => $report]);
        }

        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;

            // Skip if event filter set and doesn't match
            if ($event_id) {
                $has_event = false;
                foreach ($order->get_items() as $item) {
                    $pid = $item->get_product_id();
                    // Check if this product belongs to the filtered event
                    $ticket_event = self::get_event_for_product($pid);
                    if ($ticket_event == $event_id) {
                        $has_event = true;
                        break;
                    }
                }
                if (!$has_event) continue;
            }

            $total = floatval($order->get_total());
            $payment_type = $order->get_meta('_tix_pos_payment_type') ?: 'cash';
            $hour = date('H', strtotime($order->get_date_created()->format('Y-m-d H:i:s')));

            $report['total_revenue'] += $total;
            $report['total_orders']++;
            $report['by_payment'][$payment_type] = ($report['by_payment'][$payment_type] ?? 0) + $total;

            if (!isset($report['by_hour'][$hour])) {
                $report['by_hour'][$hour] = ['revenue' => 0, 'tickets' => 0];
            }

            foreach ($order->get_items() as $item) {
                $qty = $item->get_quantity();
                $cat_name = $item->get_name();
                $item_total = floatval($item->get_total());

                $report['total_tickets'] += $qty;
                $report['by_hour'][$hour]['revenue'] += $item_total;
                $report['by_hour'][$hour]['tickets'] += $qty;

                if (!isset($report['by_category'][$cat_name])) {
                    $report['by_category'][$cat_name] = ['tickets' => 0, 'revenue' => 0];
                }
                $report['by_category'][$cat_name]['tickets'] += $qty;
                $report['by_category'][$cat_name]['revenue'] += $item_total;
            }
        }

        // Count cancelled POS orders for this day
        if ($hpos) {
            $cancel_sql = "SELECT COUNT(*) FROM $orders_table o
                INNER JOIN $meta_table m ON o.id = m.order_id AND m.meta_key = '_tix_pos_order'
                WHERE o.status = 'wc-cancelled' AND DATE(o.date_created_gmt) = %s";
        } else {
            $cancel_sql = "SELECT COUNT(*) FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_pos_order'
                WHERE p.post_type = 'shop_order' AND p.post_status = 'wc-cancelled' AND DATE(p.post_date) = %s";
        }
        $report['cancelled'] = intval($wpdb->get_var($wpdb->prepare($cancel_sql, $date)));

        // Sort hours
        ksort($report['by_hour']);

        wp_send_json_success(['report' => $report]);
    }

    // ──────────────────────────────────────────
    // AJAX: Transaktionen
    // ──────────────────────────────────────────

    public static function handle_transactions() {
        check_ajax_referer('tix_pos', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

        $date     = sanitize_text_field($_POST['date'] ?? date('Y-m-d'));
        $event_id = intval($_POST['event_id'] ?? 0);

        global $wpdb;

        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $meta_table   = $wpdb->prefix . 'wc_orders_meta';
            $sql = "SELECT o.id FROM $orders_table o
                INNER JOIN $meta_table m ON o.id = m.order_id AND m.meta_key = '_tix_pos_order'
                WHERE o.status IN ('wc-completed', 'wc-cancelled')
                AND DATE(o.date_created_gmt) = %s ORDER BY o.date_created_gmt DESC";
        } else {
            $sql = "SELECT p.ID as id FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_pos_order'
                WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed', 'wc-cancelled')
                AND DATE(p.post_date) = %s ORDER BY p.post_date DESC";
        }

        $order_ids = $wpdb->get_col($wpdb->prepare($sql, $date));
        $transactions = [];

        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;

            $items_list = [];
            $ticket_count = 0;
            foreach ($order->get_items() as $item) {
                $items_list[] = $item->get_name() . ' ×' . $item->get_quantity();
                $ticket_count += $item->get_quantity();
            }

            // Get ticket codes
            $codes = [];
            $ticket_table = $wpdb->prefix . 'tixomat_tickets';
            if ($wpdb->get_var("SHOW TABLES LIKE '$ticket_table'") === $ticket_table) {
                $codes = $wpdb->get_col($wpdb->prepare(
                    "SELECT ticket_code FROM $ticket_table WHERE order_id = %d", $oid
                ));
            }

            $transactions[] = [
                'order_id'     => intval($oid),
                'time'         => $order->get_date_created()->format('H:i'),
                'items'        => implode(', ', $items_list),
                'tickets'      => $ticket_count,
                'total'        => floatval($order->get_total()),
                'payment'      => $order->get_meta('_tix_pos_payment_type') ?: 'cash',
                'payment_label'=> $order->get_payment_method_title(),
                'customer'     => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'email'        => $order->get_billing_email(),
                'status'       => $order->get_status(),
                'staff'        => $order->get_meta('_tix_pos_staff_name') ?: '',
                'codes'        => $codes,
            ];
        }

        wp_send_json_success(['transactions' => $transactions]);
    }

    // ──────────────────────────────────────────
    // Helper: Event für Product finden
    // ──────────────────────────────────────────

    private static function get_event_for_product($product_id) {
        global $wpdb;
        // Search event meta for this product_id in _tix_ticket_categories
        $events = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tix_ticket_categories' AND meta_value LIKE '%" . intval($product_id) . "%'"
        );
        return !empty($events) ? intval($events[0]) : 0;
    }
}
