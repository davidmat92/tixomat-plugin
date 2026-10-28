<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Basis_Content – Basis-Inhalt `app.json` (1.38.568, Lizenz-Fernschalter Paket L3).
 *
 * Fester, versionierter Auszug der Seite OHNE personenbezogene Daten: Marke, Startseite (Kacheln, Hashtag),
 * Textseiten, Kontakt, Social, Rechtliches (als HTML, damit die Datei ohne WordPress reicht) und kommende Events
 * als Info mit optionalem externem Ticket-Link (`_tix_basis_ticket_url`). Aus der Datei leben im Basis-Modus die
 * Kunden-App, die schlanke Website und der Basis-Dienst (Import beim Wechsel, danach pflegt der Mini-Editor dort).
 *
 *   GET /app.json                    – öffentlich, Datei aus uploads/tix-basis/app.json (CORS *)
 *   GET /wp-json/tixomat/v1/basis/content – dasselbe über REST
 * Neu erzeugt bei jedem Speichern eines Events bzw. der App-Inhalte/Seiten-Einstellungen und täglich (Cron).
 * Vertrag: uebergaben/vertrag-lizenz-basis.md.
 */
class TIX_Basis_Content {

    const VERSION = 1;
    const CRON    = 'tix_basis_content_build';
    const MAX_EVENTS = 200;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('parse_request', [__CLASS__, 'serve'], 0);
        add_action(self::CRON, [__CLASS__, 'write']);
        add_action('init', function () {
            if (!wp_next_scheduled(TIX_Basis_Content::CRON)) wp_schedule_event(time() + 600, 'daily', TIX_Basis_Content::CRON);
        });
        // Bei Änderungen neu erzeugen (gebündelt am Ende der Anfrage)
        add_action('save_post_event', [__CLASS__, 'mark_dirty']);
        add_action('deleted_post', [__CLASS__, 'mark_dirty']);
        foreach (['_tix_app_config', 'tix_site_config', 'tix_studio_brand', 'blogname'] as $o) {
            add_action('update_option_' . $o, [__CLASS__, 'mark_dirty']);
        }
    }

    private static $dirty = false;

    public static function mark_dirty() {
        if (self::$dirty) return;
        self::$dirty = true;
        add_action('shutdown', [__CLASS__, 'write']);
    }

    public static function dir() {
        $u = wp_upload_dir(null, false);
        return trailingslashit($u['basedir']) . 'tix-basis/';
    }

    public static function file() {
        return self::dir() . 'app.json';
    }

    // ═══════════════ Aufbau ═══════════════

    public static function build() {
        $sc = class_exists('TIX_Site_Config') ? TIX_Site_Config::payload() : [];
        $ac = class_exists('TIX_App_Config') ? TIX_App_Config::stored() : [];
        $home = isset($ac['home']) && is_array($ac['home']) ? $ac['home'] : [];
        $pages = [];
        foreach ((array) ($ac['pages'] ?? []) as $slug => $p) {
            if (!is_array($p)) continue;
            $slug = sanitize_title((string) $slug);
            if ($slug === '') continue;
            $entry = ['title' => (string) ($p['title'] ?? ''), 'html' => wp_kses_post((string) ($p['html'] ?? ''))];
            if (!empty($p['faq']) && is_array($p['faq'])) {
                $entry['faq'] = array_values(array_filter(array_map(function ($f) {
                    return is_array($f) ? ['q' => (string) ($f['q'] ?? ''), 'a' => wp_kses_post((string) ($f['a'] ?? ''))] : null;
                }, $p['faq'])));
            }
            $pages[$slug] = $entry;
        }
        $logo = $sc['logo'] ?? [];
        $brand_logo = is_array($logo) ? ($logo['light'] ?? ($logo['dark'] ?? null)) : null;
        if (!$brand_logo && class_exists('TIX_Studio')) $brand_logo = TIX_Studio::logo() ?: null;
        $legal = [];
        foreach (['imprint' => 'impressum', 'privacy' => 'datenschutz', 'terms' => 'agb'] as $k => $name) {
            $legal[$name] = self::legal_page($sc['legal'] ?? [], $k);
        }
        return [
            'version'    => self::VERSION,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'source'     => home_url('/'),
            'brand'      => [
                'name'   => (string) ($sc['name'] ?? get_bloginfo('name')),
                'accent' => class_exists('TIX_Studio') ? TIX_Studio::accent() : (string) ($sc['colors']['accent'] ?? ''),
                'colors' => is_array($sc['colors'] ?? null) ? $sc['colors'] : new stdClass(),
                'logo'   => $brand_logo ?: null,
                'icon'   => $sc['icon'] ?? null,
            ],
            'home'    => [
                'tiles'   => array_values(array_filter((array) ($home['tiles'] ?? []), 'is_array')),
                'banners' => array_values(array_filter((array) ($home['banners'] ?? []), 'is_array')),
                'hashtag' => (string) ($home['hashtag'] ?? ''),
            ],
            'pages'   => $pages ? $pages : new stdClass(),
            'contact' => self::only_public((array) ($sc['contact'] ?? []), ['email', 'phone', 'address', 'company']),
            'social'  => array_filter((array) ($sc['social'] ?? []), function ($v) { return is_string($v) && $v !== ''; }) ?: new stdClass(),
            'legal'   => $legal,
            'events'  => self::events(),
        ];
    }

    /** Kontakt des Betriebs (öffentliche Geschäftsangaben, keine Kundendaten). */
    private static function only_public(array $c, array $keys) {
        $out = [];
        foreach ($keys as $k) if (isset($c[$k]) && is_scalar($c[$k]) && (string) $c[$k] !== '') $out[$k] = (string) $c[$k];
        return $out ?: new stdClass();
    }

    /** Rechtstext als HTML (Seite der WordPress-Installation), sonst nur die Adresse. */
    private static function legal_page(array $legal, $k) {
        $url = isset($legal[$k]) && is_string($legal[$k]) ? $legal[$k] : null;
        $slug = isset($legal[$k . '_slug']) && is_string($legal[$k . '_slug']) ? $legal[$k . '_slug'] : '';
        $page = $slug !== '' ? get_page_by_path($slug) : ($url ? get_post(url_to_postid($url)) : null);
        if ($page && $page->post_status === 'publish') {
            return ['title' => (string) $page->post_title, 'html' => wp_kses_post(apply_filters('the_content', $page->post_content)), 'url' => $url];
        }
        return $url ? ['title' => '', 'html' => '', 'url' => $url] : null;
    }

    public static function events() {
        $ids = get_posts([
            'post_type' => 'event', 'post_status' => 'publish', 'numberposts' => self::MAX_EVENTS, 'fields' => 'ids',
            'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
            'meta_query' => [['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE']],
        ]);
        $out = [];
        foreach ($ids as $id) {
            $text = (string) get_post_meta($id, '_tix_info_description', true);
            if ($text === '') $text = (string) get_post_field('post_excerpt', $id);
            $ticket = (string) get_post_meta($id, '_tix_basis_ticket_url', true);
            $out[] = [
                'id'         => intval($id),
                'title'      => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
                'date'       => (string) get_post_meta($id, '_tix_date_start', true),
                'date_end'   => (string) get_post_meta($id, '_tix_date_end', true),
                'time'       => (string) get_post_meta($id, '_tix_time_start', true),
                'doors'      => (string) get_post_meta($id, '_tix_time_doors', true),
                'location'   => (string) get_post_meta($id, '_tix_location', true),
                'address'    => (string) get_post_meta($id, '_tix_address', true),
                'image'      => get_the_post_thumbnail_url($id, 'large') ?: null,
                'text'       => wp_kses_post($text),
                'ticket_url' => preg_match('#^https://#i', $ticket) ? esc_url_raw($ticket) : null,
            ];
        }
        return $out;
    }

    // ═══════════════ Datei + Auslieferung ═══════════════

    public static function write() {
        $data = self::build();
        $dir = self::dir();
        if (!wp_mkdir_p($dir)) return false;
        $tmp = $dir . 'app.json.' . wp_generate_password(8, false) . '.tmp';
        $ok = file_put_contents($tmp, wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false && @rename($tmp, self::file());
        if (!$ok) @unlink($tmp);
        return $ok;
    }

    /** Inhalt (Datei, sonst frisch gebaut; Datei älter als 1 Tag → neu). */
    public static function read() {
        $f = self::file();
        if (!is_file($f) || filemtime($f) < time() - DAY_IN_SECONDS) self::write();
        $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        return is_array($j) ? $j : self::build();
    }

    public static function serve($wp) {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if ($path !== rtrim($base, '/') . '/app.json') return;
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=300');
        echo wp_json_encode(self::read(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function routes() {
        register_rest_route('tixomat/v1', '/basis/content', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => function () { return rest_ensure_response(self::read()); },
        ]);
    }
}
