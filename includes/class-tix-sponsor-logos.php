<?php
/**
 * Sponsoren-Logos (1.38.793, Vertrag `uebergaben/vertrag-sponsoren.md`, User 13.11.).
 *
 * Bis zu 3 Hauptsponsoren und 20 weitere je Seite (evendis: je Veranstalter). Ein Event kann eigene haben (ersetzt dann) oder
 * keine zeigen. Schalter „Sponsoren anzeigen“ und Ausspielorte je Seite/Veranstalter (Website Startseite/Event-Seite/Fußzeile,
 * App Startseite/Event-Detail, Ticket/PDF, Mails). Klicks zählen über die Weiterleitung /public/sponsors/{id}/go.
 *
 * Nicht zu verwechseln mit dem Sponsor-System (Freikarten-Kontingente, TIX_Sponsor_DB) und dem Ticket-Sponsor je Event.
 * Weg-1: eigene Tabellen (JSON), Studio-/REST-Routen, Prüfregeln als reine Funktionen, keine Optionen/Postmeta je Objekt.
 */
if (!defined('ABSPATH')) exit;

class TIX_Sponsor_Logos {

    const NS = 'tixomat/v1';
    const DB_VERSION = '1';
    const OPT_DB = 'tix_sponsor_logos_db';
    const LIMITS = ['main' => 3, 'partner' => 20];
    /** Ausspielorte → Vorgabe */
    const PLACES = ['web_home' => true, 'web_event' => true, 'web_footer' => false, 'app_home' => true, 'app_event' => true, 'ticket' => false, 'mail' => false];
    const SRC = ['web', 'app', 'ticket', 'mail'];
    const MODES = ['inherit', 'own', 'none'];
    const BOTS = '#bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|slackbot|telegrambot|discordbot|curl|wget|python-requests|headless#i';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('tix_mail_sponsors_html', [__CLASS__, 'mail_html'], 10, 2);
    }

    // ═══════════════ Tabellen ═══════════════

    public static function t($k) {
        global $wpdb;
        return $wpdb->prefix . ['logos' => 'tix_sponsor_logos', 'settings' => 'tix_sponsor_settings', 'clicks' => 'tix_sponsor_clicks'][$k];
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t('logos') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scope varchar(12) NOT NULL DEFAULT 'site',
            owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            tier varchar(8) NOT NULL DEFAULT 'partner',
            name varchar(190) NOT NULL DEFAULT '',
            logo_id bigint(20) unsigned NOT NULL DEFAULT 0,
            logo_url varchar(500) NOT NULL DEFAULT '',
            logo_dark_id bigint(20) unsigned NOT NULL DEFAULT 0,
            logo_dark_url varchar(500) NOT NULL DEFAULT '',
            link varchar(500) NOT NULL DEFAULT '',
            position int(11) NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            clicks bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner (scope, owner_id)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('settings') . " (
            scope varchar(12) NOT NULL,
            owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            data longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (scope, owner_id)
        ) $c;");
        dbDelta("CREATE TABLE " . self::t('clicks') . " (
            sponsor_id bigint(20) unsigned NOT NULL,
            day date NOT NULL,
            src varchar(8) NOT NULL DEFAULT 'web',
            n int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (sponsor_id, day, src)
        ) $c;");
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    public static function ready() {
        static $ok = null;
        if ($ok) return true;
        if (get_option(self::OPT_DB) !== self::DB_VERSION) self::install();
        return $ok = (get_option(self::OPT_DB) === self::DB_VERSION);
    }

    private static function multi() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    // ═══════════════ Reine Prüfregeln ═══════════════

    /** Ausspielorte säubern (rein): nur bekannte Schlüssel, bool; fehlende = Vorgabe bzw. $base */
    public static function clean_places($in, array $base = []) {
        $out = [];
        foreach (self::PLACES as $k => $def) $out[$k] = is_array($in) && array_key_exists($k, $in) ? (bool) $in[$k] : (array_key_exists($k, $base) ? (bool) $base[$k] : $def);
        return $out;
    }

    /** Logo-Angabe (rein): {id} | {url} | null → ['id' => int, 'url' => string] | null | false (ungültig) */
    public static function clean_logo($l) {
        if ($l === null || $l === '' || $l === []) return null;
        if (is_numeric($l)) return intval($l) > 0 ? ['id' => intval($l), 'url' => ''] : null;
        if (is_string($l)) $l = ['url' => $l];
        if (!is_array($l)) return false;
        $id = intval($l['id'] ?? 0);
        if ($id > 0) return ['id' => $id, 'url' => ''];
        $u = trim((string) ($l['url'] ?? ''));
        if ($u === '') return null;
        return preg_match('#^https?://[^\s/]+\.?[^\s]*$#i', $u) && strlen($u) <= 500 ? ['id' => 0, 'url' => $u] : false;
    }

    /**
     * Liste prüfen (rein). $in = {main: [...], partners: [...]} → ['main' => [...], 'partner' => [...]] | ['error' => [code, field, msg, extra]]
     * Eintrag: {id?, name, logo, logo_dark?, link?, active?}
     */
    public static function validate($in) {
        if (!is_array($in)) return ['error' => ['tix_invalid_field', 'main', 'Ungültige Sponsoren-Liste.', []]];
        $out = ['main' => [], 'partner' => []];
        foreach (['main' => 'main', 'partners' => 'partner'] as $key => $tier) {
            if (!array_key_exists($key, $in) || $in[$key] === null) { $out[$tier] = null; continue; }
            if (!is_array($in[$key]) || ($in[$key] && array_keys($in[$key]) !== range(0, count($in[$key]) - 1))) return ['error' => ['tix_invalid_field', $key, 'Bitte eine Liste angeben.', []]];
            if (count($in[$key]) > self::LIMITS[$tier]) return ['error' => ['tix_sponsor_limit', $key, ($tier === 'main' ? 'Höchstens ' . self::LIMITS['main'] . ' Hauptsponsoren.' : 'Höchstens ' . self::LIMITS['partner'] . ' weitere Sponsoren.'), ['tier' => $tier, 'max' => self::LIMITS[$tier]]]];
            foreach (array_values($in[$key]) as $i => $s) {
                $f = $key . '.' . $i;
                if (!is_array($s)) return ['error' => ['tix_invalid_field', $f, 'Ungültiger Eintrag.', []]];
                $name = trim(wp_strip_all_tags((string) ($s['name'] ?? '')));
                if ($name === '' || mb_strlen($name) > 80) return ['error' => ['tix_invalid_field', $f . '.name', 'Name (1–80 Zeichen) fehlt.', []]];
                $logo = self::clean_logo($s['logo'] ?? null);
                if ($logo === false) return ['error' => ['tix_invalid_field', $f . '.logo', 'Logo-Adresse bitte mit https://.', []]];
                if ($logo === null) return ['error' => ['tix_invalid_field', $f . '.logo', 'Bitte ein Logo hochladen.', []]];
                $dark = self::clean_logo($s['logo_dark'] ?? null);
                if ($dark === false) return ['error' => ['tix_invalid_field', $f . '.logo_dark', 'Logo-Adresse bitte mit https://.', []]];
                $link = trim((string) ($s['link'] ?? ''));
                if ($link !== '' && (!preg_match('#^https://[^\s/]+\.[^\s]+#i', $link) || strlen($link) > 500)) return ['error' => ['tix_invalid_field', $f . '.link', 'Link bitte mit https://.', []]];
                $out[$tier][] = ['id' => intval($s['id'] ?? 0), 'name' => $name, 'logo' => $logo, 'logo_dark' => $dark, 'link' => $link,
                    'active' => !array_key_exists('active', $s) || (bool) $s['active'], 'position' => $i];
            }
        }
        return $out;
    }

    // ═══════════════ Eigentümer ═══════════════

    /** Seite bzw. Veranstalter eines Events: [scope, owner_id] */
    public static function owner_of_event($eid) {
        if (!self::multi()) return ['site', 0];
        return ['organizer', intval(get_post_meta($eid, '_tix_organizer_id', true))];
    }

    /** Studio: Seite bzw. eigener Veranstalter (Admin auf evendis: ?organizer_id=) → [scope, owner] | WP_Error */
    public static function owner_for_request(WP_REST_Request $req) {
        if (!self::multi()) return ['site', 0];
        $oid = 0;
        if (current_user_can('manage_options')) $oid = intval($req->get_param('organizer_id'));
        if (!$oid && class_exists('TIX_App_Scope')) $oid = intval(TIX_App_Scope::organizer_id_for_user());
        if (!$oid || get_post_type($oid) !== 'tix_organizer') return new WP_Error('tix_organizer_required', 'Bitte einen Veranstalter wählen (organizer_id).', ['status' => 400]);
        return ['organizer', $oid];
    }

    // ═══════════════ Daten ═══════════════

    public static function settings($scope, $owner) {
        global $wpdb;
        if (!self::ready()) return [];
        $d = $wpdb->get_var($wpdb->prepare("SELECT data FROM " . self::t('settings') . " WHERE scope = %s AND owner_id = %d", $scope, intval($owner)));
        $d = $d ? json_decode($d, true) : [];
        return is_array($d) ? $d : [];
    }

    public static function put_settings($scope, $owner, array $data) {
        global $wpdb;
        if (!self::ready()) return;
        $wpdb->replace(self::t('settings'), ['scope' => $scope, 'owner_id' => intval($owner), 'data' => wp_json_encode($data), 'updated_at' => current_time('mysql', true)]);
    }

    public static function rows($scope, $owner, $active_only = false) {
        global $wpdb;
        if (!self::ready()) return [];
        $r = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::t('logos') . " WHERE scope = %s AND owner_id = %d" . ($active_only ? ' AND active = 1' : '') . " ORDER BY tier = 'main' DESC, position ASC, id ASC", $scope, intval($owner)), ARRAY_A);
        return is_array($r) ? $r : [];
    }

    /** Liste ersetzen (eine Stufe; null = unverändert). Klickzähler bleiben an den IDs. */
    public static function replace($scope, $owner, $clean) {
        global $wpdb;
        $t = self::t('logos');
        $now = current_time('mysql', true);
        foreach (['main', 'partner'] as $tier) {
            if (!is_array($clean[$tier] ?? null)) continue;
            $have = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE scope = %s AND owner_id = %d AND tier = %s", $scope, intval($owner), $tier)));
            $all = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE scope = %s AND owner_id = %d", $scope, intval($owner))));
            $keep = [];
            foreach ($clean[$tier] as $s) {
                $row = ['tier' => $tier, 'name' => $s['name'], 'logo_id' => $s['logo']['id'], 'logo_url' => $s['logo']['url'],
                    'logo_dark_id' => $s['logo_dark']['id'] ?? 0, 'logo_dark_url' => $s['logo_dark']['url'] ?? '', 'link' => $s['link'],
                    'position' => $s['position'], 'active' => $s['active'] ? 1 : 0, 'updated_at' => $now];
                if ($s['id'] && in_array($s['id'], $all, true)) { $wpdb->update($t, $row, ['id' => $s['id']]); $keep[] = $s['id']; }
                else { $wpdb->insert($t, $row + ['scope' => $scope, 'owner_id' => intval($owner), 'created_at' => $now, 'clicks' => 0]); $keep[] = intval($wpdb->insert_id); }
            }
            foreach (array_diff($have, $keep) as $gone) { $wpdb->delete($t, ['id' => $gone]); $wpdb->delete(self::t('clicks'), ['sponsor_id' => $gone]); }
        }
    }

    // ═══════════════ Ausgabe ═══════════════

    private static function img($id, $url) {
        if ($id) {
            $s = wp_get_attachment_image_src($id, 'large');
            if ($s) return ['url' => (string) $s[0], 'w' => intval($s[1]), 'h' => intval($s[2])];
        }
        return $url !== '' ? ['url' => $url, 'w' => null, 'h' => null] : null;
    }

    public static function go_url($id) {
        return rest_url(self::NS . '/public/sponsors/' . intval($id) . '/go');
    }

    /** Öffentlicher Eintrag */
    public static function pub($r) {
        $l = self::img(intval($r['logo_id']), (string) $r['logo_url']);
        $d = self::img(intval($r['logo_dark_id']), (string) $r['logo_dark_url']);
        return ['name' => (string) $r['name'], 'logo' => $l['url'] ?? null, 'logo_dark' => $d['url'] ?? null, 'w' => $l['w'] ?? null, 'h' => $l['h'] ?? null,
            'link' => (string) $r['link'] !== '' ? self::go_url($r['id']) : null, 'target' => (string) $r['link'] !== '' ? (string) $r['link'] : null];
    }

    private static function pub_lists(array $rows) {
        $o = ['main' => [], 'partners' => []];
        foreach ($rows as $r) if (intval($r['active'])) $o[$r['tier'] === 'main' ? 'main' : 'partners'][] = self::pub($r);
        return $o;
    }

    public static function owner_state($scope, $owner) {
        $s = self::settings($scope, $owner);
        return ['enabled' => !empty($s['enabled']), 'places' => self::clean_places($s['places'] ?? null)];
    }

    /** Seite/Veranstalter öffentlich: {places, main, partners} | null (aus oder leer) */
    public static function owner_public($scope, $owner) {
        if (!self::ready()) return null;
        $st = self::owner_state($scope, $owner);
        if (!$st['enabled']) return null;
        $l = self::pub_lists(self::rows($scope, $owner, true));
        if (!$l['main'] && !$l['partners']) return null;
        return ['places' => $st['places']] + $l;
    }

    public static function event_mode($eid) {
        $s = self::settings('event', $eid);
        return in_array($s['mode'] ?? '', array_merge(self::MODES, ['snapshot']), true) ? $s['mode'] : 'inherit';
    }

    /** Wirksame Sponsoren eines Events öffentlich (Spiegel/Kopie: Schnappschuss der Quelle) | null */
    public static function event_public($eid) {
        $eid = intval($eid);
        if (!$eid || !self::ready()) return null;
        $s = self::settings('event', $eid);
        $mode = $s['mode'] ?? 'inherit';
        if ($mode === 'snapshot') return is_array($s['pub'] ?? null) ? $s['pub'] : null;
        if (class_exists('TIX_Source_Lock') && TIX_Source_Lock::source_of($eid)) return null; // Spiegel/Kopie ohne Schnappschuss: nichts Fremdes zeigen
        if ($mode === 'none') return null;
        [$scope, $owner] = self::owner_of_event($eid);
        if ($mode !== 'own') return self::owner_public($scope, $owner);
        $st = self::owner_state($scope, $owner);
        if (!$st['enabled']) return null;
        $l = self::pub_lists(self::rows('event', $eid, true));
        if (!$l['main'] && !$l['partners']) return null;
        return ['places' => $st['places']] + $l;
    }

    /** Schnappschuss der Quelle auf Spiegel/Kopie speichern (Verteilung/Abgleich); null = keine */
    public static function store_snapshot($eid, $pub) {
        if (!self::ready() || !intval($eid)) return;
        $clean = null;
        if (is_array($pub)) {
            $clean = ['places' => self::clean_places($pub['places'] ?? null), 'main' => [], 'partners' => []];
            foreach (['main' => 3, 'partners' => 20] as $k => $max) {
                foreach (array_slice(array_values((array) ($pub[$k] ?? [])), 0, $max) as $p) {
                    if (!is_array($p) || empty($p['logo']) || !preg_match('#^https?://#i', (string) $p['logo'])) continue;
                    $u = function ($v) { $v = (string) $v; return preg_match('#^https?://#i', $v) ? esc_url_raw($v) : null; };
                    $clean[$k][] = ['name' => sanitize_text_field((string) ($p['name'] ?? '')), 'logo' => $u($p['logo']), 'logo_dark' => $u($p['logo_dark'] ?? ''),
                        'w' => isset($p['w']) ? intval($p['w']) : null, 'h' => isset($p['h']) ? intval($p['h']) : null, 'link' => $u($p['link'] ?? ''), 'target' => $u($p['target'] ?? '')];
                }
            }
            if (!$clean['main'] && !$clean['partners']) $clean = null;
        }
        self::put_settings('event', $eid, ['mode' => 'snapshot', 'pub' => $clean]);
    }

    /** Für einen Ort: Liste oder null */
    public static function for_place($pub, $place) {
        return is_array($pub) && !empty($pub['places'][$place]) ? $pub : null;
    }

    /** Website-Config (Seite): {places, main, partners} | null – evendis: keine plattformweite Liste (User 13.11.) */
    public static function site_public() {
        return self::multi() ? null : self::owner_public('site', 0);
    }

    /** App-Config: Seite bzw. Veranstalter (?organizer=) */
    public static function app_public($oid = 0) {
        if (!self::multi()) return self::owner_public('site', 0);
        return $oid ? self::owner_public('organizer', $oid) : null;
    }

    /** Caches leeren, Websites neu laden (Seite, Veranstalter bzw. Event) */
    private static function flush_public($scope = 'site', $owner = 0) {
        if (class_exists('TIX_Public_Events')) TIX_Public_Events::flush();
        if (!class_exists('TIX_Web_Revalidate')) return;
        if ($scope === 'site') TIX_Web_Revalidate::touch_site();
        elseif ($scope === 'organizer' && $owner && method_exists('TIX_Web_Revalidate', 'touch_org')) TIX_Web_Revalidate::touch_org($owner);
        elseif ($scope === 'event' && $owner && method_exists('TIX_Web_Revalidate', 'touch_event')) TIX_Web_Revalidate::touch_event($owner);
    }

    // ═══════════════ Ticket/PDF und Mails ═══════════════

    /** Mail-Leiste (Filter tix_mail_sponsors_html): $m['event_id'] bzw. Seite; nur Kunden-Mails, Ort „mail“ */
    public static function mail_html($html, $m) {
        $kind = (string) ($m['kind'] ?? 'customer');
        if (!in_array($kind, ['customer', 'promo'], true)) return $html;
        $eid = intval($m['event_id'] ?? 0);
        $pub = $eid ? self::event_public($eid) : (self::multi() ? (intval($m['organizer_id'] ?? 0) ? self::owner_public('organizer', intval($m['organizer_id'])) : null) : self::owner_public('site', 0));
        $pub = self::for_place($pub, 'mail');
        if (!$pub) return $html;
        $cell = function ($p, $hgt) {
            $img = '<img src="' . esc_url($p['logo']) . '" alt="' . esc_attr($p['name']) . '" height="' . $hgt . '" style="display:inline-block;height:' . $hgt . 'px;width:auto;max-width:160px;border:0;margin:6px 10px;">';
            return $p['link'] ? '<a href="' . esc_url(add_query_arg('src', 'mail', $p['link'])) . '" style="text-decoration:none;">' . $img . '</a>' : $img;
        };
        $out = '<tr><td class="tm-foot" style="padding:8px 24px 4px;text-align:center;font-size:11px;color:#8A8A94;">';
        if ($pub['main']) $out .= '<div style="margin-bottom:2px;">' . esc_html(count($pub['main']) > 1 ? 'Unsere Hauptsponsoren' : 'Unser Hauptsponsor') . '</div><div>' . implode('', array_map(function ($p) use ($cell) { return $cell($p, 40); }, $pub['main'])) . '</div>';
        if ($pub['partners']) $out .= '<div style="margin:8px 0 2px;">' . esc_html('Mit freundlicher Unterstützung von') . '</div><div>' . implode('', array_map(function ($p) use ($cell) { return $cell($p, 24); }, $pub['partners'])) . '</div>';
        return $out . '</td></tr>';
    }

    /** Logo-Bild für GD laden (lokaler Anhang bzw. URL, kurz zwischengespeichert) */
    private static function gd_logo($p) {
        $url = (string) ($p['logo'] ?? '');
        if ($url === '') return null;
        $data = null;
        $aid = attachment_url_to_postid($url);
        if (!$aid && preg_match('#-\d+x\d+(\.[a-z]+)$#i', $url)) $aid = attachment_url_to_postid(preg_replace('#-\d+x\d+(\.[a-z]+)$#i', '$1', $url));
        if ($aid && ($f = get_attached_file($aid)) && is_readable($f)) $data = @file_get_contents($f);
        if ($data === null || $data === false) {
            $ck = 'tix_spl_' . md5($url);
            $c = get_transient($ck);
            if (is_string($c)) $data = base64_decode($c);
            else {
                $r = wp_safe_remote_get($url, ['timeout' => 4, 'limit_response_size' => 1500000]);
                $data = !is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200 ? (string) wp_remote_retrieve_body($r) : '';
                if ($data !== '' && strlen($data) < 400000) set_transient($ck, base64_encode($data), DAY_IN_SECONDS);
            }
        }
        if (!$data) return null;
        $im = @imagecreatefromstring($data);
        return $im ?: null;
    }

    /** Ticket (GD-Vorlage): Sponsoren-Leiste unten anfügen (Ort „ticket“). Gibt das (neue) Bild zurück. */
    public static function ticket_gd($gd, $event_id) {
        if (!$gd || !function_exists('imagecreatetruecolor')) return $gd;
        $pub = self::for_place(self::event_public($event_id), 'ticket');
        if (!$pub) return $gd;
        $list = $pub['main'] ?: array_slice($pub['partners'], 0, 6);
        $logos = [];
        foreach ($list as $p) { $im = self::gd_logo($p); if ($im) $logos[] = $im; }
        if (!$logos) return $gd;
        $w = imagesx($gd); $h = imagesy($gd);
        $bar = max(60, intval($w * 0.09));
        $out = imagecreatetruecolor($w, $h + $bar);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopy($out, $gd, 0, 0, 0, 0, $w, $h);
        $lh = intval($bar * 0.62); $gap = intval($bar * 0.35);
        $sizes = [];
        foreach ($logos as $im) { $iw = imagesx($im); $ih = imagesy($im); $nw = max(1, intval($iw * $lh / max(1, $ih))); $sizes[] = min($nw, intval($w / max(1, count($logos))) - $gap); }
        $total = array_sum($sizes) + $gap * (count($logos) - 1);
        $x = max(0, intval(($w - $total) / 2));
        foreach ($logos as $i => $im) {
            $nw = $sizes[$i]; $nh = intval(imagesy($im) * $nw / max(1, imagesx($im)));
            imagecopyresampled($out, $im, $x, $h + intval(($bar - $nh) / 2), 0, 0, $nw, $nh, imagesx($im), imagesy($im));
            $x += $nw + $gap;
            imagedestroy($im);
        }
        imagedestroy($gd);
        return $out;
    }

    /** Ticket (HTML-Fassung): Leiste */
    public static function ticket_html($event_id) {
        $pub = self::for_place(self::event_public($event_id), 'ticket');
        if (!$pub) return '';
        $list = $pub['main'] ?: array_slice($pub['partners'], 0, 6);
        $h = '';
        foreach ($list as $p) $h .= '<img src="' . esc_url($p['logo']) . '" alt="' . esc_attr($p['name']) . '" style="height:36px;width:auto;max-width:140px;margin:4px 10px;">';
        return '<div class="ticket-sponsors" style="max-width:600px;margin:12px auto 0;text-align:center;">' . $h . '</div>';
    }

    // ═══════════════ Klicks ═══════════════

    public static function count_click($id, $src, $ua = '') {
        global $wpdb;
        if (!self::ready() || preg_match(self::BOTS, (string) $ua)) return false;
        $src = in_array($src, self::SRC, true) ? $src : 'web';
        $wpdb->query($wpdb->prepare("INSERT INTO " . self::t('clicks') . " (sponsor_id, day, src, n) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE n = n + 1", intval($id), gmdate('Y-m-d'), $src));
        $wpdb->query($wpdb->prepare("UPDATE " . self::t('logos') . " SET clicks = clicks + 1 WHERE id = %d", intval($id)));
        return true;
    }

    public static function click_stats(array $ids, $days = 30) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !self::ready()) return [];
        $from = gmdate('Y-m-d', time() - max(1, intval($days)) * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT sponsor_id, src, SUM(n) n FROM " . self::t('clicks') . " WHERE sponsor_id IN (" . implode(',', $ids) . ") AND day >= %s GROUP BY sponsor_id, src", $from), ARRAY_A);
        $o = [];
        foreach ((array) $rows as $r) { $o[intval($r['sponsor_id'])][$r['src']] = intval($r['n']); }
        return $o;
    }

    public static function rest_go(WP_REST_Request $req) {
        global $wpdb;
        $id = absint($req['id']);
        $link = self::ready() ? (string) $wpdb->get_var($wpdb->prepare("SELECT link FROM " . self::t('logos') . " WHERE id = %d", $id)) : '';
        if ($link === '' || !preg_match('#^https://#i', $link)) return new WP_Error('tix_sponsor_not_found', 'Link nicht gefunden.', ['status' => 404]);
        self::count_click($id, (string) $req->get_param('src'), (string) $req->get_header('user_agent'));
        $r = new WP_REST_Response(null, 302);
        $r->header('Location', $link);
        $r->header('Cache-Control', 'no-store');
        $r->header('X-Robots-Tag', 'noindex, nofollow');
        return $r;
    }

    // ═══════════════ Routen ═══════════════

    public static function routes() {
        register_rest_route(self::NS, '/studio/sponsors', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => [__CLASS__, 'perm_read']],
            ['methods' => 'PUT, POST', 'callback' => [__CLASS__, 'rest_put'], 'permission_callback' => [__CLASS__, 'perm_write']],
        ]);
        register_rest_route(self::NS, '/studio/sponsors/logo', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_logo'], 'permission_callback' => [__CLASS__, 'perm_logo']]);
        register_rest_route(self::NS, '/events/(?P<id>\d+)/sponsors', [
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_event_get'], 'permission_callback' => [__CLASS__, 'perm_event']],
            ['methods' => 'PUT, POST', 'callback' => [__CLASS__, 'rest_event_put'], 'permission_callback' => [__CLASS__, 'perm_event']],
        ]);
        register_rest_route(self::NS, '/public/sponsors/(?P<id>\d+)/go', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_go'], 'permission_callback' => '__return_true']);
    }

    public static function perm_read(WP_REST_Request $req) {
        return TIX_REST_API::check_organizer($req);
    }

    /** Ändern: Admin; Einzel-Seite auch der Inhaber; evendis Inhaber/Team-Admin des eigenen Veranstalters */
    public static function perm_write(WP_REST_Request $req) {
        if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'Authentifizierung erforderlich.', ['status' => 401]);
        $u = wp_get_current_user();
        if ($u->has_cap('manage_options')) return true;
        if (!self::multi() && in_array('tix_organizer', (array) $u->roles, true)) return true;
        if (self::multi() && class_exists('TIX_App_Scope') && TIX_App_Scope::is_org_manager() && TIX_App_Scope::organizer_id_for_user()) return true;
        return new WP_Error('rest_forbidden', 'Sponsoren ändern dürfen nur Inhaber bzw. Admins.', ['status' => 403]);
    }

    public static function perm_logo(WP_REST_Request $req) {
        $eid = absint($req->get_param('event_id'));
        if ($eid) return self::perm_event($req, $eid);
        return self::perm_write($req);
    }

    public static function perm_event(WP_REST_Request $req, $eid = 0) {
        $c = TIX_REST_API::check_organizer($req);
        if ($c !== true) return $c;
        $id = $eid ?: absint($req['id']);
        if (get_post_type($id) !== 'event') return new WP_Error('tix_not_found', 'Event nicht gefunden.', ['status' => 404]);
        if (!TIX_REST_API::user_can_access_event($id)) return new WP_Error('forbidden', 'Kein Zugriff auf dieses Event.', ['status' => 403]);
        return true;
    }

    private static function studio_item($r, $stats) {
        $l = self::img(intval($r['logo_id']), (string) $r['logo_url']);
        $d = self::img(intval($r['logo_dark_id']), (string) $r['logo_dark_url']);
        $s = $stats[intval($r['id'])] ?? [];
        return ['id' => intval($r['id']), 'name' => (string) $r['name'],
            'logo' => $l ? ['id' => intval($r['logo_id']) ?: null, 'url' => $l['url']] : null,
            'logo_dark' => $d ? ['id' => intval($r['logo_dark_id']) ?: null, 'url' => $d['url']] : null,
            'link' => (string) $r['link'], 'active' => (bool) intval($r['active']), 'position' => intval($r['position']),
            'clicks' => ['total' => intval($r['clicks']), 'days30' => array_sum($s), 'by_source' => (object) $s]];
    }

    private static function studio_lists($scope, $owner) {
        $rows = self::rows($scope, $owner);
        $stats = self::click_stats(array_column($rows, 'id'));
        $o = ['main' => [], 'partners' => []];
        foreach ($rows as $r) $o[$r['tier'] === 'main' ? 'main' : 'partners'][] = self::studio_item($r, $stats);
        return $o;
    }

    public static function rest_get(WP_REST_Request $req) {
        $ow = self::owner_for_request($req);
        if (is_wp_error($ow)) return $ow;
        [$scope, $owner] = $ow;
        return rest_ensure_response(['ok' => true, 'owner' => ['scope' => $scope, 'id' => $owner]] + self::owner_state($scope, $owner)
            + ['limits' => ['main' => self::LIMITS['main'], 'partners' => self::LIMITS['partner']], 'place_keys' => array_keys(self::PLACES)] + self::studio_lists($scope, $owner));
    }

    private static function err($v) {
        [$code, $field, $msg, $extra] = $v['error'];
        return new WP_Error($code, $msg, ['status' => $code === 'tix_sponsor_limit' ? 409 : 400, 'field' => $field] + $extra);
    }

    /** Logos prüfen: eigene Anhänge (evendis: hochgeladen von dir oder schon in dieser Liste) */
    private static function logos_allowed($clean, $scope, $owner) {
        $known = [];
        foreach (self::rows($scope, $owner) as $r) { $known[intval($r['logo_id'])] = 1; $known[intval($r['logo_dark_id'])] = 1; }
        foreach (['main', 'partner'] as $tier) foreach ((array) ($clean[$tier] ?? []) as $i => $s) foreach (['logo', 'logo_dark'] as $k) {
            $id = intval($s[$k]['id'] ?? 0);
            if (!$id) continue;
            $ok = get_post_type($id) === 'attachment' && strpos((string) get_post_mime_type($id), 'image/') === 0
                && (!self::multi() || current_user_can('manage_options') || isset($known[$id]) || intval(get_post_field('post_author', $id)) === get_current_user_id());
            if (!$ok) return new WP_Error('tix_invalid_field', 'Dieses Bild ist nicht verfügbar.', ['status' => 400, 'field' => ($tier === 'main' ? 'main' : 'partners') . '.' . $i . '.' . $k]);
        }
        return true;
    }

    public static function rest_put(WP_REST_Request $req) {
        $ow = self::owner_for_request($req);
        if (is_wp_error($ow)) return $ow;
        [$scope, $owner] = $ow;
        $b = (array) $req->get_json_params();
        $clean = self::validate(['main' => array_key_exists('main', $b) ? $b['main'] : null, 'partners' => array_key_exists('partners', $b) ? $b['partners'] : null]);
        if (isset($clean['error'])) return self::err($clean);
        $ok = self::logos_allowed($clean, $scope, $owner);
        if (is_wp_error($ok)) return $ok;
        $st = self::settings($scope, $owner);
        if (array_key_exists('enabled', $b)) $st['enabled'] = (bool) $b['enabled'];
        if (array_key_exists('places', $b)) {
            if (!is_array($b['places'])) return new WP_Error('tix_invalid_field', 'Ausspielorte als Objekt angeben.', ['status' => 400, 'field' => 'places']);
            foreach (array_keys($b['places']) as $k) if (!array_key_exists($k, self::PLACES)) return new WP_Error('tix_invalid_field', 'Unbekannter Ausspielort „' . $k . '“.', ['status' => 400, 'field' => 'places.' . $k]);
            $st['places'] = self::clean_places($b['places'], self::clean_places($st['places'] ?? null));
        }
        self::put_settings($scope, $owner, $st);
        self::replace($scope, $owner, $clean);
        self::flush_public($scope, $owner);
        return self::rest_get($req);
    }

    public static function rest_logo(WP_REST_Request $req) {
        $files = $req->get_file_params();
        $file = $files['image'] ?? ($files['file'] ?? ($files['logo'] ?? null));
        if (empty($file['tmp_name'])) return new WP_Error('no_file', 'Kein Bild hochgeladen.', ['status' => 400, 'field' => 'image']);
        if (!empty($file['error'])) return new WP_Error('upload_failed', 'Upload fehlgeschlagen (höchstens 5 MB).', ['status' => in_array(intval($file['error']), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400, 'field' => 'image']);
        if (intval($file['size'] ?? 0) > 5 * MB_IN_BYTES) return new WP_Error('too_large', 'Das Bild ist zu groß (höchstens 5 MB).', ['status' => 413, 'field' => 'image']);
        $info = @getimagesize($file['tmp_name']);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return new WP_Error('bad_type', 'Bitte ein PNG-, JPG- oder WebP-Bild wählen (PNG mit transparentem Hintergrund ist ideal).', ['status' => 400, 'field' => 'image']);
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $variant = (string) $req->get_param('variant') === 'dark' ? '-dunkel' : '';
        $aid = media_handle_sideload(['name' => 'sponsor-logo' . $variant . '-' . time() . '.' . $ext, 'tmp_name' => $file['tmp_name'], 'type' => $mime, 'size' => $file['size'] ?? 0], absint($req->get_param('event_id')), 'Sponsoren-Logo');
        if (is_wp_error($aid)) return new WP_Error('upload_failed', $aid->get_error_message(), ['status' => 500]);
        $s = wp_get_attachment_image_src($aid, 'large');
        return rest_ensure_response(['ok' => true, 'image_id' => intval($aid), 'url' => (string) ($s[0] ?? ''), 'width' => intval($s[1] ?? 0), 'height' => intval($s[2] ?? 0)]);
    }

    public static function rest_event_get(WP_REST_Request $req) {
        $eid = absint($req['id']);
        [$scope, $owner] = self::owner_of_event($eid);
        $mode = self::event_mode($eid);
        $inh = self::owner_state($scope, $owner) + self::studio_lists($scope, $owner);
        return rest_ensure_response(['ok' => true, 'mode' => $mode === 'snapshot' ? 'inherit' : $mode, 'shared' => $mode === 'snapshot',
            'limits' => ['main' => self::LIMITS['main'], 'partners' => self::LIMITS['partner']], 'inherited' => $inh, 'effective' => self::event_public($eid)] + self::studio_lists('event', $eid));
    }

    public static function rest_event_put(WP_REST_Request $req) {
        $eid = absint($req['id']);
        $b = (array) $req->get_json_params();
        $mode = (string) ($b['mode'] ?? '');
        if ($mode !== '' && !in_array($mode, self::MODES, true)) return new WP_Error('tix_invalid_field', 'mode: inherit, own oder none.', ['status' => 400, 'field' => 'mode']);
        $clean = self::validate(['main' => array_key_exists('main', $b) ? $b['main'] : null, 'partners' => array_key_exists('partners', $b) ? $b['partners'] : null]);
        if (isset($clean['error'])) return self::err($clean);
        $ok = self::logos_allowed($clean, 'event', $eid);
        if (is_wp_error($ok)) return $ok;
        if ($mode !== '') self::put_settings('event', $eid, ['mode' => $mode]);
        self::replace('event', $eid, $clean);
        self::flush_public('event', $eid);
        if (class_exists('TIX_Syndication_Push') && get_post_meta($eid, '_tix_syndicate', true) === '1') TIX_Syndication_Push::push_event($eid); // an die Spiegel
        if (class_exists('TIX_Co_Sites') && method_exists('TIX_Co_Sites', 'sync_sites_of_event')) TIX_Co_Sites::sync_sites_of_event($eid); // Kunden-Kopien
        return self::rest_event_get($req);
    }
}
