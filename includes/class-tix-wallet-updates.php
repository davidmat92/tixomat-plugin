<?php
/**
 * TIX_Wallet_Updates – gespeicherte Wallet-Tickets aktualisieren sich selbst (1.38.542, Vertrag uebergaben/vertrag-wallet.md §1a).
 *
 * Apple PassKit Web Service (webServiceURL im Pass = <Kunden-Adresse>/wp-json/tixomat/v1/passkit, nur mit https):
 *   POST   /passkit/v1/devices/{device}/registrations/{passType}/{serial}   {pushToken}  → 201 neu, 200 bekannt
 *   GET    /passkit/v1/devices/{device}/registrations/{passType}?passesUpdatedSince=  → {serialNumbers, lastUpdated} | 204
 *   DELETE /passkit/v1/devices/{device}/registrations/{passType}/{serial}              → 200
 *   GET    /passkit/v1/passes/{passType}/{serial}  (If-Modified-Since → 304)          → .pkpass
 *   POST   /passkit/v1/log
 *   Pass-Routen mit Header `Authorization: ApplePass <authenticationToken>` (HMAC je Seriennummer), sonst 401.
 * Push: APNs (Produktion) mit dem Pass-Zertifikat, Thema = Pass Type ID, leerer Inhalt {}; ungültige Push-Tokens werden gelöscht.
 * Google: Objekt- und Klassen-Update über die Wallet-REST-API (Dienstkonto aus den Wallet-Einstellungen, Scope wallet_object.issuer).
 *
 * Auslöser: Ticket storniert, eingecheckt, umgeschrieben; Event abgesagt, verschoben, Termin/Einlass/Ort/Titel geändert.
 * Nur Tickets, die schon in einer Wallet liegen (`_tix_wallet_apple` / `_tix_wallet_google`), und nur bei geändertem
 * Fingerabdruck (`_tix_pass_fp`); Stand `_tix_pass_updated` (Unix-Zeit). Läuft im Hintergrund (WP-Cron, 15 s gebündelt).
 * Geräte-Anmeldungen: Tabelle {prefix}tix_passkit_regs, gelöscht mit dem Ticket bzw. 30 Tage nach dem Event.
 */
if (!defined('ABSPATH')) exit;

class TIX_Wallet_Updates {

    const NS        = 'tixomat/v1';
    const DB_VER    = '1';
    const CRON      = 'tix_wallet_sync';
    const CLEANUP   = 'tix_wallet_cleanup';
    const APNS_HOST = 'api.push.apple.com';
    const TICKET_KEYS = ['_tix_ticket_status', '_tix_ticket_checked_in', '_tix_ticket_owner_name', '_tix_ticket_cat_name'];
    const EVENT_KEYS  = ['_tix_date_start', '_tix_time_start', '_tix_time_admission', '_tix_event_status', '_tix_status', '_tix_location_id'];

