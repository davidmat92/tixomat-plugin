<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Apple_Music – „Bekannt für“: die drei meistgestreamten Titel je Act (Apple Music Top-Songs, 1.38.428, alle Seiten).
 *
 * Quelle: Apple Music API `GET /v1/catalog/de/artists/{id}/view/top-songs` mit Developer-Token (ES256-JWT,
 * MusicKit-Schlüssel – gleiches Verfahren wie der APNs-JWT). Optionen wie bei APNs (der User trägt sie ein):
 *   _tix_apple_music_key     Inhalt der .p8-Datei (wird nie angezeigt)
 *   _tix_apple_music_key_id  Key-ID
 *   _tix_apple_music_team_id Team-ID (leer = _tix_apns_team_id)
 * Ohne Schlüssel bleibt `top` leer, kein Fehler.
 *
 * Künstler-ID: aus dem Apple-Music-Link der Line-up-Zeile (manuelle Korrektur im Kasten „Künstler-Links“ schlägt den
 * automatischen Treffer), sonst Katalogsuche nach dem Namen (nur bei gleichem Namen, 30 Tage gemerkt).
 * source none oder „manuell ohne Apple-Link“ → keine Titel.
 *
 * Abruf nur im Hintergrund (Cron stündlich, kommende Events, je Lauf höchstens BUDGET Anfragen; fehlt etwas, stößt
 * die Route einen Lauf an). Je Künstler-ID etwa 24 h zwischengespeichert. Gespeichert werden nur die Adressen –
 * Hörproben und Cover lädt das Gerät direkt von Apple, nichts läuft über den eigenen Server.
 *
 * Format je Titel (meistgestreamt zuerst, höchstens 3, nur mit Hörprobe):
 *   {title, preview (previews[0].url), link (attributes.url), artwork (artwork.url mit 200x200), year (int|null)}
 */
class TIX_Apple_Music {

    const API      = 'https://api.music.apple.com/v1/catalog/de';
    const CRON     = 'tix_apple_music_cron';
    const TTL      = DAY_IN_SECONDS;      // Rangliste gilt als frisch
    const KEEP     = 7 * DAY_IN_SECONDS;  // so lange bleibt sie lesbar (falls Apple nicht erreichbar ist)
    const BUDGET   = 40;                  // Anfragen je Lauf
    const MAX_EVENTS = 300;

