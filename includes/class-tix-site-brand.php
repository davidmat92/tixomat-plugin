<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Site_Brand – Homepage zur Laufzeit (1.38.673, Doku §94), Feldnamen mit dem Homepages-Chat abgestimmt (06.11.).
 *
 * GET /public/site-config liefert zusätzlich `homepage` – nur die Angaben, die marken-web über die bestehenden Bereiche
 * (name, profile, colors, logo, icon, event_placeholder, nav, social, app, contact, seo …) hinaus braucht.
 * `homepage` ist null, solange nichts gespeichert ist; `enabled: false` oder null → keine Homepage für diesen Host (404).
 * Feste Marken in brands.ts (KitchenKlub, Mallorca, evendis-Demo, XXL) haben in marken-web Vorrang.
 *
 * Schreiben: POST /studio/site-config {homepage: {…}} (Teilfelder; Feld null = entfernen; homepage: null = alles weg),
 * WP-CLI `wp tixomat marke setzen --daten='{…}'`, `wp tixomat marke zeigen`, `wp tixomat marke zuruecksetzen`.
 * `colors.background` / `colors.glow` füllen zusätzlich die oberen `colors.background` / `colors.glow`.
 */
class TIX_Site_Brand {

    const COLORS = ['background', 'glow', 'surface', 'surface_raised', 'spotlight', 'teal', 'cta_bg', 'cta_fg'];
    const TEXT = ['key' => 40, 'short_name' => 40, 'in_venue' => 80, 'hashtag' => 60, 'description' => 300];
    const LAYOUTS = ['club', 'festival', 'festivalTour', 'theater']; // theater: Darstellung wie club, bis es eine eigene gibt (1.38.714)
    const DISPLAY = ['manrope', 'anton'];

