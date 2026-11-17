<?php
/**
 * TIX_Service_Export – Export für den Dienst evendis-api (WordPress-Ablösung Phase 2, Vertrag V5; ab 1.38.893).
 * Vertrag: uebergaben/vertrag-export-dienst-v5-2026-11-18.md
 *
 *   GET /_export/changes?since=<cursor>&limit=500   Änderungs-Signale aus Tabelle tix_export_log
 *   GET /_export/<entity>?ids=…|?page=&per_page=   Roh-Daten: events, ticket_categories, organizers, artists, locations,
 *                                                  tips, sponsors, config, terms, media
 *   GET /_export/derived                           berechnete Teile aus /info (Übergang Phase 2)
 *
 * Zugang nur mit `X-Evendis-Export: 1` + `X-Evendis-Shadow-Key` (Schlüssel der Schatten-Kennung, TIX_Shadow::key()).
 * Sonst 404 rest_no_route – die Routen verraten sich nicht. Ein gültiger Abruf gilt als Schatten-Abruf
 * (TIX_Shadow::active(): keine Zähler, keine Mails, keine Pushes, keine Ratenlimits).
 *
 * Erfassen: Hooks sammeln im Speicher (je entity|id nur der letzte Stand) und schreiben am Ende der Anfrage EINMAL.
 * Reine Seitenaufrufe schreiben nichts. Je (entity, id) bleibt im Log nur der letzte Eintrag; Aufbewahrung 30 Tage.
 * Geheimnisse: DENY-Liste des Spiegels (TIX_Mirror_Map::strip_sensitive/clean), _tix_payout_* nie.
 */
if (!defined('ABSPATH')) exit;

class TIX_Service_Export {

    const NS = 'tixomat/v1';
    const DB_OPT = 'tix_export_db';
    const DB_VERSION = '1';
    const FLOOR_OPT = 'tix_export_floor';
    const DERIVED_OPT = 'tix_export_derived_fp';
    const CRON = 'tix_export_cleanup';
    const KEEP_DAYS = 30;
    const MAX_IDS = 200;
    const MAX_CHANGES = 500;
    const RATE_PER_MIN = 240;
    const DERIVED_EVERY = 60;

    /** Entität → Post-Typ */
    const POST_TYPES = ['events' => 'event', 'organizers' => 'tix_organizer', 'artists' => 'tix_artist', 'locations' => 'tix_location', 'tips' => 'tix_app_tip'];
    const ENTITIES = ['events', 'ticket_categories', 'organizers', 'artists', 'locations', 'tips', 'sponsors', 'config', 'terms', 'media'];

    /** Meta ohne Signal (Sperren, Zähler, Protokolle) */
    const IGNORE_META = ['_tix_ticket_shared_count', '_tix_last_sync_time', '_tix_last_sync_log', '_tix_artist_log', '_tix_artist_norm',
                         '_tix_artist_spotify', '_tix_artist_apple', '_tix_raffle_hide_count', '_tix_feedback_count', '_tix_gallery_count'];

    private static $buf = [];
    private static $hooked = false;
    private static $flushed = false;

