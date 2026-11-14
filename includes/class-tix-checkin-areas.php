<?php
/**
 * TIX_Checkin_Areas – Einlassbereiche (User 14.11.2026; ab 1.38.815).
 *
 * Bedingung des Users: „wenn die bisherige Funktion weiterhin so bleibt, wie sie ist“. Deshalb:
 *  - Ohne angelegte Bereiche (bzw. ohne `area` beim Scan) läuft der Einlass exakt wie bisher – gleiche Antworten,
 *    gleiche Zählung, gleicher Wiedereinlass. Ein mitgeschicktes `area` ohne angelegte Bereiche wird ignoriert.
 *  - Die Bereichs-Logik greift nur mit `area` UND angelegten Bereichen des Bereichs (Seite bzw. evendis-Veranstalter).
 *
 * Bereiche je Seite (organizer_id 0) bzw. Veranstalter: Name, Reihenfolge, Kapazität (0 = unbegrenzt).
 * Je Ticket-Kategorie `areas` = erlaubte Bereichs-IDs ([] bzw. fehlend = überall, wie heute). Gäste der Gästeliste: überall.
 * Scan mit Bereich:
 *  - nicht erlaubt  → status invalid, area_denied, error tix_area_denied, „Kein Zutritt für diesen Bereich.“
 *  - Bereich voll   → status invalid, area_full, error tix_area_full (nur neue Einlässe; Wiedereinlass zählt nicht)
 *  - erster Einlass in den Bereich → status ok (ist das Ticket noch nicht eingecheckt, wird es dabei eingecheckt wie bisher)
 *  - erneut im selben Bereich → je Einstellung „doppelter Scan“: warn → already · block → already + block · reentry → reentry
 *    (Zähler je Bereich)
 * Zählung: Tabelle {prefix}tix_checkin_area_log (ein Eintrag je Ticket bzw. Gast und Bereich, entries = Einlässe).
 * Offline: Liste mit `areas` und je Ticket `ar` (erlaubte Bereiche) / `ain` (schon im Bereich), Abgleich mit `area` je Eintrag.
 * Datenspiegel: jeder Bereichs-Einlass eines Tickets als checkins-Eintrag mit action „area“ und data.area_id (keine neue Spalte).
 * Geteilte Events: Einlass nur beim Ersteller (Spiegel-Tickets sind gesperrt) – es gelten seine Bereiche.
 */
if (!defined('ABSPATH')) exit;

