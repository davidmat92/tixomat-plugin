<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Site_Config – Website-Konfiguration für die Marken-Websites (Next.js `apps/marken-web`), 1.38.459.
 *
 *   GET  /public/site-config            öffentlich, 60 s cachebar: Marke, Profil, Navigation, Startseiten-Bausteine, SEO,
 *                                       Social, App, Funktionen, Tracking, Kontakt, Ort, Rechtstexte
 *   POST /studio/site-config            Admin bzw. Inhaber (wie Akzent/Logo): Überschreibungen je Bereich (null = berechneter Wert)
 * Werte: berechnet aus den vorhandenen Einstellungen (Studio-Marke, App-Inhalte, Tixomat-Einstellungen, WordPress-Menüs);
 * Option `tix_site_config` überschreibt bereichsweise. Grundwerte bleiben in der Marken-Datei der Web-App.
 * Außerdem: `?ref=` / `?coupon=` per 302 ohne Parameter (Option `tix_strip_ref_params` = 1; für die Weiche vor Next.js).
 * App (1.38.533): `app.play_public` = Android-App öffentlich (Google Play zeigen), Option `tix_play_public`, Vorgabe aus; schreibbar über
 * POST /studio/site-config {app: {play_public}} bzw. POST /studio/config {play_public} (Rechte wie Akzent/Logo). Store-Kennungen der
 * Live-Seiten (STORE_DEFAULTS) als Vorgabe, solange keine Store-Links eingetragen sind.
 * `app.top_bar` (1.38.534) = App-Leiste oben auf der Homepage, Option `tix_app_top_bar`, Vorgabe an; schreibbar wie play_public.
 */
class TIX_Site_Config {

    const NS = 'tixomat/v1';
    const OPTION = 'tix_site_config';
    const SECTIONS = ['profile', 'nav', 'home', 'seo', 'social', 'contact', 'venue', 'legal'];
    const SOCIAL = ['instagram', 'facebook', 'tiktok', 'youtube', 'spotify', 'soundcloud', 'whatsapp'];
    const OPT_PLAY_PUBLIC = 'tix_play_public';
    const OPT_TOP_BAR = 'tix_app_top_bar'; // '0' = App-Leiste oben aus (1.38.534), sonst an
    // Vorgaben je Live-Host [App-Store-ID, Play-Paket], wenn tix_app_store_url / tix_play_store_url leer sind (1.38.533)
    const STORE_DEFAULTS = [
        'kitchenklub.de'           => ['6814961638', 'de.kitchenklub.app'],
        'mallorca-festival-xxl.de' => ['6822272697', 'de.mallorcafestivalxxl.app'],
    ];

    /** Android-App öffentlich im Play Store? (Website zeigt dann Google Play) */
    public static function play_public() {
        return get_option(self::OPT_PLAY_PUBLIC, '0') === '1';
    }

    public static function set_play_public($on) {
        update_option(self::OPT_PLAY_PUBLIC, $on ? '1' : '0', false);
    }

    /** App-Leiste oben auf der Homepage zeigen? (Vorgabe an) */
    public static function top_bar() {
        return get_option(self::OPT_TOP_BAR, '1') !== '0';
    }

    public static function set_top_bar($on) {
        update_option(self::OPT_TOP_BAR, $on ? '1' : '0', false);
    }

    /** Wahrheitswert aus JSON/Formular (true/false/1/0/"1"/"0"/"true"/"false") oder null bei Ungültigem. */
    private static function bool_in($v) {
        if (is_bool($v)) return $v;
        if (in_array($v, [1, '1', 'true'], true)) return true;
        if (in_array($v, [0, '0', 'false'], true)) return false;
        return null;
    }

