<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Sales_Previews – Vorschauen für den Vertrieb (Live-Demo, Verträge uebergaben/vertrag-live-demo.md D–H und
 * uebergaben/vertrag-vorschau-pakete.md; nur Mehr-Veranstalter-Modus, sonst 404).
 *
 *  - Seit 1.38.514 ist eine Vorschau ein Paket in der Tabelle {prefix}tix_previews (kein Veranstalter-, kein Event-Post):
 *    data.organizer {name, logo_id, accent, …}, data.events [{key, vid, title, excerpt, slug, image_id, term_id, meta}],
 *    data.template. Bilder sind Anhänge mit Meta _tix_preview = Paket-ID.
 *  - Editor-Felder generisch: Anlegen und PATCH laufen über die echte Editor-Funktion (TIX_App_Events::apply_body) auf
 *    einem kurzlebigen, gesperrten Arbeits-Entwurf; dessen _tix_*-Meta wird ins Paket übernommen. GET …/edit und die
 *    öffentlichen Antworten entstehen aus „virtuellen“ Posts nur im Objekt-Cache (IDs ab 900000000) mit denselben
 *    Payload-Funktionen wie echte Events. Neue Editor-Felder ziehen so automatisch mit (Laden, Speichern, Übernahme).
 *  - Test-Tickets hängen an technischen Entwurf-Events unter dem unsichtbaren Sandbox-Veranstalter „Vorschau (technisch)“.
 *  - Übernahme (POST /register/organizer + claim_token): neuer Veranstalter (pending) + echte Event-Entwürfe aus dem Paket.
 *  - Harte Sperre (1.38.513) bleibt: technische Posts und Altbestand kommen nie in einen öffentlichen Status.
 */
class TIX_Sales_Previews {

    const NS = 'tixomat/v1';
    const TABLE = 'tix_previews';
    const DB_VERSION = '1';
    const OPT_DB = 'tix_previews_db';
    const OPT_MIG = 'tix_preview_pkg_migrated';
    const OPT_MIG_REPORT = 'tix_preview_pkg_migration';
    const OPT_TECH = 'tix_preview_tech_org';
    const META_ITEM = '_tix_preview';            // technische Posts, Arbeits-Entwürfe; Bilder: = Paket-ID
    const META_PKG = '_tix_preview_pkg';         // technischer Event: Paket-ID
    const META_KEY = '_tix_preview_key';         // technischer Event: Event-Schlüssel im Paket
    const META_SCRATCH = '_tix_preview_scratch'; // Arbeits-Entwurf (wird sofort wieder gelöscht)
    const META_LOGO = '_tix_org_landing_logo_id';
    const META_ACCENT = '_tix_org_accent';
    const META_TEMPLATE = '_tix_org_site_template';
    const META_ARCHIVE = '_tix_preview_archive'; // Nutzer: gelöschte Vorschauen (Auswertung)
    const OPT_GONE = 'tix_preview_gone';
    const CRON = 'tix_preview_expire';
    const DAYS = 14;
    const MAX_EVENTS = 12;
    const PER_PAGE = 20;
    const GONE_DAYS = 30;
    const DEFAULT_ACCENT = '#FC2F55';
    const VID_BASE = 900000000;
    const TEMPLATES = ['club', 'festival', 'klar'];
    const PUBLIC_STATI = ['publish', 'future', 'pending', 'private'];
    /** Editor-Felder, die eine Vorschau nicht kennt (Rest geht 1:1 an den Event-Editor). */
    const NOT_IN_PREVIEW = ['published', 'post_status', 'organizer_id', 'id'];

