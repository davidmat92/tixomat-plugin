<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Demo_Access – Demo-Studio (demo-studio.evendis.de) mit Zugängen für Vertriebler und Interessenten (1.38.664,
 * Vertrag uebergaben/vertrag-demo-studio.md, nur Mehr-Veranstalter-Modus).
 *
 *  - Interessent = WP-Rolle tix_demo (+ tix_organizer, Team-Admin des Beispiel-Clubs wie tix_sales). Ablauf 14 Tage,
 *    einmal +14, sperrbar; danach keine Anmeldung mehr, 7 Tage später wird das Konto gelöscht.
 *  - Vertriebler (tix_sales) legen Interessenten selbst an (bis 20 aktive), Vertriebler legt nur der Admin an.
 *  - Anmeldung der Interessenten per Link (POST /public/demo-access/link → Mail, /confirm → Geräte-Token).
 *  - Ein gemeinsamer Beispiel-Club für alle: Testkäufe ANDERER Zugänge sehen Demo-Zugänge nur verkürzt
 *    („M. M***“, E-Mail maskiert), die eigenen voll. Mitteilungen über Testkäufe nur an den eigenen Zugang.
 *  - Kasse: Events des Beispiel-Clubs nur mit Zahlart „demo“ (kein Zahlungsanbieter), echte Zahlarten gesperrt.
 */
class TIX_Demo_Access {

    const NS = 'tixomat/v1';
    const ROLE = 'tix_demo';
    const DAYS = 14;
    const MAX_ACTIVE = 20;      // aktive Interessenten je Vertriebler
    const LINK_TTL = 1800;      // Anmelde-Link 30 min
    const INVITE_TTL = 7 * DAY_IN_SECONDS;
    const GRACE = 7 * DAY_IN_SECONDS; // Löschen 7 Tage nach Ablauf
    const OPT_STUDIO = 'tix_demo_studio_url';
    const OPT_HOME = 'tix_demo_home_url';
    const META_ORDER = '_tix_demo_access';
    const META_MODEL = '_tix_demo_model';
    const OPT_PLATFORM_HOME = 'tix_demo_platform_url';
    /** Modell (User 06.11.): platform = Provisionskunde (Tickets über evendis, 2,5 % + 0,59 € je Ticket), self = selbstgehostet (0 € je Ticket) */
    const MODELS = ['platform', 'self'];

    private static $foreign = null;

