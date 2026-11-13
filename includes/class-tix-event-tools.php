<?php
/**
 * P5 Expertenmodus (1.38.801, Vertrag `uebergaben/vertrag-serien-vorlagen.md`):
 *  B4  Serien im Studio: Wiederholung (TIX_Recurrence) lesen/setzen, Termine mit Verkäufen, Ausnahmen, einzeln lösen
 *  B12 Event-Vorlagen: Schnappschuss eines Events speichern und daraus neue Events anlegen (Kopier-Regeln wie „Kopieren“)
 *  B13 Sammeländerung: Status, Online-Verkauf, Vorverkauf, Preise für viele Events auf einmal (Sperren und geteilte Events beachtet)
 * Weg-1: Vorlagen in eigener Tabelle (JSON), Prüfregeln rein, nur REST.
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_Tools {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const OPT_DB = 'tix_event_tpl_db';
    const BULK_MAX = 100;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_event_templates';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            name varchar(190) NOT NULL DEFAULT '',
            source_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            data longtext NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner (owner_id)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    private static function ready() {
        static $ok = null;
        if ($ok) return true;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) self::install();
        return $ok = get_option(self::OPT_DB) === self::DB_VERSION;
    }

    public static function routes() {
        $ev = [__CLASS__, 'perm_event'];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/series', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_series_get'], 'permission_callback' => $ev],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_series_set'], 'permission_callback' => $ev],
        ]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/series/skip', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_series_skip'], 'permission_callback' => $ev]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/series/detach', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_series_detach'], 'permission_callback' => $ev]);
        $read = ['TIX_REST_API', 'check_organizer'];
        register_rest_route(self::NS, '/studio/event-templates', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_tpl_list'], 'permission_callback' => $read],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_tpl_create'], 'permission_callback' => $read],
        ]);
        register_rest_route(self::NS, '/studio/event-templates/(?P<id>\d+)', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_tpl_rename'], 'permission_callback' => $read],
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_tpl_delete'], 'permission_callback' => $read],
        ]);
        register_rest_route(self::NS, '/studio/event-templates/(?P<id>\d+)/create', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_tpl_use'], 'permission_callback' => $read]);
        register_rest_route(self::NS, '/studio/events/bulk', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_bulk'], 'permission_callback' => $read]);
    }

    public static function perm_event(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('tix_not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    private static function shared_error($eid) {
        if (class_exists('TIX_Source_Lock') && ($s = TIX_Source_Lock::source_of($eid))) {
            return new WP_Error('tix_source_owned', 'Dieses Event gehört ' . $s['site'] . ' – Serien, Vorlagen und Sammeländerungen nur dort.', ['status' => 409, 'source_site' => $s['site']]);
        }
        return null;
    }

    // ═══════════════ B4 Serien ═══════════════

    private static function series_payload($eid) {
        $parent = TIX_Recurrence::parent_of($eid);
        $master = $parent ?: $eid;
        $mode = TIX_Recurrence::mode($master);
        $classic = get_post_meta($master, '_tix_series_enabled', true) === '1' || get_post_meta($eid, '_tix_series_parent', true);
        $skip = get_post_meta($master, TIX_Recurrence::META_SKIP, true);
        $skip = is_array($skip) ? array_values($skip) : [];
        $dates = $mode !== 'none' ? TIX_Recurrence::occurrences($master) : [];
        $children = TIX_Recurrence::children($master);
        $all = array_unique(array_merge(array_keys($dates), array_keys($children), $skip));
        sort($all);
        $today = current_time('Y-m-d');
        $occ = [];
        foreach ($all as $d) {
            $cid = $children[$d] ?? 0;
            $st = $cid ? (string) get_post_status($cid) : '';
            $occ[] = ['date' => $d, 'event_id' => $cid && $st !== 'trash' ? $cid : null, 'status' => $cid ? $st : ($d < $today ? 'past' : 'planned'),
                'skipped' => in_array($d, $skip, true), 'has_sales' => $cid ? TIX_Recurrence::has_sales($cid) : false,
                'detached' => $cid ? get_post_meta($cid, TIX_Recurrence::META_DETACHED, true) === '1' : false];
        }
        $lim = TIX_Recurrence::limits();
        return ['ok' => true, 'event_id' => $eid, 'role' => $parent ? 'occurrence' : ($mode !== 'none' ? 'master' : 'single'), 'master_id' => $parent ?: ($mode !== 'none' ? $eid : null),
            'mode' => $mode, 'until' => (string) get_post_meta($master, TIX_Recurrence::META_UNTIL, true) ?: null, 'count' => intval(get_post_meta($master, TIX_Recurrence::META_COUNT, true)) ?: null,
            'weekday' => ($ds = (string) get_post_meta($master, '_tix_date_start', true)) !== '' ? intval(date('N', strtotime($ds))) : null,
            'detached' => $parent ? get_post_meta($eid, TIX_Recurrence::META_DETACHED, true) === '1' : null,
            'classic_series' => (bool) $classic, 'occurrences' => $occ] + $lim;
    }

    public static function rest_series_get(WP_REST_Request $req) {
        return rest_ensure_response(self::series_payload(absint($req['id'])));
    }

    /** POST /events/{id}/series {mode: none|weekly|biweekly, until?, count?} – nur am Master bzw. Einzel-Event */
    public static function rest_series_set(WP_REST_Request $req) {
        $eid = absint($req['id']);
        if ($e = self::shared_error($eid)) return $e;
        if (TIX_Recurrence::parent_of($eid)) return new WP_Error('tix_series_occurrence', 'Das ist ein Termin einer Serie – die Wiederholung stellst du an der Serie ein.', ['status' => 409, 'master_id' => TIX_Recurrence::parent_of($eid)]);
        if (get_post_meta($eid, '_tix_series_enabled', true) === '1' || get_post_meta($eid, '_tix_series_parent', true)) return new WP_Error('tix_series_classic', 'Für dieses Event gibt es schon klassische Serientermine.', ['status' => 409]);
        $b = (array) $req->get_json_params();
        $mode = (string) ($b['mode'] ?? '');
        if (!in_array($mode, TIX_Recurrence::MODES, true)) return new WP_Error('tix_invalid_field', 'mode: none, weekly oder biweekly.', ['status' => 400, 'field' => 'mode']);
        $until = (string) ($b['until'] ?? '');
        if ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < (string) get_post_meta($eid, '_tix_date_start', true))) return new WP_Error('tix_invalid_field', 'Ende als Datum nach dem ersten Termin (JJJJ-MM-TT).', ['status' => 400, 'field' => 'until']);
        $count = intval($b['count'] ?? 0);
        if ($count < 0 || $count > TIX_Recurrence::MAX_COUNT) return new WP_Error('tix_invalid_field', 'Anzahl 0–' . TIX_Recurrence::MAX_COUNT . '.', ['status' => 400, 'field' => 'count']);
        if ($mode !== 'none' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) get_post_meta($eid, '_tix_date_start', true))) return new WP_Error('tix_invalid_field', 'Das Event braucht zuerst ein Datum.', ['status' => 400, 'field' => 'date_start']);
        TIX_Recurrence::save_settings($eid, $mode, $until, $count);
        $r = TIX_Recurrence::sync($eid);
        return rest_ensure_response(self::series_payload($eid) + ['sync' => $r]);
    }

    /** POST /events/{id}/series/skip {date, skip: bool} – Termin auslassen bzw. wieder aufnehmen (am Master oder Termin) */
    public static function rest_series_skip(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $master = TIX_Recurrence::parent_of($eid) ?: $eid;
        if (TIX_Recurrence::mode($master) === 'none') return new WP_Error('tix_series_none', 'Dieses Event wiederholt sich nicht.', ['status' => 409]);
        $b = (array) $req->get_json_params();
        $date = (string) ($b['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('tix_invalid_field', 'Datum JJJJ-MM-TT.', ['status' => 400, 'field' => 'date']);
        $skip = get_post_meta($master, TIX_Recurrence::META_SKIP, true);
        $skip = is_array($skip) ? $skip : [];
        $child = TIX_Recurrence::children($master)[$date] ?? 0;
        if (!$child) { // auch ausgelassene (automatisch weggeräumte) Termine finden
            $found = get_posts(['post_type' => 'event', 'post_status' => 'trash', 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true,
                'meta_query' => [['key' => TIX_Recurrence::META_PARENT, 'value' => (string) $master], ['key' => TIX_Recurrence::META_DATE, 'value' => $date]]]);
            $child = $found ? intval($found[0]) : 0;
        }
        if (!empty($b['skip'])) {
            if ($child && TIX_Recurrence::has_sales($child)) return new WP_Error('tix_series_sold', 'Für diesen Termin gibt es schon Verkäufe – bitte das Event absagen statt auslassen.', ['status' => 409, 'event_id' => $child]);
            if (!in_array($date, $skip, true)) $skip[] = $date;
            update_post_meta($master, TIX_Recurrence::META_SKIP, array_values($skip));
            if ($child && get_post_status($child) !== 'trash') { update_post_meta($child, TIX_Recurrence::META_AUTO, '1'); wp_trash_post($child); }
        } else {
            update_post_meta($master, TIX_Recurrence::META_SKIP, array_values(array_diff($skip, [$date])));
            if ($child && get_post_status($child) === 'trash') {
                add_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3); // alter Status statt Entwurf
                wp_untrash_post($child);
                remove_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10);
                delete_post_meta($child, TIX_Recurrence::META_AUTO);
            }
            TIX_Recurrence::sync($master);
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return rest_ensure_response(self::series_payload($eid));
    }

    /** POST /events/{id}/series/detach {detached: bool} – Termin einzeln ändern (Master-Änderungen erreichen ihn nicht mehr) */
    public static function rest_series_detach(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $master = TIX_Recurrence::parent_of($eid);
        if (!$master) return new WP_Error('tix_series_not_occurrence', 'Das ist kein Termin einer Serie.', ['status' => 409]);
        $b = (array) $req->get_json_params();
        if (!empty($b['detached'])) update_post_meta($eid, TIX_Recurrence::META_DETACHED, '1');
        else { delete_post_meta($eid, TIX_Recurrence::META_DETACHED); delete_post_meta($eid, '_tix_recurrence_hash'); TIX_Recurrence::sync($master); }
        return rest_ensure_response(self::series_payload($eid));
    }

    // ═══════════════ B12 Event-Vorlagen ═══════════════

    private static function owner() {
        if (!self::multi()) return 0;
        return class_exists('TIX_App_Scope') ? intval(TIX_App_Scope::organizer_id_for_user()) : 0;
    }

    private static function tpl_row($id) {
        global $wpdb;
        if (!self::ready()) return null;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", intval($id)), ARRAY_A);
        if (!$r) return null;
        if (self::multi() && !current_user_can('manage_options') && intval($r['owner_id']) !== self::owner()) return null;
        return $r;
    }

    /** Schnappschuss eines Events (Kopier-Regeln wie „Kopieren“, ohne Datum und Verkaufszahlen) */
    public static function snapshot($eid) {
        if (!class_exists('TIX_Columns')) require_once TIXOMAT_PATH . 'includes/class-tix-columns.php'; // DUPLICATE_SKIP (wie „Kopieren“)
        $p = get_post($eid);
        $meta = [];
        foreach (get_post_meta($eid) as $k => $vals) {
            if (strpos($k, '_tix_') !== 0 || !TIX_Event_Copy::copyable($k)) continue;
            if (in_array($k, TIX_Columns::DUPLICATE_SKIP, true)) continue;
            if (in_array($k, ['_tix_date_start', '_tix_date_end', '_tix_recurrence', '_tix_recurrence_until', '_tix_recurrence_count', '_tix_recurrence_skip', '_tix_seatmap_snapshot'], true)) continue;
            $meta[$k] = maybe_unserialize($vals[0]);
        }
        $terms = wp_get_object_terms($eid, 'event_category', ['fields' => 'ids']);
        return ['title' => (string) $p->post_title, 'content' => (string) $p->post_content, 'excerpt' => (string) $p->post_excerpt, 'thumbnail' => intval(get_post_thumbnail_id($eid)),
            'terms' => is_wp_error($terms) ? [] : array_map('intval', $terms), 'meta' => $meta];
    }

    private static function tpl_out(array $r) {
        $d = json_decode((string) $r['data'], true) ?: [];
        $cats = (array) ($d['meta']['_tix_ticket_categories'] ?? []);
        return ['id' => intval($r['id']), 'name' => (string) $r['name'], 'source_event_id' => intval($r['source_event_id']) ?: null, 'created_at' => mysql2date('c', $r['created_at'], false),
            'summary' => ['title' => (string) ($d['title'] ?? ''), 'categories' => count($cats), 'image' => !empty($d['thumbnail']) ? (string) wp_get_attachment_image_url(intval($d['thumbnail']), 'medium') : null,
                'time_start' => (string) ($d['meta']['_tix_time_start'] ?? '') ?: null, 'location' => (string) ($d['meta']['_tix_location'] ?? '') ?: null]];
    }

    public static function rest_tpl_list(WP_REST_Request $req) {
        global $wpdb;
        if (!self::ready()) return rest_ensure_response(['ok' => true, 'templates' => []]);
        $where = (self::multi() && !current_user_can('manage_options')) ? $wpdb->prepare(' WHERE owner_id = %d', self::owner()) : '';
        $rows = $wpdb->get_results("SELECT * FROM " . self::table() . $where . " ORDER BY name ASC LIMIT 200", ARRAY_A);
        return rest_ensure_response(['ok' => true, 'templates' => array_map([__CLASS__, 'tpl_out'], (array) $rows)]);
    }

    /** POST /studio/event-templates {event_id, name} → 201 */
    public static function rest_tpl_create(WP_REST_Request $req) {
        global $wpdb;
        $b = (array) $req->get_json_params();
        $eid = absint($b['event_id'] ?? 0);
        $name = trim(sanitize_text_field((string) ($b['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 120) return new WP_Error('tix_invalid_field', 'Bitte einen Namen (bis 120 Zeichen) angeben.', ['status' => 400, 'field' => 'name']);
        if (get_post_type($eid) !== 'event' || !TIX_REST_API::user_can_access_event($eid)) return new WP_Error('tix_invalid_field', 'Event nicht gefunden.', ['status' => 400, 'field' => 'event_id']);
        if ($e = self::shared_error($eid)) return $e;
        if (!self::ready()) return new WP_Error('tix_db', 'Vorlagen sind gerade nicht verfügbar.', ['status' => 503]);
        $now = current_time('mysql', true);
        $owner = self::multi() ? (intval(get_post_meta($eid, '_tix_organizer_id', true)) ?: self::owner()) : 0;
        $wpdb->insert(self::table(), ['owner_id' => $owner, 'name' => $name, 'source_event_id' => $eid, 'data' => wp_json_encode(self::snapshot($eid)), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now]);
        $r = rest_ensure_response(['ok' => true, 'template' => self::tpl_out(self::tpl_row($wpdb->insert_id))]);
        $r->set_status(201);
        return $r;
    }

    public static function rest_tpl_rename(WP_REST_Request $req) {
        global $wpdb;
        $r = self::tpl_row(absint($req['id']));
        if (!$r) return new WP_Error('tix_not_found', 'Vorlage nicht gefunden.', ['status' => 404]);
        $name = trim(sanitize_text_field((string) ($req->get_param('name') ?? '')));
        if ($name === '' || mb_strlen($name) > 120) return new WP_Error('tix_invalid_field', 'Bitte einen Namen angeben.', ['status' => 400, 'field' => 'name']);
        $wpdb->update(self::table(), ['name' => $name, 'updated_at' => current_time('mysql', true)], ['id' => intval($r['id'])]);
        return rest_ensure_response(['ok' => true, 'template' => self::tpl_out(self::tpl_row($r['id']))]);
    }

    public static function rest_tpl_delete(WP_REST_Request $req) {
        global $wpdb;
        $r = self::tpl_row(absint($req['id']));
        if (!$r) return new WP_Error('tix_not_found', 'Vorlage nicht gefunden.', ['status' => 404]);
        $wpdb->delete(self::table(), ['id' => intval($r['id'])]);
        return rest_ensure_response(['ok' => true, 'deleted' => intval($r['id'])]);
    }

    /** POST /studio/event-templates/{id}/create {title?, date_start, date_end?, time_start?} → 201 {event_id} (Entwurf) */
    public static function rest_tpl_use(WP_REST_Request $req) {
        $r = self::tpl_row(absint($req['id']));
        if (!$r) return new WP_Error('tix_not_found', 'Vorlage nicht gefunden.', ['status' => 404]);
        $d = json_decode((string) $r['data'], true) ?: [];
        $b = (array) $req->get_json_params();
        $ds = (string) ($b['date_start'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ds) || !strtotime($ds)) return new WP_Error('tix_invalid_field', 'Bitte ein Datum (JJJJ-MM-TT).', ['status' => 400, 'field' => 'date_start']);
        $de = (string) ($b['date_end'] ?? '');
        if ($de !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) || $de < $ds)) return new WP_Error('tix_invalid_field', 'Ende nach dem Beginn (JJJJ-MM-TT).', ['status' => 400, 'field' => 'date_end']);
        $title = trim(sanitize_text_field((string) ($b['title'] ?? ''))) ?: (string) ($d['title'] ?? 'Neues Event');
        $new = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => (string) ($d['content'] ?? ''), 'post_excerpt' => (string) ($d['excerpt'] ?? ''), 'post_author' => get_current_user_id()], true);
        if (is_wp_error($new)) return new WP_Error('tix_create_failed', 'Event konnte nicht angelegt werden.', ['status' => 500]);
        foreach ((array) ($d['meta'] ?? []) as $k => $v) if (strpos((string) $k, '_tix_') === 0 && TIX_Event_Copy::copyable($k)) update_post_meta($new, $k, wp_slash($v));
        // Kategorien mit vollem Kontingent (wie „Kopieren“)
        $cats = get_post_meta($new, '_tix_ticket_categories', true);
        if (is_array($cats)) { foreach ($cats as $i => $c) if (is_array($c)) { unset($cats[$i]['product_id']); if (isset($c['qty']) && $c['qty'] !== '') $cats[$i]['stock'] = $c['qty']; } update_post_meta($new, '_tix_ticket_categories', $cats); }
        update_post_meta($new, '_tix_date_start', $ds);
        if ($de === '' && !empty($d['meta']['_tix_time_end']) && !empty($d['meta']['_tix_time_start']) && strcmp((string) $d['meta']['_tix_time_end'], (string) $d['meta']['_tix_time_start']) < 0) $de = gmdate('Y-m-d', strtotime($ds) + DAY_IN_SECONDS); // über Mitternacht
        update_post_meta($new, '_tix_date_end', $de !== '' ? $de : $ds);
        if (!empty($b['time_start']) && preg_match('/^\d{2}:\d{2}$/', (string) $b['time_start'])) update_post_meta($new, '_tix_time_start', (string) $b['time_start']);
        if (self::multi()) update_post_meta($new, '_tix_organizer_id', intval($r['owner_id']) ?: self::owner());
        if (!empty($d['terms'])) wp_set_object_terms($new, array_map('intval', (array) $d['terms']), 'event_category');
        if (!empty($d['thumbnail']) && get_post_type(intval($d['thumbnail'])) === 'attachment') set_post_thumbnail($new, intval($d['thumbnail']));
        if (class_exists('TIX_Event_Copy')) TIX_Event_Copy::after_copy($new);
        $res = rest_ensure_response(['ok' => true, 'event_id' => intval($new), 'status' => 'draft', 'title' => $title]);
        $res->set_status(201);
        return $res;
    }

    // ═══════════════ B13 Sammeländerung ═══════════════

    /** Änderungen prüfen (rein) → [feld => wert] | ['error' => feld] */
    public static function clean_changes($c) {
        if (!is_array($c) || !$c) return ['error' => 'changes'];
        $out = [];
        foreach ($c as $k => $v) {
            switch ($k) {
                case 'status': if (!in_array($v, ['publish', 'draft'], true)) return ['error' => 'changes.status']; $out[$k] = $v; break;
                case 'tickets_enabled': case 'presale_active': $out[$k] = (bool) $v; break;
                case 'presale_end':
                    if ($v !== null && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', (string) $v)) return ['error' => 'changes.presale_end'];
                    $out[$k] = (string) ($v ?? ''); break;
                case 'price':
                    if (!is_array($v) || !in_array($v['mode'] ?? '', ['percent', 'set', 'add'], true) || !is_numeric($v['value'] ?? null)) return ['error' => 'changes.price'];
                    $val = floatval($v['value']);
                    if ($v['mode'] === 'percent' && ($val < -90 || $val > 500)) return ['error' => 'changes.price.value'];
                    if ($v['mode'] === 'set' && ($val < 0 || $val > 10000)) return ['error' => 'changes.price.value'];
                    if ($v['mode'] === 'add' && abs($val) > 1000) return ['error' => 'changes.price.value'];
                    $names = isset($v['categories']) ? array_values(array_filter(array_map('strval', (array) $v['categories']))) : [];
                    $out[$k] = ['mode' => $v['mode'], 'value' => $val, 'categories' => $names]; break;
                default: return ['error' => 'changes.' . $k];
            }
        }
        return $out;
    }

    /** Neuer Preis (rein) */
    public static function new_price($old, array $p) {
        $old = floatval($old);
        $n = $p['mode'] === 'percent' ? $old * (1 + $p['value'] / 100) : ($p['mode'] === 'add' ? $old + $p['value'] : $p['value']);
        return max(0, round($n, 2));
    }

    /** POST /studio/events/bulk {event_ids[], changes{…}, dry_run?} → {results[{event_id, ok, code?, message?, changed[]}], summary} */
    public static function rest_bulk(WP_REST_Request $req) {
        $b = (array) $req->get_json_params();
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) ($b['event_ids'] ?? [])))));
        if (!$ids) return new WP_Error('tix_invalid_field', 'Bitte Events wählen.', ['status' => 400, 'field' => 'event_ids']);
        if (count($ids) > self::BULK_MAX) return new WP_Error('tix_invalid_field', 'Höchstens ' . self::BULK_MAX . ' Events auf einmal.', ['status' => 400, 'field' => 'event_ids']);
        $ch = self::clean_changes($b['changes'] ?? null);
        if (isset($ch['error'])) return new WP_Error('tix_invalid_field', 'Ungültige Änderung: ' . $ch['error'] . '.', ['status' => 400, 'field' => $ch['error']]);
        $dry = !empty($b['dry_run']);
        $res = []; $ok = 0;
        foreach ($ids as $eid) {
            $row = ['event_id' => $eid, 'title' => html_entity_decode(get_the_title($eid), ENT_QUOTES, 'UTF-8'), 'ok' => false, 'changed' => []];
            if (get_post_type($eid) !== 'event' || !TIX_REST_API::user_can_access_event($eid)) { $res[] = $row + ['code' => 'forbidden', 'message' => 'Kein Zugriff.']; continue; }
            if ($e = self::shared_error($eid)) { $res[] = $row + ['code' => 'tix_source_owned', 'message' => $e->get_error_message()]; continue; }
            if (class_exists('TIX_Edit_Lock') && method_exists('TIX_Edit_Lock', 'status')) {
                $ls = TIX_Edit_Lock::status($eid);
                if (!empty($ls['locked']) && empty($ls['mine'])) { $res[] = $row + ['code' => 'tix_event_locked', 'message' => 'Wird gerade von ' . ($ls['holder']['name'] ?? 'jemand') . ' bearbeitet.']; continue; }
            }
            $changed = [];
            if (isset($ch['price'])) {
                $cats = get_post_meta($eid, '_tix_ticket_categories', true);
                if (is_array($cats)) {
                    $hit = false;
                    foreach ($cats as $i => $c) {
                        if (!is_array($c) || !empty($c['gift_card']) || !empty($c['wallet_topup'])) continue;
                        if ($ch['price']['categories'] && !in_array((string) ($c['name'] ?? ''), $ch['price']['categories'], true)) continue;
                        $np = self::new_price($c['price'] ?? 0, $ch['price']);
                        if ($np != floatval($c['price'] ?? 0)) { $changed[] = ['field' => 'price', 'category' => (string) ($c['name'] ?? ''), 'from' => floatval($c['price'] ?? 0), 'to' => $np]; $cats[$i]['price'] = $np; $hit = true; }
                    }
                    if ($hit && !$dry) update_post_meta($eid, '_tix_ticket_categories', $cats);
                }
            }
            foreach (['tickets_enabled' => '_tix_tickets_enabled', 'presale_active' => '_tix_presale_active'] as $k => $mk) {
                if (!array_key_exists($k, $ch)) continue;
                $nv = $ch[$k] ? '1' : '0';
                if ((string) get_post_meta($eid, $mk, true) !== $nv) { $changed[] = ['field' => $k, 'to' => $ch[$k]]; if (!$dry) update_post_meta($eid, $mk, $nv); }
            }
            if (array_key_exists('presale_end', $ch) && (string) get_post_meta($eid, '_tix_presale_end', true) !== $ch['presale_end']) {
                $changed[] = ['field' => 'presale_end', 'to' => $ch['presale_end'] ?: null];
                if (!$dry) {
                    // wie TIX_Sync: Modus fest + berechnetes Ende (nur Datum → 23:59)
                    $pe = $ch['presale_end'] !== '' && strlen($ch['presale_end']) === 10 ? $ch['presale_end'] . 'T23:59' : $ch['presale_end'];
                    update_post_meta($eid, '_tix_presale_end', $pe);
                    update_post_meta($eid, '_tix_presale_end_mode', $pe !== '' ? 'fixed' : 'manual');
                    update_post_meta($eid, '_tix_presale_end_computed', $pe);
                }
            }
            if (isset($ch['status']) && get_post_status($eid) !== $ch['status']) {
                $changed[] = ['field' => 'status', 'to' => $ch['status']];
                if (!$dry) wp_update_post(['ID' => $eid, 'post_status' => $ch['status']]);
            }
            if ($changed && !$dry) {
                clean_post_cache($eid);
                if (class_exists('TIX_Syndication_Push') && get_post_meta($eid, '_tix_syndicate', true) === '1') TIX_Syndication_Push::push_event($eid);
                if (class_exists('TIX_Co_Sites') && method_exists('TIX_Co_Sites', 'sync_sites_of_event')) TIX_Co_Sites::sync_sites_of_event($eid);
            }
            $ok++;
            $res[] = array_merge($row, ['ok' => true, 'changed' => $changed]);
        }
        if (!$dry && class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return rest_ensure_response(['ok' => true, 'dry_run' => $dry, 'results' => $res, 'summary' => ['total' => count($ids), 'ok' => $ok, 'failed' => count($ids) - $ok,
            'changed' => count(array_filter($res, function ($r) { return !empty($r['changed']); }))]]);
    }
}