    public static function init() {
        if (defined('TIX_EXPORT_OFF') && TIX_EXPORT_OFF) return;
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_pre_dispatch', [__CLASS__, 'gate'], -999, 3);
        // Erfassen
        add_action('save_post', [__CLASS__, 'on_save_post'], 99, 2);
        add_action('transition_post_status', [__CLASS__, 'on_transition'], 99, 3);
        add_action('deleted_post', [__CLASS__, 'on_deleted_post'], 99, 2);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $h) add_action($h, [__CLASS__, 'on_post_meta'], 99, 3);
        add_action('set_object_terms', [__CLASS__, 'on_object_terms'], 99, 1);
        foreach (['created_term', 'edited_term', 'delete_term'] as $h) add_action($h, [__CLASS__, 'on_term'], 99, 3);
        add_action('edit_attachment', [__CLASS__, 'on_attachment'], 99, 1);
        add_action('delete_attachment', [__CLASS__, 'on_attachment_delete'], 99, 1);
        foreach (['added_option', 'updated_option', 'deleted_option'] as $h) add_action($h, [__CLASS__, 'on_option'], 99, 1);
        add_action('tix_sponsor_logos_changed', [__CLASS__, 'on_sponsors'], 99, 2);
        // Aufräumen
        add_action(self::CRON, [__CLASS__, 'cleanup']);
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 3600, 'daily', self::CRON); });
    }

    // ═══════════════ Zugang ═══════════════

    /** Export-Kopf gesetzt (mit oder ohne Schlüssel)? */
    public static function flagged() { return (string) ($_SERVER['HTTP_X_EVENDIS_EXPORT'] ?? '') === '1'; }

    /** Gültiger Export-Abruf: Kopf + passender Schlüssel (Schlüssel der Schatten-Kennung). */
    public static function authorized() {
        if (!self::flagged() || !class_exists('TIX_Shadow')) return false;
        $key = TIX_Shadow::key();
        $got = (string) ($_SERVER['HTTP_X_EVENDIS_SHADOW_KEY'] ?? '');
        return $key !== '' && $got !== '' && hash_equals($key, $got);
    }

    private static function no_route() {
        return new WP_Error('rest_no_route', __('No route was found matching the URL and request method.'), ['status' => 404]);
    }

    private static function is_export_route($route) { return strpos((string) $route, '/' . self::NS . '/_export') === 0; }

    /** Vor allem anderen: ohne Schlüssel 404, nicht lesend 404, Notbremse 429. */
    public static function gate($result, $server, $request) {
        if (!self::is_export_route($request->get_route())) return $result;
        if ($result !== null) return $result; // Schatten-Sicherung (403 nur lesend) hat schon geantwortet
        if (!self::authorized() || !in_array(strtoupper($request->get_method()), ['GET', 'HEAD'], true)) return self::no_route();
        $k = 'tix_export_rate_' . intdiv(time(), 60);
        $n = (int) get_transient($k) + 1;
        set_transient($k, $n, 120);
        if ($n > self::RATE_PER_MIN) {
            $r = new WP_REST_Response(['code' => 'tix_export_busy', 'message' => 'Zu viele Export-Abrufe.', 'data' => ['status' => 429]], 429);
            $r->header('Retry-After', '30');
            return $r;
        }
        return $result;
    }

    public static function perm() { return self::authorized() ? true : self::no_route(); }

    public static function routes() {
        $a = ['methods' => 'GET', 'permission_callback' => [__CLASS__, 'perm'], 'show_in_index' => false];
        register_rest_route(self::NS, '/_export/changes', $a + ['callback' => [__CLASS__, 'rest_changes']]);
        register_rest_route(self::NS, '/_export/derived', $a + ['callback' => [__CLASS__, 'rest_derived']]);
        register_rest_route(self::NS, '/_export/(?P<entity>[a-z_]+)', $a + ['callback' => [__CLASS__, 'rest_entity']]);
    }

    // ═══════════════ Log ═══════════════

    public static function table() { global $wpdb; return $wpdb->prefix . 'tix_export_log'; }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity varchar(24) NOT NULL,
            obj_id varchar(64) NOT NULL,
            op varchar(8) NOT NULL DEFAULT 'upsert',
            changed_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY obj (entity, obj_id),
            KEY changed (changed_at)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option(self::DB_OPT, self::DB_VERSION, false);
    }

    private static function ready() {
        static $ok = null;
        if ($ok) return true;
        if (get_option(self::DB_OPT) !== self::DB_VERSION) self::install();
        return $ok = (get_option(self::DB_OPT) === self::DB_VERSION);
    }

    /** Änderung vormerken (billig, nie ein Fehler nach außen). */
    public static function touch($entity, $id, $op = 'upsert') {
        $id = (string) $id;
        if ($id === '' || $id === '0') return;
        self::$buf[$entity . '|' . $id] = [$entity, $id, $op === 'delete' ? 'delete' : 'upsert'];
        if (!self::$hooked) { self::$hooked = true; add_action('shutdown', [__CLASS__, 'flush'], 2); }
        if (self::$flushed || count(self::$buf) >= 200) self::flush();
    }

    /** Puffer schreiben: alte Einträge derselben Objekte entfernen, neue anhängen. */
    public static function flush() {
        if (doing_action('shutdown')) self::$flushed = true;
        if (!self::$buf) return;
        $items = array_values(self::$buf);
        self::$buf = [];
        try {
            global $wpdb;
            if (!self::ready()) return;
            $t = self::table();
            $now = gmdate('Y-m-d H:i:s');
            $prev = $wpdb->suppress_errors(true);
            foreach (array_chunk($items, 100) as $chunk) {
                $w = []; $v = [];
                foreach ($chunk as $it) {
                    $w[] = $wpdb->prepare('(entity = %s AND obj_id = %s)', $it[0], $it[1]);
                    $v[] = $wpdb->prepare('(%s, %s, %s, %s)', $it[0], $it[1], $it[2], $now);
                }
                $wpdb->query("DELETE FROM $t WHERE " . implode(' OR ', $w));
                $wpdb->query("INSERT INTO $t (entity, obj_id, op, changed_at) VALUES " . implode(', ', $v));
            }
            $wpdb->suppress_errors($prev);
        } catch (\Throwable $e) {
            // Export darf nie stören.
        }
    }

    /** Täglich: Einträge > 30 Tage löschen, Untergrenze (floor) merken. */
    public static function cleanup() {
        global $wpdb;
        if (!self::ready()) return;
        $t = self::table();
        $cut = gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS);
        $max = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM $t WHERE changed_at < %s", $cut));
        if ($max <= 0) return;
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE id <= %d", $max));
        if ($max > (int) get_option(self::FLOOR_OPT, 0)) update_option(self::FLOOR_OPT, $max, false);
    }

    public static function head() {
        global $wpdb;
        if (!self::ready()) return 0;
        return (int) $wpdb->get_var("SELECT MAX(id) FROM " . self::table());
    }

    /** [obj_id => [version, changed_at]] für eine Entität */
    private static function versions($entity, array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (!$ids || !self::ready()) return [];
        $in = implode(',', array_map(function ($i) use ($wpdb) { return $wpdb->prepare('%s', $i); }, $ids));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT obj_id, MAX(id) v, MAX(changed_at) c FROM " . self::table() . " WHERE entity = %s AND obj_id IN ($in) GROUP BY obj_id", $entity), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) $out[(string) $r['obj_id']] = [(int) $r['v'], (string) $r['c']];
        return $out;
    }

    private static function iso_gmt($v) {
        $v = trim((string) $v);
        if ($v === '' || strpos($v, '0000-00-00') === 0) return null;
        return gmdate('Y-m-d\TH:i:s\Z', strtotime($v . ' UTC'));
    }

    private static function later($a, $b) {
        if (!$a) return $b; if (!$b) return $a;
        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    // ═══════════════ Hooks ═══════════════

    private static function entity_of_type($type) {
        $e = array_search((string) $type, self::POST_TYPES, true);
        return $e === false ? '' : $e;
    }

    public static function on_save_post($post_id, $post) {
        if (!$post || wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') return;
        if ($e = self::entity_of_type($post->post_type)) self::touch($e, $post_id);
    }

    public static function on_transition($new, $old, $post) {
        if ($new === $old || !$post || $new === 'auto-draft') return;
        if ($e = self::entity_of_type($post->post_type)) self::touch($e, $post->ID);
    }

    public static function on_deleted_post($post_id, $post = null) {
        $type = $post ? $post->post_type : '';
        if (!($e = self::entity_of_type($type))) return;
        self::touch($e, $post_id, 'delete');
        if ($e === 'events') self::touch('ticket_categories', $post_id, 'delete');
    }

    public static function on_post_meta($meta_id, $object_id, $meta_key) {
        $meta_key = (string) $meta_key;
        if ($meta_key !== '_thumbnail_id' && strpos($meta_key, '_tix_') !== 0) return;
        if (in_array($meta_key, self::IGNORE_META, true)) return;
        $e = self::entity_of_type(get_post_type($object_id));
        if (!$e) return;
        if (get_post_status($object_id) === 'auto-draft') return;
        self::touch($e, (int) $object_id);
        if ($e === 'events' && $meta_key === '_tix_ticket_categories') self::touch('ticket_categories', (int) $object_id);
    }

    public static function on_object_terms($object_id) {
        if ($e = self::entity_of_type(get_post_type($object_id))) self::touch($e, (int) $object_id);
    }

    public static function on_term($term_id, $tt_id = 0, $taxonomy = '') {
        if ($taxonomy !== '' && in_array($taxonomy, self::taxonomies(), true)) self::touch('terms', $taxonomy);
    }

    public static function on_attachment($id) { self::touch('media', (int) $id); }
    public static function on_attachment_delete($id) { self::touch('media', (int) $id, 'delete'); }

    public static function on_option($name) {
        if (in_array((string) $name, self::config_options(), true)) self::touch('config', (string) $name);
    }

    public static function on_sponsors($scope, $owner) { self::touch('sponsors', $scope . ':' . intval($owner)); }

    // ═══════════════ Listen ═══════════════

    /** Optionen der Entität config (Erlaubnisliste; DENY-Namen fallen immer heraus). */
    public static function config_options() {
        static $list = null;
        if ($list !== null) return $list;
        $l = ['tix_settings', '_tix_app_config', '_tix_app_notifications', '_tix_app_nav_sos', 'tix_site_config', 'tix_play_public', 'tix_app_top_bar',
              'tix_studio_brand', 'tix_studio_url', 'tix_studio_has_app', 'tix_app_layout', 'tix_modules_entitled', 'tix_plan_site',
              'tix_app_requirements', 'tix_awareness_flags', 'tix_music', 'tix_party', 'tix_weekly_mail', 'tix_mail_follow',
              'tix_app_coupons_enabled', 'tix_app_tips_enabled', 'tix_broadcast_enabled', 'tix_raffles_enabled', 'tix_muttizettel_enabled',
              'tix_artist_previews_enabled', 'tix_marketing_enabled', 'tix_cloakroom_enabled', 'tix_website_enabled', 'tix_website',
              'tix_strip_ref_params', 'tix_staff_app_min_version', 'tix_customer_base_url', '_tix_loyalty_enabled', 'tix_invoices_enabled',
              'tix_org_domain_settings', 'tix_release_dates', 'blogname', 'blogdescription', 'timezone_string', 'gmt_offset', 'site_icon'];
        $l = (array) apply_filters('tix_export_config_options', $l);
        $list = array_values(array_filter(array_unique(array_map('strval', $l)), function ($o) {
            return $o !== '' && !(class_exists('TIX_Mirror_Map') && TIX_Mirror_Map::is_sensitive($o));
        }));
        return $list;
    }

    public static function taxonomies() {
        static $t = null;
        if ($t !== null) return $t;
        $out = [];
        foreach (self::POST_TYPES as $type) foreach ((array) get_object_taxonomies($type) as $tx) $out[] = (string) $tx;
        return $t = array_values(array_unique($out));
    }

    // ═══════════════ Bereinigen ═══════════════

    private static function clean($v) {
        $v = TIX_Mirror_Map::strip_sensitive($v);
        return TIX_Mirror_Map::clean($v);
    }

    private static function post_meta($id) {
        $out = [];
        foreach ((array) get_post_meta($id) as $k => $vals) {
            if (strpos($k, '_tix_') !== 0 || strpos($k, '_tix_payout_') === 0 || TIX_Mirror_Map::is_sensitive($k)) continue;
            $vals = array_map('maybe_unserialize', (array) $vals);
            $out[$k] = self::clean(count($vals) > 1 ? $vals : ($vals[0] ?? null));
        }
        ksort($out);
        return $out;
    }

    public static function media_row($id) {
        $id = (int) $id;
        $p = $id ? get_post($id) : null;
        if (!$p || $p->post_type !== 'attachment') return null;
        $meta = wp_get_attachment_metadata($id);
        $meta = is_array($meta) ? $meta : [];
        $full = wp_get_attachment_image_src($id, 'full');
        $sizes = [];
        foreach (array_keys((array) ($meta['sizes'] ?? [])) as $s) {
            $src = wp_get_attachment_image_src($id, $s);
            if ($src) $sizes[$s] = ['url' => (string) $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2]];
        }
        return [
            'id' => $id, 'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true), 'mime' => (string) $p->post_mime_type,
            'width' => (int) ($meta['width'] ?? 0), 'height' => (int) ($meta['height'] ?? 0),
            'full' => $full ? ['url' => (string) $full[0], 'width' => (int) $full[1], 'height' => (int) $full[2]] : ['url' => (string) wp_get_attachment_url($id), 'width' => 0, 'height' => 0],
            'sizes' => (object) $sizes,
            'modified_gmt' => self::iso_gmt($p->post_modified_gmt),
        ];
    }

    private static function post_row(WP_Post $p) {
        $terms = [];
        foreach ((array) get_object_taxonomies($p->post_type) as $tx) {
            $ts = get_the_terms($p->ID, $tx);
            $terms[$tx] = is_array($ts) ? array_map(function ($t) { return ['id' => (int) $t->term_id, 'slug' => (string) $t->slug, 'name' => html_entity_decode((string) $t->name, ENT_QUOTES, 'UTF-8'), 'parent' => (int) $t->parent]; }, array_values($ts)) : [];
        }
        $thumb = (int) get_post_thumbnail_id($p->ID);
        return [
            'id' => (int) $p->ID, 'type' => $p->post_type, 'status' => $p->post_status, 'slug' => $p->post_name,
            'title' => (string) $p->post_title, 'content' => (string) $p->post_content, 'excerpt' => (string) $p->post_excerpt,
            'author' => (int) $p->post_author, 'parent' => (int) $p->post_parent, 'menu_order' => (int) $p->menu_order,
            'date_gmt' => self::iso_gmt($p->post_date_gmt), 'date_local' => (string) $p->post_date,
            'modified_gmt' => self::iso_gmt($p->post_modified_gmt),
            'meta' => (object) self::post_meta($p->ID),
            'terms' => (object) $terms,
            'image' => $thumb ? self::media_row($thumb) : null,
        ];
    }

    // ═══════════════ Routen ═══════════════

    private static function bad($code, $msg, $status = 400, $field = null) {
        return new WP_Error($code, $msg, array_filter(['status' => $status, 'field' => $field], function ($v) { return $v !== null; }));
    }

    public static function rest_changes(WP_REST_Request $req) {
        global $wpdb;
        if (!self::ready()) return self::bad('tix_export_unavailable', 'Export-Log nicht bereit.', 503);
        self::check_derived();
        $head = self::head();
        $floor = (int) get_option(self::FLOOR_OPT, 0);
        $base = ['head' => (string) $head, 'floor' => (string) $floor];
        $since = $req->get_param('since');
        if ($since === null || $since === '') return rest_ensure_response(['changes' => [], 'cursor' => (string) $head, 'has_more' => false] + $base);
        if (!preg_match('/^\d{1,19}$/', (string) $since)) return self::bad('tix_export_cursor', 'Ungültiger Cursor.', 400, 'since');
        $since = (int) $since;
        if ($since < $floor) return self::bad('tix_export_cursor_gone', 'Cursor zu alt – bitte neu laden.', 410, 'since');
        $limit = $req->get_param('limit');
        $limit = ($limit === null || $limit === '') ? self::MAX_CHANGES : (int) $limit;
        if ($limit < 1 || $limit > self::MAX_CHANGES) return self::bad('tix_export_too_many', 'limit 1–' . self::MAX_CHANGES . '.', 400, 'limit');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, entity, obj_id, op, changed_at FROM " . self::table() . " WHERE id > %d ORDER BY id ASC LIMIT %d", $since, $limit + 1), ARRAY_A);
        $rows = (array) $rows;
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        $changes = array_map(function ($r) {
            return ['entity' => (string) $r['entity'], 'id' => (string) $r['obj_id'], 'op' => (string) $r['op'], 'version' => (int) $r['id'], 'changed_at' => self::iso_gmt($r['changed_at'])];
        }, $rows);
        $cursor = $changes ? (string) end($changes)['version'] : (string) max($since, 0);
        if (!$changes && $since > $head) $cursor = (string) $since;
        return rest_ensure_response(['changes' => $changes, 'cursor' => $cursor, 'has_more' => $more] + $base);
    }

    /** ids=1,2,3 → Liste | page/per_page → [page, per_page] | WP_Error */
    private static function selection(WP_REST_Request $req) {
        $ids = $req->get_param('ids');
        if ($ids !== null && $ids !== '') {
            $list = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $ids)), 'strlen')));
            if (count($list) > self::MAX_IDS) return self::bad('tix_export_too_many', 'Höchstens ' . self::MAX_IDS . ' IDs.', 400, 'ids');
            return ['ids' => $list];
        }
        $page = max(1, (int) ($req->get_param('page') ?: 1));
        $pp = $req->get_param('per_page');
        $pp = ($pp === null || $pp === '') ? self::MAX_IDS : (int) $pp;
        if ($pp < 1 || $pp > self::MAX_IDS) return self::bad('tix_export_too_many', 'per_page 1–' . self::MAX_IDS . '.', 400, 'per_page');
        return ['page' => $page, 'per_page' => $pp];
    }

    public static function rest_entity(WP_REST_Request $req) {
        $entity = (string) $req['entity'];
        if (!in_array($entity, self::ENTITIES, true)) return self::no_route();
        $sel = self::selection($req);
        if (is_wp_error($sel)) return $sel;
        $head = self::head();
        if (isset(self::POST_TYPES[$entity]) || $entity === 'ticket_categories') $res = self::read_posts($entity, $sel);
        elseif ($entity === 'sponsors') $res = self::read_sponsors($sel);
        elseif ($entity === 'config') $res = self::read_list('config', self::config_options(), $sel, [__CLASS__, 'config_row']);
        elseif ($entity === 'terms') $res = self::read_list('terms', self::taxonomies(), $sel, [__CLASS__, 'terms_row']);
        else {
            if (!isset($sel['ids'])) return self::bad('tix_export_ids_required', 'media nur mit ids.', 400, 'ids');
            $res = self::read_list('media', $sel['ids'], $sel, [__CLASS__, 'media_row']);
        }
        return rest_ensure_response(['entity' => $entity] + $res + ['head' => (string) $head]);
    }

    /** Ergebnis um version/modified_gmt ergänzen; fehlende IDs sammeln. */
    private static function finish($entity, array $rows, array $wanted = null) {
        $ver = self::versions($entity, array_map(function ($r) { return (string) $r['id']; }, $rows));
        foreach ($rows as &$r) {
            $v = $ver[(string) $r['id']] ?? null;
            $r['version'] = $v ? $v[0] : 0;
            $r['modified_gmt'] = self::later($r['modified_gmt'] ?? null, $v ? self::iso_gmt($v[1]) : null);
        }
        unset($r);
        $out = ['items' => $rows];
        if ($wanted !== null) {
            $have = array_map(function ($r) { return (string) $r['id']; }, $rows);
            $out['missing'] = array_values(array_diff(array_map('strval', $wanted), $have));
        }
        return $out;
    }

    private static function read_posts($entity, array $sel) {
        global $wpdb;
        $type = $entity === 'ticket_categories' ? 'event' : self::POST_TYPES[$entity];
        $where = $wpdb->prepare("post_type = %s AND post_status NOT IN ('auto-draft', 'inherit')", $type);
        if (isset($sel['ids'])) {
            $ints = array_values(array_filter(array_map('intval', $sel['ids'])));
            $ids = $ints ? array_map('intval', (array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE $where AND ID IN (" . implode(',', $ints) . ") ORDER BY ID ASC")) : [];
            $meta = [];
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where");
            $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE $where ORDER BY ID ASC LIMIT %d OFFSET %d", $sel['per_page'], ($sel['page'] - 1) * $sel['per_page'])));
            $meta = ['page' => $sel['page'], 'per_page' => $sel['per_page'], 'total' => $total, 'total_pages' => (int) ceil($total / $sel['per_page'])];
        }
        if ($ids) { _prime_post_caches($ids, true, true); }
        $rows = [];
        foreach ($ids as $id) {
            $p = get_post($id);
            if (!$p) continue;
            if ($entity === 'ticket_categories') {
                $cats = get_post_meta($id, '_tix_ticket_categories', true);
                $cats = is_array($cats) ? array_values($cats) : [];
                foreach ($cats as $i => &$c) { $c = is_array($c) || is_object($c) ? ['index' => $i] + (array) $c : ['index' => $i, 'value' => $c]; }
                unset($c);
                $rows[] = ['id' => $id, 'event_id' => $id, 'modified_gmt' => self::iso_gmt($p->post_modified_gmt), 'categories' => self::clean($cats)];
            } else {
                $rows[] = self::post_row($p);
            }
        }
        return self::finish($entity, $rows, $sel['ids'] ?? null) + $meta;
    }

    /** Listen ohne Tabelle (config, terms, media): Schlüssel → Zeile | null */
    private static function read_list($entity, array $all, array $sel, callable $row) {
        if (isset($sel['ids'])) { $keys = $sel['ids']; $meta = []; }
        else {
            $total = count($all);
            $keys = array_slice($all, ($sel['page'] - 1) * $sel['per_page'], $sel['per_page']);
            $meta = ['page' => $sel['page'], 'per_page' => $sel['per_page'], 'total' => $total, 'total_pages' => (int) ceil($total / $sel['per_page'])];
        }
        $rows = [];
        foreach ($keys as $k) {
            if ($entity !== 'media' && !in_array((string) $k, $all, true)) continue;
            $r = call_user_func($row, $k);
            if ($r !== null) $rows[] = $r;
        }
        return self::finish($entity, $rows, $sel['ids'] ?? null) + $meta;
    }

    public static function config_row($name) {
        $v = get_option($name, null);
        return ['id' => (string) $name, 'value' => self::clean(maybe_unserialize($v)), 'modified_gmt' => null];
    }

    public static function terms_row($tax) {
        if (!taxonomy_exists($tax)) return null;
        $ts = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
        $ts = is_array($ts) ? $ts : [];
        return ['id' => (string) $tax, 'modified_gmt' => null, 'terms' => array_map(function ($t) {
            return ['id' => (int) $t->term_id, 'slug' => (string) $t->slug, 'name' => html_entity_decode((string) $t->name, ENT_QUOTES, 'UTF-8'),
                    'parent' => (int) $t->parent, 'description' => (string) $t->description, 'count' => (int) $t->count];
        }, array_values($ts))];
    }

    private static function read_sponsors(array $sel) {
        global $wpdb;
        if (!class_exists('TIX_Sponsor_Logos') || !TIX_Sponsor_Logos::ready()) return self::finish('sponsors', [], $sel['ids'] ?? null) + (isset($sel['ids']) ? [] : ['page' => $sel['page'], 'per_page' => $sel['per_page'], 'total' => 0, 'total_pages' => 0]);
        $lt = TIX_Sponsor_Logos::t('logos'); $st = TIX_Sponsor_Logos::t('settings');
        $owners = (array) $wpdb->get_col("SELECT CONCAT(scope, ':', owner_id) k FROM (SELECT scope, owner_id FROM $lt UNION SELECT scope, owner_id FROM $st) x ORDER BY scope, owner_id");
        $owners = array_map('strval', $owners);
        $rows = []; $meta = [];
        if (isset($sel['ids'])) $keys = array_values(array_intersect($sel['ids'], $owners));
        else {
            $total = count($owners);
            $keys = array_slice($owners, ($sel['page'] - 1) * $sel['per_page'], $sel['per_page']);
            $meta = ['page' => $sel['page'], 'per_page' => $sel['per_page'], 'total' => $total, 'total_pages' => (int) ceil($total / $sel['per_page'])];
        }
        foreach ($keys as $k) {
            [$scope, $owner] = array_pad(explode(':', $k, 2), 2, '0');
            $logos = array_map(function ($r) { unset($r['clicks']); foreach (['id', 'owner_id', 'logo_id', 'logo_dark_id', 'position', 'active'] as $f) $r[$f] = (int) $r[$f]; return $r; }, TIX_Sponsor_Logos::rows($scope, (int) $owner));
            $last = null;
            foreach ($logos as $l) $last = self::later($last, self::iso_gmt($l['updated_at'] ?? ''));
            $rows[] = ['id' => $k, 'scope' => $scope, 'owner_id' => (int) $owner, 'modified_gmt' => $last,
                       'settings' => (object) self::clean(TIX_Sponsor_Logos::settings($scope, (int) $owner)), 'logos' => self::clean($logos)];
        }
        return self::finish('sponsors', $rows, $sel['ids'] ?? null) + $meta;
    }

    // ═══════════════ Abgeleitete Teile ═══════════════

    public static function derived_payload() {
        $multi = class_exists('TIX_Weekly_Mail') && TIX_Weekly_Mail::multi();
        $d = [
            'plan'            => class_exists('TIX_Plan') ? TIX_Plan::info_payload() : null,
            'license'         => class_exists('TIX_License_Stage') ? TIX_License_Stage::payload() : null,
            'contract'        => class_exists('TIX_Contract_Template') && class_exists('TIX_Contract') && TIX_Contract::multi() ? TIX_Contract_Template::info_payload() : null,
            'migrations'      => class_exists('TIX_Order_Attribution') ? TIX_Order_Attribution::status(false) : null,
            'staff_site'      => class_exists('TIX_REST_API') ? TIX_REST_API::staff_site_info() : null,
            'push_ready'      => class_exists('TIX_Notifications') && method_exists('TIX_Notifications', 'push_configured') && TIX_Notifications::push_configured(),
            'payment_methods' => $multi && class_exists('TIX_App_Checkout') ? TIX_App_Checkout::info_methods() : null,
            'staff_app_min_version' => (string) get_option('tix_staff_app_min_version', ''),
        ];
        return self::clean($d);
    }

    /** Fingerabdruck höchstens alle 60 s prüfen; bei Abweichung Signal derived|all. */
    private static function check_derived($force = false) {
        $st = get_option(self::DERIVED_OPT, []);
        $st = is_array($st) ? $st : [];
        if (!$force && (time() - (int) ($st['at'] ?? 0)) < self::DERIVED_EVERY) return;
        $fp = md5((string) wp_json_encode(self::derived_payload()));
        $changed = ($st['fp'] ?? '') !== $fp;
        update_option(self::DERIVED_OPT, ['fp' => $fp, 'at' => time()], false);
        if ($changed) { self::touch('derived', 'all'); self::flush(); }
    }

    public static function rest_derived(WP_REST_Request $req) {
        self::check_derived();
        $v = self::versions('derived', ['all'])['all'] ?? null;
        return rest_ensure_response(['version' => $v ? $v[0] : 0, 'modified_gmt' => $v ? self::iso_gmt($v[1]) : null] + self::derived_payload());
    }

    /** Für Tests */
    public static function reset_for_tests() { self::$buf = []; self::$flushed = false; delete_option(self::DERIVED_OPT); }
}

TIX_Service_Export::init();
