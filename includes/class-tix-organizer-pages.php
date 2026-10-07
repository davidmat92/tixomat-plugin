<?php
/**
 * Veranstalter-Seiten für Seitenvorlagen (evendis.de, Breakdance).
 *
 * Nur im Mehr-Veranstalter-Modus (TIX_App_Scope::multi()): Der Beitragstyp tix_organizer
 * wird öffentlich unter /veranstalter/<name>/, damit Breakdance eine Vorlage „Single
 * Veranstalter“ anwenden kann. Sichtbar sind nur Veranstalter, die auch die Plattform
 * öffentlich listet (Filter `tix_organizer_page_public`, z. B. für die Veranstalter-Freigabe).
 *
 * Icons: Phosphor Fill (Entscheidung 07.10.). Shortcodes für die berechneten Teile (aktueller Veranstalter):
 *   [tix_org_logo] [tix_org_glow] [tix_org_subtitle] [tix_org_categories] [tix_org_facts]
 *   [tix_org_description] [tix_org_modules title="Angebot"] [tix_org_contact title="Kontakt"]
 * Events des Veranstalters: [tix_events organizer="current"].
 */
if (!defined('ABSPATH')) exit;

class TIX_Organizer_Pages {

    const SLUG = 'veranstalter';
    const REWRITE_VERSION = '1';

