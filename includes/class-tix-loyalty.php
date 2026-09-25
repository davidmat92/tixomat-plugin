<?php
if (!defined('ABSPATH')) exit;

/**
 * Treue-/Prämienprogramm ("Stempelkarte") für die App.
 *
 * Ablauf (fälschungssicher):
 *  - Der Gast zeigt in der App einen QR-Code. Inhalt = ein serverseitig
 *    signierter Token (HMAC), der alle paar Minuten erneuert wird. Ein
 *    Screenshot ist damit schnell wertlos.
 *  - Das Personal scannt den Code am Einlass (Veranstalter-Auth) und vergibt
 *    einen Stempel: `POST /loyalty/award`. Der Server prüft die Signatur, die
 *    Frische und vergibt max. 1 Stempel je Gast und Event.
 *  - Prämien werden ebenfalls vom Personal per Scan eingelöst
 *    (`POST /loyalty/redeem`), Punkte werden serverseitig abgezogen.
 *  - Bei Ticket-Events gibt es den Stempel automatisch beim Ticket-Check-in.
 *
 * Punkte liegen als User-Meta (`_tix_loyalty_points`), ein kurzer Verlauf in
 * `_tix_loyalty_log`, die schon bestempelten Events in `_tix_loyalty_stamped`.
 */
