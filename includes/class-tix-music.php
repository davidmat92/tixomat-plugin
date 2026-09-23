<?php
/**
 * Musikwünsche: Song-Autocomplete (Deezer), Wünsche der Gäste nur während
 * laufender Events, DJ-Ansicht per geheimem Link und in der Veranstalter-App.
 *
 * Song-Datenbank: eigene Tabelle, die sich selbst füllt – täglicher Import der
 * Deezer-Charts/-Playlists (Cron), Live-Suche bei Deezer für alles, was lokal
 * fehlt (Ergebnisse werden gespeichert), plus jeder Wunsch zählt hoch.
 * Es werden nur Metadaten gespeichert (Titel, Artist, Cover-URL, Deezer-ID).
 *
 * REST (tixomat/v1):
 *   GET  /music/status                       öffentlich: aktiv?, läuft gerade ein Event?, nächstes Event
 *   GET  /music/search?q=                    öffentlich: Songs (lokal + Deezer)
 *   POST /music/requests                     öffentlich (Token optional): Wunsch senden
 *   GET  /music/requests/mine?ids=&device=   öffentlich: Status der eigenen Wünsche
 *   GET  /music/dj/requests?event_id=        DJ-Key (?key=) oder Veranstalter-Login
 *   POST /music/dj/requests/{id}/status      DJ-Key oder Veranstalter-Login
 *
 * Shortcode [tix_musikwunsch_dj] (Seite wird automatisch angelegt, Aufruf nur
 * mit ?key=…): Live-Liste in Reihenfolge mit Uhrzeit, Doppelwünsche gebündelt,
 * Buttons „Gespielt“/„Ablehnen“, Auto-Refresh.
 */
if (!defined('ABSPATH')) exit;

class TIX_Music {