    /** Phosphor-Icons (MIT), Stil Fill – Bausteine der Seitenvorlage */
    const ICONS = [
        'calendar' => '<path fill="currentColor" d="M208 32h-24v-8a8 8 0 0 0-16 0v8H88v-8a8 8 0 0 0-16 0v8H48a16 16 0 0 0-16 16v160a16 16 0 0 0 16 16h160a16 16 0 0 0 16-16V48a16 16 0 0 0-16-16m0 48H48V48h24v8a8 8 0 0 0 16 0v-8h80v8a8 8 0 0 0 16 0v-8h24Z"/>',
        'ticket' => '<path fill="currentColor" d="M232 104a8 8 0 0 0 8-8V64a16 16 0 0 0-16-16H32a16 16 0 0 0-16 16v32a8 8 0 0 0 8 8a24 24 0 0 1 0 48a8 8 0 0 0-8 8v32a16 16 0 0 0 16 16h192a16 16 0 0 0 16-16v-32a8 8 0 0 0-8-8a24 24 0 0 1 0-48M32 167.2a40 40 0 0 0 0-78.4V64h56v128H32Z"/>',
        'map-pin' => '<path fill="currentColor" d="M128 16a88.1 88.1 0 0 0-88 88c0 75.3 80 132.17 83.41 134.55a8 8 0 0 0 9.18 0C136 236.17 216 179.3 216 104a88.1 88.1 0 0 0-88-88m0 56a32 32 0 1 1-32 32a32 32 0 0 1 32-32"/>',
        'globe' => '<path fill="currentColor" d="M128 24a104 104 0 1 0 104 104A104.12 104.12 0 0 0 128 24m78.36 64h-35.65a135.3 135.3 0 0 0-22.3-45.6A88.29 88.29 0 0 1 206.37 88Zm9.64 40a87.6 87.6 0 0 1-3.33 24h-38.51a157.4 157.4 0 0 0 0-48h38.51a87.6 87.6 0 0 1 3.33 24m-88-85a115.3 115.3 0 0 1 26 45h-52a115.1 115.1 0 0 1 26-45m-26 125h52a115.1 115.1 0 0 1-26 45a115.3 115.3 0 0 1-26-45m-3.9-16a140.8 140.8 0 0 1 0-48h59.88a140.8 140.8 0 0 1 0 48Zm50.35 61.6a135.3 135.3 0 0 0 22.3-45.6h35.66a88.29 88.29 0 0 1-58 45.6Z"/>',
        'mail' => '<path fill="currentColor" d="M224 48H32a8 8 0 0 0-8 8v136a16 16 0 0 0 16 16h176a16 16 0 0 0 16-16V56a8 8 0 0 0-8-8m-8 144H40V74.19l82.59 75.71a8 8 0 0 0 10.82 0L216 74.19z"/>',
        'phone' => '<path fill="currentColor" d="M231.88 175.08A56.26 56.26 0 0 1 176 224C96.6 224 32 159.4 32 80a56.26 56.26 0 0 1 48.92-55.88a16 16 0 0 1 16.62 9.52l21.12 47.15v.12A16 16 0 0 1 117.39 96c-.18.27-.37.52-.57.77L96 121.45c7.49 15.22 23.41 31 38.83 38.51l24.34-20.71a8 8 0 0 1 .75-.56a16 16 0 0 1 15.17-1.4l.13.06l47.11 21.11a16 16 0 0 1 9.55 16.62"/>',
        'link' => '<path fill="currentColor" d="M208 32H48a16 16 0 0 0-16 16v160a16 16 0 0 0 16 16h160a16 16 0 0 0 16-16V48a16 16 0 0 0-16-16m-92.3 160.49a43.31 43.31 0 0 1-55-66.43l25.37-25.37a43.35 43.35 0 0 1 61.25 0a42.9 42.9 0 0 1 9.95 15.43a8 8 0 1 1-15 5.6a27.33 27.33 0 0 0-44.9-9.72L72 137.37a27.32 27.32 0 0 0 34.68 41.91a8 8 0 1 1 9 13.21Zm79.61-62.55l-25.37 25.37A43 43 0 0 1 139.32 168a43.35 43.35 0 0 1-40.53-28.12a8 8 0 1 1 15-5.6A27.35 27.35 0 0 0 139.28 152a27.14 27.14 0 0 0 19.32-8l25.4-25.37a27.32 27.32 0 0 0-34.68-41.91a8 8 0 1 1-9-13.21a43.32 43.32 0 0 1 55 66.43Z"/>',
        'music' => '<path fill="currentColor" d="M212.92 17.71a7.89 7.89 0 0 0-6.86-1.46l-128 32A8 8 0 0 0 72 56v110.1A36 36 0 1 0 88 196v-93.75l112-28v59.85a36 36 0 1 0 16 29.9V24a8 8 0 0 0-3.08-6.29"/>',
        'star' => '<path fill="currentColor" d="m234.29 114.85l-45 38.83L203 211.75a16.4 16.4 0 0 1-24.5 17.82L128 198.49l-50.53 31.08A16.4 16.4 0 0 1 53 211.75l13.76-58.07l-45-38.83A16.46 16.46 0 0 1 31.08 86l59-4.76l22.76-55.08a16.36 16.36 0 0 1 30.27 0l22.75 55.08l59 4.76a16.46 16.46 0 0 1 9.37 28.86Z"/>',
        'gift' => '<path fill="currentColor" d="M216 72h-35.08c.39-.33.79-.65 1.17-1A29.53 29.53 0 0 0 192 49.57A32.62 32.62 0 0 0 158.44 16A29.53 29.53 0 0 0 137 25.91a55 55 0 0 0-9 14.48a55 55 0 0 0-9-14.48A29.53 29.53 0 0 0 97.56 16A32.62 32.62 0 0 0 64 49.57A29.53 29.53 0 0 0 73.91 71c.38.33.78.65 1.17 1H40a16 16 0 0 0-16 16v32a16 16 0 0 0 16 16v64a16 16 0 0 0 16 16h60a4 4 0 0 0 4-4v-92H40V88h80v32h16V88h80v32h-80v92a4 4 0 0 0 4 4h60a16 16 0 0 0 16-16v-64a16 16 0 0 0 16-16V88a16 16 0 0 0-16-16M84.51 59a13.7 13.7 0 0 1-4.5-10a16.62 16.62 0 0 1 16.58-17h.49a13.7 13.7 0 0 1 10 4.5c8.39 9.48 11.35 25.2 12.39 34.92C109.71 70.39 94 67.43 84.51 59m87 0c-9.49 8.4-25.24 11.36-35 12.4C137.7 60.89 141 45.5 149 36.51a13.7 13.7 0 0 1 10-4.5h.49A16.62 16.62 0 0 1 176 49.08a13.7 13.7 0 0 1-4.51 9.92Z"/>',
        'chat' => '<path fill="currentColor" d="M232 128a104 104 0 0 1-152.88 91.82l-34.05 11.35a16 16 0 0 1-20.24-20.24l11.35-34.05A104 104 0 1 1 232 128"/>',
        'file' => '<path fill="currentColor" d="m213.66 82.34l-56-56A8 8 0 0 0 152 24H56a16 16 0 0 0-16 16v176a16 16 0 0 0 16 16h144a16 16 0 0 0 16-16V88a8 8 0 0 0-2.34-5.66M160 176H96a8 8 0 0 1 0-16h64a8 8 0 0 1 0 16m0-32H96a8 8 0 0 1 0-16h64a8 8 0 0 1 0 16m-8-56V44l44 44Z"/>',
        'instagram' => '<path fill="currentColor" d="M176 24H80a56.06 56.06 0 0 0-56 56v96a56.06 56.06 0 0 0 56 56h96a56.06 56.06 0 0 0 56-56V80a56.06 56.06 0 0 0-56-56m-48 152a48 48 0 1 1 48-48a48.05 48.05 0 0 1-48 48m60-96a12 12 0 1 1 12-12a12 12 0 0 1-12 12m-28 48a32 32 0 1 1-32-32a32 32 0 0 1 32 32"/>',
        'facebook' => '<path fill="currentColor" d="M232 128a104.16 104.16 0 0 1-91.55 103.26a4 4 0 0 1-4.45-4V152h24a8 8 0 0 0 8-8.53a8.17 8.17 0 0 0-8.25-7.47H136v-24a16 16 0 0 1 16-16h16a8 8 0 0 0 8-8.53a8.17 8.17 0 0 0-8.27-7.47H152a32 32 0 0 0-32 32v24H96a8 8 0 0 0-8 8.53a8.17 8.17 0 0 0 8.27 7.47H120v75.28a4 4 0 0 1-4.44 4a104.15 104.15 0 0 1-91.49-107.19c2-54 45.74-97.9 99.78-100A104.12 104.12 0 0 1 232 128"/>',
        'youtube' => '<path fill="currentColor" d="M234.33 69.52a24 24 0 0 0-14.49-16.4C185.56 39.88 131 40 128 40s-57.56-.12-91.84 13.12a24 24 0 0 0-14.49 16.4C19.08 79.5 16 97.74 16 128s3.08 48.5 5.67 58.48a24 24 0 0 0 14.49 16.41C69 215.56 120.4 216 127.34 216h1.32c6.94 0 58.37-.44 91.18-13.11a24 24 0 0 0 14.49-16.41c2.59-10 5.67-28.22 5.67-58.48s-3.08-48.5-5.67-58.48m-73.74 65l-40 28A8 8 0 0 1 108 156v-56a8 8 0 0 1 12.59-6.55l40 28a8 8 0 0 1 0 13.1Z"/>',
        'chevron' => '<path fill="currentColor" d="m184.49 136.49l-80 80a12 12 0 0 1-17-17L159 128L87.51 56.49a12 12 0 1 1 17-17l80 80a12 12 0 0 1-.02 17"/>',
    ];