    public static function init() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) WP_CLI::add_command('tixomat marke', [__CLASS__, 'cli']);
    }

    // ═══════════════ Lesen ═══════════════

    /** `homepage` aus dem fertigen Payload ($c) und dem gespeicherten Stand ($st); null ohne Speicherung. */
    public static function payload(array $c, $st) {
        if (!is_array($st) || !$st) return null;
        $layout = in_array($st['layout'] ?? null, self::LAYOUTS, true) ? $st['layout'] : (in_array($c['profile'] ?? null, self::LAYOUTS, true) ? $c['profile'] : 'club');
        $name = (string) ($c['name'] ?? '');
        $host = class_exists('TIX_Customer_URL') ? TIX_Customer_URL::host() : '';
        if ($host === '') $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $multi = class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
        $app_cfg = class_exists('TIX_App_Config') ? TIX_App_Config::stored() : [];
        return [
            // Homepage nur, wenn die Lizenz „Eigene Website“ freischaltet (Audit 08.11.; ohne Lizenz = alles frei)
            'enabled' => (array_key_exists('enabled', $st) ? (bool) $st['enabled'] : true) && (!class_exists('TIX_Modules') || TIX_Modules::allowed('website')),
            'key' => (string) ($st['key'] ?? '') ?: (sanitize_key(explode('.', preg_replace('/^www\./', '', $host))[0] ?? '') ?: 'marke'),
            'short_name' => (string) ($st['short_name'] ?? '') ?: null,
            'in_venue' => (string) ($st['in_venue'] ?? '') ?: (($layout === 'club' ? 'im ' : 'beim ') . $name),
            'layout' => $layout,
            'display_font' => in_array($st['display_font'] ?? null, self::DISPLAY, true) ? $st['display_font'] : 'manrope',
            'single_venue' => array_key_exists('single_venue', $st) ? (bool) $st['single_venue'] : !$multi,
            'hashtag' => array_key_exists('hashtag', $st) ? (string) $st['hashtag'] : (string) ($app_cfg['home']['hashtag'] ?? ''),
            'tickets_path' => (string) ($st['tickets_path'] ?? '') ?: '/tickets/',
            'wp_paths' => array_values((array) ($st['wp_paths'] ?? [])),
            'redirects' => (object) ($st['redirects'] ?? []),
            'about' => array_replace(['eyebrow' => '', 'title' => '', 'text' => ''], (array) ($st['about'] ?? [])),
            'description' => (string) ($st['description'] ?? '') ?: (string) ($c['seo']['description'] ?? ''),
            'colors' => (object) ($st['colors'] ?? []),
            // zusätzlich (optional, wie brands.ts): Textseiten-Slugs und Knopf rechts im Kopf
            'page_slugs' => (object) ($st['page_slugs'] ?? []),
            'nav_cta' => $st['nav_cta'] ?? null,
        ];
    }

    /** Obere colors.background/glow aus homepage.colors (wenn gespeichert). */
    public static function top_colors(array $colors, $st) {
        foreach (['background', 'glow'] as $k) if (!empty($st['colors'][$k])) $colors[$k] = $st['colors'][$k];
        return $colors;
    }

    // ═══════════════ Schreiben ═══════════════

    private static function bad($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => 'homepage' . ($field !== '' ? '.' . $field : '')]);
    }

    private static function path($v, $field) {
        $v = trim((string) $v);
        if (!preg_match('#^/[A-Za-z0-9._~\-/*?=&%\#]*$#', $v) || strlen($v) > 200) return self::bad($field, 'Bitte einen Pfad mit / am Anfang angeben.');
        return $v;
    }

    private static function link($v, $field) {
        $v = trim((string) $v);
        if ($v !== '' && $v[0] === '/') return self::path($v, $field);
        if (preg_match('#^https?://#i', $v)) return esc_url_raw($v);
        return self::bad($field, 'Bitte einen Pfad mit / oder eine Adresse mit https:// angeben.');
    }

    /**
     * Prüft die Eingabe und mischt sie in den bisherigen Stand ($prev). Feld null = entfernen.
     * @return array|WP_Error
     */
    public static function clean($v, $prev) {
        if (!is_array($v)) return self::bad('', 'Ungültige Angaben.');
        $o = is_array($prev) ? $prev : [];
        $known = array_merge(array_keys(self::TEXT), ['enabled', 'single_venue', 'layout', 'display_font', 'tickets_path', 'wp_paths', 'redirects', 'about', 'colors', 'page_slugs', 'nav_cta']);
        foreach ($v as $k => $val) {
            if (!in_array($k, $known, true)) return self::bad($k, 'Unbekanntes Feld.');
            if ($val === null) { unset($o[$k]); continue; }
            switch (true) {
                case isset(self::TEXT[$k]):
                    $t = $k === 'description' ? sanitize_textarea_field((string) $val) : sanitize_text_field((string) $val);
                    if ($k === 'key') { $t = trim(strtolower($t)); if (!preg_match('/^[a-z0-9-]{1,40}$/', $t)) return self::bad($k, 'Kurzname: a–z, 0–9 und -.'); }
                    $o[$k] = mb_substr($t, 0, self::TEXT[$k]); break;
                case $k === 'enabled' || $k === 'single_venue':
                    $bv = is_bool($val) ? $val : (in_array($val, [1, '1', 'true'], true) ? true : (in_array($val, [0, '0', 'false'], true) ? false : null));
                    if ($bv === null) return self::bad($k, $k . ': true oder false.');
                    $o[$k] = $bv; break;
                case $k === 'layout':
                    if (!in_array($val, self::LAYOUTS, true)) return self::bad($k, 'Profil: club, festival, festivalTour oder theater.');
                    $o[$k] = $val; break;
                case $k === 'display_font':
                    if (!in_array($val, self::DISPLAY, true)) return self::bad($k, 'Überschriften: manrope oder anton.');
                    $o[$k] = $val; break;
                case $k === 'tickets_path':
                    $p = self::path($val, $k); if (is_wp_error($p)) return $p;
                    $o[$k] = trailingslashit($p); break;
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
                case $k === 'colors':
                    if (!is_array($val)) return self::bad($k, 'Farben: {surface: "#…", …}.');
                    $cl = is_array($o[$k] ?? null) ? $o[$k] : [];
                    foreach ($val as $ck => $cv) {
                        if (!in_array($ck, self::COLORS, true)) return self::bad('colors.' . $ck, 'Unbekannte Farbe.');
                        if ($cv === null || $cv === '') { unset($cl[$ck]); continue; }
                        $cv = trim((string) $cv);
                        if ($ck === 'glow') {
                            // Farbe oder Verlauf, nur harmlose Zeichen (kein url())
                            if (!preg_match('/^(#[0-9a-f]{6}|(radial|linear)-gradient\([0-9a-z#%., \-]+\))$/i', $cv) || strlen($cv) > 200) return self::bad('colors.glow', 'Farbe (#RRGGBB) oder radial-/linear-gradient(…).');
                        } elseif (!preg_match('/^#[0-9a-f]{6}$/i', $cv)) {
                            return self::bad('colors.' . $ck, 'Farbe als #RRGGBB.');
                        }
                        $cl[$ck] = $cv[0] === '#' ? strtoupper($cv) : $cv;
                    }
                    if ($cl) $o[$k] = $cl; else unset($o[$k]);
                    break;
                case $k === 'page_slugs':
                    if (!is_array($val) || count($val) > 30) return self::bad($k, 'Höchstens 30 Einträge {pfad: slug}.');
                    $ps = [];
                    foreach ($val as $pk => $pv) {
                        $pk = sanitize_key((string) $pk); $pv = sanitize_title((string) $pv);
                        if ($pk === '' || $pv === '') return self::bad('page_slugs', 'Schlüssel und Slug angeben.');
                        $ps[$pk] = $pv;
                    }
                    $o[$k] = $ps; break;
                case $k === 'nav_cta':
                    if (!is_array($val)) return self::bad($k, 'Knopf: {label, href}.');
                    $label = trim(sanitize_text_field((string) ($val['label'] ?? '')));
                    $target = self::link($val['href'] ?? '', 'nav_cta.href'); if (is_wp_error($target)) return $target;
                    if ($label === '') return self::bad('nav_cta.label', 'Bezeichnung angeben.');
                    $o[$k] = ['label' => mb_substr($label, 0, 40), 'href' => $target]; break;
            }
        }
        return $o;
    }

    // ═══════════════ WP-CLI ═══════════════

    /**
     * wp tixomat marke zeigen                    homepage als JSON
     * wp tixomat marke setzen --daten='{…}'      Felder wie POST /studio/site-config {homepage} (für kunde-anlegen); enabled = true,
     *                                            zusätzlich "has_app": false = Seite ohne App (Studio-Schalter tix_studio_has_app)
     * wp tixomat marke zuruecksetzen             gespeicherte homepage löschen (→ null)
     */
    public static function cli($args, $assoc) {
        $a = $args[0] ?? 'zeigen';
        $opt = get_option(TIX_Site_Config::OPTION, []);
        if (!is_array($opt)) $opt = [];
        if ($a === 'setzen') {
            $in = json_decode((string) ($assoc['daten'] ?? ''), true);
            if (!is_array($in)) WP_CLI::error('Bitte --daten=\'{…}\' angeben.');
            if (array_key_exists('has_app', $in)) {
                if (!is_bool($in['has_app'])) WP_CLI::error('has_app: true oder false.');
                update_option(class_exists('TIX_Studio') ? TIX_Studio::HAS_APP : 'tix_studio_has_app', $in['has_app'] ? '1' : '0', false);
                unset($in['has_app']);
            }
            $r = self::clean($in, $opt['homepage'] ?? []);
            if (is_wp_error($r)) WP_CLI::error(($r->get_error_data()['field'] ?? '') . ': ' . $r->get_error_message());
            if (!array_key_exists('enabled', $r)) $r['enabled'] = true;
            $opt['homepage'] = $r;
            update_option(TIX_Site_Config::OPTION, $opt, false);
            WP_CLI::success('Homepage gespeichert (' . implode(', ', array_keys($r)) . ').');
        } elseif ($a === 'zuruecksetzen') {
            unset($opt['homepage']);
            update_option(TIX_Site_Config::OPTION, $opt, false);
            WP_CLI::success('Homepage zurückgesetzt (homepage = null).');
        }
        $p = TIX_Site_Config::payload();
        WP_CLI::line(wp_json_encode(['name' => $p['name'] ?? '', 'profile' => $p['profile'] ?? '', 'has_app' => $p['app']['has_app'] ?? null, 'homepage' => $p['homepage'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
