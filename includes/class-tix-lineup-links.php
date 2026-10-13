<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Lineup_Links – „Musik hören“ direkt auf der Künstlerseite statt in einer Suche (alle Seiten).
 *
 * Künstler: Titel aus dem Programm (`_tix_timetable`, eindeutig, Reihenfolge wie im Programm; 1.38.428, z. B. Mallorca),
 * danach weitere Zeilen aus dem Line-up (`_tix_info_lineup`, sonst `_tix_lineup`) → Zeilen wie in den Apps (je Absatz/Zeile eine, Aufzählungszeichen
 * vorne weg). Künstler = Teil hinter dem ersten „:“ („Floor 1: DJ Rawkey“ → „DJ Rawkey“). Fließtext (mehr als 20 Zeilen
 * oder eine Zeile länger als 40 Zeichen) wird nicht aufgelöst.
 *
 * Auflösung im Hintergrund (Cron, nie im Request), Ergebnis je Künstler in {prefix}tix_artist_links (Schlüssel normalisiert):
 *   - Apple Music: iTunes Search API (ohne Schlüssel) → artistLinkUrl
 *   - Spotify: Web API mit Client Credentials (Optionen tix_spotify_client_id / tix_spotify_client_secret);
 *     ohne Zugang kein Spotify-Treffer
 *   Übernommen wird nur ein Treffer mit gleichem Namen (normalisiert, „DJ“ vorne egal). Kein Treffer → erneut nach 30 Tagen.
 * Korrektur je Event: Meta `_tix_lineup_links` {artist_key: {source: manual|none, spotify, apple_music}}; manuell schlägt
 * automatisch und bleibt bei jedem Abgleich erhalten.
 *
 * Ausgabe (rein hinzufügend, Format der evendis-App 1f2df60):
 *   GET /public/events/{id} und GET /events/{id}/edit → lineup_links [{name, artist, spotify, apple_music, source}]
 *     name = Zeile wie im Line-up, artist = Suchbegriff; source auto | manual | none
 *     spotify/apple_music: URL | null (auto ohne Treffer) | "" (manual ohne Link für den Dienst bzw. none)
 *     Editor zusätzlich auto {spotify, apple_music} = automatische Treffer (zur Info)
 *   POST /events/{id} lineup_links [{name, spotify, apple_music, source}]: auto = Korrektur entfernen,
 *     manual = Links festhalten (leer = kein Link für den Dienst), none = für dieses Event kein Link.
 *     Nicht genannte Zeilen bleiben unverändert.
 *
 * Künstlerfoto (1.38.425, Rückfall statt Deezer – die Deezer-API erlaubt keine kommerzielle Nutzung):
 *   je Act im Event vom Team gepflegt (Meta `_tix_lineup_photos` {artist_key: attachment_id}); das zuletzt gesetzte Foto
 *   eines Künstlers gilt auch in anderen Events (Spalte photo_id in tix_artist_links), bis dort eins gesetzt ist.
 *   lineup_links-Zeilen tragen zusätzlich picture / picture_thumb (URL oder null), im Editor photo_id;
 *   POST /events/{id} lineup_links[{name, photo_id}] (0 = entfernen), POST /events/{id}/lineup-photo (multipart: name, file),
 *   GET /public/events/{id}/artists → {artists: [{name, artist, picture, picture_thumb, deezer_url: null, top, spotify, apple_music, source}]}
 *   (top = Apple Music Top-Songs seit 1.38.428, siehe TIX_Apple_Music; ohne Schlüssel []).
 *   Bilder liegen in der eigenen Mediathek – kein Abruf bei Dritten.
 */
class TIX_Lineup_Links {

