<?php
/**
 * Veranstalter-Seiten für Seitenvorlagen (evendis.de, Breakdance).
 *
 * Nur im Mehr-Veranstalter-Modus (TIX_App_Scope::multi()): Der Beitragstyp tix_organizer
 * wird öffentlich unter /veranstalter/<name>/, damit Breakdance eine Vorlage „Single
 * Veranstalter“ anwenden kann. Sichtbar sind nur Veranstalter, die auch die Plattform
 * öffentlich listet (Filter `tix_organizer_page_public`, z. B. für die Veranstalter-Freigabe).
 *
 * Shortcodes für die berechneten Teile (alle beziehen sich auf den aktuellen Veranstalter):
 *   [tix_org_logo] [tix_org_glow] [tix_org_subtitle] [tix_org_categories] [tix_org_facts]
 *   [tix_org_description] [tix_org_modules title="Angebot"] [tix_org_contact title="Kontakt"]
 * Events des Veranstalters: [tix_events organizer="current"].
 */
if (!defined('ABSPATH')) exit;

class TIX_Organizer_Pages {

    const SLUG = 'veranstalter';
    const REWRITE_VERSION = '1';

    /** Lucide-Icons (ISC), Strich 2 – Bausteine der Seitenvorlage */
    const ICONS = [
        'calendar' => '<path d="M8 2v3m8-3v3"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/>',
        'ticket' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Zm11-4v2m0 10v2m0-8v2"/>',
        'map-pin' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20a14.5 14.5 0 0 0 0-20M2 12h20"/>',
        'mail' => '<path d="m22 7l-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect width="20" height="16" x="2" y="4" rx="2"/>',
        'phone' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233a14 14 0 0 0 6.392 6.384"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'music' => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'star' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.12 2.12 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.12 2.12 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.12 2.12 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.12 2.12 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.12 2.12 0 0 0 1.597-1.16z"/>',
        'gift' => '<path d="M12 7v14m8-10v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8m3.5-4a1 1 0 0 1 0-5A4.8 8 0 0 1 12 7a4.8 8 0 0 1 4.5-5a1 1 0 0 1 0 5"/><rect width="18" height="4" x="3" y="7" rx="1"/>',
        'chat' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092a10 10 0 1 0-4.777-4.719"/>',
        'file' => '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5M10 9H8m8 4H8m8 4H8"/>',
        'instagram' => '<rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8A4 4 0 0 1 16 11.37m1.5-4.87h.01"/>',
        'facebook' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
        'youtube' => '<path d="M2.5 17a24.1 24.1 0 0 1 0-10a2 2 0 0 1 1.4-1.4a49.6 49.6 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24.1 24.1 0 0 1 0 10a2 2 0 0 1-1.4 1.4a49.6 49.6 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><path d="m10 15l5-3l-5-3z"/>',
        'chevron' => '<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18l6-6l-6-6"/>',
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
        return '<svg class="' . esc_attr($class) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
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
