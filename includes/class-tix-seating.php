<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Seating – Modul „Saalplan & Tische“ als dazubuchbares Modul (Saalplan Phase 1, 1.38.659).
 *
 * Freischaltung wie Marketing:
 *   Einzel-Seite: gebucht (Option tix_seating_booked bzw. Lizenz mit `seating`), 30-Tage-Test (einmal je Seite)
 *                 oder Bestandsschutz (beim ersten Prüfen hatte schon ein Event einen Saalplan bzw. Tische).
 *   Plattform:    je Veranstalter – im Plattform-Studio eingeschaltet (_tix_org_modules.seating), 30-Tage-Test
 *                 (einmal je Veranstalter) oder Bestandsschutz. Neue Veranstalter: aus.
 *   Immer zusätzlich: Lizenz (TIX_Modules) und der Aus-Schalter der Seite (TIX_Plan::site_switched_off).
 *
 * Studio-Routen (Inhaber/Team-Admin, Plattform: nur eigene Pläne):
 *   GET  /studio/seating                       Modul-Stand, Grenzen, Haltezeit
 *   POST /studio/seating/trial                 30-Tage-Test starten
 *   POST /studio/seating/module {on}           buchen/abschalten (nur Admin; Plattform mit ?organizer=)
 *   GET  /studio/seatmaps                      Liste (Vorschau, Plätze, genutzt bei X Events)
 *   POST /studio/seatmaps                      anlegen {title, data | template}
 *   GET  /studio/seatmaps/templates            Vorlagen (theater, club, banquet, arena)
 *   POST /studio/seatmaps/generate {data}      Vorschau aus Blöcken (ohne Speichern)
 *   POST /studio/seatmaps/from-image           Bild → Saalplan (KI-Entwurf, ohne Speichern)
 *   GET|POST|DELETE /studio/seatmaps/{id}      lesen, speichern (neue Version), löschen
 *   POST /studio/seatmaps/{id}/copy            Plan kopieren
 *   GET  /studio/seatmaps/{id}/revisions/{v}   ältere Fassung lesen
 *   GET|POST /studio/events/{id}/seatmap       Zuweisung + Belegung | zuweisen {seatmap_id, mode}
 *   POST /studio/events/{id}/seatmap/block     Plätze sperren/freigeben {seat_ids[], blocked}
 */
class TIX_Seating {

    const NS = 'tixomat/v1';
    const TRIAL_DAYS = 30;
    const OPT_BOOKED = 'tix_seating_booked';
    const OPT_TRIAL = 'tix_seating_trial_until';
    const OPT_LEGACY = 'tix_seating_legacy';
    const META_TRIAL = '_tix_seating_trial_until';
    const META_LEGACY = '_tix_seating_legacy';
    const OPT_AI_USAGE = 'tix_seating_ai_usage';
    const AI_PER_DAY = 20;
    const AI_MAX_BYTES = 8388608; // 8 MB
    const ACCURACY_NOTE = 'Die KI erkennt die Struktur (Bereiche, Reihen, Bögen) meist zu 80–90 %, die genaue Platzzahl zu 60–80 %. Bitte den Entwurf über dem Bild prüfen und im Editor korrigieren.';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() { return class_exists('TIX_App_Scope') && TIX_App_Scope::multi(); }

    // ═══════════════ Freischaltung ═══════════════

    private static function entitled() {
        return !class_exists('TIX_Modules') || TIX_Modules::allowed('seating');
    }

    /** Lizenz bzw. Plattform-Liste nennt `seating` ausdrücklich = gebucht */
    private static function license_booked() {
        if (!class_exists('TIX_Modules')) return false;
        $e = TIX_Modules::entitled();
        return is_array($e) && in_array('seating', $e, true);
    }

    public static function trial_until($oid = 0) {
        return intval($oid ? get_post_meta(intval($oid), self::META_TRIAL, true) : get_option(self::OPT_TRIAL, 0));
    }
    public static function trial_active($oid = 0) { return self::trial_until($oid) > time(); }

    private static function seatmap_sql_events() {
        global $wpdb;
        return "SELECT pm.post_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = '_tix_seatmap_id' AND pm.meta_value + 0 > 0";
    }

    private static function tables_like() {
        return ['%s:7:"enabled";i:1;%', '%s:7:"enabled";s:1:"1";%', '%s:7:"enabled";b:1;%'];
    }

    /** Bestandsschutz der Seite: beim ersten Prüfen gab es schon Events mit Saalplan oder Tischen (Merker bleibt). */
    public static function legacy_site() {
        $v = get_option(self::OPT_LEGACY, null);
        if ($v === '1' || $v === '0') return $v === '1';
        global $wpdb;
        $has = (bool) $wpdb->get_var(self::seatmap_sql_events() . ' LIMIT 1');
        if (!$has) {
            list($a, $b, $c) = self::tables_like();
            $has = (bool) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tix_table_reservation' AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s) LIMIT 1", $a, $b, $c));
        }
        update_option(self::OPT_LEGACY, $has ? '1' : '0', false);
        return $has;
    }

