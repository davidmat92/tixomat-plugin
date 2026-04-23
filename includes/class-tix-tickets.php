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
            return $vars;
        });
    }

    public static function handle_download() {
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
        ];
        foreach ($hd as $k => $v) {
            $$k = isset($s[$k]) && $s[$k] !== '' ? $s[$k] : $v;
        }
        $ht_border_radius = intval($ht_border_radius);
        $ht_logo_height   = intval($ht_logo_height);

        $qr_data = 'GL-' . $event_id . '-' . $code;
        $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qr_data);

        ?>
<!DOCTYPE html>
<html lang="de">
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
    </style>
</head>
<body>
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

                <?php if ($owner_name): ?>
                <div class="info-row">
                    <div class="label">Name</div>
                    <div class="value"><?php echo esc_html($owner_name); ?></div>
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
