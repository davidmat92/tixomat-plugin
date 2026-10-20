<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Sales – Live-Demo für den Vertrieb (1.38.508, Vertrag uebergaben/vertrag-live-demo.md, nur Mehr-Veranstalter-Modus).
 *
 *  - Sandbox-Veranstalter: Demo Club (Meta _tix_org_demo = 1, Option tix_demo_organizer_id) und Vorschau-Veranstalter
 *    (Meta _tix_org_preview, bis zur Übernahme). Sie stehen nie in öffentlichen Listen, Statistiken, Abrechnungen,
 *    Rechnungen, Datenspiegel (TIX_Sales::is_sandbox_organizer() + Filter tix_sandbox_organizer). Detailseiten bleiben.
 *  - Rolle „Vertrieb“ (tix_sales, zusätzlich tix_organizer): Team-Admin von Demo Club, keine Team-Verwaltung, kein eigener
 *    Veranstalter, nie /platform/*. Anlegen: POST /platform/sales (Admin) bzw. WP-Admin (Zuordnung automatisch).
 *  - Mails an *.invalid gehen nie raus (pre_wp_mail).
 */
class TIX_Sales {

    const NS = 'tixomat/v1';
    const ROLE = 'tix_sales';
    const META_DEMO = '_tix_org_demo';
    const META_PREVIEW = '_tix_org_preview';
    const OPT_DEMO = 'tix_demo_organizer_id';

    private static $cache = [];
    private static $ids = null;

