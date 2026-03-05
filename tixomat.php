<?php
/**
 * Plugin Name: Tixomat – Event & Ticket Management
 * Description: Zentrales Event-Management mit eigenem Ticketsystem. Optional mit Tickera-Integration.
 * Version: 1.28.2
 * Author: MDJ Veranstaltungs UG (haftungsbeschränkt)
 * Text Domain: tixomat
 */

if (!defined('ABSPATH')) exit;

define('TIXOMAT_VERSION', '1.28.2');
define('TIXOMAT_PATH', plugin_dir_path(__FILE__));
define('TIXOMAT_URL', plugin_dir_url(__FILE__));

// ── Immer laden (CPT, leichtgewichtig) ──
require_once TIXOMAT_PATH . 'includes/class-tix-cpt.php';
add_action('init', ['TIX_CPT', 'register']);

/**
 * Ticket-System Modus-Helper.
 * Prüft ob Tixomat eigene Tickets erstellen soll.
 */
function tix_use_own_tickets() {
    $mode = tix_get_settings('ticket_system') ?: 'tickera';
    return in_array($mode, ['standalone', 'both'], true);
}

/**
 * Ticket-System Modus-Helper.
 * Prüft ob Tickera-Integration aktiv sein soll.
 */
function tix_use_tickera() {
    $mode = tix_get_settings('ticket_system') ?: 'tickera';
    return in_array($mode, ['tickera', 'both'], true);
}

/**
 * Leichtgewichtige Settings-Funktion (kein Admin-Overhead)
 * Cached im static-Scope für den Request.
 */
function tix_get_settings($key = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = wp_parse_args(get_option('tix_settings', []), [
            // Texte
            'vat_text_selector'  => 'inkl. MwSt.',
            'vat_text_checkout'  => 'inkl. MwSt.',
            'btn_text_buy'       => 'Weiter zur Kasse',
            'btn_text_checkout'  => 'Jetzt bestellen',
            'empty_text'         => 'Dein Warenkorb ist leer.',
            'empty_link_text'    => 'Jetzt Tickets sichern',
            'shipping_text'      => 'Versand per E-Mail',
            // Toggles
            'skip_cart'             => 1,
            'force_email_shipping'  => 1,
            'checkout_steps'        => 0,
            'checkout_countdown'         => 0,
            'checkout_countdown_minutes' => 10,
            'show_company_field'    => 0,
            'show_coupon_selector'  => 1,
            // Upselling
            'show_upsell'           => 1,
            'upsell_count'          => 3,
            'upsell_heading'        => 'Das könnte dich auch interessieren',
            // URLs
            'terms_url'          => '',
            'privacy_url'        => '',
            'google_api_key'     => '',
            // E-Mail
            'email_logo_url'     => '',
            'email_brand_name'   => '',
            'email_footer_text'  => '',
            'email_reminder'     => 1,
            'email_followup'     => 1,
            'email_followup_url' => '',
            // Gemeinsam buchen
            'group_booking'      => 1,
            // Abandoned Cart
            'abandoned_cart_enabled' => 0,
            'abandoned_cart_delay'   => 30,
            'abandoned_cart_subject' => '',
            // Ticket-System
            'ticket_system'            => 'tickera', // tickera | standalone | both
            // Express Checkout
            'express_checkout_enabled' => 0,
            // Branding
            'branding_enabled'  => 1,
            'branding_url'      => 'https://mdj.events',
            // Sponsor (Thank-You)
            'sponsor_enabled'    => 0,
            'sponsor_image_url'  => '',
            'sponsor_logo_width' => 30,
            // Ticket-Template
            'ticket_template'    => '',
            // Strichcode (Barcode)
            'barcode_enabled'    => 0,
            // Charity / Soziales Projekt
            'charity_enabled'    => 0,
            // Support-System
            'support_enabled'    => 0,
            'support_categories'  => '',
            'support_chat_enabled' => 0,
            // Geführter Modus
            'wizard_enabled'     => 1,
        ]);
    }
    return $key !== null ? ($cache[$key] ?? null) : $cache;
}

/**
 * Branding-Footer HTML für Shortcodes.
 * Gibt leeren String zurück wenn deaktiviert.
 */