    const MODULES = [
        'tickets'     => ['ticket', 'Tickets'],
        'music'       => ['music', 'Musikwunsch'],
        'loyalty'     => ['star', 'Prämien'],
        'giftcards'   => ['gift', 'Gutscheine'],
        'support'     => ['chat', 'Support'],
        'muttizettel' => ['file', 'Muttizettel'],
    ];

    public static function init() {
        if (!class_exists('TIX_App_Scope') || !TIX_App_Scope::multi()) return;

        add_filter('register_post_type_args', [__CLASS__, 'make_public'], 10, 2);
        add_action('init', [__CLASS__, 'maybe_flush'], 99);
        add_action('template_redirect', [__CLASS__, 'guard']);
        add_filter('wp_robots', [__CLASS__, 'robots']);

        foreach (['logo', 'glow', 'subtitle', 'categories', 'facts', 'description', 'modules', 'contact'] as $part) {
            add_shortcode('tix_org_' . $part, [__CLASS__, 'sc_' . $part]);
        }
    }

    public static function enabled() {
        return class_exists('TIX_App_Scope') && TIX_App_Scope::multi();
    }

    public static function make_public($args, $post_type) {
        if ($post_type !== 'tix_organizer') return $args;
        return array_merge($args, [
            'public'              => true,
            'publicly_queryable'  => true,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'has_archive'         => false,
            'rewrite'             => ['slug' => self::SLUG, 'with_front' => false],
        ]);
    }

