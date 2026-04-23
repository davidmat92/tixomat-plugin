<?php
/**
 * Tixomat – Eigenes Ticket-System
 *
 * Erstellt und verwaltet tix_ticket-Posts.
 * Aktivierung über Einstellung: Tixomat → Einstellungen → Features → Ticket-System.
 *
 * @since 1.19.0
 */
if (!defined('ABSPATH')) exit;

class TIX_Tickets {

    // ══════════════════════════════════════════════
    // INIT
    // ══════════════════════════════════════════════

    public static function init() {
        // CPT immer registrieren (damit bestehende Posts lesbar bleiben)
        add_action('init', [__CLASS__, 'register_post_type']);

        // Download-Endpoint registrieren
        add_action('init', [__CLASS__, 'register_download_endpoint']);
        add_action('template_redirect', [__CLASS__, 'handle_download']);

        // Ticket-Erstellung bei Order-Status-Änderung (WooCommerce)
        add_action('woocommerce_order_status_completed',  [__CLASS__, 'on_order_completed']);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'on_order_completed']);

        // Ticket-Stornierung (WooCommerce)
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'on_order_cancelled']);
        add_action('woocommerce_order_status_refunded',  [__CLASS__, 'on_order_cancelled']);

        // Ticket-Erstellung bei nativen Orders (ohne WC)
        add_action('tix_order_completed', [__CLASS__, 'on_native_order_completed']);

        // Ticket-Stornierung bei nativen Orders (ohne WC)
        add_action('tix_order_cancelled', [__CLASS__, 'on_native_order_cancelled']);

        // Online-Ticket AJAX-Endpoints (Badge-Polling + Personen-Zuordnung + Share-Log)
        add_action('wp_ajax_tix_ticket_status',        [__CLASS__, 'ajax_ticket_status']);
        add_action('wp_ajax_nopriv_tix_ticket_status', [__CLASS__, 'ajax_ticket_status']);
        add_action('wp_ajax_tix_ticket_assign',        [__CLASS__, 'ajax_ticket_assign']);
        add_action('wp_ajax_nopriv_tix_ticket_assign', [__CLASS__, 'ajax_ticket_assign']);
        add_action('wp_ajax_tix_ticket_log_share',        [__CLASS__, 'ajax_ticket_log_share']);
        add_action('wp_ajax_nopriv_tix_ticket_log_share', [__CLASS__, 'ajax_ticket_log_share']);
        add_action('wp_ajax_tix_ticket_clear_share',        [__CLASS__, 'ajax_ticket_clear_share']);
        add_action('wp_ajax_nopriv_tix_ticket_clear_share', [__CLASS__, 'ajax_ticket_clear_share']);
        // Admin: manueller Check-in aus "Verkaufte Tickets" (gleicher Backend-Pfad wie Scanner)
        add_action('wp_ajax_tix_admin_checkin_toggle', [__CLASS__, 'ajax_admin_checkin_toggle']);
    }

    // ══════════════════════════════════════════════
    // CPT REGISTRIERUNG
    // ══════════════════════════════════════════════

    public static function register_post_type() {
        register_post_type('tix_ticket', [
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'tixomat',
            'show_in_rest'       => false,
            'supports'           => ['title'],
            'labels'             => [
                'name'               => 'Tickets',
                'singular_name'      => 'Ticket',
                'all_items'          => 'Verkaufte Tickets',
                'search_items'       => 'Tickets suchen',
                'not_found'          => 'Keine Tickets gefunden',
                'not_found_in_trash' => 'Keine Tickets im Papierkorb',
            ],
            'capability_type'    => 'post',
            'capabilities'       => [
                'create_posts' => 'do_not_allow',
            ],
            'map_meta_cap'       => true,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
        ]);
    }

    // ══════════════════════════════════════════════
    // DOWNLOAD ENDPOINT
    // ══════════════════════════════════════════════

    public static function register_download_endpoint() {
        add_rewrite_tag('%tix_ticket_download%', '([0-9]+)');
        // Query-Vars registrieren (damit WordPress sie nicht strippt)
        add_filter('query_vars', function($vars) {
            $vars[] = 'tix_ticket_download';
            $vars[] = 'tix_ticket_code';
            $vars[] = 'tix_ticket_key';
            $vars[] = 'download_ticket';
            $vars[] = 'tix_dl';
            $vars[] = 'tix_bundle';
            $vars[] = 'tix_view';
            return $vars;
        });
    }

    public static function handle_download() {
        // ── Sammel-Ansicht: alle Tickets einer Bestellung (tix_bundle=<ticket-token>) ──
        if (!empty($_GET['tix_bundle'])) {
            $token = sanitize_text_field($_GET['tix_bundle']);
            if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
                wp_die('Ungültiger Bundle-Link.', 'Fehler', ['response' => 404]);
            }
            $results = get_posts([
                'post_type'      => 'tix_ticket',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => [
                    ['key' => '_tix_ticket_download_token', 'value' => $token],
                ],
            ]);
            if (empty($results)) {
                wp_die('Bestellung nicht gefunden oder Link abgelaufen.', 'Fehler', ['response' => 404]);
            }
            $order_id = intval(get_post_meta($results[0]->ID, '_tix_ticket_order_id', true));
            if (!$order_id) wp_die('Bestellung nicht gefunden.', 'Fehler', ['response' => 404]);
            self::render_bundle_html($order_id);
            exit;
        }

        // ── Online-Ansicht: Einzeltick immer als HTML-Online-Ticket (tix_view=<token>) ──
        // Unterscheidet zu tix_dl: tix_dl ist PDF wenn Template existiert, tix_view ist immer HTML
        if (!empty($_GET['tix_view'])) {
            $token = sanitize_text_field($_GET['tix_view']);
            if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
                wp_die('Ungültiger Link.', 'Fehler', ['response' => 404]);
            }
            $results = get_posts([
                'post_type'      => 'tix_ticket',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => [
                    ['key' => '_tix_ticket_download_token', 'value' => $token],
                ],
            ]);
            if (empty($results)) {
                wp_die('Ticket nicht gefunden.', 'Fehler', ['response' => 404]);
            }
            self::render_legacy_html($results[0]->ID);
            exit;
        }

        // ── Format 0: Kryptische Token-URL (tix_dl) ──
        if (!empty($_GET['tix_dl'])) {
            $token = sanitize_text_field($_GET['tix_dl']);
            if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
                wp_die('Ungültiger Download-Link.', 'Fehler', ['response' => 404]);
            }

            // Token-Lookup via meta_query
            $results = get_posts([
                'post_type'      => 'tix_ticket',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'meta_query'     => [
                    ['key' => '_tix_ticket_download_token', 'value' => $token],
                ],
            ]);

            if (empty($results)) {
                wp_die('Ticket nicht gefunden oder Link abgelaufen.', 'Fehler', ['response' => 404]);
            }

            self::render_pdf($results[0]->ID);
            exit;
        }

        // ── Format 1: Eigene Tickets (tix_ticket_code + tix_ticket_key) ──
        if (!empty($_GET['tix_ticket_code']) && !empty($_GET['tix_ticket_key'])) {
            $code      = sanitize_text_field($_GET['tix_ticket_code']);
            $order_key = sanitize_text_field($_GET['tix_ticket_key']);

            $ticket = self::get_ticket_by_code($code);
            if (!$ticket) {
                wp_die('Ungültiger Ticket-Code.', 'Fehler', ['response' => 404]);
            }

            // Order-Key validieren
            $order_id = get_post_meta($ticket->ID, '_tix_ticket_order_id', true);
            if (!$order_id) {
                wp_die('Bestellung nicht gefunden.', 'Fehler', ['response' => 404]);
            }

            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
            if (!$order && class_exists('TIX_Order')) {
                $order = TIX_Order::get($order_id);
            }
            if (!$order || $order->get_order_key() !== $order_key) {
                wp_die('Ungültiger Zugangsschlüssel.', 'Fehler', ['response' => 403]);
            }

            // PDF generieren und ausgeben
            self::render_pdf($ticket->ID);
            exit;
        }

        // ── Format 2: Generic Download (download_ticket + order_key) ──
        // Wird von normalize_tc_ticket() und class-tix-my-tickets.php verwendet
        if (!empty($_GET['download_ticket']) && !empty($_GET['order_key'])) {
            $ticket_id = intval($_GET['download_ticket']);
            $order_key = sanitize_text_field($_GET['order_key']);

            $post = get_post($ticket_id);
            if (!$post) {
                wp_die('Ticket nicht gefunden.', 'Fehler', ['response' => 404]);
            }

            // tix_ticket → eigener PDF-Download
            if ($post->post_type === 'tix_ticket') {
                $order_id = get_post_meta($ticket_id, '_tix_ticket_order_id', true);
                $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
                if (!$order && class_exists('TIX_Order')) {
                    $order = TIX_Order::get($order_id);
                }
                if (!$order || $order->get_order_key() !== $order_key) {
                    wp_die('Ungültiger Zugangsschlüssel.', 'Fehler', ['response' => 403]);
                }
                self::render_pdf($ticket_id);
                exit;
            }

            wp_die('Ticket nicht gefunden.', 'Fehler', ['response' => 404]);
        }
    }

    // ══════════════════════════════════════════════
    // TICKET-ERSTELLUNG BEI BESTELLUNG
    // ══════════════════════════════════════════════

    public static function on_order_completed($order_id) {
        if (!function_exists('wc_get_order')) return;

        try {
            $order = wc_get_order($order_id);
            if (!$order) return;

            // Guard: Keine Duplikate — prüfen ob schon tix_tickets für diese Order existieren
            $existing = self::get_tickets_by_order($order_id);
            if (!empty($existing)) return;

            $buyer_email = $order->get_billing_email();
            $buyer_name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                if (!$product_id) continue;

                // Prüfen ob dieses Produkt ein Event-Ticket ist
                $is_tix_ticket = get_post_meta($product_id, '_tix_is_ticket', true) === 'yes';
                // Legacy: _tc_is_ticket retained for backward compatibility with older products
                $is_tc_ticket = get_post_meta($product_id, '_tc_is_ticket', true) === 'yes';
                if (!$is_tix_ticket && !$is_tc_ticket) continue;

                // Special: Event-ID aus Order-Item-Meta
                $is_special   = $item->get_meta('_tix_special') ? true : false;
                $special_id   = intval($item->get_meta('_tix_special_id'));
                $is_special   = $is_special || (get_post_meta($product_id, '_tix_is_special', true) === 'yes');

                // Event-ID ermitteln
                $event_id = intval($item->get_meta('_tix_event_id'));
                if (!$event_id) {
                    $event_id = intval(get_post_meta($product_id, '_tix_source_event', true));
                }
                if (!$event_id) {
                    // Fallback: über _event_name → _tix_parent_event_id (Legacy)
                    $tc_event_id = get_post_meta($product_id, '_event_name', true);
                    if ($tc_event_id) {
                        $event_id = intval(get_post_meta(intval($tc_event_id), '_tix_parent_event_id', true));
                    }
                }
                if (!$event_id) continue;

                // Ticket-Kategorie-Index ermitteln
                $sku       = get_post_meta($product_id, '_sku', true);
                $cat_index = self::find_cat_index_by_product($event_id, $product_id, $sku);

                $qty = $item->get_quantity();

                // Sitzplätze aus Cart-Item-Meta
                $seat_ids    = $item->get_meta('_tix_seats') ?: [];
                $seatmap_id  = intval($item->get_meta('_tix_seatmap_id'));
                $has_seats   = is_array($seat_ids) && !empty($seat_ids);

                // Pro Stück ein Ticket erstellen
                $created_ticket_ids = [];
                for ($i = 0; $i < $qty; $i++) {
                    $code = self::generate_ticket_code();

                    $ticket_id = wp_insert_post([
                        'post_type'   => 'tix_ticket',
                        'post_title'  => $code,
                        'post_status' => 'publish',
                        'post_parent' => $order_id,
                    ]);

                    if (is_wp_error($ticket_id) || !$ticket_id) continue;

                    update_post_meta($ticket_id, '_tix_ticket_code',       $code);
                    update_post_meta($ticket_id, '_tix_ticket_event_id',   $event_id);
                    update_post_meta($ticket_id, '_tix_ticket_order_id',   $order_id);
                    update_post_meta($ticket_id, '_tix_ticket_product_id', $product_id);
                    update_post_meta($ticket_id, '_tix_ticket_cat_index',  $cat_index);
                    update_post_meta($ticket_id, '_tix_ticket_status',     'valid');
                    update_post_meta($ticket_id, '_tix_ticket_owner_email', $buyer_email);
                    update_post_meta($ticket_id, '_tix_ticket_owner_name',  $buyer_name);

                    // Special-Meta
                    if ($is_special && $special_id) {
                        update_post_meta($ticket_id, '_tix_ticket_type', 'special');
                        update_post_meta($ticket_id, '_tix_ticket_special_id', $special_id);
                    }

                    // Kryptisches Download-Token generieren
                    $dl_token = bin2hex(random_bytes(32));
                    update_post_meta($ticket_id, '_tix_ticket_download_token', $dl_token);

                    // Sitzplatz zuweisen (1 Sitz pro Ticket)
                    if ($has_seats && isset($seat_ids[$i])) {
                        update_post_meta($ticket_id, '_tix_ticket_seat_id', $seat_ids[$i]);
                        $created_ticket_ids[] = $ticket_id;
                    }

                    // Custom-DB-Tabelle befüllen
                    try {
                        if (class_exists('TIX_Ticket_DB') && class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled')) {
                            $newsletter = false;
                            if ($buyer_email) {
                                $subs = get_posts([
                                    'post_type'      => 'tix_subscriber',
                                    'posts_per_page' => 1,
                                    'meta_query'     => [['key' => '_tix_sub_contact', 'value' => $buyer_email]],
                                ]);
                                $newsletter = !empty($subs);
                            }

                            $cats      = get_post_meta($event_id, '_tix_ticket_categories', true);
                            $cat_name  = (is_array($cats) && isset($cats[$cat_index])) ? ($cats[$cat_index]['name'] ?? '') : '';
                            $price     = (is_array($cats) && isset($cats[$cat_index])) ? floatval($cats[$cat_index]['price'] ?? 0) : 0;

                            TIX_Ticket_DB::insert_ticket([
                                'ticket_post_id'  => $ticket_id,
                                'ticket_code'     => $code,
                                'event_id'        => $event_id,
                                'event_name'      => get_the_title($event_id),
                                'order_id'        => $order_id,
                                'category_name'   => $cat_name,
                                'buyer_name'      => $buyer_name,
                                'buyer_email'     => $buyer_email,
                                'buyer_phone'     => $order->get_billing_phone(),
                                'buyer_company'   => $order->get_billing_company(),
                                'buyer_city'      => $order->get_billing_city(),
                                'buyer_zip'       => $order->get_billing_postcode(),
                                'buyer_country'   => $order->get_billing_country(),
                                'seat_id'         => ($has_seats && isset($seat_ids[$i])) ? $seat_ids[$i] : '',
                                'ticket_status'   => 'valid',
                                'ticket_price'    => $price,
                                'newsletter_optin' => $newsletter ? 1 : 0,
                                'created_at'      => current_time('mysql'),
                            ]);
                        }
                    } catch (\Throwable $e) {
                        // Custom-DB-Fehler darf Ticket-Erstellung nicht blockieren
                        error_log('Tixomat: Custom-DB-Fehler bei Ticket ' . $code . ': ' . $e->getMessage());
                    }
                }

                // Sitzplatz-Reservierungen auf "sold" setzen
                if ($has_seats && class_exists('TIX_Seatmap')) {
                    TIX_Seatmap::confirm_seats($event_id, $seat_ids, $order_id, $created_ticket_ids);
                }
            }
        } catch (\Throwable $e) {
            // Fehler darf WooCommerce-Bestellverarbeitung nicht blockieren
            error_log('Tixomat: Ticket-Erstellung fehlgeschlagen für Order ' . $order_id . ': ' . $e->getMessage());
        }
    }

    public static function on_order_cancelled($order_id) {
        $tickets = self::get_tickets_by_order($order_id);
        foreach ($tickets as $ticket) {
            $status = get_post_meta($ticket->ID, '_tix_ticket_status', true);
            if ($status === 'valid') {
                update_post_meta($ticket->ID, '_tix_ticket_status', 'cancelled');
            }
        }

        // Sitzplatz-Reservierungen freigeben
        if (class_exists('TIX_Seatmap')) {
            TIX_Seatmap::on_order_cancelled($order_id);
        }
    }

    // ══════════════════════════════════════════════
    // AUTO-REPAIR: Fehlende tix_ticket Posts nachgenerieren
    // ══════════════════════════════════════════════

    /**
     * Prüft alle bezahlten WC-Bestellungen für ein Event und erstellt fehlende
     * tix_ticket Posts. Idempotent — erstellt nur, was tatsächlich fehlt.
     *
     * Findet WC-Bestellungen über drei Wege:
     *   1. Product-IDs aus _tix_ticket_categories → Line-Item _product_id
     *   2. Line-Item Meta _tix_event_id
     *   3. Native tix_order_items mit event_id (für gelöschte Produkte)
     *
     * @param int $event_id
     * @return int Anzahl neu erstellter Tickets
     */
    public static function ensure_tickets_for_event($event_id) {
        global $wpdb;
        $event_id = intval($event_id);
        if (!$event_id || !function_exists('wc_get_order')) return 0;

        // 1. Alle WC-Order-IDs sammeln, die zu diesem Event gehören
        $order_ids = [];

        // Via Product-IDs aus Ticket-Kategorien
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        $product_ids = [];
        if (is_array($cats)) {
            foreach ($cats as $c) {
                if (!empty($c['product_id'])) $product_ids[] = intval($c['product_id']);
            }
        }
        if (!empty($product_ids)) {
            $pp = implode(',', array_unique($product_ids));
            $ids = $wpdb->get_col(
                "SELECT DISTINCT oi.order_id
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                     ON oi.order_item_id = oim.order_item_id
                     AND oim.meta_key = '_product_id'
                     AND oim.meta_value IN ($pp)
                 WHERE oi.order_item_type = 'line_item'"
            );
            foreach ($ids as $oid) $order_ids[intval($oid)] = true;
        }

        // Via Line-Item Meta _tix_event_id
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT oi.order_id
             FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
                 ON oi.order_item_id = oim.order_item_id
                 AND oim.meta_key = '_tix_event_id'
                 AND oim.meta_value = %s
             WHERE oi.order_item_type = 'line_item'",
            strval($event_id)
        ));
        foreach ($ids as $oid) $order_ids[intval($oid)] = true;

        // Via native tix_order_items (für Tickets aus verschobenen/gelöschten Produkten)
        $native_table = $wpdb->prefix . 'tix_order_items';
        $native_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $native_table));
        if ($native_exists) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT o.wc_order_id
                 FROM {$wpdb->prefix}tix_order_items oi
                 INNER JOIN {$wpdb->prefix}tix_orders o ON oi.order_id = o.id
                 WHERE oi.event_id = %d AND o.wc_order_id > 0",
                $event_id
            ));
            foreach ($ids as $oid) $order_ids[intval($oid)] = true;
        }

        if (empty($order_ids)) return 0;

        // 2. Für jede Order: fehlende Tickets nachgenerieren
        $created = 0;
        $valid_statuses = ['completed', 'processing', 'on-hold'];

        foreach (array_keys($order_ids) as $oid) {
            $order = wc_get_order($oid);
            if (!$order) continue;
            if (!in_array($order->get_status(), $valid_statuses, true)) continue;

            $created += self::ensure_tickets_for_order($order, $event_id);
        }

        return $created;
    }

    /**
     * Erstellt fehlende Tickets für eine einzelne WC-Bestellung (bezogen auf ein Event).
     * Berücksichtigt bereits existierende Tickets pro Order-Item.
     */
    public static function ensure_tickets_for_order($order, $target_event_id = 0) {
        $order_id = $order->get_id();
        $buyer_email = $order->get_billing_email();
        $buyer_name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        $created = 0;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();

            // Event-ID bestimmen (mit Priorität auf Line-Item _tix_event_id, dann Product-Meta, dann native Tabelle)
            $event_id = intval($item->get_meta('_tix_event_id'));
            if (!$event_id && $product_id) {
                $event_id = intval(get_post_meta($product_id, '_tix_parent_event_id', true));
            }
            if (!$event_id && $product_id) {
                // Fallback: Suche Event, dessen _tix_ticket_categories dieses Product referenziert
                $event_id = self::find_event_by_product($product_id);
            }
            if (!$event_id) {
                // Letzter Fallback: native tix_order_items
                global $wpdb;
                $native_table = $wpdb->prefix . 'tix_order_items';
                $native_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $native_table));
                if ($native_exists) {
                    $event_id = intval($wpdb->get_var($wpdb->prepare(
                        "SELECT oi.event_id FROM {$wpdb->prefix}tix_order_items oi
                         INNER JOIN {$wpdb->prefix}tix_orders o ON oi.order_id = o.id
                         WHERE o.wc_order_id = %d AND oi.product_id = %d LIMIT 1",
                        $order_id, $product_id
                    )));
                }
            }
            if (!$event_id) continue;

            // Wenn ein Ziel-Event vorgegeben ist, nur Items für dieses Event bearbeiten
            if ($target_event_id && $event_id !== $target_event_id) continue;

            $qty = intval($item->get_quantity());
            if ($qty <= 0) continue;

            // Existierende Tickets für dieses spezifische Order-Item zählen
            // (per Product-ID, falls Product existiert — sonst per Order-ID + Event-ID)
            $existing_for_item = 0;
            $all_tickets = self::get_tickets_by_order($order_id);
            foreach ($all_tickets as $t) {
                $t_pid = intval(get_post_meta($t->ID, '_tix_ticket_product_id', true));
                $t_eid = intval(get_post_meta($t->ID, '_tix_ticket_event_id', true));
                // Match: Product ODER (Event + Product=0 bei gelöschtem Produkt)
                if ($t_pid === $product_id && $t_eid === $event_id) $existing_for_item++;
                elseif ($t_pid === 0 && $t_eid === $event_id && $product_id === 0) $existing_for_item++;
            }

            $missing = $qty - $existing_for_item;
            if ($missing <= 0) continue;

            // Cat-Index ermitteln
            $sku       = $product_id ? get_post_meta($product_id, '_sku', true) : '';
            $cat_index = self::find_cat_index_by_product($event_id, $product_id, $sku);

            // Fehlende Tickets anlegen
            for ($i = 0; $i < $missing; $i++) {
                $code = self::generate_ticket_code();
                $ticket_id = wp_insert_post([
                    'post_type'   => 'tix_ticket',
                    'post_title'  => $code,
                    'post_status' => 'publish',
                    'post_parent' => $order_id,
                ]);
                if (is_wp_error($ticket_id) || !$ticket_id) continue;

                update_post_meta($ticket_id, '_tix_ticket_code',           $code);
                update_post_meta($ticket_id, '_tix_ticket_event_id',       $event_id);
                update_post_meta($ticket_id, '_tix_ticket_order_id',       $order_id);
                update_post_meta($ticket_id, '_tix_ticket_product_id',     $product_id);
                update_post_meta($ticket_id, '_tix_ticket_cat_index',      $cat_index);
                update_post_meta($ticket_id, '_tix_ticket_status',         'valid');
                update_post_meta($ticket_id, '_tix_ticket_owner_email',    $buyer_email);
                update_post_meta($ticket_id, '_tix_ticket_owner_name',     $buyer_name);
                update_post_meta($ticket_id, '_tix_ticket_download_token', bin2hex(random_bytes(32)));

                $created++;
            }
        }

        return $created;
    }

    /**
     * Sucht Event-ID anhand Product-ID via _tix_ticket_categories (Fallback).
     */
    private static function find_event_by_product($product_id) {
        global $wpdb;
        $product_id = intval($product_id);
        if (!$product_id) return 0;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_tix_ticket_categories'
               AND meta_value LIKE %s",
            '%' . $wpdb->esc_like(strval($product_id)) . '%'
        ));
        foreach ($rows as $r) {
            $cats = maybe_unserialize($r->meta_value);
            if (!is_array($cats)) continue;
            foreach ($cats as $c) {
                if (!empty($c['product_id']) && intval($c['product_id']) === $product_id) {
                    return intval($r->post_id);
                }
            }
        }
        return 0;
    }

    // ══════════════════════════════════════════════
    // TICKET-ABFRAGEN
    // ══════════════════════════════════════════════

    /**
     * Alle tix_ticket-Posts für eine Order
     */
    public static function get_tickets_by_order($order_id) {
        return get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                ['key' => '_tix_ticket_order_id', 'value' => (string) $order_id],
            ],
        ]);
    }

    /**
     * Alle tix_ticket-Posts für ein Event
     */
    public static function get_tickets_by_event($event_id) {
        return get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                ['key' => '_tix_ticket_event_id', 'value' => (string) $event_id],
            ],
        ]);
    }

    /**
     * Einzelnes Ticket per Code
     */
    public static function get_ticket_by_code($code) {
        $results = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                ['key' => '_tix_ticket_code', 'value' => $code],
            ],
        ]);
        return $results[0] ?? null;
    }

    // ══════════════════════════════════════════════
    // UNIFIED TICKET INTERFACE
    // ══════════════════════════════════════════════

    /**
     * Gibt normalisierte Ticket-Daten zurück.
     *
     * @param int $order_id
     * @return array [{code, event_id, event_name, order_id, product_id, cat_name,
     *                 owner_email, owner_name, status, download_url, source}]
     */
    public static function get_all_tickets_for_order($order_id) {
        $tickets = [];

        // ── Eigene Tickets ──
        $tix_tickets = self::get_tickets_by_order($order_id);
        foreach ($tix_tickets as $t) {
            $tickets[] = self::normalize_tix_ticket($t);
        }

        return $tickets;
    }

    // ══════════════════════════════════════════════
    // NORMALISIERUNG
    // ══════════════════════════════════════════════

    private static function normalize_tix_ticket($post) {
        $event_id   = intval(get_post_meta($post->ID, '_tix_ticket_event_id', true));
        $product_id = intval(get_post_meta($post->ID, '_tix_ticket_product_id', true));
        $order_id   = intval(get_post_meta($post->ID, '_tix_ticket_order_id', true));
        $code       = get_post_meta($post->ID, '_tix_ticket_code', true);

        $event      = get_post($event_id);
        $event_name = $event ? $event->post_title : '';

        // Kategorie-Name aus Event-Meta
        $cat_index = intval(get_post_meta($post->ID, '_tix_ticket_cat_index', true));
        $cats      = get_post_meta($event_id, '_tix_ticket_categories', true);
        $cat_name  = '';
        if (is_array($cats) && isset($cats[$cat_index])) {
            $cat_name = $cats[$cat_index]['name'] ?? '';
        }

        return [
            'id'            => $post->ID,
            'code'          => $code,
            'event_id'      => $event_id,
            'event_name'    => $event_name,
            'order_id'      => $order_id,
            'product_id'    => $product_id,
            'cat_name'      => $cat_name,
            'cat_index'     => $cat_index,
            'owner_email'   => get_post_meta($post->ID, '_tix_ticket_owner_email', true),
            'owner_name'    => get_post_meta($post->ID, '_tix_ticket_owner_name', true),
            'status'        => get_post_meta($post->ID, '_tix_ticket_status', true) ?: 'valid',
            'download_url'  => self::get_download_url($post->ID),
            'source'        => 'eh',
        ];
    }

    private static function normalize_tc_ticket($post) {
        $tc_event_id = get_post_meta($post->ID, 'event_id', true);
        $order_id    = get_post_meta($post->ID, 'order_id', true) ?: $post->post_parent;
        $product_id  = get_post_meta($post->ID, 'ticket_type_id', true);
        $code        = get_post_meta($post->ID, 'ticket_code', true);

        // Event-ID: tc_event → _tix_parent_event_id → Tixomat Event
        $event_id   = 0;
        $event_name = '';
        if ($tc_event_id) {
            $event_id   = intval(get_post_meta(intval($tc_event_id), '_tix_parent_event_id', true));
            $tc_event   = get_post(intval($tc_event_id));
            $event_name = $tc_event ? $tc_event->post_title : '';

            // Versuche den TIX-Event-Titel
            if ($event_id) {
                $tix_event = get_post($event_id);
                if ($tix_event) $event_name = $tix_event->post_title;
            }
        }

        // Kategorie-Name
        $cat_name  = '';
        $cat_index = -1;
        if ($event_id && $product_id) {
            $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
            if (is_array($cats)) {
                foreach ($cats as $i => $cat) {
                    if (intval($cat['product_id'] ?? 0) === intval($product_id)) {
                        $cat_name  = $cat['name'] ?? '';
                        $cat_index = $i;
                        break;
                    }
                }
            }
        }

        // Download-URL
        $download_url = '';
        if (function_exists('tc_get_ticket_download_url')) {
            $download_url = tc_get_ticket_download_url($post->ID);
        }
        if (!$download_url && function_exists('wc_get_order') && $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $download_url = add_query_arg([
                    'download_ticket' => $post->ID,
                    'order_key'       => $order->get_order_key(),
                ], home_url('/'));
            }
        }

        return [
            'id'            => $post->ID,
            'code'          => $code ?: '',
            'event_id'      => $event_id,
            'event_name'    => $event_name,
            'order_id'      => intval($order_id),
            'product_id'    => intval($product_id),
            'cat_name'      => $cat_name,
            'cat_index'     => $cat_index,
            'owner_email'   => get_post_meta($post->ID, 'owner_email', true),
            'owner_name'    => trim(
                get_post_meta($post->ID, 'first_name', true) . ' ' .
                get_post_meta($post->ID, 'last_name', true)
            ),
            'status'        => 'valid',
            'download_url'  => $download_url,
            'source'        => 'tc',
        ];
    }

    /**
     * Deduplizierung: Im Both-Modus existieren Tickets doppelt.
     * Bevorzuge tix-Tickets, entferne TC-Duplikate für gleichen order_id + product_id.
     */
    private static function deduplicate_tickets($tickets) {
        $tix_keys = [];
        $result  = [];

        // Erst: alle TIX-Tickets sammeln
        foreach ($tickets as $t) {
            if ($t['source'] === 'eh') {
                $key = $t['order_id'] . '_' . $t['product_id'];
                if (!isset($tix_keys[$key])) $tix_keys[$key] = 0;
                $tix_keys[$key]++;
                $result[] = $t;
            }
        }

        // Dann: TC-Tickets nur hinzufügen wenn kein TIX-Äquivalent existiert
        $tc_counts = [];
        foreach ($tickets as $t) {
            if ($t['source'] !== 'tc') continue;
            $key = $t['order_id'] . '_' . $t['product_id'];
            if (isset($tix_keys[$key])) continue; // EH hat diese schon
            if (!isset($tc_counts[$key])) $tc_counts[$key] = 0;
            $tc_counts[$key]++;
            $result[] = $t;
        }

        return $result;
    }

    // ══════════════════════════════════════════════
    // PDF GENERIERUNG
    // ══════════════════════════════════════════════

    /**
     * Download-URL für ein tix_ticket generieren
     */
    public static function get_download_url($ticket_id) {
        $code = get_post_meta($ticket_id, '_tix_ticket_code', true);
        if (!$code) return '';

        // Token abrufen oder generieren (Lazy Generation)
        $token = get_post_meta($ticket_id, '_tix_ticket_download_token', true);
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            update_post_meta($ticket_id, '_tix_ticket_download_token', $token);
        }

        return add_query_arg(['tix_dl' => $token], home_url('/'));
    }

    /**
     * Token eines Tickets (lazy) — intern genutzt von Bundle/Online-View URLs.
     */
    public static function ensure_download_token($ticket_id) {
        $token = get_post_meta($ticket_id, '_tix_ticket_download_token', true);
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            update_post_meta($ticket_id, '_tix_ticket_download_token', $token);
        }
        return $token;
    }

    /**
     * URL für die Sammel-Ansicht aller Tickets einer Bestellung.
     * Nutzt den Download-Token eines beliebigen Tickets der Order.
     */
    public static function get_bundle_url($order_id) {
        $tickets = self::get_tickets_by_order($order_id);
        if (empty($tickets)) return '';
        $token = self::ensure_download_token($tickets[0]->ID);
        return add_query_arg(['tix_bundle' => $token], home_url('/'));
    }

    /**
     * URL für die Online-Ansicht eines einzelnen Tickets (HTML, immer —
     * unabhängig von PDF-Template).
     */
    public static function get_online_view_url($ticket_id) {
        $token = self::ensure_download_token($ticket_id);
        return add_query_arg(['tix_view' => $token], home_url('/'));
    }

    /**
     * Prüft ob für dieses Ticket ein PDF-Template konfiguriert ist (echtes PDF)
     * oder ob nur eine HTML-Ansicht generiert wird (Online-Ticket).
     *
     * @return bool true = PDF-Template konfiguriert, false = HTML-Online-Ticket
     */
    public static function has_pdf_template($ticket_id) {
        $event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        if (!$event_id || !class_exists('TIX_Ticket_Template')) return false;
        $config = TIX_Ticket_Template::get_effective_config($event_id);
        return $config && !empty($config['template_image_id']);
    }

    /**
     * Label für Ticket-Download-Button.
     * @return string "PDF-Ticket" oder "Online-Ticket"
     */
    public static function ticket_type_label($ticket_id) {
        return self::has_pdf_template($ticket_id) ? 'PDF-Ticket' : 'Online-Ticket';
    }

    /**
     * PDF-Ticket als Binärstring zurückgeben (für Mail-Attachments).
     * Gibt null zurück wenn kein Template konfiguriert oder Rendering fehlschlägt.
     *
     * @return string|null PDF-Binary oder null
     */
    public static function get_pdf_binary($ticket_id) {
        $event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        if (!class_exists('TIX_Ticket_Template')) return null;

        $config = TIX_Ticket_Template::get_effective_config($event_id);
        if (!$config || empty($config['template_image_id'])) return null;

        $gd = TIX_Ticket_Template::render_ticket_image($ticket_id, $config);
        if (!$gd) return null;

        ob_start();
        imagejpeg($gd, null, 95);
        $jpeg = ob_get_clean();
        $pdf  = TIX_Ticket_Template::create_minimal_pdf($jpeg, imagesx($gd), imagesy($gd));
        imagedestroy($gd);
        return $pdf ?: null;
    }

    /**
     * PDF-Ticket rendern und als Download ausgeben.
     * Prüft ob ein Template konfiguriert ist → GD-Rendering, sonst Legacy-HTML.
     */
    private static function render_pdf($ticket_id) {
        $event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));

        // ── TEMPLATE MODE ──
        if (class_exists('TIX_Ticket_Template')) {
            $config = TIX_Ticket_Template::get_effective_config($event_id);

            if ($config && !empty($config['template_image_id'])) {
                $format = sanitize_text_field($_GET['format'] ?? '');
                $gd = TIX_Ticket_Template::render_ticket_image($ticket_id, $config);

                if ($gd) {
                    if ($format === 'image') {
                        header('Content-Type: image/jpeg');
                        header('Content-Disposition: inline; filename="ticket-' . $ticket_id . '.jpg"');
                        imagejpeg($gd, null, 92);
                    } else {
                        ob_start();
                        imagejpeg($gd, null, 95);
                        $jpeg = ob_get_clean();
                        $pdf = TIX_Ticket_Template::create_minimal_pdf($jpeg, imagesx($gd), imagesy($gd));
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: inline; filename="ticket-' . $ticket_id . '.pdf"');
                        echo $pdf;
                    }
                    imagedestroy($gd);
                    return;
                }
            }
        }

        // ── LEGACY MODE (HTML-Ticket) ──
        self::render_legacy_html($ticket_id);
    }

    /**
     * Legacy HTML-Ticket (Fallback ohne Template)
     */
    private static function render_legacy_html($ticket_id) {
        $code       = get_post_meta($ticket_id, '_tix_ticket_code', true);
        $event_id   = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        $cat_index  = intval(get_post_meta($ticket_id, '_tix_ticket_cat_index', true));
        $owner_name = get_post_meta($ticket_id, '_tix_ticket_owner_name', true);

        $event = get_post($event_id);
        if (!$event) wp_die('Event nicht gefunden.', 'Fehler', ['response' => 404]);

        $event_name = $event->post_title;
        $date_start = get_post_meta($event_id, '_tix_date_start', true);
        $time_start = get_post_meta($event_id, '_tix_time_start', true);
        $time_doors = get_post_meta($event_id, '_tix_time_doors', true);
        $location   = get_post_meta($event_id, '_tix_location', true);
        $address    = get_post_meta($event_id, '_tix_address', true);

        // Kategorie-Name + tatsächlich bezahlter Preis
        $cats     = get_post_meta($event_id, '_tix_ticket_categories', true);
        $cat_name = '';
        $price    = '';
        if (is_array($cats) && isset($cats[$cat_index])) {
            $cat_name = $cats[$cat_index]['name'] ?? '';
        }
        // Bezahlten Preis vom Ticket-Post holen (nicht Kategoriepreis)
        $paid = get_post_meta($ticket_id, '_tix_ticket_price', true);
        if ($paid !== '' && $paid !== false) {
            $p = floatval($paid);
            if ($p > 0) $price = number_format($p, 2, ',', '.') . ' €';
        }

        // Datum formatieren
        $date_display = '';
        if ($date_start) {
            $ts = strtotime($date_start);
            $date_display = date_i18n('l, d. F Y', $ts);
        }

        header('Content-Type: text/html; charset=utf-8');

        // Design-Settings laden
        $s  = function_exists('tix_get_settings') ? get_option('tix_settings', []) : [];
        $hd = [
            'ht_header_bg'     => '#222222', 'ht_header_text'   => '#ffffff',
            'ht_body_bg'       => '#ffffff', 'ht_text_color'    => '#1a1a1a',
            'ht_label_color'   => '#888888', 'ht_border_color'  => '#222222',
            'ht_footer_color'  => '#888888', 'ht_divider_color' => '#cccccc',
            'ht_btn_bg'        => '#222222', 'ht_btn_text'      => '#ffffff',
            'ht_border_radius' => 12,
            'ht_logo_height'   => 44,
            'ht_footer_text'   => 'Bitte dieses Ticket ausgedruckt oder digital zum Einlass mitbringen.',
            'ht_logo_url'      => '',
            'ht_version'       => 'v1',
        ];
        foreach ($hd as $k => $v) {
            $$k = isset($s[$k]) && $s[$k] !== '' ? $s[$k] : $v;
        }
        $ht_border_radius = intval($ht_border_radius);
        $ht_logo_height   = intval($ht_logo_height);
        $ht_version       = in_array($ht_version, ['v1', 'v2'], true) ? $ht_version : 'v1';
        // V2 verwendet die Primärfarbe aus den Tixomat-Einstellungen (Design-Tab,
        // color_primary) — gleiche Marken-Identität überall.
        $accent = !empty($s['color_primary']) ? $s['color_primary'] : '#FF5500';

        $qr_data = 'GL-' . $event_id . '-' . $code;
        $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qr_data);

        // Badge-State + Token für Live-Updates / Personen-Zuordnung
        $badge        = self::get_badge_state($ticket_id, $s);
        $online_token = self::ensure_download_token($ticket_id);
        $assigned_raw = trim((string) get_post_meta($ticket_id, '_tix_ticket_assigned_name', true));
        $display_name = $assigned_raw !== '' ? $assigned_raw : $owner_name;
        $ajax_url     = admin_url('admin-ajax.php');

        ?>
