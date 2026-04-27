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

    // ════════════════════════════════════════════════════════════
    // EXPORT (alte Site)
    // ════════════════════════════════════════════════════════════

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_KEY);
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        // Output buffer leeren — wir streamen direkt
        while (ob_get_level()) ob_end_clean();

        $filename = 'tixomat-migration-' . sanitize_file_name(parse_url(home_url(), PHP_URL_HOST)) . '-' . date('Y-m-d-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Accel-Buffering: no'); // Nginx-Buffering aus
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
                    'status' => array_keys(wc_get_order_statuses()),
                    'limit'  => $batch, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC',
                ]);
                foreach ($orders as $o) {
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

        // ── tickets ──
        $emit_array_open('tickets');
        $offset = 0;
        do {
            $tps = get_posts([
                'post_type' => 'tix_ticket', 'post_status' => ['publish', 'draft'],
                'posts_per_page' => $batch, 'offset' => $offset, 'no_found_rows' => true,
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

        // Token + Transient
        $token = bin2hex(random_bytes(8));
        set_transient(self::token_key($token), $data, HOUR_IN_SECONDS);

        // Auto-Mapping initial: aus Event-Mapping ableiten
        $event_map   = self::build_event_map($data['events'] ?? []);
        $product_map = [];
        foreach (($data['products'] ?? []) as $p) {
            $old_eid = intval($p['event_id']);
            $new_eid = $event_map[$old_eid] ?? 0;
            $product_map[$p['id']] = [
                'event_id'  => $new_eid,
                'cat_index' => intval($p['cat_index']),
            ];
        }
        set_transient(self::map_key($token), [
            'event_map'   => $event_map,
            'product_map' => $product_map,
        ], HOUR_IN_SECONDS);

        wp_redirect(add_query_arg(['page' => 'tix-migration', 'tab' => 'import', 'token' => $token], admin_url('admin.php')));
        exit;
    }

    public static function handle_clear() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_KEY);
        $token = sanitize_text_field($_REQUEST['token'] ?? '');
        if ($token) {
            delete_transient(self::token_key($token));
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
        $data  = $token ? get_transient(self::token_key($token)) : null;
        if (!$data) {
            wp_redirect(add_query_arg(['page' => 'tix-migration', 'msg' => 'expired'], admin_url('admin.php')));
            exit;
        }

        // User-Mapping speichern (wurde aus dem Form gepostet)
        $product_map = [];
        if (!empty($_POST['map']) && is_array($_POST['map'])) {
            foreach ($_POST['map'] as $pid => $cfg) {
                $product_map[intval($pid)] = [
                    'event_id'  => intval($cfg['event_id'] ?? 0),
                    'cat_index' => intval($cfg['cat_index'] ?? 0),
                ];
            }
        }
        $event_map = self::build_event_map($data['events'] ?? []);

        // Aktualisierte Mappings persistieren (für mögliche Re-Runs)
        set_transient(self::map_key($token), ['event_map' => $event_map, 'product_map' => $product_map], HOUR_IN_SECONDS);

        $dry_run    = !empty($_POST['dry_run']);
        $do_users   = !empty($_POST['import_users']);
        $do_orders  = !empty($_POST['import_orders']);
        $do_tickets = !empty($_POST['import_tickets']);

        $report = ['dry_run' => $dry_run, 'event_map' => $event_map, 'product_map' => $product_map];
        if ($do_users)   $report['users']   = self::import_users($data['users'] ?? [], $dry_run);
        if ($do_orders)  $report['orders']  = self::import_orders($data['wc_orders'] ?? [], $data['tix_orders'] ?? [], $event_map, $product_map, $dry_run);
        if ($do_tickets) $report['tickets'] = self::import_tickets($data['tickets'] ?? [], $event_map, $product_map, $dry_run);

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
     * Liefert für ein altes WC-Item (mit product_id) das gemappte event_id + cat_index.
     * Fallback: alte event_id über event_map.
     */
    private static function resolve_item_mapping($item, $event_map, $product_map) {
        $pid = intval($item['product_id'] ?? 0);
        if ($pid && isset($product_map[$pid]) && $product_map[$pid]['event_id'] > 0) {
            return $product_map[$pid];
        }
        $old_eid = intval($item['event_id'] ?? 0);
        $new_eid = $event_map[$old_eid] ?? 0;
        return ['event_id' => $new_eid, 'cat_index' => intval($item['cat_index'] ?? 0)];
    }

    // ── Users ──
    private static function import_users($source_users, $dry_run = true) {
        global $wpdb;
        $created = 0; $skipped = 0; $errors = [];
        foreach ($source_users as $su) {
            $existing = get_user_by('email', $su['email']);
            if ($existing) { $skipped++; continue; }
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
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ── Orders ──
    private static function import_orders($wc_orders, $tix_orders, $event_map, $product_map, $dry_run = true) {
        $created = 0; $skipped = 0; $errors = [];
        if (!class_exists('TIX_Order')) return ['error' => 'TIX_Order class missing'];

        foreach ($wc_orders as $wo) {
            $existing = get_posts([
                'post_type' => 'any', 'meta_key' => '_tix_legacy_wc_order_id', 'meta_value' => $wo['wc_id'],
                'posts_per_page' => 1, 'fields' => 'ids',
            ]);
            if (!empty($existing)) { $skipped++; continue; }
            if ($dry_run) { $created++; continue; }

            $items_for_tix = [];
            foreach ($wo['items'] as $it) {
                $resolved = self::resolve_item_mapping($it, $event_map, $product_map);
                if (!$resolved['event_id']) {
                    $errors[] = 'WC-Order ' . $wo['wc_id'] . ' Item product ' . $it['product_id'] . ': kein Mapping';
                    continue;
                }
                $items_for_tix[] = [
                    'event_id'  => $resolved['event_id'],
                    'cat_index' => $resolved['cat_index'],
                    'name'      => $it['product_name'],
                    'price'     => floatval($it['total']) / max(1, intval($it['qty'])),
                    'qty'       => intval($it['qty']),
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

                if ($wo['customer_id']) {
                    $new_users = get_users(['meta_key' => '_tix_legacy_user_id', 'meta_value' => $wo['customer_id'], 'number' => 1, 'fields' => 'ID']);
                    if (!empty($new_users)) {
                        global $wpdb;
                        $wpdb->update(TIX_Order::table_name(), ['user_id' => intval($new_users[0])], ['id' => $new_order_id]);
                    }
                }

                update_post_meta($new_order_id, '_tix_legacy_wc_order_id',     $wo['wc_id']);
                update_post_meta($new_order_id, '_tix_legacy_wc_order_number', $wo['order_number']);
                update_post_meta($new_order_id, '_tix_legacy_source', parse_url(home_url(), PHP_URL_HOST));

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
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    private static function map_wc_status($wc_status) {
        $wc_status = preg_replace('/^wc-/', '', $wc_status);
        $map = ['completed' => 'completed', 'processing' => 'completed', 'on-hold' => 'pending', 'pending' => 'pending', 'cancelled' => 'cancelled', 'refunded' => 'refunded', 'failed' => 'cancelled'];
        return $map[$wc_status] ?? 'completed';
    }

    // ── Tickets ──
    private static function import_tickets($source_tickets, $event_map, $product_map, $dry_run = true) {
        $created = 0; $skipped = 0; $errors = [];
        foreach ($source_tickets as $st) {
            if (!empty($st['code'])) {
                $existing = get_posts([
                    'post_type' => 'tix_ticket', 'meta_key' => '_tix_ticket_code', 'meta_value' => $st['code'],
                    'posts_per_page' => 1, 'fields' => 'ids',
                ]);
                if (!empty($existing)) { $skipped++; continue; }
            }

            // Mapping: erst über Product, dann über Event (Fallback)
            $resolved = self::resolve_item_mapping([
                'product_id' => intval($st['product_id'] ?? 0),
                'event_id'   => intval($st['event_id']),
                'cat_index'  => intval($st['cat_index']),
            ], $event_map, $product_map);

            if (!$resolved['event_id']) { $errors[] = 'Ticket ' . ($st['code'] ?: $st['id']) . ': kein Event-Mapping'; continue; }
            if ($dry_run) { $created++; continue; }

            // Order-Mapping über legacy meta
            $new_order_id = 0;
            if ($st['order_id']) {
                $hits = get_posts([
                    'post_type' => 'any', 'meta_key' => '_tix_legacy_wc_order_id', 'meta_value' => $st['order_id'],
                    'posts_per_page' => 1, 'fields' => 'ids',
                ]);
                if (!empty($hits)) $new_order_id = intval($hits[0]);
            }

            $tid = wp_insert_post([
                'post_type' => 'tix_ticket', 'post_status' => 'publish',
                'post_title' => $st['title'] ?: 'Ticket ' . $st['code'],
                'post_date' => $st['date'],
            ]);
            if (is_wp_error($tid) || !$tid) { $errors[] = 'Ticket ' . $st['code'] . ': insert failed'; continue; }

            update_post_meta($tid, '_tix_ticket_code',         $st['code']);
            update_post_meta($tid, '_tix_ticket_event_id',     $resolved['event_id']);
            update_post_meta($tid, '_tix_ticket_order_id',     $new_order_id);
            update_post_meta($tid, '_tix_ticket_cat_index',    $resolved['cat_index']);
            update_post_meta($tid, '_tix_ticket_owner_name',   $st['owner_name']);
            update_post_meta($tid, '_tix_ticket_owner_email',  $st['owner_email']);
            update_post_meta($tid, '_tix_ticket_status',       $st['status'] ?: 'valid');
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
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ════════════════════════════════════════════════════════════
    // ADMIN UI
    // ════════════════════════════════════════════════════════════

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) wp_die();
        $tab = $_GET['tab'] ?? 'export';
        $msg = $_GET['msg'] ?? '';
        $token = sanitize_text_field($_GET['token'] ?? '');
        $data  = $token ? get_transient(self::token_key($token)) : null;
        $map   = $token ? get_transient(self::map_key($token))   : null;
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
        $product_map = $map['product_map'] ?? [];
        $event_map = $map['event_map'] ?? [];

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
            echo intval($c['users']  ?? 0) . ' Users · ' . intval($c['products'] ?? 0) . ' Produkte · '
               . intval($c['wc_orders'] ?? 0) . ' WC-Bestellungen · ' . intval($c['tickets'] ?? 0) . ' Tickets';
            ?>
            ·
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_migration_clear&token=' . $token), self::NONCE_KEY)); ?>" style="color:#b91c1c;">Abbrechen / Andere Datei</a>
        </p>

        <?php // Event-Mapping-Bericht ?>
        <h3>Event-Mapping (auto, per Slug → Title)</h3>
        <table class="widefat striped" style="max-width:900px;">
            <thead><tr><th>Altes Event</th><th>→ Neues Event auf dieser Site</th></tr></thead>
            <tbody>
            <?php foreach ($events as $se):
                $new_eid = $event_map[$se['id']] ?? 0;
                $new_event = $new_eid ? get_post($new_eid) : null;
            ?>
                <tr>
                    <td><strong><?php echo esc_html($se['title']); ?></strong> <small style="color:#9ca3af;">[<?php echo esc_html($se['slug']); ?>]</small></td>
                    <td>
                        <?php if ($new_event): ?>
                            <span style="color:#22c55e;">✓</span> <?php echo esc_html($new_event->post_title); ?> <small style="color:#9ca3af;">(ID <?php echo $new_eid; ?>)</small>
                        <?php else: ?>
                            <span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:6px;">✗ KEIN MATCH</span> — bitte Event mit Slug <code><?php echo esc_html($se['slug']); ?></code> oder Titel <code><?php echo esc_html($se['title']); ?></code> auf dieser Site anlegen
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php // Produkt-Mapping (manuell) ?>
        <h3 style="margin-top:32px;">Produkt-Mapping</h3>
        <p>Wähle pro altem WooCommerce-Produkt das passende Event und die Ticketkategorie. Kategorien werden per JS aus dem gewählten Event geladen.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::NONCE_KEY); ?>
            <input type="hidden" name="action" value="tix_migration_execute">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

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
                    $sel_event = $product_map[$p['id']]['event_id'] ?? 0;
                    $sel_cat   = $product_map[$p['id']]['cat_index'] ?? 0;
                    $sold = intval($product_counts[$p['id']] ?? 0);
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($p['name']); ?></strong><br>
                            <small style="color:#9ca3af;">ID <?php echo $p['id']; ?> · <?php echo number_format($p['price'], 2, ',', '.'); ?> €<?php if ($p['sku']): ?> · SKU: <?php echo esc_html($p['sku']); ?><?php endif; ?></small>
                        </td>
                        <td><?php if ($sold): ?><strong><?php echo $sold; ?></strong> Tickets<?php else: ?><span style="color:#9ca3af;">—</span><?php endif; ?></td>
                        <td>
                            <select name="map[<?php echo $p['id']; ?>][event_id]" class="tix-mig-event" data-row="<?php echo $p['id']; ?>" style="width:100%;max-width:300px;">
                                <option value="0">— Event wählen —</option>
                                <?php foreach ($local_events as $ev): ?>
                                    <option value="<?php echo $ev->ID; ?>" <?php selected($sel_event, $ev->ID); ?>><?php echo esc_html($ev->post_title); ?></option>
                                <?php endforeach; ?>
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

            <h3 style="margin-top:32px;">Step 3 — Import-Optionen</h3>
            <table class="form-table">
                <tr>
                    <th>Importieren</th>
                    <td>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_users"   value="1" checked> Users <small>(inkl. Passwort-Hash → direktes Login möglich)</small></label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_orders"  value="1" checked> Bestellungen <small>(WC + native; legacy_wc_order_number wird gespeichert für Support-Lookup)</small></label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_tickets" value="1" checked> Tickets <small>(mit Original-Codes)</small></label>
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
                var $event = document.querySelector('.tix-mig-event[data-row="' + row + '"]');
                var $cat   = document.querySelector('.tix-mig-cat[data-row="' + row + '"]');
                if (!$event || !$cat) return;
                var eid = parseInt($event.value);
                var selected = parseInt($cat.dataset.selected || 0);
                var cats = (TIX_EVENT_CATS[eid] || []);
                $cat.innerHTML = '<option value="0">—</option>';
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
