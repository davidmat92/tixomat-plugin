<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Organizer_Admin – Gibt Veranstaltern Zugang zum wp-admin mit Fullscreen-Shell.
 *
 * - Erweitert die tix_organizer Rolle um nötige Capabilities
 * - Filtert Queries sodass Veranstalter nur eigene Events sehen
 * - Versteckt irrelevante Admin-Menüs
 * - Redirect: Login → Events-Liste, /veranstalter/ → wp-admin
 */
class TIX_Organizer_Admin {

    public static function init() {
        // Capabilities beim Plugin-Load sicherstellen
        add_action('admin_init', [__CLASS__, 'ensure_capabilities'], 1);

        // Query-Filter: nur eigene Events
        add_action('pre_get_posts', [__CLASS__, 'filter_events_query']);

        // Capability-Filter für einzelne Posts
        add_filter('map_meta_cap', [__CLASS__, 'map_event_caps'], 10, 4);

        // Admin-Menü aufräumen
        add_action('admin_menu', [__CLASS__, 'cleanup_admin_menu'], 999);

        // Admin-Bar komplett ausblenden für Veranstalter
        add_filter('show_admin_bar', [__CLASS__, 'hide_admin_bar']);
        add_action('admin_bar_menu', [__CLASS__, 'cleanup_admin_bar'], 999);

        // Login-Redirect
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 10, 3);

        // Frontend-Dashboard → wp-admin Redirect
        add_action('template_redirect', [__CLASS__, 'redirect_frontend_dashboard'], 1);

        // Verhindere Zugang zu nicht-erlaubten Admin-Seiten
        add_action('admin_init', [__CLASS__, 'restrict_admin_pages'], 5);

        // Auto-Assign Organizer bei neuen Events
        add_action('save_post_event', [__CLASS__, 'auto_assign_organizer'], 5, 2);

        // User-Profil: Veranstalter-Name Feld
        add_action('show_user_profile', [__CLASS__, 'render_profile_fields']);
        add_action('edit_user_profile', [__CLASS__, 'render_profile_fields']);
        add_action('personal_options_update', [__CLASS__, 'save_profile_fields']);
        add_action('edit_user_profile_update', [__CLASS__, 'save_profile_fields']);

        // Organizer Admin-Seiten
        add_action('admin_menu', [__CLASS__, 'add_organizer_pages'], 20);

        // CSV Exports
        add_action('admin_post_tix_organizer_guestlist_csv', [__CLASS__, 'export_guestlist_csv']);
        add_action('admin_post_tix_organizer_tickets_csv', [__CLASS__, 'export_tickets_csv']);
        add_action('admin_post_tix_organizer_combined_csv', [__CLASS__, 'export_combined_csv']);
        add_action('admin_post_tix_organizer_billing_csv', [__CLASS__, 'export_billing_csv']);

        // Bulk E-Mail AJAX
        add_action('wp_ajax_tix_organizer_send_email', [__CLASS__, 'ajax_send_email']);