    /** Permalinks einmalig neu aufbauen, wenn die Regel neu ist. */
    public static function maybe_flush() {
        if (get_option('tix_org_pages_rewrite') === self::REWRITE_VERSION) return;
        flush_rewrite_rules(false);
        update_option('tix_org_pages_rewrite', self::REWRITE_VERSION, false);
    }

    /** Öffentlich = veröffentlicht und von der Plattform gelistet; anpassbar per Filter. */
    public static function is_public($oid) {
        $p = get_post($oid);
        $public = $p && $p->post_type === 'tix_organizer' && $p->post_status === 'publish';
        if ($public && class_exists('TIX_Public_Platform')) {
            $public = (bool) TIX_Public_Platform::organizer_payload($p->ID);
        }
        return (bool) apply_filters('tix_organizer_page_public', $public, intval($oid));
    }

    /** URL der Veranstalter-Seite oder '' (nicht öffentlich / Modus aus). */
    public static function url($oid) {
        if (!self::enabled() || !$oid || !self::is_public($oid)) return '';
        return (string) get_permalink($oid);
    }

    public static function guard() {
        if (!is_singular('tix_organizer')) return;
        if (self::is_public(get_queried_object_id())) return;
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }

    public static function robots($robots) {
        if (is_singular('tix_organizer') && !self::is_public(get_queried_object_id())) $robots['noindex'] = true;
        return $robots;
    }

    // ── Daten ──

    private static function oid() {
        $id = get_the_ID();
        return ($id && get_post_type($id) === 'tix_organizer') ? intval($id) : 0;
    }

    private static function profile($oid) {
        static $cache = [];
        if (!isset($cache[$oid])) {
            $cache[$oid] = class_exists('TIX_Public_Platform') ? (TIX_Public_Platform::organizer_payload($oid, true) ?: []) : [];
        }
        return $cache[$oid];
    }

    public static function icon($name, $class = 'tix-ico') {
        $path = self::ICONS[$name] ?? self::ICONS['link'];
        return '<svg class="' . esc_attr($class) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="24" height="24" fill="currentColor" aria-hidden="true">' . $path . '</svg>';
    }

    private static function badge($name) {
        return '<span class="tix-ib">' . self::icon($name) . '</span>';
    }

    // ── Shortcodes ──

