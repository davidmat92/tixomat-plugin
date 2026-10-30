<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Font_Mirror – Google Fonts lokal ausliefern (1.38.460, DSGVO: keine IP an Google).
 *
 * Erkennt auf der Website jede eingebundene Schrift von fonts.googleapis.com (Breakdance, Theme, andere Plugins):
 *   - <link rel="stylesheet" href="https://fonts.googleapis.com/css…">, auch per wp_enqueue_style (style_loader_src)
 *   - @import url(https://fonts.googleapis.com/…) in Inline-<style> und in eigenen CSS-Dateien der Seite
 *     (z. B. Breakdance unter uploads/breakdance/…; die Datei wird als Kopie mit lokalem Import ausgeliefert)
 *   - preconnect / dns-prefetch zu fonts.googleapis.com bzw. fonts.gstatic.com wird entfernt
 * Ablauf: einmalig serverseitig herunterladen (CSS + woff2) nach uploads/tix-fonts/<familie>-<hash>.css bzw. …/<hash>/*.woff2,
 * Link auf die lokale Datei umschreiben. Ist eine Schrift noch nicht gespiegelt, fällt der Link weg (Systemschrift, bis der
 * Download im Hintergrund fertig ist); schlägt er fehl, bleibt es bei der Systemschrift + Hinweis im Admin.
 * Seit 1.38.600 (User-Vorgabe 2026-10-30 „Schriften NIE von Google laden, immer vom eigenen Server“) immer an: kein
 * Original-Link mehr als Rückfall, die Option tix_local_fonts schaltet nichts mehr ab. Gilt für die Website, nicht für wp-admin.
 * 1.38.461: gleiche woff2-Datei nur einmal laden (variable Schriften liefern sie für viele Gewichte), nur die
 * Zeichensätze aus tix_font_subsets (Vorgabe latin,latin-ext; 'all' = alle) – andere @font-face-Blöcke fallen aus
 * der lokalen CSS. Grenze 300 Dateien. Fehlversuche sperren nicht dauerhaft: neue Spiegel-Version (REV) startet
 * alle fehlerhaften neu, sonst täglich ein neuer Anlauf nach 3 Fehlversuchen.
 */
class TIX_Font_Mirror {

    /** rewrite_imports: mindestens ein Import war noch nicht gespiegelt (Kopie später neu schreiben) */
    private static $pending = false;

    const OPTION = 'tix_local_fonts';
    const MAP = 'tix_font_mirror';
    const CSS_MAP = 'tix_font_css_copies';
    const CRON = 'tix_font_mirror_fetch';
    const DIR = 'tix-fonts';
    const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    const MAX_FILES = 300;
    const SUBSETS = 'tix_font_subsets';
    const REV = '2';
    const REV_OPTION = 'tix_font_mirror_rev';

    public static function init() {
        add_action(self::CRON, [__CLASS__, 'fetch'], 10, 1);
        add_action('admin_menu', [__CLASS__, 'menu'], 60);
        add_action('admin_post_tix_font_mirror', [__CLASS__, 'handle_admin']);
        add_action('admin_notices', [__CLASS__, 'notice']);
        if (!self::enabled()) return;
        // Neue Spiegel-Version: frühere Fehlversuche (z. B. „zu viele Dateien“ bis 1.38.460) neu starten
        if (get_option(self::REV_OPTION, '') !== self::REV) add_action('init', [__CLASS__, 'retry_failed'], 20);
        add_filter('style_loader_src', [__CLASS__, 'filter_src'], 99, 2);
        add_filter('wp_resource_hints', [__CLASS__, 'filter_hints'], 99, 2);
        add_action('template_redirect', [__CLASS__, 'start_buffer'], -1000);
        add_action('login_init', [__CLASS__, 'start_buffer'], -1000);
    }

    /** Immer an (1.38.600): Besucher laden nie eine Schrift bei Google. */
    public static function enabled() {
        return true;
    }

    private static function map() {
        $m = get_option(self::MAP, []);
        return is_array($m) ? $m : [];
    }

    private static function dir() {
        $u = wp_get_upload_dir();
        return ['path' => trailingslashit($u['basedir']) . self::DIR, 'url' => trailingslashit($u['baseurl']) . self::DIR];
    }

    /** Google-Adresse vereinheitlichen (Entities, Leerzeichen, Protokoll). */
    public static function normalize($url) {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES, 'UTF-8');
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        $url = preg_replace('#^http://#i', 'https://', $url);
        return str_replace(' ', '+', $url);
    }

    public static function is_google($url) {
        return (bool) preg_match('#^(https?:)?//fonts\.googleapis\.com/.#i', html_entity_decode(trim((string) $url), ENT_QUOTES, 'UTF-8'));
    }

    private static function key($url) {
        return substr(md5(self::normalize($url)), 0, 12);
    }

    /** Lokale CSS-Adresse, wenn gespiegelt; sonst null (und Download einplanen). */
    public static function local_url($google_url) {
        $k = self::key($google_url);
        $m = self::map();
        if (!empty($m[$k]['css']) && empty($m[$k]['error'])) {
            $d = self::dir();
            if (is_file($d['path'] . '/' . $m[$k]['css'])) return $d['url'] . '/' . $m[$k]['css'] . '?v=' . intval($m[$k]['at'] ?? 0);
        }
        if (empty($m[$k])) self::queue($google_url);
        elseif (!empty($m[$k]['error']) && intval($m[$k]['tries'] ?? 0) >= 3 && intval($m[$k]['failed_at'] ?? 0) < time() - DAY_IN_SECONDS) self::retry($k);
        return null;
    }

    /** Fehlerhaften Eintrag neu anstoßen (Zähler zurück, Download gleich einplanen). */
    private static function retry($k) {
        $m = self::map();
        if (empty($m[$k]['src'])) return;
        $m[$k] = ['src' => $m[$k]['src'], 'queued' => time(), 'last_error' => (string) ($m[$k]['error'] ?? '')];
        update_option(self::MAP, $m, false);
        if (!wp_next_scheduled(self::CRON, [$k])) wp_schedule_single_event(time(), self::CRON, [$k]);
    }

    /** Alle fehlerhaften Einträge neu starten (einmal je Spiegel-Version). */
    public static function retry_failed() {
        update_option(self::REV_OPTION, self::REV, true);
        foreach (self::map() as $k => $row) if (!empty($row['error'])) self::retry($k);
    }

    /** Gewünschte Zeichensätze ([] = alle). */
    public static function subsets() {
        $v = strtolower(trim((string) get_option(self::SUBSETS, 'latin,latin-ext')));
        if ($v === '' || $v === 'all') return [];
        return array_values(array_filter(array_map('trim', explode(',', $v))));
    }

    /** @font-face-Blöcke anderer Zeichensätze entfernen (Google kennzeichnet sie mit einem Kommentar wie „latin“). Blöcke ohne Kennzeichnung bleiben; bliebe nichts übrig, bleibt alles. */
    public static function filter_subsets($css) {
        $want = self::subsets();
        if (!$want || !preg_match_all('#(?:/\*\s*([^*]*?)\s*\*/\s*)?(@font-face\s*\{[^}]*\})#i', $css, $m, PREG_SET_ORDER)) return $css;
        $keep = [];
        $hit = false;
        foreach ($m as $b) {
            $label = strtolower(trim((string) $b[1]));
            if ($label === '') { $keep[] = $b[2]; continue; }
            if (in_array($label, $want, true)) { $keep[] = '/* ' . $label . " */\n" . $b[2]; $hit = true; }
        }
        return $hit ? implode("\n", $keep) . "\n" : $css;
    }

    private static function queue($google_url) {
        $k = self::key($google_url);
        $m = self::map();
        if (!empty($m[$k])) return;
        $m[$k] = ['src' => self::normalize($google_url), 'queued' => time()];
        update_option(self::MAP, $m, false);
        if (!wp_next_scheduled(self::CRON, [$k])) wp_schedule_single_event(time(), self::CRON, [$k]);
    }

    private static function slug($url) {
        if (preg_match_all('#family=([^:&]+)#i', $url, $m)) return sanitize_title(str_replace('+', ' ', implode('-', array_slice($m[1], 0, 3)))) ?: 'fonts';
        return 'fonts';
    }

    /** Cron bzw. „Neu aufbauen“: CSS + woff2 einer Google-Adresse herunterladen. */
    public static function fetch($k) {
        $m = self::map();
        if (empty($m[$k]['src'])) return false;
        $src = $m[$k]['src'];
        $d = self::dir();
        $res = wp_remote_get($src, ['timeout' => 20, 'user-agent' => self::UA, 'headers' => ['Accept' => 'text/css,*/*;q=0.1']]);
        $css = !is_wp_error($res) && intval(wp_remote_retrieve_response_code($res)) === 200 ? (string) wp_remote_retrieve_body($res) : '';
        if ($css === '' || stripos($css, '@font-face') === false) return self::fail($k, is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($res));
        if (!wp_mkdir_p($d['path'] . '/' . $k)) return self::fail($k, 'Ordner uploads/' . self::DIR . ' nicht beschreibbar');
        $css = self::filter_subsets($css);
        $re = '#url\(\s*[\'"]?(https://fonts\.gstatic\.com/[^)\'"]+)[\'"]?\s*\)#i';
        preg_match_all($re, $css, $mm);
        $urls = array_values(array_unique($mm[1] ?? []));   // gleiche Datei nur einmal
        if (!$urls) return self::fail($k, 'keine Schriftdateien');
        if (count($urls) > self::MAX_FILES) return self::fail($k, 'zu viele Dateien (' . count($urls) . ')');
        $local = [];
        foreach ($urls as $u) {
            $name = sanitize_file_name(basename((string) wp_parse_url($u, PHP_URL_PATH))) ?: 'f.woff2';
            $name = substr(md5($u), 0, 8) . '-' . $name;
            $target = $d['path'] . '/' . $k . '/' . $name;
            if (!is_file($target)) {
                $r = wp_remote_get($u, ['timeout' => 20, 'user-agent' => self::UA]);
                $body = !is_wp_error($r) && intval(wp_remote_retrieve_response_code($r)) === 200 ? (string) wp_remote_retrieve_body($r) : '';
                if ($body === '' || @file_put_contents($target, $body) === false) return self::fail($k, 'Schriftdatei nicht geladen');
            }
            $local[$u] = $d['url'] . '/' . $k . '/' . $name;
        }
        $files = count($local);
        $css = preg_replace_callback($re, function ($mm) use ($local) { return 'url(' . $local[$mm[1]] . ')'; }, $css);
        $file = self::slug($src) . '-' . $k . '.css';
        if (@file_put_contents($d['path'] . '/' . $file, "/* Lokal gespiegelt von " . esc_url_raw($src) . " (Tixomat) */\n" . $css) === false) return self::fail($k, 'CSS nicht gespeichert');
        $m = self::map();
        $m[$k] = ['src' => $src, 'css' => $file, 'files' => $files, 'at' => time()];
        update_option(self::MAP, $m, false);
        return true;
    }

    private static function fail($k, $msg) {
        $m = self::map();
        $m[$k] = array_merge($m[$k] ?? [], ['error' => (string) $msg, 'failed_at' => time(), 'tries' => intval($m[$k]['tries'] ?? 0) + 1]);
        update_option(self::MAP, $m, false);
        // bis zu 3 Versuche (stündlich)
        if ($m[$k]['tries'] < 3 && !wp_next_scheduled(self::CRON, [$k])) wp_schedule_single_event(time() + HOUR_IN_SECONDS, self::CRON, [$k]);
        return false;
    }

    // ═══════════════ Ausgabe umschreiben ═══════════════

    public static function filter_src($src, $handle) {
        if (is_admin() || !self::is_google($src)) return $src;
        // Noch nicht gespiegelt: Stylesheet weglassen (WordPress gibt bei leerer Adresse nichts aus), nie Google
        return self::local_url($src) ?: false;
    }

    public static function filter_hints($urls, $relation) {
        if (!in_array($relation, ['preconnect', 'dns-prefetch'], true)) return $urls;
        return array_values(array_filter((array) $urls, function ($u) {
            $h = is_array($u) ? (string) ($u['href'] ?? '') : (string) $u;
            return !preg_match('#fonts\.(googleapis|gstatic)\.com#i', $h);
        }));
    }

    public static function start_buffer() {
        static $on = false;
        if ($on || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) return;
        $on = true;
        ob_start([__CLASS__, 'rewrite_html']);
    }

    /** HTML: Google-Links, @import und Hinweise ersetzen; eigene CSS-Dateien mit Google-@import als Kopie ausliefern. */
    public static function rewrite_html($html) {
        if (!is_string($html) || $html === '') return $html;
        $html = preg_replace_callback('#<link\b[^>]*>#i', function ($m) {
            $tag = $m[0];
            if (!preg_match('#href=(["\'])([^"\']+)\1#i', $tag, $h)) return $tag;
            $href = html_entity_decode($h[2], ENT_QUOTES, 'UTF-8');
            $rel = preg_match('#rel=["\']?([a-z\- ]+)#i', $tag, $r) ? strtolower($r[1]) : '';
            $google_css = self::is_google($href);
            $google_any = $google_css || preg_match('#^(https?:)?//fonts\.(googleapis|gstatic)\.com(/|$)#i', $href);
            // Verbindungs-Hinweise und vorgeladene Schriftdateien bei Google: entfernen (die Schriften kommen über das CSS)
            if ($google_any && (strpos($rel, 'preconnect') !== false || strpos($rel, 'dns-prefetch') !== false)) return '';
            if ($google_any && !$google_css && strpos($rel, 'preload') !== false) return '';
            // Google-Stylesheet (auch preload as=style): lokale Kopie, sonst weglassen (nie Google)
            if ($google_css) {
                $local = self::local_url($href);
                return $local ? str_replace($h[2], esc_url($local), $tag) : '';
            }
            if (strpos($rel, 'stylesheet') === false) return $tag;
            $copy = self::css_copy($href);
            return $copy ? str_replace($h[2], esc_url($copy), $tag) : $tag;
        }, $html);
        if (stripos($html, 'fonts.googleapis') === false) return $html;
        // @import in Inline-Styles
        return self::rewrite_imports($html);
    }

    /** @import url(https://fonts.googleapis.com/…) bzw. @import "…" → lokale Datei; noch nicht gespiegelt → Import fällt weg. „;“ in der Adresse (wght@400;700) bleibt Teil davon. */
    public static function rewrite_imports($css) {
        $g = '(?:https?:)?//fonts\.googleapis\.com/';
        return preg_replace_callback('#@import\s+(?:url\(\s*(?:"(' . $g . '[^"]+)"|\'(' . $g . '[^\']+)\'|(' . $g . '[^)\s]+))\s*\)|"(' . $g . '[^"]+)"|\'(' . $g . '[^\']+)\')(\s*;)?#i', function ($m) {
            $url = '';
            for ($i = 1; $i <= 5; $i++) if (!empty($m[$i])) { $url = $m[$i]; break; }
            $local = $url !== '' ? self::local_url($url) : null;
            // Semikolon mit ersetzen bzw. mitnehmen – ein übrig gebliebenes „;“ ließe den Browser die nächste Regel verwerfen
            if ($local) return '@import url(' . $local . ')' . (!empty($m[6]) ? ';' : '');
            self::$pending = true;
            return '/* Schrift wird lokal bereitgestellt */';
        }, $css);
    }

    /** Eigene CSS-Datei (uploads/ bzw. wp-content der Seite) mit Google-@import → umgeschriebene Kopie (URL) oder null. */
    private static function css_copy($href) {
        $content_url = content_url();
        $abs = strpos($href, '//') === 0 ? (is_ssl() ? 'https:' : 'http:') . $href : $href;
        $path_url = strtok($abs, '?');
        if (strpos($path_url, $content_url) !== 0 || substr($path_url, -4) !== '.css') return null;
        $file = WP_CONTENT_DIR . substr($path_url, strlen($content_url));
        if (strpos($file, '/' . self::DIR . '/') !== false || !is_file($file) || filesize($file) > 2 * MB_IN_BYTES) return null;
        $mtime = intval(filemtime($file));
        $copies = get_option(self::CSS_MAP, []);
        $copies = is_array($copies) ? $copies : [];
        $ck = substr(md5($file), 0, 12);
        $d = self::dir();
        if (isset($copies[$ck]) && intval($copies[$ck]['mtime']) === $mtime && empty($copies[$ck]['pending'])) {
            if (empty($copies[$ck]['copy'])) return null; // keine Google-Schrift darin
            return is_file($d['path'] . '/css/' . $copies[$ck]['copy']) ? $d['url'] . '/css/' . $copies[$ck]['copy'] . '?v=' . $mtime : null;
        }
        $css = (string) @file_get_contents($file);
        if (stripos($css, 'fonts.googleapis.com') === false) {
            $copies[$ck] = ['mtime' => $mtime, 'copy' => ''];
            update_option(self::CSS_MAP, $copies, false);
            return null;
        }
        self::$pending = false;
        $new = self::rewrite_imports($css);
        $pending = self::$pending;
        // relative url(...) auf den Ort der Originaldatei beziehen
        $base = trailingslashit(dirname($path_url));
        $new = preg_replace_callback('#url\(\s*([\'"]?)(?!data:|https?:|//|/|\#)([^\'")]+)\1\s*\)#i', function ($m) use ($base) {
            return 'url(' . $m[1] . $base . $m[2] . $m[1] . ')';
        }, $new);
        if (!wp_mkdir_p($d['path'] . '/css')) return null;
        $name = sanitize_file_name(basename($path_url, '.css')) . '-' . $ck . '.css';
        if (@file_put_contents($d['path'] . '/css/' . $name, $new) === false) return null;
        // pending: Kopie ohne die noch fehlenden Schriften – beim nächsten Aufruf neu schreiben, bis alle gespiegelt sind
        $copies[$ck] = ['mtime' => $mtime, 'copy' => $name, 'src' => $path_url, 'pending' => $pending];
        update_option(self::CSS_MAP, $copies, false);
        return $d['url'] . '/css/' . $name . '?v=' . $mtime;
    }

    // ═══════════════ Verwaltung ═══════════════

    public static function menu() {
        add_submenu_page('tixomat', 'Schriften', 'Schriften', 'manage_options', 'tix-fonts', [__CLASS__, 'render']);
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $m = self::map();
        $url = admin_url('admin-post.php');
        echo '<div class="wrap"><h1>Schriften lokal ausliefern</h1><p>Schriften von Google Fonts (Breakdance, Theme, Plugins) werden einmal auf diesen Server geladen und von hier ausgeliefert – Besucher-IPs gehen nicht an Google. Fehlt eine Datei noch, zeigt die Seite so lange die Systemschrift – Google wird nie geladen.</p>';
        echo '<form method="post" action="' . esc_url($url) . '">' . wp_nonce_field('tix_font_mirror', '_wpnonce', true, false) . '<input type="hidden" name="action" value="tix_font_mirror">';
        echo '<p><label><input type="checkbox" checked disabled> Schriften lokal ausliefern</label> <span class="description">immer an – Schriften kommen nie von Google</span></p>';
        echo '<p><label>Zeichensätze <input type="text" name="subsets" value="' . esc_attr((string) get_option(self::SUBSETS, 'latin,latin-ext')) . '" class="regular-text"></label><br><span class="description">Kommagetrennt, z. B. latin,latin-ext (deutsche Seiten) oder all für alle. Andere Zeichensätze (kyrillisch, griechisch …) werden nicht geladen.</span></p>';
        echo '<p><button class="button button-primary" name="do" value="save">Speichern</button> <button class="button" name="do" value="rebuild">Neu aufbauen</button></p></form>';
        echo '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>Google-Adresse</th><th>Status</th><th>Dateien</th></tr></thead><tbody>';
        if (!$m) echo '<tr><td colspan="3">Noch keine Schriften erkannt – sie erscheinen nach dem ersten Aufruf der Website.</td></tr>';
        foreach ($m as $row) {
            $st = !empty($row['error']) ? '<span style="color:#b91c1c;">Fehler: ' . esc_html($row['error']) . ' (Original-Link aktiv)</span>' : (!empty($row['css']) ? '<span style="color:#15803d;">lokal</span>' : 'wird geladen');
            echo '<tr><td style="word-break:break-all;"><code>' . esc_html(mb_substr((string) $row['src'], 0, 180)) . '</code></td><td>' . $st . '</td><td>' . intval($row['files'] ?? 0) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function handle_admin() {
        if (!current_user_can('manage_options')) wp_die('Kein Zugriff.');
        check_admin_referer('tix_font_mirror');
        $do = sanitize_key((string) ($_POST['do'] ?? 'save'));
        @set_time_limit(180);
        if ($do === 'rebuild') self::rebuild();
        else {
            $sub = strtolower(preg_replace('/[^a-z0-9,\-\[\]]/i', '', (string) wp_unslash($_POST['subsets'] ?? 'latin,latin-ext')));
            $old = (string) get_option(self::SUBSETS, 'latin,latin-ext');
            update_option(self::SUBSETS, $sub === '' ? 'latin,latin-ext' : $sub, false);
            if ($sub !== $old) self::rebuild();   // andere Zeichensätze → neu laden
        }
        wp_safe_redirect(admin_url('admin.php?page=tix-fonts&done=' . $do));
        exit;
    }

    /** Alles neu laden (Cache leeren, gleich wieder holen). */
    public static function rebuild() {
        $old = self::map();
        delete_option(self::MAP);
        delete_option(self::CSS_MAP);
        foreach ($old as $k => $row) {
            if (empty($row['src'])) continue;
            $m = self::map();
            $m[$k] = ['src' => $row['src'], 'queued' => time()];
            update_option(self::MAP, $m, false);
            self::fetch($k);
        }
    }

    public static function notice() {
        if (!current_user_can('manage_options') || !self::enabled()) return;
        $bad = array_filter(self::map(), function ($r) { return !empty($r['error']) && intval($r['tries'] ?? 0) >= 3; });
        if (!$bad) return;
        printf('<div class="notice notice-warning"><p><strong>Schriften:</strong> %d Google-Schrift(en) konnten nicht lokal gespeichert werden – dort wird weiter von Google geladen. <a href="%s">Tixomat → Schriften</a></p></div>',
            count($bad), esc_url(admin_url('admin.php?page=tix-fonts')));
    }
}
