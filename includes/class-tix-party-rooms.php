<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Party_Rooms – Partyspiel „Mehrere Handys“ (Raum-Code), Server-Teil (1.38.430, alle Seiten; Vertrag
 * uebergaben/kitchenklub-app.md, Paket 4).
 *
 * Ohne Konto, nur Spitznamen. Gespeichert werden je Raum Code, Zähler `rev`, Status, Spitznamen, Punkte/Strafen,
 * die vom Gastgeber gesendete Karte (opak, ≤ 4 KB) und die Stimmen – keine IP, keine Geräte-ID. Rate-Limits je IP
 * laufen über flüchtige Transients mit HMAC der IP.
 *
 * Speicher: zwei eigene Tabellen (statt Transients), damit das Abfragen im Takt billig bleibt:
 *   {prefix}tix_party_rooms   code (PK), rev, status, data (JSON ≤ 16 KB), created_at, active_at, expires_at, ended_at
 *   {prefix}tix_party_players token_hash (PK, SHA-256 des Tokens), code, player_id, seen_ms
 * `GET /party/rooms/{code}?since=rev` = eine PK-Abfrage (JOIN über den Token-Hash) + ein PK-UPDATE, das zugleich den
 * Mindestabstand von 500 ms prüft; bei gleichem `rev` 304 ohne Inhalt, das Raum-JSON wird dann nicht gelesen.
 * Änderungen schreiben optimistisch (UPDATE … WHERE rev = alt, bei Kollision bis zu 10 Versuche mit zufälliger Pause).
 *
 * Lebenszeit: 6 h nach der letzten Aktivität, höchstens 12 h nach dem Erstellen; beendete Räume 10 Minuten.
 * Cron `tix_party_rooms_cleanup` stündlich (plus einmalig 10 Minuten nach dem Beenden) löscht Räume samt Tokens.
 *
 * Schalter: Option `tix_party` {enabled} (Standard an), Tixomat → Partyspiel. Aus → /party/config enabled:false,
 * alle anderen Routen 404 `party_disabled`.
 */
class TIX_Party_Rooms {

