<?php
if (!defined('ABSPATH')) exit;

class TIX_Upsell {

    public static function init() {
        add_shortcode('tix_upsell', [__CLASS__, 'shortcode']);
    }

    /**
     * Shortcode: [tix_upsell] oder [tix_upsell id="123" count="3" heading="Weitere Events"]
     */
    public static function shortcode($atts = []) {
        $atts = shortcode_atts([
            'id'      => 0,
            'count'   => 0,
            'heading' => '',
            'exclude' => '',
            'class'   => '',
        ], $atts, 'tix_upsell');

        $tix_s = tix_get_settings();

        // Wenn show_upsell explizit deaktiviert und kein manueller Shortcode-Aufruf
        if (empty($atts['id']) && empty($tix_s['show_upsell'])) return '';

        $post_id     = intval($atts['id']) ?: get_the_ID();
        $count       = intval($atts['count']) ?: intval($tix_s['upsell_count'] ?? 3);
        $heading     = $atts['heading'] ?: ($tix_s['upsell_heading'] ?? 'Das könnte dich auch interessieren');
        $exclude_ids = $atts['exclude'] ? array_map('intval', explode(',', $atts['exclude'])) : [];

        // Event-seitig deaktiviert?
        if ($post_id && get_post_meta($post_id, '_tix_upsell_disabled', true) === '1') return '';

        if ($post_id && get_post_type($post_id) === 'event') {
            $exclude_ids[] = $post_id;
        }

        // Manuelle Auswahl prüfen
        $manual_ids = get_post_meta($post_id, '_tix_upsell_events', true);
        if (!empty($manual_ids) && is_array($manual_ids)) {
            $events = self::get_manual_events($manual_ids, $count);
        } else {
            $events = self::get_related_events($post_id, $count, $exclude_ids);
        }
        if (empty($events)) return '';

        // Enqueue
        wp_enqueue_style('tix-upsell', TIXOMAT_URL . 'assets/css/upsell.css', [], TIXOMAT_VERSION);

        $extra_class = $atts['class'] ? ' ' . esc_attr($atts['class']) : '';

        ob_start();
        ?>
        <div class="tix-up<?php echo $extra_class; ?>">
            <?php if ($heading): ?>
                <h3 class="tix-up-heading"><?php echo esc_html($heading); ?></h3>
            <?php endif; ?>
            <div class="tix-up-grid tix-up-grid-<?php echo count($events); ?>">
                <?php foreach ($events as $event): ?>
                    <?php echo self::render_card($event); ?>
                <?php endforeach; ?>
            </div>
            <?php echo tix_branding_footer(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Upsell-Bereich für Danke-Seite (von Checkout aufgerufen)
     */
    public static function render_for_thankyou($order) {
        $tix_s = tix_get_settings();
        if (empty($tix_s['show_upsell'])) return '';

        $count   = intval($tix_s['upsell_count'] ?? 3);
        $heading = $tix_s['upsell_heading'] ?? 'Das könnte dich auch interessieren';

        // Event-IDs aus der Bestellung ermitteln
        $order_event_ids = self::get_event_ids_from_order($order);

        // Wenn alle Events Upselling deaktiviert haben → nicht anzeigen
        if (!empty($order_event_ids)) {
            $all_disabled = true;
            foreach ($order_event_ids as $eid) {
                if (get_post_meta($eid, '_tix_upsell_disabled', true) !== '1') {
                    $all_disabled = false;
                    break;
                }
            }
            if ($all_disabled) return '';
        }

        // 1. Manuelle Auswahl der bestellten Events prüfen
        $events = [];
        if (!empty($order_event_ids)) {
            foreach ($order_event_ids as $eid) {
                $manual_ids = get_post_meta($eid, '_tix_upsell_events', true);
                if (!empty($manual_ids) && is_array($manual_ids)) {
                    $manual = self::get_manual_events($manual_ids, $count);
                    foreach ($manual as $m) {
                        $events[$m->ID] = $m;
                    }
                }
                if (count($events) >= $count) break;
            }
            $events = array_slice(array_values($events), 0, $count);
        }

        // 2. Fallback: automatisch verwandte Events
        if (empty($events) && !empty($order_event_ids)) {
            foreach ($order_event_ids as $eid) {
                $related = self::get_related_events($eid, $count, $order_event_ids);
                foreach ($related as $r) {
                    $events[$r->ID] = $r;
                }
                if (count($events) >= $count) break;
            }
            $events = array_slice(array_values($events), 0, $count);
        }

        // 3. Fallback: Einfach kommende Events
        if (empty($events)) {
            $events = self::get_upcoming_events($count, $order_event_ids);
        }

        if (empty($events)) return '';

        // Enqueue
        wp_enqueue_style('tix-upsell', TIXOMAT_URL . 'assets/css/upsell.css', [], TIXOMAT_VERSION);

        ob_start();
        ?>
        <div class="tix-co-section">
            <div class="tix-up tix-up-thankyou">
                <?php if ($heading): ?>
                    <h3 class="tix-up-heading"><?php echo esc_html($heading); ?></h3>
                <?php endif; ?>
                <div class="tix-up-grid tix-up-grid-<?php echo count($events); ?>">
                    <?php foreach ($events as $event): ?>
                        <?php echo self::render_card($event); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Einzelne Event-Karte rendern
     */
    private static function render_card($event) {
        $id        = $event->ID;
        $title     = get_the_title($id);
        $permalink = get_permalink($id);
        $thumb_url = get_the_post_thumbnail_url($id, 'medium');
        $date_disp = get_post_meta($id, '_tix_date_display', true);
        $location  = get_post_meta($id, '_tix_location', true);
        $price     = get_post_meta($id, '_tix_price_range', true);
        $status    = get_post_meta($id, '_tix_status', true);
        $status_l  = get_post_meta($id, '_tix_status_label', true);

        // Status-Badge nur für besondere Zustände
        $show_badge = in_array($status, ['few_tickets', 'postponed'], true);

        ob_start();
        ?>
        <a href="<?php echo esc_url($permalink); ?>" class="tix-up-card">
            <div class="tix-up-card-img">
                <?php if ($thumb_url): ?>
                    <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
                <?php else: ?>
                    <div class="tix-up-card-noimg">
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5">
                            <rect x="3" y="4" width="18" height="18" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>
                        </svg>
                    </div>
                <?php endif; ?>
                <?php if ($show_badge && $status_l): ?>
                    <span class="tix-up-card-badge tix-up-badge-<?php echo esc_attr($status); ?>"><?php echo esc_html($status_l); ?></span>
                <?php endif; ?>
            </div>
            <div class="tix-up-card-body">
                <span class="tix-up-card-title"><?php echo esc_html($title); ?></span>
                <?php if ($date_disp): ?>
                    <span class="tix-up-card-meta">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?php echo esc_html($date_disp); ?>
                    </span>
                <?php endif; ?>
                <?php if ($location): ?>
                    <span class="tix-up-card-meta">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <?php echo esc_html($location); ?>
                    </span>
                <?php endif; ?>
                <?php if ($price): ?>
                    <span class="tix-up-card-price"><?php echo wp_kses_post($price); ?></span>
                <?php endif; ?>
            </div>
        </a>
        <?php
        return ob_get_clean();
    }

    /**
     * Manuell ausgewählte Events laden (nur publizierte, zukünftige)
     */
    private static function get_manual_events($event_ids, $count = 3) {
        if (empty($event_ids)) return [];

        $now = current_time('Y-m-d');
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'post__in'       => array_map('intval', $event_ids),
            'posts_per_page' => $count,
            'orderby'        => 'post__in', // Reihenfolge beibehalten
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_tix_date_start',
                    'value'   => $now,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_tix_status',
                        'value'   => ['sold_out', 'cancelled'],
                        'compare' => 'NOT IN',
                    ],
                    [
                        'key'     => '_tix_status',
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ],
        ]);

        return $events;
    }