    public static function init() {
        add_action('init', [__CLASS__, 'ensure_role'], 25);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('pre_wp_mail', [__CLASS__, 'skip_invalid_mail'], 5, 2);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'flag_checkin'], 20, 3);
        add_action('set_user_role', [__CLASS__, 'on_role_set'], 10, 3);
        add_action('add_user_role', [__CLASS__, 'on_role_added'], 10, 2);
        add_action('pre_get_posts', [__CLASS__, 'hide_on_website'], 20);
        add_filter('tix_organizer_page_public', [__CLASS__, 'filter_page_public'], 20, 2);
    }

    public static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Sandbox ═══════════════

    public static function demo_id() {
        return intval(get_option(self::OPT_DEMO, 0));
    }

    /** Demo Club oder nicht übernommene Vorschau? Billig (Meta, je Aufruf zwischengespeichert), ohne Netzwerk. */
    public static function is_sandbox_organizer($oid) {
        $oid = intval($oid);
        if ($oid <= 0) return false;
        if (!array_key_exists($oid, self::$cache)) {
            self::$cache[$oid] = (bool) get_post_meta($oid, self::META_DEMO, true) || (bool) get_post_meta($oid, self::META_PREVIEW, true);
        }
        return self::$cache[$oid];
    }

    /** Mit Filter (für eigene Prüfungen im Plugin). */
    public static function sandbox($oid) {
        return (bool) apply_filters('tix_sandbox_organizer', self::is_sandbox_organizer($oid), intval($oid));
    }

    public static function is_demo_organizer($oid) {
        return intval($oid) > 0 && (bool) get_post_meta(intval($oid), self::META_DEMO, true);
    }

    public static function flush() {
        self::$cache = [];
        self::$ids = null;
    }

    /** Alle Sandbox-Veranstalter (IDs). */
    public static function sandbox_ids() {
        if (self::$ids === null) {
            self::$ids = array_map('intval', get_posts(['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
                'meta_query' => ['relation' => 'OR', ['key' => self::META_DEMO, 'compare' => 'EXISTS'], ['key' => self::META_PREVIEW, 'compare' => 'EXISTS']]]));
        }
        return self::$ids;
    }

    public static function is_sandbox_event($event_id) {
        $oid = intval(get_post_meta(intval($event_id), '_tix_organizer_id', true));
        return $oid && self::sandbox($oid);
    }

    /** Bestellung gehört zur Sandbox: Zahlart demo oder eine Position an einem Sandbox-Event. */
    public static function is_sandbox_order($order_id) {
        global $wpdb;
        $order_id = intval($order_id);
        if (!$order_id) return false;
        $pm = (string) $wpdb->get_var($wpdb->prepare("SELECT payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id));
        if ($pm === 'demo') return true;
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT event_id FROM {$wpdb->prefix}tix_order_items WHERE order_id = %d", $order_id)) as $eid) {
            if (self::is_sandbox_event($eid)) return true;
        }
        return false;
    }

    /** meta_query-Klausel: Events ohne Sandbox-Veranstalter (null, wenn es keine gibt). */
    public static function event_clause() {
        $ids = self::sandbox_ids();
        if (!$ids) return null;
        return ['relation' => 'OR', ['key' => '_tix_organizer_id', 'compare' => 'NOT EXISTS'], ['key' => '_tix_organizer_id', 'value' => array_map('strval', $ids), 'compare' => 'NOT IN']];
    }

    /** SQL-Liste für NOT IN (…) bzw. '0'. */
    public static function sql_ids() {
        $ids = self::sandbox_ids();
        return $ids ? implode(',', array_map('intval', $ids)) : '0';
    }

    /** Website-Listen (Shortcodes, Archive): Sandbox-Events ausblenden – nicht im WP-Admin, nicht in REST/Cron. */
    public static function hide_on_website($q) {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return;
        if (!self::multi() || $q->get('tix_include_sandbox') || $q->is_singular()) return;
        $pt = (array) $q->get('post_type');
        if (!in_array('event', $pt, true)) return;
        $c = self::event_clause();
        if (!$c) return;
        $mq = array_filter((array) $q->get('meta_query'), function ($v) { return $v !== '' && $v !== null; });
        $q->set('meta_query', $mq ? ['relation' => 'AND', $mq, $c] : [$c]);
    }

    /** Veranstalter-Seite auf der Website (/veranstalter/<slug>/) für Sandbox nicht öffentlich. */
    public static function filter_page_public($public, $oid = 0) {
        return ($oid && self::sandbox($oid)) ? false : $public;
    }

    // ═══════════════ Mails ═══════════════

    /** Mails nur an *.invalid (Demo, anonymisiert) gar nicht senden. */
    public static function skip_invalid_mail($pre, $atts) {
        if ($pre !== null) return $pre;
        $to = $atts['to'] ?? [];
        $list = is_array($to) ? $to : preg_split('/[,;]/', (string) $to);
        $list = array_filter(array_map('trim', (array) $list));
        if (!$list) return $pre;
        foreach ($list as $addr) {
            if (preg_match('/<([^>]+)>/', $addr, $m)) $addr = $m[1];
            if (!preg_match('/\.invalid$/i', trim($addr))) return $pre;
        }
        return true;
    }

    // ═══════════════ Rolle ═══════════════

    public static function ensure_role() {
        if (!get_role(self::ROLE)) add_role(self::ROLE, 'Vertrieb', ['read' => true, 'tix_app_pos' => true, 'tix_app_wallet_charge' => true]);
    }

    public static function is_sales_user($user = null) {
        $user = $user ?: wp_get_current_user();
        return $user && $user->ID && in_array(self::ROLE, (array) $user->roles, true) && !$user->has_cap('manage_options');
    }

    /** Vertriebler: Team-Admin von Demo Club (zweite Rolle tix_organizer, Team-Zuordnung). */
    public static function attach($uid) {
        $u = get_userdata(intval($uid));
        if (!$u) return;
        if (!in_array(self::ROLE, (array) $u->roles, true)) $u->add_role(self::ROLE);
        if (!in_array('tix_organizer', (array) $u->roles, true)) $u->add_role('tix_organizer');
        $demo = self::demo_id();
        if ($demo) update_user_meta($u->ID, '_tix_team_organizer_id', $demo);
    }

    public static function on_role_set($uid, $role, $old_roles) {
        if ($role === self::ROLE) self::attach($uid);
    }

    public static function on_role_added($uid, $role) {
        if ($role === self::ROLE) self::attach($uid);
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $admin = function () {
            if (!self::multi()) return new WP_Error('rest_no_route', 'Nur im Mehr-Veranstalter-Modus.', ['status' => 404]);
            return current_user_can('manage_options') ? true : new WP_Error('rest_forbidden', 'Nur für Plattform-Admins.', ['status' => 403]);
        };
        register_rest_route(self::NS, '/platform/sales', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $admin],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'], 'permission_callback' => $admin],
        ]);
        register_rest_route(self::NS, '/platform/sales/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $admin]);
    }

    private static function user_row(WP_User $u) {
        $stats = class_exists('TIX_Sales_Previews') && method_exists('TIX_Sales_Previews', 'owner_stats') ? TIX_Sales_Previews::owner_stats($u->ID) : ['total' => 0, 'opened' => 0, 'claimed' => 0];
        $last = get_user_meta($u->ID, '_tix_last_login', true);
        return ['id' => intval($u->ID), 'name' => (string) $u->display_name, 'email' => (string) $u->user_email, 'role' => self::ROLE, 'previews' => $stats,
            'last_login' => $last ? (is_numeric($last) ? wp_date('c', intval($last)) : (string) $last) : null];
    }

    public static function rest_list(WP_REST_Request $req) {
        $users = get_users(['role' => self::ROLE, 'orderby' => 'display_name']);
        return rest_ensure_response(['ok' => true, 'users' => array_map([__CLASS__, 'user_row'], $users)]);
    }

    /** POST /platform/sales {email, name} → 201 {ok, user, invite_sent} */
    public static function rest_create(WP_REST_Request $req) {
        $email = sanitize_email((string) $req->get_param('email'));
        $name = trim(sanitize_text_field((string) $req->get_param('name')));
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail angeben.', ['status' => 400, 'field' => 'email']);
        if ($name === '') return new WP_Error('tix_invalid_field', 'Bitte einen Namen angeben.', ['status' => 400, 'field' => 'name']);
        if (!self::demo_id() && class_exists('TIX_Demo')) TIX_Demo::ensure_organizer();
        $u = get_user_by('email', $email);
        if ($u) {
            if ($u->has_cap('manage_options')) return new WP_Error('tix_invalid_field', 'Admins brauchen keinen Vertriebs-Zugang.', ['status' => 409, 'field' => 'email']);
            $owned = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_tix_org_user_id', 'meta_value' => $u->ID]);
            if ($owned) return new WP_Error('tix_invalid_field', 'Dieses Konto gehört zu einem Veranstalter.', ['status' => 409, 'field' => 'email']);
            $created = false;
        } else {
            $login = sanitize_user(strtok($email, '@'), true) ?: 'vertrieb';
            $base = $login; $i = 1;
            while (username_exists($login)) $login = $base . (++$i);
            $uid = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'display_name' => $name, 'first_name' => strtok($name, ' '), 'user_pass' => wp_generate_password(24), 'role' => self::ROLE]);
            if (is_wp_error($uid)) return new WP_Error('tix_invalid_field', $uid->get_error_message(), ['status' => 400, 'field' => 'email']);
            $u = get_userdata($uid);
            $created = true;
        }
        if (!$created) wp_update_user(['ID' => $u->ID, 'display_name' => $name]);
        self::attach($u->ID);
        $u = get_userdata($u->ID);
        $sent = self::send_invite($u, $created);
        $r = new WP_REST_Response(['ok' => true, 'user' => self::user_row($u), 'invite_sent' => $sent], 201);
        return $r;
    }

    private static function send_invite(WP_User $u, $created) {
        $key = get_password_reset_key($u);
        if (is_wp_error($key)) return false;
        $url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($u->user_login), 'login');
        $studio = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'url') ? (string) TIX_Studio::url('/') : '';
        if (!class_exists('TIX_Mail_Layout')) return false;
        return (bool) TIX_Mail_Layout::send($u->user_email, TIX_Mail_Layout::brand()['name'] . ' – Dein Vertriebs-Zugang', [
            'title' => 'Dein Vertriebs-Zugang',
            'blocks' => [
                ['type' => 'text', 'html' => 'Hallo ' . esc_html($u->display_name) . ',<br>du hast jetzt einen Vertriebs-Zugang. Damit zeigst du Kunden live den Demo Club und erstellst Vorschauen mit ihren eigenen Events.'],
                ['type' => 'button', 'label' => $created ? 'Passwort festlegen' : 'Passwort neu setzen', 'url' => $url],
            ] + ($studio !== '' ? [2 => ['type' => 'text', 'html' => 'Danach meldest du dich im Studio an: <a href="' . esc_url($studio) . '">' . esc_html($studio) . '</a>']] : []),
            'kind' => 'account',
        ]);
    }

    /** DELETE /platform/sales/{id} – Konto entfernen, Vorschauen an den Admin. */
    public static function rest_delete(WP_REST_Request $req) {
        $u = get_userdata(absint($req['id']));
        if (!$u || !in_array(self::ROLE, (array) $u->roles, true)) return new WP_Error('tix_not_found', 'Vertriebs-Konto nicht gefunden.', ['status' => 404]);
        $admin = get_current_user_id();
        if (class_exists('TIX_Sales_Previews') && method_exists('TIX_Sales_Previews', 'reassign')) TIX_Sales_Previews::reassign($u->ID, $admin);
        if (!function_exists('wp_delete_user')) require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($u->ID, $admin);
        return rest_ensure_response(['ok' => true, 'deleted' => true]);
    }

    // ═══════════════ Antworten kennzeichnen ═══════════════

    /** /checkin/*: demo:true, wenn der Einlass zum Demo Club gehört (läuft auch bei internen Aufrufen). */
    public static function flag_checkin($result, $handler, $request) {
        if (!($request instanceof WP_REST_Request) || is_wp_error($result)) return $result;
        if (is_array($result)) $result = rest_ensure_response($result);
        if (!($result instanceof WP_REST_Response)) return $result;
        if (strpos((string) $request->get_route(), '/' . self::NS . '/checkin/') !== 0) return $result;
        $data = $result->get_data();
        if (!is_array($data)) return $result;
        $eid = intval($request->get_param('event_id') ?: ($request['event_id'] ?? 0));
        $oid = $eid ? intval(get_post_meta($eid, '_tix_organizer_id', true)) : (class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0);
        if ($oid && self::is_demo_organizer($oid)) {
            $data['demo'] = true;
            $result->set_data($data);
        }
        return $result;
    }
}

TIX_Sales::init();
