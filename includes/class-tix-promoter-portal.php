<?php
/**
 * TIX_Promoter_Portal – eigener Bereich der Promoter im Studio, ohne WordPress (1.38.651, User 2026-11-05).
 *
 * Immer unter der Studio-Domain der Seite: <Studio>/promoter/ (TIX_Studio::base(), sonst studio.<Kunden-Host>).
 * Anmeldung per Login-Link oder 6-stelligem Code an die E-Mail des Promoters (15 min, einmalig, 5 Versuche), danach
 * eigenes Promoter-Token (Kopfzeile X-Tix-Promoter-Token, 90 Tage, Tabelle {prefix}tix_promoter_tokens, nur als Hash),
 * getrennt von Veranstalter-Konten. Nur eigene Daten, keine Käuferdaten; Mandanten wie im Promoter-Audit (§81).
 * Die alte WordPress-Seite ([tix_promoter_dashboard]) und alte Magic-Links leiten auf das Studio um.
 *
 * REST (Vertrag DOCUMENTATION.md §85):
 *   POST /promoter/auth/link · /auth/confirm · /auth/logout · /auth/logout-all
 *   GET|POST /promoter/me · GET /promoter/stats · /promoter/links · /promoter/sales · /promoter/payouts
 *   GET|POST /promoter/payout-details · POST /promoter/signup · GET /promoter/site
 */
if (!defined('ABSPATH')) exit;

class TIX_Promoter_Portal {

    const NS = 'tixomat/v1';
    const HEADER = 'X-Tix-Promoter-Token';
    const TOKEN_TTL = 90 * DAY_IN_SECONDS;
    const LOGIN_TTL = 15 * MINUTE_IN_SECONDS;
    const DB_VERSION = '1';