<!DOCTYPE html>
<html lang="de" class="tix-ht-<?php echo esc_attr($ht_version); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket: <?php echo esc_html($event_name); ?></title>
    <style>
        @media print {
            @page { margin: 0; }
            body { margin: 0 !important; padding: 10px !important; background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .ticket { box-shadow: none !important; }
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f0; padding: 20px; color: <?php echo esc_attr($ht_text_color); ?>; }

        /* ── Mobile (≤767px): fast 100% Viewport-Breite, Ticket vertikal, kompakte Paddings ── */
        @media (max-width: 767px) {
            body { padding: 8px !important; }
            .ticket { border-radius: 10px !important; border-width: 1px !important; }
            .ticket-header {
                padding: 16px 14px !important;
                flex-direction: column !important;
                text-align: center !important;
                gap: 10px !important;
            }
            .ticket-header-text { text-align: center !important; }
            .ticket-header h1 { font-size: 18px !important; }
            .ticket-header p { font-size: 13px !important; }
            .ticket-body {
                padding: 18px 14px !important;
                flex-direction: column !important;
                align-items: center !important;
                gap: 16px !important;
            }
            .ticket-qr { flex: 0 0 auto !important; }
            .ticket-qr img { width: 180px !important; height: 180px !important; }
            .ticket-info { width: 100% !important; }
            .info-row { margin-bottom: 10px !important; }
            .info-row .value { font-size: 15px !important; }
            .ticket-footer { padding: 12px 14px !important; font-size: 11px !important; }
            .ticket-sponsor { margin-top: 12px !important; padding: 0 8px !important; }
        }

        .ticket { max-width: 600px; margin: 0 auto; background: <?php echo esc_attr($ht_body_bg); ?>; border: 2px solid <?php echo esc_attr($ht_border_color); ?>; border-radius: <?php echo $ht_border_radius; ?>px; overflow: hidden; }
        .ticket-header { background: <?php echo esc_attr($ht_header_bg); ?>; color: <?php echo esc_attr($ht_header_text); ?>; padding: 24px 30px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .ticket-logo { max-height: <?php echo $ht_logo_height; ?>px; width: auto; flex-shrink: 0; }
        .ticket-header-text { flex: 1; }
        .ticket-header h1 { font-size: 22px; margin-bottom: 4px; }
        .ticket-header p { font-size: 14px; opacity: .75; }
        .ticket-body { padding: 30px; display: flex; gap: 24px; }
        .ticket-info { flex: 1; }
        .ticket-qr { flex: 0 0 160px; text-align: center; }
        .ticket-qr img { width: 160px; height: 160px; }
        .ticket-qr .code { font-family: monospace; font-size: 16px; font-weight: bold; margin-top: 8px; letter-spacing: 2px; color: <?php echo esc_attr($ht_text_color); ?>; }
        .info-row { margin-bottom: 14px; }
        .info-row .label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: <?php echo esc_attr($ht_label_color); ?>; margin-bottom: 2px; }
        .info-row .value { font-size: 16px; font-weight: 600; }
        .ticket-footer { border-top: 1px dashed <?php echo esc_attr($ht_divider_color); ?>; padding: 16px 30px; font-size: 12px; color: <?php echo esc_attr($ht_footer_color); ?>; text-align: center; }
        .print-btn:hover { opacity: .85; }

        /* Mobile Scroll-to-Actions — nur ≤767px sichtbar; sitzt direkt über dem Datum */
        .tix-mobile-scroll-btn {
            display: none;
            width: 100%; margin: 0 0 14px;
            padding: 12px 14px; gap: 8px;
            align-items: center; justify-content: center;
            background: <?php echo esc_attr($ht_btn_bg); ?>;
            color: <?php echo esc_attr($ht_btn_text); ?>;
            border: 0; border-radius: 10px;
            font-family: inherit; font-size: 14px; font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px -4px rgba(0,0,0,.25);
            transition: transform .12s ease, box-shadow .2s ease;
        }
        .tix-mobile-scroll-btn:active { transform: translateY(1px); }
        .tix-mobile-scroll-btn svg { animation: tixBounceY 1.6s ease-in-out infinite; }
        @keyframes tixBounceY {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(3px); }
        }
        @media (max-width: 767px) {
            .tix-mobile-scroll-btn { display: inline-flex; }
        }

        /* ── Check-in Badge ── */
        .tix-badge-row {
            max-width: 600px; margin: 12px auto 0;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }
        .tix-badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 14px 10px 16px; border-radius: 999px;
            font-size: 14px; font-weight: 600;
            transition: background .35s ease, color .35s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,.15);
        }
        .tix-badge .tix-badge-name { font-weight: 700; }
        .tix-badge .tix-badge-sep { opacity: .55; margin: 0 2px; }
        .tix-badge-edit {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 26px; border-radius: 50%;
            margin-left: 4px; padding: 0; border: 0;
            background: rgba(0,0,0,.1); color: inherit;
            cursor: pointer; transition: background .2s ease;
            flex-shrink: 0;
        }
        .tix-badge-edit:hover { background: rgba(0,0,0,.2); }
        .tix-badge-edit svg { width: 13px; height: 13px; }
        .tix-assign-hint {
            max-width: 600px; margin: 0 auto;
            padding: 12px 4px; /* gleiches Padding oben + unten */
            font-size: 12px; color: #666;
            text-align: center;
        }

        /* ── Persistente Share-Info Badge ── */
        .tix-shared-row {
            max-width: 600px; margin: 8px auto 0;
            display: flex; justify-content: center;
            padding: 0 4px;
        }
        .tix-shared-info {
            display: none;
            align-items: center; justify-content: center; gap: 6px;
            padding: 6px 5px 6px 14px;
            background: #ecfdf5; color: #065f46;
            border: 1px solid #a7f3d0; border-radius: 999px;
            font-size: 12px; font-weight: 600;
            width: fit-content;
        }
        .tix-shared-info[data-has-share="1"] { display: inline-flex; }
        .tix-shared-info .tix-shared-check { width: 14px; height: 14px; }
        .tix-shared-clear {
            display: inline-flex; align-items: center; justify-content: center;
            width: 20px; height: 20px; border-radius: 50%;
            margin-left: 2px; padding: 0; border: 0;
            background: rgba(6,95,70,.12); color: inherit;
            cursor: pointer; transition: background .15s ease;
            flex-shrink: 0;
        }
        .tix-shared-clear:hover { background: rgba(6,95,70,.25); }
        .tix-shared-clear svg { width: 11px; height: 11px; }

        /* ── Modal Overlay ── */
        .tix-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none; align-items: center; justify-content: center;
            z-index: 99999; padding: 20px;
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
        }
        .tix-modal-overlay.open { display: flex; }
        .tix-modal {
            background: #fff; border-radius: 16px; max-width: 440px; width: 100%;
            padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,.35);
            animation: tixModalIn .25s ease;
        }
        @keyframes tixModalIn {
            from { opacity: 0; transform: translateY(12px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .tix-modal h3 {
            margin: 0 0 4px; font-size: 18px; font-weight: 700; color: #111;
        }
        .tix-modal p.tix-modal-desc {
            margin: 0 0 16px; font-size: 13px; color: #666; line-height: 1.45;
        }
        .tix-modal-input {
            width: 100%; padding: 12px 14px; font-size: 16px;
            border: 1px solid #d1d5db; border-radius: 10px;
            font-family: inherit; margin-bottom: 14px;
            box-sizing: border-box;
        }
        .tix-modal-input:focus {
            outline: none; border-color: #111;
            box-shadow: 0 0 0 3px rgba(17,24,39,.08);
        }
        .tix-modal-actions {
            display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;
        }
        .tix-modal-actions button {
            padding: 10px 18px; font-size: 14px; border-radius: 10px;
            font-weight: 600; cursor: pointer; border: 0;
            font-family: inherit;
        }
        .tix-modal-cancel {
            background: #f3f4f6; color: #111; border: 1px solid #e5e7eb;
        }
        .tix-modal-save {
            background: #111827; color: #fff;
        }
        .tix-modal-save:hover { background: #000; }
        .tix-modal-delete {
            background: transparent; color: #b91c1c; border: 1px solid #fecaca;
            margin-right: auto;
        }
        .tix-modal-delete:hover { background: #fef2f2; }
        .tix-modal-delete[hidden] { display: none; }

        @media (max-width: 640px) {
            .tix-modal { padding: 20px; border-radius: 14px; }
            .tix-modal-actions { flex-direction: column-reverse; }
            .tix-modal-actions button { width: 100%; margin: 0; }
        }

        /* ═══════════════════════════════════════
           V2 · PREMIUM DESIGN (Override-Layer)
           ═══════════════════════════════════════ */
        html.tix-ht-v2 body {
            background: radial-gradient(circle at 20% 0%, rgba(139,92,246,.08), transparent 50%),
                        radial-gradient(circle at 80% 100%, rgba(59,130,246,.06), transparent 50%),
                        #f7f7f8;
            min-height: 100vh;
        }
        html.tix-ht-v2 .ticket {
            position: relative;
            box-shadow: 0 30px 60px -25px rgba(17,24,39,.22),
                        0 12px 30px -12px rgba(17,24,39,.08),
                        0 0 0 1px rgba(17,24,39,.04);
            overflow: hidden; /* Kinder (Header) respektieren border-radius */
            background:
                linear-gradient(<?php echo esc_attr($ht_body_bg); ?>, <?php echo esc_attr($ht_body_bg); ?>) padding-box,
                linear-gradient(135deg, <?php echo esc_attr($ht_header_bg); ?> 0%, <?php echo esc_attr($accent); ?> 100%) border-box;
            border: 2px solid transparent;
        }
        /* Accent-Shine: innen auf dem Header, NICHT außerhalb des Tickets */
        html.tix-ht-v2 .ticket::before {
            content: "";
            position: absolute; left: 12%; right: 12%; top: 0; height: 3px;
            background: linear-gradient(90deg, transparent, <?php echo esc_attr($accent); ?>, transparent);
            border-radius: 0 0 999px 999px;
            opacity: .7;
            z-index: 2;
            pointer-events: none;
        }
        html.tix-ht-v2 .ticket-header {
            background: linear-gradient(135deg,
                <?php echo esc_attr($ht_header_bg); ?> 0%,
                <?php echo esc_attr($ht_header_bg); ?> 60%,
                color-mix(in srgb, <?php echo esc_attr($ht_header_bg); ?> 82%, <?php echo esc_attr($accent); ?> 18%) 100%);
            padding: 28px 32px;
            position: relative;
            overflow: hidden;
        }
        html.tix-ht-v2 .ticket-header::after {
            content: "";
            position: absolute; inset: 0;
            background: radial-gradient(ellipse at top right, rgba(255,255,255,.10), transparent 55%);
            pointer-events: none;
        }
        html.tix-ht-v2 .ticket-header h1 {
            font-size: 26px; font-weight: 800;
            letter-spacing: -.02em;
            text-shadow: 0 1px 0 rgba(0,0,0,.12);
        }
        html.tix-ht-v2 .ticket-header p {
            font-size: 13px; font-weight: 500;
            opacity: .82;
            letter-spacing: .02em;
        }
        /* Perforation zwischen Header und Body */
        html.tix-ht-v2 .ticket-body {
            position: relative;
            padding-top: 40px;
        }
        html.tix-ht-v2 .ticket-body::before {
            content: "";
            position: absolute; left: 0; right: 0; top: 16px;
            height: 2px;
            background-image: radial-gradient(circle, <?php echo esc_attr($ht_divider_color); ?> 1px, transparent 1.4px);
            background-size: 10px 2px;
            background-repeat: repeat-x;
            background-position: center;
        }
        html.tix-ht-v2 .info-row .label {
            font-size: 10px; letter-spacing: 1.4px;
            color: color-mix(in srgb, <?php echo esc_attr($ht_label_color); ?> 88%, <?php echo esc_attr($accent); ?> 12%);
            font-weight: 700;
        }
        html.tix-ht-v2 .info-row .value {
            font-size: 15.5px; font-weight: 600;
            letter-spacing: -.005em;
        }
        /* QR-Bereich mit Corner-Brackets */
        html.tix-ht-v2 .ticket-qr {
            position: relative;
            padding: 10px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px -6px rgba(17,24,39,.08), 0 0 0 1px rgba(17,24,39,.05);
        }
        html.tix-ht-v2 .ticket-qr::before,
        html.tix-ht-v2 .ticket-qr::after {
            content: "";
            position: absolute;
            width: 14px; height: 14px;
            border: 2px solid <?php echo esc_attr($accent); ?>;
        }
        html.tix-ht-v2 .ticket-qr::before {
            top: -3px; left: -3px;
            border-right: 0; border-bottom: 0;
            border-radius: 4px 0 0 0;
        }
        html.tix-ht-v2 .ticket-qr::after {
            bottom: -3px; right: -3px;
            border-left: 0; border-top: 0;
            border-radius: 0 0 4px 0;
        }
        html.tix-ht-v2 .ticket-qr .code {
            font-family: 'SF Mono', 'Fira Code', Consolas, monospace;
            letter-spacing: 2.5px; font-weight: 700;
        }
        html.tix-ht-v2 .ticket-footer {
            background: color-mix(in srgb, <?php echo esc_attr($ht_body_bg); ?> 94%, <?php echo esc_attr($ht_border_color); ?> 6%);
            font-size: 11.5px; font-weight: 500;
            letter-spacing: .01em;
            padding: 14px 30px;
        }
        html.tix-ht-v2 .tix-ticket-actions .btn-base {
            border-radius: 10px;
            box-shadow: 0 2px 6px -2px rgba(17,24,39,.12);
            transition: transform .12s ease, box-shadow .2s ease;
        }
        html.tix-ht-v2 .tix-ticket-actions .btn-base:active {
            transform: translateY(1px);
        }
        html.tix-ht-v2 .tix-ticket-actions .print-btn {
            background: linear-gradient(135deg, <?php echo esc_attr($ht_btn_bg); ?>, color-mix(in srgb, <?php echo esc_attr($ht_btn_bg); ?> 85%, <?php echo esc_attr($accent); ?> 15%)) !important;
            box-shadow: 0 6px 16px -8px rgba(0,0,0,.35);
        }
        @media (max-width: 767px) {
            html.tix-ht-v2 .ticket-header { padding: 22px 18px !important; }
            html.tix-ht-v2 .ticket-header h1 { font-size: 20px !important; }
            html.tix-ht-v2 .ticket-body { padding-top: 32px !important; }
            html.tix-ht-v2 .ticket-body::before { top: 10px; }
        }
    </style>
</head>
<body>
    <div class="tix-badge-row no-print" data-ticket-token="<?php echo esc_attr($online_token); ?>">
        <div class="tix-badge" data-tix-badge style="background: <?php echo esc_attr($badge['bg']); ?>; color: <?php echo esc_attr($badge['fg']); ?>;">
            <span class="tix-badge-label"><?php echo esc_html($badge['label']); ?></span>
            <?php if (!empty($badge['name'])): ?>
                <span class="tix-badge-sep">·</span>
                <span class="tix-badge-name"><?php echo esc_html($badge['name']); ?></span>
            <?php endif; ?>
            <button type="button" class="tix-badge-edit" onclick="tixAssignOpen()" aria-label="Namen ändern" title="Namen ändern">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
            </button>
        </div>
    </div>
    <?php
    $share_info = self::get_share_info($ticket_id);
    ?>
    <div class="tix-shared-row no-print">
        <div class="tix-shared-info" data-tix-shared-info data-has-share="<?php echo $share_info['has'] ? '1' : '0'; ?>">
            <svg class="tix-shared-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span data-tix-shared-label><?php echo esc_html($share_info['label'] ?: 'Geteilt'); ?></span>
            <button type="button" class="tix-shared-clear" onclick="tixShareClear()" aria-label="Geteilt-Markierung entfernen" title="Entfernen">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>
    <div class="tix-assign-hint no-print">Ordne das Ticket einer bestimmten Person zu - freiwillig zur Übersicht.</div>

    <div class="tix-modal-overlay no-print" data-tix-modal onclick="tixAssignOverlayClose(event)">
        <div class="tix-modal" role="dialog" aria-modal="true" aria-labelledby="tix-modal-title">
            <h3 id="tix-modal-title">Ticket einer Person zuordnen</h3>
            <p class="tix-modal-desc">Ordne das Ticket einer bestimmten Person zu - freiwillig zur Übersicht.</p>
            <input type="text" class="tix-modal-input" maxlength="80" placeholder="Name der Person" value="<?php echo esc_attr($assigned_raw); ?>" data-tix-modal-input>
            <div class="tix-modal-actions">
                <button type="button" class="tix-modal-delete" onclick="tixAssignDelete()" <?php echo $assigned_raw === '' ? 'hidden' : ''; ?>>Zuordnung entfernen</button>
                <button type="button" class="tix-modal-cancel" onclick="tixAssignClose()">Abbrechen</button>
                <button type="button" class="tix-modal-save" onclick="tixAssignSave()">Speichern</button>
            </div>
        </div>
    </div>

    <div class="ticket">
        <div class="ticket-header">
            <?php if ($ht_logo_url): ?>
                <img src="<?php echo esc_url($ht_logo_url); ?>" alt="Logo" class="ticket-logo">
            <?php endif; ?>
            <div class="ticket-header-text" style="text-align:right;">
                <h1><?php echo esc_html($event_name); ?></h1>
                <p><?php echo esc_html($cat_name); ?><?php if ($price): ?> — <?php echo esc_html($price); ?><?php endif; ?></p>
            </div>
        </div>
        <div class="ticket-body">
            <div class="ticket-info">
                <button type="button" class="tix-mobile-scroll-btn no-print" onclick="tixScrollToActions()" aria-label="Zu den Aktionen scrollen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
                    Teilen, drucken &amp; mehr
                </button>
                <?php if ($date_display): ?>
                <div class="info-row">
                    <div class="label">Datum</div>
                    <div class="value"><?php echo esc_html($date_display); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($time_start): ?>
                <div class="info-row">
                    <div class="label"><?php echo $time_doors ? 'Einlass / Beginn' : 'Beginn'; ?></div>
                    <div class="value"><?php if ($time_doors): echo esc_html($time_doors) . ' / '; endif; echo esc_html($time_start); ?> Uhr</div>
                </div>
                <?php endif; ?>

                <?php if ($location): ?>
                <div class="info-row">
                    <div class="label">Location</div>
                    <div class="value"><?php echo esc_html($location); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($address): ?>
                <div class="info-row">
                    <div class="label">Adresse</div>
                    <div class="value" style="font-size:13px;font-weight:400;"><?php echo esc_html($address); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($display_name): ?>
                <div class="info-row">
                    <div class="label"><?php echo $assigned_raw !== '' ? 'Person' : 'Name'; ?></div>
                    <div class="value" data-tix-name><?php echo esc_html($display_name); ?></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="ticket-qr">
                <img src="<?php echo esc_url($qr_url); ?>" alt="QR-Code">
                <div class="code"><?php echo esc_html($code); ?></div>
            </div>
        </div>
        <div class="ticket-footer">
            <?php echo esc_html($ht_footer_text); ?>
        </div>
    </div>

    <?php
    // ── Sponsor-Banner (Werbung unter dem Ticket) ──
    $sponsor_id  = intval(get_post_meta($event_id, '_tix_ticket_sponsor_image_id', true));
    if ($sponsor_id):
        $sponsor_url  = wp_get_attachment_image_url($sponsor_id, 'large');
        $sponsor_link = get_post_meta($event_id, '_tix_ticket_sponsor_link', true);
        if ($sponsor_url):
    ?>
    <div class="ticket-sponsor" style="max-width:600px;margin:16px auto 0;text-align:center;">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:6px;">Anzeige</div>
        <?php if ($sponsor_link): ?>
            <a href="<?php echo esc_url($sponsor_link); ?>" target="_blank" rel="noopener" style="display:inline-block;">
                <img src="<?php echo esc_url($sponsor_url); ?>" alt="Sponsor" style="max-width:100%;height:auto;border-radius:<?php echo $ht_border_radius; ?>px;display:block;">
            </a>
        <?php else: ?>
            <img src="<?php echo esc_url($sponsor_url); ?>" alt="Sponsor" style="max-width:100%;height:auto;border-radius:<?php echo $ht_border_radius; ?>px;display:block;margin:0 auto;">
        <?php endif; ?>
    </div>
    <?php endif; endif; ?>

    <?php
    // ── Hidden Data-Container für Canvas-Renderer (Als Bild speichern / Teilen) ──
    // Nutzt dieselben data-Attribute + Klassen wie die Meine-Tickets-Cards,
    // damit tix-ticket-img.js ohne Änderungen funktioniert.
    $thumb_url = get_the_post_thumbnail_url($event_id, 'large') ?: '';
    $sponsor_img_url = !empty($sponsor_url) ? $sponsor_url : '';
    ?>
    <div class="tix-mt-tcard" id="tix-online-ticket-source" style="display:none;"
         data-event="<?php echo esc_attr($event_name); ?>"
         data-date="<?php echo esc_attr($date_display); ?>"
         data-doors="<?php echo esc_attr($time_doors ? 'Einlass ' . $time_doors : ''); ?>"
         data-time="<?php echo esc_attr($time_start ? 'Beginn ' . $time_start : ''); ?>"
         data-location="<?php echo esc_attr($location); ?>"
         data-type="<?php echo esc_attr($cat_name); ?>"
         data-code="<?php echo esc_attr($code); ?>"
         data-num="1"
         data-buyer="<?php echo esc_attr($owner_name); ?>"
         data-email=""
         data-thumb="<?php echo esc_attr($thumb_url); ?>"
         data-logo="<?php echo esc_attr($ht_logo_url); ?>"
         data-sponsor="<?php echo esc_attr($sponsor_img_url); ?>"
         data-share-url="<?php echo esc_attr(self::get_online_view_url($ticket_id)); ?>"
         data-ticket-token="<?php echo esc_attr($online_token); ?>"
         data-accent-bg="<?php echo esc_attr($ht_header_bg); ?>"
         data-accent-fg="<?php echo esc_attr($ht_header_text); ?>">
        <canvas class="tix-mt-qr-canvas" data-qr="<?php echo esc_attr($code); ?>" width="240" height="240"></canvas>
    </div>

    <style>
        .tix-ticket-actions { max-width:600px; margin:20px auto 40px; padding:0 8px; display:flex; flex-direction:column; gap:10px; }
        .tix-ticket-actions-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .tix-ticket-actions .btn-base {
            padding:12px 14px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer;
            font-family:inherit; display:inline-flex; align-items:center; justify-content:center; gap:6px;
        }
        /* Mobile (≤767px): alle Buttons untereinander full-width */
        @media (max-width: 767px) {
            .tix-ticket-actions { margin:14px auto 24px; padding:0; gap:8px; }
            .tix-ticket-actions-row { display:flex !important; flex-direction:column !important; gap:8px !important; }
            .tix-ticket-actions .btn-base { padding:14px !important; font-size:15px !important; }
        }
    </style>
    <div class="no-print tix-ticket-actions">
        <div class="tix-ticket-actions-row">
            <button type="button" class="btn-base" onclick="tixOnlineTicketAction(this, 'img')"
                    style="background:#fff;color:#1f2937;border:1px solid #e5e7eb;">
                &#128247; Als Bild speichern
            </button>
            <button type="button" class="btn-base" onclick="tixOnlineTicketAction(this, 'share')"
                    style="background:#fff;color:#1f2937;border:1px solid #e5e7eb;">
                &#128228; Teilen
            </button>
        </div>
        <div class="tix-ticket-actions-row">
            <button type="button" class="btn-base" onclick="tixWalletShow('apple')"
                    style="background:#000;color:#fff;border:none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.1 12.5c0-2.4 2-3.5 2.1-3.6-1.1-1.6-2.9-1.9-3.5-1.9-1.5-.2-2.9.9-3.7.9-.8 0-1.9-.9-3.2-.9-1.6 0-3.2 1-4 2.4-1.7 3-.4 7.5 1.3 10 .8 1.2 1.8 2.5 3 2.5 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.1.8 1.3 0 2.2-1.2 3-2.5.9-1.4 1.3-2.8 1.3-2.9-.1 0-2.5-.9-2.5-3.9zM14.6 5c.7-.8 1.1-1.9 1-3-1 0-2.1.7-2.8 1.5-.6.7-1.2 1.8-1 2.8 1.1.1 2.2-.6 2.8-1.3z"/></svg>
                Apple Wallet
            </button>
            <button type="button" class="btn-base" onclick="tixWalletShow('google')"
                    style="background:#fff;color:#1f2937;border:1px solid #e5e7eb;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                Google Wallet
            </button>
        </div>
        <button class="btn-base print-btn" onclick="window.print()" style="background:<?php echo esc_attr($ht_btn_bg); ?>;color:<?php echo esc_attr($ht_btn_text); ?>;border:none;margin-top:16px;">
            &#128424; Ticket drucken
        </button>
    </div>

    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/tix-qr.js?v=' . TIXOMAT_VERSION); ?>"></script>
    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/tix-ticket-img.js?v=' . TIXOMAT_VERSION); ?>"></script>
    <script src="<?php echo esc_url(TIXOMAT_URL . 'assets/js/tix-wallet.js?v=' . TIXOMAT_VERSION); ?>"></script>
    <script>
        // QR-Code auf Canvas rendern, damit tix-ticket-img.js ihn als Bild mitrendern kann
        document.addEventListener('DOMContentLoaded', function() {
            var cvs = document.querySelector('#tix-online-ticket-source canvas[data-qr]');
            if (cvs && window.ehQR && typeof window.ehQR.render === 'function') {
                window.ehQR.render(cvs);
            }
        });
        // Image/Share auf dem versteckten Source-Element triggern
        function tixOnlineTicketAction(btn, mode) {
            var src = document.getElementById('tix-online-ticket-source');
            if (!src) return;
            // Wir mocken einen Fake-Button der inside src liegt — die JS-Funktion sucht via closest()
            var fakeBtn = document.createElement('button');
            src.appendChild(fakeBtn);
            fakeBtn.innerHTML = '…';
            btn.disabled = true;
            var oldHTML = btn.innerHTML;
            btn.innerHTML = '\u23F3 wird erstellt…';
            if (mode === 'img' && typeof ehTicketImg === 'function') {
                ehTicketImg(fakeBtn);
            } else if (mode === 'share' && typeof ehTicketShare === 'function') {
                ehTicketShare(fakeBtn);
            }
            setTimeout(function() { btn.disabled = false; btn.innerHTML = oldHTML; fakeBtn.remove(); }, 3000);
        }

        // ── Mobile: Scroll zu den Action-Buttons ──
        function tixScrollToActions() {
            var target = document.querySelector('.tix-ticket-actions');
            if (!target) return;
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // ── Geteilt-Badge entfernen (X-Button) ──
        function tixShareClear() {
            var body = new FormData();
            body.append('action', 'tix_ticket_clear_share');
            body.append('token', TIX_TOKEN);
            fetch(TIX_AJAX_URL, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res && res.success) {
                        var shared = document.querySelector('[data-tix-shared-info]');
                        if (shared) shared.setAttribute('data-has-share', '0');
                    }
                })
                .catch(function(){});
        }

        // ── Check-in-Badge + Personen-Zuordnung ──
        window.TIX_AJAX_URL = <?php echo wp_json_encode($ajax_url); ?>;
        var TIX_AJAX_URL = window.TIX_AJAX_URL;
        var TIX_TOKEN    = <?php echo wp_json_encode($online_token); ?>;

        function tixApplyBadge(state) {
            var badge = document.querySelector('[data-tix-badge]');
            if (!badge) return;
            badge.style.background = state.bg;
            badge.style.color = state.fg;
            var label = badge.querySelector('.tix-badge-label');
            if (label) label.textContent = state.label;
            var editBtn = badge.querySelector('.tix-badge-edit');
            var nameEl = badge.querySelector('.tix-badge-name');
            var sep = badge.querySelector('.tix-badge-sep');
            if (state.name) {
                if (!sep) {
                    sep = document.createElement('span'); sep.className = 'tix-badge-sep'; sep.textContent = '·';
                    if (editBtn) badge.insertBefore(sep, editBtn); else badge.appendChild(sep);
                }
                if (!nameEl) {
                    nameEl = document.createElement('span'); nameEl.className = 'tix-badge-name';
                    if (editBtn) badge.insertBefore(nameEl, editBtn); else badge.appendChild(nameEl);
                }
                nameEl.textContent = state.name;
            } else {
                if (nameEl) nameEl.remove();
                if (sep) sep.remove();
            }
            // Name-Value im Info-Bereich synchronisieren
            var nameValue = document.querySelector('[data-tix-name]');
            if (nameValue && state.name) nameValue.textContent = state.name;
            // Share-Info synchronisieren
            if (state.share) {
                var shared = document.querySelector('[data-tix-shared-info]');
                if (shared) {
                    shared.setAttribute('data-has-share', state.share.has ? '1' : '0');
                    var sl = shared.querySelector('[data-tix-shared-label]');
                    if (sl) sl.textContent = state.share.label || 'Geteilt';
                }
            }
        }

        function tixAssignOpen() {
            var modal = document.querySelector('[data-tix-modal]');
            if (!modal) return;
            modal.classList.add('open');
            // Auto-Focus bewusst deaktiviert — verhindert Mobile-Zoom beim Keyboard-Öffnen
            document.body.style.overflow = 'hidden';
        }
        function tixAssignClose() {
            var modal = document.querySelector('[data-tix-modal]');
            if (!modal) return;
            modal.classList.remove('open');
            document.body.style.overflow = '';
        }
        function tixAssignOverlayClose(e) {
            // Nur schließen wenn direkt auf das Overlay geklickt wurde
            if (e.target && e.target.hasAttribute('data-tix-modal')) tixAssignClose();
        }
        function tixAssignSubmit(name, triggerBtn) {
            var oldText = triggerBtn.textContent;
            triggerBtn.disabled = true; triggerBtn.textContent = 'Speichert…';
            var body = new FormData();
            body.append('action', 'tix_ticket_assign');
            body.append('token', TIX_TOKEN);
            body.append('name', name);
            fetch(TIX_AJAX_URL, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    triggerBtn.disabled = false; triggerBtn.textContent = oldText;
                    if (res && res.success) {
                        tixApplyBadge(res.data);
                        // Delete-Button basierend auf assigned-Status umschalten
                        var delBtn = document.querySelector('.tix-modal-delete');
                        if (delBtn) delBtn.hidden = !res.data.assigned;
                        tixAssignClose();
                    }
                })
                .catch(function(){ triggerBtn.disabled = false; triggerBtn.textContent = oldText; });
        }
        function tixAssignSave() {
            var input = document.querySelector('[data-tix-modal-input]');
            var saveBtn = document.querySelector('.tix-modal-save');
            tixAssignSubmit(input.value, saveBtn);
        }
        function tixAssignDelete() {
            if (!confirm('Zuordnung wirklich entfernen? Anschließend wird wieder der Name des Käufers angezeigt.')) return;
            var delBtn = document.querySelector('.tix-modal-delete');
            tixAssignSubmit('', delBtn);
        }
        // ESC schließt Modal
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') tixAssignClose();
        });
        function tixPollStatus() {
            var body = new FormData();
            body.append('action', 'tix_ticket_status');
            body.append('token', TIX_TOKEN);
            fetch(TIX_AJAX_URL, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res){ if (res && res.success) tixApplyBadge(res.data); })
                .catch(function(){});
        }
        // Alle 12s Status abfragen (für Live-Badge nach Scan am Einlass)
        setInterval(tixPollStatus, 12000);
    </script>