    /** Vorgabe [store_id, play_package] für diese Seite oder [null, null]. */
    private static function store_defaults() {
        $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        return self::STORE_DEFAULTS[$host] ?? [null, null];
    }

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('template_redirect', [__CLASS__, 'strip_ref_params'], 20);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/public/site-config', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/studio/site-config', [
            ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => ['TIX_Studio', 'perm_brand']],
            // 1.38.578: Studio liest, was gespeichert ist (stored) und was gilt (payload), plus die verbundene Support-Adresse
            ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_studio_get'], 'permission_callback' => ['TIX_Studio', 'perm_brand']],
        ]);
    }

    private static function stored() {
        $o = get_option(self::OPTION, []);
        return is_array($o) ? $o : [];
    }

    private static function settings() {
        return function_exists('tix_get_settings') ? (array) tix_get_settings() : [];
    }

    private static function abs_url($u) {
        $u = trim((string) $u);
        if ($u === '') return '';
        return preg_match('#^https?://#i', $u) ? $u : home_url('/' . ltrim($u, '/'));
    }

    /** Menü eines Ortes (Theme) bzw. erstes Menü mit passendem Namen → [{label, url}] */
    private static function menu($location_names) {
        $locs = get_nav_menu_locations();
        $menu = 0;
        foreach ((array) $location_names as $l) if (!empty($locs[$l])) { $menu = intval($locs[$l]); break; }
        if (!$menu) {
            foreach (wp_get_nav_menus() as $m) {
                foreach ((array) $location_names as $l) if (stripos($m->name, $l) !== false || stripos($m->slug, $l) !== false) { $menu = intval($m->term_id); break 2; }
            }
        }
        if (!$menu) return [];
        $out = [];
        foreach ((array) wp_get_nav_menu_items($menu) as $it) {
            if (intval($it->menu_item_parent)) continue;
            $out[] = ['label' => html_entity_decode((string) $it->title, ENT_QUOTES, 'UTF-8'), 'url' => class_exists('TIX_Customer_URL') ? TIX_Customer_URL::to((string) $it->url) : (string) $it->url];
        }
        return $out;
    }

    private static function page_slug_from_url($url) {
        $path = trim((string) wp_parse_url((string) $url, PHP_URL_PATH), '/');
        return $path === '' ? null : basename($path);
    }

    /** Kontakt aus den App-Inhalten (Rückfall, wenn im Studio nichts eingetragen ist). */
    private static function computed_contact() {
        $app_cfg = class_exists('TIX_App_Config') ? TIX_App_Config::stored() : [];
        $c = isset($app_cfg['contact']) && is_array($app_cfg['contact']) ? $app_cfg['contact'] : [];
        return [
            'email' => (string) ($c['email'] ?? '') ?: null,
            'phone' => (string) ($c['phone'] ?? ($c['whatsapp_display'] ?? '')) ?: null,
            'address' => (string) ($c['address'] ?? '') ?: null,
            'company' => (string) ($c['company'] ?? '') ?: null,
        ];
    }

    /**
     * Zentraler Kontakt der Seite (1.38.578): Studio → Marke → Kontakt (site-config.contact) vor den App-Inhalten.
     * Einzige Quelle für Website (Footer, Kontakt, JSON-LD), Apps (/app/config.contact), Mails (Hilfe-Link, Reply-To)
     * und /support/config.contact_email. Leere Felder = Rückfall auf die App-Inhalte bzw. die Marke im Client.
     */
    public static function contact() {
        $o = self::stored();
        $st = is_array($o['contact'] ?? null) ? array_filter($o['contact'], function ($v) { return $v !== null && $v !== ''; }) : [];
        return array_replace(self::computed_contact(), $st);
    }

    public static function contact_email() {
        return (string) (self::contact()['email'] ?? '');
    }

    /** Nur die E-Mail setzen (Schalter „Support-Adresse als Kontakt“ im E-Mail-Eingang); null = Feld leeren. */
    public static function set_contact_email($email) {
        $o = self::stored();
        $c = is_array($o['contact'] ?? null) ? $o['contact'] : [];
        $c['email'] = $email ? sanitize_email((string) $email) : null;
        $o['contact'] = $c;
        update_option(self::OPTION, $o, false);
    }

    /** Link einer Rechtstext-Seite festschreiben (Studio → Rechtstexte legt eine Seite an), $lk = privacy|imprint|terms. */
    public static function set_legal_link($lk, $url) {
        if (!in_array($lk, ['privacy', 'imprint', 'terms'], true)) return;
        $o = self::stored();
        $lg = is_array($o['legal'] ?? null) ? $o['legal'] : [];
        $lg[$lk] = $url ? self::abs_url($url) : null;
        $lg[$lk . '_slug'] = $url ? self::page_slug_from_url($url) : null;
        $o['legal'] = $lg;
        update_option(self::OPTION, $o, false);
    }

    /** Berechnete Werte (ohne Überschreibungen). */
    private static function computed() {
        $s = self::settings();
        $app_cfg = class_exists('TIX_App_Config') ? TIX_App_Config::stored() : [];
        $contact_cfg = isset($app_cfg['contact']) && is_array($app_cfg['contact']) ? $app_cfg['contact'] : [];
        $app = class_exists('TIX_Studio') ? TIX_Studio::app_info() : ['has_app' => false, 'ios_url' => null, 'android_url' => null, 'name' => '', 'layout' => 'club'];
        $ios_id = $app['ios_url'] && preg_match('~/id(\d+)~', (string) $app['ios_url'], $m) ? $m[1] : null;
        $play = $app['android_url'] && preg_match('~[?&]id=([A-Za-z0-9._]+)~', (string) $app['android_url'], $m2) ? $m2[1] : null;
        list($def_ios, $def_play) = self::store_defaults();
        $ios_url = $app['ios_url'] ?? null;
        if (!$ios_id && $def_ios) { $ios_id = $def_ios; $ios_url = $ios_url ?: 'https://apps.apple.com/de/app/id' . $def_ios; }
        if (!$play && $def_play) $play = $def_play;
        $play_public = self::play_public();
        $android_url = $app['android_url'] ?? null;
        if (!$android_url && $play && $play_public) $android_url = 'https://play.google.com/store/apps/details?id=' . $play;
        $accent = class_exists('TIX_Studio') ? TIX_Studio::accent() : (string) ($s['color_primary'] ?? '');
        $logo = class_exists('TIX_Studio') ? TIX_Studio::logo() : '';
        $ph = class_exists('TIX_Studio') ? TIX_Studio::placeholder() : null;
        $privacy = self::abs_url($s['privacy_url'] ?? '');
        $terms = self::abs_url($s['terms_url'] ?? '');
        $imprint = self::abs_url($s['imprint_url'] ?? ($s['impressum_url'] ?? ''));
        if ($imprint === '') foreach (['impressum', 'imprint'] as $sl) { $p = get_page_by_path($sl); if ($p && $p->post_status === 'publish') { $imprint = get_permalink($p); break; } }
        if ($privacy === '') foreach (['datenschutz', 'datenschutzerklaerung', 'privacy-policy'] as $sl) { $p = get_page_by_path($sl); if ($p && $p->post_status === 'publish') { $privacy = get_permalink($p); break; } }
        if ($terms === '') foreach (['agb', 'terms'] as $sl) { $p = get_page_by_path($sl); if ($p && $p->post_status === 'publish') { $terms = get_permalink($p); break; } }
        $social = [];
        foreach (self::SOCIAL as $k) {
            $v = (string) ($contact_cfg[$k] ?? ($contact_cfg[$k . '_url'] ?? ($s[$k . '_url'] ?? '')));
            if ($k === 'whatsapp' && $v !== '' && !preg_match('#^https?://#', $v)) $v = 'https://wa.me/' . preg_replace('/\D/', '', $v);
            $social[$k] = $v !== '' ? $v : null;
        }
        $wallet = class_exists('TIX_Wallet_Balance') && method_exists('TIX_Wallet_Balance', 'settings') && !empty(TIX_Wallet_Balance::settings()['enabled']);
        $banner = class_exists('TIX_Consent_Banner') ? TIX_Consent_Banner::settings() : null;
        $pixel_on = !empty($s['meta_pixel_enabled']) && !empty($s['meta_pixel_id']);
        $name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        return [
            'name' => $name,
            'profile' => class_exists('TIX_Studio') ? TIX_Studio::app_layout() : 'club',
            'colors' => ['accent' => $accent, 'background' => null, 'glow' => null],
            // 1.38.599: Schrift aus Studio → Marke → Schriften wählen (null = Vorgabe der Website); Dateien liefert die Website selbst aus
            'font' => class_exists('TIX_Studio') && method_exists('TIX_Studio', 'font') ? (TIX_Studio::font() ?: null) : null,
            'logo' => ['light' => $logo ?: null, 'dark' => $logo ?: null],
            'icon' => (string) get_site_icon_url(512) ?: null,
            'favicon' => (string) get_site_icon_url(32) ?: null,
            'event_placeholder' => $ph ? ['url' => $ph['url'], 'url_small' => $ph['url_small']] : null,
            'nav' => ['header' => self::menu(['primary', 'header', 'main', 'haupt']), 'footer' => self::menu(['footer', 'fuss', 'legal'])],
            'home' => ['blocks' => []],
            'seo' => ['title_pattern' => '%s – ' . $name, 'description' => html_entity_decode(get_bloginfo('description'), ENT_QUOTES, 'UTF-8'), 'image' => $ph['url'] ?? ((string) get_site_icon_url(512) ?: null)],
            'social' => $social,
            'app' => ['has_app' => (bool) $app['has_app'], 'name' => (string) ($app['name'] ?? ''), 'app_store_id' => $ios_id, 'ios_url' => $ios_url, 'play_package' => $play, 'android_url' => $android_url,
                // Android-App öffentlich (Google Play zeigen), 1.38.533 – Vorgabe aus
                'play_public' => $play_public,
                // App-Leiste oben auf der Homepage, 1.38.534 – Vorgabe an
                'top_bar' => self::top_bar()],
            'features' => [
                'checkout' => true,
                'music' => class_exists('TIX_Music') && TIX_Music::enabled(),
                'awareness' => class_exists('TIX_Awareness_Live') ? (bool) (TIX_Awareness_Live::flags()['report_enabled'] ?? false) : false,
                'wallet' => $wallet,
                'coupons' => class_exists('TIX_Coupons'),
                'muttizettel' => class_exists('TIX_Features') ? TIX_Features::on('muttizettel') : class_exists('TIX_Muttizettel'),
                'app_banner' => (bool) $app['has_app'],
            ],
            'tracking' => [
                'meta_pixel_id' => $pixel_on ? (string) $s['meta_pixel_id'] : null,
                'meta_consent_mode' => $pixel_on ? (string) ($s['meta_consent_mode'] ?? 'always') : null,
                'consent' => ['banner' => (bool) ($banner['enabled'] ?? false), 'categories' => ['statistics' => (string) ($banner['key_statistics'] ?? 'analytics'), 'marketing' => (string) ($banner['key_marketing'] ?? 'marketing')]],
            ],
            'contact' => self::computed_contact(),
            'venue' => self::venue(),
            'legal' => [
                'privacy' => $privacy ?: null, 'imprint' => $imprint ?: null, 'terms' => $terms ?: null,
                'privacy_slug' => $privacy ? self::page_slug_from_url($privacy) : null, 'imprint_slug' => $imprint ? self::page_slug_from_url($imprint) : null, 'terms_slug' => $terms ? self::page_slug_from_url($terms) : null,
            ],
        ];
    }

    /** Location-ID des Hauptorts (festgelegt, sonst meiste kommende Events, sonst letzte 12 Monate), 0 = keiner. */
    public static function venue_location_id() {
        global $wpdb;
        $o = self::stored();
        $lid = intval($o['venue']['id'] ?? 0);
        if ($lid && get_post_type($lid) === 'tix_location' && get_post_status($lid) === 'publish') return $lid;
        foreach ([current_time('Y-m-d'), gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' 00:00:00 UTC') - 365 * DAY_IN_SECONDS)] as $from) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT l.meta_value lid, COUNT(*) n FROM {$wpdb->postmeta} l
                INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id AND p.post_type = 'event' AND p.post_status = 'publish'
                INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_tix_date_start' AND d.meta_value >= %s
                WHERE l.meta_key = '_tix_location_id' AND l.meta_value <> '' AND l.meta_value <> '0' GROUP BY l.meta_value ORDER BY n DESC LIMIT 5", $from));
            foreach ($rows as $row) {
                $cand = intval($row->lid);
                if ($cand && get_post_type($cand) === 'tix_location' && get_post_status($cand) === 'publish') return $cand;
            }
        }
        return 0;
    }

    /** Hauptort festlegen (POST /studio/club location_id); 0 = wieder automatisch. */
    public static function set_venue_id($lid) {
        $o = self::stored();
        $v = is_array($o['venue'] ?? null) ? $o['venue'] : [];
        if ($lid) $v['id'] = intval($lid); else unset($v['id']);
        if ($v) $o['venue'] = $v; else unset($o['venue']);
        update_option(self::OPTION, $o, false);
    }

    /**
     * Hauptort: festgelegter Ort (venue.id über POST /studio/site-config), sonst der Ort mit den meisten kommenden Events,
     * sonst der meisten Events der letzten 12 Monate; null ohne Ort. `id` (1.38.539) = kanonische Location-ID für
     * GET /public/venues/{id} (Homepage „Der Club“).
     */
    private static function venue() {
        $lid = self::venue_location_id();
        if (!$lid) return null;
        $cid = class_exists('TIX_Locations') ? TIX_Locations::canonical_id($lid) : $lid;
        if (get_post_type($cid) !== 'tix_location') $cid = $lid;
        $lat = get_post_meta($cid, '_tix_loc_lat', true); $lng = get_post_meta($cid, '_tix_loc_lng', true);
        $public = class_exists('TIX_Venues') ? (bool) TIX_Venues::public_venue($cid) : true;
        return ['id' => $public ? intval($cid) : null, 'name' => html_entity_decode(get_the_title($cid), ENT_QUOTES, 'UTF-8'), 'address' => (string) get_post_meta($cid, '_tix_loc_address', true),
            'city' => (string) get_post_meta($cid, '_tix_loc_city', true), 'lat' => is_numeric($lat) && floatval($lat) != 0 ? floatval($lat) : null, 'lng' => is_numeric($lng) && floatval($lng) != 0 ? floatval($lng) : null];
    }

    public static function payload() {
        $c = self::computed();
        $o = self::stored();
        $venue_id = $c['venue']['id'] ?? null; // kanonisch aus venue(), nicht die gespeicherte Auswahl
        foreach (self::SECTIONS as $k) {
            if (!array_key_exists($k, $o) || $o[$k] === null) continue;
            // Kontakt: leere Studio-Felder überschreiben den Rückfall nicht (1.38.578)
            $ov = $k === 'contact' && is_array($o[$k]) ? array_filter($o[$k], function ($v) { return $v !== null && $v !== ''; }) : $o[$k];
            $c[$k] = is_array($c[$k] ?? null) && is_array($ov) ? array_replace($c[$k], $ov) : $ov;
        }
        if (is_array($c['venue'] ?? null)) $c['venue']['id'] = $venue_id;
        // Marken-Website (1.38.515, TIX_Website): veröffentlichter Aufbau + Domain-Status; Menü der Website ersetzt das berechnete
        if (class_exists('TIX_Website') && !TIX_Website::multi()) {
            $c['website'] = TIX_Website::public_website();
            $c['domain'] = TIX_Website::domain_public();
            $wn = $c['website']['nav'] ?? [];
            if (!empty($wn['header'])) $c['nav']['header'] = $wn['header'];
            if (!empty($wn['footer'])) $c['nav']['footer'] = $wn['footer'];
        }
        $c['overrides'] = array_values(array_intersect(self::SECTIONS, array_keys($o)));
        $c['plugin_version'] = defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '';
        return ['ok' => true] + $c;
    }

    /** GET /studio/site-config – {…payload, stored, contact_fallback, support_alias} (nur Inhaber/Admin, nie gecacht). */
    public static function rest_studio_get(WP_REST_Request $req) {
        $alias = class_exists('TIX_Support_Inbound') && method_exists('TIX_Support_Inbound', 'verified_alias') ? TIX_Support_Inbound::verified_alias() : '';
        $r = new WP_REST_Response(self::payload() + ['stored' => (object) self::stored(), 'contact_fallback' => self::computed_contact(), 'support_alias' => $alias ?: null]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }

    /** GET /public/site-config – öffentlich, 60 s cachebar. */
    public static function rest_get(WP_REST_Request $req) {
        $r = new WP_REST_Response(self::payload());
        $r->header('Cache-Control', 'public, max-age=60');
        return $r;
    }

    private static function bad($field, $msg) {
        return new WP_Error('tix_invalid_field', $msg, ['status' => 400, 'field' => $field]);
    }

    private static function clean_url($v, $field) {
        $v = trim((string) $v);
        if ($v === '') return '';
        if (!preg_match('#^(https?://|/)#i', $v)) return self::bad($field, 'Bitte eine Adresse mit https:// oder einen Pfad mit / angeben.');
        return esc_url_raw($v);
    }

    /** POST /studio/site-config {profile?, nav?, home?, seo?, social?, contact?, venue?, legal?, app?: {play_public?, top_bar?}} – Bereich null = zurück auf berechnet. */
    public static function rest_save(WP_REST_Request $req) {
        $p = $req->get_json_params();
        if (!is_array($p)) return self::bad('body', 'Bitte JSON senden.');
        // App (1.38.533): nur play_public ist schreibbar, der Rest kommt aus den App-Einstellungen
        if (array_key_exists('app', $p)) {
            $a = $p['app'];
            if (!is_array($a) || array_diff(array_keys($a), ['play_public', 'top_bar'])) return self::bad('app', 'Hier lassen sich nur app.play_public und app.top_bar ändern.');
            foreach (['play_public', 'top_bar'] as $ak) {
                if (array_key_exists($ak, $a) && self::bool_in($a[$ak]) === null) return self::bad('app.' . $ak, $ak . ': true oder false.');
            }
            if (array_key_exists('play_public', $a)) self::set_play_public(self::bool_in($a['play_public']));
            if (array_key_exists('top_bar', $a)) self::set_top_bar(self::bool_in($a['top_bar']));
            unset($p['app']);
        }
        $unknown = array_diff(array_keys($p), self::SECTIONS);
        if ($unknown) return self::bad((string) reset($unknown), 'Unbekannter Bereich.');
        $o = self::stored();
        foreach ($p as $k => $v) {
            if ($v === null) { unset($o[$k]); continue; }
            switch ($k) {
                case 'profile':
                    if (!in_array($v, ['club', 'festival', 'festivalTour'], true)) return self::bad('profile', 'Profil: club, festival oder festivalTour.');
                    $o[$k] = $v; break;
                case 'nav':
                    if (!is_array($v)) return self::bad('nav', 'Ungültige Navigation.');
                    $n = [];
                    foreach (['header', 'footer'] as $part) {
                        if (!array_key_exists($part, $v)) continue;
                        if (!is_array($v[$part]) || count($v[$part]) > 20) return self::bad('nav.' . $part, 'Höchstens 20 Einträge.');
                        $n[$part] = [];
                        foreach ($v[$part] as $i => $it) {
                            $label = trim(sanitize_text_field((string) ($it['label'] ?? '')));
                            $url = self::clean_url($it['url'] ?? '', 'nav.' . $part . '[' . $i . '].url');
                            if (is_wp_error($url)) return $url;
                            if ($label === '' || $url === '') return self::bad('nav.' . $part . '[' . $i . ']', 'Bezeichnung und Ziel angeben.');
                            $n[$part][] = ['label' => mb_substr($label, 0, 60), 'url' => $url];
                        }
                    }
                    $o[$k] = $n; break;
                case 'home':
                    if (!is_array($v) || !isset($v['blocks']) || !is_array($v['blocks']) || count($v['blocks']) > 40) return self::bad('home.blocks', 'Bitte eine Liste von Bausteinen.');
                    $b = [];
                    foreach ($v['blocks'] as $i => $bl) {
                        $key = sanitize_key((string) ($bl['key'] ?? ''));
                        if ($key === '') return self::bad('home.blocks[' . $i . '].key', 'Schlüssel fehlt.');
                        $b[] = ['key' => $key, 'enabled' => !array_key_exists('enabled', (array) $bl) || filter_var($bl['enabled'], FILTER_VALIDATE_BOOLEAN)];
                    }
                    $o[$k] = ['blocks' => $b]; break;
                case 'seo':
                    if (!is_array($v)) return self::bad('seo', 'Ungültige Angaben.');
                    $seo = [];
                    if (array_key_exists('title_pattern', $v)) { $t = sanitize_text_field((string) $v['title_pattern']); if (strpos($t, '%s') === false) return self::bad('seo.title_pattern', 'Das Muster braucht %s für den Seitentitel.'); $seo['title_pattern'] = mb_substr($t, 0, 120); }
                    if (array_key_exists('description', $v)) $seo['description'] = mb_substr(sanitize_textarea_field((string) $v['description']), 0, 300);
                    if (array_key_exists('image', $v)) { $u = self::clean_url($v['image'], 'seo.image'); if (is_wp_error($u)) return $u; $seo['image'] = $u ?: null; }
                    $o[$k] = $seo; break;
                case 'social':
                    if (!is_array($v)) return self::bad('social', 'Ungültige Links.');
                    $so = [];
                    foreach ($v as $sk => $sv) {
                        if (!in_array($sk, self::SOCIAL, true)) return self::bad('social.' . $sk, 'Unbekanntes Netzwerk.');
                        $u = self::clean_url($sv, 'social.' . $sk); if (is_wp_error($u)) return $u;
                        $so[$sk] = $u ?: null;
                    }
                    $o[$k] = $so; break;
                case 'contact':
                    if (!is_array($v)) return self::bad('contact', 'Ungültige Angaben.');
                    $co = [];
                    if (array_key_exists('email', $v)) { $e = sanitize_email((string) $v['email']); if ((string) $v['email'] !== '' && !is_email($e)) return self::bad('contact.email', 'Ungültige E-Mail-Adresse.'); $co['email'] = $e ?: null; }
                    foreach (['phone', 'address', 'company'] as $ck) if (array_key_exists($ck, $v)) $co[$ck] = mb_substr(sanitize_text_field((string) $v[$ck]), 0, 200) ?: null;
                    $o[$k] = $co; break;
                case 'venue':
                    if (!is_array($v)) return self::bad('venue', 'Ungültiger Ort.');
                    foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $vk => $rng) {
                        if (isset($v[$vk]) && $v[$vk] !== null && (!is_numeric($v[$vk]) || floatval($v[$vk]) < $rng[0] || floatval($v[$vk]) > $rng[1])) return self::bad('venue.' . $vk, 'Ungültige Koordinate.');
                    }
                    // 1.38.539: venue.id legt den Hauptort fest (null = automatisch); nur übergebene Felder überschreiben
                    $ve = [];
                    if (array_key_exists('id', $v) && $v['id'] !== null) {
                        $vid = intval($v['id']);
                        if (!$vid || get_post_type($vid) !== 'tix_location' || get_post_status($vid) !== 'publish') return self::bad('venue.id', 'Ort nicht gefunden.');
                        $ve['id'] = $vid;
                    }
                    foreach (['name', 'address', 'city'] as $vk) if (array_key_exists($vk, $v)) $ve[$vk] = sanitize_text_field((string) $v[$vk]);
                    foreach (['lat', 'lng'] as $vk) if (array_key_exists($vk, $v)) $ve[$vk] = $v[$vk] !== null ? floatval($v[$vk]) : null;
                    $o[$k] = $ve;
                    break;
                case 'legal':
                    if (!is_array($v)) return self::bad('legal', 'Ungültige Angaben.');
                    $lg = [];
                    foreach (['privacy', 'imprint', 'terms'] as $lk) {
                        if (!array_key_exists($lk, $v)) continue;
                        $u = self::clean_url($v[$lk], 'legal.' . $lk); if (is_wp_error($u)) return $u;
                        $lg[$lk] = $u ? self::abs_url($u) : null;
                        $lg[$lk . '_slug'] = $u ? self::page_slug_from_url($u) : null;
                    }
                    $o[$k] = $lg; break;
            }
        }
        update_option(self::OPTION, $o, false);
        return rest_ensure_response(self::payload());
    }

    // ═══════════════ ?ref= / ?coupon= ohne WordPress-Seite ═══════════════

    /**
     * Nachdem Promoter-Code (detect_referral, template_redirect 5) und Gutschein (detect_url_coupon, init 5) gemerkt sind:
     * 302 auf dieselbe Adresse ohne `ref`/`coupon` (andere Parameter bleiben). Nur mit Option `tix_strip_ref_params` = 1
     * (für die Weiche vor Next.js), nur GET, nie in Verwaltung, REST, AJAX.
     */
    public static function strip_ref_params() {
        if (get_option('tix_strip_ref_params') !== '1') return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        if (!isset($_GET['ref']) && !isset($_GET['coupon'])) return;
        // eigene Adresse ohne die beiden Parameter (Pfad aus der Anfrage, Host der Seite bzw. der Kunden-Adresse)
        $rel = remove_query_arg(['ref', 'coupon'], (string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $url = untrailingslashit(home_url()) . '/' . ltrim((string) preg_replace('#^' . preg_quote(rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/'), '#') . '#', '', $rel), '/');
        if (class_exists('TIX_Customer_URL')) $url = TIX_Customer_URL::to($url);
        nocache_headers();
        wp_redirect($url, 302);
        exit;
    }
}
