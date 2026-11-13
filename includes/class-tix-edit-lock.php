<?php
/**
 * Bearbeitungssperre je Event über alle Seiten (1.38.789). Vertrag `uebergaben/vertrag-bearbeitungssperre.md`.
 * User 13.11.: „Gleichzeitige Bearbeitungen gehen nicht – dann ist gesperrt und man sieht, dass ein anderer gerade bearbeitet.“
 *
 * Die Sperre liegt an der Quelle; Spiegel und Kopien reichen /lock-Aufrufe und das Token (X-Tix-Edit-Lock) über den Durchgriff
 * (TIX_Source_Lock) weiter. Halter = Person + Seite (aus dem Verlaufs-Kontext, auch hinter dem Durchgriff). Herzschlag verlängert,
 * ohne Herzschlag Ablauf nach tix_edit_lock_ttl (Vorgabe 300 s = 5 min, User 13.11.). Speichern bei fremder Sperre → 409 tix_event_locked mit Name;
 * im strengen Modus (tix_edit_lock_strict = 1) auch ohne Token → 409 tix_event_lock_required.
 */
if (!defined('ABSPATH')) exit;

class TIX_Edit_Lock {

    const NS = 'tixomat/v1';
    const DB_VERSION = '2';
    const OPT_DB = 'tix_edit_locks_db';
    const HEADER = 'X-Tix-Edit-Lock'; // gleichwertig: X-Tix-Lock, Body lock_token (Studio-Wunsch)
    /** Inhalts-Routen am Event, die die Sperre brauchen (leer = POST /events/{id}) */
    const CONTENT_SUB = '#^(|image|lineup-artist|lineup-photo|charity-image|sponsor-image|ticket-fields|history/\d+/restore)$#';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_pre_dispatch', [__CLASS__, 'check'], 8, 3);
        add_filter('rest_post_dispatch', [__CLASS__, 'list_locks'], 20, 3);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_edit_locks';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::table() . " (
            event_id bigint(20) unsigned NOT NULL,
            token char(40) NOT NULL,
            holder_key char(40) NOT NULL,
            name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            site varchar(190) NOT NULL DEFAULT '',
            site_name varchar(190) NOT NULL DEFAULT '',
            channel varchar(20) NOT NULL DEFAULT '',
            since datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (event_id)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    private static function ready() {
        static $ok = null;
        if ($ok) return true;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) self::install();
        return $ok = (get_option(self::OPT_DB) === self::DB_VERSION);
    }

    public static function ttl() {
        return max(60, min(600, intval(get_option('tix_edit_lock_ttl', 300))));
    }

    public static function strict() {
        return get_option('tix_edit_lock_strict', '0') === '1';
    }

    /** Wer bin ich (auch hinter dem Durchgriff): {key, name, email, site, site_name} */
    public static function me() {
        $c = class_exists('TIX_Event_History') ? TIX_Event_History::context() : [];
        $u = wp_get_current_user();
        $name = (string) ($c['actor_name'] ?? '') ?: ($u && $u->ID ? (string) $u->display_name : '');
        $email = (string) ($c['actor_email'] ?? '') ?: ($u && $u->ID ? (string) $u->user_email : '');
        $site = (string) ($c['site'] ?? '') ?: strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $site_name = (string) ($c['site_name'] ?? '') ?: html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $ch = (string) ($c['channel'] ?? '');
        return ['key' => sha1(strtolower($site) . '|' . strtolower($email !== '' ? $email : $name)), 'name' => $name !== '' ? $name : 'Unbekannt', 'email' => $email, 'site' => $site, 'site_name' => $site_name,
            'channel' => in_array($ch, ['studio', 'app', 'api'], true) ? $ch : 'studio'];
    }

    private static function row($eid) {
        global $wpdb;
        if (!self::ready()) return null;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE event_id = %d", intval($eid)), ARRAY_A);
        if ($r && strtotime($r['expires_at'] . ' UTC') < time()) { $wpdb->delete(self::table(), ['event_id' => intval($eid), 'token' => $r['token']]); return null; }
        return $r ?: null;
    }

    private static function holder($r) {
        return ['name' => (string) $r['name'], 'site' => (string) $r['site'], 'site_name' => (string) $r['site_name'], 'since' => self::iso($r['since']), 'channel' => (string) ($r['channel'] ?? '') ?: 'studio'];
    }

    private static function iso($dt) {
        return gmdate('c', strtotime($dt . ' UTC'));
    }

    private static function locked_error($r, $code = 'tix_event_locked') {
        $h = self::holder($r);
        $msg = $code === 'tix_event_lock_lost' ? 'Deine Bearbeitungssperre wurde aufgehoben.' : 'Wird gerade von ' . $h['name'] . ' (' . ($h['site_name'] ?: $h['site']) . ') bearbeitet.';
        return new WP_Error($code, $msg, ['status' => 409, 'holder' => $h, 'since' => self::iso($r['since']), 'expires_at' => self::iso($r['expires_at']), 'can_break' => self::can_break()]);
    }

    /**
     * Holen bzw. verlängern. → {ok, token, lock: {token, expires_at, ttl}, holder, since, expires_at, ttl} | WP_Error
     * $heartbeat: nur verlängern (Token nötig) – ist die Sperre abgelaufen und frei, wird sie mit demselben Token wieder gesetzt.
     * Mit Token, aber Sperre nicht mehr meine: 409 tix_event_lock_lost {by?, holder}; dieselbe Person in einem anderen Fenster:
     * 409 tix_event_lock_other_window. Ohne Token holt dieselbe Person die Sperre zu sich (neues Token, altes Fenster verliert sie).
     */
    public static function acquire($eid, $token = '', $heartbeat = false) {
        global $wpdb;
        if (!self::ready()) return new WP_Error('tix_lock_unavailable', 'Sperre nicht verfügbar.', ['status' => 503]);
        $eid = intval($eid);
        $me = self::me();
        $t = self::table();
        $now = time();
        $exp = gmdate('Y-m-d H:i:s', $now + self::ttl());
        $r = self::row($eid);
        if ($heartbeat && $token === '') return new WP_Error('tix_lock_token_missing', 'Token fehlt.', ['status' => 400]);
        if ($r && $token !== '' && hash_equals($r['token'], sha1($token))) {
            $wpdb->update($t, ['expires_at' => $exp], ['event_id' => $eid]);
            return self::ok($token, $r, $exp);
        }
        if ($token !== '' && ($lost = get_transient('tix_lock_broken_' . $eid . '_' . sha1($token)))) {
            // Sperre wurde gelöst – auch wenn inzwischen jemand anderes sie hält, zuerst diesen Grund nennen
            return new WP_Error('tix_event_lock_lost', 'Deine Bearbeitungssperre wurde von ' . $lost . ' aufgehoben.', ['status' => 409, 'by' => $lost, 'holder' => $r ? self::holder($r) : null, 'can_break' => self::can_break()]);
        }
        if ($r && $token !== '') {
            if ($r['holder_key'] === $me['key']) return new WP_Error('tix_event_lock_other_window', 'Du bearbeitest dieses Event inzwischen in einem anderen Fenster.', ['status' => 409, 'holder' => self::holder($r), 'expires_at' => self::iso($r['expires_at']), 'can_break' => self::can_break()]);
            return self::locked_error($r, 'tix_event_lock_lost');
        }
        if ($r && $r['holder_key'] !== $me['key']) return self::locked_error($r);
        // frei (bzw. meine Sperre ohne Token): setzen; Herzschlag nach Ablauf behält sein Token
        $new = ($token !== '' && !$r) ? $token : wp_generate_password(32, false, false);
        $since = $r ? $r['since'] : gmdate('Y-m-d H:i:s', $now);
        if ($r) {
            $wpdb->update($t, ['token' => sha1($new), 'expires_at' => $exp, 'name' => $me['name'], 'email' => $me['email'], 'site' => $me['site'], 'site_name' => $me['site_name'], 'channel' => $me['channel']], ['event_id' => $eid, 'holder_key' => $me['key']]);
        } else {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (event_id, token, holder_key, name, email, site, site_name, channel, since, expires_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
                $eid, sha1($new), $me['key'], $me['name'], $me['email'], $me['site'], $me['site_name'], $me['channel'], $since, $exp));
        }
        $r = self::row($eid);
        if (!$r || !hash_equals($r['token'], sha1($new))) return $r ? self::locked_error($r) : new WP_Error('tix_lock_failed', 'Sperre konnte nicht gesetzt werden.', ['status' => 500]);
        return self::ok($new, $r, $exp);
    }

    private static function ok($token, $r, $exp) {
        return ['ok' => true, 'token' => $token, 'lock' => ['token' => $token, 'expires_at' => self::iso($exp), 'ttl' => self::ttl()],
            'holder' => self::holder($r), 'since' => self::iso($r['since']), 'expires_at' => self::iso($exp), 'ttl' => self::ttl()];
    }

    public static function release($eid, $token) {
        global $wpdb;
        if (!self::ready() || $token === '') return ['ok' => true, 'released' => false];
        $n = $wpdb->delete(self::table(), ['event_id' => intval($eid), 'token' => sha1($token)]);
        return ['ok' => true, 'released' => (bool) $n];
    }

    public static function status($eid, $token = '') {
        $r = self::row($eid);
        if (!$r) return ['locked' => false, 'mine' => false, 'holder' => null, 'since' => null, 'expires_at' => null, 'can_break' => false, 'ttl' => self::ttl()];
        $mine = ($token !== '' && hash_equals($r['token'], sha1($token))) || $r['holder_key'] === self::me()['key'];
        return ['locked' => true, 'mine' => $mine, 'holder' => self::holder($r), 'since' => self::iso($r['since']), 'expires_at' => self::iso($r['expires_at']),
            'can_break' => !$mine && self::can_break(), 'ttl' => self::ttl()];
    }

    /** Für GET /events/{id}/edit: Status der eigenen Sperre (Spiegel/Kopie: null – Studio fragt GET …/lock, das an die Quelle geht). */
    public static function editor_field($eid) {
        if (class_exists('TIX_Source_Lock') && TIX_Source_Lock::source_of($eid)) return null;
        return self::status($eid);
    }

    /** Für Event-Listen: [event_id => {name, site_name, since}] der aktiven Sperren */
    public static function locked_by(array $ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !self::ready()) return [];
        $rows = $wpdb->get_results("SELECT event_id, name, site_name, site, since FROM " . self::table() . " WHERE event_id IN (" . implode(',', $ids) . ") AND expires_at > '" . gmdate('Y-m-d H:i:s') . "'", ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) $out[intval($r['event_id'])] = ['name' => (string) $r['name'], 'site_name' => (string) ($r['site_name'] ?: $r['site']), 'since' => self::iso($r['since'])];
        return $out;
    }

    /** Lösen: Admins dieser Seite und der evendis-Betreiber (über den Durchgriff). */
    public static function can_break() {
        return current_user_can('manage_options') || get_user_meta(get_current_user_id(), '_tix_platform_operator', true) === '1';
    }

    public static function break_lock($eid) {
        global $wpdb;
        $r = self::row($eid);
        if (!$r) return ['ok' => true, 'released' => null];
        $me = self::me();
        $by = $me['name'] . ' (' . ($me['site_name'] ?: $me['site']) . ')';
        set_transient('tix_lock_broken_' . intval($eid) . '_' . $r['token'], $by, DAY_IN_SECONDS);
        $wpdb->delete(self::table(), ['event_id' => intval($eid)]);
        if (class_exists('TIX_Customers')) TIX_Customers::audit('event_lock_break', (string) $eid, ['scope' => 'site', 'oid' => 0], ['holder' => self::holder($r), 'by' => $by]);
        return ['ok' => true, 'released' => ['holder' => self::holder($r), 'since' => self::iso($r['since'])]];
    }

    // ═══════════════ Prüfen beim Speichern ═══════════════

    public static function check($result, $server, $request) {
        if ($result !== null) return $result;
        if (!in_array($request->get_method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return $result;
        if (!preg_match('#^/tixomat/v1/(?:studio/)?events/(\d+)(?:/(.+))?$#', $request->get_route(), $m)) return $result;
        $sub = $m[2] ?? '';
        if (!preg_match(self::CONTENT_SUB, $sub)) return $result;
        if ($sub === '' && $request->get_method() === 'DELETE') return $result;
        $eid = intval($m[1]);
        if (get_post_type($eid) !== 'event') return $result;
        if (class_exists('TIX_Source_Lock') && TIX_Source_Lock::source_of($eid)) return $result; // Spiegel/Kopie: prüft die Quelle (Durchgriff)
        $token = self::request_token($request);
        $r = self::row($eid);
        if ($r) {
            if ($token !== '' && hash_equals($r['token'], sha1($token))) {
                global $wpdb;
                $wpdb->update(self::table(), ['expires_at' => gmdate('Y-m-d H:i:s', time() + self::ttl())], ['event_id' => $eid]);
                return $result;
            }
            if ($r['holder_key'] === self::me()['key']) {
                if ($token === '') return $result; // dieselbe Person, älterer Client ohne Token
                return new WP_Error('tix_event_lock_other_window', 'Du bearbeitest dieses Event inzwischen in einem anderen Fenster – hier wird nichts gespeichert.', ['status' => 409, 'holder' => self::holder($r)]);
            }
            return self::locked_error($r);
        }
        if (self::strict()) return new WP_Error('tix_event_lock_required', 'Bitte das Event zum Bearbeiten öffnen (Bearbeitungssperre fehlt).', ['status' => 409]);
        return $result;
    }

    /** GET /events (auch /studio/events): je Event `locked_by` {name, site_name, since} oder null – nur eigene Sperren dieser Seite. */
    public static function list_locks($response, $server, $request) {
        if ($request->get_method() !== 'GET' || !preg_match('#^/tixomat/v1/(?:studio/)?events/?$#', $request->get_route())) return $response;
        if (!($response instanceof WP_REST_Response) || $response->get_status() !== 200) return $response;
        $d = $response->get_data();
        $key = null;
        if (is_array($d) && isset($d['events']) && is_array($d['events'])) $key = 'events';
        elseif (is_array($d) && isset($d['items']) && is_array($d['items'])) $key = 'items';
        $list = $key ? $d[$key] : $d;
        if (!is_array($list) || !$list || !array_is_list($list)) return $response;
        $ids = [];
        foreach ($list as $e) if (is_array($e) && !empty($e['id'])) $ids[] = intval($e['id']);
        if (!$ids) return $response;
        $locks = self::locked_by($ids);
        foreach ($list as $i => $e) if (is_array($e) && !empty($e['id'])) $list[$i]['locked_by'] = $locks[intval($e['id'])] ?? null;
        if ($key) $d[$key] = $list; else $d = $list;
        $response->set_data($d);
        return $response;
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        $p = ['permission_callback' => [__CLASS__, 'perm'], 'args' => []];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/lock', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_status']] + $p,
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_acquire']] + $p,
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_release']] + $p,
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/lock/release', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_release']] + $p);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/lock/heartbeat', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_heartbeat']] + $p);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/lock/break', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_break']] + $p);
    }

    public static function perm(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('tix_not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    /** Token aus dem Body ({token} oder {lock_token}; JSON, Formular oder text/plain per sendBeacon) bzw. der Kopfzeile. */
    public static function token_of(WP_REST_Request $req) {
        foreach ([(array) $req->get_json_params(), (array) $req->get_body_params()] as $b) {
            foreach (['token', 'lock_token'] as $k) if (!empty($b[$k]) && is_string($b[$k])) return self::clean($b[$k]);
        }
        $raw = trim((string) $req->get_body());
        if ($raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) { foreach (['token', 'lock_token'] as $k) if (!empty($j[$k]) && is_string($j[$k])) return self::clean($j[$k]); }
            elseif (preg_match('/^[A-Za-z0-9]{16,64}$/', $raw)) return $raw;
            else { parse_str($raw, $f); foreach (['token', 'lock_token'] as $k) if (!empty($f[$k]) && is_string($f[$k])) return self::clean($f[$k]); }
        }
        return self::request_token($req, false);
    }

    /** Token beim Speichern: X-Tix-Edit-Lock, X-Tix-Lock, Body/Formularfeld lock_token oder ?lock_token= */
    public static function request_token(WP_REST_Request $req, $body = true) {
        foreach (['x_tix_edit_lock', 'x_tix_lock'] as $h) { $v = (string) $req->get_header($h); if ($v !== '') return self::clean($v); }
        if ($body) {
            // JSON-Body oder Formularfeld (Multipart-Upload, z. B. POST /events/{id}/image – Fund App 13.11.)
            foreach ([$req->get_json_params(), $req->get_body_params(), $req->get_query_params()] as $b) {
                if (is_array($b) && !empty($b['lock_token']) && is_string($b['lock_token'])) return self::clean($b['lock_token']);
            }
        }
        return '';
    }

    private static function clean($t) {
        return substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $t), 0, 64);
    }

    public static function rest_status(WP_REST_Request $req) {
        return rest_ensure_response(self::status(absint($req['id']), self::token_of($req)));
    }

    public static function rest_acquire(WP_REST_Request $req) {
        $r = self::acquire(absint($req['id']), self::token_of($req));
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }

    public static function rest_heartbeat(WP_REST_Request $req) {
        $r = self::acquire(absint($req['id']), self::token_of($req), true);
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }

    public static function rest_release(WP_REST_Request $req) {
        return rest_ensure_response(self::release(absint($req['id']), self::token_of($req)));
    }

    public static function rest_break(WP_REST_Request $req) {
        if (!self::can_break()) return new WP_Error('rest_forbidden', 'Eine fremde Sperre lösen dürfen nur Admins.', ['status' => 403]);
        return rest_ensure_response(self::break_lock(absint($req['id'])));
    }
}