    const NS          = 'tixomat/v1';
    const OPT         = 'tix_music';
    const OPT_DB      = 'tix_music_db';
    const DB_VERSION  = 1;
    const CRON        = 'tix_music_import';
    const T_SONGS     = 'tix_songs';
    const T_REQ       = 'tix_song_requests';
    const DEEZER      = 'https://api.deezer.com';
    const STATUSES    = ['open' => 'Offen', 'played' => 'Gespielt', 'rejected' => 'Abgelehnt'];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 20);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_shortcode('tix_musikwunsch_dj', [__CLASS__, 'shortcode_dj']);
        add_action(self::CRON, [__CLASS__, 'import_charts']);
        add_action('template_redirect', [__CLASS__, 'maybe_render_dj_page']);
        add_filter('wp_robots', [__CLASS__, 'robots_noindex']);
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('admin_post_tix_music_save', [__CLASS__, 'admin_save']);
        add_action('admin_post_tix_music_import', [__CLASS__, 'admin_import']);
        add_action('admin_post_tix_music_newkey', [__CLASS__, 'admin_newkey']);
    }

    // ──────────────────────────────────────────
    //  Einstellungen / Installation
    // ──────────────────────────────────────────

    public static function defaults() {
        return [
            'enabled'          => 1,
            'dj_key'           => '',
            'dj_page_id'       => 0,
            'before_min'       => 60,   // Wünsche ab X Minuten vor Beginn
            'after_hours'      => 7,    // ohne Endzeit: bis X Stunden nach Beginn
            'grace_min'        => 60,   // nach dem Ende noch X Minuten
            'default_start'    => '22:00',
            'max_per_night'    => 5,
            'min_interval_min' => 3,
            'deezer_genres'    => '0,113,106,116,132,459,165',
            'deezer_playlists' => '1111143121,65490170',
            'last_import'      => '',
            'last_import_info' => '',
        ];
    }

    public static function settings() {
        $s = get_option(self::OPT, []);
        return wp_parse_args(is_array($s) ? $s : [], self::defaults());
    }

    private static function update_settings(array $patch) {
        $s = self::settings();
        update_option(self::OPT, array_merge($s, $patch), false);
    }

    public static function enabled() {
        return !empty(self::settings()['enabled']);
    }

    /** Geheimer DJ-Schlüssel (wird beim ersten Bedarf erzeugt). */
    public static function dj_key() {
        $s = self::settings();
        if (empty($s['dj_key'])) {
            $key = strtolower(wp_generate_password(24, false, false));
            self::update_settings(['dj_key' => $key]);
            return $key;
        }
        return (string) $s['dj_key'];
    }

    public static function maybe_install() {
        if (intval(get_option(self::OPT_DB, 0)) < self::DB_VERSION) {
            self::create_tables();
            update_option(self::OPT_DB, self::DB_VERSION, false);
        }
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'daily', self::CRON);
        }
        self::ensure_dj_page();
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $songs   = $wpdb->prefix . self::T_SONGS;
        $req     = $wpdb->prefix . self::T_REQ;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $songs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            deezer_id BIGINT UNSIGNED DEFAULT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            artist VARCHAR(255) NOT NULL DEFAULT '',
            search VARCHAR(512) NOT NULL DEFAULT '',
            cover VARCHAR(255) NOT NULL DEFAULT '',
            duration INT UNSIGNED NOT NULL DEFAULT 0,
            rank BIGINT UNSIGNED NOT NULL DEFAULT 0,
            explicit TINYINT UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(20) NOT NULL DEFAULT 'deezer',
            requests INT UNSIGNED NOT NULL DEFAULT 0,
            charted_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY deezer_id (deezer_id),
            KEY search (search(191)),
            KEY rank (rank),
            KEY requests (requests)
        ) $charset;");
        dbDelta("CREATE TABLE $req (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            song_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            deezer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            song_key VARCHAR(191) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            artist VARCHAR(255) NOT NULL DEFAULT '',
            cover VARCHAR(255) NOT NULL DEFAULT '',
            guest_name VARCHAR(80) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            message VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'open',
            device VARCHAR(64) NOT NULL DEFAULT '',
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            played_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY event_status (event_id, status),
            KEY event_key (event_id, song_key),
            KEY device (device),
            KEY created_at (created_at)
        ) $charset;");
    }

    /** Seite mit dem DJ-Shortcode anlegen (einmalig), Zugriff nur mit ?key=. */
    private static function ensure_dj_page() {
        $s = self::settings();
        $id = intval($s['dj_page_id']);
        if ($id && get_post_status($id)) return;
        $existing = get_page_by_path('dj-musikwuensche');
        if ($existing) {
            self::update_settings(['dj_page_id' => $existing->ID]);
            return;
        }
        $id = wp_insert_post([
            'post_title'   => 'DJ – Musikwünsche',
            'post_name'    => 'dj-musikwuensche',
            'post_content' => '[tix_musikwunsch_dj]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'comment_status' => 'closed',
        ]);
        if ($id && !is_wp_error($id)) self::update_settings(['dj_page_id' => intval($id)]);
    }

    public static function dj_url() {
        $s = self::settings();
        $id = intval($s['dj_page_id']);
        $base = $id ? get_permalink($id) : home_url('/dj-musikwuensche/');
        return add_query_arg('key', self::dj_key(), $base);
    }

    public static function robots_noindex($robots) {
        $s = self::settings();
        if (!empty($s['dj_page_id']) && is_page(intval($s['dj_page_id']))) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    // ──────────────────────────────────────────
    //  Hilfen
    // ──────────────────────────────────────────

    private static function error($code, $message, $status = 400, array $extra = []) {
        return new WP_Error($code, $message, ['status' => $status] + $extra);
    }

    private static function now() {
        return intval(current_time('timestamp'));
    }

    private static function mysql_now() {
        return current_time('mysql');
    }

    /** Suchbegriff/Song-Schlüssel normalisieren (klein, ohne Akzente/Satzzeichen). */
    public static function normalize($s) {
        $s = remove_accents(mb_strtolower(trim((string) $s)));
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }

    private static function device_hash($device) {
        $device = trim((string) $device);
        if (strlen($device) < 8) return '';
        return substr(hash('sha256', $device . '|' . wp_salt('nonce')), 0, 40);
    }

    private static function ip_hash() {
        $ip = class_exists('TIX_App_Account') ? TIX_App_Account::client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return substr(hash('sha256', $ip . '|' . wp_salt('nonce')), 0, 40);
    }

    private static function rate_limited($bucket, $max, $window, $key = '') {
        if (class_exists('TIX_App_Account') && method_exists('TIX_App_Account', 'rate_limited')) {
            return TIX_App_Account::rate_limited('music_' . $bucket, $max, $window, $key);
        }
        return false;
    }

    private static function song_key($deezer_id, $title, $artist) {
        if (intval($deezer_id) > 0) return 'dz:' . intval($deezer_id);
        return 'txt:' . substr(self::normalize($title . ' ' . $artist), 0, 180);
    }

    // ──────────────────────────────────────────
    //  Event-Fenster („nur während laufender Events“)
    // ──────────────────────────────────────────

    /** Zeitfenster eines Events: Beginn, Ende, Öffnung/Schluss für Wünsche (lokale Timestamps). */
    public static function event_window($event_id) {
        $s = self::settings();
        $date  = (string) get_post_meta($event_id, '_tix_date_start', true);
        if ($date === '') return null;
        $time  = (string) get_post_meta($event_id, '_tix_time_start', true);
        $doors = (string) get_post_meta($event_id, '_tix_time_doors', true);
        if ($time === '') $time = $doors !== '' ? $doors : (string) $s['default_start'];
        $start = strtotime($date . ' ' . $time);
        if (!$start) return null;
        $date_end = (string) get_post_meta($event_id, '_tix_date_end', true);
        $time_end = (string) get_post_meta($event_id, '_tix_time_end', true);
        $end = 0;
        if ($time_end !== '') {
            $end = strtotime(($date_end !== '' ? $date_end : $date) . ' ' . $time_end);
            // Endzeit nach Mitternacht ohne eigenes Enddatum
            if ($end && $end <= $start) $end += DAY_IN_SECONDS;
        }
        if (!$end) $end = $start + intval($s['after_hours']) * HOUR_IN_SECONDS;
        return [
            'start'  => $start,
            'end'    => $end,
            'opens'  => $start - intval($s['before_min']) * MINUTE_IN_SECONDS,
            'closes' => $end + intval($s['grace_min']) * MINUTE_IN_SECONDS,
        ];
    }

    private static function event_payload($event_id, array $w) {
        $tz = wp_timezone();
        $fmt = function ($ts) use ($tz) {
            return (new DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i');
        };
        // Timestamps sind „lokale“ Werte (current_time-Konvention) → als Klartext ausgeben
        $plain = function ($ts) { return gmdate('Y-m-d H:i', $ts); };
        return [
            'id'        => intval($event_id),
            'title'     => get_the_title($event_id),
            'date'      => (string) get_post_meta($event_id, '_tix_date_start', true),
            'location'  => (string) get_post_meta($event_id, '_tix_location', true),
            'image'     => get_the_post_thumbnail_url($event_id, 'medium') ?: '',
            'starts_at' => $plain($w['start']),
            'ends_at'   => $plain($w['end']),
            'opens_at'  => $plain($w['opens']),
            'closes_at' => $plain($w['closes']),
        ];
    }

    /** Läuft gerade ein Event (inkl. Vorlauf/Nachlauf)? */
    public static function current_event() {
        $now = self::now();
        $from = gmdate('Y-m-d', $now - 2 * DAY_IN_SECONDS);
        $to   = gmdate('Y-m-d', $now + DAY_IN_SECONDS);
        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [[
                'key' => '_tix_date_start', 'value' => [$from, $to], 'compare' => 'BETWEEN', 'type' => 'DATE',
            ]],
        ]);
        $best = null;
        foreach ($ids as $id) {
            if (get_post_meta($id, '_tix_status', true) === 'cancelled') continue;
            $w = self::event_window($id);
            if (!$w) continue;
            if ($now >= $w['opens'] && $now <= $w['closes']) {
                // Bei Überschneidung: das Event, das zuletzt begonnen hat
                if (!$best || $w['start'] > $best['window']['start']) {
                    $best = ['id' => $id, 'window' => $w];
                }
            }
        }
        return $best;
    }

    /** Nächstes kommendes Event (für den Hinweis „Wünsche ab …“). */
    public static function next_event() {
        $now = self::now();
        $ids = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'fields'         => 'ids',
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [[
                'key' => '_tix_date_start', 'value' => gmdate('Y-m-d', $now - DAY_IN_SECONDS), 'compare' => '>=', 'type' => 'DATE',
            ]],
        ]);
        foreach ($ids as $id) {
            if (get_post_meta($id, '_tix_status', true) === 'cancelled') continue;
            $w = self::event_window($id);
            if ($w && $w['closes'] > $now) return ['id' => $id, 'window' => $w];
        }
        return null;
    }

    // ──────────────────────────────────────────
    //  Deezer
    // ──────────────────────────────────────────

    private static function deezer($path, array $args = []) {
        $url = self::DEEZER . $path . ($args ? '?' . http_build_query($args) : '');
        // Deezer beantwortet den WordPress-User-Agent mit 403 → neutraler UA
        $res = wp_remote_get($url, [
            'timeout'    => 8,
            'user-agent' => 'Mozilla/5.0 (compatible; TixomatMusic/1.0; +' . home_url('/') . ')',
            'headers'    => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || isset($data['error'])) return null;
        return $data;
    }

    /** Deezer-Track → Song-Zeile (nur Metadaten). */
    private static function song_from_track(array $t) {
        $title  = (string) ($t['title'] ?? '');
        $artist = (string) ($t['artist']['name'] ?? '');
        if ($title === '' || empty($t['id'])) return null;
        return [
            'deezer_id' => intval($t['id']),
            'title'     => mb_substr($title, 0, 255),
            'artist'    => mb_substr($artist, 0, 255),
            'search'    => mb_substr(self::normalize($title . ' ' . $artist), 0, 512),
            'cover'     => (string) ($t['album']['cover_medium'] ?? ($t['album']['cover_small'] ?? '')),
            'duration'  => intval($t['duration'] ?? 0),
            'rank'      => intval($t['rank'] ?? 0),
            'explicit'  => !empty($t['explicit_lyrics']) ? 1 : 0,
        ];
    }

    /** Song speichern/aktualisieren (Schlüssel: Deezer-ID). Liefert die lokale ID. */
    private static function upsert_song(array $s, $source = 'deezer', $charted = false) {
        global $wpdb;
        $t   = $wpdb->prefix . self::T_SONGS;
        $now = self::mysql_now();
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $t (deezer_id, title, artist, search, cover, duration, rank, explicit, source, charted_at, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s, %d, %d, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE title = VALUES(title), artist = VALUES(artist), search = VALUES(search),
               cover = VALUES(cover), duration = VALUES(duration), rank = GREATEST(rank, VALUES(rank)),
               explicit = VALUES(explicit), updated_at = VALUES(updated_at)" . ($charted ? ", charted_at = VALUES(charted_at)" : ''),
            $s['deezer_id'], $s['title'], $s['artist'], $s['search'], $s['cover'], $s['duration'], $s['rank'], $s['explicit'],
            $source, $charted ? $now : null, $now, $now
        ));
        return intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE deezer_id = %d", $s['deezer_id'])));
    }

    /** Täglicher Import: Deezer-Charts je Genre + Playlists (z. B. „Top Germany“). */
    public static function import_charts() {
        $s = self::settings();
        $count = 0;
        $calls = 0;
        foreach (array_filter(array_map('intval', explode(',', (string) $s['deezer_genres'])), 'is_int') as $genre) {
            $data = self::deezer('/chart/' . $genre . '/tracks', ['limit' => 100]);
            $calls++;
            foreach ((array) ($data['data'] ?? []) as $track) {
                $song = is_array($track) ? self::song_from_track($track) : null;
                if ($song) { self::upsert_song($song, 'chart', true); $count++; }
            }
            usleep(250000);
        }
        foreach (array_filter(array_map('intval', explode(',', (string) $s['deezer_playlists']))) as $pl) {
            $data = self::deezer('/playlist/' . $pl . '/tracks', ['limit' => 200]);
            $calls++;
            foreach ((array) ($data['data'] ?? []) as $track) {
                $song = is_array($track) ? self::song_from_track($track) : null;
                if ($song) { self::upsert_song($song, 'chart', true); $count++; }
            }
            usleep(250000);
        }
        self::update_settings([
            'last_import'      => self::mysql_now(),
            'last_import_info' => sprintf('%d Titel aus %d Abfragen', $count, $calls),
        ]);
        return $count;
    }

    private static function row_to_song(array $r) {
        return [
            'id'        => intval($r['id']),
            'deezer_id' => intval($r['deezer_id']),
            'title'     => (string) $r['title'],
            'artist'    => (string) $r['artist'],
            'cover'     => (string) $r['cover'],
            'duration'  => intval($r['duration']),
            'explicit'  => !empty($r['explicit']),
            'requests'  => intval($r['requests']),
        ];
    }

    /** Suche: lokal (Wunsch-Häufigkeit, Beliebtheit) + Deezer live, zusammengeführt. */
    public static function search($q, $limit = 20) {
        global $wpdb;
        $t    = $wpdb->prefix . self::T_SONGS;
        $norm = self::normalize($q);
        if (mb_strlen($norm) < 2) return [];
        $words = array_slice(array_filter(explode(' ', $norm)), 0, 5);
        $where = [];
        $params = [];
        foreach ($words as $w) {
            $where[]  = 'search LIKE %s';
            $params[] = '%' . $wpdb->esc_like($w) . '%';
        }
        $local = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE " . implode(' AND ', $where) . " ORDER BY requests DESC, rank DESC LIMIT 15",
            ...$params
        ), ARRAY_A);

        $remote = [];
        $cache_key = 'tix_music_q_' . md5($norm);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            $remote = $cached;
        } else {
            $data = self::deezer('/search', ['q' => $norm, 'limit' => 25, 'order' => 'RANKING']);
            if (is_array($data)) {
                foreach ((array) ($data['data'] ?? []) as $track) {
                    $song = is_array($track) ? self::song_from_track($track) : null;
                    if (!$song) continue;
                    $song['id'] = self::upsert_song($song, 'deezer');
                    $remote[] = $song;
                }
                set_transient($cache_key, $remote, 15 * MINUTE_IN_SECONDS);
            }
        }

        $out  = [];
        $seen = [];
        // Erst, was im Club schon gewünscht wurde
        foreach ((array) $local as $r) {
            if (intval($r['requests']) <= 0) continue;
            $k = intval($r['deezer_id']) ?: 'l' . $r['id'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = self::row_to_song($r);
        }
        foreach ($remote as $s) {
            $k = intval($s['deezer_id']);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = [
                'id' => intval($s['id'] ?? 0), 'deezer_id' => $k, 'title' => $s['title'], 'artist' => $s['artist'],
                'cover' => $s['cover'], 'duration' => $s['duration'], 'explicit' => (bool) $s['explicit'], 'requests' => 0,
            ];
        }
        foreach ((array) $local as $r) {
            $k = intval($r['deezer_id']) ?: 'l' . $r['id'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = self::row_to_song($r);
        }
        return array_slice($out, 0, $limit);
    }

    // ──────────────────────────────────────────
    //  REST
    // ──────────────────────────────────────────

    public static function register_routes() {
        register_rest_route(self::NS, '/music/status', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_status'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/music/search', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_search'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/music/requests', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_request'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/music/requests/mine', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_mine'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/music/dj/requests', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_dj_list'], 'permission_callback' => [__CLASS__, 'check_dj'],
        ]);
        register_rest_route(self::NS, '/music/dj/requests/(?P<id>\d+)/status', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_dj_status'], 'permission_callback' => [__CLASS__, 'check_dj'],
        ]);
    }

    /** DJ-Zugang: geheimer Schlüssel (?key= oder Header X-Tix-DJ-Key) oder Veranstalter-Login. */
    public static function check_dj(WP_REST_Request $req) {
        $key = (string) ($req->get_param('key') ?? $req->get_header('x-tix-dj-key') ?? '');
        if ($key !== '' && hash_equals(self::dj_key(), $key)) return true;
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'check_organizer')) {
            $r = TIX_REST_API::check_organizer($req);
            if ($r === true) return true;
        }
        return new WP_Error('rest_forbidden', 'DJ-Link oder Veranstalter-Login erforderlich.', ['status' => 401]);
    }

    private static function status_payload() {
        $s   = self::settings();
        $cur = self::current_event();
        $nxt = $cur ? null : self::next_event();
        return [
            'ok'      => true,
            'enabled' => self::enabled(),
            'open'    => self::enabled() && (bool) $cur,
            'event'   => $cur ? self::event_payload($cur['id'], $cur['window']) : null,
            'next'    => $nxt ? self::event_payload($nxt['id'], $nxt['window']) : null,
            'now'     => gmdate('Y-m-d H:i', self::now()),
            'limits'  => [
                'max_per_night'    => intval($s['max_per_night']),
                'min_interval_min' => intval($s['min_interval_min']),
                'before_min'       => intval($s['before_min']),
            ],
        ];
    }

    /** GET /music/status */
    public static function rest_status(WP_REST_Request $req) {
        return rest_ensure_response(self::status_payload());
    }

    /** GET /music/search?q= */
    public static function rest_search(WP_REST_Request $req) {
        if (!self::enabled()) return self::error('tix_music_off', 'Musikwünsche sind aktuell nicht aktiv.', 404);
        if (self::rate_limited('search', 90, 60)) {
            return self::error('tix_rate_limit', 'Zu viele Suchanfragen. Bitte kurz warten.', 429);
        }
        $q = sanitize_text_field((string) $req->get_param('q'));
        return rest_ensure_response(['ok' => true, 'q' => $q, 'songs' => self::search($q)]);
    }

    private static function request_payload(array $r) {
        return [
            'id'         => intval($r['id']),
            'event_id'   => intval($r['event_id']),
            'deezer_id'  => intval($r['deezer_id']),
            'title'      => (string) $r['title'],
            'artist'     => (string) $r['artist'],
            'cover'      => (string) $r['cover'],
            'guest_name' => (string) $r['guest_name'],
            'message'    => (string) $r['message'],
            'status'     => (string) $r['status'],
            'status_label' => self::STATUSES[$r['status']] ?? $r['status'],
            'created_at' => (string) $r['created_at'],
            'played_at'  => (string) ($r['played_at'] ?? ''),
        ];
    }

    /** POST /music/requests */
    public static function rest_request(WP_REST_Request $req) {
        global $wpdb;
        if (!self::enabled()) return self::error('tix_music_off', 'Musikwünsche sind aktuell nicht aktiv.', 404);
        if (self::rate_limited('request', 30, 3600)) {
            return self::error('tix_rate_limit', 'Zu viele Wünsche von diesem Gerät. Bitte später erneut.', 429);
        }
        $s   = self::settings();
        $cur = self::current_event();
        if (!$cur) {
            $nxt = self::next_event();
            return self::error('tix_music_closed', 'Musikwünsche gibt es nur während einer laufenden Party.', 409, [
                'next' => $nxt ? self::event_payload($nxt['id'], $nxt['window']) : null,
            ]);
        }
        $device = self::device_hash((string) $req->get_param('device'));
        if ($device === '') return self::error('tix_device', 'Geräte-Kennung fehlt.', 400);
        $user   = is_user_logged_in() ? wp_get_current_user() : null;
        $name   = $user
            ? (trim($user->first_name . ' ' . mb_substr((string) $user->last_name, 0, 1)) ?: (string) $user->display_name)
            : mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 40);
        $message = mb_substr(sanitize_text_field((string) $req->get_param('message')), 0, 200);

        // Song bestimmen: Deezer-ID (aus der Suche) oder Freitext
        $deezer_id = intval($req->get_param('deezer_id'));
        $title  = mb_substr(sanitize_text_field((string) $req->get_param('title')), 0, 120);
        $artist = mb_substr(sanitize_text_field((string) $req->get_param('artist')), 0, 120);
        $cover  = '';
        $song_id = 0;
        $t = $wpdb->prefix . self::T_SONGS;
        if ($deezer_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE deezer_id = %d", $deezer_id), ARRAY_A);
            if (!$row) {
                $track = self::deezer('/track/' . $deezer_id);
                $song  = is_array($track) ? self::song_from_track($track) : null;
                if ($song) {
                    self::upsert_song($song, 'request');
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE deezer_id = %d", $deezer_id), ARRAY_A);
                }
            }
            if ($row) {
                $song_id = intval($row['id']);
                $title   = (string) $row['title'];
                $artist  = (string) $row['artist'];
                $cover   = (string) $row['cover'];
            } else {
                $deezer_id = 0;
            }
        }
        if ($title === '') return self::error('tix_music_title', 'Bitte einen Songtitel angeben.', 400, ['field' => 'title']);

        $event_id = intval($cur['id']);
        $key      = self::song_key($deezer_id, $title, $artist);
        $rt       = $wpdb->prefix . self::T_REQ;
        $mine_where = $user
            ? $wpdb->prepare('(device = %s OR user_id = %d)', $device, $user->ID)
            : $wpdb->prepare('device = %s', $device);
        $dup = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $rt WHERE event_id = %d AND song_key = %s AND $mine_where", $event_id, $key
        ));
        if (intval($dup) > 0) return self::error('tix_music_duplicate', 'Diesen Song hast du heute schon gewünscht.', 409);
        $mine = $wpdb->get_results($wpdb->prepare(
            "SELECT created_at FROM $rt WHERE event_id = %d AND $mine_where ORDER BY id DESC", $event_id
        ), ARRAY_A);
        if (count($mine) >= intval($s['max_per_night'])) {
            return self::error('tix_music_limit', sprintf('Maximal %d Wünsche pro Party – du hast dein Kontingent aufgebraucht.', intval($s['max_per_night'])), 429);
        }
        if ($mine && intval($s['min_interval_min']) > 0) {
            $last = strtotime((string) $mine[0]['created_at']);
            $wait = $last + intval($s['min_interval_min']) * MINUTE_IN_SECONDS - self::now();
            if ($wait > 0) {
                return self::error('tix_music_interval', sprintf('Bitte noch %d Minute(n) warten, dann geht der nächste Wunsch.', max(1, ceil($wait / 60))), 429);
            }
        }
        $now = self::mysql_now();
        $wpdb->insert($rt, [
            'event_id'   => $event_id,
            'song_id'    => $song_id,
            'deezer_id'  => $deezer_id,
            'song_key'   => $key,
            'title'      => $title,
            'artist'     => $artist,
            'cover'      => $cover,
            'guest_name' => $name,
            'user_id'    => $user ? intval($user->ID) : 0,
            'message'    => $message,
            'status'     => 'open',
            'device'     => $device,
            'ip_hash'    => self::ip_hash(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = intval($wpdb->insert_id);
        if (!$id) return self::error('tix_music_failed', 'Wunsch konnte nicht gespeichert werden.', 500);
        if ($song_id) $wpdb->query($wpdb->prepare("UPDATE $t SET requests = requests + 1 WHERE id = %d", $song_id));
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $rt WHERE id = %d", $id), ARRAY_A);
        return rest_ensure_response([
            'ok'      => true,
            'request' => self::request_payload($row),
            'event'   => self::event_payload($event_id, $cur['window']),
            'remaining' => max(0, intval($s['max_per_night']) - count($mine) - 1),
        ]);
    }

    /** GET /music/requests/mine?ids=1,2&device= – Status der eigenen Wünsche (nur gleiches Gerät/Konto). */
    public static function rest_mine(WP_REST_Request $req) {
        global $wpdb;
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $req->get_param('ids')))), 0, 30);
        $device = self::device_hash((string) $req->get_param('device'));
        if (!$ids) return rest_ensure_response(['ok' => true, 'requests' => []]);
        $rt   = $wpdb->prefix . self::T_REQ;
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        $own  = $user
            ? $wpdb->prepare('(device = %s OR user_id = %d)', $device, $user->ID)
            : $wpdb->prepare('device = %s', $device);
        $rows = $wpdb->get_results(
            "SELECT * FROM $rt WHERE id IN (" . implode(',', $ids) . ") AND $own ORDER BY id DESC", ARRAY_A
        );
        return rest_ensure_response(['ok' => true, 'requests' => array_map([__CLASS__, 'request_payload'], (array) $rows)]);
    }

    /** Events für die DJ-Auswahl: gestern bis in 7 Tagen. */
    private static function dj_event_candidates() {
        $now = self::now();
        $ids = get_posts([
            'post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 12, 'fields' => 'ids',
            'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [[
                'key' => '_tix_date_start', 'compare' => 'BETWEEN', 'type' => 'DATE',
                'value' => [gmdate('Y-m-d', $now - 2 * DAY_IN_SECONDS), gmdate('Y-m-d', $now + 7 * DAY_IN_SECONDS)],
            ]],
        ]);
        $out = [];
        foreach ($ids as $id) {
            $w = self::event_window($id);
            if ($w) $out[] = self::event_payload($id, $w);
        }
        return $out;
    }

    /** Wünsche eines Events gebündelt (gleicher Song = eine Zeile mit Zähler), in Reihenfolge des ersten Wunsches. */
    public static function grouped_requests($event_id) {
        global $wpdb;
        $rt   = $wpdb->prefix . self::T_REQ;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $rt WHERE event_id = %d ORDER BY id ASC", intval($event_id)), ARRAY_A);
        $groups = [];
        foreach ((array) $rows as $r) {
            $k = (string) $r['song_key'];
            if (!isset($groups[$k])) {
                $groups[$k] = [
                    'id'         => intval($r['id']),
                    'event_id'   => intval($r['event_id']),
                    'deezer_id'  => intval($r['deezer_id']),
                    'title'      => (string) $r['title'],
                    'artist'     => (string) $r['artist'],
                    'cover'      => (string) $r['cover'],
                    'count'      => 0,
                    'names'      => [],
                    'messages'   => [],
                    'status'     => 'open',
                    'first_at'   => (string) $r['created_at'],
                    'last_at'    => (string) $r['created_at'],
                    'played_at'  => '',
                    '_statuses'  => [],
                ];
            }
            $g = &$groups[$k];
            $g['count']++;
            $g['last_at'] = (string) $r['created_at'];
            if ($r['guest_name'] !== '' && !in_array($r['guest_name'], $g['names'], true) && count($g['names']) < 6) $g['names'][] = (string) $r['guest_name'];
            if ($r['message'] !== '' && count($g['messages']) < 3) $g['messages'][] = (string) $r['message'];
            $g['_statuses'][] = (string) $r['status'];
            if ($r['status'] === 'played' && $r['played_at']) $g['played_at'] = (string) $r['played_at'];
            unset($g);
        }
        $out = [];
        $counts = ['open' => 0, 'played' => 0, 'rejected' => 0, 'total' => 0];
        foreach ($groups as $g) {
            $st = in_array('played', $g['_statuses'], true) ? 'played'
                : (count(array_unique($g['_statuses'])) === 1 && $g['_statuses'][0] === 'rejected' ? 'rejected' : 'open');
            $g['status'] = $st;
            $g['status_label'] = self::STATUSES[$st];
            $g['time'] = $g['first_at'] ? gmdate('H:i', strtotime($g['first_at'])) : '';
            unset($g['_statuses']);
            $counts[$st]++;
            $counts['total']++;
            $out[] = $g;
        }
        return ['requests' => $out, 'counts' => $counts];
    }

    /** GET /music/dj/requests?event_id= */
    public static function rest_dj_list(WP_REST_Request $req) {
        $event_id = intval($req->get_param('event_id'));
        $events   = self::dj_event_candidates();
        $cur      = self::current_event();
        if (!$event_id) {
            if ($cur) {
                $event_id = intval($cur['id']);
            } else {
                // zuletzt begonnenes Event aus der Kandidatenliste
                $now = gmdate('Y-m-d H:i', self::now());
                foreach ($events as $e) if ($e['starts_at'] <= $now) $event_id = $e['id'];
                if (!$event_id && $events) $event_id = $events[0]['id'];
            }
        }
        $event = null;
        if ($event_id) {
            $w = self::event_window($event_id);
            $event = $w ? self::event_payload($event_id, $w) : ['id' => $event_id, 'title' => get_the_title($event_id)];
        }
        $data = $event_id ? self::grouped_requests($event_id) : ['requests' => [], 'counts' => ['open' => 0, 'played' => 0, 'rejected' => 0, 'total' => 0]];
        return rest_ensure_response([
            'ok'       => true,
            'enabled'  => self::enabled(),
            'live'     => $cur && intval($cur['id']) === $event_id,
            'event'    => $event,
            'events'   => $events,
            'requests' => $data['requests'],
            'counts'   => $data['counts'],
            'now'      => gmdate('H:i', self::now()),
        ]);
    }

    /** POST /music/dj/requests/{id}/status {status} – gilt für alle Wünsche desselben Songs an dem Abend. */
    public static function rest_dj_status(WP_REST_Request $req) {
        global $wpdb;
        $rt     = $wpdb->prefix . self::T_REQ;
        $id     = intval($req['id']);
        $status = sanitize_key((string) $req->get_param('status'));
        if (!isset(self::STATUSES[$status])) return self::error('tix_music_status', 'Ungültiger Status.', 400);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $rt WHERE id = %d", $id), ARRAY_A);
        if (!$row) return self::error('tix_music_missing', 'Wunsch nicht gefunden.', 404);
        $now = self::mysql_now();
        $wpdb->query($wpdb->prepare(
            "UPDATE $rt SET status = %s, updated_at = %s, played_at = %s WHERE event_id = %d AND song_key = %s",
            $status, $now, $status === 'played' ? $now : null, intval($row['event_id']), (string) $row['song_key']
        ));
        return rest_ensure_response(['ok' => true, 'id' => $id, 'status' => $status] + self::grouped_requests(intval($row['event_id'])));
    }

    // ──────────────────────────────────────────
    //  DJ-Seite (Shortcode / eigenständige Seite)
    // ──────────────────────────────────────────

    /** Die automatisch angelegte DJ-Seite ohne Theme rendern (Kanzel-Display). */
    public static function maybe_render_dj_page() {
        $s = self::settings();
        $id = intval($s['dj_page_id']);
        if (!$id || !is_page($id)) return;
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<meta name="robots" content="noindex,nofollow"><title>Musikwünsche – ' . esc_html(get_bloginfo('name')) . '</title></head>'
           . '<body style="margin:0;background:#131020;">' . self::shortcode_dj([]) . '</body></html>';
        exit;
    }

    public static function shortcode_dj($atts = []) {
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if ($key === '' || !hash_equals(self::dj_key(), $key)) {
            return '<div style="font-family:-apple-system,Inter,sans-serif;background:#131020;color:#fff;padding:48px 24px;text-align:center;min-height:60vh;">'
                 . '<div style="font-size:40px;">🎧</div><h1 style="font-size:22px;margin:12px 0 6px;">Musikwünsche – DJ-Ansicht</h1>'
                 . '<p style="opacity:.7;">Kein Zugriff. Bitte den geheimen DJ-Link aus dem Tixomat-Backend verwenden.</p></div>';
        }
        $cfg = wp_json_encode([
            'rest' => esc_url_raw(rest_url(self::NS . '/music/dj/requests')),
            'key'  => $key,
            'site' => get_bloginfo('name'),
        ]);
        ob_start();
        ?>
<style>
.tixdj{font-family:-apple-system,BlinkMacSystemFont,"Inter","Segoe UI",Roboto,sans-serif;background:#131020;color:#fff;min-height:100vh;padding:20px;box-sizing:border-box}
.tixdj *{box-sizing:border-box}
.tixdj-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.tixdj-head h1{font-size:22px;margin:0;font-weight:800}
.tixdj-head .sub{opacity:.65;font-size:13px;margin-top:2px}
.tixdj-ev{display:flex;align-items:center;gap:10px}
.tixdj-ev select{background:#1f1b2e;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:10px 12px;font-size:14px}
.tixdj-live{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#14b8a6}
.tixdj-live i{width:8px;height:8px;border-radius:50%;background:#14b8a6;display:inline-block;animation:tixdjpulse 1.4s infinite}
@keyframes tixdjpulse{0%{opacity:1}50%{opacity:.25}100%{opacity:1}}
.tixdj-tabs{display:flex;gap:8px;margin:6px 0 14px;flex-wrap:wrap}
.tixdj-tabs button{background:transparent;border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:999px;padding:8px 14px;font-size:13px;font-weight:700;cursor:pointer}
.tixdj-tabs button.on{background:#fff;color:#131020;border-color:#fff}
.tixdj-row{display:flex;align-items:center;gap:14px;background:#1f1b2e;border-radius:14px;padding:12px 14px;margin-bottom:10px;border:1px solid rgba(255,255,255,.06)}
.tixdj-row.played{opacity:.45}
.tixdj-row.rejected{opacity:.35;text-decoration:line-through}
.tixdj-time{font-variant-numeric:tabular-nums;font-weight:800;font-size:18px;min-width:56px;text-align:center}
.tixdj-cover{width:56px;height:56px;border-radius:8px;object-fit:cover;background:#2a2540;flex:none}
.tixdj-main{flex:1;min-width:0}
.tixdj-title{font-size:17px;font-weight:800;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tixdj-artist{font-size:14px;opacity:.75;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tixdj-meta{font-size:12px;opacity:.6;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tixdj-count{background:#f5b731;color:#131020;font-weight:800;font-size:13px;border-radius:999px;padding:4px 10px;flex:none}
.tixdj-actions{display:flex;gap:6px;flex:none}
.tixdj-actions button{border:none;border-radius:10px;padding:10px 12px;font-size:13px;font-weight:700;cursor:pointer;color:#131020;background:#fff}
.tixdj-actions button.no{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.3)}
.tixdj-empty{opacity:.6;text-align:center;padding:40px 10px;font-size:15px}
.tixdj-foot{opacity:.45;font-size:12px;text-align:center;margin-top:18px}
@media(max-width:600px){.tixdj{padding:12px}.tixdj-time{min-width:44px;font-size:15px}.tixdj-cover{width:44px;height:44px}.tixdj-actions button{padding:9px 10px}}
</style>
<div class="tixdj" id="tixdj">
  <div class="tixdj-head">
    <div><h1>🎧 Musikwünsche</h1><div class="sub" id="tixdj-sub">Lade …</div></div>
    <div class="tixdj-ev"><span class="tixdj-live" id="tixdj-live" style="display:none"><i></i> LIVE</span><select id="tixdj-select"></select></div>
  </div>
  <div class="tixdj-tabs">
    <button data-f="open" class="on">Offen <span id="c-open">0</span></button>
    <button data-f="played">Gespielt <span id="c-played">0</span></button>
    <button data-f="all">Alle <span id="c-total">0</span></button>
  </div>
  <div id="tixdj-list"><div class="tixdj-empty">Lade …</div></div>
  <div class="tixdj-foot">Aktualisiert sich alle 8 Sekunden · gleiche Songs werden gebündelt · Uhrzeit = erster Wunsch</div>
</div>
<script>
(function(){
  var cfg=<?php echo $cfg; ?>, filter='open', eventId=0, data=null, timer=null;
  var $=function(id){return document.getElementById(id)};
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
  function url(p){var u=new URL(cfg.rest);u.searchParams.set('key',cfg.key);if(eventId)u.searchParams.set('event_id',eventId);return u.toString()+(p||'')}
  function load(){
    fetch(url(),{cache:'no-store'}).then(function(r){return r.json()}).then(function(d){
      if(!d||!d.ok)return; data=d; if(d.event)eventId=d.event.id; render();
    }).catch(function(){});
  }
  function setStatus(id,st){
    var u=new URL(cfg.rest+'/'+id+'/status');u.searchParams.set('key',cfg.key);
    fetch(u.toString(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({status:st})})
      .then(function(r){return r.json()}).then(function(d){if(d&&d.ok&&data){data.requests=d.requests;data.counts=d.counts;render();}}).catch(function(){});
  }
  function render(){
    if(!data)return;
    var ev=data.event; $('tixdj-sub').textContent=ev?(ev.title+(ev.date?' · '+ev.date.split('-').reverse().join('.'):'')):'Kein Event gewählt';
    $('tixdj-live').style.display=data.live?'inline-flex':'none';
    var sel=$('tixdj-select'), html='';
    (data.events||[]).forEach(function(e){html+='<option value="'+e.id+'"'+(e.id==eventId?' selected':'')+'>'+esc(e.title)+' – '+esc((e.date||'').split('-').reverse().join('.'))+'</option>'});
    if(sel.innerHTML!==html)sel.innerHTML=html;
    $('c-open').textContent=data.counts.open; $('c-played').textContent=data.counts.played; $('c-total').textContent=data.counts.total;
    var rows=(data.requests||[]).filter(function(r){return filter==='all'||(filter==='open'?r.status==='open':r.status===filter)});
    if(!rows.length){$('tixdj-list').innerHTML='<div class="tixdj-empty">'+(filter==='open'?'Keine offenen Wünsche – die Gäste sind dran 🎉':'Nichts hier')+'</div>';return}
    var out='';
    rows.forEach(function(r){
      var meta=[]; if(r.names&&r.names.length)meta.push('von '+r.names.join(', ')); if(r.messages&&r.messages.length)meta.push('„'+r.messages.join('“ · „')+'“');
      out+='<div class="tixdj-row '+esc(r.status)+'">'
        +'<div class="tixdj-time">'+esc(r.time)+'</div>'
        +(r.cover?'<img class="tixdj-cover" src="'+esc(r.cover)+'" alt="">':'<div class="tixdj-cover"></div>')
        +'<div class="tixdj-main"><div class="tixdj-title">'+esc(r.title)+'</div><div class="tixdj-artist">'+esc(r.artist)+'</div>'
        +(meta.length?'<div class="tixdj-meta">'+esc(meta.join(' · '))+'</div>':'')+'</div>'
        +(r.count>1?'<div class="tixdj-count">×'+r.count+'</div>':'')
        +'<div class="tixdj-actions">'
        +(r.status==='played'?'<button class="no" data-s="open" data-id="'+r.id+'">Zurück</button>'
          :'<button data-s="played" data-id="'+r.id+'">Gespielt</button><button class="no" data-s="rejected" data-id="'+r.id+'">✕</button>')
        +'</div></div>';
    });
    $('tixdj-list').innerHTML=out;
  }
  document.addEventListener('click',function(e){
    var b=e.target.closest('button'); if(!b)return;
    if(b.dataset.f){filter=b.dataset.f;document.querySelectorAll('.tixdj-tabs button').forEach(function(x){x.classList.toggle('on',x===b)});render();}
    else if(b.dataset.s){setStatus(b.dataset.id,b.dataset.s)}
  });
  $('tixdj-select').addEventListener('change',function(){eventId=parseInt(this.value,10)||0;load()});
  load(); timer=setInterval(load,8000);
  document.addEventListener('visibilitychange',function(){if(!document.hidden)load()});
})();
</script>
        <?php
        return ob_get_clean();
    }

    // ──────────────────────────────────────────
    //  Admin
    // ──────────────────────────────────────────

    public static function register_admin_menu() {
        add_submenu_page('tixomat', 'Musikwünsche', 'Musikwünsche', 'manage_options', 'tix-music', [__CLASS__, 'render_admin_page']);
    }

    public static function admin_save() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_music_save');
        $in = wp_unslash($_POST);
        self::update_settings([
            'enabled'          => !empty($in['enabled']) ? 1 : 0,
            'before_min'       => max(0, intval($in['before_min'] ?? 60)),
            'after_hours'      => max(1, intval($in['after_hours'] ?? 7)),
            'grace_min'        => max(0, intval($in['grace_min'] ?? 60)),
            'default_start'    => preg_match('/^\d{2}:\d{2}$/', (string) ($in['default_start'] ?? '')) ? $in['default_start'] : '22:00',
            'max_per_night'    => max(1, intval($in['max_per_night'] ?? 5)),
            'min_interval_min' => max(0, intval($in['min_interval_min'] ?? 3)),
            'deezer_genres'    => implode(',', array_filter(array_map('intval', explode(',', (string) ($in['deezer_genres'] ?? ''))), function ($v) { return $v >= 0; })),
            'deezer_playlists' => implode(',', array_filter(array_map('intval', explode(',', (string) ($in['deezer_playlists'] ?? ''))))),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=tix-music&saved=1'));
        exit;
    }

    public static function admin_import() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_music_import');
        $n = self::import_charts();
        wp_safe_redirect(admin_url('admin.php?page=tix-music&imported=' . intval($n)));
        exit;
    }

    public static function admin_newkey() {
        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        check_admin_referer('tix_music_newkey');
        self::update_settings(['dj_key' => strtolower(wp_generate_password(24, false, false))]);
        wp_safe_redirect(admin_url('admin.php?page=tix-music&newkey=1'));
        exit;
    }

    public static function render_admin_page() {
        global $wpdb;
        $s  = self::settings();
        $st = $wpdb->prefix . self::T_SONGS;
        $rt = $wpdb->prefix . self::T_REQ;
        $songs = intval($wpdb->get_var("SELECT COUNT(*) FROM $st"));
        $today = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rt WHERE created_at >= %s", gmdate('Y-m-d', self::now()) . ' 00:00:00')));
        $recent = $wpdb->get_results("SELECT r.*, p.post_title AS event_title FROM $rt r LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id ORDER BY r.id DESC LIMIT 40", ARRAY_A);
        $cur = self::current_event();
        ?>
        <div class="wrap">
            <h1>🎧 Musikwünsche</h1>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success"><p>Einstellungen gespeichert.</p></div><?php endif; ?>
            <?php if (isset($_GET['imported'])): ?><div class="notice notice-success"><p><?php echo intval($_GET['imported']); ?> Titel importiert.</p></div><?php endif; ?>
            <?php if (!empty($_GET['newkey'])): ?><div class="notice notice-warning"><p>Neuer DJ-Link erzeugt – der alte Link funktioniert nicht mehr.</p></div><?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:900px;margin:12px 0 20px;">
                <div class="card" style="margin:0;padding:14px;"><strong>Status</strong><br><?php echo self::enabled() ? ($cur ? '🟢 Aktiv – Party läuft: ' . esc_html(get_the_title($cur['id'])) : '🟡 Aktiv – gerade kein Event') : '⚪ Deaktiviert'; ?></div>
                <div class="card" style="margin:0;padding:14px;"><strong>Songs in der Datenbank</strong><br><?php echo number_format_i18n($songs); ?></div>
                <div class="card" style="margin:0;padding:14px;"><strong>Wünsche heute</strong><br><?php echo $today; ?></div>
                <div class="card" style="margin:0;padding:14px;"><strong>Letzter Chart-Import</strong><br><?php echo $s['last_import'] ? esc_html($s['last_import'] . ' (' . $s['last_import_info'] . ')') : 'noch nie'; ?></div>
            </div>

            <h2>DJ-Ansicht</h2>
            <p>Geheimer Link für den DJ (auf dem Handy oder Laptop an der Kanzel öffnen, Liste aktualisiert sich von selbst). Dieselbe Liste gibt es in der App im Veranstalter-Bereich.</p>
            <p><input type="text" readonly class="regular-text code" style="width:100%;max-width:700px;" value="<?php echo esc_attr(self::dj_url()); ?>" onclick="this.select()"></p>
            <p>
                <a class="button button-primary" target="_blank" href="<?php echo esc_url(self::dj_url()); ?>">DJ-Ansicht öffnen</a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('Neuen Link erzeugen? Der alte funktioniert dann nicht mehr.');">
                    <?php wp_nonce_field('tix_music_newkey'); ?><input type="hidden" name="action" value="tix_music_newkey">
                    <button class="button">Neuen Link erzeugen</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <?php wp_nonce_field('tix_music_import'); ?><input type="hidden" name="action" value="tix_music_import">
                    <button class="button">Charts jetzt importieren</button>
                </form>
            </p>

            <h2>Einstellungen</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tix_music_save'); ?><input type="hidden" name="action" value="tix_music_save">
                <table class="form-table">
                    <tr><th>Musikwünsche</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($s['enabled'])); ?>> In der App aktiv</label></td></tr>
                    <tr><th>Zeitfenster</th><td>
                        Wünsche ab <input type="number" name="before_min" value="<?php echo intval($s['before_min']); ?>" style="width:70px"> Minuten vor Beginn,
                        ohne Endzeit bis <input type="number" name="after_hours" value="<?php echo intval($s['after_hours']); ?>" style="width:60px"> Stunden nach Beginn,
                        nach dem Ende noch <input type="number" name="grace_min" value="<?php echo intval($s['grace_min']); ?>" style="width:70px"> Minuten.<br>
                        <span class="description">Beginn = Startzeit des Events (sonst Einlass, sonst <input type="text" name="default_start" value="<?php echo esc_attr($s['default_start']); ?>" style="width:70px"> Uhr). Nur laufende Events nehmen Wünsche an.</span>
                    </td></tr>
                    <tr><th>Limits pro Gast</th><td>
                        maximal <input type="number" name="max_per_night" value="<?php echo intval($s['max_per_night']); ?>" style="width:60px"> Wünsche pro Party,
                        mindestens <input type="number" name="min_interval_min" value="<?php echo intval($s['min_interval_min']); ?>" style="width:60px"> Minuten Abstand.
                    </td></tr>
                    <tr><th>Deezer-Charts (Genre-IDs)</th><td><input type="text" class="regular-text" name="deezer_genres" value="<?php echo esc_attr($s['deezer_genres']); ?>"><br><span class="description">0 = Alle, 113 Dance, 106 Electro, 116 Rap/Hip-Hop, 132 Pop, 459 Deutsche Musik, 165 R&amp;B, 197 Latin. Täglicher Import je Genre.</span></td></tr>
                    <tr><th>Deezer-Playlists (IDs)</th><td><input type="text" class="regular-text" name="deezer_playlists" value="<?php echo esc_attr($s['deezer_playlists']); ?>"><br><span class="description">1111143121 = „Top Germany“ (Deezer Charts), 65490170 = „Hits von heute“. Eigene Playlists: ID aus der Deezer-URL.</span></td></tr>
                </table>
                <?php submit_button('Speichern'); ?>
            </form>

            <h2>Letzte Wünsche</h2>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th>Zeit</th><th>Event</th><th>Song</th><th>Gast</th><th>Nachricht</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$recent): ?><tr><td colspan="6">Noch keine Wünsche.</td></tr><?php endif; ?>
                <?php foreach ((array) $recent as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r['created_at']); ?></td>
                        <td><?php echo esc_html($r['event_title'] ?: '#' . $r['event_id']); ?></td>
                        <td><strong><?php echo esc_html($r['title']); ?></strong><br><span style="opacity:.7"><?php echo esc_html($r['artist']); ?></span></td>
                        <td><?php echo esc_html($r['guest_name'] ?: '–'); ?><?php echo $r['user_id'] ? ' <span class="dashicons dashicons-admin-users" title="Konto"></span>' : ''; ?></td>
                        <td><?php echo esc_html($r['message']); ?></td>
                        <td><?php echo esc_html(self::STATUSES[$r['status']] ?? $r['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