    public static function init() {
        add_action('init', [__CLASS__, 'ensure_role'], 26);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('determine_current_user', [__CLASS__, 'gate'], 40);
        add_filter('wp_authenticate_user', [__CLASS__, 'gate_password'], 20);
        add_filter('rest_post_dispatch', [__CLASS__, 'mask_response'], 50, 3);
        add_filter('tix_staff_push_recipients', [__CLASS__, 'push_recipients'], 10, 3);
        add_action('tix_order_completed', [__CLASS__, 'on_order_completed'], 40);
        add_action('tix_demo_access_cleanup', [__CLASS__, 'cleanup']);
        add_action('init', function () {
            if (!wp_next_scheduled('tix_demo_access_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tix_demo_access_cleanup');
        }, 30);
    }

    public static function multi() {
        return class_exists('TIX_Sales') && TIX_Sales::multi();
    }

    public static function ensure_role() {
        if (!get_role(self::ROLE)) add_role(self::ROLE, 'Demo-Zugang', ['read' => true, 'tix_app_pos' => true]);
    }

    // ═══════════════ Wer ist was? ═══════════════

    /** Interessent (tix_demo), nie Admin */
    public static function is_prospect($user = null) {
        $user = $user instanceof WP_User ? $user : ($user ? get_userdata(intval($user)) : wp_get_current_user());
        return $user && $user->ID && in_array(self::ROLE, (array) $user->roles, true) && !$user->has_cap('manage_options');
    }

    /** Demo-Zugang im weiteren Sinn: Interessent oder Vertriebler */
    public static function is_demo_user($user = null) {
        return self::is_prospect($user) || (class_exists('TIX_Sales') && TIX_Sales::is_sales_user($user instanceof WP_User ? $user : ($user ? get_userdata(intval($user)) : null)));
    }

    public static function expires($uid) {
        return intval(get_user_meta($uid, '_tix_demo_expires', true));
    }

    /** active | expiring | expired | blocked */
    public static function status($uid) {
        if (get_user_meta($uid, '_tix_demo_blocked', true)) return 'blocked';
        $exp = self::expires($uid);
        if ($exp && $exp <= time()) return 'expired';
        if ($exp && $exp - time() < 3 * DAY_IN_SECONDS) return 'expiring';
        return 'active';
    }

    public static function usable($uid) {
        return in_array(self::status($uid), ['active', 'expiring'], true);
    }

    public static function studio_url($path = '/') {
        $b = rtrim((string) get_option(self::OPT_STUDIO, 'https://demo-studio.evendis.de'), '/');
        return $b . '/' . ltrim($path, '/');
    }

    /** Testkauf: selbstgehostet → Demo-Homepage (demo.evendis.de), Provisionskunde → Veranstalter-Seite auf evendis.de (/demo/) */
    public static function home_url($code = '', $model = 'self') {
        $b = $model === 'platform'
            ? rtrim((string) get_option(self::OPT_PLATFORM_HOME, 'https://evendis.de/demo'), '/') . '/'
            : rtrim((string) get_option(self::OPT_HOME, 'https://demo.evendis.de'), '/') . '/';
        return $code !== '' ? $b . '?demo=' . rawurlencode($code) : $b;
    }

    /** Modell des Zugangs (Vorgabe Provisionskunde) */
    public static function model($uid) {
        $m = (string) get_user_meta(intval($uid), self::META_MODEL, true);
        return in_array($m, self::MODELS, true) ? $m : 'platform';
    }

    private static function clean_model($v) {
        $v = (string) $v;
        return in_array($v, self::MODELS, true) ? $v : '';
    }

    /** Kurzer Code je Zugang (Zuordnung der Testkäufe von der Demo-Homepage) */
    public static function code($uid) {
        $c = (string) get_user_meta($uid, '_tix_demo_code', true);
        if ($c === '') {
            $c = strtolower(wp_generate_password(10, false, false));
            update_user_meta($uid, '_tix_demo_code', $c);
        }
        return $c;
    }

    public static function uid_for_code($code) {
        $code = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $code));
        if (strlen($code) < 6) return 0;
        $u = get_users(['meta_key' => '_tix_demo_code', 'meta_value' => $code, 'number' => 1, 'fields' => 'ids']);
        return $u ? intval($u[0]) : 0;
    }

    // ═══════════════ Anmeldung sperren ═══════════════

    /** Abgelaufene bzw. gesperrte Interessenten gelten als nicht angemeldet (Token bleibt wirkungslos). */
    public static function gate($user_id) {
        if (!$user_id) return $user_id;
        $u = get_userdata(intval($user_id));
        if (!$u || !in_array(self::ROLE, (array) $u->roles, true) || $u->has_cap('manage_options')) return $user_id;
        if (!self::usable($u->ID)) return 0;
        // zuletzt aktiv (höchstens alle 5 Minuten schreiben)
        $last = intval(get_user_meta($u->ID, '_tix_demo_last_seen', true));
        if (time() - $last > 300) update_user_meta($u->ID, '_tix_demo_last_seen', time());
        return $user_id;
    }

    public static function gate_password($user) {
        if ($user instanceof WP_User && in_array(self::ROLE, (array) $user->roles, true) && !self::usable($user->ID)) {
            return new WP_Error('tix_demo_expired', 'Dieser Demo-Zugang ist abgelaufen oder gesperrt.');
        }
        return $user;
    }

    /** Für /me: null bei normalen Konten */
    public static function me_payload(WP_User $user) {
        if (!self::multi()) return null;
        $prospect = self::is_prospect($user);
        $sales = class_exists('TIX_Sales') && TIX_Sales::is_sales_user($user);
        if (!$prospect && !$sales) return null;
        $exp = $prospect ? self::expires($user->ID) : 0;
        return [
            'kind'       => $prospect ? 'prospect' : 'sales',
            'expires_at' => $exp ? wp_date('c', $exp) : null,
            'extended'   => (bool) get_user_meta($user->ID, '_tix_demo_extended', true),
            'can_reset'  => !$prospect,
            'can_manage' => !$prospect,
            // Modell (06.11.): Sicht im Demo-Studio; Vertriebler schalten selbst um (POST /demo-access/view)
            'model'      => self::model($user->ID),
            'can_switch' => !$prospect,
            'home'       => self::home_url(self::code($user->ID), self::model($user->ID)),
            'home_self'  => self::home_url(self::code($user->ID), 'self'),
            'home_platform' => self::home_url(self::code($user->ID), 'platform'),
            'reset_at'   => class_exists('TIX_Demo') ? wp_date('c', TIX_Demo::next_reset_ts()) : null,
            // eigene Testkäufe seit dem letzten Zurücksetzen (Karte „Testkauf ausprobieren“ im Studio)
            'test_orders' => self::test_orders($user->ID, $user->user_email),
        ];
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $staff = function () {
            if (!self::multi()) return new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]);
            if (current_user_can('manage_options') || (class_exists('TIX_Sales') && TIX_Sales::is_sales_user())) return true;
            return new WP_Error('rest_forbidden', 'Nur für den Vertrieb.', ['status' => 403]);
        };
        $public = function () { return self::multi() ? true : new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]); };
        register_rest_route(self::NS, '/demo-access', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $staff],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'], 'permission_callback' => $staff],
        ]);
        // Vertriebler: eigene Sicht umschalten (Provisionskunde ↔ selbstgehostet)
        register_rest_route(self::NS, '/demo-access/view', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_view'], 'permission_callback' => $staff]);
        register_rest_route(self::NS, '/demo-access/reset', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_reset'], 'permission_callback' => $staff]);
        register_rest_route(self::NS, '/demo-access/(?P<id>\d+)', [
            ['methods' => 'PATCH,POST', 'callback' => [__CLASS__, 'rest_update'], 'permission_callback' => $staff],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $staff],
        ]);
        register_rest_route(self::NS, '/demo-access/(?P<id>\d+)/link', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_resend'], 'permission_callback' => $staff]);
        register_rest_route(self::NS, '/public/demo-access/link', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_link'], 'permission_callback' => $public]);
        register_rest_route(self::NS, '/public/demo-access/confirm', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_confirm'], 'permission_callback' => $public]);
    }

    private static function out($data, $status = 200) {
        $r = new WP_REST_Response($data, $status);
        $r->header('Cache-Control', 'no-store');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    private static function is_admin_caller() {
        return current_user_can('manage_options');
    }

    /** Darf der Aufrufer diesen Zugang verwalten? Admin: alle; Vertriebler: eigene Interessenten. */
    private static function can_manage(WP_User $u) {
        if (self::is_admin_caller()) return self::is_prospect($u) || (class_exists('TIX_Sales') && TIX_Sales::is_sales_user($u));
        return self::is_prospect($u) && intval(get_user_meta($u->ID, '_tix_demo_owner', true)) === get_current_user_id();
    }

    private static function test_orders($uid, $email, $days = 0) {
        global $wpdb;
        $o = $wpdb->prefix . 'tix_orders';
        $since = $days ? $wpdb->prepare(' AND o.date_created >= %s', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)) : '';
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $o o WHERE o.payment_method = 'demo' AND o.status IN ('completed','processing')$since AND (o.billing_email = %s OR o.id IN (SELECT pm.post_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = %s AND pm.meta_value = %s))",
            (string) $email, self::META_ORDER, (string) intval($uid)
        )));
    }

    public static function row(WP_User $u) {
        $prospect = self::is_prospect($u);
        $exp = $prospect ? self::expires($u->ID) : 0;
        $owner = intval(get_user_meta($u->ID, '_tix_demo_owner', true));
        $ou = $owner ? get_userdata($owner) : null;
        $last = intval(get_user_meta($u->ID, '_tix_demo_last_seen', true));
        $created = intval(get_user_meta($u->ID, '_tix_demo_created', true)) ?: strtotime($u->user_registered . ' UTC');
        return [
            'id'          => intval($u->ID),
            'kind'        => $prospect ? 'prospect' : 'sales',
            'name'        => (string) $u->display_name,
            'email'       => (string) $u->user_email,
            'company'     => (string) get_user_meta($u->ID, '_tix_demo_company', true),
            'created_at'  => $created ? wp_date('c', $created) : null,
            'expires_at'  => $exp ? wp_date('c', $exp) : null,
            'extended'    => (bool) get_user_meta($u->ID, '_tix_demo_extended', true),
            'can_extend'  => $prospect && !get_user_meta($u->ID, '_tix_demo_extended', true),
            'status'      => $prospect ? self::status($u->ID) : 'active',
            'owner'       => $ou ? ['id' => intval($ou->ID), 'name' => (string) $ou->display_name] : null,
            'last_seen'   => $last ? wp_date('c', $last) : null,
            'test_orders' => self::test_orders($u->ID, $u->user_email),
            'model'       => self::model($u->ID),
            'home'        => self::home_url(self::code($u->ID), self::model($u->ID)),
        ];
    }

    /** GET /demo-access?status=&kind=&q= */
    public static function rest_list(WP_REST_Request $req) {
        $admin = self::is_admin_caller();
        $args = ['role__in' => $admin ? [self::ROLE, 'tix_sales'] : [self::ROLE], 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500];
        if (!$admin) $args['meta_query'] = [['key' => '_tix_demo_owner', 'value' => (string) get_current_user_id()]];
        $rows = array_map([__CLASS__, 'row'], array_filter(get_users($args), function ($u) { return !$u->has_cap('manage_options'); }));
        $kind = (string) $req->get_param('kind');
        $model = self::clean_model($req->get_param('model'));
        $st = (string) $req->get_param('status');
        $q = mb_strtolower(trim((string) $req->get_param('q')));
        $all = $rows;
        $rows = array_values(array_filter($rows, function ($r) use ($kind, $model, $st, $q) {
            if ($kind !== '' && $r['kind'] !== $kind) return false;
            if ($model !== '' && $r['model'] !== $model) return false;
            if ($st === 'active' && !in_array($r['status'], ['active', 'expiring'], true)) return false;
            if (in_array($st, ['expired', 'blocked'], true) && $r['status'] !== $st) return false;
            if ($q !== '' && mb_strpos(mb_strtolower($r['name'] . ' ' . $r['email'] . ' ' . $r['company']), $q) === false) return false;
            return true;
        }));
        $week = time() - 7 * DAY_IN_SECONDS;
        $active_own = self::active_count(get_current_user_id());
        return self::out([
            'ok' => true,
            'accesses' => $rows,
            'summary' => [
                'active'      => count(array_filter($all, function ($r) { return in_array($r['status'], ['active', 'expiring'], true); })),
                'seen_week'   => count(array_filter($all, function ($r) use ($week) { return $r['last_seen'] && strtotime($r['last_seen']) >= $week; })),
                'test_orders' => array_sum(array_column($all, 'test_orders')),
                'platform'    => count(array_filter($all, function ($r) { return $r['model'] === 'platform'; })),
                'self'        => count(array_filter($all, function ($r) { return $r['model'] === 'self'; })),
            ],
            'models' => self::models_info(),
            'limits' => ['days' => self::DAYS, 'max_active' => $admin ? null : self::MAX_ACTIVE, 'active_own' => $active_own, 'can_create_sales' => $admin],
            'studio' => self::studio_url('/'),
            'home' => self::home_url(),
            'reset_at' => class_exists('TIX_Demo') ? wp_date('c', TIX_Demo::next_reset_ts()) : null,
            'last_reset' => ($t = (string) get_option('tix_demo_reset_at', '')) !== '' ? wp_date('c', strtotime($t . ' ' . wp_timezone_string()) ?: time()) : null,
        ]);
    }

    private static function active_count($owner) {
        $n = 0;
        foreach (get_users(['role' => self::ROLE, 'fields' => 'ids', 'meta_query' => [['key' => '_tix_demo_owner', 'value' => (string) intval($owner)]]]) as $uid) {
            if (self::usable(intval($uid))) $n++;
        }
        return $n;
    }

    /** POST /demo-access {kind: prospect|sales, name, email, company?, invite?: true} → 201 {ok, access, invite_sent} */
    public static function rest_create(WP_REST_Request $req) {
        $kind = (string) ($req->get_param('kind') ?: 'prospect');
        if ($kind === 'sales') {
            if (!self::is_admin_caller()) return new WP_Error('rest_forbidden', 'Vertriebler legt nur der Admin an.', ['status' => 403]);
            $r = TIX_Sales::rest_create($req);
            if (is_wp_error($r)) return $r;
            $d = $r->get_data();
            $u = get_userdata(intval($d['user']['id'] ?? 0));
            if ($u && ($c = trim(sanitize_text_field((string) $req->get_param('company')))) !== '') update_user_meta($u->ID, '_tix_demo_company', mb_substr($c, 0, 80));
            if ($u && ($m = self::clean_model($req->get_param('model'))) !== '') update_user_meta($u->ID, self::META_MODEL, $m);
            return self::out(['ok' => true, 'access' => $u ? self::row($u) : null, 'invite_sent' => !empty($d['invite_sent'])], 201);
        }
        if ($kind !== 'prospect') return new WP_Error('tix_invalid_field', 'Unbekannte Art.', ['status' => 400, 'field' => 'kind']);
        $model = self::clean_model($req->get_param('model'));
        if ($model === '') return new WP_Error('tix_invalid_field', 'Bitte das Modell wählen: Provisionskunde oder selbstgehostet.', ['status' => 400, 'field' => 'model']);
        $email = sanitize_email((string) $req->get_param('email'));
        $name = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 80);
        $company = mb_substr(trim(sanitize_text_field((string) $req->get_param('company'))), 0, 80);
        if ($name === '') return new WP_Error('tix_invalid_field', 'Bitte einen Namen angeben.', ['status' => 400, 'field' => 'name']);
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail angeben.', ['status' => 400, 'field' => 'email']);
        if (!self::is_admin_caller() && self::active_count(get_current_user_id()) >= self::MAX_ACTIVE) {
            return new WP_Error('tix_demo_limit', 'Du hast schon ' . self::MAX_ACTIVE . ' aktive Demo-Zugänge. Bitte zuerst einen sperren oder löschen.', ['status' => 409]);
        }
        if (get_user_by('email', $email)) return new WP_Error('tix_invalid_field', 'Zu dieser E-Mail gibt es schon ein Konto. Bitte eine andere Adresse nehmen.', ['status' => 409, 'field' => 'email']);
        if (!TIX_Sales::demo_id() && class_exists('TIX_Demo')) TIX_Demo::ensure_organizer();
        $login = 'demo_' . (sanitize_user(strtok($email, '@'), true) ?: 'zugang');
        $base = $login; $i = 1;
        while (username_exists($login)) $login = $base . (++$i);
        $uid = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'display_name' => $name, 'first_name' => strtok($name, ' '), 'user_pass' => wp_generate_password(32), 'role' => self::ROLE]);
        if (is_wp_error($uid)) return new WP_Error('tix_invalid_field', $uid->get_error_message(), ['status' => 400, 'field' => 'email']);
        $u = get_userdata($uid);
        $u->add_role('tix_organizer');
        update_user_meta($uid, '_tix_team_organizer_id', TIX_Sales::demo_id());
        update_user_meta($uid, '_tix_demo_owner', get_current_user_id());
        update_user_meta($uid, '_tix_demo_created', time());
        update_user_meta($uid, '_tix_demo_expires', time() + self::DAYS * DAY_IN_SECONDS);
        update_user_meta($uid, self::META_MODEL, $model);
        if ($company !== '') update_user_meta($uid, '_tix_demo_company', $company);
        self::code($uid);
        $sent = $req->get_param('invite') === null || filter_var($req->get_param('invite'), FILTER_VALIDATE_BOOLEAN) ? self::send_link(get_userdata($uid), true) : false;
        return self::out(['ok' => true, 'access' => self::row(get_userdata($uid)), 'invite_sent' => $sent], 201);
    }

    /** PATCH /demo-access/{id} {extend?: true, blocked?: bool, name?, company?} */
    public static function rest_update(WP_REST_Request $req) {
        $u = get_userdata(absint($req['id']));
        if (!$u || !self::can_manage($u)) return new WP_Error('tix_not_found', 'Demo-Zugang nicht gefunden.', ['status' => 404]);
        $prospect = self::is_prospect($u);
        if ($req->get_param('extend') !== null && filter_var($req->get_param('extend'), FILTER_VALIDATE_BOOLEAN)) {
            if (!$prospect) return new WP_Error('tix_invalid_field', 'Vertriebs-Zugänge laufen nicht ab.', ['status' => 400, 'field' => 'extend']);
            if (get_user_meta($u->ID, '_tix_demo_extended', true)) return new WP_Error('tix_demo_extended', 'Der Zugang wurde schon einmal verlängert.', ['status' => 409]);
            update_user_meta($u->ID, '_tix_demo_expires', max(time(), self::expires($u->ID)) + self::DAYS * DAY_IN_SECONDS);
            update_user_meta($u->ID, '_tix_demo_extended', 1);
        }
        if ($req->get_param('blocked') !== null) {
            if (!$prospect) return new WP_Error('tix_invalid_field', 'Vertriebs-Zugänge bitte löschen statt sperren.', ['status' => 400, 'field' => 'blocked']);
            if (filter_var($req->get_param('blocked'), FILTER_VALIDATE_BOOLEAN)) {
                update_user_meta($u->ID, '_tix_demo_blocked', 1);
                delete_user_meta($u->ID, '_tix_app_tokens');
                delete_user_meta($u->ID, '_tix_demo_link');
            } else {
                delete_user_meta($u->ID, '_tix_demo_blocked');
            }
        }
        if ($req->get_param('model') !== null) {
            $m = self::clean_model($req->get_param('model'));
            if ($m === '') return new WP_Error('tix_invalid_field', 'Unbekanntes Modell.', ['status' => 400, 'field' => 'model']);
            update_user_meta($u->ID, self::META_MODEL, $m);
        }
        if (($n = trim(sanitize_text_field((string) $req->get_param('name')))) !== '') wp_update_user(['ID' => $u->ID, 'display_name' => mb_substr($n, 0, 80)]);
        if ($req->get_param('company') !== null) update_user_meta($u->ID, '_tix_demo_company', mb_substr(trim(sanitize_text_field((string) $req->get_param('company'))), 0, 80));
        return self::out(['ok' => true, 'access' => self::row(get_userdata($u->ID))]);
    }

    /** POST /demo-access/{id}/link – Einladung bzw. Anmelde-Link neu senden */
    public static function rest_resend(WP_REST_Request $req) {
        $u = get_userdata(absint($req['id']));
        if (!$u || !self::can_manage($u)) return new WP_Error('tix_not_found', 'Demo-Zugang nicht gefunden.', ['status' => 404]);
        if (!self::is_prospect($u)) return new WP_Error('tix_invalid_field', 'Vertriebler melden sich mit Passwort an.', ['status' => 400]);
        if (!self::usable($u->ID)) return new WP_Error('tix_demo_expired', 'Der Zugang ist abgelaufen oder gesperrt.', ['status' => 409]);
        return self::out(['ok' => true, 'sent' => self::send_link($u, true)]);
    }

    /** DELETE /demo-access/{id} */
    public static function rest_delete(WP_REST_Request $req) {
        $u = get_userdata(absint($req['id']));
        if (!$u || !self::can_manage($u)) return new WP_Error('tix_not_found', 'Demo-Zugang nicht gefunden.', ['status' => 404]);
        if (!self::is_prospect($u)) return TIX_Sales::rest_delete($req);
        self::delete_prospect($u->ID);
        return self::out(['ok' => true, 'deleted' => true]);
    }

    private static function delete_prospect($uid) {
        if (!function_exists('wp_delete_user')) require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user(intval($uid));
    }

    /** POST /demo-access/view {model} – eigene Sicht (Vertriebler, Admin) → {ok, demo_access} */
    public static function rest_view(WP_REST_Request $req) {
        $m = self::clean_model($req->get_param('model'));
        if ($m === '') return new WP_Error('tix_invalid_field', 'Unbekanntes Modell.', ['status' => 400, 'field' => 'model']);
        $u = wp_get_current_user();
        update_user_meta($u->ID, self::META_MODEL, $m);
        return self::out(['ok' => true, 'model' => $m, 'demo_access' => self::me_payload($u)]);
    }

    /** Modelle mit Preisen wie veranstalter.evendis.de/preise/ (Anzeige im Studio) */
    public static function models_info() {
        return [
            ['key' => 'platform', 'label' => 'Provisionskunde', 'price' => '2,5 % + 0,59 € je Ticket', 'text' => 'Tickets über evendis: Veranstalter-Seite auf evendis.de, Gebühr, Abrechnung und Auszahlung durch evendis.'],
            ['key' => 'self', 'label' => 'Selbstgehostet', 'price' => '0 € je Ticket', 'text' => 'Eigener Shop mit Lizenz: eigene Website und Domain, ggf. eigene App, eigener Zahlungsanbieter, keine evendis-Gebühr.'],
        ];
    }

    /** POST /demo-access/reset – Beispiel-Club zurücksetzen (Admin und Vertriebler, nie Interessenten) */
    public static function rest_reset(WP_REST_Request $req) {
        if (!class_exists('TIX_Demo')) return new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]);
        $key = 'tix_demo_reset_rl';
        $n = intval(get_transient($key));
        if (!self::is_admin_caller() && $n >= 6) return new WP_Error('tix_rate_limit', 'Heute schon oft zurückgesetzt. Bitte später noch einmal.', ['status' => 429]);
        set_transient($key, $n + 1, 6 * HOUR_IN_SECONDS);
        $r = TIX_Demo::reset(['orders' => 120]);
        if (!empty($r['error'])) return new WP_Error('tix_demo_reset', $r['error'], ['status' => 409]);
        return self::out(['ok' => true] + $r);
    }

    // ═══════════════ Anmelde-Link ═══════════════

    private static function limited($bucket, $max, $window) {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $k = 'tix_dal_' . $bucket . '_' . md5($ip);
        $n = intval(get_transient($k));
        if ($n >= $max) return true;
        set_transient($k, $n + 1, $window);
        return false;
    }

    /** Neuen Link erzeugen und mailen. $invite: Einladung (7 Tage gültig) statt Anmelde-Link (30 min). */
    public static function send_link(WP_User $u, $invite = false) {
        $raw = wp_generate_password(40, false, false);
        $list = array_values(array_filter((array) get_user_meta($u->ID, '_tix_demo_link', true), function ($t) { return is_array($t) && intval($t['exp'] ?? 0) > time(); }));
        $list[] = ['h' => hash('sha256', $raw), 'exp' => time() + ($invite ? self::INVITE_TTL : self::LINK_TTL)];
        update_user_meta($u->ID, '_tix_demo_link', array_slice($list, -5));
        $url = self::studio_url('/anmelden/link/?t=' . rawurlencode($raw));
        if (!class_exists('TIX_Mail_Layout')) return false;
        $exp = self::expires($u->ID);
        $blocks = [
            ['type' => 'text', 'html' => 'Hallo ' . esc_html($u->display_name) . ',<br>' . ($invite
                ? 'du hast einen Zugang zum <b>evendis Demo-Studio</b>. Dort probierst du das ganze Studio mit Beispieldaten aus – inklusive Testkauf auf der Demo-Homepage und Einlass. Nichts davon geht live, es fließt kein Geld.'
                : 'hier ist dein Anmelde-Link für das evendis Demo-Studio. Er gilt 30 Minuten.')],
            ['type' => 'button', 'label' => 'Demo-Studio öffnen', 'url' => $url],
        ];
        if ($exp) $blocks[] = ['type' => 'text', 'html' => 'Dein Zugang gilt bis ' . esc_html(wp_date('j. F Y', $exp)) . '. ' . ($invite ? 'Der Link in dieser Mail gilt 7 Tage; danach forderst du auf der Anmeldeseite einfach einen neuen an.' : '')];
        return (bool) TIX_Mail_Layout::send($u->user_email, 'evendis Demo-Studio – ' . ($invite ? 'dein Zugang' : 'dein Anmelde-Link'), [
            'title' => $invite ? 'Dein Zugang zum Demo-Studio' : 'Dein Anmelde-Link',
            'blocks' => $blocks,
            'kind' => 'account',
        ]);
    }

    /** POST /public/demo-access/link {email} → immer 202 (verrät nicht, ob es den Zugang gibt) */
    public static function rest_link(WP_REST_Request $req) {
        if (self::limited('link', 5, 600)) return new WP_Error('tix_rate_limit', 'Zu viele Versuche. Bitte in ein paar Minuten noch einmal.', ['status' => 429]);
        $email = sanitize_email((string) $req->get_param('email'));
        if (is_email($email)) {
            $u = get_user_by('email', $email);
            if ($u && self::is_prospect($u) && self::usable($u->ID)) {
                $mk = 'tix_dal_mail_' . $u->ID;
                if (intval(get_transient($mk)) < 5) {
                    set_transient($mk, intval(get_transient($mk)) + 1, HOUR_IN_SECONDS);
                    self::send_link($u, false);
                }
            }
        }
        return self::out(['ok' => true], 202);
    }

    /** POST /public/demo-access/confirm {token, device?} → {ok, token, user, demo_access} */
    public static function rest_confirm(WP_REST_Request $req) {
        if (self::limited('confirm', 20, 600)) return new WP_Error('tix_rate_limit', 'Zu viele Versuche. Bitte in ein paar Minuten noch einmal.', ['status' => 429]);
        $raw = (string) $req->get_param('token');
        if (strlen($raw) < 20) return new WP_Error('tix_demo_link', 'Der Link ist ungültig.', ['status' => 404]);
        $h = hash('sha256', $raw);
        $hit = null;
        foreach (get_users(['role' => self::ROLE, 'meta_key' => '_tix_demo_link', 'meta_compare' => 'EXISTS', 'number' => 1000]) as $u) {
            foreach ((array) get_user_meta($u->ID, '_tix_demo_link', true) as $i => $t) {
                if (is_array($t) && hash_equals((string) ($t['h'] ?? ''), $h)) { $hit = [$u, $i, intval($t['exp'] ?? 0)]; break 2; }
            }
        }
        if (!$hit) return new WP_Error('tix_demo_link', 'Der Link ist ungültig oder wurde schon benutzt. Fordere auf der Anmeldeseite einfach einen neuen an.', ['status' => 404]);
        list($u, $i, $exp) = $hit;
        $list = (array) get_user_meta($u->ID, '_tix_demo_link', true);
        unset($list[$i]);
        update_user_meta($u->ID, '_tix_demo_link', array_values($list)); // einmal einlösbar
        if ($exp <= time()) return new WP_Error('tix_demo_link_expired', 'Der Link ist abgelaufen. Fordere auf der Anmeldeseite einfach einen neuen an.', ['status' => 410]);
        if (!self::usable($u->ID)) return new WP_Error('tix_demo_expired', 'Dieser Demo-Zugang ist abgelaufen oder gesperrt.', ['status' => 403]);
        $token = TIX_REST_API::issue_app_token($u->ID, (string) ($req->get_param('device') ?: 'Demo-Studio'));
        update_user_meta($u->ID, '_tix_demo_last_seen', time());
        $first = !get_user_meta($u->ID, '_tix_demo_first_login', true);
        if ($first) update_user_meta($u->ID, '_tix_demo_first_login', time());
        return self::out(['ok' => true, 'first' => $first, 'token' => $token, 'user' => ['id' => intval($u->ID), 'name' => (string) $u->display_name, 'email' => (string) $u->user_email], 'demo_access' => self::me_payload($u)]);
    }

    /** Täglich: Interessenten 7 Tage nach Ablauf löschen */
    public static function cleanup() {
        foreach (get_users(['role' => self::ROLE, 'fields' => 'ids', 'meta_key' => '_tix_demo_expires', 'meta_value' => time() - self::GRACE, 'meta_compare' => '<', 'meta_type' => 'NUMERIC']) as $uid) {
            $u = get_userdata(intval($uid));
            if ($u && !$u->has_cap('manage_options') && in_array(self::ROLE, (array) $u->roles, true)) self::delete_prospect($u->ID);
        }
    }

    // ═══════════════ Kasse: Demo-Zahlung ═══════════════

    /** Event des Beispiel-Clubs? (nur Demo Club, nicht Vorschauen) */
    public static function is_demo_event($event_id) {
        if (!class_exists('TIX_Sales') || !TIX_Sales::multi()) return false;
        $oid = intval(get_post_meta(intval($event_id), '_tix_organizer_id', true));
        return $oid > 0 && TIX_Sales::is_demo_organizer($oid);
    }

    public static function method() {
        return ['id' => 'demo', 'title' => 'Demo-Zahlung (kein echtes Geld)', 'icon' => ''];
    }

    /** Grenzen für Testkäufe: 20/IP/Stunde, 300/Tag gesamt → WP_Error|null */
    public static function order_limit_error() {
        if (self::limited('order', 20, HOUR_IN_SECONDS)) return new WP_Error('tix_rate_limit', 'Zu viele Testkäufe. Bitte später noch einmal.', ['status' => 429]);
        $k = 'tix_demo_orders_' . wp_date('Ymd');
        $n = intval(get_option($k, 0));
        if ($n >= 300) return new WP_Error('tix_rate_limit', 'Für heute sind genug Testkäufe da. Morgen geht es weiter.', ['status' => 429]);
        update_option($k, $n + 1, false);
        delete_option('tix_demo_orders_' . wp_date('Ymd', time() - DAY_IN_SECONDS));
        return null;
    }

    /** Testkauf dem Zugang zuordnen: angemeldeter Demo-Zugang oder Code von der Demo-Homepage */
    public static function remember_order($order_id, $code) {
        $uid = 0;
        $me = wp_get_current_user();
        if ($me && $me->ID && self::is_demo_user($me)) $uid = $me->ID;
        if (!$uid && $code) $uid = self::uid_for_code($code);
        if ($uid) update_post_meta(intval($order_id), self::META_ORDER, $uid);
        return $uid;
    }

    // ═══════════════ Mitteilungen ═══════════════

    /** Staff-Pushes des Beispiel-Clubs nie an Demo-Zugänge (sonst sähe jeder die Testkäufe der anderen). */
    public static function push_recipients($uids, $org_ids, $pref) {
        if (!class_exists('TIX_Sales')) return $uids;
        $demo = TIX_Sales::demo_id();
        if (!$demo || !in_array($demo, array_map('intval', (array) $org_ids), true)) return $uids;
        return array_values(array_filter((array) $uids, function ($uid) { return !self::is_demo_user(intval($uid)); }));
    }

    /** Testkauf: Mitteilung nur an den eigenen Zugang */
    public static function on_order_completed($order_id) {
        global $wpdb;
        $pm = (string) $wpdb->get_var($wpdb->prepare("SELECT payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", intval($order_id)));
        if ($pm !== 'demo') return;
        $uid = intval(get_post_meta(intval($order_id), self::META_ORDER, true));
        if (!$uid || !class_exists('TIX_Staff_Push') || !method_exists('TIX_Staff_Push', 'send_to_users')) return;
        $o = class_exists('TIX_Order') ? TIX_Order::get($order_id) : null;
        $n = 0;
        if ($o) foreach ($o->get_items() as $it) $n += intval($it->get_quantity());
        $total = $o ? (float) $o->get_total() : 0.0;
        TIX_Staff_Push::send_to_users([$uid], 'Testkauf eingegangen', ($n === 1 ? '1 Ticket' : $n . ' Tickets') . ' · ' . number_format($total, 2, ',', '.') . ' € (Demo-Zahlung)', ['action' => 'staff:order:' . intval($order_id), 'type' => 'demo_order']);
    }

    // ═══════════════ Fremde Testkäufe verkürzen ═══════════════

    public static function mask_name($first, $last) {
        $f = trim((string) $first);
        $l = trim((string) $last);
        $fi = $f !== '' ? mb_strtoupper(mb_substr($f, 0, 1)) . '.' : '';
        $li = $l !== '' ? mb_strtoupper(mb_substr($l, 0, 1)) . '***' : '';
        return trim($fi . ' ' . $li) ?: 'Gast';
    }

    public static function mask_email($email) {
        $email = (string) $email;
        if (strpos($email, '@') === false) return '***';
        list($local, $domain) = explode('@', $email, 2);
        $parts = explode('.', $domain);
        $tld = count($parts) > 1 ? array_pop($parts) : '';
        $host = implode('.', $parts);
        return mb_substr($local, 0, 1) . '***@' . mb_substr($host, 0, 1) . '***' . ($tld !== '' ? '.' . $tld : '');
    }

    /** Daten fremder Testkäufer für den Betrachter: [ersetzen => durch] (Länge absteigend) */
    public static function foreign_map($viewer) {
        if (self::$foreign !== null && self::$foreign[0] === intval($viewer->ID)) return self::$foreign[1];
        global $wpdb;
        $o = $wpdb->prefix . 'tix_orders';
        $own = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::META_ORDER, (string) intval($viewer->ID))));
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, billing_first_name f, billing_last_name l, billing_email e, billing_phone p, billing_company c, billing_address_1 a FROM $o WHERE payment_method = 'demo' AND billing_email NOT LIKE %s AND billing_email <> %s ORDER BY id DESC LIMIT 2000",
            '%.invalid', (string) $viewer->user_email
        ));
        $map = [];
        // eigene Angaben (Konto + eigene Testkäufe) nie verkürzen – auch wenn ein fremder Käufer gleich heißt
        $mine = [mb_strtolower(trim((string) $viewer->display_name)), mb_strtolower((string) $viewer->user_email)];
        foreach ($rows as $r) if (in_array(intval($r->id), $own, true)) { $mine[] = mb_strtolower(trim($r->f . ' ' . $r->l)); $mine[] = mb_strtolower((string) $r->e); $mine[] = '@l:' . mb_strtolower((string) $r->l); }
        foreach ($rows as $r) {
            if (in_array(intval($r->id), $own, true)) continue;
            $full = trim($r->f . ' ' . $r->l);
            if ($r->e !== '') $map[(string) $r->e] = self::mask_email($r->e);
            if (mb_strlen($full) >= 4 && strpos($full, ' ') !== false) $map[$full] = self::mask_name($r->f, $r->l);
            if (mb_strlen((string) $r->l) >= 3) $map['@L:' . $r->l] = mb_strtoupper(mb_substr($r->l, 0, 1)) . '***';
            if (mb_strlen((string) $r->p) >= 5) $map[(string) $r->p] = '•••';
            if (mb_strlen((string) $r->a) >= 5) $map[(string) $r->a] = '•••';
            if (mb_strlen((string) $r->c) >= 3) $map['@C:' . $r->c] = '•••';
        }
        foreach (array_keys($map) as $k) if (in_array(mb_strtolower($k), $mine, true)) unset($map[$k]);
        uksort($map, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
        self::$foreign = [intval($viewer->ID), $map];
        return $map;
    }

    /** Antworten der Studio-Routen für Demo-Zugänge (nicht Admins): fremde Testkäufer verkürzen. */
    public static function mask_response($response, $server, $request) {
        if (!($response instanceof WP_REST_Response) || !($request instanceof WP_REST_Request) || !self::multi()) return $response;
        $route = (string) $request->get_route();
        if (strpos($route, '/' . self::NS . '/') !== 0) return $response;
        // Zugangs-Verwaltung zeigt Name und E-Mail der Zugänge immer voll (Vertriebler/Admin verwalten sie)
        foreach (['/public/', '/customer/', '/auth/', '/me', '/demo-access'] as $skip) if (strpos($route, '/' . self::NS . $skip) === 0) return $response;
        $viewer = wp_get_current_user();
        if (!$viewer || !$viewer->ID || $viewer->has_cap('manage_options') || !self::is_demo_user($viewer)) return $response;
        $map = self::foreign_map($viewer);
        if (!$map) return $response;
        $response->set_data(self::mask_value($response->get_data(), $map));
        return $response;
    }

    private static $person_keys = ['first_name', 'billing_first_name', 'firstname', 'vorname'];
    private static $last_keys = ['last_name', 'billing_last_name', 'lastname', 'nachname'];
    private static $blank_keys = ['phone', 'billing_phone', 'telefon', 'address', 'address_1', 'billing_address_1', 'address_2', 'billing_address_2', 'street', 'company', 'billing_company', 'postcode', 'billing_postcode', 'zip'];

    public static function mask_value($v, array $map) {
        if (is_string($v)) return self::mask_string($v, $map);
        if (!is_array($v)) {
            if (is_object($v) && !($v instanceof JsonSerializable)) return self::mask_value(json_decode(wp_json_encode($v), true), $map);
            return $v;
        }
        // Objekt mit fremder E-Mail: auch Einzelfelder (Vorname, Nachname, Telefon, Adresse) verkürzen
        $hit = false;
        foreach ($v as $k => $x) {
            if (is_string($x) && isset($map[$x]) && strpos($x, '@') !== false) { $hit = true; break; }
        }
        foreach ($v as $k => $x) {
            if ($hit && is_string($k) && is_string($x) && $x !== '') {
                $lk = strtolower($k);
                if (in_array($lk, self::$person_keys, true)) { $v[$k] = mb_strtoupper(mb_substr($x, 0, 1)) . '.'; continue; }
                if (in_array($lk, self::$last_keys, true)) { $v[$k] = mb_strtoupper(mb_substr($x, 0, 1)) . '***'; continue; }
                if (in_array($lk, self::$blank_keys, true)) { $v[$k] = '•••'; continue; }
            }
            $v[$k] = self::mask_value($x, $map);
        }
        return $v;
    }

    private static function mask_string($s, array $map) {
        if ($s === '') return $s;
        if (isset($map[$s])) return $map[$s];
        if (isset($map['@L:' . $s])) return $map['@L:' . $s];
        if (isset($map['@C:' . $s])) return $map['@C:' . $s];
        foreach ($map as $from => $to) {
            if (strpos($from, '@L:') === 0 || strpos($from, '@C:') === 0) continue;
            // nur ganze Wörter (sonst würde „Max Mustermann2“ zu „M. M***2“)
            if (mb_strlen($from) >= 5 && strpos($s, $from) !== false) $s = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($from, '/') . '(?![\p{L}\p{N}])/u', str_replace(['\\', '$'], ['\\\\', '\\$'], $to), $s) ?? $s;
        }
        return $s;
    }
}

TIX_Demo_Access::init();
