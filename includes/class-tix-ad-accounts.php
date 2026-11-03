<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Ad_Accounts – Werbekonten (Meta, Google Ads, TikTok) und Werbekosten per API (Statistik Stufe 2, F5 = B,
 * docs/plan-statistik-marketing.md §12.2, docs/checkliste-ads-antraege.md). Modul „Marketing“ (P1–P4).
 *
 * Ablauf:
 *  1. Studio → POST /marketing/ads/start {platform} → Adresse der OAuth-Weiche connect.evendis.de/oauth/<netz>/start
 *     mit `site` und einem signierten Einmal-Ticket {o: Veranstalter, n, exp} (HMAC mit dem Seiten-Secret, Option tix_connect).
 *  2. Die Weiche meldet beim Netz an, tauscht den Code (Secrets liegen nur dort) und leitet mit ?connect=<id> zurück ins Studio.
 *  3. Studio → POST /marketing/ads/claim {connect_id}: das Plugin holt das Token signiert bei der Weiche ab (einmal, 5 min),
 *     prüft den Veranstalter und listet die Werbekonten. POST /marketing/ads/select {pending, account_id} speichert die
 *     Verbindung; Token verschlüsselt (AES-256-GCM, Schlüssel aus den WordPress-Salts).
 *  4. Cron stündlich: heutiger Tag; um 4 Uhr die letzten 7 Tage (Netze korrigieren nachträglich); nach dem Verbinden 30 Tage.
 *     Kosten in {prefix}tix_ad_costs (day, organizer_id, platform, account_id, campaign_id, campaign_name, spend, impressions,
 *     clicks, currency). Google über den Proxy der Weiche (Developer-Token liegt nur dort), Meta/TikTok direkt.
 *  5. Zuordnung: utm_id (Kampagnen-ID aus der URL-Vorlage) an Bestellung und Zählung; sonst Name = utm_campaign; sonst
 *     von Hand (POST /marketing/ads/map). Notlösung ohne API: Kosten von Hand (/marketing/costs).
 *
 * Löschzusagen (Rechtstexte, Nutzungsbedingungen/Datenlöschung):
 *  (a) „Verbindung trennen“ (DELETE /marketing/ads/{id}) löscht sofort Token, Kontoangaben und alle Kennzahlen des Kontos.
 *  (b) Entzieht der Nutzer den Zugriff beim Netz, erkennt das der stündliche Abruf (ungültiges Token) und löscht sofort;
 *      der tägliche Prüflauf ruft jedes Konto mindestens einmal ab → spätestens nach 24 h.
 *  (c) Endet das Veranstalter-Konto oder das Modul, löscht der tägliche Prüflauf nach 30 Tagen alles (Merker off_since).
 *
 * „Bald verfügbar“: Ein Netz ist verbindbar, wenn die Weiche es eingerichtet meldet (GET /api/connect/networks) UND es
 * live ist (Option tix_ads_live {meta|google|tiktok: true} nach der Freigabe) – im Testbetrieb nur für Admins und den
 * Prüfer-Zugang (User-Meta _tix_ads_tester). Die Eingabe von Hand geht immer.
 */
