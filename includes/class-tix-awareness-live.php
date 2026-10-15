<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Awareness_Live – Awareness Stufen 2–3 (Konzept §3 B4/B8/B9, §5, §6).
 *
 * Schalter (Tixomat → Awareness, Option `tix_awareness_flags`, beide Vorgabe AUS):
 *   report_enabled – Stufe 2: Support-Thema „Awareness“ (anonym ohne E-Mail), Feedback „Hast du dich sicher gefühlt?“
 *   alarm_live     – Stufe 3: „Hilfe vor Ort“ (stiller Alarm) – erst nach der Rechtsprüfung einschalten
 *
 * REST (tixomat/v1):
 *   GET  /awareness/status?event_id=           öffentlich → {active, on_duty} (nur Ja/Nein)
 *   POST /awareness/alerts                     öffentlich {event_id, area, category?, text?, sign?, anonymous, device} → {alert{id, key, status}, point}
 *                                              category: harassment|sexual_harassment|threat|health|other (unbekannt/fehlend → other)
 *   GET  /awareness/alerts/{id}?key=           Gast: Status seines Hinweises
 *   GET  /awareness/alerts?event_id=           Team/Veranstalter: Liste (offen zuerst, darin sexual_harassment/health zuerst)
 *   POST /awareness/alerts/{id}/status         Team {status: taken|done|no_action}
 *   POST /awareness/duty                       Team {event_id, on} → im Dienst bis Event-Ende + 60 min
 *   GET  /awareness/events                     Team: betreute Events (laufend + nächste 30 Tage) mit Fenster und eigenem Dienst
 * Mitteilungs-Ziele (`action`): `awareness:alert:<id>` (Team: neuer Hinweis, Gast: Neuigkeit), `event_id` steht im Push dabei.
 *   GET  /awareness/report?event_id=           Inhaber/Admin: Bericht ohne Namen (je Bereich, Median Reaktionszeit, Feedback)
 *   POST /awareness/feedback                   öffentlich {event_id, answer: yes|partly|no, note?, device}
 *   GET  /awareness/feedback?event_id=         Team/Veranstalter: Summe erst ab 5 Antworten
 *   GET  /awareness/reports?event_id=          Inhaber/Admin: Awareness-Meldungen der eigenen Events
 * Melden (B8): POST /customer/support mit category=awareness (+ event_id), Name/E-Mail optional.
 *
 * Rolle `tix_awareness` („Awareness (App)“, Recht `tix_app_awareness`) – vergeben über `/team` (Schlüssel awareness);
 * im Mehr-Veranstalter-Modus nur Events des eigenen Veranstalters (TIX_App_Scope).
 * Push an alle im Dienst ohne Inhalt („Neuer Hinweis für das Awareness-Team“). Höchstens 3 Hinweise je Gerät und Event.
 * Löschung (Cron täglich): Hinweise und Feedback-Einzelantworten 30 Tage nach dem Event (Feedback-Summe bleibt am Event),
 * Awareness-Meldungen nach 6 Monaten.
 * Test-Modus (unsichtbares Test-Event nur für Tester): TIX_Awareness_Test. Admin: Liste der Hinweise + CSV-Export.
 */
class TIX_Awareness_Live {

