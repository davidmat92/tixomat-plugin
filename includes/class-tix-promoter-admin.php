<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Promoter_Admin -- Admin-Backend fuer Promoter-Verwaltung.
 *
 * Tabs: Promoter | Events | Provisionen | Auszahlungen | Statistiken
 * Nutzt Chart.js (CDN) fuer Visualisierung, AJAX fuer Daten.
 *
 * @since 1.29.0
 */
class TIX_Promoter_Admin {

    /* ──────────────────────────── Bootstrap ──────────────────────────── */

    public static function init() {
        if (!wp_doing_ajax()) {
            add_action('admin_menu',            [__CLASS__, 'add_menu']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        }

        // AJAX endpoints
        $actions = [
            'tix_promoter_save',
            'tix_promoter_delete',
            'tix_promoter_list',
            'tix_promoter_search_users',
            'tix_promoter_assign',
            'tix_promoter_unassign',
            'tix_promoter_assignments',
            'tix_promoter_commissions',
            'tix_promoter_create_payout',
            'tix_promoter_mark_paid',
            'tix_promoter_cancel_payout',
            'tix_promoter_payouts',
            'tix_promoter_stats',
            'tix_promoter_generate_code',
        ];
        foreach ($actions as $a) {
            add_action('wp_ajax_' . $a, [__CLASS__, 'ajax_' . str_replace('tix_promoter_', '', $a)]);
        }

        // CSV-Export ueber admin_post
        add_action('admin_post_tix_promoter_export_csv', [__CLASS__, 'export_csv']);
    }

    /* ──────────────────────────── Menu ──────────────────────────── */

    public static function add_menu() {
        add_submenu_page(
            'tixomat',
            'Promoter',
            'Promoter',
            'manage_options',
            'tix-promoters',
            [__CLASS__, 'render']
        );
    }

    /* ──────────────────────────── Assets ──────────────────────────── */

    public static function enqueue_assets($hook) {
        if ($hook !== 'tixomat_page_tix-promoters') return;

        wp_enqueue_style('dashicons');
        wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', ['tix-google-fonts'], TIXOMAT_VERSION);

        $min = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
        wp_enqueue_style('tix-promoter-admin', TIXOMAT_URL . 'assets/css/promoter-admin' . $min . '.css', ['tix-admin'], TIXOMAT_VERSION);

        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', [], '4.4.0', true);
        wp_enqueue_script('tix-promoter-admin', TIXOMAT_URL . 'assets/js/promoter-admin' . $min . '.js', ['jquery', 'chartjs'], TIXOMAT_VERSION, true);

        wp_localize_script('tix-promoter-admin', 'tixPromoter', [
            'ajaxurl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('tix_promoter_admin'),
            'exporturl'  => admin_url('admin-post.php?action=tix_promoter_export_csv'),
            'promoters'  => self::get_promoters_list(),
            'events'     => self::get_events_list(),
            'currency'   => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : "\xe2\x82\xac",
        ]);
    }

    /* ──────────────────────────── Helpers ──────────────────────────── */

    private static function get_events_list() {
        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        $list = [];
        foreach ($events as $e) {
            $list[] = ['id' => $e->ID, 'title' => $e->post_title];
        }
        return $list;
    }

    private static function get_promoters_list() {
        if (!class_exists('TIX_Promoter_DB') || !TIX_Promoter_DB::tables_exist()) return [];
        $rows = TIX_Promoter_DB::get_all_promoters();
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'   => intval($r->id),
                'name' => $r->display_name ?: $r->wp_name ?: $r->promoter_code,
                'code' => $r->promoter_code,
            ];
        }
        return $list;
    }

    private static function format_eur($val) {
        return number_format(floatval($val), 2, ',', '.') . ' ' . "\xe2\x82\xac";
    }

    private static function format_date($date) {
        if (!$date) return "\xe2\x80\x93";
        return date_i18n('d.m.Y', strtotime($date));
    }

    private static function format_datetime($date) {
        if (!$date) return "\xe2\x80\x93";
        return date_i18n('d.m.Y H:i', strtotime($date));
    }

    private static function status_badge($status) {
        $map = [
            'active'    => ['label' => 'Aktiv',      'color' => '#10b981'],
            'inactive'  => ['label' => 'Inaktiv',    'color' => '#94a3b8'],
            'ended'     => ['label' => 'Beendet',    'color' => '#94a3b8'],
            'pending'   => ['label' => 'Ausstehend', 'color' => '#f59e0b'],
            'approved'  => ['label' => 'Genehmigt',  'color' => '#3b82f6'],
            'paid'      => ['label' => 'Bezahlt',    'color' => '#10b981'],
            'cancelled' => ['label' => 'Storniert',  'color' => '#ef4444'],
        ];
        $s = $map[$status] ?? ['label' => ucfirst($status), 'color' => '#94a3b8'];
        return '<span class="tix-badge" style="background:' . esc_attr($s['color']) . '15;color:' . esc_attr($s['color']) . ';border:1px solid ' . esc_attr($s['color']) . '30;">' . esc_html($s['label']) . '</span>';
    }

    /* ══════════════════════════════════════════════════════════════════
       AJAX HANDLERS
       ══════════════════════════════════════════════════════════════════ */

    /* ──── Promoter speichern (Create / Update) ──── */

    public static function ajax_save() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $id            = intval($_POST['promoter_id'] ?? 0);
        $user_id       = intval($_POST['user_id'] ?? 0);
        $promoter_code = sanitize_text_field($_POST['promoter_code'] ?? '');
        $display_name  = sanitize_text_field($_POST['display_name'] ?? '');
        $notes         = sanitize_textarea_field($_POST['notes'] ?? '');
        $status        = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($id > 0) {
            // Update – nur gesetzte Felder aktualisieren
            $update = ['status' => $status];
            if (!empty($promoter_code)) $update['promoter_code'] = $promoter_code;
            if (!empty($display_name))  $update['display_name']  = $display_name;
            if (isset($_POST['notes'])) $update['notes']         = $notes;
            $result = TIX_Promoter_DB::update_promoter($id, $update);
            if ($result === false) {
                wp_send_json_error('Fehler beim Aktualisieren.');
            }
            wp_send_json_success(['id' => $id, 'message' => 'Promoter aktualisiert.']);
        } else {
            // Create
            if (empty($promoter_code)) {
                wp_send_json_error('Promoter-Code ist erforderlich.');
            }
            if (!$user_id) {
                wp_send_json_error('WordPress-Benutzer ist erforderlich.');
            }
            $existing = TIX_Promoter_DB::get_promoter_by_user($user_id);
            if ($existing) {
                wp_send_json_error('Dieser Benutzer ist bereits als Promoter registriert.');
            }

            $new_id = TIX_Promoter_DB::insert_promoter([
                'user_id'       => $user_id,
                'promoter_code' => $promoter_code,
                'display_name'  => $display_name,
                'notes'         => $notes,
                'status'        => $status,
            ]);
            if (!$new_id) {
                wp_send_json_error('Fehler beim Erstellen. Code evtl. bereits vergeben.');
            }

            // Promoter-Rolle zuweisen
            $user = get_userdata($user_id);
            if ($user) {
                $user->add_role('tix_promoter');
            }

            wp_send_json_success(['id' => $new_id, 'message' => 'Promoter erstellt.']);
        }
    }

    /* ──── Promoter-Code auto-generieren ──── */

    public static function ajax_generate_code() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $max_attempts = 20;
        for ($i = 0; $i < $max_attempts; $i++) {
            $code = self::random_code(5);
            // Pruefen ob Code bereits existiert (aktiv oder inaktiv)
            global $wpdb;
            $table = TIX_Promoter_DB::table_promoters();
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE promoter_code = %s", $code
            ));
            if (!$exists) {
                wp_send_json_success(['code' => $code]);
            }
        }
        wp_send_json_error('Code-Generierung fehlgeschlagen. Bitte manuell eingeben.');
    }

    private static function random_code(int $length = 5): string {
        $chars = 'abcdefghijklmnopqrstuvwxyz';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, 25)];
        }
        return $code;
    }

    /* ──── Promoter loeschen (soft-delete) ──── */

    public static function ajax_delete() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('Ungueltige ID.');

        TIX_Promoter_DB::delete_promoter($id);
        wp_send_json_success(['message' => 'Promoter deaktiviert.']);
    }

    /* ──── Promoter auflisten ──── */

    public static function ajax_list() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $rows = TIX_Promoter_DB::get_all_promoter_stats();
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id'                 => intval($r->id),
                'promoter_code'      => $r->promoter_code,
                'display_name'       => $r->display_name ?: '',
                'user_id'            => intval($r->user_id),
                'user_email'         => $r->user_email ?? '',
                'notes'              => $r->notes ?? '',
                'status'             => $r->status,
                'status_badge'       => self::status_badge($r->status),
                'total_sales'        => self::format_eur($r->total_sales),
                'total_sales_raw'    => floatval($r->total_sales),
                'total_commission'   => self::format_eur($r->total_commission),
                'pending_commission' => self::format_eur($r->pending_commission),
            ];
        }
        wp_send_json_success($data);
    }

    /* ──── WP-User-Suche (Autocomplete) ──── */

    public static function ajax_search_users() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');

        $term = sanitize_text_field($_POST['q'] ?? $_POST['term'] ?? '');
        if (strlen($term) < 2) wp_send_json_success([]);

        $users = get_users([
            'search'         => '*' . $term . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number'         => 20,
        ]);

        $results = [];
        foreach ($users as $u) {
            $results[] = [
                'id'    => $u->ID,
                'label' => $u->display_name . ' (' . $u->user_email . ')',
                'value' => $u->display_name,
                'email' => $u->user_email,
            ];
        }
        wp_send_json_success($results);
    }

    /* ──── Event-Zuordnung erstellen ──── */

    public static function ajax_assign() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $promoter_id     = intval($_POST['promoter_id'] ?? 0);
        $event_id        = intval($_POST['event_id'] ?? 0);
        $commission_type  = in_array($_POST['commission_type'] ?? '', ['percent', 'fixed']) ? $_POST['commission_type'] : 'percent';
        $commission_value = floatval($_POST['commission_value'] ?? 0);
        $discount_type    = in_array($_POST['discount_type'] ?? '', ['percent', 'fixed', 'none', '']) ? $_POST['discount_type'] : '';
        $discount_value   = floatval($_POST['discount_value'] ?? 0);
        $promo_code       = sanitize_text_field($_POST['promo_code'] ?? '');

        if (!$promoter_id || !$event_id) {
            wp_send_json_error('Promoter und Event sind erforderlich.');
        }
        if ($commission_value <= 0) {
            wp_send_json_error('Provision muss groesser als 0 sein.');
        }

        // Doppelte Zuordnung pruefen
        $existing = TIX_Promoter_DB::get_assignment_by_promoter_event($promoter_id, $event_id);
        if ($existing) {
            wp_send_json_error('Diese Zuordnung existiert bereits.');
        }

        // Discount-Normalisierung
        if ($discount_type === 'none') {
            $discount_type  = '';
            $discount_value = 0;
        }

        $assignment_data = [
            'promoter_id'      => $promoter_id,
            'event_id'         => $event_id,
            'commission_type'  => $commission_type,
            'commission_value' => $commission_value,
            'discount_type'    => $discount_type,
            'discount_value'   => $discount_value,
            'promo_code'       => $promo_code,
        ];

        // WC-Coupon erstellen wenn Promo-Code und Discount vorhanden
        $coupon_id = 0;
        if ($promo_code && $discount_type && $discount_value > 0 && class_exists('TIX_Promoter')) {
            $coupon_id = TIX_Promoter::create_promo_coupon($assignment_data, $event_id);
            if ($coupon_id) {
                $assignment_data['coupon_id'] = $coupon_id;
            }
        }

        $id = TIX_Promoter_DB::assign_event($assignment_data);
        if (!$id) {
            wp_send_json_error('Fehler beim Erstellen der Zuordnung.');
        }

        wp_send_json_success(['id' => $id, 'coupon_id' => $coupon_id, 'message' => 'Zuordnung erstellt.']);
    }

    /* ──── Event-Zuordnung entfernen ──── */

    public static function ajax_unassign() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('Ungueltige ID.');

        // Coupon loeschen falls vorhanden
        $assignment = TIX_Promoter_DB::get_assignment($id);
        if ($assignment && $assignment->coupon_id && class_exists('TIX_Promoter')) {
            TIX_Promoter::delete_promo_coupon(intval($assignment->coupon_id));
        }

        TIX_Promoter_DB::unassign_event($id);
        wp_send_json_success(['message' => 'Zuordnung entfernt.']);
    }

    /* ──── Zuordnungen auflisten (gefiltert) ──── */

    public static function ajax_assignments() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $filters = [];
        if (!empty($_POST['promoter_id'])) $filters['promoter_id'] = intval($_POST['promoter_id']);
        if (!empty($_POST['event_id']))    $filters['event_id']    = intval($_POST['event_id']);
        if (!empty($_POST['status']))      $filters['status']      = sanitize_text_field($_POST['status']);

        $rows = TIX_Promoter_DB::get_all_assignments($filters);
        $data = [];

        foreach ($rows as $r) {
            $commission_display = $r->commission_type === 'percent'
                ? number_format($r->commission_value, 1, ',', '.') . ' %'
                : self::format_eur($r->commission_value);

            $discount_display = '';
            if ($r->discount_type === 'percent') {
                $discount_display = number_format($r->discount_value, 1, ',', '.') . ' %';
            } elseif ($r->discount_type === 'fixed') {
                $discount_display = self::format_eur($r->discount_value);
            } else {
                $discount_display = "\xe2\x80\x93";
            }

            // Referral-Link bauen
            $permalink = get_permalink(intval($r->event_id));
            $referral_link = $permalink
                ? add_query_arg('ref', $r->promoter_code, $permalink)
                : home_url('/?p=' . intval($r->event_id) . '&ref=' . $r->promoter_code);

            $data[] = [
                'id'                 => intval($r->id),
                'promoter_id'        => intval($r->promoter_id),
                'promoter_name'      => $r->promoter_name ?: $r->promoter_code,
                'promoter_code'      => $r->promoter_code,
                'event_id'           => intval($r->event_id),
                'event_title'        => $r->event_title ?: '(Event #' . $r->event_id . ')',
                'commission_type'    => $r->commission_type,
                'commission_value'   => floatval($r->commission_value),
                'commission_display' => $commission_display,
                'discount_type'      => $r->discount_type ?: '',
                'discount_value'     => floatval($r->discount_value),
                'discount_display'   => $discount_display,
                'promo_code'         => $r->promo_code ?: "\xe2\x80\x93",
                'referral_link'      => $referral_link,
                'status'             => $r->status,
                'status_badge'       => self::status_badge($r->status),
            ];
        }
        wp_send_json_success($data);
    }

    /* ──── Provisionen auflisten (gefiltert) ──── */

    public static function ajax_commissions() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $filters = [];
        if (!empty($_POST['promoter_id'])) $filters['promoter_id'] = intval($_POST['promoter_id']);
        if (!empty($_POST['event_id']))    $filters['event_id']    = intval($_POST['event_id']);
        if (!empty($_POST['status']))      $filters['status']      = sanitize_text_field($_POST['status']);
        if (!empty($_POST['date_from']))   $filters['date_from']   = sanitize_text_field($_POST['date_from']);
        if (!empty($_POST['date_to']))     $filters['date_to']     = sanitize_text_field($_POST['date_to']);

        $rows = TIX_Promoter_DB::get_commissions($filters);
        $data = [];

        foreach ($rows as $r) {
            $data[] = [
                'id'                => intval($r->id),
                'created_at'        => self::format_datetime($r->created_at),
                'promoter_name'     => $r->promoter_name ?: $r->promoter_code,
                'event_title'       => $r->event_title ?: '(Event #' . $r->event_id . ')',
                'order_id'          => intval($r->order_id),
                'order_link'        => admin_url('post.php?post=' . intval($r->order_id) . '&action=edit'),
                'tickets_qty'       => intval($r->tickets_qty),
                'order_total'       => self::format_eur($r->order_total),
                'order_total_raw'   => floatval($r->order_total),
                'commission_amount' => self::format_eur($r->commission_amount),
                'commission_raw'    => floatval($r->commission_amount),
                'attribution'       => $r->attribution,
                'status'            => $r->status,
                'status_badge'      => self::status_badge($r->status),
            ];
        }
        wp_send_json_success($data);
    }

    /* ──── Auszahlung erstellen ──── */

    public static function ajax_create_payout() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $promoter_id = intval($_POST['promoter_id'] ?? 0);
        $period_from = sanitize_text_field($_POST['period_from'] ?? '');
        $period_to   = sanitize_text_field($_POST['period_to'] ?? '');

        if (!$promoter_id || !$period_from || !$period_to) {
            wp_send_json_error('Alle Felder sind erforderlich.');
        }

        $payout_id = TIX_Promoter_DB::create_payout([
            'promoter_id' => $promoter_id,
            'period_from' => $period_from,
            'period_to'   => $period_to,
        ]);

        if (!$payout_id) {
            wp_send_json_error('Keine offenen Provisionen im gewaehlten Zeitraum.');
        }

        wp_send_json_success(['id' => $payout_id, 'message' => 'Auszahlung erstellt.']);
    }

    /* ──── Auszahlung als bezahlt markieren ──── */

    public static function ajax_mark_paid() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $id   = intval($_POST['id'] ?? 0);
        $note = sanitize_textarea_field($_POST['payment_note'] ?? '');
        if (!$id) wp_send_json_error('Ungueltige ID.');

        TIX_Promoter_DB::mark_payout_paid($id, $note);
        wp_send_json_success(['message' => 'Auszahlung als bezahlt markiert.']);
    }

    /* ──── Auszahlung stornieren ──── */

    public static function ajax_cancel_payout() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('Ungueltige ID.');

        TIX_Promoter_DB::cancel_payout($id);
        wp_send_json_success(['message' => 'Auszahlung storniert.']);
    }

    /* ──── Auszahlungen auflisten ──── */

    public static function ajax_payouts() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        $filters = [];
        if (!empty($_POST['promoter_id'])) $filters['promoter_id'] = intval($_POST['promoter_id']);
        if (!empty($_POST['status']))      $filters['status']      = sanitize_text_field($_POST['status']);

        $rows = TIX_Promoter_DB::get_payouts($filters);
        $data = [];

        foreach ($rows as $r) {
            $data[] = [
                'id'               => intval($r->id),
                'period'           => self::format_date($r->period_from) . ' - ' . self::format_date($r->period_to),
                'period_from'      => $r->period_from,
                'period_to'        => $r->period_to,
                'promoter_id'      => intval($r->promoter_id),
                'promoter_name'    => $r->promoter_name ?: $r->promoter_code,
                'total_sales'      => self::format_eur($r->total_sales),
                'total_sales_raw'  => floatval($r->total_sales),
                'total_commission' => self::format_eur($r->total_commission),
                'commission_raw'   => floatval($r->total_commission),
                'commission_count' => intval($r->commission_count),
                'status'           => $r->status,
                'status_badge'     => self::status_badge($r->status),
                'paid_date'        => self::format_datetime($r->paid_date),
                'payment_note'     => $r->payment_note ?? '',
            ];
        }
        wp_send_json_success($data);
    }

    /* ──── Statistiken ──── */

    public static function ajax_stats() {
        check_ajax_referer('tix_promoter_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Keine Berechtigung.');
        if (!class_exists('TIX_Promoter_DB'))   wp_send_json_error('Datenbank nicht verfuegbar.');

        global $wpdb;
        $tc = TIX_Promoter_DB::table_commissions();
        $tp = TIX_Promoter_DB::table_promoters();

        // Gesamt-KPIs
        $totals = $wpdb->get_row(
            "SELECT COALESCE(SUM(order_total), 0) AS total_sales,
                    COALESCE(SUM(commission_amount), 0) AS total_commission,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN commission_amount ELSE 0 END), 0) AS pending_commission
             FROM $tc WHERE status != 'cancelled'"
        );

        $active_promoters = $wpdb->get_var(
            "SELECT COUNT(*) FROM $tp WHERE status = 'active'"
        );

        $kpis = [
            'total_sales'      => ['value' => self::format_eur($totals->total_sales),      'raw' => floatval($totals->total_sales),      'label' => 'Gesamtumsatz',      'icon' => 'dashicons-money-alt'],
            'total_commission' => ['value' => self::format_eur($totals->total_commission),  'raw' => floatval($totals->total_commission),  'label' => 'Gesamtprovision',   'icon' => 'dashicons-chart-pie'],
            'pending'          => ['value' => self::format_eur($totals->pending_commission),'raw' => floatval($totals->pending_commission),'label' => 'Ausstehend',        'icon' => 'dashicons-clock'],
            'active_promoters' => ['value' => intval($active_promoters),                    'raw' => intval($active_promoters),            'label' => 'Aktive Promoter',   'icon' => 'dashicons-groups'],
        ];

        // Top-Promoter Barchart
        $top = TIX_Promoter_DB::get_top_promoters('', '', 10);
        $chart_labels = [];
        $chart_sales  = [];
        $chart_comm   = [];
        foreach ($top as $t) {
            $chart_labels[] = $t->display_name ?: $t->promoter_code;
            $chart_sales[]  = floatval($t->sales);
            $chart_comm[]   = floatval($t->commission);
        }

        $chart = [
            'type' => 'bar',
            'data' => [
                'labels'   => $chart_labels,
                'datasets' => [
                    [
                        'label'           => 'Umsatz (' . "\xe2\x82\xac" . ')',
                        'data'            => $chart_sales,
                        'backgroundColor' => '#FF5500',
                        'borderRadius'    => 4,
                    ],
                    [
                        'label'           => 'Provision (' . "\xe2\x82\xac" . ')',
                        'data'            => $chart_comm,
                        'backgroundColor' => '#10b981',
                        'borderRadius'    => 4,
                    ],
                ],
            ],
            'options' => [
                'indexAxis'   => 'y',
                'responsive'  => true,
                'plugins'     => ['legend' => ['position' => 'top']],
            ],
        ];

        wp_send_json_success([
            'kpis'  => $kpis,
            'chart' => $chart,
        ]);
    }

    /* ──── CSV Export ──── */

    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_promoter_admin', 'nonce');
        if (!class_exists('TIX_Promoter_DB')) wp_die('Datenbank nicht verfuegbar.');

        $type = sanitize_text_field($_GET['type'] ?? 'payouts');

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="promoter-' . $type . '-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // BOM fuer Excel UTF-8
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if ($type === 'commissions') {
            fputcsv($out, ['Datum', 'Promoter', 'Event', 'Bestellung', 'Tickets', 'Umsatz', 'Provision', 'Status'], ';');
            $filters = [];
            if (!empty($_GET['promoter_id'])) $filters['promoter_id'] = intval($_GET['promoter_id']);
            if (!empty($_GET['event_id']))    $filters['event_id']    = intval($_GET['event_id']);
            if (!empty($_GET['date_from']))   $filters['date_from']   = sanitize_text_field($_GET['date_from']);
            if (!empty($_GET['date_to']))     $filters['date_to']     = sanitize_text_field($_GET['date_to']);
            if (!empty($_GET['status']))      $filters['status']      = sanitize_text_field($_GET['status']);

            $rows = TIX_Promoter_DB::get_commissions($filters);
            foreach ($rows as $r) {
                fputcsv($out, [
                    self::format_datetime($r->created_at),
                    $r->promoter_name ?: $r->promoter_code,
                    $r->event_title,
                    '#' . $r->order_id,
                    $r->tickets_qty,
                    number_format($r->order_total, 2, ',', '.'),
                    number_format($r->commission_amount, 2, ',', '.'),
                    $r->status,
                ], ';');
            }
        } else {
            // Payouts
            fputcsv($out, ['Zeitraum', 'Promoter', 'Umsatz', 'Provision', 'Anzahl', 'Status', 'Bezahlt am'], ';');
            $filters = [];
            if (!empty($_GET['promoter_id'])) $filters['promoter_id'] = intval($_GET['promoter_id']);
            if (!empty($_GET['status']))      $filters['status']      = sanitize_text_field($_GET['status']);

            $rows = TIX_Promoter_DB::get_payouts($filters);
            foreach ($rows as $r) {
                fputcsv($out, [
                    self::format_date($r->period_from) . ' - ' . self::format_date($r->period_to),
                    $r->promoter_name ?: $r->promoter_code,
                    number_format($r->total_sales, 2, ',', '.'),
                    number_format($r->total_commission, 2, ',', '.'),
                    $r->commission_count,
                    $r->status,
                    $r->paid_date ? self::format_datetime($r->paid_date) : '',
                ], ';');
            }
        }

        fclose($out);
        exit;
    }

    /* ══════════════════════════════════════════════════════════════════
       EVENT TAB (Metabox-Pane im Event-Editor)
       ══════════════════════════════════════════════════════════════════ */

    public static function render_event_tab($post) {
        if (!class_exists('TIX_Promoter_DB') || !TIX_Promoter_DB::tables_exist()) {
            echo '<p class="tix-promoter-event-empty">Promoter-Datenbank nicht initialisiert. Bitte das Plugin deaktivieren und wieder aktivieren.</p>';
            return;
        }

        $promoters = TIX_Promoter_DB::get_event_promoters($post->ID);
        $manage_url = admin_url('admin.php?page=tix-promoters');

        if (empty($promoters)) {
            echo '<div class="tix-promoter-event-empty">';
            echo '<p style="margin:0 0 12px;"><span class="dashicons dashicons-businessman" style="font-size:32px;width:32px;height:32px;color:#cbd5e1;"></span></p>';
            echo '<p style="margin:0 0 8px;">Keine Promoter f&uuml;r dieses Event zugeordnet.</p>';
            echo '<a href="' . esc_url($manage_url) . '" class="button">Promoter zuordnen &rarr;</a>';
            echo '</div>';
            return;
        }

        echo '<div style="overflow-x:auto;">';
        echo '<table class="tix-promoter-event-table">';
        echo '<thead><tr>';
        echo '<th>Name</th><th>Code</th><th>Provision</th><th>Rabatt</th><th>Promo-Code</th><th>Referral-Link</th>';
        echo '</tr></thead><tbody>';

        foreach ($promoters as $p) {
            $name = esc_html($p->display_name ?: $p->promoter_code);
            $code = esc_html($p->promoter_code);

            // Provision
            $commission = $p->commission_type === 'percent'
                ? number_format($p->commission_value, 1, ',', '.') . ' %'
                : number_format($p->commission_value, 2, ',', '.') . ' &euro;';

            // Rabatt
            $discount = '&ndash;';
            if (!empty($p->discount_type) && floatval($p->discount_value) > 0) {
                $discount = $p->discount_type === 'percent'
                    ? number_format($p->discount_value, 1, ',', '.') . ' %'
                    : number_format($p->discount_value, 2, ',', '.') . ' &euro;';
            }

            // Promo-Code
            $promo = $p->promo_code ? '<code>' . esc_html($p->promo_code) . '</code>' : '&ndash;';

            // Referral-Link
            $permalink = get_permalink($post->ID);
            $ref_link = $permalink
                ? add_query_arg('ref', $p->promoter_code, $permalink)
                : home_url('/?p=' . $post->ID . '&ref=' . $p->promoter_code);

            echo '<tr>';
            echo '<td><strong>' . $name . '</strong></td>';
            echo '<td><code style="font-size:11px;">' . $code . '</code></td>';
            echo '<td>' . $commission . '</td>';
            echo '<td>' . $discount . '</td>';
            echo '<td>' . $promo . '</td>';
            echo '<td class="tix-ref-link-cell">';
            echo '<code class="tix-ref-link-code">' . esc_html($ref_link) . '</code>';
            echo '<button type="button" class="button button-small tix-evt-copy" data-link="' . esc_attr($ref_link) . '" title="Link kopieren"><span class="dashicons dashicons-clipboard"></span></button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '<p style="margin-top:12px;"><a href="' . esc_url($manage_url) . '" class="button button-small">Promoter verwalten &rarr;</a></p>';

        // Inline Copy-JS (kein extra Script nötig)
        ?>
        <script>
        (function(){
            document.querySelectorAll('.tix-evt-copy').forEach(function(btn){
                btn.addEventListener('click', function(e){
                    e.preventDefault();
                    var link = this.getAttribute('data-link');
                    var icon = this.querySelector('.dashicons');
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(link).then(function(){
                            icon.className = 'dashicons dashicons-yes';
                            setTimeout(function(){ icon.className = 'dashicons dashicons-clipboard'; }, 1500);
                        });
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /* ══════════════════════════════════════════════════════════════════
       RENDER – Admin Page
       ══════════════════════════════════════════════════════════════════ */

    public static function render() {
        if (!class_exists('TIX_Promoter_DB') || !TIX_Promoter_DB::tables_exist()) {
            echo '<div class="wrap"><h1>Promoter</h1><div class="notice notice-error"><p>Promoter-Datenbank-Tabellen wurden noch nicht erstellt. Bitte das Plugin deaktivieren und wieder aktivieren.</p></div></div>';
            return;
        }

        $tabs = [
            'promoters'   => ['label' => 'Promoter',      'icon' => 'dashicons-businessman'],
            'events'      => ['label' => 'Events',        'icon' => 'dashicons-calendar-alt'],
            'commissions' => ['label' => 'Provisionen',   'icon' => 'dashicons-chart-pie'],
            'payouts'     => ['label' => 'Auszahlungen',  'icon' => 'dashicons-money-alt'],
            'stats'       => ['label' => 'Statistiken',   'icon' => 'dashicons-chart-area'],
        ];
        ?>
        <div class="wrap tix-settings-wrap">
            <h1>Tixomat &ndash; Promoter</h1>

            <div class="tix-settings-grid">
                <div class="tix-app tix-settings-app" id="tix-promoter-app">

                    <!-- ═══ Navigation ═══ -->
                    <nav class="tix-nav">
                        <?php $first = true; foreach ($tabs as $key => $tab): ?>
                        <button type="button" class="tix-nav-tab<?php echo $first ? ' active' : ''; ?>" data-tab="<?php echo esc_attr($key); ?>">
                            <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                            <span class="tix-nav-label"><?php echo esc_html($tab['label']); ?></span>
                        </button>
                        <?php $first = false; endforeach; ?>
                    </nav>

                    <div class="tix-content">

                        <!-- ═══════════════════════════════════════
                             TAB 1: Promoter
                             ═══════════════════════════════════════ -->
                        <div class="tix-pane active" data-pane="promoters">

                            <div class="tix-pane-header">
                                <h2>Promoter verwalten</h2>
                                <button type="button" class="button button-primary" id="tix-promoter-add-btn">
                                    <span class="dashicons dashicons-plus-alt2"></span> Promoter hinzuf&uuml;gen
                                </button>
                            </div>

                            <!-- Inline-Formular -->
                            <div class="tix-inline-form" id="tix-promoter-form" style="display:none;">
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-businessman"></span>
                                        <h3 id="tix-promoter-form-title">Neuen Promoter erstellen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <input type="hidden" id="tix-pf-id" value="0">
                                        <div class="tix-form-grid">
                                            <div class="tix-form-field" id="tix-pf-user-wrap">
                                                <label>WordPress-Benutzer</label>
                                                <input type="text" id="tix-pf-user-search" placeholder="Name oder E-Mail suchen..." autocomplete="off">
                                                <input type="hidden" id="tix-pf-user-id" value="0">
                                                <div class="tix-autocomplete-results" id="tix-pf-user-results"></div>
                                            </div>
                                            <div class="tix-form-field">
                                                <label>Promoter-Code</label>
                                                <div class="tix-code-input-wrap">
                                                    <input type="text" id="tix-pf-code" placeholder="z.B. max oder auto-generieren" maxlength="30">
                                                    <button type="button" class="button button-small" id="tix-pf-code-generate" title="Zufälligen Code generieren (5 Kleinbuchstaben)">
                                                        <span class="dashicons dashicons-randomize"></span> Generieren
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="tix-form-field">
                                                <label>Anzeigename</label>
                                                <input type="text" id="tix-pf-display-name" placeholder="z.B. Max Mustermann">
                                            </div>
                                            <div class="tix-form-field tix-form-field-full">
                                                <label>Notizen</label>
                                                <textarea id="tix-pf-notes" rows="2" placeholder="Interne Notizen..."></textarea>
                                            </div>
                                        </div>
                                        <div class="tix-form-actions">
                                            <button type="button" class="button button-primary" id="tix-promoter-save-btn">Speichern</button>
                                            <button type="button" class="button" id="tix-promoter-cancel-btn">Abbrechen</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Tabelle -->
                            <div class="tix-card" style="margin-top:16px;">
                                <div class="tix-card-body" style="padding:0;overflow-x:auto;">
                                    <table class="tix-promo-table" id="tix-promoter-table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Code</th>
                                                <th>E-Mail</th>
                                                <th>Status</th>
                                                <th>Umsatz</th>
                                                <th>Provision</th>
                                                <th>Ausstehend</th>
                                                <th>Aktionen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td colspan="8" class="tix-loading"><div class="tix-spinner"></div></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════
                             TAB 2: Events (Zuordnungen)
                             ═══════════════════════════════════════ -->
                        <div class="tix-pane" data-pane="events">

                            <div class="tix-pane-header">
                                <h2>Event-Zuordnungen</h2>
                                <button type="button" class="button button-primary" id="tix-assign-add-btn">
                                    <span class="dashicons dashicons-plus-alt2"></span> Zuordnung erstellen
                                </button>
                            </div>

                            <!-- Filter -->
                            <div class="tix-filter-row">
                                <div class="tix-filter-field">
                                    <label>Promoter</label>
                                    <select id="tix-assign-filter-promoter">
                                        <option value="">Alle Promoter</option>
                                    </select>
                                </div>
                                <div class="tix-filter-field">
                                    <label>Event</label>
                                    <select id="tix-assign-filter-event">
                                        <option value="">Alle Events</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Inline-Formular -->
                            <div class="tix-inline-form" id="tix-assign-form" style="display:none;">
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-links"></span>
                                        <h3>Neue Zuordnung erstellen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-form-grid">
                                            <div class="tix-form-field">
                                                <label>Promoter</label>
                                                <select id="tix-af-promoter"></select>
                                            </div>
                                            <div class="tix-form-field">
                                                <label>Event</label>
                                                <select id="tix-af-event"></select>
                                            </div>
                                            <div class="tix-form-field">
                                                <label>Provision Typ</label>
                                                <select id="tix-af-commission-type">
                                                    <option value="percent">Prozent (%)</option>
                                                    <option value="fixed">Festbetrag (&euro;)</option>
                                                </select>
                                            </div>
                                            <div class="tix-form-field">
                                                <label>Provision Wert</label>
                                                <input type="number" id="tix-af-commission-value" step="0.01" min="0" placeholder="z.B. 10">
                                            </div>
                                            <div class="tix-form-field">
                                                <label>Rabatt Typ</label>
                                                <select id="tix-af-discount-type">
                                                    <option value="none">Kein Rabatt</option>
                                                    <option value="percent">Prozent (%)</option>
                                                    <option value="fixed">Festbetrag (&euro;)</option>
                                                </select>
                                            </div>
                                            <div class="tix-form-field">
                                                <label>Rabatt Wert</label>
                                                <input type="number" id="tix-af-discount-value" step="0.01" min="0" placeholder="z.B. 5">
                                            </div>
                                            <div class="tix-form-field tix-form-field-full">
                                                <label>Promo-Code</label>
                                                <input type="text" id="tix-af-promo-code" placeholder="z.B. SOMMER2026 (optional, erstellt WC-Coupon)">
                                            </div>
                                        </div>
                                        <div class="tix-form-actions">
                                            <button type="button" class="button button-primary" id="tix-assign-save-btn">Zuordnung erstellen</button>
                                            <button type="button" class="button" id="tix-assign-cancel-btn">Abbrechen</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Tabelle -->
                            <div class="tix-card" style="margin-top:16px;">
                                <div class="tix-card-body" style="padding:0;overflow-x:auto;">
                                    <table class="tix-promo-table" id="tix-assign-table">
                                        <thead>
                                            <tr>
                                                <th>Promoter</th>
                                                <th>Event</th>
                                                <th>Provision</th>
                                                <th>Rabatt</th>
                                                <th>Promo-Code</th>
                                                <th>Referral-Link</th>
                                                <th>Status</th>
                                                <th>Aktionen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td colspan="8" class="tix-loading"><div class="tix-spinner"></div></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════
                             TAB 3: Provisionen
                             ═══════════════════════════════════════ -->
                        <div class="tix-pane" data-pane="commissions">

                            <div class="tix-pane-header">
                                <h2>Provisionen</h2>
                            </div>

                            <!-- Filter -->
                            <div class="tix-filter-row">
                                <div class="tix-filter-field">
                                    <label>Promoter</label>
                                    <select id="tix-comm-filter-promoter">
                                        <option value="">Alle Promoter</option>
                                    </select>
                                </div>
                                <div class="tix-filter-field">
                                    <label>Event</label>
                                    <select id="tix-comm-filter-event">
                                        <option value="">Alle Events</option>
                                    </select>
                                </div>
                                <div class="tix-filter-field">
                                    <label>Zeitraum von</label>
                                    <input type="date" id="tix-comm-filter-from">
                                </div>
                                <div class="tix-filter-field">
                                    <label>Zeitraum bis</label>
                                    <input type="date" id="tix-comm-filter-to">
                                </div>
                                <div class="tix-filter-field">
                                    <label>Status</label>
                                    <select id="tix-comm-filter-status">
                                        <option value="">Alle</option>
                                        <option value="pending">Ausstehend</option>
                                        <option value="approved">Genehmigt</option>
                                        <option value="paid">Bezahlt</option>
                                        <option value="cancelled">Storniert</option>
                                    </select>
                                </div>
                                <div class="tix-filter-field tix-filter-actions">
                                    <button type="button" class="button" id="tix-comm-filter-btn">
                                        <span class="dashicons dashicons-search"></span> Filtern
                                    </button>
                                </div>
                            </div>

                            <!-- Tabelle -->
                            <div class="tix-card" style="margin-top:16px;">
                                <div class="tix-card-body" style="padding:0;overflow-x:auto;">
                                    <table class="tix-promo-table" id="tix-comm-table">
                                        <thead>
                                            <tr>
                                                <th>Datum</th>
                                                <th>Promoter</th>
                                                <th>Event</th>
                                                <th>Bestellung</th>
                                                <th>Tickets</th>
                                                <th>Umsatz</th>
                                                <th>Provision</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td colspan="8" class="tix-loading"><div class="tix-spinner"></div></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════
                             TAB 4: Auszahlungen
                             ═══════════════════════════════════════ -->
                        <div class="tix-pane" data-pane="payouts">

                            <div class="tix-pane-header">
                                <h2>Auszahlungen</h2>
                                <div class="tix-pane-header-actions">
                                    <button type="button" class="button button-primary" id="tix-payout-add-btn">
                                        <span class="dashicons dashicons-plus-alt2"></span> Auszahlung erstellen
                                    </button>
                                    <a href="#" class="button" id="tix-payout-csv-btn">
                                        <span class="dashicons dashicons-download"></span> CSV Export
                                    </a>
                                </div>
                            </div>

                            <!-- Filter -->
                            <div class="tix-filter-row">
                                <div class="tix-filter-field">
                                    <label>Promoter</label>
                                    <select id="tix-payout-filter-promoter">
                                        <option value="">Alle Promoter</option>
                                    </select>
                                </div>
                                <div class="tix-filter-field">
                                    <label>Status</label>
                                    <select id="tix-payout-filter-status">
                                        <option value="">Alle</option>
                                        <option value="pending">Ausstehend</option>
                                        <option value="paid">Bezahlt</option>
                                        <option value="cancelled">Storniert</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Inline-Formular -->
                            <div class="tix-inline-form" id="tix-payout-form" style="display:none;">
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-money-alt"></span>
                                        <h3>Neue Auszahlung erstellen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-form-grid">
                                            <div class="tix-form-field">
                                                <label>Promoter</label>
                                                <select id="tix-payf-promoter"></select>
                                            </div>
                                            <div class="tix-form-field">
                                                <label>Zeitraum von</label>
                                                <input type="date" id="tix-payf-from">
                                            </div>
                                            <div class="tix-form-field">
                                                <label>Zeitraum bis</label>
                                                <input type="date" id="tix-payf-to">
                                            </div>
                                        </div>
                                        <div class="tix-form-actions">
                                            <button type="button" class="button button-primary" id="tix-payout-save-btn">Auszahlung erstellen</button>
                                            <button type="button" class="button" id="tix-payout-cancel-btn">Abbrechen</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Tabelle -->
                            <div class="tix-card" style="margin-top:16px;">
                                <div class="tix-card-body" style="padding:0;overflow-x:auto;">
                                    <table class="tix-promo-table" id="tix-payout-table">
                                        <thead>
                                            <tr>
                                                <th>Zeitraum</th>
                                                <th>Promoter</th>
                                                <th>Umsatz</th>
                                                <th>Provision</th>
                                                <th>Anzahl</th>
                                                <th>Status</th>
                                                <th>Bezahlt am</th>
                                                <th>Aktionen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td colspan="8" class="tix-loading"><div class="tix-spinner"></div></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════
                             TAB 5: Statistiken
                             ═══════════════════════════════════════ -->
                        <div class="tix-pane" data-pane="stats">

                            <div class="tix-pane-header">
                                <h2>Promoter-Statistiken</h2>
                            </div>

                            <!-- KPIs -->
                            <div class="tix-stats-kpi" id="tix-promo-stats-kpi">
                                <div class="tix-loading"><div class="tix-spinner"></div></div>
                            </div>

                            <!-- Chart -->
                            <div class="tix-card" style="margin-top:16px;">
                                <div class="tix-card-header">
                                    <span class="dashicons dashicons-chart-bar"></span>
                                    <h3>Top-Promoter</h3>
                                </div>
                                <div class="tix-card-body">
                                    <canvas id="tix-promo-chart-top" height="300"></canvas>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.tix-content -->
                </div><!-- /.tix-app -->
            </div><!-- /.tix-settings-grid -->
        </div><!-- /.wrap -->
        <?php
    }
}