</body>
</html>
        <?php
    }

    // ══════════════════════════════════════════════
    // ONLINE-TICKET: PERSONEN-ZUORDNUNG + CHECKIN-STATUS
    // ══════════════════════════════════════════════

    /**
     * Effektiver Anzeige-Name für ein Ticket: Zugewiesene Person (falls gesetzt)
     * sonst Käufer.
     */
    public static function get_effective_holder_name($ticket_id) {
        $assigned = trim((string) get_post_meta($ticket_id, '_tix_ticket_assigned_name', true));
        if ($assigned !== '') return $assigned;
        return (string) get_post_meta($ticket_id, '_tix_ticket_owner_name', true);
    }

    /**
     * Liefert den Status-Payload für Badge-Rendering (Badge-Label, Farbe, Name).
     *
     * @return array ['checked_in'=>bool, 'name'=>string, 'assigned'=>bool,
     *                'time'=>string, 'label'=>string, 'bg'=>string, 'fg'=>string]
     */
    public static function get_badge_state($ticket_id, $settings = null) {
        $s = $settings ?: (function_exists('tix_get_settings') ? get_option('tix_settings', []) : []);

        // Eigene Badge-Farben (Settings → Design → Ticket-Badge)
        $bg_pending = !empty($s['badge_pending_bg'])   ? $s['badge_pending_bg']   : '#6366f1'; // Indigo
        $fg_pending = !empty($s['badge_pending_text']) ? $s['badge_pending_text'] : '#ffffff';
        $bg_done    = !empty($s['badge_done_bg'])      ? $s['badge_done_bg']      : '#10b981'; // Emerald
        $fg_done    = !empty($s['badge_done_text'])    ? $s['badge_done_text']    : '#ffffff';

        $checked = (bool) get_post_meta($ticket_id, '_tix_ticket_checked_in', true);
        $time    = (string) get_post_meta($ticket_id, '_tix_ticket_checkin_time', true);
        $assigned_raw = trim((string) get_post_meta($ticket_id, '_tix_ticket_assigned_name', true));
        $owner   = (string) get_post_meta($ticket_id, '_tix_ticket_owner_name', true);
        $name    = $assigned_raw !== '' ? $assigned_raw : $owner;
        $assigned = $assigned_raw !== '';

        if ($checked) {
            $when = '';
            if ($time) {
                $ts = strtotime($time);
                if ($ts) $when = ' · ' . date_i18n('H:i', $ts);
            }
            $label = 'Eingecheckt ✓' . $when;
            $bg    = $bg_done;
            $fg    = $fg_done;
        } else {
            $label = 'Noch nicht eingecheckt';
            $bg    = $bg_pending;
            $fg    = $fg_pending;
        }

        return [
            'checked_in' => $checked,
            'name'       => $name,
            'assigned'   => $assigned,
            'time'       => $time,
            'label'      => $label,
            'bg'         => $bg,
            'fg'         => $fg,
            'share'      => self::get_share_info($ticket_id),
        ];
    }

    /**
     * Rendert das Assign-Modal + CSS + JS (delegierte Event-Handler).
     * Wird automatisch nur einmal pro Request ausgegeben (static flag).
     * Geeignet für: Meine-Tickets Shortcode, [tix_order_history], Bundle-Seite
     */
    public static function render_assign_modal_once() {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;

        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <style id="tix-badge-shared-style">
            .tix-badge { display:inline-flex;align-items:center;gap:8px;padding:8px 12px 8px 14px;border-radius:999px;font-size:13px;font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:background .3s,color .3s; }
            .tix-badge .tix-badge-name { font-weight:700; }
            .tix-badge .tix-badge-sep { opacity:.55;margin:0 2px; }
            .tix-badge-edit { display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;margin-left:2px;padding:0;border:0;background:rgba(0,0,0,.12);color:inherit;cursor:pointer;transition:background .2s;flex-shrink:0; }
            .tix-badge-edit:hover { background:rgba(0,0,0,.22); }
            .tix-badge-edit svg { width:12px;height:12px; }
            .tix-shared-info { display:none;align-items:center;gap:6px;padding:5px 4px 5px 11px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:999px;font-size:11px;font-weight:600;width:fit-content; }
            .tix-shared-info[data-has-share="1"] { display:inline-flex; }
            .tix-shared-info .tix-shared-check { width:12px;height:12px; }
            .tix-shared-clear { display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;margin-left:2px;padding:0;border:0;background:rgba(6,95,70,.12);color:inherit;cursor:pointer;transition:background .15s;flex-shrink:0; }
            .tix-shared-clear:hover { background:rgba(6,95,70,.25); }
            .tix-shared-clear svg { width:10px;height:10px; }

            .tix-modal-overlay { position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:99999;padding:20px;backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px); }
            .tix-modal-overlay.open { display:flex; }
            .tix-modal { background:#fff;border-radius:16px;max-width:440px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.35);animation:tixModalIn .25s ease; }
            @keyframes tixModalIn { from{opacity:0;transform:translateY(12px) scale(.98);} to{opacity:1;transform:translateY(0) scale(1);} }
            .tix-modal h3 { margin:0 0 4px;font-size:18px;font-weight:700;color:#111; }
            .tix-modal p.tix-modal-desc { margin:0 0 16px;font-size:13px;color:#666;line-height:1.45; }
            .tix-modal-input { width:100%;padding:12px 14px;font-size:16px;border:1px solid #d1d5db;border-radius:10px;font-family:inherit;margin-bottom:14px;box-sizing:border-box; }
            .tix-modal-input:focus { outline:none;border-color:#111;box-shadow:0 0 0 3px rgba(17,24,39,.08); }
            .tix-modal-actions { display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap; }
            .tix-modal-actions button { padding:10px 18px;font-size:14px;border-radius:10px;font-weight:600;cursor:pointer;border:0;font-family:inherit; }
            .tix-modal-cancel { background:#f3f4f6;color:#111;border:1px solid #e5e7eb; }
            .tix-modal-save { background:#111827;color:#fff; }
            .tix-modal-save:hover { background:#000; }
            .tix-modal-delete { background:transparent;color:#b91c1c;border:1px solid #fecaca;margin-right:auto; }
            .tix-modal-delete:hover { background:#fef2f2; }
            .tix-modal-delete[hidden] { display:none; }
            @media (max-width:640px) {
                .tix-modal { padding:20px;border-radius:14px; }
                .tix-modal-actions { flex-direction:column-reverse; }
                .tix-modal-actions button { width:100%;margin:0; }
            }
        </style>

        <div class="tix-modal-overlay" data-tix-modal>
            <div class="tix-modal" role="dialog" aria-modal="true">
                <h3>Ticket einer Person zuordnen</h3>
                <p class="tix-modal-desc">Ordne das Ticket einer bestimmten Person zu - freiwillig zur Übersicht.</p>
                <input type="text" class="tix-modal-input" maxlength="80" placeholder="Name der Person" data-tix-modal-input>
                <div class="tix-modal-actions">
                    <button type="button" class="tix-modal-delete" data-tix-modal-delete hidden>Zuordnung entfernen</button>
                    <button type="button" class="tix-modal-cancel" data-tix-modal-cancel>Abbrechen</button>
                    <button type="button" class="tix-modal-save" data-tix-modal-save>Speichern</button>
                </div>
            </div>
        </div>

        <script>
        (function(){
            if (window._tixBadgeBound) return;
            window._tixBadgeBound = true;
            window.TIX_AJAX_URL = <?php echo wp_json_encode($ajax_url); ?>;
            var activeCard = null;
            var modal = document.querySelector('[data-tix-modal]');
            if (!modal) return;
            var input = modal.querySelector('[data-tix-modal-input]');

            function findCard(el) {
                return el.closest('[data-ticket-token]');
            }
            function openModal(card) {
                activeCard = card;
                // Ist aktuell ein Name zugewiesen? (Badge hat tix-badge-name span UND wurde vom User gesetzt)
                // Wir holen den tatsächlich gespeicherten assigned_name vom data-attr der Karte (optimistisch)
                var assigned = card.getAttribute('data-assigned-name') || '';
                if (!assigned) {
                    // Fallback: aus dem Badge den Namen lesen — könnte aber auch der Käufer sein
                    var nameEl = card.querySelector('.tix-badge-name');
                    assigned = nameEl ? nameEl.textContent : '';
                }
                input.value = assigned;
                // Delete-Button nur zeigen wenn eine explizite Zuordnung existiert
                var delBtn = modal.querySelector('[data-tix-modal-delete]');
                if (delBtn) delBtn.hidden = card.getAttribute('data-has-assignment') !== '1';
                modal.classList.add('open');
                document.body.style.overflow = 'hidden';
                // Auto-Focus bewusst deaktiviert — kein Mobile-Zoom
            }
            function closeModal() {
                modal.classList.remove('open');
                document.body.style.overflow = '';
                activeCard = null;
            }
            function applyBadge(card, state) {
                var badge = card.querySelector('[data-tix-badge]');
                if (badge) {
                    badge.style.background = state.bg;
                    badge.style.color = state.fg;
                    var label = badge.querySelector('.tix-badge-label');
                    if (label) label.textContent = state.label;
                    var editBtn = badge.querySelector('.tix-badge-edit');
                    var nameEl = badge.querySelector('.tix-badge-name');
                    var sep = badge.querySelector('.tix-badge-sep');
                    if (state.name) {
                        if (!sep) {
                            sep = document.createElement('span'); sep.className = 'tix-badge-sep'; sep.textContent = '·';
                            if (editBtn) badge.insertBefore(sep, editBtn); else badge.appendChild(sep);
                        }
                        if (!nameEl) {
                            nameEl = document.createElement('span'); nameEl.className = 'tix-badge-name';
                            if (editBtn) badge.insertBefore(nameEl, editBtn); else badge.appendChild(nameEl);
                        }
                        nameEl.textContent = state.name;
                    } else {
                        if (nameEl) nameEl.remove();
                        if (sep) sep.remove();
                    }
                }
                // Share-Info aktualisieren falls Endpoint sie mitliefert
                if (state.share) {
                    var shared = card.querySelector('[data-tix-shared-info]');
                    if (!shared) shared = card.parentElement && card.parentElement.querySelector('[data-tix-shared-info]');
                    if (shared) {
                        shared.setAttribute('data-has-share', state.share.has ? '1' : '0');
                        var sl = shared.querySelector('[data-tix-shared-label]');
                        if (sl) sl.textContent = state.share.label || 'Geteilt';
                    }
                }
            }
            window.tixApplyBadgeShared = applyBadge;

            function submitAssign(newName, triggerBtn) {
                if (!activeCard) return;
                var token = activeCard.getAttribute('data-ticket-token');
                var oldText = triggerBtn.textContent;
                triggerBtn.disabled = true; triggerBtn.textContent = 'Speichert…';
                var body = new FormData();
                body.append('action', 'tix_ticket_assign');
                body.append('token', token);
                body.append('name', newName);
                fetch(window.TIX_AJAX_URL, { method:'POST', body:body, credentials:'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        triggerBtn.disabled = false; triggerBtn.textContent = oldText;
                        if (res && res.success) {
                            // data-has-assignment + data-assigned-name auf der Karte aktualisieren
                            activeCard.setAttribute('data-has-assignment', res.data.assigned ? '1' : '0');
                            activeCard.setAttribute('data-assigned-name', res.data.assigned ? res.data.name : '');
                            applyBadge(activeCard, res.data);
                            closeModal();
                        }
                    })
                    .catch(function(){ triggerBtn.disabled = false; triggerBtn.textContent = oldText; });
            }

            // Delegation: Edit-Button-Klick + Shared-Clear
            document.addEventListener('click', function(e){
                var editBtn = e.target.closest('[data-tix-badge-edit]');
                if (editBtn) { e.preventDefault(); var c = findCard(editBtn); if (c) openModal(c); return; }

                var clearBtn = e.target.closest('[data-tix-shared-clear]');
                if (clearBtn) {
                    e.preventDefault();
                    var card = clearBtn.closest('[data-ticket-token]');
                    if (!card) return;
                    var token = card.getAttribute('data-ticket-token');
                    var body = new FormData();
                    body.append('action', 'tix_ticket_clear_share');
                    body.append('token', token);
                    fetch(window.TIX_AJAX_URL, { method:'POST', body:body, credentials:'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (res && res.success) {
                                var shared = card.querySelector('[data-tix-shared-info]');
                                if (shared) shared.setAttribute('data-has-share', '0');
                            }
                        })
                        .catch(function(){});
                    return;
                }

                if (e.target === modal) { closeModal(); return; }
                if (e.target.hasAttribute && e.target.hasAttribute('data-tix-modal-cancel')) { closeModal(); return; }
                if (e.target.hasAttribute && e.target.hasAttribute('data-tix-modal-save')) {
                    submitAssign(input.value, e.target);
                }
                if (e.target.hasAttribute && e.target.hasAttribute('data-tix-modal-delete')) {
                    if (!confirm('Zuordnung wirklich entfernen? Anschließend wird wieder der Name des Käufers angezeigt.')) return;
                    submitAssign('', e.target);
                }
            });
            document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });

            // Polling alle 12s: Badge + Share-Info aktualisieren
            function pollAll() {
                var cards = document.querySelectorAll('[data-ticket-token]');
                cards.forEach(function(card){
                    var token = card.getAttribute('data-ticket-token');
                    if (!token) return;
                    var body = new FormData();
                    body.append('action', 'tix_ticket_status');
                    body.append('token', token);
                    fetch(window.TIX_AJAX_URL, { method:'POST', body:body, credentials:'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(res){ if (res && res.success) applyBadge(card, res.data); })
                        .catch(function(){});
                });
            }
            setInterval(pollAll, 12000);
        })();
        </script>
        <?php
    }

    /**
     * Rendert Badge-Markup (HTML-String) für ein Ticket.
     * Wird in Online-Ticket, Bundle und Meine-Tickets identisch verwendet.
     */
    public static function render_badge_markup($ticket_id, $state = null) {
        if (!$state) $state = self::get_badge_state($ticket_id);
        $html  = '<div class="tix-badge" data-tix-badge style="background:' . esc_attr($state['bg']) . ';color:' . esc_attr($state['fg']) . ';">';
        $html .= '<span class="tix-badge-label">' . esc_html($state['label']) . '</span>';
        if (!empty($state['name'])) {
            $html .= '<span class="tix-badge-sep">·</span><span class="tix-badge-name">' . esc_html($state['name']) . '</span>';
        }
        $html .= '<button type="button" class="tix-badge-edit" data-tix-badge-edit aria-label="Namen ändern" title="Namen ändern">';
        $html .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
        $html .= '</button></div>';
        return $html;
    }

    /**
     * Gibt shared-info Badge-Markup zurück (zeigt sich nur wenn has_share).
     * Enthält einen X-Button zum Entfernen der Share-Info.
     */
    public static function render_shared_info_markup($ticket_id) {
        $info = self::get_share_info($ticket_id);
        $has  = $info['has'] ? '1' : '0';
        $html  = '<div class="tix-shared-info" data-tix-shared-info data-has-share="' . $has . '">';
        $html .= '<svg class="tix-shared-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        $html .= '<span data-tix-shared-label>' . esc_html($info['label'] ?: 'Geteilt') . '</span>';
        $html .= '<button type="button" class="tix-shared-clear" data-tix-shared-clear aria-label="Geteilt-Markierung entfernen" title="Entfernen">';
        $html .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        $html .= '</button>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Share-Info eines Tickets: zuletzt geteilt am + optionaler Empfänger.
     * Genutzt vom Online-Ticket, Bundle und Meine-Tickets für die persistente
     * "Geteilt am …"-Anzeige.
     */
    public static function get_share_info($ticket_id) {
        $at = (string) get_post_meta($ticket_id, '_tix_ticket_shared_at', true);
        $to = (string) get_post_meta($ticket_id, '_tix_ticket_shared_to', true);
        $count = intval(get_post_meta($ticket_id, '_tix_ticket_shared_count', true));
        $label = '';
        if ($at) {
            $ts = strtotime($at);
            if ($ts) {
                $label = 'Geteilt am ' . date_i18n('d.m.Y, H:i', $ts);
                if ($to !== '') $label .= ' · an ' . $to;
            }
        }
        return [
            'at'    => $at,
            'to'    => $to,
            'count' => $count,
            'label' => $label,
            'has'   => $at !== '',
        ];
    }

    /**
     * AJAX: Share-Event loggen (Zeitstempel + optionaler Empfänger).
     * Öffentlich — Token = Besitz-Nachweis.
     */
    public static function ajax_ticket_log_share() {
        $token = isset($_REQUEST['token']) ? sanitize_text_field($_REQUEST['token']) : '';
        $to    = isset($_REQUEST['to'])    ? sanitize_text_field(wp_unslash($_REQUEST['to'])) : '';
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            wp_send_json_error(['message' => 'invalid_token'], 400);
        }
        $to = mb_substr(wp_strip_all_tags($to), 0, 80);

        $results = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                ['key' => '_tix_ticket_download_token', 'value' => $token],
            ],
        ]);
        if (empty($results)) wp_send_json_error(['message' => 'not_found'], 404);
        $ticket_id = $results[0]->ID;

        update_post_meta($ticket_id, '_tix_ticket_shared_at', current_time('c'));
        $count = intval(get_post_meta($ticket_id, '_tix_ticket_shared_count', true)) + 1;
        update_post_meta($ticket_id, '_tix_ticket_shared_count', $count);
        if ($to !== '') update_post_meta($ticket_id, '_tix_ticket_shared_to', $to);

        wp_send_json_success(self::get_share_info($ticket_id));
    }

    /**
     * AJAX: Share-Info löschen (X-Button im Geteilt-Badge).
     * Entfernt _tix_ticket_shared_at/_to/_count — Badge verschwindet.
     */
    public static function ajax_ticket_clear_share() {
        $token = isset($_REQUEST['token']) ? sanitize_text_field($_REQUEST['token']) : '';
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            wp_send_json_error(['message' => 'invalid_token'], 400);
        }
        $results = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                ['key' => '_tix_ticket_download_token', 'value' => $token],
            ],
        ]);
        if (empty($results)) wp_send_json_error(['message' => 'not_found'], 404);
        $ticket_id = $results[0]->ID;

        delete_post_meta($ticket_id, '_tix_ticket_shared_at');
        delete_post_meta($ticket_id, '_tix_ticket_shared_to');
        delete_post_meta($ticket_id, '_tix_ticket_shared_count');

        wp_send_json_success(self::get_share_info($ticket_id));
    }

    /**
     * AJAX: Admin-Check-in-Toggle (aus "Verkaufte Tickets").
     * Ruft denselben Backend-Code wie der Scanner → 100% Sync.
     */
    public static function ajax_admin_checkin_toggle() {
        check_ajax_referer('tix_ticket_action', 'nonce');
        if (!current_user_can('manage_options') && !current_user_can('edit_others_posts')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        if (!$ticket_id) wp_send_json_error(['message' => 'invalid_id'], 400);
        $post = get_post($ticket_id);
        if (!$post || $post->post_type !== 'tix_ticket') {
            wp_send_json_error(['message' => 'not_found'], 404);
        }

        $checked = self::is_checked_in($ticket_id);
        $by = wp_get_current_user()->user_login ?: 'admin';

        if ($checked) {
            self::reset_checkin($ticket_id);
            $new_checked = false;
            $msg = 'Check-in zurückgesetzt.';
        } else {
            self::checkin_ticket($ticket_id, $by);
            $new_checked = true;
            $msg = 'Eingecheckt.';
        }

        // Custom-DB aktualisieren (falls aktiviert) — identisch zur Scanner-Logik
        try {
            $code = get_post_meta($ticket_id, '_tix_ticket_code', true);
            if ($code && class_exists('TIX_Ticket_DB') && class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled')) {
                TIX_Ticket_DB::update_ticket($code, [
                    'checked_in'    => $new_checked ? 1 : 0,
                    'checkin_time'  => $new_checked ? current_time('mysql') : null,
                    'ticket_status' => $new_checked ? 'used' : 'valid',
                ]);
            }
        } catch (\Throwable $e) { /* noop */ }

        wp_send_json_success([
            'checked_in' => $new_checked,
            'message'    => $msg,
            'time'       => $new_checked ? current_time('c') : '',
            'by'         => $by,
            'state'      => self::get_badge_state($ticket_id),
        ]);
    }

    /**
     * AJAX: Live-Status eines Tickets (für Badge-Polling).
     * Erwartet token (Download-Token) im POST. Public-accessible (Online-Ticket-Seite).
     */
    public static function ajax_ticket_status() {
        $token = isset($_REQUEST['token']) ? sanitize_text_field($_REQUEST['token']) : '';
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            wp_send_json_error(['message' => 'invalid_token'], 400);
        }
        $results = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                ['key' => '_tix_ticket_download_token', 'value' => $token],
            ],
        ]);
        if (empty($results)) wp_send_json_error(['message' => 'not_found'], 404);

        $state = self::get_badge_state($results[0]->ID);
        wp_send_json_success($state);
    }

    /**
     * AJAX: Ticket einer Person zuordnen (Name speichern).
     * Erwartet token + name. Öffentlich — Token = Besitz-Nachweis.
     */
    public static function ajax_ticket_assign() {
        $token = isset($_REQUEST['token']) ? sanitize_text_field($_REQUEST['token']) : '';
        $name  = isset($_REQUEST['name'])  ? sanitize_text_field(wp_unslash($_REQUEST['name'])) : '';
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            wp_send_json_error(['message' => 'invalid_token'], 400);
        }
        // Name: max 80 Zeichen, HTML entfernen
        $name = mb_substr(wp_strip_all_tags($name), 0, 80);

        $results = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [
                ['key' => '_tix_ticket_download_token', 'value' => $token],
            ],
        ]);
        if (empty($results)) wp_send_json_error(['message' => 'not_found'], 404);

        $ticket_id = $results[0]->ID;
        if ($name === '') {
            delete_post_meta($ticket_id, '_tix_ticket_assigned_name');
        } else {
            update_post_meta($ticket_id, '_tix_ticket_assigned_name', $name);
        }

        $state = self::get_badge_state($ticket_id);
        wp_send_json_success($state);
    }

    // ══════════════════════════════════════════════
    // SAMMEL-ONLINE-TICKET (alle Tickets einer Bestellung)
    // ══════════════════════════════════════════════

    /**
     * Rendert eine HTML-Seite mit allen Tickets einer Bestellung.
     * Jedes Ticket bekommt denselben Badge + Personen-Zuordnungs-UI wie die
     * Einzelansicht.
     */
    public static function render_bundle_html($order_id) {
        $tickets = self::get_tickets_by_order($order_id);
        if (empty($tickets)) wp_die('Keine Tickets gefunden.', 'Fehler', ['response' => 404]);

        // Design-Settings laden
        $s = function_exists('tix_get_settings') ? get_option('tix_settings', []) : [];
        $hd = [
            'ht_header_bg'     => '#222222', 'ht_header_text'   => '#ffffff',
            'ht_body_bg'       => '#ffffff', 'ht_text_color'    => '#1a1a1a',
            'ht_label_color'   => '#888888', 'ht_border_color'  => '#222222',
            'ht_footer_color'  => '#888888', 'ht_divider_color' => '#cccccc',
            'ht_btn_bg'        => '#222222', 'ht_btn_text'      => '#ffffff',
            'ht_border_radius' => 12,
            'ht_logo_height'   => 44,
            'ht_footer_text'   => 'Bitte dieses Ticket ausgedruckt oder digital zum Einlass mitbringen.',
            'ht_logo_url'      => '',
            'ht_version'       => 'v1',
        ];
        foreach ($hd as $k => $v) {
            $$k = isset($s[$k]) && $s[$k] !== '' ? $s[$k] : $v;
        }
        $ht_border_radius = intval($ht_border_radius);
        $ht_logo_height   = intval($ht_logo_height);
        $ht_version       = in_array($ht_version, ['v1', 'v2'], true) ? $ht_version : 'v1';
        // V2 verwendet die Primärfarbe aus den Tixomat-Einstellungen (Design-Tab,
        // color_primary) — gleiche Marken-Identität überall.
        $accent = !empty($s['color_primary']) ? $s['color_primary'] : '#FF5500';

        $total = count($tickets);
        $buyer_name  = get_post_meta($tickets[0]->ID, '_tix_ticket_owner_name', true);
        $buyer_email = get_post_meta($tickets[0]->ID, '_tix_ticket_owner_email', true);

        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="de" class="tix-ht-<?php echo esc_attr($ht_version); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tickets zur Bestellung #<?php echo intval($order_id); ?></title>
    <style>
        @media print {
            @page { margin: 0; }
            body { margin: 0 !important; padding: 10px !important; background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .tix-bundle-card { box-shadow: none !important; page-break-after: always; }
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f0; padding: 20px; color: <?php echo esc_attr($ht_text_color); ?>; }

        .tix-bundle-head { max-width: 600px; margin: 0 auto 18px; text-align: center; }
        .tix-bundle-head h1 { font-size: 22px; margin-bottom: 4px; }
        .tix-bundle-head p { color: #555; font-size: 14px; }
        .tix-assign-hint {
            max-width: 600px; margin: 0 auto;
            padding: 12px 4px; /* gleiches Padding oben + unten */
            font-size: 12px; color: #666; text-align: center;
        }

        .tix-bundle-list { max-width: 600px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }

        .tix-bundle-card { background: <?php echo esc_attr($ht_body_bg); ?>; border: 2px solid <?php echo esc_attr($ht_border_color); ?>; border-radius: <?php echo $ht_border_radius; ?>px; overflow: hidden; }
        .tix-bundle-header { background: <?php echo esc_attr($ht_header_bg); ?>; color: <?php echo esc_attr($ht_header_text); ?>; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .tix-bundle-logo { max-height: <?php echo $ht_logo_height; ?>px; width: auto; flex-shrink: 0; }
        .tix-bundle-title h2 { font-size: 17px; margin-bottom: 2px; }
        .tix-bundle-title p { font-size: 12px; opacity: .75; }

        .tix-badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 12px 8px 14px; border-radius: 999px;
            font-size: 13px; font-weight: 600;
            transition: background .3s, color .3s;
            box-shadow: 0 1px 3px rgba(0,0,0,.15);
        }
        .tix-badge .tix-badge-name { font-weight: 700; }
        .tix-badge .tix-badge-sep { opacity: .6; margin: 0 2px; }
        .tix-badge-edit {
            display: inline-flex; align-items: center; justify-content: center;
            width: 24px; height: 24px; border-radius: 50%;
            margin-left: 2px; padding: 0; border: 0;
            background: rgba(0,0,0,.12); color: inherit;
            cursor: pointer; transition: background .2s;
            flex-shrink: 0;
        }
        .tix-badge-edit:hover { background: rgba(0,0,0,.22); }
        .tix-badge-edit svg { width: 12px; height: 12px; }
        .tix-bundle-body { padding: 16px 20px; display: flex; gap: 16px; }
        .tix-bundle-info { flex: 1; min-width: 0; }
        .tix-bundle-qr { flex: 0 0 110px; text-align: center; }
        .tix-bundle-qr img { width: 110px; height: 110px; }
        .tix-bundle-qr .code { font-family: monospace; font-size: 11px; font-weight: bold; margin-top: 4px; letter-spacing: 1.5px; }
        .info-row { margin-bottom: 10px; }
        .info-row .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: <?php echo esc_attr($ht_label_color); ?>; margin-bottom: 2px; }
        .info-row .value { font-size: 14px; font-weight: 600; }
        .tix-bundle-actions { padding: 10px 20px; display: flex; flex-wrap: wrap; gap: 8px; border-top: 1px dashed <?php echo esc_attr($ht_divider_color); ?>; }
        .tix-bundle-actions button, .tix-bundle-actions a {
            font-size: 12px; padding: 7px 12px; border-radius: 6px;
            background: #f3f4f6; border: 1px solid #e5e7eb; color: #111827;
            cursor: pointer; text-decoration: none;
        }
        .tix-bundle-actions button:hover { background: #e5e7eb; }
        .tix-bundle-footer { padding: 10px 20px; font-size: 11px; color: <?php echo esc_attr($ht_footer_color); ?>; text-align: center; border-top: 1px dashed <?php echo esc_attr($ht_divider_color); ?>; }

        .tix-shared-info {
            display: none;
            align-items: center; justify-content: center; gap: 6px;
            padding: 5px 4px 5px 11px; background: #ecfdf5; color: #065f46;
            border: 1px solid #a7f3d0; border-radius: 999px;
            font-size: 11px; font-weight: 600;
            width: fit-content;
        }
        .tix-shared-info[data-has-share="1"] { display: inline-flex; }
        .tix-shared-info .tix-shared-check { width: 11px; height: 11px; }
        .tix-shared-clear {
            display: inline-flex; align-items: center; justify-content: center;
            width: 18px; height: 18px; border-radius: 50%;
            margin-left: 2px; padding: 0; border: 0;
            background: rgba(6,95,70,.12); color: inherit;
            cursor: pointer; transition: background .15s ease;
            flex-shrink: 0;
        }
        .tix-shared-clear:hover { background: rgba(6,95,70,.25); }
        .tix-shared-clear svg { width: 10px; height: 10px; }
        .tix-bundle-shared-row { display: flex; justify-content: center; padding: 0 20px 10px; }
        .tix-bundle-shared-row:has(.tix-shared-info[data-has-share="0"]) { display: none; }

        /* ── Modal ── */
        .tix-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none; align-items: center; justify-content: center;
            z-index: 99999; padding: 20px;
            backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
        }
        .tix-modal-overlay.open { display: flex; }
        .tix-modal {
            background: #fff; border-radius: 16px; max-width: 440px; width: 100%;
            padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,.35);
            animation: tixModalIn .25s ease;
        }
        @keyframes tixModalIn {
            from { opacity: 0; transform: translateY(12px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .tix-modal h3 { margin: 0 0 4px; font-size: 18px; font-weight: 700; color: #111; }
        .tix-modal p.tix-modal-desc { margin: 0 0 16px; font-size: 13px; color: #666; line-height: 1.45; }
        .tix-modal-input {
            width: 100%; padding: 12px 14px; font-size: 15px;
            border: 1px solid #d1d5db; border-radius: 10px;
            font-family: inherit; margin-bottom: 14px; box-sizing: border-box;
        }
        .tix-modal-input:focus { outline: none; border-color: #111; box-shadow: 0 0 0 3px rgba(17,24,39,.08); }
        .tix-modal-actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
        .tix-modal-actions button {
            padding: 10px 18px; font-size: 14px; border-radius: 10px;
            font-weight: 600; cursor: pointer; border: 0; font-family: inherit;
        }
        .tix-modal-cancel { background: #f3f4f6; color: #111; border: 1px solid #e5e7eb; }
        .tix-modal-save { background: #111827; color: #fff; }
        .tix-modal-save:hover { background: #000; }
        .tix-modal-delete { background: transparent; color: #b91c1c; border: 1px solid #fecaca; margin-right: auto; }
        .tix-modal-delete:hover { background: #fef2f2; }
        .tix-modal-delete[hidden] { display: none; }
        @media (max-width: 640px) {
            .tix-modal-actions { flex-direction: column-reverse; }
            .tix-modal-actions button { width: 100%; margin: 0; }
        }

        @media (max-width: 640px) {
            .tix-bundle-body { flex-direction: column; align-items: center; text-align: center; }
            .tix-bundle-qr { flex: 0 0 auto; }
            .tix-bundle-info { width: 100%; }
            .tix-bundle-header { justify-content: center; text-align: center; }
        }

        /* ═══════════════════════════════════════
           V2 · PREMIUM DESIGN (Override-Layer Bundle)
           ═══════════════════════════════════════ */
        html.tix-ht-v2 body {
            background: radial-gradient(circle at 20% 0%, rgba(139,92,246,.08), transparent 50%),
                        radial-gradient(circle at 80% 100%, rgba(59,130,246,.06), transparent 50%),
                        #f7f7f8;
            min-height: 100vh;
        }
        html.tix-ht-v2 .tix-bundle-head h1 {
            font-size: 24px; font-weight: 800; letter-spacing: -.02em;
        }
        html.tix-ht-v2 .tix-bundle-card {
            position: relative;
            box-shadow: 0 18px 40px -18px rgba(17,24,39,.18),
                        0 8px 20px -8px rgba(17,24,39,.06),
                        0 0 0 1px rgba(17,24,39,.04);
            overflow: hidden;
            background:
                linear-gradient(<?php echo esc_attr($ht_body_bg); ?>, <?php echo esc_attr($ht_body_bg); ?>) padding-box,
                linear-gradient(135deg, <?php echo esc_attr($ht_header_bg); ?> 0%, <?php echo esc_attr($accent); ?> 100%) border-box;
            border: 2px solid transparent;
        }
        /* Accent-Strip am Header-Rand (nur unten gerundet, innerhalb des Tickets) */
        html.tix-ht-v2 .tix-bundle-card::before {
            content: "";
            position: absolute; left: 14%; right: 14%; top: 0; height: 3px;
            background: linear-gradient(90deg, transparent, <?php echo esc_attr($accent); ?>, transparent);
            border-radius: 0 0 999px 999px;
            opacity: .7;
            z-index: 2;
            pointer-events: none;
        }
        html.tix-ht-v2 .tix-bundle-header {
            background: linear-gradient(135deg,
                <?php echo esc_attr($ht_header_bg); ?> 0%,
                <?php echo esc_attr($ht_header_bg); ?> 60%,
                color-mix(in srgb, <?php echo esc_attr($ht_header_bg); ?> 82%, <?php echo esc_attr($accent); ?> 18%) 100%);
            padding: 20px 22px;
            position: relative;
            overflow: hidden;
        }
        html.tix-ht-v2 .tix-bundle-header::after {
            content: "";
            position: absolute; inset: 0;
            background: radial-gradient(ellipse at top right, rgba(255,255,255,.10), transparent 55%);
            pointer-events: none;
        }
        html.tix-ht-v2 .tix-bundle-title h2 {
            font-size: 18px; font-weight: 800;
            letter-spacing: -.015em;
            text-shadow: 0 1px 0 rgba(0,0,0,.1);
        }
        html.tix-ht-v2 .tix-bundle-title p {
            font-size: 12px; font-weight: 500;
            opacity: .85; letter-spacing: .02em;
        }

        /* Perforation zwischen Header und Body */
        html.tix-ht-v2 .tix-bundle-body {
            position: relative;
            padding-top: 28px;
        }
        html.tix-ht-v2 .tix-bundle-body::before {
            content: "";
            position: absolute; left: 0; right: 0; top: 10px;
            height: 2px;
            background-image: radial-gradient(circle, <?php echo esc_attr($ht_divider_color); ?> 1px, transparent 1.4px);
            background-size: 10px 2px;
            background-repeat: repeat-x;
            background-position: center;
        }

        html.tix-ht-v2 .info-row .label {
            font-size: 9.5px; letter-spacing: 1.4px; font-weight: 700;
            color: color-mix(in srgb, <?php echo esc_attr($ht_label_color); ?> 88%, <?php echo esc_attr($accent); ?> 12%);
        }
        html.tix-ht-v2 .info-row .value {
            font-size: 14px; font-weight: 600; letter-spacing: -.005em;
        }

        /* QR mit Corner-Brackets (kompakter als Einzelticket-V2) */
        html.tix-ht-v2 .tix-bundle-qr {
            position: relative;
            padding: 8px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 10px -6px rgba(17,24,39,.08), 0 0 0 1px rgba(17,24,39,.05);
        }
        html.tix-ht-v2 .tix-bundle-qr::before,
        html.tix-ht-v2 .tix-bundle-qr::after {
            content: "";
            position: absolute;
            width: 11px; height: 11px;
            border: 2px solid <?php echo esc_attr($accent); ?>;
        }
        html.tix-ht-v2 .tix-bundle-qr::before {
            top: -3px; left: -3px;
            border-right: 0; border-bottom: 0;
            border-radius: 3px 0 0 0;
        }
        html.tix-ht-v2 .tix-bundle-qr::after {
            bottom: -3px; right: -3px;
            border-left: 0; border-top: 0;
            border-radius: 0 0 3px 0;
        }
        html.tix-ht-v2 .tix-bundle-qr .code {
            font-family: 'SF Mono', 'Fira Code', Consolas, monospace;
            letter-spacing: 2px; font-weight: 700;
        }

        html.tix-ht-v2 .tix-bundle-actions button,
        html.tix-ht-v2 .tix-bundle-actions a {
            border-radius: 8px;
            transition: transform .12s ease, background .15s ease;
            font-weight: 600;
        }
        html.tix-ht-v2 .tix-bundle-actions button:active,
        html.tix-ht-v2 .tix-bundle-actions a:active { transform: translateY(1px); }

        html.tix-ht-v2 .tix-bundle-footer {
            background: color-mix(in srgb, <?php echo esc_attr($ht_body_bg); ?> 94%, <?php echo esc_attr($ht_border_color); ?> 6%);
            font-size: 11px; font-weight: 500; letter-spacing: .01em;
        }
    </style>
</head>
<body>
    <div class="tix-bundle-head">
        <h1>Tickets zur Bestellung #<?php echo intval($order_id); ?></h1>
        <p><?php echo intval($total); ?> <?php echo $total === 1 ? 'Ticket' : 'Tickets'; ?> für <?php echo esc_html($buyer_name ?: $buyer_email); ?></p>
    </div>

    <div class="tix-bundle-list">
        <?php
        $counter = 1;
        foreach ($tickets as $t) {
            $ticket_id = $t->ID;
            $code       = get_post_meta($ticket_id, '_tix_ticket_code', true);
            $event_id   = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
            $cat_index  = intval(get_post_meta($ticket_id, '_tix_ticket_cat_index', true));

            $event = get_post($event_id);
            if (!$event) { $counter++; continue; }
            $event_name = $event->post_title;
            $date_start = get_post_meta($event_id, '_tix_date_start', true);
            $time_start = get_post_meta($event_id, '_tix_time_start', true);
            $time_doors = get_post_meta($event_id, '_tix_time_doors', true);
            $location   = get_post_meta($event_id, '_tix_location', true);

            $cats     = get_post_meta($event_id, '_tix_ticket_categories', true);
            $cat_name = '';
            if (is_array($cats) && isset($cats[$cat_index])) {
                $cat_name = $cats[$cat_index]['name'] ?? '';
            }

            $date_display = '';
            if ($date_start) {
                $ts = strtotime($date_start);
                if ($ts) $date_display = date_i18n('l, d. F Y', $ts);
            }

            $token   = self::ensure_download_token($ticket_id);
            $qr_data = 'GL-' . $event_id . '-' . $code;
            $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qr_data);
            $badge   = self::get_badge_state($ticket_id, $s);
            $online_url = self::get_online_view_url($ticket_id);
        ?>
        <?php $bundle_assigned = (string) get_post_meta($ticket_id, '_tix_ticket_assigned_name', true); ?>
        <div class="tix-bundle-card" data-ticket-token="<?php echo esc_attr($token); ?>" data-ticket-id="<?php echo intval($ticket_id); ?>" data-has-assignment="<?php echo $bundle_assigned !== '' ? '1' : '0'; ?>" data-assigned-name="<?php echo esc_attr($bundle_assigned); ?>">
            <div class="tix-bundle-header">
                <?php if ($ht_logo_url): ?>
                    <img src="<?php echo esc_url($ht_logo_url); ?>" alt="Logo" class="tix-bundle-logo">
                <?php endif; ?>
                <div class="tix-bundle-title">
                    <h2>Ticket <?php echo $counter; ?> / <?php echo $total; ?></h2>
                    <p><?php echo esc_html($event_name); ?><?php if ($cat_name): ?> · <?php echo esc_html($cat_name); ?><?php endif; ?></p>
                </div>
                <div class="tix-badge" data-tix-badge style="background: <?php echo esc_attr($badge['bg']); ?>; color: <?php echo esc_attr($badge['fg']); ?>;">
                    <span class="tix-badge-label"><?php echo esc_html($badge['label']); ?></span>
                    <?php if (!empty($badge['name'])): ?>
                        <span class="tix-badge-sep">·</span>
                        <span class="tix-badge-name"><?php echo esc_html($badge['name']); ?></span>
                    <?php endif; ?>
                    <button type="button" class="tix-badge-edit" onclick="tixAssignOpen(this)" aria-label="Namen ändern" title="Namen ändern">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                    </button>
                </div>
            </div>
            <div class="tix-bundle-body">
                <div class="tix-bundle-info">
                    <?php if ($date_display): ?>
                        <div class="info-row"><div class="label">Datum</div><div class="value"><?php echo esc_html($date_display); ?></div></div>
                    <?php endif; ?>
                    <?php if ($time_start): ?>
                        <div class="info-row"><div class="label"><?php echo $time_doors ? 'Einlass / Beginn' : 'Beginn'; ?></div><div class="value"><?php if ($time_doors): echo esc_html($time_doors) . ' / '; endif; echo esc_html($time_start); ?> Uhr</div></div>
                    <?php endif; ?>
                    <?php if ($location): ?>
                        <div class="info-row"><div class="label">Location</div><div class="value"><?php echo esc_html($location); ?></div></div>
                    <?php endif; ?>
                </div>
                <div class="tix-bundle-qr">
                    <img src="<?php echo esc_url($qr_url); ?>" alt="QR-Code">
                    <div class="code"><?php echo esc_html($code); ?></div>
                </div>
            </div>
            <?php $share_info = self::get_share_info($ticket_id); ?>
            <div class="tix-bundle-actions no-print">
                <a href="<?php echo esc_url($online_url); ?>" target="_blank">Einzel-Ansicht</a>
                <button type="button" onclick="tixBundleShare(this)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:3px;"><path d="M4 12v7a2 2 0 002 2h12a2 2 0 002-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                    Teilen
                </button>
            </div>
            <div class="tix-bundle-shared-row">
                <div class="tix-shared-info" data-tix-shared-info data-has-share="<?php echo $share_info['has'] ? '1' : '0'; ?>">
                    <svg class="tix-shared-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span data-tix-shared-label><?php echo esc_html($share_info['label'] ?: 'Geteilt'); ?></span>
                    <button type="button" class="tix-shared-clear" onclick="tixBundleShareClear(this)" aria-label="Geteilt-Markierung entfernen" title="Entfernen">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            </div>
            <div class="tix-bundle-footer">
                <?php echo esc_html($ht_footer_text); ?>
            </div>
        </div>
        <?php $counter++; } ?>
    </div>

    <div class="tix-assign-hint">Ordne das Ticket einer bestimmten Person zu - freiwillig zur Übersicht.</div>

    <div class="tix-modal-overlay" data-tix-modal onclick="tixAssignOverlayClose(event)">
        <div class="tix-modal" role="dialog" aria-modal="true" aria-labelledby="tix-modal-title">
            <h3 id="tix-modal-title">Ticket einer Person zuordnen</h3>
            <p class="tix-modal-desc">Ordne das Ticket einer bestimmten Person zu - freiwillig zur Übersicht.</p>
            <input type="text" class="tix-modal-input" maxlength="80" placeholder="Name der Person" data-tix-modal-input>
            <div class="tix-modal-actions">
                <button type="button" class="tix-modal-delete" onclick="tixAssignDelete()" hidden>Zuordnung entfernen</button>
                <button type="button" class="tix-modal-cancel" onclick="tixAssignClose()">Abbrechen</button>
                <button type="button" class="tix-modal-save" onclick="tixAssignSave()">Speichern</button>
            </div>
        </div>
    </div>

    <script>
    var TIX_AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    window.TIX_AJAX_URL = TIX_AJAX;
    var TIX_ACTIVE_CARD = null;

    // Geteilt-Badge per X-Button entfernen
    function tixBundleShareClear(btn) {
        var card = btn.closest('.tix-bundle-card');
        if (!card) return;
        var token = card.getAttribute('data-ticket-token');
        var body = new FormData();
        body.append('action', 'tix_ticket_clear_share');
        body.append('token', token);
        fetch(TIX_AJAX, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res && res.success) {
                    var shared = card.querySelector('[data-tix-shared-info]');
                    if (shared) shared.setAttribute('data-has-share', '0');
                }
            })
            .catch(function(){});
    }

    // Share: URL der Sammel-Ansicht oder Einzel-Ansicht teilen
    function tixBundleShare(btn) {
        var card = btn.closest('.tix-bundle-card');
        if (!card) return;
        var token = card.getAttribute('data-ticket-token');
        var eventName = document.querySelector('.tix-bundle-head h1');
        eventName = eventName ? eventName.textContent : 'Mein Ticket';
        // Bundle-Share: die aktuelle URL (Bundle selbst) ODER die Einzel-URL
        // Einzel-URL ist eindeutiger für eine Person → nehmen wir die
        var einzel = card.querySelector('.tix-bundle-actions a[target]');
        var url = einzel ? einzel.href : window.location.href;
        var originalHTML = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '⏳ öffne…';

        var finished = function(ok) {
            btn.disabled = false; btn.innerHTML = originalHTML;
            if (ok && token) {
                var body = new FormData();
                body.append('action', 'tix_ticket_log_share');
                body.append('token', token);
                fetch(TIX_AJAX, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res && res.success) {
                            var target = card.querySelector('[data-tix-shared-info]');
                            if (target) {
                                target.setAttribute('data-has-share', '1');
                                var sl = target.querySelector('[data-tix-shared-label]');
                                if (sl) sl.textContent = res.data.label || 'Geteilt';
                            }
                        }
                    })
                    .catch(function(){});
            }
        };

        if (navigator.share) {
            navigator.share({ title: eventName, text: 'Dein Ticket für ' + eventName, url: url })
                .then(function(){ finished(true); })
                .catch(function(err){
                    if (err && err.name !== 'AbortError' && navigator.clipboard) {
                        navigator.clipboard.writeText(url).then(function(){
                            btn.innerHTML = '✓ Link kopiert';
                            setTimeout(function(){ finished(true); }, 1500);
                        }).catch(function(){ finished(false); });
                    } else { finished(false); }
                });
        } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function(){
                btn.innerHTML = '✓ Link kopiert';
                setTimeout(function(){ finished(true); }, 1500);
            }).catch(function(){ finished(false); });
        } else {
            window.prompt('Ticket-Link kopieren:', url);
            finished(true);
        }
    }

    function tixAssignOpen(btn) {
        var card = btn.closest('.tix-bundle-card');
        if (!card) return;
        TIX_ACTIVE_CARD = card;
        var modal = document.querySelector('[data-tix-modal]');
        var input = modal.querySelector('[data-tix-modal-input]');
        // Aktuell zugewiesener Name (aus data-Attribut falls gesetzt, sonst aus Badge)
        var assigned = card.getAttribute('data-assigned-name') || '';
        if (!assigned) {
            var nameEl = card.querySelector('.tix-badge-name');
            if (nameEl) assigned = nameEl.textContent;
        }
        input.value = assigned;
        var delBtn = modal.querySelector('.tix-modal-delete');
        if (delBtn) delBtn.hidden = card.getAttribute('data-has-assignment') !== '1';
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        // Auto-Focus bewusst deaktiviert — verhindert Mobile-Zoom beim Keyboard-Öffnen
    }
    function tixAssignClose() {
        var modal = document.querySelector('[data-tix-modal]');
        if (modal) modal.classList.remove('open');
        document.body.style.overflow = '';
        TIX_ACTIVE_CARD = null;
    }
    function tixAssignOverlayClose(e) {
        if (e.target && e.target.hasAttribute('data-tix-modal')) tixAssignClose();
    }
    function tixAssignSubmit(name, triggerBtn) {
        if (!TIX_ACTIVE_CARD) return;
        var card = TIX_ACTIVE_CARD;
        var token = card.getAttribute('data-ticket-token');
        var oldText = triggerBtn.textContent;
        triggerBtn.disabled = true; triggerBtn.textContent = 'Speichert…';
        var body = new FormData();
        body.append('action', 'tix_ticket_assign');
        body.append('token', token);
        body.append('name', name);
        fetch(TIX_AJAX, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                triggerBtn.disabled = false; triggerBtn.textContent = oldText;
                if (res && res.success) {
                    card.setAttribute('data-has-assignment', res.data.assigned ? '1' : '0');
                    card.setAttribute('data-assigned-name', res.data.assigned ? res.data.name : '');
                    tixApplyBadge(card, res.data);
                    tixAssignClose();
                }
            })
            .catch(function(){ triggerBtn.disabled = false; triggerBtn.textContent = oldText; });
    }
    function tixAssignSave() {
        var modal = document.querySelector('[data-tix-modal]');
        var input = modal.querySelector('[data-tix-modal-input]');
        var saveBtn = modal.querySelector('.tix-modal-save');
        tixAssignSubmit(input.value, saveBtn);
    }
    function tixAssignDelete() {
        if (!confirm('Zuordnung wirklich entfernen? Anschließend wird wieder der Name des Käufers angezeigt.')) return;
        var delBtn = document.querySelector('.tix-modal-delete');
        tixAssignSubmit('', delBtn);
    }
    function tixApplyBadge(card, state) {
        var badge = card.querySelector('[data-tix-badge]');
        if (badge) {
            badge.style.background = state.bg;
            badge.style.color = state.fg;
            var label = badge.querySelector('.tix-badge-label');
            if (label) label.textContent = state.label;
            var editBtn = badge.querySelector('.tix-badge-edit');
            var nameEl = badge.querySelector('.tix-badge-name');
            var sep = badge.querySelector('.tix-badge-sep');
            if (state.name) {
                if (!sep) {
                    sep = document.createElement('span'); sep.className = 'tix-badge-sep'; sep.textContent = '·';
                    if (editBtn) badge.insertBefore(sep, editBtn); else badge.appendChild(sep);
                }
                if (!nameEl) {
                    nameEl = document.createElement('span'); nameEl.className = 'tix-badge-name';
                    if (editBtn) badge.insertBefore(nameEl, editBtn); else badge.appendChild(nameEl);
                }
                nameEl.textContent = state.name;
            } else {
                if (nameEl) nameEl.remove();
                if (sep) sep.remove();
            }
        }
        // Share-Info synchronisieren
        if (state.share) {
            var shared = card.querySelector('[data-tix-shared-info]');
            if (shared) {
                shared.setAttribute('data-has-share', state.share.has ? '1' : '0');
                var sl = shared.querySelector('[data-tix-shared-label]');
                if (sl) sl.textContent = state.share.label || 'Geteilt';
            }
        }
    }
    function tixPollAll() {
        var cards = document.querySelectorAll('.tix-bundle-card[data-ticket-token]');
        cards.forEach(function(card){
            var token = card.getAttribute('data-ticket-token');
            if (!token) return;
            var body = new FormData();
            body.append('action', 'tix_ticket_status');
            body.append('token', token);
            fetch(TIX_AJAX, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(res){ if (res && res.success) tixApplyBadge(card, res.data); })
                .catch(function(){});
        });
    }
    setInterval(tixPollAll, 12000);
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') tixAssignClose(); });
    </script>
</body>
</html>
        <?php
    }

    // ══════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════

    /**
     * Eindeutigen Ticket-Code generieren (12 Zeichen, alphanumerisch).
     * Zeichenvorrat: 33 Zeichen (A-Z ohne I/O, 0-9 ohne 0/1) → 33^12 ≈ 1.7 × 10^18
     */
    private static function generate_ticket_code() {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Ohne I, O, 0, 1 (Verwechslungsgefahr)
        $len   = 12;
        $max_attempts = 50;

        for ($a = 0; $a < $max_attempts; $a++) {
            $code = '';
            for ($i = 0; $i < $len; $i++) {
                $code .= $chars[wp_rand(0, strlen($chars) - 1)];
            }

            // Uniqueness-Check
            $existing = self::get_ticket_by_code($code);
            if (!$existing) return $code;
        }

        // Ultra-Fallback: kryptographisch sicher
        return strtoupper(substr(bin2hex(random_bytes(8)), 0, $len));
    }

    /**
     * Ticket-Kategorie-Index finden basierend auf product_id oder SKU
     */
    private static function find_cat_index_by_product($event_id, $product_id, $sku = '') {
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats)) return 0;

        foreach ($cats as $i => $cat) {
            if (intval($cat['product_id'] ?? 0) === intval($product_id)) return $i;
            if ($sku && !empty($cat['sku']) && $cat['sku'] === $sku) return $i;
        }

        return 0;
    }

    // ══════════════════════════════════════════════
    // CHECK-IN
    // ══════════════════════════════════════════════

    /**
     * Ticket einchecken
     */
    public static function checkin_ticket($ticket_id, $by = 'door') {
        update_post_meta($ticket_id, '_tix_ticket_checked_in',   1);
        update_post_meta($ticket_id, '_tix_ticket_checkin_time',  current_time('c'));
        update_post_meta($ticket_id, '_tix_ticket_checkin_by',    $by);
        update_post_meta($ticket_id, '_tix_ticket_status',        'used');

        do_action('tix_ticket_checked_in', $ticket_id);
    }

    /**
     * Check-in zurücksetzen
     */
    public static function reset_checkin($ticket_id) {
        update_post_meta($ticket_id, '_tix_ticket_checked_in',   0);
        update_post_meta($ticket_id, '_tix_ticket_checkin_time',  '');
        update_post_meta($ticket_id, '_tix_ticket_checkin_by',    '');
        update_post_meta($ticket_id, '_tix_ticket_status',        'valid');

        do_action('tix_ticket_checkin_reset', $ticket_id);
    }

    /**
     * Check-in Status prüfen
     */
    public static function is_checked_in($ticket_id) {
        return (bool) get_post_meta($ticket_id, '_tix_ticket_checked_in', true);
    }

    /**
     * Cancel tickets when a native order is cancelled/refunded.
     */
    public static function on_native_order_cancelled($order_id) {
        $tickets = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_key'       => '_tix_ticket_order_id',
            'meta_value'     => $order_id,
        ]);

        foreach ($tickets as $ticket) {
            wp_update_post(['ID' => $ticket->ID, 'post_status' => 'cancelled']);
            update_post_meta($ticket->ID, '_tix_ticket_status', 'cancelled');

            // Release seatmap reservation if applicable
            $seat = get_post_meta($ticket->ID, '_tix_ticket_seat', true);
            $seatmap_id = get_post_meta($ticket->ID, '_tix_ticket_seatmap_id', true);
            if ($seat && $seatmap_id) {
                do_action('tix_release_seat', $seatmap_id, $seat, $ticket->ID);
            }
        }
    }

    /**
     * Ticket-Erstellung für native Orders (ohne WooCommerce).
     * Liest Items direkt aus tix_order_items.
     */
    public static function on_native_order_completed($order_id) {
        global $wpdb;
        $t  = $wpdb->prefix . 'tix_orders';
        $ti = $wpdb->prefix . 'tix_order_items';

        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $order_id));
        if (!$order) return;

        // Guard: Keine Duplikate
        $existing = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_key'       => '_tix_ticket_order_id',
            'meta_value'     => $order_id,
        ]);
        if (!empty($existing)) return;

        $buyer_email = $order->billing_email;
        $buyer_name  = trim($order->billing_first_name . ' ' . $order->billing_last_name);

        $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $ti WHERE order_id = %d", $order_id));

        foreach ($items as $item) {
            $event_id = intval($item->event_id);
            if (!$event_id) continue;

            $qty = intval($item->quantity);
            $meta = $item->meta ? json_decode($item->meta, true) : [];
            $seat_ids   = $meta['seats'] ?? [];
            $seatmap_id = intval($meta['seatmap_id'] ?? 0);
            $has_seats  = is_array($seat_ids) && !empty($seat_ids);

            for ($i = 0; $i < $qty; $i++) {
                $code = self::generate_ticket_code();
                $download_token = hash('sha256', $code . wp_generate_password(32, true, true));

                $ticket_id = wp_insert_post([
                    'post_type'   => 'tix_ticket',
                    'post_status' => 'publish',
                    'post_title'  => $code,
                    'post_author' => $order->customer_id ?: 1,
                    'post_parent' => $order_id,
                ]);

                if (!$ticket_id || is_wp_error($ticket_id)) continue;

                update_post_meta($ticket_id, '_tix_ticket_order_id', $order_id);
                update_post_meta($ticket_id, '_tix_ticket_event_id', $event_id);
                update_post_meta($ticket_id, '_tix_ticket_code', $code);
                update_post_meta($ticket_id, '_tix_ticket_download_token', $download_token);
                update_post_meta($ticket_id, '_tix_ticket_owner_email', $buyer_email);
                update_post_meta($ticket_id, '_tix_ticket_owner_name', $buyer_name);
                update_post_meta($ticket_id, '_tix_ticket_event_name', get_the_title($event_id));
                update_post_meta($ticket_id, '_tix_ticket_cat_name', $item->cat_name);
                update_post_meta($ticket_id, '_tix_ticket_price', $item->total / max(1, $qty));
                update_post_meta($ticket_id, '_tix_ticket_status', 'valid'); // WICHTIG: ohne diese Meta würden Dashboard-Queries Tickets nicht zählen
                update_post_meta($ticket_id, '_tix_ticket_checked_in', 0);

                // Sitzplatz zuweisen
                if ($has_seats && isset($seat_ids[$i])) {
                    update_post_meta($ticket_id, '_tix_ticket_seat_id', $seat_ids[$i]);
                    update_post_meta($ticket_id, '_tix_ticket_seatmap_id', $seatmap_id);
                }

                // Ticket-DB (denormalisierte Tabelle)
                if (class_exists('TIX_Ticket_DB')) {
                    TIX_Ticket_DB::insert_ticket([
                        'ticket_post_id' => $ticket_id,
                        'ticket_code'    => $code,
                        'event_id'       => $event_id,
                        'event_name'     => get_the_title($event_id),
                        'order_id'       => $order_id,
                        'category_name'  => $item->cat_name,
                        'buyer_name'     => $buyer_name,
                        'buyer_email'    => $buyer_email,
                        'ticket_price'   => $item->total / max(1, intval($item->quantity)),
                    ]);
                }
            }
        }
    }
}
