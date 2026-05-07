<?php
/**
 * Tixomat Migration Tool
 *
 * Domain-zu-Domain-Migration für Tickets, Users und Bestellungen.
 *
 * Workflow:
 *   1. Export auf der ALTEN Domain (vollständige JSON-Datei mit Users, Orders, Tickets, Products)
 *   2. Upload + automatische Produkt-Analyse auf der NEUEN Domain
 *   3. Manuelle Mapping-Konfiguration: WC-Produkt → Event + Ticket-Kategorie
 *   4. Dry-Run mit Bericht
 *   5. Ausführung
 *
 * Was übertragen wird:
 *   - Users mit user_pass-Hash 1:1 (User loggen sich mit altem PW ein)
 *   - WC-Bestellungen → native TIX_Orders mit legacy_wc_order_number-Meta
 *   - Native TIX_Order-Daten
 *   - Tickets mit ursprünglichen Codes
 *   - Event-Mapping per Slug → Title
 *   - Produkt-Mapping manuell (admin entscheidet welcher Slot)
 */
if (!defined('ABSPATH')) exit;

class TIX_Migration {

    const EXPORT_VERSION = 2; // v2 enthält products[]
    const NONCE_KEY = 'tix_migration';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 70);
        add_action('admin_post_tix_migration_export',  [__CLASS__, 'handle_export']);
        add_action('admin_post_tix_migration_upload',  [__CLASS__, 'handle_upload']);
        add_action('admin_post_tix_migration_execute', [__CLASS__, 'handle_execute']);
        add_action('admin_post_tix_migration_clear',   [__CLASS__, 'handle_clear']);
    }

    public static function register_menu() {
        add_submenu_page('tixomat', 'Migration', 'Migration', 'manage_options', 'tix-migration', [__CLASS__, 'render_admin_page']);
    }

    private static function token_key($token) { return 'tix_migration_data_' . preg_replace('/[^a-z0-9]/i', '', $token); }
    private static function map_key($token)   { return 'tix_migration_map_'  . preg_replace('/[^a-z0-9]/i', '', $token); }

    /**
     * Pfad zur temporären Migration-Datei auf Disk.
     * (Statt Transient, weil Transient bei großen Datenmengen via wp_options
     * an max_allowed_packet o.ä. scheitert — silent fail.)
     */
    private static function file_path($token) {
        $clean = preg_replace('/[^a-z0-9]/i', '', $token);
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'tix-migration';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        return $dir . '/migration-' . $clean . '.json';
    }

    private static function load_data($token) {
        $path = self::file_path($token);
        if (!file_exists($path)) return null;
        $json = file_get_contents($path);
        if (!$json) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /** Map-Pfad (separater File neben den Migration-Daten) */
    private static function map_path($token) {
        $clean = preg_replace('/[^a-z0-9]/i', '', $token);
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'tix-migration';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        return $dir . '/map-' . $clean . '.json';
    }

    private static function save_map($token, $map) {
        $path = self::map_path($token);
        return (bool) file_put_contents($path, wp_json_encode($map));
    }

    private static function load_map($token) {
        $path = self::map_path($token);
        if (!file_exists($path)) return null;
        $json = file_get_contents($path);
        if (!$json) return null;
        $map = json_decode($json, true);
        return is_array($map) ? $map : null;
    }

    // ════════════════════════════════════════════════════════════
    // EXPORT (alte Site)
    // ════════════════════════════════════════════════════════════

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_KEY);
        @set_time_limit(0);
        @ini_set('memory_limit', '2048M');
        @ini_set('max_execution_time', '0');
        ignore_user_abort(true);

        // Output buffer leeren — wir streamen direkt
        while (ob_get_level()) ob_end_clean();

        $filename = 'tixomat-migration-' . sanitize_file_name(parse_url(home_url(), PHP_URL_HOST)) . '-' . date('Y-m-d-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Accel-Buffering: no'); // Nginx-Buffering aus

        // Schutz gegen WordPress-Error-HTML im JSON-Stream:
        // Wenn ein Fatal Error auftritt, schreibt WP normalerweise eine Error-Page
        // mitten in den Stream. Der Shutdown-Handler verhindert das.
        $log_path = WP_CONTENT_DIR . '/_tix-export-error.log';
        register_shutdown_function(function() use ($log_path) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR, E_USER_ERROR], true)) {
                // Output-Buffer leeren falls WP welchen geöffnet hat
                while (ob_get_level()) ob_end_clean();
                @file_put_contents($log_path, "[" . date("Y-m-d H:i:s") . "] FATAL: " . $err['message'] . " in " . $err['file'] . ":" . $err['line'] . "\n", FILE_APPEND);
                // Stream sauber mit Error-Marker schließen, damit Importer es erkennt
                echo "],\n\"_export_error\": " . wp_json_encode($err['message']) . "\n}";
                exit;
            }
        });
        // Exception-Handler: ungefangene Exceptions abfangen → loggen, sauber schließen
        set_exception_handler(function($e) use ($log_path) {
            while (ob_get_level()) ob_end_clean();
            @file_put_contents($log_path, "[" . date("Y-m-d H:i:s") . "] EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n", FILE_APPEND);
            echo "],\n\"_export_error\": " . wp_json_encode($e->getMessage()) . "\n}";
            exit;
        });

        self::stream_export();
        exit;
    }

    /**
     * Streamt das gesamte Export-JSON Section für Section direkt in den Output.
     * Nutzt ein einzelnes "echo + flush" pro Datensatz — niemals mehr als ein
     * Record gleichzeitig im Speicher. Verhindert Memory-Exhausted bei großen Sites.
     */
    private static function stream_export() {
        global $wpdb;

        $first = true;
        $emit_array_open = function($key) use (&$first) {
            if (!$first) echo ",\n"; $first = false;
            echo '"' . $key . '": [';
        };
        $sep_first = true;
        $emit_record = function($obj) use (&$sep_first) {
            if (!$sep_first) echo ',';
            $sep_first = false;
            echo wp_json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            flush();
        };
        $emit_array_close = function() use (&$sep_first) {
            echo "]";
            $sep_first = true;
        };

        // ── meta ──
        $meta = [
            'version' => self::EXPORT_VERSION,
            'source_url' => home_url(),
            'source_blog' => get_bloginfo('name'),
            'exported_at' => current_time('mysql'),
            'wp_version' => get_bloginfo('version'),
            'tixomat_version' => defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '?',
            'has_wc' => function_exists('wc_get_orders'),
        ];
        echo "{\n";
        echo '"meta": ' . wp_json_encode($meta, JSON_UNESCAPED_UNICODE);
        // KEIN ",\n" hier — emit_array_open() fügt das Komma vor jedem Folge-Key ein
        $first = false;

        // ── users ──
        $emit_array_open('users');
        $batch = 200; $offset = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, user_login, user_pass, user_email, user_registered, display_name, user_nicename FROM {$wpdb->users} ORDER BY ID ASC LIMIT %d OFFSET %d", $batch, $offset));
            foreach ($rows as $u) {
                $umeta = [];
                $mrows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $u->ID));
                foreach ($mrows as $m) {
                    if (in_array($m->meta_key, ['session_tokens', 'wp_user-settings', 'wp_user-settings-time', 'closedpostboxes_event'], true)) continue;
                    $umeta[$m->meta_key] = maybe_unserialize($m->meta_value);
                }
                $caps = get_user_meta($u->ID, $wpdb->prefix . 'capabilities', true);
                $emit_record([
                    'id' => intval($u->ID),
                    'login' => $u->user_login,
                    'pass'  => $u->user_pass,
                    'email' => $u->user_email,
                    'registered' => $u->user_registered,
                    'display_name' => $u->display_name,
                    'nicename' => $u->user_nicename,
                    'roles' => is_array($caps) ? array_keys($caps) : [],
                    'meta'  => $umeta,
                ]);
            }
            $offset += $batch;
        } while (count($rows) === $batch);
        $emit_array_close();

        // ── events ──
        $emit_array_open('events');
        $offset = 0;
        do {
            $evs = get_posts([
                'post_type' => 'event', 'post_status' => ['publish', 'draft', 'private', 'trash'],
                'posts_per_page' => $batch, 'offset' => $offset, 'no_found_rows' => true,
                'orderby' => 'ID', 'order' => 'ASC', // Stabiles Pagination — sonst Off-by-one bei gleichem post_date
            ]);
            foreach ($evs as $ev) {
                $emit_record([
                    'id' => $ev->ID, 'title' => $ev->post_title, 'slug' => $ev->post_name,
                    'date_start' => get_post_meta($ev->ID, '_tix_date_start', true),
                ]);
                wp_cache_delete($ev->ID, 'posts');
            }
            $offset += $batch;
        } while (count($evs) === $batch);
        $emit_array_close();

        // ── products ──
        $emit_array_open('products');
        if (function_exists('wc_get_products')) {
            $offset = 0;
            do {
                $prods = wc_get_products(['limit' => $batch, 'offset' => $offset, 'status' => ['publish', 'draft', 'private']]);
                foreach ($prods as $p) {
                    $emit_record([
                        'id' => $p->get_id(), 'name' => $p->get_name(), 'price' => floatval($p->get_price()),
                        'sku' => $p->get_sku(),
                        'event_id'  => intval(get_post_meta($p->get_id(), '_tix_parent_event_id', true)),
                        'cat_index' => intval(get_post_meta($p->get_id(), '_tix_ticket_cat_index', true)),
                    ]);
                    wp_cache_delete($p->get_id(), 'posts');
                    wp_cache_delete($p->get_id(), 'post_meta');
                }
                $offset += $batch;
            } while (count($prods) === $batch);
        }
        $emit_array_close();

        // ── wc_orders ──
        $emit_array_open('wc_orders');
        if (function_exists('wc_get_orders')) {
            $offset = 0;
            do {
                $orders = wc_get_orders([
                    'type'   => 'shop_order', // KEINE Refunds!
                    'status' => array_keys(wc_get_order_statuses()),
                    'limit'  => $batch, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC',
                ]);
                foreach ($orders as $o) {
                    // Defensive: Refunds und non-WC_Order Objekte überspringen
                    if (!is_object($o) || !method_exists($o, 'get_order_number')) continue;
                    if ($o instanceof WC_Order_Refund) continue;
                    $items = [];
                    foreach ($o->get_items() as $item) {
                        $pid = $item->get_product_id();
                        $items[] = [
                            'product_id' => $pid, 'product_name' => $item->get_name(),
                            'qty' => $item->get_quantity(), 'total' => floatval($item->get_total()),
                            'event_id' => intval(get_post_meta($pid, '_tix_parent_event_id', true)),
                            'cat_index' => intval(get_post_meta($pid, '_tix_ticket_cat_index', true)),
                        ];
                    }
                    $emit_record([
                        'wc_id' => $o->get_id(), 'order_number' => $o->get_order_number(),
                        'status' => $o->get_status(), 'currency' => $o->get_currency(),
                        'total' => floatval($o->get_total()), 'tax' => floatval($o->get_total_tax()),
                        'date_created' => $o->get_date_created() ? $o->get_date_created()->date('Y-m-d H:i:s') : '',
                        'date_paid' => $o->get_date_paid() ? $o->get_date_paid()->date('Y-m-d H:i:s') : '',
                        'payment_method' => $o->get_payment_method(),
                        'payment_method_title' => $o->get_payment_method_title(),
                        'transaction_id' => $o->get_transaction_id(), 'customer_id' => $o->get_customer_id(),
                        'billing' => [
                            'first_name' => $o->get_billing_first_name(), 'last_name' => $o->get_billing_last_name(),
                            'email' => $o->get_billing_email(), 'phone' => $o->get_billing_phone(),
                            'company' => $o->get_billing_company(), 'address_1' => $o->get_billing_address_1(),
                            'postcode' => $o->get_billing_postcode(), 'city' => $o->get_billing_city(),
                            'country' => $o->get_billing_country(),
                        ],
                        'items' => $items,
                    ]);
                    wp_cache_delete($o->get_id(), 'posts');
                }
                $offset += $batch;
            } while (count($orders) === $batch);
        }
        $emit_array_close();

        // ── tix_orders ──
        $emit_array_open('tix_orders');
        if (class_exists('TIX_Order')) {
            $offset = 0;
            do {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM " . TIX_Order::table_name() . " ORDER BY id ASC LIMIT %d OFFSET %d",
                    $batch, $offset
                ), ARRAY_A);
                foreach ($rows as $r) {
                    $items = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM " . TIX_Order::items_table_name() . " WHERE order_id = %d",
                        $r['id']
                    ), ARRAY_A);
                    $emit_record(['order' => $r, 'items' => $items]);
                }
                $offset += $batch;
            } while (count($rows) === $batch);
        }
        $emit_array_close();

        // ── specials (CPT tix_special) ──
        $emit_array_open('specials');
        $offset = 0;
        do {
            $sps = get_posts([
                'post_type' => 'tix_special', 'post_status' => ['publish', 'draft'],
                'posts_per_page' => $batch, 'offset' => $offset, 'no_found_rows' => true,
                'orderby' => 'ID', 'order' => 'ASC', // Stabiles Pagination — sonst Off-by-one bei gleichem post_date
            ]);
            foreach ($sps as $sp) {
                $thumb_id = get_post_thumbnail_id($sp->ID);
                $emit_record([
                    'id'         => $sp->ID,
                    'title'      => $sp->post_title,
                    'slug'       => $sp->post_name,
                    'content'    => $sp->post_content,
                    'status'     => $sp->post_status,
                    'price'      => floatval(get_post_meta($sp->ID, '_tix_special_price', true)),
                    'value'      => floatval(get_post_meta($sp->ID, '_tix_special_value', true)),
                    'qty'        => intval(get_post_meta($sp->ID, '_tix_special_qty', true)),
                    'template'   => intval(get_post_meta($sp->ID, '_tix_special_template', true)),
                    'product_id' => intval(get_post_meta($sp->ID, '_tix_special_product_id', true)),
                    'image_url'  => $thumb_id ? wp_get_attachment_url($thumb_id) : '',
                ]);
                wp_cache_delete($sp->ID, 'posts');
                wp_cache_delete($sp->ID, 'post_meta');
            }
            $offset += $batch;
        } while (count($sps) === $batch);
        $emit_array_close();

        // ── event_specials (Event → Specials Verbindungen mit Overrides) ──
        $emit_array_open('event_specials');
        $offset = 0;
        do {
            $evs = get_posts([
                'post_type' => 'event', 'post_status' => ['publish', 'draft', 'private'],
                'posts_per_page' => $batch, 'offset' => $offset, 'no_found_rows' => true,
                'meta_key' => '_tix_specials',
            ]);
            foreach ($evs as $ev) {
                $rels = get_post_meta($ev->ID, '_tix_specials', true);
                if (!is_array($rels) || empty($rels)) continue;
                $emit_record([
                    'event_id' => $ev->ID,
                    'specials' => array_values(array_filter($rels, 'is_array')),
                ]);
                wp_cache_delete($ev->ID, 'posts');
            }
            $offset += $batch;
        } while (count($evs) === $batch);
        $emit_array_close();

        // ── tickera_tickets (alte Tickera tc_tickets_instances) ──
        // Tickera nutzt eigene CPTs. Wir exportieren sie hier mit minimalem Mapping
        // damit der Import sie als tix_ticket auf der neuen Site anlegen kann
        // (über post_parent = WC-Order-ID gemappt zur neuen Order).
        $emit_array_open('tickera_tickets');
        $offset = 0;
        do {
            $tcs = get_posts([
                'post_type' => 'tc_tickets_instances',
                'post_status' => ['publish', 'private'],
                'posts_per_page' => $batch, 'offset' => $offset, 'no_found_rows' => true,
                'orderby' => 'ID', 'order' => 'ASC', // Stabiles Pagination — sonst Off-by-one bei gleichem post_date
            ]);
            foreach ($tcs as $tc) {
                $checkins = get_post_meta($tc->ID, 'tc_checkins', true);
                $emit_record([
                    'id'              => $tc->ID,
                    'code'            => get_post_meta($tc->ID, 'ticket_code', true),
                    'tickera_event'   => intval(get_post_meta($tc->ID, 'event_id', true)),
                    'ticket_type_id'  => intval(get_post_meta($tc->ID, 'ticket_type_id', true)),
                    'wc_order_id'     => intval($tc->post_parent),
                    'item_id'         => intval(get_post_meta($tc->ID, 'item_id', true)),
                    'date'            => $tc->post_date,
                    'checked_in'      => is_array($checkins) ? (count($checkins) > 0 ? 1 : 0) : (!empty($checkins) ? 1 : 0),
                ]);
                wp_cache_delete($tc->ID, 'posts');
                wp_cache_delete($tc->ID, 'post_meta');
            }
            $offset += $batch;
        } while (count($tcs) === $batch);
        $emit_array_close();

        // ── tickets ──
        $emit_array_open('tickets');
        $offset = 0;
        do {
            $tps = get_posts([
                'post_type' => 'tix_ticket', 'post_status' => ['publish', 'draft'],
                'posts_per_page' => $batch, 'offset' => $offset, 'no_found_rows' => true,
                'orderby' => 'ID', 'order' => 'ASC', // Stabiles Pagination — sonst Off-by-one bei gleichem post_date
            ]);
            foreach ($tps as $t) {
                $emit_record([
                    'id' => $t->ID, 'title' => $t->post_title, 'date' => $t->post_date,
                    'code' => get_post_meta($t->ID, '_tix_ticket_code', true),
                    'event_id' => intval(get_post_meta($t->ID, '_tix_ticket_event_id', true)),
                    'order_id' => intval(get_post_meta($t->ID, '_tix_ticket_order_id', true)),
                    'cat_index' => intval(get_post_meta($t->ID, '_tix_ticket_cat_index', true)),
                    'product_id' => intval(get_post_meta($t->ID, '_tix_ticket_product_id', true)),
                    'owner_name' => get_post_meta($t->ID, '_tix_ticket_owner_name', true),
                    'owner_email' => get_post_meta($t->ID, '_tix_ticket_owner_email', true),
                    'status' => get_post_meta($t->ID, '_tix_ticket_status', true) ?: 'valid',
                    'checked_in' => get_post_meta($t->ID, '_tix_ticket_checked_in', true) ? 1 : 0,
                    'checkin_time' => get_post_meta($t->ID, '_tix_ticket_checkin_time', true),
                    'assigned_name' => get_post_meta($t->ID, '_tix_ticket_assigned_name', true),
                    'seat_id' => get_post_meta($t->ID, '_tix_ticket_seat_id', true),
                    // Special-Marker für Specials-Tickets
                    'type'       => get_post_meta($t->ID, '_tix_ticket_type', true) ?: '',
                    'special_id' => intval(get_post_meta($t->ID, '_tix_ticket_special_id', true)),
                ]);
                wp_cache_delete($t->ID, 'posts');
                wp_cache_delete($t->ID, 'post_meta');
            }
            $offset += $batch;
        } while (count($tps) === $batch);
        $emit_array_close();

        echo "\n}";
    }

    private static function build_export() {
        global $wpdb;

        // Users
        $users = [];
        $user_rows = $wpdb->get_results("SELECT ID, user_login, user_pass, user_email, user_registered, display_name, user_nicename FROM {$wpdb->users} ORDER BY ID ASC");
        foreach ($user_rows as $u) {
            $meta = [];
            $meta_rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $u->ID));
            foreach ($meta_rows as $m) {
                if (in_array($m->meta_key, ['session_tokens', 'wp_user-settings', 'wp_user-settings-time', 'closedpostboxes_event'], true)) continue;
                $meta[$m->meta_key] = maybe_unserialize($m->meta_value);
            }
            $users[] = [
                'id' => intval($u->ID),
                'login' => $u->user_login,
                'pass'  => $u->user_pass,
                'email' => $u->user_email,
                'registered' => $u->user_registered,
                'display_name' => $u->display_name,
                'nicename' => $u->user_nicename,
                'roles' => array_keys((array) get_userdata($u->ID)->caps ?? []),
                'meta'  => $meta,
            ];
        }

        // Events
        $events = [];
        $ev_posts = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'private', 'trash'], 'posts_per_page' => -1]);
        foreach ($ev_posts as $ev) {
            $events[] = [
                'id'    => $ev->ID,
                'title' => $ev->post_title,
                'slug'  => $ev->post_name,
                'date_start' => get_post_meta($ev->ID, '_tix_date_start', true),
            ];
        }

        // Products (für Mapping auf Ziel-Site)
        $products = [];
        if (function_exists('wc_get_products')) {
            $wc_products = wc_get_products(['limit' => -1, 'status' => ['publish', 'draft', 'private']]);
            foreach ($wc_products as $p) {
                $products[] = [
                    'id'        => $p->get_id(),
                    'name'      => $p->get_name(),
                    'price'     => floatval($p->get_price()),
                    'sku'       => $p->get_sku(),
                    'event_id'  => intval(get_post_meta($p->get_id(), '_tix_parent_event_id', true)),
                    'cat_index' => intval(get_post_meta($p->get_id(), '_tix_ticket_cat_index', true)),
                ];
            }
        }

        // WC-Orders
        $wc_orders = [];
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders(['status' => array_keys(wc_get_order_statuses()), 'limit' => -1, 'orderby' => 'date', 'order' => 'ASC']);
            foreach ($orders as $o) {
                $items = [];
                foreach ($o->get_items() as $item) {
                    $product_id = $item->get_product_id();
                    $items[] = [
                        'product_id'   => $product_id,
                        'product_name' => $item->get_name(),
                        'qty'          => $item->get_quantity(),
                        'total'        => floatval($item->get_total()),
                        'event_id'     => intval(get_post_meta($product_id, '_tix_parent_event_id', true)),
                        'cat_index'    => intval(get_post_meta($product_id, '_tix_ticket_cat_index', true)),
                    ];
                }
                $wc_orders[] = [
                    'wc_id'           => $o->get_id(),
                    'order_number'    => $o->get_order_number(),
                    'status'          => $o->get_status(),
                    'currency'        => $o->get_currency(),
                    'total'           => floatval($o->get_total()),
                    'tax'             => floatval($o->get_total_tax()),
                    'date_created'    => $o->get_date_created() ? $o->get_date_created()->date('Y-m-d H:i:s') : '',
                    'date_paid'       => $o->get_date_paid() ? $o->get_date_paid()->date('Y-m-d H:i:s') : '',
                    'payment_method'  => $o->get_payment_method(),
                    'payment_method_title' => $o->get_payment_method_title(),
                    'transaction_id'  => $o->get_transaction_id(),
                    'customer_id'     => $o->get_customer_id(),
                    'billing'         => [
                        'first_name' => $o->get_billing_first_name(),
                        'last_name'  => $o->get_billing_last_name(),
                        'email'      => $o->get_billing_email(),
                        'phone'      => $o->get_billing_phone(),
                        'company'    => $o->get_billing_company(),
                        'address_1'  => $o->get_billing_address_1(),
                        'postcode'   => $o->get_billing_postcode(),
                        'city'       => $o->get_billing_city(),
                        'country'    => $o->get_billing_country(),
                    ],
                    'items' => $items,
                ];
            }
        }

        // TIX_Order
        $tix_orders = [];
        if (class_exists('TIX_Order')) {
            $rows = $wpdb->get_results("SELECT * FROM " . TIX_Order::table_name() . " ORDER BY id ASC", ARRAY_A);
            foreach ($rows as $r) {
                $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TIX_Order::items_table_name() . " WHERE order_id = %d", $r['id']), ARRAY_A);
                $tix_orders[] = ['order' => $r, 'items' => $items];
            }
        }

        // Tickets
        $tickets = [];
        $ticket_posts = get_posts(['post_type' => 'tix_ticket', 'post_status' => ['publish', 'draft'], 'posts_per_page' => -1]);
        foreach ($ticket_posts as $t) {
            $tickets[] = [
                'id' => $t->ID,
                'title' => $t->post_title,
                'date' => $t->post_date,
                'code' => get_post_meta($t->ID, '_tix_ticket_code', true),
                'event_id' => intval(get_post_meta($t->ID, '_tix_ticket_event_id', true)),
                'order_id' => intval(get_post_meta($t->ID, '_tix_ticket_order_id', true)),
                'cat_index' => intval(get_post_meta($t->ID, '_tix_ticket_cat_index', true)),
                'product_id' => intval(get_post_meta($t->ID, '_tix_ticket_product_id', true)),
                'owner_name'  => get_post_meta($t->ID, '_tix_ticket_owner_name', true),
                'owner_email' => get_post_meta($t->ID, '_tix_ticket_owner_email', true),
                'status'      => get_post_meta($t->ID, '_tix_ticket_status', true) ?: 'valid',
                'checked_in'  => get_post_meta($t->ID, '_tix_ticket_checked_in', true) ? 1 : 0,
                'checkin_time'=> get_post_meta($t->ID, '_tix_ticket_checkin_time', true),
                'assigned_name' => get_post_meta($t->ID, '_tix_ticket_assigned_name', true),
                'seat_id'     => get_post_meta($t->ID, '_tix_ticket_seat_id', true),
            ];
        }

        return [
            'meta' => [
                'version' => self::EXPORT_VERSION,
                'source_url' => home_url(),
                'source_blog' => get_bloginfo('name'),
                'exported_at' => current_time('mysql'),
                'wp_version'  => get_bloginfo('version'),
                'tixomat_version' => defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '?',
                'has_wc' => function_exists('wc_get_orders'),
                'counts' => [
                    'users' => count($users), 'events' => count($events),
                    'products' => count($products), 'wc_orders' => count($wc_orders),
                    'tix_orders' => count($tix_orders), 'tickets' => count($tickets),
                ],
            ],
            'users' => $users,
            'events' => $events,
            'products' => $products,
            'wc_orders' => $wc_orders,
            'tix_orders' => $tix_orders,
            'tickets' => $tickets,
        ];
    }

    // ════════════════════════════════════════════════════════════
    // UPLOAD + PARSE → speichert in Transient → Mapping-Step
    // ════════════════════════════════════════════════════════════

    public static function handle_upload() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_KEY);

        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        if (empty($_FILES['migration_file']['tmp_name'])) {
            // Detail-Diagnose: was war das Problem?
            $err = $_FILES['migration_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $err_map = [
                UPLOAD_ERR_INI_SIZE   => 'too_big_php',  // > upload_max_filesize
                UPLOAD_ERR_FORM_SIZE  => 'too_big_form',
                UPLOAD_ERR_PARTIAL    => 'partial',
                UPLOAD_ERR_NO_FILE    => 'no_file',
                UPLOAD_ERR_NO_TMP_DIR => 'no_tmp',
                UPLOAD_ERR_CANT_WRITE => 'cant_write',
            ];
            $msg = $err_map[$err] ?? 'no_file';
            wp_redirect(add_query_arg(['page' => 'tix-migration', 'msg' => $msg], admin_url('admin.php')));
            exit;
        }

        $json = file_get_contents($_FILES['migration_file']['tmp_name']);
        $size = strlen($json);

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['meta'])) {
            $err_msg = function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown';
            // In Transient stecken damit es auf der Page angezeigt werden kann
            set_transient('tix_migration_upload_err_' . get_current_user_id(), [
                'json_err' => $err_msg,
                'size'     => $size,
                'preview'  => substr($json, 0, 200) . (strlen($json) > 200 ? '…' : ''),
            ], 5 * MINUTE_IN_SECONDS);
            wp_redirect(add_query_arg(['page' => 'tix-migration', 'msg' => 'invalid_json'], admin_url('admin.php')));
            exit;
        }

        // Token + Datei auf Disk speichern (statt Transient — sonst silent fail bei >16MB)
        $token = bin2hex(random_bytes(8));
        $path = self::file_path($token);
        $written = file_put_contents($path, $json);
        if (!$written) {
            wp_redirect(add_query_arg(['page' => 'tix-migration', 'msg' => 'cant_write'], admin_url('admin.php')));
            exit;
        }

        // Auto-Mapping (klein) bleibt im Transient
        $event_map   = self::build_event_map($data['events'] ?? []);

        // Cross-Type-Auto-Vorschlag: Wenn altes Event keinen Event-Match hat,
        // aber ein Special mit gleichem Slug/Title existiert → vorschlagen.
        $event_special_map = [];
        foreach (($data['events'] ?? []) as $se) {
            $old_id = intval($se['id']);
            if (!empty($event_map[$old_id])) continue; // Hat schon Event-Match
            $found_sp = null;
            if (!empty($se['slug'])) {
                $hits = get_posts(['post_type' => 'tix_special', 'name' => $se['slug'], 'posts_per_page' => 1, 'post_status' => 'any']);
                if (!empty($hits)) $found_sp = $hits[0];
            }
            if (!$found_sp && !empty($se['title'])) {
                $hits = get_posts(['post_type' => 'tix_special', 'title' => $se['title'], 'posts_per_page' => 1, 'post_status' => 'any']);
                if (!empty($hits)) $found_sp = $hits[0];
            }
            if ($found_sp) $event_special_map[$old_id] = $found_sp->ID;
        }

        $product_map = [];
        foreach (($data['products'] ?? []) as $p) {
            $old_eid = intval($p['event_id']);
            $new_eid = $event_map[$old_eid] ?? 0;
            $product_map[$p['id']] = [
                'event_id'     => $new_eid,
                'cat_index'    => intval($p['cat_index']),
                'old_event_id' => $old_eid, // für Cross-Type-Resolve via event_special_map
            ];
        }
        // Special-Mapping: Slug/Title-Match auf bestehende Specials der neuen Site
        $special_map = self::build_special_map($data['specials'] ?? []);
        $map_saved = self::save_map($token, [
            'event_map'         => $event_map,
            'event_special_map' => $event_special_map,
            'product_map'       => $product_map,
            'special_map'       => $special_map,
        ]);
        if (!$map_saved) {
            // Map konnte nicht auf Disk geschrieben werden — Daten-File aufräumen, Fehler zeigen
            @unlink($path);
            wp_redirect(add_query_arg(['page' => 'tix-migration', 'msg' => 'cant_write'], admin_url('admin.php')));
            exit;
        }

        wp_redirect(add_query_arg(['page' => 'tix-migration', 'tab' => 'import', 'token' => $token], admin_url('admin.php')));
        exit;
    }

    public static function handle_clear() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_KEY);
        $token = sanitize_text_field($_REQUEST['token'] ?? '');
        if ($token) {
            $path = self::file_path($token);
            if (file_exists($path)) @unlink($path);
            $mpath = self::map_path($token);
            if (file_exists($mpath)) @unlink($mpath);
            delete_transient(self::map_key($token));
        }
        wp_redirect(add_query_arg(['page' => 'tix-migration', 'tab' => 'import'], admin_url('admin.php')));
        exit;
    }

    // ════════════════════════════════════════════════════════════
    // EXECUTE → mit gespeichertem Mapping
    // ════════════════════════════════════════════════════════════

    public static function handle_execute() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_KEY);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $token = sanitize_text_field($_POST['token'] ?? '');
        $data  = $token ? self::load_data($token) : null;
        if (!$data) {
            wp_redirect(add_query_arg(['page' => 'tix-migration', 'msg' => 'expired'], admin_url('admin.php')));
            exit;
        }

        // User-Mapping speichern (wurde aus dem Form gepostet)
        // Plus: alte event_id aus den Export-Daten beibehalten — wird für Cross-Type-Resolve gebraucht
        $old_event_by_pid = [];
        $data_for_old_eid = self::load_data($token);
        if ($data_for_old_eid) {
            foreach (($data_for_old_eid['products'] ?? []) as $p) {
                $old_event_by_pid[intval($p['id'])] = intval($p['event_id']);
            }
        }
        $product_map = [];
        if (!empty($_POST['map']) && is_array($_POST['map'])) {
            foreach ($_POST['map'] as $pid => $cfg) {
                $pid_int = intval($pid);
                $event_id = 0; $special_id = 0;
                $target = sanitize_text_field($cfg['target'] ?? '');
                if (strpos($target, 'event:') === 0) {
                    $event_id = intval(substr($target, 6));
                } elseif (strpos($target, 'special:') === 0) {
                    $special_id = intval(substr($target, 8));
                } elseif (!empty($cfg['event_id'])) {
                    // Backward-compat (alte Form-Submits)
                    $event_id = intval($cfg['event_id']);
                }
                $product_map[$pid_int] = [
                    'event_id'     => $event_id,
                    'special_id'   => $special_id,
                    'cat_index'    => intval($cfg['cat_index'] ?? 0),
                    'old_event_id' => $old_event_by_pid[$pid_int] ?? 0,
                ];
            }
        }

        // Event-Mapping inkl. Cross-Type (altes Event → neues Special)
        // Format: $_POST['event_target'][<old_id>] = "event:N" | "special:N" | "0"
        $event_map = self::build_event_map($data['events'] ?? []);
        $event_special_map = [];
        if (!empty($_POST['event_target']) && is_array($_POST['event_target'])) {
            foreach ($_POST['event_target'] as $old_id => $val) {
                $old_id = intval($old_id);
                $val = sanitize_text_field($val);
                if ($val === '0' || $val === '') {
                    $event_map[$old_id] = 0;
                    continue;
                }
                if (strpos($val, 'event:') === 0) {
                    $event_map[$old_id] = intval(substr($val, 6));
                    unset($event_special_map[$old_id]);
                } elseif (strpos($val, 'special:') === 0) {
                    $event_special_map[$old_id] = intval(substr($val, 8));
                    $event_map[$old_id] = 0; // Items werden als Specials migriert
                }
            }
        }

        // Special-Mapping (Slug/Title-Match auf neuer Site)
        $special_map = self::build_special_map($data['specials'] ?? []);

        // Aktualisierte Mappings persistieren (für mögliche Re-Runs)
        self::save_map($token, [
            'event_map'         => $event_map,
            'event_special_map' => $event_special_map,
            'product_map'       => $product_map,
            'special_map'       => $special_map,
        ]);

        $dry_run     = !empty($_POST['dry_run']);
        $do_users    = !empty($_POST['import_users']);
        $do_orders   = !empty($_POST['import_orders']);
        $do_tickets  = !empty($_POST['import_tickets']);
        $do_specials = !empty($_POST['import_specials']);
        $fallback_event_id = intval($_POST['fallback_event_id'] ?? 0);

        $report = [
            'dry_run' => $dry_run,
            'event_map' => $event_map,
            'event_special_map' => $event_special_map,
            'product_map' => $product_map,
            'special_map' => $special_map,
            'fallback_event_id' => $fallback_event_id,
        ];
        if ($do_users)    $report['users']    = self::import_users($data['users'] ?? [], $dry_run);
        // Specials VOR Orders/Tickets — Tickets brauchen das Mapping!
        if ($do_specials) {
            $sp_result = self::import_specials($data['specials'] ?? [], $special_map, $dry_run);
            $report['specials']        = $sp_result['summary'];
            $special_map               = $sp_result['map']; // Updated mit neu erstellten IDs
            $report['special_map']     = $special_map;
            $report['event_specials']  = self::import_event_specials($data['event_specials'] ?? [], $event_map, $special_map, $dry_run);
            // Re-save für nachgelagerte Schritte
            self::save_map($token, [
                'event_map'   => $event_map,
                'product_map' => $product_map,
                'special_map' => $special_map,
            ]);
        }
        if ($do_orders)  $report['orders']  = self::import_orders($data['wc_orders'] ?? [], $data['tix_orders'] ?? [], $event_map, $product_map, $dry_run, $event_special_map, $fallback_event_id);
        if ($do_tickets) {
            $report['tickets'] = self::import_tickets($data['tickets'] ?? [], $event_map, $product_map, $special_map, $dry_run, $event_special_map, $fallback_event_id);
            // Tickera-Tickets ZUSÄTZLICH (nutzen wc_order_id zum Mapping)
            if (!empty($data['tickera_tickets'])) {
                $report['tickera_tickets'] = self::import_tickera_tickets(
                    $data['tickera_tickets'],
                    $data['wc_orders'] ?? [],
                    $event_map, $product_map, $event_special_map,
                    $fallback_event_id, $dry_run
                );
            }
        }

        set_transient('tix_migration_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS);

        $msg = $dry_run ? 'dry_run_done' : 'imported';
        wp_redirect(add_query_arg(['page' => 'tix-migration', 'tab' => 'import', 'token' => $token, 'msg' => $msg], admin_url('admin.php')));
        exit;
    }

    private static function build_event_map($source_events) {
        $map = [];
        foreach ($source_events as $se) {
            $found = null;
            if (!empty($se['slug'])) {
                $hits = get_posts(['post_type' => 'event', 'name' => $se['slug'], 'posts_per_page' => 1, 'post_status' => 'any']);
                if (!empty($hits)) $found = $hits[0];
            }
            if (!$found && !empty($se['title'])) {
                $hits = get_posts(['post_type' => 'event', 'title' => $se['title'], 'posts_per_page' => 1, 'post_status' => 'any']);
                if (!empty($hits)) $found = $hits[0];
            }
            $map[intval($se['id'])] = $found ? $found->ID : 0;
        }
        return $map;
    }

    /**
     * Special-Mapping: alte_special_id → neue_special_id
     * Match-Strategie: 1) Slug 2) Title; bleibt 0 wenn nichts da → wird beim Import neu angelegt.
     */
    private static function build_special_map($source_specials) {
        $map = [];
        foreach ($source_specials as $sp) {
            $found = null;
            if (!empty($sp['slug'])) {
                $hits = get_posts(['post_type' => 'tix_special', 'name' => $sp['slug'], 'posts_per_page' => 1, 'post_status' => 'any']);
                if (!empty($hits)) $found = $hits[0];
            }
            if (!$found && !empty($sp['title'])) {
                $hits = get_posts(['post_type' => 'tix_special', 'title' => $sp['title'], 'posts_per_page' => 1, 'post_status' => 'any']);
                if (!empty($hits)) $found = $hits[0];
            }
            $map[intval($sp['id'])] = $found ? $found->ID : 0;
        }
        return $map;
    }

    /**
     * Liefert für ein altes WC-Item das Mapping:
     *   - normales Event-Item: ['kind' => 'event', 'event_id' => N, 'cat_index' => N]
     *   - Special-Item        : ['kind' => 'special', 'special_id' => N]
     * Reihenfolge der Auflösung:
     *   1. Produkt-Mapping → Event (manuell zugewiesen, event_id > 0)
     *   2. Produkt-Mapping → Special (alte product → Cross-Type Special via event_special_map)
     *   3. event_special_map (alte item.event_id → neue Special-ID)
     *   4. event_map (alte item.event_id → neue event_id)
     */
    private static function resolve_item_mapping($item, $event_map, $product_map, $event_special_map = []) {
        $pid = intval($item['product_id'] ?? 0);
        if ($pid && isset($product_map[$pid])) {
            $pm = $product_map[$pid];
            // 1. Direkter Special-Match (User hat im Mapping „⭐ Special" gewählt)
            if (intval($pm['special_id'] ?? 0) > 0) {
                return ['kind' => 'special', 'special_id' => intval($pm['special_id'])];
            }
            // 2. Direkter Event-Match (User hat ein Event gewählt)
            if (intval($pm['event_id'] ?? 0) > 0) {
                return ['kind' => 'event', 'event_id' => intval($pm['event_id']), 'cat_index' => intval($pm['cat_index'] ?? 0)];
            }
            // 3. Cross-Match: altes WC-Produkt gehört zu einem alten Event das auf der neuen Site ein Special ist
            $pm_old_eid = intval($pm['old_event_id'] ?? 0);
            if ($pm_old_eid && !empty($event_special_map[$pm_old_eid])) {
                return ['kind' => 'special', 'special_id' => intval($event_special_map[$pm_old_eid])];
            }
        }
        // 3. Fallback: alte event_id direkt aus dem Item
        $old_eid = intval($item['event_id'] ?? 0);
        if ($old_eid && !empty($event_special_map[$old_eid])) {
            return ['kind' => 'special', 'special_id' => intval($event_special_map[$old_eid])];
        }
        $new_eid = $event_map[$old_eid] ?? 0;
        return ['kind' => 'event', 'event_id' => $new_eid, 'cat_index' => intval($item['cat_index'] ?? 0)];
    }

    // ── Users ──
    private static function import_users($source_users, $dry_run = true) {
        global $wpdb;
        $created = 0; $skipped = 0; $linked = 0; $errors = [];
        foreach ($source_users as $su) {
            $existing = get_user_by('email', $su['email']);
            if ($existing) {
                // WICHTIG: Auch bei existierendem User legacy_user_id setzen,
                // damit Orders später per legacy_user_id → neuer User-ID verknüpft werden können.
                if (!$dry_run) {
                    $had_legacy = get_user_meta($existing->ID, '_tix_legacy_user_id', true);
                    if (!$had_legacy) {
                        update_user_meta($existing->ID, '_tix_legacy_user_id', $su['id']);
                        update_user_meta($existing->ID, '_tix_legacy_source', parse_url(home_url(), PHP_URL_HOST));
                        $linked++;
                    }
                }
                $skipped++;
                continue;
            }
            if ($dry_run) { $created++; continue; }
            $insert = $wpdb->insert($wpdb->users, [
                'user_login' => $su['login'],
                'user_pass'  => $su['pass'],
                'user_email' => $su['email'],
                'user_registered' => $su['registered'] ?: current_time('mysql'),
                'display_name' => $su['display_name'],
                'user_nicename' => $su['nicename'],
                'user_status' => 0,
            ]);
            if ($insert === false) { $errors[] = 'User ' . $su['email'] . ': ' . $wpdb->last_error; continue; }
            $new_uid = $wpdb->insert_id;
            foreach (($su['meta'] ?? []) as $k => $v) update_user_meta($new_uid, $k, $v);
            $u = new WP_User($new_uid);
            if (!empty($su['roles'])) {
                foreach ($su['roles'] as $role) $u->add_role($role);
            } else {
                $u->set_role('customer');
            }
            update_user_meta($new_uid, '_tix_legacy_user_id', $su['id']);
            update_user_meta($new_uid, '_tix_legacy_source', parse_url(home_url(), PHP_URL_HOST));
            $created++;
        }
        return ['created' => $created, 'skipped' => $skipped, 'linked_existing' => $linked, 'errors' => $errors];
    }

    // ── Orders ──
    private static function import_orders($wc_orders, $tix_orders, $event_map, $product_map, $dry_run = true, $event_special_map = [], $fallback_event_id = 0) {
        $created = 0; $skipped = 0; $errors = [];
        $items_event_total = 0; $items_special_total = 0; $items_unmapped_total = 0; $items_fallback_total = 0;
        if (!class_exists('TIX_Order')) return ['error' => 'TIX_Order class missing'];

        global $wpdb;
        foreach ($wc_orders as $wo) {
            // Direktes SQL — get_posts joint wp_posts, aber tix_orders sind keine WP-Posts
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_tix_legacy_wc_order_id', $wo['wc_id']
            ));
            if ($existing) { $skipped++; continue; }

            // 1. Pass: alle Items auflösen — sammeln Event-Items und Special-Items getrennt
            $event_items   = [];
            $special_items = [];
            foreach ($wo['items'] as $it) {
                $resolved = self::resolve_item_mapping($it, $event_map, $product_map, $event_special_map);
                if ($resolved['kind'] === 'special' && $resolved['special_id'] > 0) {
                    $special_items[] = ['raw' => $it, 'resolved' => $resolved];
                    $items_special_total += intval($it['qty'] ?? 1);
                } elseif ($resolved['kind'] === 'event' && $resolved['event_id']) {
                    $event_items[] = ['raw' => $it, 'resolved' => $resolved];
                    $items_event_total += intval($it['qty'] ?? 1);
                } elseif ($fallback_event_id > 0) {
                    // Fallback: Item kommt aus gelöschtem Event/Produkt → auf Archiv-Event mappen
                    $event_items[] = ['raw' => $it, 'resolved' => ['kind' => 'event', 'event_id' => $fallback_event_id, 'cat_index' => 0, 'fallback' => true]];
                    $items_fallback_total += intval($it['qty'] ?? 1);
                } else {
                    $items_unmapped_total += intval($it['qty'] ?? 1);
                    if (count($errors) < 25) {
                        $errors[] = 'WC-Order ' . $wo['wc_id'] . ' Item product ' . ($it['product_id'] ?? '?') . ' (' . ($it['product_name'] ?? '?') . '): kein Mapping';
                    }
                }
            }

            // Dry-Run: nicht weiter, nur zählen
            if ($dry_run) {
                if (!empty($event_items) || !empty($special_items)) $created++;
                else $skipped++;
                continue;
            }

            // Ziel-Event für Special-Items ermitteln (Specials brauchen ein Trägerevent)
            $special_target_event = 0;
            if (!empty($special_items)) {
                if (!empty($event_items)) {
                    $special_target_event = intval($event_items[0]['resolved']['event_id']);
                } else {
                    // Fallback: erstes verfügbares Event aus event_map
                    foreach ($event_map as $eid) {
                        if ($eid > 0) { $special_target_event = intval($eid); break; }
                    }
                }
            }

            $items_for_tix = [];
            foreach ($event_items as $entry) {
                $it = $entry['raw']; $r = $entry['resolved'];
                $items_for_tix[] = [
                    'event_id'  => $r['event_id'],
                    'cat_index' => $r['cat_index'],
                    'name'      => $it['product_name'],
                    'price'     => floatval($it['total']) / max(1, intval($it['qty'])),
                    'qty'       => intval($it['qty']),
                    'meta'      => !empty($r['fallback']) ? ['fallback' => 1, 'orig_product_name' => $it['product_name']] : [],
                ];
            }
            foreach ($special_items as $entry) {
                $it = $entry['raw']; $r = $entry['resolved'];
                if (!$special_target_event) {
                    $errors[] = 'WC-Order ' . $wo['wc_id'] . ' Special-Item ohne Trägerevent — übersprungen';
                    continue;
                }
                $sp_post = get_post($r['special_id']);
                $sp_name = $sp_post ? $sp_post->post_title : ($it['product_name'] ?? 'Special');
                $items_for_tix[] = [
                    'event_id'  => $special_target_event,
                    'cat_index' => -1, // -1 = kein Kategorie-Slot, ist ein Special
                    'name'      => $sp_name,
                    'price'     => floatval($it['total']) / max(1, intval($it['qty'])),
                    'qty'       => intval($it['qty']),
                    'meta'      => ['special' => 1, 'special_id' => intval($r['special_id'])],
                ];
            }

            if (empty($items_for_tix)) { $skipped++; continue; }

            $order_data = [
                'status' => self::map_wc_status($wo['status']),
                'total' => floatval($wo['total']),
                'tax' => floatval($wo['tax']),
                'currency' => $wo['currency'] ?: 'EUR',
                'date_created' => $wo['date_created'],
                'date_paid' => $wo['date_paid'],
                'payment_method' => $wo['payment_method'],
                'payment_method_title' => $wo['payment_method_title'],
                'transaction_id' => $wo['transaction_id'],
                'billing_first_name' => $wo['billing']['first_name'] ?? '',
                'billing_last_name'  => $wo['billing']['last_name']  ?? '',
                'billing_email'      => $wo['billing']['email']      ?? '',
                'billing_phone'      => $wo['billing']['phone']      ?? '',
                'billing_company'    => $wo['billing']['company']    ?? '',
                'billing_address_1'  => $wo['billing']['address_1']  ?? '',
                'billing_postcode'   => $wo['billing']['postcode']   ?? '',
                'billing_city'       => $wo['billing']['city']       ?? '',
                'billing_country'    => $wo['billing']['country']    ?? 'DE',
            ];

            try {
                $new_order_id = TIX_Order::create_legacy($order_data, $items_for_tix);
                if (!$new_order_id) { $errors[] = 'WC-Order ' . $wo['wc_id'] . ': create_legacy() failed'; continue; }

                // User-Zuordnung: 3-stufiger Fallback
                //   1. legacy_user_id-Meta (gesetzt vom User-Import — auch bei skipped/linked_existing)
                //   2. Billing-Email → bestehender WP-User (matcht auch User die ohne Migration angelegt wurden)
                //   3. Bleibt 0 (Gast-Bestellung)
                $new_user_id = 0;
                if (!empty($wo['customer_id'])) {
                    $new_users = get_users(['meta_key' => '_tix_legacy_user_id', 'meta_value' => $wo['customer_id'], 'number' => 1, 'fields' => 'ID']);
                    if (!empty($new_users)) $new_user_id = intval($new_users[0]);
                }
                if (!$new_user_id && !empty($wo['billing']['email'])) {
                    $u = get_user_by('email', $wo['billing']['email']);
                    if ($u) $new_user_id = intval($u->ID);
                }
                if ($new_user_id) {
                    global $wpdb;
                    $wpdb->update(TIX_Order::table_name(), ['customer_id' => $new_user_id], ['id' => $new_order_id]);
                }

                update_post_meta($new_order_id, '_tix_legacy_wc_order_id',     $wo['wc_id']);
                update_post_meta($new_order_id, '_tix_legacy_wc_order_number', $wo['order_number']);
                update_post_meta($new_order_id, '_tix_legacy_source', parse_url(home_url(), PHP_URL_HOST));
                // Felder die im Schema fehlen — als Meta für Support-Lookup
                if (!empty($wo['currency']))       update_post_meta($new_order_id, '_tix_legacy_currency',       $wo['currency']);
                if (!empty($wo['date_paid']))      update_post_meta($new_order_id, '_tix_legacy_date_paid',      $wo['date_paid']);
                if (!empty($wo['transaction_id'])) update_post_meta($new_order_id, '_tix_legacy_transaction_id', $wo['transaction_id']);

                // Außerdem in der TIX_Orders-Tabelle als Spalte (für Joins/Lookups)
                global $wpdb;
                $wpdb->query($wpdb->prepare(
                    "UPDATE " . TIX_Order::table_name() . " SET order_key = CONCAT(order_key, ' | LEGACY_WC#' , %s) WHERE id = %d",
                    $wo['order_number'],
                    $new_order_id
                ));
                $created++;
            } catch (\Throwable $e) {
                $errors[] = 'WC-Order ' . $wo['wc_id'] . ': ' . $e->getMessage();
            }
        }
        return [
            'created' => $created,
            'skipped' => $skipped,
            'items_event'    => $items_event_total,
            'items_special'  => $items_special_total,
            'items_fallback' => $items_fallback_total,
            'items_unmapped' => $items_unmapped_total,
            'errors' => $errors,
        ];
    }

    private static function map_wc_status($wc_status) {
        $wc_status = preg_replace('/^wc-/', '', $wc_status);
        $map = ['completed' => 'completed', 'processing' => 'completed', 'on-hold' => 'pending', 'pending' => 'pending', 'cancelled' => 'cancelled', 'refunded' => 'refunded', 'failed' => 'cancelled'];
        return $map[$wc_status] ?? 'completed';
    }

    // ── Specials (CPT) ──
    /**
     * Importiert tix_special Posts. Für Slug/Title-Match wird wiederverwendet
     * (vom build_special_map gesetzt), sonst neu angelegt.
     * Liefert ['summary' => […], 'map' => updated_special_map].
     */
    private static function import_specials($source_specials, $special_map, $dry_run = true) {
        $created = 0; $reused = 0; $errors = [];
        foreach ($source_specials as $sp) {
            $old_id = intval($sp['id']);
            if (!empty($special_map[$old_id])) { $reused++; continue; }

            if ($dry_run) { $created++; $special_map[$old_id] = -1; continue; }

            $new_id = wp_insert_post([
                'post_type'    => 'tix_special',
                'post_title'   => $sp['title'] ?: 'Special',
                'post_name'    => $sp['slug'] ?: '',
                'post_content' => $sp['content'] ?? '',
                'post_status'  => $sp['status'] ?? 'publish',
            ]);
            if (is_wp_error($new_id) || !$new_id) {
                $errors[] = 'Special "' . $sp['title'] . '": insert failed';
                continue;
            }
            update_post_meta($new_id, '_tix_special_price', floatval($sp['price'] ?? 0));
            update_post_meta($new_id, '_tix_special_value', floatval($sp['value'] ?? 0));
            update_post_meta($new_id, '_tix_special_qty',   intval($sp['qty'] ?? 0));
            if (!empty($sp['template'])) update_post_meta($new_id, '_tix_special_template', intval($sp['template']));
            // _tix_special_product_id wird AUF DER NEUEN SITE absichtlich NICHT übertragen
            // (alte WC-Produkt-IDs zeigen ins Leere; native Checkout braucht das Feld nicht)
            update_post_meta($new_id, '_tix_legacy_special_id', $old_id);
            update_post_meta($new_id, '_tix_legacy_source', parse_url(home_url(), PHP_URL_HOST));

            // Bild via URL → media_sideload (kann teuer sein, daher nur wenn nicht schon thumb gesetzt)
            if (!empty($sp['image_url']) && !get_post_thumbnail_id($new_id)) {
                if (!function_exists('media_sideload_image')) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                }
                $att_id = media_sideload_image($sp['image_url'], $new_id, null, 'id');
                if (!is_wp_error($att_id)) set_post_thumbnail($new_id, $att_id);
            }

            $special_map[$old_id] = $new_id;
            $created++;
        }
        return ['summary' => ['created' => $created, 'reused' => $reused, 'errors' => $errors], 'map' => $special_map];
    }

    /** Event → Specials Verbindungen wiederherstellen mit gemappten IDs */
    private static function import_event_specials($source_event_specials, $event_map, $special_map, $dry_run = true) {
        $linked = 0; $skipped = 0; $errors = [];
        foreach ($source_event_specials as $es) {
            $old_eid = intval($es['event_id']);
            $new_eid = $event_map[$old_eid] ?? 0;
            if (!$new_eid) { $skipped++; continue; }

            $remapped = [];
            foreach (($es['specials'] ?? []) as $rel) {
                $old_sid = intval($rel['special_id'] ?? 0);
                $new_sid = $special_map[$old_sid] ?? 0;
                if (!$new_sid || $new_sid < 0) continue; // 0 oder dry-run Marker
                $remapped[] = [
                    'special_id'      => $new_sid,
                    'enabled'         => !empty($rel['enabled']) ? '1' : '0',
                    'price_override'  => floatval($rel['price_override'] ?? 0),
                    'qty_override'    => intval($rel['qty_override'] ?? 0),
                ];
            }
            if (empty($remapped)) { $skipped++; continue; }
            if (!$dry_run) {
                // Existierende Verbindungen mergen, nicht überschreiben
                $existing = get_post_meta($new_eid, '_tix_specials', true);
                if (!is_array($existing)) $existing = [];
                $by_sid = [];
                foreach ($existing as $e) {
                    if (!is_array($e) || empty($e['special_id'])) continue;
                    $by_sid[intval($e['special_id'])] = $e;
                }
                foreach ($remapped as $r) {
                    $by_sid[$r['special_id']] = $r; // Migration überschreibt bei Konflikt
                }
                update_post_meta($new_eid, '_tix_specials', array_values($by_sid));
            }
            $linked++;
        }
        return ['linked' => $linked, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ── Tickets ──
    private static function import_tickets($source_tickets, $event_map, $product_map, $special_map = [], $dry_run = true, $event_special_map = [], $fallback_event_id = 0) {
        $created = 0; $skipped = 0; $errors = [];
        $fallback_used = 0;
        foreach ($source_tickets as $st) {
            if (!empty($st['code'])) {
                $existing = get_posts([
                    'post_type' => 'tix_ticket', 'meta_key' => '_tix_ticket_code', 'meta_value' => $st['code'],
                    'posts_per_page' => 1, 'fields' => 'ids',
                ]);
                if (!empty($existing)) { $skipped++; continue; }
            }

            $resolved = self::resolve_item_mapping([
                'product_id' => intval($st['product_id'] ?? 0),
                'event_id'   => intval($st['event_id']),
                'cat_index'  => intval($st['cat_index']),
            ], $event_map, $product_map, $event_special_map);

            // Falls Cross-Type: Special-Ticket — braucht ein Träger-Event
            $is_cross_special = ($resolved['kind'] === 'special' && $resolved['special_id'] > 0);
            $target_event_id  = 0;
            $target_special_id = 0;
            $target_cat_index  = 0;

            if ($is_cross_special) {
                $target_special_id = intval($resolved['special_id']);
                // Träger-Event: erstes verfügbares aus event_map
                foreach ($event_map as $eid) {
                    if ($eid > 0) { $target_event_id = intval($eid); break; }
                }
                $target_cat_index = -1;
            } elseif ($resolved['kind'] === 'event' && $resolved['event_id']) {
                $target_event_id = intval($resolved['event_id']);
                $target_cat_index = intval($resolved['cat_index']);
            }

            if (!$target_event_id) {
                if ($fallback_event_id > 0) {
                    $target_event_id = $fallback_event_id;
                    $target_cat_index = 0;
                    $fallback_used++;
                } else {
                    $errors[] = 'Ticket ' . ($st['code'] ?: $st['id']) . ': kein Event-Mapping';
                    continue;
                }
            }
            if ($dry_run) { $created++; continue; }

            // Order-Mapping über legacy meta — direktes SQL (tix_orders sind keine WP-Posts)
            $new_order_id = 0;
            if ($st['order_id']) {
                global $wpdb;
                $hit = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    '_tix_legacy_wc_order_id', $st['order_id']
                ));
                if ($hit) $new_order_id = intval($hit);
            }

            $tid = wp_insert_post([
                'post_type' => 'tix_ticket', 'post_status' => 'publish',
                'post_title' => $st['title'] ?: 'Ticket ' . $st['code'],
                'post_date' => $st['date'],
            ]);
            if (is_wp_error($tid) || !$tid) { $errors[] = 'Ticket ' . $st['code'] . ': insert failed'; continue; }

            update_post_meta($tid, '_tix_ticket_code',         $st['code']);
            update_post_meta($tid, '_tix_ticket_event_id',     $target_event_id);
            update_post_meta($tid, '_tix_ticket_order_id',     $new_order_id);
            update_post_meta($tid, '_tix_ticket_cat_index',    $target_cat_index);
            // cat_name aus Event-Categories ableiten (statt nur Index zu speichern)
            $_event_cats = get_post_meta($target_event_id, '_tix_ticket_categories', true);
            $_cat_name = (is_array($_event_cats) && $target_cat_index >= 0 && isset($_event_cats[$target_cat_index]['name']))
                ? $_event_cats[$target_cat_index]['name']
                : '';
            if ($_cat_name) update_post_meta($tid, '_tix_ticket_cat_name', $_cat_name);
            update_post_meta($tid, '_tix_ticket_owner_name',   $st['owner_name']);
            update_post_meta($tid, '_tix_ticket_owner_email',  $st['owner_email']);
            update_post_meta($tid, '_tix_ticket_status',       $st['status'] ?: 'valid');

            // Special-Marker:
            //   a) Cross-Type-Mapping (altes Event → neues Special)
            //   b) Source-Ticket war bereits ein Special — Special-Map auflösen
            if ($is_cross_special) {
                update_post_meta($tid, '_tix_ticket_type', 'special');
                update_post_meta($tid, '_tix_ticket_special_id', $target_special_id);
            } elseif (!empty($st['type']) && $st['type'] === 'special') {
                $old_sid = intval($st['special_id'] ?? 0);
                $new_sid = $special_map[$old_sid] ?? 0;
                if ($new_sid > 0) {
                    update_post_meta($tid, '_tix_ticket_type', 'special');
                    update_post_meta($tid, '_tix_ticket_special_id', $new_sid);
                }
            }
            if (!empty($st['checked_in'])) {
                update_post_meta($tid, '_tix_ticket_checked_in', 1);
                update_post_meta($tid, '_tix_ticket_checkin_time', $st['checkin_time']);
            }
            if (!empty($st['assigned_name'])) update_post_meta($tid, '_tix_ticket_assigned_name', $st['assigned_name']);
            if (!empty($st['seat_id']))      update_post_meta($tid, '_tix_ticket_seat_id', $st['seat_id']);
            update_post_meta($tid, '_tix_legacy_ticket_id', $st['id']);
            update_post_meta($tid, '_tix_legacy_source', parse_url(home_url(), PHP_URL_HOST));
            $created++;
        }
        return ['created' => $created, 'skipped' => $skipped, 'fallback_used' => $fallback_used, 'errors' => $errors];
    }

    /**
     * Importiert Tickera tc_tickets_instances Tickets als tix_ticket Posts.
     *
     * Mapping-Strategie:
     *   1. ticket_code dedup (skip wenn schon importiert)
     *   2. wc_order_id (post_parent) → finde neue Order via _tix_legacy_wc_order_id Meta
     *   3. Aus erstem Item der Order → resolve_item_mapping → Event/Special
     *   4. Falls keine Order matched → fallback_event_id
     */
    private static function import_tickera_tickets($source_tickets, $wc_orders, $event_map, $product_map, $event_special_map, $fallback_event_id, $dry_run = true) {
        $created = 0; $skipped = 0; $fallback_used = 0; $no_order = 0; $errors = [];

        // WC-Order Lookup-Tabelle: wc_id → erstes Item
        $order_first_item = [];
        foreach ($wc_orders as $wo) {
            $wc_id = intval($wo['wc_id'] ?? 0);
            if (!$wc_id) continue;
            $items = $wo['items'] ?? [];
            if (!empty($items)) {
                $order_first_item[$wc_id] = $items[0];
            }
        }

        // Cache: wc_order_id → resolved [event_id, cat_index, special_id, kind]
        $resolved_cache = [];
        // Cache: wc_order_id → new_order_id auf neuer Site
        $new_order_cache = [];

        foreach ($source_tickets as $st) {
            $code = $st['code'] ?? '';
            if (!$code) { $skipped++; continue; }

            // Dedup gegen schon importierte Tickets
            $existing = get_posts([
                'post_type' => 'tix_ticket', 'meta_key' => '_tix_ticket_code', 'meta_value' => $code,
                'posts_per_page' => 1, 'fields' => 'ids',
            ]);
            if (!empty($existing)) { $skipped++; continue; }

            // Über post_parent (alte WC-Order-ID) zu neuem Mapping
            $wc_id = intval($st['wc_order_id'] ?? 0);

            $target_event_id = 0;
            $target_cat_index = 0;
            $target_special_id = 0;

            if ($wc_id && isset($order_first_item[$wc_id])) {
                if (!isset($resolved_cache[$wc_id])) {
                    $resolved_cache[$wc_id] = self::resolve_item_mapping(
                        $order_first_item[$wc_id], $event_map, $product_map, $event_special_map
                    );
                }
                $r = $resolved_cache[$wc_id];
                if ($r['kind'] === 'special' && $r['special_id'] > 0) {
                    $target_special_id = intval($r['special_id']);
                    foreach ($event_map as $eid) { if ($eid > 0) { $target_event_id = intval($eid); break; } }
                    $target_cat_index = -1;
                } elseif ($r['kind'] === 'event' && $r['event_id'] > 0) {
                    $target_event_id = intval($r['event_id']);
                    $target_cat_index = intval($r['cat_index']);
                }
            }

            // Fallback wenn kein Mapping
            if (!$target_event_id && !$target_special_id) {
                if ($fallback_event_id > 0) {
                    $target_event_id = $fallback_event_id;
                    $target_cat_index = 0;
                    $fallback_used++;
                } else {
                    $no_order++;
                    if (count($errors) < 25) {
                        $errors[] = 'Tickera-Ticket ' . $code . ' (Order ' . $wc_id . '): kein Mapping';
                    }
                    continue;
                }
            }

            if ($dry_run) { $created++; continue; }

            // Order auf neuer Site finden — direktes SQL (tix_orders sind keine WP-Posts)
            if ($wc_id && !isset($new_order_cache[$wc_id])) {
                global $wpdb;
                $hit = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    '_tix_legacy_wc_order_id', $wc_id
                ));
                $new_order_cache[$wc_id] = $hit ? intval($hit) : 0;
            }
            $new_order_id = $wc_id ? ($new_order_cache[$wc_id] ?? 0) : 0;

            // Ticket anlegen
            $tid = wp_insert_post([
                'post_type'   => 'tix_ticket',
                'post_status' => 'publish',
                'post_title'  => $code,
                'post_date'   => $st['date'] ?? current_time('mysql'),
                'post_parent' => $new_order_id,
            ]);
            if (is_wp_error($tid) || !$tid) { $errors[] = 'Tickera-Ticket ' . $code . ': insert failed'; continue; }

            update_post_meta($tid, '_tix_ticket_code',          $code);
            update_post_meta($tid, '_tix_ticket_event_id',      $target_event_id);
            update_post_meta($tid, '_tix_ticket_order_id',      $new_order_id);
            update_post_meta($tid, '_tix_ticket_cat_index',     $target_cat_index);
            // cat_name aus Event-Categories ableiten
            $_event_cats = get_post_meta($target_event_id, '_tix_ticket_categories', true);
            $_cat_name = (is_array($_event_cats) && $target_cat_index >= 0 && isset($_event_cats[$target_cat_index]['name']))
                ? $_event_cats[$target_cat_index]['name']
                : '';
            if ($_cat_name) update_post_meta($tid, '_tix_ticket_cat_name', $_cat_name);
            update_post_meta($tid, '_tix_ticket_status',        'valid');
            update_post_meta($tid, '_tix_ticket_checked_in',    intval($st['checked_in'] ?? 0));
            if (!empty($st['checked_in'])) update_post_meta($tid, '_tix_ticket_checkin_time', '');

            if ($target_special_id) {
                update_post_meta($tid, '_tix_ticket_type', 'special');
                update_post_meta($tid, '_tix_ticket_special_id', $target_special_id);
            }

            // Owner aus Order ableiten (für „Meine Tickets")
            if ($wc_id && isset($order_first_item[$wc_id])) {
                // Owner aus WC-Order Billing
                foreach ($wc_orders as $wo) {
                    if (intval($wo['wc_id']) === $wc_id) {
                        $billing = $wo['billing'] ?? [];
                        if (!empty($billing['email'])) update_post_meta($tid, '_tix_ticket_owner_email', $billing['email']);
                        $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
                        if ($name) update_post_meta($tid, '_tix_ticket_owner_name', $name);
                        break;
                    }
                }
            }

            update_post_meta($tid, '_tix_legacy_tickera_id', intval($st['id']));
            update_post_meta($tid, '_tix_legacy_source', parse_url(home_url(), PHP_URL_HOST));
            $created++;
        }

        return [
            'created'       => $created,
            'skipped'       => $skipped,
            'fallback_used' => $fallback_used,
            'no_order'      => $no_order,
            'errors'        => $errors,
        ];
    }

    // ════════════════════════════════════════════════════════════
    // ADMIN UI
    // ════════════════════════════════════════════════════════════

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) wp_die();
        $tab = $_GET['tab'] ?? 'export';
        $msg = $_GET['msg'] ?? '';
        $token = sanitize_text_field($_GET['token'] ?? '');
        $data  = $token ? self::load_data($token) : null;
        $map   = $token ? self::load_map($token)  : null;
        $report = get_transient('tix_migration_report_' . get_current_user_id());
        if ($report) delete_transient('tix_migration_report_' . get_current_user_id());

        $stats = [
            'users'  => count_users()['total_users'] ?? 0,
            'events' => wp_count_posts('event')->publish ?? 0,
            'tickets'=> wp_count_posts('tix_ticket')->publish ?? 0,
            'has_wc' => function_exists('wc_get_orders'),
        ];
        ?>
        <div class="wrap" style="max-width:1300px;">
            <h1>Migration <span style="font-size:13px;font-weight:400;color:#9ca3af;">Domain-zu-Domain Transfer</span></h1>

            <?php if ($msg === 'imported'): ?><div class="notice notice-success"><p>Import abgeschlossen — siehe Bericht unten.</p></div>
            <?php elseif ($msg === 'dry_run_done'): ?><div class="notice notice-info"><p>Dry-Run abgeschlossen — NICHTS wurde geschrieben. Siehe Bericht unten.</p></div>
            <?php elseif ($msg === 'no_file'): ?><div class="notice notice-error"><p>Bitte JSON-Datei auswählen.</p></div>
            <?php elseif ($msg === 'too_big_php'): ?><div class="notice notice-error"><p>Datei größer als <code>upload_max_filesize</code> in der PHP-Config (typisch 2&nbsp;MB). Erhöhe <code>upload_max_filesize</code> und <code>post_max_size</code> in der php.ini bzw. .user.ini auf z.B. 100M.</p></div>
            <?php elseif ($msg === 'too_big_form'): ?><div class="notice notice-error"><p>Datei größer als das HTML-Formular-Limit. Server-Konfiguration prüfen.</p></div>
            <?php elseif ($msg === 'partial'): ?><div class="notice notice-error"><p>Upload unterbrochen — bitte erneut versuchen.</p></div>
            <?php elseif ($msg === 'no_tmp'): ?><div class="notice notice-error"><p>Server-Konfigurationsfehler: kein /tmp Verzeichnis verfügbar.</p></div>
            <?php elseif ($msg === 'cant_write'): ?><div class="notice notice-error"><p>Server-Berechtigungsfehler beim Upload.</p></div>
            <?php elseif ($msg === 'invalid_json'):
                $err_data = get_transient('tix_migration_upload_err_' . get_current_user_id());
                if ($err_data) delete_transient('tix_migration_upload_err_' . get_current_user_id());
            ?>
                <div class="notice notice-error">
                    <p><strong>Ungültige JSON-Datei.</strong>
                    <?php if ($err_data): ?>
                        <br>JSON-Fehler: <code><?php echo esc_html($err_data['json_err']); ?></code>
                        <br>Datei-Größe: <?php echo number_format($err_data['size']); ?> Bytes
                        <br>Anfang der Datei: <code style="font-size:11px;background:#f3f4f6;padding:4px 8px;border-radius:4px;display:inline-block;max-width:100%;overflow:auto;"><?php echo esc_html($err_data['preview']); ?></code>
                    <?php endif; ?>
                    </p>
                </div>
            <?php elseif ($msg === 'expired'): ?><div class="notice notice-warning"><p>Session abgelaufen — bitte neu hochladen.</p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=tix-migration&tab=export" class="nav-tab <?php echo $tab === 'export' ? 'nav-tab-active' : ''; ?>">📤 Export</a>
                <a href="?page=tix-migration&tab=import" class="nav-tab <?php echo $tab === 'import' ? 'nav-tab-active' : ''; ?>">📥 Import</a>
            </h2>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:0;padding:24px;">
                <?php if ($tab === 'export'): ?>
                    <?php self::render_export_tab($stats); ?>
                <?php else: ?>
                    <?php if ($data && $map): self::render_mapping_step($token, $data, $map); ?>
                    <?php else: self::render_upload_step(); ?>
                    <?php endif; ?>

                    <?php if ($report): ?>
                        <h2 style="margin-top:32px;">Bericht <?php echo $report['dry_run'] ? '(Dry-Run)' : ''; ?></h2>
                        <pre style="background:#1e293b;color:#a7f3d0;padding:18px;border-radius:8px;overflow:auto;font-size:12px;line-height:1.5;max-height:600px;"><?php echo esc_html(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private static function render_export_tab($stats) { ?>
        <h2 style="margin-top:0;">Export auf der ALTEN Domain</h2>
        <p>Erstellt eine vollständige JSON-Datei für den Import auf der neuen Domain.</p>
        <table class="widefat striped" style="max-width:500px;">
            <tr><td>Users</td><td><strong><?php echo intval($stats['users']); ?></strong></td></tr>
            <tr><td>Events</td><td><strong><?php echo intval($stats['events']); ?></strong></td></tr>
            <tr><td>Tickets</td><td><strong><?php echo intval($stats['tickets']); ?></strong></td></tr>
            <tr><td>WooCommerce</td><td><strong><?php echo $stats['has_wc'] ? '✓ aktiv' : '✗ nicht aktiv'; ?></strong></td></tr>
        </table>
        <p style="margin-top:24px;color:#b91c1c;"><strong>Wichtig:</strong> User-Passwort-Hashes werden mitexportiert. Datei sicher behandeln.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:20px;">
            <?php wp_nonce_field(self::NONCE_KEY); ?>
            <input type="hidden" name="action" value="tix_migration_export">
            <button type="submit" class="button button-primary button-large">JSON-Datei herunterladen</button>
        </form>
    <?php }

    private static function render_upload_step() { ?>
        <h2 style="margin-top:0;">Step 1 — Import-Datei hochladen</h2>
        <p>Lade die JSON-Datei hoch, die du auf der alten Domain exportiert hast. Im nächsten Schritt mappst du die Produkte auf deine neuen Events.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin-top:20px;">
            <?php wp_nonce_field(self::NONCE_KEY); ?>
            <input type="hidden" name="action" value="tix_migration_upload">
            <table class="form-table">
                <tr>
                    <th><label for="migration_file">JSON-Datei</label></th>
                    <td><input type="file" id="migration_file" name="migration_file" accept=".json,application/json" required></td>
                </tr>
            </table>
            <p class="submit"><button type="submit" class="button button-primary button-large">Hochladen &amp; analysieren →</button></p>
        </form>
    <?php }

    private static function render_mapping_step($token, $data, $map) {
        $events    = $data['events'] ?? [];
        $products  = $data['products'] ?? [];
        $specials  = $data['specials'] ?? [];
        $product_map = $map['product_map'] ?? [];
        $event_map   = $map['event_map'] ?? [];
        $special_map = $map['special_map'] ?? [];

        // Lokale Events laden (für Dropdown)
        $local_events = get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);

        // Counts pro alter Produkt-ID (wie viele Tickets/Items)
        $product_counts = [];
        foreach (($data['wc_orders'] ?? []) as $wo) {
            foreach ($wo['items'] as $it) {
                $pid = intval($it['product_id']);
                $product_counts[$pid] = ($product_counts[$pid] ?? 0) + intval($it['qty']);
            }
        }
        ?>
        <h2 style="margin-top:0;">Step 2 — Produkt-Mapping</h2>
        <p>
            Datei: <strong><?php echo esc_html($data['meta']['source_url'] ?? '?'); ?></strong> ·
            Exportiert: <?php echo esc_html($data['meta']['exported_at'] ?? '?'); ?> ·
            <?php
            $c = $data['meta']['counts'] ?? [];
            $tickera_count = count($data['tickera_tickets'] ?? []);
            echo intval($c['users']  ?? 0) . ' Users · ' . intval($c['products'] ?? 0) . ' Produkte · '
               . intval($c['wc_orders'] ?? 0) . ' WC-Bestellungen · ' . intval($c['tickets'] ?? 0) . ' Tickets';
            if ($tickera_count) echo ' · <strong style="color:#92400e;">' . $tickera_count . ' Tickera-Tickets</strong>';
            echo ' · ' . count($specials) . ' Specials';
            ?>
            ·
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_migration_clear&token=' . $token), self::NONCE_KEY)); ?>" style="color:#b91c1c;">Abbrechen / Andere Datei</a>
        </p>

        <?php
        // Existierende Specials für Dropdowns laden
        $local_specials_for_select = get_posts(['post_type' => 'tix_special', 'post_status' => ['publish','draft'], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $event_special_map = $map['event_special_map'] ?? [];
        ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::NONCE_KEY); ?>
            <input type="hidden" name="action" value="tix_migration_execute">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

        <?php // Event-Mapping ?>
        <h3>Event-Mapping</h3>
        <p style="color:#666;">Auto-Match per Slug/Title — du kannst aber manuell auf ein anderes Event oder ein <strong>Special</strong> umschalten (z.B. wenn ein altes Event auf der neuen Site als Special abgebildet wird).</p>
        <table class="widefat striped" style="max-width:900px;">
            <thead><tr><th style="width:35%">Altes Event</th><th>→ Mapping auf dieser Site</th></tr></thead>
            <tbody>
            <?php foreach ($events as $se):
                $old_id = intval($se['id']);
                $auto_eid = $event_map[$old_id] ?? 0;
                $manual_special = intval($event_special_map[$old_id] ?? 0);
                // Selected-Wert: "special:N" hat Vorrang vor "event:N"
                if ($manual_special > 0) {
                    $selected_val = 'special:' . $manual_special;
                } elseif ($auto_eid > 0) {
                    $selected_val = 'event:' . $auto_eid;
                } else {
                    $selected_val = '0';
                }
            ?>
                <tr>
                    <td><strong><?php echo esc_html($se['title']); ?></strong><br><small style="color:#9ca3af;">Slug: <?php echo esc_html($se['slug']); ?> · alte ID <?php echo $old_id; ?></small></td>
                    <td>
                        <select name="event_target[<?php echo $old_id; ?>]" style="width:100%;max-width:480px;">
                            <option value="0" <?php selected($selected_val, '0'); ?>>— ignorieren / Items überspringen —</option>
                            <optgroup label="Events">
                                <?php foreach ($local_events as $ev): ?>
                                    <option value="event:<?php echo $ev->ID; ?>" <?php selected($selected_val, 'event:' . $ev->ID); ?>>
                                        <?php echo esc_html($ev->post_title); ?> <small>(Event #<?php echo $ev->ID; ?>)</small>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php if (!empty($local_specials_for_select)): ?>
                            <optgroup label="Specials">
                                <?php foreach ($local_specials_for_select as $sp): ?>
                                    <option value="special:<?php echo $sp->ID; ?>" <?php selected($selected_val, 'special:' . $sp->ID); ?>>
                                        ⭐ <?php echo esc_html($sp->post_title); ?> <small>(Special #<?php echo $sp->ID; ?>)</small>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <?php if ($auto_eid && $manual_special <= 0): ?>
                            <small style="color:#22c55e;display:block;margin-top:4px;">✓ Auto-Match: Event</small>
                        <?php elseif ($selected_val === '0'): ?>
                            <small style="color:#b91c1c;display:block;margin-top:4px;">✗ Kein Match — manuell wählen oder Items werden übersprungen</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php // Produkt-Mapping (manuell) ?>
        <h3 style="margin-top:32px;">Produkt-Mapping</h3>
        <p>Wähle pro altem WooCommerce-Produkt das passende Event und die Ticketkategorie. Kategorien werden per JS aus dem gewählten Event geladen. Wenn das alte Event auf ein Special gemappt wurde, wird automatisch das Special-Item übernommen — dieses Mapping kannst du dann ignorieren.</p>


            <?php // Cats pro Event als JS-Array ?>
            <script>
            var TIX_EVENT_CATS = <?php
                $cats_by_event = [];
                foreach ($local_events as $ev) {
                    $cats = get_post_meta($ev->ID, '_tix_ticket_categories', true);
                    $cats_clean = [];
                    if (is_array($cats)) {
                        foreach ($cats as $idx => $c) {
                            $cats_clean[] = ['index' => $idx, 'name' => $c['name'] ?? ('Kategorie ' . ($idx + 1)), 'price' => floatval($c['price'] ?? 0)];
                        }
                    }
                    $cats_by_event[$ev->ID] = $cats_clean;
                }
                echo wp_json_encode($cats_by_event);
            ?>;
            </script>

            <table class="widefat striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th>Altes WC-Produkt</th>
                        <th>Verkauft</th>
                        <th>Event</th>
                        <th>Kategorie</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p):
                    $sel_event   = intval($product_map[$p['id']]['event_id'] ?? 0);
                    $sel_special = intval($product_map[$p['id']]['special_id'] ?? 0);
                    $sel_cat     = intval($product_map[$p['id']]['cat_index'] ?? 0);
                    $sold = intval($product_counts[$p['id']] ?? 0);

                    // Auto-Vorschlag für Specials anhand des Produktnamens
                    $auto_special_id = 0;
                    $name_lc = mb_strtolower($p['name']);
                    foreach ($local_specials_for_select as $sp_local) {
                        $sp_title_lc = mb_strtolower($sp_local->post_title);
                        // Match auf "5 liter bier", "bierfass" etc.
                        if (strpos($name_lc, $sp_title_lc) !== false
                         || (strpos($name_lc, 'bier') !== false && strpos($sp_title_lc, 'bier') !== false)
                         || (strpos($name_lc, 'liter') !== false && strpos($sp_title_lc, 'liter') !== false)) {
                            $auto_special_id = $sp_local->ID;
                            break;
                        }
                    }

                    // Selected-Wert für Combined-Dropdown
                    if ($sel_special > 0) {
                        $sel_target = 'special:' . $sel_special;
                    } elseif ($sel_event > 0) {
                        $sel_target = 'event:' . $sel_event;
                    } elseif ($auto_special_id > 0) {
                        $sel_target = 'special:' . $auto_special_id; // Auto-Vorschlag
                    } else {
                        $sel_target = '0';
                    }
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($p['name']); ?></strong><br>
                            <small style="color:#9ca3af;">ID <?php echo $p['id']; ?> · <?php echo number_format($p['price'], 2, ',', '.'); ?> €<?php if ($p['sku']): ?> · SKU: <?php echo esc_html($p['sku']); ?><?php endif; ?></small>
                            <?php if ($auto_special_id && $sel_special <= 0 && $sel_event <= 0): ?>
                                <br><small style="color:#92400e;">⭐ Auto-Vorschlag: Special</small>
                            <?php endif; ?>
                        </td>
                        <td><?php if ($sold): ?><strong><?php echo $sold; ?></strong> Tickets<?php else: ?><span style="color:#9ca3af;">—</span><?php endif; ?></td>
                        <td>
                            <select name="map[<?php echo $p['id']; ?>][target]" class="tix-mig-event" data-row="<?php echo $p['id']; ?>" style="width:100%;max-width:300px;">
                                <option value="0" <?php selected($sel_target, '0'); ?>>— Event/Special wählen —</option>
                                <optgroup label="Events">
                                    <?php foreach ($local_events as $ev): ?>
                                        <option value="event:<?php echo $ev->ID; ?>" <?php selected($sel_target, 'event:' . $ev->ID); ?>><?php echo esc_html($ev->post_title); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php if (!empty($local_specials_for_select)): ?>
                                <optgroup label="Specials">
                                    <?php foreach ($local_specials_for_select as $sp_l): ?>
                                        <option value="special:<?php echo $sp_l->ID; ?>" <?php selected($sel_target, 'special:' . $sp_l->ID); ?>>⭐ <?php echo esc_html($sp_l->post_title); ?> (#<?php echo $sp_l->ID; ?>)</option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                        </td>
                        <td>
                            <select name="map[<?php echo $p['id']; ?>][cat_index]" class="tix-mig-cat" data-row="<?php echo $p['id']; ?>" data-selected="<?php echo $sel_cat; ?>" style="width:100%;max-width:300px;">
                                <option value="0">—</option>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php // Specials-Mapping ?>
            <?php if (!empty($specials)): ?>
            <h3 style="margin-top:32px;">Specials</h3>
            <p>Specials werden auto-gemappt per Slug oder Title. Nicht gefundene werden beim Import als neue <code>tix_special</code> Posts angelegt.</p>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th>Altes Special</th><th>Preis / Wert</th><th>→ Status auf dieser Site</th></tr></thead>
                <tbody>
                <?php foreach ($specials as $sp):
                    $new_sid = $special_map[$sp['id']] ?? 0;
                    $new_sp  = $new_sid > 0 ? get_post($new_sid) : null;
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($sp['title']); ?></strong> <small style="color:#9ca3af;">[<?php echo esc_html($sp['slug']); ?>]</small></td>
                        <td><?php echo number_format(floatval($sp['price']), 2, ',', '.'); ?> €<?php if (!empty($sp['value'])): ?> <small style="color:#9ca3af;">(Wert <?php echo number_format(floatval($sp['value']), 2, ',', '.'); ?> €)</small><?php endif; ?></td>
                        <td>
                            <?php if ($new_sp): ?>
                                <span style="color:#22c55e;">✓</span> Wird wiederverwendet — <?php echo esc_html($new_sp->post_title); ?> <small style="color:#9ca3af;">(ID <?php echo $new_sid; ?>)</small>
                            <?php else: ?>
                                <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;">+ wird neu angelegt</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h3 style="margin-top:32px;">Step 3 — Import-Optionen</h3>
            <table class="form-table">
                <tr>
                    <th>Importieren</th>
                    <td>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_users"    value="1" checked> Users <small>(inkl. Passwort-Hash → direktes Login möglich)</small></label>
                        <?php if (!empty($specials)): ?>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_specials" value="1" checked> Specials <small>(legt fehlende tix_special-Posts an + verknüpft mit Events)</small></label>
                        <?php endif; ?>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_orders"   value="1" checked> Bestellungen <small>(WC + native; legacy_wc_order_number wird gespeichert für Support-Lookup)</small></label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_tickets"  value="1" checked> Tickets <small>(mit Original-Codes; Special-Marker werden mitkopiert)</small></label>
                    </td>
                </tr>
                <tr>
                    <th>Fallback-Event<br><small style="font-weight:400;color:#9ca3af;">für Items aus gelöschten alten Events</small></th>
                    <td>
                        <select name="fallback_event_id" style="width:100%;max-width:480px;">
                            <option value="0">— kein Fallback (Items werden geskippt) —</option>
                            <?php foreach ($local_events as $ev): ?>
                                <option value="<?php echo $ev->ID; ?>"><?php echo esc_html($ev->post_title); ?> <small>(#<?php echo $ev->ID; ?>)</small></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description" style="margin-top:4px;">Wähle z.B. ein „Archiv"-Event, damit alte Bestellungen mit gelöschten Events trotzdem importiert werden (nur für Support-Lookup, ohne echtes Ticket-Stock).</p>
                    </td>
                </tr>
                <tr>
                    <th>Modus</th>
                    <td>
                        <label><input type="checkbox" name="dry_run" value="1" checked> <strong>Dry-Run</strong> — nur anzeigen, nicht schreiben</label>
                    </td>
                </tr>
            </table>

            <p class="submit"><button type="submit" class="button button-primary button-large">Import starten</button></p>
        </form>

        <script>
        (function(){
            function refreshCats(row) {
                var $sel = document.querySelector('.tix-mig-event[data-row="' + row + '"]');
                var $cat = document.querySelector('.tix-mig-cat[data-row="' + row + '"]');
                if (!$sel || !$cat) return;
                var val = $sel.value;
                var selected = parseInt($cat.dataset.selected || 0);
                $cat.innerHTML = '<option value="0">—</option>';
                if (val.indexOf('event:') !== 0) {
                    // Special oder kein Mapping → keine Categories
                    $cat.disabled = true;
                    return;
                }
                $cat.disabled = false;
                var eid = parseInt(val.substring(6));
                var cats = (TIX_EVENT_CATS[eid] || []);
                cats.forEach(function(c){
                    var o = document.createElement('option');
                    o.value = c.index; o.textContent = c.name + ' (' + c.price.toFixed(2).replace('.', ',') + ' €)';
                    if (c.index === selected) o.selected = true;
                    $cat.appendChild(o);
                });
            }
            document.querySelectorAll('.tix-mig-event').forEach(function(s){
                refreshCats(s.dataset.row);
                s.addEventListener('change', function(){ s.parentElement.parentElement.querySelector('.tix-mig-cat').dataset.selected = '0'; refreshCats(s.dataset.row); });
            });
        })();
        </script>
    <?php }
}
