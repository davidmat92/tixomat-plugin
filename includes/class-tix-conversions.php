<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Conversions – Conversion-Verteiler und Tracking-Einstellungen (Statistik Stufe 3a, docs/plan-statistik-marketing.md §4.4–4.5).
 *
 * Ziele je Bereich (Einzel-Seite: 0; evendis: 0 = Plattform selbst, sonst je Veranstalter):
 *   meta   {pixel_id, token (verschlüsselt), test_code, enabled}  → Conversions API (Graph v21.0, Purchase)
 *   tiktok {pixel_id, token (verschlüsselt), test_code, enabled}  → Events API 2.0 (v1.3 /event/track/, CompletePayment)
 *   ga4    {measurement_id, secret (verschlüsselt), enabled}      → Measurement Protocol (purchase) – nur eigene Shops
 *   gads   {conversion_id AW-…, label, enabled}                   → nur Browser (gtag) im eigenen Shop; Enhanced Conversions
 *                                                                    über gtag user_data, Offline-Upload braucht OAuth (später)
 * Regeln:
 *  - Kauf-Meldungen serverseitig nach bezahlter Bestellung (tix_order_completed), nur mit Einwilligung „Marketing“ beim Kauf
 *    (Banner-Kategorie, an der Bestellung: consent_marketing); GA4 mit „Statistik“ (consent_analytics) und Client-ID aus _ga.
 *  - event_id = „tix_<Bestellnummer>“ – dieselbe ID schickt der Browser-Pixel im eigenen Shop (Dedup).
 *  - evendis.de: Server-Meldungen an den Veranstalter und an die Plattform; Browser-Pixel nur im eigenen Shop (F4 A);
 *    kein GA4 und kein Google Ads auf der Plattform (Fund Rechtstexte: ohne Browser-Tag keine Client-ID).
 *  - Server-Meldungen gehören zum Modul Marketing; Browser-Pixel/Mess-IDs im eigenen Shop sind frei (P2).
 *  - Ist für die Seite Meta hier eingetragen, schweigt der alte Weg (TIX_Meta_Pixel, WP-Einstellungen) – nie doppelt.
 *  - Protokoll: letzte Meldungen je Bereich ohne personenbezogene Daten ({prefix}tix_conv_log).
 */