    /**
     * Verwandte Events finden: gleiche Kategorie → dann andere kommende Events
     */
    private static function get_related_events($event_id, $count = 3, $exclude_ids = []) {
        $events = [];
        $now    = current_time('Y-m-d');

        // Event-Kategorien des aktuellen Events
        $terms = wp_get_post_terms($event_id, 'event_category', ['fields' => 'ids']);

        // 1. Gleiche Kategorie
        if (!empty($terms) && !is_wp_error($terms)) {
            $args = self::base_query_args($count, $exclude_ids, $now);
            $args['tax_query'] = [
                [
                    'taxonomy' => 'event_category',
                    'field'    => 'term_id',
                    'terms'    => $terms,
                ],
            ];
            $query = new WP_Query($args);
            $events = $query->posts;
        }

        // 2. Auffüllen mit anderen kommenden Events
        if (count($events) < $count) {
            $used_ids = array_merge($exclude_ids, wp_list_pluck($events, 'ID'));
            $remaining = $count - count($events);
            $args = self::base_query_args($remaining, $used_ids, $now);
            $query = new WP_Query($args);
            $events = array_merge($events, $query->posts);
        }

        return array_slice($events, 0, $count);
    }

    /**
     * Einfach kommende Events laden (Fallback für Danke-Seite)
     */
    private static function get_upcoming_events($count = 3, $exclude_ids = []) {
        $now  = current_time('Y-m-d');
        $args = self::base_query_args($count, $exclude_ids, $now);
        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Basis-Query-Argumente für Event-Suche
     */
    private static function base_query_args($count, $exclude_ids, $now) {
        return [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'post__not_in'   => $exclude_ids,
            'meta_query'     => [
                'relation' => 'AND',
                // Nur zukünftige Events
                [
                    'key'     => '_tix_date_start',
                    'value'   => $now,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
                // Keine ausverkauften/abgesagten Events
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_tix_status',
                        'value'   => ['sold_out', 'cancelled'],
                        'compare' => 'NOT IN',
                    ],
                    [
                        'key'     => '_tix_status',
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ],
            'orderby'  => 'meta_value',
            'meta_key' => '_tix_date_start',
            'order'    => 'ASC',
        ];
    }

    /**
     * Event-IDs aus WooCommerce-Bestellung extrahieren
     */
    private static function get_event_ids_from_order($order) {
        $event_ids = [];
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();

            // Primär: Direkter TIX-Link auf Produkt
            $tix_source = get_post_meta($product_id, '_tix_source_event', true);
            if ($tix_source) {
                $eid = intval($tix_source);
                if (get_post_status($eid)) {
                    $event_ids[$eid] = $eid;
                    continue;
                }
            }

            // Fallback: Tickera-Bridge (_event_name → tc_events → tix_event)
            $tc_event_id = get_post_meta($product_id, '_event_name', true);
            if ($tc_event_id) {
                $event_post = self::find_event_by_tickera_id($tc_event_id);
                if ($event_post) {
                    $event_ids[$event_post] = $event_post;
                }
            }
        }
        return array_values($event_ids);
    }

    /**
     * Event-Post-ID anhand der Tickera-Event-ID finden
     */
    private static function find_event_by_tickera_id($tc_event_id) {
        // Tickera-Event-Post prüfen → dessen _tix_event_id oder post_parent
        $tc_post = get_post($tc_event_id);
        if (!$tc_post) return null;

        // Wenn es direkt ein Event-Post ist
        if ($tc_post->post_type === 'event') return $tc_post->ID;

        // Tickera-Event-Post hat unsere Event-ID als Meta
        $tix_event_id = get_post_meta($tc_event_id, '_tix_source_event', true);
        if ($tix_event_id) return intval($tix_event_id);

        // Fallback: Event suchen, das dieses Tickera-Event referenziert
        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_tix_tc_event_id',
                    'value'   => $tc_event_id,
                    'compare' => '=',
                ],
            ],
        ]);

        return !empty($events) ? $events[0]->ID : null;
    }
}