    public static function sc_logo() {
        $o = self::profile(self::oid());
        if (!$o) return '';
        $name = (string) $o['name'];
        $ini = '';
        foreach (array_slice(preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N} ]/u', '', $name))), 0, 2) as $w) {
            $ini .= function_exists('mb_substr') ? mb_strtoupper(mb_substr($w, 0, 1)) : strtoupper(substr($w, 0, 1));
        }
        $inner = $o['logo'] !== '' ? '<img src="' . esc_url($o['logo']) . '" alt="Logo ' . esc_attr($name) . '">' : '<span class="tix-org-ini">' . esc_html($ini) . '</span>';
        return '<div class="tix-org-logo">' . $inner . '</div>';
    }

    /** Bild für den weichen Schein: eigenes Titelbild, sonst Bild des nächsten Events, sonst Logo. */
    public static function sc_glow() {
        $oid = self::oid();
        $o = self::profile($oid);
        if (!$o) return '';
        $hero_id = intval(get_post_meta($oid, '_tix_org_landing_hero_id', true));
        $src = $hero_id ? (string) wp_get_attachment_image_url($hero_id, 'large') : '';
        if ($src === '' && !empty($o['events'][0]['image'])) $src = $o['events'][0]['image'];
        if ($src === '') $src = $o['logo'];
        if ($src === '') return '<div class="tix-org-glow tix-org-glow--plain"></div>';
        return '<div class="tix-org-glow"><img src="' . esc_url($src) . '" alt="" loading="eager"></div>';
    }

    public static function sc_subtitle() {
        $o = self::profile(self::oid());
        if (!$o) return '';
        $parts = array_filter([(string) $o['city'], (string) $o['tagline']]);
        return $parts ? '<div class="tix-org-sub">' . esc_html(implode(' · ', $parts)) . '</div>' : '';
    }

    public static function sc_categories() {
        $o = self::profile(self::oid());
        if (empty($o['categories'])) return '';
        $out = '';
        foreach ($o['categories'] as $c) $out .= '<span class="tix-pill">' . esc_html(html_entity_decode((string) $c['name'])) . '</span>';
        return '<div class="tix-pills">' . $out . '</div>';
    }

    public static function sc_facts() {
        $o = self::profile(self::oid());
        if (!$o) return '';
        $items = [];
        $next = $o['events'][0] ?? null;
        if ($next && !empty($next['date_start'])) {
            $items[] = self::icon('calendar') . 'Nächstes Event: <b>' . esc_html(date_i18n('D, j. M', strtotime($next['date_start']))) . '</b>';
        }
        $n = intval($o['upcoming_count']);
        $items[] = self::icon('ticket') . '<b>' . $n . '</b> ' . ($n === 1 ? 'kommendes Event' : 'kommende Events');
        if ($o['city'] !== '') $items[] = self::icon('map-pin') . esc_html($o['city']);
        return '<div class="tix-org-facts"><span>' . implode('</span><span>', $items) . '</span></div>';
    }

    /** Beschreibung; title="Über {name}" setzt eine Überschrift (nur wenn es eine Beschreibung gibt). */
    public static function sc_description($atts = []) {
        $atts = shortcode_atts(['title' => ''], $atts);
        $o = self::profile(self::oid());
        $d = trim((string) ($o['description'] ?? ''));
        if ($d === '') return '';
        $title = str_replace('{name}', (string) ($o['name'] ?? ''), $atts['title']);
        return self::section($title, '<div class="tix-org-desc">' . wpautop($d) . '</div>', 'tix-org-about');
    }

    private static function section($title, $body, $class) {
        if ($body === '') return '';
        return '<div class="tix-org-sec ' . esc_attr($class) . '">' . ($title !== '' ? '<h2 class="tix-org-h2">' . esc_html($title) . '</h2>' : '') . $body . '</div>';
    }

    public static function sc_modules($atts) {
        $atts = shortcode_atts(['title' => 'Angebot'], $atts);
        $o = self::profile(self::oid());
        $chips = '';
        foreach ((array) ($o['modules'] ?? []) as $k => $on) {
            if (!$on || !isset(self::MODULES[$k])) continue;
            $chips .= '<span class="tix-chip">' . self::badge(self::MODULES[$k][0]) . esc_html(self::MODULES[$k][1]) . '</span>';
        }
        return self::section($atts['title'], $chips !== '' ? '<div class="tix-chips">' . $chips . '</div>' : '', 'tix-org-modules');
    }

    public static function sc_contact($atts) {
        $atts = shortcode_atts(['title' => 'Kontakt'], $atts);
        $o = self::profile(self::oid());
        if (!$o) return '';
        $rows = [];
        $host = function ($u) { $h = (string) parse_url($u, PHP_URL_HOST); return $h !== '' ? preg_replace('/^www\./', '', $h) : $u; };
        if ($o['website'] !== '') $rows[] = ['globe', $host($o['website']), $o['website']];
        $labels = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'spotify' => 'Spotify'];
        foreach ((array) $o['social'] as $k => $u) {
            if ($k === 'website' || $u === '') continue;
            $rows[] = [isset(self::ICONS[$k]) ? $k : 'link', $labels[$k] ?? ucfirst($k), $u];
        }
        if (!empty($o['email'])) $rows[] = ['mail', $o['email'], 'mailto:' . $o['email']];
        if (!empty($o['phone'])) $rows[] = ['phone', $o['phone'], 'tel:' . preg_replace('/[^0-9+]/', '', $o['phone'])];
        if (!empty($o['address'])) $rows[] = ['map-pin', $o['address'], 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($o['address'])];
        $html = '';
        foreach ($rows as [$ic, $label, $href]) {
            $ext = strpos($href, 'http') === 0 ? ' target="_blank" rel="noopener"' : '';
            $html .= '<a class="tix-crow" href="' . esc_url($href) . '"' . $ext . '>' . self::badge($ic) . '<span>' . esc_html($label) . '</span>' . self::icon('chevron', 'tix-ico tix-chev') . '</a>';
        }
        return self::section($atts['title'], $html !== '' ? '<div class="tix-crows">' . $html . '</div>' : '', 'tix-org-contact');
    }
}