class TIX_Conversions {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const TARGETS = ['meta' => 'Meta (Facebook & Instagram)', 'tiktok' => 'TikTok', 'ga4' => 'Google Analytics 4', 'gads' => 'Google Ads'];
    const OPT = 'tix_conv_targets';
    const META = '_tix_conv_targets';
    const CRON = 'tix_conv_send';
    const KEEP_LOG = 200;
    const GRAPH = 'v21.0';

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_create'], 6);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('tix_order_completed', [__CLASS__, 'on_paid'], 20);
        add_action(self::CRON, [__CLASS__, 'send'], 10, 4);
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'tix_conv_log'; }

    public static function maybe_create() {
        if (get_option('tix_conv_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            target VARCHAR(10) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            event_id VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(10) NOT NULL,
            code INT NOT NULL DEFAULT 0,
            message VARCHAR(255) NOT NULL DEFAULT '',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
            PRIMARY KEY  (id),
            KEY org (organizer_id, id),
            KEY ord (order_id, organizer_id, target)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_conv_db', self::DB_VERSION, true);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    /** Welche Ziele es im Bereich gibt: evendis ohne GA4/Google Ads (weder Plattform noch Veranstalter) */
    public static function available($org) {
        $m = self::multi();
        return ['meta' => true, 'tiktok' => true, 'ga4' => !$m, 'gads' => !$m];
    }

    // ═══════════════ Einstellungen ═══════════════

    public static function raw($org) {
        $v = $org ? get_post_meta(intval($org), self::META, true) : get_option(self::OPT, []);
        return is_array($v) ? $v : [];
    }

    private static function save_raw($org, array $v) {
        if ($org) update_post_meta(intval($org), self::META, $v); else update_option(self::OPT, $v, false);
    }

    /** Ziel mit entschlüsseltem Token (nur serverseitig) */
    public static function target($org, $t) {
        $r = self::raw($org)[$t] ?? [];
        if (!is_array($r) || empty(self::available($org)[$t])) return null;
        $r['token'] = isset($r['token_enc']) ? TIX_Ad_Accounts::dec($r['token_enc']) : '';
        $r['secret'] = isset($r['secret_enc']) ? TIX_Ad_Accounts::dec($r['secret_enc']) : '';
        return $r;
    }

    /** Für Studio und Website: ohne Geheimnisse (gesetzt + letzte 4 Zeichen) */
    public static function public_targets($org) {
        $out = [];
        foreach (self::TARGETS as $t => $label) {
            if (empty(self::available($org)[$t])) continue;
            $r = self::target($org, $t) ?: [];
            $x = ['key' => $t, 'label' => $label, 'enabled' => !empty($r['enabled'])];
            if ($t === 'meta' || $t === 'tiktok') $x += ['pixel_id' => (string) ($r['pixel_id'] ?? ''), 'token_set' => ($r['token'] ?? '') !== '', 'token_last4' => ($r['token'] ?? '') !== '' ? substr($r['token'], -4) : '', 'test_code' => (string) ($r['test_code'] ?? '')];
            if ($t === 'ga4') $x += ['measurement_id' => (string) ($r['measurement_id'] ?? ''), 'secret_set' => ($r['secret'] ?? '') !== '', 'secret_last4' => ($r['secret'] ?? '') !== '' ? substr($r['secret'], -4) : ''];
            if ($t === 'gads') $x += ['conversion_id' => (string) ($r['conversion_id'] ?? ''), 'label_value' => (string) ($r['label'] ?? '')];
            $out[$t] = $x;
        }
        return $out;
    }

    /** Browser-IDs fürs eigene Shop-Frontend (site-config tracking): nur eingeschaltete Ziele, nur IDs */
    public static function browser_ids() {
        $o = [];
        foreach (['meta' => 'pixel_id', 'tiktok' => 'pixel_id', 'ga4' => 'measurement_id', 'gads' => 'conversion_id'] as $t => $f) {
            $r = self::target(0, $t);
            if ($r && !empty($r['enabled']) && !empty($r[$f])) $o[$t] = $t === 'gads' ? ['id' => (string) $r['conversion_id'], 'label' => (string) ($r['label'] ?? '')] : (string) $r[$f];
        }
        return $o;
    }

    /** Hat der neue Verteiler Meta für die Seite? (dann schweigt TIX_Meta_Pixel) */
    public static function handles_meta_site() {
        $r = self::target(0, 'meta');
        return $r && !empty($r['enabled']) && !empty($r['pixel_id']) && $r['token'] !== '';
    }

    private static function bad($field, $msg) { return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]); }

    /** Werte prüfen und speichern; leerer token/secret = behalten, token:null = löschen */
    public static function save($org, array $in) {
        $cur = self::raw($org);
        foreach ($in as $t => $v) {
            if (!isset(self::TARGETS[$t]) || !is_array($v)) continue;
            if (empty(self::available($org)[$t])) return self::bad($t, 'Auf der Ticketplattform gibt es ' . self::TARGETS[$t] . ' nicht (Server-Meldungen gehen ohne Browser-Kennung nicht).');
            $c = is_array($cur[$t] ?? null) ? $cur[$t] : [];
            if (array_key_exists('enabled', $v)) $c['enabled'] = (bool) $v['enabled'];
            if (array_key_exists('pixel_id', $v)) {
                $p = trim((string) $v['pixel_id']);
                if ($p !== '' && !preg_match($t === 'meta' ? '/^\d{8,20}$/' : '/^[A-Z0-9]{10,30}$/', $p)) return self::bad("$t.pixel_id", $t === 'meta' ? 'Die Pixel-ID von Meta besteht nur aus Ziffern.' : 'Die Pixel-ID von TikTok besteht aus Großbuchstaben und Ziffern.');
                $c['pixel_id'] = $p;
            }
            if (array_key_exists('test_code', $v)) {
                $tc = trim((string) $v['test_code']);
                if ($tc !== '' && !preg_match('/^[A-Za-z0-9_-]{3,40}$/', $tc)) return self::bad("$t.test_code", 'Der Test-Code besteht aus Buchstaben und Ziffern (z. B. TEST12345).');
                $c['test_code'] = $tc;
            }
            if (array_key_exists('token', $v)) {
                if ($v['token'] === null) unset($c['token_enc']);
                elseif (($tk = trim((string) $v['token'])) !== '') {
                    if (strlen($tk) < 20 || strlen($tk) > 1000 || preg_match('/\s/', $tk)) return self::bad("$t.token", 'Das Token sieht nicht vollständig aus – bitte komplett kopieren.');
                    $c['token_enc'] = TIX_Ad_Accounts::enc($tk);
                }
            }
            if (array_key_exists('measurement_id', $v)) {
                $m = strtoupper(trim((string) $v['measurement_id']));
                if ($m !== '' && !preg_match('/^G-[A-Z0-9]{4,15}$/', $m)) return self::bad('ga4.measurement_id', 'Die Mess-ID beginnt mit G-, z. B. G-AB12CD34EF.');
                $c['measurement_id'] = $m;
            }
            if (array_key_exists('secret', $v)) {
                if ($v['secret'] === null) unset($c['secret_enc']);
                elseif (($sc = trim((string) $v['secret'])) !== '') {
                    if (!preg_match('/^[A-Za-z0-9_-]{10,64}$/', $sc)) return self::bad('ga4.secret', 'Das API-Secret findest du in GA4 unter Datenstreams → Measurement Protocol.');
                    $c['secret_enc'] = TIX_Ad_Accounts::enc($sc);
                }
            }
            if (array_key_exists('conversion_id', $v)) {
                $cid = strtoupper(trim((string) $v['conversion_id']));
                if ($cid !== '' && !preg_match('/^AW-\d{6,15}$/', $cid)) return self::bad('gads.conversion_id', 'Die Conversion-ID beginnt mit AW-, z. B. AW-123456789.');
                $c['conversion_id'] = $cid;
            }
            if (array_key_exists('label', $v)) {
                $l = trim((string) $v['label']);
                if ($l !== '' && !preg_match('/^[A-Za-z0-9_-]{4,40}$/', $l)) return self::bad('gads.label', 'Das Conversion-Label findest du bei der Conversion-Aktion unter „Tag einrichten“.');
                $c['label'] = $l;
            }
            $cur[$t] = $c;
        }
        self::save_raw($org, $cur);
        if (!$org && class_exists('TIX_Site_Config') && method_exists('TIX_Site_Config', 'flush')) TIX_Site_Config::flush();
        return true;
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $p = ['TIX_Ad_Accounts', 'perm'];
        register_rest_route(self::NS, '/marketing/tracking', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $p],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $p],
        ]);
        register_rest_route(self::NS, '/marketing/tracking/test', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_test'], 'permission_callback' => ['TIX_Ad_Accounts', 'perm_module']]);
    }

    public static function rest_get(WP_REST_Request $req) {
        global $wpdb;
        $org = TIX_Ad_Accounts::org($req);
        $log = $wpdb->get_results($wpdb->prepare("SELECT id, target, order_id, event_id, status, code, message, attempts, created FROM " . self::table() . " WHERE organizer_id = %d ORDER BY id DESC LIMIT 50", $org), ARRAY_A);
        return rest_ensure_response(['ok' => true, 'scope' => self::multi() ? ($org ? 'organizer' : 'platform') : 'site', 'module_on' => TIX_Ad_Accounts::module_on($org),
            'targets' => self::public_targets($org), 'browser' => !self::multi(),
            'log' => array_map(function ($r) { $r['id'] = intval($r['id']); $r['order_id'] = intval($r['order_id']); $r['code'] = intval($r['code']); return $r; }, (array) $log)]);
    }

    public static function rest_save(WP_REST_Request $req) {
        $b = $req->get_json_params();
        if (!is_array($b)) return self::bad('targets', 'Ungültige Daten.');
        $r = self::save(TIX_Ad_Accounts::org($req), $b);
        return is_wp_error($r) ? $r : self::rest_get($req);
    }

    /** POST /marketing/tracking/test {target} → Testereignis (Meta/TikTok mit Test-Code, GA4 über den Prüf-Endpunkt) */
    public static function rest_test(WP_REST_Request $req) {
        $org = TIX_Ad_Accounts::org($req);
        $t = (string) $req->get_param('target');
        $c = self::target($org, $t);
        if (!$c || !in_array($t, ['meta', 'tiktok', 'ga4'], true)) return self::bad('target', 'Für dieses Ziel gibt es kein Testereignis.');
        $ev = ['event_id' => 'tix_test_' . wp_generate_password(10, false), 'value' => 1.0, 'currency' => 'EUR', 'num' => 1, 'contents' => [['id' => 'test', 'qty' => 1, 'price' => 1.0]],
            'user' => ['em' => hash('sha256', 'test@example.com')], 'ip' => '', 'ua' => 'evendis-test', 'fbp' => '', 'fbc' => '', 'ttclid' => '', 'ttp' => '', 'ga_cid' => '1234567890.1234567890', 'url' => home_url('/'), 'time' => time()];
        $res = self::deliver($t, $c, $ev, true);
        self::log($org, $t, 0, $ev['event_id'], $res['ok'] ? 'test' : 'failed', $res['code'], $res['message']);
        return rest_ensure_response(['ok' => $res['ok'], 'code' => $res['code'], 'message' => $res['message']]);
    }

    // ═══════════════ Kauf → Ziele ═══════════════

    /** Bezahlte Bestellung: je Bereich und Ziel einen Auftrag planen */
    public static function on_paid($order_id) {
        $order_id = intval($order_id);
        if (!$order_id) return;
        foreach (self::scopes($order_id) as $org) {
            if (!TIX_Ad_Accounts::module_on($org)) continue;
            foreach (['meta', 'tiktok', 'ga4'] as $t) {
                $c = self::target($org, $t);
                if (!$c || empty($c['enabled'])) continue;
                $ready = $t === 'ga4' ? (($c['measurement_id'] ?? '') !== '' && $c['secret'] !== '') : (($c['pixel_id'] ?? '') !== '' && $c['token'] !== '');
                if (!$ready) continue;
                if (self::already($order_id, $org, $t)) continue;
                wp_schedule_single_event(time(), self::CRON, [$order_id, $org, $t, 1]);
            }
        }
    }

    /** Bereiche einer Bestellung: 0 (Seite/Plattform) + Veranstalter der Events (nur evendis) */
    public static function scopes($order_id) {
        global $wpdb;
        $out = [0];
        if (self::multi() && class_exists('TIX_Order')) {
            foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT event_id FROM " . TIX_Order::items_table_name() . " WHERE order_id = %d AND event_id > 0", $order_id)) as $eid) {
                $o = intval(get_post_meta(intval($eid), '_tix_organizer_id', true));
                if ($o && !in_array($o, $out, true)) $out[] = $o;
            }
        }
        return $out;
    }

    private static function already($order_id, $org, $t) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE order_id = %d AND organizer_id = %d AND target = %s AND status IN ('sent','skipped') LIMIT 1", $order_id, $org, $t));
    }

    /** Ereignis aus der Bestellung (gehasht, ohne Klartext) oder null ohne Einwilligung */
    public static function event_for($order_id, $t) {
        global $wpdb;
        $o = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TIX_Order::table_name() . " WHERE id = %d", $order_id), ARRAY_A);
        if (!$o || !in_array($o['status'], ['completed', 'processing'], true)) return null;
        $a = TIX_Order_Attribution::get($order_id) ?: [];
        $ids = json_decode((string) ($a['click_ids'] ?? ''), true) ?: [];
        $an = json_decode((string) ($a['analytics_ids'] ?? ''), true) ?: [];
        if ($t === 'ga4') { if ((string) ($a['consent_analytics'] ?? '') !== '1' || empty($an['ga_cid'])) return false; }
        elseif ((string) ($a['consent_marketing'] ?? '') !== '1') return false;
        $h = function ($v) { $v = strtolower(trim((string) $v)); return $v === '' ? '' : hash('sha256', $v); };
        $phone = preg_replace('/\D/', '', (string) ($o['billing_phone'] ?? ''));
        if ($phone !== '' && strpos($phone, '0') === 0) $phone = '49' . ltrim($phone, '0');
        $items = (array) $wpdb->get_results($wpdb->prepare("SELECT event_id, quantity, total FROM " . TIX_Order::items_table_name() . " WHERE order_id = %d", $order_id), ARRAY_A);
        $contents = []; $num = 0;
        foreach ($items as $i) { $q = max(1, intval($i['quantity'])); $num += $q; $contents[] = ['id' => (string) intval($i['event_id']), 'qty' => $q, 'price' => round(floatval($i['total']) / $q, 2)]; }
        $ev = (int) ($items[0]['event_id'] ?? 0);
        return [
            'event_id' => self::event_id($o), 'value' => round(floatval($o['total']), 2), 'currency' => 'EUR', 'num' => $num, 'contents' => $contents,
            'user' => array_filter(['em' => $h($o['billing_email'] ?? ''), 'ph' => $phone !== '' ? hash('sha256', $phone) : '', 'fn' => $h($o['billing_first_name'] ?? ''), 'ln' => $h($o['billing_last_name'] ?? ''),
                'ct' => $h(preg_replace('/\s+/', '', (string) ($o['billing_city'] ?? ''))), 'zp' => $h($o['billing_postcode'] ?? ''), 'country' => $h($o['billing_country'] ?? '')]),
            'ip' => (string) ($a['client_ip'] ?? ''), 'ua' => (string) ($a['client_ua'] ?? ''), 'fbp' => (string) ($a['fbp'] ?? ''), 'fbc' => (string) ($a['fbc'] ?? ''),
            'ttclid' => (string) ($ids['ttclid'] ?? ''), 'ttp' => (string) ($ids['ttp'] ?? ''), 'ga_cid' => (string) ($an['ga_cid'] ?? ''),
            'url' => $ev ? (string) get_permalink($ev) : home_url('/'), 'time' => strtotime((string) $o['date_modified'] . ' UTC') ?: time(),
        ];
    }

    /** Dedup-ID, gleich im Browser-Pixel des eigenen Shops (Bestellnummer, kein Bestellschlüssel) */
    public static function event_id(array $o) {
        $n = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($o['order_number'] ?? ''));
        return 'tix_' . ($n !== '' ? $n : intval($o['id']));
    }

    /** Cron: senden, bei Fehler bis zu 3 Versuche (5 min, 30 min) */
    public static function send($order_id, $org, $t, $attempt = 1) {
        if (self::already($order_id, $org, $t) || !TIX_Ad_Accounts::module_on($org)) return;
        $c = self::target($org, $t);
        if (!$c || empty($c['enabled'])) return;
        $ev = self::event_for($order_id, $t);
        if ($ev === null) return;
        // ohne Einwilligung: nur Website-Bestellungen protokollieren (App/Abendkasse haben keine Banner-Einwilligung)
        if ($ev === false && (TIX_Order_Attribution::get($order_id)['channel'] ?? '') !== 'web') return;
        if ($ev === false) { self::log($org, $t, $order_id, '', 'skipped', 0, $t === 'ga4' ? 'Ohne Einwilligung „Statistik“ bzw. ohne GA4-Kennung' : 'Ohne Einwilligung „Marketing“'); return; }
        $res = self::deliver($t, $c, $ev, false);
        if ($res['ok']) { self::log($org, $t, $order_id, $ev['event_id'], 'sent', $res['code'], $res['message'], $attempt); return; }
        $last = $attempt >= 3 || ($res['code'] >= 400 && $res['code'] < 500 && $res['code'] !== 429);
        self::log($org, $t, $order_id, $ev['event_id'], $last ? 'failed' : 'retry', $res['code'], $res['message'], $attempt);
        if (!$last) wp_schedule_single_event(time() + ($attempt === 1 ? 300 : 1800), self::CRON, [$order_id, $org, $t, $attempt + 1]);
    }

    /** An ein Ziel senden → {ok, code, message} */
    public static function deliver($t, array $c, array $ev, $test) {
        if ($t === 'meta') {
            $ud = array_filter(['em' => !empty($ev['user']['em']) ? [$ev['user']['em']] : null, 'ph' => !empty($ev['user']['ph']) ? [$ev['user']['ph']] : null,
                'fn' => !empty($ev['user']['fn']) ? [$ev['user']['fn']] : null, 'ln' => !empty($ev['user']['ln']) ? [$ev['user']['ln']] : null,
                'ct' => !empty($ev['user']['ct']) ? [$ev['user']['ct']] : null, 'zp' => !empty($ev['user']['zp']) ? [$ev['user']['zp']] : null,
                'country' => !empty($ev['user']['country']) ? [$ev['user']['country']] : null, 'client_ip_address' => $ev['ip'] ?: null, 'client_user_agent' => $ev['ua'] ?: null,
                'fbp' => $ev['fbp'] ?: null, 'fbc' => $ev['fbc'] ?: null]);
            $body = ['data' => [['event_name' => 'Purchase', 'event_time' => intval($ev['time']), 'event_id' => $ev['event_id'], 'action_source' => 'website', 'event_source_url' => $ev['url'], 'user_data' => (object) $ud,
                'custom_data' => ['currency' => $ev['currency'], 'value' => $ev['value'], 'content_type' => 'product', 'num_items' => $ev['num'], 'contents' => array_map(function ($x) { return ['id' => $x['id'], 'quantity' => $x['qty'], 'item_price' => $x['price']]; }, $ev['contents'])]]]];
            if (($c['test_code'] ?? '') !== '') $body['test_event_code'] = $c['test_code'];
            return self::post('https://graph.facebook.com/' . self::GRAPH . '/' . rawurlencode($c['pixel_id']) . '/events?access_token=' . rawurlencode($c['token']), $body);
        }
        if ($t === 'tiktok') {
            $user = array_filter(['email' => $ev['user']['em'] ?? '', 'phone' => $ev['user']['ph'] ?? '', 'ttclid' => $ev['ttclid'], 'ttp' => $ev['ttp'], 'ip' => $ev['ip'], 'user_agent' => $ev['ua']]);
            $body = ['event_source' => 'web', 'event_source_id' => $c['pixel_id'], 'data' => [['event' => 'CompletePayment', 'event_time' => intval($ev['time']), 'event_id' => $ev['event_id'], 'user' => (object) $user,
                'properties' => ['currency' => $ev['currency'], 'value' => $ev['value'], 'content_type' => 'product', 'contents' => array_map(function ($x) { return ['content_id' => $x['id'], 'quantity' => $x['qty'], 'price' => $x['price']]; }, $ev['contents'])],
                'page' => ['url' => $ev['url']]]]];
            if (($c['test_code'] ?? '') !== '') $body['test_event_code'] = $c['test_code'];
            return self::post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $body, ['Access-Token' => $c['token']], 'tiktok');
        }
        if ($t === 'ga4') {
            $body = ['client_id' => $ev['ga_cid'], 'events' => [['name' => 'purchase', 'params' => ['transaction_id' => $ev['event_id'], 'value' => $ev['value'], 'currency' => $ev['currency'],
                'items' => array_map(function ($x) { return ['item_id' => $x['id'], 'quantity' => $x['qty'], 'price' => $x['price']]; }, $ev['contents'])]]]];
            $url = 'https://www.google-analytics.com/' . ($test ? 'debug/' : '') . 'mp/collect?' . http_build_query(['measurement_id' => $c['measurement_id'], 'api_secret' => $c['secret']]);
            return self::post($url, $body, [], $test ? 'ga4_debug' : 'ga4');
        }
        return ['ok' => false, 'code' => 0, 'message' => 'Unbekanntes Ziel'];
    }

    private static function post($url, array $body, array $headers = [], $kind = 'meta') {
        $r = wp_remote_post($url, ['timeout' => 15, 'headers' => $headers + ['Content-Type' => 'application/json'], 'body' => wp_json_encode($body)]);
        if (is_wp_error($r)) return ['ok' => false, 'code' => 0, 'message' => 'Nicht erreichbar'];
        $code = intval(wp_remote_retrieve_response_code($r));
        $j = json_decode((string) wp_remote_retrieve_body($r), true);
        $j = is_array($j) ? $j : [];
        if ($kind === 'tiktok') { $ok = $code === 200 && intval($j['code'] ?? -1) === 0; return ['ok' => $ok, 'code' => $ok ? 200 : ($code === 200 ? 400 : $code), 'message' => $ok ? 'Angenommen' : mb_substr((string) ($j['message'] ?? 'Fehler'), 0, 200)]; }
        if ($kind === 'ga4_debug') { $msgs = (array) ($j['validationMessages'] ?? []); return ['ok' => $code === 200 && !$msgs, 'code' => $code, 'message' => $msgs ? mb_substr((string) ($msgs[0]['description'] ?? 'Ungültig'), 0, 200) : 'Gültig (Prüf-Endpunkt)']; }
        if ($kind === 'ga4') return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'message' => $code < 300 ? 'Angenommen' : 'Fehler ' . $code];
        $ok = $code === 200;
        return ['ok' => $ok, 'code' => $code, 'message' => $ok ? 'Angenommen' . (isset($j['events_received']) ? ' (' . intval($j['events_received']) . ')' : '') : mb_substr((string) ($j['error']['message'] ?? "Fehler $code"), 0, 200)];
    }

    /** Für TIX_Matomo: Eintrag ins gemeinsame Protokoll */
    public static function log_public($org, $t, $order_id, $event_id, $status, $code, $msg) { self::log($org, $t, $order_id, $event_id, $status, $code, $msg); }

    private static function log($org, $t, $order_id, $event_id, $status, $code, $msg, $attempt = 1) {
        global $wpdb;
        $wpdb->insert(self::table(), ['organizer_id' => intval($org), 'target' => $t, 'order_id' => intval($order_id), 'event_id' => mb_substr((string) $event_id, 0, 64), 'status' => $status,
            'code' => intval($code), 'message' => mb_substr((string) $msg, 0, 255), 'attempts' => intval($attempt), 'created' => current_time('mysql', true)]);
        if (mt_rand(1, 20) === 1) {
            $keep = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table() . " WHERE organizer_id = %d ORDER BY id DESC LIMIT 1 OFFSET %d", intval($org), self::KEEP_LOG));
            if ($keep) $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " WHERE organizer_id = %d AND id <= %d", intval($org), intval($keep)));
        }
    }
}

TIX_Conversions::init();
