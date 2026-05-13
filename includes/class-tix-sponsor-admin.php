<?php
if (!defined('ABSPATH')) exit;

/**
 * Tixomat — Sponsor Admin
 *
 * Submenu "Sponsoren" unter Tixomat. Liste + Anlegen + Edit.
 *
 * @since 1.38.171
 */
class TIX_Sponsor_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);

        $actions = [
            'tix_sponsor_save', 'tix_sponsor_list', 'tix_sponsor_delete',
            'tix_sponsor_pools', 'tix_sponsor_pool_add', 'tix_sponsor_pool_delete',
            'tix_sponsor_send_login', 'tix_sponsor_create_category',
            'tix_sponsor_login_as_url',
        ];
        foreach ($actions as $a) {
            add_action('wp_ajax_' . $a, [__CLASS__, 'ajax_' . str_replace('tix_sponsor_', '', $a)]);
        }

        // "Login as Sponsor" (Admin → setzt Sponsor-Cookie + Redirect)
        add_action('admin_post_tix_sponsor_login_as', [__CLASS__, 'login_as_sponsor']);
    }

    /**
     * AJAX: gibt die nonced URL für "Login as Sponsor" zurueck.
     * Wird im JS aufgerufen wenn die Detail-Card geoeffnet wird.
     */
    public static function ajax_login_as_url() {
        check_ajax_referer('tix_sponsor_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        $sid = intval($_POST['sponsor_id'] ?? 0);
        if (!$sid) wp_send_json_error('Sponsor-ID fehlt.');
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=tix_sponsor_login_as&sponsor_id=' . $sid),
            'tix_sponsor_login_as_' . $sid
        );
        wp_send_json_success(['url' => $url]);
    }

    /**
     * Admin-Action: setzt Sponsor-Session-Cookie für den eingeloggten Admin
     * und redirected zur Sponsor-Dashboard-Seite. Capability-Check + Nonce.
     */
    public static function login_as_sponsor() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        $sid = intval($_GET['sponsor_id'] ?? 0);
        if (!$sid) wp_die('Sponsor-ID fehlt.');
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tix_sponsor_login_as_' . $sid)) {
            wp_die('Ungültige Sicherheitsprüfung.');
        }
        if (!class_exists('TIX_Sponsor_DB') || !class_exists('TIX_Sponsor_Auth')) wp_die('Sponsor-Modul fehlt.');

        $sponsor = TIX_Sponsor_DB::get_sponsor($sid);
        if (!$sponsor || $sponsor->status !== 'active') wp_die('Sponsor nicht gefunden oder inaktiv.');

        TIX_Sponsor_Auth::set_session_cookie(intval($sponsor->id));
        wp_safe_redirect(add_query_arg('tix_admin_preview', '1', TIX_Sponsor_Auth::get_sponsor_page_url()));
        exit;
    }

    public static function add_menu() {
        add_submenu_page(
            'tixomat',
            'Sponsoren',
            'Sponsoren',
            'manage_options',
            'tix-sponsors',
            [__CLASS__, 'render']
        );
    }

    public static function enqueue($hook) {
        if ($hook !== 'tixomat_page_tix-sponsors') return;
        wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_style('dashicons');
        wp_localize_script('jquery', 'tixSponsor', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tix_sponsor_admin'),
            'events'  => self::get_events_list(),
        ]);
    }

    private static function get_events_list(): array {
        $events = get_posts([
            'post_type' => 'event', 'post_status' => 'publish',
            'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC',
        ]);
        $list = [];
        foreach ($events as $ev) {
            $cats = get_post_meta($ev->ID, '_tix_ticket_categories', true);
            $cat_list = [];
            if (is_array($cats)) {
                foreach ($cats as $i => $c) {
                    $cat_list[] = ['index' => $i, 'name' => $c['name'] ?? '', 'admin_only' => !empty($c['admin_only'])];
                }
            }
            $list[] = ['id' => $ev->ID, 'title' => $ev->post_title, 'cats' => $cat_list];
        }
        return $list;
    }

    /* ──── AJAX ──── */

    public static function ajax_save() {
        check_ajax_referer('tix_sponsor_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Sponsor_DB')) wp_send_json_error('Sponsor-DB fehlt.');

        $id    = intval($_POST['sponsor_id'] ?? 0);
        $name  = sanitize_text_field($_POST['name'] ?? '');
        $cn    = sanitize_text_field($_POST['contact_name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        if (!$name)  wp_send_json_error('Name ist erforderlich.');
        if (!is_email($email)) wp_send_json_error('Bitte gültige E-Mail eingeben.');

        if ($id > 0) {
            TIX_Sponsor_DB::update_sponsor($id, ['name' => $name, 'contact_name' => $cn, 'email' => $email, 'notes' => $notes]);
            wp_send_json_success(['id' => $id, 'message' => 'Sponsor aktualisiert.']);
        }

        // Duplikat-Check
        $exists = TIX_Sponsor_DB::get_sponsor_by_email($email);
        if ($exists) wp_send_json_error('Diese E-Mail ist bereits als Sponsor registriert.');

        $new_id = TIX_Sponsor_DB::insert_sponsor([
            'name' => $name, 'contact_name' => $cn, 'email' => $email, 'notes' => $notes, 'status' => 'active',
        ]);
        wp_send_json_success(['id' => $new_id, 'message' => 'Sponsor angelegt.']);
    }

    public static function ajax_list() {
        check_ajax_referer('tix_sponsor_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Sponsor_DB')) wp_send_json_error('Sponsor-DB fehlt.');

        global $wpdb;
        $rows = TIX_Sponsor_DB::get_all_sponsors();
        $tp = TIX_Sponsor_DB::table_pools();
        $data = [];
        foreach ($rows as $r) {
            $sum = $wpdb->get_row($wpdb->prepare(
                "SELECT COALESCE(SUM(total),0) tot, COALESCE(SUM(used),0) usd FROM $tp WHERE sponsor_id = %d",
                $r->id
            ));
            $data[] = [
                'id'           => intval($r->id),
                'name'         => $r->name,
                'contact_name' => $r->contact_name,
                'email'        => $r->email,
                'notes'        => $r->notes,
                'status'       => $r->status,
                'total'        => intval($sum->tot ?? 0),
                'used'         => intval($sum->usd ?? 0),
                'available'    => max(0, intval($sum->tot ?? 0) - intval($sum->usd ?? 0)),
            ];
        }
        wp_send_json_success($data);
    }

    public static function ajax_delete() {
        check_ajax_referer('tix_sponsor_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        $id = intval($_POST['id'] ?? 0);
        TIX_Sponsor_DB::delete_sponsor($id);
        wp_send_json_success(['message' => 'Sponsor deaktiviert.']);
    }

    public static function ajax_pools() {
        check_ajax_referer('tix_sponsor_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        $sid = intval($_POST['sponsor_id'] ?? 0);
        $pools = TIX_Sponsor_DB::get_pools_by_sponsor($sid);
        $data = [];
        foreach ($pools as $p) {
            $data[] = [
                'id'         => intval($p->id),
                'event_id'   => intval($p->event_id),
                'event_title'=> $p->event_title ?: '(Event #' . $p->event_id . ')',
                'cat_index'  => intval($p->cat_index),
                'cat_name'   => $p->cat_name,
                'total'      => intval($p->total),
                'used'       => intval($p->used),
                'available'  => max(0, intval($p->total) - intval($p->used)),
            ];
        }
        wp_send_json_success($data);
    }

    public static function ajax_pool_add() {
        check_ajax_referer('tix_sponsor_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $sid       = intval($_POST['sponsor_id'] ?? 0);
        $event_id  = intval($_POST['event_id'] ?? 0);
        $cat_index = intval($_POST['cat_index'] ?? 0);
        $total     = max(1, intval($_POST['total'] ?? 0));

        if (!$sid || !$event_id) wp_send_json_error('Sponsor + Event sind erforderlich.');

        // cat_name aus Event-Cats holen
        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        $cat_name = (is_array($cats) && isset($cats[$cat_index]['name'])) ? $cats[$cat_index]['name'] : '';

        $pid = TIX_Sponsor_DB::insert_pool([
            'sponsor_id' => $sid, 'event_id' => $event_id,
            'cat_index'  => $cat_index, 'cat_name' => $cat_name,
            'total'      => $total,
        ]);
        wp_send_json_success(['id' => $pid, 'message' => 'Kontingent hinzugefügt.']);
    }

    public static function ajax_pool_delete() {
        check_ajax_referer('tix_sponsor_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        $id = intval($_POST['id'] ?? 0);
        TIX_Sponsor_DB::delete_pool($id);
        wp_send_json_success(['message' => 'Kontingent entfernt.']);
    }

    /**
     * Legt eine neue (admin-only / versteckte) Kategorie im Event an.
     * Wird vom Pool-Form genutzt — "+ Neue Kategorie anlegen".
     */
    public static function ajax_create_category() {
        check_ajax_referer('tix_sponsor_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $event_id = intval($_POST['event_id'] ?? 0);
        $name     = trim(sanitize_text_field($_POST['name'] ?? ''));
        $qty      = max(0, intval($_POST['qty'] ?? 0));
        $admin_only = !empty($_POST['admin_only']);

        if (!$event_id || !$name) wp_send_json_error('Event und Name sind erforderlich.');

        $cats = get_post_meta($event_id, '_tix_ticket_categories', true);
        if (!is_array($cats)) $cats = [];

        // Duplikat-Check (case-insensitive)
        foreach ($cats as $c) {
            if (strtolower(trim($c['name'] ?? '')) === strtolower($name)) {
                wp_send_json_error('Eine Kategorie mit diesem Namen existiert bereits in diesem Event.');
            }
        }

        $new_cat = [
            'name'           => $name,
            'price'          => 0,
            'sale_price'     => '',
            'qty'            => $qty,
            'desc'           => '',
            'image_id'       => 0,
            'online'         => '0',          // im normalen Selektor unsichtbar
            'offline_ticket' => '0',
            'admin_only'     => $admin_only ? 1 : 0,
            'bundle_buy'     => 0, 'bundle_pay' => 0, 'bundle_label' => '',
            'tc_event_id'    => 0, 'product_id' => 0, 'sku' => '', 'group' => '',
            'seatmap_id'     => 0, 'seatmap_section' => '',
            'phases'         => [],
        ];

        $cats[] = $new_cat;
        update_post_meta($event_id, '_tix_ticket_categories', $cats);

        $new_index = count($cats) - 1;
        wp_send_json_success([
            'cat_index' => $new_index,
            'cat_name'  => $name,
            'message'   => 'Kategorie "' . $name . '" angelegt (versteckt, qty=' . $qty . ').',
        ]);
    }

    public static function ajax_send_login() {
        check_ajax_referer('tix_sponsor_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        $sid = intval($_POST['sponsor_id'] ?? 0);
        $sponsor = TIX_Sponsor_DB::get_sponsor($sid);
        if (!$sponsor) wp_send_json_error('Sponsor nicht gefunden.');

        if (!class_exists('TIX_Sponsor_Auth')) wp_send_json_error('Auth-Modul fehlt.');
        $url = TIX_Sponsor_Auth::build_magic_url($sid);

        // Direkt-Mail
        $reflection = new ReflectionClass('TIX_Sponsor_Auth');
        $method = $reflection->getMethod('send_magic_link_email');
        $method->setAccessible(true);
        $method->invoke(null, $sponsor->email, $url, $sponsor);

        wp_send_json_success(['message' => 'Login-Link gesendet an ' . $sponsor->email]);
    }

    /* ──── Render ──── */

    public static function render() {
        if (!class_exists('TIX_Sponsor_DB') || !TIX_Sponsor_DB::tables_exist()) {
            echo '<div class="wrap"><h1>Sponsoren</h1><div class="notice notice-error"><p>Datenbank-Tabellen wurden noch nicht erstellt. Bitte das Plugin deaktivieren und wieder aktivieren.</p></div></div>';
            return;
        }
        ?>
        <div class="wrap" style="max-width:1300px;">
            <h1 style="display:flex;align-items:center;gap:10px;">
                <span class="dashicons dashicons-awards" style="font-size:28px;width:28px;height:28px;color:#FF5500;"></span>
                Sponsoren
            </h1>
            <p style="color:#6b7280;font-size:13px;margin:4px 0 20px;">
                Sponsoren-Kontingente verwalten: Tickets als Sponsor-Bereich oder Freikarten ausgeben, Sponsor erhält ein eigenes Portal zur Verteilung.
            </p>

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;margin-bottom:18px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                    <h2 style="margin:0;font-size:16px;">Alle Sponsoren</h2>
                    <button type="button" class="button button-primary" id="tix-sp-add-btn"><span class="dashicons dashicons-plus-alt2" style="font-size:14px;width:14px;height:14px;line-height:1;vertical-align:text-top;"></span> Sponsor anlegen</button>
                </div>

                <!-- Anlegen-Form (inline, toggle) -->
                <div id="tix-sp-form" style="display:none;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:14px;">
                    <input type="hidden" id="tix-sp-id" value="0">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#0f172a;display:block;margin-bottom:4px;">Firmenname *</label>
                            <input type="text" id="tix-sp-name" style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px;" placeholder="z.B. ACME Handwerker GmbH">
                        </div>
                        <div>
                            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#0f172a;display:block;margin-bottom:4px;">Kontaktperson</label>
                            <input type="text" id="tix-sp-contact-name" style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px;" placeholder="Max Mustermann">
                        </div>
                        <div>
                            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#0f172a;display:block;margin-bottom:4px;">E-Mail *</label>
                            <input type="email" id="tix-sp-email" style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px;" placeholder="kontakt@acme.de">
                            <small style="color:#64748b;font-size:11px;">Login-Link wird hier hingeschickt.</small>
                        </div>
                        <div>
                            <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:#0f172a;display:block;margin-bottom:4px;">Interne Notizen</label>
                            <textarea id="tix-sp-notes" rows="2" style="width:100%;padding:9px;border:1px solid #d1d5db;border-radius:6px;"></textarea>
                        </div>
                    </div>
                    <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" class="button" id="tix-sp-cancel">Abbrechen</button>
                        <button type="button" class="button button-primary" id="tix-sp-save">Speichern</button>
                    </div>
                </div>

                <table class="tix-promo-table" style="width:100%;border-collapse:collapse;">
                    <thead><tr style="background:#f9fafb;">
                        <th style="text-align:left;padding:10px;">Firmenname</th>
                        <th style="text-align:left;padding:10px;">Kontakt</th>
                        <th style="text-align:left;padding:10px;">E-Mail</th>
                        <th style="text-align:right;padding:10px;">Kontingent</th>
                        <th style="text-align:right;padding:10px;">Vergeben</th>
                        <th style="text-align:right;padding:10px;">Aktionen</th>
                    </tr></thead>
                    <tbody id="tix-sp-list"><tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;">Lade&hellip;</td></tr></tbody>
                </table>
            </div>

            <!-- Detail-Card: Kontingente eines Sponsors -->
            <div id="tix-sp-detail" style="display:none;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                    <div>
                        <h2 style="margin:0;font-size:16px;">Kontingente für <span id="tix-sp-detail-name">—</span></h2>
                        <p style="margin:2px 0 0;color:#64748b;font-size:12px;">E-Mail: <code id="tix-sp-detail-email">—</code></p>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <a href="#" id="tix-sp-login-as" class="button" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:5px;">
                            <span class="dashicons dashicons-visibility" style="font-size:14px;width:14px;height:14px;line-height:1;"></span>
                            Als Sponsor öffnen
                        </a>
                        <button type="button" class="button" id="tix-sp-send-login">📧 Login-Link senden</button>
                        <button type="button" class="button" id="tix-sp-pool-add-btn"><span class="dashicons dashicons-plus-alt2" style="font-size:14px;width:14px;height:14px;line-height:1;vertical-align:text-top;"></span> Kontingent</button>
                        <button type="button" class="button" id="tix-sp-detail-close">Schließen</button>
                    </div>
                </div>

                <div id="tix-sp-pool-form" style="display:none;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px;margin-bottom:14px;">
                    <div style="display:grid;grid-template-columns:2fr 1fr 100px;gap:10px;align-items:end;">
                        <div>
                            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Event</label>
                            <select id="tix-sp-pool-event" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;"></select>
                        </div>
                        <div>
                            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Kategorie</label>
                            <select id="tix-sp-pool-cat" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;"></select>
                        </div>
                        <div>
                            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Anzahl</label>
                            <input type="number" id="tix-sp-pool-total" min="1" value="100" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                        </div>
                    </div>

                    <!-- Inline-Form: Neue Kategorie anlegen (nur sichtbar bei "+ Neue Kategorie") -->
                    <div id="tix-sp-newcat-wrap" style="display:none;margin-top:12px;padding:12px 14px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;">
                        <strong style="font-size:13px;color:#7c2d12;display:block;margin-bottom:8px;">🔒 Neue versteckte Kategorie für diesen Sponsor anlegen</strong>
                        <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;">
                            <div>
                                <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Kategorie-Name *</label>
                                <input type="text" id="tix-sp-newcat-name" placeholder="z.B. Sponsor-Bereich Handwerker" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Kapazität gesamt</label>
                                <input type="number" id="tix-sp-newcat-qty" min="0" value="100" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                            </div>
                        </div>
                        <small style="color:#7c2d12;font-size:11px;display:block;margin-top:6px;">Diese Kategorie wird als <strong>versteckt</strong> (admin_only) angelegt — Besucher sehen sie nicht im normalen Ticket-Selektor.</small>
                    </div>

                    <div style="margin-top:10px;display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" class="button" id="tix-sp-pool-cancel">Abbrechen</button>
                        <button type="button" class="button button-primary" id="tix-sp-pool-save">Kontingent hinzufügen</button>
                    </div>
                </div>

                <table style="width:100%;border-collapse:collapse;">
                    <thead><tr style="background:#f9fafb;"><th style="text-align:left;padding:10px;">Event</th><th style="text-align:left;padding:10px;">Kategorie</th><th style="text-align:right;padding:10px;">Gesamt</th><th style="text-align:right;padding:10px;">Vergeben</th><th style="text-align:right;padding:10px;">Frei</th><th style="text-align:right;padding:10px;"></th></tr></thead>
                    <tbody id="tix-sp-pools"><tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;">Wähle einen Sponsor.</td></tr></tbody>
                </table>
            </div>
        </div>

        <script>
        (function($) {
            var currentSponsor = null;

            function esc(s) { var d = document.createElement('div'); d.textContent = (s === null || s === undefined ? '' : s); return d.innerHTML; }

            function loadSponsors() {
                $.post(tixSponsor.ajaxurl, { action: 'tix_sponsor_list', nonce: tixSponsor.nonce }, function(r) {
                    if (!r.success) return;
                    var html = '';
                    if (!r.data.length) {
                        html = '<tr><td colspan="6" style="text-align:center;padding:30px;color:#9ca3af;">Noch keine Sponsoren angelegt.</td></tr>';
                    } else {
                        $.each(r.data, function(i, s) {
                            var statusBadge = s.status === 'active'
                                ? '<span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:700;">aktiv</span>'
                                : '<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;font-size:11px;font-weight:700;">inaktiv</span>';
                            html += '<tr>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;"><strong>' + esc(s.name) + '</strong> &nbsp;' + statusBadge + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;">' + esc(s.contact_name) + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;"><code style="font-size:11px;">' + esc(s.email) + '</code></td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;text-align:right;">' + s.total + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;text-align:right;">' + s.used + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;text-align:right;">' +
                                    '<button class="button button-small tix-sp-detail-btn" data-id="' + s.id + '" data-name="' + esc(s.name) + '" data-email="' + esc(s.email) + '" data-contact="' + esc(s.contact_name) + '" data-notes="' + esc(s.notes) + '">Verwalten</button> ' +
                                    '<button class="button button-small tix-sp-edit-btn" data-id="' + s.id + '" data-name="' + esc(s.name) + '" data-email="' + esc(s.email) + '" data-contact="' + esc(s.contact_name) + '" data-notes="' + esc(s.notes) + '">Bearbeiten</button>' +
                                '</td></tr>';
                        });
                    }
                    $('#tix-sp-list').html(html);
                });
            }

            // Form-Toggle
            $('#tix-sp-add-btn').on('click', function() {
                $('#tix-sp-id').val('0');
                $('#tix-sp-name, #tix-sp-contact-name, #tix-sp-email, #tix-sp-notes').val('');
                $('#tix-sp-form').show();
            });
            $('#tix-sp-cancel').on('click', function() { $('#tix-sp-form').hide(); });

            // Edit
            $(document).on('click', '.tix-sp-edit-btn', function() {
                $('#tix-sp-id').val($(this).data('id'));
                $('#tix-sp-name').val($(this).data('name'));
                $('#tix-sp-contact-name').val($(this).data('contact'));
                $('#tix-sp-email').val($(this).data('email'));
                $('#tix-sp-notes').val($(this).data('notes'));
                $('#tix-sp-form').show();
            });

            // Save
            $('#tix-sp-save').on('click', function() {
                var $btn = $(this); $btn.prop('disabled', true).text('Speichere…');
                $.post(tixSponsor.ajaxurl, {
                    action: 'tix_sponsor_save', nonce: tixSponsor.nonce,
                    sponsor_id:   $('#tix-sp-id').val(),
                    name:         $('#tix-sp-name').val(),
                    contact_name: $('#tix-sp-contact-name').val(),
                    email:        $('#tix-sp-email').val(),
                    notes:        $('#tix-sp-notes').val(),
                }, function(r) {
                    $btn.prop('disabled', false).text('Speichern');
                    if (r.success) {
                        $('#tix-sp-form').hide();
                        loadSponsors();
                    } else {
                        alert(r.data || 'Fehler.');
                    }
                });
            });

            // Detail-View
            $(document).on('click', '.tix-sp-detail-btn', function() {
                currentSponsor = { id: $(this).data('id'), name: $(this).data('name'), email: $(this).data('email') };
                $('#tix-sp-detail-name').text(currentSponsor.name);
                $('#tix-sp-detail-email').text(currentSponsor.email);
                // Login-As-URL bauen (per-sponsor Nonce, im Backend per AJAX holen)
                $.post(tixSponsor.ajaxurl, { action: 'tix_sponsor_login_as_url', nonce: tixSponsor.nonce, sponsor_id: currentSponsor.id }, function(r) {
                    if (r && r.success && r.data && r.data.url) $('#tix-sp-login-as').attr('href', r.data.url);
                });
                $('#tix-sp-detail').show();
                loadPools();
                $('html, body').animate({ scrollTop: $('#tix-sp-detail').offset().top - 60 }, 200);
            });
            $('#tix-sp-detail-close').on('click', function() {
                $('#tix-sp-detail').hide();
                currentSponsor = null;
            });

            // Send Login
            $('#tix-sp-send-login').on('click', function() {
                if (!currentSponsor) return;
                var $btn = $(this); $btn.prop('disabled', true).text('Sende…');
                $.post(tixSponsor.ajaxurl, { action: 'tix_sponsor_send_login', nonce: tixSponsor.nonce, sponsor_id: currentSponsor.id }, function(r) {
                    $btn.prop('disabled', false).text('📧 Login-Link senden');
                    alert(r.success ? r.data.message : (r.data || 'Fehler.'));
                });
            });

            function loadPools() {
                if (!currentSponsor) return;
                $.post(tixSponsor.ajaxurl, { action: 'tix_sponsor_pools', nonce: tixSponsor.nonce, sponsor_id: currentSponsor.id }, function(r) {
                    if (!r.success) return;
                    var html = '';
                    if (!r.data.length) {
                        html = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;">Noch keine Kontingente. Füge eines hinzu.</td></tr>';
                    } else {
                        $.each(r.data, function(i, p) {
                            html += '<tr>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;"><strong>' + esc(p.event_title) + '</strong></td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;">' + esc(p.cat_name) + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;text-align:right;">' + p.total + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;text-align:right;">' + p.used + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;text-align:right;font-weight:700;">' + p.available + '</td>' +
                                '<td style="padding:10px;border-top:1px solid #f3f4f6;text-align:right;">' +
                                    (p.used === 0 ? '<button class="button button-small tix-sp-pool-del" data-id="' + p.id + '">Entfernen</button>' : '<span style="color:#9ca3af;font-size:11px;">in Nutzung</span>') +
                                '</td></tr>';
                        });
                    }
                    $('#tix-sp-pools').html(html);
                });
            }

            // Pool-Form
            $('#tix-sp-pool-add-btn').on('click', function() {
                if (!currentSponsor) return;
                // Events ins Dropdown
                var evOpts = '<option value="">— Event wählen —</option>';
                if (tixSponsor.events && tixSponsor.events.length) {
                    tixSponsor.events.forEach(function(e) {
                        evOpts += '<option value="' + e.id + '">' + esc(e.title) + '</option>';
                    });
                }
                $('#tix-sp-pool-event').html(evOpts);
                $('#tix-sp-pool-cat').html('<option value="">Erst Event wählen</option>');
                $('#tix-sp-pool-total').val('100');
                $('#tix-sp-pool-form').show();
            });
            $('#tix-sp-pool-cancel').on('click', function() { $('#tix-sp-pool-form').hide(); });

            $('#tix-sp-pool-event').on('change', function() {
                var eid = parseInt($(this).val(), 10);
                var ev = tixSponsor.events.find(function(e) { return e.id === eid; });
                var opts = '<option value="">— Kategorie wählen —</option>';
                if (ev && ev.cats) {
                    ev.cats.forEach(function(c) {
                        var hint = c.admin_only ? ' 🔒 (versteckt)' : '';
                        opts += '<option value="' + c.index + '">' + esc(c.name) + hint + '</option>';
                    });
                }
                // Letzte Option: Neue Kategorie anlegen
                opts += '<option value="__new__" style="font-weight:700;color:#7c2d12;">+ Neue versteckte Kategorie anlegen…</option>';
                $('#tix-sp-pool-cat').html(opts);
                $('#tix-sp-newcat-wrap').hide();
            });

            // Cat-Auswahl: bei "__new__" Inline-Form einblenden
            $('#tix-sp-pool-cat').on('change', function() {
                $('#tix-sp-newcat-wrap').toggle($(this).val() === '__new__');
            });

            $('#tix-sp-pool-save').on('click', function() {
                if (!currentSponsor) return;
                var $btn = $(this); $btn.prop('disabled', true).text('Speichere…');
                var event_id = $('#tix-sp-pool-event').val();
                var cat_val  = $('#tix-sp-pool-cat').val();
                var total    = $('#tix-sp-pool-total').val();

                function createPool(cat_index) {
                    $.post(tixSponsor.ajaxurl, {
                        action: 'tix_sponsor_pool_add', nonce: tixSponsor.nonce,
                        sponsor_id: currentSponsor.id,
                        event_id:   event_id,
                        cat_index:  cat_index,
                        total:      total,
                    }, function(r) {
                        $btn.prop('disabled', false).text('Kontingent hinzufügen');
                        if (r.success) {
                            $('#tix-sp-pool-form').hide();
                            $('#tix-sp-newcat-wrap').hide();
                            // tixSponsor.events Cache aktualisieren — neue Kategorie kommt sonst beim nächsten Pool nicht im Dropdown
                            location.reload();
                        } else {
                            alert(r.data || 'Fehler.');
                        }
                    });
                }

                if (cat_val === '__new__') {
                    var name = ($('#tix-sp-newcat-name').val() || '').trim();
                    var qty  = parseInt($('#tix-sp-newcat-qty').val(), 10) || 0;
                    if (!name) {
                        alert('Bitte einen Kategorie-Namen eingeben.');
                        $btn.prop('disabled', false).text('Kontingent hinzufügen');
                        return;
                    }
                    // 1. Kategorie anlegen, dann Pool
                    $.post(tixSponsor.ajaxurl, {
                        action: 'tix_sponsor_create_category', nonce: tixSponsor.nonce,
                        event_id:   event_id,
                        name:       name,
                        qty:        qty,
                        admin_only: 1,
                    }, function(r) {
                        if (!r.success) {
                            $btn.prop('disabled', false).text('Kontingent hinzufügen');
                            alert(r.data || 'Fehler beim Kategorie-Anlegen.');
                            return;
                        }
                        createPool(r.data.cat_index);
                    });
                } else {
                    if (!cat_val) {
                        alert('Bitte eine Kategorie auswählen.');
                        $btn.prop('disabled', false).text('Kontingent hinzufügen');
                        return;
                    }
                    createPool(cat_val);
                }
            });

            $(document).on('click', '.tix-sp-pool-del', function() {
                if (!confirm('Kontingent wirklich entfernen?')) return;
                $.post(tixSponsor.ajaxurl, { action: 'tix_sponsor_pool_delete', nonce: tixSponsor.nonce, id: $(this).data('id') }, function(r) {
                    if (r.success) { loadPools(); loadSponsors(); }
                    else alert(r.data || 'Fehler.');
                });
            });

            loadSponsors();
        })(jQuery);
        </script>
        <?php
    }
}