    /** Bestandsschutz eines Veranstalters (Events mit Saalplan/Tischen beim ersten Prüfen) */
    public static function legacy_org($oid) {
        $oid = intval($oid);
        if (!$oid) return false;
        $v = get_post_meta($oid, self::META_LEGACY, true);
        if ($v === '1' || $v === '0') return $v === '1';
        global $wpdb;
        $has = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->postmeta} o ON o.post_id = pm.post_id AND o.meta_key = '_tix_organizer_id' AND o.meta_value = %s
             WHERE pm.meta_key = '_tix_seatmap_id' AND pm.meta_value + 0 > 0 LIMIT 1", (string) $oid));
        if (!$has) {
            list($a, $b, $c) = self::tables_like();
            $has = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->postmeta} o ON o.post_id = pm.post_id AND o.meta_key = '_tix_organizer_id' AND o.meta_value = %s
                 WHERE pm.meta_key = '_tix_table_reservation' AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s) LIMIT 1", (string) $oid, $a, $b, $c));
        }
        update_post_meta($oid, self::META_LEGACY, $has ? '1' : '0');
        return $has;
    }

    public static function booked_site() {
        return get_option(self::OPT_BOOKED, '0') === '1' || self::license_booked();
    }

    /** Ausdrücklich gespeicherter Plattform-Schalter (true/false) oder null */
    private static function org_switch($oid) {
        if (!class_exists('TIX_Plan')) return null;
        $raw = get_post_meta(intval($oid), TIX_Plan::META, true);
        if (is_string($raw) && $raw !== '') $raw = json_decode($raw, true);
        return (is_array($raw) && array_key_exists('seating', $raw)) ? (bool) $raw['seating'] : null;
    }

    /** Einzel-Seite: Modul nutzbar? */
    public static function site_access() {
        if (!self::entitled()) return false;
        if (class_exists('TIX_Plan') && TIX_Plan::site_switched_off('seating')) return false;
        return self::booked_site() || self::trial_active(0) || self::legacy_site();
    }

    /** Plattform: Modul für Veranstalter nutzbar? (ausdrücklich aus = aus) */
    public static function org_access($oid) {
        if (!self::entitled()) return false;
        $oid = intval($oid);
        if (!$oid) return true;
        $sw = self::org_switch($oid);
        if ($sw === false) return false;
        return $sw === true || self::trial_active($oid) || self::legacy_org($oid);
    }

    /** Für ein Event: Seite bzw. dessen Veranstalter */
    public static function event_on($event_id) {
        if (!self::multi()) return self::site_access();
        $org = intval(get_post_meta(intval($event_id), '_tix_organizer_id', true));
        return $org ? self::org_access($org) : self::entitled();
    }

    /** Bereich der Anfrage: Plattform = Veranstalter (Admin ?organizer=), sonst 0 */
    public static function org(WP_REST_Request $req = null) {
        if (!self::multi()) return 0;
        if (current_user_can('manage_options')) return $req ? max(0, intval($req->get_param('organizer'))) : 0;
        return intval(TIX_App_Scope::organizer_id_for_user());
    }

    public static function on_for($org) {
        return self::multi() ? self::org_access($org) : self::site_access();
    }

    public static function status($org) {
        $multi = self::multi();
        $until = self::trial_until($org);
        $booked = $multi ? ($org ? self::org_switch($org) === true : true) : self::booked_site();
        $legacy = $multi ? ($org ? self::legacy_org($org) : false) : self::legacy_site();
        $on = self::on_for($org);
        $lim = TIX_Seatmap::limits(0);
        return [
            'on' => $on, 'booked' => $booked, 'legacy' => $legacy && !$booked,
            'trial' => self::trial_active($org) && !$booked, 'trial_until' => $until ? gmdate('c', $until) : null,
            'trial_available' => !$on && $until === 0 && self::entitled(), 'entitled' => self::entitled(),
            'hold' => ['minutes' => TIX_Seatmap::RESERVE_MIN, 'checkout_minutes' => TIX_Seatmap::CHECKOUT_MIN, 'max_total_minutes' => $lim['total_min']],
            'limits' => ['per_holder' => $lim['holder'], 'per_ip' => $lim['ip'], 'event_share' => $lim['share'], 'event_min' => $lim['min_event']],
        ];
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        $m = [__CLASS__, 'perm_module'];
        $p = [__CLASS__, 'perm'];
        $id = '(?P<id>\d+)';
        register_rest_route(self::NS, '/studio/seating', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_status'], 'permission_callback' => $p]);
        register_rest_route(self::NS, '/studio/seating/trial', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_trial'], 'permission_callback' => $p]);
        register_rest_route(self::NS, '/studio/seating/module', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_module'], 'permission_callback' => function () { return current_user_can('manage_options'); }]);
        register_rest_route(self::NS, '/studio/seatmaps', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $m],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create'], 'permission_callback' => $m],
        ]);
        register_rest_route(self::NS, '/studio/seatmaps/templates', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_templates'], 'permission_callback' => $m]);
        register_rest_route(self::NS, '/studio/seatmaps/generate', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_generate'], 'permission_callback' => $m]);
        register_rest_route(self::NS, '/studio/seatmaps/from-image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_from_image'], 'permission_callback' => $m]);
        register_rest_route(self::NS, '/studio/seatmaps/' . $id, [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $m],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => $m],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete'], 'permission_callback' => $m],
        ]);
        register_rest_route(self::NS, '/studio/seatmaps/' . $id . '/copy', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_copy'], 'permission_callback' => $m]);
        register_rest_route(self::NS, '/studio/seatmaps/' . $id . '/revisions/(?P<v>\d+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_revision'], 'permission_callback' => $m]);
        register_rest_route(self::NS, '/studio/events/' . $id . '/seatmap', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_event'], 'permission_callback' => [__CLASS__, 'perm_event']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_assign'], 'permission_callback' => [__CLASS__, 'perm_event']],
        ]);
        register_rest_route(self::NS, '/studio/events/' . $id . '/seatmap/block', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_block'], 'permission_callback' => [__CLASS__, 'perm_event']]);
    }

    public static function perm(WP_REST_Request $req) {
        return class_exists('TIX_Studio_Stats') ? TIX_Studio_Stats::perm_manager($req) : current_user_can('manage_options');
    }

    public static function perm_module(WP_REST_Request $req) {
        $p = self::perm($req);
        if ($p !== true) return $p;
        if (!self::on_for(self::org($req))) return new WP_Error('tix_module_off', 'Saalpläne gehören zum Modul „Saalplan & Tische“. Du kannst es 30 Tage kostenlos testen.', ['status' => 403, 'module' => 'seating']);
        return true;
    }

    public static function perm_event(WP_REST_Request $req) {
        $p = self::perm($req);
        if ($p !== true) return $p;
        $id = intval($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::can_access_event($id)) return new WP_Error('rest_forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        // Lesen geht auch ohne Modul (Bestand ansehen), Ändern nur mit Modul
        if ($req->get_method() !== 'GET' && !self::event_on($id)) return new WP_Error('tix_module_off', 'Saalpläne gehören zum Modul „Saalplan & Tische“.', ['status' => 403, 'module' => 'seating']);
        return true;
    }

    private static function bad($field, $msg, $code = 'tix_invalid_field', $status = 400) {
        return new WP_Error($code, $msg, ['status' => $status, 'field' => $field]);
    }

    /** Plan gehört zum Bereich der Anfrage? */
    private static function plan($req) {
        $id = intval($req['id']);
        if (get_post_type($id) !== TIX_Seatmap::CPT || get_post_status($id) === 'trash') return new WP_Error('tix_seatmap', 'Saalplan nicht gefunden.', ['status' => 404]);
        if (self::multi()) {
            $org = self::org($req);
            $admin = current_user_can('manage_options');
            if ((!$admin || $org) && TIX_Seatmap::org_of($id) !== $org) return new WP_Error('tix_seatmap', 'Saalplan nicht gefunden.', ['status' => 404]);
        }
        return $id;
    }

    // ═══════════════ Modul ═══════════════

    public static function rest_status(WP_REST_Request $req) {
        return rest_ensure_response(['ok' => true, 'module' => self::status(self::org($req))]);
    }

    public static function rest_trial(WP_REST_Request $req) {
        $org = self::org($req);
        if (self::multi() && !$org) return self::bad('organizer', 'Bitte einen Veranstalter wählen.');
        if (!self::entitled()) return new WP_Error('tix_module_disabled', 'Dieses Modul ist nicht in deinem Paket. Du kannst es im Studio anfragen.', ['status' => 403, 'module' => 'seating']);
        if (self::trial_until($org) > 0) return self::bad('trial', 'Der Test lief hier schon.', 'tix_trial_used', 409);
        if (self::on_for($org)) return self::bad('trial', 'Das Modul ist schon an.', 'tix_trial_not_needed', 409);
        $until = time() + self::TRIAL_DAYS * DAY_IN_SECONDS;
        if ($org) update_post_meta($org, self::META_TRIAL, $until); else update_option(self::OPT_TRIAL, $until, false);
        self::flush();
        return self::rest_status($req);
    }

    /** Admin: Modul gebucht an/aus (Plattform: ?organizer=) */
    public static function rest_module(WP_REST_Request $req) {
        $on = filter_var($req->get_param('on'), FILTER_VALIDATE_BOOLEAN);
        $org = self::org($req);
        if (self::multi()) {
            if (!$org) return self::bad('organizer', 'Bitte einen Veranstalter wählen.');
            TIX_Plan::save_org($org, ['seating' => $on]);
        } else {
            update_option(self::OPT_BOOKED, $on ? '1' : '0', false);
            if (!$on) update_option(self::OPT_LEGACY, '0', false); // Abschalten beendet den Bestandsschutz
        }
        self::flush();
        return self::rest_status($req);
    }

    private static function flush() {
        if (class_exists('TIX_Public_Events') && method_exists('TIX_Public_Events', 'flush')) TIX_Public_Events::flush();
    }

    // ═══════════════ Pläne ═══════════════

    /** Kleine Vorschau: höchstens 400 Punkte [x, y, Sektion] im Rahmen w×h */
    public static function preview($data) {
        $pts = [];
        foreach (($data['sections'] ?? []) as $si => $sec) foreach (($sec['rows'] ?? []) as $row) foreach (($row['seats'] ?? []) as $s) $pts[] = [round($s['x']), round($s['y']), $si];
        $step = max(1, (int) ceil(count($pts) / 400));
        $out = [];
        foreach ($pts as $i => $p) if ($i % $step === 0) $out[] = $p;
        return ['w' => intval($data['width'] ?? 0), 'h' => intval($data['height'] ?? 0), 'colors' => array_map(function ($s) { return $s['color'] ?? '#FF5500'; }, (array) ($data['sections'] ?? [])),
            'dots' => $out, 'objects' => array_values(array_map(function ($o) { return array_intersect_key($o, array_flip(['type', 'x', 'y', 'w', 'h', 'label'])); }, (array) ($data['objects'] ?? [])))];
    }

    private static function events_info($sm) {
        $out = [];
        foreach (TIX_Seatmap::events_using($sm) as $ev) {
            $out[] = ['id' => $ev, 'title' => get_the_title($ev), 'date' => (string) get_post_meta($ev, '_tix_date_start', true),
                'status' => get_post_status($ev), 'sold' => count(TIX_Seatmap::sold_seats($ev)), 'version' => TIX_Seatmap::event_version($ev)];
        }
        return $out;
    }

    private static function summary($id) {
        $data = TIX_Seatmap::data($id) ?: ['sections' => []];
        $events = self::events_info($id);
        return [
            'id' => $id, 'title' => get_the_title($id), 'version' => TIX_Seatmap::version($id),
            'seats' => TIX_Seatmap::count_seats($data), 'sections' => count((array) ($data['sections'] ?? [])),
            'layout' => (string) ($data['layout'] ?? 'theater'),
            'updated' => get_post_modified_time('c', true, $id), 'events_count' => count($events), 'events' => $events,
            'preview' => self::preview($data),
        ];
    }

    public static function rest_list(WP_REST_Request $req) {
        $org = self::org($req);
        $ids = get_posts(['post_type' => TIX_Seatmap::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC']);
        $out = [];
        foreach ($ids as $id) {
            if (self::multi() && (!current_user_can('manage_options') || $org) && TIX_Seatmap::org_of($id) !== $org) continue;
            $out[] = self::summary(intval($id));
        }
        return rest_ensure_response(['ok' => true, 'seatmaps' => $out]);
    }

    public static function rest_get(WP_REST_Request $req) {
        $id = self::plan($req);
        if (is_wp_error($id)) return $id;
        $data = TIX_Seatmap::data($id) ?: ['sections' => [], 'objects' => []];
        $check = $data['sections'] ? TIX_Seatmap_Geometry::check($data) : ['seats' => 0, 'bookable' => 0];
        $revs = get_post_meta($id, TIX_Seatmap::META_REVS, true);
        $revs = array_map(function ($r) { return ['v' => intval($r['v']), 'at' => (string) $r['at'], 'user' => intval($r['user'])]; }, is_array($revs) ? $revs : []);
        return rest_ensure_response(['ok' => true] + self::summary($id) + ['data' => $data, 'stats' => $check, 'revisions' => array_reverse($revs)]);
    }

    private static function new_post($title, $org) {
        $id = wp_insert_post(['post_type' => TIX_Seatmap::CPT, 'post_status' => 'publish', 'post_title' => $title, 'post_author' => get_current_user_id()], true);
        if (is_wp_error($id)) return $id;
        if (self::multi()) update_post_meta($id, TIX_Seatmap::META_ORG, intval($org));
        return intval($id);
    }

    public static function rest_create(WP_REST_Request $req) {
        $title = sanitize_text_field((string) $req->get_param('title'));
        $tpl = sanitize_key((string) $req->get_param('template'));
        $data = $req->get_param('data');
        if ($tpl !== '') {
            $t = self::templates();
            if (!isset($t[$tpl])) return self::bad('template', 'Unbekannte Vorlage.');
            $data = $t[$tpl]['data'];
            if ($title === '') $title = $t[$tpl]['label'];
        }
        if ($title === '') return self::bad('title', 'Bitte einen Namen eingeben.');
        if (!is_array($data) && !is_string($data)) return self::bad('data', 'Bitte einen Plan oder eine Vorlage wählen.');
        if (!TIX_Seatmap::sanitize_seatmap($data)) return self::bad('data', 'Der Saalplan ist leer oder ungültig.');
        $org = self::org($req);
        if (self::multi() && !$org && !current_user_can('manage_options')) return new WP_Error('rest_forbidden', 'Kein Veranstalter.', ['status' => 403]);
        $id = self::new_post($title, $org);
        if (is_wp_error($id)) return $id;
        $r = TIX_Seatmap::store($id, $data);
        if (is_wp_error($r)) { wp_delete_post($id, true); return $r; }
        $req->set_param('id', $id);
        $res = self::rest_get($req);
        $res->set_status(201);
        return $res;
    }

    public static function rest_save(WP_REST_Request $req) {
        $id = self::plan($req);
        if (is_wp_error($id)) return $id;
        $title = $req->get_param('title');
        if ($title !== null) {
            $title = sanitize_text_field((string) $title);
            if ($title === '') return self::bad('title', 'Bitte einen Namen eingeben.');
            wp_update_post(['ID' => $id, 'post_title' => $title]);
        }
        $result = null;
        if ($req->get_param('data') !== null) {
            $result = TIX_Seatmap::store($id, $req->get_param('data'), ['keep_old_for_sold' => filter_var($req->get_param('keep_old_for_sold'), FILTER_VALIDATE_BOOLEAN)]);
            if (is_wp_error($result)) return $result;
        }
        $res = self::rest_get($req);
        $d = $res->get_data();
        if ($result) $d += ['kept' => $result['kept'], 'upgraded' => $result['upgraded']];
        self::flush();
        return rest_ensure_response($d);
    }

    public static function rest_delete(WP_REST_Request $req) {
        $id = self::plan($req);
        if (is_wp_error($id)) return $id;
        $ev = TIX_Seatmap::events_using($id);
        if ($ev) return new WP_Error('tix_seatmap_in_use', sprintf('Der Saalplan ist %d Event(s) zugewiesen. Bitte zuerst dort entfernen.', count($ev)), ['status' => 409, 'events' => $ev]);
        wp_delete_post($id, true);
        return rest_ensure_response(['ok' => true, 'deleted' => $id]);
    }

    public static function rest_copy(WP_REST_Request $req) {
        $id = self::plan($req);
        if (is_wp_error($id)) return $id;
        $title = sanitize_text_field((string) $req->get_param('title')) ?: get_the_title($id) . ' (Kopie)';
        $new = self::new_post($title, self::multi() ? TIX_Seatmap::org_of($id) : 0);
        if (is_wp_error($new)) return $new;
        $data = TIX_Seatmap::data($id);
        if ($data) { unset($data['version']); TIX_Seatmap::store($new, $data); }
        $req->set_param('id', $new);
        $res = self::rest_get($req);
        $res->set_status(201);
        return $res;
    }

    public static function rest_revision(WP_REST_Request $req) {
        $id = self::plan($req);
        if (is_wp_error($id)) return $id;
        $v = intval($req['v']);
        foreach ((array) get_post_meta($id, TIX_Seatmap::META_REVS, true) as $r) {
            if (intval($r['v'] ?? 0) === $v) return rest_ensure_response(['ok' => true, 'id' => $id, 'v' => $v, 'at' => $r['at'], 'data' => json_decode((string) $r['data'], true)]);
        }
        if ($v === TIX_Seatmap::version($id)) return rest_ensure_response(['ok' => true, 'id' => $id, 'v' => $v, 'data' => TIX_Seatmap::data($id)]);
        return new WP_Error('tix_seatmap', 'Diese Fassung gibt es nicht mehr.', ['status' => 404]);
    }

    /** POST /studio/seatmaps/generate {data} → aufgebauter Plan + Prüfung (nichts gespeichert) */
    public static function rest_generate(WP_REST_Request $req) {
        $data = TIX_Seatmap::sanitize_seatmap($req->get_param('data'));
        if (!$data) return self::bad('data', 'Der Saalplan ist leer oder ungültig.');
        $check = TIX_Seatmap_Geometry::check($data);
        return rest_ensure_response(['ok' => true, 'data' => $data, 'stats' => $check]);
    }

    // ═══════════════ Vorlagen ═══════════════

    public static function templates() {
        $c = ['#E8445A', '#3D7BF7', '#1F8F5F', '#E8B931', '#8E5CF7', '#F28C28'];
        $raw = [
            'theater' => ['Theater', 'Parkett und Rang im Bogen vor der Bühne', ['layout' => 'theater', 'stage_label' => 'BÜHNE', 'sections' => [
                ['id' => 'parkett', 'label' => 'Parkett', 'color' => $c[0], 'block' => ['rows' => 12, 'seats' => 20, 'curve' => 0.25, 'gaps' => [['after' => 10, 'width' => 1.5]], 'origin' => ['x' => 0, 'y' => 80]]],
                ['id' => 'rang', 'label' => 'Rang', 'color' => $c[1], 'block' => ['rows' => 5, 'seats' => 26, 'curve' => 0.3, 'row_start' => 'M', 'origin' => ['x' => 0, 'y' => 520]]],
            ], 'objects' => [['type' => 'stage', 'x' => 0, 'y' => 0, 'w' => 420, 'h' => 50, 'label' => 'BÜHNE']]]],
            'club' => ['Club', 'Stehbereich vor der Bühne, Lounge-Tische an der Seite', ['layout' => 'club', 'stage_label' => 'DJ', 'sections' => [
                ['id' => 'lounge', 'label' => 'Lounge', 'color' => $c[4], 'block' => ['shape' => 'tables', 'tables' => 6, 'seats_per_table' => 6, 'cols' => 2, 'origin' => ['x' => 380, 'y' => 120]]],
            ], 'objects' => [
                ['type' => 'stage', 'x' => 0, 'y' => 0, 'w' => 260, 'h' => 60, 'label' => 'DJ'],
                ['type' => 'standing', 'x' => 0, 'y' => 230, 'w' => 420, 'h' => 300, 'label' => 'Tanzfläche', 'capacity' => 300],
                ['type' => 'label', 'x' => -300, 'y' => 230, 'w' => 80, 'h' => 300, 'label' => 'Bar'],
            ]]],
            'banquet' => ['Bankett', 'Runde Tische für Gala und Dinner', ['layout' => 'banquet', 'stage_label' => 'BÜHNE', 'sections' => [
                ['id' => 'saal', 'label' => 'Saal', 'color' => $c[2], 'block' => ['shape' => 'tables', 'tables' => 12, 'seats_per_table' => 8, 'cols' => 4, 'origin' => ['x' => 0, 'y' => 140]]],
            ], 'objects' => [['type' => 'stage', 'x' => 0, 'y' => 0, 'w' => 360, 'h' => 60, 'label' => 'BÜHNE']]]],
            'arena' => ['Arena', 'Vier Tribünen rund um die Fläche', ['layout' => 'arena', 'stage_label' => 'FLÄCHE', 'sections' => [
                ['id' => 'nord', 'label' => 'Nord', 'color' => $c[0], 'block' => ['rows' => 8, 'seats' => 18, 'origin' => ['x' => 0, 'y' => -260], 'angle' => 180]],
                ['id' => 'sued', 'label' => 'Süd', 'color' => $c[1], 'block' => ['rows' => 8, 'seats' => 18, 'origin' => ['x' => 0, 'y' => 260]]],
                ['id' => 'ost', 'label' => 'Ost', 'color' => $c[2], 'block' => ['rows' => 8, 'seats' => 12, 'origin' => ['x' => 360, 'y' => 0], 'angle' => -90]],
                ['id' => 'west', 'label' => 'West', 'color' => $c[3], 'block' => ['rows' => 8, 'seats' => 12, 'origin' => ['x' => -360, 'y' => 0], 'angle' => 90]],
            ], 'objects' => [['type' => 'stage', 'x' => 0, 'y' => 0, 'w' => 480, 'h' => 300, 'label' => 'FLÄCHE']]]],
        ];
        $out = [];
        foreach ($raw as $k => list($label, $desc, $data)) {
            $built = TIX_Seatmap::sanitize_seatmap($data);
            $out[$k] = ['key' => $k, 'label' => $label, 'description' => $desc, 'seats' => TIX_Seatmap::count_seats($built), 'data' => $built];
        }
        return $out;
    }

    public static function rest_templates() {
        return rest_ensure_response(['ok' => true, 'templates' => array_values(self::templates())]);
    }

    // ═══════════════ Event: Zuweisung, Belegung, Sperren ═══════════════

    public static function rest_event(WP_REST_Request $req) {
        global $wpdb;
        $ev = intval($req['id']);
        $sm = TIX_Seatmap::event_seatmap($ev);
        $org = self::multi() ? intval(get_post_meta($ev, '_tix_organizer_id', true)) : null;
        $plans = [];
        foreach (TIX_Seatmap::get_all_seatmaps($org ?: null) as $pid => $t) $plans[] = ['id' => $pid, 'title' => $t, 'seats' => intval(get_post_meta($pid, TIX_Seatmap::META_SEATS, true))];
        $base = ['ok' => true, 'event_id' => $ev, 'module_on' => self::event_on($ev), 'seatmap_id' => $sm, 'plans' => $plans,
            'mode' => get_post_meta($ev, '_tix_seatmap_mode', true) ?: 'manual'];
        if (!$sm) return rest_ensure_response($base + ['can_change' => true]);
        $data = TIX_Seatmap::event_data($ev);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.seat_id, r.status, r.order_id, r.expires_at, (r.expires_at > UTC_TIMESTAMP()) AS valid, o.order_number
             FROM {$wpdb->prefix}" . TIX_Seatmap::TABLE_SUFFIX . " r LEFT JOIN {$wpdb->prefix}tix_orders o ON o.id = r.order_id
             WHERE r.event_id = %d", $ev));
        $state = [];
        foreach ((array) $rows as $r) {
            if ($r->status === 'reserved' && !intval($r->valid)) continue;
            $s = $r->status === 'reserved' ? 'held' : $r->status;
            $one = ['status' => $s];
            if ($r->order_id) { $one['order_id'] = intval($r->order_id); if ($r->order_number) $one['order_number'] = $r->order_number; }
            if ($s === 'held') { $one['expires_at'] = gmdate('c', strtotime($r->expires_at . ' UTC')); $one['in_checkout'] = (bool) $r->order_id; }
            if (!isset($state[$r->seat_id]) || $s === 'sold') $state[$r->seat_id] = $one;
        }
        $counts = ['total' => 0, 'free' => 0, 'held' => 0, 'sold' => 0, 'blocked' => 0, 'unavailable' => 0];
        $sections = [];
        foreach (($data['sections'] ?? []) as $sec) {
            $c = ['id' => $sec['id'], 'label' => $sec['label'], 'color' => $sec['color'], 'price' => floatval($sec['price'] ?? 0), 'total' => 0, 'free' => 0, 'held' => 0, 'sold' => 0, 'blocked' => 0];
            foreach (($sec['rows'] ?? []) as $row) foreach (($row['seats'] ?? []) as $s) {
                if (($s['type'] ?? 'standard') === 'blocked') { $counts['unavailable']++; continue; }
                $st = $state[$s['id']]['status'] ?? 'free';
                $c['total']++; $c[$st]++; $counts['total']++; $counts[$st]++;
            }
            $sections[] = $c;
        }
        $sold = $counts['sold'] > 0;
        return rest_ensure_response($base + [
            'title' => get_the_title($sm), 'version' => TIX_Seatmap::event_version($ev), 'plan_version' => TIX_Seatmap::version($sm),
            'outdated' => TIX_Seatmap::event_version($ev) < TIX_Seatmap::version($sm), 'can_change' => !$sold,
            'counts' => $counts, 'sections' => $sections, 'seats' => (object) $state, 'data' => $data,
        ]);
    }

    public static function rest_assign(WP_REST_Request $req) {
        $ev = intval($req['id']);
        $sm = intval($req->get_param('seatmap_id'));
        $mode = $req->get_param('mode');
        if ($mode !== null && !in_array($mode, ['manual', 'best'], true)) return self::bad('mode', 'Modus manual oder best.');
        if ($sm && self::multi()) {
            $org = intval(get_post_meta($ev, '_tix_organizer_id', true));
            if ($org && TIX_Seatmap::org_of($sm) !== $org) return new WP_Error('tix_seatmap', 'Saalplan nicht gefunden.', ['status' => 404]);
        }
        $r = TIX_Seatmap::assign($ev, $sm, $mode);
        if (is_wp_error($r)) return $r;
        return self::rest_event($req);
    }

    /** POST /studio/events/{id}/seatmap/block {seat_ids[], blocked} – nur freie Plätze lassen sich sperren */
    public static function rest_block(WP_REST_Request $req) {
        global $wpdb;
        $ev = intval($req['id']);
        $sm = TIX_Seatmap::event_seatmap($ev);
        if (!$sm) return new WP_Error('tix_no_seatmap', 'Diesem Event ist kein Saalplan zugewiesen.', ['status' => 404]);
        $data = TIX_Seatmap::event_data($ev);
        $ids = array_slice(array_values(array_unique(array_map('sanitize_text_field', (array) $req->get_param('seat_ids')))), 0, 2000);
        if (!$ids) return self::bad('seat_ids', 'Bitte Plätze wählen.');
        $block = filter_var($req->get_param('blocked'), FILTER_VALIDATE_BOOLEAN);
        $t = $wpdb->prefix . TIX_Seatmap::TABLE_SUFFIX;
        $done = []; $skipped = [];
        $lock = 'tix_seat_' . $ev;
        $wpdb->query($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        foreach ($ids as $sid) {
            if (!TIX_Seatmap::section_of($data, $sid)) { $skipped[] = $sid; continue; }
            if ($block) {
                $busy = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE event_id = %d AND seat_id = %s AND (status IN ('sold','blocked') OR (status = 'reserved' AND expires_at > UTC_TIMESTAMP())) LIMIT 1", $ev, $sid));
                if ($busy) { $skipped[] = $sid; continue; }
                $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE event_id = %d AND seat_id = %s AND status = 'reserved'", $ev, $sid));
                $ok = $wpdb->insert($t, ['event_id' => $ev, 'seatmap_id' => $sm, 'seat_id' => $sid, 'status' => 'blocked', 'session_id' => 'staff_' . get_current_user_id(), 'reserved_at' => gmdate('Y-m-d H:i:s')]);
                if ($ok) $done[] = $sid; else $skipped[] = $sid;
            } else {
                $n = $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE event_id = %d AND seat_id = %s AND status = 'blocked'", $ev, $sid));
                if ($n) $done[] = $sid; else $skipped[] = $sid;
            }
        }
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        $res = self::rest_event($req)->get_data();
        return rest_ensure_response($res + ['changed' => $done, 'skipped' => $skipped]);
    }

    // ═══════════════ Bild → Saalplan (KI) ═══════════════

    /** Tool-Schema für parametrische Blöcke (Koordinaten im Bildrahmen, Breite 1000) */
    public static function tool() {
        $num = ['type' => 'number'];
        $block = ['type' => 'object', 'properties' => [
            'shape' => ['type' => 'string', 'enum' => ['rows', 'tables']],
            'origin' => ['type' => 'object', 'properties' => ['x' => $num, 'y' => $num], 'required' => ['x', 'y'], 'description' => 'Mitte der ersten (bühnennächsten) Reihe bzw. des ersten Tisches im Bildrahmen'],
            'rows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'seats' => ['description' => 'Plätze je Reihe: eine Zahl oder eine Liste je Reihe (vorne zuerst)', 'anyOf' => [['type' => 'integer'], ['type' => 'array', 'items' => ['type' => 'integer']]]],
            'seat_spacing' => $num, 'row_spacing' => $num,
            'curve' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => '0 = gerade Reihen, 1 = stark gebogen'],
            'angle' => ['type' => 'number', 'description' => 'Drehung in Grad; 0 = Reihen laufen von der Bühne weg nach unten (Bühne oben)'],
            'gaps' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['after' => ['type' => 'integer'], 'width' => $num], 'required' => ['after']]],
            'row_labels' => ['type' => 'string', 'enum' => ['alpha', 'numeric']], 'row_start' => ['type' => 'string'],
            'seat_start' => ['type' => 'integer'], 'numbering' => ['type' => 'string', 'enum' => ['ltr', 'rtl']],
            'tables' => ['type' => 'integer'], 'seats_per_table' => ['type' => 'integer'], 'table_shape' => ['type' => 'string', 'enum' => ['round', 'rect']], 'cols' => ['type' => 'integer'],
        ], 'required' => ['origin']];
        return [
            'name' => 'saalplan',
            'description' => 'Beschreibt den Saalplan aus dem Bild als parametrische Blöcke. Ein deterministischer Generator erzeugt daraus die einzelnen Plätze.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'frame' => ['type' => 'object', 'properties' => ['width' => $num, 'height' => $num], 'required' => ['width', 'height'], 'description' => 'Bildrahmen der Koordinaten: Breite 1000, Höhe nach Seitenverhältnis'],
                'layout' => ['type' => 'string', 'enum' => ['theater', 'club', 'banquet', 'arena', 'custom']],
                'sections' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'label' => ['type' => 'string'], 'price' => $num, 'block' => $block,
                ], 'required' => ['label', 'block']]],
                'objects' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['stage', 'standing', 'label', 'area']], 'x' => $num, 'y' => $num, 'w' => $num, 'h' => $num,
                    'label' => ['type' => 'string'], 'capacity' => ['type' => 'integer'],
                ], 'required' => ['type', 'x', 'y']]],
                'total_seats_estimate' => ['type' => 'integer'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'notes' => ['type' => 'string', 'description' => 'Kurze Hinweise auf Deutsch, was unsicher ist'],
            ], 'required' => ['frame', 'sections']],
        ];
    }

    private static function ai_count($scope, $inc = false) {
        $u = get_option(self::OPT_AI_USAGE, []);
        $u = is_array($u) ? $u : [];
        $d = current_time('Y-m-d');
        foreach (array_keys($u) as $k) if ($k < gmdate('Y-m-d', time() - 3 * DAY_IN_SECONDS)) unset($u[$k]);
        $n = intval($u[$d][intval($scope)] ?? 0);
        if ($inc) { $u[$d][intval($scope)] = $n + 1; update_option(self::OPT_AI_USAGE, $u, false); }
        return $inc ? $n + 1 : $n;
    }

    /** Bild aus Upload, Data-URL/Base64 oder Anhang → [media_type, bytes] | WP_Error */
    private static function read_image(WP_REST_Request $req) {
        $files = $req->get_file_params();
        $bytes = null; $type = '';
        if (!empty($files['image']['tmp_name']) && is_uploaded_file($files['image']['tmp_name'])) {
            $bytes = file_get_contents($files['image']['tmp_name']);
            $type = (string) ($files['image']['type'] ?? '');
        } elseif (($b64 = (string) $req->get_param('image')) !== '') {
            if (preg_match('#^data:([a-z/+.-]+);base64,(.+)$#is', $b64, $m)) { $type = strtolower($m[1]); $b64 = $m[2]; }
            else $type = (string) $req->get_param('media_type');
            $bytes = base64_decode(preg_replace('/\s+/', '', $b64), true);
        } elseif (($aid = intval($req->get_param('attachment_id'))) > 0) {
            if (get_post_type($aid) !== 'attachment' || (!current_user_can('manage_options') && intval(get_post_field('post_author', $aid)) !== get_current_user_id())) return self::bad('attachment_id', 'Bild nicht gefunden.', 'tix_image', 404);
            $f = get_attached_file($aid);
            if ($f && is_readable($f)) { $bytes = file_get_contents($f); $type = (string) get_post_mime_type($aid); }
        }
        if (!$bytes) return self::bad('image', 'Bitte ein Bild des Saals hochladen.', 'tix_image');
        if (strlen($bytes) > self::AI_MAX_BYTES) return self::bad('image', 'Das Bild ist zu groß (höchstens 8 MB).', 'tix_image', 413);
        // Typ aus dem Inhalt (nicht dem Dateinamen trauen)
        $sig = substr($bytes, 0, 12);
        if (strncmp($sig, "\xFF\xD8\xFF", 3) === 0) $type = 'image/jpeg';
        elseif (strncmp($sig, "\x89PNG", 4) === 0) $type = 'image/png';
        elseif (strncmp($sig, 'GIF8', 4) === 0) $type = 'image/gif';
        elseif (substr($sig, 0, 4) === 'RIFF' && substr($sig, 8, 4) === 'WEBP') $type = 'image/webp';
        elseif (strncmp($sig, '%PDF', 4) === 0) $type = 'application/pdf';
        else return self::bad('image', 'Bitte ein Bild (JPG, PNG, WebP) oder ein PDF hochladen.', 'tix_image', 415);
        return [$type, $bytes];
    }

    /** PDF → PNG (erste Seite, Imagick) bzw. große Bilder auf 1568 px verkleinern → [type, bytes, w, h] */
    private static function prepare_image($type, $bytes) {
        $dir = get_temp_dir();
        if ($type === 'application/pdf') {
            if (!class_exists('Imagick')) return null; // ohne Imagick: als Dokument an Claude
            try {
                $im = new Imagick();
                $im->setResolution(150, 150);
                $im->readImageBlob($bytes . '');
                $im->setIteratorIndex(0);
                $im->setImageFormat('png');
                $im->setImageBackgroundColor('white');
                $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $bytes = $im->getImageBlob();
                $type = 'image/png';
            } catch (\Throwable $e) {
                return null;
            }
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$type] ?? 'jpg';
        $tmp = wp_tempnam('tix-seatmap') . '.' . $ext;
        file_put_contents($tmp, $bytes);
        $w = 0; $h = 0;
        $sz = @getimagesize($tmp);
        if ($sz) { $w = intval($sz[0]); $h = intval($sz[1]); }
        if (max($w, $h) > 1568 && function_exists('wp_get_image_editor')) {
            $ed = wp_get_image_editor($tmp);
            if (!is_wp_error($ed)) {
                $ed->resize(1568, 1568, false);
                $out = $ed->save($tmp . '-s.jpg', 'image/jpeg');
                if (!is_wp_error($out) && !empty($out['path'])) {
                    $bytes = file_get_contents($out['path']); $type = 'image/jpeg';
                    $w = intval($out['width']); $h = intval($out['height']);
                    @unlink($out['path']);
                }
            }
        }
        @unlink($tmp);
        @unlink(preg_replace('/\.' . $ext . '$/', '', $tmp));
        return [$type, $bytes, $w, $h];
    }

    /**
     * POST /studio/seatmaps/from-image {image (Datei | Data-URL | Base64 + media_type) | attachment_id, total_seats?, hint?, title?}
     * → {draft {title, data}, stats, check {expected, got, diff, diff_pct}, overlay {frame, shift}, ai {confidence, notes, estimate}, accuracy_note, usage}
     */
    public static function rest_from_image(WP_REST_Request $req) {
        if (!class_exists('TIX_AI_Provider')) return new WP_Error('ai_unavailable', 'Die KI ist hier nicht eingerichtet.', ['status' => 503]);
        $org = self::org($req);
        if (!TIX_AI_Provider::ready($org)) return new WP_Error('ai_unavailable', 'Die KI ist hier nicht eingerichtet (kein API-Schlüssel). Du kannst ihn im Studio unter Einstellungen → KI hinterlegen.', ['status' => 503]);
        if (self::ai_count($org) >= self::AI_PER_DAY) return new WP_Error('tix_rate_limit', sprintf('Heute sind schon %d Bilder ausgewertet. Morgen geht es weiter.', self::AI_PER_DAY), ['status' => 429]);
        $img = self::read_image($req);
        if (is_wp_error($img)) return $img;
        list($type, $bytes) = $img;
        $prep = self::prepare_image($type, $bytes);
        $w = 0; $h = 0;
        if ($prep) list($type, $bytes, $w, $h) = $prep;
        elseif ($type === 'application/pdf' && (TIX_AI_Provider::effective($org)['provider'] ?? '') !== 'claude') {
            return self::bad('image', 'Bitte das PDF als Bild hochladen (JPG oder PNG).', 'tix_image', 415);
        }
        $expected = max(0, intval($req->get_param('total_seats')));
        $hint = sanitize_textarea_field((string) $req->get_param('hint'));
        $media = $type === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $type, 'data' => base64_encode($bytes)]];
        $prompt = "Das ist ein Saalplan (Foto, Skizze oder Grafik). Beschreibe ihn als parametrische Blöcke mit dem Werkzeug saalplan.\n"
            . "- Koordinaten im Bildrahmen: Breite 1000, Höhe nach Seitenverhältnis" . ($w && $h ? ' (Bild ' . $w . '×' . $h . ' px → Höhe ' . round(1000 * $h / $w) . ')' : '') . ".\n"
            . "- Je zusammenhängendem Sitzbereich ein Block (Parkett links/rechts, Rang, Loge …). Reihen mit unterschiedlicher Länge als Liste.\n"
            . "- origin = Mitte der bühnennächsten Reihe; angle so, dass die Reihen von der Bühne weg laufen (Bühne oben = 0°); curve für gebogene Reihen; seat_spacing/row_spacing in Rahmen-Einheiten.\n"
            . "- Gänge innerhalb einer Reihe als gaps. Tische als shape tables. Bühne und Stehflächen (mit Kapazität) als objects.\n"
            . "- Zähle sorgfältig; wenn du unsicher bist, schreibe es in notes und setze confidence niedriger."
            . ($expected ? "\n- Der Veranstalter nennt insgesamt {$expected} Plätze." : '')
            . ($hint !== '' ? "\n- Hinweis des Veranstalters: " . $hint : '');
        $payload = ['max_tokens' => 4096, 'tools' => [self::tool()], 'tool_choice' => ['type' => 'tool', 'name' => 'saalplan'],
            'messages' => [['role' => 'user', 'content' => [$media, ['type' => 'text', 'text' => $prompt]]]]];
        $res = TIX_AI_Provider::with_org($org, function () use ($payload) { return TIX_AI_Provider::send($payload, 90); });
        if (is_wp_error($res)) return $res;
        $code = intval(wp_remote_retrieve_response_code($res));
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code !== 200 || !is_array($body)) return new WP_Error('ai_failed', 'Die KI konnte das Bild gerade nicht auswerten. Bitte später noch einmal versuchen.', ['status' => 502, 'detail' => (string) ($body['error']['message'] ?? '')]);
        $input = null;
        foreach ((array) ($body['content'] ?? []) as $c) if (($c['type'] ?? '') === 'tool_use' && ($c['name'] ?? '') === 'saalplan') $input = (array) $c['input'];
        self::ai_count($org, true);
        if (!$input || empty($input['sections'])) return new WP_Error('ai_no_plan', 'Auf dem Bild hat die KI keinen Saalplan erkannt. Versuche ein schärferes Bild oder eine Skizze.', ['status' => 422]);
        return rest_ensure_response(self::draft_from_ai($input, $expected, sanitize_text_field((string) $req->get_param('title')), $org));
    }

    /** KI-Antwort → Entwurf (Generator + Prüfung) */
    public static function draft_from_ai(array $in, $expected = 0, $title = '', $org = 0) {
        $palette = ['#E8445A', '#3D7BF7', '#1F8F5F', '#E8B931', '#8E5CF7', '#F28C28', '#14B8A6', '#EC4899'];
        $sections = []; $used = [];
        foreach (array_slice((array) $in['sections'], 0, 40) as $i => $s) {
            if (!is_array($s) || empty($s['block']) || !is_array($s['block'])) continue;
            $label = sanitize_text_field((string) ($s['label'] ?? '')) ?: 'Bereich ' . ($i + 1);
            $id = sanitize_key(remove_accents($label)) ?: 's' . ($i + 1);
            $id = substr(str_replace('_', '-', $id), 0, 24);
            $base = $id; $n = 2;
            while (isset($used[$id])) $id = $base . '-' . $n++;
            $used[$id] = true;
            $sections[] = ['id' => $id, 'label' => $label, 'color' => $palette[$i % count($palette)], 'price' => max(0, floatval($s['price'] ?? 0)), 'block' => $s['block']];
        }
        $plan = ['layout' => in_array($in['layout'] ?? '', ['theater', 'club', 'banquet', 'arena', 'custom'], true) ? $in['layout'] : 'custom',
            'stage_label' => 'BÜHNE', 'sections' => $sections, 'objects' => array_slice((array) ($in['objects'] ?? []), 0, 50)];
        $data = TIX_Seatmap::sanitize_seatmap($plan);
        if (!$data) return ['ok' => false, 'error' => 'Kein verwertbarer Plan.'];
        // Verschiebung gegenüber dem Bildrahmen (für die halbtransparente Überlagerung im Studio):
        // derselbe Aufbau ohne Normalisieren, Abstand des ersten Platzes
        $stage = [];
        foreach ((array) $plan['objects'] as $o) if (is_array($o) && ($o['type'] ?? '') === 'stage') { $stage = [['type' => 'stage', 'x' => 0, 'y' => 0]]; break; }
        $raw = TIX_Seatmap_Geometry::build(['sections' => array_map(function ($s) { return ['id' => $s['id'], 'block' => $s['block'], 'rows' => []]; }, $sections), 'objects' => $stage], false);
        $shift = ['x' => 0, 'y' => 0];
        $a = $data['sections'][0]['rows'][0]['seats'][0] ?? null;
        $b = $raw['sections'][0]['rows'][0]['seats'][0] ?? null;
        if ($a && $b) $shift = ['x' => round($a['x'] - $b['x'], 1), 'y' => round($a['y'] - $b['y'], 1)];
        $stats = TIX_Seatmap_Geometry::check($data);
        $got = $stats['bookable'];
        $frame = (array) ($in['frame'] ?? []);
        return [
            'ok' => true,
            'draft' => ['title' => $title ?: 'Saalplan aus Bild', 'data' => $data],
            'stats' => $stats,
            'check' => ['expected' => $expected ?: null, 'estimate' => isset($in['total_seats_estimate']) ? intval($in['total_seats_estimate']) : null, 'got' => $got,
                'diff' => $expected ? $got - $expected : null, 'diff_pct' => $expected ? round(($got - $expected) / $expected * 100, 1) : null],
            'overlay' => ['frame' => ['width' => floatval($frame['width'] ?? 1000), 'height' => floatval($frame['height'] ?? 0)],
                // Planpunkt (x, y) → Bildrahmen: (x - shift.x, y - shift.y)
                'shift' => $shift],
            'ai' => ['confidence' => isset($in['confidence']) ? round(floatval($in['confidence']), 2) : null, 'notes' => sanitize_textarea_field((string) ($in['notes'] ?? ''))],
            'accuracy_note' => self::ACCURACY_NOTE,
            'usage' => ['today' => self::ai_count($org), 'limit' => self::AI_PER_DAY],
        ];
    }
}

TIX_Seating::init();
