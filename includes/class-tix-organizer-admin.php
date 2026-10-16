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

    /**
     * Nur Gästeliste-Seite + CSV-Exports registrieren (ohne Organizer-Dashboard).
     */
    public static function init_guestlist_only() {
        add_action('admin_menu', function() {
            add_submenu_page('tixomat', 'G&auml;steliste Export', 'G&auml;steliste Export', 'edit_posts', 'tix-organizer-guestlist', [__CLASS__, 'render_guestlist_page']);
        }, 20);
        add_action('admin_post_tix_organizer_guestlist_csv', [__CLASS__, 'export_guestlist_csv']);
        add_action('admin_post_tix_organizer_tickets_csv', [__CLASS__, 'export_tickets_csv']);
        add_action('admin_post_tix_organizer_combined_csv', [__CLASS__, 'export_combined_csv']);
    }

    public static function init() {
        // Capabilities früh sicherstellen (vor dem Rechte-Check der ersten Admin-Seite)
        add_action('init', [__CLASS__, 'ensure_capabilities'], 1);

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
        add_action('admin_post_tix_organizer_orders_csv', [__CLASS__, 'export_orders_csv']);

        // Bulk E-Mail AJAX
        add_action('wp_ajax_tix_organizer_send_email', [__CLASS__, 'ajax_send_email']);

        // Benachrichtigungen: Neue Bestellung + Low-Stock (native Kasse)
        add_action('tix_order_status_changed', [__CLASS__, 'on_order_status'], 20, 2);

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

        // Rechte des angemeldeten Nutzers für diese Anfrage auffrischen (sonst 403 beim ersten Admin-Aufruf)
        if ($changed && did_action('set_current_user')) {
            $u = wp_get_current_user();
            if ($u && $u->ID) $u->get_role_caps();
        }
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
            $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
            $write = in_array($cap, ['edit_post', 'delete_post'], true);
            // Mehr-Veranstalter: nur das eigene Veranstalter-Profil bearbeiten
            if ($post->post_type === 'tix_organizer') {
                if ($multi && $write && intval($post->ID) !== intval(self::get_organizer_id($user_id))) return ['do_not_allow'];
                return $caps;
            }
            // Locations: im Mehr-Veranstalter-Modus nur selbst angelegte bearbeiten (lesen/auswählen geht)
            if ($post->post_type === 'tix_location') {
                if ($multi && $write && intval($post->post_author) !== intval($user_id)) return ['do_not_allow'];
                return $caps;
            }
            // Attachments (Medien): im Mehr-Veranstalter-Modus nur eigene bearbeiten/löschen
            if ($post->post_type === 'attachment') {
                if ($multi && $write && intval($post->post_author) !== intval($user_id)) return ['do_not_allow'];
                return $caps;
            }
            // Alles andere: do_not_allow
            return ['do_not_allow'];
        }

        // Event: Ownership prüfen (inkl. Co-Veranstalter)
        $org_id = self::get_organizer_id($user_id);
        $event_org = intval(get_post_meta($post->ID, '_tix_organizer_id', true));
        $event_co_org = intval(get_post_meta($post->ID, '_tix_co_organizer_id', true));

        if ($org_id && ($event_org === $org_id || $event_co_org === $org_id)) {
            // Check-in Rolle: nur lesen, nicht bearbeiten/löschen
            if (class_exists('TIX_Team') && in_array($cap, ['edit_post', 'delete_post'], true)) {
                if (!TIX_Team::user_can($user_id, 'edit_events')) {
                    return ['do_not_allow'];
                }
            }
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
                    'relation' => 'OR',
                    ['key' => '_tix_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'],
                    ['key' => '_tix_co_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'],
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
                'key'     => '_tix_ticket_event_id',
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
            'tix-organizer-payouts',
            'tix-organizer-media',
            'tix-templates',
            'tix-orders',
            'tix-customers',
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

        return admin_url('admin.php?page=tixomat');
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
            wp_redirect(admin_url('admin.php?page=tixomat'));
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

        // Auszahlungen (Mehr-Veranstalter-Modus): Formulare/PDF über admin-post.php, Prüfung im Handler
        // CSV-Exporte (Gästeliste, Tickets, Gesamt, Abrechnung): Handler prüfen Nonce + eigenes Event selbst
        if ($pagenow === 'admin-post.php' && in_array($_REQUEST['action'] ?? '', [
            'tix_org_payout', 'tix_org_settlement_pdf', 'tix_org_account',
            'tix_organizer_guestlist_csv', 'tix_organizer_tickets_csv', 'tix_organizer_combined_csv', 'tix_organizer_billing_csv', 'tix_organizer_orders_csv',
        ], true)) return;
        $allowed_pages = [
            'index.php', 'edit.php', 'post.php', 'post-new.php',
            'edit-tags.php', 'admin.php', 'admin-ajax.php', 'profile.php',
            'async-upload.php', 'media-upload.php',
        ];

        if (!in_array($pagenow, $allowed_pages, true)) {
            wp_redirect(admin_url('admin.php?page=tixomat'));
            exit;
        }

        // admin.php: nur erlaubte page-Parameter
        if ($pagenow === 'admin.php') {
            $page = $_GET['page'] ?? '';
            $allowed_admin_pages = [
                'tixomat',                 // Dashboard (TIX_Dashboard, auf eigene Events gefiltert)
                'tix-organizer-dashboard', // alter Slug → leitet auf tixomat
                'tix-organizer-guestlist',
                'tix-org-account', // Freigabe-Status + Pflichtangaben (Mehr-Veranstalter-Modus)
            ];

            // Rollen-basiert erweitern
            $uid = get_current_user_id();
            if (!class_exists('TIX_Team') || TIX_Team::user_can($uid, 'view_statistics')) {
                $allowed_admin_pages[] = 'tix-statistics';
                $allowed_admin_pages[] = 'tix-docs';
            }
            if (!class_exists('TIX_Team') || TIX_Team::user_can($uid, 'view_orders')) {
                $allowed_admin_pages[] = 'tix-organizer-orders';
                $allowed_admin_pages[] = 'tix-customers';
            }
            if (!class_exists('TIX_Team') || TIX_Team::user_can($uid, 'manage_billing')) {
                $allowed_admin_pages[] = 'tix-organizer-email';
                $allowed_admin_pages[] = 'tix-organizer-billing';
                $allowed_admin_pages[] = 'tix-organizer-payouts';
            }
            if (!class_exists('TIX_Team') || TIX_Team::user_can($uid, 'manage_settings')) {
                $allowed_admin_pages[] = 'tix-settings';
                $allowed_admin_pages[] = 'tix-organizer-media';
                $allowed_admin_pages[] = 'tix-organizer-landing';
            }

            if ($page && !in_array($page, $allowed_admin_pages, true)) {
                wp_redirect(admin_url('admin.php?page=tixomat'));
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
                wp_redirect(admin_url('admin.php?page=tixomat'));
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
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_tix_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'],
                ['key' => '_tix_co_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'],
            ],
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

    // ══════════════════════════════════════
    // NATIVE BESTELLUNGEN (tix_orders)
    // ══════════════════════════════════════

    const STATUS_LABELS = [
        'completed'  => 'Abgeschlossen',
        'processing' => 'In Bearbeitung',
        'on-hold'    => 'Wartend',
        'pending'    => 'Ausstehend',
        'refunded'   => 'Erstattet',
        'cancelled'  => 'Storniert',
        'failed'     => 'Fehlgeschlagen',
    ];

    /**
     * Native Bestellungen mit Positionen der Events, neueste zuerst.
     * $args: status[], search, date_from (Y-m-d), date_to (Y-m-d), limit (0 = alle), offset.
     * @return array [int $total, array $rows] – je Zeile ['order' => object, 'items' => object[] (nur diese Events)]
     */
    public static function event_orders(array $event_ids, array $args = []) {
        global $wpdb;
        if (!class_exists('TIX_Order')) return [0, []];
        $ids = array_values(array_unique(array_filter(array_map('intval', $event_ids))));
        if (!$ids) return [0, []];
        $t  = TIX_Order::table_name();
        $ti = TIX_Order::items_table_name();
        $statuses = (array) ($args['status'] ?? ['completed', 'processing', 'on-hold', 'refunded', 'cancelled']);
        $where  = 'o.status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')'
                . ' AND EXISTS (SELECT 1 FROM ' . $ti . ' i WHERE i.order_id = o.id AND i.event_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . '))';
        $params = array_merge($statuses, $ids);
        $search = trim((string) ($args['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (o.order_number LIKE %s OR o.billing_email LIKE %s OR CONCAT(o.billing_first_name, \' \', o.billing_last_name) LIKE %s' . (ctype_digit($search) ? ' OR o.id = %d' : '') . ')';
            array_push($params, $like, $like, $like);
            if (ctype_digit($search)) $params[] = intval($search);
        }
        if (!empty($args['date_from'])) { $where .= ' AND o.date_created >= %s'; $params[] = $args['date_from'] . ' 00:00:00'; }
        if (!empty($args['date_to']))   { $where .= ' AND o.date_created <= %s'; $params[] = $args['date_to'] . ' 23:59:59'; }

        $total = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t o WHERE $where", ...$params)));
        $sql = "SELECT o.* FROM $t o WHERE $where ORDER BY o.date_created DESC, o.id DESC";
        $limit = intval($args['limit'] ?? 0);
        if ($limit > 0) { $sql .= ' LIMIT %d OFFSET %d'; array_push($params, $limit, max(0, intval($args['offset'] ?? 0))); }
        $orders = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        if (!$orders) return [$total, []];

        $oids = array_map(function ($o) { return intval($o->id); }, $orders);
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $ti WHERE order_id IN (" . implode(',', array_fill(0, count($oids), '%d')) . ") AND event_id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ") ORDER BY id ASC",
            ...array_merge($oids, $ids)
        ));
        $by_order = [];
        foreach ((array) $items as $it) $by_order[intval($it->order_id)][] = $it;
        $rows = [];
        foreach ($orders as $o) $rows[] = ['order' => $o, 'items' => $by_order[intval($o->id)] ?? []];
        return [$total, $rows];
    }

    private static function money($v) {
        return number_format(floatval($v), 2, ',', '.') . '&nbsp;&euro;';
    }

    /** Team-Recht des aktuellen Nutzers (ohne TIX_Team: erlaubt). */
    private static function team_can($perm) {
        return !class_exists('TIX_Team') || TIX_Team::user_can(get_current_user_id(), $perm);
    }

    /** Ist das Event eines der eigenen (Haupt- oder Co-Veranstalter)? */
    private static function owns_event($event_id, $user_id = null) {
        $org_id = self::get_organizer_id($user_id);
        if (!$org_id || !$event_id) return false;
        return in_array($org_id, [
            intval(get_post_meta($event_id, '_tix_organizer_id', true)),
            intval(get_post_meta($event_id, '_tix_co_organizer_id', true)),
        ], true);
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

        // Events für Filter-Dropdown (inkl. Co-Veranstalter)
        $events = get_posts([
            'post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'any',
            'meta_query' => ['relation' => 'OR', ['key' => '_tix_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'], ['key' => '_tix_co_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC']],
            'orderby' => 'date', 'order' => 'DESC',
        ]);

        // Bestellungen (native Kasse) mit Positionen der eigenen Events
        $scope = ($filter_event && in_array($filter_event, $event_ids)) ? [$filter_event] : $event_ids;
        list($total, $result) = self::event_orders($scope, [
            'search' => $filter_search,
            'limit'  => $per_page,
            'offset' => ($paged - 1) * $per_page,
        ]);
        $orders = [];
        foreach ($result as $r) {
            $o = $r['order'];
            $tickets = [];
            $own_total = 0;
            foreach ($r['items'] as $it) {
                $tickets[] = esc_html($it->name) . ' &times;' . intval($it->quantity);
                $own_total += floatval($it->total);
            }
            $orders[] = [
                'id'      => $o->order_number ?: $o->id,
                'date'    => $o->date_created ? date_i18n('d.m.Y H:i', strtotime($o->date_created)) : '-',
                'name'    => trim($o->billing_first_name . ' ' . $o->billing_last_name),
                'email'   => $o->billing_email,
                'tickets' => $tickets,
                'total'   => $own_total,
                'status'  => self::STATUS_LABELS[$o->status] ?? ucfirst($o->status),
            ];
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
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-left:auto;">
                    <input type="hidden" name="action" value="tix_organizer_orders_csv">
                    <input type="hidden" name="event_id" value="<?php echo intval($filter_event); ?>">
                    <?php wp_nonce_field('tix_orders_csv', '_tix_nonce'); ?>
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Export von – bis</label>
                        <input type="date" name="from" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                        <input type="date" name="to" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Status</label>
                        <select name="status" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                            <option value="paid">Nur bezahlte</option>
                            <option value="all">Alle (auch storniert/erstattet)</option>
                        </select>
                    </div>
                    <button type="submit" class="button" style="padding:7px 16px;border-radius:8px;">Als CSV exportieren</button>
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
                            <td><strong>#<?php echo esc_html($o['id']); ?></strong></td>
                            <td><?php echo $o['date']; ?></td>
                            <td><?php echo esc_html($o['name']); ?></td>
                            <td><a href="mailto:<?php echo esc_attr($o['email']); ?>"><?php echo esc_html($o['email']); ?></a></td>
                            <td><?php echo implode('<br>', $o['tickets']); ?></td>
                            <td style="text-align:right;font-weight:600;"><?php echo self::money($o['total']); ?></td>
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
            $query_args['meta_query'] = ['relation' => 'OR', ['key' => '_tix_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'], ['key' => '_tix_co_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC']];
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
                        <button type="submit" class="button button-primary" style="padding:7px 16px;border-radius:8px;background:<?php echo tix_primary(); ?>;border-color:<?php echo tix_primary(); ?>;font-size:13px;display:inline-flex;align-items:center;gap:4px;">
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
    public static function collect_export_rows($event_id, $type = 'combined') {
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
                        $o = class_exists('TIX_Order') ? TIX_Order::get(intval($order_id)) : null;
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
        if (self::is_organizer() && !current_user_can('manage_options') && !self::team_can('view_guestlist')) wp_die('Kein Zugriff.');
        check_admin_referer($nonce_action, $nonce_field);
        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_die('Kein Event gewählt.');
        if (self::is_organizer()) {
            $org_id = self::get_organizer_id();
            $event_org = intval(get_post_meta($event_id, '_tix_organizer_id', true));
            $event_co_org = intval(get_post_meta($event_id, '_tix_co_organizer_id', true));
            if ($event_org !== $org_id && $event_co_org !== $org_id) wp_die('Kein Zugriff auf dieses Event.');
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
        // Normalerweise leitet TIX_Dashboard den alten Slug auf page=tixomat um
        if (class_exists('TIX_Dashboard')) TIX_Dashboard::render_page();
    }

    // ══════════════════════════════════════
    // E-MAIL AN KÄUFER
    // ══════════════════════════════════════

    public static function render_email_page() {
        $org_id = self::get_organizer_id();
        $events = get_posts([
            'post_type' => 'event', 'posts_per_page' => -1, 'post_status' => 'publish',
            'meta_query' => ['relation' => 'OR', ['key' => '_tix_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'], ['key' => '_tix_co_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC']],
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
                        <button type="button" id="tix-email-send" style="padding:10px 24px;background:<?php echo tix_primary(); ?>;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;">Senden</button>
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
        if (!self::is_organizer() || !self::team_can('manage_billing')) wp_send_json_error(['message' => 'Kein Zugriff.']);

        $event_id = intval($_POST['event_id'] ?? 0);
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $message = wp_kses_post($_POST['message'] ?? '');

        if (!$event_id || !$subject || !$message) wp_send_json_error(['message' => 'Felder fehlen.']);

        // Ownership (inkl. Co-Veranstalter)
        $org_id = self::get_organizer_id();
        $event_org = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        $event_co_org = intval(get_post_meta($event_id, '_tix_co_organizer_id', true));
        if ($event_org !== $org_id && $event_co_org !== $org_id) wp_send_json_error(['message' => 'Kein Zugriff.']);

        // Rate-Limit: max 1x pro Event pro Tag
        $today_key = '_tix_org_email_' . date('Y-m-d');
        if (get_post_meta($event_id, $today_key, true)) {
            wp_send_json_error(['message' => 'Heute wurde bereits eine E-Mail f&uuml;r dieses Event versendet.']);
        }

        // Käufer-E-Mails sammeln (bezahlte native Bestellungen mit Positionen dieses Events)
        list(, $result) = self::event_orders([$event_id], ['status' => ['completed', 'processing']]);
        $emails = [];
        foreach ($result as $r) {
            $email = sanitize_email((string) $r['order']->billing_email);
            if ($email && !in_array(strtolower($email), array_map('strtolower', $emails), true)) $emails[] = $email;
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

        if (class_exists('TIX_Emails') && method_exists('TIX_Emails', 'build_generic_email_html')) {
            $html = TIX_Emails::build_generic_email_html(
                esc_html($subject),
                '<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">' . nl2br($message) . '</p>',
                esc_html($event_title)
            );
        }
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        // Antworten gehen an den Veranstalter
        $reply = sanitize_email((string) get_post_meta($org_id, '_tix_org_email', true));
        if (!$reply) { $u = wp_get_current_user(); $reply = $u ? (string) $u->user_email : ''; }
        if ($reply) $headers[] = 'Reply-To: ' . $reply;
        $sent = 0;
        foreach ($emails as $to) {
            $ok = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::mail($to, $subject, $html, $headers) : wp_mail($to, $subject, $html, $headers);
            if ($ok) $sent++;
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
            'meta_query' => ['relation' => 'OR', ['key' => '_tix_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'], ['key' => '_tix_co_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC']],
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
                    <button type="submit" class="button button-primary" style="padding:10px 24px;border-radius:10px;font-size:14px;background:<?php echo tix_primary(); ?>;border-color:<?php echo tix_primary(); ?>;">
                        <span class="dashicons dashicons-download" style="margin-right:4px;"></span> CSV herunterladen
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /** Bestellungen mit Käuferdaten als CSV (Zeitraum frei, nur bezahlt oder alle; nur eigene Positionen). */
    public static function export_orders_csv() {
        if (!self::is_organizer() || !self::team_can('view_orders')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_orders_csv', '_tix_nonce');
        $event_ids = self::get_organizer_event_ids();
        $filter = intval($_POST['event_id'] ?? 0);
        if ($filter && in_array($filter, $event_ids)) $event_ids = [$filter];
        $status = (($_POST['status'] ?? '') === 'all') ? 'all' : 'paid';
        $rows = TIX_Export::order_rows($event_ids, sanitize_text_field($_POST['from'] ?? ''), sanitize_text_field($_POST['to'] ?? ''), $status);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bestellungen-' . current_time('Y-m-d') . '.csv"');
        echo TIX_Export::csv(TIX_Export::ORDER_HEADER, $rows);
        exit;
    }

    public static function export_billing_csv() {
        if (!self::is_organizer() || !self::team_can('manage_billing')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_billing_csv', '_tix_nonce');

        $org_id = self::get_organizer_id();
        $month = sanitize_text_field($_POST['month'] ?? date('Y-m'));
        $filter_event = intval($_POST['event_id'] ?? 0);

        $event_ids = self::get_organizer_event_ids();
        if ($filter_event && in_array($filter_event, $event_ids)) {
            $event_ids = [$filter_event];
        }

        $rows = [];
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
        $date_from = $month . '-01';
        $date_to   = date('Y-m-t', strtotime($date_from));
        list(, $result) = self::event_orders($event_ids, ['status' => ['completed', 'processing'], 'date_from' => $date_from, 'date_to' => $date_to]);
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $rate = !empty($s['tax_enabled']) ? floatval($s['tax_rate'] ?? 0) : 0.0;
        $inclusive = !empty($s['tax_inclusive']);
        foreach (array_reverse($result) as $r) {
            $o = $r['order'];
            foreach ($r['items'] as $it) {
                $amount = floatval($it->total);
                // Positionen speichern den Preis × Menge; MwSt nach den Steuer-Einstellungen
                if ($rate > 0 && $inclusive)      { $gross = $amount; $tax = round($amount - $amount / (1 + $rate / 100), 2); }
                elseif ($rate > 0)                { $tax = round($amount * $rate / 100, 2); $gross = $amount + $tax; }
                else                              { $gross = $amount; $tax = 0.0; }
                $rows[] = [
                    $o->order_number ?: $o->id,
                    date_i18n('d.m.Y', strtotime($o->date_created)),
                    get_the_title(intval($it->event_id)),
                    ($it->cat_name ?: $it->name) . ' x' . intval($it->quantity),
                    number_format($gross, 2, ',', ''),
                    number_format($tax, 2, ',', ''),
                    number_format($gross - $tax, 2, ',', ''),
                ];
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

    /** tix_order_status_changed: bei bezahlter Bestellung die Veranstalter benachrichtigen. */
    public static function on_order_status($order_id, $new_status = '') {
        if (in_array($new_status, ['completed', 'processing'], true)) self::notify_new_order($order_id);
    }

    public static function notify_new_order($order_id) {
        $order_id = intval($order_id);
        if (!$order_id || !class_exists('TIX_Order')) return;
        // Bereits benachrichtigt? (Bestellungen sind keine Posts → Option)
        if (get_option('_tix_org_notified_' . $order_id)) return;
        $order = TIX_Order::get($order_id);
        if (!$order) return;
        update_option('_tix_org_notified_' . $order_id, 1, false);

        // Positionen je Event sammeln
        $by_event = [];
        foreach ($order->get_items() as $item) {
            $eid = intval($item->get_event_id());
            if (!$eid) continue;
            if (!isset($by_event[$eid])) $by_event[$eid] = ['qty' => 0, 'total' => 0.0];
            $by_event[$eid]['qty']   += $item->get_quantity();
            $by_event[$eid]['total'] += $item->get_total();
        }
        $buyer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $notified = [];
        foreach ($by_event as $event_id => $sum) {
            // Beide Veranstalter (Haupt + Co) benachrichtigen
            foreach (array_filter([intval(get_post_meta($event_id, '_tix_organizer_id', true)), intval(get_post_meta($event_id, '_tix_co_organizer_id', true))]) as $org_id) {
                if (isset($notified[$org_id . ':' . $event_id])) continue;
                $email = sanitize_email((string) get_post_meta($org_id, '_tix_org_email', true));
                if (!$email) {
                    $user = get_userdata(intval(get_post_meta($org_id, '_tix_org_user_id', true)));
                    $email = $user ? (string) $user->user_email : '';
                }
                if (!$email) continue;
                // Keine Doppelmail (1.38.471): Dieselbe Adresse bekommt die Bestellung schon über die Benachrichtigungen
                // („💰 Neue Bestellung“), bzw. der Veranstalter hat Benachrichtigungen pausiert.
                if (class_exists('TIX_Organizer_Notifications') && TIX_Organizer_Notifications::covers_order_mail($org_id, $email)) {
                    $notified[$org_id . ':' . $event_id] = true;
                    self::check_low_stock($event_id, $org_id, $email);
                    continue;
                }
                $event_title = get_the_title($event_id);
                $body = '<p style="margin:0 0 12px;font-size:14px;line-height:1.6;"><strong>' . esc_html($buyer ?: $order->get_billing_email()) . '</strong> hat <strong>' . intval($sum['qty']) . ' Ticket(s)</strong> f&uuml;r <strong>' . esc_html($event_title) . '</strong> gekauft.</p>'
                      . '<p style="margin:0 0 12px;font-size:18px;font-weight:700;">' . self::money($sum['total']) . '</p>'
                      . '<p style="margin:0;font-size:12px;color:#94a3b8;">Bestellung #' . esc_html($order->get_order_number()) . '</p>';
                $html = (class_exists('TIX_Emails') && method_exists('TIX_Emails', 'build_generic_email_html'))
                    ? TIX_Emails::build_generic_email_html('Neue Bestellung', $body, $event_title, '', 'admin')
                    : '<div style="font-family:Inter,system-ui,sans-serif;max-width:500px;margin:0 auto;padding:20px;"><h2 style="font-size:16px;">Neue Bestellung</h2>' . $body . '</div>';
                wp_mail($email, 'Neue Bestellung: ' . $event_title, $html, ['Content-Type: text/html; charset=UTF-8']);
                $notified[$org_id . ':' . $event_id] = true;
                self::check_low_stock($event_id, $org_id, $email);
            }
        }
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

        // Seit 1.38.471 im Mail-Layout und auf Deutsch (vorher „Low-Stock“)
        $t = html_entity_decode($event_title, ENT_QUOTES, 'UTF-8');
        $m = ['title' => 'Fast ausverkauft', 'subtitle' => $t, 'kind' => 'admin', 'blocks' => [
            ['type' => 'notice', 'tone' => 'warn', 'html' => '<strong>' . esc_html($t) . '</strong> ist zu <strong>' . $pct . ' %</strong> ausverkauft.'],
            ['type' => 'info', 'rows' => [['Noch verfügbar', $remaining . ' von ' . $capacity . ' Tickets'], ['Verkauft', (string) $sold]]],
        ], 'note' => 'Benachrichtigung für deinen Veranstalter-Bereich.'];
        $subject = 'Fast ausverkauft: ' . $t . ' (noch ' . $remaining . ' Tickets)';
        if (class_exists('TIX_Mail_Layout')) TIX_Mail_Layout::send($email, $subject, $m);
        else wp_mail($email, $subject, '<p>' . esc_html($subject) . '</p>', ['Content-Type: text/html; charset=UTF-8']);
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
                        <div id="tix-media-bar" style="background:<?php echo tix_primary(); ?>;height:100%;width:0;transition:width .3s;"></div>
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

            dropZone.addEventListener('dragover', function(e) { e.preventDefault(); this.style.borderColor = '<?php echo tix_primary(); ?>'; this.style.background = '#FFF8F4'; });
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
