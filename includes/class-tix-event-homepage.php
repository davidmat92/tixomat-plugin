<?php
/**
 * TIX Event Homepage — [tix_homepage] Shortcode
 *
 * Features:
 * - Hero (Grid / Slider / Fullwidth)
 * - "Heute beliebt" Sektion (nach Verkäufen)
 * - Zeitfilter + Kategorie-Chips (AJAX)
 * - Countdown bei bald startenden Events
 * - Skeleton Loading
 * - URL-Sync (?time=…&cat=…)
 * - JSON-LD Event Schema
 * - Newsletter-Banner
 * - Lazy Loading für Bilder
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_Homepage {

    public static function init() {
        add_shortcode('tix_homepage', [__CLASS__, 'render']);

        // Event-CPT-Archive-Seite: Suche (?s=) + Standard-Sortierung nach Datum filtern
        add_action('pre_get_posts', [__CLASS__, 'filter_archive_query']);

        add_action('wp_ajax_tix_homepage_filter',            [__CLASS__, 'ajax_filter']);
        add_action('wp_ajax_nopriv_tix_homepage_filter',     [__CLASS__, 'ajax_filter']);
        add_action('wp_ajax_tix_homepage_load_more',         [__CLASS__, 'ajax_load_more']);
        add_action('wp_ajax_nopriv_tix_homepage_load_more',  [__CLASS__, 'ajax_load_more']);
        add_action('wp_ajax_tix_homepage_cards_by_ids',      [__CLASS__, 'ajax_cards_by_ids']);
        add_action('wp_ajax_nopriv_tix_homepage_cards_by_ids',[__CLASS__, 'ajax_cards_by_ids']);
        add_action('wp_ajax_tix_homepage_near_me',           [__CLASS__, 'ajax_near_me']);
        add_action('wp_ajax_nopriv_tix_homepage_near_me',    [__CLASS__, 'ajax_near_me']);
        add_action('wp_ajax_tix_homepage_calendar',          [__CLASS__, 'ajax_calendar']);
        add_action('wp_ajax_nopriv_tix_homepage_calendar',   [__CLASS__, 'ajax_calendar']);

        // Cache-Invalidierung bei Daten-Änderungen
        add_action('save_post_event',       [__CLASS__, 'invalidate_cache']);
        add_action('deleted_post',          [__CLASS__, 'invalidate_cache_if_event'], 10, 2);
        add_action('trashed_post',          [__CLASS__, 'invalidate_cache_if_event']);
        add_action('untrash_post',          [__CLASS__, 'invalidate_cache_if_event']);
        add_action('update_option_tix_settings', [__CLASS__, 'invalidate_cache']);
    }

    /**
     * pre_get_posts: Auf der Event-CPT-Archive-Seite (/events/) die Hauptquery
     * mit ?s= filtern und nach Start-Datum sortieren (aufsteigend, nur kommende).
     * Zusätzlich: Venue-Match (Events in matching Locations).
     */
    public static function filter_archive_query($query) {
        if (is_admin() || !$query->is_main_query()) return;
        if (!$query->is_post_type_archive('event')) return;

        $today = current_time('Y-m-d');
        $meta_query = $query->get('meta_query') ?: [];

        // Nur kommende Events
        $meta_query[] = [
            'key'     => '_tix_date_start',
            'value'   => $today,
            'compare' => '>=',
            'type'    => 'DATE',
        ];
        $query->set('meta_query', $meta_query);
        $query->set('meta_key', '_tix_date_start');
        $query->set('orderby', 'meta_value');
        $query->set('order', 'ASC');

        // ?s= wird von WordPress automatisch durchgereicht und filtert auf post_title + post_content.
        // Hinweis: Wenn Suche aktiv ist, nimmt WordPress normalerweise die Sortierung aus `s`,
        // aber unser explizites meta_key/orderby oben zwingt stabile Datum-ASC-Sortierung.
    }

    /**
     * Cache-Invalidation: Version-Bump macht alle transients invalide.
     * Wir nutzen eine globale "cache-generation" als Teil des Transient-Keys.
     */
    public static function invalidate_cache() {
        $gen = intval(get_option('tix_hp_cache_gen', 0));
        update_option('tix_hp_cache_gen', $gen + 1, false);
    }
    public static function invalidate_cache_if_event($post_id, $post = null) {
        if ($post === null) $post = get_post($post_id);
        if ($post && $post->post_type === 'event') self::invalidate_cache();
    }

    // ══════════════════════════════════════════════
    // SECTION REGISTRY (Phase-1 Page-Builder)
    // ══════════════════════════════════════════════

    /**
     * Alle verfügbaren Sektionen mit Label + Legacy-Key.
     * Die legacy_key referenziert den bestehenden hp_show_* Toggle für Abwärtskompatibilität.
     * default_order definiert die vordefinierte Reihenfolge für Erst-Setup.
     */
    public static function get_section_registry() {
        return [
            'greeting'       => ['label' => 'Smart-Greeting (Tageszeit)', 'icon' => 'smiley',           'legacy_key' => 'hp_show_greeting',        'default' => false],
            'stories'        => ['label' => 'Story-Carousel (Insta-Style)', 'icon' => 'images-alt2',   'legacy_key' => 'hp_show_stories',         'default' => false],
            'promoted'       => ['label' => 'Promoted Events (Bezahlt)', 'icon' => 'megaphone',        'legacy_key' => 'hp_show_promoted',        'default' => false],
            'editorial'      => ['label' => 'Empfehlung der Redaktion',  'icon' => 'edit-large',       'legacy_key' => 'hp_show_editorial',       'default' => false],
            'hero'           => ['label' => 'Hero-Bereich',             'icon' => 'cover-image',      'legacy_key' => 'hp_show_hero',            'default' => true],
            'hero_countdown' => ['label' => 'Hero-Countdown',           'icon' => 'clock',            'legacy_key' => 'hp_show_hero_countdown',  'default' => false],
            'favorites'      => ['label' => 'Deine Favoriten (Wishlist)', 'icon' => 'heart',          'legacy_key' => 'hp_show_favorites',       'default' => false],
            'recent'         => ['label' => 'Kürzlich angesehen',       'icon' => 'backup',           'legacy_key' => 'hp_show_recent',          'default' => false],
            'stats_bar'      => ['label' => 'Stats-Bar',                'icon' => 'chart-bar',        'legacy_key' => 'hp_show_stats_bar',       'default' => true],
            'cat_tiles'      => ['label' => 'Kategorie-Kacheln',        'icon' => 'screenoptions',    'legacy_key' => 'hp_show_cat_tiles',       'default' => true],
            'popular'        => ['label' => 'Heute beliebt',            'icon' => 'awards',           'legacy_key' => 'hp_show_popular',         'default' => true],
            'last_chance'    => ['label' => 'Letzte Chance (FOMO)',     'icon' => 'warning',          'legacy_key' => 'hp_show_last_chance',     'default' => false],
            'weekday_grid'   => ['label' => 'Wochentag-Grid (Mo–So)',   'icon' => 'calendar',         'legacy_key' => 'hp_show_weekday_grid',    'default' => false],
            'this_week'      => ['label' => 'Diese Woche',              'icon' => 'calendar-alt',     'legacy_key' => 'hp_show_this_week',       'default' => true],
            'newsletter'     => ['label' => 'Newsletter-Banner',        'icon' => 'email-alt2',       'legacy_key' => 'hp_show_newsletter',      'default' => false],
            'voucher'        => ['label' => 'Geschenkgutschein-Banner', 'icon' => 'tickets-alt',      'legacy_key' => 'hp_show_voucher',         'default' => false],
            'spotlight'      => ['label' => 'Veranstalter-Spotlight',   'icon' => 'star-filled',      'legacy_key' => 'hp_show_spotlight',       'default' => false],
            'filters'        => ['label' => 'Filter (Zeit/Kategorie)',  'icon' => 'filter',           'legacy_key' => '',                        'default' => true],
            'grid'           => ['label' => 'Event-Grid (Hauptliste)',  'icon' => 'grid-view',        'legacy_key' => '',                        'default' => true],
            'locations'      => ['label' => 'Beliebte Locations',       'icon' => 'location',         'legacy_key' => 'hp_show_locations',       'default' => true],
            'partners'       => ['label' => 'Partner/Presse-Logos',     'icon' => 'businesswoman',    'legacy_key' => 'hp_show_partners',        'default' => false],
            'faq'            => ['label' => 'FAQ-Accordion',            'icon' => 'editor-help',      'legacy_key' => 'hp_show_faq',             'default' => false],
            'load_more'      => ['label' => '"Mehr laden" Button',      'icon' => 'plus-alt',         'legacy_key' => 'hp_show_load_more',       'default' => true],
        ];
    }

    // ═══════════════════════════════════════════
    // Smart-Greeting: Text basierend auf Tageszeit + Wochentag
    // ═══════════════════════════════════════════

    public static function get_smart_greeting() {
        $hour = intval(current_time('H'));
        $dow  = intval(current_time('w')); // 0=So, 5=Fr, 6=Sa

        if ($dow === 5 && $hour >= 12 && $hour < 20) {
            return ['Wochenende ruft 🎉', 'Wo willst du heute und morgen hin?'];
        }
        if ($dow === 6 && $hour >= 12) {
            return ['Was geht heute? 🔥', 'Die heißen Events für deinen Samstag.'];
        }
        if ($dow === 0 && $hour >= 16) {
            return ['Noch nicht genug? 🎈', 'Diese Woche wird gut — plan deine Highlights.'];
        }
        if ($hour < 11) {
            return ['Guten Morgen ☀️', 'Was steht heute Abend an?'];
        }
        if ($hour >= 11 && $hour < 17) {
            return ['Bereit für Action? 🎯', 'Die besten Events in deiner Nähe.'];
        }
        if ($hour >= 17 && $hour < 22) {
            return ['Heute Abend los? 🌆', 'Spontan-Tickets für heute und morgen.'];
        }
        return ['Noch wach? 🌙', 'Plan den nächsten Abend direkt jetzt.'];
    }

    /**
     * Gibt die konfigurierte Sektions-Liste zurück (Reihenfolge + enabled).
     * Fällt auf default_order zurück wenn nichts gespeichert. Führt Migrations
     * von Legacy-hp_show_*-Flags durch, wenn hp_sections nicht gesetzt ist.
     */
    public static function get_sections_config() {
        $s        = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $registry = self::get_section_registry();
        $stored   = $s['hp_sections'] ?? null;

        // Migration: wenn noch nie konfiguriert → aus Legacy-Flags ableiten
        if (!is_array($stored) || empty($stored)) {
            $out = [];
            foreach ($registry as $id => $def) {
                $enabled = $def['default'];
                if (!empty($def['legacy_key'])) {
                    // Legacy-Flag liest gesetzten Wert, sonst default
                    $enabled = isset($s[$def['legacy_key']])
                        ? !empty($s[$def['legacy_key']])
                        : $def['default'];
                }
                $out[] = ['id' => $id, 'enabled' => $enabled];
            }
            return $out;
        }

        // Gespeicherte Reihenfolge + enabled-Flags
        $out      = [];
        $seen_ids = [];
        foreach ($stored as $row) {
            $id = is_array($row) ? ($row['id'] ?? '') : '';
            if (!$id || !isset($registry[$id])) continue;
            $out[] = [
                'id'      => $id,
                'enabled' => !empty($row['enabled']),
                'spacing' => isset($row['spacing']) ? max(0, min(200, intval($row['spacing']))) : -1, // -1 = Default übernehmen
            ];
            $seen_ids[] = $id;
        }
        // Neue Sektionen die nach einem Update hinzugekommen sind → hinten anhängen
        foreach ($registry as $id => $def) {
            if (in_array($id, $seen_ids, true)) continue;
            $out[] = ['id' => $id, 'enabled' => $def['default'], 'spacing' => -1];
        }
        return $out;
    }

    /**
     * Shortcode: [tix_homepage]
     */
    public static function render($atts) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];

        // Master-Toggle: Event-Homepage deaktiviert → nichts ausgeben (bzw. nur Hinweis für Admins).
        if (empty($s['hp_enabled'])) {
            if (current_user_can('manage_options')) {
                return '<div style="padding:16px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px;text-align:center;">'
                     . '[tix_homepage] ist deaktiviert. Aktiviere in <em>Tixomat → Einstellungen → Event-Homepage → Layout → „Event-Homepage aktivieren"</em>.'
                     . '<br><span style="font-size:11px;opacity:0.7;">(Dieser Hinweis ist nur für Admins sichtbar.)</span></div>';
            }
            return '';
        }

        $atts = shortcode_atts([
            'mode'       => $s['tix_card_default_mode'] ?? 'light',
            'hero_count' => intval($s['hp_hero_count'] ?? 5),
            'grid_count' => intval($s['hp_grid_count'] ?? 8),
            'columns'    => intval($s['tix_card_columns_default'] ?? 4),
        ], $atts);

        // Settings
        $show_hero        = !empty($s['hp_show_hero'] ?? 1);
        $hero_style       = $s['hp_hero_style'] ?? 'grid';
        $show_time_filter = !empty($s['hp_show_time_filter'] ?? 1);
        $show_cat_filter  = !empty($s['hp_show_cat_filter'] ?? 1);
        $show_load_more   = !empty($s['hp_show_load_more'] ?? 1);
        $only_featured    = !empty($s['hp_only_featured_hero']);
        $exclude_cats     = array_filter(array_map('trim', explode(',', $s['hp_exclude_categories'] ?? '')));
        $show_popular     = !empty($s['hp_show_popular'] ?? 1);
        $popular_count    = intval($s['hp_popular_count'] ?? 4);
        $show_countdown   = !empty($s['hp_show_countdown'] ?? 1);
        $url_sync         = !empty($s['hp_url_sync'] ?? 1);
        $show_newsletter  = !empty($s['hp_show_newsletter']);
        $nl_title         = $s['hp_newsletter_title'] ?? 'Bleib auf dem Laufenden';
        $nl_text          = $s['hp_newsletter_text'] ?? '';
        $nl_url           = $s['hp_newsletter_url'] ?? '';
        $show_list_toggle = !empty($s['hp_show_list_toggle'] ?? 1);
        $show_spotlight   = !empty($s['hp_show_spotlight']);
        $spotlight_org_id = intval($s['hp_spotlight_org_id'] ?? 0);
        $spotlight_count  = intval($s['hp_spotlight_count'] ?? 3);
        $smart_time       = !empty($s['hp_smart_time'] ?? 1);
        $max_width        = intval($s['hp_max_width'] ?? 1200);
        $pad_x            = intval($s['hp_pad_x'] ?? 24);
        $pad_y            = intval($s['hp_pad_y'] ?? 40);

        // Dashboard-Sektionen
        $show_stats_bar    = !empty($s['hp_show_stats_bar'] ?? 1);
        $show_cat_tiles    = !empty($s['hp_show_cat_tiles'] ?? 1);
        $show_this_week    = !empty($s['hp_show_this_week'] ?? 1);
        $this_week_days    = max(3, min(14, intval($s['hp_this_week_days'] ?? 7)));
        $show_locations    = !empty($s['hp_show_locations'] ?? 1);
        $locations_count   = max(3, min(12, intval($s['hp_locations_count'] ?? 6)));

        // Tageszeit-Logik: Empfohlener Zeitfilter
        $smart_time_hint = '';
        if ($smart_time) {
            $smart_time_hint = self::get_smart_time_hint();
        }

        self::enqueue($atts['mode'], $url_sync);

        $show_heart  = !empty($s['tix_card_show_heart']);
        $show_badges = !empty($s['tix_card_show_badges']);
        $saved       = TIX_Event_Cards::get_saved_events_static();

        // Kategorien (ausgeschlossene entfernen)
        $categories = self::get_filtered_categories($exclude_cats);

        // Hero
        $hero_events = [];
        $hero_ids = [];
        if ($show_hero && intval($atts['hero_count']) > 0) {
            $hero_count = ($hero_style === 'fullwidth') ? 1 : intval($atts['hero_count']);
            $hero_events = self::query_hero_events($hero_count, $only_featured, $exclude_cats);
            $hero_ids = wp_list_pluck($hero_events, 'ID');
        }

        // Beliebt
        $popular_events = [];
        $popular_ids = [];
        if ($show_popular) {
            $popular_events = self::query_popular_events($popular_count, $hero_ids, $exclude_cats);
            $popular_ids = wp_list_pluck($popular_events, 'ID');
        }

        // Suchparameter aus URL (?s=xyz) — wenn gesetzt: nur Hauptgrid filtern, Hero/Popular/etc. ausblenden
        $search_query = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        // Grid (hero + popular IDs ausschließen — nur wenn keine Suche aktiv)
        $all_exclude = $search_query ? [] : array_merge($hero_ids, $popular_ids);
        $grid_count  = $search_query ? 50 : intval($atts['grid_count']); // Bei Suche mehr anzeigen
        $grid_events = self::query_grid_events($grid_count, $all_exclude, 0, '', '', $exclude_cats, $search_query);

        $total_upcoming = self::count_upcoming_events($exclude_cats);

        // Alle Events für JSON-LD sammeln
        $all_events = array_merge($hero_events, $popular_events, $grid_events);

        $skeleton_cols = intval($atts['columns']);

        // Sektions-Kontext für den Renderer zusammenstellen
        $ctx = compact(
            'atts', 's', 'hero_events', 'hero_style', 'hero_ids',
            'popular_events', 'popular_ids', 'grid_events', 'all_exclude',
            'total_upcoming', 'categories', 'exclude_cats', 'search_query',
            'show_heart', 'show_badges', 'saved', 'show_countdown',
            'show_time_filter', 'show_cat_filter', 'show_list_toggle',
            'show_load_more', 'smart_time_hint', 'skeleton_cols',
            'nl_title', 'nl_text', 'nl_url',
            'this_week_days', 'spotlight_org_id', 'spotlight_count',
            'locations_count'
        );

        $sections = self::get_sections_config();

        ob_start();
        ?>
        <div class="tix-hp tix-hp-<?php echo esc_attr($atts['mode']); ?>"
             style="max-width:<?php echo $max_width; ?>px;padding:<?php echo $pad_y; ?>px <?php echo $pad_x; ?>px;"
             data-mode="<?php echo esc_attr($atts['mode']); ?>"
             data-columns="<?php echo $skeleton_cols; ?>"
             data-url-sync="<?php echo $url_sync ? '1' : '0'; ?>"
             data-smart-time="<?php echo $smart_time_hint ? esc_attr($smart_time_hint) : ''; ?>">

            <?php
            $default_spacing = max(0, min(200, intval($s['hp_section_spacing'] ?? 40)));
            // Cache deaktivieren wenn Suche aktiv (individuelle Ergebnisse pro Query)
            $cache_enabled   = !empty($s['hp_cache_enabled']) && !is_user_logged_in() && empty($search_query);
            $cache_ttl       = max(60, min(3600, intval($s['hp_cache_ttl'] ?? 600)));
            $cache_gen       = intval(get_option('tix_hp_cache_gen', 0));

            // Bei aktiver Suche: Suchergebnis-Banner + nur Grid zeigen (Hero/Popular/Stories etc. ausblenden)
            if (!empty($search_query)) {
                $clear_url = remove_query_arg('s');
                $result_count = count($grid_events);
                echo '<div class="tix-hp-search-banner" style="margin-bottom:24px;padding:20px 24px;background:var(--tix-card-sand,#F8F5EF);border-radius:14px;display:flex;flex-wrap:wrap;align-items:center;gap:16px;justify-content:space-between;">';
                echo   '<div>';
                echo     '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;margin-bottom:4px;">Suchergebnisse</div>';
                echo     '<h2 style="margin:0;font-size:1.35rem;">' . $result_count . ' Event' . ($result_count !== 1 ? 's' : '') . ' für „<em>' . esc_html($search_query) . '</em>"</h2>';
                echo   '</div>';
                echo   '<a href="' . esc_url($clear_url) . '" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:transparent;border:1.5px solid currentColor;border-radius:50px;text-decoration:none;color:inherit;font-size:0.85rem;font-weight:600;">✕ Suche aufheben</a>';
                echo '</div>';
            }

            foreach ($sections as $sec) {
                if (empty($sec['enabled'])) continue;

                // Während aktiver Suche: Nur Grid + Load-More rendern, andere Sektionen ausblenden
                if (!empty($search_query) && !in_array($sec['id'], ['grid', 'load_more'], true)) continue;

                $html = null;
                $cache_key = '';
                if ($cache_enabled) {
                    // Cache-Key: Sektions-ID + relevante Kontext-Daten (damit Filter-AJAX nicht mit Hauptrender kollidiert)
                    $key_seed = [
                        $sec['id'],
                        $cache_gen,
                        $atts['mode'] ?? 'light',
                        $skeleton_cols,
                        implode(',', $exclude_cats),
                    ];
                    $cache_key = 'tix_hp_sec_' . md5(implode('|', $key_seed));
                    $cached = get_transient($cache_key);
                    if ($cached !== false) $html = $cached;
                }

                if ($html === null) {
                    $html = self::render_section($sec['id'], $ctx);
                    if ($cache_enabled && $cache_key) {
                        set_transient($cache_key, $html, $cache_ttl);
                    }
                }

                if (trim($html) === '') continue;
                // spacing > 0 = explizit gesetzt, sonst Default (0 oder -1 → Default verwenden)
                $gap = !empty($sec['spacing']) && intval($sec['spacing']) > 0 ? intval($sec['spacing']) : $default_spacing;
                // display:flow-root verhindert Margin-Collapse mit inneren Elementen.
                // padding-bottom statt margin-bottom → Kollabiert nicht mit Nachbarn.
                echo '<div class="tix-hp-sec-wrap" data-section="' . esc_attr($sec['id']) . '" style="display:flow-root;padding-bottom:' . $gap . 'px;">' . $html . '</div>';
            }
            ?>

        </div>

        <?php // ── JSON-LD ── ?>
        <?php echo self::render_json_ld($all_events); ?>

        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // SECTION DISPATCHER (Phase-1 Page-Builder)
    // ═══════════════════════════════════════════

    /**
     * Rendert eine einzelne Sektion anhand der ID. Nutzt den shared Context $ctx.
     */
    private static function render_section($id, $ctx) {
        extract($ctx);

        ob_start();

        switch ($id) {
            case 'hero':
                if (!empty($hero_events)) {
                    if ($hero_style === 'fullwidth') {
                        echo self::render_hero_fullwidth($hero_events[0], $show_heart, $show_badges, $saved, $show_countdown);
                    } elseif ($hero_style === 'slider') {
                        echo self::render_hero_slider($hero_events, $show_heart, $show_badges, $saved, $show_countdown);
                    } else {
                        echo self::render_hero_grid($hero_events, $show_heart, $show_badges, $saved, $show_countdown);
                    }
                }
                break;

            case 'greeting':
                echo self::render_greeting();
                break;

            case 'stories':
                echo self::render_stories($exclude_cats);
                break;

            case 'promoted':
                echo self::render_promoted($show_heart, $show_badges, $saved, $exclude_cats);
                break;

            case 'editorial':
                echo self::render_editorial();
                break;

            case 'favorites':
                echo self::render_favorites();
                break;

            case 'recent':
                echo self::render_recent();
                break;

            case 'hero_countdown':
                echo self::render_hero_countdown($exclude_cats);
                break;

            case 'last_chance':
                echo self::render_last_chance($show_heart, $show_badges, $saved, $exclude_cats);
                break;

            case 'weekday_grid':
                echo self::render_weekday_grid($exclude_cats);
                break;

            case 'voucher':
                echo self::render_voucher_banner();
                break;

            case 'partners':
                echo self::render_partners();
                break;

            case 'faq':
                echo self::render_faq_accordion();
                break;

            case 'stats_bar':
                echo self::render_stats_bar($exclude_cats);
                break;

            case 'cat_tiles':
                if (!empty($categories)) echo self::render_category_tiles($categories);
                break;

            case 'popular':
                if (!empty($popular_events)):
                ?>
                <div class="tix-hp-section">
                    <div class="tix-hp-section-header">
                        <div>
                            <div class="tix-hp-section-label">Trending</div>
                            <h2 class="tix-hp-section-title">Heute beliebt</h2>
                        </div>
                    </div>
                    <div class="ev-grid tix-hp-popular" data-columns="<?php echo $skeleton_cols; ?>">
                        <?php foreach ($popular_events as $event): ?>
                            <?php echo TIX_Event_Cards::render_card($event, $show_heart, $show_badges, $saved); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
                endif;
                break;

            case 'this_week':
                echo self::render_this_week($this_week_days, $exclude_cats);
                break;

            case 'newsletter':
                if ($nl_title || $nl_text):
                ?>
                <div class="tix-hp-newsletter">
                    <div class="tix-hp-newsletter-inner">
                        <div class="tix-hp-newsletter-content">
                            <?php if ($nl_title): ?><h3 class="tix-hp-newsletter-title"><?php echo esc_html($nl_title); ?></h3><?php endif; ?>
                            <?php if ($nl_text): ?><p class="tix-hp-newsletter-text"><?php echo esc_html($nl_text); ?></p><?php endif; ?>
                        </div>
                        <?php if ($nl_url): ?>
                        <a href="<?php echo esc_url($nl_url); ?>" class="tix-hp-newsletter-btn">Jetzt anmelden</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                endif;
                break;

            case 'spotlight':
                echo self::render_spotlight($spotlight_org_id, $spotlight_count, $show_heart, $show_badges, $saved, $exclude_cats);
                break;

            case 'filters':
                if ($show_time_filter || $show_cat_filter || $show_list_toggle):
                ?>
                <div class="tix-hp-filters">
                    <?php if ($show_time_filter): ?>
                    <div class="tix-hp-time-filters">
                        <button class="tix-hp-time-btn active" data-time="all">Alle</button>
                        <button class="tix-hp-time-btn<?php echo $smart_time_hint === 'today' ? ' tix-hp-suggested' : ''; ?>" data-time="today">Heute</button>
                        <button class="tix-hp-time-btn<?php echo $smart_time_hint === 'tomorrow' ? ' tix-hp-suggested' : ''; ?>" data-time="tomorrow">Morgen</button>
                        <button class="tix-hp-time-btn<?php echo $smart_time_hint === 'weekend' ? ' tix-hp-suggested' : ''; ?>" data-time="weekend">Wochenende</button>
                        <button class="tix-hp-time-btn" data-time="week">Diese Woche</button>
                        <button class="tix-hp-nearme-btn" title="Events in deiner Nähe finden" aria-label="Events in deiner Nähe finden">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="10" r="3"/><path d="M12 2a8 8 0 0 0-8 8c0 5.5 8 12 8 12s8-6.5 8-12a8 8 0 0 0-8-8z"/></svg>
                            <span>In der Nähe</span>
                        </button>
                    </div>
                    <?php endif; ?>
                    <div class="tix-hp-filter-row">
                        <?php if ($show_cat_filter && !empty($categories)): ?>
                        <div class="tix-hp-cat-chips">
                            <button class="tix-hp-cat-chip active" data-cat="">Alle</button>
                            <?php foreach ($categories as $cat): ?>
                                <button class="tix-hp-cat-chip" data-cat="<?php echo esc_attr($cat->slug); ?>"><?php echo esc_html($cat->name); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($show_list_toggle): ?>
                        <div class="tix-hp-view-toggle">
                            <button class="tix-hp-view-btn active" data-view="grid" aria-label="Grid-Ansicht">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                            </button>
                            <button class="tix-hp-view-btn" data-view="list" aria-label="Listen-Ansicht">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            </button>
                            <button class="tix-hp-view-btn" data-view="calendar" aria-label="Kalender-Ansicht">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                endif;
                break;

            case 'grid':
                ?>
                <div class="tix-hp-grid ev-grid" data-columns="<?php echo $skeleton_cols; ?>">
                    <?php foreach ($grid_events as $event): ?>
                        <?php echo TIX_Event_Cards::render_card($event, $show_heart, $show_badges, $saved); ?>
                    <?php endforeach; ?>
                    <?php if (empty($grid_events)): ?>
                        <p class="tix-hp-empty">Keine weiteren Events gefunden.</p>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'locations':
                echo self::render_location_spotlight($locations_count, $exclude_cats);
                break;

            case 'load_more':
                $loaded = count($all_exclude) + count($grid_events);
                if ($show_load_more && $loaded < $total_upcoming):
                ?>
                <div class="tix-hp-load-more-wrap">
                    <button class="tix-hp-load-more"
                            data-offset="<?php echo count($grid_events); ?>"
                            data-exclude="<?php echo esc_attr(implode(',', $all_exclude)); ?>"
                            data-exclude-cats="<?php echo esc_attr(implode(',', $exclude_cats)); ?>">
                        Mehr Events laden
                    </button>
                </div>
                <?php
                endif;
                break;
        }

        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Hero Varianten
    // ═══════════════════════════════════════════

    /**
     * Hero: Grid (1 groß + kleine)
     */
    private static function render_hero_grid($events, $show_heart, $show_badges, $saved, $show_countdown) {
        ob_start();
        ?>
        <div class="tix-hp-hero tix-hp-hero-grid">
            <div class="tix-hp-hero-main">
                <?php echo self::render_hero_card($events[0], $show_heart, $show_badges, $saved, $show_countdown); ?>
            </div>
            <?php if (count($events) > 1): ?>
            <div class="tix-hp-hero-side">
                <?php for ($i = 1; $i < count($events); $i++): ?>
                    <?php echo self::render_hero_card_small($events[$i], $show_heart, $show_badges, $saved, $show_countdown); ?>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Hero: Fullwidth (1 großes Event)
     */
    private static function render_hero_fullwidth($event, $show_heart, $show_badges, $saved, $show_countdown) {
        ob_start();
        ?>
        <div class="tix-hp-hero tix-hp-hero-fullwidth">
            <?php echo self::render_hero_card($event, $show_heart, $show_badges, $saved, $show_countdown); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Hero: Slider (Autoplay)
     */
    private static function render_hero_slider($events, $show_heart, $show_badges, $saved, $show_countdown) {
        ob_start();
        ?>
        <div class="tix-hp-hero tix-hp-hero-slider" data-autoplay="5000">
            <div class="tix-hp-slider-track">
                <?php foreach ($events as $event): ?>
                <div class="tix-hp-slider-slide">
                    <?php echo self::render_hero_card($event, $show_heart, $show_badges, $saved, $show_countdown); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($events) > 1): ?>
            <div class="tix-hp-slider-dots">
                <?php for ($i = 0; $i < count($events); $i++): ?>
                    <button class="tix-hp-slider-dot<?php echo $i === 0 ? ' active' : ''; ?>" data-index="<?php echo $i; ?>"></button>
                <?php endfor; ?>
            </div>
            <button class="tix-hp-slider-arrow tix-hp-slider-prev" aria-label="Zurück">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="tix-hp-slider-arrow tix-hp-slider-next" aria-label="Weiter">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 6 15 12 9 18"/></svg>
            </button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Hero Karten
    // ═══════════════════════════════════════════

    private static function render_hero_card($event, $show_heart, $show_badges, $saved, $show_countdown = false) {
        $id = $event->ID;
        $title = get_the_title($id);
        $permalink = get_permalink($id);
        $thumb_url = get_the_post_thumbnail_url($id, 'large');
        $date_card = get_post_meta($id, '_tix_date_card', true);
        $date_start = get_post_meta($id, '_tix_date_start', true);
        $location_short = get_post_meta($id, '_tix_location_short', true);
        $price_card = get_post_meta($id, '_tix_price_card', true);

        $terms = wp_get_post_terms($id, 'event_category', ['fields' => 'all']);
        $cat_name = (!is_wp_error($terms) && !empty($terms)) ? $terms[0]->name : '';

        $is_saved = in_array($id, $saved);
        $heart_default = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';
        $heart_saved = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';
        $pin = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>';

        // Countdown
        $countdown_html = '';
        if ($show_countdown && $date_start) {
            $start_ts = strtotime($date_start);
            $now = current_time('timestamp');
            $diff = $start_ts - $now;
            if ($diff > 0 && $diff <= 86400) {
                $countdown_html = '<span class="tix-hp-countdown" data-start="' . esc_attr($date_start) . '"></span>';
            }
        }

        ob_start();
        ?>
        <a href="<?php echo esc_url($permalink); ?>" class="tix-hp-hero-card">
            <div class="tix-hp-hero-img"<?php if ($thumb_url): ?> style="background-image:url('<?php echo esc_url($thumb_url); ?>');"<?php endif; ?> aria-label="<?php echo esc_attr($title); ?>">
                <?php if (!$thumb_url): ?>
                    <?php echo TIX_Event_Cards::get_placeholder('default'); ?>
                <?php endif; ?>
                <div class="tix-hp-hero-overlay"></div>
            </div>
            <div class="tix-hp-hero-badges">
                <?php if ($cat_name): ?><span class="ev-badge ev-badge-cat"><?php echo esc_html($cat_name); ?></span><?php endif; ?>
                <?php echo $countdown_html; ?>
            </div>
            <?php if ($show_heart): ?>
            <button class="ev-save <?php echo $is_saved ? 'saved' : ''; ?>" data-event-id="<?php echo $id; ?>" onclick="event.preventDefault();event.stopPropagation();tixToggleSave(this);">
                <?php echo $is_saved ? $heart_saved : $heart_default; ?>
            </button>
            <?php endif; ?>
            <div class="tix-hp-hero-content">
                <div class="tix-hp-hero-date"><?php echo esc_html($date_card); ?></div>
                <h2 class="tix-hp-hero-title"><?php echo esc_html($title); ?></h2>
                <div class="tix-hp-hero-meta">
                    <?php if ($location_short): ?>
                        <span class="tix-hp-hero-loc"><?php echo $pin; ?> <?php echo esc_html($location_short); ?></span>
                    <?php endif; ?>
                    <?php if ($price_card): ?>
                        <span class="tix-hp-hero-price"><?php echo esc_html($price_card); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php
        return ob_get_clean();
    }

    private static function render_hero_card_small($event, $show_heart, $show_badges, $saved, $show_countdown = false) {
        $id = $event->ID;
        $title = get_the_title($id);
        $permalink = get_permalink($id);
        $thumb_url = get_the_post_thumbnail_url($id, 'large');
        $date_card = get_post_meta($id, '_tix_date_card', true);
        $date_start = get_post_meta($id, '_tix_date_start', true);
        $location_short = get_post_meta($id, '_tix_location_short', true);

        $countdown_html = '';
        if ($show_countdown && $date_start) {
            $start_ts = strtotime($date_start);
            $now = current_time('timestamp');
            $diff = $start_ts - $now;
            if ($diff > 0 && $diff <= 86400) {
                $countdown_html = '<span class="tix-hp-countdown tix-hp-countdown-sm" data-start="' . esc_attr($date_start) . '"></span>';
            }
        }

        ob_start();
        ?>
        <a href="<?php echo esc_url($permalink); ?>" class="tix-hp-hero-sm">
            <div class="tix-hp-hero-sm-img"<?php if ($thumb_url): ?> style="background-image:url('<?php echo esc_url($thumb_url); ?>');"<?php endif; ?> aria-label="<?php echo esc_attr($title); ?>">
                <?php if (!$thumb_url): ?>
                    <?php echo TIX_Event_Cards::get_placeholder('default'); ?>
                <?php endif; ?>
                <div class="tix-hp-hero-overlay"></div>
            </div>
            <div class="tix-hp-hero-sm-content">
                <?php echo $countdown_html; ?>
                <div class="tix-hp-hero-date"><?php echo esc_html($date_card); ?></div>
                <div class="tix-hp-hero-sm-title"><?php echo esc_html($title); ?></div>
                <?php if ($location_short): ?>
                    <div class="tix-hp-hero-sm-loc"><?php echo esc_html($location_short); ?></div>
                <?php endif; ?>
            </div>
        </a>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Queries
    // ═══════════════════════════════════════════

    private static function get_filtered_categories($exclude_cats = []) {
        $all_cats = get_terms(['taxonomy' => 'event_category', 'hide_empty' => true]);
        if (is_wp_error($all_cats)) return [];
        if (empty($exclude_cats)) return $all_cats;
        return array_filter($all_cats, function($cat) use ($exclude_cats) {
            return !in_array($cat->slug, $exclude_cats);
        });
    }

    private static function query_hero_events($count = 5, $only_featured = false, $exclude_cats = []) {
        $today = current_time('Y-m-d');

        $base_args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                ['key' => '_tix_is_featured', 'value' => '1'],
            ],
        ];
        if (!empty($exclude_cats)) {
            $base_args['tax_query'] = [['taxonomy' => 'event_category', 'field' => 'slug', 'terms' => $exclude_cats, 'operator' => 'NOT IN']];
        }

        $featured = get_posts($base_args);
        if ($only_featured || count($featured) >= $count) return array_slice($featured, 0, $count);

        $exclude = wp_list_pluck($featured, 'ID');
        $remaining = $count - count($featured);
        $fill_args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $remaining,
            'post__not_in'   => $exclude,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
            ],
        ];
        if (!empty($exclude_cats)) {
            $fill_args['tax_query'] = [['taxonomy' => 'event_category', 'field' => 'slug', 'terms' => $exclude_cats, 'operator' => 'NOT IN']];
        }
        return array_merge($featured, get_posts($fill_args));
    }

    /**
     * Heute beliebt: Nach Verkaufszahlen sortiert
     */
    private static function query_popular_events($count = 4, $exclude_ids = [], $exclude_cats = []) {
        $today = current_time('Y-m-d');
        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'meta_key'       => '_tix_sold_total',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
            'meta_query'     => [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                ['key' => '_tix_sold_total', 'value' => '0', 'compare' => '>', 'type' => 'NUMERIC'],
            ],
        ];
        if (!empty($exclude_ids)) {
            $args['post__not_in'] = $exclude_ids;
        }
        if (!empty($exclude_cats)) {
            $args['tax_query'] = [['taxonomy' => 'event_category', 'field' => 'slug', 'terms' => $exclude_cats, 'operator' => 'NOT IN']];
        }
        return get_posts($args);
    }

    private static function query_grid_events($count = 8, $exclude_ids = [], $offset = 0, $time = '', $category = '', $exclude_cats = [], $search = '') {
        $today = current_time('Y-m-d');
        $meta_query = [self::get_time_meta_query($time, $today)];

        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'offset'         => $offset,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => $meta_query,
        ];
        if (!empty($search)) {
            $args['s'] = $search;
        }
        if (!empty($exclude_ids)) {
            $args['post__not_in'] = $exclude_ids;
        }
        $tax_query = [];
        if (!empty($category)) {
            $tax_query[] = ['taxonomy' => 'event_category', 'field' => 'slug', 'terms' => $category];
        }
        if (!empty($exclude_cats)) {
            $tax_query[] = ['taxonomy' => 'event_category', 'field' => 'slug', 'terms' => $exclude_cats, 'operator' => 'NOT IN'];
        }
        if (!empty($tax_query)) {
            if (count($tax_query) > 1) $tax_query['relation'] = 'AND';
            $args['tax_query'] = $tax_query;
        }
        $results = get_posts($args);

        // Venue-Match: bei Suche zusätzlich Events in Venues finden deren Name matcht
        if (!empty($search) && count($results) < $count) {
            global $wpdb;
            $loc_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'tix_location' AND p.post_status = 'publish' AND p.post_title LIKE %s LIMIT 10",
                '%' . $wpdb->esc_like($search) . '%'
            ));
            if (!empty($loc_ids)) {
                $existing_ids = wp_list_pluck($results, 'ID');
                $extra_exclude = array_merge($exclude_ids, $existing_ids);
                $venue_args = $args;
                unset($venue_args['s']);
                $venue_args['posts_per_page'] = $count - count($results);
                $venue_args['post__not_in'] = $extra_exclude ?: [0];
                $venue_args['meta_query'] = array_merge($meta_query, [['key' => '_tix_location_id', 'value' => $loc_ids, 'compare' => 'IN']]);
                $venue_results = get_posts($venue_args);
                $results = array_merge($results, $venue_results);
            }
        }

        return $results;
    }

    private static function get_time_meta_query($time, $today = '') {
        if (!$today) $today = current_time('Y-m-d');
        switch ($time) {
            case 'today':
                return ['key' => '_tix_date_start', 'value' => $today, 'compare' => '=', 'type' => 'DATE'];
            case 'tomorrow':
                return ['key' => '_tix_date_start', 'value' => date('Y-m-d', strtotime($today . ' +1 day')), 'compare' => '=', 'type' => 'DATE'];
            case 'weekend':
                $dow = date('N', strtotime($today));
                if ($dow >= 6) {
                    $start = $today;
                    $end = ($dow == 7) ? $today : date('Y-m-d', strtotime('next sunday', strtotime($today)));
                } else {
                    $start = date('Y-m-d', strtotime('next saturday', strtotime($today)));
                    $end = date('Y-m-d', strtotime('next sunday', strtotime($today)));
                }
                return ['key' => '_tix_date_start', 'value' => [$start, $end], 'compare' => 'BETWEEN', 'type' => 'DATE'];
            case 'week':
                return ['key' => '_tix_date_start', 'value' => [$today, date('Y-m-d', strtotime('next sunday', strtotime($today)))], 'compare' => 'BETWEEN', 'type' => 'DATE'];
            default:
                return ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'];
        }
    }

    private static function count_upcoming_events($exclude_cats = []) {
        $today = current_time('Y-m-d');
        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE']],
        ];
        if (!empty($exclude_cats)) {
            $args['tax_query'] = [['taxonomy' => 'event_category', 'field' => 'slug', 'terms' => $exclude_cats, 'operator' => 'NOT IN']];
        }
        $q = new WP_Query($args);
        return $q->found_posts;
    }

    // ═══════════════════════════════════════════
    // Listenansicht
    // ═══════════════════════════════════════════

    public static function render_list_item($event, $show_heart = true, $saved = []) {
        $id = $event->ID;
        $title = get_the_title($id);
        $permalink = get_permalink($id);
        $thumb_url = get_the_post_thumbnail_url($id, 'medium');
        $date_card = get_post_meta($id, '_tix_date_card', true);
        $location_short = get_post_meta($id, '_tix_location_short', true);
        $price_card = get_post_meta($id, '_tix_price_card', true);
        $price_min = floatval(get_post_meta($id, '_tix_price_min', true));
        $date_start = get_post_meta($id, '_tix_date_start', true);

        $terms = wp_get_post_terms($id, 'event_category', ['fields' => 'all']);
        $cat_name = (!is_wp_error($terms) && !empty($terms)) ? $terms[0]->name : '';

        $is_past = $date_start && strtotime($date_start) < current_time('timestamp');
        $cats = get_post_meta($id, '_tix_ticket_categories', true);
        $total = 0;
        $sold = intval(get_post_meta($id, '_tix_sold_total', true));
        if (is_array($cats)) foreach ($cats as $c) $total += intval($c['qty'] ?? 0);
        $is_soldout = ($total > 0 && ($total - $sold) <= 0) || $is_past;
        $is_free = ($price_min <= 0);

        $pin = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>';

        ob_start();
        ?>
        <a href="<?php echo esc_url($permalink); ?>" class="tix-hp-list-item<?php echo $is_soldout ? ' tix-hp-list-soldout' : ''; ?>">
            <div class="tix-hp-list-img">
                <?php if ($thumb_url): ?>
                    <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
                <?php else: ?>
                    <div class="tix-hp-list-placeholder"></div>
                <?php endif; ?>
            </div>
            <div class="tix-hp-list-info">
                <div class="tix-hp-list-date"><?php echo esc_html($date_card ?: $date_start); ?></div>
                <div class="tix-hp-list-title"><?php echo esc_html($title); ?></div>
                <div class="tix-hp-list-meta">
                    <?php if ($location_short): ?><span><?php echo $pin; ?> <?php echo esc_html($location_short); ?></span><?php endif; ?>
                    <?php if ($cat_name): ?><span class="tix-hp-list-cat"><?php echo esc_html($cat_name); ?></span><?php endif; ?>
                </div>
            </div>
            <div class="tix-hp-list-right">
                <?php if ($is_soldout): ?>
                    <span class="tix-hp-list-price tix-hp-list-soldout-label">Ausverkauft</span>
                <?php elseif ($is_free): ?>
                    <span class="tix-hp-list-price tix-hp-list-free">Eintritt frei</span>
                <?php elseif ($price_card): ?>
                    <span class="tix-hp-list-price"><?php echo esc_html($price_card); ?></span>
                <?php endif; ?>
            </div>
        </a>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Veranstalter-Spotlight
    // ═══════════════════════════════════════════

    private static function render_spotlight($org_id, $event_count, $show_heart, $show_badges, $saved, $exclude_cats = []) {
        $today = current_time('Y-m-d');

        // Veranstalter finden
        if ($org_id > 0) {
            $organizer = get_post($org_id);
            if (!$organizer || $organizer->post_type !== 'tix_organizer') return '';
        } else {
            // Automatisch: Veranstalter mit den meisten kommenden Events
            $organizer = self::find_top_organizer($exclude_cats);
            if (!$organizer) return '';
            $org_id = $organizer->ID;
        }

        // Events dieses Veranstalters
        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $event_count,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                ['key' => '_tix_organizer_id', 'value' => $org_id, 'type' => 'NUMERIC'],
            ],
        ];
        $events = get_posts($args);
        if (empty($events)) return '';

        $org_name = get_the_title($org_id);
        $org_desc = get_post_meta($org_id, '_tix_org_short_desc', true);
        $org_image_id = get_post_meta($org_id, '_tix_org_image_id', true);
        $org_image_url = $org_image_id ? wp_get_attachment_image_url(intval($org_image_id), 'thumbnail') : '';
        $org_city = get_post_meta($org_id, '_tix_org_city', true);

        ob_start();
        ?>
        <div class="tix-hp-spotlight">
            <div class="tix-hp-section-header">
                <div>
                    <div class="tix-hp-section-label">Veranstalter-Spotlight</div>
                    <h2 class="tix-hp-section-title">Präsentiert von</h2>
                </div>
            </div>
            <div class="tix-hp-spotlight-content">
                <div class="tix-hp-spotlight-org">
                    <?php if ($org_image_url): ?>
                    <div class="tix-hp-spotlight-logo">
                        <img src="<?php echo esc_url($org_image_url); ?>" alt="<?php echo esc_attr($org_name); ?>" loading="lazy">
                    </div>
                    <?php endif; ?>
                    <div class="tix-hp-spotlight-info">
                        <h3 class="tix-hp-spotlight-name"><?php echo esc_html($org_name); ?></h3>
                        <?php if ($org_city): ?>
                            <div class="tix-hp-spotlight-city"><?php echo esc_html($org_city); ?></div>
                        <?php endif; ?>
                        <?php if ($org_desc): ?>
                            <p class="tix-hp-spotlight-desc"><?php echo esc_html($org_desc); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tix-hp-spotlight-events">
                    <?php foreach ($events as $event): ?>
                        <?php echo TIX_Event_Cards::render_card($event, $show_heart, $show_badges, $saved); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function find_top_organizer($exclude_cats = []) {
        global $wpdb;
        $today = current_time('Y-m-d');

        // Veranstalter mit meisten kommenden Events
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS org_id, COUNT(*) AS cnt
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_organizer_id'
             JOIN {$wpdb->postmeta} ds ON p.ID = ds.post_id AND ds.meta_key = '_tix_date_start'
             WHERE p.post_type = 'event'
               AND p.post_status = 'publish'
               AND ds.meta_value >= %s
               AND pm.meta_value > 0
             GROUP BY pm.meta_value
             ORDER BY cnt DESC
             LIMIT 1",
            $today
        ));

        if (empty($results)) return null;
        $org_id = intval($results[0]->org_id);
        $org = get_post($org_id);
        return ($org && $org->post_type === 'tix_organizer' && $org->post_status === 'publish') ? $org : null;
    }

    // ═══════════════════════════════════════════
    // Tageszeit-Logik
    // ═══════════════════════════════════════════

    private static function get_smart_time_hint() {
        $hour = intval(current_time('G')); // 0-23
        $dow  = intval(current_time('N')); // 1=Mo, 7=So

        if ($hour >= 6 && $hour < 14) {
            // Morgens/Mittags → "Heute" hervorheben (Heute Abend noch was vor?)
            return 'today';
        } elseif ($hour >= 14 && $hour < 20) {
            // Nachmittags → wenn Freitag "Wochenende", sonst "Heute"
            return ($dow == 5) ? 'weekend' : 'today';
        } else {
            // Abends/Nachts → "Morgen" hervorheben
            return 'tomorrow';
        }
    }

    // ═══════════════════════════════════════════
    // JSON-LD Structured Data
    // ═══════════════════════════════════════════

    private static function render_json_ld($events) {
        if (empty($events)) return '';
        $items = [];
        foreach ($events as $event) {
            $item = self::build_event_schema($event);
            if ($item) $items[] = $item;
        }

        $schema = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'numberOfItems'   => count($items),
            'itemListElement' => array_map(function($item, $i) {
                return [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'item'     => $item,
                ];
            }, $items, array_keys($items)),
        ];

        return '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    }

    /**
     * Baut ein erweitertes schema.org/Event Objekt für ein Event.
     * Inklusive: Offer(s), Place mit Address, Performer, Organizer, endDate, Bilder.
     * Öffentlich damit auch class-tix-single-event es nutzen kann.
     */
    public static function build_event_schema($event) {
        $id = $event->ID;
        $date_start = get_post_meta($id, '_tix_date_start', true);
        $date_end   = get_post_meta($id, '_tix_date_end', true);
        $time_start = get_post_meta($id, '_tix_time_start', true);
        $time_end   = get_post_meta($id, '_tix_time_end', true);
        $time_doors = get_post_meta($id, '_tix_time_doors', true);

        $location       = get_post_meta($id, '_tix_location', true);
        $address        = get_post_meta($id, '_tix_address', true);
        $location_id    = intval(get_post_meta($id, '_tix_location_id', true));
        $loc_city       = $location_id ? get_post_meta($location_id, '_tix_loc_city', true) : '';
        $loc_postcode   = $location_id ? get_post_meta($location_id, '_tix_loc_postcode', true) : '';
        $loc_country    = $location_id ? (get_post_meta($location_id, '_tix_loc_country', true) ?: 'DE') : 'DE';

        $price_min = floatval(get_post_meta($id, '_tix_price_min', true));
        $price_max = floatval(get_post_meta($id, '_tix_price_max', true));
        $thumb_url = get_the_post_thumbnail_url($id, 'large');
        $thumb_sq  = get_the_post_thumbnail_url($id, 'medium_large') ?: $thumb_url;

        $organizer_id   = intval(get_post_meta($id, '_tix_organizer_id', true));
        $organizer_name = $organizer_id ? get_the_title($organizer_id) : '';
        $organizer_url  = $organizer_id ? get_post_meta($organizer_id, '_tix_org_website', true) : '';

        $description = trim(wp_strip_all_tags(get_the_excerpt($id) ?: $event->post_content));
        if (mb_strlen($description) > 300) $description = mb_substr($description, 0, 297) . '…';

        // Start/End mit Zeit zu ISO-8601
        $start_iso = '';
        if ($date_start) {
            $start_iso = $date_start . ($time_start ? 'T' . substr($time_start, 0, 5) . ':00' : '');
        }
        $end_iso = '';
        if ($date_end) {
            $end_iso = $date_end . ($time_end ? 'T' . substr($time_end, 0, 5) . ':00' : '');
        } elseif ($date_start && $time_end) {
            // Nur Endzeit aber kein Enddatum → Startdatum als End-Tag nutzen
            $end_iso = $date_start . 'T' . substr($time_end, 0, 5) . ':00';
        }

        $item = [
            '@type'              => 'Event',
            'name'               => get_the_title($id),
            'url'                => get_permalink($id),
            'startDate'          => $start_iso,
            'eventStatus'        => 'https://schema.org/EventScheduled',
            'eventAttendanceMode'=> 'https://schema.org/OfflineEventAttendanceMode',
        ];
        if ($end_iso) $item['endDate'] = $end_iso;
        if ($time_doors) $item['doorTime'] = substr($time_doors, 0, 5);
        if ($description) $item['description'] = $description;

        // Bilder (multiple formats für Google)
        if ($thumb_url) {
            $images = array_unique(array_filter([$thumb_url, $thumb_sq]));
            $item['image'] = count($images) === 1 ? reset($images) : array_values($images);
        }

        // Location mit strukturierter Adresse
        if ($location) {
            $place = [
                '@type' => 'Place',
                'name'  => $location,
            ];
            if ($address || $loc_city || $loc_postcode) {
                $place['address'] = array_filter([
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $address,
                    'addressLocality' => $loc_city,
                    'postalCode'      => $loc_postcode,
                    'addressCountry'  => $loc_country,
                ]);
            }
            $item['location'] = $place;
        }

        // Offers — Preis-Range statt Einzelpreis
        if ($price_min > 0) {
            $offer = [
                '@type'         => 'Offer',
                'priceCurrency' => 'EUR',
                'availability'  => 'https://schema.org/InStock',
                'url'           => get_permalink($id),
                'validFrom'     => date('c', current_time('timestamp')),
            ];
            if ($price_max > 0 && $price_max !== $price_min) {
                $offer['lowPrice']  = number_format($price_min, 2, '.', '');
                $offer['highPrice'] = number_format($price_max, 2, '.', '');
                $offer['@type']     = 'AggregateOffer';
                $offer['offerCount'] = 1;
            } else {
                $offer['price'] = number_format($price_min, 2, '.', '');
            }
            $item['offers'] = $offer;
        } else {
            // Eintritt frei
            $min_val = floatval(get_post_meta($id, '_tix_price_min', true));
            if ($min_val === 0.0 && get_post_meta($id, '_tix_tickets_enabled', true)) {
                $item['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => '0',
                    'priceCurrency' => 'EUR',
                    'availability'  => 'https://schema.org/InStock',
                    'url'           => get_permalink($id),
                ];
            }
        }

        // Organizer
        if ($organizer_name) {
            $org = [
                '@type' => 'Organization',
                'name'  => $organizer_name,
            ];
            if ($organizer_url) $org['url'] = $organizer_url;
            $item['organizer'] = $org;
        }

        // Performer (wenn Line-Up gesetzt ist)
        $lineup = get_post_meta($id, '_tix_info_lineup', true);
        if ($lineup) {
            $performers = array_filter(array_map('trim', explode("\n", wp_strip_all_tags($lineup))));
            if (!empty($performers)) {
                $item['performer'] = array_map(function($p) {
                    return ['@type' => 'PerformingGroup', 'name' => $p];
                }, array_slice($performers, 0, 10));
            }
        }

        return $item;
    }

    // ═══════════════════════════════════════════
    // AJAX
    // ═══════════════════════════════════════════

    public static function ajax_filter() {
        $time     = sanitize_text_field($_POST['time'] ?? 'all');
        $category = sanitize_text_field($_POST['category'] ?? '');
        $limit    = intval($_POST['limit'] ?? 12);
        $view     = sanitize_text_field($_POST['view'] ?? 'grid');

        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $exclude_cats = array_filter(array_map('trim', explode(',', $s['hp_exclude_categories'] ?? '')));

        $events = self::query_grid_events($limit, [], 0, $time, $category, $exclude_cats);

        $show_heart  = !empty($s['tix_card_show_heart']);
        $show_badges = !empty($s['tix_card_show_badges']);
        $saved       = TIX_Event_Cards::get_saved_events_static();

        $html = '';
        if ($view === 'list') {
            foreach ($events as $event) {
                $html .= self::render_list_item($event, $show_heart, $saved);
            }
        } else {
            foreach ($events as $event) {
                $html .= TIX_Event_Cards::render_card($event, $show_heart, $show_badges, $saved);
            }
        }
        if (empty($events)) {
            $html = '<p class="tix-hp-empty">Keine Events gefunden.</p>';
        }

        wp_send_json_success(['html' => $html, 'found' => count($events)]);
    }

    /**
     * AJAX: Events für einen Monat als Kalender-HTML.
     */
    public static function ajax_calendar() {
        $year  = intval($_POST['year']  ?? date('Y'));
        $month = intval($_POST['month'] ?? date('n'));
        if ($month < 1) { $month = 12; $year--; }
        if ($month > 12) { $month = 1;  $year++; }

        $first_day = sprintf('%04d-%02d-01', $year, $month);
        $last_day  = date('Y-m-t', strtotime($first_day));

        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [[
                'relation' => 'AND',
                ['key' => '_tix_date_start', 'value' => $first_day, 'compare' => '>=', 'type' => 'DATE'],
                ['key' => '_tix_date_start', 'value' => $last_day,  'compare' => '<=', 'type' => 'DATE'],
            ]],
        ]);

        // Gruppieren nach Tag
        $by_day = [];
        foreach ($events as $ev) {
            $date = get_post_meta($ev->ID, '_tix_date_start', true);
            if (!$date) continue;
            $day = intval(date('j', strtotime($date)));
            $by_day[$day][] = $ev;
        }

        ob_start();
        $month_name = date_i18n('F Y', strtotime($first_day));
        $days_in_month = date('t', strtotime($first_day));
        $first_dow = date('N', strtotime($first_day)); // 1=Mo, 7=So
        $today_y = intval(current_time('Y'));
        $today_m = intval(current_time('n'));
        $today_d = intval(current_time('j'));
        ?>
        <div class="tix-hp-cal" data-year="<?php echo $year; ?>" data-month="<?php echo $month; ?>">
            <div class="tix-hp-cal-header">
                <button type="button" class="tix-hp-cal-nav" data-dir="-1" aria-label="Vorheriger Monat">‹</button>
                <div class="tix-hp-cal-title"><?php echo esc_html($month_name); ?></div>
                <button type="button" class="tix-hp-cal-nav" data-dir="1" aria-label="Nächster Monat">›</button>
            </div>
            <div class="tix-hp-cal-dows">
                <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $d): ?>
                    <div class="tix-hp-cal-dow"><?php echo $d; ?></div>
                <?php endforeach; ?>
            </div>
            <div class="tix-hp-cal-grid">
                <?php for ($i = 1; $i < $first_dow; $i++): ?>
                    <div class="tix-hp-cal-cell tix-hp-cal-empty"></div>
                <?php endfor; ?>
                <?php for ($d = 1; $d <= $days_in_month; $d++):
                    $day_events = $by_day[$d] ?? [];
                    $is_today = ($year === $today_y && $month === $today_m && $d === $today_d);
                    $has = !empty($day_events);
                ?>
                    <div class="tix-hp-cal-cell<?php echo $is_today ? ' is-today' : ''; ?><?php echo $has ? ' has-events' : ''; ?>" data-day="<?php echo $d; ?>">
                        <div class="tix-hp-cal-num"><?php echo $d; ?></div>
                        <?php if ($has): ?>
                        <div class="tix-hp-cal-dots">
                            <?php foreach (array_slice($day_events, 0, 3) as $ev):
                                $terms = wp_get_post_terms($ev->ID, 'event_category', ['fields' => 'slugs']);
                                $cat_class = !is_wp_error($terms) && !empty($terms) ? 'tix-hp-cal-dot-' . sanitize_html_class($terms[0]) : '';
                            ?>
                                <span class="tix-hp-cal-dot <?php echo $cat_class; ?>" title="<?php echo esc_attr(get_the_title($ev->ID)); ?>"></span>
                            <?php endforeach; ?>
                            <?php if (count($day_events) > 3): ?>
                                <span class="tix-hp-cal-more">+<?php echo count($day_events) - 3; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="tix-hp-cal-day-events" style="display:none;"></div>
        </div>

        <?php
        // Event-Details pro Tag als JSON mitsenden
        $day_data = [];
        foreach ($by_day as $day => $day_events) {
            $day_data[$day] = [];
            foreach ($day_events as $ev) {
                $time = get_post_meta($ev->ID, '_tix_time_start', true);
                $loc  = get_post_meta($ev->ID, '_tix_location_short', true);
                $price = get_post_meta($ev->ID, '_tix_price_card', true);
                $thumb = get_the_post_thumbnail_url($ev->ID, 'thumbnail');
                $day_data[$day][] = [
                    'id'    => $ev->ID,
                    'title' => get_the_title($ev->ID),
                    'time'  => $time ? substr($time, 0, 5) : '',
                    'loc'   => $loc,
                    'price' => $price,
                    'url'   => get_permalink($ev->ID),
                    'thumb' => $thumb,
                ];
            }
        }
        $html = ob_get_clean();
        wp_send_json_success(['html' => $html, 'days' => $day_data, 'month_label' => $month_name]);
    }

    /**
     * AJAX: Events in der Nähe finden.
     * Primär: Haversine-Distanz wenn Location lat/lng hat.
     * Fallback: Stadt-Match via Nominatim Reverse-Geocoding.
     */
    public static function ajax_near_me() {
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        $max_km = max(5, min(500, intval($_POST['max_km'] ?? 50)));
        $limit  = max(3, min(20, intval($_POST['limit'] ?? 8)));

        if (!$lat || !$lng) {
            wp_send_json_error(['message' => 'Keine Koordinaten erhalten.']);
        }

        // 1) Alle publizierten zukünftigen Events sammeln
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [[
                'key'     => '_tix_date_start',
                'value'   => current_time('Y-m-d'),
                'compare' => '>=',
            ]],
        ]);
        if (empty($events)) {
            wp_send_json_success(['html' => '', 'count' => 0]);
        }

        // 2) Ermittelt die Stadt des Users (für City-Fallback)
        $user_city = self::reverse_geocode_city($lat, $lng);

        // 3) Für jedes Event Distanz berechnen (falls lat/lng am Location-CPT verfügbar)
        //    oder City-Match als Fallback (0.0-km „Nähe")
        $scored = [];
        foreach ($events as $ev) {
            $loc_id = intval(get_post_meta($ev->ID, '_tix_location_id', true));
            if (!$loc_id) continue;
            $loc_lat = floatval(get_post_meta($loc_id, '_tix_loc_lat', true));
            $loc_lng = floatval(get_post_meta($loc_id, '_tix_loc_lng', true));
            $loc_city = strtolower(trim(get_post_meta($loc_id, '_tix_loc_city', true)));

            $distance = null;
            if ($loc_lat && $loc_lng) {
                $distance = self::haversine_km($lat, $lng, $loc_lat, $loc_lng);
                if ($distance > $max_km) continue;
            } elseif ($user_city && $loc_city && strtolower($user_city) === $loc_city) {
                $distance = 0.5; // pseudo-Nähe für Stadt-Match
            } else {
                continue;
            }
            $scored[] = ['event' => $ev, 'distance' => $distance];
        }

        if (empty($scored)) {
            wp_send_json_success([
                'html'  => '<p class="tix-hp-empty" style="grid-column:1/-1;text-align:center;padding:24px;color:#94a3b8;">Keine Events im Umkreis von ' . $max_km . ' km gefunden.</p>',
                'count' => 0,
                'city'  => $user_city,
            ]);
        }

        // Sortieren nach Distanz
        usort($scored, function($a, $b){ return $a['distance'] <=> $b['distance']; });
        $scored = array_slice($scored, 0, $limit);

        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $show_heart  = !empty($s['tix_card_show_heart']);
        $show_badges = !empty($s['tix_card_show_badges']);
        $saved       = TIX_Event_Cards::get_saved_events_static();

        $html = '';
        foreach ($scored as $item) {
            $html .= TIX_Event_Cards::render_card($item['event'], $show_heart, $show_badges, $saved);
        }

        wp_send_json_success([
            'html'  => $html,
            'count' => count($scored),
            'city'  => $user_city,
        ]);
    }

    /**
     * Haversine-Distanzformel (km) zwischen zwei GPS-Punkten.
     */
    private static function haversine_km($lat1, $lng1, $lat2, $lng2) {
        $r = 6371; // Erdradius in km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $r * $c;
    }

    /**
     * Reverse-Geocoding via Nominatim (cached per 24h).
     */
    private static function reverse_geocode_city($lat, $lng) {
        $key = 'tix_geo_' . md5(round($lat, 3) . '_' . round($lng, 3));
        $cached = get_transient($key);
        if ($cached !== false) return $cached;

        $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' . urlencode($lat) . '&lon=' . urlencode($lng) . '&accept-language=de';
        $resp = wp_remote_get($url, [
            'timeout'    => 5,
            'user-agent' => 'Tixomat/' . TIXOMAT_VERSION . ' (WordPress Plugin)',
        ]);
        if (is_wp_error($resp)) { set_transient($key, '', 300); return ''; }

        $body = json_decode(wp_remote_retrieve_body($resp), true);
        $city = '';
        if (is_array($body) && !empty($body['address'])) {
            $a = $body['address'];
            $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? '';
        }
        set_transient($key, $city, 86400);
        return $city;
    }

    /**
     * AJAX: Event-Karten per ID-Liste rendern.
     * Wird vom Frontend genutzt für „Kürzlich angesehen" und „Favoriten" (LocalStorage).
     */
    public static function ajax_cards_by_ids() {
        $ids_raw = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
        $ids = array_values(array_filter(array_map('intval', $ids_raw)));
        $ids = array_slice($ids, 0, 12);
        if (empty($ids)) {
            wp_send_json_success(['html' => '', 'count' => 0]);
        }

        $only_upcoming = !empty($_POST['only_upcoming']);
        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'post__in'       => $ids,
            'posts_per_page' => count($ids),
            'orderby'        => 'post__in',
        ];
        if ($only_upcoming) {
            $args['meta_query'] = [[
                'key'     => '_tix_date_start',
                'value'   => current_time('Y-m-d'),
                'compare' => '>=',
                'type'    => 'DATE',
            ]];
        }
        $events = get_posts($args);
        if (empty($events)) {
            wp_send_json_success(['html' => '', 'count' => 0]);
        }

        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $show_heart  = !empty($s['tix_card_show_heart']);
        $show_badges = !empty($s['tix_card_show_badges']);
        $saved       = TIX_Event_Cards::get_saved_events_static();

        $html = '';
        foreach ($events as $ev) {
            $html .= TIX_Event_Cards::render_card($ev, $show_heart, $show_badges, $saved);
        }
        wp_send_json_success(['html' => $html, 'count' => count($events)]);
    }

    public static function ajax_load_more() {
        $offset   = intval($_POST['offset'] ?? 0);
        $limit    = intval($_POST['limit'] ?? 8);
        $time     = sanitize_text_field($_POST['time'] ?? 'all');
        $category = sanitize_text_field($_POST['category'] ?? '');
        $view     = sanitize_text_field($_POST['view'] ?? 'grid');
        $exclude  = array_filter(array_map('intval', explode(',', $_POST['exclude'] ?? '')));
        $exclude_cats = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['exclude_cats'] ?? ''))));

        $events = self::query_grid_events($limit, $exclude, $offset, $time, $category, $exclude_cats);

        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $show_heart  = !empty($s['tix_card_show_heart']);
        $show_badges = !empty($s['tix_card_show_badges']);
        $saved       = TIX_Event_Cards::get_saved_events_static();

        $html = '';
        if ($view === 'list') {
            foreach ($events as $event) {
                $html .= self::render_list_item($event, $show_heart, $saved);
            }
        } else {
            foreach ($events as $event) {
                $html .= TIX_Event_Cards::render_card($event, $show_heart, $show_badges, $saved);
            }
        }

        wp_send_json_success(['html' => $html, 'found' => count($events), 'hasMore' => count($events) >= $limit]);
    }

    // ═══════════════════════════════════════════
    // Phase-3: Smart-Greeting
    // ═══════════════════════════════════════════

    private static function render_greeting() {
        list($title, $subtitle) = self::get_smart_greeting();
        ob_start();
        ?>
        <div class="tix-hp-greeting" style="padding:8px 0 16px;">
            <h1 style="font-size:clamp(24px,3.5vw,38px);margin:0 0 6px;line-height:1.15;"><?php echo esc_html($title); ?></h1>
            <p style="font-size:clamp(14px,1.5vw,17px);margin:0;opacity:0.7;"><?php echo esc_html($subtitle); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-3: Kürzlich angesehen (LocalStorage-driven)
    // ═══════════════════════════════════════════

    private static function render_recent() {
        ob_start();
        ?>
        <div class="tix-hp-section tix-hp-recent" data-tix-hp-lazy="recent" style="display:none;">
            <div class="tix-hp-section-header">
                <div>
                    <div class="tix-hp-section-label">Für dich</div>
                    <h2 class="tix-hp-section-title">Kürzlich angesehen</h2>
                </div>
                <button type="button" class="tix-hp-recent-clear" style="background:none;border:0;color:#94a3b8;font-size:12px;cursor:pointer;text-decoration:underline;">Verlauf löschen</button>
            </div>
            <div class="ev-grid tix-hp-recent-grid" data-columns="4"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-3: Favoriten/Wishlist (LocalStorage + User-Server-Sync)
    // ═══════════════════════════════════════════

    private static function render_favorites() {
        ob_start();
        ?>
        <div class="tix-hp-section tix-hp-favorites" data-tix-hp-lazy="favorites" style="display:none;">
            <div class="tix-hp-section-header">
                <div>
                    <div class="tix-hp-section-label">❤️ Wishlist</div>
                    <h2 class="tix-hp-section-title">Deine Favoriten</h2>
                </div>
            </div>
            <div class="ev-grid tix-hp-favorites-grid" data-columns="4"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-3: Story-Carousel (Insta-Style runde Cards)
    // ═══════════════════════════════════════════

    private static function render_stories($exclude_cats = []) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $count = max(5, min(20, intval($s['hp_stories_count'] ?? 12)));
        // Größe des Avatars (äußerer Durchmesser inklusive Gradient-Rand)
        $size = max(50, min(160, intval($s['hp_stories_size'] ?? 72)));
        // Ableitungen
        $inner  = $size - 6;                                    // innerer Durchmesser (Padding 3px)
        $label_w = max(60, $size + 8);                          // Label-Breite
        $font_size = max(10, min(14, round($size / 6)));        // Label-Font-Size

        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [[
                'key'     => '_tix_date_start',
                'value'   => current_time('Y-m-d'),
                'compare' => '>=',
            ]],
            'tax_query'      => !empty($exclude_cats) ? [[
                'taxonomy' => 'event_category',
                'field'    => 'slug',
                'terms'    => $exclude_cats,
                'operator' => 'NOT IN',
            ]] : [],
        ]);
        if (empty($events)) return '';

        ob_start();
        ?>
        <div class="tix-hp-stories" style="margin-bottom:4px;text-align:center;">
            <div class="tix-hp-stories-scroll" style="display:inline-flex;gap:14px;overflow-x:auto;max-width:100%;padding:4px 2px 12px;scrollbar-width:thin;text-align:left;vertical-align:top;">
                <?php foreach ($events as $ev):
                    $thumb = get_the_post_thumbnail_url($ev->ID, 'medium') ?: get_the_post_thumbnail_url($ev->ID, 'thumbnail');
                    $title = get_the_title($ev->ID);
                    $link  = get_permalink($ev->ID);
                ?>
                <a href="<?php echo esc_url($link); ?>" class="tix-hp-story" style="flex-shrink:0;text-align:center;text-decoration:none;color:inherit;width:<?php echo $label_w; ?>px;" title="<?php echo esc_attr($title); ?>">
                    <div style="width:<?php echo $size; ?>px;height:<?php echo $size; ?>px;border-radius:50%;padding:3px;background:linear-gradient(135deg,#FF5500,#8B00FF);margin:0 auto 6px;overflow:hidden;">
                        <div style="width:100%;height:100%;border-radius:50%;background:#fff url('<?php echo esc_url($thumb); ?>') center/cover no-repeat;border:2px solid #fff;"></div>
                    </div>
                    <div style="font-size:<?php echo $font_size; ?>px;font-weight:600;line-height:1.2;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;"><?php echo esc_html(mb_strimwidth($title, 0, 30, '…')); ?></div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .tix-hp-stories-scroll::-webkit-scrollbar { height: 6px; }
            .tix-hp-stories-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 3px; }
            .tix-hp-story:hover > div:first-child { transform: scale(1.05); transition: transform .2s; }
        </style>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-3: Empfehlung der Redaktion
    // ═══════════════════════════════════════════

    private static function render_editorial() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $event_id = intval($s['hp_editorial_event_id'] ?? 0);
        if (!$event_id) return '';

        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'event' || $event->post_status !== 'publish') return '';

        $label   = sanitize_text_field($s['hp_editorial_label'] ?? 'Empfehlung der Redaktion');
        $title_o = sanitize_text_field($s['hp_editorial_title_override'] ?? '');
        $text    = wp_kses_post($s['hp_editorial_text'] ?? '');
        $byline  = sanitize_text_field($s['hp_editorial_byline'] ?? '');
        $cta     = sanitize_text_field($s['hp_editorial_cta'] ?? 'Event ansehen');

        $title       = $title_o ?: get_the_title($event_id);
        $permalink   = get_permalink($event_id);
        $thumb_url   = get_the_post_thumbnail_url($event_id, 'large');
        $date_card   = get_post_meta($event_id, '_tix_date_card', true);
        $location    = get_post_meta($event_id, '_tix_location_short', true);
        $price_card  = get_post_meta($event_id, '_tix_price_card', true);

        ob_start();
        ?>
        <div class="tix-hp-editorial">
            <div class="tix-hp-editorial-inner">
                <a href="<?php echo esc_url($permalink); ?>" class="tix-hp-editorial-img"<?php if ($thumb_url): ?> style="background-image:url('<?php echo esc_url($thumb_url); ?>');"<?php endif; ?> aria-label="<?php echo esc_attr($title); ?>">
                    <div class="tix-hp-editorial-img-overlay"></div>
                    <?php if ($date_card): ?>
                        <span class="tix-hp-editorial-date-pill"><?php echo esc_html($date_card); ?></span>
                    <?php endif; ?>
                </a>
                <div class="tix-hp-editorial-content">
                    <div class="tix-hp-editorial-label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" style="margin-right:4px;vertical-align:-1px;"><path d="M12 2L14.5 8.5 21 9.3l-5 4.7 1.5 7L12 17.8 6.5 21 8 14l-5-4.7 6.5-.8L12 2z"/></svg>
                        <?php echo esc_html($label); ?>
                    </div>
                    <h2 class="tix-hp-editorial-title">
                        <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($title); ?></a>
                    </h2>
                    <?php if ($text): ?>
                        <div class="tix-hp-editorial-text"><?php echo wp_kses_post(wpautop($text)); ?></div>
                    <?php endif; ?>
                    <div class="tix-hp-editorial-meta">
                        <?php if ($location): ?>
                            <span>📍 <?php echo esc_html($location); ?></span>
                        <?php endif; ?>
                        <?php if ($price_card): ?>
                            <span class="tix-hp-editorial-price"><?php echo esc_html($price_card); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="tix-hp-editorial-foot">
                        <a href="<?php echo esc_url($permalink); ?>" class="tix-hp-editorial-cta"><?php echo esc_html($cta); ?> →</a>
                        <?php if ($byline): ?>
                            <span class="tix-hp-editorial-byline">— <?php echo esc_html($byline); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .tix-hp-editorial { border-radius: 16px; overflow: hidden; background: var(--tix-card-sand, #F8F5EF); }
            .tix-hp-editorial-inner { display: grid; grid-template-columns: 1fr 1fr; min-height: 320px; }
            .tix-hp-editorial-img { position: relative; background-size: cover; background-position: center; background-color: var(--tix-card-sand, #E3DED4); min-height: 260px; text-decoration: none; }
            .tix-hp-editorial-img-overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,0.35) 0%, rgba(0,0,0,0.05) 50%, transparent 100%); }
            .tix-hp-editorial-date-pill { position: absolute; bottom: 16px; left: 16px; padding: 5px 12px; background: var(--tix-card-signal, #E8445A); color: #fff; border-radius: 6px; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; z-index: 2; }
            .tix-hp-editorial-content { padding: 32px 36px; display: flex; flex-direction: column; justify-content: center; }
            .tix-hp-editorial-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.12em; font-weight: 700; color: var(--tix-card-signal, #E8445A); margin-bottom: 10px; display: flex; align-items: center; }
            .tix-hp-editorial-title { font-family: var(--tix-card-font-d, var(--tix-font-heading, 'Sora')), sans-serif; font-size: clamp(22px, 2.4vw, 30px); font-weight: 800; line-height: 1.18; margin: 0 0 14px; }
            .tix-hp-editorial-title a { color: inherit; text-decoration: none; }
            .tix-hp-editorial-title a:hover { color: var(--tix-card-signal, #E8445A); }
            .tix-hp-editorial-text { font-size: 0.95rem; line-height: 1.65; color: var(--tix-card-text-muted, #3A3937); margin-bottom: 18px; }
            .tix-hp-editorial-text p:last-child { margin-bottom: 0; }
            .tix-hp-editorial-meta { display: flex; gap: 16px; font-size: 0.88rem; margin-bottom: 18px; color: var(--tix-card-text-muted, #3A3937); flex-wrap: wrap; }
            .tix-hp-editorial-price { font-weight: 700; color: inherit; }
            .tix-hp-editorial-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
            .tix-hp-editorial-cta { display: inline-block; padding: 11px 22px; background: var(--tix-card-signal, #E8445A); color: #fff !important; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.92rem; transition: transform .2s, background .2s; }
            .tix-hp-editorial-cta:hover { transform: translateY(-1px); background: var(--tix-card-signal-dark, #C9324A); }
            .tix-hp-editorial-byline { font-size: 0.82rem; font-style: italic; color: var(--tix-card-text-muted, #8C8985); }
            @media (max-width: 720px) {
                .tix-hp-editorial-inner { grid-template-columns: 1fr; }
                .tix-hp-editorial-img { min-height: 220px; }
                .tix-hp-editorial-content { padding: 24px 22px; }
            }
            .tix-hp-dark .tix-hp-editorial { background: #221E3F; }
            .tix-hp-dark .tix-hp-editorial-text,
            .tix-hp-dark .tix-hp-editorial-meta { color: #D8D4D0; }
            .tix-hp-dark .tix-hp-editorial-title { color: #fff; }
        </style>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-3: Promoted Events (bezahltes Placement)
    // ═══════════════════════════════════════════

    private static function render_promoted($show_heart = false, $show_badges = false, $saved = [], $exclude_cats = []) {
        $now = current_time('Y-m-d');
        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'meta_key'       => '_tix_promoted_priority',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_promoted', 'value' => '1'],
                ['key' => '_tix_date_start', 'value' => $now, 'compare' => '>=', 'type' => 'DATE'],
                [
                    'relation' => 'OR',
                    ['key' => '_tix_promoted_until', 'compare' => 'NOT EXISTS'],
                    ['key' => '_tix_promoted_until', 'value' => $now, 'compare' => '>=', 'type' => 'DATE'],
                ],
            ],
        ];
        if (!empty($exclude_cats)) {
            $args['tax_query'] = [[
                'taxonomy' => 'event_category',
                'field'    => 'slug',
                'terms'    => $exclude_cats,
                'operator' => 'NOT IN',
            ]];
        }
        $events = get_posts($args);
        if (empty($events)) return '';

        ob_start();
        ?>
        <div class="tix-hp-section tix-hp-promoted">
            <div class="tix-hp-section-header">
                <div>
                    <div class="tix-hp-section-label" style="color:#FF5500;">✨ Highlights</div>
                    <h2 class="tix-hp-section-title">Empfohlene Events</h2>
                </div>
                <span style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;">Anzeige</span>
            </div>
            <div class="ev-grid" data-columns="<?php echo min(count($events), 3); ?>">
                <?php foreach ($events as $event): ?>
                    <?php echo TIX_Event_Cards::render_card($event, $show_heart, $show_badges, $saved); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-2: Hero-Countdown
    // ═══════════════════════════════════════════

    private static function render_hero_countdown($exclude_cats = []) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $force_event = intval($s['hp_countdown_event_id'] ?? 0);

        $event = null;
        if ($force_event) {
            $p = get_post($force_event);
            if ($p && $p->post_type === 'event' && $p->post_status === 'publish') $event = $p;
        }
        if (!$event) {
            // Nächstes zukünftiges Event (nicht featured-only)
            $events = self::query_hero_events(1, false, $exclude_cats);
            $event = !empty($events) ? $events[0] : null;
        }
        if (!$event) return '';

        $date_start = get_post_meta($event->ID, '_tix_date_start', true);
        $time_start = get_post_meta($event->ID, '_tix_time_start', true);
        if (!$date_start) return '';

        $ts = strtotime(trim($date_start . ' ' . ($time_start ?: '20:00')));
        if (!$ts || $ts <= time()) return '';

        $image_url = get_the_post_thumbnail_url($event->ID, 'large');
        $location  = get_post_meta($event->ID, '_tix_location', true);
        $link      = get_permalink($event->ID);
        $title     = get_the_title($event->ID);
        $label     = sanitize_text_field($s['hp_countdown_label'] ?? 'Next Event');

        ob_start();
        ?>
        <div class="tix-hp-countdown" data-target="<?php echo esc_attr($ts); ?>" style="position:relative;border-radius:16px;overflow:hidden;margin-bottom:32px;min-height:240px;background:#111;color:#fff;">
            <?php if ($image_url): ?>
                <div style="position:absolute;inset:0;background:url('<?php echo esc_url($image_url); ?>') center/cover no-repeat;opacity:0.45;"></div>
                <div style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,0.85) 0%,rgba(0,0,0,0.35) 100%);"></div>
            <?php endif; ?>
            <div style="position:relative;padding:32px 28px;display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center;min-height:240px;">
                <div>
                    <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;opacity:0.7;margin-bottom:8px;"><?php echo esc_html($label); ?></div>
                    <h2 style="font-size:clamp(22px,3vw,36px);margin:0 0 8px;line-height:1.1;"><?php echo esc_html($title); ?></h2>
                    <?php if ($location): ?>
                        <div style="opacity:0.8;font-size:14px;margin-bottom:16px;">📍 <?php echo esc_html($location); ?></div>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($link); ?>" style="display:inline-block;padding:10px 22px;background:#fff;color:#111;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">Jetzt sichern →</a>
                </div>
                <div class="tix-hp-countdown-clock" style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
                    <?php foreach (['days'=>'T','hours'=>'Std','minutes'=>'Min','seconds'=>'Sek'] as $k=>$lbl): ?>
                        <div style="background:rgba(255,255,255,0.12);backdrop-filter:blur(8px);padding:10px 14px;border-radius:10px;text-align:center;min-width:64px;">
                            <div class="tix-cd-<?php echo $k; ?>" style="font-size:24px;font-weight:800;line-height:1;">00</div>
                            <div style="font-size:10px;letter-spacing:1px;opacity:0.7;margin-top:2px;"><?php echo $lbl; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var el = document.currentScript.previousElementSibling;
            var target = parseInt(el.getAttribute('data-target'), 10) * 1000;
            function tick(){
                var diff = target - Date.now();
                if (diff <= 0) { el.style.display = 'none'; return; }
                var d = Math.floor(diff/86400000);
                var h = Math.floor(diff%86400000/3600000);
                var m = Math.floor(diff%3600000/60000);
                var s = Math.floor(diff%60000/1000);
                function pad(n){ return n < 10 ? '0'+n : n; }
                el.querySelector('.tix-cd-days').textContent = pad(d);
                el.querySelector('.tix-cd-hours').textContent = pad(h);
                el.querySelector('.tix-cd-minutes').textContent = pad(m);
                el.querySelector('.tix-cd-seconds').textContent = pad(s);
            }
            tick();
            setInterval(tick, 1000);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-2: Letzte Chance (FOMO-Karussell)
    // ═══════════════════════════════════════════

    private static function render_last_chance($show_heart = false, $show_badges = false, $saved = [], $exclude_cats = []) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $hours_threshold = max(6, min(168, intval($s['hp_last_chance_hours'] ?? 48)));
        $max_items       = max(2, min(8, intval($s['hp_last_chance_count'] ?? 4)));

        $events = self::query_last_chance_events($hours_threshold, $max_items, $exclude_cats);
        if (empty($events)) return '';

        ob_start();
        ?>
        <div class="tix-hp-section tix-hp-last-chance">
            <div class="tix-hp-section-header">
                <div>
                    <div class="tix-hp-section-label" style="color:#e11d48;">⚠️ Last Chance</div>
                    <h2 class="tix-hp-section-title">Jetzt oder nie</h2>
                </div>
            </div>
            <div class="ev-grid" data-columns="<?php echo min(count($events), 4); ?>">
                <?php foreach ($events as $event): ?>
                    <?php echo TIX_Event_Cards::render_card($event, $show_heart, $show_badges, $saved); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Findet Events deren Beginn innerhalb $hours liegt.
     */
    private static function query_last_chance_events($hours, $limit, $exclude_cats = []) {
        $now   = current_time('Y-m-d H:i:s');
        $until = date('Y-m-d H:i:s', strtotime("+{$hours} hours", strtotime($now)));

        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_date_start', 'value' => date('Y-m-d', strtotime($now)),   'compare' => '>='],
                ['key' => '_tix_date_start', 'value' => date('Y-m-d', strtotime($until)), 'compare' => '<='],
            ],
        ];

        if (!empty($exclude_cats)) {
            $args['tax_query'] = [[
                'taxonomy' => 'event_category',
                'field'    => 'slug',
                'terms'    => $exclude_cats,
                'operator' => 'NOT IN',
            ]];
        }

        return get_posts($args);
    }

    // ═══════════════════════════════════════════
    // Phase-2: Wochentag-Grid (Mo-So Spalten)
    // ═══════════════════════════════════════════

    private static function render_weekday_grid($exclude_cats = []) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $weeks_ahead = max(1, min(4, intval($s['hp_weekday_weeks'] ?? 2)));

        // Events der nächsten X Wochen
        $now_ts = current_time('timestamp');
        $end_ts = strtotime("+{$weeks_ahead} weeks", $now_ts);

        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_date_start', 'value' => date('Y-m-d', $now_ts), 'compare' => '>='],
                ['key' => '_tix_date_start', 'value' => date('Y-m-d', $end_ts), 'compare' => '<='],
            ],
        ];
        if (!empty($exclude_cats)) {
            $args['tax_query'] = [[
                'taxonomy' => 'event_category',
                'field'    => 'slug',
                'terms'    => $exclude_cats,
                'operator' => 'NOT IN',
            ]];
        }
        $events = get_posts($args);
        if (empty($events)) return '';

        // Gruppieren nach Wochentag (0=So, 1=Mo, ..., 6=Sa)
        $by_day = [1=>[], 2=>[], 3=>[], 4=>[], 5=>[], 6=>[], 0=>[]];
        foreach ($events as $ev) {
            $date = get_post_meta($ev->ID, '_tix_date_start', true);
            if (!$date) continue;
            $dow = intval(date('w', strtotime($date)));
            $by_day[$dow][] = $ev;
        }

        $day_labels = [1=>'Montag', 2=>'Dienstag', 3=>'Mittwoch', 4=>'Donnerstag', 5=>'Freitag', 6=>'Samstag', 0=>'Sonntag'];
        $day_short  = [1=>'Mo', 2=>'Di', 3=>'Mi', 4=>'Do', 5=>'Fr', 6=>'Sa', 0=>'So'];

        ob_start();
        ?>
        <div class="tix-hp-section">
            <div class="tix-hp-section-header">
                <div>
                    <div class="tix-hp-section-label">Wochenplan</div>
                    <h2 class="tix-hp-section-title">Nach Wochentag</h2>
                </div>
            </div>
            <div class="tix-hp-weekday-grid" style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:12px;overflow-x:auto;">
                <?php foreach ($by_day as $dow => $day_events): ?>
                    <div class="tix-hp-weekday-col" style="background:#f8fafc;border-radius:12px;padding:14px 10px;min-width:140px;">
                        <div style="font-weight:700;font-size:13px;margin-bottom:10px;text-align:center;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
                            <span style="display:block;font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;"><?php echo $day_short[$dow]; ?></span>
                            <?php echo esc_html($day_labels[$dow]); ?>
                        </div>
                        <?php if (empty($day_events)): ?>
                            <div style="font-size:12px;color:#cbd5e1;text-align:center;padding:20px 0;">–</div>
                        <?php else: ?>
                            <?php foreach ($day_events as $ev): ?>
                                <?php
                                $date  = get_post_meta($ev->ID, '_tix_date_start', true);
                                $time  = get_post_meta($ev->ID, '_tix_time_start', true);
                                $date_display = $date ? date_i18n('d.m.', strtotime($date)) : '';
                                ?>
                                <a href="<?php echo esc_url(get_permalink($ev->ID)); ?>" style="display:block;background:#fff;border-radius:8px;padding:8px 10px;margin-bottom:8px;text-decoration:none;color:inherit;border:1px solid transparent;transition:border-color .2s;" onmouseover="this.style.borderColor='#FF5500'" onmouseout="this.style.borderColor='transparent'">
                                    <div style="font-size:10px;color:#64748b;margin-bottom:2px;"><?php echo $date_display; ?><?php echo $time ? ' · ' . esc_html(substr($time, 0, 5)) : ''; ?></div>
                                    <div style="font-size:12px;font-weight:600;line-height:1.3;"><?php echo esc_html(mb_strimwidth(get_the_title($ev->ID), 0, 36, '…')); ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            @media (max-width:900px) {
                .tix-hp-weekday-grid { grid-template-columns:repeat(3,minmax(160px,1fr)) !important; }
            }
            @media (max-width:560px) {
                .tix-hp-weekday-grid { grid-template-columns:repeat(2,minmax(160px,1fr)) !important; }
            }
        </style>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-2: Geschenkgutschein-Banner
    // ═══════════════════════════════════════════

    private static function render_voucher_banner() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $title     = sanitize_text_field($s['hp_voucher_title'] ?? 'Verschenke ein Erlebnis');
        $text      = sanitize_text_field($s['hp_voucher_text'] ?? 'Gutscheine für jedes Event — der perfekte Geschenktipp.');
        $btn_label = sanitize_text_field($s['hp_voucher_btn'] ?? 'Gutschein kaufen');
        $url       = esc_url_raw($s['hp_voucher_url'] ?? '');
        $image_id  = intval($s['hp_voucher_image_id'] ?? 0);
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';
        $bg_color  = sanitize_text_field($s['hp_voucher_bg'] ?? '#FFE066');
        $fg_color  = sanitize_text_field($s['hp_voucher_fg'] ?? '#0D0B09');

        if (!$title && !$text) return '';

        ob_start();
        ?>
        <div class="tix-hp-voucher" style="border-radius:16px;overflow:hidden;margin-bottom:32px;background:<?php echo esc_attr($bg_color); ?>;color:<?php echo esc_attr($fg_color); ?>;display:grid;grid-template-columns:<?php echo $image_url ? '1fr 1fr' : '1fr'; ?>;gap:0;min-height:200px;">
            <div style="padding:32px 28px;display:flex;flex-direction:column;justify-content:center;">
                <div style="font-size:28px;margin-bottom:8px;">🎁</div>
                <?php if ($title): ?><h3 style="font-size:clamp(20px,2.5vw,28px);margin:0 0 10px;line-height:1.15;"><?php echo esc_html($title); ?></h3><?php endif; ?>
                <?php if ($text): ?><p style="font-size:15px;margin:0 0 18px;opacity:0.85;max-width:460px;"><?php echo esc_html($text); ?></p><?php endif; ?>
                <?php if ($url && $btn_label): ?>
                    <a href="<?php echo esc_url($url); ?>" style="display:inline-block;align-self:flex-start;padding:11px 24px;background:<?php echo esc_attr($fg_color); ?>;color:<?php echo esc_attr($bg_color); ?>;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;"><?php echo esc_html($btn_label); ?></a>
                <?php endif; ?>
            </div>
            <?php if ($image_url): ?>
                <div style="background:url('<?php echo esc_url($image_url); ?>') center/cover no-repeat;min-height:180px;"></div>
            <?php endif; ?>
        </div>
        <style>@media (max-width:700px){.tix-hp-voucher{grid-template-columns:1fr !important}}</style>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-2: Partner/Presse-Logo-Strip
    // ═══════════════════════════════════════════

    private static function render_partners() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $title = sanitize_text_field($s['hp_partners_title'] ?? 'Bekannt aus & Partner');
        $logos = isset($s['hp_partners_logos']) && is_array($s['hp_partners_logos']) ? $s['hp_partners_logos'] : [];
        $logos = array_values(array_filter($logos, function($l){ return !empty($l['image_id']); }));
        if (empty($logos)) return '';

        ob_start();
        ?>
        <div class="tix-hp-partners" style="margin:24px 0 32px;padding:22px 24px;border-radius:14px;background:#f8fafc;">
            <?php if ($title): ?><div style="text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:2px;color:#64748b;margin-bottom:16px;"><?php echo esc_html($title); ?></div><?php endif; ?>
            <div style="display:flex;flex-wrap:wrap;justify-content:center;align-items:center;gap:28px;">
                <?php foreach ($logos as $logo):
                    $img_id = intval($logo['image_id']);
                    $url    = wp_get_attachment_image_url($img_id, 'medium');
                    $link   = esc_url_raw($logo['link'] ?? '');
                    $alt    = sanitize_text_field($logo['alt'] ?? '');
                    if (!$url) continue;
                    $img_html = '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" style="max-height:50px;width:auto;filter:grayscale(1);opacity:0.65;transition:filter .2s,opacity .2s;" onmouseover="this.style.filter=\'none\';this.style.opacity=\'1\'" onmouseout="this.style.filter=\'grayscale(1)\';this.style.opacity=\'0.65\'">';
                ?>
                    <?php if ($link): ?>
                        <a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener" style="display:inline-block;"><?php echo $img_html; ?></a>
                    <?php else: ?>
                        <?php echo $img_html; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Phase-2: FAQ-Accordion
    // ═══════════════════════════════════════════

    private static function render_faq_accordion() {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $title = sanitize_text_field($s['hp_faq_title'] ?? 'Häufige Fragen');
        $items = isset($s['hp_faq_items']) && is_array($s['hp_faq_items']) ? $s['hp_faq_items'] : [];
        $items = array_values(array_filter($items, function($f){ return !empty($f['question']) && !empty($f['answer']); }));
        if (empty($items)) return '';

        ob_start();
        ?>
        <div class="tix-hp-faq" style="margin:32px auto;max-width:820px;">
            <?php if ($title): ?><h2 style="font-size:clamp(22px,2.5vw,28px);margin:0 0 18px;text-align:center;"><?php echo esc_html($title); ?></h2><?php endif; ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($items as $idx => $faq): ?>
                    <details style="background:#f8fafc;border-radius:10px;padding:14px 18px;cursor:pointer;">
                        <summary style="font-weight:600;font-size:15px;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:12px;">
                            <span><?php echo esc_html($faq['question']); ?></span>
                            <span style="color:#94a3b8;font-weight:400;transition:transform .2s;">+</span>
                        </summary>
                        <div style="margin-top:10px;font-size:14px;color:#475569;line-height:1.6;"><?php echo wp_kses_post(wpautop($faq['answer'])); ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .tix-hp-faq details[open] summary span:last-child { transform:rotate(45deg); }
            .tix-hp-faq summary::-webkit-details-marker { display:none; }
        </style>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Dashboard: Stats-Bar
    // ═══════════════════════════════════════════

    private static function render_stats_bar($exclude_cats = []) {
        $today = current_time('Y-m-d');

        // Events zählen
        $event_count = self::count_upcoming_events($exclude_cats);

        // Locations mit kommenden Events
        global $wpdb;
        $location_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.meta_value)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_location_id'
             JOIN {$wpdb->postmeta} ds ON p.ID = ds.post_id AND ds.meta_key = '_tix_date_start'
             WHERE p.post_type = 'event' AND p.post_status = 'publish'
               AND ds.meta_value >= %s AND pm.meta_value > 0",
            $today
        ));

        // Veranstalter mit Events
        $org_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.meta_value)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_organizer_id'
             JOIN {$wpdb->postmeta} ds ON p.ID = ds.post_id AND ds.meta_key = '_tix_date_start'
             WHERE p.post_type = 'event' AND p.post_status = 'publish'
               AND ds.meta_value >= %s AND pm.meta_value > 0",
            $today
        ));

        // Kategorien mit Events
        $cat_count = 0;
        $cats = get_terms(['taxonomy' => 'event_category', 'hide_empty' => true]);
        if (!is_wp_error($cats)) $cat_count = count($cats);

        // Städte (distinct _tix_city oder Location-Stadt)
        $city_count = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.meta_value)
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_location_city'
             WHERE p.post_type = 'tix_location' AND p.post_status = 'publish'
               AND pm.meta_value != ''"
        );
        if ($city_count < 1) $city_count = 1; // Mindestens 1 Stadt

        $stats = [
            ['icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>', 'value' => $event_count, 'label' => 'Events'],
            ['icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>', 'value' => $location_count, 'label' => 'Locations'],
            ['icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>', 'value' => $org_count, 'label' => 'Veranstalter'],
            ['icon' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>', 'value' => $cat_count, 'label' => 'Kategorien'],
        ];

        ob_start();
        ?>
        <div class="tix-hp-stats-bar" data-animate="1">
            <?php foreach ($stats as $i => $stat): ?>
                <?php if ($i > 0): ?><span class="tix-hp-stats-dot"></span><?php endif; ?>
                <div class="tix-hp-stat">
                    <span class="tix-hp-stat-icon"><?php echo $stat['icon']; ?></span>
                    <span class="tix-hp-stat-value" data-target="<?php echo intval($stat['value']); ?>">0</span>
                    <span class="tix-hp-stat-label"><?php echo esc_html($stat['label']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Dashboard: Kategorie-Kacheln
    // ═══════════════════════════════════════════

    private static function render_category_tiles($categories) {
        $cat_icons = [
            'konzert'    => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
            'comedy'     => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',
            'festival'   => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            'clubbing'   => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="4" y="2" width="16" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18.01"/><path d="M8 6h8M8 10h8M8 14h8"/></svg>',
            'workshop'   => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
            'theater'    => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 8c-2.2 0-4 1.8-4 4s1.8 4 4 4"/><path d="M12 8c2.2 0 4 1.8 4 4s-1.8 4-4 4"/><circle cx="9" cy="10" r="0.5" fill="currentColor"/><circle cx="15" cy="10" r="0.5" fill="currentColor"/><path d="M9 14s1 1 3 1 3-1 3-1"/><circle cx="12" cy="12" r="10"/></svg>',
            'sport'      => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a15 15 0 0 1 4 10 15 15 0 0 1-4 10"/><path d="M12 2a15 15 0 0 0-4 10 15 15 0 0 0 4 10"/><path d="M2 12h20"/></svg>',
            'networking' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
            'sonstiges'  => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        ];
        $default_icon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>';

        ob_start();
        ?>
        <div class="tix-hp-cat-tiles">
            <?php foreach ($categories as $cat):
                if ($cat->count < 1) continue;
                $slug = $cat->slug;
                $icon = $cat_icons[$slug] ?? $default_icon;
                $archive_url = get_post_type_archive_link('event');
                $url = add_query_arg('cat', $slug, $archive_url);
            ?>
            <a href="<?php echo esc_url($url); ?>" class="tix-hp-cat-tile" data-cat="<?php echo esc_attr($slug); ?>">
                <span class="tix-hp-cat-tile-icon"><?php echo $icon; ?></span>
                <span class="tix-hp-cat-tile-name"><?php echo esc_html($cat->name); ?></span>
                <span class="tix-hp-cat-tile-count"><?php echo intval($cat->count); ?> Events</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Dashboard: Diese Woche
    // ═══════════════════════════════════════════

    private static function render_this_week($days = 7, $exclude_cats = []) {
        $today = current_time('Y-m-d');
        $end = date('Y-m-d', strtotime("+{$days} days", strtotime($today)));

        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                ['key' => '_tix_date_start', 'value' => $end, 'compare' => '<=', 'type' => 'DATE'],
            ],
        ];

        if (!empty($exclude_cats)) {
            $args['tax_query'] = [['taxonomy' => 'event_category', 'field' => 'slug', 'terms' => $exclude_cats, 'operator' => 'NOT IN']];
        }

        $events = get_posts($args);
        if (empty($events)) return '';

        // Nach Tag gruppieren
        $by_day = [];
        foreach ($events as $e) {
            $date = get_post_meta($e->ID, '_tix_date_start', true);
            if (!$date) continue;
            $day_key = date('Y-m-d', strtotime($date));
            if (!isset($by_day[$day_key])) $by_day[$day_key] = [];
            $by_day[$day_key][] = $e;
        }

        if (empty($by_day)) return '';

        // Tage-Labels
        $day_labels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $month_labels = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

        // Zeitraum-Label
        $first_day = array_key_first($by_day);
        $last_day = array_key_last($by_day);
        $range_label = 'Diese Woche';
        if ($today === $first_day) {
            $range_label = 'Die nächsten Tage';
        }

        ob_start();
        ?>
        <div class="tix-hp-section tix-hp-this-week">
            <div class="tix-hp-section-header">
                <div>
                    <div class="tix-hp-section-label"><?php echo esc_html($range_label); ?></div>
                    <h2 class="tix-hp-section-title">Bald geht's los</h2>
                </div>
                <a href="<?php echo esc_url(get_post_type_archive_link('event')); ?>" class="tix-hp-section-link">
                    Alle anzeigen
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
            <div class="tix-hp-week-scroll">
                <div class="tix-hp-week-track">
                    <?php foreach ($by_day as $day_key => $day_events):
                        $ts = strtotime($day_key);
                        $dow = intval(date('N', $ts)) - 1;
                        $day_num = date('d', $ts);
                        $month_num = intval(date('n', $ts)) - 1;
                        $is_today = ($day_key === $today);
                        $is_tomorrow = ($day_key === date('Y-m-d', strtotime('+1 day', strtotime($today))));
                        $day_display = $is_today ? 'Heute' : ($is_tomorrow ? 'Morgen' : $day_labels[$dow]);
                    ?>
                    <div class="tix-hp-week-day <?php echo $is_today ? 'tix-hp-week-day--today' : ''; ?>">
                        <div class="tix-hp-week-day-header">
                            <span class="tix-hp-week-day-name"><?php echo esc_html($day_display); ?></span>
                            <span class="tix-hp-week-day-date"><?php echo $day_num; ?>. <?php echo $month_labels[$month_num]; ?></span>
                        </div>
                        <div class="tix-hp-week-day-events">
                            <?php foreach ($day_events as $e):
                                $time_start = get_post_meta($e->ID, '_tix_time_start', true);
                                $time_doors = get_post_meta($e->ID, '_tix_time_doors', true);
                                $display_time = $time_start ?: $time_doors;
                                $location = get_post_meta($e->ID, '_tix_location_short', true) ?: get_post_meta($e->ID, '_tix_location', true);
                                $price_card = get_post_meta($e->ID, '_tix_price_card', true);
                                $thumb = get_the_post_thumbnail_url($e->ID, 'thumbnail');
                                $permalink = get_permalink($e->ID);
                                $cats = get_the_terms($e->ID, 'event_category');
                                $cat_name = ($cats && !is_wp_error($cats)) ? $cats[0]->name : '';
                            ?>
                            <a href="<?php echo esc_url($permalink); ?>" class="tix-hp-week-event">
                                <?php if ($thumb): ?>
                                <div class="tix-hp-week-event-img">
                                    <img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy">
                                </div>
                                <?php endif; ?>
                                <div class="tix-hp-week-event-info">
                                    <div class="tix-hp-week-event-title"><?php echo esc_html(get_the_title($e->ID)); ?></div>
                                    <div class="tix-hp-week-event-meta">
                                        <?php if ($display_time): ?>
                                            <span class="tix-hp-week-event-time"><?php echo esc_html($display_time); ?> Uhr</span>
                                        <?php endif; ?>
                                        <?php if ($location): ?>
                                            <span class="tix-hp-week-event-loc"><?php echo esc_html($location); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($price_card): ?>
                                        <div class="tix-hp-week-event-price"><?php echo wp_kses_post($price_card); ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($cat_name): ?>
                                    <span class="tix-hp-week-event-cat"><?php echo esc_html($cat_name); ?></span>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Dashboard: Location-Spotlight
    // ═══════════════════════════════════════════

    private static function render_location_spotlight($count = 6, $exclude_cats = []) {
        global $wpdb;
        $today = current_time('Y-m-d');

        // Locations mit den meisten kommenden Events
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS loc_id, COUNT(*) AS cnt
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_tix_location_id'
             JOIN {$wpdb->postmeta} ds ON p.ID = ds.post_id AND ds.meta_key = '_tix_date_start'
             WHERE p.post_type = 'event' AND p.post_status = 'publish'
               AND ds.meta_value >= %s AND pm.meta_value > 0
             GROUP BY pm.meta_value
             ORDER BY cnt DESC
             LIMIT %d",
            $today, $count
        ));

        if (empty($results)) return '';

        $locations = [];
        foreach ($results as $row) {
            $loc = get_post(intval($row->loc_id));
            if (!$loc || $loc->post_type !== 'tix_location') continue;
            $address = get_post_meta($loc->ID, '_tix_location_address', true);
            $city = get_post_meta($loc->ID, '_tix_location_city', true);
            $image_id = get_post_meta($loc->ID, '_tix_location_image_id', true);
            $image_url = $image_id ? wp_get_attachment_image_url(intval($image_id), 'medium') : '';

            // Nächstes Event an dieser Location
            $next_event = get_posts([
                'post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => 1,
                'meta_key' => '_tix_date_start', 'orderby' => 'meta_value', 'order' => 'ASC',
                'meta_query' => [
                    ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                    ['key' => '_tix_location_id', 'value' => $loc->ID, 'type' => 'NUMERIC'],
                ],
            ]);
            $next_title = !empty($next_event) ? get_the_title($next_event[0]->ID) : '';
            $next_date = !empty($next_event) ? get_post_meta($next_event[0]->ID, '_tix_date_card', true) : '';

            $locations[] = [
                'id'         => $loc->ID,
                'name'       => get_the_title($loc->ID),
                'city'       => $city,
                'address'    => $address,
                'image_url'  => $image_url,
                'count'      => intval($row->cnt),
                'next_title' => $next_title,
                'next_date'  => $next_date,
            ];
        }

        if (empty($locations)) return '';

        ob_start();
        ?>
        <div class="tix-hp-section tix-hp-locations">
            <div class="tix-hp-section-header">
                <div>
                    <div class="tix-hp-section-label">Entdecken</div>
                    <h2 class="tix-hp-section-title">Beliebte Locations</h2>
                </div>
            </div>
            <div class="tix-hp-loc-grid">
                <?php foreach ($locations as $loc): ?>
                <div class="tix-hp-loc-card">
                    <div class="tix-hp-loc-card-img">
                        <?php if ($loc['image_url']): ?>
                            <img src="<?php echo esc_url($loc['image_url']); ?>" alt="<?php echo esc_attr($loc['name']); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="tix-hp-loc-card-placeholder">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            </div>
                        <?php endif; ?>
                        <div class="tix-hp-loc-card-badge"><?php echo $loc['count']; ?> Event<?php echo $loc['count'] !== 1 ? 's' : ''; ?></div>
                    </div>
                    <div class="tix-hp-loc-card-body">
                        <div class="tix-hp-loc-card-name"><?php echo esc_html($loc['name']); ?></div>
                        <?php if ($loc['city']): ?>
                            <div class="tix-hp-loc-card-city">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                <?php echo esc_html($loc['city']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($loc['next_title']): ?>
                            <div class="tix-hp-loc-card-next">
                                <span class="tix-hp-loc-card-next-label">Nächstes Event:</span>
                                <span class="tix-hp-loc-card-next-title"><?php echo esc_html($loc['next_title']); ?></span>
                                <?php if ($loc['next_date']): ?>
                                    <span class="tix-hp-loc-card-next-date"><?php echo esc_html($loc['next_date']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ═══════════════════════════════════════════
    // Assets
    // ═══════════════════════════════════════════

    private static function enqueue($mode = 'light', $url_sync = true) {
        wp_enqueue_style('tix-event-cards', TIXOMAT_URL . 'assets/css/event-cards.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-event-cards', TIXOMAT_URL . 'assets/js/event-cards.js', [], TIXOMAT_VERSION, true);

        wp_enqueue_style('tix-event-homepage', TIXOMAT_URL . 'assets/css/event-homepage.css', ['tix-event-cards'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-event-homepage', TIXOMAT_URL . 'assets/js/event-homepage.js', ['tix-event-cards'], TIXOMAT_VERSION, true);

        wp_localize_script('tix-event-cards', 'tixCards', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('tix_cards_nonce'),
            'isLoggedIn' => is_user_logged_in(),
        ]);
    }
}