function tix_branding_footer() {
    $s = tix_get_settings();
    if (empty($s['branding_enabled'])) return '';
    static $css = false;
    $html = '';
    if (!$css) {
        $html .= '<style>.tix-branding{margin-top:16px;padding-top:12px;text-align:center;font-size:.8rem;opacity:.45;transition:opacity .2s}.tix-branding:hover{opacity:.7}.tix-branding a{color:inherit;text-decoration:none}.tix-branding a:hover{text-decoration:underline}</style>';
        $css = true;
    }
    $url  = $s['branding_url'] ?? '';
    $text = 'mit ♥️ entwickelt von MDJ.events';
    if ($url) {
        $html .= '<div class="tix-branding"><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . $text . '</a></div>';
    } else {
        $html .= '<div class="tix-branding"><span>' . $text . '</span></div>';
    }
    return $html;
}

// ── Metabox-Klasse global laden (get_active_phase() wird auch im Frontend benötigt) ──
require_once TIXOMAT_PATH . 'includes/class-tix-metabox.php';

// ── Saalplan-System (Admin + Frontend + AJAX) ──
require_once TIXOMAT_PATH . 'includes/class-tix-seatmap.php';
TIX_Seatmap::init();

// ── Custom Ticket-DB + Sync-Klassen ──
require_once TIXOMAT_PATH . 'includes/class-tix-ticket-db.php';
require_once TIXOMAT_PATH . 'includes/class-tix-sync-supabase.php';
require_once TIXOMAT_PATH . 'includes/class-tix-sync-airtable.php';

// ── DB-Tabellen bei Aktivierung ──
register_activation_hook(__FILE__, function() {
    TIX_Seatmap::create_table();
    if (class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled')) {
        TIX_Ticket_DB::create_table();
    }
});

// ── Statistiken (Admin + AJAX) ──
if (is_admin()) {
    require_once TIXOMAT_PATH . 'includes/class-tix-statistics.php';
    TIX_Statistics::init();
}

// ── Nur Admin (nicht AJAX) ──
if (is_admin() && !wp_doing_ajax()) {
    require_once TIXOMAT_PATH . 'includes/class-tix-sync.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-columns.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-cleanup.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-settings.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-calendar.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-series.php';

    add_action('add_meta_boxes', ['TIX_Metabox', 'register']);
    add_action('save_post_event', ['TIX_Metabox', 'save'], 10, 2);
    add_action('admin_enqueue_scripts', ['TIX_Metabox', 'enqueue_assets']);
    add_action('save_post_event', ['TIX_Sync', 'sync'], 20, 2);
    add_action('save_post_event', ['TIX_Series', 'on_save'], 25, 2);
    add_action('wp_trash_post',      ['TIX_Series', 'on_trash']);
    add_action('before_delete_post', ['TIX_Series', 'on_trash']);
    add_action('init', ['TIX_Cleanup', 'init']);
    add_action('init', ['TIX_Settings', 'init']);

    require_once TIXOMAT_PATH . 'includes/class-tix-docs.php';
    TIX_Docs::init();

    add_filter('manage_event_posts_columns', ['TIX_Columns', 'add']);
    add_action('manage_event_posts_custom_column', ['TIX_Columns', 'render'], 10, 2);
    add_filter('manage_tix_subscriber_posts_columns', ['TIX_Columns', 'add_subscriber_columns']);
    add_action('manage_tix_subscriber_posts_custom_column', ['TIX_Columns', 'render_subscriber_column'], 10, 2);
    add_action('restrict_manage_posts', ['TIX_Columns', 'subscriber_csv_button']);
    add_action('admin_post_tix_export_subscribers', ['TIX_Columns', 'subscriber_csv_export']);
    add_filter('manage_tix_abandoned_cart_posts_columns', ['TIX_Columns', 'add_abandoned_cart_columns']);
    add_action('manage_tix_abandoned_cart_posts_custom_column', ['TIX_Columns', 'render_abandoned_cart_column'], 10, 2);

    // Ticket-Spalten (Verkaufte Tickets – eigenes Ticketsystem)
    add_filter('manage_tix_ticket_posts_columns', ['TIX_Columns', 'add_ticket_columns']);
    add_action('manage_tix_ticket_posts_custom_column', ['TIX_Columns', 'render_ticket_column'], 10, 2);
    add_action('restrict_manage_posts', ['TIX_Columns', 'ticket_event_filter']);
    add_action('pre_get_posts', ['TIX_Columns', 'filter_ticket_query']);
    add_filter('post_row_actions', ['TIX_Columns', 'ticket_row_actions'], 10, 2);
    add_filter('bulk_actions-edit-tix_ticket', ['TIX_Columns', 'register_ticket_bulk_actions']);
    add_filter('handle_bulk_actions-edit-tix_ticket', ['TIX_Columns', 'handle_ticket_bulk_actions'], 10, 3);
    add_action('admin_notices', ['TIX_Columns', 'ticket_bulk_notices']);
    add_action('admin_notices', ['TIX_Columns', 'ticket_summary_bar']);
    add_action('admin_post_tix_export_tickets_csv', ['TIX_Columns', 'export_tickets_csv']);
    add_filter('manage_edit-tix_ticket_sortable_columns', ['TIX_Columns', 'sortable_ticket_columns']);
    add_action('pre_get_posts', ['TIX_Columns', 'extend_ticket_search']);

    // Event duplizieren + endgültig löschen
    add_filter('post_row_actions', ['TIX_Columns', 'duplicate_link'], 10, 2);
    add_action('admin_post_tix_duplicate_event', ['TIX_Columns', 'handle_duplicate']);
    add_action('admin_post_tix_force_delete_event', ['TIX_Columns', 'handle_force_delete']);
    add_action('admin_notices', ['TIX_Columns', 'force_delete_notice']);
    add_action('restrict_manage_posts', ['TIX_Columns', 'series_filter']);
    add_action('pre_get_posts', ['TIX_Columns', 'filter_series_query']);

    add_action('admin_notices', function() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'event' || $screen->base !== 'post') return;
        global $post;
        if (!$post) return;
        $log = get_transient('tix_sync_log_' . $post->ID);
        if (!$log || !is_array($log)) return;
        delete_transient('tix_sync_log_' . $post->ID);
        echo '<div class="notice notice-success is-dismissible tix-sync-notice"><p><strong>Tixomat – Sync:</strong></p><ul>';
        foreach ($log as $entry) echo '<li>' . esc_html($entry) . '</li>';
        echo '</ul></div>';
    });
}