class TIX_Loyalty {
    const NS = 'tixomat/v1';
    const TOKEN_TTL = 900; // 15 Minuten Gültigkeit des Einlass-Tokens

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Automatischer Stempel beim Ticket-Check-in
        add_action('tix_ticket_checked_in', [__CLASS__, 'on_ticket_checked_in'], 20, 1);
    }

    // ── Einstellungen ─────────────────────────────────────────────
    public static function enabled() {
        return get_option('_tix_loyalty_enabled', '1') === '1';
    }

    public static function stamps_per_visit() {
        return max(1, intval(get_option('_tix_loyalty_stamps_per_visit', 1)));
    }

    /** Prämienstufen (per Option/Filter anpassbar). */
    public static function rewards() {
        $default = [
            ['id' => 'entry', 'cost' => 5,  'title' => 'Freier Eintritt', 'description' => 'Bei der nächsten Party', 'type' => 'entry'],
            ['id' => 'drink', 'cost' => 10, 'title' => '1 Freigetränk',    'description' => 'Im Club als Verzehrguthaben', 'type' => 'drink'],
        ];
        $stored = get_option('_tix_loyalty_rewards', null);
        $rewards = is_array($stored) && $stored ? $stored : $default;
        $rewards = apply_filters('tix_loyalty_rewards', $rewards);
        // Normalisieren
        $out = [];
        foreach ($rewards as $r) {
            if (!is_array($r) || empty($r['id'])) continue;
            $out[] = [
                'id'          => sanitize_key($r['id']),
                'cost'        => max(1, intval($r['cost'] ?? 0)),
                'title'       => (string) ($r['title'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'type'        => sanitize_key($r['type'] ?? 'reward'),
            ];
        }
        return $out;
    }

    private static function reward($id) {
        foreach (self::rewards() as $r) {
            if ($r['id'] === sanitize_key($id)) return $r;
        }
        return null;
    }

    // ── Token (signierter Einlass-Code) ───────────────────────────
    private static function secret() {
        $s = get_option('_tix_loyalty_secret');
        if (!$s) {
            $s = wp_generate_password(64, true, true);
            update_option('_tix_loyalty_secret', $s, false);
        }
        return $s;
    }

    private static function b64url_encode($d) {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }

    private static function b64url_decode($d) {
        return base64_decode(strtr((string) $d, '-_', '+/'));
    }

    public static function make_token($uid) {
        $payload = intval($uid) . '.' . time();
        $sig = hash_hmac('sha256', $payload, self::secret());
        return self::b64url_encode($payload . '.' . $sig);
    }

    /** Liefert die User-ID oder 0, wenn ungültig/abgelaufen. */
    public static function verify_token($token) {
        $raw = self::b64url_decode($token);
        $parts = explode('.', (string) $raw);
        if (count($parts) !== 3) return 0;
        list($uid, $issued, $sig) = $parts;
        $expected = hash_hmac('sha256', $uid . '.' . $issued, self::secret());
        if (!hash_equals($expected, (string) $sig)) return 0;
        $issued = intval($issued);
        if ($issued < time() - self::TOKEN_TTL) return 0; // abgelaufen
        if ($issued > time() + 120) return 0;              // Uhr-Drift
        return intval($uid);
    }

    // ── Punkte + Verlauf ──────────────────────────────────────────
    public static function points($uid) {
        return max(0, intval(get_user_meta($uid, '_tix_loyalty_points', true)));
    }

    private static function set_points($uid, $points) {
        update_user_meta($uid, '_tix_loyalty_points', max(0, intval($points)));
    }

    private static function log($uid, $type, $delta, $label) {
        $log = get_user_meta($uid, '_tix_loyalty_log', true);
        $log = is_array($log) ? $log : [];
        array_unshift($log, [
            'ts'    => current_time('c'),
            'type'  => $type,
            'delta' => intval($delta),
            'label' => (string) $label,
        ]);
        update_user_meta($uid, '_tix_loyalty_log', array_slice($log, 0, 40));
    }

    private static function stamp_key($event_id) {
        return $event_id > 0 ? 'e' . intval($event_id) : 'd' . gmdate('Ymd');
    }

    private static function already_stamped($uid, $event_id) {
        $done = get_user_meta($uid, '_tix_loyalty_stamped', true);
        $done = is_array($done) ? $done : [];
        return in_array(self::stamp_key($event_id), $done, true);
    }

    /** Vergibt Stempel; gibt false zurück, wenn für dieses Event schon geschehen. */
    public static function award_stamp($uid, $event_id = 0, $source = 'scan') {
        if (!$uid || !self::enabled()) return false;
        $key = self::stamp_key($event_id);
        $done = get_user_meta($uid, '_tix_loyalty_stamped', true);
        $done = is_array($done) ? $done : [];
        if (in_array($key, $done, true)) return false;
        $done[] = $key;
        update_user_meta($uid, '_tix_loyalty_stamped', array_slice($done, -400));

        $n = self::stamps_per_visit();
        self::set_points($uid, self::points($uid) + $n);
        $label = ($event_id > 0 && get_post_type($event_id) === 'tix_event') ? get_the_title($event_id) : 'Besuch';
        self::log($uid, 'stamp', $n, $label !== '' ? $label : 'Besuch');
        return $n;
    }

    // ── App: Konfiguration + eigener Stand + Token ────────────────
    public static function rest_config() {
        return rest_ensure_response([
            'enabled'          => self::enabled(),
            'stamps_per_visit' => self::stamps_per_visit(),
            'rewards'          => self::rewards(),
        ]);
    }

    private static function reward_state($uid) {
        $points = self::points($uid);
        $out = [];
        foreach (self::rewards() as $r) {
            $r['can_redeem'] = $points >= $r['cost'];
            $out[] = $r;
        }
        return $out;
    }

    public static function rest_me(WP_REST_Request $req) {
        $uid = get_current_user_id();
        if (!$uid) return new WP_Error('not_logged_in', 'Nicht angemeldet.', ['status' => 401]);
        $log = get_user_meta($uid, '_tix_loyalty_log', true);
        $log = is_array($log) ? array_slice($log, 0, 20) : [];
        return rest_ensure_response([
            'enabled'          => self::enabled(),
            'points'           => self::points($uid),
            'stamps_per_visit' => self::stamps_per_visit(),
            'rewards'          => self::reward_state($uid),
            'history'          => array_values($log),
        ]);
    }

    public static function rest_token(WP_REST_Request $req) {
        $uid = get_current_user_id();
        if (!$uid) return new WP_Error('not_logged_in', 'Nicht angemeldet.', ['status' => 401]);
        if (!self::enabled()) return new WP_Error('disabled', 'Programm nicht aktiv.', ['status' => 403]);
        return rest_ensure_response([
            'token'   => self::make_token($uid),
            'expires' => time() + self::TOKEN_TTL,
            'ttl'     => self::TOKEN_TTL,
        ]);
    }

    // ── Veranstalter: scannen, Stempel vergeben, Prämie einlösen ──
    private static function scanned_user(WP_REST_Request $req) {
        $body = $req->get_json_params();
        $token = is_array($body) ? ($body['token'] ?? '') : '';
        $uid = self::verify_token($token);
        if (!$uid) return new WP_Error('bad_token', 'Code ungültig oder abgelaufen.', ['status' => 400]);
        $user = get_user_by('ID', $uid);
        if (!$user) return new WP_Error('no_user', 'Gast nicht gefunden.', ['status' => 404]);
        return $user;
    }

    private static function user_payload($user, $event_id = 0) {
        $uid = $user->ID;
        return [
            'user_id'         => $uid,
            'user_name'       => $user->display_name ?: $user->user_login,
            'points'          => self::points($uid),
            'already_stamped' => self::already_stamped($uid, $event_id),
            'rewards'         => self::reward_state($uid),
        ];
    }

    public static function rest_scan(WP_REST_Request $req) {
        $user = self::scanned_user($req);
        if (is_wp_error($user)) return $user;
        $body = $req->get_json_params();
        $event_id = intval(is_array($body) ? ($body['event_id'] ?? 0) : 0);
        return rest_ensure_response(['ok' => true] + self::user_payload($user, $event_id));
    }

    public static function rest_award(WP_REST_Request $req) {
        $user = self::scanned_user($req);
        if (is_wp_error($user)) return $user;
        $body = $req->get_json_params();
        $event_id = intval(is_array($body) ? ($body['event_id'] ?? 0) : 0);
        $awarded = self::award_stamp($user->ID, $event_id, 'scan');
        if ($awarded === false) {
            return rest_ensure_response([
                'ok'      => true,
                'awarded' => 0,
                'already' => true,
                'message' => 'Für dieses Event schon gestempelt.',
            ] + self::user_payload($user, $event_id));
        }
        return rest_ensure_response([
            'ok'      => true,
            'awarded' => $awarded,
            'already' => false,
            'message' => $awarded . ' Stempel vergeben.',
        ] + self::user_payload($user, $event_id));
    }

    public static function rest_redeem(WP_REST_Request $req) {
        $user = self::scanned_user($req);
        if (is_wp_error($user)) return $user;
        $body = $req->get_json_params();
        $reward_id = is_array($body) ? sanitize_key($body['reward_id'] ?? '') : '';
        $reward = self::reward($reward_id);
        if (!$reward) return new WP_Error('no_reward', 'Prämie unbekannt.', ['status' => 400]);
        $uid = $user->ID;
        if (self::points($uid) < $reward['cost']) {
            return new WP_Error('not_enough', 'Nicht genug Punkte.', ['status' => 409]);
        }
        self::set_points($uid, self::points($uid) - $reward['cost']);
        $code = 'LR-' . strtoupper(wp_generate_password(6, false, false));
        self::log($uid, 'redeem', -$reward['cost'], $reward['title'] . ' (' . $code . ')');
        return rest_ensure_response([
            'ok'      => true,
            'reward'  => $reward,
            'code'    => $code,
            'user_id' => $uid,
            'user_name' => $user->display_name ?: $user->user_login,
            'points'  => self::points($uid),
        ]);
    }

    // ── Automatischer Stempel beim Ticket-Check-in ────────────────
    public static function on_ticket_checked_in($ticket_id) {
        if (!self::enabled()) return;
        $email = get_post_meta($ticket_id, '_tix_ticket_owner_email', true);
        if (!$email) return;
        $user = get_user_by('email', $email);
        if (!$user) return; // Gast ohne Konto → kein Stempel
        $event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        self::award_stamp($user->ID, $event_id, 'ticket');
    }

    // ── Routen ────────────────────────────────────────────────────
    public static function register_routes() {
        $organizer = ['TIX_REST_API', 'check_organizer'];

        register_rest_route(self::NS, '/loyalty/config', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_config'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/loyalty/me', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_me'],
            'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route(self::NS, '/loyalty/token', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_token'],
            'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route(self::NS, '/loyalty/scan', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_scan'],
            'permission_callback' => $organizer,
        ]);
        register_rest_route(self::NS, '/loyalty/award', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_award'],
            'permission_callback' => $organizer,
        ]);
        register_rest_route(self::NS, '/loyalty/redeem', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_redeem'],
            'permission_callback' => $organizer,
        ]);
    }
}