    const NS        = 'tixomat/v1';
    const OPT       = 'tix_party';
    const DB_VER    = '1';
    const CRON      = 'tix_party_rooms_cleanup';
    const ALPHABET  = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    const MAX_PLAYERS = 12;
    const TTL       = 6 * HOUR_IN_SECONDS;
    const MAX_AGE   = 12 * HOUR_IN_SECONDS;
    const ENDED_KEEP = 10 * MINUTE_IN_SECONDS;
    const POLL_MS   = 1500;
    const MIN_POLL_MS = 500;
    const MAX_ROOM  = 16384;
    const MAX_TURN  = 4096;
    const TYPES     = ['task', 'who', 'truth', 'group', 'vote', 'rule', 'quiz'];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 9);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action(self::CRON, [__CLASS__, 'cleanup']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Party_Rooms::CRON)) wp_schedule_event(time() + 600, 'hourly', TIX_Party_Rooms::CRON);
        }, 25);
        add_action('admin_menu', [__CLASS__, 'menu'], 47);
        add_action('admin_post_tix_party', [__CLASS__, 'save_admin']);
    }

    // ── Einstellungen / Tabellen ──

    public static function enabled() {
        $o = get_option(self::OPT, []);
        return !is_array($o) || !array_key_exists('enabled', $o) || !empty($o['enabled']);
    }

    private static function t_rooms() { global $wpdb; return $wpdb->prefix . 'tix_party_rooms'; }
    private static function t_players() { global $wpdb; return $wpdb->prefix . 'tix_party_players'; }

    public static function maybe_install() {
        if (get_option('tix_party_db') === self::DB_VER) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t_rooms() . " (
            code char(5) NOT NULL,
            rev int(10) unsigned NOT NULL DEFAULT 0,
            status varchar(8) NOT NULL DEFAULT 'lobby',
            data mediumtext NOT NULL,
            created_at int(10) unsigned NOT NULL DEFAULT 0,
            active_at int(10) unsigned NOT NULL DEFAULT 0,
            expires_at int(10) unsigned NOT NULL DEFAULT 0,
            ended_at int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (code),
            KEY expires_at (expires_at)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t_players() . " (
            token_hash char(64) NOT NULL,
            code char(5) NOT NULL,
            player_id varchar(8) NOT NULL,
            seen_ms bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (token_hash),
            KEY code (code)
        ) $c;");
        update_option('tix_party_db', self::DB_VER, false);
    }

    // ── Hilfen ──

    private static function err($code, $msg, $status) {
        return new WP_Error($code, $msg, ['status' => $status]);
    }

    private static function no_store(WP_REST_Response $r) {
        $r->header('Cache-Control', 'no-store');
        $r->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $r;
    }

    private static function out($data, $status = 200) {
        return self::no_store(new WP_REST_Response($data, $status));
    }

    private static function now_ms() {
        return (int) floor(microtime(true) * 1000);
    }

    private static function hash_token($t) {
        $t = trim((string) $t);
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $t) ? hash('sha256', $t) : '';
    }

    private static function new_token() {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function norm_code($c) {
        $c = strtoupper(trim((string) $c));
        return preg_match('/^[' . self::ALPHABET . ']{5}$/', $c) ? $c : '';
    }

    /** Flüchtiges Rate-Limit je IP (HMAC der IP nur im Transient-Schlüssel, nichts Dauerhaftes). */
    private static function limited($bucket, $max, $window) {
        $ip = class_exists('TIX_Client_IP') ? TIX_Client_IP::get() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $key = 'tix_party_rl_' . $bucket . '_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 32);
        $d = get_transient($key);
        if (!is_array($d) || time() - intval($d['first'] ?? 0) >= $window) $d = ['count' => 0, 'first' => time()];
        $d['count']++;
        set_transient($key, $d, $window);
        return $d['count'] > $max;
    }

    private static function nickname($raw) {
        $n = trim(sanitize_text_field((string) $raw));
        $len = mb_strlen($n);
        if ($len < 1 || $len > 20) return self::err('party_nickname_invalid', 'Bitte einen Spitznamen mit 1 bis 20 Zeichen.', 422);
        return $n;
    }

    private static function expires($created, $active) {
        return min($active + self::TTL, $created + self::MAX_AGE);
    }

    private static function gone($row) {
        return $row['status'] !== 'ended' && intval($row['expires_at']) < time();
    }

    private static function load($code) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t_rooms() . " WHERE code = %s", $code), ARRAY_A);
        if (!$row) return null;
        $row['d'] = json_decode((string) $row['data'], true);
        if (!is_array($row['d'])) $row['d'] = ['host_id' => '', 'next' => 1, 'players' => [], 'turn' => null, 'votes' => []];
        return $row;
    }

    /** Raum für einen Token (Spieler) prüfen: [row, player_id] oder WP_Error. */
    private static function member(WP_REST_Request $req, $code, $host_only = false) {
        $row = self::load($code);
        if (!$row) return self::err('party_room_not_found', 'Diesen Raum gibt es nicht (mehr).', 404);
        if (self::gone($row)) return self::err('party_room_expired', 'Der Raum ist abgelaufen.', 410);
        $pid = self::player_for($req, $code);
        if ($pid === '') return self::err('party_forbidden', 'Du bist nicht in diesem Raum.', 403);
        if ($host_only && $pid !== (string) $row['d']['host_id']) return self::err('party_forbidden', 'Nur der Gastgeber kann das.', 403);
        return [$row, $pid];
    }

    private static function player_for(WP_REST_Request $req, $code) {
        global $wpdb;
        $h = self::hash_token($req->get_header('x_party_token'));
        if ($h === '') return '';
        return (string) $wpdb->get_var($wpdb->prepare("SELECT player_id FROM " . self::t_players() . " WHERE token_hash = %s AND code = %s", $h, $code));
    }

    /**
     * Änderung am Raum (optimistisch): $fn(&$d, $row) ändert die Daten bzw. liefert WP_Error, optional ['status' => …].
     * Liefert die neue Zeile oder WP_Error.
     */
    private static function mutate($code, callable $fn) {
        global $wpdb;
        for ($try = 0; $try < 10; $try++) {
            $row = self::load($code);
            if (!$row) return self::err('party_room_not_found', 'Diesen Raum gibt es nicht (mehr).', 404);
            if (self::gone($row)) return self::err('party_room_expired', 'Der Raum ist abgelaufen.', 410);
            $d = $row['d'];
            $res = $fn($d, $row);
            if (is_wp_error($res)) return $res;
            $status = is_array($res) && isset($res['status']) ? $res['status'] : $row['status'];
            $json = wp_json_encode($d);
            if (strlen($json) > self::MAX_ROOM) return self::err('party_room_too_large', 'Der Raum ist zu groß.', 413);
            $now = time();
            $n = $wpdb->query($wpdb->prepare(
                "UPDATE " . self::t_rooms() . " SET data = %s, rev = rev + 1, status = %s, active_at = %d, expires_at = %d, ended_at = %d WHERE code = %s AND rev = %d",
                $json, $status, $now, self::expires(intval($row['created_at']), $now), $status === 'ended' ? ($row['ended_at'] ?: $now) : 0, $code, intval($row['rev'])
            ));
            if ($n === 1) {
                if ($status === 'ended' && $row['status'] !== 'ended') wp_schedule_single_event($now + self::ENDED_KEEP + 30, self::CRON, ['ended-' . $code]);
                return self::load($code);
            }
            usleep(random_int(5000, 20000) * ($try + 1)); // Kollision (viele Stimmen gleichzeitig): kurz warten, neu lesen
        }
        return self::err('party_busy', 'Gerade viel los – bitte nochmal.', 409);
    }

    /** Raum-JSON wie im Vertrag (aus Sicht von $pid). */
    private static function view(array $row, $pid) {
        $d = $row['d'];
        $players = [];
        foreach ((array) $d['players'] as $p) {
            $players[] = ['id' => (string) $p['id'], 'nickname' => (string) $p['nickname'], 'points' => intval($p['points'] ?? 0),
                'strafen' => intval($p['strafen'] ?? 0), 'host' => (string) $p['id'] === (string) $d['host_id']];
        }
        $turn = null;
        if (is_array($d['turn'] ?? null)) {
            $turn = $d['turn'];
            $counts = [];
            foreach ((array) ($d['votes'] ?? []) as $target) $counts[(string) $target] = ($counts[(string) $target] ?? 0) + 1;
            $turn['counts'] = (object) $counts;
            $turn['my_vote'] = isset($d['votes'][$pid]) ? (string) $d['votes'][$pid] : null;
        }
        return [
            'code'       => (string) $row['code'],
            'rev'        => intval($row['rev']),
            'status'     => (string) $row['status'],
            'host_id'    => (string) $d['host_id'],
            'players'    => $players,
            'turn'       => $turn,
            'expires_at' => gmdate('c', $row['status'] === 'ended' ? intval($row['ended_at']) + self::ENDED_KEEP : intval($row['expires_at'])),
        ];
    }

    private static function room_out(array $row, $pid, $status = 200) {
        return self::out(['room' => self::view($row, $pid)], $status);
    }

    // ── REST ──

    public static function register_routes() {
        $code = '(?P<code>[A-Za-z0-9]{5})';
        $r = function ($path, $method, $cb) {
            register_rest_route(self::NS, $path, ['methods' => $method, 'callback' => [__CLASS__, $cb], 'permission_callback' => '__return_true']);
        };
        $r('/party/config', 'GET', 'rest_config');
        $r('/party/rooms', 'POST', 'rest_create');
        $r("/party/rooms/$code/join", 'POST', 'rest_join');
        register_rest_route(self::NS, "/party/rooms/$code", [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => '__return_true'],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_end'], 'permission_callback' => '__return_true'],
        ]);
        foreach (['start', 'turn', 'vote', 'score', 'leave'] as $a) $r("/party/rooms/$code/$a", 'POST', 'rest_' . $a);
    }

    private static function off() {
        return self::enabled() ? null : self::err('party_disabled', 'Das Partyspiel mit mehreren Handys ist hier nicht aktiv.', 404);
    }

    /** GET /party/config */
    public static function rest_config() {
        $r = new WP_REST_Response(['enabled' => self::enabled(), 'max_players' => self::MAX_PLAYERS, 'ttl_hours' => self::TTL / HOUR_IN_SECONDS, 'poll_ms' => self::POLL_MS]);
        $r->header('Cache-Control', 'public, max-age=60');
        return $r;
    }

    private static function add_player(array &$d, $code, $nick) {
        global $wpdb;
        $token = self::new_token();
        $pid = 'p' . intval($d['next'] ?? 1);
        $d['next'] = intval($d['next'] ?? 1) + 1;
        $d['players'][] = ['id' => $pid, 'nickname' => $nick, 'points' => 0, 'strafen' => 0];
        $wpdb->insert(self::t_players(), ['token_hash' => hash('sha256', $token), 'code' => $code, 'player_id' => $pid, 'seen_ms' => 0]);
        return [$pid, $token];
    }

    /** POST /party/rooms {nickname} → 201 {player_id, player_token, room} */
    public static function rest_create(WP_REST_Request $req) {
        global $wpdb;
        if ($e = self::off()) return $e;
        $nick = self::nickname($req->get_param('nickname'));
        if (is_wp_error($nick)) return $nick;
        if (self::limited('create', 5, HOUR_IN_SECONDS)) return self::err('party_rate_limited', 'Zu viele neue Räume. Bitte später nochmal.', 429);
        $now = time();
        $code = '';
        for ($i = 0; $i < 12 && $code === ''; $i++) {
            $c = '';
            for ($k = 0; $k < 5; $k++) $c .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            $ok = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO " . self::t_rooms() . " (code, rev, status, data, created_at, active_at, expires_at) VALUES (%s, 0, 'lobby', '{}', %d, %d, %d)",
                $c, $now, $now, self::expires($now, $now)
            ));
            if ($ok === 1) $code = $c;
        }
        if ($code === '') return self::err('party_busy', 'Gerade kein freier Raum – bitte nochmal.', 503);
        $d = ['host_id' => '', 'next' => 1, 'players' => [], 'turn' => null, 'votes' => []];
        list($pid, $token) = self::add_player($d, $code, $nick);
        $d['host_id'] = $pid;
        $wpdb->update(self::t_rooms(), ['data' => wp_json_encode($d), 'rev' => 1], ['code' => $code]);
        $row = self::load($code);
        return self::out(['player_id' => $pid, 'player_token' => $token, 'room' => self::view($row, $pid)], 201);
    }

    /** POST /party/rooms/{code}/join {nickname} */
    public static function rest_join(WP_REST_Request $req) {
        if ($e = self::off()) return $e;
        if (self::limited('join', 20, MINUTE_IN_SECONDS)) return self::err('party_rate_limited', 'Zu viele Versuche. Bitte kurz warten.', 429);
        $code = self::norm_code($req['code']);
        if ($code === '') return self::err('party_room_not_found', 'Diesen Raum gibt es nicht (mehr).', 404);
        $nick = self::nickname($req->get_param('nickname'));
        if (is_wp_error($nick)) return $nick;
        $new = null;
        $row = self::mutate($code, function (&$d, $row) use ($code, $nick, &$new) {
            if ($row['status'] === 'ended') return self::err('party_room_expired', 'Der Raum ist beendet.', 410);
            if (count((array) $d['players']) >= self::MAX_PLAYERS) return self::err('party_room_full', 'Der Raum ist voll.', 409);
            foreach ((array) $d['players'] as $p) {
                if (mb_strtolower((string) $p['nickname']) === mb_strtolower($nick)) return self::err('party_nickname_taken', 'Diesen Spitznamen gibt es im Raum schon.', 409);
            }
            if ($new) { // zweiter Versuch nach Kollision: alten Token wieder entfernen
                global $wpdb;
                $wpdb->delete(self::t_players(), ['token_hash' => hash('sha256', $new[1])]);
            }
            $new = self::add_player($d, $code, $nick);
            return null;
        });
        if (is_wp_error($row)) {
            if ($new) { global $wpdb; $wpdb->delete(self::t_players(), ['token_hash' => hash('sha256', $new[1])]); }
            return $row;
        }
        return self::out(['player_id' => $new[0], 'player_token' => $new[1], 'room' => self::view($row, $new[0])]);
    }

    /** GET /party/rooms/{code}?since=rev – der Takt der Handys; billig halten. */
    public static function rest_get(WP_REST_Request $req) {
        global $wpdb;
        if ($e = self::off()) return $e;
        $code = self::norm_code($req['code']);
        if ($code === '') return self::err('party_room_not_found', 'Diesen Raum gibt es nicht (mehr).', 404);
        $h = self::hash_token($req->get_header('x_party_token'));
        $hit = $h === '' ? null : $wpdb->get_row($wpdb->prepare(
            "SELECT p.player_id, p.code AS pcode, r.rev, r.status, r.created_at, r.active_at, r.expires_at FROM " . self::t_players() . " p JOIN " . self::t_rooms() . " r ON r.code = p.code WHERE p.token_hash = %s",
            $h
        ), ARRAY_A);
        if (!$hit || $hit['pcode'] !== $code) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT status, expires_at FROM " . self::t_rooms() . " WHERE code = %s", $code), ARRAY_A);
            if (!$row) return self::err('party_room_not_found', 'Diesen Raum gibt es nicht (mehr).', 404);
            if (self::gone($row)) return self::err('party_room_expired', 'Der Raum ist abgelaufen.', 410);
            return self::err('party_forbidden', 'Du bist nicht in diesem Raum.', 403);
        }
        if (self::gone($hit)) return self::err('party_room_expired', 'Der Raum ist abgelaufen.', 410);
        // Mindestabstand je Token: Prüfen und Merken in einem PK-UPDATE
        $now = self::now_ms();
        $ok = $wpdb->query($wpdb->prepare("UPDATE " . self::t_players() . " SET seen_ms = %d WHERE token_hash = %s AND seen_ms <= %d", $now, $h, $now - self::MIN_POLL_MS));
        if ($ok !== 1) return self::err('party_rate_limited', 'Bitte etwas langsamer abfragen.', 429);
        // Jede gültige Abfrage verlängert die Lebenszeit (geschrieben höchstens einmal pro Minute)
        $t = time();
        if ($hit['status'] !== 'ended' && intval($hit['active_at']) < $t - 60) {
            $wpdb->query($wpdb->prepare("UPDATE " . self::t_rooms() . " SET active_at = %d, expires_at = %d WHERE code = %s", $t, self::expires(intval($hit['created_at']), $t), $code));
        }
        $since = $req->get_param('since');
        if ($since !== null && $since !== '' && intval($since) === intval($hit['rev'])) {
            return self::no_store(new WP_REST_Response(null, 304));
        }
        $row = self::load($code);
        return $row ? self::room_out($row, (string) $hit['player_id']) : self::err('party_room_not_found', 'Diesen Raum gibt es nicht (mehr).', 404);
    }

    /** POST …/start (Gastgeber) */
    public static function rest_start(WP_REST_Request $req) {
        if ($e = self::off()) return $e;
        $code = self::norm_code($req['code']);
        $m = self::member($req, $code, true);
        if (is_wp_error($m)) return $m;
        $row = self::mutate($code, function (&$d, $row) {
            if ($row['status'] === 'ended') return self::err('party_room_expired', 'Der Raum ist beendet.', 410);
            return ['status' => 'playing'];
        });
        return is_wp_error($row) ? $row : self::room_out($row, $m[1]);
    }

    /** POST …/turn (Gastgeber): Karte opak ersetzen, Stimmen löschen. */
    public static function rest_turn(WP_REST_Request $req) {
        if ($e = self::off()) return $e;
        $code = self::norm_code($req['code']);
        $m = self::member($req, $code, true);
        if (is_wp_error($m)) return $m;
        $turn = $req->get_json_params();
        if (!is_array($turn)) $turn = $req->get_body_params();
        unset($turn['counts'], $turn['my_vote']);
        $bad = !isset($turn['n']) || !is_numeric($turn['n']) || !in_array($turn['type'] ?? '', self::TYPES, true) || !is_string($turn['text'] ?? null)
            || (isset($turn['players']) && !is_array($turn['players'])) || strlen((string) wp_json_encode($turn)) > self::MAX_TURN;
        if ($bad) return self::err('party_turn_invalid', 'Diese Karte kann der Raum nicht übernehmen.', 422);
        $turn['n'] = intval($turn['n']);
        $turn['vote'] = !empty($turn['vote']);
        $row = self::mutate($code, function (&$d, $row) use ($turn) {
            if ($row['status'] === 'ended') return self::err('party_room_expired', 'Der Raum ist beendet.', 410);
            $d['turn'] = $turn;
            $d['votes'] = [];
            return ['status' => 'playing'];
        });
        return is_wp_error($row) ? $row : self::room_out($row, $m[1]);
    }

    /** POST …/vote {n, target} – jedes Handy, eine Stimme je Karte. */
    public static function rest_vote(WP_REST_Request $req) {
        if ($e = self::off()) return $e;
        $code = self::norm_code($req['code']);
        $m = self::member($req, $code);
        if (is_wp_error($m)) return $m;
        $pid = $m[1];
        $n = intval($req->get_param('n'));
        $target = (string) $req->get_param('target');
        $row = self::mutate($code, function (&$d, $row) use ($pid, $n, $target) {
            if ($row['status'] === 'ended') return self::err('party_room_expired', 'Der Raum ist beendet.', 410);
            $t = $d['turn'] ?? null;
            if (!is_array($t) || intval($t['n'] ?? -1) !== $n || empty($t['vote'])) return self::err('party_vote_closed', 'Die Abstimmung ist vorbei.', 409);
            if (isset($d['votes'][$pid])) return self::err('party_already_voted', 'Du hast schon abgestimmt.', 409);
            if (!in_array($target, array_map('strval', array_column((array) $d['players'], 'id')), true)) return self::err('party_vote_invalid', 'Diese Person ist nicht im Raum.', 422);
            if (!is_array($d['votes'] ?? null) || !$d['votes']) $d['votes'] = [];
            $d['votes'][$pid] = $target;
            return null;
        });
        return is_wp_error($row) ? $row : self::room_out($row, $pid);
    }

    /** POST …/score (Gastgeber) {points:{id:n}, strafen:{id:n}} – ersetzt die Stände. */
    public static function rest_score(WP_REST_Request $req) {
        if ($e = self::off()) return $e;
        $code = self::norm_code($req['code']);
        $m = self::member($req, $code, true);
        if (is_wp_error($m)) return $m;
        $points = (array) $req->get_param('points');
        $strafen = (array) $req->get_param('strafen');
        $row = self::mutate($code, function (&$d, $row) use ($points, $strafen) {
            if ($row['status'] === 'ended') return self::err('party_room_expired', 'Der Raum ist beendet.', 410);
            foreach ($d['players'] as &$p) {
                $p['points'] = max(-99999, min(99999, intval($points[$p['id']] ?? 0)));
                $p['strafen'] = max(0, min(99999, intval($strafen[$p['id']] ?? 0)));
            }
            unset($p);
            return null;
        });
        return is_wp_error($row) ? $row : self::room_out($row, $m[1]);
    }

    /** POST …/leave – Spieler entfernen; Gastgeber = Raum beenden. */
    public static function rest_leave(WP_REST_Request $req) {
        global $wpdb;
        if ($e = self::off()) return $e;
        $code = self::norm_code($req['code']);
        $m = self::member($req, $code);
        if (is_wp_error($m)) return $m;
        $pid = $m[1];
        if ($pid === (string) $m[0]['d']['host_id']) return self::rest_end($req);
        $row = self::mutate($code, function (&$d) use ($pid) {
            $d['players'] = array_values(array_filter((array) $d['players'], function ($p) use ($pid) { return (string) $p['id'] !== $pid; }));
            unset($d['votes'][$pid]);
            return null;
        });
        if (is_wp_error($row)) return $row;
        $wpdb->delete(self::t_players(), ['token_hash' => self::hash_token($req->get_header('x_party_token'))]);
        return self::out(['ok' => true]);
    }

    /** DELETE /party/rooms/{code} (Gastgeber) → beendet, nach 10 Minuten gelöscht. */
    public static function rest_end(WP_REST_Request $req) {
        if ($e = self::off()) return $e;
        $code = self::norm_code($req['code']);
        $m = self::member($req, $code, true);
        if (is_wp_error($m)) return $m;
        if ($m[0]['status'] === 'ended') return self::out(['ok' => true]);
        $row = self::mutate($code, function () { return ['status' => 'ended']; });
        return is_wp_error($row) ? $row : self::out(['ok' => true]);
    }

    // ── Aufräumen ──

    /** Abgelaufene und seit 10 Minuten beendete Räume samt Tokens löschen. */
    public static function cleanup() {
        global $wpdb;
        $now = time();
        $r = self::t_rooms(); $p = self::t_players();
        $n = intval($wpdb->query($wpdb->prepare("DELETE FROM $r WHERE (status <> 'ended' AND expires_at < %d) OR (status = 'ended' AND ended_at < %d)", $now, $now - self::ENDED_KEEP)));
        $wpdb->query("DELETE p FROM $p p LEFT JOIN $r r ON r.code = p.code WHERE r.code IS NULL");
        return $n;
    }

    // ── Admin ──

    public static function menu() {
        add_submenu_page('tixomat', 'Partyspiel', 'Partyspiel', 'manage_options', 'tix-party', [__CLASS__, 'render_admin']);
    }

    public static function save_admin() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_party_admin');
        update_option(self::OPT, ['enabled' => !empty($_POST['enabled']) ? 1 : 0], false);
        wp_safe_redirect(admin_url('admin.php?page=tix-party&saved=1'));
        exit;
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        self::maybe_install();
        $open = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::t_rooms() . " WHERE status <> 'ended' AND expires_at >= %d", time())));
        $players = intval($wpdb->get_var("SELECT COUNT(*) FROM " . self::t_players()));
        ?>
        <div class="wrap"><h1>Partyspiel</h1>
            <?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <p>„Mehrere Handys“: Gäste spielen das Partyspiel der App zusammen in einem Raum mit 5-stelligem Code – ohne Konto, nur mit Spitznamen (höchstens <?php echo self::MAX_PLAYERS; ?> Handys). Räume verfallen 6 Stunden nach der letzten Aktivität, spätestens nach 12 Stunden, und werden dann vollständig gelöscht. Es werden keine IP-Adressen und keine Geräte-Kennungen gespeichert.</p>
            <p>Gerade offen: <strong><?php echo $open; ?></strong> Räume · <strong><?php echo $players; ?></strong> Handys</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tix_party_admin'); ?><input type="hidden" name="action" value="tix_party">
                <p><label><input type="checkbox" name="enabled" value="1" <?php checked(self::enabled()); ?>> „Mehrere Handys“ in der App anbieten</label></p>
                <p><button class="button button-primary">Speichern</button></p>
            </form>
        </div>
        <?php
    }
}
