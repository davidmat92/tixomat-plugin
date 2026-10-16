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
 */
class TIX_Site_Config {

    const NS = 'tixomat/v1';
    const OPTION = 'tix_site_config';
    const SECTIONS = ['profile', 'nav', 'home', 'seo', 'social', 'contact', 'venue', 'legal'];
    const SOCIAL = ['instagram', 'facebook', 'tiktok', 'youtube', 'spotify', 'soundcloud', 'whatsapp'];

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('template_redirect', [__CLASS__, 'strip_ref_params'], 20);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/public/site-config', ['methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/studio/site-config', ['methods' => 'POST', 'callback' => [__CLASS__, 'rest_save'], 'permission_callback' => ['TIX_Studio', 'perm_brand']]);
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

    /** Berechnete Werte (ohne Überschreibungen). */
    private static function computed() {
        $s = self::settings();
        $app_cfg = class_exists('TIX_App_Config') ? TIX_App_Config::stored() : [];
        $contact_cfg = isset($app_cfg['contact']) && is_array($app_cfg['contact']) ? $app_cfg['contact'] : [];
        $app = class_exists('TIX_Studio') ? TIX_Studio::app_info() : ['has_app' => false, 'ios_url' => null, 'android_url' => null, 'name' => '', 'layout' => 'club'];
        $ios_id = $app['ios_url'] && preg_match('~/id(\d+)~', (string) $app['ios_url'], $m) ? $m[1] : null;
        $play = $app['android_url'] && preg_match('~[?&]id=([A-Za-z0-9._]+)~', (string) $app['android_url'], $m2) ? $m2[1] : null;
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
            'logo' => ['light' => $logo ?: null, 'dark' => $logo ?: null],
            'icon' => (string) get_site_icon_url(512) ?: null,
            'favicon' => (string) get_site_icon_url(32) ?: null,
            'event_placeholder' => $ph ? ['url' => $ph['url'], 'url_small' => $ph['url_small']] : null,
            'nav' => ['header' => self::menu(['primary', 'header', 'main', 'haupt']), 'footer' => self::menu(['footer', 'fuss', 'legal'])],
            'home' => ['blocks' => []],
            'seo' => ['title_pattern' => '%s – ' . $name, 'description' => html_entity_decode(get_bloginfo('description'), ENT_QUOTES, 'UTF-8'), 'image' => $ph['url'] ?? ((string) get_site_icon_url(512) ?: null)],
            'social' => $social,
            'app' => ['has_app' => (bool) $app['has_app'], 'name' => (string) ($app['name'] ?? ''), 'app_store_id' => $ios_id, 'ios_url' => $app['ios_url'] ?? null, 'play_package' => $play, 'android_url' => $app['android_url'] ?? null],
            'features' => [
                'checkout' => true,
                'music' => class_exists('TIX_Music') && TIX_Music::enabled(),
                'awareness' => class_exists('TIX_Awareness_Live') ? (bool) (TIX_Awareness_Live::flags()['report_enabled'] ?? false) : false,
                'wallet' => $wallet,
                'coupons' => class_exists('TIX_Coupons'),
                'muttizettel' => class_exists('TIX_Muttizettel'),
                'app_banner' => (bool) $app['has_app'],
            ],
            'tracking' => [
                'meta_pixel_id' => $pixel_on ? (string) $s['meta_pixel_id'] : null,
                'meta_consent_mode' => $pixel_on ? (string) ($s['meta_consent_mode'] ?? 'always') : null,
                'consent' => ['banner' => (bool) ($banner['enabled'] ?? false), 'categories' => ['statistics' => (string) ($banner['key_statistics'] ?? 'analytics'), 'marketing' => (string) ($banner['key_marketing'] ?? 'marketing')]],
            ],
            'contact' => [
                'email' => (string) ($contact_cfg['email'] ?? '') ?: null,
                'phone' => (string) ($contact_cfg['phone'] ?? ($contact_cfg['whatsapp_display'] ?? '')) ?: null,
                'address' => (string) ($contact_cfg['address'] ?? '') ?: null,
                'company' => (string) ($contact_cfg['company'] ?? '') ?: null,
            ],
            'venue' => self::venue(),
            'legal' => [
                'privacy' => $privacy ?: null, 'imprint' => $imprint ?: null, 'terms' => $terms ?: null,
                'privacy_slug' => $privacy ? self::page_slug_from_url($privacy) : null, 'imprint_slug' => $imprint ? self::page_slug_from_url($imprint) : null, 'terms_slug' => $terms ? self::page_slug_from_url($terms) : null,
            ],
        ];
    }

    /** Hauptort: der Ort mit den meisten kommenden Events (mit Koordinaten), sonst null. */
    private static function venue() {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT l.meta_value lid, COUNT(*) n FROM {$wpdb->postmeta} l
            INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id AND p.post_type = 'event' AND p.post_status = 'publish'
            INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_tix_date_start' AND d.meta_value >= %s
            WHERE l.meta_key = '_tix_location_id' AND l.meta_value <> '' AND l.meta_value <> '0' GROUP BY l.meta_value ORDER BY n DESC LIMIT 1", current_time('Y-m-d')));
        $lid = $row ? intval($row->lid) : 0;
        if (!$lid || get_post_type($lid) !== 'tix_location') return null;
        $lat = get_post_meta($lid, '_tix_loc_lat', true); $lng = get_post_meta($lid, '_tix_loc_lng', true);
        return ['name' => html_entity_decode(get_the_title($lid), ENT_QUOTES, 'UTF-8'), 'address' => (string) get_post_meta($lid, '_tix_loc_address', true),
            'city' => (string) get_post_meta($lid, '_tix_loc_city', true), 'lat' => is_numeric($lat) && floatval($lat) != 0 ? floatval($lat) : null, 'lng' => is_numeric($lng) && floatval($lng) != 0 ? floatval($lng) : null];
    }

    public static function payload() {
        $c = self::computed();
        $o = self::stored();
        foreach (self::SECTIONS as $k) {
            if (!array_key_exists($k, $o) || $o[$k] === null) continue;
            $c[$k] = is_array($c[$k] ?? null) && is_array($o[$k]) ? array_replace($c[$k], $o[$k]) : $o[$k];
        }
        $c['overrides'] = array_values(array_intersect(self::SECTIONS, array_keys($o)));
        $c['plugin_version'] = defined('TIXOMAT_VERSION') ? TIXOMAT_VERSION : '';
        return ['ok' => true] + $c;
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

    /** POST /studio/site-config {profile?, nav?, home?, seo?, social?, contact?, venue?, legal?} – Bereich null = zurück auf berechnet. */
    public static function rest_save(WP_REST_Request $req) {
        $p = $req->get_json_params();
        if (!is_array($p)) return self::bad('body', 'Bitte JSON senden.');
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
                    $o[$k] = ['name' => sanitize_text_field((string) ($v['name'] ?? '')), 'address' => sanitize_text_field((string) ($v['address'] ?? '')), 'city' => sanitize_text_field((string) ($v['city'] ?? '')),
                        'lat' => isset($v['lat']) && $v['lat'] !== null ? floatval($v['lat']) : null, 'lng' => isset($v['lng']) && $v['lng'] !== null ? floatval($v['lng']) : null];
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
