<?php
if (!defined('ABSPATH')) exit;

class TIX_Meta_Pixel {

    private static $pixel_id = '';
    private static $settings = [];

    public static function init() {
        self::$settings = tix_get_settings();
        self::$pixel_id = self::$settings['meta_pixel_id'] ?? '';

        if (empty(self::$pixel_id) || empty(self::$settings['meta_pixel_enabled'])) return;

        // Frontend pixel
        add_action('wp_head', [__CLASS__, 'inject_pixel'], 1);
        add_action('wp_footer', [__CLASS__, 'inject_event_data'], 99);

        // CAPI: server-side Purchase event
        if (!empty(self::$settings['meta_capi_enabled']) && !empty(self::$settings['meta_access_token'])) {
            add_action('woocommerce_checkout_order_processed', [__CLASS__, 'capi_purchase'], 30, 1);
        }

        // AJAX: store event_id from browser for deduplication
        add_action('wp_ajax_tix_meta_store_event_id', [__CLASS__, 'ajax_store_event_id']);
        add_action('wp_ajax_nopriv_tix_meta_store_event_id', [__CLASS__, 'ajax_store_event_id']);

        // AJAX: test pixel
        add_action('wp_ajax_tix_meta_test_pixel', [__CLASS__, 'ajax_test_pixel']);
    }

    /**
     * Inject Meta Pixel base code in <head>
     */
    public static function inject_pixel() {
        $s = self::$settings;
        $pixel_id = esc_js(self::$pixel_id);
        $consent_mode = $s['meta_consent_mode'] ?? 'always';
        $consent_cookie = esc_js($s['meta_consent_cookie'] ?? 'cookie_consent');

        if ($consent_mode === 'consent_required') {
            // Deferred loading: only init after consent
            ?>
            <script>
            window.tixMetaPixelId = '<?php echo $pixel_id; ?>';
            window.tixMetaConsentCookie = '<?php echo $consent_cookie; ?>';
            (function(){
                function hasCookie(name){return document.cookie.split(';').some(function(c){return c.trim().indexOf(name+'=')===0})}
                function loadPixel(){
                    if(window.tixMetaPixelLoaded)return;
                    window.tixMetaPixelLoaded=true;
                    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
                    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
                    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
                    (window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
                    fbq('init','<?php echo $pixel_id; ?>');
                    fbq('track','PageView');
                    document.dispatchEvent(new Event('tix:metaPixelReady'));
                }
                if(hasCookie(window.tixMetaConsentCookie)){loadPixel()}
                document.addEventListener('tix:consentGranted',loadPixel);
                // Check common consent plugins
                if(window.Cookiebot){window.addEventListener('CookiebotOnAccept',function(){if(Cookiebot.consent.marketing)loadPixel()})}
                if(window.borlabsCookieConfig){document.addEventListener('borlabs-cookie-consent-saved',function(){loadPixel()})}
            })();
            </script>
            <?php
        } else {
            // Always load
            ?>
            <script>
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
            (window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init','<?php echo $pixel_id; ?>');
            fbq('track','PageView');
            window.tixMetaPixelLoaded=true;
            window.tixMetaPixelId='<?php echo $pixel_id; ?>';
            </script>
            <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo $pixel_id; ?>&ev=PageView&noscript=1" /></noscript>
            <?php
        }
    }

    /**
     * Inject event-specific pixel data in footer
     */
    public static function inject_event_data() {
        $data = ['ajaxUrl' => admin_url('admin-ajax.php')];

        // ViewContent on event pages
        if (is_singular('event')) {
            global $post;
            $price_min = get_post_meta($post->ID, '_tix_price_min', true);
            $date_start = get_post_meta($post->ID, '_tix_date_start', true);
            $location = get_post_meta($post->ID, '_tix_location', true);
            $cats = get_post_meta($post->ID, '_tix_ticket_categories', true);
            $status = get_post_meta($post->ID, '_tix_status', true) ?: 'available';

            $data['viewContent'] = [
                'content_name'     => get_the_title($post->ID),
                'content_ids'      => [$post->ID],
                'content_type'     => 'product',
                'value'            => floatval($price_min),
                'currency'         => 'EUR',
                'event_date'       => $date_start,
                'event_location'   => $location,
                'availability'     => $status,
                'num_categories'   => is_array($cats) ? count($cats) : 0,
            ];
        }

        // InitiateCheckout on checkout page
        if (function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received')) {
            $cart = WC()->cart;
            if ($cart) {
                $data['initiateCheckout'] = [
                    'value'     => floatval($cart->get_total('edit')),
                    'currency'  => 'EUR',
                    'num_items' => $cart->get_cart_contents_count(),
                ];
            }
        }

        // Purchase on thank-you page
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            global $wp;
            $order_id = absint($wp->query_vars['order-received'] ?? 0);
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order && !$order->get_meta('_tix_meta_pixel_fired')) {
                    $event_ids = [];
                    $content_ids = [];
                    foreach ($order->get_items() as $item) {
                        $product_id = $item->get_product_id();
                        $event_id = get_post_meta($product_id, '_tix_event_id', true);
                        if ($event_id) $event_ids[] = intval($event_id);
                        $content_ids[] = $product_id;
                    }
                    $event_id_dedup = 'tix_purchase_' . $order_id . '_' . wp_generate_password(8, false);
                    $data['purchase'] = [
                        'value'       => floatval($order->get_total()),
                        'currency'    => $order->get_currency(),
                        'content_ids' => array_unique($content_ids),
                        'event_ids'   => array_unique($event_ids),
                        'num_items'   => $order->get_item_count(),
                        'order_id'    => $order_id,
                        'event_id'    => $event_id_dedup,
                    ];
                    $order->update_meta_data('_tix_meta_pixel_fired', 1);
                    $order->update_meta_data('_tix_meta_event_id', $event_id_dedup);
                    $order->save();
                }
            }
        }

