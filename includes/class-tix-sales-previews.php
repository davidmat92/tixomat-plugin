<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Sales_Previews – Vorschauen für den Vertrieb (Live-Demo Paket 2, 1.38.510, Vertrag uebergaben/vertrag-live-demo.md D–H,
 * nur Mehr-Veranstalter-Modus, sonst 404).
 *
 *  - Ein Vertriebler (Rolle tix_sales) oder Admin legt aus Link, Text oder Flyer eine Vorschau an: Veranstalter als Entwurf
 *    (Meta _tix_org_preview = Sandbox, _tix_preview_data = Datensatz) mit bis zu 12 Event-Entwürfen (Meta _tix_preview).
 *    Logo und Akzentfarbe kommen von der Quellseite, sonst gilt die evendis-Vorgabe.
 *  - Der Kunde sieht sie ohne Login unter /public/previews/{token} (noindex, no-store) und übernimmt sie mit
 *    POST /register/organizer + claim_token: neuer Inhaber, Freigabe „pending“, Events bleiben Entwürfe, keine Sandbox mehr.
 *  - Erstes Öffnen und Übernehmen melden sich beim Vertriebler (Glocke Typ sales + Mail).
 *  - Stündlich werden abgelaufene, nicht übernommene Vorschauen samt Bildern gelöscht; der Link antwortet danach noch
 *    30 Tage mit 410 (Token-Hash in tix_preview_gone), dann 404.
 */
class TIX_Sales_Previews {

    const NS = 'tixomat/v1';
    const META = '_tix_preview_data';      // Datensatz am Veranstalter
    const META_TOKEN = '_tix_preview_token';
    const META_CLAIM = '_tix_preview_claim';
    const META_OWNER = '_tix_preview_owner';
    const META_ITEM = '_tix_preview';       // Events und Bilder der Vorschau
    const META_ACCENT = '_tix_org_accent';
    const META_LOGO = '_tix_org_landing_logo_id';
    const META_ARCHIVE = '_tix_preview_archive'; // Nutzer: gelöschte Vorschauen (für die Auswertung)
    const OPT_GONE = 'tix_preview_gone';
    const CRON = 'tix_preview_expire';
    const DAYS = 14;
    const MAX_EVENTS = 12;
    const PER_PAGE = 20;
    const GONE_DAYS = 30;
    const DEFAULT_ACCENT = '#FC2F55';