    const DB_VER   = '2'; // 2: photo_id (Künstlerfoto)
    const META_PHOTOS = '_tix_lineup_photos';
    const META     = '_tix_lineup_links';
    const CRON     = 'tix_lineup_links_cron';
    const BATCH    = 15;          // Künstler je Lauf und Dienst (iTunes erlaubt rund 20 Anfragen je Minute)
    const RETRY    = 30;          // Tage bis zur erneuten Suche ohne Treffer
    const MAX_LINES = 20;
    const MAX_LEN   = 40;
    const MAX_SLOTS = 60;         // Künstler aus dem Programm (1.38.428)

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_install'], 8);
        add_action('added_post_meta', [__CLASS__, 'on_meta'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'on_meta'], 10, 4);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Lineup_Links::CRON)) wp_schedule_event(time() + 600, 'hourly', TIX_Lineup_Links::CRON);
        }, 25);
        add_action('add_meta_boxes', function () {
            add_meta_box('tix_lineup_links', 'Musik hören: Künstler-Links', [__CLASS__, 'render_box'], 'event', 'normal', 'default');
        });
        add_action('save_post_event', [__CLASS__, 'save_box'], 30, 2);
        add_action('admin_menu', [__CLASS__, 'menu'], 46);
        add_action('admin_post_tix_lineup_links', [__CLASS__, 'save_admin']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    private static function t() {
        global $wpdb;
        return $wpdb->prefix . 'tix_artist_links';
    }

    private static function ready() {
        return get_option('tix_artist_links_db') === self::DB_VER;
    }

    public static function maybe_install() {
        if (self::ready()) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE " . self::t() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name_key varchar(190) NOT NULL,
            name varchar(190) NOT NULL,
            spotify varchar(255) NOT NULL DEFAULT '',
            apple_music varchar(255) NOT NULL DEFAULT '',
            spotify_at datetime NULL,
            apple_at datetime NULL,
            photo_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name_key (name_key)
        ) " . $wpdb->get_charset_collate() . ";");
        update_option('tix_artist_links_db', self::DB_VER, false);
    }

    // ── Namen ──

    /**
     * Künstler wie in den Apps (festivalArtists): zuerst die Titel aus dem Programm (`_tix_timetable`, Tage nach Datum,
     * Reihenfolge wie im Programm), dann die Zeilen des Line-ups (ohne Fließtext); jeder Künstler einmal (norm()).
     */
    public static function lines($event_id) {
        $out = [];
        $seen = [];
        $add = function ($t) use (&$out, &$seen) {
            $k = self::norm(self::artist($t));
            if ($k === '' || isset($seen[$k])) return;
            $seen[$k] = true;
            $out[] = $t;
        };
        $tt = get_post_meta($event_id, '_tix_timetable', true);
        if (is_array($tt) && $tt) {
            ksort($tt);
            foreach ($tt as $slots) {
                if (!is_array($slots)) continue;
                foreach ($slots as $sl) {
                    $t = trim(html_entity_decode(wp_strip_all_tags((string) ($sl['title'] ?? '')), ENT_QUOTES, 'UTF-8'));
                    if ($t !== '' && mb_strlen($t) <= self::MAX_LEN && count($out) < self::MAX_SLOTS) $add($t);
                }
            }
        }
        // Line-up-Zeilen dazu (Events mit Programm und Line-up behalten ihre bisherigen Zeilen)
        foreach (self::lineup_lines($event_id) as $l) $add($l);
        return $out;
    }

    /** Zeilen des Line-up-Felds wie in den Apps; [] bei Fließtext. */
    private static function lineup_lines($event_id) {
        $html = (string) get_post_meta($event_id, '_tix_info_lineup', true);
        if ($html === '') $html = (string) get_post_meta($event_id, '_tix_lineup', true);
        if (trim($html) === '') return [];
        $out = [];
        foreach (preg_split('~</p>|<br\s*/?>|\n~i', $html) as $p) {
            $t = trim(html_entity_decode(wp_strip_all_tags($p), ENT_QUOTES, 'UTF-8'));
            $t = trim(preg_replace('/^[\s•\-–·*]+/u', '', $t));
            if ($t !== '') $out[] = $t;
        }
        if (count($out) > self::MAX_LINES) return [];
        foreach ($out as $l) if (mb_strlen($l) > self::MAX_LEN) return [];
        return $out;
    }

    /** „Floor 1: DJ Rawkey“ → „DJ Rawkey“ (wie artistQuery der App). */
    public static function artist($line) {
        $i = mb_strpos($line, ':');
        $n = trim($i !== false ? mb_substr($line, $i + 1) : $line);
        return $n !== '' ? $n : trim($line);
    }

    /** Suchbegriff für die Dienste: Künstler ohne „(live)“/„(DJ-Set)“. */
    public static function query($name) {
        $q = trim(preg_replace('/\s*\((live|dj[ -]?set|hybrid)\)\s*$/iu', '', self::artist($name)));
        return $q !== '' ? $q : self::artist($name);
    }

    /** Vergleichsform: klein, ohne Akzente/Satzzeichen, „DJ“/„MC“ vorne und „(live)“ hinten weg. */
    public static function norm($name) {
        $s = mb_strtolower(remove_accents(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')), 'UTF-8');
        $s = preg_replace('/\((live|dj[ -]?set|hybrid)\)|\b(live|dj[ -]?set)$/u', ' ', $s);
        $s = str_replace(['&', '+'], ' and ', $s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $s = preg_replace('/^(dj|mc)\s+/u', '', $s);
        return mb_substr($s, 0, 190);
    }

    // ── Warteschlange ──

    public static function on_meta($meta_id, $post_id, $key, $value) {
        if ($key !== '_tix_info_lineup' && $key !== '_tix_lineup' && $key !== '_tix_timetable') return;
        if (get_post_type($post_id) !== 'event') return;
        self::enqueue_event($post_id);
    }

    /** Neue Künstler eines Events eintragen und einen baldigen Lauf anstoßen. */
    public static function enqueue_event($event_id) {
        global $wpdb;
        self::maybe_install();
        $new = false;
        foreach (self::lines($event_id) as $line) {
            $name = self::artist($line);
            $key  = self::norm($name);
            if ($key === '' || mb_strlen($key) < 2) continue;
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO " . self::t() . " (name_key, name, created) VALUES (%s, %s, %s)", $key, mb_substr($name, 0, 190), current_time('mysql')));
            if ($wpdb->rows_affected) $new = true;
        }
        if ($new) wp_schedule_single_event(time() + 60, self::CRON); // WordPress verwirft Doppelte innerhalb von 10 Minuten
    }

    private static function due_sql($col) {
        global $wpdb;
        return $wpdb->prepare("($col IS NULL OR ($col < %s AND " . ($col === 'spotify_at' ? 'spotify' : 'apple_music') . " = ''))", gmdate('Y-m-d H:i:s', current_time('timestamp') - self::RETRY * DAY_IN_SECONDS));
    }

    /** Lauf: je Dienst höchstens BATCH offene Künstler. */
    public static function cron() {
        global $wpdb;
        if (!self::ready()) return ['apple' => 0, 'spotify' => 0];
        // Einmalig: Line-ups aller kommenden Events vormerken (bestehende Events vor diesem Update)
        if (!get_option('tix_lineup_links_backfill')) {
            $ids = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1000, 'fields' => 'ids', 'no_found_rows' => true,
                'meta_query' => [['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']]]);
            foreach ($ids as $eid) self::enqueue_event($eid);
            update_option('tix_lineup_links_backfill', current_time('mysql'), false);
        }
        $done = ['apple' => 0, 'spotify' => 0];
        if (!get_transient('tix_lineup_backoff_apple')) {
            foreach ((array) $wpdb->get_results("SELECT * FROM " . self::t() . " WHERE " . self::due_sql('apple_at') . " ORDER BY id LIMIT " . self::BATCH) as $r) {
                $url = self::find_apple($r->name);
                if ($url === false) break; // Rate-Limit/Fehler: später weiter
                $wpdb->update(self::t(), ['apple_music' => $url, 'apple_at' => current_time('mysql')], ['id' => $r->id]);
                $done['apple']++;
            }
        }
        if (self::spotify_creds() && !get_transient('tix_lineup_backoff_spotify')) {
            foreach ((array) $wpdb->get_results("SELECT * FROM " . self::t() . " WHERE " . self::due_sql('spotify_at') . " ORDER BY id LIMIT " . self::BATCH) as $r) {
                $url = self::find_spotify($r->name);
                if ($url === false) break;
                $wpdb->update(self::t(), ['spotify' => $url, 'spotify_at' => current_time('mysql')], ['id' => $r->id]);
                $done['spotify']++;
            }
        }
        if (($done['apple'] || $done['spotify']) && class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        // Noch offen → in einer Minute weiter (ohne auf den Stunden-Takt zu warten)
        $left = intval($wpdb->get_var("SELECT COUNT(*) FROM " . self::t() . " WHERE " . self::due_sql('apple_at') . (self::spotify_creds() ? " OR " . self::due_sql('spotify_at') : '')));
        if ($left > 0 && ($done['apple'] >= self::BATCH || $done['spotify'] >= self::BATCH)) wp_schedule_single_event(time() + 70, self::CRON);
        return $done;
    }

    private static function http_get($url, $args, $service) {
        $res = wp_remote_get($url, $args + ['timeout' => 8, 'user-agent' => 'Tixomat/' . (defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '1')]);
        if (is_wp_error($res)) return false;
        $code = intval(wp_remote_retrieve_response_code($res));
        if ($code === 429 || $code >= 500 || $code === 403) {
            $wait = max(60, intval(wp_remote_retrieve_header($res, 'retry-after')) ?: 600);
            set_transient('tix_lineup_backoff_' . $service, 1, $wait);
            return false;
        }
        if ($code === 401 && $service === 'spotify') { delete_transient('tix_spotify_token'); return false; }
        if ($code !== 200) return null;
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        return is_array($j) ? $j : null;
    }

    /** URL bei passendem Namen, '' ohne Treffer, false bei Fehler/Rate-Limit. */
    public static function find_apple($name) {
        $j = self::http_get(add_query_arg(['term' => rawurlencode(self::query($name)), 'entity' => 'musicArtist', 'country' => 'DE', 'limit' => 5], 'https://itunes.apple.com/search'), [], 'apple');
        if ($j === false) return false;
        $key = self::norm($name);
        foreach ((array) ($j['results'] ?? []) as $a) {
            if (self::norm($a['artistName'] ?? '') === $key && !empty($a['artistLinkUrl'])) {
                $u = strtok((string) $a['artistLinkUrl'], '?');
                return preg_match('#^https://music\.apple\.com/#', $u) ? esc_url_raw($u) : '';
            }
        }
        return '';
    }

    private static function spotify_creds() {
        $id = trim((string) get_option('tix_spotify_client_id', ''));
        $secret = trim((string) get_option('tix_spotify_client_secret', ''));
        return ($id !== '' && $secret !== '') ? [$id, $secret] : null;
    }

    private static function spotify_token() {
        $t = get_transient('tix_spotify_token');
        if ($t) return $t;
        $c = self::spotify_creds();
        if (!$c) return '';
        $res = wp_remote_post('https://accounts.spotify.com/api/token', [
            'timeout' => 8, 'headers' => ['Authorization' => 'Basic ' . base64_encode($c[0] . ':' . $c[1])], 'body' => ['grant_type' => 'client_credentials'],
        ]);
        if (is_wp_error($res) || intval(wp_remote_retrieve_response_code($res)) !== 200) {
            set_transient('tix_lineup_backoff_spotify', 1, 15 * MINUTE_IN_SECONDS);
            return '';
        }
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if (empty($j['access_token'])) return '';
        set_transient('tix_spotify_token', (string) $j['access_token'], max(60, intval($j['expires_in'] ?? 3600) - 120));
        return (string) $j['access_token'];
    }

    public static function find_spotify($name) {
        $token = self::spotify_token();
        if ($token === '') return false;
        $j = self::http_get(add_query_arg(['type' => 'artist', 'q' => rawurlencode(self::query($name)), 'market' => 'DE', 'limit' => 5], 'https://api.spotify.com/v1/search'), ['headers' => ['Authorization' => 'Bearer ' . $token]], 'spotify');
        if ($j === false) return false;
        $key = self::norm($name);
        foreach ((array) ($j['artists']['items'] ?? []) as $a) {
            $u = (string) ($a['external_urls']['spotify'] ?? '');
            if (self::norm($a['name'] ?? '') === $key && preg_match('#^https://open\.spotify\.com/artist/[A-Za-z0-9]+$#', $u)) return $u;
        }
        return '';
    }

    // ── Ausgabe ──

    private static function auto_rows($keys) {
        global $wpdb;
        if (!$keys || !self::ready()) return [];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT name_key, spotify, apple_music, spotify_at, apple_at, photo_id FROM " . self::t() . " WHERE name_key IN (" . implode(',', array_fill(0, count($keys), '%s')) . ")", ...$keys));
        $out = [];
        foreach ((array) $rows as $r) $out[$r->name_key] = $r;
        return $out;
    }

    private static function overrides($event_id) {
        $o = get_post_meta($event_id, self::META, true);
        return is_array($o) ? $o : [];
    }

    /** Je Zeile: name, artist, spotify, apple_music, source; $editor = zusätzlich auto. */
    public static function for_event($event_id, $editor = false) {
        $lines = self::lines($event_id);
        if (!$lines) return [];
        $keys = [];
        foreach ($lines as $l) $keys[] = self::norm(self::artist($l));
        $auto = self::auto_rows(array_values(array_unique(array_filter($keys))));
        $ov = self::overrides($event_id);
        $photos = self::photos($event_id);
        $out = [];
        foreach ($lines as $i => $line) {
            $k = $keys[$i];
            $a = $auto[$k] ?? null;
            $auto_vals = ['spotify' => ($a && $a->spotify !== '') ? $a->spotify : null, 'apple_music' => ($a && $a->apple_music !== '') ? $a->apple_music : null];
            $o = $ov[$k] ?? null;
            $src = (is_array($o) && in_array($o['source'] ?? '', ['manual', 'none'], true)) ? $o['source'] : 'auto';
            $row = ['name' => $line, 'artist' => self::artist($line)];
            if ($src === 'none') { $row['spotify'] = ''; $row['apple_music'] = ''; }
            elseif ($src === 'manual') { $row['spotify'] = (string) ($o['spotify'] ?? ''); $row['apple_music'] = (string) ($o['apple_music'] ?? ''); }
            else { $row['spotify'] = $auto_vals['spotify']; $row['apple_music'] = $auto_vals['apple_music']; }
            $row['source'] = $src;
            // Künstlerfoto: im Event gesetzt, sonst das zuletzt gesetzte des Künstlers
            $pid = isset($photos[$k]) ? intval($photos[$k]) : (($a && !empty($a->photo_id)) ? intval($a->photo_id) : 0);
            $big = $pid ? wp_get_attachment_image_url($pid, 'large') : '';
            $row['picture'] = $big ?: null;
            $row['picture_thumb'] = $big ? (wp_get_attachment_image_url($pid, 'medium') ?: $big) : null;
            if ($editor) { $row['auto'] = $auto_vals; $row['photo_id'] = $big ? $pid : 0; }
            $out[] = $row;
        }
        return $out;
    }

    /** Ein Link je Zeile für die Website: Künstlerseite (Spotify vor Apple Music), sonst Suche wie bisher, '' = kein Link. */
    public static function web_links($event_id) {
        $out = [];
        foreach (self::for_event($event_id) as $r) {
            if ($r['source'] === 'none')       $u = '';
            elseif (!empty($r['spotify']))     $u = $r['spotify'];
            elseif (!empty($r['apple_music'])) $u = $r['apple_music'];
            else                               $u = 'https://open.spotify.com/search/' . rawurlencode(self::query($r['artist']));
            $out[$r['name']] = $u;
        }
        return $out;
    }

    // ── Korrektur ──

    /** Künstlerlink prüfen: '' bleibt '', sonst URL ohne Parameter oder WP_Error. */
    private static function clean_url($svc, $v) {
        $v = trim((string) $v);
        if ($v === '') return '';
        $re = $svc === 'spotify' ? '#^https://open\.spotify\.com/(intl-[a-z-]+/)?artist/[A-Za-z0-9]+#' : '#^https://music\.apple\.com/[a-z]{2}/artist/#';
        if (!preg_match($re, $v)) return new WP_Error('tix_invalid_field', $svc === 'spotify' ? 'Bitte einen Spotify-Künstlerlink (https://open.spotify.com/artist/…) angeben.' : 'Bitte einen Apple-Music-Künstlerlink (https://music.apple.com/de/artist/…) angeben.', ['status' => 400, 'field' => 'lineup_links.' . $svc]);
        return esc_url_raw(strtok($v, '?'));
    }

    /** [{name|artist, spotify, apple_music, source}] übernehmen; nicht genannte Zeilen bleiben unverändert. */
    public static function apply($event_id, $list) {
        if (!is_array($list)) return new WP_Error('tix_invalid_field', 'lineup_links muss eine Liste sein.', ['status' => 400, 'field' => 'lineup_links']);
        $ov = self::overrides($event_id);
        foreach ($list as $row) {
            if (!is_array($row)) continue;
            $name = trim((string) ($row['artist'] ?? '')) !== '' ? (string) $row['artist'] : self::artist((string) ($row['name'] ?? ''));
            $k = self::norm($name);
            if ($k === '') continue;
            if (array_key_exists('photo_id', $row)) {
                $ph = self::set_photo($event_id, $name, intval($row['photo_id']));
                if (is_wp_error($ph)) return $ph;
            }
            if (!array_key_exists('source', $row) && !array_key_exists('spotify', $row) && !array_key_exists('apple_music', $row)) continue; // nur Foto
            $src = (string) ($row['source'] ?? 'manual');
            if ($src === 'none') { $ov[$k] = ['source' => 'none']; continue; }
            if ($src !== 'manual') { unset($ov[$k]); continue; } // auto (oder unbekannt): Korrektur entfernen
            $sp = self::clean_url('spotify', $row['spotify'] ?? '');
            if (is_wp_error($sp)) return $sp;
            $am = self::clean_url('apple_music', $row['apple_music'] ?? '');
            if (is_wp_error($am)) return $am;
            $ov[$k] = ['source' => 'manual', 'spotify' => $sp, 'apple_music' => $am];
        }
        if ($ov) update_post_meta($event_id, self::META, $ov); else delete_post_meta($event_id, self::META);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return true;
    }

    // ── Künstlerfoto ──

    public static function photos($event_id) {
        $p = get_post_meta($event_id, self::META_PHOTOS, true);
        return is_array($p) ? $p : [];
    }

    /** Foto eines Acts im Event setzen (0 = entfernen); merkt es auch als Vorgabe für den Künstler. */
    public static function set_photo($event_id, $name, $attachment_id) {
        global $wpdb;
        $k = self::norm(self::artist($name));
        if ($k === '') return new WP_Error('tix_invalid_field', 'Unbekannter Künstler.', ['status' => 400, 'field' => 'lineup_links.name']);
        $att = intval($attachment_id);
        if ($att && (get_post_type($att) !== 'attachment' || strpos((string) get_post_mime_type($att), 'image/') !== 0)) {
            return new WP_Error('tix_invalid_field', 'Bitte ein Bild aus der Mediathek wählen.', ['status' => 400, 'field' => 'lineup_links.photo_id']);
        }
        $photos = self::photos($event_id);
        $prev = intval($photos[$k] ?? 0);
        $photos[$k] = $att; // 0 = in diesem Event bewusst ohne Foto (keine Vorgabe aus anderen Events)
        update_post_meta($event_id, self::META_PHOTOS, $photos);
        self::maybe_install();
        if ($att) {
            $wpdb->query($wpdb->prepare("INSERT INTO " . self::t() . " (name_key, name, photo_id, created) VALUES (%s, %s, %d, %s) ON DUPLICATE KEY UPDATE photo_id = VALUES(photo_id)", $k, mb_substr(self::artist($name), 0, 190), $att, current_time('mysql')));
        } elseif ($prev) {
            // falsches Foto: auch als Vorgabe für andere Events entfernen, wenn es von hier stammte
            $wpdb->query($wpdb->prepare("UPDATE " . self::t() . " SET photo_id = 0 WHERE name_key = %s AND photo_id = %d", $k, $prev));
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return true;
    }

    public static function register_routes() {
        register_rest_route('tixomat/v1', '/public/events/(?P<id>\d+)/artists', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_artists'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route('tixomat/v1', '/events/(?P<id>\d+)/lineup-photo', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_upload'], 'permission_callback' => ['TIX_REST_API', 'check_organizer'],
        ]);
    }

    /**
     * Öffentlich: Künstler des Line-ups mit Foto, Links und `top` = Apple Music Top-Songs (TIX_Apple_Music, nur aus dem
     * Zwischenspeicher; fehlt etwas, holt es ein Hintergrundlauf). deezer_url bleibt null (Deezer nur nicht-kommerziell).
     */
    public static function rest_artists(WP_REST_Request $req) {
        $id = intval($req['id']);
        $public = class_exists('TIX_Public_Events') ? TIX_Public_Events::payload($id, false) : null;
        if (!$public) return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        $out = [];
        $missing = false;
        $am = class_exists('TIX_Apple_Music');
        foreach (self::for_event($id) as $r) {
            $out[] = ['name' => $r['name'], 'artist' => $r['artist'], 'picture' => $r['picture'], 'picture_thumb' => $r['picture_thumb'],
                'deezer_url' => null, 'top' => $am ? TIX_Apple_Music::top_for($r, $missing) : [], 'spotify' => $r['spotify'], 'apple_music' => $r['apple_music'], 'source' => $r['source']];
        }
        if ($missing) TIX_Apple_Music::kick();
        return rest_ensure_response(['ok' => true, 'event_id' => $id, 'artists' => $out]);
    }

    /** POST /events/{id}/lineup-photo (multipart: name, file) → Foto hochladen und dem Act zuordnen. */
    public static function rest_upload(WP_REST_Request $req) {
        $id = absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        $name = trim((string) $req->get_param('name'));
        if ($name === '' || self::norm(self::artist($name)) === '') return new WP_Error('tix_invalid_field', 'Bitte den Künstler angeben.', ['status' => 400, 'field' => 'name']);
        $files = $req->get_file_params();
        $file = $files['file'] ?? ($files['image'] ?? null);
        if (empty($file) || empty($file['tmp_name']) || !empty($file['error'])) return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400]);
        if (intval($file['size'] ?? 0) > 10 * MB_IN_BYTES) return new WP_Error('too_large', 'Das Bild ist zu groß (max. 10 MB).', ['status' => 400]);
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'] ?? 'foto.jpg');
        $mime = $check['type'] ?: '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return new WP_Error('bad_type', 'Bitte ein JPG-, PNG- oder WebP-Bild wählen.', ['status' => 400]);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $att = media_handle_sideload(['name' => sanitize_file_name('artist-' . sanitize_title(self::artist($name)) . '-' . time() . '.' . $ext), 'tmp_name' => $file['tmp_name'], 'type' => $mime, 'size' => $file['size'] ?? 0], $id, self::artist($name));
        if (is_wp_error($att)) return new WP_Error('upload_failed', $att->get_error_message(), ['status' => 500]);
        $r = self::set_photo($id, $name, intval($att));
        if (is_wp_error($r)) return $r;
        $row = null;
        foreach (self::for_event($id, true) as $x) if (self::norm($x['artist']) === self::norm(self::artist($name))) $row = $x;
        return rest_ensure_response(['ok' => true, 'photo_id' => intval($att), 'artist' => $row]);
    }

    // ── WP-Admin ──

    public static function render_box($post) {
        $rows = self::for_event($post->ID, true);
        wp_nonce_field('tix_lineup_links', 'tix_lineup_links_nonce');
        if (!$rows) {
            echo '<p class="description">Keine Künstler erkannt. Im Line-up je Zeile ein Name (z. B. „Floor 1: DJ Name“); Fließtext wird nicht verlinkt.</p>';
            return;
        }
        echo '<p class="description">Links werden automatisch gesucht (Apple Music immer, Spotify mit Zugangsdaten unter Tixomat → Künstler-Links). Felder leer = automatisch; eigener Link = festhalten; „Kein Link“ = nichts anzeigen.</p>';
        wp_enqueue_media();
        echo '<table class="widefat striped"><thead><tr><th>Foto</th><th>Künstler</th><th>Spotify</th><th>Apple Music</th><th>Kein Link</th></tr></thead><tbody>';
        foreach ($rows as $i => $r) {
            $manual = $r['source'] === 'manual';
            echo '<tr><td style="width:72px;"><span class="tix-ll-photo" style="display:block;width:56px;height:56px;border-radius:50%;background:#f0f0f1 center/cover no-repeat;' . ($r['picture_thumb'] ? 'background-image:url(' . esc_url($r['picture_thumb']) . ');' : '') . '"></span>'
                . '<input type="hidden" name="tix_ll[' . intval($i) . '][photo_id]" value="' . intval($r['photo_id']) . '"><input type="hidden" name="tix_ll[' . intval($i) . '][photo_orig]" value="' . intval($r['photo_id']) . '">'
                . '<a href="#" class="tix-ll-pick" style="font-size:12px;">' . ($r['picture'] ? 'Ändern' : 'Foto') . '</a>' . ($r['picture'] ? ' · <a href="#" class="tix-ll-clear" style="font-size:12px;">weg</a>' : '') . '</td>';
            echo '<td><strong>' . esc_html($r['artist']) . '</strong><input type="hidden" name="tix_ll[' . intval($i) . '][name]" value="' . esc_attr($r['name']) . '"></td>';
            foreach (['spotify', 'apple_music'] as $svc) {
                $ph = $r['auto'][$svc] ? 'automatisch: ' . $r['auto'][$svc] : 'automatisch (noch kein Treffer)';
                echo '<td><input type="url" class="large-text" name="tix_ll[' . intval($i) . '][' . $svc . ']" value="' . esc_attr($manual ? $r[$svc] : '') . '" placeholder="' . esc_attr($ph) . '"></td>';
            }
            echo '<td><input type="checkbox" name="tix_ll[' . intval($i) . '][none]" value="1" ' . checked($r['source'], 'none', false) . ' aria-label="Kein Link für ' . esc_attr($r['artist']) . '"></td></tr>';
        }
        echo '</tbody></table>';
        echo "<script>jQuery(function($){ $(document).on('click','.tix-ll-pick',function(e){ e.preventDefault(); var td=$(this).closest('td'); var f=wp.media({title:'Künstlerfoto',library:{type:'image'},multiple:false}); f.on('select',function(){ var a=f.state().get('selection').first().toJSON(); td.find('input').val(a.id); var u=(a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url); td.find('.tix-ll-photo').css('background-image','url('+u+')'); }); f.open(); }); $(document).on('click','.tix-ll-clear',function(e){ e.preventDefault(); var td=$(this).closest('td'); td.find('input').val(0); td.find('.tix-ll-photo').css('background-image','none'); }); });</script>";
        echo '<p class="description">Künstlerfoto: wird in den Apps auf der Künstler-Karte gezeigt und gilt auch für andere Events mit diesem Künstler, bis dort ein eigenes gesetzt ist. Nur Bilder verwenden, an denen ihr die Rechte habt (z. B. Pressefotos der Künstler).</p>';
    }

    public static function save_box($post_id, $post) {
        if (!isset($_POST['tix_lineup_links_nonce']) || !wp_verify_nonce($_POST['tix_lineup_links_nonce'], 'tix_lineup_links')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $list = [];
        foreach ((array) wp_unslash($_POST['tix_ll'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $sp = self::clean_url('spotify', $row['spotify'] ?? '');
            $am = self::clean_url('apple_music', $row['apple_music'] ?? '');
            $sp = is_wp_error($sp) ? '' : $sp; // ungültige Eingabe im Admin: verworfen
            $am = is_wp_error($am) ? '' : $am;
            $src = !empty($row['none']) ? 'none' : (($sp !== '' || $am !== '') ? 'manual' : 'auto');
            $item = ['name' => (string) ($row['name'] ?? ''), 'spotify' => $sp, 'apple_music' => $am, 'source' => $src];
            if (isset($row['photo_id']) && intval($row['photo_id']) !== intval($row['photo_orig'] ?? -1)) $item['photo_id'] = max(0, intval($row['photo_id'])); // nur geänderte Fotos
            $list[] = $item;
        }
        self::apply($post_id, $list);
    }

    public static function menu() {
        add_submenu_page('tixomat', 'Künstler-Links', 'Künstler-Links', 'manage_options', 'tix-lineup-links', [__CLASS__, 'render_admin']);
    }

    public static function save_admin() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_lineup_links_admin');
        if (isset($_POST['run'])) {
            delete_transient('tix_lineup_backoff_apple'); delete_transient('tix_lineup_backoff_spotify');
            self::cron();
        } else {
            update_option('tix_spotify_client_id', sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')), false);
            $secret = sanitize_text_field(wp_unslash($_POST['client_secret'] ?? ''));
            if ($secret !== '') update_option('tix_spotify_client_secret', $secret, false);
            if (!empty($_POST['clear_secret'])) delete_option('tix_spotify_client_secret');
            delete_transient('tix_spotify_token'); delete_transient('tix_lineup_backoff_spotify');
        }
        wp_safe_redirect(admin_url('admin.php?page=tix-lineup-links&saved=1'));
        exit;
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        self::maybe_install();
        $t = self::t();
        $n = intval($wpdb->get_var("SELECT COUNT(*) FROM $t"));
        $sp = intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE spotify <> ''"));
        $ap = intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE apple_music <> ''"));
        $open = intval($wpdb->get_var("SELECT COUNT(*) FROM $t WHERE apple_at IS NULL" . (self::spotify_creds() ? " OR spotify_at IS NULL" : '')));
        ?>
        <div class="wrap"><h1>Künstler-Links</h1>
            <?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div><?php endif; ?>
            <p>„Musik hören“ im Line-up führt direkt auf die Künstlerseite. Künstler: <strong><?php echo $n; ?></strong> · mit Spotify: <strong><?php echo $sp; ?></strong> · mit Apple Music: <strong><?php echo $ap; ?></strong> · noch offen: <strong><?php echo $open; ?></strong></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tix_lineup_links_admin'); ?><input type="hidden" name="action" value="tix_lineup_links">
                <h2>Spotify-Zugang (Client Credentials)</h2>
                <p class="description">Ohne Zugang gibt es nur Apple-Music-Links; Spotify bleibt dann eine Suche.</p>
                <table class="form-table" role="presentation">
                    <tr><th><label for="tix_sp_id">Client ID</label></th><td><input type="text" class="regular-text" id="tix_sp_id" name="client_id" value="<?php echo esc_attr(get_option('tix_spotify_client_id', '')); ?>" autocomplete="off"></td></tr>
                    <tr><th><label for="tix_sp_secret">Client Secret</label></th><td><input type="password" class="regular-text" id="tix_sp_secret" name="client_secret" value="" placeholder="<?php echo get_option('tix_spotify_client_secret') ? 'gespeichert – leer lassen zum Behalten' : ''; ?>" autocomplete="new-password">
                        <?php if (get_option('tix_spotify_client_secret')) : ?><label><input type="checkbox" name="clear_secret" value="1"> löschen</label><?php endif; ?></td></tr>
                </table>
                <p><button class="button button-primary">Speichern</button> <button class="button" name="run" value="1">Offene Künstler jetzt suchen</button></p>
            </form>
            <?php if (class_exists('TIX_Apple_Music')) TIX_Apple_Music::render_admin(); ?>
        </div>
        <?php
    }
}