    private static $queue = ['ticket' => [], 'event' => []];
    private static $patched_classes = [];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $h) add_action($h, [__CLASS__, 'on_meta'], 10, 3);
        add_action('post_updated', [__CLASS__, 'on_post_updated'], 10, 3);
        add_action('before_delete_post', [__CLASS__, 'on_delete']);
        add_action('shutdown', [__CLASS__, 'flush_queue']);
        add_action(self::CRON, [__CLASS__, 'run'], 10, 2);
        add_action(self::CLEANUP, [__CLASS__, 'cleanup']);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_passkit_regs';
    }

    public static function maybe_install() {
        if (get_option('tix_passkit_db') !== self::DB_VER) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE " . self::table() . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                device_id varchar(128) NOT NULL,
                push_token varchar(255) NOT NULL,
                serial varchar(100) NOT NULL,
                ticket_id bigint(20) unsigned NOT NULL,
                created datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dev_serial (device_id, serial),
                KEY ticket_id (ticket_id),
                KEY push_token (push_token(64))
            ) " . $wpdb->get_charset_collate() . ";");
            update_option('tix_passkit_db', self::DB_VER, false);
        }
        if (!wp_next_scheduled(self::CLEANUP)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP);
    }

    // ───────────────────────── Ausgabe merken ─────────────────────────

    /** Ticket liegt (jetzt) in einer Wallet: Plattform merken, Fingerabdruck und Stand setzen. */
    public static function mark_issued(array $t, $platform) {
        $id = intval($t['id']);
        update_post_meta($id, $platform === 'google' ? '_tix_wallet_google' : '_tix_wallet_apple', '1');
        update_post_meta($id, '_tix_pass_fp', TIX_Wallet::fingerprint($t));
        if (!get_post_meta($id, '_tix_pass_updated', true)) update_post_meta($id, '_tix_pass_updated', time());
    }

    private static function issued($ticket_id) {
        return get_post_meta($ticket_id, '_tix_wallet_apple', true) === '1' || get_post_meta($ticket_id, '_tix_wallet_google', true) === '1';
    }

    // ───────────────────────── Auslöser ─────────────────────────

    public static function on_meta($meta_id, $object_id, $meta_key) {
        if (in_array($meta_key, self::TICKET_KEYS, true)) {
            if (get_post_type($object_id) === 'tix_ticket' && self::issued($object_id)) self::$queue['ticket'][intval($object_id)] = true;
        } elseif (in_array($meta_key, self::EVENT_KEYS, true)) {
            if (get_post_type($object_id) === 'event') self::$queue['event'][intval($object_id)] = true;
        }
    }

    public static function on_post_updated($post_id, $after, $before) {
        if ($after->post_type === 'event' && $after->post_title !== $before->post_title) self::$queue['event'][intval($post_id)] = true;
    }

    /** Am Ende der Anfrage gebündelt in den Hintergrund (15 s), damit Speichern und Check-in nicht warten. */
    public static function flush_queue() {
        if (!self::$queue['ticket'] && !self::$queue['event']) return;
        if (!class_exists('TIX_Wallet') || !TIX_Wallet::is_master_enabled()) { self::$queue = ['ticket' => [], 'event' => []]; return; }
        foreach (self::$queue as $kind => $ids) {
            foreach (array_keys($ids) as $id) {
                $args = [$kind, intval($id)];
                if ($kind === 'event' && !self::event_ticket_ids($id)) continue;
                if (!wp_next_scheduled(self::CRON, $args)) wp_schedule_single_event(time() + 15, self::CRON, $args);
            }
        }
        self::$queue = ['ticket' => [], 'event' => []];
    }

    public static function run($kind, $id) {
        if ($kind === 'event') {
            foreach (self::event_ticket_ids($id) as $tid) self::sync_ticket($tid);
        } else {
            self::sync_ticket($id);
        }
    }

    /** Tickets eines Events, die in einer Wallet liegen. */
    public static function event_ticket_ids($event_id) {
        return get_posts(['post_type' => 'tix_ticket', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => ['relation' => 'AND', ['key' => '_tix_ticket_event_id', 'value' => intval($event_id)],
                ['relation' => 'OR', ['key' => '_tix_wallet_apple', 'value' => '1'], ['key' => '_tix_wallet_google', 'value' => '1']]]]);
    }

    /** Ticket prüfen: hat sich der sichtbare Inhalt geändert → Stand setzen, Apple pushen, Google aktualisieren. */
    public static function sync_ticket($ticket_id) {
        $ticket_id = intval($ticket_id);
        if (!self::issued($ticket_id)) return ['changed' => false];
        $t = TIX_Wallet::load_ticket($ticket_id);
        if (!$t) return ['changed' => false];
        $fp = TIX_Wallet::fingerprint($t);
        if ($fp === get_post_meta($ticket_id, '_tix_pass_fp', true)) return ['changed' => false];
        update_post_meta($ticket_id, '_tix_pass_fp', $fp);
        update_post_meta($ticket_id, '_tix_pass_updated', time());
        $out = ['changed' => true, 'apple' => null, 'google' => null];
        if (get_post_meta($ticket_id, '_tix_wallet_apple', true) === '1' && TIX_Wallet::is_apple_ready()) {
            global $wpdb;
            $tokens = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT push_token FROM " . self::table() . " WHERE ticket_id = %d", $ticket_id));
            $out['apple'] = $tokens ? self::apns_push($tokens) : 0;
        }
        if (get_post_meta($ticket_id, '_tix_wallet_google', true) === '1' && TIX_Wallet::is_google_ready()) {
            $out['google'] = self::google_update($t);
        }
        return $out;
    }

    // ───────────────────────── Apple: Web Service ─────────────────────────

    public static function register_routes() {
        $dev = '/passkit/v1/devices/(?P<device>[A-Za-z0-9]{1,128})/registrations/(?P<type>[A-Za-z0-9.\-]{1,128})';
        register_rest_route(self::NS, $dev . '/(?P<serial>[A-Za-z0-9_\-]{1,100})', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_register'], 'permission_callback' => '__return_true'],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_unregister'], 'permission_callback' => '__return_true'],
        ]);
        register_rest_route(self::NS, $dev, ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_serials'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/passkit/v1/passes/(?P<type>[A-Za-z0-9.\-]{1,128})/(?P<serial>[A-Za-z0-9_\-]{1,100})', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_pass'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/passkit/v1/log', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_log'], 'permission_callback' => '__return_true']);
    }

    private static function plain($status) {
        $r = new WP_REST_Response(null, $status);
        return $r;
    }

    /** Pass Type ID passt, Seriennummer gehört zu dieser Seite und das Token stimmt → Ticket-ID, sonst WP_REST_Response 401/404. */
    private static function auth(WP_REST_Request $req) {
        if (!TIX_Wallet::is_apple_ready() || $req['type'] !== tix_get_settings('wallet_apple_pass_type_id')) return self::plain(404);
        $tid = TIX_Wallet::ticket_from_serial($req['serial']);
        if (!$tid) return self::plain(404);
        $h = (string) $req->get_header('authorization');
        if (stripos($h, 'ApplePass ') !== 0 || !hash_equals(TIX_Wallet::auth_token($req['serial']), trim(substr($h, 10)))) return self::plain(401);
        return $tid;
    }

    public static function rest_register(WP_REST_Request $req) {
        $tid = self::auth($req);
        if (!is_int($tid)) return $tid;
        $p = $req->get_json_params();
        $push = is_array($p) ? preg_replace('/[^A-Fa-f0-9]/', '', (string) ($p['pushToken'] ?? '')) : '';
        if ($push === '' || strlen($push) > 255) return self::plain(400);
        global $wpdb;
        $t = self::table();
        $have = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE device_id = %s AND serial = %s", $req['device'], $req['serial']));
        if ($have) {
            $wpdb->update($t, ['push_token' => $push], ['id' => $have]);
            return self::plain(200);
        }
        $wpdb->insert($t, ['device_id' => $req['device'], 'push_token' => $push, 'serial' => $req['serial'], 'ticket_id' => $tid, 'created' => current_time('mysql', true)]);
        update_post_meta($tid, '_tix_wallet_apple', '1');
        return self::plain(201);
    }

    public static function rest_unregister(WP_REST_Request $req) {
        $tid = self::auth($req);
        if (!is_int($tid)) return $tid;
        global $wpdb;
        $wpdb->delete(self::table(), ['device_id' => $req['device'], 'serial' => $req['serial']]);
        return self::plain(200);
    }

    public static function rest_serials(WP_REST_Request $req) {
        if (!TIX_Wallet::is_apple_ready() || $req['type'] !== tix_get_settings('wallet_apple_pass_type_id')) return self::plain(404);
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT serial, ticket_id FROM " . self::table() . " WHERE device_id = %s", $req['device']));
        if (!$rows) return self::plain(204);
        $since = (string) $req->get_param('passesUpdatedSince');
        $since = ctype_digit($since) ? intval($since) : 0;
        $serials = []; $last = 0;
        foreach ($rows as $r) {
            $u = intval(get_post_meta($r->ticket_id, '_tix_pass_updated', true));
            if ($u > $since) $serials[] = $r->serial;
            $last = max($last, $u);
        }
        if (!$serials) return self::plain(204);
        return new WP_REST_Response(['serialNumbers' => array_values(array_unique($serials)), 'lastUpdated' => (string) $last], 200);
    }

    public static function rest_pass(WP_REST_Request $req) {
        $tid = self::auth($req);
        if (!is_int($tid)) return $tid;
        $updated = intval(get_post_meta($tid, '_tix_pass_updated', true)) ?: time();
        $ims = strtotime((string) $req->get_header('if_modified_since'));
        if ($ims && $ims >= $updated) return self::plain(304);
        $t = TIX_Wallet::load_ticket($tid);
        $pkpass = $t ? TIX_Wallet::generate_apple_pass($t) : null;
        if (!$pkpass) return self::plain(500);
        if (empty($GLOBALS['tix_wallet_test'])) {
            status_header(200);
            header('Content-Type: application/vnd.apple.pkpass');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $updated) . ' GMT');
            header('Content-Length: ' . strlen($pkpass));
            echo $pkpass;
            exit;
        }
        $r = new WP_REST_Response(['bytes' => strlen($pkpass)], 200);
        $r->header('Last-Modified', gmdate('D, d M Y H:i:s', $updated) . ' GMT');
        return $r;
    }

    public static function rest_log(WP_REST_Request $req) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $p = $req->get_json_params();
            foreach ((array) ($p['logs'] ?? []) as $l) error_log('[tix-passkit] ' . mb_substr((string) $l, 0, 300));
        }
        return self::plain(200);
    }

    // ───────────────────────── Apple: Push ─────────────────────────

    /** Leerer Push an Wallet (Produktion, Pass-Zertifikat). Gibt die Zahl der angenommenen Pushes zurück. */
    public static function apns_push(array $tokens) {
        $pre = apply_filters('tix_wallet_apns_send', null, $tokens);
        if ($pre !== null) return $pre;
        if (!function_exists('curl_init') || !defined('CURL_HTTP_VERSION_2_0')) return 0;
        $s = tix_get_settings();
        $certs = [];
        if (!openssl_pkcs12_read((string) @file_get_contents($s['wallet_apple_cert_path']), $certs, (string) $s['wallet_apple_cert_password'])) return 0;
        if (!function_exists('wp_tempnam')) require_once ABSPATH . 'wp-admin/includes/file.php';
        $pem = wp_tempnam('tix-pk-push');
        $pem_out = '';
        openssl_pkey_export($certs['pkey'], $pem_out);
        file_put_contents($pem, $certs['cert'] . "\n" . $pem_out);
        @chmod($pem, 0600);
        $ok = 0;
        foreach (array_unique($tokens) as $token) {
            $ch = curl_init('https://' . self::APNS_HOST . '/3/device/' . $token);
            curl_setopt_array($ch, [
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '{}',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSLCERT        => $pem,
                CURLOPT_HTTPHEADER     => ['apns-topic: ' . $s['wallet_apple_pass_type_id'], 'Content-Type: application/json'],
            ]);
            $res  = curl_exec($ch);
            $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            curl_close($ch);
            if ($code === 200) { $ok++; continue; }
            $reason = is_string($res) ? (string) (json_decode($res, true)['reason'] ?? '') : '';
            if ($code === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
                global $wpdb;
                $wpdb->delete(self::table(), ['push_token' => $token]);
            }
            if (defined('WP_DEBUG') && WP_DEBUG) error_log('[tix-passkit] APNs ' . $code . ' ' . $reason);
        }
        @unlink($pem);
        return $ok;
    }

    // ───────────────────────── Google: Objekt-Update ─────────────────────────

    private static function google_token() {
        $tok = get_transient('tix_gwallet_token');
        if (is_string($tok) && $tok !== '') return $tok;
        $s = tix_get_settings();
        $now = time();
        $jwt = TIX_Wallet::jwt_sign(['iss' => $s['wallet_google_service_email'], 'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600], (string) $s['wallet_google_service_key']);
        if (!$jwt) return '';
        $r = self::google_http('POST', 'https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt], false);
        $tok = (string) ($r['body']['access_token'] ?? '');
        if ($tok !== '') set_transient('tix_gwallet_token', $tok, 50 * MINUTE_IN_SECONDS);
        return $tok;
    }

    /** HTTP an Google (Filter `tix_wallet_google_request` für Tests): ['code' => int, 'body' => array]. */
    private static function google_http($method, $url, $body, $json = true, $token = '') {
        $pre = apply_filters('tix_wallet_google_request', null, $method, $url, $body);
        if ($pre !== null) return $pre;
        $args = ['method' => $method, 'timeout' => 10, 'headers' => []];
        if ($json) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode($body); } else { $args['body'] = $body; }
        if ($token !== '') $args['headers']['Authorization'] = 'Bearer ' . $token;
        $r = wp_remote_request($url, $args);
        if (is_wp_error($r)) return ['code' => 0, 'body' => []];
        return ['code' => intval(wp_remote_retrieve_response_code($r)), 'body' => (array) json_decode((string) wp_remote_retrieve_body($r), true)];
    }

    /** Klasse (einmal je Lauf) und Objekt aktualisieren; 404 = (noch) nicht gespeichert, wird ignoriert. */
    public static function google_update(array $t) {
        $token = self::google_token();
        if ($token === '') return false;
        $base = 'https://walletobjects.googleapis.com/walletobjects/v1/';
        $cid = TIX_Wallet::google_class_id($t);
        if (!isset(self::$patched_classes[$cid])) {
            self::$patched_classes[$cid] = true;
            self::google_http('PATCH', $base . 'eventTicketClass/' . rawurlencode($cid), TIX_Wallet::google_class($t), true, $token);
        }
        $r = self::google_http('PATCH', $base . 'eventTicketObject/' . rawurlencode(TIX_Wallet::google_object_id($t)), TIX_Wallet::google_object($t), true, $token);
        return in_array($r['code'], [200, 404], true);
    }

    // ───────────────────────── Aufräumen ─────────────────────────

    public static function on_delete($post_id) {
        if (get_post_type($post_id) !== 'tix_ticket') return;
        global $wpdb;
        $wpdb->delete(self::table(), ['ticket_id' => intval($post_id)]);
    }

    /** Täglich: Anmeldungen zu gelöschten Tickets und 30 Tage nach dem Event entfernen. */
    public static function cleanup() {
        global $wpdb;
        $t = self::table();
        $limit = gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS);
        $wpdb->query("DELETE r FROM $t r LEFT JOIN {$wpdb->posts} p ON p.ID = r.ticket_id WHERE p.ID IS NULL");
        $wpdb->query($wpdb->prepare("DELETE r FROM $t r
            JOIN {$wpdb->postmeta} e ON e.post_id = r.ticket_id AND e.meta_key = '_tix_ticket_event_id'
            JOIN {$wpdb->postmeta} d ON d.post_id = e.meta_value AND d.meta_key = '_tix_date_start'
            WHERE d.meta_value <> '' AND d.meta_value < %s", $limit));
    }
}
