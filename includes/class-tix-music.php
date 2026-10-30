<?php
/**
 * Musikwünsche: Song-Autocomplete (eigene Liste + Apple iTunes Search), Wünsche
 * der Gäste nur während laufender Events, DJ-Ansicht per geheimem Link und in der
 * Veranstalter-App.
 *
 * Song-Datenbank: eigene Tabelle, die sich selbst füllt – Live-Suche bei Apple
 * (iTunes Search API) für alles, was lokal fehlt (Ergebnisse werden gespeichert),
 * plus jeder Wunsch zählt hoch. Gespeichert werden nur Titel, Artist, Dauer,
 * Explicit und die Apple-Track-ID – keine Cover, keine Hörproben (1.38.428;
 * vorher Deezer, dessen API nur nicht-kommerziell erlaubt ist).
 *
 * REST (tixomat/v1):
 *   GET  /music/status[?event_id=]           öffentlich: aktiv?, läuft gerade ein Event?, nächstes Event
 *                                            (mit event_id: nur dieses Event – Modul „music“ des Veranstalters, eigenes Zeitfenster)
 *   GET  /music/search?q=                    öffentlich: Songs (lokal + Apple)
 *   POST /music/requests                     öffentlich (Token optional): Wunsch senden (optional event_id)
 *   GET  /music/requests/mine?ids=&device=   öffentlich: Status der eigenen Wünsche
 *   GET  /music/dj/requests?event_id=        DJ-Key (?key=) oder Veranstalter-Login
 *   POST /music/dj/requests/{id}/status      DJ-Key oder Veranstalter-Login
 *   GET/POST /music/settings                 Inhaber/Team-Admin (Einzelseite) bzw. Admin (Plattform): an/aus, Zeitfenster, Limits (1.38.455)
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
    const DB_VERSION  = 2;
    const CRON        = 'tix_music_import';   // alter Deezer-Chart-Import, wird nur noch abgemeldet
    const T_SONGS     = 'tix_songs';
    const T_REQ       = 'tix_song_requests';
    const ITUNES      = 'https://itunes.apple.com/search';
    const ITUNES_PER_MIN = 18;                 // Apple: etwa 20 Anfragen pro Minute
    const STATUSES    = ['open' => 'Offen', 'played' => 'Gespielt', 'rejected' => 'Abgelehnt'];

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 20);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_shortcode('tix_musikwunsch_dj', [__CLASS__, 'shortcode_dj']);
        add_action('template_redirect', [__CLASS__, 'maybe_render_dj_page']);
        add_filter('wp_robots', [__CLASS__, 'robots_noindex']);
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('admin_post_tix_music_save', [__CLASS__, 'admin_save']);
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
        if (class_exists('TIX_Modules') && !TIX_Modules::allowed('music')) return false; // Plattform-Freischaltung
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
        $db = intval(get_option(self::OPT_DB, 0));
        if ($db < self::DB_VERSION) {
            self::create_tables();
            if ($db === 1) self::drop_deezer_covers();
            update_option(self::OPT_DB, self::DB_VERSION, false);
        }
        // Deezer-Chart-Import gibt es nicht mehr
        if (wp_next_scheduled(self::CRON)) wp_clear_scheduled_hook(self::CRON);
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
            apple_id BIGINT UNSIGNED DEFAULT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            artist VARCHAR(255) NOT NULL DEFAULT '',
            search VARCHAR(512) NOT NULL DEFAULT '',
            cover VARCHAR(255) NOT NULL DEFAULT '',
            duration INT UNSIGNED NOT NULL DEFAULT 0,
            rank BIGINT UNSIGNED NOT NULL DEFAULT 0,
            explicit TINYINT UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(20) NOT NULL DEFAULT 'apple',
            requests INT UNSIGNED NOT NULL DEFAULT 0,
            charted_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY deezer_id (deezer_id),
            UNIQUE KEY apple_id (apple_id),
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

    /** 1.38.428: Deezer-Cover-Adressen (fremdes CDN, nicht erlaubt) aus Songs und Wünschen entfernen. */
    private static function drop_deezer_covers() {
        global $wpdb;
        $wpdb->query("UPDATE {$wpdb->prefix}" . self::T_SONGS . " SET cover = '' WHERE cover <> ''");
        $wpdb->query("UPDATE {$wpdb->prefix}" . self::T_REQ . " SET cover = '' WHERE cover <> ''");
        $s = get_option(self::OPT, []);
        if (is_array($s)) {
            unset($s['deezer_genres'], $s['deezer_playlists'], $s['last_import'], $s['last_import_info']);
            update_option(self::OPT, $s, false);
        }
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

    public static function dj_url($key = null) {
        $s = self::settings();
        $id = intval($s['dj_page_id']);
        $base = $id ? get_permalink($id) : home_url('/dj-musikwuensche/');
        return add_query_arg('key', $key ?? self::dj_key(), $base);
    }

    const META_ORG_KEY = '_tix_org_dj_key';

    /** Neuer zufälliger Schlüssel (24 Zeichen, a–z0–9). */
    private static function new_key() {
        return strtolower(wp_generate_password(24, false, false));
    }

    /** DJ-Schlüssel eines Veranstalters (Mehr-Veranstalter-Modus), beim ersten Bedarf erzeugt. */
    public static function org_dj_key($oid, $renew = false) {
        $oid = intval($oid);
        $key = (string) get_post_meta($oid, self::META_ORG_KEY, true);
        if ($renew || $key === '') {
            $key = self::new_key();
            update_post_meta($oid, self::META_ORG_KEY, $key);
        }
        return $key;
    }

    private static function req_key(WP_REST_Request $req) {
        return (string) ($req->get_param('key') ?? $req->get_header('x-tix-dj-key') ?? '');
    }

    /**
     * Wem gehört ein DJ-Schlüssel? 0 = Seiten-Schlüssel (alle Events), >0 = Veranstalter-ID
     * (nur dessen Events), null = ungültig.
     */
    public static function key_scope($key) {
        $key = (string) $key;
        if ($key === '' || strlen($key) > 64) return null;
        if (hash_equals(self::dj_key(), $key)) return 0;
        $ids = get_posts([
            'post_type' => 'tix_organizer', 'post_status' => 'publish', 'posts_per_page' => 1,
            'fields' => 'ids', 'meta_key' => self::META_ORG_KEY, 'meta_value' => $key, 'no_found_rows' => true,
        ]);
        if ($ids && hash_equals((string) get_post_meta($ids[0], self::META_ORG_KEY, true), $key)) return intval($ids[0]);
        return null;
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
            'title'     => html_entity_decode(get_the_title($event_id), ENT_QUOTES, 'UTF-8'),
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
    //  Apple iTunes Search (seit 1.38.428, vorher Deezer)
    // ──────────────────────────────────────────
    //  Deezer erlaubt seine API nur nicht-kommerziell → raus. Die iTunes Search
    //  API liefert Titel/Artist für die Autovervollständigung. Cover und Hörproben
    //  sind dort „Promotional Content“ (nur zur Store-Werbung, nur neben einem
    //  Apple-Badge, nicht speichern) → werden weder gespeichert noch ausgeliefert.
    //  Apple drosselt auf etwa 20 Anfragen pro Minute: eigener Zähler, Pause bei
    //  429/403, Suchergebnisse 15 Minuten zwischengespeichert.

    /** iTunes-Search-Aufruf (null = nicht verfügbar → nur eigene Liste). */
    private static function itunes(array $args) {
        if (get_transient('tix_music_itunes_pause')) return null;
        $bucket = 'tix_music_itunes_' . gmdate('YmdHi');
        $used = intval(get_transient($bucket));
        if ($used >= self::ITUNES_PER_MIN) return null;
        set_transient($bucket, $used + 1, 2 * MINUTE_IN_SECONDS);
        $res = wp_remote_get(self::ITUNES . '?' . http_build_query($args), [
            'timeout' => 5,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($res)) return null;
        $code = intval(wp_remote_retrieve_response_code($res));
        if ($code === 429 || $code === 403) {
            set_transient('tix_music_itunes_pause', 1, 10 * MINUTE_IN_SECONDS);
            return null;
        }
        if ($code !== 200) return null;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($data) && isset($data['results']) && is_array($data['results']) ? $data : null;
    }

    /** iTunes-Ergebnis → Song-Zeile (nur Titel, Artist, Dauer, Explicit – kein Cover). */
    private static function song_from_itunes(array $t) {
        $title  = (string) ($t['trackName'] ?? '');
        $artist = (string) ($t['artistName'] ?? '');
        if ($title === '' || empty($t['trackId']) || ($t['kind'] ?? 'song') !== 'song') return null;
        return [
            'apple_id' => intval($t['trackId']),
            'title'    => mb_substr($title, 0, 255),
            'artist'   => mb_substr($artist, 0, 255),
            'search'   => mb_substr(self::normalize($title . ' ' . $artist), 0, 512),
            'duration' => intval(round(intval($t['trackTimeMillis'] ?? 0) / 1000)),
            'explicit' => ($t['trackExplicitness'] ?? '') === 'explicit' ? 1 : 0,
        ];
    }

    /**
     * Apple-Song speichern/aktualisieren (Schlüssel: Apple-Track-ID). Gibt es den
     * Song schon aus der Deezer-Zeit (gleicher Titel + Artist), bekommt diese Zeile
     * die Apple-ID – die Wunsch-Zähler bleiben. Liefert die DB-Zeile.
     */
    private static function upsert_apple(array $s) {
        global $wpdb;
        $t   = $wpdb->prefix . self::T_SONGS;
        $now = self::mysql_now();
        $id  = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE apple_id = %d", $s['apple_id'])));
        if (!$id) {
            $id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE apple_id IS NULL AND search = %s ORDER BY requests DESC, id ASC LIMIT 1", $s['search'])));
            if ($id) $wpdb->update($t, ['apple_id' => $s['apple_id'], 'updated_at' => $now], ['id' => $id]);
        }
        if ($id) {
            $wpdb->update($t, [
                'title' => $s['title'], 'artist' => $s['artist'], 'search' => $s['search'],
                'duration' => $s['duration'], 'explicit' => $s['explicit'], 'cover' => '', 'updated_at' => $now,
            ], ['id' => $id]);
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $t (apple_id, title, artist, search, cover, duration, rank, explicit, source, created_at, updated_at)
                 VALUES (%d, %s, %s, %s, '', %d, 0, %d, 'apple', %s, %s)",
                $s['apple_id'], $s['title'], $s['artist'], $s['search'], $s['duration'], $s['explicit'], $now, $now
            ));
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE apple_id = %d", $s['apple_id']), ARRAY_A);
    }

    /**
     * Song-Referenz für die Apps. Das Feld heißt aus Vertragsgründen weiter
     * `deezer_id`: alte Songs behalten ihre Deezer-ID, neue tragen die Apple-Track-ID.
     */
    private static function song_ref(array $r) {
        return intval($r['deezer_id'] ?? 0) ?: intval($r['apple_id'] ?? 0);
    }

    /** Wunsch-Schlüssel eines Songs aus der Datenbank (gleicher Song = eine DJ-Zeile). */
    private static function row_key(array $r) {
        if (intval($r['deezer_id'] ?? 0) > 0) return 'dz:' . intval($r['deezer_id']);
        if (intval($r['apple_id'] ?? 0) > 0) return 'am:' . intval($r['apple_id']);
        return 'id:' . intval($r['id']);
    }

    /** Song zur Referenz aus der Suche finden; bei (seltener) Doppelbelegung entscheidet der Titel. */
    private static function find_by_ref($ref, $title = '') {
        global $wpdb;
        $t = $wpdb->prefix . self::T_SONGS;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE deezer_id = %d OR (apple_id = %d AND deezer_id IS NULL) LIMIT 5", $ref, $ref
        ), ARRAY_A);
        if (!$rows) return null;
        $want = self::normalize($title);
        if ($want !== '') {
            foreach ($rows as $r) if (self::normalize($r['title']) === $want) return $r;
        }
        return $rows[0];
    }

    private static function row_to_song(array $r) {
        return [
            'id'        => intval($r['id']),
            'deezer_id' => self::song_ref($r),
            'title'     => (string) $r['title'],
            'artist'    => (string) $r['artist'],
            'cover'     => '',
            'duration'  => intval($r['duration']),
            'explicit'  => !empty($r['explicit']),
            'requests'  => intval($r['requests']),
        ];
    }

    /** Suche: eigene Tabelle (Wunsch-Häufigkeit) + Apple iTunes Search live, zusammengeführt. */
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

        // Apple erst ab 3 Zeichen (spart das Kontingent beim Tippen)
        $remote = [];
        if (mb_strlen($norm) >= 3) {
            $cache_key = 'tix_music_q_' . md5('itunes|' . $norm);
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                $remote = $cached;
            } else {
                $data = self::itunes(['term' => $norm, 'media' => 'music', 'entity' => 'song', 'country' => 'DE', 'limit' => 25]);
                if (is_array($data)) {
                    foreach ($data['results'] as $track) {
                        $song = is_array($track) ? self::song_from_itunes($track) : null;
                        $row  = $song ? self::upsert_apple($song) : null;
                        if ($row) $remote[] = self::row_to_song($row);
                    }
                    set_transient($cache_key, $remote, 15 * MINUTE_IN_SECONDS);
                }
            }
        }

        // Gleicher Song in mehreren Releases (Single, Album, Remix-Fassung mit
        // gleichem Namen) → nur einmal anzeigen: Schlüssel = normalisierter Titel + Artist
        $out  = [];
        $seen = [];
        $take = function (array $song) use (&$out, &$seen) {
            $k = self::normalize($song['title'] . ' ' . $song['artist']);
            if ($k === '' || isset($seen[$k])) return;
            $seen[$k] = true;
            $out[] = $song;
        };
        // Erst, was im Club schon gewünscht wurde
        foreach ((array) $local as $r) {
            if (intval($r['requests']) > 0) $take(self::row_to_song($r));
        }
        foreach ($remote as $s) {
            $take($s);
        }
        foreach ((array) $local as $r) {
            $take(self::row_to_song($r));
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
        register_rest_route(self::NS, '/music/dj-link', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'rest_dj_link'], 'permission_callback' => [__CLASS__, 'check_dj_link']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_dj_link'], 'permission_callback' => [__CLASS__, 'check_dj_link']],
        ]);
        register_rest_route(self::NS, '/music/settings', [
            ['methods' => 'GET',  'callback' => [__CLASS__, 'rest_settings'],      'permission_callback' => [__CLASS__, 'check_settings']],
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_settings_save'], 'permission_callback' => [__CLASS__, 'check_settings']],
        ]);
    }

    /** Einstellungen gelten für die ganze Seite: Einzelseite Inhaber/Team-Admin, Plattform nur Admin. */
    public static function check_settings(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (current_user_can('manage_options')) return true;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) return new WP_Error('rest_forbidden', 'Den Musikwunsch der Plattform stellt evendis ein.', ['status' => 403]);
        return class_exists('TIX_Studio_Stats') && TIX_Studio_Stats::is_manager() ? true : new WP_Error('rest_forbidden', 'Nur Inhaber und Team-Admins ändern den Musikwunsch.', ['status' => 403]);
    }

    /**
     * DJ-Link sehen/erneuern: Admin (Seiten-Link, im Mehr-Veranstalter-Modus optional ?organizer=),
     * Einzelseite Inhaber/Team-Admin, Mehr-Veranstalter-Modus Inhaber/Team-Admin des eigenen Veranstalters.
     */
    public static function check_dj_link(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        if (current_user_can('manage_options')) return true;
        if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            return TIX_App_Scope::organizer_id_for_user() && TIX_App_Scope::is_org_manager()
                ? true : new WP_Error('rest_forbidden', 'Den DJ-Link sehen nur Inhaber und Team-Admins.', ['status' => 403]);
        }
        return class_exists('TIX_Studio_Stats') && TIX_Studio_Stats::is_manager() ? true : new WP_Error('rest_forbidden', 'Den DJ-Link sehen nur Inhaber und Team-Admins.', ['status' => 403]);
    }

    /** GET /music/dj-link → {url, scope}; POST /music/dj-link {renew: true} → neuer Schlüssel, alter Link gilt nicht mehr. */
    public static function rest_dj_link(WP_REST_Request $req) {
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        $oid = 0;
        if ($multi) {
            $oid = current_user_can('manage_options') ? intval($req->get_param('organizer')) : TIX_App_Scope::organizer_id_for_user();
            if ($oid && get_post_type($oid) !== 'tix_organizer') return self::error('tix_not_found', 'Veranstalter nicht gefunden.', 404);
        }
        $renew = $req->get_method() === 'POST' && filter_var($req->get_param('renew'), FILTER_VALIDATE_BOOLEAN);
        if ($oid) {
            $key = self::org_dj_key($oid, $renew);
        } else {
            if ($renew) self::update_settings(['dj_key' => self::new_key()]);
            $key = self::dj_key();
        }
        if ($renew && class_exists('TIX_Customers') && method_exists('TIX_Customers', 'audit')) {
            // nie den Schlüssel ins Protokoll
            TIX_Customers::audit('dj_link_renewed', $oid ? (string) $oid : 'site', ['oid' => $oid, 'scope' => $oid ? 'organizer' : 'site'], ['scope' => $oid ? 'organizer' : 'site']);
        }
        return rest_ensure_response([
            'ok'      => true,
            'url'     => self::dj_url($key),
            'scope'   => $oid ? 'organizer' : 'site',
            'renewed' => $renew,
            'active'  => self::enabled(),
        ]);
    }

    private static function settings_payload() {
        $s = self::settings();
        return ['ok' => true, 'settings' => [
            'enabled' => !empty($s['enabled']),
            'before_min' => intval($s['before_min']), 'after_hours' => intval($s['after_hours']), 'grace_min' => intval($s['grace_min']),
            'default_start' => (string) $s['default_start'], 'max_per_night' => intval($s['max_per_night']), 'min_interval_min' => intval($s['min_interval_min']),
        ], 'active' => self::enabled(), 'module_allowed' => !class_exists('TIX_Modules') || TIX_Modules::allowed('music'), 'dj_url' => self::dj_url()];
    }

    /** GET /music/settings */
    public static function rest_settings(WP_REST_Request $req) {
        return rest_ensure_response(self::settings_payload());
    }

    /** POST /music/settings {enabled?, before_min?, after_hours?, grace_min?, default_start?, max_per_night?, min_interval_min?} – Teil-Update. */
    public static function rest_settings_save(WP_REST_Request $req) {
        $in = $req->get_json_params();
        if (!is_array($in)) $in = $req->get_body_params();
        if (isset($in['settings']) && is_array($in['settings'])) $in = $in['settings'];
        $limits = ['before_min' => [0, 1440], 'after_hours' => [1, 24], 'grace_min' => [0, 600], 'max_per_night' => [1, 100], 'min_interval_min' => [0, 120]];
        $patch = [];
        foreach ((array) $in as $k => $v) {
            if ($k === 'enabled') { $patch['enabled'] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; continue; }
            if ($k === 'default_start') {
                if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) return new WP_Error('tix_invalid_field', 'Uhrzeit im Format HH:MM, z. B. 22:00.', ['status' => 400, 'field' => 'default_start']);
                $patch['default_start'] = $v; continue;
            }
            if (isset($limits[$k])) {
                if (!is_numeric($v) || intval($v) < $limits[$k][0] || intval($v) > $limits[$k][1]) return new WP_Error('tix_invalid_field', sprintf('Bitte einen Wert zwischen %d und %d angeben.', $limits[$k][0], $limits[$k][1]), ['status' => 400, 'field' => $k]);
                $patch[$k] = intval($v); continue;
            }
            if (in_array($k, ['active', 'module_allowed', 'dj_url', 'ok'], true)) continue; // Rückgabe des GET mitgeschickt
            return new WP_Error('tix_invalid_field', 'Unbekannte Einstellung.', ['status' => 400, 'field' => (string) $k]);
        }
        if ($patch) self::update_settings($patch);
        return rest_ensure_response(self::settings_payload());
    }

    /** DJ-Zugang: geheimer Schlüssel (?key= oder Header X-Tix-DJ-Key) oder Veranstalter-Login. */
    public static function check_dj(WP_REST_Request $req) {
        if (self::key_scope(self::req_key($req)) !== null) return true;
        // Angemeldeter DJ (Rolle „DJ (App)“) darf die Liste sehen.
        if (is_user_logged_in() && current_user_can('tix_app_dj')) {
            // Mehr-Veranstalter-Modus: nur mit Veranstalter-Zuordnung
            if (class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) return TIX_App_Scope::require_organizer();
            return true;
        }
        if (class_exists('TIX_REST_API') && method_exists('TIX_REST_API', 'check_organizer')) {
            $r = TIX_REST_API::check_organizer($req);
            if ($r === true) return true;
        }
        return new WP_Error('rest_forbidden', 'DJ-Link oder Veranstalter-Login erforderlich.', ['status' => 401]);
    }

    /**
     * Wünsche für ein bestimmtes Event (evendis-App, Paket 3a): Seite erlaubt Musikwünsche und – im
     * Mehr-Veranstalter-Modus – das Modul „music“ des Veranstalters ist an. Zeitfenster wie event_window().
     * @return array|null {id, window, enabled, open} oder null, wenn es das Event nicht gibt
     */
    public static function event_state($event_id) {
        $event_id = intval($event_id);
        if (!$event_id || get_post_type($event_id) !== 'event' || get_post_status($event_id) !== 'publish') return null;
        $w = self::event_window($event_id);
        if (!$w) return null;
        $enabled = self::enabled() && get_post_meta($event_id, '_tix_status', true) !== 'cancelled';
        if ($enabled && class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) {
            $oid = intval(get_post_meta($event_id, '_tix_organizer_id', true));
            $mods = ($oid && class_exists('TIX_Public_Platform')) ? TIX_Public_Platform::modules($oid) : [];
            $enabled = !empty($mods['music']);
        }
        $now = self::now();
        return ['id' => $event_id, 'window' => $w, 'enabled' => $enabled, 'open' => $enabled && $now >= $w['opens'] && $now <= $w['closes']];
    }

    private static function status_payload($event_id = 0) {
        $s   = self::settings();
        if ($event_id) {
            $st = self::event_state($event_id);
            return [
                'ok'      => true,
                'enabled' => $st ? $st['enabled'] : false,
                'open'    => $st ? $st['open'] : false,
                'event'   => $st ? self::event_payload($st['id'], $st['window']) : null,
                // Noch nicht offen → dieses Event als „nächstes“ (Hinweis „Wünsche ab …“)
                'next'    => ($st && $st['enabled'] && !$st['open'] && $st['window']['opens'] > self::now()) ? self::event_payload($st['id'], $st['window']) : null,
                'now'     => gmdate('Y-m-d H:i', self::now()),
                'limits'  => [
                    'max_per_night'    => intval($s['max_per_night']),
                    'min_interval_min' => intval($s['min_interval_min']),
                    'before_min'       => intval($s['before_min']),
                ],
            ];
        }
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
        return rest_ensure_response(self::status_payload(absint($req->get_param('event_id'))));
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
        $want = absint($req->get_param('event_id'));
        if ($want) {
            // Wunsch für ein bestimmtes Event (Modul des Veranstalters + Zeitfenster dieses Events)
            $st = self::event_state($want);
            if (!$st) return self::error('tix_music_event', 'Event nicht gefunden.', 404, ['field' => 'event_id']);
            if (!$st['enabled']) return self::error('tix_music_off', 'Für dieses Event gibt es keine Musikwünsche.', 404);
            if (!$st['open']) {
                return self::error('tix_music_closed', 'Musikwünsche gibt es nur während der Party.', 409, [
                    'next' => $st['window']['opens'] > self::now() ? self::event_payload($st['id'], $st['window']) : null,
                ]);
            }
            $cur = ['id' => $st['id'], 'window' => $st['window']];
        } else {
            $cur = self::current_event();
        }
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

        // Song bestimmen: Referenz aus der Suche (Feld `deezer_id`, seit 1.38.428 bei
        // neuen Songs die Apple-Track-ID) oder Freitext. Kein Abruf bei Deezer/Apple:
        // alles, was die Suche geliefert hat, steht schon in der eigenen Tabelle.
        $deezer_id = intval($req->get_param('deezer_id'));
        $title  = mb_substr(sanitize_text_field((string) $req->get_param('title')), 0, 120);
        $artist = mb_substr(sanitize_text_field((string) $req->get_param('artist')), 0, 120);
        $song_id = 0;
        $key     = '';
        $t = $wpdb->prefix . self::T_SONGS;
        if ($deezer_id > 0) {
            $row = self::find_by_ref($deezer_id, $title);
            if ($row) {
                $song_id   = intval($row['id']);
                $deezer_id = self::song_ref($row);
                $title     = (string) $row['title'];
                $artist    = (string) $row['artist'];
                $key       = self::row_key($row);
            } else {
                $deezer_id = 0;
            }
        }
        if ($title === '') return self::error('tix_music_title', 'Bitte einen Songtitel angeben.', 400, ['field' => 'title']);

        $event_id = intval($cur['id']);
        if ($key === '') $key = self::song_key(0, $title, $artist);
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
            'cover'      => '',
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
        do_action('tix_music_requested', intval($event_id), $title, $artist); // Team-Mitteilung (1.38.595)
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

    /**
     * Auf welchen Veranstalter ist die DJ-Ansicht beschränkt? null = alle Events (Seiten-Schlüssel,
     * Einzelseite, Admin). Veranstalter-Schlüssel → dessen Events; Mehr-Veranstalter-Modus ohne
     * Schlüssel → Veranstalter des angemeldeten Nutzers (0 = keiner).
     */
    private static function dj_scope_oid(WP_REST_Request $req) {
        $k = self::key_scope(self::req_key($req));
        if ($k === 0) return null;
        if ($k) return $k;
        if (!(class_exists('TIX_App_Scope') && TIX_App_Scope::multi()) || !TIX_App_Scope::scoped()) return null;
        return TIX_App_Scope::organizer_id_for_user();
    }

    private static function event_in_scope($event_id, $oid) {
        return $oid && class_exists('TIX_App_Scope') && in_array(intval($oid), TIX_App_Scope::event_org_ids($event_id), true);
    }

    /** GET /music/dj/requests?event_id= */
    public static function rest_dj_list(WP_REST_Request $req) {
        $event_id = intval($req->get_param('event_id'));
        $events   = self::dj_event_candidates();
        $cur      = self::current_event();
        $oid = self::dj_scope_oid($req);
        if ($oid !== null) {
            if ($event_id && !self::event_in_scope($event_id, $oid)) {
                return TIX_App_Scope::deny('Kein Zugriff auf dieses Event.');
            }
            $events = array_values(array_filter($events, fn($e) => self::event_in_scope($e['id'], $oid)));
            if ($cur && !self::event_in_scope($cur['id'], $oid)) {
                // Gerade läuft ein fremdes Event – eigenes laufendes Event suchen
                $cur = null;
                foreach ($events as $e) {
                    $w = self::event_window($e['id']);
                    $now = self::now();
                    if ($w && $now >= $w['opens'] && $now <= $w['closes']) { $cur = ['id' => $e['id'], 'window' => $w]; break; }
                }
            }
        }
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
            $event = $w ? self::event_payload($event_id, $w) : ['id' => $event_id, 'title' => html_entity_decode(get_the_title($event_id), ENT_QUOTES, 'UTF-8')];
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
        $oid = self::dj_scope_oid($req);
        if ($oid !== null && !self::event_in_scope(intval($row['event_id']), $oid)) {
            return TIX_App_Scope::deny('Kein Zugriff auf diesen Wunsch.');
        }
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
        // Web-App-Meta-Tags: „Zum Home-Bildschirm“ öffnet die DJ-Ansicht ohne Browserleisten (iPhone hat keine Vollbild-API)
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
           . '<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#131020">'
           . '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">'
           . '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="Musikwünsche">'
           . '<title>Musikwünsche – ' . esc_html(get_bloginfo('name')) . '</title></head>'
           . '<body style="margin:0;background:#131020;">' . self::shortcode_dj([]) . '</body></html>';
        exit;
    }

    public static function shortcode_dj($atts = []) {
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if (self::key_scope($key) === null) {
            return '<div style="font-family:-apple-system,Inter,sans-serif;background:#131020;color:#fff;padding:48px 24px;text-align:center;min-height:60vh;">'
                 . '<div style="font-size:40px;">🎧</div><h1 style="font-size:22px;margin:12px 0 6px;">Musikwünsche – DJ-Ansicht</h1>'
                 . '<p style="opacity:.7;">Kein Zugriff. Bitte den aktuellen DJ-Link verwenden – ihn findest du im Studio unter Musikwunsch.</p></div>';
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
@media(max-width:600px){.tixdj{padding:12px}.tixdj-head h1{font-size:20px}.tixdj-ev select{max-width:100%;flex:1 1 auto}
  /* Handy kompakt: Titel volle Breite, Knöpfe in eigener Zeile */
  .tixdj-row{flex-wrap:wrap;gap:4px 12px;padding:10px 12px}.tixdj-time{min-width:0;font-size:14px;text-align:left}.tixdj-cover{display:none}
  .tixdj-main{flex:1 1 0}.tixdj-actions{flex:1 1 100%;justify-content:flex-end;margin-top:6px}.tixdj-actions button{padding:9px 14px;min-height:40px}
  .tixdj-actions button[data-s=played],.tixdj-actions button[data-s=open]{flex:1}}
.tixdj-ev{flex-wrap:wrap}
.tixdj-fsbtn{display:inline-flex;align-items:center;gap:6px;background:#fff;color:#131020;border:none;border-radius:10px;padding:10px 14px;font-size:14px;font-weight:800;cursor:pointer;min-height:40px}
.tixdj-fsbtn svg{width:16px;height:16px}
.tixdj-clock{display:none;font-variant-numeric:tabular-nums;font-weight:800;font-size:22px;opacity:.85}
.tixdj-hint{display:none;background:#f5b731;color:#131020;border-radius:12px;padding:10px 14px;font-size:13px;font-weight:600;margin:0 0 12px;align-items:center;gap:10px}
.tixdj-hint.on{display:flex}
.tixdj-hint span{flex:1}
.tixdj-hint button{background:transparent;border:none;font-size:18px;font-weight:800;cursor:pointer;color:#131020;padding:0 4px}
/* Vollbild: echtes Vollbild (Fullscreen-API) oder Ersatz per CSS (iPhone) */
.tixdj.is-fs{position:fixed;inset:0;z-index:2147483000;overflow-y:auto;-webkit-overflow-scrolling:touch;min-height:100%;padding:max(16px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(16px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left))}
.tixdj.is-fs .tixdj-clock{display:block}
.tixdj.is-fs .tixdj-foot{display:none}
.tixdj.is-fs .tixdj-head h1{font-size:24px}
.tixdj.is-fs .tixdj-title{font-size:19px}
.tixdj.is-fs .tixdj-actions button{padding:12px 14px;font-size:15px}
@media(min-width:720px){
  .tixdj.is-fs{padding:max(28px,env(safe-area-inset-top)) 36px 36px}
  .tixdj.is-fs .tixdj-head h1{font-size:34px}
  .tixdj.is-fs .tixdj-head .sub{font-size:17px}
  .tixdj.is-fs .tixdj-clock{font-size:34px}
  .tixdj.is-fs .tixdj-tabs button{font-size:17px;padding:11px 20px}
  .tixdj.is-fs .tixdj-row{padding:18px 22px;gap:20px;border-radius:18px;margin-bottom:14px}
  .tixdj.is-fs .tixdj-time{font-size:26px;min-width:80px}
  .tixdj.is-fs .tixdj-cover{width:84px;height:84px;border-radius:12px}
  .tixdj.is-fs .tixdj-title{font-size:30px}
  .tixdj.is-fs .tixdj-artist{font-size:21px}
  .tixdj.is-fs .tixdj-meta{font-size:16px}
  .tixdj.is-fs .tixdj-count{font-size:19px;padding:6px 14px}
  .tixdj.is-fs .tixdj-actions{gap:10px}
  .tixdj.is-fs .tixdj-actions button{padding:16px 22px;font-size:19px;border-radius:14px;min-width:64px}
  .tixdj.is-fs .tixdj-empty{font-size:22px;padding:80px 10px}
}
html.tixdj-lock,html.tixdj-lock body{overflow:hidden}
</style>
<div class="tixdj" id="tixdj">
  <div class="tixdj-head">
    <div><h1>🎧 Musikwünsche</h1><div class="sub" id="tixdj-sub">Lade …</div></div>
    <div class="tixdj-ev"><span class="tixdj-clock" id="tixdj-clock"></span><span class="tixdj-live" id="tixdj-live" style="display:none"><i></i> LIVE</span><select id="tixdj-select"></select>
      <button type="button" class="tixdj-fsbtn" id="tixdj-fs" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg><span>Vollbild</span></button></div>
  </div>
  <div class="tixdj-hint" id="tixdj-hint" role="status"><span>Für echtes Vollbild auf dem iPhone: Teilen → „Zum Home-Bildschirm“ und die DJ-Ansicht von dort öffnen.</span><button type="button" id="tixdj-hint-x" aria-label="Hinweis schließen">×</button></div>
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
  document.addEventListener('visibilitychange',function(){if(!document.hidden){load();if(fs)lock(true)}});

  // ── Vollbild: Fullscreen-API, sonst (iPhone) Ersatz per CSS; Display bleibt an (Wake-Lock)
  var root=$('tixdj'), btn=$('tixdj-fs'), fs=false, wake=null, clock=null;
  var de=document.documentElement;
  var canApi=!!(de.requestFullscreen||de.webkitRequestFullscreen);
  var standalone=window.navigator.standalone===true||(window.matchMedia&&matchMedia('(display-mode: standalone)').matches);
  function fsEl(){return document.fullscreenElement||document.webkitFullscreenElement}
  function lock(on){
    if(!('wakeLock' in navigator))return;
    if(on){navigator.wakeLock.request('screen').then(function(w){wake=w}).catch(function(){})}
    else if(wake){wake.release().catch(function(){});wake=null}
  }
  function tick(){var d=new Date();$('tixdj-clock').textContent=('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2)}
  function setFs(on){
    fs=on; root.classList.toggle('is-fs',on); de.classList.toggle('tixdj-lock',on);
    btn.setAttribute('aria-pressed',on?'true':'false');
    btn.querySelector('span').textContent=on?'Beenden':'Vollbild';
    btn.setAttribute('aria-label',on?'Vollbild beenden (Esc)':'Vollbild');
    lock(on); if(on){tick();clock=setInterval(tick,15000)}else{clearInterval(clock)}
  }
  function enter(){
    if(canApi){var r=de.requestFullscreen?de.requestFullscreen():de.webkitRequestFullscreen();if(r&&r.catch)r.catch(function(){setFs(true)});setFs(true);return}
    setFs(true);
    if(!standalone){try{if(!sessionStorage.getItem('tixdj-hint'))$('tixdj-hint').classList.add('on')}catch(e){$('tixdj-hint').classList.add('on')}}
  }
  function leave(){
    if(fsEl()){(document.exitFullscreen||document.webkitExitFullscreen).call(document)}
    setFs(false);
  }
  btn.addEventListener('click',function(e){e.stopPropagation();fs?leave():enter()});
  $('tixdj-hint-x').addEventListener('click',function(e){e.stopPropagation();$('tixdj-hint').classList.remove('on');try{sessionStorage.setItem('tixdj-hint','1')}catch(x){}});
  function onFsChange(){if(!fsEl()&&fs)setFs(false)}
  document.addEventListener('fullscreenchange',onFsChange);
  document.addEventListener('webkitfullscreenchange',onFsChange);
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&fs&&!fsEl())setFs(false)});
  // ?vollbild=1 (Link aus Studio/App) und vom Home-Bildschirm gestartet: gleich groß
  try{if(standalone||new URLSearchParams(location.search).get('vollbild')==='1')setFs(true)}catch(e){}
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
        ]);
        wp_safe_redirect(admin_url('admin.php?page=tix-music&saved=1'));
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
            <?php if (!empty($_GET['newkey'])): ?><div class="notice notice-warning"><p>Neuer DJ-Link erzeugt – der alte Link funktioniert nicht mehr.</p></div><?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:900px;margin:12px 0 20px;">
                <div class="card" style="margin:0;padding:14px;"><strong>Status</strong><br><?php echo self::enabled() ? ($cur ? '🟢 Aktiv – Party läuft: ' . esc_html(get_the_title($cur['id'])) : '🟡 Aktiv – gerade kein Event') : '⚪ Deaktiviert'; ?></div>
                <div class="card" style="margin:0;padding:14px;"><strong>Songs in der Datenbank</strong><br><?php echo number_format_i18n($songs); ?></div>
                <div class="card" style="margin:0;padding:14px;"><strong>Wünsche heute</strong><br><?php echo $today; ?></div>
                <div class="card" style="margin:0;padding:14px;"><strong>Songsuche</strong><br>Eigene Liste + Apple iTunes Search<?php echo get_transient('tix_music_itunes_pause') ? ' – <em>Apple pausiert (Limit), nur eigene Liste</em>' : ''; ?></div>
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
                    <tr><th>Songsuche</th><td><span class="description">Gäste finden Songs aus der eigenen Liste (alles, was schon gesucht oder gewünscht wurde) und live über die Apple iTunes Search (Titel und Artist, keine Cover). Was nicht gefunden wird, geht als Freitext.</span></td></tr>
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