// ── Admin AJAX (Gästeliste Check-in, E-Mail) ──
if (is_admin() && wp_doing_ajax()) {
    add_action('wp_ajax_tix_guest_checkin',         ['TIX_Metabox', 'ajax_guest_checkin']);
    add_action('wp_ajax_tix_guest_send_email',      ['TIX_Metabox', 'ajax_guest_send_email']);
    add_action('wp_ajax_tix_guest_send_all_emails', ['TIX_Metabox', 'ajax_guest_send_all_emails']);
    add_action('wp_ajax_tix_teilnehmer_csv',        ['TIX_Metabox', 'ajax_teilnehmer_csv']);
    add_action('wp_ajax_tix_create_location',       ['TIX_Metabox', 'ajax_create_location']);
    add_action('wp_ajax_tix_create_organizer',      ['TIX_Metabox', 'ajax_create_organizer']);

    // Ticket-Verwaltung (Verkaufte Tickets)
    add_action('wp_ajax_tix_ticket_resend',          ['TIX_Columns', 'ajax_resend_ticket']);
    add_action('wp_ajax_tix_ticket_resend_order',    ['TIX_Columns', 'ajax_resend_order_tickets']);
    add_action('wp_ajax_tix_ticket_toggle_status',   ['TIX_Columns', 'ajax_toggle_ticket_status']);

    // Daten-Sync (Supabase + Airtable)
    add_action('wp_ajax_tix_sync_test_connection', function() {
        check_ajax_referer('tix_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        $service = sanitize_text_field($_POST['service'] ?? '');
        if ($service === 'supabase' && class_exists('TIX_Sync_Supabase')) {
            wp_send_json(TIX_Sync_Supabase::test_connection());
        } elseif ($service === 'airtable' && class_exists('TIX_Sync_Airtable')) {
            wp_send_json(TIX_Sync_Airtable::test_connection());
        }
        wp_send_json_error(['message' => 'Unbekannter Service.']);
    });
    add_action('wp_ajax_tix_sync_all', function() {
        check_ajax_referer('tix_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);
        $service = sanitize_text_field($_POST['service'] ?? '');
        if ($service === 'supabase' && class_exists('TIX_Sync_Supabase')) {
            wp_send_json_success(TIX_Sync_Supabase::sync_batch(100));
        } elseif ($service === 'airtable' && class_exists('TIX_Sync_Airtable')) {
            wp_send_json_success(TIX_Sync_Airtable::sync_batch(100));
        }
        wp_send_json_error(['message' => 'Unbekannter Service.']);
    });
}

// ── Frontend + AJAX (Ticket-Selector, Checkout, FAQ, Calendar brauchen AJAX-Hooks) ──
if (!is_admin() || wp_doing_ajax()) {
    require_once TIXOMAT_PATH . 'includes/class-tix-ticket-selector.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-faq.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-calendar.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-upsell.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-my-tickets.php';

    require_once TIXOMAT_PATH . 'includes/class-tix-group-booking.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-checkin.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-ticket-transfer.php';

    add_action('init', ['TIX_Ticket_Selector', 'init']);
    add_action('init', ['TIX_FAQ', 'init']);
    add_action('init', ['TIX_Calendar', 'init']);
    add_action('init', ['TIX_Upsell', 'init']);
    add_action('init', ['TIX_My_Tickets', 'init']);
    add_action('init', ['TIX_Group_Booking', 'init']);
    add_action('init', ['TIX_Checkin', 'init']);
    add_action('init', ['TIX_Ticket_Transfer', 'init']);

    // Serien-Shortcode
    if (!class_exists('TIX_Series')) {
        require_once TIXOMAT_PATH . 'includes/class-tix-series.php';
    }
    add_shortcode('tix_series_dates', ['TIX_Series', 'render_dates_shortcode']);

    // Checkout: nur wenn WC aktiv
    add_action('init', function() {
        if (class_exists('WooCommerce')) {
            require_once TIXOMAT_PATH . 'includes/class-tix-checkout.php';
            TIX_Checkout::init();
        }
    });
}

// ── Nur Frontend (kein Admin, kein AJAX) ──
if (!is_admin()) {
    require_once TIXOMAT_PATH . 'includes/class-tix-frontend.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-settings.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-embed.php';
    add_action('init', ['TIX_Frontend', 'init']);
    add_action('wp_head', ['TIX_Settings', 'output_css'], 99);
    TIX_Embed::init();

    // Gästeliste: QR-Seite für Gäste (Self-Service)
    add_action('template_redirect', function() {
        if (!empty($_GET['tix_guest'])) {
            if (!class_exists('TIX_Checkin')) {
                require_once TIXOMAT_PATH . 'includes/class-tix-checkin.php';
            }
            TIX_Checkin::render_guest_qr_page();
        }
    }, 1);

    // Embed Cart-Transfer: Warenkorb aus Token wiederherstellen
    // Priority 2: nach Embed-Handler (1), vor redirect_canonical (10)
    add_action('template_redirect', function() {
        if (empty($_GET['tix_cart']) || !function_exists('WC') || !WC()->cart) return;

        $token = sanitize_text_field($_GET['tix_cart']);
        $data  = get_transient('tix_cart_transfer_' . $token);
        if (!$data || empty($data['items'])) {
            // Token ungültig/abgelaufen → trotzdem weiterleiten
            wp_safe_redirect(remove_query_arg('tix_cart'));
            exit;
        }

        // Token einmalig verbrauchen
        delete_transient('tix_cart_transfer_' . $token);

        // Warenkorb leeren und neu befüllen
        WC()->cart->empty_cart();

        foreach ($data['items'] as $item) {
            // ── Kombi-Ticket ──
            if (!empty($item['combo'])) {
                $combo_id    = sanitize_text_field($item['combo_id'] ?? '');
                $combo_label = sanitize_text_field($item['combo_label'] ?? '');
                $combo_price = floatval($item['combo_price'] ?? 0);
                $combo_qty   = intval($item['quantity'] ?? 0);
                $products    = $item['products'] ?? [];

                if (empty($combo_id) || $combo_qty <= 0 || empty($products)) continue;

                $group_id = 'combo_' . time() . '_' . wp_generate_password(6, false, false);
                $item_count = count($products);

                foreach ($products as $p) {
                    $p_id = intval($p['product_id'] ?? 0);
                    if ($p_id <= 0) continue;

                    $cart_item_data = [
                        '_tix_combo' => [
                            'group_id'    => $group_id,
                            'combo_id'    => $combo_id,
                            'label'       => $combo_label,
                            'total_price' => $combo_price,
                            'item_count'  => $item_count,
                        ],
                    ];
                    WC()->cart->add_to_cart($p_id, $combo_qty, 0, [], $cart_item_data);
                }
                continue;
            }

            // ── Einzel- / Bundle-Ticket ──
            $product_id = intval($item['product_id'] ?? 0);
            $quantity   = intval($item['quantity'] ?? 0);
            if ($product_id <= 0 || $quantity <= 0) continue;

            $cart_item_data = [];
            if (!empty($item['bundle'])) {
                $cart_item_data['_tix_bundle'] = [
                    'buy'   => intval($item['bundle_buy'] ?? 0),
                    'pay'   => intval($item['bundle_pay'] ?? 0),
                    'label' => sanitize_text_field($item['bundle_label'] ?? ''),
                ];
            }

            WC()->cart->add_to_cart($product_id, $quantity, 0, [], $cart_item_data);
        }

        // Gutschein anwenden
        if (!empty($data['coupon'])) {
            WC()->cart->apply_coupon($data['coupon']);
        }

        // Session sofort in DB speichern (statt erst bei shutdown)
        if (WC()->session) {
            WC()->session->save_data();
        }

        // Sauberer Redirect ohne Token-Parameter
        wp_safe_redirect(remove_query_arg('tix_cart'));
        exit;
    }, 2);

    // Defer für unsere Scripts (kein Render-Blocking)
    add_filter('script_loader_tag', function($tag, $handle) {
        if (in_array($handle, ['tix-ticket-selector', 'tix-faq', 'tix-checkout', 'tix-calendar', 'tix-my-tickets', 'tix-qr', 'tix-ticket-img', 'tix-group-booking', 'tix-checkin', 'tix-jsqr', 'tix-support-front', 'tix-support-chat'])) {
            if (strpos($tag, 'defer') === false) {
                return str_replace(' src', ' defer src', $tag);
            }
        }
        return $tag;
    }, 10, 2);
}

// ── Eigenes Ticket-System (CPT + Hooks, Frontend + Admin + AJAX) ──
require_once TIXOMAT_PATH . 'includes/class-tix-tickets.php';
TIX_Tickets::init();

// ── Sync bei Ticket-Check-in triggern ──
add_action('tix_ticket_checked_in', function($ticket_id) {
    $code = get_post_meta($ticket_id, '_tix_ticket_code', true);
    if (empty($code)) return;
    $row = class_exists('TIX_Ticket_DB') ? TIX_Ticket_DB::get_by_code($code) : null;
    if (!$row) return;
    $data = (array) $row;
    if (TIX_Sync_Supabase::is_enabled()) TIX_Sync_Supabase::sync_ticket($data);
    if (TIX_Sync_Airtable::is_enabled()) TIX_Sync_Airtable::sync_ticket($data);
});

// ── Ticket-Template-System (GD-Rendering + visueller Editor) ──
require_once TIXOMAT_PATH . 'includes/class-tix-ticket-template.php';
require_once TIXOMAT_PATH . 'includes/class-tix-ticket-template-cpt.php';
TIX_Ticket_Template::init();
TIX_Ticket_Template_CPT::init();

// ── E-Mails: immer laden (WP Core-Mails + WC-Mails wenn aktiv) ──
require_once TIXOMAT_PATH . 'includes/class-tix-emails.php';
TIX_Emails::init();

// ── Support-System (CRM + Kunden-Portal) ──
require_once TIXOMAT_PATH . 'includes/class-tix-support.php';
TIX_Support::init();

// ── WooCommerce-Integration: Frontend + AJAX (Gruppenrabatt, Dynamische Preise) ──
if (!is_admin() || defined('DOING_AJAX')) {
    require_once TIXOMAT_PATH . 'includes/class-tix-group-discount.php';
    require_once TIXOMAT_PATH . 'includes/class-tix-dynamic-pricing.php';
    TIX_Group_Discount::init();
    TIX_Dynamic_Pricing::init();
}

// ── Cron: Intervall global registrieren (auch für wp-cron.php) ──
add_filter('cron_schedules', function($schedules) {
    $schedules['tix_every_10min'] = [
        'interval' => 600,
        'display'  => 'Alle 10 Minuten (Tixomat)',
    ];
    return $schedules;
});
if (!wp_next_scheduled('tix_presale_check')) {
    wp_schedule_event(time(), 'tix_every_10min', 'tix_presale_check');
}

add_action('tix_presale_check', function() {
    if (!class_exists('TIX_Frontend')) {
        require_once TIXOMAT_PATH . 'includes/class-tix-frontend.php';
    }
    if (!class_exists('TIX_Metabox')) {
        require_once TIXOMAT_PATH . 'includes/class-tix-metabox.php';
    }
    if (!class_exists('TIX_Sync')) {
        require_once TIXOMAT_PATH . 'includes/class-tix-sync.php';
    }
    TIX_Frontend::check_presale_end();
    TIX_Frontend::check_past_events();
    TIX_Frontend::check_pricing_phases();
});

// ── Dashboard Widget (Admin) ──
add_action('wp_dashboard_setup', function() {
    if (!class_exists('TIX_Frontend')) {
        require_once TIXOMAT_PATH . 'includes/class-tix-frontend.php';
    }
    TIX_Frontend::register_dashboard_widget();
});

// ── Login: Language Switcher entfernen ──
add_filter('login_display_language_dropdown', '__return_false');

// Deaktivierung
register_deactivation_hook(__FILE__, function() {
    $ts = wp_next_scheduled('tix_presale_check');
    if ($ts) wp_unschedule_event($ts, 'tix_presale_check');
});

// ── Zeilenumbrüche in Info-Sektionen: wpautop-Fallback beim Lesen ──
add_filter('get_post_metadata', function($value, $post_id, $meta_key, $single) {
    static $bypass = false;
    if ($bypass) return $value;

    static $keys = [
        '_tix_info_description', '_tix_info_lineup', '_tix_info_specials', '_tix_info_extra_info',
        '_tix_description', '_tix_lineup', '_tix_specials', '_tix_extra_info',
    ];
    if (!in_array($meta_key, $keys, true)) return $value;

    $bypass = true;
    $raw = get_post_meta($post_id, $meta_key, true);
    $bypass = false;

    if ($raw && is_string($raw) && strpos($raw, '<p>') === false) {
        $raw = wpautop($raw);
    }

    return $single ? $raw : [$raw];
}, 10, 4);

// ── Heute / Morgen Prefix für Datum-Anzeige ──
add_filter('get_post_metadata', function($value, $post_id, $meta_key, $single) {
    $prefixed_keys = [
        '_tix_date_display',
        '_tix_date_only',
        '_tix_date_start_formatted',
        '_tix_date_multiline',
    ];
    if (!in_array($meta_key, $prefixed_keys, true)) return $value;

    static $bypass_date = false;
    if ($bypass_date) return $value;

    $bypass_date = true;
    $date_start = get_post_meta($post_id, '_tix_date_start', true);
    $raw = get_post_meta($post_id, $meta_key, true);
    $bypass_date = false;

    if (!$date_start || !$raw) return $single ? $raw : [$raw];

    $today    = wp_date('Y-m-d');
    $tomorrow = wp_date('Y-m-d', strtotime('+1 day'));

    $prefix = '';
    if ($date_start === $today) {
        $prefix = 'Heute, ';
    } elseif ($date_start === $tomorrow) {
        $prefix = 'Morgen, ';
    }

    $result = $prefix . $raw;
    return $single ? $result : [$result];
}, 10, 4);

// ── Einmalige Migrationen bei Versions-Update ──
add_action('admin_init', function() {
    $stored = get_option('tix_db_version', '0');
    if (version_compare($stored, TIXOMAT_VERSION, '>=')) return;

    // v1.15.8: wpautop auf ALLE Info-Meta-Keys (Source + Breakdance-Kopie)
    if (version_compare($stored, '1.15.8', '<')) {
        global $wpdb;
        $keys = [
            '_tix_info_description', '_tix_info_lineup', '_tix_info_specials', '_tix_info_extra_info',
            '_tix_description', '_tix_lineup', '_tix_specials', '_tix_extra_info',
        ];
        $not_like = '%' . $wpdb->esc_like('<p>') . '%';
        foreach ($keys as $mk) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' AND meta_value NOT LIKE %s",
                $mk, $not_like
            ));
            foreach ($rows as $r) {
                update_post_meta($r->post_id, $mk, wpautop($r->meta_value));
            }
        }
    }

    // v1.27.0: Custom Ticket-DB Tabelle erstellen (wenn aktiviert)
    if (version_compare($stored, '1.27.0', '<')) {
        if (class_exists('TIX_Settings') && TIX_Settings::get('ticket_db_enabled') && class_exists('TIX_Ticket_DB')) {
            TIX_Ticket_DB::create_table();
        }
    }

    // v1.28.0 Migration: flush rewrite rules für neuen CPT
    if (version_compare($stored, '1.28.0', '<')) {
        flush_rewrite_rules();
    }

    update_option('tix_db_version', TIXOMAT_VERSION);
});