        if (count($data) > 1) {
            ?>
            <script>
            (function(){
                var d=<?php echo wp_json_encode($data); ?>;
                function fire(){
                    if(typeof fbq==='undefined')return;
                    var eid=function(n){return 'tix_'+n+'_'+Date.now()+'_'+Math.random().toString(36).substr(2,6)};
                    if(d.viewContent){fbq('track','ViewContent',d.viewContent,{eventID:eid('vc')})}
                    if(d.initiateCheckout){fbq('track','InitiateCheckout',d.initiateCheckout,{eventID:eid('ic')})}
                    if(d.purchase){
                        fbq('track','Purchase',{
                            value:d.purchase.value,
                            currency:d.purchase.currency,
                            content_ids:d.purchase.content_ids,
                            content_type:'product',
                            num_items:d.purchase.num_items
                        },{eventID:d.purchase.event_id});
                    }
                }
                if(window.tixMetaPixelLoaded){fire()}
                else{document.addEventListener('tix:metaPixelReady',fire)}
            })();
            </script>
            <?php
        }
    }

    /**
     * Server-side CAPI Purchase event
     */
    public static function capi_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $s = self::$settings;
        $pixel_id = $s['meta_pixel_id'] ?? '';
        $token = $s['meta_access_token'] ?? '';
        if (empty($pixel_id) || empty($token)) return;

        // Get event_id for deduplication (set by browser pixel on thank-you page)
        // If not yet set, generate one
        $event_id = $order->get_meta('_tix_meta_event_id');
        if (empty($event_id)) {
            $event_id = 'tix_purchase_' . $order_id . '_' . wp_generate_password(8, false);
            $order->update_meta_data('_tix_meta_event_id', $event_id);
            $order->save();
        }

        // Collect content IDs
        $content_ids = [];
        foreach ($order->get_items() as $item) {
            $content_ids[] = (string) $item->get_product_id();
        }

        // Build user data (hashed)
        $user_data = [
            'client_ip_address' => self::get_client_ip(),
            'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        $billing_email = $order->get_billing_email();
        if ($billing_email) $user_data['em'] = [hash('sha256', strtolower(trim($billing_email)))];

        $billing_phone = $order->get_billing_phone();
        if ($billing_phone) $user_data['ph'] = [hash('sha256', preg_replace('/[^0-9]/', '', $billing_phone))];

        $fn = $order->get_billing_first_name();
        if ($fn) $user_data['fn'] = [hash('sha256', strtolower(trim($fn)))];

        $ln = $order->get_billing_last_name();
        if ($ln) $user_data['ln'] = [hash('sha256', strtolower(trim($ln)))];

        $city = $order->get_billing_city();
        if ($city) $user_data['ct'] = [hash('sha256', strtolower(trim($city)))];

        $zip = $order->get_billing_postcode();
        if ($zip) $user_data['zp'] = [hash('sha256', trim($zip))];

        $country = $order->get_billing_country();
        if ($country) $user_data['country'] = [hash('sha256', strtolower(trim($country)))];

        // fbc + fbp cookies
        if (!empty($_COOKIE['_fbc'])) $user_data['fbc'] = sanitize_text_field($_COOKIE['_fbc']);
        if (!empty($_COOKIE['_fbp'])) $user_data['fbp'] = sanitize_text_field($_COOKIE['_fbp']);

        $event_data = [
            'event_name'    => 'Purchase',
            'event_time'    => time(),
            'event_id'      => $event_id,
            'action_source' => 'website',
            'event_source_url' => $order->get_checkout_order_received_url(),
            'user_data'     => $user_data,
            'custom_data'   => [
                'value'        => floatval($order->get_total()),
                'currency'     => $order->get_currency(),
                'content_ids'  => $content_ids,
                'content_type' => 'product',
                'num_items'    => $order->get_item_count(),
                'order_id'     => (string) $order_id,
            ],
        ];

        $payload = ['data' => [$event_data]];

        $test_code = $s['meta_test_event_code'] ?? '';
        if (!empty($test_code)) {
            $payload['test_event_code'] = $test_code;
        }

        // Fire async
        wp_schedule_single_event(time(), 'tix_meta_capi_send', [$pixel_id, $token, $payload]);
        spawn_cron();

        // Also store in our conversions table
        self::store_conversion($order);
    }

    /**
     * Actually send the CAPI request (called by cron)
     */
    public static function send_capi_event($pixel_id, $token, $payload) {
        $url = 'https://graph.facebook.com/v21.0/' . $pixel_id . '/events?access_token=' . $token;

        $response = wp_remote_post($url, [
            'timeout'  => 15,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('[TIX Meta CAPI] Error: ' . $response->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                error_log('[TIX Meta CAPI] HTTP ' . $code . ': ' . wp_remote_retrieve_body($response));
            }
        }
    }

    /**
     * Store conversion in our tracking table
     */
    private static function store_conversion($order) {
        global $wpdb;
        $table = $wpdb->prefix . 'tix_meta_conversions';

        // Get event IDs from order items
        $event_ids = [];
        foreach ($order->get_items() as $item) {
            $eid = get_post_meta($item->get_product_id(), '_tix_event_id', true);
            if ($eid) $event_ids[] = intval($eid);
        }

        // Get UTM data from order meta or campaign tracking
        $utm_source = $order->get_meta('_tix_campaign_source') ?: '';
        $utm_campaign = $order->get_meta('_tix_campaign_name') ?: '';
        $utm_content = $order->get_meta('_tix_campaign_content') ?: '';
        $fbclid = sanitize_text_field($_COOKIE['_fbc'] ?? '');

        foreach (array_unique($event_ids) ?: [0] as $event_id) {
            $wpdb->insert($table, [
                'event_post_id'   => $event_id,
                'order_id'        => $order->get_id(),
                'meta_event_name' => 'Purchase',
                'value'           => floatval($order->get_total()),
                'currency'        => $order->get_currency(),
                'utm_source'      => $utm_source,
                'utm_campaign'    => $utm_campaign,
                'utm_content'     => $utm_content,
                'fbclid'          => $fbclid,
                'event_id'        => $order->get_meta('_tix_meta_event_id') ?: '',
                'created_at'      => current_time('mysql'),
            ], ['%d','%d','%s','%f','%s','%s','%s','%s','%s','%s','%s']);
        }
    }

    /**
     * AJAX: Store event_id from browser for dedup
     */
    public static function ajax_store_event_id() {
        $order_id = intval($_POST['order_id'] ?? 0);
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        if ($order_id && $event_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_tix_meta_event_id', $event_id);
                $order->save();
            }
        }
        wp_send_json_success();
    }

    /**
     * AJAX: Test pixel connection
     */
    public static function ajax_test_pixel() {
        check_ajax_referer('tix_admin_nonce', 'nonce');

        $s = tix_get_settings();
        $pixel_id = $s['meta_pixel_id'] ?? '';
        $token = $s['meta_access_token'] ?? '';

        if (empty($pixel_id) || empty($token)) {
            wp_send_json_error(['message' => 'Pixel-ID oder Access Token fehlt.']);
        }

        $payload = [
            'data' => [[
                'event_name'    => 'PageView',
                'event_time'    => time(),
                'event_id'      => 'test_' . wp_generate_password(8, false),
                'action_source' => 'website',
                'event_source_url' => home_url(),
                'user_data'     => [
                    'client_ip_address' => self::get_client_ip(),
                    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Tixomat Test',
                ],
            ]],
        ];

        $test_code = $s['meta_test_event_code'] ?? '';
        if (!empty($test_code)) $payload['test_event_code'] = $test_code;

        $url = 'https://graph.facebook.com/v21.0/' . $pixel_id . '/events?access_token=' . $token;
        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            wp_send_json_success([
                'message' => 'Test-Event erfolgreich gesendet! Prüfe den Meta Events Manager.',
                'events_received' => $body['events_received'] ?? 0,
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Fehler: ' . ($body['error']['message'] ?? 'HTTP ' . $code),
            ]);
        }
    }

    /**
     * Create DB table
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'tix_meta_conversions';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED AUTO_INCREMENT,
            event_post_id BIGINT UNSIGNED DEFAULT 0,
            order_id BIGINT UNSIGNED DEFAULT 0,
            meta_event_name VARCHAR(50) NOT NULL DEFAULT '',
            value DECIMAL(10,2) DEFAULT 0,
            currency VARCHAR(3) DEFAULT 'EUR',
            utm_source VARCHAR(100) DEFAULT '',
            utm_campaign VARCHAR(255) DEFAULT '',
            utm_content VARCHAR(255) DEFAULT '',
            fbclid VARCHAR(255) DEFAULT '',
            event_id VARCHAR(100) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_event (event_post_id),
            INDEX idx_order (order_id),
            INDEX idx_created (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function get_client_ip() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = explode(',', $_SERVER[$h])[0];
                return trim($ip);
            }
        }
        return '127.0.0.1';
    }
}

// Cron handler for async CAPI
add_action('tix_meta_capi_send', ['TIX_Meta_Pixel', 'send_capi_event'], 10, 3);