    private static $current = null;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('init', [__CLASS__, 'maybe_create'], 26);
        add_action('template_redirect', [__CLASS__, 'redirect_wp_page'], 1);
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'tix_promoter_tokens'; }

    public static function maybe_create() {
        if (get_option('tix_promoter_tokens_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            promoter_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created DATETIME NOT NULL,
            expires DATETIME NOT NULL,
            last_used DATETIME NULL,
            device VARCHAR(120) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY promoter_id (promoter_id)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_promoter_tokens_db', self::DB_VERSION, false);
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }
    private static function enabled() { $s = get_option('tix_settings', []); return is_array($s) && !empty($s['promoter_enabled']); }
    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }
    private static function now() { return current_time('mysql', true); }

    /** Studio-Adresse der Seite (nie WordPress) */
    public static function studio_base() {
        $b = class_exists('TIX_Studio') ? (string) TIX_Studio::base() : '';
        if ($b !== '') return $b;
        $c = class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::base() : '';
        $h = preg_replace('/^www\./', '', strtolower((string) wp_parse_url($c !== '' ? $c : home_url(), PHP_URL_HOST)));
        return 'https://studio.' . $h;
    }

    public static function url($path = '') { return self::studio_base() . '/promoter/' . ltrim((string) $path, '/'); }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        $pub = '__return_true';
        $me = [__CLASS__, 'perm'];
        $r = function ($path, $m, $cb, $perm) { register_rest_route(self::NS, $path, ['methods' => $m, 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm]); };
        $r('/promoter/site', 'GET', 'rest_site', $pub);
        $r('/promoter/auth/link', 'POST', 'rest_link', $pub);
        $r('/promoter/auth/confirm', 'POST', 'rest_confirm', $pub);
        $r('/promoter/signup', 'POST', 'rest_signup', $pub);
        $r('/promoter/auth/logout', 'POST', 'rest_logout', $me);
        $r('/promoter/auth/logout-all', 'POST', 'rest_logout_all', $me);
        $r('/promoter/me', 'GET', 'rest_me', $me);
        $r('/promoter/me', 'POST', 'rest_me_save', $me);
        $r('/promoter/stats', 'GET', 'rest_stats', $me);
        $r('/promoter/links', 'GET', 'rest_links', $me);
        $r('/promoter/sales', 'GET', 'rest_sales', $me);
        $r('/promoter/payouts', 'GET', 'rest_payouts', $me);
        $r('/promoter/payout-details', 'GET', 'rest_payout_get', $me);
        $r('/promoter/payout-details', 'POST', 'rest_payout_save', $me);
    }

    // ═══════════════ Token ═══════════════

    private static function token_from(WP_REST_Request $req) {
        $t = (string) $req->get_header('x_tix_promoter_token');
        if ($t === '' && preg_match('/^Bearer\s+(tp_[A-Za-z0-9]{40})$/', (string) $req->get_header('authorization'), $m)) $t = $m[1];
        return preg_match('/^tp_[A-Za-z0-9]{40}$/', $t) ? $t : '';
    }

    /** Berechtigung: gültiges Promoter-Token (Promoter aktiv oder wartet auf Freigabe) */
    public static function perm(WP_REST_Request $req) {
        if (!self::enabled()) return new WP_Error('tix_promoter_off', 'Das Promoter-Programm ist hier nicht aktiv.', ['status' => 404]);
        $t = self::token_from($req);
        if ($t === '') return new WP_Error('rest_not_logged_in', 'Bitte anmelden.', ['status' => 401]);
        global $wpdb;
        self::maybe_create();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE token_hash = %s", hash('sha256', $t)));
        if (!$row || $row->expires < self::now()) return new WP_Error('rest_not_logged_in', 'Die Anmeldung ist abgelaufen – bitte neu anmelden.', ['status' => 401]);
        $p = TIX_Promoter_DB::get_promoter(intval($row->promoter_id));
        if (!$p || !in_array($p->status, ['active', 'pending'], true)) return new WP_Error('rest_not_logged_in', 'Dieser Promoter-Zugang ist nicht aktiv.', ['status' => 401]);
        if (!$row->last_used || strtotime($row->last_used . ' UTC') < time() - HOUR_IN_SECONDS) $wpdb->update(self::table(), ['last_used' => self::now()], ['id' => intval($row->id)]);
        self::$current = $p;
        $GLOBALS['tix_pp_token_id'] = intval($row->id);
        return true;
    }

    private static function issue($pid, $device) {
        global $wpdb;
        self::maybe_create();
        $t = 'tp_' . wp_generate_password(40, false, false);
        $exp = gmdate('Y-m-d H:i:s', time() + self::TOKEN_TTL);
        $wpdb->insert(self::table(), ['promoter_id' => intval($pid), 'token_hash' => hash('sha256', $t), 'created' => self::now(), 'expires' => $exp, 'device' => mb_substr(sanitize_text_field((string) $device), 0, 120)]);
        return [$t, mysql_to_rfc3339($exp)];
    }

    /** Alle Token eines Promoters ungültig (Studio „Überall abmelden“, Deaktivieren, neue E-Mail) */
    public static function revoke_all($pid) {
        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '" . self::table() . "'") === self::table()) $wpdb->delete(self::table(), ['promoter_id' => intval($pid)]);
    }

    // ═══════════════ Anmeldung ═══════════════

    private static function rl($bucket, $max, $win, $key = '') {
        return class_exists('TIX_App_Account') && TIX_App_Account::rate_limited($bucket, $max, $win, $key);
    }

    private static function email_of($p) {
        $e = (string) ($p->email ?? '');
        if ($e === '' && !empty($p->user_id) && ($u = get_userdata(intval($p->user_id)))) $e = $u->user_email;
        return strtolower($e);
    }

    /**
     * Login-Link und Code an den Promoter schicken (auch für das Studio „Zugang schicken“ und die alte Selbst-Anmeldung).
     * Höchstens 3 je 15 Minuten und Promoter. → true | WP_Error
     */
    public static function send_login($p, $reason = 'login') {
        $email = self::email_of($p);
        if (!is_email($email)) return self::bad('email', 'Der Promoter hat keine E-Mail-Adresse.');
        $k = 'tix_pp_sent_' . intval($p->id);
        $n = (int) get_transient($k);
        if ($n >= 3) return new WP_Error('tix_rate_limit', 'Es wurden gerade schon Login-Links verschickt – bitte 15 Minuten warten.', ['status' => 429]);
        set_transient($k, $n + 1, self::LOGIN_TTL);
        $magic = wp_generate_password(32, false, false);
        $code = str_pad((string) wp_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        set_transient('tix_pp_login_' . md5($email), ['pid' => intval($p->id), 'code' => wp_hash_password($code), 'magic' => hash('sha256', $magic), 'tries' => 0], self::LOGIN_TTL);
        $url = add_query_arg('t', $magic . '.' . intval($p->id), self::url('anmelden/'));
        $site = class_exists('TIX_Mail_Layout') ? (string) TIX_Mail_Layout::brand()['name'] : get_bloginfo('name');
        $title = $reason === 'signup' ? 'Willkommen im Promoter-Bereich' : 'Dein Login für den Promoter-Bereich';
        $blocks = [
            ['type' => 'text', 'html' => '<p>Hallo' . ($p->display_name ? ' ' . esc_html($p->display_name) : '') . ',</p><p>' . ($reason === 'signup' ? 'danke für deine Anmeldung als Promoter. Mit dem Knopf meldest du dich an' . ($p->status === 'pending' ? ' – sobald der Veranstalter dich freigibt, siehst du dort deine Links.' : '.') : 'mit dem Knopf meldest du dich im Promoter-Bereich an.') . '</p>'],
            ['type' => 'button', 'label' => 'Zum Promoter-Bereich', 'url' => $url],
            ['type' => 'code', 'value' => $code, 'hint' => 'Oder diesen Code auf der Anmeldeseite eingeben'],
            ['type' => 'text', 'html' => '<p style="font-size:13px;">Link und Code gelten 15 Minuten und nur einmal. Hast du das nicht angefordert, kannst du die Mail ignorieren.</p>'],
        ];
        $html = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::render(['title' => $title, 'preheader' => 'Code ' . $code . ' · gilt 15 Minuten', 'kind' => 'account', 'blocks' => $blocks])
            : '<p><a href="' . esc_url($url) . '">Zum Promoter-Bereich</a></p><p>Code: ' . $code . '</p>';
        wp_mail($email, $title . ' – ' . $site, $html, ['Content-Type: text/html; charset=UTF-8']);
        return true;
    }

    private static function find_by_email($email) {
        $p = TIX_Promoter_DB::get_promoter_by_email($email);
        if (!$p) {
            global $wpdb;
            $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Promoter_DB::table_promoters() . " WHERE LOWER(email) = %s AND status = 'pending' LIMIT 1", strtolower($email)));
        }
        if (!$p && ($u = get_user_by('email', $email))) $p = TIX_Promoter_DB::get_promoter_by_user($u->ID);
        return ($p && in_array($p->status, ['active', 'pending'], true)) ? $p : null;
    }

    /** POST /promoter/auth/link {email} – immer dieselbe Antwort (verrät nicht, ob es die Adresse gibt) */
    public static function rest_link(WP_REST_Request $req) {
        if (!self::enabled()) return new WP_Error('tix_promoter_off', 'Das Promoter-Programm ist hier nicht aktiv.', ['status' => 404]);
        $email = strtolower(sanitize_email((string) $req->get_param('email')));
        if (!is_email($email)) return self::bad('email', 'Bitte eine gültige E-Mail-Adresse eingeben.');
        if (self::rl('pp_link_ip', 5, 15 * MINUTE_IN_SECONDS)) return new WP_Error('tix_rate_limit', 'Zu viele Anfragen – bitte 15 Minuten warten.', ['status' => 429]);
        $ok = rest_ensure_response(['ok' => true, 'sent' => true, 'message' => 'Wenn diese E-Mail bei uns als Promoter registriert ist, kommt gleich ein Login-Link mit Code.']);
        if (self::rl('pp_link_mail', 3, 15 * MINUTE_IN_SECONDS, 'e' . $email)) return $ok;
        if (class_exists('TIX_Promoter_DB') && ($p = self::find_by_email($email))) self::send_login($p);
        return $ok;
    }

    /** POST /promoter/auth/confirm {t} | {email, code} | {legacy} (alter Magic-Link aus WordPress-Mails) → {token, expires_at, promoter} */
    public static function rest_confirm(WP_REST_Request $req) {
        if (!self::enabled()) return new WP_Error('tix_promoter_off', 'Das Promoter-Programm ist hier nicht aktiv.', ['status' => 404]);
        if (self::rl('pp_confirm_ip', 20, 15 * MINUTE_IN_SECONDS)) return new WP_Error('tix_rate_limit', 'Zu viele Versuche – bitte 15 Minuten warten.', ['status' => 429]);
        $pid = 0;
        $t = (string) $req->get_param('t');
        $legacy = (string) $req->get_param('legacy');
        if ($legacy !== '' && class_exists('TIX_Promoter_Auth')) {
            $pid = TIX_Promoter_Auth::verify_magic_token($legacy);
        } elseif (preg_match('/^([A-Za-z0-9]{32})\.(\d+)$/', $t, $m)) {
            $p = TIX_Promoter_DB::get_promoter(intval($m[2]));
            $email = $p ? self::email_of($p) : '';
            $st = $email !== '' ? get_transient('tix_pp_login_' . md5($email)) : null;
            if (is_array($st) && intval($st['pid']) === intval($m[2]) && hash_equals((string) $st['magic'], hash('sha256', $m[1]))) { $pid = intval($m[2]); delete_transient('tix_pp_login_' . md5($email)); }
        } else {
            $email = strtolower(sanitize_email((string) $req->get_param('email')));
            $code = preg_replace('/\D/', '', (string) $req->get_param('code'));
            $k = 'tix_pp_login_' . md5($email);
            $st = get_transient($k);
            if (is_array($st) && strlen($code) === 6) {
                if (wp_check_password($code, (string) $st['code'])) { $pid = intval($st['pid']); delete_transient($k); }
                else { $st['tries']++; if ($st['tries'] >= 5) delete_transient($k); else set_transient($k, $st, self::LOGIN_TTL); }
            }
        }
        $p = $pid ? TIX_Promoter_DB::get_promoter($pid) : null;
        if (!$p || !in_array($p->status, ['active', 'pending'], true)) return new WP_Error('tix_login_invalid', 'Link oder Code ist ungültig oder abgelaufen – bitte neu anfordern.', ['status' => 400]);
        list($token, $exp) = self::issue($p->id, (string) $req->get_header('user_agent'));
        return rest_ensure_response(['ok' => true, 'token' => $token, 'expires_at' => $exp, 'promoter' => self::me_payload($p)]);
    }

    public static function rest_logout(WP_REST_Request $req) {
        global $wpdb;
        $wpdb->delete(self::table(), ['id' => intval($GLOBALS['tix_pp_token_id'] ?? 0)]);
        return rest_ensure_response(['ok' => true]);
    }

    public static function rest_logout_all(WP_REST_Request $req) {
        self::revoke_all(self::$current->id);
        if (class_exists('TIX_Promoter_Auth')) TIX_Promoter_Auth::revoke_sessions(intval(self::$current->id));
        return rest_ensure_response(['ok' => true]);
    }

    // ═══════════════ Daten ═══════════════

    private static function brand() {
        $b = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand() : [];
        return ['name' => (string) ($b['name'] ?? get_bloginfo('name')), 'logo' => (string) ($b['logo'] ?? ''), 'accent' => (string) ($b['accent'] ?? '#FC2F55'), 'site_url' => (string) ($b['site_url'] ?? home_url())];
    }

    /** GET /promoter/site – Marke, Anmeldung möglich, Selbst-Anmeldung (für die Anmeldeseite, öffentlich) */
    public static function rest_site(WP_REST_Request $req) {
        $s = get_option('tix_settings', []);
        $s = is_array($s) ? $s : [];
        $orgs = null;
        if (self::multi() && !empty($s['promoter_self_signup'])) {
            $orgs = [];
            foreach (get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC']) as $o) $orgs[] = ['id' => $o->ID, 'name' => html_entity_decode($o->post_title, ENT_QUOTES, 'UTF-8')];
        }
        return rest_ensure_response(['ok' => true, 'enabled' => !empty($s['promoter_enabled']), 'self_signup' => !empty($s['promoter_enabled']) && !empty($s['promoter_self_signup']),
            'brand' => self::brand(), 'studio_url' => self::url(), 'multi' => self::multi(), 'organizers' => $orgs, 'cookie_days' => intval($s['promoter_cookie_days'] ?? 30) ?: 30]);
    }

    private static function me_payload($p) {
        $org = intval($p->organizer_id ?? 0);
        return ['id' => intval($p->id), 'name' => (string) $p->display_name, 'email' => self::email_of($p), 'code' => (string) $p->promoter_code, 'status' => (string) $p->status,
            'organizer' => $org ? ['id' => $org, 'name' => html_entity_decode(get_the_title($org), ENT_QUOTES, 'UTF-8')] : null,
            'links' => TIX_Promoter_REST::links((string) $p->promoter_code, self::site_base()), 'brand' => self::brand(), 'payout_details' => self::payout_public($p->id)];
    }

    private static function site_base() {
        $b = class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::base() : '';
        return trailingslashit($b !== '' ? $b : home_url('/'));
    }

    public static function rest_me(WP_REST_Request $req) { return rest_ensure_response(['ok' => true, 'promoter' => self::me_payload(self::$current)]); }

    /** POST /promoter/me {name} – E-Mail ändert nur der Veranstalter (sie ist der Login) */
    public static function rest_me_save(WP_REST_Request $req) {
        $n = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 120);
        if ($n === '') return self::bad('name', 'Bitte einen Namen angeben.');
        TIX_Promoter_DB::update_promoter(intval(self::$current->id), ['display_name' => $n]);
        return rest_ensure_response(['ok' => true, 'promoter' => self::me_payload(TIX_Promoter_DB::get_promoter(intval(self::$current->id)))]);
    }

    private static function period(WP_REST_Request $req) {
        $ok = function ($d) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d); };
        return [$ok($req->get_param('from')) ? (string) $req->get_param('from') : '', $ok($req->get_param('to')) ? (string) $req->get_param('to') : ''];
    }

    /** Events, für die der Promoter werben darf (kommende) */
    private static function events($p) {
        $scope = TIX_Promoter::scope_event_ids($p);
        $args = ['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids', 'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']]];
        if ($scope !== null) $args['post__in'] = $scope;
        return array_map('intval', get_posts($args));
    }

    public static function rest_stats(WP_REST_Request $req) {
        $p = self::$current;
        list($from, $to) = self::period($req);
        $st = TIX_Promoter_REST::stats([intval($p->id)], $from, $to)[intval($p->id)] ?? [];
        list($now, $wait) = TIX_Promoter_DB::open_balance(intval($p->id));
        $c = $st['commission'] ?? ['open' => 0, 'approved' => 0, 'paid' => 0, 'total' => 0];
        $visitors = intval($st['visitors'] ?? 0);
        $orders = intval($st['orders'] ?? 0);
        $up = [];
        foreach (array_slice(self::events($p), 0, 5) as $eid) $up[] = ['id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($eid, '_tix_date_start', true),
            'image' => (string) (get_the_post_thumbnail_url($eid, 'medium_large') ?: '')];
        return rest_ensure_response(['ok' => true, 'from' => $from ?: null, 'to' => $to ?: null,
            'earnings' => ['payable' => $now, 'waiting' => $wait, 'in_payout' => floatval($c['approved']), 'paid' => floatval($c['paid']), 'total' => floatval($c['total'])],
            'sales' => ['orders' => $orders, 'tickets' => intval($st['tickets'] ?? 0), 'revenue' => floatval($st['revenue'] ?? 0)],
            'clicks' => ['total' => intval($st['clicks'] ?? 0), 'visitors' => $visitors], 'conversion' => $visitors > 0 ? round($orders / $visitors * 100, 1) : null,
            'last_sale' => $st['last_sale'] ?? null, 'upcoming' => $up]);
    }

    public static function rest_links(WP_REST_Request $req) {
        $p = self::$current;
        if ($p->status !== 'active') return rest_ensure_response(['ok' => true, 'pending' => true, 'general' => null, 'events' => []]);
        $out = [];
        foreach (self::events($p) as $eid) {
            $eff = TIX_Promoter::get_effective_commission(intval($p->id), $eid);
            if ($eff['source'] === 'none' && class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) continue;
            $a = TIX_Promoter_DB::get_assignment_by_promoter_event(intval($p->id), $eid, true);
            $url = (string) get_permalink($eid);
            if (class_exists('TIX_Customer_URL')) $url = TIX_Customer_URL::to($url);
            $out[] = ['event' => ['id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'date' => (string) get_post_meta($eid, '_tix_date_start', true),
                'time' => (string) get_post_meta($eid, '_tix_time_start', true), 'location' => (string) get_post_meta($eid, '_tix_location', true), 'image' => (string) (get_the_post_thumbnail_url($eid, 'large') ?: ''), 'url' => $url],
                'commission' => ['type' => $eff['type'], 'value' => floatval($eff['value'])],
                'promo_code' => $a && $a->promo_code !== '' ? (string) $a->promo_code : null,
                'discount' => $a && $a->discount_type !== '' ? ['type' => (string) $a->discount_type, 'value' => floatval($a->discount_value)] : null,
                'links' => TIX_Promoter_REST::links((string) $p->promoter_code, $url)];
        }
        return rest_ensure_response(['ok' => true, 'pending' => false, 'general' => TIX_Promoter_REST::links((string) $p->promoter_code, self::site_base()), 'events' => $out]);
    }

    /** Verkäufe ohne Käuferdaten: Datum, Event, Tickets, Umsatz, Provision, Status, Art */
    public static function rest_sales(WP_REST_Request $req) {
        list($from, $to) = self::period($req);
        $rows = array_map(function ($c) {
            return ['date' => mysql_to_rfc3339($c->created_at), 'event_id' => intval($c->event_id), 'event_title' => html_entity_decode((string) ($c->event_title ?: ''), ENT_QUOTES, 'UTF-8'),
                'type' => floatval($c->commission_amount) < 0 ? (string) $c->attribution : 'sale', 'via' => (string) $c->attribution === 'promo_code' ? 'promo_code' : 'link',
                'tickets' => intval($c->tickets_qty), 'revenue' => floatval($c->order_total), 'commission' => floatval($c->commission_amount), 'status' => (string) $c->status,
                'payable' => (string) $c->status === 'pending' && (floatval($c->commission_amount) < 0 || TIX_Promoter_DB::event_ended(intval($c->event_id)))];
        }, (array) TIX_Promoter_DB::get_commissions(array_filter(['promoter_id' => intval(self::$current->id), 'date_from' => $from, 'date_to' => $to, 'limit' => 500])));
        $by = [];
        foreach ($rows as $r) {
            $k = $r['event_id'];
            $by[$k] = $by[$k] ?? ['event_id' => $k, 'event_title' => $r['event_title'], 'tickets' => 0, 'revenue' => 0.0, 'commission' => 0.0];
            $by[$k]['tickets'] += $r['tickets']; $by[$k]['revenue'] = round($by[$k]['revenue'] + $r['revenue'], 2); $by[$k]['commission'] = round($by[$k]['commission'] + ($r['status'] === 'cancelled' ? 0 : $r['commission']), 2);
        }
        return rest_ensure_response(['ok' => true, 'sales' => $rows, 'by_event' => array_values($by)]);
    }

    public static function rest_payouts(WP_REST_Request $req) {
        return rest_ensure_response(['ok' => true, 'payouts' => array_map(function ($po) { $x = TIX_Promoter_REST::payout_item($po); unset($x['promoter_name']); return $x; }, (array) TIX_Promoter_DB::get_payouts(['promoter_id' => intval(self::$current->id)]))]);
    }

    // ═══════════════ Auszahlungsdaten ═══════════════

    private static function opt($pid) { return 'tix_promoter_payout_' . intval($pid); }

    /** Auszahlungsdaten entschlüsselt (für den Veranstalter, der überweist) */
    public static function payout_details($pid) {
        $v = get_option(self::opt($pid));
        if (!is_array($v) || empty($v['iban'])) return null;
        $iban = class_exists('TIX_Payout_Details') ? (string) TIX_Payout_Details::decrypt($v['iban']) : '';
        if ($iban === '') return null;
        return ['holder' => (string) $v['holder'], 'iban' => class_exists('TIX_Payout_Details') ? TIX_Payout_Details::format_iban($iban) : $iban, 'bic' => (string) ($v['bic'] ?? ''), 'updated' => (string) ($v['updated'] ?? '')];
    }

    private static function payout_public($pid) {
        $d = self::payout_details($pid);
        if (!$d) return null;
        $d['iban'] = class_exists('TIX_Payout_Details') ? TIX_Payout_Details::mask_iban(str_replace(' ', '', $d['iban'])) : '••••';
        return $d;
    }

    public static function rest_payout_get(WP_REST_Request $req) { return rest_ensure_response(['ok' => true, 'payout_details' => self::payout_public(self::$current->id)]); }

    /** POST /promoter/payout-details {holder, iban, bic?} – IBAN-Prüfung wie bei den Veranstaltern; danach nur noch gekürzt sichtbar */
    public static function rest_payout_save(WP_REST_Request $req) {
        if (!class_exists('TIX_Payout_Details')) return new WP_Error('tix_unavailable', 'Auszahlungsdaten sind hier nicht verfügbar.', ['status' => 503]);
        $holder = mb_substr(trim(sanitize_text_field((string) $req->get_param('holder'))), 0, 120);
        if ($holder === '') return self::bad('holder', 'Bitte den Kontoinhaber angeben.');
        $iban = TIX_Payout_Details::normalize_iban((string) $req->get_param('iban'));
        if (!TIX_Payout_Details::iban_valid($iban)) return self::bad('iban', 'Die IBAN ist nicht gültig.');
        $bic = strtoupper(preg_replace('/\s/', '', (string) $req->get_param('bic')));
        if ($bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) return self::bad('bic', 'Die BIC ist nicht gültig.');
        update_option(self::opt(self::$current->id), ['holder' => $holder, 'iban' => TIX_Payout_Details::encrypt($iban), 'bic' => $bic, 'updated' => current_time('mysql', true)], false);
        return self::rest_payout_get($req);
    }

    // ═══════════════ Selbst-Anmeldung ═══════════════

    /** POST /promoter/signup {name, email, organizer_id?} – wartet auf Freigabe durch den Veranstalter (Status pending) */
    public static function rest_signup(WP_REST_Request $req) {
        $s = get_option('tix_settings', []);
        if (!self::enabled() || empty($s['promoter_self_signup'])) return new WP_Error('tix_signup_off', 'Die Anmeldung als Promoter ist hier nicht möglich.', ['status' => 404]);
        if (self::rl('pp_signup', 5, HOUR_IN_SECONDS)) return new WP_Error('tix_rate_limit', 'Zu viele Anmeldungen – bitte später noch einmal.', ['status' => 429]);
        $name = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 120);
        $email = strtolower(sanitize_email((string) $req->get_param('email')));
        if ($name === '') return self::bad('name', 'Bitte deinen Namen angeben.');
        if (!is_email($email)) return self::bad('email', 'Bitte eine gültige E-Mail-Adresse eingeben.');
        $org = 0;
        if (self::multi()) {
            $org = absint($req->get_param('organizer_id'));
            if (!$org || get_post_type($org) !== 'tix_organizer' || get_post_status($org) !== 'publish') return self::bad('organizer_id', 'Bitte einen Veranstalter wählen.');
        }
        $ok = rest_ensure_response(['ok' => true, 'sent' => true, 'message' => 'Danke! Wir haben dir eine Mail geschickt. Sobald der Veranstalter dich freigibt, findest du im Promoter-Bereich deine Links.']);
        global $wpdb;
        $exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Promoter_DB::table_promoters() . " WHERE LOWER(email) = %s AND organizer_id = %d AND status IN ('active','pending') LIMIT 1", $email, $org));
        if ($exists) { self::send_login($exists); return $ok; } // gleiche Antwort, Zugang nur per Mail
        $base = preg_replace('/[^a-z0-9]/', '', sanitize_title(mb_substr($name, 0, 12))) ?: 'promo';
        if (strlen($base) < 3) $base .= 'promo';
        do { $code = $base . wp_rand(10, 9999); } while (TIX_Promoter_DB::get_promoter_by_code($code) || $wpdb->get_var($wpdb->prepare("SELECT id FROM " . TIX_Promoter_DB::table_promoters() . " WHERE promoter_code = %s", $code)));
        $pid = TIX_Promoter_DB::insert_promoter(['user_id' => 0, 'organizer_id' => $org, 'email' => $email, 'promoter_code' => $code, 'display_name' => $name, 'status' => 'pending', 'notes' => 'Self-Signup']);
        if (!$pid) return new WP_Error('tix_db', 'Die Anmeldung hat nicht geklappt.', ['status' => 500]);
        self::send_login(TIX_Promoter_DB::get_promoter($pid), 'signup');
        do_action('tix_promoter_signup', $pid, $org);
        return $ok;
    }

    // ═══════════════ Alte WordPress-Seite ═══════════════

    /** Seite mit [tix_promoter_dashboard] bzw. alter Magic-Link (?tix_pauth=) → Studio */
    public static function redirect_wp_page() {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        $legacy = isset($_GET['tix_pauth']) ? sanitize_text_field((string) $_GET['tix_pauth']) : '';
        $post = get_queried_object();
        $is_page = $post instanceof WP_Post && has_shortcode((string) $post->post_content, 'tix_promoter_dashboard');
        if ($legacy === '' && !$is_page) return;
        wp_redirect($legacy !== '' ? add_query_arg('legacy', rawurlencode($legacy), self::url('anmelden/')) : self::url(), 302, 'Tixomat');
        exit;
    }
}

TIX_Promoter_Portal::init();
