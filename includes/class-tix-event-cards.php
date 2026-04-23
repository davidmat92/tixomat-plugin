<?php
/**
 * TIX Event Cards — [tix_events] Shortcode
 *
 * Rendert Event-Karten in einem responsiven Grid.
 * Nutzt --tix-card-* CSS Custom Properties aus den Settings.
 */
if (!defined('ABSPATH')) exit;

class TIX_Event_Cards {

    public static function init() {
        add_shortcode('tix_events', [__CLASS__, 'render']);
        add_shortcode('tix_search', [__CLASS__, 'render_search']);

        // Automatische /events/ Archive-Seite
        // Auf `wp` Hook entscheiden wir, WELCHEN template_include-Filter wir nutzen:
        //   - Wenn Breakdance aktiv: deren Filter ersetzen + eigenen Renderer
        //   - Sonst: normaler template_include mit archive-event.php
        if (function_exists('tix_get_settings') && tix_get_settings('ec_page_enabled')) {
            add_action('wp', [__CLASS__, 'setup_archive_rendering'], 0);
        }
        add_action('wp_ajax_tix_toggle_save_event',        [__CLASS__, 'ajax_toggle_save']);
        add_action('wp_ajax_nopriv_tix_toggle_save_event', [__CLASS__, 'ajax_toggle_save_nopriv']);
        add_action('wp_ajax_tix_filter_events',            [__CLASS__, 'ajax_filter']);
        add_action('wp_ajax_nopriv_tix_filter_events',     [__CLASS__, 'ajax_filter']);
        add_action('wp_ajax_tix_search_events',            [__CLASS__, 'ajax_search']);
        add_action('wp_ajax_nopriv_tix_search_events',     [__CLASS__, 'ajax_search']);

        // Safety-Net: bei jedem Event-Save _tix_price_min/_tix_price_card aus _tix_ticket_categories
        // neu berechnen, falls der Haupt-Sync nicht gelaufen ist (WC-unabhängig).
        add_action('save_post_event', [__CLASS__, 'ensure_price_meta_on_save'], 99, 2);
    }

    /**
     * Berechnet min/max/card-text LIVE aus _tix_ticket_categories.
     * Unabhängig von _tix_tickets_enabled, unabhängig von WooCommerce, unabhängig von Sync-Status.
     *
     * Nur Online- + Offline-Kategorien mit Preis > 0 werden berücksichtigt.
     * "Gratis" Kategorien (price=0) werden ignoriert für min/max, da eine Zero-Kategorie
     * den Preis verschleiern würde. Nur wenn ALLE Preise 0 sind → $has_paid = false.
     *
     * @return array{min:float, max:float, has_paid:bool, card:string}
     */
    public static function compute_price_from_cats($cats) {
        $prices = [];
        if (is_array($cats)) {
            foreach ($cats as $cat) {
                // Aktive Phase hat Vorrang vor cat.price (analog zu Sync-Logik)
                $price = floatval($cat['price'] ?? 0);
                $sale  = $cat['sale_price'] ?? '';
                $effective = ($sale !== '' && $sale !== null && $sale !== false)
                    ? floatval($sale) : $price;

                if (class_exists('TIX_Metabox') && !empty($cat['phases'])) {
                    $active = TIX_Metabox::get_active_phase($cat['phases']);
                    if ($active && isset($active['price'])) {
                        $effective = floatval($active['price']);
                    }
                }

                if ($effective > 0) $prices[] = $effective;
            }
        }

        if (empty($prices)) {
            return ['min' => 0.0, 'max' => 0.0, 'has_paid' => false, 'card' => ''];
        }

        $min = min($prices);
        $max = max($prices);
        $card = ($min === $max)
            ? number_format($min, 2, ',', '.') . ' €'
            : 'Ab ' . number_format($min, 2, ',', '.') . ' €';

        return ['min' => $min, 'max' => $max, 'has_paid' => true, 'card' => $card];
    }

    /**
     * Safety-Net auf save_post_event: Preis-Meta immer aus Kategorien neu berechnen.
     * Fängt Fälle ab, wo TIX_Sync::sync() nicht greift (z.B. _tix_tickets_enabled leer,
     * Events ohne WC, Imports, REST-API-Writes).
     */
    public static function ensure_price_meta_on_save($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!$post || $post->post_type !== 'event') return;
        if ($post->post_status === 'auto-draft' || $post->post_status === 'trash') return;

