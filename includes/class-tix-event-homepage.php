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
        add_action('wp_ajax_tix_homepage_filter',            [__CLASS__, 'ajax_filter']);
        add_action('wp_ajax_nopriv_tix_homepage_filter',     [__CLASS__, 'ajax_filter']);
        add_action('wp_ajax_tix_homepage_load_more',         [__CLASS__, 'ajax_load_more']);
        add_action('wp_ajax_nopriv_tix_homepage_load_more',  [__CLASS__, 'ajax_load_more']);
    }

    /**
     * Shortcode: [tix_homepage]
     */
    public static function render($atts) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];

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

        // Grid (hero + popular IDs ausschließen)
        $all_exclude = array_merge($hero_ids, $popular_ids);
        $grid_events = self::query_grid_events(intval($atts['grid_count']), $all_exclude, 0, '', '', $exclude_cats);

        $total_upcoming = self::count_upcoming_events($exclude_cats);

        // Alle Events für JSON-LD sammeln
        $all_events = array_merge($hero_events, $popular_events, $grid_events);

        $skeleton_cols = intval($atts['columns']);

        ob_start();
        ?>
        <div class="tix-hp tix-hp-<?php echo esc_attr($atts['mode']); ?>"
             style="max-width:<?php echo $max_width; ?>px;padding:<?php echo $pad_y; ?>px <?php echo $pad_x; ?>px;"
             data-mode="<?php echo esc_attr($atts['mode']); ?>"
             data-columns="<?php echo $skeleton_cols; ?>"
             data-url-sync="<?php echo $url_sync ? '1' : '0'; ?>"
             data-smart-time="<?php echo $smart_time_hint ? esc_attr($smart_time_hint) : ''; ?>">

            <?php // ── Hero ── ?>
            <?php if ($show_hero && !empty($hero_events)): ?>
                <?php if ($hero_style === 'fullwidth'): ?>
                    <?php echo self::render_hero_fullwidth($hero_events[0], $show_heart, $show_badges, $saved, $show_countdown); ?>
                <?php elseif ($hero_style === 'slider'): ?>
                    <?php echo self::render_hero_slider($hero_events, $show_heart, $show_badges, $saved, $show_countdown); ?>
                <?php else: ?>
                    <?php echo self::render_hero_grid($hero_events, $show_heart, $show_badges, $saved, $show_countdown); ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php // ── Stats-Bar ── ?>
            <?php if ($show_stats_bar): ?>
                <?php echo self::render_stats_bar($exclude_cats); ?>
            <?php endif; ?>

            <?php // ── Kategorie-Kacheln ── ?>
            <?php if ($show_cat_tiles && !empty($categories)): ?>
                <?php echo self::render_category_tiles($categories); ?>
            <?php endif; ?>

            <?php // ── Beliebt ── ?>
            <?php if ($show_popular && !empty($popular_events)): ?>
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
            <?php endif; ?>

            <?php // ── Diese Woche ── ?>
            <?php if ($show_this_week): ?>
                <?php echo self::render_this_week($this_week_days, $exclude_cats); ?>
            <?php endif; ?>

            <?php // ── Newsletter ── ?>
            <?php if ($show_newsletter && ($nl_title || $nl_text)): ?>
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
            <?php endif; ?>

            <?php // ── Veranstalter-Spotlight ── ?>
            <?php if ($show_spotlight): ?>
                <?php echo self::render_spotlight($spotlight_org_id, $spotlight_count, $show_heart, $show_badges, $saved, $exclude_cats); ?>
            <?php endif; ?>

            <?php // ── Filter ── ?>
            <?php if ($show_time_filter || $show_cat_filter || $show_list_toggle): ?>
            <div class="tix-hp-filters">
                <?php if ($show_time_filter): ?>
                <div class="tix-hp-time-filters">
                    <button class="tix-hp-time-btn active" data-time="all">Alle</button>
                    <button class="tix-hp-time-btn<?php echo $smart_time_hint === 'today' ? ' tix-hp-suggested' : ''; ?>" data-time="today">Heute</button>
                    <button class="tix-hp-time-btn<?php echo $smart_time_hint === 'tomorrow' ? ' tix-hp-suggested' : ''; ?>" data-time="tomorrow">Morgen</button>
                    <button class="tix-hp-time-btn<?php echo $smart_time_hint === 'weekend' ? ' tix-hp-suggested' : ''; ?>" data-time="weekend">Wochenende</button>
                    <button class="tix-hp-time-btn" data-time="week">Diese Woche</button>
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
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php // ── Grid ── ?>
            <div class="tix-hp-grid ev-grid" data-columns="<?php echo $skeleton_cols; ?>">
                <?php foreach ($grid_events as $event): ?>
                    <?php echo TIX_Event_Cards::render_card($event, $show_heart, $show_badges, $saved); ?>
                <?php endforeach; ?>
                <?php if (empty($grid_events)): ?>
                    <p class="tix-hp-empty">Keine weiteren Events gefunden.</p>
                <?php endif; ?>
            </div>

            <?php // ── Location-Spotlight ── ?>
            <?php if ($show_locations): ?>
                <?php echo self::render_location_spotlight($locations_count, $exclude_cats); ?>
            <?php endif; ?>

            <?php // ── Load More ── ?>
            <?php
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
            <?php endif; ?>

        </div>

        <?php // ── JSON-LD ── ?>
        <?php echo self::render_json_ld($all_events); ?>

        <?php
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
            <div class="tix-hp-hero-img">
                <?php if ($thumb_url): ?>
                    <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
                <?php else: ?>
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
            <div class="tix-hp-hero-sm-img">
                <?php if ($thumb_url): ?>
                    <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
                <?php else: ?>
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

    private static function query_grid_events($count = 8, $exclude_ids = [], $offset = 0, $time = '', $category = '', $exclude_cats = []) {
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
        return get_posts($args);
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
            $id = $event->ID;
            $date_start = get_post_meta($id, '_tix_date_start', true);
            $location_short = get_post_meta($id, '_tix_location_short', true);
            $price_min = floatval(get_post_meta($id, '_tix_price_min', true));
            $thumb_url = get_the_post_thumbnail_url($id, 'large');

            $item = [
                '@type'     => 'Event',
                'name'      => get_the_title($id),
                'url'       => get_permalink($id),
                'startDate' => $date_start ?: '',
            ];
            if ($thumb_url) $item['image'] = $thumb_url;
            if ($location_short) {
                $item['location'] = [
                    '@type' => 'Place',
                    'name'  => $location_short,
                ];
            }
            if ($price_min > 0) {
                $item['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => number_format($price_min, 2, '.', ''),
                    'priceCurrency' => 'EUR',
                    'availability'  => 'https://schema.org/InStock',
                    'url'           => get_permalink($id),
                ];
            }
            $items[] = $item;
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