        // Benachrichtigungen: Neue Bestellung + Low-Stock
        add_action('woocommerce_order_status_completed', [__CLASS__, 'notify_new_order']);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'notify_new_order']);

        // Medien: wp.media Modal nur eigene Bilder zeigen
        add_filter('ajax_query_attachments_args', [__CLASS__, 'filter_media_library']);

        // Medien: Eigene AJAX-Endpoints
        add_action('wp_ajax_tix_organizer_load_media', [__CLASS__, 'ajax_load_media']);
        add_action('wp_ajax_tix_organizer_upload_media', [__CLASS__, 'ajax_upload_media']);
        add_action('wp_ajax_tix_organizer_delete_media', [__CLASS__, 'ajax_delete_media']);
    }

    /**
     * Ist der aktuelle User ein Veranstalter?
     */
    public static function is_organizer($user_id = null) {
        if (!$user_id) $user_id = get_current_user_id();
        if (!$user_id) return false;
        $user = get_userdata($user_id);
        if (!$user) return false;
        return in_array('tix_organizer', (array) $user->roles, true);
    }

    /**
     * Gibt die Organizer-Post-ID für einen User zurück.
     */
    private static function get_organizer_id($user_id = null) {
        if (!$user_id) $user_id = get_current_user_id();
        if (!class_exists('TIX_Organizer_Dashboard')) return 0;
        $org = TIX_Organizer_Dashboard::get_organizer_by_user($user_id);
        return $org ? $org->ID : 0;
    }

    /**
     * Capabilities für tix_organizer Rolle sicherstellen.
     */
    public static function ensure_capabilities() {
        $role = get_role('tix_organizer');
        if (!$role) return;

        $needed = [
            'read', 'upload_files',
            // Events (post-Typ: event, nutzt Standard-Caps)
            'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts',
            // Medien
            'edit_others_posts', // nötig für Media-Library Zugang
        ];

        $changed = false;
        foreach ($needed as $cap) {
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap);
                $changed = true;
            }
        }

        // edit_others_posts ist nötig für Media, aber wir beschränken
        // den Zugriff auf Events über map_meta_cap und pre_get_posts
    }

    /**
     * Capability-Mapping: Veranstalter dürfen nur eigene Events bearbeiten.
     */
    public static function map_event_caps($caps, $cap, $user_id, $args) {
        if (!self::is_organizer($user_id)) return $caps;

        // Nur für post-bezogene Caps mit einer Post-ID
        $single_caps = ['edit_post', 'delete_post', 'read_post'];
        if (!in_array($cap, $single_caps, true)) return $caps;
        if (empty($args[0])) return $caps;

        $post = get_post($args[0]);
        if (!$post) return $caps;

        // Nur Events filtern
        if ($post->post_type !== 'event') {
            // Locations und Organizer CPTs auch erlauben
            if (in_array($post->post_type, ['tix_location', 'tix_organizer'], true)) {
                return $caps;
            }
            // Attachments (Medien) erlauben
            if ($post->post_type === 'attachment') {
                return $caps;
            }
            // Alles andere: do_not_allow
            return ['do_not_allow'];
        }

        // Event: Ownership prüfen
        $org_id = self::get_organizer_id($user_id);
        $event_org = intval(get_post_meta($post->ID, '_tix_organizer_id', true));

        if ($org_id && $event_org === $org_id) {
            // Eigenes Event → erlauben
            return ['edit_posts'];
        }

        // Neues Event (noch keine Organizer-ID) → erlauben
        if (!$event_org && $post->post_author == $user_id) {
            return ['edit_posts'];
        }

        return ['do_not_allow'];
    }

    /**
     * pre_get_posts: Veranstalter sehen nur eigene Events.
     */
    public static function filter_events_query($query) {
        if (!is_admin()) return;
        if (!$query->is_main_query()) return;
        if (!self::is_organizer()) return;

        $post_type = $query->get('post_type');

        if ($post_type === 'event') {
            $org_id = self::get_organizer_id();
            if ($org_id) {
                $meta_query = $query->get('meta_query') ?: [];
                $meta_query[] = [
                    'key'   => '_tix_organizer_id',
                    'value' => $org_id,
                    'type'  => 'NUMERIC',
                ];
                $query->set('meta_query', $meta_query);
            } else {
                // Kein Organizer-Profil → keine Events
                $query->set('post__in', [0]);
            }
        }

        // Tickets: nur von eigenen Events
        if ($post_type === 'tix_ticket') {
            $event_ids = self::get_organizer_event_ids();
            $meta_query = $query->get('meta_query') ?: [];
            $meta_query[] = [
                'key'     => '_tix_event_id',
                'value'   => $event_ids,
                'compare' => 'IN',
                'type'    => 'NUMERIC',
            ];
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Admin-Bar für Veranstalter komplett ausblenden.
     */
    public static function hide_admin_bar($show) {
        if (self::is_organizer()) return false;
        return $show;
    }

    /**
     * Admin-Menü: Nur relevante Einträge für Veranstalter.
     */
    public static function cleanup_admin_menu() {
        if (!self::is_organizer()) return;

        // Alles entfernen außer Tixomat
        $allowed_menus = ['tixomat', 'upload.php', 'profile.php'];
        global $menu;
        if ($menu) {
            foreach ($menu as $key => $item) {
                if (!in_array($item[2] ?? '', $allowed_menus, true)) {
                    remove_menu_page($item[2]);
                }
            }
        }

        // Tixomat Sub-Menüs aufräumen: nur Event-relevante
        $allowed_submenus = [
            'edit.php?post_type=event',
            'post-new.php?post_type=event',
            'edit-tags.php?taxonomy=event_category&post_type=event',
            'edit.php?post_type=tix_location',
            'edit.php?post_type=tix_ticket',
            'edit.php?post_type=tix_seatmap',
            'edit.php?post_type=tix_ticket_tpl',
            'edit.php?post_type=tix_subscriber',
            'tix-organizer-dashboard',
            'tix-organizer-orders',
            'tix-organizer-guestlist',
            'tix-organizer-email',
            'tix-organizer-billing',
            'tix-organizer-media',
            'tix-statistics',
            'tix-settings',
            'tix-docs',
        ];

        global $submenu;
        if (!empty($submenu['tixomat'])) {
            foreach ($submenu['tixomat'] as $key => $item) {
                if (!in_array($item[2] ?? '', $allowed_submenus, true)) {
                    unset($submenu['tixomat'][$key]);
                }
            }
        }
    }

    /**
     * Admin-Bar aufräumen für Veranstalter.
     */
    public static function cleanup_admin_bar($wp_admin_bar) {
        if (!self::is_organizer()) return;

        $remove = ['comments', 'new-content', 'wp-logo', 'updates', 'wpseo-menu'];
        foreach ($remove as $id) {
            $wp_admin_bar->remove_node($id);
        }
    }

    /**
     * Login-Redirect: Veranstalter → Events-Liste.
     */
    public static function login_redirect($redirect_to, $requested, $user) {
        if (!$user || is_wp_error($user)) return $redirect_to;
        if (!in_array('tix_organizer', (array) $user->roles, true)) return $redirect_to;

        return admin_url('admin.php?page=tix-organizer-dashboard');
    }

    /**
     * Frontend /veranstalter/ → wp-admin Redirect für Veranstalter.
     */
    public static function redirect_frontend_dashboard() {
        if (!is_user_logged_in()) return;
        if (!self::is_organizer()) return;

        global $post;
        if (!$post) return;

        // Prüfe ob Seite den Organizer-Dashboard-Shortcode enthält
        $has_shortcode = has_shortcode($post->post_content, 'tix_organizer_dashboard');
        if (!$has_shortcode) {
            $bd = get_post_meta($post->ID, '_breakdance_data', true);
            if ($bd && is_string($bd) && strpos($bd, 'tix_organizer_dashboard') !== false) {
                $has_shortcode = true;
            }
        }

        if ($has_shortcode) {
            wp_redirect(admin_url('admin.php?page=tix-organizer-dashboard'));
            exit;
        }
    }

    /**
     * Auto-Assign: Wenn Veranstalter ein Event erstellt, Organizer-ID setzen.
     */
    public static function auto_assign_organizer($post_id, $post) {
        if (!self::is_organizer()) return;
        if (wp_is_post_revision($post_id)) return;

        $existing = get_post_meta($post_id, '_tix_organizer_id', true);
        if ($existing) return;

        $org_id = self::get_organizer_id();
        if ($org_id) {
            update_post_meta($post_id, '_tix_organizer_id', $org_id);
        }
    }

    /**
     * Verhindere Zugang zu nicht-erlaubten Admin-Seiten.
     */
    public static function restrict_admin_pages() {
        if (!self::is_organizer()) return;

        // Erlaubte Seiten
        global $pagenow;
        $allowed_pages = [
            'index.php', 'edit.php', 'post.php', 'post-new.php',
            'edit-tags.php', 'admin.php', 'admin-ajax.php', 'profile.php',
            'async-upload.php', 'media-upload.php',
        ];

        if (!in_array($pagenow, $allowed_pages, true)) {
            wp_redirect(admin_url('admin.php?page=tix-organizer-dashboard'));
            exit;
        }

        // admin.php: nur erlaubte page-Parameter
        if ($pagenow === 'admin.php') {
            $page = $_GET['page'] ?? '';
            $allowed_admin_pages = [
                'tix-settings', 'tix-statistics', 'tix-docs',
                'tix-organizer-dashboard', 'tix-organizer-orders',
                'tix-organizer-guestlist', 'tix-organizer-email',
                'tix-organizer-billing', 'tix-organizer-media',
            ];
            if ($page && !in_array($page, $allowed_admin_pages, true)) {
                wp_redirect(admin_url('admin.php?page=tix-organizer-dashboard'));
                exit;
            }
        }

        // edit.php / post.php: nur erlaubte Post-Types
        if (in_array($pagenow, ['edit.php', 'post.php', 'post-new.php'], true)) {
            $pt = $_GET['post_type'] ?? '';
            if ($pagenow === 'post.php' && !empty($_GET['post'])) {
                $p = get_post(intval($_GET['post']));
                $pt = $p ? $p->post_type : '';
            }
            $allowed_types = ['event', 'tix_location', 'tix_ticket', 'tix_seatmap', 'tix_ticket_tpl', 'tix_subscriber', 'attachment', ''];
            if ($pt && !in_array($pt, $allowed_types, true)) {
                wp_redirect(admin_url('admin.php?page=tix-organizer-dashboard'));
                exit;
            }
        }
    }

    /**
     * Helper: Event-IDs des aktuellen Organizers.
     */
    private static function get_organizer_event_ids() {
        $org_id = self::get_organizer_id();
        if (!$org_id) return [0];

        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'meta_key'       => '_tix_organizer_id',
            'meta_value'     => $org_id,
            'meta_type'      => 'NUMERIC',
        ]);
        return $events ?: [0];
    }

    /**
     * Veranstalter-Name Feld im User-Profil.
     */
    public static function render_profile_fields($user) {
        // Nur für Veranstalter-Rolle oder Admin der Veranstalter bearbeitet
        if (!in_array('tix_organizer', (array) $user->roles, true) && !current_user_can('manage_options')) return;
        if (!in_array('tix_organizer', (array) $user->roles, true)) return;

        $name = get_user_meta($user->ID, '_tix_organizer_name', true);
        ?>
        <h3>Veranstalter</h3>
        <table class="form-table">
            <tr>
                <th><label for="tix_organizer_name">Veranstalter-Name</label></th>
                <td>
                    <input type="text" id="tix_organizer_name" name="tix_organizer_name"
                           value="<?php echo esc_attr($name); ?>" class="regular-text"
                           placeholder="z.B. MDJ Events GmbH">
                    <p class="description">Wird als Veranstaltername bei deinen Events angezeigt.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Admin-Seiten registrieren (versteckt, nur via Sidebar erreichbar).
     */
    public static function add_organizer_pages() {
        add_submenu_page('tixomat', 'Dashboard', 'Dashboard', 'edit_posts', 'tix-organizer-dashboard', [__CLASS__, 'render_dashboard_page']);
        add_submenu_page('tixomat', 'Bestellungen', 'Bestellungen', 'edit_posts', 'tix-organizer-orders', [__CLASS__, 'render_orders_page']);
        add_submenu_page('tixomat', 'G&auml;steliste Export', 'G&auml;steliste Export', 'edit_posts', 'tix-organizer-guestlist', [__CLASS__, 'render_guestlist_page']);
        add_submenu_page('tixomat', 'E-Mail senden', 'E-Mail senden', 'edit_posts', 'tix-organizer-email', [__CLASS__, 'render_email_page']);
        add_submenu_page('tixomat', 'Abrechnung', 'Abrechnung', 'edit_posts', 'tix-organizer-billing', [__CLASS__, 'render_billing_page']);
        add_submenu_page('tixomat', 'Meine Medien', 'Meine Medien', 'upload_files', 'tix-organizer-media', [__CLASS__, 'render_media_page']);
    }

    /**
     * Bestellungen-Seite für Veranstalter (eigene, reduzierte Ansicht).
     */
    public static function render_orders_page() {
        $org_id = self::get_organizer_id();
        $event_ids = self::get_organizer_event_ids();

        // Filter
        $filter_event = intval($_GET['event_id'] ?? 0);
        $filter_search = sanitize_text_field($_GET['s'] ?? '');
        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 25;

        // Events für Filter-Dropdown
        $events = get_posts([
            'post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'any',
            'meta_key' => '_tix_organizer_id', 'meta_value' => $org_id, 'meta_type' => 'NUMERIC',
            'orderby' => 'date', 'order' => 'DESC',
        ]);

        // Orders abfragen
        global $wpdb;
        $product_ids = [];
        foreach ($event_ids as $eid) {
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            if (is_array($cats)) {
                foreach ($cats as $cat) {
                    if (!empty($cat['product_id'])) $product_ids[] = intval($cat['product_id']);
                }
            }
        }

        $orders = [];
        $total = 0;
        if ($product_ids) {
            $pids = implode(',', $product_ids);
            $where = "oi.order_item_type = 'line_item' AND oim.meta_key = '_product_id' AND oim.meta_value IN ($pids)";

            if ($filter_event && in_array($filter_event, $event_ids)) {
                $evt_pids = [];
                $cats = get_post_meta($filter_event, '_tix_ticket_categories', true);
                if (is_array($cats)) foreach ($cats as $c) if (!empty($c['product_id'])) $evt_pids[] = intval($c['product_id']);
                if ($evt_pids) $where = "oi.order_item_type = 'line_item' AND oim.meta_key = '_product_id' AND oim.meta_value IN (" . implode(',', $evt_pids) . ")";
            }

            $order_ids_sql = "SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                WHERE $where ORDER BY oi.order_id DESC";

            $total = count($wpdb->get_col($order_ids_sql));
            $offset = ($paged - 1) * $per_page;
            $order_ids = $wpdb->get_col($order_ids_sql . " LIMIT $per_page OFFSET $offset");

            foreach ($order_ids as $oid) {
                $order = wc_get_order($oid);
                if (!$order) continue;

                if ($filter_search) {
                    $match = stripos($order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), $filter_search) !== false
                        || stripos($order->get_billing_email(), $filter_search) !== false
                        || stripos((string)$order->get_id(), $filter_search) !== false;
                    if (!$match) { $total--; continue; }
                }

                $tickets = [];
                foreach ($order->get_items() as $item) {
                    $pid = $item->get_product_id();
                    if (in_array($pid, $product_ids)) {
                        $tickets[] = $item->get_name() . ' &times;' . $item->get_quantity();
                    }
                }

                $orders[] = [
                    'id'      => $order->get_id(),
                    'date'    => $order->get_date_created() ? $order->get_date_created()->date_i18n('d.m.Y H:i') : '-',
                    'name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'email'   => $order->get_billing_email(),
                    'tickets' => $tickets,
                    'total'   => $order->get_total(),
                    'status'  => wc_get_order_status_name($order->get_status()),
                ];
            }
        }

        $total_pages = ceil($total / $per_page);
        ?>
        <div class="wrap">
            <h1>Bestellungen</h1>

            <div style="display:flex;gap:12px;margin:16px 0;flex-wrap:wrap;align-items:end;">
                <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
                    <input type="hidden" name="page" value="tix-organizer-orders">
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Event</label>
                        <select name="event_id" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                            <option value="">Alle Events</option>
                            <?php foreach ($events as $e) : ?>
                                <option value="<?php echo $e->ID; ?>" <?php selected($filter_event, $e->ID); ?>><?php echo esc_html($e->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Suche</label>
                        <input type="text" name="s" value="<?php echo esc_attr($filter_search); ?>" placeholder="Name, E-Mail, Bestellnr."
                               style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;width:200px;">
                    </div>
                    <button type="submit" class="button" style="padding:7px 16px;border-radius:8px;">Filtern</button>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th style="width:70px;">#</th>
                        <th style="width:130px;">Datum</th>
                        <th>K&auml;ufer</th>
                        <th>E-Mail</th>
                        <th>Tickets</th>
                        <th style="width:90px;text-align:right;">Betrag</th>
                        <th style="width:110px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)) : ?>
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">Keine Bestellungen gefunden.</td></tr>
                    <?php else : foreach ($orders as $o) : ?>
                        <tr>
                            <td><strong>#<?php echo $o['id']; ?></strong></td>
                            <td><?php echo $o['date']; ?></td>
                            <td><?php echo esc_html($o['name']); ?></td>
                            <td><a href="mailto:<?php echo esc_attr($o['email']); ?>"><?php echo esc_html($o['email']); ?></a></td>
                            <td><?php echo implode('<br>', $o['tickets']); ?></td>
                            <td style="text-align:right;font-weight:600;"><?php echo wc_price($o['total']); ?></td>
                            <td><?php echo esc_html($o['status']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom" style="margin-top:12px;">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo $total; ?> Eintr&auml;ge</span>
                    <?php for ($i = 1; $i <= $total_pages; $i++) : ?>
                        <?php if ($i === $paged) : ?>
                            <span class="tablenav-pages-navspan button disabled"><?php echo $i; ?></span>
                        <?php else : ?>
                            <a class="button" href="<?php echo esc_url(add_query_arg('paged', $i)); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Gästeliste-Export Seite (Event auswählen → CSV).
     */
    public static function render_guestlist_page() {
        $is_admin = current_user_can('manage_options');
        $org_id = self::get_organizer_id();
        $query_args = [
            'post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'any',
            'orderby' => 'date', 'order' => 'DESC',
        ];
        if (!$is_admin && $org_id) {
            $query_args['meta_key'] = '_tix_organizer_id';
            $query_args['meta_value'] = $org_id;
            $query_args['meta_type'] = 'NUMERIC';
        }
        $events = get_posts($query_args);
        ?>
        <div class="wrap">
            <h1>G&auml;steliste &amp; Ticket-Export</h1>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;max-width:1100px;margin-top:20px;">

                <!-- 1: Nur Tickets -->
                <div style="background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                    <h3 style="font-size:15px;font-weight:700;margin:0 0 8px;color:#0D0B09;">Tickets</h3>
                    <p style="color:#64748b;font-size:12px;margin-bottom:14px;">Alle Ticket-Codes mit K&auml;ufer, Kategorie und Status.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tix_organizer_tickets_csv">
                        <?php wp_nonce_field('tix_tickets_csv', '_tix_nonce2'); ?>
                        <select name="event_id" required style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;margin-bottom:10px;">
                            <option value="">— Event —</option>
                            <?php foreach ($events as $e) : ?>
                                <option value="<?php echo $e->ID; ?>"><?php echo esc_html($e->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-primary" style="padding:7px 16px;border-radius:8px;background:#FF5500;border-color:#FF5500;font-size:13px;display:inline-flex;align-items:center;gap:4px;">
                            <span class="dashicons dashicons-tickets-alt" style="font-size:14px;width:14px;height:14px;"></span> Tickets CSV
                        </button>
                    </form>
                </div>

                <!-- 2: Nur Gästeliste -->
                <div style="background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                    <h3 style="font-size:15px;font-weight:700;margin:0 0 8px;color:#0D0B09;">G&auml;steliste</h3>
                    <p style="color:#64748b;font-size:12px;margin-bottom:14px;">Manuelle G&auml;ste mit Plus-Anzahl, Notizen und Check-in-Status.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tix_organizer_guestlist_csv">
                        <?php wp_nonce_field('tix_guestlist_csv', '_tix_nonce'); ?>
                        <select name="event_id" required style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;margin-bottom:10px;">
                            <option value="">— Event —</option>
                            <?php foreach ($events as $e) : ?>
                                <option value="<?php echo $e->ID; ?>"><?php echo esc_html($e->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button" style="padding:7px 16px;border-radius:8px;font-size:13px;display:inline-flex;align-items:center;gap:4px;">
                            <span class="dashicons dashicons-groups" style="font-size:14px;width:14px;height:14px;"></span> G&auml;steliste CSV
                        </button>
                    </form>
                </div>

                <!-- 3: Tickets + Gästeliste kombiniert -->
                <div style="background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                    <h3 style="font-size:15px;font-weight:700;margin:0 0 8px;color:#0D0B09;">Gesamt-Export</h3>
                    <p style="color:#64748b;font-size:12px;margin-bottom:14px;">Tickets + G&auml;steliste zusammen in einer Datei.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tix_organizer_combined_csv">
                        <?php wp_nonce_field('tix_combined_csv', '_tix_nonce3'); ?>
                        <select name="event_id" required style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;margin-bottom:10px;">
                            <option value="">— Event —</option>
                            <?php foreach ($events as $e) : ?>
                                <option value="<?php echo $e->ID; ?>"><?php echo esc_html($e->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button" style="padding:7px 16px;border-radius:8px;font-size:13px;background:#0D0B09;color:#fff;border-color:#0D0B09;display:inline-flex;align-items:center;gap:4px;">
                            <span class="dashicons dashicons-download" style="font-size:14px;width:14px;height:14px;"></span> Gesamt CSV
                        </button>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * CSV-Export der Gästeliste eines Events.
     */
    /**
     * Sammelt alle Export-Rows für ein Event.
     * @param int    $event_id
     * @param string $type  'tickets' | 'guestlist' | 'combined'
     * @return array  Rows mit einheitlichem Schema, sortiert nach Kaufdatum.
     */
    private static function collect_export_rows($event_id, $type = 'combined') {
        $rows = [];

        // ── Ticket-Kategorien ──
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        $cat_names_by_index = [];
        $product_ids = [];
        $cat_names_by_pid = [];
        if (is_array($cats)) {
            foreach ($cats as $i => $c) {
                $cat_names_by_index[$i] = $c['name'] ?? 'Kategorie ' . ($i + 1);
                if (!empty($c['product_id'])) {
                    $pid = intval($c['product_id']);
                    $product_ids[] = $pid;
                    $cat_names_by_pid[$pid] = $c['name'] ?? '';
                }
            }
        }

        // ── Tickets (tix_ticket CPT) ──
        if ($type === 'tickets' || $type === 'combined') {
            $tickets = get_posts([
                'post_type' => 'tix_ticket', 'posts_per_page' => -1, 'post_status' => 'publish',
                'meta_key' => '_tix_ticket_event_id', 'meta_value' => $event_id, 'meta_type' => 'NUMERIC',
            ]);
            $order_cache = [];
            foreach ($tickets as $t) {
                $raw_status = get_post_meta($t->ID, '_tix_ticket_status', true) ?: 'valid';
                $checked = (bool) get_post_meta($t->ID, '_tix_ticket_checked_in', true);
                $order_id = get_post_meta($t->ID, '_tix_ticket_order_id', true);

                $phone = ''; $address = ''; $first_name = ''; $last_name = ''; $date = ''; $date_sort = '';
                if ($order_id) {
                    if (!isset($order_cache[$order_id])) {
                        $o = wc_get_order($order_id);
                        if ($o) {
                            $a = trim($o->get_billing_address_1() . ' ' . $o->get_billing_address_2());
                            $c = trim($o->get_billing_postcode() . ' ' . $o->get_billing_city());
                            $dc = $o->get_date_created();
                            $order_cache[$order_id] = [
                                'phone' => $o->get_billing_phone(), 'address' => trim($a . ', ' . $c, ', '),
                                'first_name' => $o->get_billing_first_name(), 'last_name' => $o->get_billing_last_name(),
                                'date' => $dc ? $dc->date_i18n('d.m.Y H:i') : '',
                                'date_sort' => $dc ? $dc->getTimestamp() : 0,
                            ];
                        } else {
                            $order_cache[$order_id] = ['phone' => '', 'address' => '', 'first_name' => '', 'last_name' => '', 'date' => '', 'date_sort' => 0];
                        }
                    }
                    $phone = $order_cache[$order_id]['phone']; $address = $order_cache[$order_id]['address'];
                    $first_name = $order_cache[$order_id]['first_name']; $last_name = $order_cache[$order_id]['last_name'];
                    $date = $order_cache[$order_id]['date']; $date_sort = $order_cache[$order_id]['date_sort'];
                }
                if (!$first_name && !$last_name) {
                    $full = get_post_meta($t->ID, '_tix_ticket_owner_name', true);
                    $parts = explode(' ', $full, 2);
                    $first_name = $parts[0] ?? ''; $last_name = $parts[1] ?? '';
                }

                if ($checked) { $status = 'Eingecheckt'; }
                elseif ($raw_status === 'transferred') { $status = 'Umgeschrieben'; }
                elseif ($raw_status === 'cancelled' || $raw_status === 'revoked') { $status = 'Storniert'; }
                else { $status = 'Gueltig'; }

                $rows[] = [
                    'date_sort'  => $date_sort,
                    'typ'        => 'Ticket',
                    'code'       => get_post_meta($t->ID, '_tix_ticket_code', true),
                    'vorname'    => $first_name,
                    'nachname'   => $last_name,
                    'email'      => get_post_meta($t->ID, '_tix_ticket_owner_email', true),
                    'telefon'    => $phone,
                    'adresse'    => $address,
                    'kategorie'  => $cat_names_by_index[intval(get_post_meta($t->ID, '_tix_ticket_cat_index', true))] ?? '',
                    'anzahl'     => 1,
                    'status'     => $status,
                    'kaufdatum'  => $date,
                    'notizen'    => '',
                    'quelle'     => $order_id ? 'Bestellung #' . $order_id : '',
                ];
            }
        }

        // ── Gästeliste (nur manuelle Gäste) ──
        if ($type === 'guestlist' || $type === 'combined') {
            $guestlist = get_post_meta($event_id, '_tix_guest_list', true);
            if (is_array($guestlist)) {
                foreach ($guestlist as $g) {
                    $full = $g['name'] ?? '';
                    $parts = explode(' ', $full, 2);
                    $created_ts = !empty($g['created']) ? strtotime($g['created']) : 0;
                    $created = $created_ts ? date_i18n('d.m.Y H:i', $created_ts) : '';
                    $plus = intval($g['plus'] ?? 0);
                    $rows[] = [
                        'date_sort'  => $created_ts,
                        'typ'        => 'Gast',
                        'code'       => '',
                        'vorname'    => $parts[0] ?? '',
                        'nachname'   => $parts[1] ?? '',
                        'email'      => $g['email'] ?? '',
                        'telefon'    => '',
                        'adresse'    => '',
                        'kategorie'  => 'Gaesteliste',
                        'anzahl'     => 1 + $plus,
                        'status'     => !empty($g['checked_in']) ? 'Eingecheckt' : 'Offen',
                        'kaufdatum'  => $created,
                        'notizen'    => $g['note'] ?? '',
                        'quelle'     => 'Manuell',
                    ];
                }
            }
        }

        // Chronologisch nach Kaufdatum sortieren
        usort($rows, fn($a, $b) => $a['date_sort'] <=> $b['date_sort']);

        return $rows;
    }

    /**
     * CSV aus Rows schreiben (einheitliches Schema).
     */
    private static function write_export_csv($rows, $filename) {
        $header = ['Typ', 'Ticket-Code', 'Vorname', 'Nachname', 'E-Mail', 'Telefon', 'Adresse', 'Kategorie', 'Anzahl', 'Status', 'Kaufdatum', 'Notizen', 'Quelle'];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ';');
        foreach ($rows as $r) {
            unset($r['date_sort']);
            fputcsv($out, array_values($r), ';');
        }
        fclose($out);
        exit;
    }

    /**
     * Ownership + Event-ID prüfen (gemeinsam für alle Exports).
     */
    private static function validate_export_access($nonce_action, $nonce_field) {
        if (!self::is_organizer() && !current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer($nonce_action, $nonce_field);
        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_die('Kein Event gewählt.');
        if (self::is_organizer()) {
            $org_id = self::get_organizer_id();
            if (intval(get_post_meta($event_id, '_tix_organizer_id', true)) !== $org_id) wp_die('Kein Zugriff auf dieses Event.');
        }
        return $event_id;
    }

    public static function export_guestlist_csv() {
        $event_id = self::validate_export_access('tix_guestlist_csv', '_tix_nonce');
        $rows = self::collect_export_rows($event_id, 'guestlist');
        self::write_export_csv($rows, sanitize_file_name('gaesteliste-' . get_the_title($event_id) . '-' . date('Y-m-d')) . '.csv');
    }

    public static function export_tickets_csv() {
        $event_id = self::validate_export_access('tix_tickets_csv', '_tix_nonce2');
        $rows = self::collect_export_rows($event_id, 'tickets');
        self::write_export_csv($rows, sanitize_file_name('tickets-' . get_the_title($event_id) . '-' . date('Y-m-d')) . '.csv');
    }

    public static function export_combined_csv() {
        $event_id = self::validate_export_access('tix_combined_csv', '_tix_nonce3');
        $rows = self::collect_export_rows($event_id, 'combined');
        self::write_export_csv($rows, sanitize_file_name('gesamt-' . get_the_title($event_id) . '-' . date('Y-m-d')) . '.csv');
    }

    // ══════════════════════════════════════
    // DASHBOARD (KPIs)
    // ══════════════════════════════════════

    public static function render_dashboard_page() {
        $org_id = self::get_organizer_id();
        $event_ids = self::get_organizer_event_ids();

        // Product IDs sammeln
        global $wpdb;
        $product_ids = [];
        $event_products = [];
        foreach ($event_ids as $eid) {
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            if (is_array($cats)) {
                foreach ($cats as $cat) {
                    if (!empty($cat['product_id'])) {
                        $pid = intval($cat['product_id']);
                        $product_ids[] = $pid;
                        $event_products[$eid][] = $pid;
                    }
                }
            }
        }

        // KPIs berechnen
        $total_revenue = 0;
        $total_tickets = 0;
        if ($product_ids) {
            $pids = implode(',', array_unique($product_ids));
            $r = $wpdb->get_row("
                SELECT COALESCE(SUM(oim_total.meta_value),0) as revenue,
                       COALESCE(SUM(oim_qty.meta_value),0) as tickets
                FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id' AND oim_pid.meta_value IN ($pids)
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
                WHERE oi.order_item_type = 'line_item'
            ");
            $total_revenue = floatval($r->revenue ?? 0);
            $total_tickets = intval($r->tickets ?? 0);
        }

        // Aktive Events
        $active_events = get_posts([
            'post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => -1,
            'fields' => 'ids', 'meta_key' => '_tix_organizer_id', 'meta_value' => $org_id, 'meta_type' => 'NUMERIC',
            'meta_query' => [['key' => '_tix_start_date', 'value' => date('Y-m-d'), 'compare' => '>=']],
        ]);

        // Nächstes Event
        $next_event = get_posts([
            'post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1,
            'meta_key' => '_tix_start_date', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [
                ['key' => '_tix_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'],
                ['key' => '_tix_start_date', 'value' => date('Y-m-d'), 'compare' => '>='],
            ],
        ]);
        $next = $next_event ? $next_event[0] : null;

        // Top 5 Events nach Umsatz
        $top_events = [];
        foreach ($event_products as $eid => $pids) {
            if (empty($pids)) continue;
            $pp = implode(',', $pids);
            $r = $wpdb->get_row("
                SELECT COALESCE(SUM(oim_total.meta_value),0) as rev, COALESCE(SUM(oim_qty.meta_value),0) as tix
                FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id' AND oim_pid.meta_value IN ($pp)
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
                WHERE oi.order_item_type = 'line_item'
            ");
            $top_events[] = ['id' => $eid, 'title' => get_the_title($eid), 'revenue' => floatval($r->rev ?? 0), 'tickets' => intval($r->tix ?? 0)];
        }
        usort($top_events, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $top_events = array_slice($top_events, 0, 5);

        ?>
        <div class="wrap">
            <h1>Dashboard</h1>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin:20px 0;">
                <div style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                    <div style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Gesamtumsatz</div>
                    <div style="font-size:28px;font-weight:700;color:#0D0B09;margin-top:4px;"><?php echo number_format($total_revenue, 2, ',', '.'); ?>&nbsp;&euro;</div>
                </div>
                <div style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                    <div style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Tickets verkauft</div>
                    <div style="font-size:28px;font-weight:700;color:#0D0B09;margin-top:4px;"><?php echo number_format($total_tickets, 0, ',', '.'); ?></div>
                </div>
                <div style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                    <div style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Aktive Events</div>
                    <div style="font-size:28px;font-weight:700;color:#0D0B09;margin-top:4px;"><?php echo count($active_events); ?></div>
                </div>
                <div style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                    <div style="font-size:12px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">N&auml;chstes Event</div>
                    <div style="font-size:14px;font-weight:600;color:#0D0B09;margin-top:8px;"><?php echo $next ? esc_html($next->post_title) : '&ndash;'; ?></div>
                    <?php if ($next) : ?>
                        <div style="font-size:12px;color:#64748b;margin-top:2px;"><?php echo get_post_meta($next->ID, '_tix_start_date', true); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($top_events) : ?>
            <div style="background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-top:8px;">
                <h3 style="font-size:15px;font-weight:700;margin:0 0 16px;color:#0D0B09;">Top Events nach Umsatz</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>Event</th><th style="width:100px;text-align:right;">Tickets</th><th style="width:120px;text-align:right;">Umsatz</th></tr></thead>
                    <tbody>
                    <?php foreach ($top_events as $te) : ?>
                        <tr>
                            <td><a href="<?php echo get_edit_post_link($te['id']); ?>"><?php echo esc_html($te['title']); ?></a></td>
                            <td style="text-align:right;"><?php echo $te['tickets']; ?></td>
                            <td style="text-align:right;font-weight:600;"><?php echo number_format($te['revenue'], 2, ',', '.'); ?>&nbsp;&euro;</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ══════════════════════════════════════
    // E-MAIL AN KÄUFER
    // ══════════════════════════════════════

    public static function render_email_page() {
        $org_id = self::get_organizer_id();
        $events = get_posts([
            'post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'publish',
            'meta_key' => '_tix_organizer_id', 'meta_value' => $org_id, 'meta_type' => 'NUMERIC',
            'orderby' => 'date', 'order' => 'DESC',
        ]);
        ?>
        <div class="wrap">
            <h1>E-Mail an K&auml;ufer senden</h1>
            <div style="max-width:700px;margin-top:20px;background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                <div id="tix-email-form">
                    <div style="margin-bottom:16px;">
                        <label style="font-size:13px;font-weight:600;color:#475569;display:block;margin-bottom:6px;">Event</label>
                        <select id="tix-email-event" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;">
                            <option value="">— Event w&auml;hlen —</option>
                            <?php foreach ($events as $e) : ?>
                                <option value="<?php echo $e->ID; ?>"><?php echo esc_html($e->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="font-size:13px;font-weight:600;color:#475569;display:block;margin-bottom:6px;">Betreff</label>
                        <input type="text" id="tix-email-subject" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;" placeholder="z.B. Wichtige Info zu deinem Event">
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="font-size:13px;font-weight:600;color:#475569;display:block;margin-bottom:6px;">Nachricht</label>
                        <textarea id="tix-email-message" rows="8" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;font-family:inherit;resize:vertical;" placeholder="Deine Nachricht an alle K&auml;ufer..."></textarea>
                    </div>
                    <div style="display:flex;gap:12px;align-items:center;">
                        <button type="button" id="tix-email-send" style="padding:10px 24px;background:#FF5500;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;">Senden</button>
                        <span id="tix-email-status" style="font-size:13px;color:#64748b;"></span>
                    </div>
                </div>
            </div>
        </div>
        <script>
        jQuery(function($) {
            $('#tix-email-send').on('click', function() {
                var event_id = $('#tix-email-event').val();
                var subject = $('#tix-email-subject').val();
                var message = $('#tix-email-message').val();
                if (!event_id || !subject || !message) { alert('Bitte alle Felder ausfüllen.'); return; }
                if (!confirm('E-Mail an alle Käufer dieses Events senden?')) return;

                var $btn = $(this).prop('disabled', true).text('Sende...');
                $('#tix-email-status').text('');

                $.post(ajaxurl, {
                    action: 'tix_organizer_send_email',
                    _wpnonce: '<?php echo wp_create_nonce('tix_organizer_email'); ?>',
                    event_id: event_id,
                    subject: subject,
                    message: message
                }, function(res) {
                    $btn.prop('disabled', false).text('Senden');
                    if (res.success) {
                        $('#tix-email-status').css('color','#10b981').text(res.data.message);
                    } else {
                        $('#tix-email-status').css('color','#ef4444').text(res.data.message || 'Fehler beim Senden.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_send_email() {
        check_ajax_referer('tix_organizer_email');
        if (!self::is_organizer()) wp_send_json_error(['message' => 'Kein Zugriff.']);

        $event_id = intval($_POST['event_id'] ?? 0);
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $message = wp_kses_post($_POST['message'] ?? '');

        if (!$event_id || !$subject || !$message) wp_send_json_error(['message' => 'Felder fehlen.']);

        // Ownership
        $org_id = self::get_organizer_id();
        $event_org = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        if ($event_org !== $org_id) wp_send_json_error(['message' => 'Kein Zugriff.']);

        // Rate-Limit: max 1x pro Event pro Tag
        $today_key = '_tix_org_email_' . date('Y-m-d');
        if (get_post_meta($event_id, $today_key, true)) {
            wp_send_json_error(['message' => 'Heute wurde bereits eine E-Mail f&uuml;r dieses Event versendet.']);
        }

        // Käufer-E-Mails sammeln
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        $product_ids = [];
        if (is_array($cats)) foreach ($cats as $c) if (!empty($c['product_id'])) $product_ids[] = intval($c['product_id']);

        if (!$product_ids) wp_send_json_error(['message' => 'Keine Tickets/Produkte f&uuml;r dieses Event.']);

        global $wpdb;
        $pids = implode(',', $product_ids);
        $order_ids = $wpdb->get_col("
            SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            WHERE oi.order_item_type = 'line_item' AND oim.meta_key = '_product_id' AND oim.meta_value IN ($pids)
        ");

        $emails = [];
        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order || $order->get_status() === 'cancelled') continue;
            $email = $order->get_billing_email();
            if ($email && !in_array($email, $emails)) $emails[] = $email;
        }

        if (empty($emails)) wp_send_json_error(['message' => 'Keine K&auml;ufer gefunden.']);

        // E-Mails senden
        $event_title = get_the_title($event_id);
        $html = '<div style="font-family:Inter,system-ui,sans-serif;max-width:600px;margin:0 auto;padding:24px;">'
              . '<h2 style="color:#0D0B09;font-size:18px;">' . esc_html($subject) . '</h2>'
              . '<p style="color:#475569;font-size:14px;line-height:1.6;">' . nl2br($message) . '</p>'
              . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">'
              . '<p style="color:#94a3b8;font-size:12px;">Diese Nachricht bezieht sich auf: ' . esc_html($event_title) . '</p>'
              . '</div>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = 0;
        foreach ($emails as $to) {
            if (wp_mail($to, $subject, $html, $headers)) $sent++;
        }

        update_post_meta($event_id, $today_key, time());

        wp_send_json_success(['message' => $sent . ' E-Mail(s) an K&auml;ufer versendet.']);
    }

    // ══════════════════════════════════════
    // ABRECHNUNGS-EXPORT (CSV)
    // ══════════════════════════════════════

    public static function render_billing_page() {
        $org_id = self::get_organizer_id();
        $events = get_posts([
            'post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'any',
            'meta_key' => '_tix_organizer_id', 'meta_value' => $org_id, 'meta_type' => 'NUMERIC',
            'orderby' => 'date', 'order' => 'DESC',
        ]);
        ?>
        <div class="wrap">
            <h1>Abrechnung</h1>
            <div style="max-width:600px;margin-top:20px;background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                <p style="color:#64748b;font-size:13px;margin-bottom:16px;">Exportiere deine Ums&auml;tze als CSV &ndash; nach Monat oder Event.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tix_organizer_billing_csv">
                    <?php wp_nonce_field('tix_billing_csv', '_tix_nonce'); ?>
                    <div style="margin-bottom:16px;">
                        <label style="font-size:13px;font-weight:600;color:#475569;display:block;margin-bottom:6px;">Monat</label>
                        <input type="month" name="month" value="<?php echo date('Y-m'); ?>" style="padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;width:100%;">
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="font-size:13px;font-weight:600;color:#475569;display:block;margin-bottom:6px;">Oder: einzelnes Event</label>
                        <select name="event_id" style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;">
                            <option value="">Alle Events im gew&auml;hlten Monat</option>
                            <?php foreach ($events as $e) : ?>
                                <option value="<?php echo $e->ID; ?>"><?php echo esc_html($e->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="button button-primary" style="padding:10px 24px;border-radius:10px;font-size:14px;background:#FF5500;border-color:#FF5500;">
                        <span class="dashicons dashicons-download" style="margin-right:4px;"></span> CSV herunterladen
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    public static function export_billing_csv() {
        if (!self::is_organizer()) wp_die('Kein Zugriff.');
        check_admin_referer('tix_billing_csv', '_tix_nonce');

        $org_id = self::get_organizer_id();
        $month = sanitize_text_field($_POST['month'] ?? date('Y-m'));
        $filter_event = intval($_POST['event_id'] ?? 0);

        $event_ids = self::get_organizer_event_ids();
        if ($filter_event && in_array($filter_event, $event_ids)) {
            $event_ids = [$filter_event];
        }

        // Product IDs + Event-Name Mapping
        $product_ids = [];
        $pid_to_event = [];
        $pid_to_cat = [];
        foreach ($event_ids as $eid) {
            $cats = get_post_meta($eid, '_tix_ticket_categories', true);
            if (is_array($cats)) {
                foreach ($cats as $cat) {
                    if (!empty($cat['product_id'])) {
                        $pid = intval($cat['product_id']);
                        $product_ids[] = $pid;
                        $pid_to_event[$pid] = get_the_title($eid);
                        $pid_to_cat[$pid] = $cat['name'] ?? '';
                    }
                }
            }
        }

        $rows = [];
        if ($product_ids) {
            global $wpdb;
            $pids = implode(',', array_unique($product_ids));
            $date_from = $month . '-01';
            $date_to = date('Y-m-t', strtotime($date_from));

            $order_ids = $wpdb->get_col("
                SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                WHERE oi.order_item_type = 'line_item' AND oim.meta_key = '_product_id' AND oim.meta_value IN ($pids)
                ORDER BY oi.order_id DESC
            ");

            foreach ($order_ids as $oid) {
                $order = wc_get_order($oid);
                if (!$order || $order->get_status() === 'cancelled') continue;
                $date = $order->get_date_created();
                if (!$date) continue;
                $d = $date->format('Y-m-d');
                if ($d < $date_from || $d > $date_to) continue;

                foreach ($order->get_items() as $item) {
                    $pid = $item->get_product_id();
                    if (!in_array($pid, $product_ids)) continue;
                    $total = floatval($item->get_total());
                    $tax = floatval($item->get_total_tax());
                    $rows[] = [
                        $order->get_id(),
                        $date->date_i18n('d.m.Y'),
                        $pid_to_event[$pid] ?? '',
                        ($pid_to_cat[$pid] ?? $item->get_name()) . ' x' . $item->get_quantity(),
                        number_format($total + $tax, 2, ',', ''),
                        number_format($tax, 2, ',', ''),
                        number_format($total, 2, ',', ''),
                    ];
                }
            }
        }

        $filename = 'abrechnung-' . $month . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Bestellnr', 'Datum', 'Event', 'Tickets', 'Brutto', 'MwSt', 'Netto'], ';');
        foreach ($rows as $r) fputcsv($out, $r, ';');
        fclose($out);
        exit;
    }

    // ══════════════════════════════════════
    // BENACHRICHTIGUNGEN
    // ══════════════════════════════════════

    public static function notify_new_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Bereits benachrichtigt?
        if (get_post_meta($order_id, '_tix_org_notified', true)) return;

        // Events in dieser Bestellung finden
        $notified_orgs = [];
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $event_id = get_post_meta($product_id, '_tix_parent_event_id', true);
            if (!$event_id) continue;

            $org_id = intval(get_post_meta($event_id, '_tix_organizer_id', true));
            if (!$org_id || in_array($org_id, $notified_orgs)) continue;

            // Organizer-User finden
            $org_post = get_post($org_id);
            if (!$org_post) continue;
            $user_id = get_post_meta($org_id, '_tix_org_user_id', true);
            if (!$user_id) continue;
            $user = get_userdata($user_id);
            if (!$user || !$user->user_email) continue;

            // Benachrichtigung senden
            $event_title = get_the_title($event_id);
            $buyer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $total = $order->get_total();
            $tickets = $item->get_quantity();

            $html = '<div style="font-family:Inter,system-ui,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
                  . '<h2 style="color:#0D0B09;font-size:16px;margin:0 0 12px;">Neue Bestellung</h2>'
                  . '<p style="color:#475569;font-size:14px;line-height:1.6;margin:0 0 8px;">'
                  . '<strong>' . esc_html($buyer) . '</strong> hat <strong>' . $tickets . ' Ticket(s)</strong> f&uuml;r <strong>' . esc_html($event_title) . '</strong> gekauft.'
                  . '</p>'
                  . '<p style="color:#0D0B09;font-size:18px;font-weight:700;margin:8px 0;">' . wc_price($total) . '</p>'
                  . '<p style="color:#94a3b8;font-size:12px;margin-top:16px;">Bestellung #' . $order->get_id() . '</p>'
                  . '</div>';

            wp_mail($user->user_email, 'Neue Bestellung: ' . $event_title, $html, ['Content-Type: text/html; charset=UTF-8']);

            $notified_orgs[] = $org_id;

            // Low-Stock Check
            self::check_low_stock($event_id, $org_id, $user->user_email);
        }

        update_post_meta($order_id, '_tix_org_notified', 1);
    }

    private static function check_low_stock($event_id, $org_id, $email) {
        if (get_post_meta($event_id, '_tix_low_stock_notified', true)) return;

        $sold = intval(get_post_meta($event_id, '_tix_sold_total', true));
        $capacity = intval(get_post_meta($event_id, '_tix_capacity_total', true));
        if ($capacity <= 0) return;

        $remaining = $capacity - $sold;
        $threshold = max(1, ceil($capacity * 0.1));

        if ($remaining > $threshold) return;

        $event_title = get_the_title($event_id);
        $pct = round(($sold / $capacity) * 100);

        $html = '<div style="font-family:Inter,system-ui,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
              . '<h2 style="color:#f59e0b;font-size:16px;margin:0 0 12px;">&#9888; Low-Stock Warnung</h2>'
              . '<p style="color:#475569;font-size:14px;line-height:1.6;">'
              . '<strong>' . esc_html($event_title) . '</strong> ist zu <strong>' . $pct . '%</strong> ausverkauft.'
              . '</p>'
              . '<p style="color:#0D0B09;font-size:16px;font-weight:600;">Nur noch ' . $remaining . ' von ' . $capacity . ' Tickets verf&uuml;gbar.</p>'
              . '</div>';

        wp_mail($email, 'Low-Stock: ' . $event_title . ' (' . $remaining . ' Tickets)', $html, ['Content-Type: text/html; charset=UTF-8']);
        update_post_meta($event_id, '_tix_low_stock_notified', 1);
    }

    // ══════════════════════════════════════
    // EIGENE MEDIATHEK
    // ══════════════════════════════════════

    /**
     * wp.media Modal: nur eigene Bilder zeigen für Organizer.
     */
    public static function filter_media_library($query) {
        if (!self::is_organizer()) return $query;
        $query['author'] = get_current_user_id();
        return $query;
    }

    /**
     * Eigene Medien-Seite.
     */
    public static function render_media_page() {
        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1>Meine Medien</h1>

            <!-- Upload Zone -->
            <div id="tix-media-upload" style="margin:20px 0;background:#fff;border:2px dashed #d1d5db;border-radius:16px;padding:40px;text-align:center;cursor:pointer;transition:all .2s;">
                <div style="color:#94a3b8;margin-bottom:12px;">
                    <span class="dashicons dashicons-cloud-upload" style="font-size:48px;width:48px;height:48px;"></span>
                </div>
                <p style="color:#64748b;font-size:14px;margin:0 0 12px;">Bilder hierher ziehen oder klicken zum Hochladen</p>
                <input type="file" id="tix-media-file" accept="image/*" multiple style="display:none;">
                <button type="button" id="tix-media-btn" class="button" style="padding:8px 20px;border-radius:8px;">Dateien ausw&auml;hlen</button>
                <div id="tix-media-progress" style="display:none;margin-top:12px;">
                    <div style="background:#e2e8f0;border-radius:4px;height:6px;overflow:hidden;">
                        <div id="tix-media-bar" style="background:#FF5500;height:100%;width:0;transition:width .3s;"></div>
                    </div>
                </div>
            </div>

            <!-- Grid -->
            <div id="tix-media-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;">
                <div style="text-align:center;padding:40px;color:#94a3b8;grid-column:1/-1;">Lade...</div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var nonce = '<?php echo wp_create_nonce('tix_organizer_media'); ?>';

            function loadMedia() {
                $.post(ajaxurl, { action: 'tix_organizer_load_media', _wpnonce: nonce }, function(res) {
                    if (!res.success) return;
                    var grid = $('#tix-media-grid').empty();
                    if (!res.data.length) {
                        grid.html('<div style="text-align:center;padding:40px;color:#94a3b8;grid-column:1/-1;">Noch keine Bilder hochgeladen.</div>');
                        return;
                    }
                    res.data.forEach(function(img) {
                        grid.append(
                            '<div style="position:relative;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);aspect-ratio:1;" data-id="' + img.id + '">' +
                            '<img src="' + img.thumb + '" style="width:100%;height:100%;object-fit:cover;">' +
                            '<button type="button" class="tix-media-delete" data-id="' + img.id + '" style="position:absolute;top:6px;right:6px;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:50%;width:24px;height:24px;cursor:pointer;font-size:14px;line-height:24px;display:none;">&times;</button>' +
                            '<div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.5);color:#fff;font-size:10px;padding:4px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + img.name + '</div>' +
                            '</div>'
                        );
                    });
                });
            }

            // Hover: Delete-Button zeigen
            $(document).on('mouseenter', '#tix-media-grid > div', function() { $(this).find('.tix-media-delete').show(); });
            $(document).on('mouseleave', '#tix-media-grid > div', function() { $(this).find('.tix-media-delete').hide(); });

            // Delete
            $(document).on('click', '.tix-media-delete', function(e) {
                e.stopPropagation();
                if (!confirm('Bild löschen?')) return;
                var id = $(this).data('id');
                $.post(ajaxurl, { action: 'tix_organizer_delete_media', _wpnonce: nonce, attachment_id: id }, function() { loadMedia(); });
            });

            // Upload
            var dropZone = document.getElementById('tix-media-upload');
            var fileInput = document.getElementById('tix-media-file');

            $('#tix-media-btn').on('click', function() { fileInput.click(); });

            dropZone.addEventListener('dragover', function(e) { e.preventDefault(); this.style.borderColor = '#FF5500'; this.style.background = '#FFF8F4'; });
            dropZone.addEventListener('dragleave', function() { this.style.borderColor = '#d1d5db'; this.style.background = '#fff'; });
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderColor = '#d1d5db'; this.style.background = '#fff';
                uploadFiles(e.dataTransfer.files);
            });

            fileInput.addEventListener('change', function() { uploadFiles(this.files); this.value = ''; });

            function uploadFiles(files) {
                if (!files.length) return;
                var total = files.length, done = 0;
                $('#tix-media-progress').show();
                Array.from(files).forEach(function(file) {
                    var fd = new FormData();
                    fd.append('action', 'tix_organizer_upload_media');
                    fd.append('_wpnonce', nonce);
                    fd.append('file', file);
                    $.ajax({ url: ajaxurl, type: 'POST', data: fd, processData: false, contentType: false,
                        success: function() { done++; $('#tix-media-bar').css('width', (done/total*100)+'%'); if (done === total) { setTimeout(function() { $('#tix-media-progress').hide(); $('#tix-media-bar').css('width','0'); loadMedia(); }, 500); } }
                    });
                });
            }

            loadMedia();
        });
        </script>
        <?php
    }

    /**
     * AJAX: Medien des Organizers laden.
     */
    public static function ajax_load_media() {
        check_ajax_referer('tix_organizer_media');
        if (!self::is_organizer()) wp_send_json_error();

        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'author'         => get_current_user_id(),
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $items = [];
        foreach ($attachments as $att) {
            $thumb = wp_get_attachment_image_url($att->ID, 'thumbnail');
            if (!$thumb) continue;
            $items[] = [
                'id'    => $att->ID,
                'name'  => $att->post_title,
                'thumb' => $thumb,
                'url'   => wp_get_attachment_url($att->ID),
            ];
        }

        wp_send_json_success($items);
    }

    /**
     * AJAX: Bild hochladen.
     */
    public static function ajax_upload_media() {
        check_ajax_referer('tix_organizer_media');
        if (!self::is_organizer()) wp_send_json_error(['message' => 'Kein Zugriff.']);

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('file', 0);
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }

        wp_send_json_success([
            'id'    => $attachment_id,
            'url'   => wp_get_attachment_url($attachment_id),
            'thumb' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
        ]);
    }

    /**
     * AJAX: Bild löschen (nur eigene).
     */
    public static function ajax_delete_media() {
        check_ajax_referer('tix_organizer_media');
        if (!self::is_organizer()) wp_send_json_error();

        $id = intval($_POST['attachment_id'] ?? 0);
        if (!$id) wp_send_json_error();

        $att = get_post($id);
        if (!$att || $att->post_type !== 'attachment' || intval($att->post_author) !== get_current_user_id()) {
            wp_send_json_error(['message' => 'Kein Zugriff.']);
        }

        wp_delete_attachment($id, true);
        wp_send_json_success();
    }

    /**
     * Veranstalter-Name speichern + tix_organizer CPT synchronisieren.
     */
    public static function save_profile_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) return;
        if (!isset($_POST['tix_organizer_name'])) return;

        $user = get_userdata($user_id);
        if (!in_array('tix_organizer', (array) $user->roles, true)) return;

        $name = sanitize_text_field($_POST['tix_organizer_name']);
        update_user_meta($user_id, '_tix_organizer_name', $name);

        // tix_organizer CPT synchronisieren
        if (class_exists('TIX_Organizer_Dashboard')) {
            $org = TIX_Organizer_Dashboard::get_organizer_by_user($user_id);
            if ($org && $name) {
                wp_update_post(['ID' => $org->ID, 'post_title' => $name]);
            } elseif (!$org && $name) {
                // Neuen tix_organizer anlegen wenn noch keiner existiert
                $org_id = wp_insert_post([
                    'post_type'   => 'tix_organizer',
                    'post_title'  => $name,
                    'post_status' => 'publish',
                ]);
                if ($org_id && !is_wp_error($org_id)) {
                    update_post_meta($org_id, '_tix_org_user_id', $user_id);
                }
            }
        }
    }
}