class TIX_Checkin_Areas {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const MAX = 30;

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 27);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function t() { global $wpdb; return $wpdb->prefix . 'tix_checkin_areas'; }
    public static function tl() { global $wpdb; return $wpdb->prefix . 'tix_checkin_area_log'; }

    public static function maybe_install() {
        if (get_option('tix_checkin_areas_db') === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organizer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(80) NOT NULL,
            sort INT NOT NULL DEFAULT 0,
            capacity INT UNSIGNED NOT NULL DEFAULT 0,
            created DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY org (organizer_id, sort)
        ) $c;");
        dbDelta("CREATE TABLE " . self::tl() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            area_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(8) NOT NULL DEFAULT 'ticket',
            ref VARCHAR(60) NOT NULL,
            entries INT UNSIGNED NOT NULL DEFAULT 1,
            first_at DATETIME NOT NULL,
            last_at DATETIME NOT NULL,
            by_user VARCHAR(80) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY entry (event_id, area_id, kind, ref),
            KEY area (event_id, area_id)
        ) $c;");
        update_option('tix_checkin_areas_db', self::DB_VERSION, true);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }
    private static function bad($f, $m, $code = 'tix_invalid_field', $st = 400) { return new WP_Error($code, $m, ['status' => $st, 'field' => $f]); }

    /** Bereich (Seite 0 / Veranstalter) eines Events */
    public static function scope_of_event($eid) { return self::multi() ? intval(get_post_meta(intval($eid), '_tix_organizer_id', true)) : 0; }

    /** Angelegte Bereiche eines Bereichs, sortiert → [id => row] */
    public static function areas($org) {
        global $wpdb;
        self::maybe_install();
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t() . " WHERE organizer_id = %d ORDER BY sort ASC, id ASC", intval($org)), ARRAY_A) as $r) $out[intval($r['id'])] = $r;
        return $out;
    }

    private static function area_out(array $r) { return ['id' => intval($r['id']), 'name' => (string) $r['name'], 'sort' => intval($r['sort']), 'capacity' => intval($r['capacity']) ?: null]; }

    // ═══════════════ Kategorie ═══════════════

    /** Erlaubte Bereiche einer Kategorie (nur noch vorhandene IDs); [] = überall */
    public static function allowed_ids($eid, $cat_index, array $areas = null) {
        $cats = get_post_meta(intval($eid), '_tix_ticket_categories', true);
        $ids = is_array($cats) && isset($cats[intval($cat_index)]) ? array_map('intval', (array) ($cats[intval($cat_index)]['areas'] ?? [])) : [];
        if (!$ids) return [];
        $areas = $areas ?? self::areas(self::scope_of_event($eid));
        $ids = array_values(array_intersect($ids, array_keys($areas)));
        return $ids; // nur gelöschte Bereiche eingetragen → [] = überall (lieber einlassen als falsch abweisen)
    }

    /** Eingabe `areas` einer Kategorie prüfen → int[] oder WP_Error */
    public static function clean_category_areas($in, $eid) {
        if ($in === null || $in === '' || $in === []) return [];
        if (!is_array($in)) return self::bad('categories.areas', 'areas: Liste von Bereichs-IDs.');
        $known = self::areas(self::scope_of_event($eid));
        $out = [];
        foreach ($in as $id) {
            $id = intval($id);
            if (!isset($known[$id])) return self::bad('categories.areas', 'Diesen Einlassbereich gibt es nicht.');
            if (!in_array($id, $out, true)) $out[] = $id;
        }
        return $out;
    }

    // ═══════════════ Scan ═══════════════

    /** Bereich aus der Anfrage: null = ohne Bereiche (wie bisher), Zeile, oder WP_Error (unbekannter Bereich) */
    public static function from_request(WP_REST_Request $req, $eid) {
        $b = $req->get_json_params();
        $raw = is_array($b) && array_key_exists('area', $b) ? $b['area'] : $req->get_param('area');
        $id = intval($raw);
        if (!$id) return null;
        $areas = self::areas(self::scope_of_event($eid));
        if (!$areas) return null; // keine Bereiche angelegt → exakt wie bisher
        if (!isset($areas[$id])) return new WP_Error('tix_area_unknown', 'Diesen Einlassbereich gibt es nicht (mehr). Bitte am Scanner neu wählen.', ['status' => 400, 'field' => 'area']);
        return $areas[$id];
    }

    private static function count_in($eid, $area_id) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::tl() . " WHERE event_id = %d AND area_id = %d", intval($eid), intval($area_id))));
    }

    private static function entry($eid, $area_id, $kind, $ref) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::tl() . " WHERE event_id = %d AND area_id = %d AND kind = %s AND ref = %s", intval($eid), intval($area_id), $kind, (string) $ref), ARRAY_A);
    }

    private static function add_entry($eid, $area_id, $kind, $ref, $by, $n = 1) {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare("INSERT INTO " . self::tl() . " (event_id, area_id, kind, ref, entries, first_at, last_at, by_user) VALUES (%d, %d, %s, %s, %d, %s, %s, %s)
            ON DUPLICATE KEY UPDATE entries = entries + VALUES(entries), last_at = VALUES(last_at)", intval($eid), intval($area_id), $kind, (string) $ref, max(1, intval($n)), $now, $now, mb_substr((string) $by, 0, 80)));
        // Datenspiegel: Bereichs-Einlass als eigener Check-in-Eintrag (action „area“; „in“/„reset“ bleiben unverändert)
        if ($kind === 'ticket' && class_exists('TIX_Mirror') && method_exists('TIX_Mirror', 'capturing') && TIX_Mirror::capturing()) {
            $tid = intval($ref);
            TIX_Mirror::touch('checkin', $tid . ':area' . intval($area_id) . ':' . substr(str_replace('.', '', (string) microtime(true)), 0, 16), 'upsert', [
                'ticket_id' => $tid, 'ticket_code' => (string) get_post_meta($tid, '_tix_ticket_code', true), 'event_id' => intval($eid) ?: null,
                'action' => 'area', 'at' => gmdate('c'), 'by' => mb_substr((string) $by, 0, 80), 'data' => (object) ['area_id' => intval($area_id)],
            ]);
        }
    }

    private static function info(array $area, $eid) {
        $cap = intval($area['capacity']);
        $cnt = self::count_in($eid, $area['id']);
        return ['id' => intval($area['id']), 'name' => (string) $area['name'], 'count' => $cnt, 'capacity' => $cap ?: null, 'full' => $cap > 0 && $cnt >= $cap];
    }

    /** Ticket-Scan mit Bereich (nach allen bisherigen Prüfungen: Code, Event, Zugriff, Passwort, Storno, Spiegel) */
    public static function scan_ticket($ticket, $eid, array $area, $code, $name) {
        $tid = intval($ticket->ID);
        $ci = intval(get_post_meta($tid, '_tix_ticket_cat_index', true));
        $cats = get_post_meta($eid, '_tix_ticket_categories', true);
        $cat_name = is_array($cats) && isset($cats[$ci]) ? (string) ($cats[$ci]['name'] ?? '') : '';
        $areas = self::areas(self::scope_of_event($eid));
        $base = ['ok' => true, 'name' => $name, 'type' => 'ticket', 'code' => $code, 'category' => $cat_name,
            'seat' => get_post_meta($tid, '_tix_ticket_seat_id', true),
            'seat_label' => class_exists('TIX_Seatmap') && method_exists('TIX_Seatmap', 'ticket_seat_label') ? TIX_Seatmap::ticket_seat_label($tid) : ''];
        $allowed = self::allowed_ids($eid, $ci, $areas);
        if ($allowed && !in_array(intval($area['id']), $allowed, true)) {
            return rest_ensure_response($base + ['status' => 'invalid', 'area_denied' => true, 'error' => 'tix_area_denied', 'area' => self::info($area, $eid),
                'allowed_areas' => array_map(function ($id) use ($areas) { return (string) $areas[$id]['name']; }, $allowed), 'message' => 'Kein Zutritt für diesen Bereich.']);
        }
        $by = wp_get_current_user()->user_login;
        $prev = self::entry($eid, $area['id'], 'ticket', $tid);
        if ($prev) {
            $dup = class_exists('TIX_Sale_Settings') ? (TIX_Sale_Settings::for_event($eid)['checkin']['duplicate'] ?? 'warn') : 'warn';
            if ($dup === 'reentry') {
                self::add_entry($eid, $area['id'], 'ticket', $tid, $by);
                $rc = intval($prev['entries']); // Wiedereinlässe in diesem Bereich (entries − 1 + 1)
                return rest_ensure_response($base + ['status' => 'reentry', 'reentry' => true, 'reentry_count' => $rc, 'time' => (string) $prev['first_at'], 'checked_in_count' => 1, 'total_expected' => 1,
                    'area' => self::info($area, $eid), 'message' => 'Wiedereinlass ' . $area['name'] . ' (' . $rc . '.)']);
            }
            return rest_ensure_response($base + ['status' => 'already', 'block' => $dup === 'block', 'time' => (string) $prev['first_at'], 'checked_in_count' => 1, 'total_expected' => 1,
                'area' => self::info($area, $eid), 'message' => 'Bereits in „' . $area['name'] . '“ eingelassen.']);
        }
        $cap = intval($area['capacity']);
        if ($cap > 0 && self::count_in($eid, $area['id']) >= $cap) {
            return rest_ensure_response($base + ['status' => 'invalid', 'area_full' => true, 'error' => 'tix_area_full', 'area' => self::info($area, $eid), 'message' => '„' . $area['name'] . '“ ist voll.']);
        }
        if (!get_post_meta($tid, '_tix_ticket_checked_in', true)) {
            TIX_Tickets::checkin_ticket($tid, $by);
            TIX_REST_API::ticket_db_checked_in($code);
        }
        self::add_entry($eid, $area['id'], 'ticket', $tid, $by);
        return rest_ensure_response($base + ['status' => 'ok', 'checked_in_count' => 1, 'total_expected' => 1, 'area' => self::info($area, $eid), 'message' => 'Willkommen!']);
    }

    /** Gast der Gästeliste: überall Zutritt, zählt im gewählten Bereich mit (nur bei ok/partial) */
    public static function after_guest($res, WP_REST_Request $req, $eid, $guest_id) {
        $area = self::from_request($req, $eid);
        if (!$area || is_wp_error($area)) return $res;
        $d = $res instanceof WP_REST_Response ? $res->get_data() : (is_array($res) ? $res : null);
        if (!is_array($d) || !in_array($d['status'] ?? '', ['ok', 'partial'], true)) return $res;
        self::add_entry($eid, $area['id'], 'guest', strtoupper((string) $guest_id), wp_get_current_user()->user_login);
        $d['area'] = self::info($area, $eid);
        return rest_ensure_response($d);
    }

    // ═══════════════ Offline ═══════════════

    /** Zusatz für die Offline-Liste: areas + je Ticket ar/ain (nur wenn Bereiche angelegt sind) */
    public static function offline_extra($eid, array $ticket_ids) {
        $areas = self::areas(self::scope_of_event($eid));
        if (!$areas) return null;
        global $wpdb;
        $in = [];
        if ($ticket_ids) foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT area_id, ref FROM " . self::tl() . " WHERE event_id = %d AND kind = 'ticket'", intval($eid))) as $r) $in[intval($r->ref)][] = intval($r->area_id);
        $per = [];
        foreach ($ticket_ids as $tid) $per[intval($tid)] = ['ar' => self::allowed_ids($eid, intval(get_post_meta($tid, '_tix_ticket_cat_index', true)), $areas), 'ain' => $in[intval($tid)] ?? []];
        return ['areas' => array_values(array_map([__CLASS__, 'area_out'], $areas)), 'per_ticket' => $per];
    }

    /** Offline-Eintrag mit Bereich → Ergebnis wie batch_ticket (ok | reentry | conflict | denied | full | …) */
    public static function batch_ticket($eid, $tid, $area_id, $by, $at) {
        $areas = self::areas(self::scope_of_event($eid));
        if (!$areas || !isset($areas[intval($area_id)])) return null; // keine bzw. unbekannte Bereiche → bisheriger Abgleich
        $area = $areas[intval($area_id)];
        $p = $tid ? get_post($tid) : null;
        if (!$p || $p->post_type !== 'tix_ticket' || intval(get_post_meta($tid, '_tix_ticket_event_id', true)) !== intval($eid)) return ['status' => 'not_found'];
        if (class_exists('TIX_Tickets') && TIX_Tickets::mirror_lock_message($tid) !== null) return ['status' => 'invalid'];
        if ((string) (get_post_meta($tid, '_tix_ticket_status', true) ?: 'valid') === 'cancelled') return ['status' => 'cancelled'];
        $allowed = self::allowed_ids($eid, intval(get_post_meta($tid, '_tix_ticket_cat_index', true)), $areas);
        if ($allowed && !in_array(intval($area_id), $allowed, true)) return ['status' => 'denied', 'error' => 'tix_area_denied', 'area' => intval($area_id)];
        $prev = self::entry($eid, $area_id, 'ticket', $tid);
        if ($prev) {
            if ((string) $prev['by_user'] === (string) $by) return ['status' => 'ok', 'area' => intval($area_id), 'checked_at' => (string) $prev['first_at']]; // Wiederholung desselben Geräts
            $dup = class_exists('TIX_Sale_Settings') ? (TIX_Sale_Settings::for_event($eid)['checkin']['duplicate'] ?? 'warn') : 'warn';
            if ($dup === 'reentry') { self::add_entry($eid, $area_id, 'ticket', $tid, $by); return ['status' => 'reentry', 'reentry' => true, 'area' => intval($area_id)]; }
            return ['status' => 'conflict', 'area' => intval($area_id), 'checked_at' => (string) $prev['first_at'], 'by' => (string) $prev['by_user']];
        }
        $cap = intval($area['capacity']);
        if ($cap > 0 && self::count_in($eid, $area_id) >= $cap) return ['status' => 'full', 'error' => 'tix_area_full', 'area' => intval($area_id)];
        if (!get_post_meta($tid, '_tix_ticket_checked_in', true)) {
            TIX_Tickets::checkin_ticket($tid, $by);
            if ($at !== '') update_post_meta($tid, '_tix_ticket_checkin_offline_at', $at);
        }
        self::add_entry($eid, $area_id, 'ticket', $tid, $by);
        return ['status' => 'ok', 'area' => intval($area_id), 'checked_at' => (string) get_post_meta($tid, '_tix_ticket_checkin_time', true)];
    }

    // ═══════════════ REST ═══════════════

    public static function routes() {
        $read = function (WP_REST_Request $req) { return TIX_REST_API::check_entrance($req); };
        $write = ['TIX_Studio_Stats', 'perm_manager'];
        register_rest_route(self::NS, '/checkin/areas', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $read],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'], 'permission_callback' => $write],
        ]);
        register_rest_route(self::NS, '/checkin/areas/order', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_order'], 'permission_callback' => $write]);
        register_rest_route(self::NS, '/checkin/areas/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_update'], 'permission_callback' => $write],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $write],
        ]);
        register_rest_route(self::NS, '/checkin/(?P<event_id>\d+)/areas', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_occupancy'], 'permission_callback' => $read]);
    }

    /** Bereich der Anfrage: ?event_id= → dessen Veranstalter (mit Zugriff), sonst eigener Veranstalter bzw. Seite */
    private static function org(WP_REST_Request $req) {
        $eid = absint($req->get_param('event_id'));
        if ($eid) return TIX_REST_API::user_can_access_event($eid) ? self::scope_of_event($eid) : new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        if (!self::multi()) return 0;
        if (current_user_can('manage_options')) return max(0, intval($req->get_param('organizer_id') ?: $req->get_param('organizer'))); // Admin: organizer_id bzw. organizer
        return intval(TIX_App_Scope::organizer_id_for_user());
    }

    private static function payload($org) { return ['ok' => true, 'areas' => array_values(array_map([__CLASS__, 'area_out'], self::areas($org))), 'max' => self::MAX]; }

    public static function rest_list(WP_REST_Request $req) {
        $org = self::org($req);
        if (is_wp_error($org)) return $org;
        return rest_ensure_response(self::payload($org));
    }

    private static function fields(WP_REST_Request $req, $create) {
        $set = [];
        if ($create || $req->has_param('name')) {
            $n = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 80);
            if ($n === '') return self::bad('name', 'Bitte einen Namen angeben (z. B. „VIP-Bereich“).');
            $set['name'] = $n;
        }
        if ($req->has_param('capacity')) {
            $c = $req->get_param('capacity');
            $c = ($c === null || $c === '') ? 0 : intval($c);
            if ($c < 0 || $c > 1000000) return self::bad('capacity', 'Kapazität 0 (unbegrenzt) bis 1.000.000.');
            $set['capacity'] = $c;
        }
        return $set;
    }

    public static function rest_create(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        if (is_wp_error($org)) return $org;
        $set = self::fields($req, true);
        if (is_wp_error($set)) return $set;
        $have = self::areas($org);
        if (count($have) >= self::MAX) return self::bad('name', 'Höchstens ' . self::MAX . ' Bereiche.');
        foreach ($have as $a) if (mb_strtolower($a['name']) === mb_strtolower($set['name'])) return self::bad('name', 'Diesen Bereich gibt es schon.', 'tix_area_exists', 409);
        $wpdb->insert(self::t(), $set + ['organizer_id' => $org, 'sort' => count($have), 'created' => current_time('mysql')]);
        return new WP_REST_Response(['ok' => true, 'area' => self::area_out(self::areas($org)[intval($wpdb->insert_id)])] + self::payload($org), 201);
    }

    private static function own($id, $org) {
        $a = self::areas($org);
        return $a[intval($id)] ?? null;
    }

    public static function rest_update(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        if (is_wp_error($org)) return $org;
        if (!self::own($req['id'], $org)) return new WP_Error('not_found', 'Bereich nicht gefunden.', ['status' => 404]);
        $set = self::fields($req, false);
        if (is_wp_error($set)) return $set;
        if ($set) $wpdb->update(self::t(), $set, ['id' => intval($req['id'])]);
        return rest_ensure_response(['ok' => true, 'area' => self::area_out(self::own($req['id'], $org))] + self::payload($org));
    }

    public static function rest_order(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        if (is_wp_error($org)) return $org;
        $ids = array_values(array_unique(array_map('intval', (array) $req->get_param('ids'))));
        $have = self::areas($org);
        foreach ($ids as $i => $id) { if (!isset($have[$id])) return self::bad('ids', 'Unbekannter Bereich.'); $wpdb->update(self::t(), ['sort' => $i], ['id' => $id]); }
        return rest_ensure_response(self::payload($org));
    }

    /** Löschen: Zählung bleibt in der Statistik nicht erhalten; Kategorien mit nur diesem Bereich gelten wieder überall */
    public static function rest_delete(WP_REST_Request $req) {
        global $wpdb;
        $org = self::org($req);
        if (is_wp_error($org)) return $org;
        if (!self::own($req['id'], $org)) return new WP_Error('not_found', 'Bereich nicht gefunden.', ['status' => 404]);
        $wpdb->delete(self::t(), ['id' => intval($req['id'])]);
        return rest_ensure_response(self::payload($org));
    }

    /** GET /checkin/{event_id}/areas → Belegung je Bereich */
    public static function rest_occupancy(WP_REST_Request $req) {
        global $wpdb;
        $eid = intval($req['event_id']);
        if (get_post_type($eid) !== 'event' || !TIX_REST_API::user_can_access_event($eid)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $rows = [];
        $stats = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT area_id, kind, COUNT(*) n, SUM(entries) e FROM " . self::tl() . " WHERE event_id = %d GROUP BY area_id, kind", $eid)) as $r) {
            $stats[intval($r->area_id)][$r->kind] = ['n' => intval($r->n), 'e' => intval($r->e)];
        }
        foreach (self::areas(self::scope_of_event($eid)) as $id => $a) {
            $t = $stats[$id]['ticket'] ?? ['n' => 0, 'e' => 0]; $g = $stats[$id]['guest'] ?? ['n' => 0, 'e' => 0];
            $cap = intval($a['capacity']);
            $rows[] = self::area_out($a) + ['tickets' => $t['n'], 'guests' => $g['e'], 'count' => $t['n'] + $g['n'], 'reentries' => max(0, $t['e'] - $t['n']), 'full' => $cap > 0 && ($t['n'] + $g['n']) >= $cap];
        }
        $r = rest_ensure_response(['ok' => true, 'event_id' => $eid, 'areas' => $rows]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
}

TIX_Checkin_Areas::init();
