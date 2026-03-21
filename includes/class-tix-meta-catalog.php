<?php
if (!defined('ABSPATH')) exit;

class TIX_Meta_Catalog {

    public static function init() {
        $s = tix_get_settings();
        if (empty($s['meta_catalog_enabled'])) return;

        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('save_post_event', [__CLASS__, 'invalidate_cache']);
    }

    public static function register_routes() {
        register_rest_route('tixomat/v1', '/meta-feed', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'render_feed'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function render_feed(\WP_REST_Request $request) {
        $s = tix_get_settings();
        $key = $request->get_param('key');
        $expected_key = $s['meta_feed_key'] ?? '';

        if (empty($expected_key) || $key !== $expected_key) {
            return new \WP_REST_Response(['error' => 'Invalid key'], 403);
        }

        // Check cache
        $cached = get_transient('tix_meta_feed_cache');
        if ($cached !== false) {
            return new \WP_REST_Response(null, 200, [
                'Content-Type' => 'application/xml; charset=utf-8',
                'X-Cache'      => 'HIT',
            ]);
        }

        $xml = self::generate_feed();

        // Cache for 1 hour
        set_transient('tix_meta_feed_cache', $xml, HOUR_IN_SECONDS);

        // Output raw XML
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Cache: MISS');
        echo $xml;
        exit;
    }

    private static function generate_feed() {
        $events = get_posts([
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_tix_tickets_enabled', 'value' => '1'],
                [
                    'relation' => 'OR',
                    ['key' => '_tix_is_past', 'compare' => 'NOT EXISTS'],
                    ['key' => '_tix_is_past', 'value' => '0'],
                ],
            ],
            'orderby'  => 'meta_value',
            'meta_key' => '_tix_date_start',
            'order'    => 'ASC',
        ]);

        $site_name = get_bloginfo('name');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= '  <title>' . esc_xml($site_name) . ' Events</title>' . "\n";
        $xml .= '  <link href="' . esc_url(home_url()) . '"/>' . "\n";
        $xml .= '  <updated>' . gmdate('c') . '</updated>' . "\n";

        foreach ($events as $event) {
            $id          = $event->ID;
            $title       = get_the_title($id);
            $description = wp_strip_all_tags(get_post_meta($id, '_tix_info_description', true) ?: $event->post_excerpt);
            $link        = get_permalink($id);
            $image       = get_the_post_thumbnail_url($id, 'large') ?: '';
            $date_start  = get_post_meta($id, '_tix_date_start', true);
            $time_start  = get_post_meta($id, '_tix_time_start', true);
            $location    = get_post_meta($id, '_tix_location', true);
            $address     = get_post_meta($id, '_tix_address', true);
            $categories  = get_post_meta($id, '_tix_ticket_categories', true);
            $status      = get_post_meta($id, '_tix_status', true) ?: 'available';
            $price_min   = get_post_meta($id, '_tix_price_min', true);
            $price_max   = get_post_meta($id, '_tix_price_max', true);

            // Organizer
            $org_id = get_post_meta($id, '_tix_organizer_id', true);
            $brand  = $org_id ? get_the_title($org_id) : $site_name;

            // Availability mapping
            $availability = 'in stock';
            if ($status === 'sold_out') $availability = 'out of stock';
            elseif ($status === 'few_tickets') $availability = 'in stock';

            // Category term
            $terms = get_the_terms($id, 'event_category');
            $category_name = ($terms && !is_wp_error($terms)) ? $terms[0]->name : 'Event';

            // If we have ticket categories, create one item per category
            if (is_array($categories) && !empty($categories)) {
                foreach ($categories as $i => $cat) {
                    $cat_name  = $cat['name'] ?? 'Ticket';
                    $cat_price = floatval($cat['price'] ?? 0);
                    $cat_stock = ($cat['sold_out'] ?? false) ? 'out of stock' : 'in stock';

                    $xml .= self::feed_entry(
                        'event_' . $id . '_' . $i,
                        $title . ' — ' . $cat_name,
                        $description,
                        $link,
                        $image,
                        $cat_price,
                        $cat_stock,
                        $brand,
                        $category_name,
                        $date_start,
                        $location,
                        $cat_name
                    );
                }
            } else {
                // Single item
                $xml .= self::feed_entry(
                    'event_' . $id,
                    $title,
                    $description,
                    $link,
                    $image,
                    floatval($price_min),
                    $availability,
                    $brand,
                    $category_name,
                    $date_start,
                    $location,
                    ''
                );
            }
        }

        $xml .= '</feed>';
        return $xml;
    }

    private static function feed_entry($id, $title, $desc, $link, $image, $price, $availability, $brand, $category, $date, $location, $ticket_type) {
        $xml  = "  <entry>\n";
        $xml .= '    <g:id>' . esc_xml($id) . "</g:id>\n";
        $xml .= '    <g:title>' . esc_xml($title) . "</g:title>\n";
        $xml .= '    <g:description>' . esc_xml(mb_substr($desc, 0, 500)) . "</g:description>\n";
        $xml .= '    <g:link>' . esc_url($link) . "</g:link>\n";
        if ($image) {
            $xml .= '    <g:image_link>' . esc_url($image) . "</g:image_link>\n";
        }
        $xml .= '    <g:price>' . number_format($price, 2, '.', '') . ' EUR</g:price>' . "\n";
        $xml .= '    <g:availability>' . esc_xml($availability) . "</g:availability>\n";
        $xml .= '    <g:condition>new</g:condition>' . "\n";
        $xml .= '    <g:brand>' . esc_xml($brand) . "</g:brand>\n";
        $xml .= '    <g:google_product_category>Entertainment &gt; Events</g:google_product_category>' . "\n";
        $xml .= '    <g:custom_label_0>' . esc_xml($date) . "</g:custom_label_0>\n";
        $xml .= '    <g:custom_label_1>' . esc_xml($location) . "</g:custom_label_1>\n";
        $xml .= '    <g:custom_label_2>' . esc_xml($category) . "</g:custom_label_2>\n";
        $xml .= '    <g:custom_label_3>' . esc_xml($availability) . "</g:custom_label_3>\n";
        if ($ticket_type) {
            $xml .= '    <g:custom_label_4>' . esc_xml($ticket_type) . "</g:custom_label_4>\n";
        }
        $xml .= "  </entry>\n";
        return $xml;
    }

    public static function invalidate_cache() {
        delete_transient('tix_meta_feed_cache');
    }
}
