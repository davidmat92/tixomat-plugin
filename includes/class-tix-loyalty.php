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
 *
 * Mehr-Veranstalter-Modus (TIX_App_Scope, evendis): jeder Veranstalter hat
 * sein eigenes Programm. Einstellungen am Veranstalter (`_tix_org_loyalty`),
 * Punkte/Verlauf/Stempel je Veranstalter (User-Meta mit Endung `_o{ID}`).
 * Personal stempelt nur für den eigenen Veranstalter, Gäste fragen ihren
 * Stand mit `?organizer=ID` ab. Ohne Modus bleibt alles wie oben.
 * `GET /loyalty/cards` (Token): alle Stempelkarten des Gasts auf einmal (je Veranstalter mit aktivem Programm;
 * ohne Modus eine Karte für die Seite).
 */
class TIX_Loyalty {
    const NS = 'tixomat/v1';
    const TOKEN_TTL = 900; // 15 Minuten Gültigkeit des Einlass-Tokens

    /** Aktueller Veranstalter-Kontext (0 = seitenweites Programm). */
    private static $org = 0;

    /** Kontext für Personal-Routen setzen; liefert WP_Error bei fremdem Event. */
    private static function staff_context(WP_REST_Request $req) {
        self::$org = 0;
        if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) return true;
        if (TIX_App_Scope::scoped()) {
            self::$org = TIX_App_Scope::organizer_id_for_user();
            if (!self::$org) return TIX_App_Scope::deny('Dieses Konto ist keinem Veranstalter zugeordnet.');
        } else {
            self::$org = intval($req->get_param('organizer'));
        }
        $body = $req->get_json_params();
        $event_id = intval(is_array($body) ? ($body['event_id'] ?? 0) : 0);
        if ($event_id && !TIX_App_Scope::event_allowed($event_id)) {
            return TIX_App_Scope::deny('Kein Zugriff auf dieses Event.');
        }
        return true;
    }

    /** Kontext für Gast-Routen (`?organizer=ID`). */
    private static function guest_context(WP_REST_Request $req) {
        self::$org = (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) ? max(0, intval($req->get_param('organizer'))) : 0;
    }

    /** Meta-Schlüssel im aktuellen Kontext. */
    private static function mk($base) {
        return self::$org ? $base . '_o' . self::$org : $base;
    }

    /** Einstellungen des Veranstalters (Kontext > 0). */
    private static function org_config() {
        $c = get_post_meta(self::$org, '_tix_org_loyalty', true);
        return is_array($c) ? $c : [];
    }

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Automatischer Stempel beim Ticket-Check-in
        add_action('tix_ticket_checked_in', [__CLASS__, 'on_ticket_checked_in'], 20, 1);
    }

    // ── Einstellungen ─────────────────────────────────────────────
    public static function enabled() {
        if (class_exists('TIX_Modules') && !TIX_Modules::allowed('loyalty')) return false; // Plattform-Freischaltung
        if (self::$org) {
            $c = self::org_config();
            if (array_key_exists('enabled', $c)) return !empty($c['enabled']);
            $mods = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::modules(self::$org) : [];
            return !empty($mods['loyalty']);
        }
        return get_option('_tix_loyalty_enabled', '1') === '1';
    }

    public static function stamps_per_visit() {
        if (self::$org) return max(1, intval(self::org_config()['stamps_per_visit'] ?? 1));
        return max(1, intval(get_option('_tix_loyalty_stamps_per_visit', 1)));
    }

    /** Prämienstufen (per Option/Filter anpassbar). */
    public static function rewards() {
        $default = [
            ['id' => 'entry', 'cost' => 5,  'title' => 'Freier Eintritt', 'description' => 'Bei der nächsten Party', 'type' => 'entry'],
            ['id' => 'drink', 'cost' => 10, 'title' => '1 Freigetränk',    'description' => 'Im Club als Verzehrguthaben', 'type' => 'drink'],
        ];
        $stored = self::$org ? (self::org_config()['rewards'] ?? null) : get_option('_tix_loyalty_rewards', null);
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
        return max(0, intval(get_user_meta($uid, self::mk('_tix_loyalty_points'), true)));
    }

    private static function set_points($uid, $points) {
        update_user_meta($uid, self::mk('_tix_loyalty_points'), max(0, intval($points)));
    }

    private static function log($uid, $type, $delta, $label) {
        $log = get_user_meta($uid, self::mk('_tix_loyalty_log'), true);
        $log = is_array($log) ? $log : [];
        array_unshift($log, [
            'ts'    => current_time('c'),
            'type'  => $type,
            'delta' => intval($delta),
            'label' => (string) $label,
        ]);
        update_user_meta($uid, self::mk('_tix_loyalty_log'), array_slice($log, 0, 40));
    }

    private static function stamp_key($event_id) {
        return $event_id > 0 ? 'e' . intval($event_id) : 'd' . gmdate('Ymd');
    }

    private static function already_stamped($uid, $event_id) {
        $done = get_user_meta($uid, self::mk('_tix_loyalty_stamped'), true);
        $done = is_array($done) ? $done : [];
        return in_array(self::stamp_key($event_id), $done, true);
    }

    /** Vergibt Stempel; gibt false zurück, wenn für dieses Event schon geschehen. */
    public static function award_stamp($uid, $event_id = 0, $source = 'scan') {
        if (!$uid || !self::enabled()) return false;
        $key = self::stamp_key($event_id);
        $done = get_user_meta($uid, self::mk('_tix_loyalty_stamped'), true);
        $done = is_array($done) ? $done : [];
        if (in_array($key, $done, true)) return false;
        $done[] = $key;
        update_user_meta($uid, self::mk('_tix_loyalty_stamped'), array_slice($done, -400));

        $n = self::stamps_per_visit();
        self::set_points($uid, self::points($uid) + $n);
        $event_type = self::$org ? 'event' : 'tix_event'; // seitenweit unverändert (Ein-Club-Setup)
        $label = ($event_id > 0 && get_post_type($event_id) === $event_type) ? html_entity_decode(get_the_title($event_id), ENT_QUOTES, 'UTF-8') : 'Besuch';
        self::log($uid, 'stamp', $n, $label !== '' ? $label : 'Besuch');
        return $n;
    }

    // ── App: Konfiguration + eigener Stand + Token ────────────────
    public static function rest_config($req = null) {
        if ($req instanceof WP_REST_Request && $req->get_method() === 'GET') self::guest_context($req);
        return rest_ensure_response([
            'enabled'          => self::enabled(),
            'stamps_per_visit' => self::stamps_per_visit(),
            'rewards'          => self::rewards(),
            'station_events_only' => self::station_events_only(),
            'has_exit_pin'     => self::exit_pin_hash() !== '',
        ]);
    }

    /** Veranstalter: Voraussetzungen anpassen (aktiv, Stempel/Besuch, Prämien). */
    public static function rest_save_config(WP_REST_Request $req) {
        $ctx = self::staff_context($req);
        if (is_wp_error($ctx)) return $ctx;
        $b = $req->get_json_params();
        if (!is_array($b)) $b = [];
        // PIN zum Verlassen der Stempel-Station (1.38.870): 4–6 Ziffern, nur gehasht; leer/null löscht ihn
        if (array_key_exists('station_exit_pin', $b)) {
            $pin = $b['station_exit_pin'] === null ? '' : trim((string) $b['station_exit_pin']);
            if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) return new WP_Error('tix_invalid_field', 'Der Code braucht 4 bis 6 Ziffern.', ['status' => 400, 'field' => 'station_exit_pin']);
            $hash = $pin === '' ? '' : wp_hash_password($pin);
            if (self::$org) { $c = self::org_config(); if ($hash === '') unset($c['station_exit_pin_hash']); else $c['station_exit_pin_hash'] = $hash; update_post_meta(self::$org, '_tix_org_loyalty', $c); }
            elseif ($hash === '') delete_option('_tix_loyalty_station_exit_pin');
            else update_option('_tix_loyalty_station_exit_pin', $hash, false);
            unset($b['station_exit_pin']);
        }
        if (self::$org) return self::save_org_config($b);
        if (array_key_exists('enabled', $b)) {
            update_option('_tix_loyalty_enabled', filter_var($b['enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }
        if (array_key_exists('stamps_per_visit', $b)) {
            update_option('_tix_loyalty_stamps_per_visit', max(1, intval($b['stamps_per_visit'])));
        }
        if (array_key_exists('station_events_only', $b)) update_option('_tix_loyalty_station_events_only', filter_var($b['station_events_only'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0', false);
        if (array_key_exists('rewards', $b) && is_array($b['rewards'])) {
            $clean = [];
            foreach ($b['rewards'] as $r) {
                if (!is_array($r)) continue;
                $title = sanitize_text_field($r['title'] ?? '');
                if ($title === '') continue;
                $id = sanitize_key($r['id'] ?? '');
                if ($id === '') $id = sanitize_key(sanitize_title($title));
                if ($id === '') $id = 'r' . count($clean);
                $clean[] = [
                    'id'          => $id,
                    'cost'        => max(1, intval($r['cost'] ?? 1)),
                    'title'       => $title,
                    'description' => sanitize_text_field($r['description'] ?? ''),
                    'type'        => sanitize_key($r['type'] ?? 'reward'),
                ];
            }
            update_option('_tix_loyalty_rewards', $clean, false);
        }
        return self::rest_config();
    }

    /** Einstellungen eines Veranstalters speichern (Mehr-Veranstalter-Modus). */
    private static function save_org_config(array $b) {
        $c = self::org_config();
        if (array_key_exists('enabled', $b)) $c['enabled'] = filter_var($b['enabled'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('stamps_per_visit', $b)) $c['stamps_per_visit'] = max(1, intval($b['stamps_per_visit']));
        if (array_key_exists('station_events_only', $b)) $c['station_events_only'] = filter_var($b['station_events_only'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('rewards', $b) && is_array($b['rewards'])) {
            $clean = [];
            foreach ($b['rewards'] as $r) {
                if (!is_array($r)) continue;
                $title = sanitize_text_field($r['title'] ?? '');
                if ($title === '') continue;
                $id = sanitize_key($r['id'] ?? '');
                if ($id === '') $id = sanitize_key(sanitize_title($title));
                if ($id === '') $id = 'r' . count($clean);
                $clean[] = [
                    'id'          => $id,
                    'cost'        => max(1, intval($r['cost'] ?? 1)),
                    'title'       => $title,
                    'description' => sanitize_text_field($r['description'] ?? ''),
                    'type'        => sanitize_key($r['type'] ?? 'reward'),
                ];
            }
            $c['rewards'] = $clean;
        }
        update_post_meta(self::$org, '_tix_org_loyalty', $c);
        // Modul-Schalter der Veranstalter-Seite mitziehen
        if (array_key_exists('enabled', $b) && class_exists('TIX_Public_Platform')) {
            $mods = TIX_Public_Platform::modules(self::$org);
            $mods['loyalty'] = !empty($c['enabled']);
            update_post_meta(self::$org, TIX_Public_Platform::META_MODULES, wp_json_encode($mods));
        }
        return self::rest_config();
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
        self::guest_context($req);
        $uid = get_current_user_id();
        if (!$uid) return new WP_Error('not_logged_in', 'Nicht angemeldet.', ['status' => 401]);
        $log = get_user_meta($uid, self::mk('_tix_loyalty_log'), true);
        $log = is_array($log) ? array_slice($log, 0, 20) : [];
        return rest_ensure_response([
            'enabled'          => self::enabled(),
            'points'           => self::points($uid),
            'stamps_per_visit' => self::stamps_per_visit(),
            'rewards'          => self::reward_state($uid),
            'history'          => array_values($log),
        ]);
    }

    /** GET /loyalty/cards – alle Karten des angemeldeten Gasts (Karten mit Punkten zuerst). */
    public static function rest_cards(WP_REST_Request $req) {
        $uid = get_current_user_id();
        if (!$uid) return new WP_Error('not_logged_in', 'Nicht angemeldet.', ['status' => 401]);
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        $orgs = $multi ? array_map('intval', get_posts(['post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 500, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC'])) : [0];
        $cards = [];
        foreach ($orgs as $oid) {
            if ($oid && class_exists('TIX_Org_Approval') && !TIX_Org_Approval::is_public($oid)) continue;
            self::$org = $oid;
            if (!self::enabled()) continue;
            $log = get_user_meta($uid, self::mk('_tix_loyalty_log'), true);
            $img = $oid ? intval(get_post_meta($oid, '_tix_org_image_id', true)) : 0;
            $cards[] = [
                'organizer_id'     => $oid ?: null,
                'organizer_name'   => $oid ? html_entity_decode(get_the_title($oid), ENT_QUOTES, 'UTF-8') : wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                'organizer_image'  => $img ? (string) wp_get_attachment_image_url($img, 'thumbnail') : '',
                'points'           => self::points($uid),
                'stamps_per_visit' => self::stamps_per_visit(),
                'rewards'          => self::reward_state($uid),
                'last_activity'    => (is_array($log) && !empty($log[0]['ts'])) ? (string) $log[0]['ts'] : null,
            ];
        }
        self::$org = 0;
        usort($cards, function ($a, $b) {
            return ($b['points'] <=> $a['points']) ?: strcasecmp($a['organizer_name'], $b['organizer_name']);
        });
        return rest_ensure_response(['ok' => true, 'cards' => $cards]);
    }

    public static function rest_token(WP_REST_Request $req) {
        self::guest_context($req);
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
        $ctx = self::staff_context($req);
        if (is_wp_error($ctx)) return $ctx;
        $user = self::scanned_user($req);
        if (is_wp_error($user)) return $user;
        $body = $req->get_json_params();
        $event_id = intval(is_array($body) ? ($body['event_id'] ?? 0) : 0);
        return rest_ensure_response(['ok' => true] + self::user_payload($user, $event_id));
    }

    public static function rest_award(WP_REST_Request $req) {
        $ctx = self::staff_context($req);
        if (is_wp_error($ctx)) return $ctx;
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
        $ctx = self::staff_context($req);
        if (is_wp_error($ctx)) return $ctx;
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
        $prev = self::$org;
        self::$org = 0;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            $eid = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
            $ids = $eid ? TIX_App_Scope::event_org_ids($eid) : [];
            self::$org = $ids ? intval($ids[0]) : 0;
        }
        self::checked_in_award($ticket_id);
        self::$org = $prev;
    }

    private static function checked_in_award($ticket_id) {
        if (!self::enabled()) return;
        $email = get_post_meta($ticket_id, '_tix_ticket_owner_email', true);
        if (!$email) return;
        $user = get_user_by('email', $email);
        if (!$user) return; // Gast ohne Konto → kein Stempel
        $event_id = intval(get_post_meta($ticket_id, '_tix_ticket_event_id', true));
        self::award_stamp($user->ID, $event_id, 'ticket');
    }

    // ── Stempel-Station (1.38.869, Plan uebergaben/plan-stempel-station-2026-11-17.md) ──
    const STATION_TTL = 15;        // Code gilt 15 s
    const STATION_REFRESH = 10;    // Station holt alle 10 s einen neuen
    const STATION_ALIVE = 30;      // ohne Herzschlag seit 30 s gilt die Station als aus
    const STATION_PREFIX = 'evendis-stamp:';

    public static function station_events_only() {
        if (self::$org) return !empty(self::org_config()['station_events_only']);
        return get_option('_tix_loyalty_station_events_only', '0') === '1';
    }

    public static function exit_pin_hash() {
        return self::$org ? (string) (self::org_config()['station_exit_pin_hash'] ?? '') : (string) get_option('_tix_loyalty_station_exit_pin', '');
    }

    /** POST /loyalty/station/exit {pin} – darf die Station verlassen werden? 5 Fehlversuche → 1 min Sperre (je Konto und Veranstalter) */
    public static function rest_station_exit(WP_REST_Request $req) {
        self::$org = 0;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            self::$org = (current_user_can('tix_app_stamp_station') || TIX_App_Scope::scoped()) ? intval(TIX_App_Scope::organizer_id_for_user()) : intval($req->get_param('organizer'));
            if (!self::$org) return new WP_Error('rest_forbidden', 'Bitte einen Veranstalter wählen.', ['status' => 403]);
        }
        $hash = self::exit_pin_hash();
        if ($hash === '') return rest_ensure_response(['ok' => true, 'exit' => true, 'pin_required' => false]);
        $k = 'tix_station_exit_' . md5(self::$org . '|' . get_current_user_id());
        $st = get_transient($k); $st = is_array($st) ? $st : ['fails' => 0, 'until' => 0];
        if ($st['until'] > time()) return new WP_Error('tix_station_exit_locked', 'Zu viele falsche Versuche. Bitte in einer Minute noch einmal.', ['status' => 429, 'retry_after' => $st['until'] - time()]);
        $b = $req->get_json_params();
        $pin = trim((string) (is_array($b) ? ($b['pin'] ?? '') : $req->get_param('pin')));
        if ($pin !== '' && preg_match('/^\d{4,6}$/', $pin) && wp_check_password($pin, $hash)) {
            delete_transient($k);
            return rest_ensure_response(['ok' => true, 'exit' => true, 'pin_required' => true]);
        }
        $st['fails']++;
        if ($st['fails'] >= 5) { $st = ['fails' => 0, 'until' => time() + MINUTE_IN_SECONDS]; set_transient($k, $st, 2 * MINUTE_IN_SECONDS); return new WP_Error('tix_station_exit_locked', 'Zu viele falsche Versuche. Bitte in einer Minute noch einmal.', ['status' => 429, 'retry_after' => MINUTE_IN_SECONDS]); }
        set_transient($k, $st, 10 * MINUTE_IN_SECONDS);
        return new WP_Error('tix_station_exit_wrong', 'Der Code stimmt nicht.', ['status' => 403, 'remaining' => 5 - $st['fails']]);
    }

    /** Signatur eines Stations-Codes (an diese Seite gebunden) */
    private static function station_sig($payload) {
        return substr(hash_hmac('sha256', 'tix-stamp-v1|' . strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)) . '|' . $payload, self::secret()), 0, 32);
    }

    /** Code bauen: s1.<org>.<event>.<station>.<iat>.<sig> (base64url) */
    public static function station_make($org, $event_id, $station, $iat) {
        $payload = 's1.' . intval($org) . '.' . intval($event_id) . '.' . $station . '.' . intval($iat);
        return self::b64url_encode($payload . '.' . self::station_sig($payload));
    }

    /** Code prüfen → ['org','event','station','iat'] | WP_Error (rein bis auf Zeit/Geheimnis) */
    public static function station_parse($token, $now = null) {
        $now = $now === null ? time() : intval($now);
        $token = trim((string) $token);
        if (strpos($token, self::STATION_PREFIX) === 0) $token = substr($token, strlen(self::STATION_PREFIX));
        $raw = (string) self::b64url_decode($token);
        $parts = explode('.', $raw);
        if (count($parts) !== 6 || $parts[0] !== 's1' || !preg_match('/^[a-z0-9-]{1,48}$/', $parts[3])) return new WP_Error('tix_station_token_invalid', 'Das ist kein gültiger Stempel-Code.', ['status' => 400]);
        $sig = array_pop($parts);
        if (!hash_equals(self::station_sig(implode('.', $parts)), $sig)) return new WP_Error('tix_station_token_invalid', 'Das ist kein gültiger Stempel-Code.', ['status' => 400]);
        $iat = intval($parts[4]);
        if ($iat > $now + 5) return new WP_Error('tix_station_token_invalid', 'Das ist kein gültiger Stempel-Code.', ['status' => 400]);
        if ($now - $iat > self::STATION_TTL) return new WP_Error('tix_station_token_expired', 'Code abgelaufen – bitte neu scannen.', ['status' => 410]);
        return ['org' => intval($parts[1]), 'event' => intval($parts[2]), 'station' => $parts[3], 'iat' => $iat];
    }

    private static function station_key($org, $station) {
        return 'tix_stamp_station_' . md5(intval($org) . '|' . $station);
    }

    /** Laufendes Event des Veranstalters (Zeitfenster wie beim Musikwunsch) → ['id','window'] | null */
    public static function station_event($org) {
        if (!class_exists('TIX_Music')) return null;
        $now = current_time('timestamp');
        $mq = [['key' => '_tix_date_start', 'value' => [gmdate('Y-m-d', $now - 2 * DAY_IN_SECONDS), gmdate('Y-m-d', $now + DAY_IN_SECONDS)], 'compare' => 'BETWEEN', 'type' => 'DATE']];
        if ($org) $mq[] = ['key' => '_tix_organizer_id', 'value' => (string) intval($org)];
        $best = null;
        foreach (get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 30, 'fields' => 'ids', 'meta_query' => $mq]) as $id) {
            if (get_post_meta($id, '_tix_status', true) === 'cancelled') continue;
            $w = TIX_Music::event_window($id);
            if ($w && $now >= $w['opens'] && $now <= $w['closes'] && (!$best || $w['start'] > $best['window']['start'])) $best = ['id' => intval($id), 'window' => $w];
        }
        return $best;
    }

    /** Darf der Nutzer die Station bedienen? (Rolle Station oder Personal/Veranstalter) */
    public static function perm_station(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (current_user_can('tix_app_stamp_station')) {
            if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi() && !TIX_App_Scope::organizer_id_for_user()) return new WP_Error('rest_forbidden', 'Dieses Konto ist keinem Veranstalter zugeordnet.', ['status' => 403]);
            return true;
        }
        return class_exists('TIX_REST_API') ? TIX_REST_API::check_organizer($req) : new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
    }

    /** GET /loyalty/station/token?device= → neuer Code (zugleich Herzschlag der Station) */
    public static function rest_station_token(WP_REST_Request $req) {
        self::$org = 0;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            self::$org = (current_user_can('tix_app_stamp_station') || TIX_App_Scope::scoped()) ? intval(TIX_App_Scope::organizer_id_for_user()) : intval($req->get_param('organizer'));
            if (!self::$org) return new WP_Error('rest_forbidden', 'Bitte einen Veranstalter wählen.', ['status' => 403]);
        }
        if (!self::enabled()) return new WP_Error('tix_loyalty_disabled', 'Das Prämienprogramm ist nicht aktiv.', ['status' => 403]);
        $dev = strtolower(preg_replace('/[^a-z0-9-]/i', '', (string) $req->get_param('device')));
        $station = substr($dev !== '' ? $dev : 'u' . get_current_user_id(), 0, 40);
        $ev = self::station_event(self::$org);
        $only = self::station_events_only();
        if ($only && !$ev) {
            $next = class_exists('TIX_Music') ? TIX_Music::next_event() : null;
            $nid = is_array($next) ? intval($next['id'] ?? 0) : intval($next);
            return new WP_Error('tix_station_no_event', 'Stempel gibt es nur während eines Events.', ['status' => 409, 'next_event' => $nid ? ['id' => $nid, 'title' => html_entity_decode(get_the_title($nid), ENT_QUOTES, 'UTF-8'), 'starts_at' => (string) get_post_meta($nid, '_tix_date_start', true) . ' ' . (string) get_post_meta($nid, '_tix_time_start', true)] : null]);
        }
        $eid = $ev ? $ev['id'] : 0;
        $now = time();
        set_transient(self::station_key(self::$org, $station), $now, self::STATION_ALIVE * 2);
        $token = self::station_make(self::$org, $eid, $station, $now);
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand() : ['name' => get_bloginfo('name'), 'logo' => '', 'accent' => '#111111'];
        if (self::$org) { $img = intval(get_post_meta(self::$org, '_tix_org_image_id', true)); $brand = ['name' => html_entity_decode(get_the_title(self::$org), ENT_QUOTES, 'UTF-8'), 'logo' => $img ? (string) wp_get_attachment_image_url($img, 'medium') : '', 'accent' => (string) ($brand['accent'] ?? '#111111')]; }
        $r = rest_ensure_response(['ok' => true, 'token' => $token, 'qr' => self::STATION_PREFIX . $token, 'station_id' => $station, 'iat' => $now, 'expires_at' => $now + self::STATION_TTL,
            'ttl' => self::STATION_TTL, 'refresh_in' => self::STATION_REFRESH, 'event' => $eid ? ['id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8')] : null, 'mode' => $eid ? 'event' : 'day',
            'brand' => ['name' => (string) ($brand['name'] ?? ''), 'logo' => (string) ($brand['logo'] ?? ''), 'accent' => (string) ($brand['accent'] ?? '')],
            'stamps_per_visit' => self::stamps_per_visit(), 'rewards' => self::rewards(), 'steps' => ['App öffnen', 'Prämien öffnen', '„Code scannen“ antippen'], 'events_only' => $only]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** POST /loyalty/claim {token} – Gast scannt den Code der Station */
    public static function rest_claim(WP_REST_Request $req) {
        $uid = get_current_user_id();
        if (!$uid) return new WP_Error('not_logged_in', 'Nicht angemeldet.', ['status' => 401]);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('loyalty_claim', 10, MINUTE_IN_SECONDS, 'u' . $uid)) return new WP_Error('tix_rate_limited', 'Zu viele Versuche. Bitte kurz warten.', ['status' => 429]);
        $b = $req->get_json_params();
        $t = self::station_parse(is_array($b) ? ($b['token'] ?? '') : $req->get_param('token'));
        if (is_wp_error($t)) return $t;
        self::$org = $t['org'];
        if (self::$org && !(class_exists('TIX_App_Scope') && TIX_App_Scope::multi())) return new WP_Error('tix_station_token_invalid', 'Das ist kein gültiger Stempel-Code.', ['status' => 400]);
        if (!self::enabled()) return new WP_Error('tix_loyalty_disabled', 'Das Prämienprogramm ist nicht aktiv.', ['status' => 403]);
        $beat = intval(get_transient(self::station_key(self::$org, $t['station'])));
        if (!$beat || time() - $beat > self::STATION_ALIVE) return new WP_Error('tix_station_offline', 'Die Stempel-Station ist gerade aus.', ['status' => 409]);
        $eid = $t['event'];
        // 1 Stempel je Event bzw. Tag, gemeinsam mit Personal-Scan und Check-in
        if (self::already_stamped($uid, $eid) || self::already_stamped($uid, 0)) {
            return new WP_Error('tix_already_stamped', $eid ? 'Für dieses Event schon gestempelt.' : 'Heute schon gestempelt.', ['status' => 409, 'points' => self::points($uid)]);
        }
        $awarded = self::award_stamp($uid, $eid, 'station');
        if ($awarded === false) return new WP_Error('tix_already_stamped', 'Heute schon gestempelt.', ['status' => 409, 'points' => self::points($uid)]);
        if ($eid) { // auch den Tag sperren, damit ein Personal-Scan ohne Event heute nicht doppelt stempelt
            $done = get_user_meta($uid, self::mk('_tix_loyalty_stamped'), true); $done = is_array($done) ? $done : [];
            $k = self::stamp_key(0); if (!in_array($k, $done, true)) { $done[] = $k; update_user_meta($uid, self::mk('_tix_loyalty_stamped'), array_slice($done, -400)); }
        }
        return rest_ensure_response(['ok' => true, 'awarded' => $awarded, 'points' => self::points($uid), 'stamps_per_visit' => self::stamps_per_visit(), 'rewards' => self::reward_state($uid),
            'organizer_id' => self::$org ?: null, 'event' => $eid ? ['id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8')] : null, 'message' => 'Stempel erhalten']);
    }

    // ── Routen ────────────────────────────────────────────────────
    public static function register_routes() {
        $organizer = ['TIX_REST_API', 'check_organizer'];

        register_rest_route(self::NS, '/loyalty/config', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_config'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/loyalty/config', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_save_config'],
            'permission_callback' => $organizer,
        ]);
        register_rest_route(self::NS, '/loyalty/me', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_me'],
            'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route(self::NS, '/loyalty/cards', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_cards'],
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
        register_rest_route(self::NS, '/loyalty/station/token', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_station_token'],
            'permission_callback' => [__CLASS__, 'perm_station'],
        ]);
        register_rest_route(self::NS, '/loyalty/station/exit', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_station_exit'],
            'permission_callback' => [__CLASS__, 'perm_station'],
        ]);
        register_rest_route(self::NS, '/loyalty/claim', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_claim'],
            'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route(self::NS, '/loyalty/redeem', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_redeem'],
            'permission_callback' => $organizer,
        ]);
    }
}
