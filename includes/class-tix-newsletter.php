<?php
/**
 * TIX_Newsletter – Newsletter aus dem Studio über die Brevo-API (Modul Marketing, Statistik Stufe 2, User 2026-11-04).
 *
 * Bereich: Einzel-Seite = 0, evendis = je Veranstalter (eigenes Brevo-Konto, der Veranstalter ist Verantwortlicher).
 *
 * Kontakte ({prefix}tix_nl_contacts) – nur mit Double-Opt-in:
 *  - Quelle `checkout` (Häkchen in der Kasse, Parameter newsletter_optin) oder `form` (POST /public/nl/subscribe).
 *  - pending → Bestätigungsmail → active (GET /public/nl/confirm) → unsubscribed (Abmeldelink, Brevo-Webhook, Abgleich).
 *  - Einwilligung mit Text, Zeitpunkt, Quelle und IP als HMAC. Unbestätigte nach 7 Tagen gelöscht, Abmeldungen nach
 *    30 Tagen auf einen Hash der Adresse reduziert (Status blocked, Nachweis der Abmeldung ohne Klartext). Eine neue
 *    Anmeldung braucht wieder das Double-Opt-in.
 * Kampagnen ({prefix}tix_nl_campaigns): Entwurf → geplant → wird vorbereitet → versendet (bzw. fehlgeschlagen).
 *  - Zielgruppen: `contacts` (alle aktiven Kontakte des Bereichs), `event` (aktive Kontakte, die Tickets für das Event
 *    gekauft haben), `brevo_list` (vorhandene Listen im Brevo-Konto, nur mit Bestätigung, dass alle eingewilligt haben).
 *  - Brevo-Listen (1.38.692, §105): `list_ids` (bis 10) nur an diese Listen (`brevo_list`) oder zusätzlich zu den eigenen
 *    Empfängern; eigene Abmeldungen gehen als Ausschluss-Liste mit; GET …/lists, Empfängerzahl ohne Doppelte vorab.
 *  - Versand: Das Plugin legt je Kampagne eine Brevo-Liste an, importiert die Empfänger, legt die Brevo-Kampagne an
 *    (Absender aus Brevo, Abmeldelink {{ unsubscribe }}, Impressum im Fuß) und sendet sofort bzw. zur geplanten Zeit.
 *  - Links auf die eigene Website tragen utm_source=newsletter, utm_medium=email, utm_campaign=nl-<id> und c=nl-<id>:
 *    Käufe zählen über die Herkunft der Bestellung (TIX_Sales_Figures). Öffnungen/Klicks/Abmeldungen liefert Brevo.
 * REST (Inhaber/Team-Admin/Admin, Modul Marketing): /marketing/newsletter/… (siehe routes()).
 */
if (!defined('ABSPATH')) exit;

class TIX_Newsletter {