class TIX_Ad_Accounts {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const PLATFORMS = ['meta' => 'Meta (Facebook & Instagram)', 'google' => 'Google Ads', 'tiktok' => 'TikTok'];
    const OPT_CONNECT = 'tix_connect';      // {site_id, secret, base}
    const OPT_LIVE = 'tix_ads_live';        // {meta: bool, google: bool, tiktok: bool}
    const OPT_TRIAL = 'tix_marketing_trial_until';
    const TRIAL_DAYS = 30;
    const KEEP_AFTER_END = 30;              // (c)
    const META_TESTER = '_tix_ads_tester';
    const CRON = 'tix_ads_sync';

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_create'], 6);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 300, 'hourly', self::CRON); }, 50);
        // Veranstalter gelöscht → Werbedaten weg (c, sofort statt 30 Tage, da es kein Konto mehr gibt)
        add_action('before_delete_post', function ($id) { if (get_post_type($id) === 'tix_organizer') self::purge_organizer(intval($id)); });
    }

    // ═══════════════ Tabellen ═══════════════

    public static function t($n) { global $wpdb; return $wpdb->prefix . 'tix_ad_' . $n; }

    public static function maybe_create() {
        if (get_option('tix_ad_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t('connections') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            platform VARCHAR(10) NOT NULL,
            account_id VARCHAR(64) NOT NULL,
            account_name VARCHAR(190) NOT NULL DEFAULT '',
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            login_customer_id VARCHAR(32) NOT NULL DEFAULT '',
            token_enc TEXT NULL,
            refresh_enc TEXT NULL,
            expires_at DATETIME NULL,
            meta TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'ok',
            backfilled TINYINT(1) NOT NULL DEFAULT 0,
            last_sync DATETIME NULL,
            last_error VARCHAR(255) NOT NULL DEFAULT '',
            created DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
            updated DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY acct (organizer_id, platform, account_id)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('costs') . " (
            day DATE NOT NULL,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            platform VARCHAR(10) NOT NULL,
            account_id VARCHAR(64) NOT NULL,
            campaign_id VARCHAR(64) NOT NULL,
            campaign_name VARCHAR(190) NOT NULL DEFAULT '',
            spend DECIMAL(12,2) NOT NULL DEFAULT 0,
            impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            PRIMARY KEY  (day, organizer_id, platform, account_id, campaign_id),
            KEY org_day (organizer_id, day)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('manual') . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            campaign VARCHAR(100) NOT NULL DEFAULT '',
            label VARCHAR(120) NOT NULL DEFAULT '',
            date_from DATE NOT NULL,
            date_to DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
            PRIMARY KEY  (id),
            KEY org (organizer_id)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('map') . " (
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            platform VARCHAR(10) NOT NULL,
            campaign_id VARCHAR(64) NOT NULL,
            campaign VARCHAR(100) NOT NULL DEFAULT '',
            PRIMARY KEY  (organizer_id, platform, campaign_id)
        ) $c;");
        update_option('tix_ad_db', self::DB_VERSION, true);
    }

    // ═══════════════ Modul, Test, Rechte ═══════════════

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    /** Veranstalter des Aufrufers (Plattform), Einzel-Seite 0; Admin optional ?organizer= */
    public static function org(WP_REST_Request $req = null) {
        if (!self::multi()) return 0;
        if (current_user_can('manage_options')) return $req ? max(0, intval($req->get_param('organizer'))) : 0;
        return intval(TIX_App_Scope::organizer_id_for_user());
    }

    public static function trial_until() { return intval(get_option(self::OPT_TRIAL, 0)); }
    public static function trial_active() { $u = self::trial_until(); return $u > time(); }

    public static function module_on($org) {
        return class_exists('TIX_Features') && TIX_Features::on('marketing', intval($org));
    }

    /** Rechte: Inhaber/Team-Admin/Admin (wie Statistik) */
    public static function perm(WP_REST_Request $req) { return TIX_Studio_Stats::perm_manager($req); }

    /** Zusätzlich: Modul an (sonst 403 tix_module_off mit Hinweis auf den Test) */
    public static function perm_module(WP_REST_Request $req) {
        $p = self::perm($req);
        if ($p !== true) return $p;
        if (!self::module_on(self::org($req))) return new WP_Error('tix_module_off', 'Werbekonten gehören zum Modul Marketing.', ['status' => 403]);
        return true;
    }

    /** Darf der Nutzer ein Netz im Testbetrieb verbinden? (Admin oder Prüfer-Zugang) */
    public static function tester($uid = 0) {
        $uid = $uid ?: get_current_user_id();
        return user_can($uid, 'manage_options') || get_user_meta($uid, self::META_TESTER, true) === '1';
    }

    // ═══════════════ Verschlüsselung ═══════════════

    private static function key() {
        return hash('sha256', 'tix-ads|' . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') . '|' . wp_salt('secure_auth'), true);
    }

    public static function enc($plain) {
        if ($plain === null || $plain === '') return null;
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt((string) $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? null : base64_encode($iv . $tag . $ct);
    }

    public static function dec($blob) {
        if (!$blob) return '';
        $raw = base64_decode((string) $blob, true);
        if ($raw === false || strlen($raw) < 29) return '';
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? '' : $p;
    }

    // ═══════════════ Weiche (connect.evendis.de) ═══════════════

    public static function connect_cfg() {
        $c = get_option(self::OPT_CONNECT, []);
        $c = is_array($c) ? $c : [];
        return ['site_id' => (string) ($c['site_id'] ?? ''), 'secret' => (string) ($c['secret'] ?? ''), 'base' => rtrim((string) ($c['base'] ?? 'https://connect.evendis.de'), '/')];
    }

    private static function sign($msg) { return hash_hmac('sha256', $msg, self::connect_cfg()['secret']); }

    /** POST an die Weiche mit Signatur; → [status, array] oder WP_Error */
    private static function hub($path, array $body, $sig_msg) {
        $c = self::connect_cfg();
        if ($c['site_id'] === '' || $c['secret'] === '') return new WP_Error('tix_connect_missing', 'Diese Seite ist noch nicht mit connect.evendis.de verbunden.', ['status' => 503]);
        $body['site'] = $c['site_id'];
        $body['ts'] = time();
        $body['sig'] = self::sign(str_replace('{ts}', (string) $body['ts'], $sig_msg));
        $r = wp_remote_post($c['base'] . $path, ['timeout' => 25, 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'], 'body' => wp_json_encode($body)]);
        if (is_wp_error($r)) return new WP_Error('tix_connect_down', 'connect.evendis.de ist gerade nicht erreichbar.', ['status' => 502]);
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        return [intval(wp_remote_retrieve_response_code($r)), is_array($j) ? $j : []];
    }

    /** Netze der Weiche (10 min zwischengespeichert): {meta: {configured, mode}, …} */
    public static function networks() {
        $c = self::connect_cfg();
        $k = 'tix_ads_networks_' . md5($c['base']);
        $v = get_transient($k);
        if (is_array($v)) return $v;
        $v = [];
        $r = wp_remote_get($c['base'] . '/api/connect/networks', ['timeout' => 6, 'headers' => ['Accept' => 'application/json']]);
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
            $j = json_decode((string) wp_remote_retrieve_body($r), true);
            foreach (array_keys(self::PLATFORMS) as $p) $v[$p] = ['configured' => !empty($j[$p]['configured']), 'mode' => ($j[$p]['mode'] ?? 'test') === 'live' ? 'live' : 'test'];
        }
        set_transient($k, $v, $v ? 600 : 120);
        return $v;
    }

    /** Netz-Zustand für das Studio: available (verbindbar), soon („Bald verfügbar“), reason */
    public static function network_state($p) {
        $live = (array) get_option(self::OPT_LIVE, []);
        $n = self::networks()[$p] ?? ['configured' => false, 'mode' => 'test'];
        $cfg = self::connect_cfg();
        if ($cfg['site_id'] === '') return ['available' => false, 'soon' => true, 'reason' => 'not_connected'];
        if (!$n['configured']) return ['available' => false, 'soon' => true, 'reason' => 'not_configured'];
        if (!empty($live[$p]) && $n['mode'] === 'live') return ['available' => true, 'soon' => false, 'reason' => ''];
        return self::tester() ? ['available' => true, 'soon' => true, 'reason' => 'test'] : ['available' => false, 'soon' => true, 'reason' => 'review'];
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $r = function ($path, $methods, $cb, $perm = 'perm_module') {
            register_rest_route(self::NS, $path, ['methods' => $methods, 'callback' => [__CLASS__, $cb], 'permission_callback' => [__CLASS__, $perm]]);
        };
        $r('/marketing/ads', 'GET', 'rest_status', 'perm');
        $r('/marketing/trial', 'POST', 'rest_trial', 'perm');
        $r('/marketing/ads/start', 'POST', 'rest_start');
        $r('/marketing/ads/claim', 'POST', 'rest_claim');
        $r('/marketing/ads/select', 'POST', 'rest_select');
        $r('/marketing/ads/(?P<id>\d+)', 'DELETE', 'rest_disconnect', 'perm');
        $r('/marketing/ads/(?P<id>\d+)/sync', 'POST', 'rest_sync');
        $r('/marketing/ads/map', 'POST', 'rest_map');
        $r('/marketing/costs', 'GET', 'rest_manual_list');
        $r('/marketing/costs', 'POST', 'rest_manual_save');
        $r('/marketing/costs/(?P<id>\d+)', 'DELETE', 'rest_manual_delete');
        $r('/marketing/campaigns', 'GET', 'rest_campaigns');
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }

    private static function conn_public(array $c) {
        $m = json_decode((string) ($c['meta'] ?? ''), true);
        return ['id' => intval($c['id']), 'platform' => $c['platform'], 'label' => self::PLATFORMS[$c['platform']] ?? $c['platform'], 'account_id' => $c['account_id'],
            'account_name' => $c['account_name'], 'currency' => $c['currency'], 'status' => $c['status'], 'last_sync' => $c['last_sync'], 'last_error' => $c['last_error'],
            'url_template' => self::url_template($c['platform']), 'test' => !empty($m['test'])];
    }

    /** URL-Vorlage je Netz: Kampagnen-ID der Werbung landet als utm_id an Aufruf und Bestellung */
    public static function url_template($p) {
        switch ($p) {
            case 'meta': return ['field' => 'URL-Parameter (url_tags)', 'value' => 'utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.name}}&utm_id={{campaign.id}}&utm_content={{ad.name}}'];
            case 'google': return ['field' => 'Suffix der finalen URL (final_url_suffix)', 'value' => 'utm_source=google&utm_medium=cpc&utm_campaign={_campaign}&utm_id={campaignid}'];
            case 'tiktok': return ['field' => 'URL-Parameter', 'value' => 'utm_source=tiktok&utm_medium=paid&utm_campaign=__CAMPAIGN_NAME__&utm_id=__CAMPAIGN_ID__'];
        }
        return null;
    }

    public static function rest_status(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        $on = self::module_on($org);
        $multi = self::multi();
        $nets = [];
        foreach (self::PLATFORMS as $p => $label) $nets[$p] = ['key' => $p, 'label' => $label] + self::network_state($p);
        $conns = $on ? array_map([__CLASS__, 'conn_public'], (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('connections') . " WHERE organizer_id = %d ORDER BY platform, account_name", $org), ARRAY_A)) : [];
        $until = self::trial_until();
        $notices = get_option('tix_ads_revoked_notice', []);
        $revoked = is_array($notices) ? array_keys((array) ($notices[$org] ?? [])) : [];
        if ($revoked) { unset($notices[$org]); update_option('tix_ads_revoked_notice', $notices, false); }
        return rest_ensure_response(['ok' => true, 'revoked' => $revoked, 'module' => [
            'on' => $on, 'free' => $multi, 'trial' => !$multi && self::trial_active(), 'trial_until' => $until ? gmdate('c', $until) : null,
            'trial_available' => !$multi && !$on && $until === 0, 'booked' => !$multi && $on && !self::trial_active(),
        ], 'networks' => array_values($nets), 'connections' => $conns, 'site_connected' => self::connect_cfg()['site_id'] !== '']);
    }

    /** POST /marketing/trial → 30 Tage Test (einmal je Seite, nur Einzel-Seiten) */
    public static function rest_trial(WP_REST_Request $req) {
        if (self::multi()) return self::bad('trial', 'Auf der Ticketplattform ist das Modul kostenlos.', 'tix_trial_not_needed');
        if (self::trial_until() > 0) return self::bad('trial', 'Der Test lief auf dieser Seite schon.', 'tix_trial_used', 409);
        if (self::module_on(0)) return self::bad('trial', 'Das Modul ist schon an.', 'tix_trial_not_needed');
        update_option(self::OPT_TRIAL, time() + self::TRIAL_DAYS * DAY_IN_SECONDS, false);
        return self::rest_status($req);
    }

    /** POST /marketing/ads/start {platform} → {url} (Weiche mit signiertem Einmal-Ticket) */
    public static function rest_start(WP_REST_Request $req) {
        $p = (string) $req->get_param('platform');
        if (!isset(self::PLATFORMS[$p])) return self::bad('platform', 'Unbekanntes Werbenetz.');
        $st = self::network_state($p);
        if (!$st['available']) return new WP_Error('tix_ads_soon', 'Diese Anbindung ist bald verfügbar.', ['status' => 409, 'reason' => $st['reason']]);
        $c = self::connect_cfg();
        $payload = rtrim(strtr(base64_encode(wp_json_encode(['o' => self::org($req), 'u' => get_current_user_id(), 'n' => wp_generate_password(16, false), 'exp' => time() + 600])), '+/', '-_'), '=');
        $ticket = $payload . '.' . self::sign($payload);
        return rest_ensure_response(['ok' => true, 'url' => $c['base'] . '/oauth/' . $p . '/start?' . http_build_query(['site' => $c['site_id'], 'ticket' => $ticket])]);
    }

    /** POST /marketing/ads/claim {connect_id} → {pending, platform, accounts[]} */
    public static function rest_claim(WP_REST_Request $req) {
        $id = (string) $req->get_param('connect_id');
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $id)) return self::bad('connect_id', 'Ungültige Anmeldung. Bitte noch einmal verbinden.');
        $r = self::hub('/api/connect/claim', ['id' => $id], 'claim|' . $id . '|{ts}');
        if (is_wp_error($r)) return $r;
        list($code, $j) = $r;
        if ($code !== 200 || empty($j['token'])) return new WP_Error('tix_connect_claim', 'Die Anmeldung ist abgelaufen. Bitte noch einmal verbinden.', ['status' => 410]);
        $p = (string) ($j['network'] ?? '');
        if (!isset(self::PLATFORMS[$p])) return new WP_Error('tix_connect_claim', 'Unbekanntes Werbenetz.', ['status' => 400]);
        // Ticket gehört zum Aufrufer (Veranstalter und Nutzer)
        $o = intval($j['ticket']['o'] ?? -1);
        if ($o !== self::org($req) || intval($j['ticket']['u'] ?? 0) !== get_current_user_id()) return new WP_Error('rest_forbidden', 'Diese Anmeldung gehört zu einem anderen Konto.', ['status' => 403]);
        $tok = (array) $j['token'];
        $accounts = self::list_accounts($p, $tok);
        if (is_wp_error($accounts)) return $accounts;
        $pending = wp_generate_password(24, false);
        set_transient('tix_ads_pending_' . $pending, ['enc' => self::enc(wp_json_encode($tok)), 'platform' => $p, 'org' => $o, 'u' => get_current_user_id(), 'accounts' => $accounts, 'test' => ($j['mode'] ?? 'test') !== 'live'], 15 * MINUTE_IN_SECONDS);
        return rest_ensure_response(['ok' => true, 'pending' => $pending, 'platform' => $p, 'accounts' => array_values($accounts)]);
    }

    /** POST /marketing/ads/select {pending, account_id} → Verbindung (30 Tage werden nachgeladen) */
    public static function rest_select(WP_REST_Request $req) {
        global $wpdb;
        $pid = (string) $req->get_param('pending');
        $pend = preg_match('/^[A-Za-z0-9]{24}$/', $pid) ? get_transient('tix_ads_pending_' . $pid) : false;
        if (!is_array($pend) || intval($pend['u']) !== get_current_user_id() || intval($pend['org']) !== self::org($req)) return self::bad('pending', 'Die Auswahl ist abgelaufen. Bitte noch einmal verbinden.', 'tix_connect_claim', 410);
        $aid = (string) $req->get_param('account_id');
        $acc = $pend['accounts'][$aid] ?? null;
        if (!$acc) return self::bad('account_id', 'Dieses Werbekonto gehört nicht zur Anmeldung.');
        $tok = json_decode(self::dec($pend['enc']), true) ?: [];
        $now = current_time('mysql', true);
        $row = ['organizer_id' => intval($pend['org']), 'platform' => $pend['platform'], 'account_id' => $aid, 'account_name' => mb_substr((string) $acc['name'], 0, 190),
            'currency' => strtoupper(substr((string) ($acc['currency'] ?: 'EUR'), 0, 3)), 'login_customer_id' => (string) ($acc['login_customer_id'] ?? ''),
            'token_enc' => self::enc((string) ($tok['access_token'] ?? '')), 'refresh_enc' => self::enc((string) ($tok['refresh_token'] ?? '')),
            'expires_at' => !empty($tok['expires_in']) ? gmdate('Y-m-d H:i:s', time() + intval($tok['expires_in'])) : null,
            'meta' => wp_json_encode(['test' => !empty($pend['test']), 'graph' => (string) ($tok['graph'] ?? ''), 'api_base' => (string) ($tok['api_base'] ?? '')]),
            'status' => 'ok', 'backfilled' => 0, 'last_error' => '', 'updated' => $now];
        $t = self::t('connections');
        $ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE organizer_id = %d AND platform = %s AND account_id = %s", $row['organizer_id'], $row['platform'], $aid));
        if ($ex) $wpdb->update($t, $row, ['id' => intval($ex)]); else { $row['created'] = $now; $wpdb->insert($t, $row); $ex = $wpdb->insert_id; }
        delete_transient('tix_ads_pending_' . $pid);
        $c = self::conn(intval($ex));
        self::sync($c);
        return rest_ensure_response(['ok' => true, 'connection' => self::conn_public(self::conn(intval($ex)))]);
    }

    /** DELETE /marketing/ads/{id} – (a) sofort: Token, Kontoangaben, Kennzahlen */
    public static function rest_disconnect(WP_REST_Request $req) {
        $c = self::conn(intval($req['id']));
        if (!$c || intval($c['organizer_id']) !== self::org($req)) return new WP_Error('not_found', 'Verbindung nicht gefunden.', ['status' => 404]);
        self::purge_connection($c);
        return rest_ensure_response(['ok' => true, 'deleted' => true]);
    }

    public static function rest_sync(WP_REST_Request $req) {
        $c = self::conn(intval($req['id']));
        if (!$c || intval($c['organizer_id']) !== self::org($req)) return new WP_Error('not_found', 'Verbindung nicht gefunden.', ['status' => 404]);
        if (get_transient('tix_ads_sync_lock_' . $c['id'])) return new WP_Error('tix_rate_limit', 'Gerade erst abgerufen. Bitte kurz warten.', ['status' => 429]);
        set_transient('tix_ads_sync_lock_' . $c['id'], 1, 120);
        self::sync($c, 7);
        $c = self::conn(intval($c['id']));
        return rest_ensure_response(['ok' => (bool) $c, 'connection' => $c ? self::conn_public($c) : null]);
    }

    /** POST /marketing/ads/map {platform, campaign_id, campaign} – Werbekampagne einer Studio-Kampagne (utm_campaign) zuordnen; leer = lösen */
    public static function rest_map(WP_REST_Request $req) {
        global $wpdb;
        $p = (string) $req->get_param('platform'); $cid = (string) $req->get_param('campaign_id');
        if (!isset(self::PLATFORMS[$p]) || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $cid)) return self::bad('campaign_id', 'Unbekannte Werbekampagne.');
        $cmp = TIX_Web_Stats::norm((string) $req->get_param('campaign'));
        $org = self::org($req);
        if ($cmp === '') $wpdb->delete(self::t('map'), ['organizer_id' => $org, 'platform' => $p, 'campaign_id' => $cid]);
        else $wpdb->replace(self::t('map'), ['organizer_id' => $org, 'platform' => $p, 'campaign_id' => $cid, 'campaign' => $cmp]);
        return rest_ensure_response(['ok' => true]);
    }

    // ── Kosten von Hand (Plakate, Radio, Influencer) ──

    public static function rest_manual_list(WP_REST_Request $req) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, campaign, label, date_from, date_to, amount, currency, note FROM " . self::t('manual') . " WHERE organizer_id = %d ORDER BY date_from DESC, id DESC LIMIT 500", self::org($req)), ARRAY_A);
        return rest_ensure_response(['ok' => true, 'costs' => array_map(function ($r) { $r['id'] = intval($r['id']); $r['amount'] = floatval($r['amount']); return $r; }, (array) $rows)]);
    }

    public static function rest_manual_save(WP_REST_Request $req) {
        global $wpdb;
        $label = mb_substr(sanitize_text_field((string) $req->get_param('label')), 0, 120);
        $cmp = TIX_Web_Stats::norm((string) $req->get_param('campaign'));
        $from = (string) $req->get_param('date_from'); $to = (string) ($req->get_param('date_to') ?: $from);
        $amount = round(floatval(str_replace(',', '.', (string) $req->get_param('amount'))), 2);
        if ($label === '' && $cmp === '') return self::bad('label', 'Bitte einen Namen oder eine Kampagne angeben.');
        foreach (['date_from' => $from, 'date_to' => $to] as $k => $d) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return self::bad($k, 'Bitte ein Datum im Format JJJJ-MM-TT angeben.');
        if ($to < $from) return self::bad('date_to', '„Bis“ liegt vor „Von“.');
        if ($amount <= 0 || $amount > 1000000) return self::bad('amount', 'Bitte einen Betrag zwischen 0,01 und 1.000.000 € angeben.');
        $wpdb->insert(self::t('manual'), ['organizer_id' => self::org($req), 'campaign' => $cmp, 'label' => $label ?: $cmp, 'date_from' => $from, 'date_to' => $to, 'amount' => $amount, 'currency' => 'EUR',
            'note' => mb_substr(sanitize_text_field((string) $req->get_param('note')), 0, 255), 'created' => current_time('mysql', true)]);
        return self::rest_manual_list($req);
    }

    public static function rest_manual_delete(WP_REST_Request $req) {
        global $wpdb;
        $n = $wpdb->delete(self::t('manual'), ['id' => intval($req['id']), 'organizer_id' => self::org($req)]);
        return $n ? self::rest_manual_list($req) : new WP_Error('not_found', 'Eintrag nicht gefunden.', ['status' => 404]);
    }

    // ── Kampagnen mit Kosten ──

    /**
     * GET /marketing/campaigns?from&to → {rows[{key, campaign, platform, campaign_id, name, spend, impressions, clicks, views,
     * orders, tickets, revenue, cpa, roas, mapped_by: utm_id|map|name|manual|none}], totals}
     */
    public static function rest_campaigns(WP_REST_Request $req) {
        global $wpdb;
        $to = (string) ($req->get_param('to') ?: current_time('Y-m-d'));
        $from = (string) ($req->get_param('from') ?: gmdate('Y-m-d', strtotime($to) - 29 * DAY_IN_SECONDS));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) return self::bad('from', 'Bitte einen gültigen Zeitraum angeben.');
        $org = self::org($req);
        $ids = TIX_Studio_Stats::scope_ids();
        // Werbekosten je Kampagne
        $ads = (array) $wpdb->get_results($wpdb->prepare("SELECT platform, campaign_id, MAX(campaign_name) name, SUM(spend) spend, SUM(impressions) imp, SUM(clicks) clk, MAX(currency) cur FROM " . self::t('costs') . " WHERE organizer_id = %d AND day BETWEEN %s AND %s GROUP BY platform, campaign_id", $org, $from, $to), ARRAY_A);
        $map = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT platform, campaign_id, campaign FROM " . self::t('map') . " WHERE organizer_id = %d", $org), ARRAY_A) as $m) $map[$m['platform'] . '|' . $m['campaign_id']] = $m['campaign'];
        // unsere Zahlen: bezahlte Website-Bestellungen mit utm_id/utm_campaign, Aufrufe der Event-Seiten je utm_id/utm_campaign
        $res = TIX_Sales_Figures::for_events($ids, $from, $to);
        $paid = []; $rev = [];
        foreach ($res['rows'] as $r) if ($r->money['paid']) { $paid[intval($r->id)] = 1; $rev[intval($r->id)] = ($rev[intval($r->id)] ?? 0.0) + $r->money['net']; }
        $attr = $paid ? TIX_Order_Attribution::many(array_keys($paid), ['campaign', 'utm_id', 'source']) : [];
        $by_uid = []; $by_cmp = [];
        $add = function (array &$b, $k, array $x) {
            if ($k === '') return;
            if (!isset($b[$k])) $b[$k] = ['o' => 0, 't' => 0, 'r' => 0.0, 'v' => 0];
            foreach ($x as $f => $v) $b[$k][$f] += $v;
        };
        foreach ($attr as $oid => $a) {
            $x = ['o' => 1, 't' => intval($res['order_tickets'][$oid] ?? 0), 'r' => floatval($rev[$oid] ?? 0)];
            $add($by_uid, (string) ($a['utm_id'] ?? ''), $x);
            $add($by_cmp, TIX_Web_Stats::norm($a['campaign'] ?? ''), $x);
        }
        $d = TIX_Web_Stats::dim_table();
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            if (!$chunk) continue;
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT campaign, utm_id, SUM(n) n FROM $d WHERE day BETWEEN %s AND %s AND step = 'event' AND channel = 'web' AND event_id IN (" . implode(',', $chunk) . ") AND (campaign <> '' OR utm_id <> '') GROUP BY campaign, utm_id", $from, $to)) as $r) {
                $add($by_uid, (string) $r->utm_id, ['v' => intval($r->n)]);
                $add($by_cmp, (string) $r->campaign, ['v' => intval($r->n)]);
            }
        }
        $rows = []; $used_cmp = [];
        foreach ($ads as $a) {
            $how = 'none'; $ours = null; $cmp = '';
            if (isset($by_uid[$a['campaign_id']])) { $how = 'utm_id'; $ours = $by_uid[$a['campaign_id']]; }
            elseif (isset($map[$a['platform'] . '|' . $a['campaign_id']]) && isset($by_cmp[$map[$a['platform'] . '|' . $a['campaign_id']]])) { $how = 'map'; $cmp = $map[$a['platform'] . '|' . $a['campaign_id']]; $ours = $by_cmp[$cmp]; }
            elseif (($n = TIX_Web_Stats::norm($a['name'])) !== '' && isset($by_cmp[$n])) { $how = 'name'; $cmp = $n; $ours = $by_cmp[$n]; }
            if ($cmp !== '') $used_cmp[$cmp] = 1;
            $rows[] = self::row(['key' => $a['platform'] . ':' . $a['campaign_id'], 'platform' => $a['platform'], 'campaign_id' => $a['campaign_id'], 'name' => $a['name'], 'campaign' => $cmp ?: ($map[$a['platform'] . '|' . $a['campaign_id']] ?? ''),
                'spend' => floatval($a['spend']), 'impressions' => intval($a['imp']), 'clicks' => intval($a['clk']), 'currency' => $a['cur'], 'mapped_by' => $how], $ours);
        }
        // Kosten von Hand: anteilig nach Tagen im Zeitraum
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('manual') . " WHERE organizer_id = %d AND date_to >= %s AND date_from <= %s", $org, $from, $to), ARRAY_A) as $m) {
            $all = (strtotime($m['date_to']) - strtotime($m['date_from'])) / DAY_IN_SECONDS + 1;
            $in = (strtotime(min($m['date_to'], $to)) - strtotime(max($m['date_from'], $from))) / DAY_IN_SECONDS + 1;
            $c = $m['campaign'];
            $ours = $c !== '' && empty($used_cmp[$c]) && isset($by_cmp[$c]) ? $by_cmp[$c] : null;
            if ($ours) $used_cmp[$c] = 1;
            $rows[] = self::row(['key' => 'manual:' . $m['id'], 'platform' => 'manual', 'campaign_id' => (string) $m['id'], 'name' => $m['label'], 'campaign' => $c,
                'spend' => round(floatval($m['amount']) * max(0, $in) / max(1, $all), 2), 'impressions' => null, 'clicks' => null, 'currency' => $m['currency'], 'mapped_by' => $ours ? 'manual' : 'none'], $ours);
        }
        // Studio-Kampagnen mit Käufen, aber ohne Kosten (Kampagnen ohne Kosten gehören in die Basis)
        foreach ($by_cmp as $c => $o) if (empty($used_cmp[$c]) && $o['o'] > 0) $rows[] = self::row(['key' => 'utm:' . $c, 'platform' => 'utm', 'campaign_id' => '', 'name' => $c, 'campaign' => $c, 'spend' => null, 'impressions' => null, 'clicks' => null, 'currency' => 'EUR', 'mapped_by' => 'none'], $o);
        usort($rows, function ($a, $b) { return ($b['spend'] ?? 0) <=> ($a['spend'] ?? 0) ?: $b['revenue'] <=> $a['revenue']; });
        $tot = ['spend' => 0.0, 'orders' => 0, 'revenue' => 0.0];
        foreach ($rows as $r) { $tot['spend'] += floatval($r['spend']); if ($r['spend'] !== null) { $tot['orders'] += $r['orders']; $tot['revenue'] += $r['revenue']; } }
        $tot['cpa'] = $tot['orders'] ? round($tot['spend'] / $tot['orders'], 2) : null;
        $tot['roas'] = $tot['spend'] > 0 ? round($tot['revenue'] / $tot['spend'], 2) : null;
        $tot['spend'] = round($tot['spend'], 2); $tot['revenue'] = round($tot['revenue'], 2);
        return rest_ensure_response(['ok' => true, 'range' => ['from' => $from, 'to' => $to], 'rows' => $rows, 'totals' => $tot]);
    }

    private static function row(array $r, $ours) {
        $r['views'] = $ours ? intval($ours['v']) : 0;
        $r['orders'] = $ours ? intval($ours['o']) : 0;
        $r['tickets'] = $ours ? intval($ours['t']) : 0;
        $r['revenue'] = $ours ? round(floatval($ours['r']), 2) : 0.0;
        $r['cpa'] = $r['spend'] !== null && $r['orders'] ? round($r['spend'] / $r['orders'], 2) : null;
        $r['roas'] = $r['spend'] ? round($r['revenue'] / $r['spend'], 2) : null;
        return $r;
    }

    // ═══════════════ Konten der Netze ═══════════════

    /** Werbekonten zur Anmeldung: [account_id => {id, name, currency, login_customer_id?}] */
    public static function list_accounts($p, array $tok) {
        $at = (string) ($tok['access_token'] ?? '');
        if ($p === 'meta') {
            $v = preg_match('/^v\d+\.\d+$/', (string) ($tok['graph'] ?? '')) ? $tok['graph'] : 'v21.0';
            $r = self::get_json("https://graph.facebook.com/$v/me/adaccounts?" . http_build_query(['fields' => 'account_id,name,currency', 'limit' => 100, 'access_token' => $at]));
            if (is_wp_error($r)) return $r;
            $out = [];
            foreach ((array) ($r['data'] ?? []) as $a) if (!empty($a['account_id'])) $out[(string) $a['account_id']] = ['id' => (string) $a['account_id'], 'name' => (string) ($a['name'] ?? $a['account_id']), 'currency' => (string) ($a['currency'] ?? 'EUR')];
            return $out;
        }
        if ($p === 'google') {
            $r = self::google(['op' => 'list', 'access_token' => $at]);
            if (is_wp_error($r)) return $r;
            $out = [];
            foreach (array_slice((array) ($r['accounts'] ?? []), 0, 50) as $a) {
                $id = preg_replace('/\D/', '', (string) ($a['id'] ?? ''));
                if ($id !== '' && empty($a['manager'])) $out[$id] = ['id' => $id, 'name' => (string) ($a['name'] ?? $id), 'currency' => (string) ($a['currency'] ?? 'EUR'), 'login_customer_id' => preg_replace('/\D/', '', (string) ($a['login_customer_id'] ?? ''))];
            }
            return $out;
        }
        if ($p === 'tiktok') {
            $base = self::tiktok_base($tok);
            $ids = array_values(array_filter(array_map('strval', (array) ($tok['advertiser_ids'] ?? []))));
            if (!$ids) return [];
            $r = self::get_json("$base/v1.3/advertiser/info/?" . http_build_query(['advertiser_ids' => wp_json_encode(array_slice($ids, 0, 100)), 'fields' => wp_json_encode(['advertiser_id', 'name', 'currency'])]), ['Access-Token' => $at]);
            $out = [];
            foreach ($ids as $id) $out[$id] = ['id' => $id, 'name' => $id, 'currency' => 'EUR'];
            if (!is_wp_error($r)) foreach ((array) ($r['data']['list'] ?? []) as $a) if (isset($out[(string) ($a['advertiser_id'] ?? '')])) $out[(string) $a['advertiser_id']] = ['id' => (string) $a['advertiser_id'], 'name' => (string) ($a['name'] ?? $a['advertiser_id']), 'currency' => (string) ($a['currency'] ?? 'EUR')];
            return $out;
        }
        return [];
    }

    private static function tiktok_base(array $m) {
        $b = rtrim((string) ($m['api_base'] ?? ''), '/');
        return in_array($b, ['https://business-api.tiktok.com/open_api', 'https://sandbox-ads.tiktok.com/open_api'], true) ? $b : 'https://business-api.tiktok.com/open_api';
    }

    /** GET mit JSON-Antwort; ungültiges Token → WP_Error tix_ads_revoked */
    private static function get_json($url, array $headers = []) {
        $r = wp_remote_get($url, ['timeout' => 30, 'headers' => $headers + ['Accept' => 'application/json']]);
        if (is_wp_error($r)) return new WP_Error('tix_ads_down', 'Das Werbenetz ist gerade nicht erreichbar.', ['status' => 502]);
        $code = intval(wp_remote_retrieve_response_code($r));
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        $j = is_array($j) ? $j : [];
        $rv = self::revoked_error($code, $j);
        if ($rv === 'expired') return new WP_Error('tix_ads_expired', 'Die Verbindung ist abgelaufen. Bitte erneuern.', ['status' => 401]);
        if ($rv) return new WP_Error('tix_ads_revoked', 'Der Zugriff wurde beim Werbenetz entzogen.', ['status' => 401]);
        if ($code >= 400 || (isset($j['code']) && intval($j['code']) !== 0 && isset($j['message']))) return new WP_Error('tix_ads_error', mb_substr((string) ($j['error']['message'] ?? $j['message'] ?? "Fehler $code"), 0, 200), ['status' => 502]);
        return $j;
    }

    /** Token ungültig/entzogen? Meta OAuthException 190 (außer abgelaufen = 463), TikTok 40100–40105, HTTP 401 */
    private static function revoked_error($code, array $j) {
        if (isset($j['error']['code']) && intval($j['error']['code']) === 190) return intval($j['error']['error_subcode'] ?? 0) !== 463 ? true : 'expired';
        if (isset($j['code']) && in_array(intval($j['code']), [40100, 40101, 40102, 40104, 40105], true)) return true;
        return $code === 401;
    }

    /** Google Ads über die Weiche (Developer-Token liegt dort) */
    private static function google(array $body) {
        $hash = hash('sha256', wp_json_encode($body));
        $r = self::hub('/api/connect/google', $body + ['body_hash' => $hash], 'google|{ts}|' . $hash);
        if (is_wp_error($r)) return $r;
        list($code, $j) = $r;
        if ($code === 401 || (($j['error'] ?? '') === 'revoked')) return new WP_Error('tix_ads_revoked', 'Der Zugriff wurde bei Google entzogen.', ['status' => 401]);
        if ($code !== 200) return new WP_Error('tix_ads_error', mb_substr((string) ($j['message'] ?? "Google-Fehler $code"), 0, 200), ['status' => 502]);
        return $j;
    }

    // ═══════════════ Abruf ═══════════════

    public static function conn($id) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('connections') . " WHERE id = %d", intval($id)), ARRAY_A);
        return $r ?: null;
    }

    /** Gültiges Access-Token (erneuert Google stündlich, Meta vor Ablauf über die Weiche) oder WP_Error */
    private static function access_token(array &$c) {
        global $wpdb;
        $at = self::dec($c['token_enc']);
        $exp = $c['expires_at'] ? strtotime($c['expires_at'] . ' UTC') : 0;
        $need = $c['platform'] === 'google' ? (!$at || $exp < time() + 120) : ($c['platform'] === 'meta' && $exp && $exp < time() + 7 * DAY_IN_SECONDS);
        if (!$need) return $at;
        $rt = $c['platform'] === 'google' ? self::dec($c['refresh_enc']) : $at;
        if ($rt === '') return new WP_Error('tix_ads_revoked', 'Bitte die Verbindung erneuern.', ['status' => 401]);
        $r = self::hub('/api/connect/refresh', ['network' => $c['platform'], 'token' => $rt], 'refresh|' . $c['platform'] . '|{ts}|' . hash('sha256', $rt));
        if (is_wp_error($r)) return $c['platform'] === 'meta' && $at ? $at : $r;
        list($code, $j) = $r;
        if ($code !== 200 || empty($j['access_token'])) {
            if (in_array($j['error'] ?? '', ['revoked', 'invalid_grant'], true)) return new WP_Error('tix_ads_revoked', 'Der Zugriff wurde beim Werbenetz entzogen.', ['status' => 401]);
            return $c['platform'] === 'meta' && $at && $exp > time() ? $at : new WP_Error('tix_ads_error', 'Erneuern fehlgeschlagen.', ['status' => 502]);
        }
        $c['token_enc'] = self::enc((string) $j['access_token']);
        $c['expires_at'] = !empty($j['expires_in']) ? gmdate('Y-m-d H:i:s', time() + intval($j['expires_in'])) : null;
        $wpdb->update(self::t('connections'), ['token_enc' => $c['token_enc'], 'expires_at' => $c['expires_at'], 'updated' => current_time('mysql', true)], ['id' => intval($c['id'])]);
        return (string) $j['access_token'];
    }

    /** Kosten abrufen; $days = Tage rückwärts (inkl. heute). Ungültiges Token → (b) löschen. */
    public static function sync(array $c, $days = null) {
        global $wpdb;
        if (!in_array($c['status'], ['ok', 'error'], true)) return false;
        $days = $days ?: (intval($c['backfilled']) ? 1 : 30);
        $to = wp_date('Y-m-d');
        $from = wp_date('Y-m-d', time() - ($days - 1) * DAY_IN_SECONDS);
        $at = self::access_token($c);
        $rows = is_wp_error($at) ? $at : self::fetch($c, $at, $from, $to);
        if (is_wp_error($rows)) {
            if ($rows->get_error_code() === 'tix_ads_revoked') { self::purge_connection($c, true); return false; }
            // abgelaufen (Meta nach 60 Tagen ohne Erneuerung): Daten bleiben, Studio zeigt „Verbindung erneuern“
            if ($rows->get_error_code() === 'tix_ads_expired') { $wpdb->update(self::t('connections'), ['status' => 'expired', 'last_error' => $rows->get_error_message(), 'last_sync' => current_time('mysql', true)], ['id' => intval($c['id'])]); return false; }
            $wpdb->update(self::t('connections'), ['status' => 'error', 'last_error' => mb_substr($rows->get_error_message(), 0, 255), 'last_sync' => current_time('mysql', true)], ['id' => intval($c['id'])]);
            return false;
        }
        $ct = self::t('costs');
        $wpdb->query($wpdb->prepare("DELETE FROM $ct WHERE organizer_id = %d AND platform = %s AND account_id = %s AND day BETWEEN %s AND %s", $c['organizer_id'], $c['platform'], $c['account_id'], $from, $to));
        foreach ($rows as $r) {
            $wpdb->replace($ct, ['day' => $r['day'], 'organizer_id' => intval($c['organizer_id']), 'platform' => $c['platform'], 'account_id' => $c['account_id'], 'campaign_id' => mb_substr((string) $r['campaign_id'], 0, 64),
                'campaign_name' => mb_substr((string) $r['campaign_name'], 0, 190), 'spend' => round(floatval($r['spend']), 2), 'impressions' => intval($r['impressions']), 'clicks' => intval($r['clicks']), 'currency' => strtoupper(substr((string) ($r['currency'] ?: $c['currency']), 0, 3))]);
        }
        $wpdb->update(self::t('connections'), ['status' => 'ok', 'last_error' => '', 'backfilled' => 1, 'last_sync' => current_time('mysql', true)], ['id' => intval($c['id'])]);
        return count($rows);
    }

    /** Tageszeilen [{day, campaign_id, campaign_name, spend, impressions, clicks, currency}] oder WP_Error */
    public static function fetch(array $c, $at, $from, $to) {
        $m = json_decode((string) ($c['meta'] ?? ''), true) ?: [];
        $out = [];
        if ($c['platform'] === 'meta') {
            $v = preg_match('/^v\d+\.\d+$/', (string) ($m['graph'] ?? '')) ? $m['graph'] : 'v21.0';
            $url = "https://graph.facebook.com/$v/act_" . rawurlencode($c['account_id']) . '/insights?' . http_build_query(['level' => 'campaign', 'time_increment' => 1, 'limit' => 500,
                'time_range' => wp_json_encode(['since' => $from, 'until' => $to]), 'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,account_currency,date_start', 'access_token' => $at]);
            for ($page = 0; $url && $page < 20; $page++) {
                $j = self::get_json($url);
                if (is_wp_error($j)) return $j;
                foreach ((array) ($j['data'] ?? []) as $r) $out[] = ['day' => substr((string) $r['date_start'], 0, 10), 'campaign_id' => (string) $r['campaign_id'], 'campaign_name' => (string) ($r['campaign_name'] ?? ''), 'spend' => $r['spend'] ?? 0, 'impressions' => $r['impressions'] ?? 0, 'clicks' => $r['clicks'] ?? 0, 'currency' => (string) ($r['account_currency'] ?? '')];
                $url = (string) ($j['paging']['next'] ?? '');
            }
            return $out;
        }
        if ($c['platform'] === 'google') {
            $q = "SELECT campaign.id, campaign.name, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, customer.currency_code FROM campaign WHERE segments.date BETWEEN '$from' AND '$to'";
            $j = self::google(['op' => 'search', 'access_token' => $at, 'customer_id' => $c['account_id'], 'login_customer_id' => (string) $c['login_customer_id'], 'query' => $q]);
            if (is_wp_error($j)) return $j;
            foreach ((array) ($j['rows'] ?? []) as $r) $out[] = ['day' => (string) ($r['segments']['date'] ?? ''), 'campaign_id' => (string) ($r['campaign']['id'] ?? ''), 'campaign_name' => (string) ($r['campaign']['name'] ?? ''),
                'spend' => intval($r['metrics']['costMicros'] ?? 0) / 1000000, 'impressions' => $r['metrics']['impressions'] ?? 0, 'clicks' => $r['metrics']['clicks'] ?? 0, 'currency' => (string) ($r['customer']['currencyCode'] ?? '')];
            return array_values(array_filter($out, function ($r) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['day']) && $r['campaign_id'] !== ''; }));
        }
        if ($c['platform'] === 'tiktok') {
            $base = self::tiktok_base($m);
            for ($page = 1; $page <= 20; $page++) {
                $j = self::get_json("$base/v1.3/report/integrated/get/?" . http_build_query(['advertiser_id' => $c['account_id'], 'report_type' => 'BASIC', 'data_level' => 'AUCTION_CAMPAIGN',
                    'dimensions' => wp_json_encode(['campaign_id', 'stat_time_day']), 'metrics' => wp_json_encode(['spend', 'impressions', 'clicks', 'campaign_name', 'currency']),
                    'start_date' => $from, 'end_date' => $to, 'page' => $page, 'page_size' => 1000]), ['Access-Token' => $at]);
                if (is_wp_error($j)) return $j;
                foreach ((array) ($j['data']['list'] ?? []) as $r) $out[] = ['day' => substr((string) ($r['dimensions']['stat_time_day'] ?? ''), 0, 10), 'campaign_id' => (string) ($r['dimensions']['campaign_id'] ?? ''),
                    'campaign_name' => (string) ($r['metrics']['campaign_name'] ?? ''), 'spend' => $r['metrics']['spend'] ?? 0, 'impressions' => $r['metrics']['impressions'] ?? 0, 'clicks' => $r['metrics']['clicks'] ?? 0, 'currency' => (string) ($r['metrics']['currency'] ?? '')];
                if ($page >= intval($j['data']['page_info']['total_page'] ?? 1)) break;
            }
            return $out;
        }
        return [];
    }

    // ═══════════════ Cron und Löschen ═══════════════

    /** Stündlich: Kosten (heute; um 4 Uhr 7 Tage) + Löschprüfung (b)/(c) */
    public static function cron() {
        global $wpdb;
        self::maybe_create();
        $full = intval(wp_date('G')) === 4;
        foreach ((array) $wpdb->get_results("SELECT * FROM " . self::t('connections') . " WHERE status IN ('ok','error') ORDER BY last_sync IS NOT NULL, last_sync LIMIT 200", ARRAY_A) as $c) {
            if (!self::module_on(intval($c['organizer_id']))) continue;
            self::sync($c, $full ? 7 : null);
        }
        self::retention();
    }

    /** (c) Modul aus oder Veranstalter weg → nach 30 Tagen alles löschen */
    public static function retention() {
        global $wpdb;
        $orgs = array_unique(array_merge(
            array_map('intval', (array) $wpdb->get_col("SELECT DISTINCT organizer_id FROM " . self::t('connections'))),
            array_map('intval', (array) $wpdb->get_col("SELECT DISTINCT organizer_id FROM " . self::t('costs'))),
            array_map('intval', (array) $wpdb->get_col("SELECT DISTINCT organizer_id FROM " . self::t('manual')))
        ));
        $off = get_option('tix_ads_off_since', []);
        $off = is_array($off) ? $off : [];
        foreach ($orgs as $o) {
            $gone = $o && self::multi() && (get_post_type($o) !== 'tix_organizer' || get_post_status($o) === 'trash');
            if ($gone) { self::purge_organizer($o); unset($off[$o]); continue; }
            if (self::module_on($o)) { unset($off[$o]); continue; }
            if (empty($off[$o])) $off[$o] = time();
            elseif ($off[$o] < time() - self::KEEP_AFTER_END * DAY_IN_SECONDS) { self::purge_organizer($o); unset($off[$o]); }
        }
        update_option('tix_ads_off_since', $off, false);
    }

    /** (a)/(b): Token, Kontoangaben und Kennzahlen eines Kontos löschen */
    public static function purge_connection(array $c, $revoked = false) {
        global $wpdb;
        $wpdb->delete(self::t('costs'), ['organizer_id' => intval($c['organizer_id']), 'platform' => $c['platform'], 'account_id' => $c['account_id']]);
        $wpdb->delete(self::t('map'), ['organizer_id' => intval($c['organizer_id']), 'platform' => $c['platform']]);
        $wpdb->delete(self::t('connections'), ['id' => intval($c['id'])]);
        if ($revoked) {
            // Hinweis fürs Studio ohne Kontodaten: „Zugriff beim Netz entzogen – Daten gelöscht“
            $n = get_option('tix_ads_revoked_notice', []);
            $n = is_array($n) ? $n : [];
            $n[intval($c['organizer_id'])][$c['platform']] = time();
            update_option('tix_ads_revoked_notice', $n, false);
        }
    }

    /** (c) alles eines Veranstalters */
    public static function purge_organizer($o) {
        global $wpdb;
        foreach (['connections', 'costs', 'manual', 'map'] as $t) $wpdb->delete(self::t($t), ['organizer_id' => intval($o)]);
    }
}

TIX_Ad_Accounts::init();
