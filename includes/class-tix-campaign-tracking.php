<?php
if (!defined('ABSPATH')) exit;

/**
 * TIX_Campaign_Tracking – Kampagnen-Tracking per URL-Parameter.
 *
 * Trackt Besucher-Quellen (?tix_src=instagram) und ordnet
 * Ticket-Verkäufe dem jeweiligen Marketing-Kanal zu.
 * Cookie-basierte First-Touch Attribution + aggregierte Pageviews.
 *
 * @since 1.28.92
 */
class TIX_Campaign_Tracking {

    const COOKIE_NAME = 'tix_campaign';
    const TABLE_NAME  = 'tixomat_campaign_views';

    /**
     * Vordefinierte Kanäle.
     */
    const CHANNELS = [
        // Direct
        'direct'             => 'Direkt-Aufruf',
        'referral'           => 'Verweis (sonstige)',

        // Search engines (organic)
        'google_organic'     => 'Google (organisch)',
        'bing_organic'       => 'Bing (organisch)',
        'duckduckgo_organic' => 'DuckDuckGo',
        'ecosia_organic'     => 'Ecosia',
        'startpage_organic'  => 'Startpage',
        'yahoo_organic'      => 'Yahoo',
        'yandex_organic'     => 'Yandex',

        // Paid ads
        'google_ads'         => 'Google Ads',
        'facebook_ads'       => 'Facebook Ads',
        'meta_ads'           => 'Meta Ads',
        'tiktok_ads'         => 'TikTok Ads',

        // Social
        'instagram'          => 'Instagram',
        'tiktok'             => 'TikTok',
        'facebook'           => 'Facebook',
        'linkedin'           => 'LinkedIn',
        'xing'               => 'Xing',
        'youtube'            => 'YouTube',
        'twitter'            => 'X (Twitter)',
        'pinterest'          => 'Pinterest',
        'reddit'             => 'Reddit',
        'snapchat'           => 'Snapchat',
        'threads'            => 'Threads',

        // Messenger
        'whatsapp'           => 'WhatsApp',
        'telegram'           => 'Telegram',
        'signal'             => 'Signal',
        'messenger'          => 'Messenger',

        // Email / Sonstige
        'email'              => 'E-Mail',
        'flyer'              => 'Plakat/Flyer',
        'website'            => 'Webseite',
        'podcast'            => 'Podcast',

        // Event-Plattformen
        'eventim'            => 'Eventim',
        'eventbrite'         => 'Eventbrite',
        'ticketmaster'       => 'Ticketmaster',
    ];

    public static function init() {
        // AJAX: Pageview tracking
        add_action('wp_ajax_tix_campaign_pageview', [__CLASS__, 'handle_pageview']);
        add_action('wp_ajax_nopriv_tix_campaign_pageview', [__CLASS__, 'handle_pageview']);

        // Frontend assets
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // WooCommerce: Order-Attribution
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_order_meta'], 10, 2);
            add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_order_meta']);
        }