    const NS = 'tixomat/v1';
    const API = 'https://api.brevo.com/v3';
    const DB_VERSION = '3'; // 2 = Spalte blocks (Baukasten, 1.38.654); 3 = Brevo-Listen (brevo_lists_src, brevo_excl, audience_meta)
    const OPT = 'tix_nl_settings';
    const META = '_tix_nl_settings';
    const CRON = 'tix_nl_cron';
    const STATS_DAYS = 30;
    const MAX_RECIPIENTS = 50000;
    const MAX_LISTS = 10;          // gewählte Brevo-Listen je Kampagne
    const LISTS_LIMIT = 1000;      // höchstens so viele Listen aus dem Brevo-Konto
    const CONSENT_TEXT = 'Ja, ich möchte Neuigkeiten und Event-Tipps von %s per E-Mail erhalten. Dafür wird ausgewertet, ob und welche Links ich öffne. Die Einwilligung kann ich jederzeit über den Abmeldelink in jeder Mail widerrufen.';

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_create'], 24);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('cron_schedules', function ($s) { $s['tix_nl_5min'] = ['interval' => 300, 'display' => 'Alle 5 Minuten (Newsletter)']; return $s; });
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 300, 'tix_nl_5min', self::CRON); }, 51);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('tix_nl_daily', [__CLASS__, 'daily']);
        add_action('init', function () { if (!wp_next_scheduled('tix_nl_daily')) wp_schedule_event(time() + 1800, 'daily', 'tix_nl_daily'); }, 51);
        add_action('before_delete_post', function ($id) { if (get_post_type($id) === 'tix_organizer') self::purge_organizer(intval($id)); });
    }

    public static function tc() { global $wpdb; return $wpdb->prefix . 'tix_nl_contacts'; }
    public static function tk() { global $wpdb; return $wpdb->prefix . 'tix_nl_campaigns'; }

    public static function maybe_create() {
        if (get_option('tix_nl_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::tc() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email VARCHAR(190) NOT NULL,
            first_name VARCHAR(60) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            source VARCHAR(16) NOT NULL DEFAULT 'form',
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            token VARCHAR(64) NOT NULL,
            consent_text TEXT NOT NULL,
            consent_at DATETIME NOT NULL,
            consent_ip VARCHAR(64) NOT NULL DEFAULT '',
            confirmed_at DATETIME NULL,
            unsubscribed_at DATETIME NULL,
            unsub_reason VARCHAR(16) NOT NULL DEFAULT '',
            created DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY org_email (organizer_id, email),
            KEY token (token),
            KEY org_status (organizer_id, status)
        ) $c;");
        dbDelta("CREATE TABLE " . self::tk() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(120) NOT NULL DEFAULT '',
            subject VARCHAR(200) NOT NULL DEFAULT '',
            preheader VARCHAR(200) NOT NULL DEFAULT '',
            content LONGTEXT NOT NULL,
            blocks LONGTEXT NULL,
            audience VARCHAR(16) NOT NULL DEFAULT 'contacts',
            event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            brevo_list_src BIGINT UNSIGNED NOT NULL DEFAULT 0,
            brevo_lists_src TEXT NULL,
            audience_meta LONGTEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            recipients INT UNSIGNED NOT NULL DEFAULT 0,
            brevo_campaign BIGINT UNSIGNED NOT NULL DEFAULT 0,
            brevo_list BIGINT UNSIGNED NOT NULL DEFAULT 0,
            brevo_process BIGINT UNSIGNED NOT NULL DEFAULT 0,
            brevo_excl BIGINT UNSIGNED NOT NULL DEFAULT 0,
            stats LONGTEXT NULL,
            stats_at DATETIME NULL,
            error VARCHAR(255) NOT NULL DEFAULT '',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created DATETIME NOT NULL,
            updated DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY org (organizer_id, id),
            KEY status (status)
        ) $c;");
        update_option('tix_nl_db', self::DB_VERSION, true);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }
    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) { return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]); }
    private static function now() { return current_time('mysql', true); }

    // ═══════════════ Einstellungen je Bereich ═══════════════

    /** {api_key, sender_id, sender_name, sender_email, folder_id, webhook_id, hook_secret, address} – Schlüssel entschlüsselt */
    public static function settings($org) {
        $v = $org ? get_post_meta(intval($org), self::META, true) : get_option(self::OPT, []);
        $v = is_array($v) ? $v : [];
        $key = isset($v['api_key_enc']) ? TIX_Ad_Accounts::dec($v['api_key_enc']) : '';
        // Einzel-Seite: vorhandener Brevo-Schlüssel aus den Plugin-Einstellungen als Rückfall
        if ($key === '' && !$org && function_exists('tix_get_settings')) $key = trim((string) tix_get_settings('brevo_api_key'));
        return ['api_key' => $key, 'sender_id' => intval($v['sender_id'] ?? 0), 'sender_name' => (string) ($v['sender_name'] ?? ''), 'sender_email' => (string) ($v['sender_email'] ?? ''),
            'folder_id' => intval($v['folder_id'] ?? 0), 'webhook_id' => intval($v['webhook_id'] ?? 0), 'hook_secret' => (string) ($v['hook_secret'] ?? ''),
            'address' => (string) ($v['address'] ?? ''), 'own_key' => isset($v['api_key_enc']) && $v['api_key_enc'] !== ''];
    }

    private static function save_settings($org, array $set) {
        $v = $org ? get_post_meta(intval($org), self::META, true) : get_option(self::OPT, []);
        $v = is_array($v) ? $v : [];
        if (array_key_exists('api_key', $set)) { $v['api_key_enc'] = $set['api_key'] === '' ? '' : TIX_Ad_Accounts::enc($set['api_key']); unset($set['api_key']); }
        $v = array_merge($v, $set);
        if ($org) update_post_meta(intval($org), self::META, $v); else update_option(self::OPT, $v, false);
    }

    /** Name des Absenders im Einwilligungstext: Veranstalter (evendis) bzw. Seitenname */
    public static function owner_name($org) {
        return html_entity_decode($org ? get_the_title(intval($org)) : get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
    }

    public static function consent_text($org) { return sprintf(self::CONSENT_TEXT, self::owner_name($org)); }

    // ═══════════════ Brevo ═══════════════

    /** Aufruf der Brevo-API → [code, array] oder WP_Error (Verbindung) */
    public static function brevo($key, $method, $path, $body = null) {
        $args = ['method' => $method, 'timeout' => 20, 'headers' => ['api-key' => $key, 'Accept' => 'application/json', 'Content-Type' => 'application/json']];
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $r = wp_remote_request(self::API . $path, $args);
        if (is_wp_error($r)) return new WP_Error('tix_brevo_down', 'Brevo ist gerade nicht erreichbar.', ['status' => 502]);
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        return [intval(wp_remote_retrieve_response_code($r)), is_array($j) ? $j : []];
    }

    private static function brevo_err(array $r, $fallback) {
        return mb_substr((string) ($r[1]['message'] ?? $fallback), 0, 200);
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        $mod = ['TIX_Ad_Accounts', 'perm_module'];
        $mgr = ['TIX_Ad_Accounts', 'perm'];
        $r = function ($path, $methods, $cb, $perm) { register_rest_route(self::NS, $path, ['methods' => $methods, 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm]); };
        $r('/marketing/newsletter', 'GET', 'rest_status', $mgr);
        $r('/marketing/newsletter/settings', 'POST', 'rest_settings', $mod);
        $r('/marketing/newsletter/contacts', 'GET', 'rest_contacts', $mod);
        $r('/marketing/newsletter/contacts/(?P<id>\d+)', 'DELETE', 'rest_contact_delete', $mod);
        $r('/marketing/newsletter/audience', 'GET', 'rest_audience', $mod);
        $r('/marketing/newsletter/lists', 'GET', 'rest_lists', $mod);
        $r('/marketing/newsletter/campaigns', 'GET', 'rest_campaigns', $mod);
        $r('/marketing/newsletter/campaigns', 'POST', 'rest_create', $mod);
        $r('/marketing/newsletter/campaigns/(?P<id>\d+)', 'GET', 'rest_campaign', $mod);
        $r('/marketing/newsletter/campaigns/(?P<id>\d+)', 'POST', 'rest_update', $mod);
        $r('/marketing/newsletter/campaigns/(?P<id>\d+)', 'DELETE', 'rest_delete', $mod);
        $r('/marketing/newsletter/campaigns/(?P<id>\d+)/preview', 'GET', 'rest_preview', $mod);
        $r('/marketing/newsletter/campaigns/(?P<id>\d+)/test', 'POST', 'rest_test', $mod);
        $r('/marketing/newsletter/campaigns/(?P<id>\d+)/send', 'POST', 'rest_send', $mod);
        $r('/marketing/newsletter/campaigns/(?P<id>\d+)/cancel', 'POST', 'rest_cancel', $mod);
        $r('/marketing/newsletter/campaigns/(?P<id>\d+)/duplicate', 'POST', 'rest_duplicate', $mod);
        $pub = '__return_true';
        $r('/public/nl/consent', 'GET', 'rest_public_consent', $pub);
        $r('/public/nl/subscribe', 'POST', 'rest_subscribe', $pub);
        $r('/public/nl/confirm', 'GET', 'rest_confirm', $pub);
        $r('/public/nl/unsubscribe', 'GET', 'rest_unsubscribe', $pub);
        $r('/public/nl/unsubscribe', 'POST', 'rest_unsubscribe_post', $pub); // RFC 8058
        $r('/public/nl/hook/(?P<secret>[A-Za-z0-9]{32})', 'POST', 'rest_hook', $pub);
    }

    private static function org(WP_REST_Request $req) { return TIX_Ad_Accounts::org($req); }

    // ═══════════════ Einstellungen ═══════════════

    /** GET /marketing/newsletter → {module, connected, account, sender, senders, counts, consent_text} */
    public static function rest_status(WP_REST_Request $req) {
        self::maybe_create();
        global $wpdb;
        $org = self::org($req);
        $s = self::settings($org);
        $counts = array_fill_keys(['pending', 'active', 'unsubscribed'], 0);
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) n FROM " . self::tc() . " WHERE organizer_id = %d AND status IN ('pending','active','unsubscribed') GROUP BY status", $org)) as $r) $counts[$r->status] = intval($r->n);
        $out = ['ok' => true, 'module_on' => TIX_Ad_Accounts::module_on($org), 'connected' => $s['api_key'] !== '', 'own_key' => $s['own_key'],
            'sender' => $s['sender_email'] !== '' ? ['id' => $s['sender_id'], 'name' => $s['sender_name'], 'email' => $s['sender_email']] : null,
            'address' => $s['address'] !== '' ? $s['address'] : self::default_address($org), 'webhook' => $s['webhook_id'] > 0,
            'counts' => $counts, 'consent_text' => self::consent_text($org), 'account' => null, 'senders' => []];
        if ($s['api_key'] !== '' && $out['module_on'] && $req->get_param('check') !== '0') {
            // Konto und Absender 10 Minuten zwischenspeichern (Studio-Tempo 1.38.650: vorher 345 ms je Aufruf); ?fresh=1 fragt neu
            $ck = self::brevo_cache_key($org, $s['api_key']);
            $c = $req->get_param('fresh') ? false : get_transient($ck);
            if (is_array($c)) {
                $out['account'] = $c['account'];
                $out['senders'] = $c['senders'];
                $out['cached'] = true;
            } else {
                $a = self::brevo($s['api_key'], 'GET', '/account');
                if (!is_wp_error($a) && $a[0] === 200) {
                    $out['account'] = ['company' => (string) ($a[1]['companyName'] ?? ''), 'email' => (string) ($a[1]['email'] ?? '')];
                    $sn = self::brevo($s['api_key'], 'GET', '/senders');
                    if (!is_wp_error($sn) && $sn[0] === 200) foreach ((array) ($sn[1]['senders'] ?? []) as $x) if (!empty($x['active'])) $out['senders'][] = ['id' => intval($x['id']), 'name' => (string) $x['name'], 'email' => (string) $x['email']];
                    if (!is_wp_error($sn) && $sn[0] === 200) set_transient($ck, ['account' => $out['account'], 'senders' => $out['senders']], 10 * MINUTE_IN_SECONDS);
                } else $out['error'] = is_wp_error($a) ? $a->get_error_message() : 'Der Brevo-Schlüssel wird nicht angenommen.';
            }
        }
        return rest_ensure_response($out);
    }

    private static function brevo_cache_key($org, $key) { return 'tix_nl_brevo_' . md5(intval($org) . '|' . $key); }

    /** Zwischenspeicher von Konto und Absender verwerfen (nach dem Speichern der Einstellungen) */
    public static function flush_brevo_cache($org) {
        $s = self::settings($org);
        if ($s['api_key'] !== '') delete_transient(self::brevo_cache_key($org, $s['api_key']));
    }

    /** Impressums-Adresse für den Fuß der Mail (Pflichtangabe): Veranstalter-Adresse bzw. Rechnungsadresse der Seite */
    private static function default_address($org) {
        if ($org) {
            $a = trim((string) get_post_meta($org, '_tix_org_address', true));
            return trim(self::owner_name($org) . ($a !== '' ? ', ' . preg_replace('/\s*\R\s*/u', ', ', $a) : ''));
        }
        $ts = get_option('tix_settings', []);
        $n = trim((string) ($ts['invoice_company_name'] ?? '')) ?: self::owner_name(0);
        $a = trim(preg_replace('/\s*\R\s*/u', ', ', (string) ($ts['invoice_company_address'] ?? '')));
        return trim($n . ($a !== '' ? ', ' . $a : ''));
    }

    /** POST /marketing/newsletter/settings {api_key?, sender_id?, address?} – Schlüssel prüfen, Absender wählen, Webhook anlegen */
    public static function rest_settings(WP_REST_Request $req) {
        $org = self::org($req);
        self::flush_brevo_cache($org); // neuer Schlüssel bzw. Absender: Konto/Absender frisch holen
        if ($req->has_param('api_key')) {
            $key = trim((string) $req->get_param('api_key'));
            if ($key !== '') {
                if (!preg_match('/^xkeysib-[A-Za-z0-9-]{20,}$/', $key)) return self::bad('api_key', 'Der Brevo-Schlüssel beginnt mit „xkeysib-“ (Brevo → SMTP & API → API-Schlüssel).');
                $a = self::brevo($key, 'GET', '/account');
                if (is_wp_error($a)) return $a;
                if ($a[0] !== 200) return self::bad('api_key', 'Brevo nimmt diesen Schlüssel nicht an.', 'tix_brevo_key');
            }
            $old = self::settings($org);
            if ($old['webhook_id'] && $old['api_key'] !== '') self::brevo($old['api_key'], 'DELETE', '/webhooks/' . $old['webhook_id']);
            self::save_settings($org, ['api_key' => $key, 'webhook_id' => 0, 'folder_id' => 0, 'sender_id' => 0, 'sender_name' => '', 'sender_email' => '']);
        }
        $s = self::settings($org);
        if ($req->has_param('sender_id')) {
            if ($s['api_key'] === '') return self::bad('api_key', 'Bitte zuerst den Brevo-Schlüssel eintragen.');
            $sid = intval($req->get_param('sender_id'));
            $sn = self::brevo($s['api_key'], 'GET', '/senders');
            if (is_wp_error($sn)) return $sn;
            $hit = null;
            foreach ((array) ($sn[1]['senders'] ?? []) as $x) if (intval($x['id']) === $sid && !empty($x['active'])) $hit = $x;
            if (!$hit) return self::bad('sender_id', 'Diesen Absender gibt es in Brevo nicht oder er ist noch nicht bestätigt.');
            self::save_settings($org, ['sender_id' => $sid, 'sender_name' => (string) $hit['name'], 'sender_email' => (string) $hit['email']]);
        }
        if ($req->has_param('address')) self::save_settings($org, ['address' => mb_substr(trim(wp_strip_all_tags((string) $req->get_param('address'))), 0, 300)]);
        $s = self::settings($org);
        if ($s['api_key'] !== '' && !$s['webhook_id']) self::ensure_webhook($org, $s);
        $req->set_param('check', '1');
        return self::rest_status($req);
    }

    /** Brevo meldet Abmeldungen, harte Bounces und Spam-Beschwerden an /public/nl/hook/<geheim> */
    private static function ensure_webhook($org, array $s) {
        $secret = $s['hook_secret'] !== '' ? $s['hook_secret'] : wp_generate_password(32, false, false);
        $r = self::brevo($s['api_key'], 'POST', '/webhooks', ['url' => rest_url(self::NS . '/public/nl/hook/' . $secret), 'description' => 'Tixomat Newsletter', 'events' => ['unsubscribed', 'hardBounce', 'spam'], 'type' => 'marketing']);
        $id = !is_wp_error($r) && in_array($r[0], [200, 201], true) ? intval($r[1]['id'] ?? 0) : 0;
        self::save_settings($org, ['hook_secret' => $secret, 'webhook_id' => $id]);
    }

    // ═══════════════ Kontakte ═══════════════

    /** Neuer bzw. erneuerter Kontakt (pending) und Bestätigungsmail; aktive Kontakte bleiben unverändert. → 'pending'|'active'|'' */
    public static function subscribe($org, $email, $first, $source, $order_id = 0) {
        self::maybe_create();
        global $wpdb;
        $email = strtolower(trim(sanitize_email((string) $email)));
        if (!is_email($email)) return '';
        $org = intval($org);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::tc() . " WHERE organizer_id = %d AND email = %s", $org, $email));
        if ($row && $row->status === 'active') return 'active';
        $token = ($row && $row->status === 'pending') ? (string) $row->token : wp_generate_password(40, false, false);
        $ip = class_exists('TIX_Client_IP') ? (string) TIX_Client_IP::get() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $data = ['status' => 'pending', 'token' => $token, 'first_name' => mb_substr(sanitize_text_field((string) $first), 0, 60), 'source' => $source === 'checkout' ? 'checkout' : 'form',
            'order_id' => intval($order_id), 'consent_text' => self::consent_text($org), 'consent_at' => self::now(), 'consent_ip' => $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : '',
            'unsubscribed_at' => null, 'unsub_reason' => ''];
        if ($row) $wpdb->update(self::tc(), $data, ['id' => $row->id]);
        else $wpdb->insert(self::tc(), $data + ['organizer_id' => $org, 'email' => $email, 'created' => self::now()]);
        self::send_confirm($org, $email, $token);
        return 'pending';
    }

    /** Kasse: Häkchen „Newsletter“ – je Veranstalter der gekauften Events (evendis) bzw. für die Seite */
    public static function from_checkout($order_id, $email, $first, array $event_ids) {
        $orgs = [0];
        if (self::multi()) {
            $orgs = [];
            foreach (array_unique(array_map('intval', $event_ids)) as $e) { $o = intval(get_post_meta($e, '_tix_organizer_id', true)); if ($o) $orgs[$o] = $o; }
        }
        foreach ($orgs as $o) self::subscribe($o, $email, $first, 'checkout', $order_id);
    }

    private static function link($action, $token) {
        return add_query_arg('token', rawurlencode($token), rest_url(self::NS . '/public/nl/' . $action));
    }

    private static function customer_base() {
        $b = class_exists('TIX_Customer_URL') ? (string) TIX_Customer_URL::base() : '';
        return rtrim($b !== '' ? $b : home_url(), '/');
    }

    private static function send_confirm($org, $email, $token) {
        $who = self::owner_name($org);
        $subject = 'Bitte bestätige deine Anmeldung zum Newsletter von ' . $who;
        $html = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::render(['title' => 'Bitte bestätige deine Anmeldung', 'kind' => 'account', 'blocks' => [
            ['type' => 'text', 'html' => 'Hallo,<br>bitte bestätige, dass du den Newsletter von ' . esc_html($who) . ' bekommen möchtest:'],
            ['type' => 'button', 'label' => 'Anmeldung bestätigen', 'url' => self::link('confirm', $token)],
            ['type' => 'text', 'html' => 'Wenn du dich nicht angemeldet hast, ignoriere diese Mail einfach – ohne Bestätigung schicken wir nichts.'],
        ]]) : 'Bitte bestätige deine Anmeldung: ' . esc_url(self::link('confirm', $token));
        wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    private static function by_token($token) {
        global $wpdb;
        $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
        if (strlen($token) < 20) return null;
        self::maybe_create();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::tc() . " WHERE token = %s", $token));
    }

    private static function go($url) {
        $r = new WP_REST_Response(null, 302);
        $r->header('Location', $url);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    private static function back($state, $org = 0) {
        $base = self::customer_base();
        if ($org && self::multi()) { $u = (string) get_permalink($org); if ($u !== '') $base = rtrim(class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to($u) : $u, '/'); }
        return add_query_arg('newsletter', $state, $base . '/');
    }

    /** GET /public/nl/consent?organizer_id= → {text, enabled} – Text für das Häkchen in Kasse und Formular */
    public static function rest_public_consent(WP_REST_Request $req) {
        $org = self::multi() ? absint($req->get_param('organizer_id')) : 0;
        if ($org && get_post_type($org) !== 'tix_organizer') $org = 0;
        $s = self::settings($org);
        return rest_ensure_response(['ok' => true, 'enabled' => $s['api_key'] !== '' && $s['sender_email'] !== '' && TIX_Ad_Accounts::module_on($org), 'text' => self::consent_text($org), 'owner' => self::owner_name($org)]);
    }

    /** POST /public/nl/subscribe {email, first_name?, organizer_id?, consent: true} → immer dieselbe Antwort */
    public static function rest_subscribe(WP_REST_Request $req) {
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('nl_subscribe', 5, 3600)) return new WP_Error('tix_rate_limit', 'Zu viele Anmeldungen. Bitte später erneut versuchen.', ['status' => 429]);
        $email = strtolower(sanitize_email((string) $req->get_param('email')));
        if (!is_email($email)) return self::bad('email', 'Bitte eine gültige E-Mail-Adresse angeben.', 'invalid_email');
        if (!filter_var($req->get_param('consent'), FILTER_VALIDATE_BOOLEAN)) return self::bad('consent', 'Bitte bestätige die Einwilligung.', 'tix_consent_required');
        $org = self::multi() ? absint($req->get_param('organizer_id')) : 0;
        if (self::multi() && (!$org || get_post_type($org) !== 'tix_organizer' || get_post_status($org) !== 'publish')) return self::bad('organizer_id', 'Veranstalter nicht gefunden.', 'not_found', 404);
        $s = self::settings($org);
        if ($s['api_key'] === '' || $s['sender_email'] === '' || !TIX_Ad_Accounts::module_on($org)) return new WP_Error('tix_newsletter_off', 'Der Newsletter ist hier noch nicht verfügbar.', ['status' => 404]);
        self::subscribe($org, $email, (string) $req->get_param('first_name'), 'form');
        return rest_ensure_response(['ok' => true, 'message' => 'Fast geschafft: Bitte bestätige die Anmeldung über den Link in der E-Mail.']);
    }

    public static function rest_confirm(WP_REST_Request $req) {
        global $wpdb;
        $row = self::by_token($req->get_param('token'));
        if (!$row || !in_array($row->status, ['pending', 'active'], true)) return self::go(self::back('ungueltig'));
        if ($row->status === 'pending') {
            // neuer Schlüssel für die Abmeldelinks (der Bestätigungslink wird damit ungültig)
            $wpdb->update(self::tc(), ['status' => 'active', 'confirmed_at' => self::now(), 'token' => wp_generate_password(40, false, false)], ['id' => $row->id]);
        }
        return self::go(self::back('bestaetigt', intval($row->organizer_id)));
    }

    private static function unsubscribe_row($row, $reason) {
        global $wpdb;
        if (!$row || $row->status === 'unsubscribed') return;
        $wpdb->update(self::tc(), ['status' => 'unsubscribed', 'unsubscribed_at' => self::now(), 'unsub_reason' => $reason], ['id' => $row->id]);
    }

    public static function rest_unsubscribe(WP_REST_Request $req) {
        $row = self::by_token($req->get_param('token'));
        self::unsubscribe_row($row, 'link');
        return self::go(self::back('abgemeldet', $row ? intval($row->organizer_id) : 0));
    }

    public static function rest_unsubscribe_post(WP_REST_Request $req) {
        self::unsubscribe_row(self::by_token($req->get_param('token')), 'link');
        return rest_ensure_response(['ok' => true]);
    }

    /** Brevo-Webhook (marketing): unsubscribed / hard_bounce / spam → Kontakt abgemeldet */
    public static function rest_hook(WP_REST_Request $req) {
        global $wpdb;
        $secret = (string) $req['secret'];
        $org = null;
        if (hash_equals((string) self::settings(0)['hook_secret'], $secret) && $secret !== '') $org = 0;
        elseif (self::multi()) {
            $id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1", self::META, '%' . $wpdb->esc_like($secret) . '%'));
            if ($id && hash_equals(self::settings(intval($id))['hook_secret'], $secret)) $org = intval($id);
        }
        if ($org === null) return new WP_Error('rest_forbidden', 'Unbekannt.', ['status' => 403]);
        $p = $req->get_json_params() ?: $req->get_body_params();
        $events = isset($p[0]) && is_array($p[0]) ? $p : [$p];
        $n = 0;
        foreach ($events as $e) {
            $ev = strtolower((string) ($e['event'] ?? ''));
            $email = strtolower(sanitize_email((string) ($e['email'] ?? '')));
            if (!is_email($email)) continue;
            $reason = strpos($ev, 'unsub') !== false ? 'brevo' : (strpos($ev, 'spam') !== false ? 'spam' : (strpos($ev, 'hard') !== false ? 'bounce' : ''));
            if ($reason === '') continue;
            self::unsubscribe_row($wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::tc() . " WHERE organizer_id = %d AND email = %s", $org, $email)), $reason);
            $n++;
        }
        return rest_ensure_response(['ok' => true, 'handled' => $n]);
    }

    /** GET /marketing/newsletter/contacts?status=&q=&page= */
    public static function rest_contacts(WP_REST_Request $req) {
        self::maybe_create();
        global $wpdb;
        $org = self::org($req);
        $per = 50; $page = max(1, intval($req->get_param('page') ?: 1));
        $w = $wpdb->prepare("organizer_id = %d AND status IN ('pending','active','unsubscribed')", $org);
        $st = (string) $req->get_param('status');
        if (in_array($st, ['pending', 'active', 'unsubscribed'], true)) $w .= $wpdb->prepare(' AND status = %s', $st);
        $q = trim((string) $req->get_param('q'));
        if ($q !== '') $w .= $wpdb->prepare(' AND (email LIKE %s OR first_name LIKE %s)', '%' . $wpdb->esc_like($q) . '%', '%' . $wpdb->esc_like($q) . '%');
        $total = intval($wpdb->get_var("SELECT COUNT(*) FROM " . self::tc() . " WHERE $w"));
        $rows = (array) $wpdb->get_results("SELECT id, email, first_name, status, source, consent_at, confirmed_at, unsubscribed_at, unsub_reason FROM " . self::tc() . " WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), ARRAY_A);
        $iso = function ($d) { return $d ? mysql_to_rfc3339($d) : null; };
        return rest_ensure_response(['ok' => true, 'total' => $total, 'page' => $page, 'per_page' => $per, 'contacts' => array_map(function ($r) use ($iso) {
            return ['id' => intval($r['id']), 'email' => $r['email'], 'first_name' => $r['first_name'], 'status' => $r['status'], 'source' => $r['source'],
                'consent_at' => $iso($r['consent_at']), 'confirmed_at' => $iso($r['confirmed_at']), 'unsubscribed_at' => $iso($r['unsubscribed_at']), 'unsub_reason' => $r['unsub_reason'] ?: null];
        }, $rows)]);
    }

    public static function rest_contact_delete(WP_REST_Request $req) {
        global $wpdb;
        $n = $wpdb->delete(self::tc(), ['id' => intval($req['id']), 'organizer_id' => self::org($req)]);
        return $n ? rest_ensure_response(['ok' => true]) : new WP_Error('not_found', 'Kontakt nicht gefunden.', ['status' => 404]);
    }

    // ═══════════════ Zielgruppe ═══════════════

    /** Empfänger-Adressen (aktiv, im Bereich) für contacts/event → [email => first_name] */
    public static function recipients($org, $audience, $event_id = 0) {
        global $wpdb;
        $t = self::tc();
        if ($audience === 'event') {
            if (!class_exists('TIX_Order')) return [];
            $rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT c.email, c.first_name FROM $t c JOIN " . TIX_Order::table_name() . " o ON LOWER(o.billing_email) = c.email
                JOIN " . TIX_Order::items_table_name() . " i ON i.order_id = o.id WHERE c.organizer_id = %d AND c.status = 'active' AND i.event_id = %d AND o.status IN ('completed','processing') LIMIT %d", $org, $event_id, self::MAX_RECIPIENTS));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT email, first_name FROM $t WHERE organizer_id = %d AND status = 'active' LIMIT %d", $org, self::MAX_RECIPIENTS));
        }
        $out = [];
        foreach ((array) $rows as $r) $out[$r->email] = (string) $r->first_name;
        return $out;
    }

    private static function event_ok($eid) {
        return $eid && get_post_type($eid) === 'event' && in_array(intval($eid), array_map('intval', TIX_Studio_Stats::scope_ids()), true);
    }

    // ═══════════════ Brevo-Listen (1.38.692, §105) ═══════════════

    private static function lists_cache_key($org, $key) { return 'tix_nl_lists_' . md5(intval($org) . '|' . $key); }

    /** Alle Listen des Brevo-Kontos ohne die eigenen (Tixomat · …, Tixomat-Ordner) → [{id, name, count, folder}] oder WP_Error */
    public static function brevo_lists($org, array $s, $refresh = false) {
        if ($s['api_key'] === '') return self::bad('api_key', 'Bitte zuerst Brevo verbinden.', 'tix_nl_not_connected', 409);
        $ck = self::lists_cache_key($org, $s['api_key']);
        $c = $refresh ? false : get_transient($ck);
        if (is_array($c)) return $c;
        $raw = [];
        for ($off = 0; $off < self::LISTS_LIMIT; $off += 50) {
            $r = self::brevo($s['api_key'], 'GET', '/contacts/lists?limit=50&offset=' . $off . '&sort=desc');
            if (is_wp_error($r)) return new WP_Error('tix_brevo_error', $r->get_error_message(), ['status' => 502]);
            if ($r[0] !== 200) return new WP_Error('tix_brevo_error', self::brevo_err($r, 'Brevo liefert die Listen nicht.'), ['status' => 502]);
            $page = (array) ($r[1]['lists'] ?? []);
            foreach ($page as $l) if (is_array($l) && !empty($l['id'])) $raw[intval($l['id'])] = $l;
            if (count($page) < 50 || (isset($r[1]['count']) && $off + 50 >= intval($r[1]['count']))) break;
        }
        $folders = [];
        $need = false; foreach ($raw as $l) if (!empty($l['folderId'])) $need = true;
        if ($need) for ($off = 0; $off < self::LISTS_LIMIT; $off += 50) {
            $r = self::brevo($s['api_key'], 'GET', '/contacts/folders?limit=50&offset=' . $off);
            if (is_wp_error($r) || $r[0] !== 200) break; // Ordnername ist nur Beiwerk
            $page = (array) ($r[1]['folders'] ?? []);
            foreach ($page as $f) if (is_array($f) && !empty($f['id'])) $folders[intval($f['id'])] = (string) ($f['name'] ?? '');
            if (count($page) < 50) break;
        }
        $out = [];
        foreach ($raw as $id => $l) {
            $name = (string) ($l['name'] ?? '');
            $fid = intval($l['folderId'] ?? 0);
            if (strpos($name, 'Tixomat · ') === 0 || ($s['folder_id'] && $fid === intval($s['folder_id']))) continue;
            $out[] = ['id' => $id, 'name' => $name, 'count' => intval($l['uniqueSubscribers'] ?? $l['totalSubscribers'] ?? 0), 'folder' => $fid ? (string) ($folders[$fid] ?? '') : ''];
        }
        set_transient($ck, $out, 5 * MINUTE_IN_SECONDS);
        return $out;
    }

    /** GET /marketing/newsletter/lists[?refresh=1] → {lists, total} */
    public static function rest_lists(WP_REST_Request $req) {
        $org = self::org($req);
        $l = self::brevo_lists($org, self::settings($org), (bool) $req->get_param('refresh'));
        if (is_wp_error($l)) return $l;
        return rest_ensure_response(['ok' => true, 'lists' => $l, 'total' => count($l)]);
    }

    /** list_ids aus Parameter (Array oder „7,9“) → int[] (eindeutig, Reihenfolge bleibt) */
    private static function parse_ids($v) {
        if (is_string($v)) $v = $v === '' ? [] : explode(',', $v);
        $out = [];
        foreach ((array) $v as $x) { $i = absint($x); if ($i && !in_array($i, $out, true)) $out[] = $i; }
        return $out;
    }

    /** Gewählte Listen einer Kampagne; alte Daten: [brevo_list_src] */
    public static function list_ids_of(array $c) {
        $l = !empty($c['brevo_lists_src']) ? json_decode((string) $c['brevo_lists_src'], true) : null;
        if (is_array($l)) return self::parse_ids($l);
        return intval($c['brevo_list_src'] ?? 0) ? [intval($c['brevo_list_src'])] : [];
    }

    private static function meta_of(array $c) {
        $m = !empty($c['audience_meta']) ? json_decode((string) $c['audience_meta'], true) : null;
        return is_array($m) ? $m : [];
    }

    /** Hash wie im täglichen Aufräumen (anonymisierte Abmeldung, Status blocked) */
    private static function email_hash($email) { return hash('sha256', strtolower((string) $email) . wp_salt('auth')); }

    /** Eigene Abmeldungen des Bereichs → [klartext-adressen (Set), hashes (Set)] */
    private static function unsub_sets($org) {
        global $wpdb;
        $plain = []; $hash = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT email, status FROM " . self::tc() . " WHERE organizer_id = %d AND status IN ('unsubscribed','blocked')", $org)) as $r) {
            if ($r->status === 'blocked') $hash[strtolower($r->email)] = 1; else $plain[strtolower($r->email)] = 1;
        }
        return [$plain, $hash];
    }

    /**
     * Empfänger insgesamt: eigene (contacts/event) + Brevo-Listen, ohne Doppelte, eigene Abmeldungen und in Brevo gesperrte.
     * → {count, own, lists[{id,count}], excluded, exact, _own [email=>first], _exclude [email…]} oder WP_Error (tix_nl_list_gone …)
     */
    public static function count_audience($org, array $s, $audience, $event_id, array $list_ids, $cache = true) {
        $own = $audience === 'brevo_list' ? [] : self::recipients($org, $audience, $event_id);
        $out = ['count' => count($own), 'own' => count($own), 'lists' => [], 'excluded' => 0, 'exact' => true, '_own' => $own, '_exclude' => []];
        if (!$list_ids) return $out;
        if ($s['api_key'] === '') return self::bad('api_key', 'Bitte zuerst Brevo verbinden.', 'tix_nl_not_connected', 409);
        $ck = 'tix_nl_aud_' . md5(intval($org) . '|' . $s['api_key'] . '|' . $audience . '|' . intval($event_id) . '|' . implode(',', $list_ids) . '|' . count($own));
        if ($cache && is_array($c = get_transient($ck))) return $c + ['_own' => $own];
        $known = self::brevo_lists($org, $s);
        if (is_wp_error($known)) return $known;
        $names = array_column($known, 'name', 'id');
        $sizes = [];
        foreach ($list_ids as $id) {
            $r = self::brevo($s['api_key'], 'GET', '/contacts/lists/' . $id);
            if (is_wp_error($r)) return new WP_Error('tix_brevo_error', $r->get_error_message(), ['status' => 502]);
            if ($r[0] === 404) return new WP_Error('tix_nl_list_gone', 'Die Brevo-Liste „' . ($names[$id] ?? ('#' . $id)) . '“ gibt es nicht mehr.', ['status' => 409, 'list_id' => $id, 'field' => 'list_ids']);
            if ($r[0] !== 200) return new WP_Error('tix_brevo_error', self::brevo_err($r, 'Brevo liefert die Liste nicht.'), ['status' => 502]);
            $sizes[$id] = intval($r[1]['uniqueSubscribers'] ?? $r[1]['totalSubscribers'] ?? 0);
            $out['lists'][] = ['id' => $id, 'count' => $sizes[$id]];
        }
        $total = count($own) + array_sum($sizes);
        if ($total > self::MAX_RECIPIENTS) {
            // zu groß zum Durchzählen: Summe als Schätzung (Studio zeigt „etwa …“)
            $out['count'] = min($total, self::MAX_RECIPIENTS * 10); $out['exact'] = false;
        } else {
            list($plain, $hash) = self::unsub_sets($org);
            $seen = []; foreach ($own as $em => $fn) $seen[strtolower($em)] = 1;
            $excl = [];
            $raw = count($own);
            foreach ($list_ids as $id) {
                for ($off = 0; $off < self::MAX_RECIPIENTS; $off += 500) {
                    $r = self::brevo($s['api_key'], 'GET', '/contacts/lists/' . $id . '/contacts?limit=500&offset=' . $off);
                    if (is_wp_error($r)) return new WP_Error('tix_brevo_error', $r->get_error_message(), ['status' => 502]);
                    if ($r[0] === 404) return new WP_Error('tix_nl_list_gone', 'Die Brevo-Liste „' . ($names[$id] ?? ('#' . $id)) . '“ gibt es nicht mehr.', ['status' => 409, 'list_id' => $id, 'field' => 'list_ids']);
                    if ($r[0] !== 200) return new WP_Error('tix_brevo_error', self::brevo_err($r, 'Brevo liefert die Kontakte nicht.'), ['status' => 502]);
                    $page = (array) ($r[1]['contacts'] ?? []);
                    foreach ($page as $x) {
                        $em = strtolower((string) ($x['email'] ?? ''));
                        if ($em === '') continue;
                        $raw++;
                        if (isset($seen[$em])) continue;
                        $seen[$em] = 1;
                        if (isset($plain[$em]) || isset($hash[self::email_hash($em)])) { $excl[$em] = 1; continue; }
                        if (!empty($x['emailBlacklisted'])) { $excl[$em] = 0; continue; } // kennt Brevo selbst, nicht importieren
                    }
                    if (count($page) < 500) break;
                }
            }
            $out['count'] = count($seen) - count($excl);
            $out['excluded'] = $raw - $out['count'];
            $out['_exclude'] = array_keys(array_filter($excl));
        }
        $keep = $out; unset($keep['_own']);
        set_transient($ck, $keep, MINUTE_IN_SECONDS);
        return $out;
    }

    /** GET /marketing/newsletter/audience?audience=contacts|event|brevo_list&event_id=&list_ids=7,9 → {count, own, lists, excluded, exact} */
    public static function rest_audience(WP_REST_Request $req) {
        $org = self::org($req);
        $a = (string) ($req->get_param('audience') ?: 'contacts');
        $ids = self::parse_ids($req->get_param('list_ids'));
        if (count($ids) > self::MAX_LISTS) return self::bad('list_ids', 'Höchstens ' . self::MAX_LISTS . ' Brevo-Listen.');
        if ($a === 'brevo_list' && !$ids) {
            // ältere Studios: Listen zur Auswahl (ohne Zählung)
            $l = self::brevo_lists($org, self::settings($org));
            if (is_wp_error($l)) return $l->get_error_code() === 'tix_nl_not_connected' ? self::bad('api_key', 'Bitte zuerst Brevo verbinden.') : $l;
            return rest_ensure_response(['ok' => true, 'audience' => 'brevo_list', 'lists' => array_map(function ($x) { return ['id' => $x['id'], 'name' => $x['name'], 'count' => $x['count']]; }, $l)]);
        }
        $eid = absint($req->get_param('event_id'));
        if ($a === 'event' && !self::event_ok($eid)) return self::bad('event_id', 'Event nicht gefunden.', 'not_found', 404);
        $a = in_array($a, ['event', 'brevo_list'], true) ? $a : 'contacts';
        $res = self::count_audience($org, self::settings($org), $a, $eid, $ids);
        if (is_wp_error($res)) return $res;
        unset($res['_own'], $res['_exclude']);
        return rest_ensure_response(['ok' => true, 'audience' => $a] + $res);
    }

    // ═══════════════ Kampagnen ═══════════════

    private static function get($id, $org) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::tk() . " WHERE id = %d AND organizer_id = %d", intval($id), intval($org)), ARRAY_A);
        return $r ?: null;
    }

    /** Felder aus der Anfrage prüfen → [set, WP_Error|null] */
    private static function fields(WP_REST_Request $req, $org, array $cur = null) {
        $set = [];
        foreach (['name' => 120, 'subject' => 200, 'preheader' => 200] as $f => $len) if ($req->has_param($f)) $set[$f] = mb_substr(trim(wp_strip_all_tags((string) $req->get_param($f))), 0, $len);
        if ($req->has_param('content')) { $set['content'] = wp_kses_post((string) $req->get_param('content')); if (!$req->has_param('blocks')) $set['blocks'] = null; }
        // Baukasten (1.38.654): Blöcke als JSON; content = gerendertes HTML (für Prüfung und Altbestand), beim Versand frisch gerendert
        if ($req->has_param('blocks') && class_exists('TIX_Newsletter_Blocks')) {
            $b = TIX_Newsletter_Blocks::sanitize($req->get_param('blocks'));
            if (is_wp_error($b)) return [null, $b];
            $set['blocks'] = wp_json_encode($b);
            $set['content'] = TIX_Newsletter_Blocks::render($b, $org);
        }
        $a = $cur ? (string) $cur['audience'] : 'contacts';
        if ($req->has_param('audience')) {
            $a = (string) $req->get_param('audience');
            if (!in_array($a, ['contacts', 'event', 'brevo_list'], true)) return [null, self::bad('audience', 'Bitte eine Zielgruppe wählen.')];
            $set['audience'] = $a;
            $set['event_id'] = 0;
            if ($a === 'event') {
                $eid = absint($req->get_param('event_id'));
                if (!self::event_ok($eid)) return [null, self::bad('event_id', 'Event nicht gefunden.', 'not_found', 404)];
                $set['event_id'] = $eid;
            }
        }
        // Brevo-Listen (§105): list_ids gewinnt; älteres Studio schickt nur list_id (bei brevo_list)
        $touch = $req->has_param('audience') || $req->has_param('list_ids') || $req->has_param('list_id');
        if ($touch) {
            if ($req->has_param('list_ids')) $ids = self::parse_ids($req->get_param('list_ids'));
            elseif ($req->has_param('list_id') && absint($req->get_param('list_id'))) $ids = [absint($req->get_param('list_id'))];
            elseif ($req->has_param('audience')) $ids = [];
            else $ids = $cur ? self::list_ids_of($cur) : [];
            if ($a === 'brevo_list' && !$ids) return [null, self::bad('list_ids', 'Bitte mindestens eine Brevo-Liste wählen.')];
            if (count($ids) > self::MAX_LISTS) return [null, self::bad('list_ids', 'Höchstens ' . self::MAX_LISTS . ' Brevo-Listen.')];
            $meta = $cur ? self::meta_of($cur) : [];
            if ($ids) {
                if (!rest_sanitize_boolean($req->get_param('consent_confirmed'))) return [null, self::bad('consent_confirmed', 'Bitte bestätige, dass alle Kontakte dieser Liste eingewilligt haben.')];
                $known = self::brevo_lists($org, self::settings($org));
                if (is_wp_error($known)) return [null, $known];
                $names = array_column($known, 'name', 'id');
                foreach ($ids as $id) if (!isset($names[$id])) return [null, new WP_Error('tix_invalid_field', 'Diese Brevo-Liste gibt es im Konto nicht.', ['status' => 400, 'field' => 'list_ids', 'list_id' => $id])];
                $meta['consent'] = ['confirmed' => true, 'at' => gmdate('c'), 'by' => get_current_user_id()];
                $meta['lists'] = array_map(function ($id) use ($names) { return ['id' => $id, 'name' => $names[$id]]; }, $ids);
            } else unset($meta['consent'], $meta['lists']);
            $set['brevo_lists_src'] = wp_json_encode($ids);
            $set['brevo_list_src'] = $ids ? $ids[0] : 0;
            $set['audience_meta'] = $meta ? wp_json_encode($meta) : null;
        }
        return [$set, null];
    }

    public static function rest_create(WP_REST_Request $req) {
        self::maybe_create();
        global $wpdb;
        $org = self::org($req);
        list($set, $err) = self::fields($req, $org);
        if ($err) return $err;
        $row = array_merge(['organizer_id' => $org, 'name' => '', 'subject' => '', 'preheader' => '', 'content' => '', 'audience' => 'contacts', 'event_id' => 0, 'brevo_list_src' => 0,
            'status' => 'draft', 'created_by' => get_current_user_id(), 'created' => self::now(), 'updated' => self::now()], $set);
        if ($row['name'] === '') $row['name'] = $row['subject'] !== '' ? $row['subject'] : 'Newsletter ' . wp_date('d.m.Y');
        $wpdb->insert(self::tk(), $row);
        return rest_ensure_response(['ok' => true, 'campaign' => self::out(self::get($wpdb->insert_id, $org), false)]);
    }

    public static function rest_update(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        $c = self::get($req['id'], $org);
        if (!$c) return new WP_Error('not_found', 'Kampagne nicht gefunden.', ['status' => 404]);
        if ($c['status'] !== 'draft') return self::bad('status', 'Versendete oder geplante Kampagnen lassen sich nicht mehr ändern.', 'tix_nl_locked', 409);
        list($set, $err) = self::fields($req, $org, $c);
        if ($err) return $err;
        if ($set) $wpdb->update(self::tk(), $set + ['updated' => self::now()], ['id' => intval($c['id'])]);
        return rest_ensure_response(['ok' => true, 'campaign' => self::out(self::get($c['id'], $org), false)]);
    }

    public static function rest_delete(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        $c = self::get($req['id'], $org);
        if (!$c) return new WP_Error('not_found', 'Kampagne nicht gefunden.', ['status' => 404]);
        if (in_array($c['status'], ['preparing', 'sent'], true)) return self::bad('status', 'Versendete Kampagnen bleiben für die Auswertung erhalten.', 'tix_nl_locked', 409);
        $s = self::settings($org);
        if ($s['api_key'] !== '' && $c['brevo_campaign']) self::brevo($s['api_key'], 'DELETE', '/emailCampaigns/' . intval($c['brevo_campaign']));
        $wpdb->delete(self::tk(), ['id' => intval($c['id'])]);
        return rest_ensure_response(['ok' => true]);
    }

    public static function rest_campaigns(WP_REST_Request $req) {
        self::maybe_create();
        global $wpdb;
        $org = self::org($req);
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::tk() . " WHERE organizer_id = %d ORDER BY id DESC LIMIT 200", $org), ARRAY_A);
        $sales = self::sales(array_map(function ($r) { return intval($r['id']); }, array_filter($rows, function ($r) { return $r['status'] === 'sent'; })));
        return rest_ensure_response(['ok' => true, 'campaigns' => array_map(function ($r) use ($sales) { return self::out($r, false, $sales[intval($r['id'])] ?? null); }, $rows)]);
    }

    public static function rest_campaign(WP_REST_Request $req) {
        $org = self::org($req);
        $c = self::get($req['id'], $org);
        if (!$c) return new WP_Error('not_found', 'Kampagne nicht gefunden.', ['status' => 404]);
        if ($c['status'] === 'sent' && (!$c['stats_at'] || strtotime($c['stats_at'] . ' UTC') < time() - 900)) { self::refresh_stats($c); $c = self::get($c['id'], $org); }
        $sales = $c['status'] === 'sent' ? self::sales([intval($c['id'])]) : [];
        return rest_ensure_response(['ok' => true, 'campaign' => self::out($c, true, $sales[intval($c['id'])] ?? null)]);
    }

    /** GET …/preview → {html, subject} – so, wie die Mail bei Brevo ankommt (Abmeldelink als Platzhalter) */
    /** Vorschau: Abmeldelink als #, Vorname-Platzhalter als Ersatztext */
    public static function preview_html($html) {
        $html = str_replace('{{ unsubscribe }}', '#', (string) $html);
        return preg_replace('/\{\{\s*contact\.FIRSTNAME\s*\|\s*default:\s*"([^"]*)"\s*\}\}/', '$1', $html);
    }

    /** Blöcke einer Kampagne (für „als Vorlage speichern“) */
    public static function campaign_blocks($id, $org) {
        $c = self::get($id, $org);
        if (!$c) return null;
        $bl = !empty($c['blocks']) ? json_decode((string) $c['blocks'], true) : null;
        return is_array($bl) ? $bl : (class_exists('TIX_Newsletter_Blocks') ? TIX_Newsletter_Blocks::from_legacy($c['content']) : []);
    }

    /** POST …/campaigns/{id}/duplicate → neuer Entwurf mit gleichem Inhalt und gleicher Zielgruppe */
    public static function rest_duplicate(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        $c = self::get($req['id'], $org);
        if (!$c) return new WP_Error('not_found', 'Kampagne nicht gefunden.', ['status' => 404]);
        $row = ['organizer_id' => $org, 'name' => mb_substr('Kopie von ' . $c['name'], 0, 120), 'subject' => $c['subject'], 'preheader' => $c['preheader'], 'content' => $c['content'], 'blocks' => $c['blocks'],
            'audience' => $c['audience'], 'event_id' => $c['event_id'], 'brevo_list_src' => $c['brevo_list_src'], 'brevo_lists_src' => $c['brevo_lists_src'], 'audience_meta' => $c['audience_meta'], 'status' => 'draft', 'created_by' => get_current_user_id(), 'created' => self::now(), 'updated' => self::now()];
        $wpdb->insert(self::tk(), $row);
        return new WP_REST_Response(['ok' => true, 'campaign' => self::out(self::get($wpdb->insert_id, $org), true)], 201);
    }

    public static function rest_preview(WP_REST_Request $req) {
        $org = self::org($req);
        $c = self::get($req['id'], $org);
        if (!$c) return new WP_Error('not_found', 'Kampagne nicht gefunden.', ['status' => 404]);
        return rest_ensure_response(['ok' => true, 'subject' => $c['subject'], 'html' => self::preview_html(self::html($c))]);
    }

    private static function out(array $c, $full, $sales = null) {
        $st = $c['stats'] ? json_decode((string) $c['stats'], true) : null;
        $eid = intval($c['event_id']);
        $o = ['id' => intval($c['id']), 'name' => $c['name'], 'subject' => $c['subject'], 'preheader' => $c['preheader'], 'status' => $c['status'],
            'audience' => $c['audience'], 'event' => $eid ? ['id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8')] : null,
            'list_id' => self::list_ids_of($c)[0] ?? null, 'list_ids' => self::list_ids_of($c), 'scheduled_at' => $c['scheduled_at'] ? mysql_to_rfc3339($c['scheduled_at']) : null,
            'sent_at' => $c['sent_at'] ? mysql_to_rfc3339($c['sent_at']) : null, 'recipients' => intval($c['recipients']), 'error' => $c['error'] ?: null,
            'utm_campaign' => 'nl-' . intval($c['id']), 'stats' => is_array($st) ? $st : null, 'stats_at' => $c['stats_at'] ? mysql_to_rfc3339($c['stats_at']) : null,
            'orders' => $sales ? $sales['orders'] : ($c['status'] === 'sent' ? 0 : null), 'tickets' => $sales ? $sales['tickets'] : ($c['status'] === 'sent' ? 0 : null),
            'revenue' => $sales ? $sales['revenue'] : ($c['status'] === 'sent' ? 0.0 : null), 'updated' => mysql_to_rfc3339($c['updated'])];
        if ($full) {
            $m = self::meta_of($c);
            $o['lists'] = $m['lists'] ?? [];
            $o['consent'] = isset($m['consent']) ? ['confirmed' => true, 'at' => $m['consent']['at'] ?? null, 'by' => intval($m['consent']['by'] ?? 0)] : null;
            if (isset($m['sent_lists'])) $o['sent_lists'] = $m['sent_lists'];
            $o['content'] = $c['content'];
            // Baukasten: Blöcke; alte Kampagnen erscheinen als ein HTML-Block (editor = html)
            $bl = !empty($c['blocks']) ? json_decode((string) $c['blocks'], true) : null;
            $o['editor'] = is_array($bl) ? 'blocks' : 'html';
            $o['blocks'] = is_array($bl) ? $bl : (class_exists('TIX_Newsletter_Blocks') ? TIX_Newsletter_Blocks::from_legacy($c['content']) : []);
        }
        return $o;
    }

    /** Käufe je Kampagne: bezahlte Bestellungen mit Herkunft utm_campaign/c = nl-<id>, Zahlen aus TIX_Sales_Figures */
    private static function sales(array $ids) {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !class_exists('TIX_Order_Attribution') || !class_exists('TIX_Sales_Figures')) return [];
        global $wpdb;
        $keys = array_map(function ($i) { return 'nl-' . $i; }, $ids);
        $in = implode(',', array_map(function ($k) use ($wpdb) { return $wpdb->prepare('%s', $k); }, $keys));
        $map = [];
        foreach ((array) $wpdb->get_results("SELECT order_id, campaign, link_code FROM " . TIX_Order_Attribution::table() . " WHERE campaign IN ($in) OR link_code IN ($in) LIMIT 20000") as $r) {
            $k = in_array($r->link_code, $keys, true) ? $r->link_code : $r->campaign;
            $map[intval($r->order_id)] = intval(substr($k, 3));
        }
        $out = [];
        if (!$map) return $out;
        $res = TIX_Sales_Figures::for_orders(array_keys($map));
        foreach ($res['rows'] as $r) {
            if (empty($r->money['paid'])) continue;
            $cid = $map[intval($r->id)] ?? 0;
            if (!$cid) continue;
            if (!isset($out[$cid])) $out[$cid] = ['orders' => 0, 'tickets' => 0, 'revenue' => 0.0, '_o' => []];
            if (empty($out[$cid]['_o'][intval($r->id)])) { $out[$cid]['orders']++; $out[$cid]['tickets'] += intval($res['order_tickets'][intval($r->id)] ?? 0); $out[$cid]['_o'][intval($r->id)] = 1; }
            $out[$cid]['revenue'] = round($out[$cid]['revenue'] + floatval($r->money['net']), 2);
        }
        foreach ($out as &$x) unset($x['_o']);
        return $out;
    }

    // ═══════════════ Mail bauen ═══════════════

    /** Links auf die eigene Website mit Kampagne versehen */
    public static function tag_links($html, $cid) {
        $hosts = array_filter(array_unique([strtolower((string) wp_parse_url(self::customer_base(), PHP_URL_HOST)), strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))]));
        $key = 'nl-' . intval($cid);
        $base = self::customer_base();
        // relative Links (/events/…) gelten in einer Mail nicht: auf die Kunden-Website umschreiben
        return preg_replace_callback('/href=(["\'])((?:https?:\/\/|\/(?!\/))[^"\']*)\1/i', function ($m) use ($hosts, $key, $base) {
            $u = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if ($u[0] === '/') $u = $base . $u;
            $h = preg_replace('/^www\./', '', strtolower((string) wp_parse_url($u, PHP_URL_HOST)));
            $own = false; foreach ($hosts as $x) if ($h === preg_replace('/^www\./', '', $x)) $own = true;
            if (!$own) return $m[0];
            $u = add_query_arg(['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => $key, 'c' => $key], $u);
            return 'href=' . $m[1] . esc_url($u) . $m[1];
        }, (string) $html);
    }

    public static function html(array $c) {
        $org = intval($c['organizer_id']);
        $s = self::settings($org);
        $addr = $s['address'] !== '' ? $s['address'] : self::default_address($org);
        $bl = !empty($c['blocks']) && class_exists('TIX_Newsletter_Blocks') ? json_decode((string) $c['blocks'], true) : null;
        // Baukasten: frisch aus den Blöcken (Event-Daten, Gutschein aktuell), ohne wpautop
        $content = self::tag_links(is_array($bl) ? TIX_Newsletter_Blocks::render($bl, $org) : wpautop((string) $c['content']), $c['id']);
        // Nur Brevo-Listen: die Liste stammt aus dem Konto des Veranstalters (§105)
        $why = ($c['audience'] ?? '') === 'brevo_list'
            ? 'Du bekommst diese Mail, weil du dich bei ' . esc_html($s['sender_name'] !== '' ? $s['sender_name'] : self::owner_name($org)) . ' angemeldet hast.'
            : 'Du bekommst diese Mail, weil du dich für den Newsletter von ' . esc_html(self::owner_name($org)) . ' angemeldet hast.';
        $note = $why . ' <a href="{{ unsubscribe }}" style="text-decoration:underline;">Abmelden</a><br>' . esc_html($addr);
        if (class_exists('TIX_Mail_Layout')) return TIX_Mail_Layout::render(['title' => (string) $c['subject'], 'hide_title' => is_array($bl) || strpos($content, 'tm-nl-hero') !== false, 'preheader' => (string) $c['preheader'], 'kind' => 'promo', 'blocks' => [['type' => 'html', 'html' => $content]], 'note' => $note]);
        return '<!doctype html><html><body>' . $content . '<p>' . $note . '</p></body></html>';
    }

    // ═══════════════ Testversand und Versand ═══════════════

    private static function ready(array $c, array $s) {
        if ($s['api_key'] === '') return self::bad('api_key', 'Bitte zuerst Brevo verbinden.', 'tix_nl_not_connected', 409);
        if ($s['sender_email'] === '') return self::bad('sender_id', 'Bitte einen in Brevo bestätigten Absender wählen.', 'tix_nl_no_sender', 409);
        if (trim($c['subject']) === '') return self::bad('subject', 'Bitte einen Betreff eingeben.');
        if (trim(wp_strip_all_tags($c['content'])) === '') return self::bad('content', 'Die Mail ist noch leer.');
        return true;
    }

    /** Brevo-Kampagne anlegen bzw. aktualisieren → Brevo-ID oder WP_Error */
    private static function push_campaign(array $c, array $s, array $extra = []) {
        global $wpdb;
        $body = array_merge(['name' => 'Tixomat · ' . $c['name'] . ' (#' . $c['id'] . ')', 'subject' => $c['subject'], 'previewText' => $c['preheader'],
            'sender' => ['id' => $s['sender_id']], 'htmlContent' => self::html($c), 'inlineImageActivation' => false, 'mirrorActive' => false], $extra);
        if ($c['brevo_campaign']) {
            $r = self::brevo($s['api_key'], 'PUT', '/emailCampaigns/' . intval($c['brevo_campaign']), $body);
            if (is_wp_error($r)) return $r;
            if ($r[0] === 204 || $r[0] === 200) return intval($c['brevo_campaign']);
            if ($r[0] !== 404) return new WP_Error('tix_brevo_error', self::brevo_err($r, 'Brevo lehnt die Kampagne ab.'), ['status' => 502]);
        }
        $r = self::brevo($s['api_key'], 'POST', '/emailCampaigns', $body);
        if (is_wp_error($r)) return $r;
        if ($r[0] !== 201 || empty($r[1]['id'])) return new WP_Error('tix_brevo_error', self::brevo_err($r, 'Brevo lehnt die Kampagne ab.'), ['status' => 502]);
        $wpdb->update(self::tk(), ['brevo_campaign' => intval($r[1]['id'])], ['id' => intval($c['id'])]);
        return intval($r[1]['id']);
    }

    /** POST …/test {emails: [..]} (höchstens 5, z. B. die eigene Adresse) */
    public static function rest_test(WP_REST_Request $req) {
        $org = self::org($req);
        $c = self::get($req['id'], $org);
        if (!$c) return new WP_Error('not_found', 'Kampagne nicht gefunden.', ['status' => 404]);
        $s = self::settings($org);
        if (($ok = self::ready($c, $s)) !== true) return $ok;
        $emails = array_values(array_unique(array_filter(array_map(function ($e) { return strtolower(sanitize_email((string) $e)); }, (array) ($req->get_param('emails') ?: [wp_get_current_user()->user_email])), 'is_email')));
        if (!$emails || count($emails) > 5) return self::bad('emails', 'Bitte 1 bis 5 gültige Adressen angeben.');
        $bid = self::push_campaign($c, $s);
        if (is_wp_error($bid)) return $bid;
        $r = self::brevo($s['api_key'], 'POST', '/emailCampaigns/' . $bid . '/sendTest', ['emailTo' => $emails]);
        if (is_wp_error($r)) return $r;
        if ($r[0] !== 204 && $r[0] !== 200) return new WP_Error('tix_brevo_error', self::brevo_err($r, 'Der Testversand hat nicht geklappt.'), ['status' => 502]);
        return rest_ensure_response(['ok' => true, 'sent_to' => $emails]);
    }

    /** POST …/send {at?: ISO} – jetzt oder geplant (mindestens 10 min, höchstens 90 Tage in der Zukunft) */
    public static function rest_send(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        $c = self::get($req['id'], $org);
        if (!$c) return new WP_Error('not_found', 'Kampagne nicht gefunden.', ['status' => 404]);
        if ($c['status'] !== 'draft') return self::bad('status', 'Diese Kampagne ist schon geplant oder versendet.', 'tix_nl_locked', 409);
        $s = self::settings($org);
        if (($ok = self::ready($c, $s)) !== true) return $ok;
        // frisch zählen (gelöschte Liste sofort melden), nicht aus dem 60-s-Zwischenspeicher
        $cnt = self::count_audience($org, $s, $c['audience'], intval($c['event_id']), self::list_ids_of($c), false);
        if (is_wp_error($cnt)) return $cnt;
        if (!$cnt['count']) return self::bad('audience', self::list_ids_of($c) ? 'In dieser Zielgruppe ist niemand übrig, der die Mail bekommen darf.' : 'In dieser Zielgruppe ist noch niemand mit bestätigter Anmeldung.', 'tix_nl_empty', 409);
        $at = (string) $req->get_param('at');
        $ts = $at !== '' ? strtotime($at) : time();
        if ($at !== '' && ($ts === false || $ts < time() + 600 || $ts > time() + 90 * DAY_IN_SECONDS)) return self::bad('at', 'Bitte einen Zeitpunkt zwischen 10 Minuten und 90 Tagen ab jetzt wählen.');
        $wpdb->update(self::tk(), ['status' => 'scheduled', 'scheduled_at' => gmdate('Y-m-d H:i:s', $ts), 'error' => '', 'updated' => self::now()], ['id' => intval($c['id'])]);
        if ($at === '') self::advance(self::get($c['id'], $org));
        return rest_ensure_response(['ok' => true, 'campaign' => self::out(self::get($c['id'], $org), false)]);
    }

    /** POST …/cancel – geplante Kampagne zurück auf Entwurf (solange Brevo noch nicht sendet) */
    public static function rest_cancel(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        $c = self::get($req['id'], $org);
        if (!$c) return new WP_Error('not_found', 'Kampagne nicht gefunden.', ['status' => 404]);
        if (!in_array($c['status'], ['scheduled', 'preparing', 'failed'], true)) return self::bad('status', 'Nur geplante Kampagnen lassen sich zurückholen.', 'tix_nl_locked', 409);
        $s = self::settings($org);
        if ($c['status'] === 'preparing' && $c['brevo_campaign'] && $s['api_key'] !== '') {
            $r = self::brevo($s['api_key'], 'PUT', '/emailCampaigns/' . intval($c['brevo_campaign']) . '/status', ['status' => 'suspended']);
            if (is_wp_error($r) || !in_array($r[0], [200, 204], true)) return self::bad('status', 'Brevo versendet die Kampagne schon.', 'tix_nl_locked', 409);
        }
        // nur eigene Kampagnen-/Ausschluss-Listen löschen, nie die gewählten Listen des Kunden
        if ($s['api_key'] !== '') self::delete_own_lists($c, $s);
        $m = self::meta_of($c); unset($m['excl_process'], $m['sent_lists']);
        $wpdb->update(self::tk(), ['status' => 'draft', 'scheduled_at' => null, 'brevo_list' => 0, 'brevo_excl' => 0, 'brevo_process' => 0, 'audience_meta' => $m ? wp_json_encode($m) : null, 'updated' => self::now()], ['id' => intval($c['id'])]);
        return rest_ensure_response(['ok' => true, 'campaign' => self::out(self::get($c['id'], $org), false)]);
    }

    /** Eigene Listen der Kampagne (Kampagnen-Liste, Ausschluss-Liste) löschen – gewählte Kundenlisten nie */
    private static function delete_own_lists(array $c, array $s, $lists = true, $excl = true) {
        $keep = self::list_ids_of($c);
        if ($lists && intval($c['brevo_list']) && $c['audience'] !== 'brevo_list' && !in_array(intval($c['brevo_list']), $keep, true)) self::brevo($s['api_key'], 'DELETE', '/contacts/lists/' . intval($c['brevo_list']));
        if ($excl && intval($c['brevo_excl'] ?? 0) && !in_array(intval($c['brevo_excl']), $keep, true)) self::brevo($s['api_key'], 'DELETE', '/contacts/lists/' . intval($c['brevo_excl']));
    }

    /** Liste in Brevo anlegen und Adressen importieren → [list_id, process_id] oder Fehlertext */
    private static function import_list($org, array $s, $name, array $json) {
        $r = self::brevo($s['api_key'], 'POST', '/contacts/lists', ['name' => $name, 'folderId' => self::folder($org, $s)]);
        if (is_wp_error($r) || $r[0] !== 201) return is_wp_error($r) ? $r->get_error_message() : self::brevo_err($r, 'Brevo-Liste ließ sich nicht anlegen.');
        $list = intval($r[1]['id']);
        $p = self::brevo($s['api_key'], 'POST', '/contacts/import', ['jsonBody' => $json, 'listIds' => [$list], 'updateExistingContacts' => true, 'emptyContactsAttributes' => false]);
        if (is_wp_error($p) || !in_array($p[0], [200, 202], true)) return is_wp_error($p) ? $p->get_error_message() : self::brevo_err($p, 'Der Import zu Brevo hat nicht geklappt.');
        return [$list, intval($p[1]['processId'] ?? 0)];
    }

    private static function fail(array $c, $msg) {
        global $wpdb;
        $wpdb->update(self::tk(), ['status' => 'failed', 'error' => mb_substr((string) $msg, 0, 255), 'updated' => self::now()], ['id' => intval($c['id'])]);
    }

    /** Ordner „Tixomat“ in Brevo (für die Empfänger-Listen) */
    private static function folder($org, array $s) {
        if ($s['folder_id']) return $s['folder_id'];
        $r = self::brevo($s['api_key'], 'POST', '/contacts/folders', ['name' => 'Tixomat']);
        $id = !is_wp_error($r) && $r[0] === 201 ? intval($r[1]['id'] ?? 0) : 0;
        if ($id) self::save_settings($org, ['folder_id' => $id]);
        return $id;
    }

    /**
     * Versand Schritt für Schritt (Cron alle 5 min, „Jetzt senden“ direkt):
     * scheduled (Zeit erreicht bzw. ≤ 15 min davor) → Empfänger-Liste anlegen + importieren (preparing)
     * preparing + Import fertig → Brevo-Kampagne mit Liste anlegen, sendNow bzw. scheduledAt → sent
     */
    public static function advance($c) {
        global $wpdb;
        if (!$c) return;
        $org = intval($c['organizer_id']);
        $s = self::settings($org);
        if ($s['api_key'] === '' || $s['sender_email'] === '') return self::fail($c, 'Brevo ist nicht mehr verbunden.');
        if (!TIX_Ad_Accounts::module_on($org)) return self::fail($c, 'Das Modul Marketing ist nicht gebucht.');
        $ts = strtotime($c['scheduled_at'] . ' UTC');
        if ($c['status'] === 'scheduled') {
            if ($ts > time() + 15 * MINUTE_IN_SECONDS) return;
            $ids = self::list_ids_of($c);
            $cnt = self::count_audience($org, $s, $c['audience'], intval($c['event_id']), $ids, false);
            if (is_wp_error($cnt)) {
                if ($cnt->get_error_code() === 'tix_brevo_error') return; // Brevo kurz weg: beim nächsten Lauf neu
                if ($cnt->get_error_code() === 'tix_nl_list_gone') {
                    // Name zum Zeitpunkt der Auswahl (die Liste selbst kennt Brevo nicht mehr)
                    $gid = intval(($cnt->get_error_data()['list_id'] ?? 0));
                    $nm = array_column((array) (self::meta_of($c)['lists'] ?? []), 'name', 'id');
                    if (!empty($nm[$gid])) return self::fail($c, 'Die Brevo-Liste „' . $nm[$gid] . '“ gibt es nicht mehr.');
                }
                return self::fail($c, $cnt->get_error_message());
            }
            if (!$cnt['count']) return self::fail($c, 'In der Zielgruppe ist niemand mehr mit bestätigter Anmeldung.');
            $rec = $cnt['_own'];
            $list = 0; $proc = 0; $excl = 0; $eproc = 0;
            if ($rec) {
                $json = [];
                foreach ($rec as $em => $fn) $json[] = ['email' => $em] + ($fn !== '' ? ['attributes' => ['FIRSTNAME' => $fn]] : []);
                $res = self::import_list($org, $s, 'Tixomat · Kampagne #' . $c['id'] . ' · ' . wp_date('d.m.Y H:i'), $json);
                if (!is_array($res)) return self::fail($c, $res);
                list($list, $proc) = $res;
            }
            if ($ids && $cnt['_exclude']) {
                // eigene Abmeldungen auch für Brevo-Listen: Ausschluss-Liste
                $res = self::import_list($org, $s, 'Tixomat · Ausschluss #' . $c['id'] . ' · ' . wp_date('d.m.Y H:i'), array_map(function ($e) { return ['email' => $e]; }, $cnt['_exclude']));
                if (!is_array($res)) { if ($list) self::brevo($s['api_key'], 'DELETE', '/contacts/lists/' . $list); return self::fail($c, $res); }
                list($excl, $eproc) = $res;
            }
            $m = self::meta_of($c);
            if ($ids) {
                $names = array_column((array) ($m['lists'] ?? []), 'name', 'id');
                $sizes = array_column($cnt['lists'], 'count', 'id');
                $m['sent_lists'] = array_map(function ($id) use ($names, $sizes) { return ['id' => $id, 'name' => (string) ($names[$id] ?? ''), 'count' => intval($sizes[$id] ?? 0)]; }, $ids);
            }
            if ($eproc) $m['excl_process'] = $eproc; else unset($m['excl_process']);
            $wpdb->update(self::tk(), ['status' => 'preparing', 'brevo_list' => $list, 'brevo_excl' => $excl, 'brevo_process' => $proc, 'recipients' => intval($cnt['count']), 'audience_meta' => $m ? wp_json_encode($m) : null], ['id' => intval($c['id'])]);
            $c = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::tk() . " WHERE id = %d", intval($c['id'])), ARRAY_A);
        }
        if ($c['status'] !== 'preparing') return;
        $m = self::meta_of($c);
        foreach (array_filter([intval($c['brevo_process']), intval($m['excl_process'] ?? 0)]) as $pid) {
            $p = self::brevo($s['api_key'], 'GET', '/processes/' . $pid);
            if (is_wp_error($p)) return;
            $st = (string) ($p[1]['status'] ?? '');
            if ($st !== 'completed') { if ($st === 'failed') self::fail($c, 'Der Import zu Brevo ist fehlgeschlagen.'); return; }
        }
        $later = $ts > time() + 5 * MINUTE_IN_SECONDS;
        // Alte Kampagnen (vor §105) in preparing: brevo_list = gewählte Liste; neue: eigene Liste + gewählte Listen
        $lids = array_values(array_unique(array_filter(array_merge([intval($c['brevo_list'])], self::list_ids_of($c)))));
        $rcp = ['listIds' => $lids] + (intval($c['brevo_excl'] ?? 0) ? ['exclusionListIds' => [intval($c['brevo_excl'])]] : []);
        $bid = self::push_campaign($c, $s, ['recipients' => $rcp] + ($later ? ['scheduledAt' => gmdate('c', $ts)] : []));
        if (is_wp_error($bid)) return self::fail($c, $bid->get_error_message());
        if (!$later) {
            $r = self::brevo($s['api_key'], 'POST', '/emailCampaigns/' . $bid . '/sendNow');
            if (is_wp_error($r) || !in_array($r[0], [200, 204], true)) return self::fail($c, is_wp_error($r) ? $r->get_error_message() : self::brevo_err($r, 'Brevo hat den Versand abgelehnt.'));
        }
        unset($m['excl_process']);
        $wpdb->update(self::tk(), ['status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s', $later ? $ts : time()), 'brevo_process' => 0, 'audience_meta' => $m ? wp_json_encode($m) : null, 'updated' => self::now()], ['id' => intval($c['id'])]);
    }

    /** Kennzahlen von Brevo (globalStats) */
    public static function refresh_stats(array $c) {
        global $wpdb;
        $s = self::settings(intval($c['organizer_id']));
        if ($s['api_key'] === '' || !$c['brevo_campaign']) return;
        $r = self::brevo($s['api_key'], 'GET', '/emailCampaigns/' . intval($c['brevo_campaign']) . '?statistics=globalStats');
        if (is_wp_error($r) || $r[0] !== 200) return;
        $g = (array) ($r[1]['statistics']['globalStats'] ?? []);
        $st = ['sent' => intval($g['sent'] ?? 0), 'delivered' => intval($g['delivered'] ?? 0), 'opens' => intval($g['uniqueViews'] ?? 0), 'clicks' => intval($g['uniqueClicks'] ?? 0),
            'unsubscribed' => intval($g['unsubscriptions'] ?? 0), 'bounces' => intval($g['hardBounces'] ?? 0) + intval($g['softBounces'] ?? 0), 'complaints' => intval($g['complaints'] ?? 0)];
        $st['open_rate'] = $st['delivered'] ? round($st['opens'] / $st['delivered'] * 100, 1) : null;
        $st['click_rate'] = $st['delivered'] ? round($st['clicks'] / $st['delivered'] * 100, 1) : null;
        $wpdb->update(self::tk(), ['stats' => wp_json_encode($st), 'stats_at' => self::now(), 'recipients' => max(intval($c['recipients']), $st['sent'])], ['id' => intval($c['id'])]);
    }

    public static function cron() {
        self::maybe_create();
        global $wpdb;
        foreach ((array) $wpdb->get_results("SELECT * FROM " . self::tk() . " WHERE status IN ('scheduled','preparing') ORDER BY scheduled_at ASC LIMIT 5", ARRAY_A) as $c) self::advance($c);
        // Kennzahlen: 30 Tage nach dem Versand, höchstens stündlich je Kampagne
        $cut = gmdate('Y-m-d H:i:s', time() - self::STATS_DAYS * DAY_IN_SECONDS);
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::tk() . " WHERE status = 'sent' AND sent_at > %s AND (stats_at IS NULL OR stats_at < %s) ORDER BY stats_at ASC LIMIT 5", $cut, gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)), ARRAY_A) as $c) self::refresh_stats($c);
    }

    /** Täglich: Brevo-Sperrliste abgleichen, Empfänger-Listen nach 30 Tagen löschen, unbestätigte (7 Tage) und Abmeldungen (30 Tage) aufräumen */
    public static function daily() {
        self::maybe_create();
        global $wpdb;
        $scopes = [0];
        if (self::multi()) $scopes = array_merge($scopes, array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META))));
        foreach (array_unique($scopes) as $org) {
            $s = self::settings($org);
            if ($s['api_key'] === '') continue;
            $r = self::brevo($s['api_key'], 'GET', '/contacts?limit=500&modifiedSince=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', time() - 2 * DAY_IN_SECONDS)));
            if (!is_wp_error($r) && $r[0] === 200) foreach ((array) ($r[1]['contacts'] ?? []) as $x) {
                if (empty($x['emailBlacklisted'])) continue;
                self::unsubscribe_row($wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::tc() . " WHERE organizer_id = %d AND email = %s", $org, strtolower((string) $x['email']))), 'brevo');
            }
            // Kampagnen- und Ausschluss-Listen nach 30 Tagen löschen; gewählte Kundenlisten nie (delete_own_lists)
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::tk() . " WHERE organizer_id = %d AND status = 'sent' AND ((audience <> 'brevo_list' AND brevo_list > 0) OR brevo_excl > 0) AND sent_at < %s", $org, gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)), ARRAY_A) as $k) {
                self::delete_own_lists($k, $s);
                $wpdb->update(self::tk(), ['brevo_list' => $k['audience'] === 'brevo_list' ? intval($k['brevo_list']) : 0, 'brevo_excl' => 0], ['id' => intval($k['id'])]);
            }
        }
        $wpdb->query($wpdb->prepare("DELETE FROM " . self::tc() . " WHERE status = 'pending' AND consent_at < %s", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)));
        // Abmeldungen nach 30 Tagen: nur ein Hash bleibt (Sperrvermerk), Name und Einwilligung entfallen
        $wpdb->query($wpdb->prepare("UPDATE " . self::tc() . " SET status = 'blocked', email = SHA2(CONCAT(email, %s), 256), first_name = '', consent_text = '', consent_ip = '', token = SHA2(token, 256) WHERE status = 'unsubscribed' AND unsubscribed_at < %s", wp_salt('auth'), gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)));
    }

    public static function purge_organizer($o) {
        global $wpdb;
        $wpdb->delete(self::tc(), ['organizer_id' => intval($o)]);
        $wpdb->delete(self::tk(), ['organizer_id' => intval($o)]);
    }
}

TIX_Newsletter::init();
