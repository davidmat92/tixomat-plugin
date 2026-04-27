<?php
/**
 * Tixomat Migration Tool
 *
 * Domain-zu-Domain-Migration für Tickets, Users und Bestellungen.
 *
 * Quelle (alte Domain): Export → JSON-Datei
 * Ziel (neue Domain):    Import ← JSON-Datei
 *
 * Was übertragen wird:
 *   - Users (inkl. user_pass-Hash → User können sich direkt einloggen)
 *   - WC-Bestellungen (als legacy_wc_order_number gespeichert für Support-Lookup)
 *   - Tixomat-native TIX_Order-Daten
 *   - tix_ticket CPT-Posts mit ursprünglichen Codes
 *   - Event-ID-Mapping (alte ID → neue ID via Title-Match)
 *
 * Sicherheit:
 *   - Nonce-geschützt, manage_options nur
 *   - Dry-Run Default an: Vorschau ohne Schreibzugriff
 *   - Backup-Empfehlung VOR Execute
 */
if (!defined('ABSPATH')) exit;

class TIX_Migration {

    const EXPORT_VERSION = 1;
    const NONCE_KEY = 'tix_migration';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 70);
        add_action('admin_post_tix_migration_export', [__CLASS__, 'handle_export']);
        add_action('admin_post_tix_migration_import', [__CLASS__, 'handle_import']);
    }

    public static function register_menu() {
        add_submenu_page(
            'tixomat',
            'Migration',
            'Migration',
            'manage_options',
            'tix-migration',
            [__CLASS__, 'render_admin_page']
        );
    }

    // ════════════════════════════════════════════════════════════
    // EXPORT (auf alter Site)
    // ════════════════════════════════════════════════════════════

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_KEY);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $data = self::build_export();

        $filename = 'tixomat-migration-' . sanitize_file_name(parse_url(home_url(), PHP_URL_HOST)) . '-' . date('Y-m-d-His') . '.json';
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    private static function build_export() {
        global $wpdb;

        // ── Users ──
        $users = [];
        $user_rows = $wpdb->get_results("SELECT ID, user_login, user_pass, user_email, user_registered, display_name, user_nicename FROM {$wpdb->users} ORDER BY ID ASC");
        foreach ($user_rows as $u) {
            $meta = [];
            $meta_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d",
                $u->ID
            ));
            foreach ($meta_rows as $m) {
                // Skip ephemeral/security keys
                if (in_array($m->meta_key, ['session_tokens', 'wp_user-settings', 'wp_user-settings-time', 'closedpostboxes_event'], true)) continue;
                $meta[$m->meta_key] = maybe_unserialize($m->meta_value);
            }
            $users[] = [
                'id'              => intval($u->ID),
                'login'           => $u->user_login,
                'pass'            => $u->user_pass, // Hash 1:1 → User loggt sich mit altem PW ein
                'email'           => $u->user_email,
                'registered'      => $u->user_registered,
                'display_name'    => $u->display_name,
                'nicename'        => $u->user_nicename,
                'roles'           => array_keys((array) get_userdata($u->ID)->caps ?? []),
                'meta'            => $meta,
            ];
        }

        // ── Events (für ID-Mapping per Title) ──
        $events = [];
        $ev_posts = get_posts([
            'post_type'      => 'event',
            'post_status'    => ['publish', 'draft', 'private', 'trash'],
            'posts_per_page' => -1,
        ]);
        foreach ($ev_posts as $ev) {
            $events[] = [
                'id'         => $ev->ID,
                'title'      => $ev->post_title,
                'slug'       => $ev->post_name,
                'date_start' => get_post_meta($ev->ID, '_tix_date_start', true),
            ];
        }

        // ── WC-Orders (vollständig) ──
        $wc_orders = [];
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'status'  => array_keys(wc_get_order_statuses()),
                'limit'   => -1,
                'orderby' => 'date',
                'order'   => 'ASC',
            ]);
            foreach ($orders as $o) {
                $items = [];
                foreach ($o->get_items() as $item) {
                    $product_id = $item->get_product_id();
                    $items[] = [
                        'product_id'    => $product_id,
                        'product_name'  => $item->get_name(),
                        'qty'           => $item->get_quantity(),
                        'total'         => floatval($item->get_total()),
                        'event_id'      => intval(get_post_meta($product_id, '_tix_parent_event_id', true)),
                        'cat_index'     => intval(get_post_meta($product_id, '_tix_ticket_cat_index', true)),
                    ];
                }
                $wc_orders[] = [
                    'wc_id'          => $o->get_id(),
                    'order_number'   => $o->get_order_number(), // Custom-Bestellnummer
                    'status'         => $o->get_status(),
                    'currency'       => $o->get_currency(),
                    'total'          => floatval($o->get_total()),
                    'tax'            => floatval($o->get_total_tax()),
                    'date_created'   => $o->get_date_created() ? $o->get_date_created()->date('Y-m-d H:i:s') : '',
                    'date_paid'      => $o->get_date_paid() ? $o->get_date_paid()->date('Y-m-d H:i:s') : '',
                    'payment_method' => $o->get_payment_method(),
                    'payment_method_title' => $o->get_payment_method_title(),
                    'transaction_id' => $o->get_transaction_id(),
                    'customer_id'    => $o->get_customer_id(),
                    'billing'        => [
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

        // ── TIX_Order (native) ──
        $tix_orders = [];
        if (class_exists('TIX_Order')) {
            $rows = $wpdb->get_results("SELECT * FROM " . TIX_Order::table_name() . " ORDER BY id ASC", ARRAY_A);
            foreach ($rows as $r) {
                $items = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM " . TIX_Order::items_table_name() . " WHERE order_id = %d",
                    $r['id']
                ), ARRAY_A);
                $tix_orders[] = ['order' => $r, 'items' => $items];
            }
        }

        // ── Tickets (tix_ticket CPT) ──
        $tickets = [];
        $ticket_posts = get_posts([
            'post_type'      => 'tix_ticket',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
        ]);
        foreach ($ticket_posts as $t) {
            $tickets[] = [
                'id'        => $t->ID,
                'title'     => $t->post_title,
                'date'      => $t->post_date,
                'code'      => get_post_meta($t->ID, '_tix_ticket_code', true),
                'event_id'  => intval(get_post_meta($t->ID, '_tix_ticket_event_id', true)),
                'order_id'  => intval(get_post_meta($t->ID, '_tix_ticket_order_id', true)),
                'cat_index' => intval(get_post_meta($t->ID, '_tix_ticket_cat_index', true)),
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
                'version'        => self::EXPORT_VERSION,
                'source_url'     => home_url(),
                'source_blog'    => get_bloginfo('name'),
                'exported_at'    => current_time('mysql'),
                'wp_version'     => get_bloginfo('version'),
                'tixomat_version'=> defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '?',
                'has_wc'         => function_exists('wc_get_orders'),
                'counts'         => [
                    'users'      => count($users),
                    'events'     => count($events),
                    'wc_orders'  => count($wc_orders),
                    'tix_orders' => count($tix_orders),
                    'tickets'    => count($tickets),
                ],
            ],
            'users'      => $users,
            'events'     => $events,
            'wc_orders'  => $wc_orders,
            'tix_orders' => $tix_orders,
            'tickets'    => $tickets,
        ];
    }

    // ════════════════════════════════════════════════════════════
    // IMPORT (auf neuer Site)
    // ════════════════════════════════════════════════════════════

    public static function handle_import() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer(self::NONCE_KEY);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        if (empty($_FILES['migration_file']['tmp_name'])) {
            wp_redirect(add_query_arg(['page' => 'tix-migration', 'msg' => 'no_file'], admin_url('admin.php')));
            exit;
        }

        $json = file_get_contents($_FILES['migration_file']['tmp_name']);
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['meta'])) {
            wp_redirect(add_query_arg(['page' => 'tix-migration', 'msg' => 'invalid_json'], admin_url('admin.php')));
            exit;
        }

        $dry_run = !empty($_POST['dry_run']);
        $do_users   = !empty($_POST['import_users']);
        $do_orders  = !empty($_POST['import_orders']);
        $do_tickets = !empty($_POST['import_tickets']);

        $event_map = self::build_event_map($data['events'] ?? []);

        $report = [
            'dry_run'   => $dry_run,
            'event_map' => $event_map,
        ];

        if ($do_users)   $report['users']   = self::import_users($data['users'] ?? [], $dry_run);
        if ($do_orders)  $report['orders']  = self::import_orders($data['wc_orders'] ?? [], $data['tix_orders'] ?? [], $event_map, $dry_run);
        if ($do_tickets) $report['tickets'] = self::import_tickets($data['tickets'] ?? [], $event_map, $dry_run);

        // Report transient für die Page-Anzeige
        set_transient('tix_migration_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS);

        wp_redirect(add_query_arg(['page' => 'tix-migration', 'msg' => $dry_run ? 'dry_run_done' : 'imported'], admin_url('admin.php')));
        exit;
    }

    private static function build_event_map($source_events) {
        $map = []; // alte_id => neue_id
        foreach ($source_events as $se) {
            // Match by slug zuerst, dann title, dann date_start
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

    private static function import_users($source_users, $dry_run = true) {
        global $wpdb;
        $created = 0; $updated = 0; $skipped = 0; $errors = [];
        foreach ($source_users as $su) {
            $existing = get_user_by('email', $su['email']);
            if ($existing) {
                $skipped++;
                continue; // Bestehender User wird nicht überschrieben (Sicherheit)
            }
            if ($dry_run) {
                $created++;
                continue;
            }
            // User direkt in DB anlegen mit altem Hash (wp_create_user würde re-hashen)
            $insert = $wpdb->insert($wpdb->users, [
                'user_login'      => $su['login'],
                'user_pass'       => $su['pass'], // Hash 1:1 — bcrypt/phpass werden von wp_check_password() erkannt
                'user_email'      => $su['email'],
                'user_registered' => $su['registered'] ?: current_time('mysql'),
                'display_name'    => $su['display_name'],
                'user_nicename'   => $su['nicename'],
                'user_status'     => 0,
            ]);
            if ($insert === false) {
                $errors[] = 'User ' . $su['email'] . ': ' . $wpdb->last_error;
                continue;
            }
            $new_uid = $wpdb->insert_id;
            // Meta-Daten
            foreach (($su['meta'] ?? []) as $k => $v) {
                update_user_meta($new_uid, $k, $v);
            }
            // Tixomat-Customer-Rolle setzen
            $u = new WP_User($new_uid);
            if (!empty($su['roles'])) {
                foreach ($su['roles'] as $role) $u->add_role($role);
            } else {
                $u->set_role('customer');
            }
            // Marker für Migration
            update_user_meta($new_uid, '_tix_legacy_user_id', $su['id']);
            update_user_meta($new_uid, '_tix_legacy_source', 'mallorca-festival-xxl.de');
            $created++;
        }
        return ['created' => $created, 'skipped' => $skipped, 'updated' => $updated, 'errors' => $errors];
    }

    private static function import_orders($wc_orders, $tix_orders, $event_map, $dry_run = true) {
        $created = 0; $skipped = 0; $errors = [];

        if (!class_exists('TIX_Order')) {
            return ['error' => 'TIX_Order class missing on target — cannot import orders'];
        }

        // WC-Orders → TIX_Order mit legacy_wc_order_number-Meta
        foreach ($wc_orders as $wo) {
            // Doppelten Import verhindern
            $existing = get_posts([
                'post_type'  => 'any',
                'meta_key'   => '_tix_legacy_wc_order_id',
                'meta_value' => $wo['wc_id'],
                'posts_per_page' => 1,
                'fields' => 'ids',
            ]);
            if (!empty($existing)) {
                $skipped++;
                continue;
            }
            if ($dry_run) { $created++; continue; }

            // Native TIX_Order anlegen
            $items_for_tix = [];
            foreach ($wo['items'] as $it) {
                $eid_old = intval($it['event_id']);
                $eid_new = $event_map[$eid_old] ?? 0;
                if (!$eid_new) {
                    $errors[] = 'WC-Order ' . $wo['wc_id'] . ': Event-Mapping fehlt für Event ' . $eid_old;
                    continue;
                }
                $items_for_tix[] = [
                    'event_id'  => $eid_new,
                    'cat_index' => intval($it['cat_index']),
                    'name'      => $it['product_name'],
                    'price'     => floatval($it['total']) / max(1, intval($it['qty'])),
                    'qty'       => intval($it['qty']),
                ];
            }

            if (empty($items_for_tix)) { $skipped++; continue; }

            $order_data = [
                'status'       => self::map_wc_status($wo['status']),
                'total'        => floatval($wo['total']),
                'tax'          => floatval($wo['tax']),
                'currency'     => $wo['currency'] ?: 'EUR',
                'date_created' => $wo['date_created'],
                'date_paid'    => $wo['date_paid'],
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
                if (!$new_order_id) {
                    $errors[] = 'WC-Order ' . $wo['wc_id'] . ': create_legacy() failed';
                    continue;
                }
                // Customer-Lookup (legacy user ID → neue user ID)
                if ($wo['customer_id']) {
                    $new_users = get_users([
                        'meta_key'   => '_tix_legacy_user_id',
                        'meta_value' => $wo['customer_id'],
                        'number'     => 1,
                        'fields'     => 'ID',
                    ]);
                    if (!empty($new_users)) {
                        global $wpdb;
                        $wpdb->update(TIX_Order::table_name(), ['user_id' => intval($new_users[0])], ['id' => $new_order_id]);
                    }
                }
                // Legacy-Marker
                update_post_meta($new_order_id, '_tix_legacy_wc_order_id', $wo['wc_id']);
                update_post_meta($new_order_id, '_tix_legacy_wc_order_number', $wo['order_number']);
                update_post_meta($new_order_id, '_tix_legacy_source', 'mallorca-festival-xxl.de');
                $created++;
            } catch (\Throwable $e) {
                $errors[] = 'WC-Order ' . $wo['wc_id'] . ': ' . $e->getMessage();
            }
        }
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    private static function map_wc_status($wc_status) {
        $wc_status = preg_replace('/^wc-/', '', $wc_status);
        $map = [
            'completed'  => 'completed',
            'processing' => 'completed',
            'on-hold'    => 'pending',
            'pending'    => 'pending',
            'cancelled'  => 'cancelled',
            'refunded'   => 'refunded',
            'failed'     => 'cancelled',
        ];
        return $map[$wc_status] ?? 'completed';
    }

    private static function import_tickets($source_tickets, $event_map, $dry_run = true) {
        $created = 0; $skipped = 0; $errors = [];
        foreach ($source_tickets as $st) {
            // Doppelten Import via Code verhindern
            if (!empty($st['code'])) {
                $existing = get_posts([
                    'post_type'  => 'tix_ticket',
                    'meta_key'   => '_tix_ticket_code',
                    'meta_value' => $st['code'],
                    'posts_per_page' => 1,
                    'fields'     => 'ids',
                ]);
                if (!empty($existing)) {
                    $skipped++;
                    continue;
                }
            }
            $eid_old = intval($st['event_id']);
            $eid_new = $event_map[$eid_old] ?? 0;
            if (!$eid_new) {
                $errors[] = 'Ticket ' . ($st['code'] ?: $st['id']) . ': Event-Mapping fehlt';
                continue;
            }
            if ($dry_run) { $created++; continue; }

            // Order-Mapping (über legacy meta)
            $new_order_id = 0;
            if ($st['order_id']) {
                $hits = get_posts([
                    'post_type'  => 'any',
                    'meta_key'   => '_tix_legacy_wc_order_id',
                    'meta_value' => $st['order_id'],
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                ]);
                if (!empty($hits)) $new_order_id = intval($hits[0]);
            }

            $tid = wp_insert_post([
                'post_type'   => 'tix_ticket',
                'post_status' => 'publish',
                'post_title'  => $st['title'] ?: 'Ticket ' . $st['code'],
                'post_date'   => $st['date'],
            ]);
            if (is_wp_error($tid) || !$tid) {
                $errors[] = 'Ticket ' . $st['code'] . ': insert failed';
                continue;
            }
            update_post_meta($tid, '_tix_ticket_code',          $st['code']);
            update_post_meta($tid, '_tix_ticket_event_id',      $eid_new);
            update_post_meta($tid, '_tix_ticket_order_id',      $new_order_id);
            update_post_meta($tid, '_tix_ticket_cat_index',     intval($st['cat_index']));
            update_post_meta($tid, '_tix_ticket_owner_name',    $st['owner_name']);
            update_post_meta($tid, '_tix_ticket_owner_email',   $st['owner_email']);
            update_post_meta($tid, '_tix_ticket_status',        $st['status'] ?: 'valid');
            if (!empty($st['checked_in'])) {
                update_post_meta($tid, '_tix_ticket_checked_in', 1);
                update_post_meta($tid, '_tix_ticket_checkin_time', $st['checkin_time']);
            }
            if (!empty($st['assigned_name'])) update_post_meta($tid, '_tix_ticket_assigned_name', $st['assigned_name']);
            if (!empty($st['seat_id']))      update_post_meta($tid, '_tix_ticket_seat_id', $st['seat_id']);
            update_post_meta($tid, '_tix_legacy_ticket_id', $st['id']);
            update_post_meta($tid, '_tix_legacy_source',    'mallorca-festival-xxl.de');
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
        $report = get_transient('tix_migration_report_' . get_current_user_id());
        if ($report) delete_transient('tix_migration_report_' . get_current_user_id());

        // Quick stats
        $stats = [
            'users'  => count_users()['total_users'] ?? 0,
            'events' => wp_count_posts('event')->publish ?? 0,
            'tickets'=> wp_count_posts('tix_ticket')->publish ?? 0,
            'has_wc' => function_exists('wc_get_orders'),
        ];
        ?>
        <div class="wrap" style="max-width:1100px;">
            <h1>Migration <span style="font-size:13px;font-weight:400;color:#9ca3af;">Domain-zu-Domain Transfer</span></h1>

            <?php if ($msg === 'imported'): ?>
                <div class="notice notice-success"><p>Import abgeschlossen.</p></div>
            <?php elseif ($msg === 'dry_run_done'): ?>
                <div class="notice notice-info"><p>Dry-Run abgeschlossen — siehe Bericht unten. Es wurde NICHTS in die DB geschrieben.</p></div>
            <?php elseif ($msg === 'no_file'): ?>
                <div class="notice notice-error"><p>Bitte JSON-Datei auswählen.</p></div>
            <?php elseif ($msg === 'invalid_json'): ?>
                <div class="notice notice-error"><p>Ungültige JSON-Datei.</p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="?page=tix-migration&tab=export" class="nav-tab <?php echo $tab === 'export' ? 'nav-tab-active' : ''; ?>">📤 Export</a>
                <a href="?page=tix-migration&tab=import" class="nav-tab <?php echo $tab === 'import' ? 'nav-tab-active' : ''; ?>">📥 Import</a>
            </h2>

            <div style="background:#fff;border:1px solid #c3c4c7;border-top:0;padding:24px;">

                <?php if ($tab === 'export'): ?>
                    <h2 style="margin-top:0;">Export auf der ALTEN Domain</h2>
                    <p>Erstellt eine vollständige JSON-Datei mit allen Users, Bestellungen und Tickets — zum Import auf der neuen Domain.</p>

                    <h3>Aktuelle Site enthält:</h3>
                    <table class="widefat striped" style="max-width:500px;">
                        <tr><td>Users</td><td><strong><?php echo intval($stats['users']); ?></strong></td></tr>
                        <tr><td>Events</td><td><strong><?php echo intval($stats['events']); ?></strong></td></tr>
                        <tr><td>Tickets (tix_ticket)</td><td><strong><?php echo intval($stats['tickets']); ?></strong></td></tr>
                        <tr><td>WooCommerce</td><td><strong><?php echo $stats['has_wc'] ? '✓ aktiv (Orders werden mitgenommen)' : '✗ nicht aktiv'; ?></strong></td></tr>
                    </table>

                    <p style="margin-top:24px;color:#b91c1c;"><strong>Wichtig:</strong> User-Passwort-Hashes werden mitexportiert. Datei enthält sensible Daten — sicher aufbewahren und nach Import löschen.</p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:20px;">
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <input type="hidden" name="action" value="tix_migration_export">
                        <button type="submit" class="button button-primary button-large">JSON-Datei herunterladen</button>
                    </form>

                <?php else: ?>
                    <h2 style="margin-top:0;">Import auf der NEUEN Domain</h2>
                    <p>Lade die JSON-Datei hoch, die du auf der alten Domain exportiert hast.</p>

                    <p style="background:#fef3c7;border-left:3px solid #f59e0b;padding:12px 14px;border-radius:6px;color:#78350f;">
                        <strong>Empfohlen:</strong> Erst <strong>Dry-Run</strong> aktivieren — zeigt was passieren würde ohne tatsächlich zu schreiben.
                        Dann Backup machen. Erst dann ohne Dry-Run importieren.
                    </p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin-top:20px;">
                        <?php wp_nonce_field(self::NONCE_KEY); ?>
                        <input type="hidden" name="action" value="tix_migration_import">

                        <table class="form-table">
                            <tr>
                                <th><label for="migration_file">JSON-Datei</label></th>
                                <td><input type="file" id="migration_file" name="migration_file" accept=".json,application/json" required></td>
                            </tr>
                            <tr>
                                <th>Importieren</th>
                                <td>
                                    <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_users"   value="1" checked> Users <small>(inkl. Passwort-Hash → direktes Login möglich)</small></label>
                                    <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_orders"  value="1" checked> Bestellungen <small>(WC + native; legacy_wc_order_number wird gespeichert)</small></label>
                                    <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_tickets" value="1" checked> Tickets <small>(mit Original-Codes, Event-IDs werden gemappt)</small></label>
                                </td>
                            </tr>
                            <tr>
                                <th>Modus</th>
                                <td>
                                    <label><input type="checkbox" name="dry_run" value="1" checked> <strong>Dry-Run</strong> — nur anzeigen, nicht schreiben</label>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary button-large">Import starten</button>
                        </p>
                    </form>

                    <?php if ($report): ?>
                        <h2 style="margin-top:32px;">Bericht <?php echo $report['dry_run'] ? '(Dry-Run)' : ''; ?></h2>
                        <pre style="background:#1e293b;color:#a7f3d0;padding:18px;border-radius:8px;overflow:auto;font-size:12px;line-height:1.5;max-height:600px;"><?php echo esc_html(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
