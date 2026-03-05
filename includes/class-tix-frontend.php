<?php
if (!defined('ABSPATH')) exit;

class TIX_Frontend {

    public static function init() {
        // Open Graph + JSON-LD Meta
        add_action('wp_head', [__CLASS__, 'og_meta'], 5);
        add_action('wp_head', [__CLASS__, 'json_ld'], 6);
    }

    /**
     * Eigenes Cron-Intervall: alle 10 Minuten
     */
    public static function cron_interval($schedules) {
        $schedules['tix_every_10min'] = [
            'interval' => 600,
            'display'  => 'Alle 10 Minuten (Tixomat)',
        ];
        return $schedules;
    }

    /**
     * Presale automatisch beenden wenn Zeitpunkt erreicht
     */
    public static function check_presale_end() {
        $now = current_time('timestamp');

        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_tix_tickets_enabled', 'value' => '1'],
                ['key' => '_tix_presale_active',  'value' => '1'],
                [
                    'key'     => '_tix_presale_end_mode',
                    'value'   => 'manual',
                    'compare' => '!=',
                ],
            ],
        ]);

        foreach ($events as $event) {
            $post_id  = $event->ID;
            $computed = get_post_meta($post_id, '_tix_presale_end_computed', true);

            if (empty($computed)) continue;

            $end_ts = strtotime($computed);
            if ($end_ts && $now >= $end_ts) {
                update_post_meta($post_id, '_tix_presale_active', '0');

                // Breakdance-Meta aktualisieren (Status → sold_out wenn auto)
                $manual_status = get_post_meta($post_id, '_tix_event_status', true) ?: '';
                if ($manual_status === '') {
                    update_post_meta($post_id, '_tix_status', 'sold_out');
                    update_post_meta($post_id, '_tix_status_label', 'Ausverkauft');
                }
            }
        }
    }

    /**
     * Preisphasen automatisch aktualisieren
     * Wird vom Cron alle 10 Minuten aufgerufen
     */
    public static function check_pricing_phases() {
        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_tix_tickets_enabled', 'value' => '1'],
            ],
        ]);

        if (!class_exists('TIX_Metabox')) {
            require_once TIXOMAT_PATH . 'includes/class-tix-metabox.php';
        }

        foreach ($events as $event) {
            $post_id    = $event->ID;
            $categories = get_post_meta($post_id, '_tix_ticket_categories', true);
            if (!is_array($categories)) continue;

            $updated = false;

            foreach ($categories as $cat) {
                $phases = $cat['phases'] ?? [];
                if (empty($phases)) continue;

                $active_phase = TIX_Metabox::get_active_phase($phases);
                $product_id   = intval($cat['product_id'] ?? 0);
                if (!$product_id) continue;

                $product = wc_get_product($product_id);
                if (!$product) continue;

                $base_price = floatval($cat['price']);

                if ($active_phase) {
                    $phase_price = floatval($active_phase['price']);
                    $current_regular = floatval($product->get_regular_price());
                    $current_sale    = $product->get_sale_price();

                    if ($phase_price < $base_price) {
                        // Phase günstiger → als Sale
                        if ($current_regular != $base_price || floatval($current_sale) != $phase_price) {
                            $product->set_regular_price($base_price);
                            $product->set_sale_price($phase_price);
                            $product->save();
                            $updated = true;
                        }
                    } else {
                        // Phase gleich/teurer
                        if ($current_regular != $phase_price || $current_sale !== '') {
                            $product->set_regular_price($phase_price);
                            $product->set_sale_price('');
                            $product->save();
                            $updated = true;
                        }
                    }
                } else {
                    // Keine aktive Phase → Basispreis
                    $current_regular = floatval($product->get_regular_price());
                    $manual_sale = $cat['sale_price'] ?? '';
                    $has_manual_sale = ($manual_sale !== '' && $manual_sale !== null && $manual_sale !== false);

                    if ($current_regular != $base_price) {
                        $product->set_regular_price($base_price);
                        if ($has_manual_sale) {
                            $product->set_sale_price(floatval($manual_sale));
                        } else {
                            $product->set_sale_price('');
                        }
                        $product->save();
                        $updated = true;
                    }
                }
            }

            // Breakdance-Meta neu berechnen wenn sich Preise geändert haben
            if ($updated && class_exists('TIX_Sync')) {
                // Trigger a minimal meta update
                TIX_Sync::update_price_meta($post_id, $categories);
            }
        }
    }

    /**
     * Open Graph Meta Tags für Event-Seiten
     */
    public static function og_meta() {
        if (!is_singular('event')) return;

        $post_id = get_the_ID();
        $title   = get_the_title($post_id);
        $url     = get_permalink($post_id);
        $excerpt = get_the_excerpt($post_id);

        // Beschreibung: Excerpt oder Info-Beschreibung
        if (empty($excerpt)) {
            $excerpt = get_post_meta($post_id, '_tix_info_description', true);
        }
        $excerpt = wp_strip_all_tags($excerpt);
        if (mb_strlen($excerpt) > 160) {
            $excerpt = mb_substr($excerpt, 0, 157) . '…';
        }

        // Bild: Featured Image oder erstes Ticket-Bild
        $image = '';
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            $image = wp_get_attachment_image_url($thumb_id, 'large');
        } else {
            $img_url = get_post_meta($post_id, '_tix_ticket_1_image_url', true);
            if ($img_url) $image = $img_url;
        }

        // Datum
        $date_start = get_post_meta($post_id, '_tix_date_start', true);
        $time_start = get_post_meta($post_id, '_tix_time_start', true);
        $location   = get_post_meta($post_id, '_tix_location', true);

        // Preis
        $price_range = get_post_meta($post_id, '_tix_price_range', true);

        // Site Name
        $site_name = get_bloginfo('name');

        // Keine bestehenden OG-Tags duplizieren (z.B. Yoast/RankMath)
        if (defined('WPSEO_VERSION') || class_exists('RankMath')) return;

        ?>
        <!-- Tixomat: Open Graph -->
        <meta property="og:type" content="website" />
        <meta property="og:title" content="<?php echo esc_attr($title); ?>" />
        <meta property="og:url" content="<?php echo esc_url($url); ?>" />
        <meta property="og:site_name" content="<?php echo esc_attr($site_name); ?>" />
        <?php if ($excerpt): ?>
        <meta property="og:description" content="<?php echo esc_attr($excerpt); ?>" />
        <?php endif; ?>
        <?php if ($image): ?>
        <meta property="og:image" content="<?php echo esc_url($image); ?>" />
        <meta property="og:image:width" content="1200" />
        <meta property="og:image:height" content="630" />
        <?php endif; ?>
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="<?php echo esc_attr($title); ?>" />
        <?php if ($excerpt): ?>
        <meta name="twitter:description" content="<?php echo esc_attr($excerpt); ?>" />
        <?php endif; ?>
        <?php if ($image): ?>
        <meta name="twitter:image" content="<?php echo esc_url($image); ?>" />
        <?php endif; ?>
        <?php if ($date_start): ?>
        <meta property="event:start_time" content="<?php echo esc_attr($date_start . ($time_start ? 'T' . $time_start : '')); ?>" />
        <?php endif; ?>
        <?php if ($location): ?>
        <meta property="event:location" content="<?php echo esc_attr($location); ?>" />
        <?php endif; ?>
        <?php if ($price_range): ?>
        <meta property="event:price" content="<?php echo esc_attr(wp_strip_all_tags($price_range)); ?>" />
        <?php endif; ?>
        <!-- /Tixomat: Open Graph -->
        <?php
    }

    /**
     * Vergangene Events automatisch archivieren
     */
    public static function check_past_events() {
        $now = current_time('Y-m-d H:i');

        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'relation' => 'OR',
                    ['key' => '_tix_is_past', 'compare' => 'NOT EXISTS'],
                    ['key' => '_tix_is_past', 'value' => '0'],
                ],
            ],
        ]);

        foreach ($events as $event) {
            $post_id    = $event->ID;
            $date_end   = get_post_meta($post_id, '_tix_date_end', true);
            $time_end   = get_post_meta($post_id, '_tix_time_end', true);

            if (!$date_end) continue;

            $event_end = $date_end . ' ' . ($time_end ?: '23:59');

            // 24h nach Event-Ende
            $archive_ts = strtotime($event_end) + 86400;
            if (current_time('timestamp') >= $archive_ts) {
                update_post_meta($post_id, '_tix_is_past', '1');
                update_post_meta($post_id, '_tix_is_past_label', 'Vergangen');

                // Presale auch beenden
                update_post_meta($post_id, '_tix_presale_active', '0');

                // Status aktualisieren wenn auto
                $manual = get_post_meta($post_id, '_tix_event_status', true);
                if (!$manual) {
                    update_post_meta($post_id, '_tix_status', 'past');
                    update_post_meta($post_id, '_tix_status_label', 'Vergangen');
                }
            }
        }
    }

    /**
     * JSON-LD Structured Data für Event-Seiten
     */
    public static function json_ld() {
        if (!is_singular('event')) return;

        $post_id    = get_the_ID();
        $title      = get_the_title($post_id);
        $url        = get_permalink($post_id);
        $date_start = get_post_meta($post_id, '_tix_date_start', true);
        $time_start = get_post_meta($post_id, '_tix_time_start', true);
        $date_end   = get_post_meta($post_id, '_tix_date_end', true);
        $time_end   = get_post_meta($post_id, '_tix_time_end', true);
        $location   = get_post_meta($post_id, '_tix_location', true);
        $address    = get_post_meta($post_id, '_tix_address', true);
        $organizer  = get_post_meta($post_id, '_tix_organizer', true);

        if (!$date_start) return;

        // Beschreibung
        $desc = get_the_excerpt($post_id);
        if (!$desc) $desc = get_post_meta($post_id, '_tix_info_description', true);
        $desc = wp_strip_all_tags($desc);

        // Bild
        $image = '';
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) $image = wp_get_attachment_image_url($thumb_id, 'large');

        // Preise
        $price_min = get_post_meta($post_id, '_tix_price_min', true);
        $price_max = get_post_meta($post_id, '_tix_price_max', true);

        // Status
        $status     = get_post_meta($post_id, '_tix_status', true);
        $is_past    = get_post_meta($post_id, '_tix_is_past', true);
        $event_status = 'https://schema.org/EventScheduled';
        if ($status === 'cancelled') $event_status = 'https://schema.org/EventCancelled';
        elseif ($status === 'postponed') $event_status = 'https://schema.org/EventPostponed';

        // Availability
        $availability = 'https://schema.org/InStock';
        if ($status === 'sold_out') $availability = 'https://schema.org/SoldOut';
        elseif ($status === 'few_tickets') $availability = 'https://schema.org/LimitedAvailability';
        if ($is_past === '1') $availability = 'https://schema.org/SoldOut';

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Event',
            'name'        => $title,
            'url'         => $url,
            'startDate'   => $date_start . ($time_start ? 'T' . $time_start : ''),
            'eventStatus' => $event_status,
        ];

        if ($date_end) {
            $schema['endDate'] = $date_end . ($time_end ? 'T' . $time_end : '');
        }

        if ($desc) $schema['description'] = mb_substr($desc, 0, 300);
        if ($image) $schema['image'] = $image;

        if ($location) {
            $place = [
                '@type' => 'Place',
                'name'  => $location,
            ];
            if ($address) {
                $place['address'] = [
                    '@type'         => 'PostalAddress',
                    'streetAddress' => $address,
                ];
            } else {
                $place['address'] = $location;
            }
            $schema['location'] = $place;
        }

        if ($organizer) {
            $schema['organizer'] = [
                '@type' => 'Organization',
                'name'  => $organizer,
            ];
        }

        if ($price_min && floatval($price_min) > 0) {
            $offer = [
                '@type'         => 'AggregateOffer',
                'priceCurrency' => 'EUR',
                'lowPrice'      => floatval($price_min),
                'availability'  => $availability,
                'url'           => $url,
            ];
            if ($price_max && floatval($price_max) > floatval($price_min)) {
                $offer['highPrice'] = floatval($price_max);
            }
            $schema['offers'] = $offer;
        }

        echo "\n<!-- Tixomat: JSON-LD -->\n";
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
    }

    /**
     * Dashboard Widget registrieren
     */
    public static function register_dashboard_widget() {
        wp_add_dashboard_widget(
            'tix_dashboard_widget',
            'Tixomat – Übersicht',
            [__CLASS__, 'render_dashboard_widget']
        );
    }

    /**
     * Dashboard Widget rendern
     */
    public static function render_dashboard_widget() {
        global $wpdb;

        // Nächste 5 Events
        $upcoming = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => 5,
            'post_status'    => 'publish',
            'meta_key'       => '_tix_date_start',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => '_tix_date_start', 'value' => current_time('Y-m-d'), 'compare' => '>=', 'type' => 'DATE'],
            ],
        ]);

        // Gesamtstatistiken
        $total_events = wp_count_posts('event');
        $published    = $total_events->publish ?? 0;

        // Verkaufszahlen über alle Events (aus Meta)
        $total_sold = $wpdb->get_var(
            "SELECT COALESCE(SUM(CAST(m.meta_value AS UNSIGNED)), 0)
             FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID AND p.post_type = 'event' AND p.post_status = 'publish'
             WHERE m.meta_key = '_tix_sold_total'"
        );

        $total_capacity = $wpdb->get_var(
            "SELECT COALESCE(SUM(CAST(m.meta_value AS UNSIGNED)), 0)
             FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID AND p.post_type = 'event' AND p.post_status = 'publish'
             WHERE m.meta_key = '_tix_capacity_total'"
        );

        // Umsatz aus WC-Bestellungen (nur Ticket-Produkte, letzte 30 Tage)
        $revenue_30d = 0;
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $revenue_30d = $wpdb->get_var(
                "SELECT COALESCE(SUM(CAST(oim_total.meta_value AS DECIMAL(10,2))), 0)
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
                 INNER JOIN {$wpdb->prefix}wc_orders o ON oi.order_id = o.id AND o.status IN ('wc-completed','wc-processing') AND o.date_created_gmt >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 WHERE oi.order_item_type = 'line_item'"
            );
        } else {
            $revenue_30d = $wpdb->get_var(
                "SELECT COALESCE(SUM(CAST(oim_total.meta_value AS DECIMAL(10,2))), 0)
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
                 INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID AND p.post_status IN ('wc-completed','wc-processing') AND p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 WHERE oi.order_item_type = 'line_item'"
            );
        }

        ?>
        <style>
            .tix-dw { font-size: 13px; }
            .tix-dw-stats { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
            .tix-dw-stat { flex: 1; min-width: 80px; background: #f6f7f7; border-radius: 6px; padding: 10px 12px; text-align: center; }
            .tix-dw-stat-num { font-size: 20px; font-weight: 700; display: block; line-height: 1.2; }
            .tix-dw-stat-lbl { font-size: 11px; color: #666; display: block; }
            .tix-dw-events { margin: 0; }
            .tix-dw-event { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee; }
            .tix-dw-event:last-child { border-bottom: none; }
            .tix-dw-event-name { font-weight: 600; }
            .tix-dw-event-name a { text-decoration: none; }
            .tix-dw-event-meta { font-size: 12px; color: #888; }
            .tix-dw-event-right { text-align: right; white-space: nowrap; }
            .tix-dw-event-status { font-size: 11px; font-weight: 600; }
            .tix-dw-empty { color: #888; font-style: italic; padding: 12px 0; }
        </style>
        <div class="tix-dw">
            <div class="tix-dw-stats">
                <div class="tix-dw-stat">
                    <span class="tix-dw-stat-num"><?php echo intval($published); ?></span>
                    <span class="tix-dw-stat-lbl">Events</span>
                </div>
                <div class="tix-dw-stat">
                    <span class="tix-dw-stat-num"><?php echo intval($total_sold); ?></span>
                    <span class="tix-dw-stat-lbl">Tickets verkauft</span>
                </div>
                <div class="tix-dw-stat">
                    <span class="tix-dw-stat-num"><?php echo intval($total_capacity); ?></span>
                    <span class="tix-dw-stat-lbl">Kapazität</span>
                </div>
                <div class="tix-dw-stat">
                    <span class="tix-dw-stat-num"><?php echo number_format(floatval($revenue_30d), 0, ',', '.'); ?> €</span>
                    <span class="tix-dw-stat-lbl">Umsatz (30 Tage)</span>
                </div>
            </div>

            <h4 style="margin: 0 0 8px; font-size: 13px;">Nächste Events</h4>
            <?php if (!empty($upcoming)): ?>
            <div class="tix-dw-events">
                <?php foreach ($upcoming as $ev):
                    $ds = get_post_meta($ev->ID, '_tix_date_start', true);
                    $loc = get_post_meta($ev->ID, '_tix_location', true);
                    $st = get_post_meta($ev->ID, '_tix_status', true);
                    $sl = get_post_meta($ev->ID, '_tix_status_label', true);
                    $pct = get_post_meta($ev->ID, '_tix_sold_percent', true);
                    $status_colors = [
                        'available' => '#2e7d32', 'few_tickets' => '#e65100',
                        'sold_out' => '#b32d2e', 'cancelled' => '#616161', 'postponed' => '#7b1fa2',
                    ];
                    $sc = $status_colors[$st] ?? '#666';
                ?>
                <div class="tix-dw-event">
                    <div>
                        <div class="tix-dw-event-name"><a href="<?php echo get_edit_post_link($ev->ID); ?>"><?php echo esc_html($ev->post_title); ?></a></div>
                        <div class="tix-dw-event-meta"><?php echo $ds ? date_i18n('d.m.Y', strtotime($ds)) : ''; ?><?php echo $loc ? ' · ' . esc_html($loc) : ''; ?></div>
                    </div>
                    <div class="tix-dw-event-right">
                        <?php if ($sl): ?>
                            <div class="tix-dw-event-status" style="color: <?php echo $sc; ?>"><?php echo esc_html($sl); ?></div>
                        <?php endif; ?>
                        <?php if ($pct !== '' && $pct !== false): ?>
                            <div class="tix-dw-event-meta"><?php echo intval($pct); ?>% verkauft</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <p class="tix-dw-empty">Keine kommenden Events.</p>
            <?php endif; ?>
        </div>
        <?php
    }
    public static function deactivate() {
        $ts = wp_next_scheduled('tix_presale_check');
        if ($ts) wp_unschedule_event($ts, 'tix_presale_check');
    }
}