    /** virtueller Veranstalter, dessen Vorschau gerade ausgeliefert wird */
    private static $render = 0;
    /** eingehängte virtuelle Post-IDs */
    private static $mounted = [];
    /** Altbestand (vor 1.38.514): Vorschau-Veranstalter, der gerade übernommen wird */
    private static $claiming = 0;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action(self::CRON, [__CLASS__, 'expire']);
        add_action('init', [__CLASS__, 'boot'], 40);
        // Harte Sperre (1.38.513): Vorschau-Posts kommen auf keinem Weg in einen öffentlichen Status
        add_filter('wp_insert_post_data', [__CLASS__, 'guard_insert'], 999, 2);
        add_action('transition_post_status', [__CLASS__, 'guard_transition'], 1, 3);
        add_action('added_post_meta', [__CLASS__, 'guard_meta'], 999, 4);
        add_action('updated_post_meta', [__CLASS__, 'guard_meta'], 999, 4);
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard_rest'], 5, 3);
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat previews', [__CLASS__, 'cli']);
    }

    /** Tabelle anlegen, Cron, einmalige Migration der Vorschau-Posts (1.38.514). */
    public static function boot() {
        if (!class_exists('TIX_Sales') || !TIX_Sales::multi()) {
            if (wp_next_scheduled(self::CRON)) wp_clear_scheduled_hook(self::CRON);
            return;
        }
        if (get_option(self::OPT_DB) !== self::DB_VERSION) self::install();
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 600, 'hourly', self::CRON);
        // automatisch beim ersten Web-/Cron-Aufruf nach dem Update; per WP-CLI erst mit `wp tixomat previews migrate` (Probelauf möglich)
        if (!(defined('WP_CLI') && WP_CLI) && !get_option(self::OPT_MIG) && !get_transient('tix_preview_mig_lock')) {
            set_transient('tix_preview_mig_lock', 1, 300);
            self::migrate();
            delete_transient('tix_preview_mig_lock');
        }
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        dbDelta("CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token varchar(40) NOT NULL,
  claim varchar(40) NOT NULL,
  owner bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT 'active',
  name varchar(190) NOT NULL DEFAULT '',
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  expires_at int(10) unsigned NOT NULL DEFAULT 0,
  opened_at int(10) unsigned NOT NULL DEFAULT 0,
  claimed_at int(10) unsigned NOT NULL DEFAULT 0,
  claimed_org bigint(20) unsigned NOT NULL DEFAULT 0,
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  UNIQUE KEY claim (claim),
  KEY owner (owner),
  KEY status (status)
) " . $wpdb->get_charset_collate() . ";");
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    // ═══════════════ Harte Sperre (1.38.513) ═══════════════

    /** Übernahme beginnt/endet (nur POST /register/organizer mit claim_token). */
    public static function begin_claim($oid) {
        self::$claiming = intval($oid);
    }

    public static function end_claim() {
        self::$claiming = 0;
    }

    /** Vorschau-Veranstalter (noch nicht übernommen)? */
    public static function locked_org($oid) {
        $oid = intval($oid);
        if (!$oid || $oid === self::$claiming) return false;
        return get_post_type($oid) === 'tix_organizer' && (bool) get_post_meta($oid, TIX_Sales::META_PREVIEW, true);
    }

    /** Event einer Vorschau (Meta _tix_preview oder Veranstalter ist eine Vorschau)? */
    public static function locked_event($event_id) {
        $event_id = intval($event_id);
        if (!$event_id) return false;
        $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
        if ($oid && $oid === self::$claiming) return false;
        return (bool) get_post_meta($event_id, self::META_ITEM, true) || ($oid && self::locked_org($oid));
    }

    private static function locked_post($post_id) {
        $t = get_post_type($post_id);
        return $t === 'event' ? self::locked_event($post_id) : ($t === 'tix_organizer' ? self::locked_org($post_id) : false);
    }

    /** wp_insert_post_data: jeder Speicherweg (REST, Editor, Schnellbearbeiten, Bulk, Import, Kopie) → Entwurf. */
    public static function guard_insert($data, $postarr) {
        $type = (string) ($data['post_type'] ?? '');
        if (($type !== 'event' && $type !== 'tix_organizer') || !in_array((string) ($data['post_status'] ?? ''), self::PUBLIC_STATI, true)) return $data;
        $id = intval($postarr['ID'] ?? 0);
        $mi = is_array($postarr['meta_input'] ?? null) ? $postarr['meta_input'] : [];
        if ($type === 'tix_organizer') {
            $locked = ($id && self::locked_org($id)) || (!empty($mi[TIX_Sales::META_PREVIEW]) && $id !== self::$claiming);
        } else {
            $locked = ($id && self::locked_event($id)) || !empty($mi[self::META_ITEM]) || (!empty($mi['_tix_organizer_id']) && self::locked_org(intval($mi['_tix_organizer_id'])));
        }
        if ($locked) $data['post_status'] = 'draft';
        return $data;
    }

    /** Netz für Wege ohne wp_insert_post_data (wp_publish_post bei geplanten Beiträgen, direkte Status-Updates). */
    public static function guard_transition($new, $old, $post) {
        if (!$post || !in_array($new, self::PUBLIC_STATI, true) || !in_array($post->post_type, ['event', 'tix_organizer'], true)) return;
        if (self::locked_post($post->ID)) self::force_draft($post->ID);
    }

    /** Veranstalter-Zuordnung bzw. Vorschau-Merkmal an einem schon öffentlichen Beitrag → Entwurf. */
    public static function guard_meta($meta_id, $object_id, $meta_key, $value) {
        if (!in_array($meta_key, ['_tix_organizer_id', self::META_ITEM, TIX_Sales::META_PREVIEW], true)) return;
        if (!in_array((string) get_post_status($object_id), self::PUBLIC_STATI, true)) return;
        if (self::locked_post($object_id)) self::force_draft($object_id);
    }

    private static function force_draft($post_id) {
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_status' => 'draft'], ['ID' => intval($post_id)]);
        clean_post_cache($post_id);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
    }

    /** /tixomat/v1/events/{id}… (lesen, bearbeiten, Status, Kopie, Bilder, Berichte …) für Vorschau-Events → 403 tix_preview_locked. */
    public static function guard_rest($result, $server, $request) {
        if ($result !== null || !($request instanceof WP_REST_Request)) return $result;
        if (!preg_match('#^/' . preg_quote(self::NS, '#') . '/events/(\d+)(/|$)#', (string) $request->get_route(), $m)) return $result;
        if (!self::locked_event(intval($m[1]))) return $result;
        return new WP_Error('tix_preview_locked', 'Das ist eine Vorschau des Vertriebs. Bearbeiten geht nur über die Vorschau.', ['status' => 403]);
    }

    /** meta_query-Klausel für /events: keine Vorschau-Events (Merkmal bzw. Vorschau-Veranstalter); null ohne Vorschauen. */
    public static function event_list_clause() {
        $ids = array_map('intval', get_posts(['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            'meta_query' => [['key' => TIX_Sales::META_PREVIEW, 'compare' => 'EXISTS']]]));
        $c = ['relation' => 'AND', ['key' => self::META_ITEM, 'compare' => 'NOT EXISTS']];
        if ($ids) $c[] = ['relation' => 'OR', ['key' => '_tix_organizer_id', 'compare' => 'NOT EXISTS'], ['key' => '_tix_organizer_id', 'value' => array_map('strval', $ids), 'compare' => 'NOT IN']];
        return $c;
    }

    /** Verkauf (Web-Kasse, App-Kasse, POS über TIX_Org_Approval::sale_error) → WP_Error 403 | null */
    public static function sale_error($event_id) {
        if (!self::locked_event($event_id)) return null;
        return new WP_Error('tix_preview_locked', 'Das ist eine Vorschau – hier können keine Tickets verkauft werden.', ['status' => 403]);
    }


    // ═══════════════ Virtuelle Posts (öffentliche Antworten, Editor-Format) ═══════════════

    public static function rendering_org($oid) {
        return self::$render > 0 && intval($oid) === self::$render;
    }

    public static function rendering_event($event_id) {
        if (self::$render <= 0) return false;
        return intval(get_post_meta(intval($event_id), '_tix_organizer_id', true)) === self::$render && (bool) get_post_meta(intval($event_id), self::META_ITEM, true);
    }

    private static function org_vid(array $d) {
        return self::VID_BASE + intval($d['id']) * 100;
    }

    private static function event_vid(array $d, array $ev) {
        return self::org_vid($d) + intval($ev['vid']);
    }

    private static function cache_post($id, array $f) {
        $now = gmdate('Y-m-d H:i:s', intval($f['modified'] ?? time()));
        $local = get_date_from_gmt($now);
        $raw = (object) [
            'ID' => intval($id), 'post_author' => 0, 'post_date' => $local, 'post_date_gmt' => $now, 'post_content' => (string) ($f['content'] ?? ''),
            'post_title' => (string) ($f['title'] ?? ''), 'post_excerpt' => (string) ($f['excerpt'] ?? ''), 'post_status' => 'draft', 'comment_status' => 'closed',
            'ping_status' => 'closed', 'post_password' => '', 'post_name' => (string) ($f['slug'] ?? ''), 'to_ping' => '', 'pinged' => '', 'post_modified' => $local,
            'post_modified_gmt' => $now, 'post_content_filtered' => '', 'post_parent' => 0, 'guid' => '', 'menu_order' => 0, 'post_type' => (string) $f['type'],
            'post_mime_type' => '', 'comment_count' => 0, 'filter' => 'raw',
        ];
        wp_cache_set(intval($id), $raw, 'posts', 120);
        $meta = [];
        foreach ((array) ($f['meta'] ?? []) as $k => $v) $meta[$k] = [maybe_serialize($v)];
        wp_cache_set(intval($id), $meta, 'post_meta', 120);
        foreach (get_object_taxonomies((string) $f['type']) as $tax) {
            wp_cache_set(intval($id), $tax === 'event_category' && !empty($f['term_id']) ? [intval($f['term_id'])] : [], $tax . '_relationships', 120);
        }
        self::$mounted[intval($id)] = (string) $f['type'];
    }

    /** Paket als virtuelle Posts einhängen → [Veranstalter-ID, [key => Event-ID]] */
    private static function mount(array $d) {
        $o = (array) ($d['organizer'] ?? []);
        $ovid = self::org_vid($d);
        self::cache_post($ovid, ['type' => 'tix_organizer', 'title' => (string) ($o['name'] ?? $d['name']), 'modified' => $d['updated_at'] ?? $d['created_at'],
            'meta' => array_filter([TIX_Sales::META_PREVIEW => '1', self::META_LOGO => intval($o['logo_id'] ?? 0) ?: null, self::META_ACCENT => self::hex($o['accent'] ?? '') ?: null,
                '_tix_org_short_desc' => (string) ($o['short_desc'] ?? '') ?: null, '_tix_org_city' => (string) ($o['city'] ?? '') ?: null,
                '_tix_org_landing_social' => !empty($o['social']) && is_array($o['social']) ? $o['social'] : null], function ($v) { return $v !== null; })]);
        $ids = [];
        foreach ((array) ($d['events'] ?? []) as $ev) {
            $vid = self::event_vid($d, $ev);
            $meta = (array) ($ev['meta'] ?? []);
            $meta['_tix_organizer_id'] = $ovid;
            $meta['_tix_organizer'] = (string) ($o['name'] ?? $d['name']);
            $meta[self::META_ITEM] = '1';
            if (!empty($ev['image_id'])) $meta['_thumbnail_id'] = intval($ev['image_id']);
            self::cache_post($vid, ['type' => 'event', 'title' => $ev['title'], 'excerpt' => $ev['excerpt'] ?? '', 'slug' => $ev['slug'], 'modified' => $ev['updated_at'] ?? null,
                'term_id' => $ev['term_id'] ?? 0, 'meta' => $meta]);
            $ids[$ev['key']] = $vid;
        }
        self::$render = $ovid;
        return [$ovid, $ids];
    }

    private static function unmount() {
        foreach (self::$mounted as $id => $type) {
            wp_cache_delete($id, 'posts');
            wp_cache_delete($id, 'post_meta');
            foreach (get_object_taxonomies($type) as $tax) wp_cache_delete($id, $tax . '_relationships');
        }
        self::$mounted = [];
        self::$render = 0;
    }

    /** $fn() mit eingehängtem Paket ausführen (immer wieder aushängen). */
    private static function with_mounted(array $d, callable $fn) {
        $m = self::mount($d);
        try {
            return $fn($m[0], $m[1]);
        } finally {
            self::unmount();
        }
    }

    // ═══════════════ Datensatz ═══════════════

    const COLS = ['id', 'token', 'claim', 'owner', 'status', 'name', 'created_at', 'expires_at', 'opened_at', 'claimed_at', 'claimed_org'];

    private static function hydrate($row) {
        if (!$row) return null;
        $d = json_decode((string) $row->data, true);
        $d = is_array($d) ? $d : [];
        foreach (self::COLS as $c) $d[$c] = in_array($c, ['token', 'claim', 'status', 'name'], true) ? (string) $row->$c : intval($row->$c);
        $d['events'] = array_values((array) ($d['events'] ?? []));
        $d['organizer'] = (array) ($d['organizer'] ?? []);
        return $d;
    }

    /** Paket per ID → Datensatz | null */
    private static function get($id) {
        global $wpdb;
        if (!self::table_ready()) return null;
        return self::hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', intval($id))));
    }

    private static function by_token($token, $col) {
        global $wpdb;
        $token = (string) $token;
        if (!preg_match('/^[pc]_[A-Za-z0-9]{32}$/', $token) || !self::table_ready()) return null;
        return self::hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE $col = %s", $token)));
    }

    private static $ready = null;
    private static function table_ready() {
        if (self::$ready === null) self::$ready = get_option(self::OPT_DB) === self::DB_VERSION;
        return self::$ready;
    }

    private static function split(array $d) {
        $cols = [];
        foreach (self::COLS as $c) if ($c !== 'id' && array_key_exists($c, $d)) $cols[$c] = $d[$c];
        $data = array_diff_key($d, array_flip(self::COLS));
        $cols['data'] = wp_json_encode($data);
        return $cols;
    }

    private static function save(array &$d) {
        global $wpdb;
        $d['updated_at'] = time();
        $d['status'] = !empty($d['claimed_at']) ? 'claimed' : 'active';
        $wpdb->update(self::table(), self::split($d), ['id' => intval($d['id'])]);
    }

    private static function insert(array $d) {
        global $wpdb;
        $d['updated_at'] = time();
        $wpdb->insert(self::table(), self::split($d));
        return intval($wpdb->insert_id);
    }

    /** active | expired | claimed */
    public static function status(array $d) {
        if (!empty($d['claimed_at'])) return 'claimed';
        return intval($d['expires_at'] ?? 0) <= time() ? 'expired' : 'active';
    }

    private static function new_token($prefix) {
        return $prefix . '_' . wp_generate_password(32, false, false);
    }

    private static function hash($token) {
        return hash('sha256', (string) $token);
    }

    private static function gone_list() {
        $g = get_option(self::OPT_GONE, []);
        $g = is_array($g) ? $g : [];
        $min = time() - self::GONE_DAYS * DAY_IN_SECONDS;
        return array_filter($g, function ($ts) use ($min) { return intval($ts) > $min; });
    }

    private static function is_gone($token) {
        return isset(self::gone_list()[self::hash($token)]);
    }

    private static function mark_gone(array $tokens) {
        $g = self::gone_list();
        foreach ($tokens as $t) if ((string) $t !== '') $g[self::hash($t)] = time();
        update_option(self::OPT_GONE, $g, false);
    }

    private static function iso($ts) {
        $ts = intval($ts);
        return $ts > 0 ? wp_date('c', $ts) : null;
    }

    /** Events nach Beginn sortiert */
    private static function sorted_events(array $d) {
        $ev = (array) ($d['events'] ?? []);
        usort($ev, function ($a, $b) {
            $x = (string) ($a['meta']['_tix_date_start'] ?? '') . ' ' . (string) ($a['meta']['_tix_time_start'] ?? '');
            $y = (string) ($b['meta']['_tix_date_start'] ?? '') . ' ' . (string) ($b['meta']['_tix_time_start'] ?? '');
            return strcmp($x, $y) ?: intval($a['vid']) - intval($b['vid']);
        });
        return $ev;
    }

    private static function find_event(array $d, $key) {
        $key = (string) $key;
        foreach ((array) $d['events'] as $i => $ev) {
            if ($ev['key'] === $key || (ctype_digit($key) && intval($key) === self::event_vid($d, $ev)) || (string) ($ev['slug'] ?? '') === $key) return $i;
        }
        return -1;
    }

    // ═══════════════ Links, Marke ═══════════════

    private static function studio_base() {
        $b = class_exists('TIX_Studio') ? (string) TIX_Studio::base() : '';
        return $b !== '' ? $b : 'https://studio.evendis.de';
    }

    /**
     * hub (Übersichtsseite für den Kunden, 1.38.512), homepage (Homepage-Vorschau = hub + homepage/), public (Web), app
     * (App-Vorschau im Studio) – Token im Pfad.
     */
    public static function link($kind, $token) {
        if ($kind === 'hub' || $kind === 'homepage') $base = (string) get_option('tix_preview_home_base', '') ?: 'https://vorschau.evendis.de/';
        elseif ($kind === 'app') $base = (string) get_option('tix_preview_app_base', '') ?: self::studio_base() . '/app-vorschau/';
        else $base = (string) get_option('tix_preview_base', '') ?: 'https://evendis.de/vorschau/';
        return trailingslashit(esc_url_raw($base)) . rawurlencode((string) $token) . '/' . ($kind === 'homepage' ? 'homepage/' : '');
    }

    /** Link für den Vertriebler selbst (Mails/Glocke): ?intern=1, damit die Frontends den Aufruf nicht als „angesehen“ zählen (1.38.511). */
    public static function internal_link($kind, $token) {
        return add_query_arg('intern', '1', self::link($kind, $token));
    }

    public static function claim_link($claim) {
        return self::studio_base() . '/registrieren/?claim=' . rawurlencode((string) $claim);
    }

    private static function hex($v) {
        $v = trim((string) $v);
        if (preg_match('/^#?([0-9a-f]{3})$/i', $v, $m)) $v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        return preg_match('/^#?([0-9a-f]{6})$/i', $v, $m) ? '#' . strtoupper($m[1]) : '';
    }

    private static function default_accent() {
        $a = class_exists('TIX_Studio') && method_exists('TIX_Studio', 'accent') ? self::hex(TIX_Studio::accent()) : '';
        return $a !== '' ? $a : self::DEFAULT_ACCENT;
    }


    /** Farbe brauchbar als Akzent (nicht grau, nicht fast weiß/schwarz)? */
    private static function colorful($hex) {
        $hex = self::hex($hex);
        if ($hex === '') return false;
        $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
        $max = max($r, $g, $b); $min = min($r, $g, $b);
        return ($max - $min) >= 40 && $max >= 60 && $min <= 225;
    }

    /** Hauptfarbe eines Bildes (häufigste kräftige Farbe) oder ''. */
    public static function main_color($bytes) {
        if (!function_exists('imagecreatefromstring') || (string) $bytes === '') return '';
        $im = @imagecreatefromstring((string) $bytes);
        if (!$im) return '';
        $w = imagesx($im); $h = imagesy($im);
        $s = imagecreatetruecolor(32, 32);
        imagealphablending($s, false);
        imagesavealpha($s, true);
        imagecopyresampled($s, $im, 0, 0, 0, 0, 32, 32, $w, $h);
        $buckets = [];
        for ($x = 0; $x < 32; $x++) {
            for ($y = 0; $y < 32; $y++) {
                $c = imagecolorat($s, $x, $y);
                if ((($c >> 24) & 0x7F) > 80) continue; // durchsichtig
                $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                if (!self::colorful(sprintf('#%02X%02X%02X', $r, $g, $b))) continue;
                $k = ($r >> 5) . '-' . ($g >> 5) . '-' . ($b >> 5);
                if (!isset($buckets[$k])) $buckets[$k] = [0, 0, 0, 0];
                $buckets[$k][0]++; $buckets[$k][1] += $r; $buckets[$k][2] += $g; $buckets[$k][3] += $b;
            }
        }
        imagedestroy($s);
        imagedestroy($im);
        if (!$buckets) return '';
        uasort($buckets, function ($a, $b) { return $b[0] - $a[0]; });
        $top = reset($buckets);
        if ($top[0] < 8) return '';
        return sprintf('#%02X%02X%02X', intdiv($top[1], $top[0]), intdiv($top[2], $top[0]), intdiv($top[3], $top[0]));
    }

    /** Name, Logo-Adresse und Akzent der Quellseite (og:site_name, og:logo, JSON-LD logo, apple-touch-icon, Logo-Bild, theme-color). */
    public static function brand_from_html($html, $url) {
        $html = (string) $html;
        $E = 'TIX_Event_Import';
        $name = $E::meta($html, 'og:site_name') ?: $E::meta($html, 'application-name');
        $logo = $E::meta($html, 'og:logo');
        if ($logo === '' || $name === '') {
            foreach ($E::jsonld_nodes($html) as $n) {
                $t = implode(' ', array_map('strval', (array) ($n['@type'] ?? [])));
                if (!preg_match('/Organization|LocalBusiness|NightClub|BarOrPub|Restaurant|EventVenue|MusicVenue|Brand|WebSite|PerformingGroup|Place/i', $t)) continue;
                if ($name === '' && is_string($n['name'] ?? null) && !preg_match('/WebSite/i', $t)) $name = trim(wp_strip_all_tags($n['name']));
                if ($logo === '' && !empty($n['logo'])) {
                    $l = $n['logo'];
                    $logo = is_string($l) ? $l : (string) ($l['url'] ?? ($l['contentUrl'] ?? (is_array($l[0] ?? null) ? ($l[0]['url'] ?? '') : ($l[0] ?? ''))));
                }
            }
        }
        if ($logo === '' && preg_match_all('#<link[^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\'][^>]*>#i', $html, $mm)) {
            $best = 0;
            foreach ($mm[0] as $tag) {
                if (!preg_match('#href=["\']([^"\']+)["\']#i', $tag, $h)) continue;
                $size = preg_match('#sizes=["\'](\d+)#i', $tag, $sz) ? intval($sz[1]) : 1;
                if ($size >= $best) { $best = $size; $logo = html_entity_decode($h[1], ENT_QUOTES, 'UTF-8'); }
            }
        }
        if ($logo === '' && preg_match_all('#<img\b[^>]*>#i', $html, $mm)) {
            foreach ($mm[0] as $tag) {
                if (!preg_match('#(class|id|alt|src)=["\'][^"\']*logo#i', $tag)) continue;
                if (preg_match('#\ssrc=["\']([^"\']+)["\']#i', $tag, $h) && strpos($h[1], 'data:') !== 0) { $logo = html_entity_decode($h[1], ENT_QUOTES, 'UTF-8'); break; }
            }
        }
        if ($name === '' && preg_match('#<title[^>]*>(.*?)</title>#si', $html, $m)) {
            $parts = preg_split('/\s+[|–—\-·:]\s+/u', trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8')));
            $name = (string) end($parts);
        }
        $theme = self::hex($E::meta($html, 'theme-color'));
        return [
            'name' => mb_substr(trim($name), 0, 120),
            'logo' => $logo !== '' ? $E::absolute($logo, $url) : '',
            'accent' => self::colorful($theme) ? $theme : '',
        ];
    }

    // ═══════════════ Import ═══════════════

    /** Eingabe {url | text | image} prüfen → ['mode' => url|text|image, …] | WP_Error */
    private static function read_source(WP_REST_Request $req) {
        $url = trim((string) $req->get_param('url'));
        $text = trim(sanitize_textarea_field((string) $req->get_param('text')));
        $files = $req->get_file_params();
        $has_image = !empty($files['image']['tmp_name']) || (string) $req->get_param('image') !== '';
        if ($url !== '') {
            $clean = class_exists('TIX_Event_Import') ? TIX_Event_Import::clean_url($url) : '';
            if ($clean === '') return new WP_Error('url_invalid', 'Bitte eine vollständige Adresse eingeben, z. B. https://kunde.de/events.', ['status' => 400, 'field' => 'url']);
            return ['mode' => 'url', 'url' => $clean];
        }
        if ($has_image) {
            if (!class_exists('TIX_AI_REST') || !method_exists('TIX_AI_REST', 'read_image')) return new WP_Error('ai_unavailable', 'Der Flyer-Import ist hier nicht verfügbar.', ['status' => 503]);
            $img = TIX_AI_REST::read_image($req);
            if (is_wp_error($img)) return $img;
            return ['mode' => 'image', 'bytes' => $img[0], 'mime' => $img[1]];
        }
        if ($text !== '') {
            if (mb_strlen($text) < 10) return new WP_Error('tix_invalid_field', 'Beschreibe die Events in ein paar Worten (Titel, Datum, Ort).', ['status' => 400, 'field' => 'text']);
            if (mb_strlen($text) > 4000) return new WP_Error('tix_invalid_field', 'Bitte höchstens 4000 Zeichen.', ['status' => 400, 'field' => 'text']);
            return ['mode' => 'text', 'text' => $text];
        }
        return new WP_Error('tix_invalid_field', 'Bitte einen Link, einen Text oder einen Flyer angeben.', ['status' => 400, 'field' => 'url']);
    }

    /** Fehler des Imports wie POST /events/import-url (url_blocked mit read_mode blocked + notice). */
    private static function map_error(WP_Error $e) {
        $code = $e->get_error_code();
        $map = ['url_invalid' => 400, 'url_unreadable' => 422, 'url_blocked' => 422, 'ai_unavailable' => 503, 'tix_preview_no_events' => 422, 'tix_invalid_field' => 400];
        if (!isset($map[$code])) $code = 'url_unreadable';
        $d = (array) $e->get_error_data();
        $extra = $code === 'url_blocked' ? ['read_mode' => 'blocked', 'notice' => (string) ($d['notice'] ?? TIX_Event_Import::BLOCKED_NOTICE), 'reason' => (string) ($d['reason'] ?? '')] : [];
        $msg = $code === 'url_blocked' ? (string) ($d['notice'] ?? TIX_Event_Import::BLOCKED_NOTICE) : $e->get_error_message();
        return new WP_Error($code, $msg, ['status' => $map[$code]] + (isset($d['field']) ? ['field' => $d['field']] : ($code === 'url_blocked' || $code === 'url_unreadable' || $code === 'url_invalid' ? ['field' => 'url'] : [])) + $extra);
    }

    /** Mehrere Events aus JSON-LD (Veranstalter-/Programmseiten), kommende zuerst. */
    private static function structured_events($html, $url, $room) {
        $out = [];
        $seen = [];
        foreach (TIX_Event_Import::event_nodes($html) as $n) {
            $f = TIX_Event_Import::structured($html, $url, $n);
            if (!TIX_Event_Import::usable($f)) continue;
            $k = mb_strtolower($f['title']) . '|' . $f['date_start'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $f;
        }
        $today = current_time('Y-m-d');
        $upcoming = array_values(array_filter($out, function ($f) use ($today) { return ($f['date_end'] ?: $f['date_start']) >= $today; }));
        if ($upcoming) $out = $upcoming;
        usort($out, function ($a, $b) { return strcmp($a['date_start'] . $a['time_start'], $b['date_start'] . $b['time_start']); });
        return array_slice($out, 0, max(0, intval($room)));
    }

    /**
     * Quelle einlesen → ['events' => [Felder …], 'brand' => {name, logo, accent}, 'source_url', 'read_mode', 'notice', 'flyer' => [bytes, mime]|null]
     * oder WP_Error (url_invalid, url_blocked, url_unreadable, ai_unavailable, tix_preview_no_events).
     */
    private static function import(array $src, $room) {
        $brand = ['name' => '', 'logo' => '', 'accent' => ''];
        $read = ['direct', null];
        $flyer = null;
        if ($src['mode'] === 'url') {
            // Ein Abruf (Sperren und robots.txt wie beim Event-Import, 1.38.507)
            $page = TIX_Event_Import::fetch($src['url']);
            if (is_wp_error($page)) return $page;
            $final = $page['final_url'];
            $events = [];
            if (strpos((string) $page['content_type'], 'image/') !== 0) {
                $brand = self::brand_from_html($page['body'], $final);
                $events = self::structured_events($page['body'], $final, $room);
            }
            if (count($events) < 2) {
                $f = TIX_AI_Writer::import_url($final, $page);
                if (is_wp_error($f)) {
                    if (!$events) return $f;
                } else {
                    $read = [(string) ($f['_read_mode'] ?? 'direct'), $f['_notice'] ?? null];
                    $events = [$f];
                }
            }
            $source = $final;
        } else {
            if (!TIX_AI_Writer::provider_ready()) return new WP_Error('ai_unavailable', 'Die automatische Analyse ist hier nicht eingerichtet.', ['status' => 503]);
            if ($src['mode'] === 'text') {
                $f = TIX_AI_Writer::extract_text($src['text']);
            } else {
                list($b, $m) = TIX_AI_REST::shrink_for_api($src['bytes'], $src['mime']);
                $f = TIX_AI_Writer::extract_image(base64_encode($b), $m);
                $flyer = [$src['bytes'], $src['mime']];
                $brand['accent'] = self::main_color($src['bytes']);
            }
            if (is_wp_error($f)) return new WP_Error('ai_unavailable', 'Die automatische Analyse ist gerade nicht verfügbar. Bitte später noch einmal versuchen.', ['status' => 503, 'reason' => $f->get_error_message()]);
            $events = [$f];
            $source = '';
        }
        $events = array_values(array_filter($events, function ($f) { return trim((string) ($f['title'] ?? '')) !== ''; }));
        if (!$events) return new WP_Error('tix_preview_no_events', 'In der Quelle wurde kein Event gefunden. Bitte einen anderen Link, einen Flyer oder einen Text versuchen.', ['status' => 422]);
        return ['events' => array_slice($events, 0, max(1, intval($room))), 'brand' => $brand, 'source_url' => $source, 'read_mode' => $read[0], 'notice' => $read[1], 'flyer' => $flyer];
    }

    /** Bild herunterladen (nur JPG/PNG/WebP/GIF, robots.txt beachtet) → Anhang-ID | 0 */

    public static function accent_of(array $d) {
        return self::hex($d['organizer']['accent'] ?? '') ?: self::default_accent();
    }

    private static function logo_of(array $d) {
        $id = intval($d['organizer']['logo_id'] ?? 0);
        return $id && get_post_type($id) === 'attachment' ? (string) (wp_get_attachment_image_url($id, 'medium') ?: wp_get_attachment_url($id)) : '';
    }

    // ═══════════════ Bilder ═══════════════

    /** Bild herunterladen (nur JPG/PNG/WebP/GIF, robots.txt beachtet) → Anhang-ID des Pakets | 0 */
    private static function sideload($url, $pkg) {
        $url = esc_url_raw((string) $url, ['http', 'https']);
        if ($url === '' || !TIX_Event_Import::robots_allows($url)) return 0;
        $r = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 3, 'user-agent' => TIX_Event_Import::user_agent(), 'limit_response_size' => 8 * MB_IN_BYTES]);
        if (is_wp_error($r) || intval(wp_remote_retrieve_response_code($r)) !== 200) return 0;
        return self::store_bytes((string) wp_remote_retrieve_body($r), $pkg, 'vorschau');
    }

    /** Bilddaten als Anhang des Pakets speichern → Anhang-ID | 0 */
    private static function store_bytes($bytes, $pkg, $name) {
        $info = @getimagesizefromstring((string) $bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? '';
        if ($ext === '') return 0;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $up = wp_upload_bits(sanitize_file_name($name) . '-' . wp_generate_password(8, false, false) . '.' . $ext, null, (string) $bytes);
        if (!empty($up['error'])) return 0;
        $id = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => $name, 'post_status' => 'inherit', 'post_parent' => 0], $up['file']);
        if (!$id || is_wp_error($id)) return 0;
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $up['file']));
        self::own_attachment($id, $pkg);
        return intval($id);
    }

    /** Anhang gehört dem Paket (Meta = Paket-ID, kein Autor, kein Eltern-Post). */
    private static function own_attachment($id, $pkg) {
        global $wpdb;
        update_post_meta($id, self::META_ITEM, (string) intval($pkg));
        if (class_exists('TIX_Register_API') && defined('TIX_Register_API::TMP_META')) delete_post_meta($id, TIX_Register_API::TMP_META);
        $wpdb->update($wpdb->posts, ['post_author' => 0, 'post_parent' => 0], ['ID' => intval($id)]);
        clean_post_cache($id);
    }

    /** Anhang darf ins Paket: Bild, gehört schon dem Paket, dem Aufrufer oder Aufrufer ist Admin. */
    private static function image_allowed($id, array $d) {
        $id = intval($id);
        if (!$id || get_post_type($id) !== 'attachment' || strpos((string) get_post_mime_type($id), 'image/') !== 0) return false;
        return (string) get_post_meta($id, self::META_ITEM, true) === (string) $d['id'] || intval(get_post_field('post_author', $id)) === get_current_user_id() || current_user_can('manage_options');
    }

    private static function pkg_attachments($pkg) {
        return array_map('intval', get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            'meta_query' => [['key' => self::META_ITEM, 'value' => (string) intval($pkg)]]]));
    }

    // ═══════════════ Editor-Brücke (generisch) ═══════════════

    /** Unsichtbarer Sandbox-Veranstalter für technische Posts (Arbeits-Entwürfe, Test-Ticket-Events). */
    public static function tech_org() {
        global $wpdb;
        $id = intval(get_option(self::OPT_TECH, 0));
        if ($id && get_post_type($id) === 'tix_organizer' && get_post_meta($id, TIX_Sales::META_PREVIEW, true)) return $id;
        $id = wp_insert_post(['post_type' => 'tix_organizer', 'post_status' => 'draft', 'post_title' => 'Vorschau (technisch)',
            'meta_input' => [TIX_Sales::META_PREVIEW => '1', '_tix_org_approval' => 'approved', self::META_ITEM => 'tech']], true);
        if (is_wp_error($id) || !$id) return 0;
        $wpdb->update($wpdb->posts, ['post_author' => 0], ['ID' => intval($id)]);
        clean_post_cache($id);
        update_option(self::OPT_TECH, intval($id), false);
        TIX_Sales::flush();
        return intval($id);
    }

    /** Meta-Schlüssel, die das Paket vom Editor übernimmt: alle _tix_* außer Zuordnung, Status und interne Merker. */
    private static function capturable($key) {
        $key = (string) $key;
        if (strpos($key, '_tix_') !== 0) return false;
        foreach (['_tix_preview', '_tix_organizer', '_tix_org_', '_tix_co_organizer', '_tix_publish_held', '_tix_syndicat', '_tix_guard', '_tix_ai_', '_tix_status',
            '_tix_archived', '_tix_held', '_tix_source_', '_tix_web_', '_tix_mirror', '_tix_artist_ref'] as $p) {
            if (strpos($key, $p) === 0) return false;
        }
        return true;
    }

    /**
     * Event des Pakets auf einen kurzlebigen, gesperrten Arbeits-Entwurf legen, $body mit der echten Editor-Funktion anwenden,
     * Ergebnis (Titel, Kurztext, Bild, Sparte, alle _tix_*-Meta) zurücklesen, Entwurf löschen. → neues Event | WP_Error
     */
    private static function apply_editor(array $ev, array $body, $is_new) {
        global $wpdb;
        $tech = self::tech_org();
        if (!$tech) return new WP_Error('tix_preview_failed', 'Die Vorschau konnte nicht gespeichert werden.', ['status' => 500]);
        $sid = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => (string) ($ev['title'] ?? '') ?: 'Vorschau',
            'post_excerpt' => (string) ($ev['excerpt'] ?? ''), 'post_name' => (string) ($ev['slug'] ?? ''),
            'meta_input' => ['_tix_organizer_id' => $tech, self::META_ITEM => '1', self::META_SCRATCH => time()]], true);
        if (is_wp_error($sid) || !$sid) return new WP_Error('tix_preview_failed', 'Die Vorschau konnte nicht gespeichert werden.', ['status' => 500]);
        try {
            foreach ((array) ($ev['meta'] ?? []) as $k => $v) if (self::capturable($k)) update_post_meta($sid, $k, wp_slash($v));
            if (!empty($ev['image_id'])) update_post_meta($sid, '_thumbnail_id', intval($ev['image_id']));
            if (!empty($ev['term_id'])) wp_set_object_terms($sid, [intval($ev['term_id'])], 'event_category');
            $r = TIX_App_Events::apply_body($sid, $body, $is_new);
            if (is_wp_error($r)) return $r;
            clean_post_cache($sid);
            wp_cache_delete($sid, 'post_meta');
            $p = get_post($sid);
            $meta = [];
            foreach ((array) get_post_meta($sid) as $k => $vals) if (self::capturable($k) && isset($vals[0])) $meta[$k] = maybe_unserialize($vals[0]);
            $terms = wp_get_object_terms($sid, 'event_category', ['fields' => 'ids']);
            $ev['title'] = (string) $p->post_title;
            $ev['excerpt'] = (string) $p->post_excerpt;
            $ev['meta'] = $meta;
            $ev['term_id'] = (!is_wp_error($terms) && $terms) ? intval($terms[0]) : 0;
            $ev['updated_at'] = time();
            return $ev;
        } finally {
            wp_delete_post($sid, true);
        }
    }

    private static function unique_slug(array $d, $title, $skip_key = '') {
        $base = sanitize_title($title) ?: 'event';
        $used = [];
        foreach ((array) $d['events'] as $ev) if ($ev['key'] !== $skip_key) $used[(string) $ev['slug']] = true;
        $slug = $base;
        for ($i = 2; isset($used[$slug]); $i++) $slug = $base . '-' . $i;
        return $slug;
    }

    private static function next_slot(array $d) {
        $used = array_map(function ($e) { return intval($e['vid']); }, (array) $d['events']);
        for ($i = 1; $i < 100; $i++) if (!in_array($i, $used, true)) return $i;
        return 0;
    }

    private static function new_event(array $d, $title) {
        return ['key' => 'e_' . strtolower(wp_generate_password(8, false, false)), 'vid' => self::next_slot($d), 'title' => (string) $title, 'excerpt' => '',
            'slug' => self::unique_slug($d, $title), 'image_id' => 0, 'term_id' => 0, 'meta' => [], 'updated_at' => time()];
    }

    /** Import-Felder (KI bzw. strukturierte Daten) → Body des Event-Editors */
    private static function body_from_fields(array $f) {
        $b = ['title' => mb_substr(sanitize_text_field((string) ($f['title'] ?? '')), 0, 200), 'excerpt' => sanitize_textarea_field((string) ($f['excerpt'] ?? ''))];
        foreach (['date_start', 'date_end'] as $k) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$k] ?? ''))) $b[$k] = (string) $f[$k];
        foreach (['time_start', 'time_end', 'time_doors'] as $k) if (preg_match('/^\d{1,2}:\d{2}$/', (string) ($f[$k] ?? ''))) $b[$k] = substr('0' . $f[$k], -5);
        // Ort als Freitext (location_id 0 wie im Studio-Formular)
        if (trim((string) ($f['location'] ?? '')) !== '' || trim((string) ($f['location_address'] ?? '')) !== '') {
            $b['location_id'] = 0;
            $b['location'] = sanitize_text_field((string) ($f['location'] ?? ''));
            $b['address'] = sanitize_text_field((string) ($f['location_address'] ?? ''));
        }
        $info = [];
        foreach (['description', 'lineup', 'specials', 'extra_info'] as $k) if (trim((string) ($f[$k] ?? '')) !== '') $info[$k] = (string) $f[$k];
        if (intval($f['age_limit'] ?? 0) > 0) $info['age_limit'] = intval($f['age_limit']);
        if ($info) $b['info'] = $info;
        $cats = [];
        foreach ((array) ($f['tickets'] ?? []) as $t) {
            if (!is_array($t) || trim((string) ($t['name'] ?? '')) === '') continue;
            $cats[] = ['name' => mb_substr(sanitize_text_field((string) $t['name']), 0, 80), 'price' => max(0, round(floatval(str_replace(',', '.', (string) ($t['price'] ?? 0))), 2)),
                'qty' => max(0, intval($t['qty'] ?? 0)), 'desc' => sanitize_text_field((string) ($t['description'] ?? ($t['desc'] ?? '')))];
            if (count($cats) >= 12) break;
        }
        if ($cats) { $b['categories'] = $cats; $b['tickets_enabled'] = true; }
        $ids = array_map('intval', (array) ($f['category_ids'] ?? []));
        $term = $ids ? get_term($ids[0], 'event_category') : null;
        if (!$term && trim((string) ($f['event_type'] ?? '')) !== '') $term = get_term_by('slug', sanitize_title((string) $f['event_type']), 'event_category');
        if ($term && !is_wp_error($term)) $b['event_category'] = (string) $term->slug;
        return $b;
    }

    /** Importierte Events ins Paket (ohne Doppelte) → neue Schlüssel */
    private static function add_events(array &$d, array $imp) {
        $have = [];
        foreach ((array) $d['events'] as $ev) $have[mb_strtolower($ev['title']) . '|' . (string) ($ev['meta']['_tix_date_start'] ?? '')] = true;
        $new = [];
        $flyer = $imp['flyer'] ?? null;
        foreach ($imp['events'] as $f) {
            $body = self::body_from_fields($f);
            $k = mb_strtolower($body['title']) . '|' . (string) ($body['date_start'] ?? '');
            $ai_img = intval($f['suggested_image_id'] ?? 0);
            if ($body['title'] === '' || isset($have[$k]) || count($d['events']) >= self::MAX_EVENTS) {
                if ($ai_img && !get_post_field('post_parent', $ai_img)) wp_delete_attachment($ai_img, true);
                continue;
            }
            $ev = self::new_event($d, $body['title']);
            // Bild: Flyer (Import aus Bild), von der KI übernommen, sonst aus den strukturierten Daten
            $img = $flyer ? self::store_bytes($flyer[0], $d['id'], 'flyer') : 0;
            $flyer = null;
            if (!$img && $ai_img && get_post_type($ai_img) === 'attachment') { self::own_attachment($ai_img, $d['id']); $img = $ai_img; }
            if (!$img && !empty($f['_image'])) {
                $orig = TIX_Event_Import::original_image((string) $f['_image']);
                $img = self::sideload($orig ?: (string) $f['_image'], $d['id']);
            }
            $ev['image_id'] = $img;
            $ev = self::apply_editor($ev, $body, true);
            if (is_wp_error($ev)) continue;
            $d['events'][] = $ev;
            $have[$k] = true;
            $new[] = ['key' => $ev['key'], 'id' => self::event_vid($d, $ev), 'title' => (string) $ev['title']];
        }
        return $new;
    }

    /** Editor-Body auf ein Paket-Event anwenden: Bild selbst (Paket-Bilder), Rest über den Event-Editor → [Event, ignored] | WP_Error */
    private static function patch_event(array $d, array $ev, array $body) {
        // Hülle {fields: {…}} wie POST …/events
        if (isset($body['fields']) && is_array($body['fields'])) $body = $body['fields'] + array_diff_key($body, ['fields' => 1]);
        // Info-Abschnitte auch flach (description, lineup, specials, extra_info, age_limit, … wie info_sections des Editors)
        $secs = method_exists('TIX_App_Events', 'edit_response') ? array_column((array) (TIX_App_Events::edit_response(0)['info_sections'] ?? []), 'key') : ['description', 'lineup', 'specials', 'extra_info', 'age_limit'];
        foreach ($secs as $sk) {
            if (!array_key_exists($sk, $body) || is_array($body[$sk])) continue;
            $body['info'] = (isset($body['info']) && is_array($body['info']) ? $body['info'] : []) + [$sk => $body[$sk]];
            unset($body[$sk]);
        }
        // Ort ohne location_id = Freitext (der Editor übernimmt ihn sonst nicht)
        if ((array_key_exists('location', $body) || array_key_exists('address', $body)) && !array_key_exists('location_id', $body)) {
            $body['location_id'] = 0;
            $body += ['location' => (string) ($ev['meta']['_tix_location'] ?? ''), 'address' => (string) ($ev['meta']['_tix_address'] ?? '')];
        }
        $ignored = array_values(array_intersect(array_keys($body), self::NOT_IN_PREVIEW));
        foreach (self::NOT_IN_PREVIEW as $k) unset($body[$k]);
        if (array_key_exists('image_id', $body)) {
            $img = intval($body['image_id']);
            if ($img > 0 && !self::image_allowed($img, $d)) return new WP_Error('tix_invalid_field', 'Bild nicht gefunden oder nicht erlaubt.', ['status' => 400, 'field' => 'image_id']);
            if ($img > 0) self::own_attachment($img, $d['id']);
            $ev['image_id'] = max(0, $img);
            unset($body['image_id']);
        }
        if (array_key_exists('title', $body) && trim((string) $body['title']) === '') return new WP_Error('missing_title', 'Bitte einen Titel eingeben.', ['status' => 400, 'field' => 'title']);
        $ev = self::apply_editor($ev, $body, false);
        if (is_wp_error($ev)) return $ev;
        return [$ev, $ignored];
    }

    /** Editor-Format (wie GET /events/{id}/edit) eines Paket-Events */
    private static function edit_of(array $d, $key) {
        return self::with_mounted($d, function ($ovid, $ids) use ($key, $d) {
            if (!isset($ids[$key]) || !method_exists('TIX_App_Events', 'edit_response')) return null;
            $r = TIX_App_Events::edit_response($ids[$key]);
            $r['event']['key'] = $key;
            $r['event']['preview'] = true;
            return $r;
        });
    }

    // ═══════════════ Payload ═══════════════

    /** PREVIEW laut Vertrag (D, Pakete) */
    public static function payload($d) {
        if (is_numeric($d)) $d = self::get($d);
        if (!$d) return null;
        $st = self::status($d);
        $events = [];
        foreach (self::sorted_events($d) as $ev) {
            $img = intval($ev['image_id'] ?? 0);
            $events[] = ['id' => self::event_vid($d, $ev), 'key' => $ev['key'], 'title' => (string) $ev['title'], 'date_start' => (string) ($ev['meta']['_tix_date_start'] ?? ''),
                'image' => $img ? (string) (wp_get_attachment_image_url($img, 'medium_large') ?: wp_get_attachment_url($img)) : ''];
        }
        $public = self::link('public', $d['token']);
        $owner = get_userdata(intval($d['owner']));
        return [
            'id'             => intval($d['id']),
            'token'          => (string) $d['token'],
            'status'         => $st,
            'name'           => (string) $d['name'],
            'logo'           => self::logo_of($d) ?: null,
            'accent'         => self::accent_of($d),
            'template'       => self::template_of($d),
            'source_url'     => (string) ($d['source_url'] ?? ''),
            'created_at'     => self::iso($d['created_at'] ?? 0),
            'expires_at'     => self::iso($d['expires_at'] ?? 0),
            'extended'       => !empty($d['extended']),
            'opened_at'      => self::iso($d['opened_at'] ?? 0),
            'views'          => intval($d['views'] ?? 0),
            'last_view_at'   => self::iso($d['last_view_at'] ?? 0),
            'claimed_at'     => self::iso($d['claimed_at'] ?? 0),
            'claimed_org'    => intval($d['claimed_org'] ?? 0) ?: null,
            'customer_email' => (string) ($d['customer_email'] ?? '') ?: null,
            'note'           => (string) ($d['note'] ?? ''),
            'events_count'   => $st === 'claimed' ? intval($d['claimed_events'] ?? 0) : count($events),
            'events'         => $events,
            'links'          => [
                'hub'      => self::link('hub', $d['token']),
                'public'   => $public,
                'homepage' => self::link('homepage', $d['token']),
                'app'      => self::link('app', $d['token']),
                'claim'    => $st === 'claimed' ? null : self::claim_link($d['claim']),
                'qr'       => class_exists('TIX_QR') ? TIX_QR::url($public, 480) : '',
            ],
            'owner'          => ['id' => intval($d['owner']), 'name' => $owner ? (string) $owner->display_name : ''],
            'read_mode'      => (string) ($d['read_mode'] ?? 'direct'),
            'notice'         => $d['notice'] ?? null,
        ];
    }

    private static function template_of(array $d) {
        $t = (string) ($d['template'] ?? '');
        return in_array($t, self::TEMPLATES, true) ? $t : 'club';
    }

    // ═══════════════ REST ═══════════════

    public static function perm_sales() {
        if (!class_exists('TIX_Sales') || !TIX_Sales::multi()) return new WP_Error('rest_no_route', 'Nur im Mehr-Veranstalter-Modus.', ['status' => 404]);
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        return (TIX_Sales::is_sales_user() || current_user_can('manage_options')) ? true : new WP_Error('rest_forbidden', 'Nur für den Vertrieb.', ['status' => 403]);
    }

    public static function perm_public() {
        return (class_exists('TIX_Sales') && TIX_Sales::multi()) ? true : new WP_Error('rest_no_route', 'Nur im Mehr-Veranstalter-Modus.', ['status' => 404]);
    }

    public static function routes() {
        $s = ['permission_callback' => [__CLASS__, 'perm_sales']];
        $pub = ['permission_callback' => [__CLASS__, 'perm_public']];
        $ek = '(?P<event_key>[A-Za-z0-9_-]+)';
        register_rest_route(self::NS, '/sales/previews', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list']] + $s,
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_create']] + $s,
        ]);
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get']] + $s,
            ['methods' => 'PATCH', 'callback' => [__CLASS__, 'rest_patch']] + $s,
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete']] + $s,
        ]);
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)/events', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_add_events']] + $s);
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)/events/' . $ek, [
            ['methods' => 'PATCH', 'callback' => [__CLASS__, 'rest_patch_event']] + $s,
            ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete_event']] + $s,
        ]);
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)/events/' . $ek . '/edit', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_edit_event']] + $s);
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)/events/' . $ek . '/image', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_event_image']] + $s);
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)/logo', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_logo']] + $s);
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)/claim', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_claim']] + $s);
        register_rest_route(self::NS, '/sales/summary', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_summary']] + $s);

        register_rest_route(self::NS, '/public/previews/claim/(?P<claim>c_[A-Za-z0-9]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_claim_info']] + $pub);
        register_rest_route(self::NS, '/public/previews/(?P<token>p_[A-Za-z0-9]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public']] + $pub);
        register_rest_route(self::NS, '/public/previews/(?P<token>p_[A-Za-z0-9]+)/events/(?P<key>[A-Za-z0-9_-]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public_event']] + $pub);
        register_rest_route(self::NS, '/public/previews/(?P<token>p_[A-Za-z0-9]+)/seen', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_seen']] + $pub);
        register_rest_route(self::NS, '/public/previews/(?P<token>p_[A-Za-z0-9]+)/test-ticket', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_test_ticket']] + $pub);
    }

    private static function err_unknown() {
        return new WP_Error('tix_preview_unknown', 'Diese Vorschau gibt es nicht (mehr).', ['status' => 404]);
    }

    private static function err_expired() {
        return new WP_Error('tix_preview_expired', 'Diese Vorschau ist abgelaufen.', ['status' => 410]);
    }

    private static function err_claimed() {
        return new WP_Error('tix_preview_claimed', 'Diese Vorschau wurde schon übernommen.', ['status' => 409]);
    }

    /** Paket für /sales/* laden: eigenes (Admin alle) → Datensatz | WP_Error */
    private static function load($id) {
        $d = self::get($id);
        if (!$d) return self::err_unknown();
        if (!current_user_can('manage_options') && intval($d['owner']) !== get_current_user_id()) return new WP_Error('rest_forbidden', 'Das ist nicht deine Vorschau.', ['status' => 403]);
        return $d;
    }

    /** Änderbar? claimed → 409, abgelaufen → 410 */
    private static function editable(array $d) {
        $st = self::status($d);
        if ($st === 'claimed') return self::err_claimed();
        if ($st === 'expired') return self::err_expired();
        return null;
    }

    private static function respond($d, $status = 200, array $extra = []) {
        $r = rest_ensure_response(['ok' => true, 'preview' => self::payload($d)] + $extra);
        $r->set_status($status);
        return $r;
    }

    private static function limited($bucket, $max, $window) {
        return class_exists('TIX_App_Account') && TIX_App_Account::rate_limited($bucket, $max, $window, 'u' . get_current_user_id());
    }

    private static function body(WP_REST_Request $req) {
        $p = $req->get_json_params();
        return is_array($p) ? $p : $req->get_body_params();
    }

    /** POST /sales/previews {url|text|image, name?, customer_email?, note?, template?} → 201 {ok, preview} */
    public static function rest_create(WP_REST_Request $req) {
        if (!self::table_ready()) self::install();
        $src = self::read_source($req);
        if (is_wp_error($src)) return $src;
        $email = trim((string) $req->get_param('customer_email'));
        if ($email !== '' && !is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse des Kunden angeben.', ['status' => 400, 'field' => 'customer_email']);
        $tpl = (string) $req->get_param('template');
        if ($tpl !== '' && !in_array($tpl, self::TEMPLATES, true)) return new WP_Error('tix_invalid_field', 'Vorlage: club, festival oder klar.', ['status' => 400, 'field' => 'template']);
        if (self::limited('sales_preview', 30, HOUR_IN_SECONDS)) return new WP_Error('tix_rate_limit', 'Zu viele Vorschauen in kurzer Zeit. Bitte warte etwas.', ['status' => 429]);
        $imp = self::import($src, self::MAX_EVENTS);
        if (is_wp_error($imp)) return self::map_error($imp);

        $name = mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 120);
        if ($name === '') $name = $imp['brand']['name'];
        if ($name === '') $name = sanitize_text_field((string) ($imp['events'][0]['location'] ?? ''));
        if ($name === '' && $imp['source_url'] !== '') $name = preg_replace('/^www\./', '', (string) wp_parse_url($imp['source_url'], PHP_URL_HOST));
        if ($name === '') $name = sanitize_text_field((string) $imp['events'][0]['title']);
        $d = [
            'token' => self::new_token('p'), 'claim' => self::new_token('c'), 'owner' => get_current_user_id(), 'status' => 'active', 'name' => $name,
            'created_at' => time(), 'expires_at' => time() + self::DAYS * DAY_IN_SECONDS, 'opened_at' => 0, 'claimed_at' => 0, 'claimed_org' => 0,
            'extended' => false, 'views' => 0, 'last_view_at' => 0, 'claimed_by' => 0, 'template' => $tpl ?: 'club',
            'source_url' => $imp['source_url'], 'read_mode' => $imp['read_mode'], 'notice' => $imp['notice'],
            'customer_email' => $email !== '' ? sanitize_email($email) : '', 'note' => mb_substr(sanitize_textarea_field((string) $req->get_param('note')), 0, 1000),
            'organizer' => ['name' => $name, 'logo_id' => 0, 'accent' => ''], 'events' => [],
        ];
        $d['id'] = self::insert($d);
        if (!$d['id']) return new WP_Error('tix_preview_failed', 'Die Vorschau konnte nicht angelegt werden.', ['status' => 500]);
        if ($imp['brand']['logo'] !== '' && ($lid = self::sideload($imp['brand']['logo'], $d['id']))) {
            $d['organizer']['logo_id'] = $lid;
            if ($imp['brand']['accent'] === '') $imp['brand']['accent'] = self::main_color((string) @file_get_contents(get_attached_file($lid)));
        }
        $d['organizer']['accent'] = $imp['brand']['accent'];
        self::add_events($d, $imp);
        self::save($d);
        return self::respond($d, 201);
    }

    /** GET /sales/previews?status=&page=&owner= → {ok, previews, total} */
    public static function rest_list(WP_REST_Request $req) {
        global $wpdb;
        $status = sanitize_key((string) $req->get_param('status'));
        if ($status !== '' && !in_array($status, ['active', 'expired', 'claimed'], true)) return new WP_Error('tix_invalid_field', 'status: active, expired oder claimed.', ['status' => 400, 'field' => 'status']);
        $page = max(1, intval($req->get_param('page') ?: 1));
        $owner = current_user_can('manage_options') ? intval($req->get_param('owner')) : get_current_user_id();
        $where = $owner ? $wpdb->prepare('WHERE owner = %d', $owner) : '';
        $rows = self::table_ready() ? (array) $wpdb->get_results('SELECT * FROM ' . self::table() . " $where ORDER BY id DESC") : [];
        $all = [];
        foreach ($rows as $row) {
            $d = self::hydrate($row);
            if ($status === '' || self::status($d) === $status) $all[] = $d;
        }
        $list = array_values(array_filter(array_map([__CLASS__, 'payload'], array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE))));
        return rest_ensure_response(['ok' => true, 'previews' => $list, 'total' => count($all), 'page' => $page, 'per_page' => self::PER_PAGE]);
    }

    public static function rest_get(WP_REST_Request $req) {
        $d = self::load($req['id']);
        if (is_wp_error($d)) return $d;
        return self::respond($d);
    }

    /** PATCH /sales/previews/{id} {name?, accent?, logo_id?, template?, customer_email?, note?, extend?: true} */
    public static function rest_patch(WP_REST_Request $req) {
        $d = self::load($req['id']);
        if (is_wp_error($d)) return $d;
        $p = self::body($req);
        $extend = !empty($p['extend']) && filter_var($p['extend'], FILTER_VALIDATE_BOOLEAN);
        $st = self::status($d);
        if ($st === 'claimed') return self::err_claimed();
        // Abgelaufen (noch nicht gelöscht): nur Verlängern
        if ($st === 'expired' && !$extend) return self::err_expired();
        if ($extend && !empty($d['extended'])) return new WP_Error('tix_preview_extended', 'Die Vorschau wurde schon einmal verlängert.', ['status' => 409, 'field' => 'extend']);
        // erst alles prüfen, dann schreiben
        $name = array_key_exists('name', $p) ? mb_substr(sanitize_text_field((string) $p['name']), 0, 120) : null;
        if ($name === '') return new WP_Error('tix_invalid_field', 'Bitte einen Namen angeben.', ['status' => 400, 'field' => 'name']);
        $accent = null;
        if (array_key_exists('accent', $p)) {
            $accent = ($p['accent'] === null || $p['accent'] === '') ? '' : self::hex($p['accent']);
            if ($accent === '' && $p['accent'] !== null && $p['accent'] !== '') return new WP_Error('tix_invalid_field', 'Farbe bitte als #RRGGBB.', ['status' => 400, 'field' => 'accent']);
        }
        $tpl = null;
        if (array_key_exists('template', $p)) {
            $tpl = (string) $p['template'];
            if (!in_array($tpl, self::TEMPLATES, true)) return new WP_Error('tix_invalid_field', 'Vorlage: club, festival oder klar.', ['status' => 400, 'field' => 'template']);
        }
        $logo = null;
        if (array_key_exists('logo_id', $p)) {
            $logo = intval($p['logo_id']);
            if ($logo > 0 && !self::image_allowed($logo, $d)) return new WP_Error('tix_invalid_field', 'Dieses Bild gehört nicht zu dieser Vorschau.', ['status' => 403, 'field' => 'logo_id']);
        }
        $email = null;
        if (array_key_exists('customer_email', $p)) {
            $email = trim((string) $p['customer_email']);
            if ($email !== '' && !is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse des Kunden angeben.', ['status' => 400, 'field' => 'customer_email']);
        }
        if ($name !== null) { $d['name'] = $name; $d['organizer']['name'] = $name; }
        if ($accent !== null) $d['organizer']['accent'] = $accent;
        if ($tpl !== null) $d['template'] = $tpl;
        if ($logo !== null) {
            if ($logo > 0) self::own_attachment($logo, $d['id']);
            $d['organizer']['logo_id'] = max(0, $logo);
        }
        if ($email !== null) $d['customer_email'] = $email !== '' ? sanitize_email($email) : '';
        if (array_key_exists('note', $p)) $d['note'] = mb_substr(sanitize_textarea_field((string) $p['note']), 0, 1000);
        if ($extend) {
            $d['expires_at'] = max(intval($d['expires_at']), time()) + self::DAYS * DAY_IN_SECONDS;
            $d['extended'] = true;
        }
        self::save($d);
        return self::respond($d);
    }

    /** POST /sales/previews/{id}/logo (Multipart `image` oder Base64) */
    public static function rest_logo(WP_REST_Request $req) {
        $d = self::load($req['id']);
        if (is_wp_error($d)) return $d;
        if ($e = self::editable($d)) return $e;
        $img = TIX_AI_REST::read_image($req);
        if (is_wp_error($img)) return $img;
        $lid = self::store_bytes($img[0], $d['id'], 'logo');
        if (!$lid) return new WP_Error('tix_invalid_field', 'Das Logo konnte nicht gespeichert werden.', ['status' => 400, 'field' => 'image']);
        $d['organizer']['logo_id'] = $lid;
        if (!self::hex($d['organizer']['accent'] ?? '') && ($c = self::main_color($img[0])) !== '') $d['organizer']['accent'] = $c;
        self::save($d);
        return self::respond($d);
    }

    /** POST /sales/previews/{id}/events {url|text|image} bzw. {title, … Editor-Felder} → 201 {ok, preview, added} */
    public static function rest_add_events(WP_REST_Request $req) {
        $d = self::load($req['id']);
        if (is_wp_error($d)) return $d;
        if ($e = self::editable($d)) return $e;
        if (count($d['events']) >= self::MAX_EVENTS) return new WP_Error('tix_preview_full', 'Eine Vorschau hat höchstens ' . self::MAX_EVENTS . ' Events.', ['status' => 409]);
        $b = self::body($req);
        $fields = isset($b['fields']) && is_array($b['fields']) ? $b['fields'] : null;
        $manual = $fields !== null || (trim((string) ($b['title'] ?? '')) !== '' && trim((string) ($b['url'] ?? '')) === '' && trim((string) ($b['text'] ?? '')) === '' && empty($b['image']) && empty($req->get_file_params()['image']));
        if ($manual) {
            // Event von Hand ({fields} bzw. Felder direkt): wie im Event-Editor
            $f = $fields ?? $b;
            $title = sanitize_text_field((string) ($f['title'] ?? ''));
            if ($title === '') return new WP_Error('missing_title', 'Bitte einen Titel eingeben.', ['status' => 400, 'field' => 'title']);
            $ev = self::new_event($d, $title);
            $r = self::patch_event($d, $ev, $f);
            if (is_wp_error($r)) return $r;
            $d['events'][] = $r[0];
            self::save($d);
            $edit = self::edit_of($d, $r[0]['key']);
            return self::respond($d, 201, ['added' => [['key' => $r[0]['key'], 'id' => self::event_vid($d, $r[0]), 'title' => (string) $r[0]['title']]], 'ignored' => $r[1], 'event' => $edit['event'] ?? null, 'edit' => $edit]);
        }
        $src = self::read_source($req);
        if (is_wp_error($src)) return $src;
        if (self::limited('sales_preview', 30, HOUR_IN_SECONDS)) return new WP_Error('tix_rate_limit', 'Zu viele Importe in kurzer Zeit. Bitte warte etwas.', ['status' => 429]);
        $imp = self::import($src, self::MAX_EVENTS - count($d['events']));
        if (is_wp_error($imp)) return self::map_error($imp);
        $new = self::add_events($d, $imp);
        self::save($d);
        return self::respond($d, 201, ['added' => $new]);
    }

    private static function load_event(WP_REST_Request $req, $write = true) {
        $d = self::load($req['id']);
        if (is_wp_error($d)) return $d;
        if ($write && ($e = self::editable($d))) return $e;
        $i = self::find_event($d, (string) $req['event_key']);
        if ($i < 0) return new WP_Error('tix_event', 'Event nicht in dieser Vorschau.', ['status' => 404]);
        return [$d, $i];
    }

    /** GET /sales/previews/{id}/events/{key}/edit → Format von GET /events/{id}/edit */
    public static function rest_edit_event(WP_REST_Request $req) {
        $l = self::load_event($req, false);
        if (is_wp_error($l)) return $l;
        list($d, $i) = $l;
        $r = self::edit_of($d, $d['events'][$i]['key']);
        return $r ? rest_ensure_response($r) : self::err_unknown();
    }

    /** PATCH /sales/previews/{id}/events/{key} – Felder wie POST /events/{id} → {ok, preview, event (Editor-Format), ignored} */
    public static function rest_patch_event(WP_REST_Request $req) {
        $l = self::load_event($req);
        if (is_wp_error($l)) return $l;
        list($d, $i) = $l;
        $r = self::patch_event($d, $d['events'][$i], self::body($req));
        if (is_wp_error($r)) return $r;
        $d['events'][$i] = $r[0];
        self::save($d);
        $edit = self::edit_of($d, $r[0]['key']);
        return self::respond($d, 200, ['event' => $edit['event'] ?? null, 'edit' => $edit, 'ignored' => $r[1]]);
    }

    /** POST /sales/previews/{id}/events/{key}/image (Multipart `image` oder Base64) */
    public static function rest_event_image(WP_REST_Request $req) {
        $l = self::load_event($req);
        if (is_wp_error($l)) return $l;
        list($d, $i) = $l;
        $img = TIX_AI_REST::read_image($req);
        if (is_wp_error($img)) return $img;
        $id = self::store_bytes($img[0], $d['id'], 'event');
        if (!$id) return new WP_Error('tix_invalid_field', 'Das Bild konnte nicht gespeichert werden.', ['status' => 400, 'field' => 'image']);
        $d['events'][$i]['image_id'] = $id;
        $d['events'][$i]['updated_at'] = time();
        self::save($d);
        $edit = self::edit_of($d, $d['events'][$i]['key']);
        return self::respond($d, 200, ['image' => (string) ($edit['event']['image'] ?? ''), 'image_id' => $id, 'event' => $edit['event'] ?? null, 'edit' => $edit]);
    }

    /** DELETE /sales/previews/{id}/events/{key} */
    public static function rest_delete_event(WP_REST_Request $req) {
        $l = self::load_event($req);
        if (is_wp_error($l)) return $l;
        list($d, $i) = $l;
        $ev = $d['events'][$i];
        self::delete_tech_events($d['id'], $ev['key']);
        array_splice($d['events'], $i, 1);
        $img = intval($ev['image_id'] ?? 0);
        $used = $img === intval($d['organizer']['logo_id'] ?? 0) || in_array($img, array_map(function ($e) { return intval($e['image_id'] ?? 0); }, $d['events']), true);
        if ($img && !$used && (string) get_post_meta($img, self::META_ITEM, true) === (string) $d['id']) wp_delete_attachment($img, true);
        self::save($d);
        return self::respond($d);
    }

    /** DELETE /sales/previews/{id} – sofort löschen (Link danach 404). */
    public static function rest_delete(WP_REST_Request $req) {
        $d = self::load($req['id']);
        if (is_wp_error($d)) return $d;
        if (self::status($d) === 'claimed') return self::err_claimed();
        self::destroy($d, false);
        return rest_ensure_response(['ok' => true, 'deleted' => true]);
    }

    /** POST /sales/previews/{id}/claim {email?} – Link per Mail an den Kunden → {ok, claim_url, sent_to} */
    public static function rest_claim(WP_REST_Request $req) {
        $d = self::load($req['id']);
        if (is_wp_error($d)) return $d;
        if ($e = self::editable($d)) return $e;
        $email = trim((string) $req->get_param('email')) ?: (string) ($d['customer_email'] ?? '');
        if ($email === '' || !is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte die E-Mail-Adresse des Kunden angeben.', ['status' => 400, 'field' => 'email']);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('sales_claim_mail', 5, HOUR_IN_SECONDS, 'pk' . $d['id'])) return new WP_Error('tix_rate_limit', 'Für diese Vorschau wurden gerade schon mehrere Mails verschickt. Bitte warte etwas.', ['status' => 429]);
        $email = sanitize_email($email);
        $owner = get_userdata(intval($d['owner']));
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : get_bloginfo('name');
        $sent = class_exists('TIX_Mail_Layout') && TIX_Mail_Layout::send($email, $brand . ' – Deine Events sind vorbereitet', [
            'title' => 'Deine Events bei ' . $brand,
            'blocks' => [
                ['type' => 'text', 'html' => 'Hallo,<br>' . ($owner ? esc_html($owner->display_name) . ' hat' : 'wir haben') . ' für <strong>' . esc_html($d['name']) . '</strong> eine Vorschau mit ' . count($d['events']) . ' Events vorbereitet. Schau sie dir an und übernimm sie mit einem Klick in dein eigenes Konto.'],
                // Ein Link für den Kunden: die Übersichtsseite (hub) mit Website, Homepage, App, Test-Ticket und Übernehmen (1.38.512)
                ['type' => 'button', 'label' => 'Vorschau ansehen', 'url' => self::link('hub', $d['token'])],
                ['type' => 'text', 'html' => 'Die Vorschau ist bis ' . esc_html(wp_date('d.m.Y', intval($d['expires_at']))) . ' verfügbar. Nach der Übernahme liegen die Events als Entwürfe in deinem Studio.'],
            ],
            'kind' => 'account',
        ]);
        if (!$sent) return new WP_Error('tix_mail_failed', 'Die Mail konnte nicht verschickt werden.', ['status' => 502]);
        $d['customer_email'] = $email;
        $d['claim_sent_at'] = time();
        self::save($d);
        return rest_ensure_response(['ok' => true, 'claim_url' => self::claim_link($d['claim']), 'sent_to' => $email]);
    }

    // ── Auswertung ──

    /** Datensätze (Tabelle + gelöschte aus dem Archiv) für einen Vertriebler bzw. alle (0). */
    private static function records($owner) {
        global $wpdb;
        $out = [];
        if (self::table_ready()) {
            $where = $owner ? $wpdb->prepare('WHERE owner = %d', intval($owner)) : '';
            foreach ((array) $wpdb->get_results('SELECT id, created_at, opened_at, claimed_at, expires_at FROM ' . self::table() . " $where") as $r) {
                $st = intval($r->claimed_at) ? 'claimed' : (intval($r->expires_at) <= time() ? 'expired' : 'active');
                $out[] = ['created_at' => intval($r->created_at), 'opened_at' => intval($r->opened_at), 'claimed_at' => intval($r->claimed_at), 'status' => $st];
            }
        }
        $users = $owner ? [intval($owner)] : array_map('intval', get_users(['meta_key' => self::META_ARCHIVE, 'fields' => 'ID']));
        foreach ($users as $u) {
            foreach ((array) get_user_meta($u, self::META_ARCHIVE, true) as $a) {
                if (!is_array($a)) continue;
                $out[] = ['created_at' => intval($a['created_at'] ?? 0), 'opened_at' => intval($a['opened_at'] ?? 0), 'claimed_at' => 0, 'status' => !empty($a['expired']) ? 'expired' : 'deleted'];
            }
        }
        return $out;
    }

    /** Für GET /platform/sales: {total, opened, claimed} */
    public static function owner_stats($uid) {
        $r = self::records(intval($uid) ?: -1);
        return ['total' => count($r), 'opened' => count(array_filter($r, function ($x) { return $x['opened_at'] > 0; })),
            'claimed' => count(array_filter($r, function ($x) { return $x['claimed_at'] > 0; }))];
    }

    /** GET /sales/summary → {ok, previews:{active,opened,claimed,expired}, last_30_days:{created,opened,claimed}} */
    public static function rest_summary(WP_REST_Request $req) {
        $owner = current_user_can('manage_options') ? intval($req->get_param('owner')) : get_current_user_id();
        $r = self::records($owner);
        $since = time() - 30 * DAY_IN_SECONDS;
        $count = function ($fn) use ($r) { return count(array_filter($r, $fn)); };
        return rest_ensure_response(['ok' => true,
            'previews' => [
                'active'  => $count(function ($x) { return $x['status'] === 'active'; }),
                'opened'  => $count(function ($x) { return $x['opened_at'] > 0; }),
                'claimed' => $count(function ($x) { return $x['claimed_at'] > 0; }),
                'expired' => $count(function ($x) { return $x['status'] === 'expired'; }),
            ],
            'last_30_days' => [
                'created' => $count(function ($x) use ($since) { return $x['created_at'] >= $since; }),
                'opened'  => $count(function ($x) use ($since) { return $x['opened_at'] >= $since; }),
                'claimed' => $count(function ($x) use ($since) { return $x['claimed_at'] >= $since; }),
            ],
        ]);
    }

    /** Vertriebler entfernt (DELETE /platform/sales/{id}): Vorschauen und Archiv gehen an $to. */
    public static function reassign($from, $to) {
        global $wpdb;
        $from = intval($from); $to = intval($to);
        if (!$from || !$to || $from === $to) return 0;
        $n = self::table_ready() ? intval($wpdb->update(self::table(), ['owner' => $to], ['owner' => $from])) : 0;
        $arch = get_user_meta($from, self::META_ARCHIVE, true);
        if (is_array($arch) && $arch) {
            $mine = get_user_meta($to, self::META_ARCHIVE, true);
            update_user_meta($to, self::META_ARCHIVE, array_slice(array_merge(is_array($mine) ? $mine : [], $arch), -1000));
            delete_user_meta($from, self::META_ARCHIVE);
        }
        return $n;
    }

    private static function notify(array $d, $what) {
        $owner = get_userdata(intval($d['owner']));
        if (!$owner) return;
        $name = (string) $d['name'];
        $title = $what === 'opened' ? 'Vorschau geöffnet' : 'Vorschau übernommen';
        $body = $what === 'opened'
            ? 'Die Vorschau für „' . $name . '“ wurde zum ersten Mal geöffnet.'
            : '„' . $name . '“ hat die Vorschau übernommen und ein Konto angelegt. Die Freigabe läuft.';
        if (class_exists('TIX_Notifications')) TIX_Notifications::add_user_item($owner->ID, $title, $body, ['type' => 'sales', 'action' => 'sales_preview:' . intval($d['id'])]);
        if (class_exists('TIX_Mail_Layout')) {
            TIX_Mail_Layout::send($owner->user_email, TIX_Mail_Layout::brand()['name'] . ' – ' . $title . ': ' . $name, [
                'title' => $title,
                'blocks' => [
                    ['type' => 'text', 'html' => 'Hallo ' . esc_html($owner->display_name) . ',<br>' . esc_html($body)],
                    ['type' => 'button', 'label' => 'Vorschau ansehen', 'url' => self::internal_link('hub', $d['token'])],
                ],
                'kind' => 'admin',
            ], ['customer' => false]);
        }
    }

    // ═══════════════ Öffentlich ═══════════════

    private static function nostore($data, $status = 200) {
        $r = rest_ensure_response($data);
        $r->set_status($status);
        $r->header('Cache-Control', 'no-store, max-age=0');
        $r->header('X-Robots-Tag', 'noindex, nofollow');
        return $r;
    }

    /** Token → Datensatz | WP_Error (404 unbekannt, 410 abgelaufen bzw. gelöscht innerhalb von 30 Tagen) */
    private static function public_load($token, $allow_claimed = false) {
        $d = self::by_token($token, 'token');
        if (!$d) return self::is_gone($token) ? self::err_expired() : self::err_unknown();
        $st = self::status($d);
        if ($st === 'expired') return self::err_expired();
        if ($st === 'claimed' && !$allow_claimed) return self::err_claimed();
        return $d;
    }

    /** Event im öffentlichen Format (Liste bzw. Detail), Verkauf aus – nur mit eingehängtem Paket. */
    private static function public_event($vid, array $d, $detailed) {
        $e = TIX_Public_Events::payload($vid, $detailed);
        if (!$e) return null;
        foreach ($d['events'] as $ev) if (self::event_vid($d, $ev) === intval($vid)) { $e['key'] = $ev['key']; $e['slug'] = (string) $ev['slug']; }
        $e['url'] = self::link('public', $d['token']) . rawurlencode((string) $e['slug']) . '/';
        $e['tickets_enabled'] = false;
        $e['preview'] = true;
        if ($detailed) $e['sale'] = ['enabled' => false, 'reason' => 'preview'];
        return $e;
    }

    /** GET /public/previews/{token} */
    public static function rest_public(WP_REST_Request $req) {
        $d = self::public_load((string) $req['token'], true);
        if (is_wp_error($d)) return $d;
        if (self::status($d) === 'claimed') {
            $oid = intval($d['claimed_org']);
            $slug = $oid && class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($oid) : '';
            $live = $oid && get_post_status($oid) === 'publish' && (!class_exists('TIX_Org_Approval') || TIX_Org_Approval::is_public($oid)) && !TIX_Sales::is_sandbox_organizer($oid);
            return self::nostore(['ok' => true, 'preview' => true, 'noindex' => true, 'status' => 'taken_over', 'expires_at' => null, 'template' => self::template_of($d), 'organizer' => null, 'events' => [],
                'redirect' => ($live && $slug !== '') ? '/veranstalter/' . $slug : null]);
        }
        $out = self::with_mounted($d, function ($ovid, $ids) use ($d) {
            $events = [];
            foreach (self::sorted_events($d) as $ev) if ($e = self::public_event(self::event_vid($d, $ev), $d, false)) $events[] = $e;
            return [TIX_Public_Platform::organizer_payload($ovid, true, $events), $events];
        });
        list($org, $events) = $out;
        if (!$org) return self::err_unknown();
        $org['logo'] = self::logo_of($d) ?: ($org['logo'] ?? '');
        $org['accent'] = self::accent_of($d);
        $org['template'] = self::template_of($d);
        $org['preview'] = true;
        $org['events'] = $events;
        $owner = get_userdata(intval($d['owner']));
        return self::nostore(['ok' => true, 'preview' => true, 'noindex' => true, 'status' => 'active', 'expires_at' => self::iso($d['expires_at']), 'template' => self::template_of($d),
            'organizer' => $org, 'events' => $events, 'takeover_url' => self::claim_link($d['claim']),
            'contact' => ['name' => $owner ? (string) $owner->display_name : '', 'email' => $owner ? (string) $owner->user_email : '']]);
    }

    /** GET /public/previews/{token}/events/{id|slug|key} */
    public static function rest_public_event(WP_REST_Request $req) {
        $d = self::public_load((string) $req['token']);
        if (is_wp_error($d)) return $d;
        $i = self::find_event($d, (string) $req['key']);
        if ($i < 0) return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        $vid = self::event_vid($d, $d['events'][$i]);
        $e = self::with_mounted($d, function () use ($vid, $d) { return self::public_event($vid, $d, true); });
        if (!$e) return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        return self::nostore(['ok' => true, 'preview' => true, 'noindex' => true, 'event' => $e]);
    }

    /** POST /public/previews/{token}/seen {visit_id} → {ok, first, counted} (30 min je Besuch, höchstens 60 je Stunde) */
    public static function rest_seen(WP_REST_Request $req) {
        $d = self::public_load((string) $req['token']);
        if (is_wp_error($d)) return $d;
        $visit = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $req->get_param('visit_id')), 0, 64);
        if ($visit === '') return new WP_Error('tix_invalid_field', 'visit_id fehlt.', ['status' => 400, 'field' => 'visit_id']);
        $uid = get_current_user_id();
        if ($uid && ($uid === intval($d['owner']) || current_user_can('manage_options'))) return self::nostore(['ok' => true, 'first' => false, 'counted' => false]);
        $vk = 'tix_pv_v_' . md5($d['id'] . '|' . $visit);
        if (get_transient($vk)) return self::nostore(['ok' => true, 'first' => false, 'counted' => false]);
        $hk = 'tix_pv_h_' . $d['id'] . '_' . gmdate('YmdH');
        $n = intval(get_transient($hk));
        if ($n >= 60) return self::nostore(['ok' => true, 'first' => false, 'counted' => false]);
        set_transient($hk, $n + 1, HOUR_IN_SECONDS + 300);
        set_transient($vk, 1, 30 * MINUTE_IN_SECONDS);
        $first = empty($d['opened_at']);
        $d['views'] = intval($d['views'] ?? 0) + 1;
        $d['last_view_at'] = time();
        if ($first) $d['opened_at'] = time();
        self::save($d);
        if ($first) self::notify($d, 'opened');
        return self::nostore(['ok' => true, 'first' => $first, 'counted' => true]);
    }

    /** GET /public/previews/claim/{claim_token} → {ok, organizer{name,logo,accent}, events_count, customer_email|null, expires_at} */
    public static function rest_claim_info(WP_REST_Request $req) {
        $d = self::claim_target((string) $req['claim'], true);
        if (is_wp_error($d)) return $d;
        return self::nostore(['ok' => true, 'organizer' => ['name' => (string) $d['name'], 'logo' => self::logo_of($d) ?: null, 'accent' => self::accent_of($d)],
            'events_count' => count($d['events']), 'customer_email' => (string) ($d['customer_email'] ?? '') ?: null, 'expires_at' => self::iso($d['expires_at']), 'template' => self::template_of($d)]);
    }

    // ═══════════════ Test-Ticket (1.38.512, ab 1.38.514 an technischen Events) ═══════════════

    /** Technischer Entwurf-Event für Test-Tickets eines Paket-Events (anlegen bzw. auffrischen) → ID */
    private static function tech_event(array $d, array $ev) {
        global $wpdb;
        $tech = self::tech_org();
        if (!$tech) return 0;
        $ids = get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true, 'tix_include_sandbox' => 1,
            'meta_query' => [['key' => self::META_PKG, 'value' => (string) $d['id']], ['key' => self::META_KEY, 'value' => $ev['key']]]]);
        $meta = [];
        foreach (['_tix_date_start', '_tix_date_end', '_tix_time_start', '_tix_time_end', '_tix_time_doors', '_tix_location', '_tix_address'] as $k) $meta[$k] = (string) ($ev['meta'][$k] ?? '');
        if ($ids) {
            $id = intval($ids[0]);
            if (get_the_title($id) !== $ev['title']) wp_update_post(['ID' => $id, 'post_title' => $ev['title']]);
            foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
            return $id;
        }
        $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => (string) $ev['title'],
            'meta_input' => $meta + ['_tix_organizer_id' => $tech, self::META_ITEM => '1', self::META_PKG => (string) $d['id'], self::META_KEY => $ev['key']]], true);
        if (is_wp_error($id) || !$id) return 0;
        $wpdb->update($wpdb->posts, ['post_author' => 0], ['ID' => intval($id)]);
        clean_post_cache($id);
        return intval($id);
    }

    private static function tech_events($pkg, $key = '') {
        $mq = [['key' => self::META_PKG, 'value' => (string) intval($pkg)]];
        if ($key !== '') $mq[] = ['key' => self::META_KEY, 'value' => (string) $key];
        return array_map('intval', get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'tix_include_sandbox' => 1, 'meta_query' => $mq]));
    }

    /** Technische Events samt Test-Tickets und Bestellungen endgültig löschen → Anzahl Tickets */
    private static function delete_tech_events($pkg, $key = '') {
        $events = self::tech_events($pkg, $key);
        if (!$events) return 0;
        $n = self::delete_test_tickets($events);
        foreach ($events as $eid) if (get_post_meta($eid, self::META_PKG, true) !== '') wp_delete_post($eid, true);
        return $n;
    }

    /** POST /public/previews/{token}/test-ticket {name?, event_id?} → 201 wie /public/demo-ticket, für das nächste Event der Vorschau */
    public static function rest_test_ticket(WP_REST_Request $req) {
        $d = self::public_load((string) $req['token']);
        if (is_wp_error($d)) return $d;
        $events = self::sorted_events($d);
        $want = (string) ($req->get_param('event_id') ?? '');
        $ev = null;
        if ($want !== '' && $want !== '0') {
            $i = self::find_event($d, $want);
            if ($i < 0) return new WP_Error('tix_preview_event', 'Dieses Event gehört nicht zur Vorschau.', ['status' => 404]);
            $ev = $d['events'][$i];
        } else {
            $today = current_time('Y-m-d');
            foreach ($events as $e) {
                $end = (string) (($e['meta']['_tix_date_end'] ?? '') ?: ($e['meta']['_tix_date_start'] ?? ''));
                if ($end === '' || $end >= $today) { $ev = $e; break; }
            }
            if (!$ev && $events) $ev = $events[0];
        }
        if (!$ev) return new WP_Error('tix_preview_event', 'Die Vorschau hat noch kein Event.', ['status' => 404]);
        $name = mb_substr(trim(sanitize_text_field((string) $req->get_param('name'))), 0, 60) ?: 'Test-Gast';
        if ($e = TIX_Demo::ticket_limit_error('preview_ticket')) return $e;
        $eid = self::tech_event($d, $ev);
        if (!$eid) return new WP_Error('tix_demo_failed', 'Das Test-Ticket konnte nicht erstellt werden.', ['status' => 500]);
        $parts = preg_split('/\s+/', $name, 2);
        // Zahlart demo, Adresse *.invalid (keine Mail), kostenlos, ohne Kategorie
        $order_id = TIX_Native_Checkout::create_order(['billing_first_name' => $parts[0], 'billing_last_name' => $parts[1] ?? '',
            'billing_email' => 'test-' . strtolower(wp_generate_password(8, false, false)) . '@demo.invalid', 'payment_method' => 'demo', 'total' => 0,
            'items' => [['event_id' => $eid, 'cat_index' => -1, 'name' => 'Test-Ticket', 'event_title' => $ev['title'], 'price' => 0, 'qty' => 1, 'meta' => ['preview_test' => 1]]]]);
        if (!$order_id || is_wp_error($order_id)) return new WP_Error('tix_demo_failed', 'Das Test-Ticket konnte nicht erstellt werden.', ['status' => 500]);
        TIX_Native_Checkout::update_order_status($order_id, 'completed', 'demo');
        global $wpdb;
        $key = (string) $wpdb->get_var($wpdb->prepare("SELECT order_key FROM {$wpdb->prefix}tix_orders WHERE id = %d", $order_id));
        $t = class_exists('TIX_Tickets') ? TIX_Tickets::get_all_tickets_for_order($order_id) : [];
        if (!$t) return new WP_Error('tix_demo_failed', 'Das Test-Ticket konnte nicht erstellt werden.', ['status' => 500]);
        update_post_meta($t[0]['id'], '_tix_ticket_owner_name', $name);
        update_post_meta($t[0]['id'], '_tix_ticket_cat_name', 'Test-Ticket');
        update_post_meta($t[0]['id'], '_tix_ticket_cat_index', -1); // keine Kategorie des Events (Einlass zeigt sonst die erste)
        $p = TIX_Demo::ticket_payload(intval($t[0]['id']), $key);
        $view = $p['_view_url']; unset($p['_view_url']);
        $p['event']['key'] = $ev['key'];
        return self::nostore(['ok' => true, 'demo' => true, 'preview' => true, 'ticket' => $p, 'view_url' => $view, 'expires_at' => self::iso($d['expires_at'])], 201);
    }

    /** Paket eines technischen Events (bzw. Altbestand) → Datensatz | null */
    private static function pkg_of_event($event_id) {
        $pkg = intval(get_post_meta(intval($event_id), self::META_PKG, true));
        return $pkg ? self::get($pkg) : null;
    }

    /** Test-Ticket-Event → Ablauf der Vorschau (ISO) | null; für GET /public/demo-ticket/{code}. */
    public static function test_ticket_expiry($event_id) {
        $d = self::pkg_of_event($event_id);
        return $d && self::status($d) !== 'claimed' ? self::iso($d['expires_at']) : null;
    }

    /** Einlass: Inhaber der Vorschau (Vertriebler) bzw. Admin darf Test-Tickets scannen, solange die Vorschau läuft. */
    public static function can_checkin($event_id) {
        if (!is_user_logged_in()) return false;
        $d = self::pkg_of_event($event_id);
        if (!$d || self::status($d) !== 'active') return false;
        return current_user_can('manage_options') || intval($d['owner']) === get_current_user_id();
    }

    /** Test-Tickets und ihre Bestellungen (Zahlart demo) an diesen Events endgültig löschen → Anzahl Tickets */
    private static function delete_test_tickets(array $events) {
        global $wpdb;
        $events = array_filter(array_map('intval', $events));
        if (!$events || !class_exists('TIX_Demo')) return 0;
        $orders = array_filter(TIX_Demo::event_order_ids($events), function ($o) use ($wpdb) {
            return (string) $wpdb->get_var($wpdb->prepare("SELECT payment_method FROM {$wpdb->prefix}tix_orders WHERE id = %d", $o)) === 'demo';
        });
        $n = TIX_Demo::delete_event_tickets($events);
        TIX_Demo::delete_orders($orders);
        return $n;
    }

    // ═══════════════ Übernehmen ═══════════════

    /** Übernahme-Code → Paket | WP_Error (404 unbekannt, 410 abgelaufen, 409 übernommen); $full: Datensatz statt ID */
    public static function claim_target($claim, $full = false) {
        if (!class_exists('TIX_Sales') || !TIX_Sales::multi()) return self::err_unknown();
        $d = self::by_token($claim, 'claim');
        if (!$d) return self::is_gone($claim) ? self::err_expired() : self::err_unknown();
        $st = self::status($d);
        if ($st === 'claimed') return self::err_claimed();
        if ($st === 'expired') return self::err_expired();
        return $full ? $d : intval($d['id']);
    }

    /** Name für die Registrierung mit claim_token */
    public static function claim_name($pkg) {
        $d = self::get($pkg);
        return $d ? (string) $d['name'] : '';
    }

    /** Anhang an den neuen Inhaber geben (kein Paket-Merkmal mehr). */
    private static function hand_over_attachment($id, $uid, $parent) {
        global $wpdb;
        delete_post_meta($id, self::META_ITEM);
        $wpdb->update($wpdb->posts, ['post_author' => intval($uid), 'post_parent' => intval($parent)], ['ID' => intval($id)]);
        clean_post_cache($id);
    }

    /**
     * Nach POST /register/organizer mit claim_token: Veranstalter (neu, pending) bekommt Marke, Vorlage und echte Event-Entwürfe
     * aus dem Paket (alle Editor-Meta), Test-Tickets weg, Paket schrumpft auf den Auswertungs-Datensatz. → Zahl der Events
     */
    public static function complete_claim($pkg, $uid, $org_id = 0) {
        $d = self::get($pkg);
        if (!$d || !$org_id) return 0;
        $uid = intval($uid); $org_id = intval($org_id);
        $o = $d['organizer'];
        $logo = intval($o['logo_id'] ?? 0);
        if ($logo && get_post_type($logo) === 'attachment') { self::hand_over_attachment($logo, $uid, $org_id); update_post_meta($org_id, self::META_LOGO, $logo); }
        if (self::hex($o['accent'] ?? '')) update_post_meta($org_id, self::META_ACCENT, self::hex($o['accent']));
        foreach (['short_desc' => '_tix_org_short_desc', 'city' => '_tix_org_city'] as $k => $mk) if ((string) ($o[$k] ?? '') !== '') update_post_meta($org_id, $mk, (string) $o[$k]);
        if (!empty($o['social']) && is_array($o['social'])) update_post_meta($org_id, '_tix_org_landing_social', $o['social']);
        // Website-Vorlage für die spätere Website-Einstellung (Phase 3 des Website-Plans)
        update_post_meta($org_id, self::META_TEMPLATE, self::template_of($d));
        // Veranstalterseite auf evendis.de passend zur Vorlage (User-Entscheid A, 1.38.515) – nur ohne eigenes Design
        $design = ['club' => 'clubnacht', 'festival' => 'ticketschalter', 'klar' => 'galerie'][self::template_of($d)] ?? '';
        if ($design !== '' && (string) get_post_meta($org_id, '_tix_org_page_design', true) === '' && class_exists('TIX_Organizer_Page') && method_exists('TIX_Organizer_Page', 'set_design')) {
            TIX_Organizer_Page::set_design($org_id, $design);
        }
        $made = 0;
        foreach (self::sorted_events($d) as $ev) {
            $eid = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => (string) $ev['title'], 'post_excerpt' => (string) ($ev['excerpt'] ?? ''),
                'post_name' => (string) $ev['slug'], 'post_author' => $uid,
                'meta_input' => ['_tix_organizer_id' => $org_id, '_tix_organizer' => (string) get_the_title($org_id)]], true);
            if (is_wp_error($eid) || !$eid) continue;
            foreach ((array) $ev['meta'] as $k => $v) if (self::capturable($k)) update_post_meta($eid, $k, wp_slash($v));
            $img = intval($ev['image_id'] ?? 0);
            if ($img && get_post_type($img) === 'attachment') { self::hand_over_attachment($img, $uid, $eid); set_post_thumbnail($eid, $img); }
            if (!empty($ev['term_id'])) wp_set_object_terms($eid, [intval($ev['term_id'])], 'event_category');
            if (class_exists('TIX_App_Events')) TIX_App_Events::resolve_status($eid);
            if (class_exists('TIX_Artists')) TIX_Artists::reindex($eid);
            if (class_exists('TIX_Sync')) { $c = get_post_meta($eid, '_tix_ticket_categories', true); TIX_Sync::save_breakdance_meta($eid, is_array($c) ? $c : []); }
            $made++;
        }
        self::delete_tech_events($d['id']);
        foreach (self::pkg_attachments($d['id']) as $aid) wp_delete_attachment($aid, true);
        $d['claimed_at'] = time();
        $d['claimed_by'] = $uid;
        $d['claimed_org'] = $org_id;
        $d['claimed_events'] = $made;
        $d['events'] = [];
        $d['organizer'] = ['name' => $d['name']];
        self::save($d);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        self::notify($d, 'claimed');
        return $made;
    }

    // ═══════════════ Ablauf ═══════════════

    /** Paket samt Bildern und technischen Events löschen; $expired: Link antwortet noch 30 Tage mit 410. */
    private static function destroy(array $d, $expired) {
        global $wpdb;
        if (!empty($d['claimed_at'])) return false;
        self::delete_tech_events($d['id']);
        foreach (self::pkg_attachments($d['id']) as $aid) wp_delete_attachment($aid, true);
        $owner = intval($d['owner']);
        if ($owner && get_userdata($owner)) {
            $arch = get_user_meta($owner, self::META_ARCHIVE, true);
            $arch = is_array($arch) ? $arch : [];
            $arch[] = ['created_at' => intval($d['created_at']), 'opened_at' => intval($d['opened_at'] ?? 0), 'expired' => (bool) $expired, 'ended_at' => time()];
            update_user_meta($owner, self::META_ARCHIVE, array_slice($arch, -1000));
        }
        if ($expired) self::mark_gone([$d['token'], $d['claim']]);
        $wpdb->delete(self::table(), ['id' => intval($d['id'])]);
        return true;
    }

    /** Stündlich: abgelaufene, nicht übernommene Pakete löschen, liegengebliebene Arbeits-Entwürfe entfernen. → Anzahl */
    public static function expire() {
        global $wpdb;
        if (!class_exists('TIX_Sales') || !TIX_Sales::multi() || !self::table_ready()) return 0;
        $n = 0;
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE claimed_at = 0 AND expires_at <= %d LIMIT 200', time())) as $row) {
            if (self::destroy(self::hydrate($row), true)) $n++;
        }
        foreach (get_posts(['post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => 50, 'fields' => 'ids', 'suppress_filters' => true, 'tix_include_sandbox' => 1,
            'meta_query' => [['key' => self::META_SCRATCH, 'value' => time() - HOUR_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC']]]) as $sid) wp_delete_post($sid, true);
        update_option(self::OPT_GONE, self::gone_list(), false);
        return $n;
    }

    // ═══════════════ Migration der Vorschau-Posts (1.38.510–513) in Pakete ═══════════════

    /** Einmalig: Vorschau-Veranstalter (Posts) → Pakete; übernommene → Auswertungs-Datensätze. → Bericht */
    public static function migrate($dry = false) {
        global $wpdb;
        if (!self::table_ready()) self::install();
        $rep = ['at' => current_time('mysql'), 'dry_run' => (bool) $dry, 'converted' => [], 'claimed' => [], 'skipped' => [], 'deleted_posts' => 0, 'deleted_tickets' => 0];
        $orgs = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            'meta_query' => [['key' => '_tix_preview_data', 'compare' => 'EXISTS']]]);
        foreach (array_map('intval', $orgs) as $oid) {
            $old = get_post_meta($oid, '_tix_preview_data', true);
            if (!is_array($old) || empty($old['token']) || self::by_token($old['token'], 'token')) { $rep['skipped'][] = $oid; continue; }
            $base = ['token' => (string) $old['token'], 'claim' => (string) $old['claim'], 'owner' => intval($old['owner']), 'name' => (string) get_the_title($oid),
                'created_at' => intval($old['created_at']), 'expires_at' => intval($old['expires_at']), 'opened_at' => intval($old['opened_at'] ?? 0),
                'claimed_at' => intval($old['claimed_at'] ?? 0), 'claimed_org' => 0, 'extended' => !empty($old['extended']), 'views' => intval($old['views'] ?? 0),
                'last_view_at' => intval($old['last_view_at'] ?? 0), 'claimed_by' => intval($old['claimed_by'] ?? 0), 'template' => 'club',
                'source_url' => (string) ($old['source_url'] ?? ''), 'read_mode' => (string) ($old['read_mode'] ?? 'direct'), 'notice' => $old['notice'] ?? null,
                'customer_email' => (string) ($old['customer_email'] ?? ''), 'note' => (string) ($old['note'] ?? ''), 'migrated_from' => $oid];
            $events = array_map('intval', get_posts(['post_type' => 'event', 'post_status' => array_values(get_post_stati()), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
                'tix_include_sandbox' => 1, 'meta_query' => [['key' => '_tix_organizer_id', 'value' => (string) $oid]]]));
            $claimed = !empty($old['claimed_at']) || !get_post_meta($oid, TIX_Sales::META_PREVIEW, true);
            if ($claimed) {
                // übernommen: Veranstalter bleibt, nur der Auswertungs-Datensatz zieht um
                $rep['claimed'][] = ['org' => $oid, 'token' => substr($base['token'], 0, 8) . '…'];
                if ($dry) continue;
                $id = self::insert(array_merge($base, ['status' => 'claimed', 'claimed_org' => $oid, 'claimed_at' => $base['claimed_at'] ?: time(), 'claimed_events' => count($events), 'organizer' => ['name' => $base['name']], 'events' => []]));
                if ($id) foreach (['_tix_preview_data', '_tix_preview_token', '_tix_preview_claim', '_tix_preview_owner'] as $k) delete_post_meta($oid, $k);
                continue;
            }
            $rep['converted'][] = ['org' => $oid, 'events' => count($events), 'token' => substr($base['token'], 0, 8) . '…'];
            if ($dry) continue;
            $d = array_merge($base, ['status' => 'active', 'organizer' => ['name' => $base['name'], 'logo_id' => intval(get_post_meta($oid, self::META_LOGO, true)), 'accent' => (string) get_post_meta($oid, self::META_ACCENT, true)], 'events' => []]);
            $d['id'] = self::insert($d);
            if (!$d['id']) { $rep['skipped'][] = $oid; continue; }
            if ($d['organizer']['logo_id']) self::own_attachment($d['organizer']['logo_id'], $d['id']);
            foreach ($events as $eid) {
                $p = get_post($eid);
                $meta = [];
                foreach ((array) get_post_meta($eid) as $k => $vals) if (self::capturable($k) && isset($vals[0])) $meta[$k] = maybe_unserialize($vals[0]);
                $terms = wp_get_object_terms($eid, 'event_category', ['fields' => 'ids']);
                $ev = self::new_event($d, (string) $p->post_title);
                $ev['excerpt'] = (string) $p->post_excerpt;
                $ev['slug'] = (string) $p->post_name !== '' ? self::unique_slug($d, (string) $p->post_name) : $ev['slug'];
                $ev['meta'] = $meta;
                $ev['term_id'] = (!is_wp_error($terms) && $terms) ? intval($terms[0]) : 0;
                $thumb = intval(get_post_thumbnail_id($eid));
                if ($thumb) { self::own_attachment($thumb, $d['id']); $ev['image_id'] = $thumb; }
                $d['events'][] = $ev;
            }
            self::save($d);
            // Test-Tickets (es sind Tests) und die alten Posts endgültig löschen; Bilder gehören jetzt dem Paket
            $rep['deleted_tickets'] += self::delete_test_tickets($events);
            foreach ($events as $eid) { delete_post_meta($eid, '_thumbnail_id'); wp_delete_post($eid, true); $rep['deleted_posts']++; }
            wp_delete_post($oid, true);
            $rep['deleted_posts']++;
        }
        if (!$dry) {
            TIX_Sales::flush();
            if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
            update_option(self::OPT_MIG, current_time('mysql'), false);
            update_option(self::OPT_MIG_REPORT, $rep, false);
        }
        return $rep;
    }

    /**
     * WP-CLI: wp tixomat previews migrate [--dry-run] | report
     */
    public static function cli($args, $assoc) {
        $cmd = $args[0] ?? 'report';
        if ($cmd === 'migrate') {
            $dry = !empty($assoc['dry-run']);
            if (!$dry && get_option(self::OPT_MIG)) { WP_CLI::log('Schon migriert: ' . get_option(self::OPT_MIG)); WP_CLI::log(wp_json_encode(get_option(self::OPT_MIG_REPORT), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); return; }
            WP_CLI::log(wp_json_encode(self::migrate($dry), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }
        global $wpdb;
        $n = self::table_ready() ? intval($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table())) : 0;
        WP_CLI::log(wp_json_encode(['migrated_at' => get_option(self::OPT_MIG) ?: null, 'report' => get_option(self::OPT_MIG_REPORT) ?: null, 'packages' => $n,
            'old_preview_posts' => count(get_posts(['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
                'meta_query' => [['key' => '_tix_preview_data', 'compare' => 'EXISTS']]]))], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

TIX_Sales_Previews::init();