    /** Veranstalter, dessen Vorschau gerade öffentlich ausgeliefert wird (Entwürfe nur dann in den Payloads). */
    private static $render = 0;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action(self::CRON, [__CLASS__, 'expire']);
        add_action('init', [__CLASS__, 'schedule'], 30);
    }

    public static function schedule() {
        if (!class_exists('TIX_Sales') || !TIX_Sales::multi()) {
            if (wp_next_scheduled(self::CRON)) wp_clear_scheduled_hook(self::CRON);
            return;
        }
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 600, 'hourly', self::CRON);
    }

    // ═══════════════ Vorschau-Modus der öffentlichen Payloads ═══════════════

    public static function rendering_org($oid) {
        return self::$render > 0 && intval($oid) === self::$render;
    }

    public static function rendering_event($event_id) {
        if (self::$render <= 0) return false;
        return intval(get_post_meta(intval($event_id), '_tix_organizer_id', true)) === self::$render && (bool) get_post_meta(intval($event_id), self::META_ITEM, true);
    }

    // ═══════════════ Datensatz ═══════════════

    private static function data($oid) {
        $d = get_post_meta(intval($oid), self::META, true);
        return is_array($d) && !empty($d['token']) ? $d : null;
    }

    private static function save($oid, array $d) {
        update_post_meta(intval($oid), self::META, $d);
    }

    /** active | expired | claimed */
    public static function status(array $d) {
        if (!empty($d['claimed_at'])) return 'claimed';
        return intval($d['expires_at'] ?? 0) <= time() ? 'expired' : 'active';
    }

    private static function by_token($token, $meta) {
        $token = (string) $token;
        if (!preg_match('/^[pc]_[A-Za-z0-9]{32}$/', $token)) return 0;
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true,
            'suppress_filters' => true, 'meta_key' => $meta, 'meta_value' => $token]);
        return $ids ? intval($ids[0]) : 0;
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

    /** Events der Vorschau (bzw. nach der Übernahme des Veranstalters), nach Beginn sortiert. */
    private static function event_ids($oid, $only_preview = true) {
        $mq = [['key' => '_tix_organizer_id', 'value' => strval(intval($oid))]];
        if ($only_preview) $mq[] = ['key' => self::META_ITEM, 'compare' => 'EXISTS'];
        $ids = array_map('intval', get_posts(['post_type' => 'event', 'post_status' => ['publish', 'draft', 'pending', 'future', 'private'], 'posts_per_page' => 100,
            'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => true, 'meta_query' => $mq]));
        usort($ids, function ($a, $b) {
            $x = (string) get_post_meta($a, '_tix_date_start', true) . ' ' . (string) get_post_meta($a, '_tix_time_start', true);
            $y = (string) get_post_meta($b, '_tix_date_start', true) . ' ' . (string) get_post_meta($b, '_tix_time_start', true);
            return strcmp($x, $y) ?: $a - $b;
        });
        return $ids;
    }

    // ═══════════════ Links, Marke ═══════════════

    private static function studio_base() {
        $b = class_exists('TIX_Studio') ? (string) TIX_Studio::base() : '';
        return $b !== '' ? $b : 'https://studio.evendis.de';
    }

    /** public (Web), homepage (Homepage-Vorschau), app (App-Vorschau im Studio) – Token im Pfad. */
    public static function link($kind, $token) {
        if ($kind === 'homepage') $base = (string) get_option('tix_preview_home_base', '') ?: 'https://vorschau.evendis.de/';
        elseif ($kind === 'app') $base = (string) get_option('tix_preview_app_base', '') ?: self::studio_base() . '/app-vorschau/';
        else $base = (string) get_option('tix_preview_base', '') ?: 'https://evendis.de/vorschau/';
        return trailingslashit(esc_url_raw($base)) . rawurlencode((string) $token) . '/';
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

    public static function accent($oid) {
        return self::hex(get_post_meta(intval($oid), self::META_ACCENT, true)) ?: self::default_accent();
    }

    private static function logo_url($oid) {
        $id = intval(get_post_meta(intval($oid), self::META_LOGO, true));
        return $id ? (string) (wp_get_attachment_image_url($id, 'medium') ?: wp_get_attachment_url($id)) : '';
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
    private static function sideload($url, $parent = 0) {
        $url = esc_url_raw((string) $url, ['http', 'https']);
        if ($url === '' || !TIX_Event_Import::robots_allows($url)) return 0;
        $r = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 3, 'user-agent' => TIX_Event_Import::user_agent(), 'limit_response_size' => 8 * MB_IN_BYTES]);
        if (is_wp_error($r) || intval(wp_remote_retrieve_response_code($r)) !== 200) return 0;
        return self::store_bytes((string) wp_remote_retrieve_body($r), $parent, 'logo');
    }

    /** Bilddaten als Anhang der Vorschau speichern → Anhang-ID | 0 */
    private static function store_bytes($bytes, $parent, $name) {
        $info = @getimagesizefromstring((string) $bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? '';
        if ($ext === '') return 0;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $up = wp_upload_bits(sanitize_file_name($name) . '-' . wp_generate_password(8, false, false) . '.' . $ext, null, (string) $bytes);
        if (!empty($up['error'])) return 0;
        $id = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => $name, 'post_status' => 'inherit', 'post_parent' => intval($parent)], $up['file']);
        if (!$id || is_wp_error($id)) return 0;
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $up['file']));
        self::own_attachment($id, $parent);
        return intval($id);
    }

    /** Anhang gehört zur Vorschau (Meta, kein Autor – Vertriebler sehen ihn nicht in eigenen Medien). */
    private static function own_attachment($id, $parent) {
        global $wpdb;
        update_post_meta($id, self::META_ITEM, '1');
        if (class_exists('TIX_Register_API') && defined('TIX_Register_API::TMP_META')) delete_post_meta($id, TIX_Register_API::TMP_META);
        $wpdb->update($wpdb->posts, ['post_author' => 0, 'post_parent' => intval($parent)], ['ID' => intval($id)]);
        clean_post_cache($id);
    }

    private static function unique_slug($oid, $title) {
        $base = sanitize_title($title) ?: 'event';
        $used = [];
        foreach (self::event_ids($oid) as $eid) $used[(string) get_post_field('post_name', $eid)] = true;
        $slug = $base;
        for ($i = 2; isset($used[$slug]); $i++) $slug = $base . '-' . $i;
        return $slug;
    }

    private static function date_ok($v) {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
    }

    private static function time_ok($v) {
        return preg_match('/^\d{1,2}:\d{2}$/', (string) $v) ? substr('0' . $v, -5) : '';
    }

    /** Event-Entwurf der Vorschau aus Import-Feldern → Event-ID | 0 */
    private static function create_event($oid, array $f, $image_id = 0) {
        global $wpdb;
        $title = mb_substr(sanitize_text_field((string) ($f['title'] ?? '')), 0, 200);
        if ($title === '') return 0;
        $eid = wp_insert_post([
            'post_type' => 'event', 'post_title' => $title, 'post_status' => 'draft', 'post_name' => self::unique_slug($oid, $title),
            'post_excerpt' => sanitize_textarea_field((string) ($f['excerpt'] ?? '')),
            'meta_input' => ['_tix_organizer_id' => intval($oid), '_tix_organizer' => (string) get_the_title($oid), self::META_ITEM => '1'],
        ], true);
        if (is_wp_error($eid) || !$eid) return 0;
        // kein Autor: Vorschau-Events gehören niemandem, bis der Kunde übernimmt
        $wpdb->update($wpdb->posts, ['post_author' => 0], ['ID' => intval($eid)]);
        clean_post_cache($eid);
        foreach (['date_start' => '_tix_date_start', 'date_end' => '_tix_date_end'] as $k => $mk) if (($v = self::date_ok($f[$k] ?? '')) !== '') update_post_meta($eid, $mk, $v);
        foreach (['time_start' => '_tix_time_start', 'time_end' => '_tix_time_end', 'time_doors' => '_tix_time_doors'] as $k => $mk) if (($v = self::time_ok($f[$k] ?? '')) !== '') update_post_meta($eid, $mk, $v);
        foreach (['description' => '_tix_info_description', 'lineup' => '_tix_info_lineup', 'specials' => '_tix_info_specials', 'extra_info' => '_tix_info_extra_info', 'excerpt' => '_tix_info_excerpt'] as $k => $mk) {
            if (trim((string) ($f[$k] ?? '')) !== '') update_post_meta($eid, $mk, wp_kses_post((string) $f[$k]));
        }
        if (trim((string) ($f['location'] ?? '')) !== '') update_post_meta($eid, '_tix_location', sanitize_text_field((string) $f['location']));
        if (trim((string) ($f['location_address'] ?? '')) !== '') update_post_meta($eid, '_tix_address', sanitize_text_field((string) $f['location_address']));
        if (intval($f['age_limit'] ?? 0) > 0) update_post_meta($eid, '_tix_info_age_limit', intval($f['age_limit']));
        $cats = [];
        foreach ((array) ($f['tickets'] ?? []) as $t) {
            if (!is_array($t) || trim((string) ($t['name'] ?? '')) === '') continue;
            $qty = max(0, intval($t['qty'] ?? 0));
            $c = ['name' => mb_substr(sanitize_text_field((string) $t['name']), 0, 80), 'price' => max(0, round(floatval(str_replace(',', '.', (string) ($t['price'] ?? 0))), 2)),
                'qty' => $qty, 'desc' => sanitize_text_field((string) ($t['description'] ?? ($t['desc'] ?? ''))), 'sale_mode' => 'online', 'product_id' => '', 'tc_event_id' => '', 'sku' => ''];
            if ($qty > 0) $c['stock'] = $qty;
            $cats[] = $c;
            if (count($cats) >= 12) break;
        }
        if ($cats) {
            update_post_meta($eid, '_tix_ticket_categories', $cats);
            update_post_meta($eid, '_tix_tickets_enabled', '1');
        }
        // Sparte: aus der KI (category_ids) bzw. dem erkannten Typ
        $term = 0;
        $ids = array_map('intval', (array) ($f['category_ids'] ?? []));
        if ($ids && get_term($ids[0], 'event_category')) $term = $ids[0];
        if (!$term && trim((string) ($f['event_type'] ?? '')) !== '') {
            $t = get_term_by('slug', sanitize_title((string) $f['event_type']), 'event_category');
            if ($t) $term = intval($t->term_id);
        }
        if ($term) wp_set_object_terms($eid, [$term], 'event_category');
        // Bild: Flyer, von der KI übernommen (suggested_image_id) oder aus den strukturierten Daten
        $img = intval($image_id) ?: intval($f['suggested_image_id'] ?? 0);
        if (!$img && !empty($f['_image'])) {
            $orig = TIX_Event_Import::original_image((string) $f['_image']);
            $img = self::sideload($orig ?: (string) $f['_image'], $eid);
        }
        if ($img && get_post_type($img) === 'attachment') {
            self::own_attachment($img, $eid);
            set_post_thumbnail($eid, $img);
        }
        if (class_exists('TIX_Sync')) TIX_Sync::save_breakdance_meta($eid, $cats);
        return intval($eid);
    }

    /** Importierte Events anlegen (ohne Doppelte zur Vorschau) → neue Event-IDs */
    private static function add_events($oid, array $imp) {
        $have = [];
        foreach (self::event_ids($oid) as $eid) $have[mb_strtolower(get_the_title($eid)) . '|' . get_post_meta($eid, '_tix_date_start', true)] = true;
        $room = self::MAX_EVENTS - count($have);
        $new = [];
        $flyer = $imp['flyer'] ?? null;
        foreach ($imp['events'] as $f) {
            $k = mb_strtolower(sanitize_text_field((string) ($f['title'] ?? ''))) . '|' . self::date_ok($f['date_start'] ?? '');
            if (isset($have[$k]) || $room <= 0) {
                // nicht genutztes Bild der KI wieder entfernen
                if (!empty($f['suggested_image_id']) && !get_post_field('post_parent', intval($f['suggested_image_id']))) wp_delete_attachment(intval($f['suggested_image_id']), true);
                continue;
            }
            // Flyer (Import aus Bild) wird Bild des ersten Events
            $fid = $flyer ? self::store_bytes($flyer[0], 0, 'flyer') : 0;
            $flyer = null;
            $eid = self::create_event($oid, $f, $fid);
            if (!$eid) { if ($fid) wp_delete_attachment($fid, true); continue; }
            $have[$k] = true;
            $new[] = $eid;
            $room--;
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return $new;
    }

    // ═══════════════ Payload ═══════════════

    /** PREVIEW laut Vertrag (D) */
    public static function payload($oid) {
        $d = self::data($oid);
        $p = get_post($oid);
        if (!$d || !$p) return null;
        $st = self::status($d);
        $events = [];
        foreach (self::event_ids($oid, $st !== 'claimed') as $eid) {
            $events[] = ['id' => $eid, 'title' => (string) get_the_title($eid), 'date_start' => (string) get_post_meta($eid, '_tix_date_start', true),
                'image' => (string) (get_the_post_thumbnail_url($eid, 'medium_large') ?: '')];
        }
        $public = self::link('public', $d['token']);
        $owner = get_userdata(intval($d['owner']));
        return [
            'id'             => intval($oid),
            'token'          => (string) $d['token'],
            'status'         => $st,
            'name'           => (string) $p->post_title,
            'logo'           => self::logo_url($oid) ?: null,
            'accent'         => self::accent($oid),
            'source_url'     => (string) ($d['source_url'] ?? ''),
            'created_at'     => self::iso($d['created_at'] ?? 0),
            'expires_at'     => self::iso($d['expires_at'] ?? 0),
            'extended'       => !empty($d['extended']),
            'opened_at'      => self::iso($d['opened_at'] ?? 0),
            'views'          => intval($d['views'] ?? 0),
            'last_view_at'   => self::iso($d['last_view_at'] ?? 0),
            'claimed_at'     => self::iso($d['claimed_at'] ?? 0),
            'customer_email' => (string) ($d['customer_email'] ?? '') ?: null,
            'note'           => (string) ($d['note'] ?? ''),
            'events_count'   => count($events),
            'events'         => $events,
            'links'          => [
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
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)/events/(?P<event_id>\d+)', ['methods' => 'DELETE', 'callback' => [__CLASS__, 'rest_delete_event']] + $s);
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)/logo', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_logo']] + $s);
        register_rest_route(self::NS, '/sales/previews/(?P<id>\d+)/claim', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_claim']] + $s);
        register_rest_route(self::NS, '/sales/summary', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_summary']] + $s);

        register_rest_route(self::NS, '/public/previews/claim/(?P<claim>c_[A-Za-z0-9]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_claim_info']] + $pub);
        register_rest_route(self::NS, '/public/previews/(?P<token>p_[A-Za-z0-9]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public']] + $pub);
        register_rest_route(self::NS, '/public/previews/(?P<token>p_[A-Za-z0-9]+)/events/(?P<key>[A-Za-z0-9_-]+)', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_public_event']] + $pub);
        register_rest_route(self::NS, '/public/previews/(?P<token>p_[A-Za-z0-9]+)/seen', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_seen']] + $pub);
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

    /** Vorschau für /sales/* laden: eigene (Admin alle) → [oid, data] | WP_Error */
    private static function load($id) {
        $oid = intval($id);
        $d = ($oid && get_post_type($oid) === 'tix_organizer') ? self::data($oid) : null;
        if (!$d) return self::err_unknown();
        if (!current_user_can('manage_options') && intval($d['owner']) !== get_current_user_id()) return new WP_Error('rest_forbidden', 'Das ist nicht deine Vorschau.', ['status' => 403]);
        return [$oid, $d];
    }

    /** Änderbar? claimed → 409, abgelaufen → 410 */
    private static function editable(array $d) {
        $st = self::status($d);
        if ($st === 'claimed') return self::err_claimed();
        if ($st === 'expired') return self::err_expired();
        return null;
    }

    private static function respond($oid, $status = 200, array $extra = []) {
        $r = rest_ensure_response(['ok' => true, 'preview' => self::payload($oid)] + $extra);
        $r->set_status($status);
        return $r;
    }

    private static function limited($bucket, $max, $window) {
        return class_exists('TIX_App_Account') && TIX_App_Account::rate_limited($bucket, $max, $window, 'u' . get_current_user_id());
    }

    /** POST /sales/previews {url|text|image, name?, customer_email?, note?} → 201 {ok, preview} */
    public static function rest_create(WP_REST_Request $req) {
        $src = self::read_source($req);
        if (is_wp_error($src)) return $src;
        $email = trim((string) $req->get_param('customer_email'));
        if ($email !== '' && !is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse des Kunden angeben.', ['status' => 400, 'field' => 'customer_email']);
        if (self::limited('sales_preview', 30, HOUR_IN_SECONDS)) return new WP_Error('tix_rate_limit', 'Zu viele Vorschauen in kurzer Zeit. Bitte warte etwas.', ['status' => 429]);
        $imp = self::import($src, self::MAX_EVENTS);
        if (is_wp_error($imp)) return self::map_error($imp);

        $name = mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 120);
        if ($name === '') $name = $imp['brand']['name'];
        if ($name === '') $name = sanitize_text_field((string) ($imp['events'][0]['location'] ?? ''));
        if ($name === '' && $imp['source_url'] !== '') $name = preg_replace('/^www\./', '', (string) wp_parse_url($imp['source_url'], PHP_URL_HOST));
        if ($name === '') $name = sanitize_text_field((string) $imp['events'][0]['title']);
        $token = self::new_token('p');
        $claim = self::new_token('c');
        $uid = get_current_user_id();
        $oid = wp_insert_post([
            'post_type' => 'tix_organizer', 'post_title' => $name, 'post_status' => 'draft',
            // Sandbox von Anfang an; Freigabe-Status gesetzt, damit keine Prüfung/Admin-Mail ausgelöst wird
            'meta_input' => [TIX_Sales::META_PREVIEW => '1', '_tix_org_approval' => 'approved', self::META_TOKEN => $token, self::META_CLAIM => $claim, self::META_OWNER => $uid],
        ], true);
        if (is_wp_error($oid) || !$oid) return new WP_Error('tix_preview_failed', 'Die Vorschau konnte nicht angelegt werden.', ['status' => 500]);
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_author' => 0], ['ID' => intval($oid)]);
        clean_post_cache($oid);
        TIX_Sales::flush();
        self::save($oid, [
            'token' => $token, 'claim' => $claim, 'owner' => $uid, 'created_at' => time(), 'expires_at' => time() + self::DAYS * DAY_IN_SECONDS,
            'extended' => false, 'opened_at' => 0, 'views' => 0, 'last_view_at' => 0, 'claimed_at' => 0, 'claimed_by' => 0,
            'source_url' => $imp['source_url'], 'read_mode' => $imp['read_mode'], 'notice' => $imp['notice'],
            'customer_email' => $email !== '' ? sanitize_email($email) : '', 'note' => mb_substr(sanitize_textarea_field((string) $req->get_param('note')), 0, 1000),
        ]);
        if ($imp['brand']['logo'] !== '' && ($lid = self::sideload($imp['brand']['logo'], $oid))) {
            update_post_meta($oid, self::META_LOGO, $lid);
            if ($imp['brand']['accent'] === '') $imp['brand']['accent'] = self::main_color((string) @file_get_contents(get_attached_file($lid)));
        }
        if ($imp['brand']['accent'] !== '') update_post_meta($oid, self::META_ACCENT, $imp['brand']['accent']);
        self::add_events($oid, $imp);
        return self::respond($oid, 201);
    }

    /** GET /sales/previews?status=&page=&owner= → {ok, previews, total} */
    public static function rest_list(WP_REST_Request $req) {
        $status = sanitize_key((string) $req->get_param('status'));
        if ($status !== '' && !in_array($status, ['active', 'expired', 'claimed'], true)) return new WP_Error('tix_invalid_field', 'status: active, expired oder claimed.', ['status' => 400, 'field' => 'status']);
        $page = max(1, intval($req->get_param('page') ?: 1));
        $q = ['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'orderby' => 'ID', 'order' => 'DESC'];
        $owner = current_user_can('manage_options') ? intval($req->get_param('owner')) : get_current_user_id();
        $q['meta_query'] = $owner ? [['key' => self::META_OWNER, 'value' => strval($owner)]] : [['key' => self::META_OWNER, 'compare' => 'EXISTS']];
        $ids = [];
        foreach (array_map('intval', get_posts($q)) as $oid) {
            $d = self::data($oid);
            if (!$d || ($status !== '' && self::status($d) !== $status)) continue;
            $ids[] = $oid;
        }
        $list = array_values(array_filter(array_map([__CLASS__, 'payload'], array_slice($ids, ($page - 1) * self::PER_PAGE, self::PER_PAGE))));
        return rest_ensure_response(['ok' => true, 'previews' => $list, 'total' => count($ids), 'page' => $page, 'per_page' => self::PER_PAGE]);
    }

    public static function rest_get(WP_REST_Request $req) {
        $l = self::load($req['id']);
        if (is_wp_error($l)) return $l;
        return self::respond($l[0]);
    }

    /** PATCH /sales/previews/{id} {name?, accent?, logo_id?, customer_email?, note?, extend?: true} */
    public static function rest_patch(WP_REST_Request $req) {
        $l = self::load($req['id']);
        if (is_wp_error($l)) return $l;
        list($oid, $d) = $l;
        $p = $req->get_json_params();
        $p = is_array($p) ? $p : $req->get_params();
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
        $logo = null;
        if (array_key_exists('logo_id', $p)) {
            $logo = intval($p['logo_id']);
            if ($logo > 0) {
                if (get_post_type($logo) !== 'attachment' || strpos((string) get_post_mime_type($logo), 'image/') !== 0) return new WP_Error('tix_invalid_field', 'Bild nicht gefunden.', ['status' => 400, 'field' => 'logo_id']);
                $mine = intval(get_post_field('post_author', $logo)) === get_current_user_id() || intval(get_post_field('post_parent', $logo)) === $oid;
                if (!current_user_can('manage_options') && !$mine) return new WP_Error('tix_invalid_field', 'Dieses Bild gehört nicht zu dieser Vorschau.', ['status' => 403, 'field' => 'logo_id']);
            }
        }
        $email = null;
        if (array_key_exists('customer_email', $p)) {
            $email = trim((string) $p['customer_email']);
            if ($email !== '' && !is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte eine gültige E-Mail-Adresse des Kunden angeben.', ['status' => 400, 'field' => 'customer_email']);
        }
        if ($name !== null) wp_update_post(['ID' => $oid, 'post_title' => $name]);
        if ($accent !== null) { if ($accent === '') delete_post_meta($oid, self::META_ACCENT); else update_post_meta($oid, self::META_ACCENT, $accent); }
        if ($logo !== null) {
            if ($logo > 0) { self::own_attachment($logo, $oid); update_post_meta($oid, self::META_LOGO, $logo); }
            else delete_post_meta($oid, self::META_LOGO);
        }
        if ($email !== null) $d['customer_email'] = $email !== '' ? sanitize_email($email) : '';
        if (array_key_exists('note', $p)) $d['note'] = mb_substr(sanitize_textarea_field((string) $p['note']), 0, 1000);
        if ($extend) {
            $d['expires_at'] = max(intval($d['expires_at']), time()) + self::DAYS * DAY_IN_SECONDS;
            $d['extended'] = true;
        }
        self::save($oid, $d);
        return self::respond($oid);
    }

    /** POST /sales/previews/{id}/logo (Multipart `image` oder Base64) – eigenes Logo hochladen. */
    public static function rest_logo(WP_REST_Request $req) {
        $l = self::load($req['id']);
        if (is_wp_error($l)) return $l;
        list($oid, $d) = $l;
        if ($e = self::editable($d)) return $e;
        $img = TIX_AI_REST::read_image($req);
        if (is_wp_error($img)) return $img;
        $lid = self::store_bytes($img[0], $oid, 'logo');
        if (!$lid) return new WP_Error('tix_invalid_field', 'Das Logo konnte nicht gespeichert werden.', ['status' => 400, 'field' => 'image']);
        update_post_meta($oid, self::META_LOGO, $lid);
        if (!self::hex(get_post_meta($oid, self::META_ACCENT, true)) && ($c = self::main_color($img[0])) !== '') update_post_meta($oid, self::META_ACCENT, $c);
        return self::respond($oid);
    }

    /** POST /sales/previews/{id}/events {url|text|image} → 201 {ok, preview, added} */
    public static function rest_add_events(WP_REST_Request $req) {
        $l = self::load($req['id']);
        if (is_wp_error($l)) return $l;
        list($oid, $d) = $l;
        if ($e = self::editable($d)) return $e;
        $room = self::MAX_EVENTS - count(self::event_ids($oid));
        if ($room <= 0) return new WP_Error('tix_preview_full', 'Eine Vorschau hat höchstens ' . self::MAX_EVENTS . ' Events.', ['status' => 409]);
        $src = self::read_source($req);
        if (is_wp_error($src)) return $src;
        if (self::limited('sales_preview', 30, HOUR_IN_SECONDS)) return new WP_Error('tix_rate_limit', 'Zu viele Importe in kurzer Zeit. Bitte warte etwas.', ['status' => 429]);
        $imp = self::import($src, $room);
        if (is_wp_error($imp)) return self::map_error($imp);
        $new = self::add_events($oid, $imp);
        return self::respond($oid, 201, ['added' => $new]);
    }

    /** DELETE /sales/previews/{id}/events/{event_id} */
    public static function rest_delete_event(WP_REST_Request $req) {
        $l = self::load($req['id']);
        if (is_wp_error($l)) return $l;
        list($oid, $d) = $l;
        if ($e = self::editable($d)) return $e;
        $eid = intval($req['event_id']);
        if (!in_array($eid, self::event_ids($oid), true)) return new WP_Error('tix_event', 'Event nicht in dieser Vorschau.', ['status' => 404]);
        self::delete_event($eid);
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return self::respond($oid);
    }

    /** DELETE /sales/previews/{id} – sofort löschen (Link danach 404). */
    public static function rest_delete(WP_REST_Request $req) {
        $l = self::load($req['id']);
        if (is_wp_error($l)) return $l;
        list($oid, $d) = $l;
        if (self::status($d) === 'claimed') return self::err_claimed();
        self::destroy($oid, false);
        return rest_ensure_response(['ok' => true, 'deleted' => true]);
    }

    /** POST /sales/previews/{id}/claim {email?} – Übernahme-Link per Mail an den Kunden → {ok, claim_url, sent_to} */
    public static function rest_claim(WP_REST_Request $req) {
        $l = self::load($req['id']);
        if (is_wp_error($l)) return $l;
        list($oid, $d) = $l;
        if ($e = self::editable($d)) return $e;
        $email = trim((string) $req->get_param('email')) ?: (string) ($d['customer_email'] ?? '');
        if ($email === '' || !is_email($email)) return new WP_Error('tix_invalid_field', 'Bitte die E-Mail-Adresse des Kunden angeben.', ['status' => 400, 'field' => 'email']);
        if (class_exists('TIX_App_Account') && TIX_App_Account::rate_limited('sales_claim_mail', 5, HOUR_IN_SECONDS, 'p' . $oid)) return new WP_Error('tix_rate_limit', 'Für diese Vorschau wurden gerade schon mehrere Mails verschickt. Bitte warte etwas.', ['status' => 429]);
        $email = sanitize_email($email);
        $url = self::claim_link($d['claim']);
        $owner = get_userdata(intval($d['owner']));
        $brand = class_exists('TIX_Mail_Layout') ? TIX_Mail_Layout::brand()['name'] : get_bloginfo('name');
        $name = (string) get_the_title($oid);
        $sent = class_exists('TIX_Mail_Layout') && TIX_Mail_Layout::send($email, $brand . ' – Deine Events sind vorbereitet', [
            'title' => 'Deine Events bei ' . $brand,
            'blocks' => [
                ['type' => 'text', 'html' => 'Hallo,<br>' . ($owner ? esc_html($owner->display_name) . ' hat' : 'wir haben') . ' für <strong>' . esc_html($name) . '</strong> eine Vorschau mit ' . count(self::event_ids($oid)) . ' Events vorbereitet. Schau sie dir an und übernimm sie mit einem Klick in dein eigenes Konto.'],
                ['type' => 'button', 'label' => 'Jetzt übernehmen', 'url' => $url],
                ['type' => 'text', 'html' => 'Vorschau ansehen: <a href="' . esc_url(self::link('public', $d['token'])) . '">' . esc_html(self::link('public', $d['token'])) . '</a><br>Die Vorschau ist bis ' . esc_html(wp_date('d.m.Y', intval($d['expires_at']))) . ' verfügbar. Nach der Übernahme liegen die Events als Entwürfe in deinem Studio.'],
            ],
            'kind' => 'account',
        ]);
        if (!$sent) return new WP_Error('tix_mail_failed', 'Die Mail konnte nicht verschickt werden.', ['status' => 502]);
        $d['customer_email'] = $email;
        $d['claim_sent_at'] = time();
        self::save($oid, $d);
        return rest_ensure_response(['ok' => true, 'claim_url' => $url, 'sent_to' => $email]);
    }

    // ── Auswertung ──

    /** Datensätze (lebend + gelöschte aus dem Archiv) für einen Vertriebler bzw. alle (0). */
    private static function records($owner) {
        $q = ['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            'meta_query' => $owner ? [['key' => self::META_OWNER, 'value' => strval(intval($owner))]] : [['key' => self::META_OWNER, 'compare' => 'EXISTS']]];
        $out = [];
        foreach (get_posts($q) as $oid) {
            $d = self::data($oid);
            if (!$d) continue;
            $out[] = ['created_at' => intval($d['created_at']), 'opened_at' => intval($d['opened_at'] ?? 0), 'claimed_at' => intval($d['claimed_at'] ?? 0), 'status' => self::status($d)];
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
        $from = intval($from); $to = intval($to);
        if (!$from || !$to || $from === $to) return 0;
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            'meta_query' => [['key' => self::META_OWNER, 'value' => strval($from)]]]);
        foreach ($ids as $oid) {
            $d = self::data($oid);
            if ($d) { $d['owner'] = $to; self::save($oid, $d); }
            update_post_meta($oid, self::META_OWNER, $to);
        }
        $arch = get_user_meta($from, self::META_ARCHIVE, true);
        if (is_array($arch) && $arch) {
            $mine = get_user_meta($to, self::META_ARCHIVE, true);
            update_user_meta($to, self::META_ARCHIVE, array_slice(array_merge(is_array($mine) ? $mine : [], $arch), -1000));
            delete_user_meta($from, self::META_ARCHIVE);
        }
        return count($ids);
    }

    private static function notify($oid, $what) {
        $d = self::data($oid);
        $owner = $d ? get_userdata(intval($d['owner'])) : null;
        if (!$owner) return;
        $name = (string) get_the_title($oid);
        $title = $what === 'opened' ? 'Vorschau geöffnet' : 'Vorschau übernommen';
        $body = $what === 'opened'
            ? 'Die Vorschau für „' . $name . '“ wurde zum ersten Mal geöffnet.'
            : '„' . $name . '“ hat die Vorschau übernommen und ein Konto angelegt. Die Freigabe läuft.';
        if (class_exists('TIX_Notifications')) TIX_Notifications::add_user_item($owner->ID, $title, $body, ['type' => 'sales', 'action' => 'sales_preview:' . intval($oid)]);
        if (class_exists('TIX_Mail_Layout')) {
            TIX_Mail_Layout::send($owner->user_email, TIX_Mail_Layout::brand()['name'] . ' – ' . $title . ': ' . $name, [
                'title' => $title,
                'blocks' => [
                    ['type' => 'text', 'html' => 'Hallo ' . esc_html($owner->display_name) . ',<br>' . esc_html($body)],
                    ['type' => 'button', 'label' => 'Vorschau ansehen', 'url' => self::link('public', $d['token'])],
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

    /** Token → [oid, data] | WP_Error (404 unbekannt, 410 abgelaufen bzw. gelöscht innerhalb von 30 Tagen) */
    private static function public_load($token, $allow_claimed = false) {
        $oid = self::by_token($token, self::META_TOKEN);
        $d = $oid ? self::data($oid) : null;
        if (!$d) return self::is_gone($token) ? self::err_expired() : self::err_unknown();
        $st = self::status($d);
        if ($st === 'expired') return self::err_expired();
        if ($st === 'claimed' && !$allow_claimed) return self::err_claimed();
        return [$oid, $d];
    }

    /** Events der Vorschau im öffentlichen Format (Liste bzw. Detail), Verkauf aus. */
    private static function public_event($eid, array $d, $detailed) {
        $e = TIX_Public_Events::payload($eid, $detailed);
        if (!$e) return null;
        $e['url'] = self::link('public', $d['token']) . rawurlencode((string) get_post_field('post_name', $eid)) . '/';
        $e['tickets_enabled'] = false;
        $e['preview'] = true;
        if ($detailed) $e['sale'] = ['enabled' => false, 'reason' => 'preview'];
        return $e;
    }

    /** GET /public/previews/{token} */
    public static function rest_public(WP_REST_Request $req) {
        $l = self::public_load((string) $req['token'], true);
        if (is_wp_error($l)) return $l;
        list($oid, $d) = $l;
        if (self::status($d) === 'claimed') {
            $slug = class_exists('TIX_Public_Platform') ? TIX_Public_Platform::slug($oid) : '';
            $live = get_post_status($oid) === 'publish' && (!class_exists('TIX_Org_Approval') || TIX_Org_Approval::is_public($oid)) && !TIX_Sales::is_sandbox_organizer($oid);
            return self::nostore(['ok' => true, 'preview' => true, 'noindex' => true, 'status' => 'taken_over', 'expires_at' => null, 'organizer' => null, 'events' => [],
                'redirect' => ($live && $slug !== '') ? '/veranstalter/' . $slug : null]);
        }
        self::$render = $oid;
        try {
            $events = [];
            foreach (self::event_ids($oid) as $eid) if ($e = self::public_event($eid, $d, false)) $events[] = $e;
            $org = TIX_Public_Platform::organizer_payload($oid, true, $events);
        } finally {
            self::$render = 0;
        }
        if (!$org) return self::err_unknown();
        $org['logo'] = self::logo_url($oid) ?: ($org['logo'] ?? '');
        $org['accent'] = self::accent($oid);
        $org['preview'] = true;
        $org['events'] = $events;
        $owner = get_userdata(intval($d['owner']));
        return self::nostore(['ok' => true, 'preview' => true, 'noindex' => true, 'status' => 'active', 'expires_at' => self::iso($d['expires_at']),
            'organizer' => $org, 'events' => $events, 'takeover_url' => self::claim_link($d['claim']),
            'contact' => ['name' => $owner ? (string) $owner->display_name : '', 'email' => $owner ? (string) $owner->user_email : '']]);
    }

    /** GET /public/previews/{token}/events/{id|slug} */
    public static function rest_public_event(WP_REST_Request $req) {
        $l = self::public_load((string) $req['token']);
        if (is_wp_error($l)) return $l;
        list($oid, $d) = $l;
        $key = (string) $req['key'];
        $hit = 0;
        foreach (self::event_ids($oid) as $eid) {
            if ((ctype_digit($key) && intval($key) === $eid) || (string) get_post_field('post_name', $eid) === $key) { $hit = $eid; break; }
        }
        if (!$hit) return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        self::$render = $oid;
        try {
            $e = self::public_event($hit, $d, true);
        } finally {
            self::$render = 0;
        }
        if (!$e) return new WP_Error('tix_event', 'Event nicht gefunden.', ['status' => 404]);
        return self::nostore(['ok' => true, 'preview' => true, 'noindex' => true, 'event' => $e]);
    }

    /** POST /public/previews/{token}/seen {visit_id} → {ok, first} (30 min je Besuch, höchstens 60 je Stunde) */
    public static function rest_seen(WP_REST_Request $req) {
        $l = self::public_load((string) $req['token']);
        if (is_wp_error($l)) return $l;
        list($oid, $d) = $l;
        $visit = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $req->get_param('visit_id')), 0, 64);
        if ($visit === '') return new WP_Error('tix_invalid_field', 'visit_id fehlt.', ['status' => 400, 'field' => 'visit_id']);
        // Vertriebler bzw. Admin selbst zählen nicht
        $uid = get_current_user_id();
        if ($uid && ($uid === intval($d['owner']) || current_user_can('manage_options'))) return self::nostore(['ok' => true, 'first' => false, 'counted' => false]);
        $vk = 'tix_pv_v_' . md5($oid . '|' . $visit);
        if (get_transient($vk)) return self::nostore(['ok' => true, 'first' => false, 'counted' => false]);
        $hk = 'tix_pv_h_' . $oid . '_' . gmdate('YmdH');
        $n = intval(get_transient($hk));
        if ($n >= 60) return self::nostore(['ok' => true, 'first' => false, 'counted' => false]);
        set_transient($hk, $n + 1, HOUR_IN_SECONDS + 300);
        set_transient($vk, 1, 30 * MINUTE_IN_SECONDS);
        $first = empty($d['opened_at']);
        $d['views'] = intval($d['views'] ?? 0) + 1;
        $d['last_view_at'] = time();
        if ($first) $d['opened_at'] = time();
        self::save($oid, $d);
        if ($first) self::notify($oid, 'opened');
        return self::nostore(['ok' => true, 'first' => $first, 'counted' => true]);
    }

    /** GET /public/previews/claim/{claim_token} → {ok, organizer{name,logo,accent}, events_count, customer_email|null, expires_at} */
    public static function rest_claim_info(WP_REST_Request $req) {
        $oid = self::claim_target((string) $req['claim']);
        if (is_wp_error($oid)) return $oid;
        $d = self::data($oid);
        return self::nostore(['ok' => true, 'organizer' => ['name' => (string) get_the_title($oid), 'logo' => self::logo_url($oid) ?: null, 'accent' => self::accent($oid)],
            'events_count' => count(self::event_ids($oid)), 'customer_email' => (string) ($d['customer_email'] ?? '') ?: null, 'expires_at' => self::iso($d['expires_at'])]);
    }

    // ═══════════════ Übernehmen ═══════════════

    /** Übernahme-Code → Veranstalter-ID | WP_Error (404 unbekannt, 410 abgelaufen, 409 übernommen) */
    public static function claim_target($claim) {
        if (!class_exists('TIX_Sales') || !TIX_Sales::multi()) return self::err_unknown();
        $oid = self::by_token($claim, self::META_CLAIM);
        $d = $oid ? self::data($oid) : null;
        if (!$d) return self::is_gone($claim) ? self::err_expired() : self::err_unknown();
        $st = self::status($d);
        if ($st === 'claimed') return self::err_claimed();
        if ($st === 'expired') return self::err_expired();
        return $oid;
    }

    /**
     * Nach POST /register/organizer mit claim_token: Sandbox aufheben, Events (Entwürfe) und Bilder gehören dem neuen
     * Inhaber, Vertriebler wird benachrichtigt. → Zahl der übernommenen Events
     */
    public static function complete_claim($oid, $uid) {
        global $wpdb;
        $oid = intval($oid); $uid = intval($uid);
        $d = self::data($oid);
        if (!$d) return 0;
        $d['claimed_at'] = time();
        $d['claimed_by'] = $uid;
        self::save($oid, $d);
        delete_post_meta($oid, TIX_Sales::META_PREVIEW);
        TIX_Sales::flush();
        $events = self::event_ids($oid);
        foreach ($events as $eid) {
            delete_post_meta($eid, self::META_ITEM);
            $wpdb->update($wpdb->posts, ['post_author' => $uid], ['ID' => $eid]);
            clean_post_cache($eid);
        }
        foreach (self::attachments($oid, $events) as $aid) {
            delete_post_meta($aid, self::META_ITEM);
            $wpdb->update($wpdb->posts, ['post_author' => $uid], ['ID' => $aid]);
            clean_post_cache($aid);
        }
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        self::notify($oid, 'claimed');
        return count($events);
    }

    // ═══════════════ Ablauf ═══════════════

    /** Bilder der Vorschau (Meta _tix_preview, am Veranstalter oder an seinen Events). */
    private static function attachments($oid, array $events) {
        $parents = array_merge([intval($oid)], array_map('intval', $events));
        return array_map('intval', get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true,
            'post_parent__in' => $parents, 'meta_query' => [['key' => self::META_ITEM, 'compare' => 'EXISTS']]]));
    }

    private static function delete_event($eid) {
        if (!get_post_meta($eid, self::META_ITEM, true)) return; // nie echte Events
        foreach (self::attachments(0, [$eid]) as $aid) wp_delete_attachment($aid, true);
        wp_delete_post($eid, true);
    }

    /** Vorschau samt Events und Bildern löschen; $expired: Link antwortet noch 30 Tage mit 410. */
    private static function destroy($oid, $expired) {
        $d = self::data($oid);
        if (!$d || !empty($d['claimed_at']) || !get_post_meta($oid, TIX_Sales::META_PREVIEW, true)) return false;
        $events = self::event_ids($oid);
        foreach (self::attachments($oid, []) as $aid) wp_delete_attachment($aid, true);
        foreach ($events as $eid) self::delete_event($eid);
        $owner = intval($d['owner']);
        if ($owner && get_userdata($owner)) {
            $arch = get_user_meta($owner, self::META_ARCHIVE, true);
            $arch = is_array($arch) ? $arch : [];
            $arch[] = ['created_at' => intval($d['created_at']), 'opened_at' => intval($d['opened_at'] ?? 0), 'expired' => (bool) $expired, 'ended_at' => time()];
            update_user_meta($owner, self::META_ARCHIVE, array_slice($arch, -1000));
        }
        if ($expired) self::mark_gone([$d['token'], $d['claim']]);
        wp_delete_post($oid, true);
        TIX_Sales::flush();
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        return true;
    }

    /** Stündlich: abgelaufene, nicht übernommene Vorschauen löschen. → Anzahl */
    public static function expire() {
        if (!class_exists('TIX_Sales') || !TIX_Sales::multi()) return 0;
        $n = 0;
        $ids = get_posts(['post_type' => 'tix_organizer', 'post_status' => 'any', 'posts_per_page' => 200, 'fields' => 'ids', 'suppress_filters' => true,
            'meta_query' => [['key' => TIX_Sales::META_PREVIEW, 'compare' => 'EXISTS']]]);
        foreach ($ids as $oid) {
            $d = self::data($oid);
            if (!$d || self::status($d) !== 'expired') continue;
            if (self::destroy($oid, true)) $n++;
        }
        // abgelaufene Token-Hashes (älter als 30 Tage) aufräumen
        update_option(self::OPT_GONE, self::gone_list(), false);
        return $n;
    }
}

TIX_Sales_Previews::init();
