<?php
/**
 * Event-Verlauf mit Wiederherstellen (1.38.776). Vertrag `uebergaben/vertrag-event-verlauf.md`, DOCUMENTATION §176.
 *
 * Je Speichern (= je Aufruf) eine Revision: wer, wann, über welche Seite und welchen Kanal, lesbarer Diff je Bereich
 * und der Stand der Quellfelder danach (Snapshot). Wiederherstellen ganz oder je Bereich, verkaufsrelevante Teile geschützt.
 * Geteilte Events: Person/Seite/Kennung (`origin`) reisen mit Verteilung, Abgleich und Durchgriff mit; wiederhergestellt
 * wird immer an der Quelle.
 *
 * Weg-1: eigene Tabelle {prefix}tix_event_history (JSON), reine Funktionen für Snapshot-Vergleich/Diff/Schutz, Routen.
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_History {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const OPT_DB = 'tix_event_history_db';
    const CRON = 'tix_event_history_cleanup';

    /** Bereich → Quellfelder. post_* = Felder des Beitrags, _thumbnail_id = Beitragsbild. */
    const AREAS = [
        'basis'    => ['post_title', 'post_status', 'post_excerpt', 'post_name', '_tix_event_status', '_tix_status', '_tix_organizer_id', '_tix_event_preset', '_tix_age_limit', '_tix_free_entry', '_tix_free_entry_note', '_tix_promoted'],
        'termin'   => ['_tix_date_start', '_tix_date_end', '_tix_time_start', '_tix_time_end', '_tix_time_doors', '_tix_countdown_target', '_tix_presale_active', '_tix_presale_start', '_tix_presale_end', '_tix_presale_end_mode', '_tix_presale_end_offset'],
        'ort'      => ['_tix_location_id', '_tix_location', '_tix_address', '_tix_venue_city', '_tix_venue_zip', '_tix_venue_lat', '_tix_venue_lng'],
        'texte'    => ['post_content', '_tix_info_description', '_tix_info_extra_info', '_tix_info_specials', '_tix_info_dresscode', '_tix_info_entry_rules', '_tix_info_ticket_notes', '_tix_info_excerpt', '_tix_info_artist', '_tix_info_age_limit', '_tix_info_sections', '_tix_faq', '_tix_accessibility'],
        'tickets'  => ['_tix_ticket_categories', '_tix_tickets_enabled', '_tix_specials', '_tix_specials_in_checkout', '_tix_specials_in_selector', '_tix_combo_deals', '_tix_group_discount', '_tix_box_office_only', '_tix_box_office_price', '_tix_box_office_desc', '_tix_extshop_enabled', '_tix_extshop_url', '_tix_extshop_text', '_tix_extshop_mode', '_tix_ticket_transfer', '_tix_table_reservation', '_tix_seatmap_id', '_tix_seatmap_mode', '_tix_ticket_fields', '_tix_tax_rate', '_tix_low_stock_mode', '_tix_low_stock_text', '_tix_low_stock_threshold', '_tix_partner_sales', '_tix_waitlist_enabled', '_tix_express_checkout'],
        'lineup'   => ['_tix_info_lineup', '_tix_lineup_order', '_tix_lineup_sort', '_tix_lineup_headliners', '_tix_lineup_roles', '_tix_lineup_links', '_tix_lineup_photos', '_tix_lineup_artist_map', '_tix_artists', '_tix_lineup_acts', '_tix_source_artists', '_tix_timetable', '_tix_timetable_hidden', '_tix_timetable_times_tba', '_tix_timetable_mode', '_tix_stages'],
        'bilder'   => ['_thumbnail_id', '_tix_gallery', '_tix_video_url', '_tix_video_id', '_tix_video_type', '_tix_video_embed', '_tix_ticket_sponsor_image_id', '_tix_ticket_sponsor_image_url', '_tix_ticket_sponsor_link'],
        'weiteres' => ['_tix_guest_list_enabled', '_tix_embed_enabled', '_tix_embed_domains', '_tix_upsell_disabled', '_tix_upsell_events', '_tix_raffle_enabled', '_tix_raffle_title', '_tix_raffle_description', '_tix_raffle_prizes', '_tix_raffle_end_date', '_tix_awareness', '_tix_awareness_mode', '_tix_charity_enabled', '_tix_charity_name', '_tix_charity_desc', '_tix_charity_percent', '_tix_charity_image', '_tix_syndicate'],
    ];

    const AREA_LABELS = ['basis' => 'Grunddaten', 'termin' => 'Termin', 'ort' => 'Ort', 'texte' => 'Texte', 'tickets' => 'Tickets & Preise', 'lineup' => 'Line-up & Programm', 'bilder' => 'Bilder & Video', 'weiteres' => 'Weiteres'];

    const LABELS = [
        'post_title' => 'Titel', 'post_status' => 'Sichtbarkeit', 'post_excerpt' => 'Kurzbeschreibung', 'post_name' => 'Adresse (Slug)', 'post_content' => 'Beschreibung (Seite)',
        '_tix_event_status' => 'Event-Status', '_tix_status' => 'Status', '_tix_organizer_id' => 'Veranstalter', '_tix_age_limit' => 'Altersgrenze', '_tix_free_entry' => 'Eintritt frei',
        '_tix_date_start' => 'Datum Beginn', '_tix_date_end' => 'Datum Ende', '_tix_time_start' => 'Beginn', '_tix_time_end' => 'Ende', '_tix_time_doors' => 'Einlass',
        '_tix_presale_start' => 'Vorverkauf ab', '_tix_presale_end' => 'Vorverkauf bis', '_tix_presale_active' => 'Vorverkauf aktiv', '_tix_countdown_target' => 'Countdown',
        '_tix_location_id' => 'Ort', '_tix_location' => 'Ortsname', '_tix_address' => 'Adresse', '_tix_venue_city' => 'Stadt', '_tix_venue_zip' => 'PLZ',
        '_tix_info_description' => 'Beschreibung', '_tix_info_extra_info' => 'Weitere Infos', '_tix_info_specials' => 'Specials (Text)', '_tix_info_dresscode' => 'Dresscode',
        '_tix_info_entry_rules' => 'Einlassregeln', '_tix_info_ticket_notes' => 'Ticket-Hinweise', '_tix_info_sections' => 'Info-Abschnitte', '_tix_faq' => 'FAQ', '_tix_accessibility' => 'Barrierefreiheit',
        '_tix_ticket_categories' => 'Ticket-Kategorien', '_tix_tickets_enabled' => 'Ticketverkauf', '_tix_specials' => 'Extras', '_tix_combo_deals' => 'Kombi-Angebote', '_tix_group_discount' => 'Gruppenrabatt',
        '_tix_extshop_enabled' => 'Externer Shop', '_tix_extshop_url' => 'Shop-Link', '_tix_ticket_transfer' => 'Ticket-Übertragung', '_tix_seatmap_id' => 'Saalplan', '_tix_tax_rate' => 'Steuersatz',
        '_tix_info_lineup' => 'Line-up (Text)', '_tix_lineup_order' => 'Line-up-Reihenfolge', '_tix_lineup_sort' => 'Line-up-Sortierung', '_tix_lineup_headliners' => 'Headliner', '_tix_lineup_roles' => 'Rollen',
        '_tix_artists' => 'Künstler', '_tix_lineup_acts' => 'Line-up', '_tix_source_artists' => 'Künstler', '_tix_timetable' => 'Programm', '_tix_timetable_hidden' => 'Programm ausgeblendet', '_tix_stages' => 'Bühnen',
        '_thumbnail_id' => 'Beitragsbild', '_tix_gallery' => 'Galerie', '_tix_video_url' => 'Video', '_tix_guest_list_enabled' => 'Gästeliste', '_tix_embed_enabled' => 'Einbetten',
        '_tix_raffle_enabled' => 'Gewinnspiel', '_tix_awareness' => 'Awareness', '_tix_charity_enabled' => 'Spende', '_tix_syndicate' => 'Auf evendis teilen',
    ];

    /** Kategorie-Felder, die nie in den Verlauf gehören (ändern sich mit jedem Verkauf bzw. technisch). */
    const CAT_SKIP = ['stock', 'product_id', 'sku', 'sold', 'tc_event_id'];

    private static $dirty = [];      // event_id => true
    private static $created = [];    // in diesem Aufruf angelegt
    private static $ctx = [];        // Aufruf-Kontext {actor_user_id, actor_name, actor_email, site, site_name, channel, origin, restored_from}
    private static $event_ctx = [];  // je Event (Abgleich mehrerer Events in einem Aufruf)
    private static $origin = null;
    private static $restoring = 0;

    // ═══════════════ Einrichtung ═══════════════

    public static function init() {
        add_action('pre_post_update', [__CLASS__, 'before_post'], 1, 2);
        add_action('save_post_event', [__CLASS__, 'on_save'], 1, 3);
        add_filter('update_post_metadata', [__CLASS__, 'before_meta'], 1, 3);
        add_filter('add_post_metadata', [__CLASS__, 'before_meta'], 1, 3);
        add_filter('delete_post_metadata', [__CLASS__, 'before_meta'], 1, 3);
        add_action('shutdown', [__CLASS__, 'flush'], 1);
        add_action('deleted_post', [__CLASS__, 'on_deleted_post']);
        add_action('delete_user', [__CLASS__, 'anonymize_user']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action(self::CRON, [__CLASS__, 'cleanup']);
        add_action('init', function () { if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 3600, 'daily', self::CRON); }, 30);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tix_event_history';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            rev int(11) NOT NULL,
            created_at datetime NOT NULL,
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_name varchar(190) NOT NULL DEFAULT '',
            actor_email varchar(190) NOT NULL DEFAULT '',
            site varchar(190) NOT NULL DEFAULT '',
            site_name varchar(190) NOT NULL DEFAULT '',
            channel varchar(20) NOT NULL DEFAULT '',
            origin varchar(120) NOT NULL DEFAULT '',
            areas varchar(190) NOT NULL DEFAULT '',
            diff longtext NULL,
            snapshot longtext NULL,
            hash char(40) NOT NULL DEFAULT '',
            restored_from int(11) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_rev (event_id,rev),
            KEY origin (origin),
            KEY created_at (created_at)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    public static function ready() {
        static $ok = null;
        if ($ok) return true;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) self::install();
        return $ok = (get_option(self::OPT_DB) === self::DB_VERSION);
    }

    // ═══════════════ Kontext (wer, wo, wie) ═══════════════

    /** Kontext für diesen Aufruf setzen (Durchgriff, Verteilung, Wiederherstellen). Felder werden ergänzt, nicht ersetzt. */
    public static function set_context(array $c, $event_id = 0) {
        $c = array_intersect_key($c, array_flip(['actor_user_id', 'actor_name', 'actor_email', 'site', 'site_name', 'channel', 'origin', 'restored_from']));
        if ($event_id) self::$event_ctx[intval($event_id)] = array_merge(self::$event_ctx[intval($event_id)] ?? [], $c);
        else self::$ctx = array_merge(self::$ctx, $c);
    }

    /** Person aus einem Durchgriff-Umschlag {name, email, site?, site_name?, channel?} übernehmen (Anzeige, kein Recht). */
    public static function context_from_actor($actor, $fallback_site = '', $fallback_site_name = '') {
        if (!is_array($actor)) return;
        $c = [];
        if (!empty($actor['name'])) $c['actor_name'] = mb_substr(sanitize_text_field((string) $actor['name']), 0, 190);
        if (!empty($actor['email']) && is_email($actor['email'])) $c['actor_email'] = (string) $actor['email'];
        $site = self::clean_host((string) ($actor['site'] ?? '')) ?: self::clean_host($fallback_site);
        if ($site !== '') $c['site'] = $site;
        $sn = (string) ($actor['site_name'] ?? '') ?: $fallback_site_name;
        if ($sn !== '') $c['site_name'] = mb_substr(sanitize_text_field($sn), 0, 190);
        $ch = (string) ($actor['channel'] ?? '');
        $c['channel'] = in_array($ch, ['studio', 'app', 'api'], true) ? $ch : 'studio';
        if (!empty($actor['origin']) && preg_match('/^[a-z0-9.-]+#[a-f0-9-]{8,40}$/i', (string) $actor['origin'])) $c['origin'] = (string) $actor['origin'];
        $c['actor_user_id'] = 0; // technisches Konto nicht als Person zeigen
        self::set_context($c);
    }

    /** Kopf für die Weitergabe (Verteilung/Abgleich/Durchgriff): wer und wo in diesem Aufruf. */
    public static function outgoing($event_id = 0) {
        $c = self::context($event_id);
        return ['origin' => $c['origin'], 'actor' => ['name' => $c['actor_name'], 'email' => $c['actor_email']], 'site' => $c['site'], 'site_name' => $c['site_name'],
            'channel' => $c['channel'], 'at' => gmdate('c')];
    }

    /** Eingehenden Kopf (Verteilung/Abgleich) für ein Event übernehmen: Kanal sync, Person/Seite/origin der Quelle. */
    public static function incoming($event_id, $h) {
        if (!is_array($h)) return;
        $c = ['channel' => 'sync', 'actor_user_id' => 0];
        if (!empty($h['actor']['name'])) $c['actor_name'] = mb_substr(sanitize_text_field((string) $h['actor']['name']), 0, 190);
        if (!empty($h['actor']['email']) && is_email($h['actor']['email'])) $c['actor_email'] = (string) $h['actor']['email'];
        if (!empty($h['site'])) $c['site'] = self::clean_host((string) $h['site']);
        if (!empty($h['site_name'])) $c['site_name'] = mb_substr(sanitize_text_field((string) $h['site_name']), 0, 190);
        if (!empty($h['origin']) && preg_match('/^[a-z0-9.-]+#[a-f0-9-]{8,40}$/i', (string) $h['origin'])) $c['origin'] = (string) $h['origin'];
        if (!empty($h['channel']) && $h['channel'] === 'restore') $c['channel'] = 'restore';
        self::set_context($c, $event_id);
    }

    private static function clean_host($h) {
        $h = strtolower(trim($h));
        if (strpos($h, '://') !== false) $h = (string) wp_parse_url($h, PHP_URL_HOST);
        return preg_replace('/^www\./', '', preg_replace('/[^a-z0-9.-]/', '', $h));
    }

    private static function host() {
        return self::clean_host((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    private static function origin() {
        if (self::$origin === null) self::$origin = self::host() . '#' . wp_generate_uuid4();
        return self::$origin;
    }

    /** Kanal des laufenden Aufrufs (für den Durchgriff an die Quelle). */
    public static function request_channel() {
        return self::channel();
    }

    /** Kopf der letzten Revision eines Events im Format von outgoing() (Abgleich an Kunden-Seiten) oder null. */
    public static function last_head_out($event_id) {
        $r = self::last_row($event_id);
        if (!$r || $r['channel'] === 'bestand') return null;
        return ['origin' => (string) $r['origin'], 'actor' => ['name' => (string) $r['actor_name'], 'email' => (string) $r['actor_email']], 'site' => (string) $r['site'],
            'site_name' => (string) $r['site_name'], 'channel' => (string) $r['channel'], 'at' => gmdate('c', strtotime($r['created_at'] . ' UTC'))];
    }

    /** Kanal aus dem Aufruf ableiten. */
    private static function channel() {
        if (self::$restoring) return 'restore';
        if (defined('WP_CLI') && WP_CLI) return 'cli';
        if (wp_doing_cron()) return 'system';
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $client = strtolower((string) ($_SERVER['HTTP_X_TIX_CLIENT'] ?? ''));
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            if ($client === 'app' || stripos($ua, 'Dart/') === 0) return 'app';
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            if ($client === 'studio' || strpos($uri, '/studio/') !== false || !empty($_SERVER['HTTP_X_TIX_STUDIO'])) return 'studio';
            return 'api';
        }
        if (is_admin()) return 'wp-admin';
        return 'system';
    }

    public static function context($event_id = 0) {
        $u = wp_get_current_user();
        $base = ['actor_user_id' => $u && $u->ID ? intval($u->ID) : 0, 'actor_name' => $u && $u->ID ? (string) $u->display_name : '', 'actor_email' => $u && $u->ID ? (string) $u->user_email : '',
            'site' => self::host(), 'site_name' => html_entity_decode((string) (tix_get_settings('syndication_site_name') ?: get_bloginfo('name')), ENT_QUOTES, 'UTF-8'),
            'channel' => self::channel(), 'origin' => self::origin(), 'restored_from' => null];
        // Technisches Konto des Durchgriffs nie als Person
        if ($base['actor_user_id'] && get_user_meta($base['actor_user_id'], '_tix_technical', true) === '1') { $base['actor_name'] = ''; $base['actor_email'] = ''; }
        return array_merge($base, self::$ctx, $event_id ? (self::$event_ctx[intval($event_id)] ?? []) : []);
    }

    // ═══════════════ Erfassen ═══════════════

    private static function tracked_key($key) {
        static $all = null;
        if ($all === null) { $all = []; foreach (self::AREAS as $keys) foreach ($keys as $k) $all[$k] = true; }
        return isset($all[$key]);
    }

    private static function relevant($event_id) {
        $p = get_post($event_id);
        if (!$p || $p->post_type !== 'event' || in_array($p->post_status, ['auto-draft', 'inherit'], true)) return false;
        if (get_post_meta($event_id, '_tix_system_giftcard_event', true) || get_post_meta($event_id, '_tix_system_wallet_event', true)) return false;
        return true;
    }

    /** Vor der ersten Änderung im Aufruf: Bestand sichern, falls es noch keine Revision gibt. */
    private static function touch($event_id) {
        $event_id = intval($event_id);
        if (!$event_id || isset(self::$dirty[$event_id])) return;
        if (!self::relevant($event_id) || !self::ready()) return;
        self::$dirty[$event_id] = true;
        if (empty(self::$created[$event_id]) && !self::last_row($event_id)) self::insert_baseline($event_id);
    }

    public static function before_post($post_id, $data) {
        if (get_post_type($post_id) === 'event') self::touch($post_id);
    }

    public static function on_save($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!$update) { self::$created[intval($post_id)] = true; }
        if (!isset(self::$dirty[intval($post_id)]) && self::relevant($post_id) && self::ready()) self::$dirty[intval($post_id)] = true;
    }

    public static function before_meta($check, $object_id, $meta_key) {
        if (self::tracked_key($meta_key) && get_post_type($object_id) === 'event') self::touch($object_id);
        return $check;
    }

    public static function on_deleted_post($post_id) {
        global $wpdb;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) return;
        $wpdb->delete(self::table(), ['event_id' => intval($post_id)], ['%d']);
        unset(self::$dirty[intval($post_id)]);
    }

    /** Ende des Aufrufs: je geändertem Event eine Revision. */
    public static function flush() {
        foreach (array_keys(self::$dirty) as $eid) {
            try { self::record($eid); } catch (Throwable $e) { if (function_exists('error_log')) error_log('[tixomat] Verlauf ' . $eid . ': ' . $e->getMessage()); }
        }
        self::$dirty = []; self::$created = []; self::$event_ctx = [];
    }

    /** Revision schreiben, wenn sich der Stand geändert hat. Rückgabe: rev oder 0. */
    public static function record($event_id, array $extra = []) {
        global $wpdb;
        $event_id = intval($event_id);
        if (!self::relevant($event_id) || !self::ready()) return 0;
        wp_cache_delete($event_id, 'post_meta');
        clean_post_cache($event_id);
        $snap = self::snapshot($event_id);
        $hash = self::hash($snap);
        $last = self::last_row($event_id);
        if ($last && $last['hash'] === $hash) return 0;
        $old = $last ? (self::decode($last['snapshot']) ?? []) : [];
        $diff = self::diff($old, $snap);
        if ($last && !$diff) return 0;
        $c = array_merge(self::context($event_id), $extra);
        $areas = array_values(array_unique(array_column($diff, 'area')));
        return self::insert($event_id, $c, $areas, $diff, $snap, $hash, $last ? 0 : 1);
    }

    private static function insert_baseline($event_id) {
        $snap = self::snapshot($event_id);
        $c = ['actor_user_id' => 0, 'actor_name' => '', 'actor_email' => '', 'site' => self::host(), 'site_name' => html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8'),
            'channel' => 'bestand', 'origin' => self::host() . '#' . wp_generate_uuid4(), 'restored_from' => null];
        self::insert($event_id, $c, [], [], $snap, self::hash($snap), 0);
    }

    private static function insert($event_id, array $c, array $areas, array $diff, array $snap, $hash, $first) {
        global $wpdb;
        $t = self::table();
        for ($try = 0; $try < 3; $try++) {
            $rev = intval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(rev), 0) + 1 FROM $t WHERE event_id = %d", $event_id)));
            $ok = $wpdb->insert($t, [
                'event_id' => $event_id, 'rev' => $rev, 'created_at' => current_time('mysql', true),
                'actor_user_id' => intval($c['actor_user_id'] ?? 0), 'actor_name' => mb_substr((string) ($c['actor_name'] ?? ''), 0, 190), 'actor_email' => mb_substr((string) ($c['actor_email'] ?? ''), 0, 190),
                'site' => mb_substr((string) ($c['site'] ?? ''), 0, 190), 'site_name' => mb_substr((string) ($c['site_name'] ?? ''), 0, 190),
                'channel' => $first && ($c['channel'] ?? '') !== 'sync' ? 'neu' : mb_substr((string) ($c['channel'] ?? ''), 0, 20),
                'origin' => mb_substr((string) ($c['origin'] ?? ''), 0, 120), 'areas' => implode(',', $areas),
                'diff' => wp_json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'snapshot' => wp_json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'hash' => $hash, 'restored_from' => isset($c['restored_from']) && $c['restored_from'] !== null ? intval($c['restored_from']) : null,
            ]);
            if ($ok) { do_action('tix_event_history_recorded', $event_id, $rev); return $rev; }
        }
        return 0;
    }

    // ═══════════════ Snapshot, Vergleich, Diff (rein) ═══════════════

    /** Stand der Quellfelder eines Events [Bereich => [Feld => Wert]]. */
    public static function snapshot($event_id) {
        $p = get_post($event_id);
        $out = [];
        foreach (self::AREAS as $area => $keys) {
            foreach ($keys as $k) {
                if (strpos($k, 'post_') === 0) { $v = $p ? (string) $p->$k : ''; }
                elseif (!metadata_exists('post', $event_id, $k)) { continue; }
                else { $v = get_post_meta($event_id, $k, true); }
                $out[$area][$k] = self::normalize($k, $v);
            }
        }
        return $out;
    }

    /** Werte vergleichbar machen (Kategorien ohne Bestand/technische Felder, Zahlen als Text). */
    public static function normalize($key, $v) {
        if (is_object($v)) $v = json_decode(wp_json_encode($v), true);
        if ($key === '_tix_ticket_categories' && is_array($v)) {
            foreach ($v as $i => $c) if (is_array($c)) $v[$i] = array_diff_key($c, array_flip(self::CAT_SKIP));
        }
        if (is_array($v)) return $v;
        if (is_bool($v)) return $v ? '1' : '0';
        return $v === null ? '' : (string) $v;
    }

    public static function hash(array $snap) {
        return sha1(wp_json_encode(self::sort_rec($snap)));
    }

    private static function sort_rec($v) {
        if (!is_array($v)) return $v;
        if (array_keys($v) !== range(0, count($v) - 1)) ksort($v);
        foreach ($v as $k => $x) $v[$k] = self::sort_rec($x);
        return $v;
    }

    private static function decode($s) {
        $v = json_decode((string) $s, true);
        return is_array($v) ? $v : null;
    }

    private static function short($v) {
        if (is_array($v)) return null;
        $s = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $v)));
        return mb_strlen($s) > 300 ? mb_substr($s, 0, 299) . '…' : $s;
    }

    private static function label($k) {
        return self::LABELS[$k] ?? ucfirst(str_replace('_', ' ', preg_replace('/^_tix_/', '', $k)));
    }

    /** Lesbarer Diff zweier Snapshots: [{area, field, label, kind, old, new}] */
    public static function diff(array $old, array $new) {
        $out = [];
        foreach (self::AREAS as $area => $keys) {
            foreach ($keys as $k) {
                $has_o = isset($old[$area]) && array_key_exists($k, $old[$area]);
                $has_n = isset($new[$area]) && array_key_exists($k, $new[$area]);
                if (!$has_o && !$has_n) continue;
                $o = $has_o ? $old[$area][$k] : null; $n = $has_n ? $new[$area][$k] : null;
                if (self::hash([$o]) === self::hash([$n])) continue;
                if ($k === '_tix_ticket_categories') { foreach (self::diff_categories((array) $o, (array) $n) as $d) $out[] = ['area' => $area, 'field' => $k] + $d; continue; }
                if (in_array($k, ['_tix_lineup_acts', '_tix_artists', '_tix_source_artists', '_tix_lineup_order', '_tix_lineup_headliners'], true)) { $out[] = ['area' => $area, 'field' => $k, 'label' => self::label($k), 'kind' => 'changed'] + self::diff_names($o, $n); continue; }
                $kind = !$has_o || $o === '' || $o === [] ? 'set' : (!$has_n || $n === '' || $n === [] ? 'removed' : 'changed');
                $row = ['area' => $area, 'field' => $k, 'label' => self::label($k), 'kind' => $kind, 'old' => self::short($o), 'new' => self::short($n)];
                if (is_array($o) || is_array($n)) { $row['old'] = null; $row['new'] = null; $row['detail'] = 'Liste geändert'; }
                $out[] = $row;
            }
        }
        return $out;
    }

    private static function cat_name($c, $i) {
        return is_array($c) && trim((string) ($c['name'] ?? '')) !== '' ? (string) $c['name'] : 'Kategorie ' . ($i + 1);
    }

    /** Kategorien je Name (sonst Position): hinzugefügt/entfernt, Preis/Kontingent/Name/versteckt/sonstiges. */
    public static function diff_categories(array $o, array $n) {
        $out = [];
        $byname = function ($list) { $m = []; foreach (array_values($list) as $i => $c) $m[mb_strtolower(self::cat_name($c, $i))] = [$i, (array) $c]; return $m; };
        $mo = $byname($o); $mn = $byname($n);
        foreach ($mn as $key => [$i, $c]) {
            $nm = self::cat_name($c, $i);
            if (!isset($mo[$key])) { $out[] = ['label' => 'Kategorie „' . $nm . '“', 'kind' => 'added', 'old' => null, 'new' => isset($c['price']) ? (string) $c['price'] : null]; continue; }
            $oc = $mo[$key][1];
            foreach (['price' => 'Preis', 'qty' => 'Kontingent', 'hidden' => 'versteckt', 'desc' => 'Beschreibung', 'tax_rate' => 'Steuersatz'] as $f => $fl) {
                $a = array_key_exists($f, $oc) ? $oc[$f] : null; $b = array_key_exists($f, $c) ? $c[$f] : null;
                if ((string) (is_array($a) ? wp_json_encode($a) : $a) !== (string) (is_array($b) ? wp_json_encode($b) : $b)) {
                    $out[] = ['label' => 'Ticket „' . $nm . '“: ' . $fl, 'kind' => 'changed', 'old' => self::short($a), 'new' => self::short($b)];
                }
            }
            $rest_o = array_diff_key($oc, array_flip(['price', 'qty', 'hidden', 'desc', 'tax_rate', 'name'])); $rest_n = array_diff_key($c, array_flip(['price', 'qty', 'hidden', 'desc', 'tax_rate', 'name']));
            if (self::hash([$rest_o]) !== self::hash([$rest_n])) $out[] = ['label' => 'Ticket „' . $nm . '“: weitere Einstellungen', 'kind' => 'changed', 'old' => null, 'new' => null];
        }
        foreach ($mo as $key => [$i, $c]) if (!isset($mn[$key])) $out[] = ['label' => 'Kategorie „' . self::cat_name($c, $i) . '“', 'kind' => 'removed', 'old' => isset($c['price']) ? (string) $c['price'] : null, 'new' => null];
        if (!$out && self::hash([$o]) !== self::hash([$n])) $out[] = ['label' => 'Ticket-Kategorien: Reihenfolge', 'kind' => 'changed', 'old' => null, 'new' => null];
        return $out;
    }

    private static function names($v) {
        $out = [];
        foreach ((array) $v as $x) {
            if (is_array($x)) $x = $x['name'] ?? ($x['artist_name'] ?? ($x['artist_id'] ?? ''));
            $x = trim((string) $x);
            if ($x !== '') $out[] = $x;
        }
        return $out;
    }

    private static function diff_names($o, $n) {
        $a = self::names($o); $b = self::names($n);
        $add = array_values(array_diff($b, $a)); $rem = array_values(array_diff($a, $b));
        $parts = [];
        if ($add) $parts[] = 'neu: ' . implode(', ', array_slice($add, 0, 10));
        if ($rem) $parts[] = 'entfernt: ' . implode(', ', array_slice($rem, 0, 10));
        if (!$add && !$rem) $parts[] = 'Reihenfolge bzw. Angaben geändert';
        return ['old' => null, 'new' => null, 'detail' => implode(' · ', $parts)];
    }

    // ═══════════════ Lesen ═══════════════

    private static function last_row($event_id) {
        global $wpdb;
        if (!self::ready()) return null;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE event_id = %d ORDER BY rev DESC LIMIT 1", intval($event_id)), ARRAY_A);
        return $r ?: null;
    }

    public static function row($event_id, $rev) {
        global $wpdb;
        if (!self::ready()) return null;
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE event_id = %d AND rev = %d", intval($event_id), intval($rev)), ARRAY_A);
        return $r ?: null;
    }

    public static function is_copy($event_id) {
        return get_post_meta($event_id, '_tix_syndicated', true) === '1' || (string) get_post_meta($event_id, '_tix_platform_co', true) !== '';
    }

    private static function source_site($event_id) {
        $s = (string) get_post_meta($event_id, '_tix_source_site', true);
        if ($s === '') $s = (string) get_post_meta($event_id, '_tix_platform_co_source', true);
        return $s;
    }

    public static function head(array $r, $with_email = false) {
        $label = ['studio' => 'Studio', 'app' => 'App', 'wp-admin' => 'WordPress', 'api' => 'Schnittstelle', 'cli' => 'Wartung', 'sync' => 'Übernommen', 'restore' => 'Wiederhergestellt',
            'system' => 'Automatisch', 'bestand' => 'Stand vor dem Verlauf', 'neu' => 'Angelegt'];
        $areas = array_values(array_filter(explode(',', (string) $r['areas'])));
        $summary = $r['channel'] === 'bestand' ? 'Stand vor der ersten erfassten Änderung' : ($areas ? implode(', ', array_map(function ($a) { return self::AREA_LABELS[$a] ?? $a; }, $areas)) . ' geändert' : 'Gespeichert');
        if ($r['channel'] === 'restore' && $r['restored_from'] !== null) $summary = 'Stand von Version ' . intval($r['restored_from']) . ' wiederhergestellt' . ($areas ? ' (' . implode(', ', array_map(function ($a) { return self::AREA_LABELS[$a] ?? $a; }, $areas)) . ')' : '');
        return [
            'rev' => intval($r['rev']), 'at' => gmdate('c', strtotime($r['created_at'] . ' UTC')),
            'actor' => array_filter(['name' => $r['actor_name'] !== '' ? $r['actor_name'] : ($r['channel'] === 'system' || $r['channel'] === 'bestand' ? 'System' : 'Unbekannt'), 'email' => $with_email && $r['actor_email'] !== '' ? $r['actor_email'] : null], function ($v) { return $v !== null; }),
            'site' => (string) $r['site'], 'site_name' => (string) $r['site_name'], 'channel' => (string) $r['channel'], 'channel_label' => $label[$r['channel']] ?? $r['channel'],
            'origin' => (string) $r['origin'], 'areas' => $areas, 'summary' => $summary,
            'restored_from' => $r['restored_from'] !== null ? intval($r['restored_from']) : null, 'restorable' => $r['snapshot'] !== null && $r['snapshot'] !== '',
        ];
    }

    // ═══════════════ Wiederherstellen ═══════════════

    /**
     * Stand einer Revision (alle oder gewählte Bereiche) wiederherstellen. Rückgabe {ok, rev, areas, protected} | WP_Error.
     */
    public static function restore($event_id, $rev, array $areas = []) {
        $event_id = intval($event_id);
        if (self::is_copy($event_id)) {
            $site = self::source_site($event_id);
            return new WP_Error('tix_history_copy', 'Dieses Event gehört ' . ($site ?: 'einer anderen Seite') . ' – bitte dort wiederherstellen (bzw. als Co-Veranstalter im Studio über den gemeinsamen Zugriff).', ['status' => 409, 'source_site' => $site]);
        }
        if (get_post_status($event_id) === 'trash') return new WP_Error('tix_history_trash', 'Das Event liegt im Papierkorb. Bitte zuerst wiederherstellen.', ['status' => 409]);
        $row = self::row($event_id, $rev);
        if (!$row) return new WP_Error('tix_not_found', 'Diese Version gibt es nicht.', ['status' => 404]);
        $snap = self::decode($row['snapshot']);
        if (!$snap) return new WP_Error('tix_history_no_snapshot', 'Für diese Version ist der Stand nicht mehr gespeichert (Aufbewahrung abgelaufen).', ['status' => 410]);
        $areas = $areas ? array_values(array_intersect(array_keys(self::AREAS), $areas)) : array_keys(self::AREAS);
        if (!$areas) return new WP_Error('tix_invalid_field', 'Unbekannter Bereich.', ['status' => 400, 'field' => 'areas']);
        $current = self::snapshot($event_id);
        $plan = self::restore_plan($current, $snap, $areas, self::sold_by_cat($event_id), self::has_sales($event_id));
        if (!$plan['set'] && !$plan['delete']) return ['ok' => true, 'rev' => null, 'areas' => $areas, 'protected' => $plan['protected'], 'unchanged' => true];

        self::touch($event_id);
        self::$restoring = 1;
        self::set_context(['channel' => 'restore', 'restored_from' => intval($rev)], $event_id);
        try {
            $post = [];
            foreach ($plan['set'] as $k => $v) {
                if (strpos($k, 'post_') === 0) { $post[$k] = $v; continue; }
                if ($k === '_thumbnail_id') { if ($v && get_post($v)) set_post_thumbnail($event_id, intval($v)); continue; }
                update_post_meta($event_id, $k, wp_slash($k === '_tix_ticket_categories' ? self::restore_categories($event_id, (array) $v) : $v));
            }
            foreach ($plan['delete'] as $k) {
                if ($k === '_thumbnail_id') delete_post_thumbnail($event_id);
                elseif (strpos($k, 'post_') !== 0) delete_post_meta($event_id, $k);
            }
            // Speichern auslösen: Anzeige-Felder, Verteilung, Homepages (wie ein normales Speichern im Studio)
            wp_update_post(wp_slash(['ID' => $event_id] + $post));
        } finally {
            self::$restoring = 0;
        }
        $new = self::record($event_id);
        unset(self::$dirty[$event_id]);
        return ['ok' => true, 'rev' => $new ?: null, 'areas' => $areas, 'protected' => $plan['protected']];
    }

    /**
     * Was beim Wiederherstellen geschrieben wird (rein): {set: [Feld => Wert], delete: [Feld], protected: [{field, reason}]}.
     * $sold_by_cat: [Kategorie-Index => verkauft], $has_sales: Event hat verkaufte Tickets.
     */
    public static function restore_plan(array $current, array $snap, array $areas, array $sold_by_cat, $has_sales) {
        $set = []; $del = []; $prot = [];
        foreach ($areas as $area) {
            foreach (self::AREAS[$area] as $k) {
                if (in_array($k, ['_tix_lineup_acts', '_tix_source_artists'], true)) continue; // nur auf Kopien, kommen von der Quelle
                $has_s = isset($snap[$area]) && array_key_exists($k, $snap[$area]);
                $has_c = isset($current[$area]) && array_key_exists($k, $current[$area]);
                $v = $has_s ? $snap[$area][$k] : null;
                if ($has_s && $has_c && self::hash([$v]) === self::hash([$current[$area][$k]])) continue;
                if (!$has_s && !$has_c) continue;
                if ($k === 'post_status' && $v === 'trash') continue;
                if ($area === 'termin' && $has_sales && in_array($k, ['_tix_date_start', '_tix_date_end'], true) && $has_s && $v !== '' && strtotime((string) $v . ' 23:59:59') < time()) {
                    $prot[] = ['field' => $k, 'reason' => 'Termin liegt in der Vergangenheit und es gibt verkaufte Tickets.']; continue;
                }
                if ($k === '_tix_ticket_categories' && $sold_by_cat) {
                    $cur = (array) ($current[$area][$k] ?? []);
                    foreach ($sold_by_cat as $i => $n) {
                        if ($n <= 0) continue;
                        $nm = isset($cur[$i]['name']) ? (string) $cur[$i]['name'] : 'Kategorie ' . ($i + 1);
                        $in_snap = isset($v[$i]) && mb_strtolower((string) ($v[$i]['name'] ?? '')) === mb_strtolower($nm);
                        if (!$in_snap) $prot[] = ['field' => $k, 'reason' => 'Kategorie „' . $nm . '“ hat ' . intval($n) . ' verkaufte Tickets und bleibt erhalten.'];
                    }
                }
                if ($has_s) $set[$k] = $v; else $del[] = $k;
            }
        }
        return ['set' => $set, 'delete' => $del, 'protected' => $prot];
    }

    /** Kategorien aus dem Snapshot, mit Schutz: verkaufte Kategorien bleiben an ihrer Stelle, Kontingent ≥ verkauft, Bestand neu. */
    private static function restore_categories($event_id, array $snap_cats) {
        $cur = get_post_meta($event_id, '_tix_ticket_categories', true);
        $cur = is_array($cur) ? $cur : [];
        $sold = self::sold_by_cat($event_id);
        $out = $snap_cats;
        foreach ($sold as $i => $n) {
            if ($n <= 0 || !isset($cur[$i])) continue;
            $same = isset($out[$i]) && mb_strtolower((string) ($out[$i]['name'] ?? '')) === mb_strtolower((string) ($cur[$i]['name'] ?? ''));
            if (!$same) {
                // verkaufte Kategorie an ihre Stelle zurück; was dort im Snapshot stand, rückt ans Ende
                if (isset($out[$i])) $out[] = $out[$i];
                $out[$i] = array_diff_key($cur[$i], array_flip(['stock']));
            }
        }
        ksort($out);
        $out = array_values($out);
        foreach ($out as $i => &$c) {
            if (!is_array($c)) continue;
            foreach (['product_id', 'sku'] as $f) if (isset($cur[$i][$f]) && !isset($c[$f])) $c[$f] = $cur[$i][$f];
            $n = intval($sold[$i] ?? 0);
            $qty = isset($c['qty']) && $c['qty'] !== '' ? intval($c['qty']) : 0;
            if ($qty > 0 && $qty < $n) $c['qty'] = $qty = $n;
            if ($qty > 0) $c['stock'] = max(0, $qty - $n); else unset($c['stock']);
        }
        unset($c);
        return $out;
    }

    /** Verkaufte Tickets je Kategorie-Index, frisch gezählt (wie TIX_REST_API::ticket_counts, aber ohne dessen Zwischenspeicher). */
    private static function sold_by_cat($event_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT st.meta_value AS status, ci.meta_value AS cat_index FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} ev ON ev.post_id = p.ID AND ev.meta_key = '_tix_ticket_event_id' AND ev.meta_value = %s
             LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_tix_ticket_status'
             LEFT JOIN {$wpdb->postmeta} ci ON ci.post_id = p.ID AND ci.meta_key = '_tix_ticket_cat_index'
             WHERE p.post_type = 'tix_ticket' AND p.post_status IN ('publish', 'private')", (string) intval($event_id)), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) {
            if ((string) $r['status'] === 'cancelled' || $r['cat_index'] === null || $r['cat_index'] === '') continue;
            $out[intval($r['cat_index'])] = ($out[intval($r['cat_index'])] ?? 0) + 1;
        }
        return $out;
    }

    private static function has_sales($event_id) {
        return array_sum(self::sold_by_cat($event_id)) > 0;
    }

    // ═══════════════ Aufbewahrung, Datenschutz ═══════════════

    public static function cleanup() {
        global $wpdb;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) return;
        $t = self::table();
        $keep = max(30, intval(get_option('tix_history_keep_days', 1095)));
        $snap_days = max(30, intval(get_option('tix_history_snapshot_days', 730)));
        $snap_max = max(20, intval(get_option('tix_history_snapshot_max', 200)));
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE created_at < %s", gmdate('Y-m-d H:i:s', time() - $keep * DAY_IN_SECONDS)));
        $wpdb->query($wpdb->prepare("UPDATE $t SET snapshot = NULL WHERE snapshot IS NOT NULL AND created_at < %s", gmdate('Y-m-d H:i:s', time() - $snap_days * DAY_IN_SECONDS)));
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT event_id FROM $t WHERE snapshot IS NOT NULL GROUP BY event_id HAVING COUNT(*) > %d", $snap_max)) as $eid) {
            $cut = intval($wpdb->get_var($wpdb->prepare("SELECT rev FROM $t WHERE event_id = %d AND snapshot IS NOT NULL ORDER BY rev DESC LIMIT 1 OFFSET %d", $eid, $snap_max)));
            // der neueste Stand bleibt immer (Vergleichsbasis)
            if ($cut) $wpdb->query($wpdb->prepare("UPDATE $t SET snapshot = NULL WHERE event_id = %d AND rev <= %d", $eid, $cut));
        }
    }

    public static function anonymize_user($user_id) {
        global $wpdb;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) return;
        $wpdb->update(self::table(), ['actor_name' => 'Gelöschtes Konto', 'actor_email' => '', 'actor_user_id' => 0], ['actor_user_id' => intval($user_id)]);
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        $perm = [__CLASS__, 'perm'];
        register_rest_route(self::NS, '/events/(?P<id>\d+)/history', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_list'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/history/(?P<rev>\d+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_detail'], 'permission_callback' => $perm]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/history/(?P<rev>\d+)/restore', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_restore'], 'permission_callback' => $perm]);
    }

    public static function perm(WP_REST_Request $req) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('tix_not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    /**
     * E-Mail der handelnden Person zeigen (User 12.11.): Admins und Inhaber/Team des Veranstalters – also jeder, der den Verlauf
     * sehen darf (perm: Event-Zugriff). Nicht über den Co-Durchgriff (technisches Konto): Co-Veranstalter sind nicht das Team.
     */
    private static function with_email() {
        $uid = get_current_user_id();
        return $uid > 0 && get_user_meta($uid, '_tix_technical', true) !== '1' && !(class_exists('TIX_Co_Remote') && get_user_meta($uid, TIX_Co_Remote::USER_META, true));
    }

    public static function rest_list(WP_REST_Request $req) {
        global $wpdb;
        $id = absint($req['id']);
        self::ready();
        $per = max(1, min(100, intval($req->get_param('per_page') ?: 30)));
        $page = max(1, intval($req->get_param('page') ?: 1));
        $t = self::table();
        $total = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id = %d", $id)));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, event_id, rev, created_at, actor_user_id, actor_name, actor_email, site, site_name, channel, origin, areas, restored_from, (snapshot IS NOT NULL AND snapshot <> '') AS has_snap FROM $t WHERE event_id = %d ORDER BY rev DESC LIMIT %d OFFSET %d", $id, $per, ($page - 1) * $per), ARRAY_A);
        $copy = self::is_copy($id);
        $em = self::with_email();
        $items = array_map(function ($r) use ($copy, $em) { $r['snapshot'] = $r['has_snap'] ? '1' : ''; $h = self::head($r, $em); if ($copy) $h['restorable'] = false; return $h; }, (array) $rows);
        return rest_ensure_response(['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per,
            'source' => ['type' => 'local', 'copy' => $copy, 'site' => $copy ? self::source_site($id) : self::host()]]);
    }

    public static function rest_detail(WP_REST_Request $req) {
        $id = absint($req['id']);
        $r = self::row($id, absint($req['rev']));
        if (!$r) return new WP_Error('tix_not_found', 'Diese Version gibt es nicht.', ['status' => 404]);
        $h = self::head($r, self::with_email());
        if (self::is_copy($id)) $h['restorable'] = false;
        $diff = json_decode((string) $r['diff'], true) ?: [];
        foreach ($diff as &$d) $d['area_label'] = self::AREA_LABELS[$d['area']] ?? $d['area'];
        unset($d);
        return rest_ensure_response($h + ['diff' => $diff, 'area_labels' => self::AREA_LABELS]);
    }

    public static function rest_restore(WP_REST_Request $req) {
        $b = $req->get_json_params();
        $areas = is_array($b) && is_array($b['areas'] ?? null) ? array_map('strval', $b['areas']) : [];
        $r = self::restore(absint($req['id']), absint($req['rev']), $areas);
        return is_wp_error($r) ? $r : rest_ensure_response($r);
    }
}