        // Native-Order: Cookie-Daten + Referrer beim Anlegen speichern
        add_action('tix_native_order_created', [__CLASS__, 'save_native_order_meta'], 10, 1);
    }

    // ──────────────────────────────────────────
    // DB-Tabelle
    // ──────────────────────────────────────────

    public static function get_table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::get_table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id         BIGINT UNSIGNED AUTO_INCREMENT,
            event_id   BIGINT UNSIGNED NOT NULL,
            source     VARCHAR(50)  NOT NULL DEFAULT 'direct',
            campaign   VARCHAR(100) NOT NULL DEFAULT '',
            content    VARCHAR(100) NOT NULL DEFAULT '',
            view_date  DATE         NOT NULL,
            views      INT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY event_source_camp_date (event_id, source, campaign, content, view_date),
            KEY idx_source (source),
            KEY idx_view_date (view_date)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ──────────────────────────────────────────
    // Kanäle
    // ──────────────────────────────────────────

    /**
     * Alle Kanäle: Predefined + Custom aus Settings.
     */
    public static function get_all_channels() {
        $channels = self::CHANNELS;

        if (function_exists('tix_get_settings')) {
            $custom_json = tix_get_settings('campaign_custom_channels') ?: '[]';
            $custom = json_decode($custom_json, true);
            if (is_array($custom)) {
                foreach ($custom as $ch) {
                    if (!empty($ch['slug']) && !empty($ch['label'])) {
                        $channels[sanitize_key($ch['slug'])] = sanitize_text_field($ch['label']);
                    }
                }
            }
        }

        return $channels;
    }

    /**
     * Label für einen Channel-Slug.
     */
    public static function get_channel_label($slug) {
        $channels = self::get_all_channels();
        return $channels[$slug] ?? ucfirst($slug);
    }

    // ──────────────────────────────────────────
    // Frontend Assets
    // ──────────────────────────────────────────

    public static function enqueue_assets() {
        // Auf allen Frontend-Seiten laden — Cookie wird beim ersten Besuch gesetzt
        // (z.B. Homepage, Event-Liste, Blog-Beitrag). EventID nur auf Event-Seiten,
        // damit der Pageview-AJAX nur für Event-Statistiken zählt.
        if (is_admin()) return;

        $event_id = is_singular('event') ? get_the_ID() : 0;
        $s = function_exists('tix_get_settings') ? tix_get_settings() : [];
        $cookie_days = intval($s['campaign_cookie_days'] ?? 30);

        wp_enqueue_script(
            'tix-campaign-pixel',
            TIXOMAT_URL . 'assets/js/campaign-pixel.js',
            [],
            TIXOMAT_VERSION,
            true
        );

        wp_localize_script('tix-campaign-pixel', 'tixCampaign', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'eventId'    => $event_id, // 0 = keine Pageview-Aggregation, nur Cookie setzen
            'nonce'      => wp_create_nonce('tix_campaign'),
            'cookieDays' => $cookie_days,
            'cookieName' => self::COOKIE_NAME,
        ]);
    }

    // ──────────────────────────────────────────
    // AJAX: Pageview tracken
    // ──────────────────────────────────────────

    public static function handle_pageview() {
        check_ajax_referer('tix_campaign', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        $source   = sanitize_key(substr($_POST['source'] ?? 'direct', 0, 50));
        $campaign = sanitize_text_field(substr($_POST['campaign'] ?? '', 0, 100));
        $content  = sanitize_text_field(substr($_POST['content'] ?? '', 0, 100));

        if (!$event_id || !get_post($event_id)) {
            wp_send_json_error();
        }

        global $wpdb;
        $table = self::get_table();
        $today = current_time('Y-m-d');

        // INSERT ... ON DUPLICATE KEY UPDATE views = views + 1
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (event_id, source, campaign, content, view_date, views)
             VALUES (%d, %s, %s, %s, %s, 1)
             ON DUPLICATE KEY UPDATE views = views + 1",
            $event_id, $source, $campaign, $content, $today
        ));

        wp_send_json_success();
    }

    // ──────────────────────────────────────────
    // WooCommerce: Order-Meta speichern
    // ──────────────────────────────────────────

    public static function save_order_meta($order, $data) {
        $cookie_raw = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (empty($cookie_raw)) return;

        $cookie = json_decode(stripslashes(urldecode($cookie_raw)), true);
        if (!is_array($cookie)) return;

        $source  = sanitize_key($cookie['src'] ?? '');
        $camp    = sanitize_text_field($cookie['camp'] ?? '');
        $content = sanitize_text_field($cookie['content'] ?? '');

        if ($source) {
            $order->update_meta_data('_tix_campaign_source', $source);
        }
        if ($camp) {
            $order->update_meta_data('_tix_campaign_name', $camp);
        }
        if ($content) {
            $order->update_meta_data('_tix_campaign_content', $content);
        }
    }

    /**
     * Native-Order: speichert Campaign-Daten aus Cookie + Server-Side-Referrer.
     */
    public static function save_native_order_meta($tix_order_id) {
        if (!$tix_order_id) return;

        $cookie_raw = $_COOKIE[self::COOKIE_NAME] ?? '';
        $source = $campaign = $content = $medium = '';
        $cookie_referrer = '';

        if ($cookie_raw) {
            $cookie = json_decode(stripslashes(urldecode($cookie_raw)), true);
            if (is_array($cookie)) {
                $source   = sanitize_key($cookie['src']     ?? '');
                $campaign = sanitize_text_field($cookie['camp']    ?? '');
                $content  = sanitize_text_field($cookie['content'] ?? '');
                $medium   = sanitize_text_field($cookie['medium']  ?? '');
                $cookie_referrer = esc_url_raw($cookie['referrer'] ?? '');
            }
        }

        // Fallback: HTTP-Referrer aus Order-Submit-Request
        $http_referer = sanitize_text_field($_SERVER['HTTP_REFERER'] ?? '');

        if ($source) {
            update_post_meta($tix_order_id, '_tix_campaign_source', $source);
        }
        if ($campaign) {
            update_post_meta($tix_order_id, '_tix_campaign_name', $campaign);
        }
        if ($content) {
            update_post_meta($tix_order_id, '_tix_campaign_content', $content);
        }
        if ($medium) {
            update_post_meta($tix_order_id, '_tix_campaign_medium', $medium);
        }
        if ($cookie_referrer) {
            update_post_meta($tix_order_id, '_tix_campaign_referrer', $cookie_referrer);
        } elseif ($http_referer) {
            update_post_meta($tix_order_id, '_tix_campaign_referrer', $http_referer);
        }

        // Landing-Page (auf der der Cookie initial gesetzt wurde — falls trackbar)
        // Falls keine direkte Quelle ermittelbar, mark mit 'direct'
        if (!$source && !$cookie_referrer && !$http_referer) {
            update_post_meta($tix_order_id, '_tix_campaign_source', 'direct');
        }
    }

    // ──────────────────────────────────────────
    // Admin: Order-Detail Badge
    // ──────────────────────────────────────────

    public static function display_order_meta($order) {
        $source = $order->get_meta('_tix_campaign_source');
        if (!$source) return;

        $label    = self::get_channel_label($source);
        $campaign = $order->get_meta('_tix_campaign_name');
        $content  = $order->get_meta('_tix_campaign_content');

        echo '<p style="margin-top:12px"><strong style="display:block;margin-bottom:4px">Kampagne:</strong>';
        echo '<span style="display:inline-block;padding:3px 12px;border-radius:12px;font-size:12px;font-weight:600;background:#e0f2fe;color:#0369a1">';
        echo esc_html($label);
        echo '</span>';
        if ($campaign) {
            echo ' <span style="color:#64748b;font-size:12px">(' . esc_html($campaign) . ')</span>';
        }
        if ($content) {
            echo ' <span style="color:#94a3b8;font-size:11px">[' . esc_html($content) . ']</span>';
        }
        echo '</p>';
    }
}