        $cats = get_post_meta($post_id, '_tix_ticket_categories', true);
        if (!is_array($cats) || empty($cats)) return;

        $calc = self::compute_price_from_cats($cats);
        if (!$calc['has_paid']) return; // echt gratis — nichts überschreiben

        // Nur setzen wenn stored-Wert fehlt oder 0 ist (Sync darf gewinnen)
        $stored_min = floatval(get_post_meta($post_id, '_tix_price_min', true));
        if ($stored_min <= 0) {
            update_post_meta($post_id, '_tix_price_min', $calc['min']);
            update_post_meta($post_id, '_tix_price_max', $calc['max']);
            update_post_meta($post_id, '_tix_price_card', $calc['card']);
        }
    }

    /**
     * Archive-Template für /events/
     */
    public static function archive_template($template) {
        if (is_post_type_archive('event') || (is_tax('event_category'))) {
            $plugin_tpl = TIXOMAT_PATH . 'templates/archive-event.php';
            if (file_exists($plugin_tpl)) return $plugin_tpl;
        }
        return $template;
    }

    /**
     * Entscheidet auf `wp` welcher template-Filter genutzt wird.
     */
    public static function setup_archive_rendering() {
        if (!is_post_type_archive('event') && !is_tax('event_category')) return;

        $has_breakdance = function_exists('Breakdance\Render\getWordPressHtmlOutputWithHeaderAndFooterDependenciesAddedAndDisplayIt');

        if ($has_breakdance) {
            // Breakdance-Flow: deren Filter ersetzen durch unseren Render-Wrapper
            remove_filter('template_include', 'Breakdance\ActionsFilters\template_include', 1000000);
            add_filter('template_include', [__CLASS__, 'render_with_breakdance'], 1000000);
        } else {
            // Plain WP / anderes Theme: normales template_include mit get_header/get_footer
            add_filter('template_include', [__CLASS__, 'archive_template'], PHP_INT_MAX);
        }
    }

    /**
     * Reicht unser inneres Template an Breakdance's Renderer weiter.
     */
    public static function render_with_breakdance($file_to_include) {
        // Verhindern dass wir mehrfach rendern (z.B. falls der Filter aus mehreren Quellen feuert)
        static $rendered = false;
        if ($rendered) return null;
        $rendered = true;

        $our_tpl = TIXOMAT_PATH . 'templates/archive-event-inner.php';
        if (!file_exists($our_tpl)) return $file_to_include;

        // Breakdance Template-System initialisieren (analog zu deren eigenem template_include Handler),
        // damit get_breakdance_header/footer_template_for_request() funktionieren.
        if (function_exists('bdox_run_action')) {
            bdox_run_action('breakdance_register_template_types_and_conditions');
        }

        \Breakdance\Render\getWordPressHtmlOutputWithHeaderAndFooterDependenciesAddedAndDisplayIt($our_tpl);
        return null;
    }

    /**
     * Shortcode: [tix_events]
     */
    public static function render($atts) {
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $atts = shortcode_atts([
            'limit'        => intval($s['tix_card_columns_default'] ?? 4) * 2,
            'columns'      => intval($s['tix_card_columns_default'] ?? 4),
            'category'     => '',
            'city'         => '',
            'featured'     => '',
            'mode'         => $s['tix_card_default_mode'] ?? 'light',
            'show_filter'  => '0',
            'show_header'  => '0',
            'header_label' => 'Empfehlungen',
            'header_title' => 'Beliebt in deiner Nähe',
        ], $atts);

        self::enqueue($atts['mode']);

        $events = self::query_events($atts);
        $show_heart = !empty($s['tix_card_show_heart']);
        $show_badges = !empty($s['tix_card_show_badges']);
        $saved = self::get_saved_events();

        ob_start();
        ?>
        <section class="section section-<?php echo $atts['mode'] === 'dark' ? 'dark' : 'light'; ?>">
            <div class="section-inner">
                <?php if ($atts['show_header'] === '1'): ?>
                <div class="section-header">
                    <div>
                        <div class="section-label"><?php echo esc_html($atts['header_label']); ?></div>
                        <h2 class="section-title"><?php echo esc_html($atts['header_title']); ?></h2>
                    </div>
                    <a href="<?php echo esc_url(get_post_type_archive_link('event')); ?>" class="section-link">
                        Alle anzeigen
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($atts['show_filter'] === '1'): ?>
                    <?php echo self::render_filters($atts); ?>
                <?php endif; ?>

                <div class="ev-grid" data-columns="<?php echo intval($atts['columns']); ?>">
                    <?php foreach ($events as $event): ?>
                        <?php echo self::render_card($event, $show_heart, $show_badges, $saved); ?>
                    <?php endforeach; ?>
                    <?php if (empty($events)): ?>
                        <p style="grid-column:1/-1;text-align:center;padding:40px 0;opacity:0.5;">Keine Events gefunden.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Einzelne Event-Karte rendern
     */
    public static function render_card($event, $show_heart = true, $show_badges = true, $saved = []) {
        $id = $event->ID;
        $title = get_the_title($id);
        $permalink = get_permalink($id);
        $thumb_url = get_the_post_thumbnail_url($id, 'medium');

        // Tixomat Meta-Felder nutzen
        $date_card      = get_post_meta($id, '_tix_date_card', true);
        $location_short = get_post_meta($id, '_tix_location_short', true);
        $price_card     = get_post_meta($id, '_tix_price_card', true);
        $price_min      = floatval(get_post_meta($id, '_tix_price_min', true));
        $date_start     = get_post_meta($id, '_tix_date_start', true);

        // Ticket-Status
        $cats = get_post_meta($id, '_tix_ticket_categories', true);
        $total_tickets = 0;
        $sold_tickets = 0;
        if (is_array($cats)) {
            foreach ($cats as $c) {
                $total_tickets += intval($c['qty'] ?? 0);
            }
        }
        $sold_display = get_post_meta($id, '_tix_sold_total', true);
        $sold_tickets = intval($sold_display);
        $remaining = max(0, $total_tickets - $sold_tickets);

        // ── Preis-Fallback: LIVE aus _tix_ticket_categories neu berechnen,
        //    falls stored _tix_price_min = 0 ist (Sync nicht gelaufen, tickets_enabled nicht gesetzt,
        //    Import ohne Sync, etc.). Damit wird NIE fälschlich "Eintritt frei" angezeigt.
        if ($price_min <= 0 && is_array($cats) && !empty($cats)) {
            $calc = self::compute_price_from_cats($cats);
            if ($calc['has_paid']) {
                $price_min  = $calc['min'];
                if (empty($price_card)) $price_card = $calc['card'];
            }
        }

        $is_past = $date_start && strtotime($date_start) < current_time('timestamp');
        $is_soldout = ($total_tickets > 0 && $remaining <= 0) || $is_past;
        $is_free = ($price_min <= 0);
        $is_featured = get_post_meta($id, '_tix_is_featured', true);
        $is_today = $date_start && date('Y-m-d', strtotime($date_start)) === current_time('Y-m-d');
        $is_syndicated = get_post_meta($id, '_tix_syndicated', true);

        // Kategorie
        $terms = wp_get_post_terms($id, 'event_category', ['fields' => 'all']);
        $cat_name = (!is_wp_error($terms) && !empty($terms)) ? $terms[0]->name : '';
        $cat_slug = (!is_wp_error($terms) && !empty($terms)) ? $terms[0]->slug : 'default';

        // Button-Logik
        if ($is_soldout) {
            $btn_class = 'ev-btn-disabled';
            $btn_label = 'Ausverkauft';
        } elseif ($price_min > 0) {
            $btn_class = 'ev-btn-primary';
            $btn_label = 'Tickets';
        } elseif ($is_free) {
            $btn_class = 'ev-btn-outline';
            $btn_label = 'Details';
        } else {
            $btn_class = 'ev-btn-outline';
            $btn_label = 'Details';
        }

        // Preis-Anzeige
        $price_html = '';
        if ($is_soldout && $price_card) {
            $price_html = '<div class="ev-price" style="opacity:0.4">' . esc_html($price_card) . '</div>';
        } elseif ($is_free) {
            $price_html = '<div class="ev-price ev-price-free">Eintritt frei</div>';
        } elseif ($price_card) {
            $price_html = '<div class="ev-price">' . esc_html($price_card) . '</div>';
        }

        // Badge
        $badge_html = '';
        if ($show_badges) {
            if ($is_soldout) {
                $badge_html = '<span class="ev-badge" style="background:var(--tix-card-nacht, #131020);color:#fff;">Ausverkauft</span>';
            } elseif ($is_featured) {
                $badge_html = '<span class="ev-badge" style="background:rgba(232,68,90,0.9);color:#fff;">Empfehlung</span>';
            } elseif ($is_today) {
                $badge_html = '<span class="ev-badge" style="background:rgba(0,0,0,0.5);backdrop-filter:blur(6px);color:#fff;">Heute</span>';
            } elseif ($total_tickets > 0 && $remaining <= 3 && $remaining > 0) {
                $badge_html = '<span class="ev-badge" style="background:rgba(245,183,49,0.85);color:#131020;"><span class="ev-badge-dot" style="background:#131020;"></span>Letzte ' . $remaining . '</span>';
            } elseif ($total_tickets > 0 && $remaining <= ($total_tickets * 0.2)) {
                $badge_html = '<span class="ev-badge" style="background:rgba(20,184,166,0.85);color:#fff;"><span class="ev-badge-dot" style="background:#fff;"></span>Wenige Plätze</span>';
            }
        }

        $is_saved = in_array($id, $saved);

        $soldout_class = $is_soldout ? ' ev-soldout' : '';

        // Herz SVGs
        $heart_default = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';
        $heart_saved = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';

        // Pin icon
        $pin = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>';

        ob_start();
        ?>
        <a href="<?php echo esc_url($permalink); ?>" class="ev<?php echo $soldout_class; ?>" style="text-decoration:none;color:inherit;">
            <div class="ev-img">
                <div class="ev-img-inner">
                    <?php if ($thumb_url): ?>
                        <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        <?php echo self::get_placeholder($cat_slug); ?>
                    <?php endif; ?>
                </div>
                <?php if ($cat_name || $badge_html): ?>
                <div class="ev-badges">
                    <?php if ($cat_name): ?><span class="ev-badge ev-badge-cat"><?php echo esc_html($cat_name); ?></span><?php endif; ?>
                    <?php echo $badge_html; ?>
                </div>
                <?php endif; ?>
                <?php if ($show_heart): ?>
                <button class="ev-save <?php echo $is_saved ? 'saved' : ''; ?>" data-event-id="<?php echo $id; ?>" onclick="event.preventDefault();event.stopPropagation();tixToggleSave(this);">
                    <?php echo $is_saved ? $heart_saved : $heart_default; ?>
                </button>
                <?php endif; ?>
                <?php
                // Share-Button (Web-Share-API mit Copy-Link-Fallback)
                $share_url   = esc_url(get_permalink($id));
                $share_title = esc_attr($title);
                $share_text  = esc_attr(trim(($date_card ?: '') . ($location_short ? ' · ' . $location_short : '')));
                ?>
                <button type="button" class="ev-share" data-url="<?php echo $share_url; ?>" data-title="<?php echo $share_title; ?>" data-text="<?php echo $share_text; ?>" aria-label="Event teilen" onclick="event.preventDefault();event.stopPropagation();tixShareEvent(this);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                </button>
            </div>
            <div class="ev-body">
                <div class="ev-date"><?php echo esc_html($date_card ?: $date_start); ?></div>
                <div class="ev-title"><?php echo esc_html($title); ?></div>
                <div class="ev-loc"><?php echo $pin; ?> <?php echo esc_html($location_short ?: ''); ?></div>
            </div>
            <div class="ev-footer">
                <?php echo $price_html; ?>
                <span class="ev-btn <?php echo $btn_class; ?>"><?php echo esc_html($btn_label); ?></span>
            </div>
        </a>
        <?php
        return ob_get_clean();
    }

    /**
     * SVG-Platzhalter nach Kategorie
     */
    public static function get_placeholder($slug) {
        $colors = [
            'konzert'     => 'linear-gradient(135deg, #1C1831, #3A2F5C 50%, #E8445A)',
            'clubbing'    => 'linear-gradient(135deg, #1C1831, #3A2F5C 50%, #E8445A)',
            'party'       => '#131020',
            'workshop'    => '#0D9488',
            'festival'    => '#D49A18',
            'sport'       => '#2563EB',
            'comedy'      => '#7C3AED',
            'theater'     => '#5B21B6',
            'networking'  => '#14B8A6',
            'default'     => '#3A3937',
        ];
        $bg = $colors[$slug] ?? $colors['default'];

        return '<div class="ev-placeholder" style="background:' . $bg . ';">'
            . '<svg viewBox="0 0 300 172" fill="none" preserveAspectRatio="xMidYMid slice">'
            . '<circle cx="150" cy="86" r="30" stroke="rgba(255,255,255,0.1)" stroke-width="1"/>'
            . '<circle cx="150" cy="86" r="50" stroke="rgba(255,255,255,0.06)" stroke-width="1"/>'
            . '<circle cx="150" cy="86" r="70" stroke="rgba(255,255,255,0.04)" stroke-width="1"/>'
            . '<line x1="40" y1="86" x2="260" y2="86" stroke="rgba(255,255,255,0.06)" stroke-width="1"/>'
            . '</svg></div>';
    }

    /**
     * Filter-Bar rendern
     */
    private static function render_filters($atts) {
        $categories = get_terms(['taxonomy' => 'event_category', 'hide_empty' => true]);
        ob_start();
        ?>
        <div class="ev-filters" data-mode="<?php echo esc_attr($atts['mode']); ?>" style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
            <input type="text" class="ev-filter-search" placeholder="Events suchen…" style="flex:1;min-width:180px;padding:10px 14px;border:1px solid var(--tix-card-sand, #E0ECE4);border-radius:10px;font-size:14px;font-family:inherit;">
            <select class="ev-filter-cat" style="padding:10px 14px;border:1px solid var(--tix-card-sand, #E0ECE4);border-radius:10px;font-size:14px;font-family:inherit;">
                <option value="">Alle Kategorien</option>
                <?php if (!is_wp_error($categories)):
                    foreach ($categories as $cat): ?>
                        <option value="<?php echo esc_attr($cat->slug); ?>"><?php echo esc_html($cat->name); ?></option>
                    <?php endforeach;
                endif; ?>
            </select>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Events abfragen
     */
    public static function query_events($atts) {
        // Suche aus URL (?s=) oder Shortcode-Attribut
        $search = isset($atts['search']) ? trim((string) $atts['search']) : '';
        if (!$search && isset($_GET['s'])) {
            $search = sanitize_text_field(wp_unslash($_GET['s']));
        }

        $args = [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $search ? 100 : intval($atts['limit']),
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'     => '_tix_date_start',
                    'value'   => current_time('Y-m-d'),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
            ],
        ];

        if ($search !== '') {
            $args['s'] = $search;
        }

        if (!empty($atts['category'])) {
            $args['tax_query'] = [['taxonomy' => 'event_category', 'field' => 'slug', 'terms' => $atts['category']]];
        }

        if (!empty($atts['featured'])) {
            $args['meta_query'][] = ['key' => '_tix_is_featured', 'value' => '1'];
        }

        $results = get_posts($args);

        // Erweiterte Suche über Venue- und Organizer-Namen
        if ($search !== '') {
            global $wpdb;
            $like = '%' . $wpdb->esc_like($search) . '%';

            // Venue-Match: Locations deren Titel matcht
            $loc_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tix_location' AND post_status = 'publish' AND post_title LIKE %s LIMIT 20",
                $like
            ));
            // Organizer-Match: Organizer-CPTs deren Titel matcht
            $org_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tix_organizer' AND post_status = 'publish' AND post_title LIKE %s LIMIT 20",
                $like
            ));

            $meta_or = ['relation' => 'OR'];
            if (!empty($loc_ids)) $meta_or[] = ['key' => '_tix_location_id', 'value' => $loc_ids, 'compare' => 'IN'];
            if (!empty($org_ids)) {
                $meta_or[] = ['key' => '_tix_organizer_id', 'value' => $org_ids, 'compare' => 'IN'];
                $meta_or[] = ['key' => '_tix_co_organizer_id', 'value' => $org_ids, 'compare' => 'IN'];
            }

            if (count($meta_or) > 1) {
                $existing_ids = wp_list_pluck($results, 'ID');
                $related_args = $args;
                unset($related_args['s']);
                $related_args['post__not_in'] = $existing_ids ?: [0];
                $related_args['meta_query'] = array_merge($args['meta_query'], [$meta_or]);
                $related_args['posts_per_page'] = 100;
                $related_results = get_posts($related_args);
                $results = array_merge($results, $related_results);
            }
        }

        return $results;
    }

    /**
     * Gespeicherte Events des Users
     */
    public static function get_saved_events_static() {
        return self::get_saved_events();
    }

    private static function get_saved_events() {
        if (!is_user_logged_in()) return [];
        $saved = get_user_meta(get_current_user_id(), '_tix_saved_events', true);
        return is_array($saved) ? $saved : [];
    }

    /**
     * AJAX: Event speichern/entfernen
     */
    public static function ajax_toggle_save() {
        check_ajax_referer('tix_cards_nonce', 'nonce');
        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) wp_send_json_error();

        $user_id = get_current_user_id();
        $saved = get_user_meta($user_id, '_tix_saved_events', true) ?: [];
        if (!is_array($saved)) $saved = [];

        if (in_array($event_id, $saved)) {
            $saved = array_diff($saved, [$event_id]);
            $is_saved = false;
        } else {
            $saved[] = $event_id;
            $is_saved = true;
        }

        update_user_meta($user_id, '_tix_saved_events', array_values($saved));
        wp_send_json_success(['saved' => $is_saved]);
    }

    public static function ajax_toggle_save_nopriv() {
        wp_send_json_error(['login_required' => true]);
    }

    /**
     * AJAX: Events filtern
     */
    public static function ajax_filter() {
        $atts = [
            'limit'    => intval($_POST['limit'] ?? 8),
            'category' => sanitize_text_field($_POST['category'] ?? ''),
            'city'     => sanitize_text_field($_POST['city'] ?? ''),
            'featured' => sanitize_text_field($_POST['featured'] ?? ''),
        ];

        $events = self::query_events($atts);
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $show_heart = !empty($s['tix_card_show_heart']);
        $show_badges = !empty($s['tix_card_show_badges']);
        $saved = self::get_saved_events();

        $html = '';
        foreach ($events as $event) {
            $html .= self::render_card($event, $show_heart, $show_badges, $saved);
        }

        wp_send_json_success([
            'html'  => $html,
            'found' => count($events),
        ]);
    }

    /**
     * Assets laden
     */
    private static function enqueue($mode = 'light') {
        wp_enqueue_style('tix-event-cards', TIXOMAT_URL . 'assets/css/event-cards.css', [], TIXOMAT_VERSION);
        wp_enqueue_script('tix-event-cards', TIXOMAT_URL . 'assets/js/event-cards.js', [], TIXOMAT_VERSION, true);
        wp_localize_script('tix-event-cards', 'tixCards', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('tix_cards_nonce'),
            'isLoggedIn' => is_user_logged_in(),
        ]);
    }

    // ──────────────────────────────────────────
    // [tix_search] — Kompaktes Event-Suchfeld
    // ──────────────────────────────────────────

    public static function render_search($atts) {
        $atts = shortcode_atts([
            'placeholder' => 'Events suchen…',
            'mode'        => 'light',
            'limit'       => 5,
        ], $atts);

        self::enqueue($atts['mode']);

        $dark = $atts['mode'] === 'dark';
        $id = 'tix-search-' . wp_rand(1000, 9999);

        ob_start();
        ?>
        <div class="tix-search-wrap <?php echo $dark ? 'tix-search-dark' : ''; ?>" id="<?php echo $id; ?>" data-limit="<?php echo intval($atts['limit']); ?>">
            <div class="tix-search-input-wrap">
                <svg class="tix-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="tix-search-input" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" autocomplete="off">
                <span class="tix-search-clear" style="display:none;">&times;</span>
            </div>
            <div class="tix-search-results" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Live-Suche — matcht Title/Content + Venue-Namen + Organizer-Namen.
     * Zeigt ausschließlich KOMMENDE Events (keine vergangenen).
     */
    public static function ajax_search() {
        $q     = sanitize_text_field($_POST['q'] ?? '');
        $limit = min(10, max(1, intval($_POST['limit'] ?? 5)));

        if (strlen($q) < 2) {
            wp_send_json_success(['html' => '', 'count' => 0]);
        }

        $today = current_time('Y-m-d');

        global $wpdb;
        $like = '%' . $wpdb->esc_like($q) . '%';

        // Verwandte Venue + Organizer IDs einsammeln
        $loc_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tix_location' AND post_status = 'publish' AND post_title LIKE %s LIMIT 10",
            $like
        ));
        $org_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tix_organizer' AND post_status = 'publish' AND post_title LIKE %s LIMIT 10",
            $like
        ));

        // 1. Kommende Events mit Title/Content-Match
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $q,
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [[
                'key'     => '_tix_date_start',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATE',
            ]],
        ]);

        // 2. Venue/Organizer-Match bei kommenden Events ergänzen
        //    Matched auf lokale Location/Organizer-Posts (IDs) UND Klartext-Strings
        //    (_tix_location, _tix_organizer) — letzteres wichtig für syndicated Events,
        //    wo die Location als String gespeichert ist, aber keine lokale Post-ID existiert.
        if (count($events) < $limit) {
            $meta_or = ['relation' => 'OR'];
            if (!empty($loc_ids)) $meta_or[] = ['key' => '_tix_location_id', 'value' => $loc_ids, 'compare' => 'IN'];
            if (!empty($org_ids)) {
                $meta_or[] = ['key' => '_tix_organizer_id', 'value' => $org_ids, 'compare' => 'IN'];
                $meta_or[] = ['key' => '_tix_co_organizer_id', 'value' => $org_ids, 'compare' => 'IN'];
            }
            // String-Match auf Klartext-Meta (funktioniert auch ohne lokale Post-IDs)
            $meta_or[] = ['key' => '_tix_location',       'value' => $q, 'compare' => 'LIKE'];
            $meta_or[] = ['key' => '_tix_location_short', 'value' => $q, 'compare' => 'LIKE'];
            $meta_or[] = ['key' => '_tix_location_full',  'value' => $q, 'compare' => 'LIKE'];
            $meta_or[] = ['key' => '_tix_organizer',      'value' => $q, 'compare' => 'LIKE'];

            $existing_ids = wp_list_pluck($events, 'ID');
            $extra = get_posts([
                'post_type'      => 'event',
                'post_status'    => 'publish',
                'posts_per_page' => $limit - count($events),
                'post__not_in'   => $existing_ids ?: [0],
                'meta_key'       => '_tix_date_start',
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
                'meta_query'     => [
                    'relation' => 'AND',
                    ['key' => '_tix_date_start', 'value' => $today, 'compare' => '>=', 'type' => 'DATE'],
                    $meta_or,
                ],
            ]);
            $events = array_merge($events, $extra);
        }

        $events = array_slice($events, 0, $limit);

        if (empty($events)) {
            wp_send_json_success([
                'html'  => '<div class="tix-search-empty">Keine Events gefunden</div>',
                'count' => 0,
            ]);
        }

        $html = '';
        foreach ($events as $ev) {
            $date_card      = get_post_meta($ev->ID, '_tix_date_card', true);
            $location_short = get_post_meta($ev->ID, '_tix_location_short', true);
            $price_card     = get_post_meta($ev->ID, '_tix_price_card', true);
            $thumb          = get_the_post_thumbnail_url($ev->ID, 'thumbnail');
            $link           = get_permalink($ev->ID);

            $html .= '<a href="' . esc_url($link) . '" class="tix-search-item">';
            $html .= '<div class="tix-search-item-img">';
            if ($thumb) {
                $html .= '<img src="' . esc_url($thumb) . '" alt="">';
            } else {
                $html .= '<div class="tix-search-item-placeholder"></div>';
            }
            $html .= '</div>';
            $html .= '<div class="tix-search-item-info">';
            $html .= '<div class="tix-search-item-title">' . esc_html($ev->post_title) . '</div>';
            $html .= '<div class="tix-search-item-meta">' . esc_html($date_card) . ($location_short ? ' · ' . esc_html($location_short) : '') . '</div>';
            $html .= '</div>';
            if ($price_card) {
                $html .= '<div class="tix-search-item-price">' . esc_html($price_card) . '</div>';
            }
            $html .= '</a>';
        }

        $archive = get_post_type_archive_link('event');
        $html .= '<a href="' . esc_url(add_query_arg('s', urlencode($q), $archive)) . '" class="tix-search-all">Alle Ergebnisse anzeigen →</a>';

        wp_send_json_success(['html' => $html, 'count' => count($events)]);
    }
}
