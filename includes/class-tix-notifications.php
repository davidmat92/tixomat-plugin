<?php
/**
 * App-Benachrichtigungen (Glocke + Push).
 *
 * - In-App-Feed: Broadcasts (Veranstalter-Nachrichten, Event-Erinnerungen) für
 *   alle + nutzerbezogene Hinweise (z. B. „Deine Tickets liegen in der App“).
 * - Der Lese-Status wird in der App lokal gehalten (kein Server-State nötig),
 *   sodass der Feed auch für Gäste ohne Konto funktioniert.
 * - Automatisch: `tix_order_completed` → Ticket-Hinweis an den Käufer; stündlicher
 *   Cron → Erinnerung am Event-Tag (einmal je Event).
 * - Veranstalter schreiben Nachrichten über `POST /notifications/broadcast`.
 * - Echte Push-Zustellung (APNs) ist optional: nur aktiv, sobald in den Optionen
 *   ein Apple-Push-Schlüssel (.p8) hinterlegt ist – sonst bleibt alles beim
 *   In-App-Feed (kein Fehler, keine Zustellung).
 *
 * @package Tixomat
 */

if (!defined('ABSPATH')) exit;

class TIX_Notifications {
    const NS            = 'tixomat/v1';
    const OPT_FEED      = '_tix_app_notifications';      // Broadcasts (Liste)
    const OPT_TOKENS    = '_tix_push_tokens';            // Geräte-Token → Meta
    const OPT_REMINDED  = '_tix_app_notif_reminded';     // bereits erinnerte Event-IDs
    const META_USER     = '_tix_app_notif_user';         // nutzerbezogene Hinweise
    const MAX_FEED      = 50;
    const MAX_USER      = 25;
    const CRON_HOOK     = 'tix_app_notif_cron';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Automatik
        add_action('tix_order_completed', [__CLASS__, 'on_order_completed'], 30, 1);
        add_action(self::CRON_HOOK, [__CLASS__, 'cron_reminders']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    // ── Einstellungen ─────────────────────────────────────────────

    /** Stunden vor Event-Beginn, ab denen am Event-Tag erinnert wird. */
    public static function reminder_hours() {
        return max(1, intval(get_option('_tix_app_notif_reminder_hours', 6)));
    }

    // ── Feed-Speicher ─────────────────────────────────────────────

    private static function broadcasts() {
        $list = get_option(self::OPT_FEED, []);
        return is_array($list) ? $list : [];
    }

    private static function save_broadcasts($list) {
        // Neueste zuerst, deckeln.
        usort($list, fn($a, $b) => intval($b['created'] ?? 0) - intval($a['created'] ?? 0));
        if (count($list) > self::MAX_FEED) $list = array_slice($list, 0, self::MAX_FEED);
        update_option(self::OPT_FEED, array_values($list), false);
    }

    /**
     * Legt eine Broadcast-Nachricht an (für alle sichtbar) und stößt – falls
     * konfiguriert – die Push-Zustellung an alle Geräte an.
     */
    public static function add_broadcast($title, $body, $args = []) {
        $item = [
            'id'       => 'b_' . wp_generate_uuid4(),
            'type'     => sanitize_key($args['type'] ?? 'message'),
            'title'    => self::clean($title),
            'body'     => self::clean($body),
            'event_id' => intval($args['event_id'] ?? 0),
            'action'   => (string) ($args['action'] ?? ''),
            'created'  => time(),
        ];
        $list = self::broadcasts();
        $list[] = $item;
        self::save_broadcasts($list);

        if (empty($args['no_push'])) {
            self::push_all($item['title'], $item['body'], [
                'notif_id' => $item['id'],
                'event_id' => (string) $item['event_id'],
                'action'   => $item['action'],
            ]);
        }
        return $item;
    }

    private static function user_items($user_id) {
        $list = get_user_meta($user_id, self::META_USER, true);
        return is_array($list) ? $list : [];
    }

    /** Nutzerbezogener Hinweis (nur dieser Nutzer sieht ihn). */
    public static function add_user_item($user_id, $title, $body, $args = []) {
        $user_id = intval($user_id);
        if ($user_id <= 0) return null;
        $item = [
            'id'       => 'u_' . wp_generate_uuid4(),
            'type'     => sanitize_key($args['type'] ?? 'message'),
            'title'    => self::clean($title),
            'body'     => self::clean($body),
            'event_id' => intval($args['event_id'] ?? 0),
            'action'   => (string) ($args['action'] ?? ''),
            'created'  => time(),
        ];
        $list = self::user_items($user_id);
        $list[] = $item;
        usort($list, fn($a, $b) => intval($b['created'] ?? 0) - intval($a['created'] ?? 0));
        if (count($list) > self::MAX_USER) $list = array_slice($list, 0, self::MAX_USER);
        update_user_meta($user_id, self::META_USER, array_values($list));

        if (empty($args['no_push'])) {
            self::push_user($user_id, $item['title'], $item['body'], [
                'notif_id' => $item['id'],
                'event_id' => (string) $item['event_id'],
                'action'   => $item['action'],
            ]);
        }
        return $item;
    }

    private static function clean($s) {
        return trim(wp_strip_all_tags((string) $s));
    }

    // ── REST ──────────────────────────────────────────────────────

    public static function register_routes() {
        register_rest_route(self::NS, '/notifications', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_feed'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/notifications/broadcast', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_broadcast'],
            'permission_callback' => ['TIX_REST_API', 'check_organizer'],
        ]);
        register_rest_route(self::NS, '/push/register', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_register_device'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/push/unregister', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_unregister_device'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** GET /notifications – Broadcasts (+ nutzerbezogene Hinweise, falls angemeldet). */
    public static function rest_feed(WP_REST_Request $req) {
        $items = self::broadcasts();
        $uid = get_current_user_id();
        if ($uid) {
            $items = array_merge($items, self::user_items($uid));
        }
        usort($items, fn($a, $b) => intval($b['created'] ?? 0) - intval($a['created'] ?? 0));
        if (count($items) > self::MAX_FEED) $items = array_slice($items, 0, self::MAX_FEED);

        $out = array_map(function ($it) {
            return [
                'id'       => (string) ($it['id'] ?? ''),
                'type'     => (string) ($it['type'] ?? 'message'),
                'title'    => (string) ($it['title'] ?? ''),
                'body'     => (string) ($it['body'] ?? ''),
                'event_id' => intval($it['event_id'] ?? 0),
                'action'   => (string) ($it['action'] ?? ''),
                'created'  => intval($it['created'] ?? 0),
            ];
        }, $items);

        return new WP_REST_Response([
            'ok'            => true,
            'notifications' => array_values($out),
            'push_ready'    => self::apns_configured(),
        ], 200);
    }

    /** POST /notifications/broadcast {title, body, event_id?} – Veranstalter. */
    public static function rest_broadcast(WP_REST_Request $req) {
        $title = self::clean($req->get_param('title'));
        $body  = self::clean($req->get_param('body'));
        if ($title === '' && $body === '') {
            return new WP_Error('tix_empty', 'Titel oder Text erforderlich.', ['status' => 400]);
        }
        if ($title === '') $title = 'KitchenKlub';
        $item = self::add_broadcast($title, $body, [
            'type'     => 'message',
            'event_id' => intval($req->get_param('event_id')),
            'action'   => (string) $req->get_param('action'),
        ]);
        return new WP_REST_Response([
            'ok'         => true,
            'item'       => $item,
            'pushed'     => self::apns_configured(),
            'recipients' => count(self::tokens()),
        ], 200);
    }

    /** POST /push/register {token, platform} – Geräte-Token merken. */
    public static function rest_register_device(WP_REST_Request $req) {
        $token    = preg_replace('/[^a-f0-9]/i', '', (string) $req->get_param('token'));
        $platform = sanitize_key($req->get_param('platform') ?: 'ios');
        if (strlen($token) < 32) {
            return new WP_Error('tix_bad_token', 'Ungültiges Token.', ['status' => 400]);
        }
        $tokens = self::tokens();
        $tokens[$token] = [
            'user'     => get_current_user_id(),
            'platform' => $platform,
            'ts'       => time(),
        ];
        // Alte Token (>180 Tage) ausmisten, deckeln.
        $cutoff = time() - 180 * DAY_IN_SECONDS;
        foreach ($tokens as $t => $meta) {
            if (intval($meta['ts'] ?? 0) < $cutoff) unset($tokens[$t]);
        }
        if (count($tokens) > 5000) {
            $tokens = array_slice($tokens, -5000, null, true);
        }
        update_option(self::OPT_TOKENS, $tokens, false);
        return new WP_REST_Response(['ok' => true, 'push_ready' => self::apns_configured()], 200);
    }

    /** POST /push/unregister {token} */
    public static function rest_unregister_device(WP_REST_Request $req) {
        $token  = preg_replace('/[^a-f0-9]/i', '', (string) $req->get_param('token'));
        $tokens = self::tokens();
        if (isset($tokens[$token])) {
            unset($tokens[$token]);
            update_option(self::OPT_TOKENS, $tokens, false);
        }
        return new WP_REST_Response(['ok' => true], 200);
    }

    // ── Automatik ─────────────────────────────────────────────────

    /** Nach abgeschlossener Bestellung: Ticket-Hinweis an den Käufer. */
    public static function on_order_completed($order_id) {
        if (!class_exists('TIX_Order')) return;
        $order = TIX_Order::get($order_id);
        if (!$order) return;

        $uid = intval($order->get_customer_id());
        if ($uid <= 0) {
            // Gastbestellung: Käufer per E-Mail suchen (falls doch ein Konto existiert).
            $email = $order->get_billing_email();
            if ($email) {
                $u = get_user_by('email', $email);
                if ($u) $uid = intval($u->ID);
            }
        }
        if ($uid <= 0) return; // Ohne Konto kein persönlicher In-App-Hinweis.

        // Doppelte Hinweise je Bestellung vermeiden.
        $flag = '_tix_notif_order_' . intval($order_id);
        if (get_user_meta($uid, $flag, true)) return;
        update_user_meta($uid, $flag, time());

        self::add_user_item(
            $uid,
            'Deine Tickets liegen in der App',
            'Öffne den Tickets-Tab, um deine QR-Codes jederzeit griffbereit zu haben.',
            ['type' => 'ticket', 'action' => 'tab:tickets']
        );
    }

    /** Stündlich: am Event-Tag einmal an das heutige Event erinnern. */
    public static function cron_reminders() {
        $today = current_time('Y-m-d');
        $q = new WP_Query([
            'post_type'      => 'tix_event',
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '=', 'type' => 'DATE'],
                [
                    'relation' => 'AND',
                    ['key' => '_tix_date_start', 'value' => $today, 'compare' => '<=', 'type' => 'DATE'],
                    ['key' => '_tix_date_end',   'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                ],
            ],
        ]);
        if (!$q->have_posts()) return;

        $reminded = get_option(self::OPT_REMINDED, []);
        if (!is_array($reminded)) $reminded = [];
        // Einträge älter als 3 Tage vergessen.
        $reminded = array_filter($reminded, fn($ts) => intval($ts) > time() - 3 * DAY_IN_SECONDS);

        $now   = current_time('timestamp');
        $window = self::reminder_hours() * HOUR_IN_SECONDS;

        foreach ($q->posts as $post) {
            $eid = $post->ID;
            if (isset($reminded[$eid])) continue;

            $date  = get_post_meta($eid, '_tix_date_start', true);
            $time  = get_post_meta($eid, '_tix_time_start', true) ?: '20:00';
            $start = strtotime(trim($date . ' ' . $time));
            if (!$start) continue;

            // Erst innerhalb des Erinnerungsfensters vor Beginn (aber nicht danach) auslösen.
            $diff = $start - $now;
            if ($diff > $window) continue;      // noch zu früh
            if ($diff < -2 * HOUR_IN_SECONDS) continue; // Event läuft längst / vorbei

            $title = get_the_title($eid);
            $when  = $time ? ('Beginn ' . substr($time, 0, 5) . ' Uhr') : 'heute';
            self::add_broadcast(
                'Heute: ' . $title,
                'Es geht los – ' . $when . '. Wir freuen uns auf dich!',
                ['type' => 'reminder', 'event_id' => $eid, 'action' => 'event:' . $eid]
            );
            $reminded[$eid] = time();
        }
        update_option(self::OPT_REMINDED, $reminded, false);
        wp_reset_postdata();
    }

    // ── Push-Zustellung (APNs) ────────────────────────────────────

    private static function tokens() {
        $t = get_option(self::OPT_TOKENS, []);
        return is_array($t) ? $t : [];
    }

    public static function apns_configured() {
        $c = self::push_config();
        return $c['key'] !== '' && $c['key_id'] !== '' && $c['team_id'] !== '';
    }

    private static function push_config() {
        return [
            'key'     => trim((string) get_option('_tix_apns_key', '')),        // Inhalt der .p8-Datei
            'key_id'  => trim((string) get_option('_tix_apns_key_id', '')),
            'team_id' => trim((string) get_option('_tix_apns_team_id', '')),
            'bundle'  => trim((string) get_option('_tix_apns_bundle', 'de.kitchenklub.app')),
            'env'     => get_option('_tix_apns_env', 'production') === 'sandbox' ? 'sandbox' : 'production',
        ];
    }

    private static function push_all($title, $body, $data = []) {
        self::send_push(array_keys(self::tokens()), $title, $body, $data);
    }

    private static function push_user($user_id, $title, $body, $data = []) {
        $tokens = [];
        foreach (self::tokens() as $t => $meta) {
            if (intval($meta['user'] ?? 0) === intval($user_id)) $tokens[] = $t;
        }
        if ($tokens) self::send_push($tokens, $title, $body, $data);
    }

    /**
     * Sendet eine Push-Nachricht an die Geräte-Token. Ohne konfigurierten
     * Apple-Schlüssel passiert nichts (der In-App-Feed genügt dann).
     */
    public static function send_push($tokens, $title, $body, $data = []) {
        $tokens = array_values(array_unique(array_filter((array) $tokens)));
        if (!$tokens || !self::apns_configured()) return 0;

        $jwt = self::apns_jwt();
        if (!$jwt) return 0;

        $c    = self::push_config();
        $host = $c['env'] === 'sandbox' ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
        $payload = wp_json_encode([
            'aps' => [
                'alert' => ['title' => $title, 'body' => $body],
                'sound' => 'default',
                'badge' => 1,
            ],
            'data' => $data,
        ]);

        $sent    = 0;
        $invalid = [];
        foreach ($tokens as $token) {
            $ch = curl_init("https://{$host}/3/device/{$token}");
            curl_setopt_array($ch, [
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_HTTPHEADER     => [
                    'authorization: bearer ' . $jwt,
                    'apns-topic: ' . $c['bundle'],
                    'apns-push-type: alert',
                    'apns-priority: 10',
                    'content-type: application/json',
                ],
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200) {
                $sent++;
            } elseif ($code === 410 || $code === 400) {
                $invalid[] = $token; // Token abgelaufen/ungültig → entfernen
            }
        }
        if ($invalid) {
            $all = self::tokens();
            foreach ($invalid as $t) unset($all[$t]);
            update_option(self::OPT_TOKENS, $all, false);
        }
        return $sent;
    }

    /** ES256-JWT für die APNs-Authentifizierung (10 min gecacht). */
    private static function apns_jwt() {
        $c = self::push_config();
        $cache = get_transient('_tix_apns_jwt');
        if ($cache) return $cache;

        $header = self::b64url(wp_json_encode(['alg' => 'ES256', 'kid' => $c['key_id']]));
        $claims = self::b64url(wp_json_encode(['iss' => $c['team_id'], 'iat' => time()]));
        $input  = $header . '.' . $claims;

        $pkey = openssl_pkey_get_private($c['key']);
        if (!$pkey) return '';
        $der = '';
        if (!openssl_sign($input, $der, $pkey, OPENSSL_ALGO_SHA256)) return '';

        $sig = self::der_to_raw_ecdsa($der, 64);
        if ($sig === '') return '';
        $jwt = $input . '.' . self::b64url($sig);
        set_transient('_tix_apns_jwt', $jwt, 10 * MINUTE_IN_SECONDS);
        return $jwt;
    }

    private static function b64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** OpenSSL liefert ECDSA als DER-Sequenz; APNs erwartet rohes R||S. */
    private static function der_to_raw_ecdsa($der, $len) {
        $pos = 0;
        if (($der[$pos++] ?? '') !== "\x30") return '';
        // Gesamtlänge überspringen (kurz- oder langform)
        $b = ord($der[$pos++]);
        if ($b & 0x80) $pos += ($b & 0x7f);
        $read = function () use ($der, &$pos) {
            if (($der[$pos++] ?? '') !== "\x02") return null; // INTEGER
            $l = ord($der[$pos++]);
            $v = substr($der, $pos, $l);
            $pos += $l;
            return ltrim($v, "\x00");
        };
        $r = $read();
        $s = $read();
        if ($r === null || $s === null) return '';
        $half = $len / 2;
        $r = str_pad($r, $half, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, $half, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }
}