    const NS        = 'tixomat/v1';
    const FLAGS     = 'tix_awareness_flags';
    const DB_VER    = '2';
    const CRON      = 'tix_awareness_cleanup';
    const META_DUTY = '_tix_awareness_duty';
    const MAX_PER_DEVICE = 3;
    const STATUSES  = ['open' => 'Das Team ist informiert', 'taken' => 'Jemand ist unterwegs', 'done' => 'Erledigt', 'no_action' => 'Kein Einsatz nötig'];
    const ANSWERS   = ['yes', 'partly', 'no'];
    // Art des Hinweises (1.38.439); PRIORITY steht in der Team-Liste zuerst
    const CATEGORIES = ['harassment' => 'Belästigung', 'sexual_harassment' => 'Sexuelle Belästigung', 'threat' => 'Bedrohung', 'health' => 'Gesundheit', 'other' => 'Sonstiges'];
    const PRIORITY   = ['sexual_harassment', 'health'];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 7);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action(self::CRON, [__CLASS__, 'cleanup']);
        add_action('admin_post_tix_awareness_alerts_csv', [__CLASS__, 'export_csv']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Awareness_Live::CRON)) wp_schedule_event(time() + 900, 'daily', TIX_Awareness_Live::CRON);
        }, 22);
    }

    public static function flags() {
        $f = get_option(self::FLAGS, []);
        $f = is_array($f) ? $f : [];
        return ['report_enabled' => !empty($f['report_enabled']), 'alarm_live' => !empty($f['alarm_live'])];
    }

    private static function t($name) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function maybe_install() {
        if (get_option('tix_awareness_db') === self::DB_VER) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t('tix_awareness_alerts') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            area varchar(60) NOT NULL DEFAULT '',
            category varchar(24) NOT NULL DEFAULT 'other',
            text varchar(255) NOT NULL DEFAULT '',
            sign varchar(150) NOT NULL DEFAULT '',
            anonymous tinyint(1) NOT NULL DEFAULT 1,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            first_name varchar(60) NOT NULL DEFAULT '',
            device varchar(64) NOT NULL DEFAULT '',
            access_key varchar(64) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT 'open',
            taken_by bigint(20) unsigned NOT NULL DEFAULT 0,
            taken_at datetime NULL,
            done_at datetime NULL,
            created datetime NOT NULL,
            updated datetime NOT NULL,
            delete_after datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY device_event (device, event_id)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('tix_awareness_feedback') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            device varchar(64) NOT NULL DEFAULT '',
            answer varchar(10) NOT NULL DEFAULT '',
            note varchar(255) NOT NULL DEFAULT '',
            created datetime NOT NULL,
            delete_after datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY device_event (device, event_id)
        ) $c;");
        update_option('tix_awareness_db', self::DB_VER, false);
    }

    private static function error($code, $msg, $status, array $extra = []) {
        return new WP_Error($code, $msg, ['status' => $status] + $extra);
    }

    private static function device_hash($device) {
        $device = trim((string) $device);
        return strlen($device) >= 8 ? hash_hmac('sha256', $device, wp_salt('auth')) : '';
    }

    private static function now_ts() {
        return current_time('timestamp');
    }

    /** Zeitfenster wie beim Musikwunsch (60 min vorher bis Ende + 60 min). */
    public static function window($event_id) {
        return class_exists('TIX_Music') ? TIX_Music::event_window($event_id) : null;
    }

    private static function event_ok($event_id) {
        if (!$event_id || get_post_type($event_id) !== 'event') return false;
        if (get_post_status($event_id) === 'publish') return true;
        // Test-Event (privat) nur für Tester
        return class_exists('TIX_Awareness_Test') && TIX_Awareness_Test::visible($event_id);
    }

    public static function category($raw) {
        $c = sanitize_key((string) $raw);
        return isset(self::CATEGORIES[$c]) ? $c : 'other';
    }

    private static function event_organizer($event_id) {
        return intval(get_post_meta($event_id, '_tix_organizer_id', true));
    }

    private static function delete_after($event_id) {
        $w = self::window($event_id);
        $end = $w ? $w['end'] : self::now_ts();
        return gmdate('Y-m-d H:i:s', $end + 30 * DAY_IN_SECONDS);
    }

    // ── Stufe 3: Status, Dienst, Hinweise ──

    /** Läuft das Event mit eingeschaltetem Hilfe-Knopf und Team? */
    public static function alarm_possible($event_id) {
        if (!self::flags()['alarm_live'] || !self::event_ok($event_id)) return false;
        $a = class_exists('TIX_Awareness') ? TIX_Awareness::effective($event_id) : null;
        if (!$a || empty($a['team']) || empty($a['alarm_enabled'])) return false;
        $w = self::window($event_id);
        $now = self::now_ts();
        return $w && $now >= $w['opens'] && $now <= $w['closes'];
    }

    /** Nutzer im Dienst für dieses Event. */
    public static function on_duty_users($event_id) {
        $now = self::now_ts();
        $out = [];
        foreach (get_users(['meta_key' => self::META_DUTY, 'fields' => 'ID', 'number' => 200]) as $uid) {
            $d = get_user_meta($uid, self::META_DUTY, true);
            if (is_array($d) && intval($d['event_id'] ?? 0) === intval($event_id) && intval($d['until'] ?? 0) >= $now) $out[] = intval($uid);
        }
        return $out;
    }

    public static function rest_status(WP_REST_Request $req) {
        $eid = absint($req->get_param('event_id'));
        $active = self::alarm_possible($eid);
        return rest_ensure_response(['ok' => true, 'active' => $active, 'on_duty' => $active && (bool) self::on_duty_users($eid), 'report_enabled' => self::flags()['report_enabled']]);
    }

    /** Darf der Nutzer Team-Routen dieses Events nutzen? */
    public static function team_can_event($event_id) {
        if (!is_user_logged_in() || !self::event_ok($event_id)) return false;
        $u = wp_get_current_user();
        $team = $u->has_cap('tix_app_awareness') || $u->has_cap('manage_options')
            || (class_exists('TIX_REST_API') && TIX_REST_API::is_staff_user($u));
        if (!$team) return false;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) return TIX_App_Scope::event_allowed($event_id);
        return true;
    }

    /** Inhaber/Team-Admin bzw. Admin (Bericht, Meldungen). */
    private static function manager_can_event($event_id) {
        if (!is_user_logged_in() || !self::event_ok($event_id)) return false;
        if (current_user_can('manage_options')) return true;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            return TIX_App_Scope::is_org_manager() && TIX_App_Scope::event_allowed($event_id);
        }
        return class_exists('TIX_REST_API') && TIX_REST_API::is_staff_user(wp_get_current_user());
    }

    /** GET /awareness/events – Events, die das Konto betreut (eigener Veranstalter bzw. alle), mit Team. */
    public static function rest_events(WP_REST_Request $req) {
        $u = wp_get_current_user();
        $team = $u->has_cap('tix_app_awareness') || $u->has_cap('manage_options') || (class_exists('TIX_REST_API') && TIX_REST_API::is_staff_user($u));
        if (!$team) return self::error('rest_forbidden', 'Nur für das Awareness-Team.', 403);
        $from = gmdate('Y-m-d', self::now_ts() - DAY_IN_SECONDS);
        $to   = gmdate('Y-m-d', self::now_ts() + 30 * DAY_IN_SECONDS);
        $args = ['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids',
            'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_date_start', 'value' => [$from, $to], 'compare' => 'BETWEEN', 'type' => 'DATE']]];
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::scoped()) {
            $oid = TIX_App_Scope::organizer_id_for_user();
            if (!$oid) return rest_ensure_response(['ok' => true, 'events' => []]);
            $args['meta_query'][] = TIX_App_Scope::event_meta_clause($oid);
        }
        $me = get_user_meta(get_current_user_id(), self::META_DUTY, true);
        $now = self::now_ts();
        $plain = function ($ts) { return gmdate('Y-m-d H:i', $ts); };
        $out = [];
        $ids = get_posts($args);
        if (class_exists('TIX_Awareness_Test') && TIX_Awareness_Test::is_tester()) {
            foreach (TIX_Awareness_Test::event_ids() as $tid) if (TIX_Awareness_Test::visible($tid)) array_unshift($ids, $tid);
        }
        foreach ($ids as $eid) {
            $a = class_exists('TIX_Awareness') ? TIX_Awareness::effective($eid) : null;
            $w = self::window($eid);
            if (!$a || empty($a['team']) || !$w || $now > $w['closes']) continue;
            $out[] = [
                'id'            => intval($eid),
                'title'         => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'),
                'starts_at'     => $plain($w['start']),
                'ends_at'       => $plain($w['end']),
                'opens_at'      => $plain($w['opens']),
                'closes_at'     => $plain($w['closes']),
                'running'       => $now >= $w['opens'],
                'alarm_enabled' => !empty($a['alarm_enabled']) && self::flags()['alarm_live'],
                'point'         => (string) $a['point'],
                'on_duty'       => is_array($me) && intval($me['event_id'] ?? 0) === intval($eid) && intval($me['until'] ?? 0) >= $now,
                'test'          => class_exists('TIX_Awareness_Test') && TIX_Awareness_Test::is_test_event($eid),
            ];
        }
        return rest_ensure_response(['ok' => true, 'events' => $out]);
    }

    public static function rest_duty(WP_REST_Request $req) {
        $eid = absint($req->get_param('event_id'));
        if (!self::team_can_event($eid)) return self::error('rest_forbidden', 'Kein Zugriff auf dieses Event.', 403);
        $uid = get_current_user_id();
        if (!filter_var($req->get_param('on'), FILTER_VALIDATE_BOOLEAN)) {
            delete_user_meta($uid, self::META_DUTY);
            return rest_ensure_response(['ok' => true, 'on' => false]);
        }
        $w = self::window($eid);
        if (!$w || self::now_ts() > $w['closes']) return self::error('tix_awareness_ended', 'Das Event ist vorbei.', 409);
        update_user_meta($uid, self::META_DUTY, ['event_id' => $eid, 'until' => $w['closes'], 'since' => self::now_ts()]);
        return rest_ensure_response(['ok' => true, 'on' => true, 'event_id' => $eid, 'until' => gmdate('Y-m-d H:i', $w['closes'])]);
    }

    private static function alert_row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('tix_awareness_alerts') . " WHERE id = %d", $id));
    }

    private static function point($event_id) {
        $a = class_exists('TIX_Awareness') ? TIX_Awareness::effective($event_id) : null;
        return $a ? (string) $a['point'] : '';
    }

    public static function rest_create_alert(WP_REST_Request $req) {
        global $wpdb;
        self::maybe_install();
        $eid = absint($req->get_param('event_id'));
        if (!self::flags()['alarm_live']) return self::error('tix_awareness_off', 'Hilfe vor Ort ist noch nicht verfügbar.', 404);
        if (!self::alarm_possible($eid) || !self::on_duty_users($eid)) {
            return self::error('tix_awareness_unavailable', 'Gerade ist kein Awareness-Team erreichbar. Bei Gefahr sofort 110.', 409, ['point' => self::point($eid)]);
        }
        $device = self::device_hash((string) $req->get_param('device'));
        if ($device === '') return self::error('tix_device', 'Geräte-Kennung fehlt.', 400);
        $t = self::t('tix_awareness_alerts');
        $count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE device = %s AND event_id = %d", $device, $eid)));
        if ($count >= self::MAX_PER_DEVICE) {
            return self::error('tix_awareness_limit', 'Du hast das Team schon informiert. Bitte geh zum Awareness-Punkt.', 429, ['point' => self::point($eid)]);
        }
        $a = TIX_Awareness::effective($eid);
        $area = mb_substr(sanitize_text_field((string) $req->get_param('area')), 0, 60);
        if (!in_array($area, $a['areas'], true)) $area = 'Weiß nicht';
        $category = self::category($req->get_param('category'));
        // Vorname nur, wenn der angemeldete Gast ihn mitschickt (anonymous=false); nie Nachname oder E-Mail
        $anon = $req->get_param('anonymous') === null ? true : filter_var($req->get_param('anonymous'), FILTER_VALIDATE_BOOLEAN);
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        if (!$user) $anon = true;
        $now = current_time('mysql');
        $key = wp_generate_password(32, false, false);
        $wpdb->insert($t, [
            'event_id'     => $eid,
            'organizer_id' => self::event_organizer($eid),
            'area'         => $area,
            'category'     => $category,
            'text'         => mb_substr(sanitize_text_field((string) $req->get_param('text')), 0, 200),
            'sign'         => mb_substr(sanitize_text_field((string) $req->get_param('sign')), 0, 120),
            'anonymous'    => $anon ? 1 : 0,
            'user_id'      => (!$anon && $user) ? intval($user->ID) : 0,
            'first_name'   => (!$anon && $user) ? self::first_name_of($user) : '',
            'device'       => $device,
            'access_key'   => $key,
            'status'       => 'open',
            'created'      => $now,
            'updated'      => $now,
            'delete_after' => self::delete_after($eid),
        ]);
        $id = intval($wpdb->insert_id);
        if (!$id) return self::error('tix_awareness_failed', 'Hinweis konnte nicht gesendet werden. Bitte geh zum Awareness-Punkt.', 500, ['point' => self::point($eid)]);
        // Push an alle im Dienst – nur die Art, ohne Text, Bereich oder Namen auf dem Sperrbildschirm
        if (class_exists('TIX_Notifications')) {
            $title = $category === 'other' ? 'Neuer Hinweis für das Awareness-Team' : 'Neuer Hinweis: ' . self::CATEGORIES[$category];
            if (in_array($category, self::PRIORITY, true)) $title = 'Dringend – ' . $title;
            foreach (self::on_duty_users($eid) as $uid) {
                TIX_Notifications::add_user_item($uid, $title, '', ['type' => 'awareness', 'event_id' => $eid, 'action' => 'awareness:alert:' . $id]);
            }
        }
        $g = self::guest_payload(self::alert_row($id), true);
        // id/key/status zusätzlich oben (Vertrag Web/App), ausführlich unter `alert`
        return rest_ensure_response(['ok' => true, 'id' => $g['id'], 'key' => $g['key'], 'status' => $g['status'], 'alert' => $g, 'point' => self::point($eid)]);
    }

    /** Nur der Vorname: Profil-Vorname, sonst das erste Wort des Anzeigenamens (nicht Login, nicht E-Mail). */
    public static function first_name_of($user) {
        $f = trim((string) $user->first_name);
        if ($f === '') {
            $d = trim((string) $user->display_name);
            if ($d !== '' && strpos($d, '@') === false && $d !== $user->user_login) $f = (string) preg_split('/\s+/', $d)[0];
        }
        if (strpos($f, '@') !== false) $f = '';
        return mb_substr(sanitize_text_field($f), 0, 60);
    }

    private static function guest_payload($r, $with_key = false) {
        $out = [
            'id'           => intval($r->id),
            'event_id'     => intval($r->event_id),
            'category'     => self::category($r->category ?? 'other'),
            'status'       => (string) $r->status,
            'status_label' => self::STATUSES[$r->status] ?? (string) $r->status,
            'taken'        => $r->status !== 'open',
            'created'      => (string) $r->created,
            'updated'      => (string) $r->updated,
        ];
        if ($with_key) $out['key'] = (string) $r->access_key;
        return $out;
    }

    public static function rest_guest_alert(WP_REST_Request $req) {
        $r = self::alert_row(intval($req['id']));
        $key = (string) $req->get_param('key');
        if (!$r || $key === '' || !hash_equals((string) $r->access_key, $key)) return self::error('rest_forbidden', 'Kein Zugriff.', 403);
        $g = self::guest_payload($r);
        return rest_ensure_response(['ok' => true, 'status' => $g['status'], 'status_label' => $g['status_label'], 'alert' => $g, 'point' => self::point(intval($r->event_id))]);
    }

    private static function team_payload($r) {
        $by = intval($r->taken_by);
        return [
            'id'         => intval($r->id),
            'event_id'   => intval($r->event_id),
            'area'       => (string) $r->area,
            'category'   => self::category($r->category ?? 'other'),
            'category_label' => self::CATEGORIES[self::category($r->category ?? 'other')],
            'priority'   => in_array(self::category($r->category ?? 'other'), self::PRIORITY, true),
            'text'       => (string) $r->text,
            'sign'       => (string) $r->sign,
            'anonymous'  => (bool) $r->anonymous,
            'first_name' => $r->anonymous ? '' : (string) $r->first_name,
            'status'     => (string) $r->status,
            'taken_by'   => $by ? ['id' => $by, 'name' => (string) get_the_author_meta('display_name', $by)] : null,
            'taken_at'   => $r->taken_at ? (string) $r->taken_at : null,
            'done_at'    => $r->done_at ? (string) $r->done_at : null,
            'created'    => (string) $r->created,
        ];
    }

    public static function rest_team_alerts(WP_REST_Request $req) {
        global $wpdb;
        $eid = absint($req->get_param('event_id'));
        if (!self::team_can_event($eid)) return self::error('rest_forbidden', 'Kein Zugriff auf dieses Event.', 403);
        self::maybe_install();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('tix_awareness_alerts') . " WHERE event_id = %d ORDER BY (status = 'open') DESC, (category IN ('sexual_harassment','health')) DESC, id DESC LIMIT 200", $eid));
        $me = get_user_meta(get_current_user_id(), self::META_DUTY, true);
        return rest_ensure_response([
            'ok'      => true,
            'on_duty' => is_array($me) && intval($me['event_id'] ?? 0) === $eid && intval($me['until'] ?? 0) >= self::now_ts(),
            'alerts'  => array_map([__CLASS__, 'team_payload'], (array) $rows),
        ]);
    }

    public static function rest_alert_status(WP_REST_Request $req) {
        global $wpdb;
        $r = self::alert_row(intval($req['id']));
        if (!$r || !self::team_can_event(intval($r->event_id))) return self::error('rest_forbidden', 'Kein Zugriff.', 403);
        $st = sanitize_key((string) $req->get_param('status'));
        if (!in_array($st, ['taken', 'done', 'no_action'], true)) return self::error('tix_invalid_field', 'Ungültiger Status.', 400, ['field' => 'status']);
        $now = current_time('mysql');
        $upd = ['status' => $st, 'updated' => $now];
        if ($st === 'taken') { $upd['taken_by'] = get_current_user_id(); $upd['taken_at'] = $r->taken_at ?: $now; }
        else { $upd['done_at'] = $now; if (!$r->taken_at) { $upd['taken_at'] = $now; $upd['taken_by'] = get_current_user_id(); } }
        $wpdb->update(self::t('tix_awareness_alerts'), $upd, ['id' => $r->id]);
        // Gast mit Konto (nicht anonym): Mitteilung ohne Inhalt
        if (intval($r->user_id) && class_exists('TIX_Notifications')) {
            TIX_Notifications::add_user_item(intval($r->user_id), 'Neuigkeit zu deinem Hinweis', '', ['type' => 'awareness_guest', 'event_id' => intval($r->event_id), 'action' => 'awareness:alert:' . intval($r->id)]);
        }
        return rest_ensure_response(['ok' => true, 'alert' => self::team_payload(self::alert_row($r->id))]);
    }

    public static function rest_report(WP_REST_Request $req) {
        global $wpdb;
        $eid = absint($req->get_param('event_id'));
        if (!self::manager_can_event($eid)) return self::error('rest_forbidden', 'Kein Zugriff auf dieses Event.', 403);
        self::maybe_install();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT area, category, status, created, taken_at FROM " . self::t('tix_awareness_alerts') . " WHERE event_id = %d", $eid));
        $by = []; $mins = []; $cats = array_fill_keys(array_keys(self::CATEGORIES), 0);
        foreach ((array) $rows as $r) {
            $by[$r->area] = ($by[$r->area] ?? 0) + 1;
            $cats[self::category($r->category)]++;
            if ($r->taken_at) $mins[] = max(0, (strtotime($r->taken_at) - strtotime($r->created)) / 60);
        }
        sort($mins);
        $n = count($mins);
        $median = $n ? ($n % 2 ? $mins[intdiv($n, 2)] : ($mins[$n / 2 - 1] + $mins[$n / 2]) / 2) : null;
        arsort($by);
        return rest_ensure_response([
            'ok'                  => true,
            'event_id'            => $eid,
            'alerts_total'        => count((array) $rows),
            'by_area'             => (object) $by,
            'by_category'         => (object) $cats,
            'median_response_min' => $median === null ? null : round($median, 1),
            'feedback'            => self::feedback_summary($eid),
        ]);
    }

    // ── Stufe 2: Feedback ──

    public static function feedback_summary($eid) {
        global $wpdb;
        $counts = ['yes' => 0, 'partly' => 0, 'no' => 0];
        $kept = get_post_meta($eid, '_tix_awareness_feedback_sum', true);
        if (is_array($kept)) foreach ($counts as $k => $v) $counts[$k] += intval($kept[$k] ?? 0);
        $t = self::t('tix_awareness_feedback');
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") === $t) {
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT answer, COUNT(*) AS n FROM $t WHERE event_id = %d GROUP BY answer", $eid)) as $r) {
                if (isset($counts[$r->answer])) $counts[$r->answer] += intval($r->n);
            }
        }
        $total = array_sum($counts);
        return ['total' => $total, 'hidden' => $total < 5, 'counts' => $total >= 5 ? $counts : null];
    }

    public static function rest_feedback(WP_REST_Request $req) {
        global $wpdb;
        if (!self::flags()['report_enabled']) return self::error('tix_awareness_off', 'Feedback ist noch nicht verfügbar.', 404);
        self::maybe_install();
        $eid = absint($req->get_param('event_id'));
        if (!self::event_ok($eid)) return self::error('tix_awareness_event', 'Event nicht gefunden.', 404, ['field' => 'event_id']);
        $w = self::window($eid);
        $now = self::now_ts();
        if (!$w || $now < $w['start'] || $now > $w['end'] + 14 * DAY_IN_SECONDS) return self::error('tix_awareness_feedback_closed', 'Feedback ist nur ab Beginn bis 14 Tage nach dem Event möglich.', 409);
        $answer = sanitize_key((string) $req->get_param('answer'));
        if (!in_array($answer, self::ANSWERS, true)) return self::error('tix_invalid_field', 'Bitte Ja, Teils oder Nein wählen.', 400, ['field' => 'answer']);
        $device = self::device_hash((string) $req->get_param('device'));
        if ($device === '') return self::error('tix_device', 'Geräte-Kennung fehlt.', 400);
        $t = self::t('tix_awareness_feedback');
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE device = %s AND event_id = %d", $device, $eid))) {
            return self::error('tix_awareness_feedback_done', 'Danke, deine Antwort haben wir schon.', 409);
        }
        $wpdb->insert($t, [
            'event_id' => $eid, 'device' => $device, 'answer' => $answer,
            'note' => mb_substr(sanitize_text_field((string) $req->get_param('note')), 0, 200),
            'created' => current_time('mysql'), 'delete_after' => self::delete_after($eid),
        ]);
        return rest_ensure_response(['ok' => true, 'follow_up' => $answer === 'no'
            ? ['report' => self::flags()['report_enabled'], 'hotline' => '116 016', 'chat_url' => 'https://www.hilfetelefon.de']
            : null]);
    }

    public static function rest_feedback_summary(WP_REST_Request $req) {
        $eid = absint($req->get_param('event_id'));
        if (!self::team_can_event($eid)) return self::error('rest_forbidden', 'Kein Zugriff auf dieses Event.', 403);
        return rest_ensure_response(['ok' => true] + self::feedback_summary($eid));
    }

    // ── Stufe 2: Melden (Support-Thema „Awareness“) ──

    /** Awareness-Meldung als Support-Anfrage anlegen; E-Mail optional (anonym). */
    public static function create_report(array $a) {
        if (!self::flags()['report_enabled'] || !class_exists('TIX_Support')) return self::error('tix_awareness_off', 'Melden ist noch nicht verfügbar.', 404);
        $content = mb_substr(sanitize_textarea_field((string) ($a['content'] ?? '')), 0, 5000);
        if ($content === '') return self::error('missing_message', 'Bitte beschreibe kurz, was passiert ist.', 400, ['field' => 'message']);
        $email = sanitize_email((string) ($a['email'] ?? ''));
        if ($email !== '' && !is_email($email)) $email = '';
        $eid = absint($a['event_id'] ?? 0);
        if ($eid && !self::event_ok($eid)) $eid = 0;
        $subject = mb_substr(sanitize_text_field((string) ($a['subject'] ?? '')), 0, 120) ?: ('Awareness-Meldung' . ($eid ? ': ' . html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8') : ''));
        $name = mb_substr(sanitize_text_field((string) ($a['name'] ?? '')), 0, 80);
        $post_id = wp_insert_post(['post_type' => 'tix_support_ticket', 'post_title' => $subject, 'post_status' => 'tix_open', 'post_author' => intval($a['user_id'] ?? 0)]);
        if (!$post_id || is_wp_error($post_id)) return self::error('tix_support_failed', 'Meldung konnte nicht gespeichert werden.', 500);
        $meta = [
            '_tix_sp_email' => $email, '_tix_sp_name' => $name ?: 'Anonym', '_tix_sp_category' => 'awareness', '_tix_sp_priority' => 'high',
            '_tix_sp_last_reply' => current_time('c'), '_tix_sp_source' => sanitize_key($a['source'] ?? 'app'),
            '_tix_sp_event_id' => $eid, '_tix_sp_organizer_id' => $eid ? self::event_organizer($eid) : 0, '_tix_sp_anonymous' => $email === '' ? 1 : 0,
        ];
        if ($email !== '' && method_exists('TIX_Support', 'ensure_access_key_for_email')) $meta['_tix_sp_access_key'] = TIX_Support::ensure_access_key_for_email($email);
        foreach ($meta as $k => $v) update_post_meta($post_id, $k, $v);
        update_post_meta($post_id, '_tix_sp_messages', [[
            'id' => 'msg_' . bin2hex(random_bytes(8)), 'type' => 'customer', 'author' => $name ?: 'Anonym',
            'email' => $email, 'content' => $content, 'date' => current_time('c'),
        ]]);
        // Hinweis ohne Inhalt an Admin und Veranstalter (Art. 9 DSGVO: Inhalt nur im geschützten Bereich)
        $to = [function_exists('tix_get_settings') && is_email((string) tix_get_settings('support_email')) ? (string) tix_get_settings('support_email') : (string) get_option('admin_email')];
        $oid = intval($meta['_tix_sp_organizer_id']);
        if ($oid && is_email((string) get_post_meta($oid, '_tix_org_email', true))) $to[] = (string) get_post_meta($oid, '_tix_org_email', true);
        wp_mail(array_unique($to), 'Neue Awareness-Meldung', "Es ist eine neue Awareness-Meldung eingegangen" . ($eid ? ' (Event: ' . html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8') . ')' : '') . ".\nDen Inhalt siehst du angemeldet im Veranstalter-Bereich bzw. unter Tixomat → Support.");
        return intval($post_id);
    }

    public static function rest_reports(WP_REST_Request $req) {
        $eid = absint($req->get_param('event_id'));
        if (!self::manager_can_event($eid)) return self::error('rest_forbidden', 'Kein Zugriff auf dieses Event.', 403);
        $posts = get_posts(['post_type' => 'tix_support_ticket', 'post_status' => self::support_statuses(), 'posts_per_page' => 100,
            'meta_query' => [['key' => '_tix_sp_category', 'value' => 'awareness'], ['key' => '_tix_sp_event_id', 'value' => (string) $eid]]]);
        $out = [];
        foreach ($posts as $p) {
            $msgs = get_post_meta($p->ID, '_tix_sp_messages', true);
            $first = is_array($msgs) && $msgs ? $msgs[0] : [];
            $out[] = [
                'id' => intval($p->ID), 'subject' => (string) $p->post_title, 'message' => (string) ($first['content'] ?? ''),
                'anonymous' => (bool) get_post_meta($p->ID, '_tix_sp_anonymous', true), 'created' => (string) $p->post_date,
            ];
        }
        return rest_ensure_response(['ok' => true, 'reports' => $out]);
    }

    /** Alle Status der Support-Anfragen (eigene Status, die post_status 'any' nicht findet). */
    private static function support_statuses() {
        return class_exists('TIX_Support') ? array_keys(TIX_Support::get_statuses()) : ['tix_open'];
    }

    // ── Admin: Liste der Hinweise + CSV ──

    private static function admin_rows($event_id = 0, $limit = 100) {
        global $wpdb;
        self::maybe_install();
        $t = self::t('tix_awareness_alerts');
        $sql = "SELECT * FROM $t" . ($event_id ? $wpdb->prepare(' WHERE event_id = %d', $event_id) : '') . ' ORDER BY id DESC' . ($limit ? ' LIMIT ' . intval($limit) : '');
        return (array) $wpdb->get_results($sql);
    }

    private static function status_label($st) {
        return ['open' => 'offen', 'taken' => 'unterwegs', 'done' => 'erledigt', 'no_action' => 'kein Einsatz'][$st] ?? $st;
    }

    private static function reaction_min($r) {
        return $r->taken_at ? round(max(0, strtotime($r->taken_at) - strtotime($r->created)) / 60, 1) : null;
    }

    /** Tabelle auf Tixomat → Awareness (nur Admins). */
    public static function render_admin_alerts() {
        if (!current_user_can('manage_options')) return;
        $rows = self::admin_rows(0, 100);
        ?>
        <hr style="margin:28px 0 8px;">
        <h2>Hinweise „Hilfe vor Ort“</h2>
        <p class="description">Die letzten 100 Hinweise aller Events. Hinweise werden 30 Tage nach dem Event automatisch gelöscht. <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tix_awareness_alerts_csv'), 'tix_awareness_alerts_csv')); ?>">Als CSV exportieren</a></p>
        <?php if (!$rows) : ?><p>Noch keine Hinweise.</p><?php else : ?>
        <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Zeit</th><th>Event</th><th>Art</th><th>Bereich</th><th>Text</th><th>Vorname</th><th>Status</th><th>Reaktion</th></tr></thead><tbody>
        <?php foreach ($rows as $r) : $c = self::category($r->category ?? 'other'); $m = self::reaction_min($r); ?>
            <tr>
                <td><?php echo esc_html(mysql2date('d.m.Y H:i', $r->created)); ?></td>
                <td><?php echo esc_html(html_entity_decode(get_the_title(intval($r->event_id)), ENT_QUOTES, 'UTF-8') ?: '#' . intval($r->event_id)); ?></td>
                <td><?php echo in_array($c, self::PRIORITY, true) ? '<strong>' . esc_html(self::CATEGORIES[$c]) . '</strong>' : esc_html(self::CATEGORIES[$c]); ?></td>
                <td><?php echo esc_html($r->area); ?></td>
                <td><?php echo esc_html($r->text); ?></td>
                <td><?php echo $r->anonymous ? '<span style="color:#888">anonym</span>' : esc_html($r->first_name); ?></td>
                <td><?php echo esc_html(self::status_label($r->status)); ?></td>
                <td><?php echo $m === null ? '–' : esc_html(str_replace('.', ',', (string) $m) . ' min'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif;
    }

    /** admin-post.php?action=tix_awareness_alerts_csv[&event_id=] */
    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_awareness_alerts_csv');
        $rows = self::admin_rows(absint($_GET['event_id'] ?? 0), 0);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="awareness-hinweise-' . current_time('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Zeit', 'Event-ID', 'Event', 'Art', 'Art (Schlüssel)', 'Bereich', 'Text', 'Erkennungszeichen', 'Anonym', 'Vorname', 'Status', 'Übernommen', 'Erledigt', 'Reaktion (min)'], ';');
        foreach ($rows as $r) {
            $c = self::category($r->category ?? 'other');
            fputcsv($out, [
                intval($r->id), $r->created, intval($r->event_id), html_entity_decode(get_the_title(intval($r->event_id)), ENT_QUOTES, 'UTF-8'),
                self::CATEGORIES[$c], $c, $r->area, $r->text, $r->sign, $r->anonymous ? 'ja' : 'nein', $r->anonymous ? '' : $r->first_name,
                self::status_label($r->status), (string) $r->taken_at, (string) $r->done_at, (string) self::reaction_min($r),
            ], ';');
        }
        fclose($out);
        exit;
    }

    // ── Löschung ──

    public static function cleanup() {
        global $wpdb;
        if (get_option('tix_awareness_db') === self::DB_VER) {
            $now = current_time('mysql');
            $wpdb->query($wpdb->prepare("DELETE FROM " . self::t('tix_awareness_alerts') . " WHERE delete_after < %s", $now));
            $ft = self::t('tix_awareness_feedback');
            foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT event_id FROM $ft WHERE delete_after < %s", $now)) as $eid) {
                $sum = get_post_meta($eid, '_tix_awareness_feedback_sum', true);
                $sum = is_array($sum) ? $sum : [];
                foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT answer, COUNT(*) AS n FROM $ft WHERE event_id = %d AND delete_after < %s GROUP BY answer", $eid, $now)) as $r) {
                    $sum[$r->answer] = intval($sum[$r->answer] ?? 0) + intval($r->n);
                }
                update_post_meta($eid, '_tix_awareness_feedback_sum', $sum);
                $wpdb->query($wpdb->prepare("DELETE FROM $ft WHERE event_id = %d AND delete_after < %s", $eid, $now));
            }
        }
        $old = get_posts(['post_type' => 'tix_support_ticket', 'post_status' => self::support_statuses(), 'posts_per_page' => 200, 'fields' => 'ids',
            'date_query' => [['before' => gmdate('Y-m-d H:i:s', current_time('timestamp') - 180 * DAY_IN_SECONDS)]],
            'meta_query' => [['key' => '_tix_sp_category', 'value' => 'awareness']]]);
        foreach ($old as $pid) wp_delete_post($pid, true);
        // Abgelaufene Dienste aufräumen
        foreach (get_users(['meta_key' => self::META_DUTY, 'fields' => 'ID', 'number' => 500]) as $uid) {
            $d = get_user_meta($uid, self::META_DUTY, true);
            if (!is_array($d) || intval($d['until'] ?? 0) < self::now_ts()) delete_user_meta($uid, self::META_DUTY);
        }
    }

    // ── Routen ──

    public static function register_routes() {
        $pub = '__return_true';
        $in  = 'is_user_logged_in';
        register_rest_route(self::NS, '/awareness/status', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_status'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/awareness/alerts', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create_alert'], 'permission_callback' => $pub],
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_team_alerts'], 'permission_callback' => $in],
        ]);
        register_rest_route(self::NS, '/awareness/alerts/(?P<id>\d+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_guest_alert'], 'permission_callback' => $pub]);
        register_rest_route(self::NS, '/awareness/alerts/(?P<id>\d+)/status', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_alert_status'], 'permission_callback' => $in]);
        register_rest_route(self::NS, '/awareness/duty', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_duty'], 'permission_callback' => $in]);
        register_rest_route(self::NS, '/awareness/events', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_events'], 'permission_callback' => $in]);
        register_rest_route(self::NS, '/awareness/report', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_report'], 'permission_callback' => $in]);
        register_rest_route(self::NS, '/awareness/feedback', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_feedback'], 'permission_callback' => $pub],
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_feedback_summary'], 'permission_callback' => $in],
        ]);
        register_rest_route(self::NS, '/awareness/reports', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_reports'], 'permission_callback' => $in]);
    }
}
