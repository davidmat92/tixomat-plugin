<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Site_Brand – Homepage-Marke zur Laufzeit (1.38.673, Doku §94).
 *
 * GET /public/site-config liefert zusätzlich `brand`: alles, was die Marken-Website (`apps/marken-web`) bisher aus
 * `brands.ts` liest, schon mit Rückfällen aus den übrigen Bereichen gefüllt. Gespeichert wird nur, was abweicht
 * (Option `tix_site_config`, Bereich `brand`). `brand.ready` = Marke ausdrücklich gespeichert (Studio bzw. `kunde-anlegen`)
 * – nur dann baut marken-web die Homepage zur Laufzeit; feste Marken in brands.ts haben Vorrang.
 *
 * Schreiben: POST /studio/site-config {brand: {…}} (Teilfelder; Feld null = zurück auf berechnet; brand: null = alles zurück),
 * WP-CLI `wp tixomat marke setzen --daten='{…}'`, `wp tixomat marke zeigen`, `wp tixomat marke zuruecksetzen`.
 */
class TIX_Site_Brand {

    const COLORS = ['--bg', '--bg-light', '--surface', '--surface-raised', '--muted-surface', '--night', '--signal', '--spotlight', '--teal', '--presale', '--free', '--cta-bg', '--cta-fg'];
    const TEXT = ['key' => 40, 'name' => 80, 'short_name' => 40, 'in_venue' => 80, 'hashtag' => 60, 'description' => 300];
    const BOOL = ['single_venue', 'no_app'];
    const URLS = ['logo', 'icon', 'placeholder'];
    const DISPLAY = ['manrope', 'anton'];
    const LAYOUTS = ['club', 'festival', 'festivalTour'];
    const SOCIAL_LABELS = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'spotify' => 'Spotify', 'soundcloud' => 'SoundCloud', 'whatsapp' => 'WhatsApp'];
    const DEFAULT_BG = '#17181F';

    public static function init() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat marke', [__CLASS__, 'cli']);
    }

    // ═══════════════ Lesen ═══════════════

    private static function host() {
        $h = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::host() : '';
        if ($h === '') $h = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return (string) preg_replace('/^www\./', '', $h);
    }

    /** Link der Seite → Pfad (eigene Adresse), fremde Links bleiben absolut. */
    private static function href($url) {
        $url = (string) $url;
        $h = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($h === '') return $url === '' ? '/' : $url;
        $own = [self::host(), strtolower((string) wp_parse_url((string) get_option('home'), PHP_URL_HOST))];
        if (in_array(preg_replace('/^www\./', '', $h), array_map(function ($x) { return preg_replace('/^www\./', '', $x); }, $own), true)) {
            $p = (string) wp_parse_url($url, PHP_URL_PATH);
            $q = (string) wp_parse_url($url, PHP_URL_QUERY);
            $f = (string) wp_parse_url($url, PHP_URL_FRAGMENT);
            return ($p !== '' ? $p : '/') . ($q !== '' ? '?' . $q : '') . ($f !== '' ? '#' . $f : '');
        }
        return $url;
    }

    /**
     * `brand` aus dem fertigen site-config-Payload ($c, ohne brand) und der gespeicherten Abweichung ($st).
     */
    public static function payload(array $c, $st) {
        $st = is_array($st) ? $st : [];
        $name = (string) ($st['name'] ?? '') ?: (string) ($c['name'] ?? '');
        $layout = in_array($st['layout'] ?? null, self::LAYOUTS, true) ? $st['layout'] : (in_array($c['profile'] ?? null, self::LAYOUTS, true) ? $c['profile'] : 'club');
        $host = self::host();
        $app_cfg = class_exists('TIX_App_Config') ? TIX_App_Config::stored() : [];
        $app_contact = is_array($app_cfg['contact'] ?? null) ? $app_cfg['contact'] : [];
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();

        // Social → Liste wie brands.ts; Instagram mit Handle
        $social = [];
        foreach ((array) ($c['social'] ?? []) as $k => $u) {
            if (!$u) continue;
            $social[] = ['label' => self::SOCIAL_LABELS[$k] ?? ucfirst((string) $k), 'url' => (string) $u, 'icon' => (string) $k];
        }
        $insta = null;
        if (!empty($c['social']['instagram'])) {
            $iu = (string) $c['social']['instagram'];
            $handle = preg_match('~instagram\.com/([A-Za-z0-9._]+)~i', $iu, $m) ? '@' . $m[1] : '';
            $insta = ['url' => $iu, 'handle' => $handle];
        }
        if (array_key_exists('instagram', $st)) $insta = $st['instagram'];

        $nav = [];
        foreach ((array) ($c['nav']['header'] ?? []) as $it) {
            if (empty($it['label'])) continue;
            $nav[] = ['label' => (string) $it['label'], 'href' => self::href($it['url'] ?? '/')];
        }

        $ct = (array) ($c['contact'] ?? []);
        $wa = (string) ($app_contact['whatsapp'] ?? '');
        if ($wa === '' && !empty($c['social']['whatsapp']) && preg_match('~wa\.me/(\d+)~', (string) $c['social']['whatsapp'], $wm)) $wa = $wm[1];
        $contact = [
            'email' => (string) ($ct['email'] ?? ''), 'address' => (string) ($ct['address'] ?? ''), 'company' => (string) ($ct['company'] ?? ''),
            'whatsapp' => $wa !== '' ? preg_replace('/\D/', '', $wa) : null, 'phone' => !empty($ct['phone']) ? (string) $ct['phone'] : null,
        ];

        $desc = (string) ($st['description'] ?? '') ?: (string) ($c['seo']['description'] ?? '');
        $logo = $st['logo'] ?? ($c['logo']['dark'] ?? null);
        $placeholder = $st['placeholder'] ?? ($c['event_placeholder']['url'] ?? null);
        $icon = $st['icon'] ?? ($c['icon'] ?? null) ?: $logo;
        $hashtag = array_key_exists('hashtag', $st) ? $st['hashtag'] : ((string) ($app_cfg['home']['hashtag'] ?? '') ?: null);
        $display = in_array($st['display'] ?? null, self::DISPLAY, true) ? $st['display'] : 'manrope';
        $accent = (string) ($c['colors']['accent'] ?? '') ?: '#FC2F55';

        $b = [
            'ready' => !empty($st),
            'key' => (string) ($st['key'] ?? '') ?: (sanitize_key(explode('.', $host)[0] ?? '') ?: 'marke'),
            'name' => $name,
            'short_name' => (string) ($st['short_name'] ?? '') ?: $name,
            'in_venue' => (string) ($st['in_venue'] ?? '') ?: (($layout === 'club' ? 'im ' : 'beim ') . $name),
            'site_host' => $host,
            'api_base' => 'https://' . $host . '/wp-json/tixomat/v1',
            'layout' => $layout,
            'single_venue' => array_key_exists('single_venue', $st) ? (bool) $st['single_venue'] : !$multi,
            'display' => $display,
            'logo' => $logo ?: null,
            'icon' => $icon ?: null,
            'placeholder' => $placeholder ?: null,
            'hashtag' => $hashtag ?: null,
            'instagram' => $insta,
            'nav' => isset($st['nav']) ? $st['nav'] : $nav,
            'nav_cta' => $st['nav_cta'] ?? null,
            'contact' => isset($st['contact']) ? array_replace($contact, $st['contact']) : $contact,
            'social' => isset($st['social']) ? $st['social'] : $social,
            'about' => array_replace(['eyebrow' => $layout === 'club' ? 'Der Club' : 'Das Festival', 'title' => $name, 'text' => $desc], (array) ($st['about'] ?? [])),
            'description' => $desc,
            'tickets_path' => (string) ($st['tickets_path'] ?? '') ?: '/tickets/',
            'page_slugs' => (object) ($st['page_slugs'] ?? []),
            'wp_paths' => array_values((array) ($st['wp_paths'] ?? [])),
            'redirects' => (object) ($st['redirects'] ?? []),
            'store' => [
                'app_store_id' => (string) ($c['app']['app_store_id'] ?? ''),
                'play_package' => (string) ($c['app']['play_package'] ?? ''),
                'play_public' => (bool) ($c['app']['play_public'] ?? false),
            ],
            'no_app' => array_key_exists('no_app', $st) ? (bool) $st['no_app'] : empty($c['app']['has_app']),
            'colors' => self::colors($accent, (array) ($st['colors'] ?? [])),
            'stored' => array_keys($st),
        ];
        return $b;
    }

    // ═══════════════ Farben ═══════════════

    private static function rgb($hex) {
        $h = ltrim((string) $hex, '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        if (!preg_match('/^[0-9a-f]{6}$/i', $h)) return [0, 0, 0];
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    private static function hex(array $c) {
        return sprintf('#%02X%02X%02X', max(0, min(255, (int) round($c[0]))), max(0, min(255, (int) round($c[1]))), max(0, min(255, (int) round($c[2]))));
    }

    /** a → b um t (0–1) */
    public static function mix($a, $b, $t) {
        $x = self::rgb($a); $y = self::rgb($b);
        return self::hex([$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t]);
    }

    /** Relative Helligkeit nach WCAG (0–1). */
    public static function luminance($hex) {
        $l = array_map(function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4); }, self::rgb($hex));
        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    }

    /**
     * Ganze Palette: gespeicherte Werte gewinnen, der Rest aus Akzent und Grund (dunkles Design wie die Apps).
     */
    public static function colors($accent, array $st) {
        $bg = isset($st['--bg']) && preg_match('/^#[0-9a-f]{6}$/i', $st['--bg']) ? strtoupper($st['--bg']) : self::DEFAULT_BG;
        $glow = self::mix($bg, $accent, 0.35);
        $cta_fg = self::luminance($accent) > 0.45 ? '#131020' : '#FFFFFF';
        $d = [
            '--bg' => $bg,
            '--bg-light' => 'radial-gradient(135% 95% at 50% -12%, ' . $glow . ' 0%, ' . self::mix($bg, $glow, 0.45) . ' 45%, ' . $bg . ' 100%)',
            '--surface' => self::mix($bg, '#FFFFFF', 0.04),
            '--surface-raised' => self::mix($bg, '#FFFFFF', 0.14),
            '--muted-surface' => self::mix($bg, '#FFFFFF', 0.10),
            '--night' => self::mix($bg, '#000000', 0.35),
            '--signal' => strtoupper($accent),
            '--spotlight' => '#F5B731',
            '--teal' => '#14B8A6',
            '--presale' => '#1F8F5F',
            '--free' => strtoupper($accent),
            '--cta-bg' => strtoupper($accent),
            '--cta-fg' => $cta_fg,
        ];
        foreach (self::COLORS as $k) if (isset($st[$k]) && $st[$k] !== '') $d[$k] = $st[$k];
        return $d;
    }

    // ═══════════════ Schreiben ═══════════════

    private static function bad($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => 'brand.' . $field]);
    }

    private static function url($v, $field) {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (!preg_match('#^(https?://|/)#i', $v)) return self::bad($field, 'Bitte eine Adresse mit https:// oder einen Pfad mit / angeben.');
        return esc_url_raw($v);
    }

    private static function path($v, $field) {
        $v = trim((string) $v);
        if (!preg_match('#^/[A-Za-z0-9._~\-/*?=&%\#]*$#', $v) || strlen($v) > 200) return self::bad($field, 'Bitte einen Pfad mit / am Anfang angeben.');
        return $v;
    }

    /** Link für nav/nav_cta/social: Pfad (/…, /#…) oder https-Adresse. */
    private static function link($v, $field) {
        $v = trim((string) $v);
        if ($v === '') return self::bad($field, 'Ziel angeben.');
        if ($v[0] === '/') return self::path($v, $field);
        if (preg_match('#^https?://#i', $v)) return esc_url_raw($v);
        return self::bad($field, 'Bitte einen Pfad mit / oder eine Adresse mit https:// angeben.');
    }

    /**
     * Prüft die Eingabe und mischt sie in den bisherigen Stand ($prev). Feld null = Feld entfernen.
     * @return array|WP_Error
     */
    public static function clean($v, $prev) {
        if (!is_array($v)) return self::bad('', 'Ungültige Marke.');
        $o = is_array($prev) ? $prev : [];
        $known = array_merge(array_keys(self::TEXT), self::BOOL, self::URLS, ['layout', 'display', 'instagram', 'nav', 'nav_cta', 'contact', 'social', 'about', 'tickets_path', 'page_slugs', 'wp_paths', 'redirects', 'colors', 'ready']);
        foreach ($v as $k => $val) {
            if (!in_array($k, $known, true)) return self::bad($k, 'Unbekanntes Feld.');
            if ($val === null) { unset($o[$k]); continue; }
            switch (true) {
                case $k === 'ready':
                    // nur Markierung „Marke eingerichtet“ (z. B. kunde-anlegen ohne weitere Felder)
                    if (!$val) unset($o['ready']); else $o['ready'] = true;
                    break;
                case isset(self::TEXT[$k]):
                    $t = $k === 'description' ? sanitize_textarea_field((string) $val) : sanitize_text_field((string) $val);
                    if ($k === 'key') { $t = sanitize_key($t); if ($t === '') return self::bad($k, 'Kurzname: Kleinbuchstaben, Ziffern, - oder _.'); }
                    if ($k === 'name' && trim($t) === '') return self::bad($k, 'Name angeben.');
                    $o[$k] = mb_substr($t, 0, self::TEXT[$k]); break;
                case in_array($k, self::BOOL, true):
                    $bv = is_bool($val) ? $val : (in_array($val, [1, '1', 'true'], true) ? true : (in_array($val, [0, '0', 'false'], true) ? false : null));
                    if ($bv === null) return self::bad($k, $k . ': true oder false.');
                    $o[$k] = $bv; break;
                case in_array($k, self::URLS, true):
                    $u = self::url($val, $k); if (is_wp_error($u)) return $u;
                    if ($u === null) unset($o[$k]); else $o[$k] = $u; break;
                case $k === 'layout':
                    if (!in_array($val, self::LAYOUTS, true)) return self::bad($k, 'Profil: club, festival oder festivalTour.');
                    $o[$k] = $val; break;
                case $k === 'display':
                    if (!in_array($val, self::DISPLAY, true)) return self::bad($k, 'Überschriften: manrope oder anton.');
                    $o[$k] = $val; break;
                case $k === 'instagram':
                    if (!is_array($val)) return self::bad($k, 'Instagram: {url, handle}.');
                    $u = self::url($val['url'] ?? '', 'instagram.url'); if (is_wp_error($u)) return $u;
                    if ($u === null) return self::bad('instagram.url', 'Adresse angeben.');
                    $o[$k] = ['url' => $u, 'handle' => mb_substr(sanitize_text_field((string) ($val['handle'] ?? '')), 0, 40)]; break;
                case $k === 'nav' || $k === 'social':
                    if (!is_array($val) || count($val) > 20) return self::bad($k, 'Höchstens 20 Einträge.');
                    $list = [];
                    foreach (array_values($val) as $i => $it) {
                        $label = trim(sanitize_text_field((string) ($it['label'] ?? '')));
                        $target = self::link($it[$k === 'nav' ? 'href' : 'url'] ?? '', $k . '[' . $i . ']');
                        if (is_wp_error($target)) return $target;
                        if ($label === '') return self::bad($k . '[' . $i . '].label', 'Bezeichnung angeben.');
                        if ($k === 'nav') $list[] = ['label' => mb_substr($label, 0, 60), 'href' => $target];
                        else $list[] = ['label' => mb_substr($label, 0, 40), 'url' => $target, 'icon' => sanitize_key((string) ($it['icon'] ?? '')) ?: 'link'];
                    }
                    $o[$k] = $list; break;
                case $k === 'nav_cta':
                    if (!is_array($val)) return self::bad($k, 'Knopf: {label, href}.');
                    $label = trim(sanitize_text_field((string) ($val['label'] ?? '')));
                    $target = self::link($val['href'] ?? '', 'nav_cta.href'); if (is_wp_error($target)) return $target;
                    if ($label === '') return self::bad('nav_cta.label', 'Bezeichnung angeben.');
                    $o[$k] = ['label' => mb_substr($label, 0, 40), 'href' => $target]; break;
                case $k === 'contact':
                    if (!is_array($val)) return self::bad($k, 'Ungültiger Kontakt.');
                    $co = is_array($o[$k] ?? null) ? $o[$k] : [];
                    foreach ($val as $ck => $cv) {
                        if (!in_array($ck, ['email', 'address', 'company', 'whatsapp', 'phone'], true)) return self::bad('contact.' . $ck, 'Unbekanntes Feld.');
                        if ($cv === null || $cv === '') { unset($co[$ck]); continue; }
                        if ($ck === 'email') { $e = sanitize_email((string) $cv); if (!is_email($e)) return self::bad('contact.email', 'Ungültige E-Mail-Adresse.'); $co[$ck] = $e; }
                        elseif ($ck === 'whatsapp') { $d = preg_replace('/\D/', '', (string) $cv); if (strlen($d) < 6) return self::bad('contact.whatsapp', 'Nummer mit Ländervorwahl, nur Ziffern.'); $co[$ck] = $d; }
                        else $co[$ck] = mb_substr(sanitize_text_field((string) $cv), 0, 200);
                    }
                    if ($co) $o[$k] = $co; else unset($o[$k]);
                    break;
                case $k === 'about':
                    if (!is_array($val)) return self::bad($k, 'Über uns: {eyebrow, title, text}.');
                    $ab = is_array($o[$k] ?? null) ? $o[$k] : [];
                    foreach ($val as $ak => $av) {
                        if (!in_array($ak, ['eyebrow', 'title', 'text'], true)) return self::bad('about.' . $ak, 'Unbekanntes Feld.');
                        if ($av === null) { unset($ab[$ak]); continue; }
                        $ab[$ak] = $ak === 'text' ? mb_substr(sanitize_textarea_field((string) $av), 0, 1200) : mb_substr(sanitize_text_field((string) $av), 0, 80);
                    }
                    if ($ab) $o[$k] = $ab; else unset($o[$k]);
                    break;
                case $k === 'tickets_path':
                    $p = self::path($val, $k); if (is_wp_error($p)) return $p;
                    $o[$k] = trailingslashit($p); break;
                case $k === 'page_slugs':
                    if (!is_array($val) || count($val) > 30) return self::bad($k, 'Höchstens 30 Einträge {pfad: slug}.');
                    $ps = [];
                    foreach ($val as $pk => $pv) {
                        $pk = sanitize_key((string) $pk); $pv = sanitize_title((string) $pv);
                        if ($pk === '' || $pv === '') return self::bad('page_slugs', 'Schlüssel und Slug angeben.');
                        $ps[$pk] = $pv;
                    }
                    $o[$k] = $ps; break;
                case $k === 'wp_paths':
                    if (!is_array($val) || count($val) > 60) return self::bad($k, 'Höchstens 60 Pfade.');
                    $wp = [];
                    foreach (array_values($val) as $i => $pv) { $p = self::path($pv, 'wp_paths[' . $i . ']'); if (is_wp_error($p)) return $p; $wp[] = $p; }
                    $o[$k] = array_values(array_unique($wp)); break;
                case $k === 'redirects':
                    if (!is_array($val) || count($val) > 100) return self::bad($k, 'Höchstens 100 Weiterleitungen {alt: neu}.');
                    $rd = [];
                    foreach ($val as $from => $to) {
                        $f = self::path($from, 'redirects'); if (is_wp_error($f)) return $f;
                        $t = self::link($to, 'redirects.' . $from); if (is_wp_error($t)) return $t;
                        $rd[$f] = $t;
                    }
                    $o[$k] = $rd; break;
                case $k === 'colors':
                    if (!is_array($val)) return self::bad($k, 'Farben: {"--bg": "#…", …}.');
                    $cl = is_array($o[$k] ?? null) ? $o[$k] : [];
                    foreach ($val as $ck => $cv) {
                        if (!in_array($ck, self::COLORS, true)) return self::bad('colors.' . $ck, 'Unbekannte Farbe.');
                        if ($cv === null || $cv === '') { unset($cl[$ck]); continue; }
                        $cv = trim((string) $cv);
                        if ($ck === '--bg-light') {
                            // Farbe oder Verlauf, nur harmlose Zeichen (kein url(), keine Klammer-Tricks über ; oder })
                            if (!preg_match('/^(#[0-9a-f]{3,8}|(radial|linear)-gradient\([0-9a-z#%., \-]+\))$/i', $cv) || strlen($cv) > 200) return self::bad('colors.--bg-light', 'Farbe (#…) oder radial-/linear-gradient(…).');
                        } elseif (!preg_match('/^#[0-9a-f]{6}$/i', $cv)) {
                            return self::bad('colors.' . $ck, 'Farbe als #RRGGBB.');
                        } else {
                            $cv = strtoupper($cv);
                        }
                        $cl[$ck] = $cv;
                    }
                    if ($cl) $o[$k] = $cl; else unset($o[$k]);
                    break;
            }
        }
        return $o;
    }

    // ═══════════════ WP-CLI ═══════════════

    /**
     * wp tixomat marke zeigen                      Marke (brand) als JSON
     * wp tixomat marke setzen --daten='{…}'         Felder wie POST /studio/site-config {brand} (für kunde-anlegen), danach ready
     * wp tixomat marke zuruecksetzen               gespeicherte Marke löschen (ready = false)
     */
    public static function cli($args, $assoc) {
        $a = $args[0] ?? 'zeigen';
        $opt = get_option(TIX_Site_Config::OPTION, []);
        if (!is_array($opt)) $opt = [];
        if ($a === 'setzen') {
            $in = json_decode((string) ($assoc['daten'] ?? ''), true);
            if (!is_array($in)) WP_CLI::error('Bitte --daten=\'{…}\' angeben.');
            $r = self::clean($in, $opt['brand'] ?? []);
            if (is_wp_error($r)) WP_CLI::error(($r->get_error_data()['field'] ?? '') . ': ' . $r->get_error_message());
            if (empty($r)) $r = ['ready' => true];
            $opt['brand'] = $r;
            update_option(TIX_Site_Config::OPTION, $opt, false);
            WP_CLI::success('Marke gespeichert (' . implode(', ', array_keys($r)) . ').');
        } elseif ($a === 'zuruecksetzen') {
            unset($opt['brand']);
            update_option(TIX_Site_Config::OPTION, $opt, false);
            WP_CLI::success('Marke zurückgesetzt (brand.ready = false).');
        }
        $p = TIX_Site_Config::payload();
        WP_CLI::line(wp_json_encode($p['brand'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