    public static function init() {
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Apple_Music::CRON)) wp_schedule_event(time() + 900, 'hourly', TIX_Apple_Music::CRON);
        }, 25);
        add_action('admin_post_tix_apple_music', [__CLASS__, 'save_admin']);
    }

    // ── Zugang ──

    public static function config() {
        $team = trim((string) get_option('_tix_apple_music_team_id', ''));
        if ($team === '') $team = trim((string) get_option('_tix_apns_team_id', ''));
        return [
            'key'     => trim((string) get_option('_tix_apple_music_key', '')),
            'key_id'  => trim((string) get_option('_tix_apple_music_key_id', '')),
            'team_id' => $team,
        ];
    }

    public static function configured() {
        $c = self::config();
        return $c['key'] !== '' && $c['key_id'] !== '' && $c['team_id'] !== '';
    }

    /** Developer-Token (ES256), 12 h gültig, im Transient. '' = kein bzw. unbrauchbarer Schlüssel. */
    public static function token() {
        if (!self::configured()) return '';
        $cache = get_transient('_tix_apple_music_jwt');
        if ($cache) return $cache;
        $c = self::config();
        $now = time();
        $header = self::b64url(wp_json_encode(['alg' => 'ES256', 'kid' => $c['key_id']]));
        $claims = self::b64url(wp_json_encode(['iss' => $c['team_id'], 'iat' => $now, 'exp' => $now + 12 * HOUR_IN_SECONDS]));
        $input  = $header . '.' . $claims;
        $pkey = @openssl_pkey_get_private($c['key']);
        if (!$pkey) return '';
        $der = '';
        if (!openssl_sign($input, $der, $pkey, OPENSSL_ALGO_SHA256)) return '';
        $sig = self::der_to_raw_ecdsa($der, 64);
        if ($sig === '') return '';
        $jwt = $input . '.' . self::b64url($sig);
        set_transient('_tix_apple_music_jwt', $jwt, 11 * HOUR_IN_SECONDS);
        return $jwt;
    }

    private static function b64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** OpenSSL liefert ECDSA als DER-Sequenz; JWT erwartet rohes R||S (wie TIX_Notifications). */
    private static function der_to_raw_ecdsa($der, $len) {
        $pos = 0;
        if (($der[$pos++] ?? '') !== "\x30") return '';
        $b = ord($der[$pos++]);
        if ($b & 0x80) $pos += ($b & 0x7f);
        $read = function () use ($der, &$pos) {
            if (($der[$pos++] ?? '') !== "\x02") return null;
            $l = ord($der[$pos++]);
            $v = substr($der, $pos, $l);
            $pos += $l;
            return ltrim($v, "\x00");
        };
        $r = $read();
        $s = $read();
        if ($r === null || $s === null) return '';
        $half = $len / 2;
        return str_pad($r, $half, "\x00", STR_PAD_LEFT) . str_pad($s, $half, "\x00", STR_PAD_LEFT);
    }

    private static function status($code, $msg) {
        update_option('tix_apple_music_status', ['at' => current_time('mysql'), 'code' => intval($code), 'msg' => (string) $msg], false);
    }

    /** API-Aufruf: Daten, null (nichts gefunden/ungültig) oder false (Fehler/Limit → Lauf abbrechen). */
    private static function api($path, array $args = []) {
        if (get_transient('tix_apple_music_backoff')) return false;
        $jwt = self::token();
        if ($jwt === '') { self::status(0, 'Schlüssel unbrauchbar (.p8, Key-ID oder Team-ID prüfen).'); return false; }
        $res = wp_remote_get(self::API . $path . ($args ? '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986) : ''), [
            'timeout' => 8,
            'headers' => ['Authorization' => 'Bearer ' . $jwt, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($res)) return false;
        $code = intval(wp_remote_retrieve_response_code($res));
        if ($code === 401 || $code === 403) {
            delete_transient('_tix_apple_music_jwt');
            set_transient('tix_apple_music_backoff', 1, HOUR_IN_SECONDS);
            self::status($code, 'Apple lehnt den Schlüssel ab (MusicKit-Schlüssel, Key-ID und Team-ID prüfen).');
            return false;
        }
        if ($code === 429 || $code >= 500) {
            $wait = max(60, intval(wp_remote_retrieve_header($res, 'retry-after')) ?: 600);
            set_transient('tix_apple_music_backoff', 1, $wait);
            self::status($code, $code === 429 ? 'Apple-Limit erreicht, später weiter.' : 'Apple nicht erreichbar, später weiter.');
            return false;
        }
        if ($code === 404) return null;
        if ($code !== 200) { self::status($code, 'Unerwartete Antwort von Apple.'); return null; }
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($j)) return null;
        self::status(200, 'OK');
        return $j;
    }

    // ── Künstler-ID ──

    /** Apple-Künstler-ID aus einem Apple-Music-Link (…/artist/name/123 oder …/artist/123). */
    public static function id_from_url($url) {
        return preg_match('#^https://music\.apple\.com/[a-z]{2}/artist/(?:[^/?\#]+/)?(\d+)#', (string) $url, $m) ? $m[1] : '';
    }

    /** Für eine Line-up-Zeile: ['id' => '123'] bekannt, ['search' => name] Suche nötig, null = keine Titel. */
    private static function target(array $row) {
        if (($row['source'] ?? 'auto') === 'none') return null;
        $url = (string) ($row['apple_music'] ?? '');
        if ($url !== '') {
            $id = self::id_from_url($url);
            return $id !== '' ? ['id' => $id] : null;
        }
        if (($row['source'] ?? 'auto') === 'manual') return null; // Team will hier keinen Apple-Link
        $key = TIX_Lineup_Links::norm($row['artist'] ?? $row['name'] ?? '');
        if ($key === '') return null;
        $hit = get_transient('tix_am_artist_' . md5($key));
        if (is_array($hit)) return $hit['id'] !== '' ? ['id' => $hit['id']] : null;
        return ['search' => (string) ($row['artist'] ?? $row['name']), 'key' => $key];
    }

    /** Katalogsuche; übernommen wird nur ein Treffer mit gleichem Namen. false bei Fehler. */
    private static function search_artist($name, $key) {
        $j = self::api('/search', ['types' => 'artists', 'term' => TIX_Lineup_Links::query($name), 'limit' => 5]);
        if ($j === false) return false;
        $id = '';
        foreach ((array) ($j['results']['artists']['data'] ?? []) as $a) {
            if (TIX_Lineup_Links::norm($a['attributes']['name'] ?? '') === $key && preg_match('/^\d+$/', (string) ($a['id'] ?? ''))) { $id = (string) $a['id']; break; }
        }
        set_transient('tix_am_artist_' . md5($key), ['id' => $id], ($id !== '' ? 30 : 7) * DAY_IN_SECONDS);
        return $id;
    }

    // ── Rangliste ──

    /** Apple-Antwort → höchstens 3 Titel im App-Format (nur mit Hörprobe). */
    public static function map_songs($data) {
        $out = [];
        foreach ((array) $data as $s) {
            $a = (array) ($s['attributes'] ?? []);
            $title = trim((string) ($a['name'] ?? ''));
            $preview = (string) ($a['previews'][0]['url'] ?? '');
            if ($title === '' || strpos($preview, 'https://') !== 0) continue;
            $link = (string) ($a['url'] ?? '');
            $art = str_replace(['{w}', '{h}'], ['200', '200'], (string) ($a['artwork']['url'] ?? ''));
            $year = preg_match('/^(\d{4})/', (string) ($a['releaseDate'] ?? ''), $m) ? intval($m[1]) : null;
            $out[] = [
                'title'   => $title,
                'preview' => esc_url_raw($preview),
                'link'    => strpos($link, 'https://') === 0 ? esc_url_raw($link) : '',
                'artwork' => strpos($art, 'https://') === 0 ? esc_url_raw($art) : '',
                'year'    => $year,
            ];
            if (count($out) >= 3) break;
        }
        return $out;
    }

    private static function cached($id) {
        $c = get_transient('tix_am_top_' . $id);
        return is_array($c) ? $c : null;
    }

    private static function fetch_top($id) {
        $j = self::api('/artists/' . rawurlencode($id) . '/view/top-songs', ['limit' => 5]);
        if ($j === false) return false;
        $top = is_array($j) ? self::map_songs($j['data'] ?? []) : [];
        set_transient('tix_am_top_' . $id, ['at' => time(), 'top' => $top], self::KEEP);
        return true;
    }

    /** `top` für eine Zeile aus for_event() – liest nur den Zwischenspeicher. */
    public static function top_for(array $row, &$missing = false) {
        if (!self::configured()) return [];
        $t = self::target($row);
        if (!$t) return [];
        if (isset($t['search'])) { $missing = true; return []; }
        $c = self::cached($t['id']);
        if (!$c || $c['at'] < time() - self::TTL) $missing = true;
        return $c ? (array) $c['top'] : [];
    }

    /** Hintergrundlauf gleich anstoßen (fehlende oder veraltete Ranglisten). */
    public static function kick() {
        if (!self::configured() || get_transient('tix_apple_music_backoff')) return;
        if (!wp_next_scheduled(self::CRON, ['now'])) wp_schedule_single_event(time() + 15, self::CRON, ['now']);
    }

    /** Lauf: kommende Events, erst IDs auflösen, dann veraltete Ranglisten holen. */
    public static function cron($why = '') {
        $done = ['search' => 0, 'top' => 0];
        if (!self::configured() || get_transient('tix_apple_music_backoff')) return $done;
        $ids = get_posts(['post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => self::MAX_EVENTS, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']]]);
        $budget = self::BUDGET;
        $seen = [];
        foreach ($ids as $eid) {
            foreach (TIX_Lineup_Links::for_event($eid) as $row) {
                if ($budget <= 0) break 2;
                $t = self::target($row);
                if (!$t) continue;
                if (isset($t['search'])) {
                    if (isset($seen['s:' . $t['key']])) continue;
                    $seen['s:' . $t['key']] = 1;
                    $budget--;
                    $id = self::search_artist($t['search'], $t['key']);
                    if ($id === false) return $done;
                    $done['search']++;
                    if ($id === '') continue;
                    $t = ['id' => $id];
                }
                if (isset($seen['t:' . $t['id']])) continue;
                $seen['t:' . $t['id']] = 1;
                $c = self::cached($t['id']);
                if ($c && $c['at'] >= time() - self::TTL) continue;
                $budget--;
                if (self::fetch_top($t['id']) === false) return $done;
                $done['top']++;
            }
        }
        if ($budget <= 0) wp_schedule_single_event(time() + 70, self::CRON, ['more']);
        return $done;
    }

    // ── Admin (Kasten auf der Seite „Künstler-Links“) ──

    public static function save_admin() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_apple_music_admin');
        $msg = 'saved';
        if (isset($_POST['run'])) {
            delete_transient('tix_apple_music_backoff');
            $d = self::cron('admin');
            $msg = 'run-' . intval($d['search']) . '-' . intval($d['top']);
        } else {
            update_option('_tix_apple_music_team_id', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_POST['team_id'] ?? ''))), false);
            update_option('_tix_apple_music_key_id', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_POST['key_id'] ?? ''))), false);
            $key = trim((string) wp_unslash($_POST['p8'] ?? ''));
            if ($key === '' && !empty($_FILES['p8_file']['tmp_name']) && is_uploaded_file($_FILES['p8_file']['tmp_name']) && intval($_FILES['p8_file']['size']) < 8192) {
                $key = trim((string) file_get_contents($_FILES['p8_file']['tmp_name']));
            }
            if ($key !== '') {
                $pk = @openssl_pkey_get_private($key);
                $det = $pk ? openssl_pkey_get_details($pk) : null;
                if ($det && intval($det['type'] ?? -1) === OPENSSL_KEYTYPE_EC) update_option('_tix_apple_music_key', $key, false);
                else $msg = 'badkey';
            }
            if (!empty($_POST['clear_key'])) delete_option('_tix_apple_music_key');
            delete_transient('_tix_apple_music_jwt'); delete_transient('tix_apple_music_backoff');
        }
        wp_safe_redirect(admin_url('admin.php?page=tix-lineup-links&am=' . rawurlencode($msg)));
        exit;
    }

    public static function render_admin() {
        $c = self::config();
        $st = get_option('tix_apple_music_status');
        $am = sanitize_key($_GET['am'] ?? '');
        ?>
        <?php if ($am === 'badkey') : ?><div class="notice notice-error"><p>Der Schlüssel wurde nicht gespeichert: Bitte den Inhalt der .p8-Datei (MusicKit) verwenden.</p></div><?php endif; ?>
        <?php if (strpos($am, 'run') === 0) : $p = explode('-', $am); ?><div class="notice notice-success is-dismissible"><p>Lauf fertig: <?php echo intval($p[1] ?? 0); ?> Künstler gesucht, <?php echo intval($p[2] ?? 0); ?> Ranglisten geladen.</p></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tix_apple_music_admin'); ?><input type="hidden" name="action" value="tix_apple_music">
            <h2>„Bekannt für“: Apple Music Top-Songs</h2>
            <p class="description">Die Apps zeigen je Act die drei meistgestreamten Titel mit Hörprobe (direkt von Apple). Dafür braucht es einen MusicKit-Schlüssel aus dem Apple-Developer-Konto (Keys → „Media Services“). Ohne Schlüssel zeigen die Apps keine Titel. Falsche Künstler korrigiert ihr über den Apple-Music-Link im Kasten „Künstler-Links“ am Event.</p>
            <table class="form-table" role="presentation">
                <tr><th><label for="tix_am_team">Team-ID</label></th><td><input type="text" class="regular-text" id="tix_am_team" name="team_id" value="<?php echo esc_attr(get_option('_tix_apple_music_team_id', '')); ?>" placeholder="<?php echo esc_attr($c['team_id'] !== '' ? $c['team_id'] . ' (wie Push)' : ''); ?>" autocomplete="off"></td></tr>
                <tr><th><label for="tix_am_kid">Key-ID</label></th><td><input type="text" class="regular-text" id="tix_am_kid" name="key_id" value="<?php echo esc_attr($c['key_id']); ?>" autocomplete="off"></td></tr>
                <tr><th><label for="tix_am_p8">Schlüssel (.p8)</label></th><td>
                    <textarea id="tix_am_p8" name="p8" rows="3" class="large-text code" autocomplete="off" placeholder="<?php echo $c['key'] !== '' ? 'gespeichert – leer lassen zum Behalten' : '-----BEGIN PRIVATE KEY----- …'; ?>"></textarea>
                    <p><input type="file" name="p8_file" accept=".p8"> <?php if ($c['key'] !== '') : ?><label><input type="checkbox" name="clear_key" value="1"> löschen</label><?php endif; ?></p></td></tr>
                <tr><th>Status</th><td><?php
                    if (!self::configured()) echo 'Nicht eingerichtet – die Apps zeigen keine Titel.';
                    elseif (is_array($st)) echo esc_html(($st['code'] === 200 ? '🟢 ' : '🟠 ') . $st['msg'] . ' (' . $st['at'] . ')');
                    else echo 'Eingerichtet, noch kein Abruf.';
                ?></td></tr>
            </table>
            <p><button class="button button-primary">Speichern</button> <?php if (self::configured()) : ?><button class="button" name="run" value="1">Ranglisten jetzt laden</button><?php endif; ?></p>
        </form>
        <?php
    }
}
