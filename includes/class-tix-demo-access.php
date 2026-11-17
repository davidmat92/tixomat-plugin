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
 *  - Seit 1.38.841 auch auf einer Demo-Instanz „Eigene Website“ (TIX_Demo_Shop, Option tix_demo_instance): gleiche Rollen und
 *    Routen, ohne Modell/Profil, ohne Beispiel-Veranstalter; Zurücksetzen = ganze Instanz; Kasse = „demo“ + Anbieter im Testmodus.
 *  - Seit 1.38.843 Fälle je Zugang (Meta _tix_demo_cases: platform, shop oder beide; ohne Meta = platform): die Plattform führt
 *    Liste, Limit und Laufzeit, der Shop-Teil liegt signiert auf der Instanz (TIX_Demo_Remote). Ein Zugang nur mit „shop“ meldet
 *    sich im Demo-Studio nie an. Ohne `cases` in der Anfrage verhält sich alles wie bisher (ältere Studios).
 *  - Seit 1.38.844 drei Fälle: platform, shop (Instanz demo-shop, ein Event) und club (Instanz demo-club, mehrere Events), beliebig
 *    kombiniert. Je Instanz-Fall ein signierter Teil auf der jeweiligen Instanz; scheitert eine, wird alles zurückgerollt.
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
    /** Modell (User 06.11.): platform = Provisionskunde (Tickets über evendis, 2,9 % + 0,49 € je Ticket), self = selbstgehostet (0 € je Ticket) */
    const MODELS = ['platform', 'self'];
    /** Website-Profil (User 06.11.): club = mehrere Events (Beispiel-Club Ostkai), festival = 1 Event (Beispiel-Festival Seewiese) */
    const META_PROFILE = '_tix_demo_profile';
    const PROFILES = ['club', 'festival'];
    /** Fälle je Zugang (1.38.843): platform = Demo-Studio, shop = Demo-Shop „Eigene Website“ auf der Instanz */
    const META_CASES = '_tix_demo_cases';

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

    /** Demo-Instanz „Eigene Website“ (Einzel-Seite mit Schalter tix_demo_instance, 1.38.841) */
    public static function instance() {
        return class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::on();
    }

    /** Zugänge aktiv: Demo-Studio auf der Plattform oder Demo-Instanz */
    public static function enabled() {
        return self::multi() || self::instance();
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
        if (self::instance()) return rtrim(TIX_Demo_Shop::studio_url(), '/') . '/' . ltrim($path, '/');
        $b = rtrim((string) get_option(self::OPT_STUDIO, 'https://demo-studio.evendis.de'), '/');
        return $b . '/' . ltrim($path, '/');
    }

    /** Testkauf: selbstgehostet → Demo-Homepage (demo.evendis.de/club/ bzw. /festival/), Provisionskunde → evendis.de/demo/ (?profile=festival) */
    public static function home_url($code = '', $model = 'self', $profile = 'club') {
        $q = [];
        if (self::instance()) {
            $b = TIX_Demo_Shop::home_url();
            return $code !== '' ? $b . '?' . http_build_query(['demo' => $code]) : $b;
        }
        if ($model === 'platform') {
            $b = rtrim((string) get_option(self::OPT_PLATFORM_HOME, 'https://evendis.de/demo'), '/') . '/';
            if ($profile === 'festival') $q['profile'] = 'festival';
        } else {
            $b = rtrim((string) get_option(self::OPT_HOME, 'https://demo.evendis.de'), '/') . '/' . ($profile === 'festival' ? 'festival' : 'club') . '/';
        }
        if ($code !== '') $q['demo'] = $code;
        return $q ? $b . '?' . http_build_query($q) : $b;
    }

    public static function profile($uid) {
        $p = (string) get_user_meta(intval($uid), self::META_PROFILE, true);
        return in_array($p, self::PROFILES, true) ? $p : 'club';
    }

    private static function clean_profile($v) {
        $v = (string) $v;
        return in_array($v, self::PROFILES, true) ? $v : '';
    }

    /** Profil setzen und den Zugang dem passenden Beispiel-Veranstalter zuordnen (Team) */
    public static function set_profile($uid, $profile) {
        if (self::instance()) { update_user_meta(intval($uid), self::META_PROFILE, $profile); return; } // kein Beispiel-Veranstalter auf der Instanz
        if ($profile === 'festival' && class_exists('TIX_Demo')) TIX_Demo::ensure_festival();
        update_user_meta(intval($uid), self::META_PROFILE, $profile);
        $oid = class_exists('TIX_Demo') ? TIX_Demo::org_for($profile) : 0;
        if ($oid) update_user_meta(intval($uid), '_tix_team_organizer_id', $oid);
    }

    /** Alle Beispiel-Veranstalter (Club, Festival) */
    public static function demo_orgs() {
        return array_values(array_filter([class_exists('TIX_Demo') ? TIX_Demo::org_for('club') : 0, class_exists('TIX_Demo') ? TIX_Demo::org_for('festival') : 0]));
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

    /** Fälle des Zugangs; ältere Zugänge und Vertriebler: ['platform'] */
    public static function cases($uid) {
        $c = get_user_meta(intval($uid), self::META_CASES, true);
        $c = is_array($c) ? array_values(array_intersect(TIX_Demo_Remote::CASES, array_map('strval', $c))) : [];
        return $c ?: ['platform'];
    }

    /** Instanz-Fälle des Zugangs (shop, club) – nur auf der Plattform */
    public static function remote_cases($uid) {
        return self::multi() ? TIX_Demo_Remote::instance_cases(self::cases($uid)) : [];
    }

    /** Stand der Instanzen für die Liste: [target => Stand|null]; $only = nur diese Ziele frisch abfragen */
    private static function remote_states(array $only = null) {
        $out = [];
        foreach (TIX_Demo_Remote::INSTANCE_CASES as $t) {
            if ($only !== null && !in_array($t, $only, true)) { $out[$t] = TIX_Demo_Remote::last_state($t); continue; }
            $out[$t] = TIX_Demo_Remote::platform_ready($t) ? TIX_Demo_Remote::state(false, $t) : null;
            if ($out[$t] === null) $out[$t] = TIX_Demo_Remote::last_state($t);
        }
        return $out;
    }

    public static function has_case($uid, $case) {
        return in_array($case, self::cases($uid), true);
    }

    /** Zugang mit Fällen angelegt (eine Mail mit allen Links) */
    private static function with_cases($uid) {
        return is_array(get_user_meta(intval($uid), self::META_CASES, true));
    }

    /** Plattform: Zugang nur mit Fall „shop“ darf sich im Demo-Studio nicht anmelden */
    private static function studio_denied($uid) {
        return self::multi() && !self::has_case($uid, 'platform');
    }

    // ═══════════════ Anmeldung sperren ═══════════════

    /** Abgelaufene bzw. gesperrte Interessenten gelten als nicht angemeldet (Token bleibt wirkungslos). */
    public static function gate($user_id) {
        if (!$user_id) return $user_id;
        $u = get_userdata(intval($user_id));
        if (!$u || !in_array(self::ROLE, (array) $u->roles, true) || $u->has_cap('manage_options')) return $user_id;
        if (!self::usable($u->ID) || self::studio_denied($u->ID)) return 0;
        // zuletzt aktiv (höchstens alle 5 Minuten schreiben)
        $last = intval(get_user_meta($u->ID, '_tix_demo_last_seen', true));
        if (time() - $last > 300) update_user_meta($u->ID, '_tix_demo_last_seen', time());
        return $user_id;
    }

    public static function gate_password($user) {
        if ($user instanceof WP_User && in_array(self::ROLE, (array) $user->roles, true) && (!self::usable($user->ID) || self::studio_denied($user->ID))) {
            return new WP_Error('tix_demo_expired', 'Dieser Demo-Zugang ist abgelaufen oder gesperrt.');
        }
        return $user;
    }

    /** Für /me: null bei normalen Konten */
    public static function me_payload(WP_User $user) {
        if (!self::enabled()) return null;
        $inst = self::instance();
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
            'profile'    => self::profile($user->ID),
            'can_switch' => !$prospect && !$inst,
            'instance'   => $inst,
            'home'       => self::home_url(self::code($user->ID), self::model($user->ID), self::profile($user->ID)),
            'home_self'  => self::home_url(self::code($user->ID), 'self', self::profile($user->ID)),
            'home_platform' => self::home_url(self::code($user->ID), 'platform', self::profile($user->ID)),
            'reset_at'   => $inst ? wp_date('c', TIX_Demo_Shop::next_reset_ts()) : (class_exists('TIX_Demo') ? wp_date('c', TIX_Demo::next_reset_ts()) : null),
            // eigene Testkäufe seit dem letzten Zurücksetzen (Karte „Testkauf ausprobieren“ im Studio)
            'test_orders' => self::test_orders($user->ID, $user->user_email),
        ];
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $staff = function () {
            if (!self::enabled()) return new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]);
            if (current_user_can('manage_options') || (class_exists('TIX_Sales') && TIX_Sales::is_sales_user())) return true;
            return new WP_Error('rest_forbidden', 'Nur für den Vertrieb.', ['status' => 403]);
        };
        $public = function () { return self::enabled() ? true : new WP_Error('rest_no_route', 'Nicht gefunden.', ['status' => 404]); };
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
            "SELECT COUNT(*) FROM $o o WHERE " . self::pm_clause('o') . " AND o.status IN ('completed','processing')$since AND (o.billing_email = %s OR o.id IN (SELECT pm.post_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = %s AND pm.meta_value = %s))",
            (string) $email, self::META_ORDER, (string) intval($uid)
        )));
    }

    public static function test_orders_of(WP_User $u) {
        return self::test_orders($u->ID, $u->user_email);
    }

    /** Testkäufe: auf der Plattform Zahlart demo, auf der Demo-Instanz jede Bestellung (dort ist alles Test) */
    private static function pm_clause($alias = '') {
        if (self::instance()) return '1=1';
        return ($alias !== '' ? $alias . '.' : '') . "payment_method = 'demo'";
    }

    /** $state: false = ohne Fälle (wie bisher); sonst Stand der Instanz (TIX_Demo_Remote::state) oder null */
    public static function row(WP_User $u, $state = false) {
        $prospect = self::is_prospect($u);
        $exp = $prospect ? self::expires($u->ID) : 0;
        $owner = intval(get_user_meta($u->ID, '_tix_demo_owner', true));
        $ou = $owner ? get_userdata($owner) : null;
        $ro = $ou ? null : get_user_meta($u->ID, '_tix_demo_remote_owner', true); // Instanz: von der Plattform angelegt
        $last = intval(get_user_meta($u->ID, '_tix_demo_last_seen', true));
        $created = intval(get_user_meta($u->ID, '_tix_demo_created', true)) ?: strtotime($u->user_registered . ' UTC');
        $row = [
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
            'owner'       => $ou ? ['id' => intval($ou->ID), 'name' => (string) $ou->display_name] : (is_array($ro) && !empty($ro['name']) ? ['id' => 0, 'name' => (string) $ro['name'] . ' (Plattform)'] : null),
            'last_seen'   => $last ? wp_date('c', $last) : null,
            'test_orders' => self::test_orders($u->ID, $u->user_email),
            'model'       => self::model($u->ID),
            'profile'     => self::profile($u->ID),
            'home'        => self::home_url(self::code($u->ID), self::model($u->ID), self::profile($u->ID)),
        ];
        // Fälle: Felder der Instanzen überschreiben bei Zugängen ohne Plattform test_orders, last_seen und home (1.38.844; vorher blieb
        // durch „+“ der Plattform-Wert stehen)
        return $state === false ? $row : array_merge($row, self::case_fields($u, $state));
    }

    /**
     * cases + je Instanz-Fall {status, last_seen, test_orders} je Zeile (GET /demo-access?cases=all): Felder shop und club
     * (null ohne diesen Fall bzw. ohne Stand). $states = [target => Stand|null] (remote_states).
     */
    private static function case_fields(WP_User $u, $states) {
        $cases = self::is_prospect($u) ? self::cases($u->ID) : ['platform'];
        $f = ['cases' => $cases];
        $homes = [];
        foreach (TIX_Demo_Remote::INSTANCE_CASES as $t) {
            $state = is_array($states) ? ($states[$t] ?? null) : null;
            $row = null;
            if (in_array($t, $cases, true) && is_array($state) && isset($state['accesses'][$u->ID]) && is_array($state['accesses'][$u->ID])) {
                $a = $state['accesses'][$u->ID];
                $st = (string) ($a['status'] ?? '');
                $row = ['status' => in_array($st, ['active', 'expiring', 'expired', 'blocked'], true) ? $st : 'active',
                    'last_seen' => !empty($a['last_seen']) ? (string) $a['last_seen'] : null, 'test_orders' => intval($a['test_orders'] ?? 0)];
            }
            $f[$t] = $row;
            if (in_array($t, $cases, true) && is_array($state) && !empty($state['shop']['home'])) $homes[] = (string) $state['shop']['home'];
        }
        if (!in_array('platform', $cases, true)) {
            // ohne Plattform: Testkäufe, letzte Anmeldung und Testkauf-Adresse kommen von den Instanzen
            $rows = array_values(array_filter([$f['shop'] ?? null, $f['club'] ?? null]));
            $f['test_orders'] = array_sum(array_column($rows, 'test_orders'));
            $seen = array_values(array_filter(array_column($rows, 'last_seen')));
            usort($seen, function ($a, $b) { return strtotime($b) <=> strtotime($a); });
            $f['last_seen'] = $seen ? $seen[0] : null;
            $f['home'] = $homes ? $homes[0] : '';
        }
        return $f;
    }

    /** GET /demo-access?status=&kind=&q= */
    public static function rest_list(WP_REST_Request $req) {
        $admin = self::is_admin_caller();
        $args = ['role__in' => $admin ? [self::ROLE, 'tix_sales'] : [self::ROLE], 'orderby' => 'registered', 'order' => 'DESC', 'number' => 500];
        if (!$admin) $args['meta_query'] = [['key' => '_tix_demo_owner', 'value' => (string) get_current_user_id()]];
        // Fälle (1.38.843): ?cases=all → je Zeile cases + shop, Block shop; ohne Parameter wie bisher
        $with_cases = self::multi() && (string) $req->get_param('cases') === 'all';
        $state = false;
        $fresh = [];
        if ($with_cases) {
            // je Instanz frischer Stand (60 s Zwischenspeicher), sonst letzter bekannter (Liste bleibt vollständig)
            $state = [];
            foreach (TIX_Demo_Remote::INSTANCE_CASES as $t) {
                $fresh[$t] = TIX_Demo_Remote::platform_ready($t) ? TIX_Demo_Remote::state(false, $t) : null;
                $state[$t] = $fresh[$t] ?? TIX_Demo_Remote::last_state($t);
            }
        }
        $rows = array_values(array_map(function ($u) use ($state) { return self::row($u, $state); }, array_filter(get_users($args), function ($u) { return !$u->has_cap('manage_options'); })));
        $kind = (string) $req->get_param('kind');
        $model = self::clean_model($req->get_param('model'));
        $profile = self::clean_profile($req->get_param('profile'));
        $st = (string) $req->get_param('status');
        $q = mb_strtolower(trim((string) $req->get_param('q')));
        $all = $rows;
        $rows = array_values(array_filter($rows, function ($r) use ($kind, $model, $profile, $st, $q) {
            if ($kind !== '' && $r['kind'] !== $kind) return false;
            if ($model !== '' && $r['model'] !== $model) return false;
            if ($profile !== '' && $r['profile'] !== $profile) return false;
            if ($st === 'active' && !in_array($r['status'], ['active', 'expiring'], true)) return false;
            if (in_array($st, ['expired', 'blocked'], true) && $r['status'] !== $st) return false;
            if ($q !== '' && mb_strpos(mb_strtolower($r['name'] . ' ' . $r['email'] . ' ' . $r['company']), $q) === false) return false;
            return true;
        }));
        $week = time() - 7 * DAY_IN_SECONDS;
        $active_own = self::active_count(get_current_user_id());
        $extra = [];
        if ($with_cases) foreach (TIX_Demo_Remote::INSTANCE_CASES as $t) $extra[$t] = self::shop_block($fresh[$t] ?? null, $all, $admin, $t);
        return self::out($extra + [
            'ok' => true,
            'accesses' => $rows,
            'summary' => [
                'active'      => count(array_filter($all, function ($r) { return in_array($r['status'], ['active', 'expiring'], true); })),
                'seen_week'   => count(array_filter($all, function ($r) use ($week) { return $r['last_seen'] && strtotime($r['last_seen']) >= $week; })),
                'test_orders' => array_sum(array_column($all, 'test_orders')),
                'platform'    => count(array_filter($all, function ($r) { return $r['model'] === 'platform'; })),
                'self'        => count(array_filter($all, function ($r) { return $r['model'] === 'self'; })),
                'club'        => count(array_filter($all, function ($r) { return $r['profile'] === 'club'; })),
                'festival'    => count(array_filter($all, function ($r) { return $r['profile'] === 'festival'; })),
            ],
            'profiles' => self::profiles_info(),
            'models' => self::models_info(),
            'limits' => ['days' => self::DAYS, 'max_active' => $admin ? null : self::MAX_ACTIVE, 'active_own' => $active_own, 'can_create_sales' => $admin],
            'studio' => self::studio_url('/'),
            'home' => self::home_url(),
            'reset_at' => self::instance() ? wp_date('c', TIX_Demo_Shop::next_reset_ts()) : (class_exists('TIX_Demo') ? wp_date('c', TIX_Demo::next_reset_ts()) : null),
            'last_reset' => self::instance() ? TIX_Demo_Shop::last_reset() : (($t = (string) get_option('tix_demo_reset_at', '')) !== '' ? wp_date('c', strtotime($t . ' ' . wp_timezone_string()) ?: time()) : null),
            'instance' => self::instance(),
        ]);
    }

    /** Block shop bzw. club der Liste: Verbindung, Kopfdaten der Instanz, Zahlen (Admin: ganze Instanz, Vertriebler: eigene Zugänge) */
    private static function shop_block($fresh, array $rows, $admin, $target = 'shop') {
        $ready = TIX_Demo_Remote::platform_ready($target);
        $label = TIX_Demo_Remote::label($target);
        $s = is_array($fresh) ? (array) ($fresh['shop'] ?? []) : [];
        $mine = array_values(array_filter($rows, function ($r) use ($target) { return in_array($target, (array) ($r['cases'] ?? []), true); }));
        $week = time() - 7 * DAY_IN_SECONDS;
        $own = ['active' => count(array_filter($mine, function ($r) { return in_array($r['status'], ['active', 'expiring'], true); })),
            'seen_week' => count(array_filter($mine, function ($r) use ($week, $target) { return !empty($r[$target]['last_seen']) && strtotime($r[$target]['last_seen']) >= $week; })),
            'test_orders' => array_sum(array_map(function ($r) use ($target) { return intval($r[$target]['test_orders'] ?? 0); }, $mine))];
        $sum = $admin && is_array($s['summary'] ?? null) ? ['active' => intval($s['summary']['active'] ?? 0), 'seen_week' => intval($s['summary']['seen_week'] ?? 0), 'test_orders' => intval($s['summary']['test_orders'] ?? 0)] : $own;
        return [
            'available' => $fresh !== null,
            'message'   => $fresh !== null ? '' : ($ready ? 'Der ' . $label . ' ist gerade nicht erreichbar.' : 'Der ' . $label . ' ist mit dieser Plattform noch nicht verbunden.'),
            'studio'    => (string) ($s['studio'] ?? ''),
            'home'      => (string) ($s['home'] ?? ''),
            'vorlage'   => (string) ($s['vorlage'] ?? ''),
            'last_reset' => $s['last_reset'] ?? null,
            'reset_at'  => $s['reset_at'] ?? null,
            'summary'   => $sum,
        ];
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
            if ($u && ($p = self::clean_profile($req->get_param('profile'))) !== '') self::set_profile($u->ID, $p);
            return self::out(['ok' => true, 'access' => $u ? self::row($u) : null, 'invite_sent' => !empty($d['invite_sent'])], 201);
        }
        if ($kind !== 'prospect') return new WP_Error('tix_invalid_field', 'Unbekannte Art.', ['status' => 400, 'field' => 'kind']);
        // Fälle (1.38.843, nur Plattform): null = nicht übergeben → wie bisher
        $cases = self::multi() ? TIX_Demo_Remote::clean_cases($req->get_param('cases')) : null;
        if ($cases !== null && !$cases) return new WP_Error('tix_invalid_field', 'Bitte mindestens einen Fall wählen: Plattform, Demo-Shop (ein Event) oder Demo-Club (mehrere Events).', ['status' => 400, 'field' => 'cases']);
        $with_platform = $cases === null || in_array('platform', $cases, true);
        $targets = $cases !== null ? TIX_Demo_Remote::instance_cases($cases) : []; // shop, club
        $model = self::clean_model($req->get_param('model'));
        // Demo-Instanz: Modell steht fest (eigene Website), Profil folgt der Vorlage – das Studio schickt beides nicht
        if ($model === '' && self::instance()) $model = 'self';
        if ($model === '' && $cases !== null) $model = 'platform'; // mit Fällen ist das Modell im Demo-Studio „Plattform“
        if ($model === '') return new WP_Error('tix_invalid_field', 'Bitte das Modell wählen: Provisionskunde oder selbstgehostet.', ['status' => 400, 'field' => 'model']);
        $profile = self::clean_profile($req->get_param('profile'));
        if ($profile === '' && self::instance()) $profile = TIX_Demo_Shop::profile();
        if ($profile === '' && !$with_platform) $profile = 'club'; // Profil nur beim Fall „platform“ Pflicht
        if ($profile === '') return new WP_Error('tix_invalid_field', 'Bitte das Profil wählen: Club (mehrere Events) oder Festival (1 Event).', ['status' => 400, 'field' => 'profile']);
        $email = sanitize_email((string) $req->get_param('email'));
        $name = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 80);
        $company = mb_substr(trim(sanitize_text_field((string) $req->get_param('company'))), 0, 80);
        if ($name === '') return new WP_Error('tix_invalid_field', 'Bitte einen Namen angeben.', ['status' => 400, 'field' => 'name']);
        if (!is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail angeben.', ['status' => 400, 'field' => 'email']);
        if (!self::is_admin_caller() && self::active_count(get_current_user_id()) >= self::MAX_ACTIVE) {
            return new WP_Error('tix_demo_limit', 'Du hast schon ' . self::MAX_ACTIVE . ' aktive Demo-Zugänge. Bitte zuerst einen sperren oder löschen.', ['status' => 409]);
        }
        if (get_user_by('email', $email)) return new WP_Error('tix_invalid_field', 'Zu dieser E-Mail gibt es schon ein Konto. Bitte eine andere Adresse nehmen.', ['status' => 409, 'field' => 'email']);
        foreach ($targets as $t) if (!TIX_Demo_Remote::platform_ready($t)) return TIX_Demo_Remote::error(['error' => 'not_configured'], $t);
        if ($with_platform && !self::instance() && !TIX_Sales::demo_id() && class_exists('TIX_Demo')) TIX_Demo::ensure_organizer();
        $u = self::insert_prospect($name, $email, $company, time() + self::DAYS * DAY_IN_SECONDS, get_current_user_id(), $with_platform);
        if (is_wp_error($u)) return $u;
        $uid = $u->ID;
        update_user_meta($uid, self::META_MODEL, $model);
        if ($with_platform) self::set_profile($uid, $profile);
        else update_user_meta($uid, self::META_PROFILE, $profile); // nur Shop: kein Team im Beispiel-Veranstalter
        if ($cases !== null) update_user_meta($uid, self::META_CASES, $cases);
        $logins = [];
        if ($targets) {
            // Instanz-Teile nacheinander anlegen (shop, dann club); scheitert eine, werden alle schon angelegten Teile und der
            // Plattform-Zugang wieder gelöscht (kein halber Zugang)
            $me = wp_get_current_user();
            $done = [];
            foreach ($targets as $t) {
                $r = TIX_Demo_Remote::call('POST', '/demo-access/remote', [], [
                    'remote_id' => $uid, 'owner' => ['id' => intval($me->ID), 'name' => (string) $me->display_name, 'email' => (string) $me->user_email],
                    'name' => $name, 'email' => $email, 'company' => $company, 'expires_at' => wp_date('c', self::expires($uid)), 'extended' => false, 'invite' => false,
                ], 15, $t);
                $login = $r['ok'] ? (string) ($r['data']['login_url'] ?? '') : '';
                if (!$r['ok'] || $login === '') {
                    if ($r['status'] === 0 || $r['status'] >= 500 || $r['ok']) TIX_Demo_Remote::call('DELETE', '/demo-access/remote/' . $uid, [], null, 15, $t); // evtl. doch angelegt: aufräumen
                    foreach ($done as $d) TIX_Demo_Remote::call('DELETE', '/demo-access/remote/' . $uid, [], null, 15, $d);
                    TIX_Demo_Remote::forget();
                    self::delete_prospect($uid);
                    return TIX_Demo_Remote::error($r, $t);
                }
                $done[] = $t;
                $logins[$t] = $login;
            }
            TIX_Demo_Remote::forget();
        }
        $invite = $req->get_param('invite') === null || filter_var($req->get_param('invite'), FILTER_VALIDATE_BOOLEAN);
        $sent = false;
        if ($invite) { $sent = self::send_credentials(get_userdata($uid), true); if (is_wp_error($sent)) $sent = false; } // 1.38.872: Benutzername + Passwort statt Einmal-Link
        $state = false;
        if ($cases !== null) $state = self::remote_states($targets);
        return self::out(['ok' => true, 'access' => self::row(get_userdata($uid), $state), 'invite_sent' => $sent], 201);
    }

    /**
     * Interessent anlegen (Plattform: rest_create; Instanz: signierter Aufruf der Plattform) → WP_User|WP_Error.
     * $studio: Rolle tix_organizer und (Plattform) Team im Beispiel-Club – nicht bei Zugängen nur mit Fall „shop“.
     */
    public static function insert_prospect($name, $email, $company, $expires, $owner = 0, $studio = true) {
        $login = 'demo_' . (sanitize_user(strtok($email, '@'), true) ?: 'zugang');
        $base = $login; $i = 1;
        while (username_exists($login)) $login = $base . (++$i);
        $uid = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'display_name' => $name, 'first_name' => strtok($name, ' '), 'user_pass' => wp_generate_password(32), 'role' => self::ROLE]);
        if (is_wp_error($uid)) return new WP_Error('tix_invalid_field', $uid->get_error_message(), ['status' => 400, 'field' => 'email']);
        $u = get_userdata($uid);
        if ($studio) {
            $u->add_role('tix_organizer');
            if (!self::instance()) update_user_meta($uid, '_tix_team_organizer_id', TIX_Sales::demo_id()); // Instanz: Team der Seite (ohne Veranstalter)
        }
        if (intval($owner) > 0) update_user_meta($uid, '_tix_demo_owner', intval($owner));
        update_user_meta($uid, '_tix_demo_created', time());
        update_user_meta($uid, '_tix_demo_expires', intval($expires));
        if ($company !== '') update_user_meta($uid, '_tix_demo_company', $company);
        self::code($uid);
        return get_userdata($uid);
    }

    /**
     * PATCH /demo-access/{id} {extend?: true, blocked?: bool, name?, company?, model?, profile?}
     * Erst alles prüfen, dann (Fall „shop“) auf der Instanz anwenden, erst danach hier – scheitert die Instanz, ändert sich nichts.
     */
    public static function rest_update(WP_REST_Request $req) {
        $u = get_userdata(absint($req['id']));
        if (!$u || !self::can_manage($u)) return new WP_Error('tix_not_found', 'Demo-Zugang nicht gefunden.', ['status' => 404]);
        $prospect = self::is_prospect($u);
        $targets = $prospect ? self::remote_cases($u->ID) : [];
        $remote = [];
        $new_exp = 0;
        if ($req->get_param('extend') !== null && filter_var($req->get_param('extend'), FILTER_VALIDATE_BOOLEAN)) {
            if (!$prospect) return new WP_Error('tix_invalid_field', 'Vertriebs-Zugänge laufen nicht ab.', ['status' => 400, 'field' => 'extend']);
            if (get_user_meta($u->ID, '_tix_demo_extended', true)) return new WP_Error('tix_demo_extended', 'Der Zugang wurde schon einmal verlängert.', ['status' => 409]);
            $new_exp = max(time(), self::expires($u->ID)) + self::DAYS * DAY_IN_SECONDS;
            $remote['expires_at'] = wp_date('c', $new_exp); // beide Seiten dasselbe Ende
            $remote['extended'] = true;
        }
        $blocked = null;
        if ($req->get_param('blocked') !== null) {
            if (!$prospect) return new WP_Error('tix_invalid_field', 'Vertriebs-Zugänge bitte löschen statt sperren.', ['status' => 400, 'field' => 'blocked']);
            $blocked = filter_var($req->get_param('blocked'), FILTER_VALIDATE_BOOLEAN);
            $remote['blocked'] = $blocked;
        }
        $m = $p = '';
        if ($req->get_param('model') !== null || $req->get_param('profile') !== null) {
            // Modell und Profil betreffen nur den Fall „platform“
            if ($prospect && self::multi() && !self::has_case($u->ID, 'platform')) return new WP_Error('tix_invalid_field', 'Modell und Profil gibt es nur beim Fall Plattform.', ['status' => 400, 'field' => $req->get_param('model') !== null ? 'model' : 'profile']);
        }
        if ($req->get_param('model') !== null) {
            $m = self::clean_model($req->get_param('model'));
            if ($m === '') return new WP_Error('tix_invalid_field', 'Unbekanntes Modell.', ['status' => 400, 'field' => 'model']);
        }
        if ($req->get_param('profile') !== null) {
            $p = self::clean_profile($req->get_param('profile'));
            if ($p === '') return new WP_Error('tix_invalid_field', 'Unbekanntes Profil.', ['status' => 400, 'field' => 'profile']);
        }
        $n = trim(sanitize_text_field((string) $req->get_param('name')));
        if ($n !== '') $remote['name'] = mb_substr($n, 0, 80);
        $company = $req->get_param('company') !== null ? mb_substr(trim(sanitize_text_field((string) $req->get_param('company'))), 0, 80) : null;
        if ($company !== null) $remote['company'] = $company;
        if ($targets && $remote) {
            // erst alle Instanzen; scheitert eine, werden die schon geänderten auf den bisherigen Stand der Plattform zurückgesetzt
            $undo = [];
            if (array_key_exists('expires_at', $remote)) $undo['expires_at'] = wp_date('c', max(time() + 60, self::expires($u->ID)));
            if (array_key_exists('extended', $remote)) $undo['extended'] = (bool) get_user_meta($u->ID, '_tix_demo_extended', true);
            if (array_key_exists('blocked', $remote)) $undo['blocked'] = (bool) get_user_meta($u->ID, '_tix_demo_blocked', true);
            if (array_key_exists('name', $remote)) $undo['name'] = (string) $u->display_name;
            if (array_key_exists('company', $remote)) $undo['company'] = (string) get_user_meta($u->ID, '_tix_demo_company', true);
            foreach ($targets as $t) if (TIX_Demo_Remote::state(true, $t) === null) return TIX_Demo_Remote::error(TIX_Demo_Remote::platform_ready($t) ? ['error' => 'unreachable'] : ['error' => 'not_configured'], $t);
            $done = [];
            foreach ($targets as $t) {
                $r = TIX_Demo_Remote::call('PATCH', '/demo-access/remote/' . intval($u->ID), [], $remote, 15, $t);
                if (!$r['ok']) {
                    foreach ($done as $d) TIX_Demo_Remote::call('PATCH', '/demo-access/remote/' . intval($u->ID), [], $undo, 15, $d);
                    TIX_Demo_Remote::forget();
                    return TIX_Demo_Remote::error($r, $t);
                }
                $done[] = $t;
            }
            TIX_Demo_Remote::forget();
        }
        if ($new_exp) {
            update_user_meta($u->ID, '_tix_demo_expires', $new_exp);
            update_user_meta($u->ID, '_tix_demo_extended', 1);
        }
        if ($blocked === true) {
            update_user_meta($u->ID, '_tix_demo_blocked', 1);
            delete_user_meta($u->ID, '_tix_app_tokens');
            delete_user_meta($u->ID, '_tix_demo_link');
        } elseif ($blocked === false) {
            delete_user_meta($u->ID, '_tix_demo_blocked');
        }
        if ($m !== '') update_user_meta($u->ID, self::META_MODEL, $m);
        if ($p !== '') self::set_profile($u->ID, $p);
        if ($n !== '') wp_update_user(['ID' => $u->ID, 'display_name' => mb_substr($n, 0, 80)]);
        if ($company !== null) update_user_meta($u->ID, '_tix_demo_company', $company);
        return self::out(['ok' => true, 'access' => self::row(get_userdata($u->ID), self::with_cases($u->ID) ? self::remote_states($targets) : false)]);
    }

    /** POST /demo-access/{id}/link – Einladung bzw. Anmelde-Link neu senden (mit Fällen: eine Mail mit allen Links) */
    public static function rest_resend(WP_REST_Request $req) {
        $u = get_userdata(absint($req['id']));
        if (!$u || !self::can_manage($u)) return new WP_Error('tix_not_found', 'Demo-Zugang nicht gefunden.', ['status' => 404]);
        if (!self::is_prospect($u)) return new WP_Error('tix_invalid_field', 'Vertriebler melden sich mit Passwort an.', ['status' => 400]);
        if (!self::usable($u->ID)) return new WP_Error('tix_demo_expired', 'Der Zugang ist abgelaufen oder gesperrt.', ['status' => 409]);
        $r = self::send_credentials($u, false); // 1.38.872: neues Passwort für alle Fälle; scheitert eine Instanz, geht keine Mail raus
        if (is_wp_error($r)) return $r;
        return self::out(['ok' => true, 'sent' => $r]);
    }

    /**
     * DELETE /demo-access/{id} – mit Instanz-Fällen erst auf den Instanzen (dort schon weg = in Ordnung). Vorher wird geprüft, ob alle
     * beteiligten Instanzen antworten; sonst 502 und nichts gelöscht. Scheitert eine trotzdem mittendrin, bleibt der Zugang auf der
     * Plattform stehen (502); ein neuer Versuch löscht den Rest (schon gelöschte Teile melden 404 = in Ordnung).
     */
    public static function rest_delete(WP_REST_Request $req) {
        $u = get_userdata(absint($req['id']));
        if (!$u || !self::can_manage($u)) return new WP_Error('tix_not_found', 'Demo-Zugang nicht gefunden.', ['status' => 404]);
        if (!self::is_prospect($u)) return TIX_Sales::rest_delete($req);
        // erst prüfen, ob alle beteiligten Instanzen antworten – sonst nichts löschen (kein halb gelöschter Zugang)
        foreach (self::remote_cases($u->ID) as $t) if (TIX_Demo_Remote::state(true, $t) === null) return TIX_Demo_Remote::error(TIX_Demo_Remote::platform_ready($t) ? ['error' => 'unreachable'] : ['error' => 'not_configured'], $t);
        foreach (self::remote_cases($u->ID) as $t) {
            $r = TIX_Demo_Remote::call('DELETE', '/demo-access/remote/' . intval($u->ID), [], null, 15, $t);
            if (!$r['ok'] && intval($r['status']) !== 404) { TIX_Demo_Remote::forget(); return TIX_Demo_Remote::error($r, $t); }
        }
        if (self::remote_cases($u->ID)) TIX_Demo_Remote::forget();
        self::delete_prospect($u->ID);
        return self::out(['ok' => true, 'deleted' => true]);
    }

    public static function delete_prospect($uid) {
        if (!function_exists('wp_delete_user')) require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user(intval($uid));
    }

    /** POST /demo-access/view {model} – eigene Sicht (Vertriebler, Admin) → {ok, demo_access} */
    public static function rest_view(WP_REST_Request $req) {
        // {model?, profile?} – mindestens eins; Profil hängt auch das Team um (Vertriebler arbeiten dann im anderen Beispiel-Veranstalter)
        $m = self::clean_model($req->get_param('model'));
        $p = self::clean_profile($req->get_param('profile'));
        if ($m === '' && $p === '') return new WP_Error('tix_invalid_field', 'Unbekanntes Modell bzw. Profil.', ['status' => 400, 'field' => 'model']);
        $u = wp_get_current_user();
        if ($m !== '') update_user_meta($u->ID, self::META_MODEL, $m);
        if ($p !== '' && !$u->has_cap('manage_options')) self::set_profile($u->ID, $p);
        elseif ($p !== '') update_user_meta($u->ID, self::META_PROFILE, $p);
        return self::out(['ok' => true, 'model' => self::model($u->ID), 'profile' => self::profile($u->ID), 'demo_access' => self::me_payload($u)]);
    }

    /** Website-Profile (Anzeige im Studio) */
    public static function profiles_info() {
        return [
            ['key' => 'club', 'label' => 'Club', 'sub' => 'mehrere Events', 'text' => 'Wie ein Club mit Programm jede Woche: Events, Vorverkauf, Gästeliste, Musikwunsch.'],
            ['key' => 'festival', 'label' => 'Festival', 'sub' => '1 Event', 'text' => 'Ein großes Event über mehrere Tage: Line-up, Programm auf Bühnen, Festival-Guide.'],
        ];
    }

    /** Modelle mit Preisen wie veranstalter.evendis.de/preise/ (Anzeige im Studio) */
    public static function models_info() {
        return [
            ['key' => 'platform', 'label' => 'Provisionskunde', 'price' => '2,9 % + 0,49 € je Ticket', 'text' => 'Tickets über evendis: Veranstalter-Seite auf evendis.de, Gebühr, Abrechnung und Auszahlung durch evendis.'],
            ['key' => 'self', 'label' => 'Selbstgehostet', 'price' => '0 € je Ticket', 'text' => 'Eigener Shop mit Lizenz: eigene Website und Domain, ggf. eigene App, eigener Zahlungsanbieter, keine evendis-Gebühr.'],
        ];
    }

    /** POST /demo-access/reset – Beispiel-Club zurücksetzen (Admin und Vertriebler, nie Interessenten) */
    public static function rest_reset(WP_REST_Request $req) {
        // Plattform: {target: "shop"|"club"} setzt die Demo-Instanz zurück (signiert); ohne target wie bisher das Demo-Studio
        $target = (string) $req->get_param('target');
        if ($target !== '' && !in_array($target, ['studio', 'shop', 'club'], true)) return new WP_Error('tix_invalid_field', 'Ziel: studio, shop oder club.', ['status' => 400, 'field' => 'target']);
        if (in_array($target, TIX_Demo_Remote::INSTANCE_CASES, true) && self::multi()) {
            $v = (string) $req->get_param('vorlage');
            if ($v !== '' && !in_array($v, ['openair', 'club'], true)) return new WP_Error('tix_invalid_field', 'Vorlage: openair oder club.', ['status' => 400, 'field' => 'vorlage']);
            if (!TIX_Demo_Remote::platform_ready($target)) return TIX_Demo_Remote::error(['error' => 'not_configured'], $target);
            $key = 'tix_demo_' . $target . '_reset_rl';
            $n = intval(get_transient($key));
            if (!self::is_admin_caller() && $n >= 6) return new WP_Error('tix_rate_limit', 'Heute schon oft zurückgesetzt. Bitte später noch einmal.', ['status' => 429]);
            $r = TIX_Demo_Remote::call('POST', '/demo-access/remote/reset', [], $v !== '' ? ['vorlage' => $v] : [], 180, $target);
            if (!$r['ok']) return TIX_Demo_Remote::error($r, $target);
            set_transient($key, $n + 1, 6 * HOUR_IN_SECONDS);
            TIX_Demo_Remote::forget($target);
            $d = (array) $r['data'];
            return self::out(['ok' => true, 'target' => $target, 'last_reset' => $d['last_reset'] ?? null, 'next_reset' => $d['next_reset'] ?? null, 'vorlage' => $d['vorlage'] ?? null,
                'studio' => $d['studio'] ?? null, 'home' => $d['home'] ?? null, 'seeded' => $d['seeded'] ?? null, 'wiped' => $d['wiped'] ?? null]);
        }
        if (self::instance()) {
            // Demo-Instanz: ganze Seite zurücksetzen (Zugänge, Gestaltung, Test-Schlüssel, Rechtstexte bleiben), optional Vorlage wechseln
            $key = 'tix_demo_reset_rl';
            $n = intval(get_transient($key));
            if (!self::is_admin_caller() && $n >= 6) return new WP_Error('tix_rate_limit', 'Heute schon oft zurückgesetzt. Bitte später noch einmal.', ['status' => 429]);
            set_transient($key, $n + 1, 6 * HOUR_IN_SECONDS);
            $v = (string) $req->get_param('vorlage');
            if ($v !== '' && !in_array($v, TIX_Demo_Shop::VORLAGEN, true)) return new WP_Error('tix_invalid_field', 'Vorlage: openair oder club.', ['status' => 400, 'field' => 'vorlage']);
            $r = TIX_Demo_Shop::reset($v);
            if (empty($r['ok'])) return new WP_Error((string) ($r['error'] ?? 'tix_demo_reset'), (string) ($r['message'] ?? 'Zurücksetzen fehlgeschlagen.'), ['status' => 409]);
            return self::out(['ok' => true, 'last_reset' => $r['last_reset'], 'next_reset' => $r['next_reset'], 'vorlage' => $r['vorlage'],
                'studio' => TIX_Demo_Shop::studio_url(), 'home' => TIX_Demo_Shop::home_url(), 'seeded' => $r['seeded'], 'wiped' => $r['wiped']]);
        }
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
        if (class_exists('TIX_Shadow') && TIX_Shadow::active()) return false; // Schatten-Abruf
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $k = 'tix_dal_' . $bucket . '_' . md5($ip);
        $n = intval(get_transient($k));
        if ($n >= $max) return true;
        set_transient($k, $n + 1, $window);
        return false;
    }

    /** Neuen Link erzeugen und mailen. $invite: Einladung (7 Tage gültig) statt Anmelde-Link (30 min). */
    /** Einmal-Anmelde-Link erzeugen (Einladung 7 Tage, sonst 30 min) → URL des Studios dieser Seite */
    public static function login_url(WP_User $u, $invite = false) {
        $raw = wp_generate_password(40, false, false);
        $list = array_values(array_filter((array) get_user_meta($u->ID, '_tix_demo_link', true), function ($t) { return is_array($t) && intval($t['exp'] ?? 0) > time(); }));
        $list[] = ['h' => hash('sha256', $raw), 'exp' => time() + ($invite ? self::INVITE_TTL : self::LINK_TTL)];
        update_user_meta($u->ID, '_tix_demo_link', array_slice($list, -5));
        return self::studio_url('/anmelden/link/?t=' . rawurlencode($raw));
    }

    /**
     * Eine Mail mit allen Links eines Zugangs mit Fällen (Plattform): je Fall ein Knopf, dazu demo.evendis.de.
     * $urls = Einmal-Anmelde-Links der Instanzen [shop => …, club => …] (von TIX_Demo_Remote); ein String gilt als Link für „shop“.
     */
    public static function send_cases_mail(WP_User $u, $invite, $urls = []) {
        if (!class_exists('TIX_Mail_Layout')) return false;
        $urls = is_array($urls) ? $urls : ['shop' => (string) $urls];
        $cases = self::cases($u->ID);
        $both = count($cases) > 1;
        $links = TIX_Demo_Remote::links();
        $blocks = [['type' => 'text', 'html' => 'Hallo ' . esc_html($u->display_name) . ',<br>' . ($invite
            ? 'du hast einen Zugang zu den <b>evendis-Demos</b>. ' . ($both ? (count($cases) > 2 ? 'Du kannst alle drei Demos ausprobieren' : 'Du kannst beide Demos ausprobieren') . ' – mit Beispieldaten, Testkauf und Einlass. Es fließt kein echtes Geld.' : 'Dort probierst du alles mit Beispieldaten aus – inklusive Testkauf und Einlass. Es fließt kein echtes Geld.')
            : 'hier sind deine Anmelde-Links für die evendis-Demos.')]];
        if (in_array('platform', $cases, true)) {
            $blocks[] = ['type' => 'text', 'html' => '<b>Plattform</b> – Verkauf über evendis.de: Beispiel-Veranstalter mit Veranstalter-Seite und das Studio dazu.'];
            $blocks[] = ['type' => 'button', 'label' => 'Plattform ansehen', 'url' => self::login_url($u, $invite)];
        }
        if (in_array('shop', $cases, true) && ($urls['shop'] ?? '') !== '') {
            $blocks[] = ['type' => 'text', 'html' => '<b>Eigene Website mit einem Event</b> – ein Festival über ein ganzes Wochenende: eigene Ticket-Website mit eigenem Studio, nur Testzahlung. Jede Nacht wird dort alles zurückgesetzt.'];
            $blocks[] = ['type' => 'button', 'label' => 'Eigenen Shop ansehen', 'url' => (string) $urls['shop']];
        }
        if (in_array('club', $cases, true) && ($urls['club'] ?? '') !== '') {
            $blocks[] = ['type' => 'text', 'html' => '<b>Eigene Website mit mehreren Events</b> – ein Club mit Programm jede Woche: eigene Ticket-Website mit eigenem Studio, Vorverkauf, Gästeliste und Musikwunsch, nur Testzahlung. Jede Nacht wird dort alles zurückgesetzt.'];
            $blocks[] = ['type' => 'button', 'label' => 'Club-Website ansehen', 'url' => (string) $urls['club']];
        }
        $blocks[] = ['type' => 'text', 'html' => 'Alle Demos auf einen Blick: <a href="' . esc_url($links['hub']) . '">' . esc_html(preg_replace('#^https?://|/$#', '', $links['hub'])) . '</a>'];
        $exp = self::expires($u->ID);
        if ($exp) $blocks[] = ['type' => 'text', 'html' => 'Dein Zugang gilt bis ' . esc_html(wp_date('j. F Y', $exp)) . '. ' . ($invite ? 'Die Links in dieser Mail gelten 7 Tage; danach forderst du auf der Anmeldeseite einfach einen neuen an.' : '')];
        return (bool) TIX_Mail_Layout::send($u->user_email, 'evendis Demo – ' . ($invite ? 'dein Zugang' : 'deine Anmelde-Links'), [
            'title' => $invite ? 'Dein Zugang zu den evendis-Demos' : 'Deine Anmelde-Links',
            'blocks' => $blocks,
            'kind' => 'account',
        ]);
    }

    // ── Zugang mit Benutzername und Passwort (1.38.872, User: Einmal-Links wurden von Mail-Scannern verbraucht) ──

    /** Gut lesbares Passwort: 3 × 4 Zeichen ohne verwechselbare (0/O, 1/l/I), mit Bindestrich → 14 Zeichen */
    public static function readable_password() {
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $g = [];
        for ($j = 0; $j < 3; $j++) { $x = ''; for ($i = 0; $i < 4; $i++) $x .= $abc[random_int(0, strlen($abc) - 1)]; $g[] = $x; }
        return implode('-', $g);
    }

    /** Fertigen WordPress-Hash setzen (Passwort selbst kennt diese Seite dann nicht) */
    public static function set_hash($uid, $hash) {
        global $wpdb;
        $hash = (string) $hash;
        if ($hash === '' || $hash[0] !== '$' || strlen($hash) > 255) return false;
        $wpdb->update($wpdb->users, ['user_pass' => $hash, 'user_activation_key' => ''], ['ID' => intval($uid)]);
        clean_user_cache(intval($uid));
        delete_user_meta(intval($uid), '_tix_app_tokens'); // alte Geräte-Anmeldungen enden
        return true;
    }

    /** Anmeldeseite eines Demo-Studios (ohne Einmal-Token) */
    public static function login_page($case) {
        if (self::instance()) return self::studio_url('/anmelden/');
        $links = class_exists('TIX_Demo_Remote') ? TIX_Demo_Remote::links() : [];
        $base = $case === 'platform' ? (string) get_option(self::OPT_STUDIO, $links['platform'] ?? 'https://demo-studio.evendis.de') : (string) ($links[$case] ?? '');
        return $base !== '' ? rtrim($base, '/') . '/anmelden/' : '';
    }

    /**
     * Neues Passwort setzen (Plattform und alle Instanz-Fälle) und die Zugangs-Mail mit Benutzername und Passwort schicken.
     * Scheitert eine Instanz, geht keine Mail raus und das Passwort gilt nirgends als mitgeteilt → true | WP_Error
     */
    public static function send_credentials(WP_User $u, $invite = true) {
        if (!class_exists('TIX_Mail_Layout')) return new WP_Error('tix_mail_off', 'Mail ist nicht eingerichtet.', ['status' => 503]);
        $pw = self::readable_password();
        $hash = wp_hash_password($pw);
        $cases = (self::multi() && self::with_cases($u->ID)) ? self::cases($u->ID) : [self::instance() ? (class_exists('TIX_Demo_Shop') && TIX_Demo_Shop::vorlage() === 'club' ? 'club' : 'shop') : 'platform'];
        if (self::multi() && self::with_cases($u->ID)) {
            foreach (self::remote_cases($u->ID) as $t) {
                $r = TIX_Demo_Remote::call('POST', '/demo-access/remote/' . intval($u->ID) . '/link', [], ['password_hash' => $hash], 15, $t);
                if (!$r['ok'] || empty($r['data']['password_set'])) return TIX_Demo_Remote::error($r, $t);
            }
        }
        self::set_hash($u->ID, $hash);
        delete_user_meta($u->ID, '_tix_demo_link'); // alte Einmal-Links gelten nicht mehr
        // Kompakt (1.38.873, User: „erklären, was zu tun ist“): ein Hauptknopf, Schritte, Rest als Textlinks
        $names = ['platform' => 'Plattform (Verkauf über evendis.de)', 'shop' => 'Festival – ein Event', 'club' => 'Club – mehrere Events'];
        $cases = array_values(array_filter(array_unique($cases), function ($c) use ($names) { return isset($names[$c]) && self::login_page($c) !== ''; }));
        $first = $cases[0] ?? 'platform';
        $shop_url = function ($c) use ($u) {
            if ($c === 'platform' || self::instance()) return self::home_url(self::code($u->ID), self::model($u->ID) ?: 'self', self::profile($u->ID) ?: 'club');
            $st = (string) (TIX_Demo_Remote::links()[$c] ?? '');
            return $st !== '' ? preg_replace('#^https://studio\.#', 'https://', rtrim($st, '/')) . '/' : '';
        };
        $btn = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::button('Zum Studio', self::login_page($first), 'compact') : '';
        $steps = '<ol style="margin:4px 0 12px 20px;padding:0;font-size:15px;line-height:23px;">'
            . '<li>Auf „Zum Studio“ klicken und mit Benutzername und Passwort anmelden.</li>'
            . '<li>Die Tour zeigt dir das Wichtigste.</li>'
            . '<li>Im Demo-Shop ein Testticket kaufen (Testzahlung, kein echtes Geld) und die Bestellung im Studio ansehen.</li>'
            . '<li>Am Einlass das Ticket mit dem Handy scannen.</li></ol>'
            . '<p style="margin:0 0 12px;">Alles wird jede Nacht zurückgesetzt – probier ruhig alles aus.</p>';
        $links = '';
        foreach ($cases as $c) {
            $su = $shop_url($c);
            $links .= '<p style="margin:0 0 6px;font-size:14px;line-height:21px;"><b>' . esc_html($names[$c]) . ':</b> '
                . ($su !== '' ? '<a href="' . esc_url($su) . '">Demo-Shop</a> · ' : '') . '<a href="' . esc_url(self::login_page($c)) . '">Studio</a></p>';
        }
        $trial = class_exists('TIX_Trial') && method_exists('TIX_Trial', 'page_url') ? TIX_Trial::page_url('neu') : 'https://demo.evendis.de/testen/';
        $links .= '<p style="margin:10px 0 0;font-size:14px;line-height:21px;"><a href="' . esc_url($trial) . '">Eigenen Shop 14 Tage testen</a></p>';
        $blocks = [['type' => 'text', 'html' => 'Hallo ' . esc_html($u->display_name) . ',<br>' . ($invite ? 'hier ist dein Zugang zu den <b>evendis-Demos</b> – mit Beispieldaten, Testkauf und Einlass. Es fließt kein echtes Geld.' : 'hier sind deine neuen Zugangsdaten für die evendis-Demos. Das alte Passwort gilt nicht mehr.')],
            ['type' => 'html', 'html' => '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 8px;border:1px solid #E5E7EB;border-radius:8px;"><tr><td style="padding:12px 16px;font-size:15px;line-height:24px;">'
                . 'Benutzername: <b>' . esc_html($u->user_email) . '</b><br>Passwort: <b style="font-family:Menlo,Consolas,monospace;font-size:17px;letter-spacing:1px;">' . esc_html($pw) . '</b>'
                . (count($cases) > 1 ? '<br><span style="font-size:13px;color:#6B7280;">gilt für alle Demos</span>' : '') . '</td></tr></table>' . $btn],
            ['type' => 'html', 'html' => '<p style="margin:12px 0 4px;"><b>So geht’s</b></p>' . $steps . $links]];
        $exp = self::expires($u->ID);
        if ($exp) $blocks[] = ['type' => 'text', 'html' => '<span style="font-size:13px;color:#6B7280;">Dein Zugang gilt bis ' . esc_html(wp_date('j. F Y', $exp)) . '. Passwort vergessen? Auf der Demo-Seite einfach den Zugang neu anfordern – du bekommst dann ein neues Passwort.</span>'];
        if (class_exists('TIX_Email_Log')) TIX_Email_Log::$redact = [$pw];
        try {
            $ok = (bool) TIX_Mail_Layout::send($u->user_email, 'evendis Demo – ' . ($invite ? 'dein Zugang' : 'deine neuen Zugangsdaten'), ['title' => $invite ? 'Dein Zugang zu den evendis-Demos' : 'Deine neuen Zugangsdaten', 'blocks' => $blocks, 'kind' => 'account']);
        } finally {
            if (class_exists('TIX_Email_Log')) TIX_Email_Log::$redact = [];
        }
        unset($pw);
        return $ok;
    }

    public static function send_link(WP_User $u, $invite = false) {
        $url = self::login_url($u, $invite);
        if (!class_exists('TIX_Mail_Layout')) return false;
        $exp = self::expires($u->ID);
        if (self::instance()) {
            $blocks = [
                ['type' => 'text', 'html' => 'Hallo ' . esc_html($u->display_name) . ',<br>' . ($invite
                    ? 'du hast einen Zugang zum <b>evendis Demo-Shop „Eigene Website“</b>. Dort probierst du eine eigene Ticket-Website mit Studio aus – mit Testkauf auf ' . esc_html((string) wp_parse_url(home_url(), PHP_URL_HOST)) . ' und Einlass. Es fließt kein echtes Geld, und jede Nacht wird alles zurückgesetzt.'
                    : 'hier ist dein Anmelde-Link für den evendis Demo-Shop. Er gilt 30 Minuten.')],
                ['type' => 'button', 'label' => 'Studio öffnen', 'url' => $url],
            ];
            if ($exp) $blocks[] = ['type' => 'text', 'html' => 'Dein Zugang gilt bis ' . esc_html(wp_date('j. F Y', $exp)) . '. ' . ($invite ? 'Der Link in dieser Mail gilt 7 Tage; danach forderst du auf der Anmeldeseite einfach einen neuen an.' : '')];
            return (bool) TIX_Mail_Layout::send($u->user_email, 'evendis Demo-Shop – ' . ($invite ? 'dein Zugang' : 'dein Anmelde-Link'), [
                'title' => $invite ? 'Dein Zugang zum Demo-Shop' : 'Dein Anmelde-Link',
                'blocks' => $blocks,
                'kind' => 'account',
            ]);
        }
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
            if ($u && self::is_prospect($u) && self::usable($u->ID) && !self::studio_denied($u->ID)) {
                $mk = 'tix_dal_mail_' . $u->ID;
                if (intval(get_transient($mk)) < 5) {
                    set_transient($mk, intval(get_transient($mk)) + 1, HOUR_IN_SECONDS);
                    self::send_credentials($u, false); // 1.38.872: neues Passwort statt Einmal-Link
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
        if (!self::usable($u->ID) || self::studio_denied($u->ID)) return new WP_Error('tix_demo_expired', 'Dieser Demo-Zugang ist abgelaufen oder gesperrt.', ['status' => 403]);
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
            if (!$u || $u->has_cap('manage_options') || !in_array(self::ROLE, (array) $u->roles, true)) continue;
            // Shop-Teil best effort mitlöschen; die Instanz räumt ihn sonst selbst auf (gleiches Ablaufdatum)
            foreach (self::remote_cases($u->ID) as $t) if (TIX_Demo_Remote::platform_ready($t)) TIX_Demo_Remote::call('DELETE', '/demo-access/remote/' . intval($u->ID), [], null, 15, $t);
            self::delete_prospect($u->ID);
        }
    }

    // ═══════════════ Kasse: Demo-Zahlung ═══════════════

    /** Event des Beispiel-Clubs? (nur Demo Club, nicht Vorschauen) */
    public static function is_demo_event($event_id) {
        if (self::instance()) return true; // Demo-Instanz: jedes Event ist Demo (Zahlart „demo“ + Testmodus, TIX_Demo_Shop)
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
        if (self::instance()) return array_values(array_filter((array) $uids, function ($uid) { return !self::is_demo_user(intval($uid)); }));
        if (!array_intersect(self::demo_orgs(), array_map('intval', (array) $org_ids))) return $uids;
        return array_values(array_filter((array) $uids, function ($uid) { return !self::is_demo_user(intval($uid)); }));
    }

    /** Testkauf: Mitteilung nur an den eigenen Zugang */
    public static function on_order_completed($order_id) {
        global $wpdb;
        $pm = (string) $wpdb->get_var($wpdb->prepare("SELECT payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", intval($order_id)));
        if ($pm !== 'demo' && !self::instance()) return;
        $uid = intval(get_post_meta(intval($order_id), self::META_ORDER, true));
        if (!$uid || !class_exists('TIX_Staff_Push') || !method_exists('TIX_Staff_Push', 'send_to_users')) return;
        $o = class_exists('TIX_Order') ? TIX_Order::get($order_id) : null;
        $n = 0;
        if ($o) foreach ($o->get_items() as $it) $n += intval($it->get_quantity());
        $total = $o ? (float) $o->get_total() : 0.0;
        TIX_Staff_Push::send_to_users([$uid], 'Testkauf eingegangen', ($n === 1 ? '1 Ticket' : $n . ' Tickets') . ' · ' . number_format($total, 2, ',', '.') . ' € (' . ($pm === 'demo' ? 'Demo-Zahlung' : 'Testzahlung') . ')', ['action' => 'staff:order:' . intval($order_id), 'type' => 'demo_order']);
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
            "SELECT id, billing_first_name f, billing_last_name l, billing_email e, billing_phone p, billing_company c, billing_address_1 a FROM $o WHERE " . self::pm_clause() . " AND billing_email NOT LIKE %s AND billing_email <> %s ORDER BY id DESC LIMIT 2000",
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
        if (!($response instanceof WP_REST_Response) || !($request instanceof WP_REST_Request) || !self::enabled()) return $response;
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
