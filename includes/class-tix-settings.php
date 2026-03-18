<?php
if (!defined('ABSPATH')) exit;

class TIX_Settings {

    const OPTION_KEY = 'tix_settings';

    /**
     * Default-Werte
     */
    public static function defaults() {
        return [
            // ── Modus ──
            'fullscreen_admin'    => 1,
            'login_slug'          => '',
            'organizer_slug'      => '',
            'theme_mode'          => 'light',

            // ── Farbpalette ──
            'color_palette'       => [],   // Array of {name, color} (max 12)

            // ── Farben ──
            'color_text'          => '',       // leer = inherit (Theme-Farbe)
            'color_accent'        => '#c8ff00',
            'color_accent_text'   => '#000000',
            'color_accent_hover'      => '#b8e600',
            'color_accent_hover_text' => '',       // Button-Hover-Textfarbe (leer = unverändert)
            'color_border'        => '#333333',
            'color_input_border'  => '#555555',
            'color_focus'         => '#c8ff00',
            'color_sale'          => '#ef5350',
            'color_success'       => '#4caf50',
            'save_badge_bg'       => '',       // Spar-Badge Hintergrund (leer = Sale-Farbe)
            'save_badge_text'     => '',       // Spar-Badge Textfarbe (leer = weiß)
            'color_card_bg'       => '',
            'color_input_bg'      => '',
            'shortcode_bg'        => '',       // Shortcode-Wrapper-Hintergrund (leer = transparent)

            // ── Ecken & Rahmen ──
            'radius_general'     => 8,
            'radius_input'       => 6,
            'radius_button'      => 8,
            'radius_qty_btn'     => 50,  // % → 50 = rund
            'border_width'       => 1,

            // ── Größen ──
            'qty_btn_size'       => 36,
            'gap'                => 10,
            'checkout_width'     => 640,

            // ── Button-Varianten ──
            'btn1_bg'            => '#c8ff00',
            'btn1_color'         => '#000000',
            'btn1_hover_bg'      => '',
            'btn1_hover_color'   => '',
            'btn1_radius'        => 8,
            'btn1_border'        => '',
            'btn1_font_size'     => '1',

            'btn2_bg'            => '',
            'btn2_color'         => '',
            'btn2_hover_bg'      => '',
            'btn2_hover_color'   => '',
            'btn2_radius'        => 8,
            'btn2_border'        => '',
            'btn2_font_size'     => '0.9',

            // ── Schrift ──
            'font_price'         => '1',
            'font_name'          => '1',
            'font_total'         => '1.3',
            'font_desc'          => '0.85',
            'font_vat'           => '0.85',

            // ── Event-Seite ([tix_event_page]) ──
            'ep_layout'          => '2col',   // '1col' oder '2col'
            'ep_max_width'       => 1100,
            'ep_gap'             => 32,
            'ep_radius'          => 12,
            'ep_bg'              => '',       // leer = #fff
            'ep_text'            => '',       // leer = #1a1a1a
            'ep_muted'           => '',       // leer = #64748b
            'ep_border'          => '',       // leer = #e5e7eb
            'ep_ticket_mode'     => 'selector', // selector | modal | both
            'ep_show_hero'       => 1,
            'ep_show_gallery'    => 1,
            'ep_show_video'      => 1,
            'ep_show_faq'        => 1,
            'ep_show_location'   => 1,
            'ep_show_organizer'  => 1,
            'ep_show_series'     => 1,
            'ep_show_charity'    => 1,
            'ep_show_upsell'     => 1,
            'ep_show_calendar'   => 1,
            'ep_show_phases'     => 1,
            'ep_show_raffle'     => 1,
            'ep_show_share'      => 1,
            'ep_show_timetable'  => 1,

            // ── Share-Buttons ([tix_share]) ──
            'share_channels'     => 'wa,tg,fb,x,li,email,copy,native',
            'share_label'        => 'Teilen',
            'share_style'        => 'icon',  // 'icon' | 'label'

            // ── Warteliste / Presale-Benachrichtigungen ──
            'waitlist_enabled'   => 1,
            // ── Post-Event Feedback ──
            'feedback_enabled'   => 1,

            // ── Ticket-Selektor ──
            'low_stock_threshold' => 10,  // "Nur noch X verfügbar!" (0=aus)

            // ── Ticket-Selektor Texte ──
            'btn_text_buy'       => 'Weiter zur Kasse',
            'vat_text_selector'  => 'inkl. MwSt.',

            // ── FAQ ──
            'faq_text_color'     => '',       // leer = inherit
            'faq_bg'             => '',       // Frage-Hintergrund
            'faq_list_bg'        => '',       // Gesamter Listen-Hintergrund
            'faq_hover_bg'       => '',       // Hover-Hintergrund
            'faq_hover_text'     => '',       // Hover-Textfarbe
            'faq_active_bg'      => '',       // Aktive Frage Hintergrund
            'faq_border_color'   => '',       // leer = globale Rahmenfarbe
            'faq_divider_color'  => '',       // leer = gleich wie border
            'faq_accent_color'   => '',       // leer = globaler Accent
            'faq_icon_color'     => '',       // leer = currentColor
            'faq_link_color'     => '',       // leer = inherit
            'faq_radius'         => 8,        // gleich wie globaler Radius
            'faq_border_width'   => 1,        // gleich wie globale Rahmenbreite
            'faq_accent_width'   => 3,
            'faq_icon_size'      => 24,
            'faq_max_width'      => 720,
            'faq_question_size'  => '1',
            'faq_answer_size'    => '0.95',
            'faq_answer_opacity' => '0.75',
            'faq_title_size'     => '1.3',
            'faq_padding_v'      => 14,       // vertikaler Padding
            'faq_padding_h'      => 18,       // horizontaler Padding

            // ── Ticket Selector ──
            'sel_text_color'       => '',       // leer = inherit
            'sel_bg'               => '',       // Kategorie-Hintergrund
            'sel_border_color'     => '',       // leer = globale Rahmenfarbe
            'sel_active_border'    => '',       // leer = globale Fokus-Farbe
            'sel_active_bg'        => '',       // leer = leicht transparent
            'sel_hover_text'       => '',       // Hover-Textfarbe
            'sel_radius'           => 8,        // gleich wie globaler Radius
            'sel_border_width'     => 1,
            'sel_padding_v'        => 14,
            'sel_padding_h'        => 16,
            'sel_max_width'        => 0,        // 0 = kein Limit

            // ── Kalender-Button ──
            'cal_bg'               => '',       // leer = transparent
            'cal_text_color'       => '',       // leer = inherit
            'cal_border_color'     => '',       // leer = globale Rahmenfarbe
            'cal_border_width'     => 1,
            'cal_radius'           => 8,
            'cal_hover_bg'         => '',       // leer = transparent
            'cal_hover_border'     => '',       // leer = Akzentfarbe
            'cal_hover_text'       => '',       // leer = unverändert

            // ── Express-Checkout Modal ──
            'ec_btn_bg'            => '',       // Trigger-Button Hintergrund (leer = Akzentfarbe)
            'ec_btn_text'          => '',       // Trigger-Button Textfarbe (leer = Akzent-Text)
            'ec_btn_hover_bg'      => '',       // Trigger Hover-Hintergrund
            'ec_btn_hover_text'    => '',       // Trigger Hover-Textfarbe
            'ec_btn_border_color'  => '',       // Trigger Rahmenfarbe (leer = keine)
            'ec_btn_border_width'  => 0,        // Trigger Rahmenbreite
            'ec_btn_radius'        => 8,        // Trigger Eckenradius
            'ec_modal_bg'          => '',       // Modal-Hintergrund (leer = Karten-BG)
            'ec_modal_text'        => '',       // Modal-Textfarbe (leer = inherit)
            'ec_modal_border'      => '',       // Modal-Rahmenfarbe (leer = globale Rahmenfarbe)
            'ec_modal_radius'      => 8,        // Modal-Eckenradius
            'ec_modal_max_width'   => 520,      // Modal Max-Breite
            'ec_cat_border'        => '',       // Kategorie-Rahmenfarbe (leer = globale Rahmenfarbe)
            'ec_cat_active'        => '',       // Kategorie aktiver Rahmen (leer = Fokusfarbe)
            'ec_cat_radius'        => 8,        // Kategorie-Eckenradius
            'ec_buy_bg'            => '',       // Kauf-Button Hintergrund (leer = Akzentfarbe)
            'ec_buy_text'          => '',       // Kauf-Button Textfarbe (leer = Akzent-Text)
            'ec_buy_hover_bg'      => '',       // Kauf-Button Hover
            'ec_buy_hover_text'    => '',       // Kauf-Button Hover-Textfarbe

            // ── Checkout ──
            'terms_url'          => '',
            'privacy_url'        => '',
            'revocation_url'     => '',
            'btn_text_checkout'  => 'Jetzt kostenpflichtig bestellen',
            'vat_text_checkout'  => 'inkl. MwSt.',
            'empty_text'         => 'Dein Warenkorb ist leer.',
            'empty_link_text'    => 'Zu den Events',
            'shipping_text'      => 'Kostenloser Versand per E-Mail',

            // ── Verhalten ──
            'skip_cart'            => 1,
            'force_email_shipping' => 1,
            'show_company_field'   => 0,
            'show_coupon_selector' => 1,
            'checkout_steps'       => 0,
            'checkout_btn_variant' => '1',  // '1' = Primär, '2' = Sekundär
            // ── Countdown ──
            'checkout_countdown'         => 0,
            'checkout_countdown_minutes' => 10,
            // ── Upselling ──
            'show_upsell'          => 1,
            'upsell_count'         => 3,
            'upsell_heading'       => 'Das könnte dich auch interessieren',
            // ── Google Places ──
            'google_api_key'     => '',
            // ── Meine Tickets ──
            'mt_bg'              => '#ffffff',
            'mt_card_bg'         => '#ffffff',
            'mt_text_color'      => '#1a1a1a',
            'mt_border_color'    => '#e5e7eb',
            'mt_ticket_bg'       => '#f8fdf0',
            'mt_accent_color'    => '',  // leer = globaler Accent

            // ── E-Mail ──
            'email_logo_url'     => '',       // URL zum Logo-Bild
            'email_brand_name'   => '',       // Firmenname (leer = Blogname)
            'email_footer_text'  => '',       // Footer-Text (leer = Default)
            'email_reminder'     => 1,        // Erinnerungsmail 24h vor Event
            'email_followup'     => 1,        // Nachbefragungsmail 24h nach Event
            'email_followup_url' => '',       // Feedback-Link URL
            // ── Gemeinsam buchen ──
            'group_booking'      => 1,
            // ── Newsletter ──
            'newsletter_enabled'  => 0,
            'newsletter_type'     => 'email',    // 'email' oder 'whatsapp'
            'newsletter_label'    => '',          // leer = Standardtext
            'newsletter_webhook'  => '',          // URL für Webhook
            'newsletter_legal'    => '',          // Rechtlicher Hinweis unter Checkbox
            // ── Ticket-System ──
            'ticket_system'         => 'standalone',
            // ── Abandoned Cart ──
            'abandoned_cart_enabled' => 0,
            'abandoned_cart_delay'   => 30,       // Minuten
            'abandoned_cart_subject' => '',        // leer = Standard
            // ── Express Checkout ──
            'express_checkout_enabled' => 0,
            // ── Ticket-Umschreibung ──
            'ticket_transfer_enabled' => 0,
            // ── Strichcode (Barcode) ──
            'barcode_enabled' => 0,
            // ── Charity / Soziales Projekt ──
            'charity_enabled' => 0,
            // ── Promoter-System ──
            'promoter_enabled'      => 0,
            'promoter_cookie_days'  => 30,
            'promoter_self_signup'  => 0,
            'promoter_signup_commission_type'  => 'fixed',
            'promoter_signup_commission_value' => 2,
            'promoter_signup_auto_events'      => 1,
            'promoter_post_purchase_enabled'   => 0,
            'promoter_my_tickets_enabled'      => 0,
            // ── Veranstalter-Dashboard ──
            'organizer_dashboard_enabled' => 0,
            'organizer_auto_publish'      => 0,
            'organizer_fullscreen'        => 1,
            // ── Support-System ──
            'support_enabled'    => 0,
            'support_categories'  => '',
            'support_chat_enabled' => 0,
            // ── KI-Schutz ──
            'ai_guard_enabled'  => 0,
            'ai_guard_api_key'  => '',
            // ── Mein-Konto Styling ──
            'myaccount_restyle'  => 0,

            // ── Marketing ──
            // VIP
            'vip_enabled'            => 0,
            'vip_min_tickets'        => 5,
            'vip_min_orders'         => 3,
            'vip_discount_enabled'   => 0,
            'vip_discount_type'      => 'percent',
            'vip_discount_value'     => 10,
            'vip_badge_label'        => 'VIP',
            // Marketing-Export
            'marketing_export_enabled' => 0,
            // Social Proof
            'social_proof_enabled'     => 0,
            'social_proof_text'        => '{count} Personen sehen sich dieses Event gerade an',
            'social_proof_min_count'   => 2,
            'social_proof_multiplier'  => 1.5,
            'social_proof_interval'    => 30,
            'social_proof_position'    => 'above_tickets',
            // Exit-Intent
            'exit_intent_enabled'      => 0,
            'exit_intent_coupon_code'  => '',
            'exit_intent_headline'     => 'Warte! Hier ist dein Rabatt',
            'exit_intent_text'         => 'Sichere dir {discount} Rabatt mit folgendem Code:',
            'exit_intent_button_text'  => 'Jetzt einl&ouml;sen',
            'exit_intent_cookie_days'  => 7,
            'exit_intent_delay'        => 5,
            // Kampagnen-Tracking
            'campaign_tracking_enabled'  => 0,
            'campaign_cookie_days'       => 30,
            'campaign_custom_channels'   => '[]',
            // ── Specials / Zusatzprodukte ──
            'specials_enabled'         => 0,
            // ── POS / Abendkasse ──
            'pos_enabled'              => 0,
            'pos_pin_required'         => 1,
            'pos_auto_reset_seconds'   => 10,
            'pos_default_payment'      => 'cash',
            'pos_allow_free'           => 1,
            'pos_require_email'        => 0,
            'pos_require_name'         => 0,
            // ── Tischreservierung ──
            'table_reservation_enabled'  => 0,
            'reservation_bg'             => '',
            'reservation_surface'        => '',
            'reservation_card'           => '',
            'reservation_text'           => '',
            'table_button_style'         => '1',
            'table_default_categories'   => '[]',
            // ── Geführter Modus ──
            'wizard_enabled'     => 1,
            // ── Theme-Modus (universell) ──
            'theme_mode'         => 'light',   // 'light' oder 'dark'
            // ── Check-in ──
            'ci_bg'              => '#FAF8F4',
            'ci_surface'         => '#ffffff',
            'ci_border'          => '#EDE9E0',
            'ci_text'            => '#1e293b',
            'ci_muted'           => '#64748b',
            'ci_accent'          => '#1e293b',
            'ci_accent_text'     => '#ffffff',
            'ci_ok'              => '#22c55e',
            'ci_warn'            => '#eab308',
            'ci_err'             => '#ef4444',
            'ci_popup_duration'  => 5,
            // ── Daten-Sync ──
            'ticket_db_enabled'  => 0,
            'supabase_enabled'   => 0,
            'supabase_url'       => '',
            'supabase_api_key'   => '',
            'supabase_table'     => 'tickets',
            'airtable_enabled'   => 0,
            'airtable_api_key'   => '',
            'airtable_base_id'   => '',
            'airtable_table'     => 'Tickets',
            // ── Branding ──
            'branding_enabled'  => 1,
            'branding_url'      => 'https://mdj.events',
            // ── Sponsor (Thank-You) ──
            'sponsor_enabled'    => 0,
            'sponsor_image_url'  => '',
            'sponsor_logo_width' => 30,
            // Ticket-Template
            'ticket_template'    => '',
        ];
    }

    /**
     * Settings laden (mit Defaults gemergt)
     */
    public static function get($key = null) {
        $settings = wp_parse_args(get_option(self::OPTION_KEY, []), self::defaults());
        if ($key !== null) return $settings[$key] ?? null;
        return $settings;
    }

    /**
     * Init
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_head',    [__CLASS__, 'output_css'], 99);
    }

    /**
     * Admin-Assets auf der Settings-Seite laden
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'tixomat_page_tix-settings') return;
        wp_enqueue_style('dashicons');
        wp_enqueue_style('tix-admin', TIXOMAT_URL . 'assets/css/admin.css', ['tix-google-fonts'], TIXOMAT_VERSION);

        // Ticket-Template Editor
        wp_enqueue_media();
        wp_enqueue_style('tix-tte-editor', TIXOMAT_URL . 'assets/css/ticket-template-editor.css', ['tix-google-fonts'], TIXOMAT_VERSION);
        wp_enqueue_script('tix-tte-editor', TIXOMAT_URL . 'assets/js/ticket-template-editor.js', ['jquery'], TIXOMAT_VERSION, true);
    }

    /**
     * Menüeintrag
     */
    public static function add_menu() {
        add_submenu_page(
            'tixomat',
            'Tixomat Einstellungen',
            'Einstellungen',
            'manage_options',
            'tix-settings',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Settings registrieren
     */
    public static function register_settings() {
        register_setting('tix_settings_group', self::OPTION_KEY, [
            'sanitize_callback' => [__CLASS__, 'sanitize'],
        ]);
    }

    /**
     * Sanitize
     */
    public static function sanitize($input) {
        $defaults = self::defaults();
        $clean = [];

        // Farben (hex + rgba Support)
        foreach (['color_accent', 'color_accent_text', 'color_accent_hover', 'color_accent_hover_text', 'color_border', 'color_input_border', 'color_focus', 'color_sale', 'color_success'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: $defaults[$k];
        }
        $clean['color_text']     = self::sanitize_color($input['color_text'] ?? '') ?: '';
        $clean['color_card_bg']  = self::sanitize_color($input['color_card_bg'] ?? '') ?: '';
        $clean['color_input_bg'] = self::sanitize_color($input['color_input_bg'] ?? '') ?: '';
        $clean['shortcode_bg']   = self::sanitize_color($input['shortcode_bg'] ?? '') ?: '';
        foreach (['save_badge_bg', 'save_badge_text'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: '';
        }

        // Zahlen
        foreach (['radius_general', 'radius_input', 'radius_button', 'border_width', 'qty_btn_size', 'gap', 'checkout_width'] as $k) {
            $clean[$k] = max(0, intval($input[$k] ?? $defaults[$k]));
        }
        $clean['radius_qty_btn'] = max(0, min(50, intval($input['radius_qty_btn'] ?? $defaults['radius_qty_btn'])));

        // Schrift (Dezimal)
        foreach (['font_price', 'font_name', 'font_total', 'font_desc', 'font_vat'] as $k) {
            $v = floatval($input[$k] ?? $defaults[$k]);
            $clean[$k] = max(0.5, min(3, $v));
        }

        // Button-Varianten
        foreach (['btn1_bg', 'btn1_color', 'btn1_hover_bg', 'btn1_hover_color', 'btn2_bg', 'btn2_color', 'btn2_hover_bg', 'btn2_hover_color'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: '';
        }
        foreach (['btn1_radius', 'btn2_radius'] as $k) {
            $clean[$k] = max(0, min(50, intval($input[$k] ?? $defaults[$k])));
        }
        foreach (['btn1_font_size', 'btn2_font_size'] as $k) {
            $v = floatval($input[$k] ?? $defaults[$k]);
            $clean[$k] = (string) max(0.5, min(2.5, $v));
        }
        $clean['btn1_border'] = sanitize_text_field($input['btn1_border'] ?? '');
        $clean['btn2_border'] = sanitize_text_field($input['btn2_border'] ?? '');

        // Migration: alte Akzentfarben → Button-Variante 1 (einmalig beim ersten Speichern)
        $existing = get_option(self::OPTION_KEY, []);
        if (!isset($existing['btn1_bg']) && !empty($existing['color_accent'])) {
            if (empty($clean['btn1_bg']))          $clean['btn1_bg']          = $existing['color_accent'];
            if (empty($clean['btn1_color']))        $clean['btn1_color']       = $existing['color_accent_text'] ?? '';
            if (empty($clean['btn1_hover_bg']))     $clean['btn1_hover_bg']    = $existing['color_accent_hover'] ?? '';
            if (empty($clean['btn1_hover_color']))  $clean['btn1_hover_color'] = $existing['color_accent_hover_text'] ?? '';
            if (isset($existing['radius_button']))  $clean['btn1_radius']      = intval($existing['radius_button']);
        }

        // FAQ Farben (alle optional/leer möglich)
        foreach (['faq_text_color', 'faq_bg', 'faq_list_bg', 'faq_hover_bg', 'faq_hover_text', 'faq_active_bg', 'faq_border_color', 'faq_divider_color', 'faq_accent_color', 'faq_icon_color', 'faq_link_color'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: '';
        }
        // FAQ Zahlen (leer möglich = global fallback)
        $clean['faq_radius']       = max(0, min(24, intval($input['faq_radius'] ?? 8)));
        $clean['faq_border_width'] = max(0, min(4, intval($input['faq_border_width'] ?? 1)));
        $clean['faq_accent_width'] = max(0, min(8, intval($input['faq_accent_width'] ?? 3)));
        $clean['faq_icon_size']    = max(12, min(48, intval($input['faq_icon_size'] ?? 24)));
        $clean['faq_max_width']    = max(300, min(1600, intval($input['faq_max_width'] ?? 720)));
        $clean['faq_padding_v']    = max(4, min(32, intval($input['faq_padding_v'] ?? 14)));
        $clean['faq_padding_h']    = max(8, min(40, intval($input['faq_padding_h'] ?? 18)));
        // FAQ Schrift (Dezimal)
        foreach (['faq_question_size', 'faq_answer_size', 'faq_title_size'] as $k) {
            $v = floatval($input[$k] ?? $defaults[$k]);
            $clean[$k] = max(0.6, min(2.5, $v));
        }
        $clean['faq_answer_opacity'] = max(0.3, min(1, floatval($input['faq_answer_opacity'] ?? 0.75)));

        // Ticket Selector Farben
        foreach (['sel_text_color', 'sel_bg', 'sel_border_color', 'sel_active_border', 'sel_active_bg', 'sel_hover_text'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: '';
        }
        $clean['sel_radius']       = max(0, min(24, intval($input['sel_radius'] ?? 8)));
        $clean['sel_border_width'] = max(0, min(4, intval($input['sel_border_width'] ?? 1)));
        $clean['sel_padding_v']    = max(4, min(32, intval($input['sel_padding_v'] ?? 14)));
        $clean['sel_padding_h']    = max(4, min(40, intval($input['sel_padding_h'] ?? 16)));
        $clean['sel_max_width']    = max(0, min(1600, intval($input['sel_max_width'] ?? 0)));



        // Event-Seite Farben
        foreach (['ep_bg', 'ep_text', 'ep_muted', 'ep_border'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: '';
        }
        // Event-Seite Layout
        $clean['ep_layout'] = in_array($input['ep_layout'] ?? '', ['1col', '2col']) ? $input['ep_layout'] : '2col';
        // Event-Seite Zahlen
        $clean['ep_max_width'] = max(400, min(1600, intval($input['ep_max_width'] ?? 1100)));
        $clean['ep_gap']       = max(12, min(48, intval($input['ep_gap'] ?? 32)));
        $clean['ep_radius']    = max(0, min(24, intval($input['ep_radius'] ?? 12)));
        // Event-Seite Ticket-Modus
        $clean['ep_ticket_mode'] = in_array($input['ep_ticket_mode'] ?? '', ['selector', 'modal', 'both']) ? $input['ep_ticket_mode'] : 'selector';
        // Event-Seite Toggles
        foreach (['ep_show_hero', 'ep_show_gallery', 'ep_show_video', 'ep_show_faq', 'ep_show_location', 'ep_show_organizer', 'ep_show_series', 'ep_show_charity', 'ep_show_upsell', 'ep_show_calendar', 'ep_show_phases', 'ep_show_raffle', 'ep_show_share', 'ep_show_timetable'] as $k) {
            $clean[$k] = !empty($input[$k]) ? 1 : 0;
        }

        // Share-Buttons
        $valid_channels = ['wa', 'tg', 'fb', 'x', 'li', 'pi', 'rd', 'email', 'sms', 'copy', 'native'];
        $share_raw = sanitize_text_field($input['share_channels'] ?? '');
        $share_arr = array_filter(array_map('trim', explode(',', $share_raw)), function ($c) use ($valid_channels) {
            return in_array($c, $valid_channels, true);
        });
        $clean['share_channels'] = implode(',', $share_arr);
        $clean['share_label']    = sanitize_text_field($input['share_label'] ?? 'Teilen');
        $clean['share_style']    = in_array($input['share_style'] ?? '', ['icon', 'label']) ? $input['share_style'] : 'icon';

        // Low-Stock-Schwellenwert
        $clean['low_stock_threshold'] = max(0, min(999, intval($input['low_stock_threshold'] ?? 10)));

        // Kalender-Button Farben
        foreach (['cal_bg', 'cal_text_color', 'cal_border_color', 'cal_hover_bg', 'cal_hover_border', 'cal_hover_text'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: '';
        }
        $clean['cal_border_width'] = max(0, min(4, intval($input['cal_border_width'] ?? 1)));
        $clean['cal_radius']       = max(0, min(24, intval($input['cal_radius'] ?? 8)));

        // Texte
        foreach (['terms_url', 'privacy_url', 'revocation_url'] as $k) {
            $clean[$k] = esc_url_raw($input[$k] ?? '');
        }
        foreach (['btn_text_buy', 'btn_text_checkout', 'empty_text', 'empty_link_text', 'shipping_text', 'vat_text_selector', 'vat_text_checkout'] as $k) {
            $clean[$k] = sanitize_text_field($input[$k] ?? $defaults[$k]);
        }

        // Toggles
        $clean['skip_cart']            = !empty($input['skip_cart']) ? 1 : 0;
        $clean['force_email_shipping'] = !empty($input['force_email_shipping']) ? 1 : 0;
        $clean['show_company_field']   = !empty($input['show_company_field']) ? 1 : 0;
        $clean['show_coupon_selector'] = !empty($input['show_coupon_selector']) ? 1 : 0;
        $clean['checkout_steps']       = !empty($input['checkout_steps']) ? 1 : 0;
        $clean['checkout_btn_variant'] = in_array($input['checkout_btn_variant'] ?? '1', ['1', '2']) ? $input['checkout_btn_variant'] : '1';
        $clean['checkout_countdown']         = !empty($input['checkout_countdown']) ? 1 : 0;
        $clean['checkout_countdown_minutes'] = max(1, min(60, intval($input['checkout_countdown_minutes'] ?? 10)));
        $clean['show_upsell']          = !empty($input['show_upsell']) ? 1 : 0;
        $clean['upsell_count']         = max(1, min(6, intval($input['upsell_count'] ?? 3)));
        $clean['upsell_heading']       = sanitize_text_field($input['upsell_heading'] ?? '');

        // Google API Key
        $clean['google_api_key'] = sanitize_text_field($input['google_api_key'] ?? '');

        // Meine Tickets Farben
        foreach (['mt_bg', 'mt_card_bg', 'mt_text_color', 'mt_border_color', 'mt_ticket_bg', 'mt_accent_color'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: '';
        }

        // E-Mail
        $clean['email_logo_url']     = esc_url_raw($input['email_logo_url'] ?? '');
        $clean['email_brand_name']   = sanitize_text_field($input['email_brand_name'] ?? '');
        $clean['email_footer_text']  = sanitize_text_field($input['email_footer_text'] ?? '');
        $clean['email_reminder']     = !empty($input['email_reminder']) ? 1 : 0;
        $clean['email_followup']     = !empty($input['email_followup']) ? 1 : 0;
        $clean['email_followup_url'] = esc_url_raw($input['email_followup_url'] ?? '');

        // Gemeinsam buchen
        $clean['group_booking'] = !empty($input['group_booking']) ? 1 : 0;

        // Newsletter
        $clean['newsletter_enabled'] = !empty($input['newsletter_enabled']) ? 1 : 0;
        $clean['newsletter_type']    = in_array($input['newsletter_type'] ?? '', ['email', 'whatsapp']) ? $input['newsletter_type'] : 'email';
        $clean['newsletter_label']   = sanitize_text_field($input['newsletter_label'] ?? '');
        $clean['newsletter_webhook'] = esc_url_raw($input['newsletter_webhook'] ?? '');
        $clean['newsletter_legal']   = sanitize_textarea_field($input['newsletter_legal'] ?? '');

        // Ticket-System (nur eigenes System)
        $clean['ticket_system'] = 'standalone';

        // Abandoned Cart
        $clean['abandoned_cart_enabled'] = !empty($input['abandoned_cart_enabled']) ? 1 : 0;
        $clean['abandoned_cart_delay']   = max(5, min(1440, intval($input['abandoned_cart_delay'] ?? 30)));
        $clean['abandoned_cart_subject'] = sanitize_text_field($input['abandoned_cart_subject'] ?? '');

        // Express Checkout
        $clean['express_checkout_enabled'] = !empty($input['express_checkout_enabled']) ? 1 : 0;

        // Warteliste
        $clean['waitlist_enabled'] = !empty($input['waitlist_enabled']) ? 1 : 0;

        // Feedback
        $clean['feedback_enabled'] = !empty($input['feedback_enabled']) ? 1 : 0;

        // Express-Checkout Modal Design
        foreach (['ec_btn_bg', 'ec_btn_text', 'ec_btn_hover_bg', 'ec_btn_hover_text', 'ec_btn_border_color', 'ec_modal_bg', 'ec_modal_text', 'ec_modal_border', 'ec_cat_border', 'ec_cat_active', 'ec_buy_bg', 'ec_buy_text', 'ec_buy_hover_bg', 'ec_buy_hover_text'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: '';
        }
        $clean['ec_btn_border_width'] = max(0, min(4, intval($input['ec_btn_border_width'] ?? 0)));
        $clean['ec_btn_radius']       = max(0, min(24, intval($input['ec_btn_radius'] ?? 8)));
        $clean['ec_modal_radius']     = max(0, min(24, intval($input['ec_modal_radius'] ?? 8)));
        $clean['ec_modal_max_width']  = max(320, min(800, intval($input['ec_modal_max_width'] ?? 520)));
        $clean['ec_cat_radius']       = max(0, min(24, intval($input['ec_cat_radius'] ?? 8)));

        // Ticket-Umschreibung
        $clean['ticket_transfer_enabled'] = !empty($input['ticket_transfer_enabled']) ? 1 : 0;

        // Strichcode (Barcode)
        $clean['barcode_enabled'] = !empty($input['barcode_enabled']) ? 1 : 0;

        // Charity / Soziales Projekt
        $clean['charity_enabled'] = !empty($input['charity_enabled']) ? 1 : 0;

        // Promoter-System
        $clean['promoter_enabled'] = !empty($input['promoter_enabled']) ? 1 : 0;
        $clean['promoter_cookie_days'] = max(1, intval($input['promoter_cookie_days'] ?? 30));
        $clean['promoter_self_signup'] = !empty($input['promoter_self_signup']) ? 1 : 0;
        $clean['promoter_signup_commission_type']  = in_array($input['promoter_signup_commission_type'] ?? '', ['percent', 'fixed']) ? $input['promoter_signup_commission_type'] : 'fixed';
        $clean['promoter_signup_commission_value'] = max(0, floatval($input['promoter_signup_commission_value'] ?? 2));
        $clean['promoter_signup_auto_events']      = !empty($input['promoter_signup_auto_events']) ? 1 : 0;
        $clean['promoter_post_purchase_enabled']   = !empty($input['promoter_post_purchase_enabled']) ? 1 : 0;
        $clean['promoter_my_tickets_enabled']      = !empty($input['promoter_my_tickets_enabled']) ? 1 : 0;

        // Veranstalter-Dashboard
        $clean['organizer_dashboard_enabled'] = !empty($input['organizer_dashboard_enabled']) ? 1 : 0;
        $clean['organizer_auto_publish'] = !empty($input['organizer_auto_publish']) ? 1 : 0;
        $clean['organizer_fullscreen'] = !empty($input['organizer_fullscreen']) ? 1 : 0;

        // Support-System
        $clean['support_enabled'] = !empty($input['support_enabled']) ? 1 : 0;
        $clean['support_categories'] = sanitize_textarea_field($input['support_categories'] ?? '');
        $clean['support_chat_enabled'] = !empty($input['support_chat_enabled']) ? 1 : 0;

        // KI-Schutz
        $clean['ai_guard_enabled'] = !empty($input['ai_guard_enabled']) ? 1 : 0;
        $clean['ai_guard_api_key'] = sanitize_text_field($input['ai_guard_api_key'] ?? '');

        // Mein-Konto Styling
        $clean['myaccount_restyle'] = !empty($input['myaccount_restyle']) ? 1 : 0;

        // ── Marketing ──
        // VIP
        $clean['vip_enabled']          = !empty($input['vip_enabled']) ? 1 : 0;
        $clean['vip_min_tickets']      = max(1, intval($input['vip_min_tickets'] ?? 5));
        $clean['vip_min_orders']       = max(1, intval($input['vip_min_orders'] ?? 3));
        $clean['vip_discount_enabled'] = !empty($input['vip_discount_enabled']) ? 1 : 0;
        $clean['vip_discount_type']    = in_array($input['vip_discount_type'] ?? '', ['percent', 'fixed']) ? $input['vip_discount_type'] : 'percent';
        $clean['vip_discount_value']   = max(0, floatval($input['vip_discount_value'] ?? 10));
        $clean['vip_badge_label']      = sanitize_text_field($input['vip_badge_label'] ?? 'VIP');
        // Marketing-Export
        $clean['marketing_export_enabled'] = !empty($input['marketing_export_enabled']) ? 1 : 0;
        // Social Proof
        $clean['social_proof_enabled']    = !empty($input['social_proof_enabled']) ? 1 : 0;
        $clean['social_proof_text']       = sanitize_text_field($input['social_proof_text'] ?? '');
        $clean['social_proof_min_count']  = max(1, intval($input['social_proof_min_count'] ?? 2));
        $clean['social_proof_multiplier'] = max(1.0, min(3.0, floatval($input['social_proof_multiplier'] ?? 1.5)));
        $clean['social_proof_interval']   = max(10, min(120, intval($input['social_proof_interval'] ?? 30)));
        $clean['social_proof_position']   = in_array($input['social_proof_position'] ?? '', ['above_tickets', 'below_hero', 'floating']) ? $input['social_proof_position'] : 'above_tickets';
        // Exit-Intent
        $clean['exit_intent_enabled']     = !empty($input['exit_intent_enabled']) ? 1 : 0;
        $clean['exit_intent_coupon_code'] = sanitize_text_field($input['exit_intent_coupon_code'] ?? '');
        $clean['exit_intent_headline']    = sanitize_text_field($input['exit_intent_headline'] ?? '');
        $clean['exit_intent_text']        = sanitize_textarea_field($input['exit_intent_text'] ?? '');
        $clean['exit_intent_button_text'] = sanitize_text_field($input['exit_intent_button_text'] ?? '');
        $clean['exit_intent_cookie_days'] = max(1, intval($input['exit_intent_cookie_days'] ?? 7));
        $clean['exit_intent_delay']       = max(0, intval($input['exit_intent_delay'] ?? 5));
        // Kampagnen-Tracking
        $clean['campaign_tracking_enabled']  = !empty($input['campaign_tracking_enabled']) ? 1 : 0;
        $clean['campaign_cookie_days']       = max(1, min(365, intval($input['campaign_cookie_days'] ?? 30)));
        $clean['campaign_custom_channels']   = sanitize_text_field($input['campaign_custom_channels'] ?? '[]');

        // Specials / Zusatzprodukte
        $clean['specials_enabled'] = !empty($input['specials_enabled']) ? 1 : 0;

        // POS / Abendkasse
        $clean['pos_enabled']            = !empty($input['pos_enabled']) ? 1 : 0;
        $clean['pos_pin_required']       = !empty($input['pos_pin_required']) ? 1 : 0;
        $clean['pos_auto_reset_seconds'] = max(3, min(60, intval($input['pos_auto_reset_seconds'] ?? 10)));
        $clean['pos_default_payment']    = in_array($input['pos_default_payment'] ?? '', ['cash', 'card', 'free']) ? $input['pos_default_payment'] : 'cash';
        $clean['pos_allow_free']         = !empty($input['pos_allow_free']) ? 1 : 0;
        $clean['pos_require_email']      = !empty($input['pos_require_email']) ? 1 : 0;
        $clean['pos_require_name']       = !empty($input['pos_require_name']) ? 1 : 0;
        $clean['pos_sumup_enabled']      = !empty($input['pos_sumup_enabled']) ? 1 : 0;
        $clean['pos_sumup_api_key']      = sanitize_text_field($input['pos_sumup_api_key'] ?? '');
        $clean['pos_sumup_merchant_code'] = sanitize_text_field($input['pos_sumup_merchant_code'] ?? '');

        // Tischreservierung
        $clean['table_reservation_enabled'] = !empty($input['table_reservation_enabled']) ? 1 : 0;
        foreach (['reservation_bg', 'reservation_surface', 'reservation_card', 'reservation_text'] as $k) {
            $v = $input[$k] ?? '';
            $clean[$k] = $v ? (self::sanitize_color($v) ?: '') : '';
        }
        $clean['table_button_style'] = in_array($input['table_button_style'] ?? '1', ['1', '2']) ? $input['table_button_style'] : '1';
        // Default table categories (JSON)
        $raw_defaults = $input['table_default_categories'] ?? '[]';
        if (is_string($raw_defaults)) {
            $parsed = json_decode($raw_defaults, true);
            if (!is_array($parsed)) $parsed = [];
            $valid = [];
            foreach ($parsed as $dc) {
                if (!is_array($dc)) continue;
                $dcname = sanitize_text_field($dc['name'] ?? '');
                if (!$dcname) continue;
                $valid[] = [
                    'name'       => $dcname,
                    'min_spend'  => floatval($dc['min_spend'] ?? 0),
                    'quantity'   => max(0, intval($dc['quantity'] ?? 0)),
                    'min_guests' => max(1, intval($dc['min_guests'] ?? 1)),
                    'max_guests' => max(1, intval($dc['max_guests'] ?? 10)),
                ];
            }
            $clean['table_default_categories'] = json_encode($valid);
        } else {
            $clean['table_default_categories'] = '[]';
        }

        // Geführter Modus
        $clean['wizard_enabled'] = !empty($input['wizard_enabled']) ? 1 : 0;

        // Admin-Ansicht
        $clean['fullscreen_admin'] = !empty($input['fullscreen_admin']) ? 1 : 0;

        // Custom URLs
        $clean['login_slug'] = sanitize_title(trim($input['login_slug'] ?? ''));
        $clean['organizer_slug'] = sanitize_title(trim($input['organizer_slug'] ?? ''));

        // Theme-Modus (universell)
        $clean['theme_mode'] = in_array($input['theme_mode'] ?? '', ['light', 'dark']) ? $input['theme_mode'] : 'light';

        // Farbpalette (max 12 Einträge, je name + color)
        $raw_palette = $input['color_palette'] ?? [];
        $clean_palette = [];
        if (is_array($raw_palette)) {
            foreach ($raw_palette as $entry) {
                if (count($clean_palette) >= 12) break;
                if (!is_array($entry)) continue;
                $name  = sanitize_text_field(trim($entry['name'] ?? ''));
                $color = self::sanitize_color($entry['color'] ?? '');
                if ($name === '' || $color === '') continue;
                $clean_palette[] = ['name' => $name, 'color' => $color];
            }
        }
        $clean['color_palette'] = $clean_palette;

        // Check-in
        foreach (['ci_bg', 'ci_surface', 'ci_border', 'ci_text', 'ci_muted', 'ci_accent', 'ci_accent_text', 'ci_ok', 'ci_warn', 'ci_err'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: $defaults[$k];
        }
        $clean['ci_popup_duration'] = max(1, min(30, intval($input['ci_popup_duration'] ?? 5)));

        // Daten-Sync
        $clean['ticket_db_enabled']  = !empty($input['ticket_db_enabled']) ? 1 : 0;
        $clean['supabase_enabled']   = !empty($input['supabase_enabled']) ? 1 : 0;
        $clean['supabase_url']       = esc_url_raw($input['supabase_url'] ?? '');
        $clean['supabase_api_key']   = sanitize_text_field($input['supabase_api_key'] ?? '');
        $clean['supabase_table']     = sanitize_text_field($input['supabase_table'] ?? 'tickets');
        $clean['airtable_enabled']   = !empty($input['airtable_enabled']) ? 1 : 0;
        $clean['airtable_api_key']   = sanitize_text_field($input['airtable_api_key'] ?? '');
        $clean['airtable_base_id']   = sanitize_text_field($input['airtable_base_id'] ?? '');
        $clean['airtable_table']     = sanitize_text_field($input['airtable_table'] ?? 'Tickets');

        // DB-Tabelle erstellen wenn aktiviert
        if ($clean['ticket_db_enabled'] && class_exists('TIX_Ticket_DB') && !TIX_Ticket_DB::table_exists()) {
            TIX_Ticket_DB::create_table();
        }

        // Branding
        $clean['branding_enabled'] = !empty($input['branding_enabled']) ? 1 : 0;
        $clean['branding_url']     = esc_url_raw($input['branding_url'] ?? 'https://mdj.events');

        // Sponsor (Thank-You)
        $clean['sponsor_enabled']    = !empty($input['sponsor_enabled']) ? 1 : 0;
        $clean['sponsor_image_url']  = esc_url_raw($input['sponsor_image_url'] ?? '');
        $clean['sponsor_logo_width'] = max(10, min(80, intval($input['sponsor_logo_width'] ?? 30)));

        // Ticket-Template
        $raw_tt = $input['ticket_template'] ?? '';
        if (!empty($raw_tt) && class_exists('TIX_Ticket_Template')) {
            $tt_config = TIX_Ticket_Template::sanitize_config($raw_tt);
            $clean['ticket_template'] = wp_json_encode($tt_config);
        } else {
            $clean['ticket_template'] = '';
        }

        return $clean;
    }

    /**
     * CSS-Variablen ins Frontend ausgeben
     */
    public static function output_css() {
        $s = self::get();
        $d = self::defaults();

        $vars = [];

        // Nur geänderte Werte ausgeben
        $map = [
            'color_border'        => ['--tix-border', null],
            'color_input_border'  => ['--tix-input-border', null],
            'color_focus'         => ['--tix-input-focus', null],
            'color_sale'          => ['--tix-sale-color', null],
            'color_success'       => ['--tix-success-color', null],
            'color_card_bg'       => ['--tix-cat-bg', null],
            'color_input_bg'      => ['--tix-input-bg', null],
        ];

        foreach ($map as $key => list($var, $unit)) {
            $val = $s[$key];
            if ($val !== '' && $val !== ($d[$key] ?? '')) {
                $vars[] = "$var: $val";
            }
        }

        // Auch Accent = cat-border und cat-active-border synken
        if ($s['color_border'] !== $d['color_border']) {
            $vars[] = '--tix-cat-border: ' . $s['color_border'];
        }
        if ($s['color_focus'] !== $d['color_focus']) {
            $vars[] = '--tix-cat-active-border: ' . $s['color_focus'];
        }

        // Spar-Badge
        if (!empty($s['save_badge_bg']))   $vars[] = '--tix-save-bg: ' . $s['save_badge_bg'];
        if (!empty($s['save_badge_text'])) $vars[] = '--tix-save-text: ' . $s['save_badge_text'];

        $num_map = [
            'radius_general' => ['--tix-radius', 'px'],
            'radius_input'   => ['--tix-input-radius', 'px'],
            'radius_qty_btn' => ['--tix-btn-radius', '%'],
            'qty_btn_size'   => ['--tix-btn-size', 'px'],
            'gap'            => ['--tix-gap', 'px'],
        ];

        foreach ($num_map as $key => list($var, $unit)) {
            if ((string)$s[$key] !== (string)$d[$key]) {
                $vars[] = "$var: {$s[$key]}{$unit}";
            }
        }

        // ── Button-Varianten ──
        // Prüfe ob btn1_* schon explizit gespeichert wurde (Migration passiert erst beim Save)
        $raw = get_option(self::OPTION_KEY, []);
        $btn_migrated = isset($raw['btn1_bg']);

        // Variante 1 (Primär) — Fallback auf alte color_accent Werte wenn noch nicht migriert
        if ($btn_migrated) {
            $btn1_bg          = $s['btn1_bg'];
            $btn1_color       = $s['btn1_color'];
            $btn1_hover_bg    = $s['btn1_hover_bg'] ?? '';
            $btn1_hover_color = $s['btn1_hover_color'] ?? '';
        } else {
            $btn1_bg          = !empty($s['color_accent'])            ? $s['color_accent']            : $d['btn1_bg'];
            $btn1_color       = !empty($s['color_accent_text'])       ? $s['color_accent_text']       : $d['btn1_color'];
            $btn1_hover_bg    = !empty($s['color_accent_hover'])      ? $s['color_accent_hover']      : '';
            $btn1_hover_color = !empty($s['color_accent_hover_text']) ? $s['color_accent_hover_text'] : '';
        }
        $btn1_radius    = $btn_migrated ? intval($s['btn1_radius']) : intval($s['radius_button'] ?? $d['btn1_radius']);
        $btn1_border    = $s['btn1_border'] ?? '';
        $btn1_font_size = floatval($s['btn1_font_size'] ?? $d['btn1_font_size']);

        $vars[] = '--tix-btn1-bg: ' . $btn1_bg;
        $vars[] = '--tix-btn1-color: ' . $btn1_color;
        $vars[] = '--tix-btn1-hover-bg: ' . ($btn1_hover_bg ?: $btn1_bg);
        $vars[] = '--tix-btn1-hover-color: ' . ($btn1_hover_color ?: $btn1_color);
        $vars[] = '--tix-btn1-radius: ' . $btn1_radius . 'px';
        $vars[] = '--tix-btn1-border: ' . ($btn1_border ?: 'none');
        $vars[] = '--tix-btn1-font-size: ' . $btn1_font_size . 'rem';

        // Variante 2 (Sekundär)
        $btn2_bg          = $s['btn2_bg'] ?? '';
        $btn2_color       = $s['btn2_color'] ?? '';
        $btn2_hover_bg    = $s['btn2_hover_bg'] ?? '';
        $btn2_hover_color = $s['btn2_hover_color'] ?? '';
        $btn2_radius      = intval($s['btn2_radius'] ?? $d['btn2_radius']);
        $btn2_border      = $s['btn2_border'] ?? '';
        $btn2_font_size   = floatval($s['btn2_font_size'] ?? $d['btn2_font_size']);

        $vars[] = '--tix-btn2-bg: ' . ($btn2_bg ?: 'transparent');
        $vars[] = '--tix-btn2-color: ' . ($btn2_color ?: 'inherit');
        $vars[] = '--tix-btn2-hover-bg: ' . ($btn2_hover_bg ?: 'transparent');
        $vars[] = '--tix-btn2-hover-color: ' . ($btn2_hover_color ?: 'inherit');
        $vars[] = '--tix-btn2-radius: ' . $btn2_radius . 'px';
        $vars[] = '--tix-btn2-border: ' . ($btn2_border ?: '1px solid currentColor');
        $vars[] = '--tix-btn2-font-size: ' . $btn2_font_size . 'rem';

        // Backward-Compat Aliase (alte Variablen → neue)
        $vars[] = '--tix-buy-bg: var(--tix-btn1-bg)';
        $vars[] = '--tix-buy-color: var(--tix-btn1-color)';
        $vars[] = '--tix-buy-hover: var(--tix-btn1-hover-bg)';
        $vars[] = '--tix-buy-hover-color: var(--tix-btn1-hover-color)';
        $vars[] = '--tix-buy-radius: var(--tix-btn1-radius)';

        $font_map = [
            'font_price' => '--tix-font-price',
            'font_name'  => '--tix-font-name',
            'font_total' => '--tix-font-total',
            'font_desc'  => '--tix-font-desc',
            'font_vat'   => '--tix-font-vat',
        ];

        foreach ($font_map as $key => $var) {
            if ((string)$s[$key] !== (string)$d[$key]) {
                $vars[] = "$var: {$s[$key]}rem";
            }
        }

        // ── .tix-vat Regel (immer ausgeben, da Meta-Spans auch außerhalb von .tix-sel/.tix-co vorkommen) ──
        $vat_size = floatval($s['font_vat'] ?: $d['font_vat']);
        $vat_css  = ".tix-vat { font-size: {$vat_size}rem; font-weight: 400; }\n";

        // ── Mein-Konto Scope (WC My Account Variablen erweitern) ──
        $wc_scope = !empty($s['myaccount_restyle']) ? ', .woocommerce-account .woocommerce' : '';

        // ── Globale Textfarbe ──
        $text_css = '';
        if (!empty($s['color_text'])) {
            $vars[] = '--tix-text: ' . $s['color_text'];
            $text_css = ".tix-sel, .tix-co, .tix-faq, .tix-up, .tix-mt, .tix-mc-trigger, .tix-ec-overlay, .tix-cal, .tix-raffle, .tix-table-btn-wrap, #tix-table-res-app, .tr-modal-overlay{$wc_scope} { color: var(--tix-text); }\n";
        }

        if (empty($vars)) {
            echo "<style id=\"tix-custom-vars\">\n{$vat_css}{$text_css}";
        } else {
            echo "<style id=\"tix-custom-vars\">\n.tix-sel, .tix-co, .tix-faq, .tix-up, .tix-mt, .tix-mc-trigger, .tix-mc-overlay, .tix-cal, .tix-raffle, .tix-ec-trigger, .tix-ec-overlay, .tix-table-btn-wrap, #tix-table-res-app, .tr-modal-overlay{$wc_scope} {\n    " . implode(";\n    ", $vars) . ";\n}\n";
            echo $vat_css;
            echo $text_css;
        }

        // Border-Width Override (nicht als Variable, sondern als direkte Regel)
        if ((int)$s['border_width'] !== (int)$d['border_width']) {
            $bw = intval($s['border_width']);
            // .tix-sel-cat nur inkludieren wenn sel_border_width NICHT separat gesetzt
            $sel_has_own = (int)$s['sel_border_width'] !== 1;
            $sel_el = $sel_has_own ? '' : '.tix-sel-cat, ';
            echo "{$sel_el}.tix-co-item, .tix-co-gateway, .tix-co-shipping-info, .tix-co-legal, .tix-co-login-section { border-width: {$bw}px; }\n";
            echo ".tix-co-input, .tix-co-select, .tix-co-textarea { border-width: {$bw}px; }\n";
        }

        // Checkout Max-Width
        if ((int)$s['checkout_width'] !== (int)$d['checkout_width']) {
            echo ".tix-co { max-width: {$s['checkout_width']}px; }\n";
        }

        // ── FAQ Styles ──
        $faq_vars = [];
        // Farben (leer = Fallback auf global oder CSS-Default)
        if (!empty($s['faq_bg']))            $faq_vars[] = '--tix-faq-bg: ' . $s['faq_bg'];
        if (!empty($s['faq_list_bg']))       $faq_vars[] = '--tix-faq-list-bg: ' . $s['faq_list_bg'];
        if (!empty($s['faq_hover_bg']))      $faq_vars[] = '--tix-faq-hover: ' . $s['faq_hover_bg'];
        if (!empty($s['faq_hover_text']))    $faq_vars[] = '--tix-faq-hover-text: ' . $s['faq_hover_text'];
        if (!empty($s['faq_active_bg']))     $faq_vars[] = '--tix-faq-active-bg: ' . $s['faq_active_bg'];
        if (!empty($s['faq_icon_color']))    $faq_vars[] = '--tix-faq-icon-color: ' . $s['faq_icon_color'];
        if (!empty($s['faq_link_color']))    $faq_vars[] = '--tix-faq-link-color: ' . $s['faq_link_color'];
        // Border: eigene Farbe oder globale Rahmenfarbe
        $faq_border = !empty($s['faq_border_color']) ? $s['faq_border_color'] : $s['color_border'];
        if ($faq_border !== $d['color_border']) {
            $faq_vars[] = '--tix-faq-border: ' . $faq_border;
        }
        // Divider: eigene oder gleich wie border
        if (!empty($s['faq_divider_color'])) {
            $faq_vars[] = '--tix-faq-divider: ' . $s['faq_divider_color'];
        }
        // Accent: eigene oder globale Akzentfarbe
        $faq_accent = !empty($s['faq_accent_color']) ? $s['faq_accent_color'] : $s['color_accent'];
        if ($faq_accent !== $d['color_accent']) {
            $faq_vars[] = '--tix-faq-accent: ' . $faq_accent;
        }
        // Radius
        if ((int)$s['faq_radius'] !== (int)$d['faq_radius']) {
            $faq_vars[] = '--tix-faq-radius: ' . intval($s['faq_radius']) . 'px';
        }
        // Border-Width
        if ((int)$s['faq_border_width'] !== (int)$d['faq_border_width']) {
            $faq_vars[] = '--tix-faq-border-width: ' . intval($s['faq_border_width']) . 'px';
        }
        // Zahlen
        if ((int)$s['faq_accent_width'] !== 3) $faq_vars[] = '--tix-faq-accent-width: ' . intval($s['faq_accent_width']) . 'px';
        if ((int)$s['faq_icon_size'] !== 24)   $faq_vars[] = '--tix-faq-icon-size: ' . intval($s['faq_icon_size']) . 'px';
        if ((int)$s['faq_max_width'] !== 720)  $faq_vars[] = '--tix-faq-max-width: ' . intval($s['faq_max_width']) . 'px';
        // Padding
        $pv = intval($s['faq_padding_v']);
        $ph = intval($s['faq_padding_h']);
        if ($pv !== 14 || $ph !== 18) {
            $faq_vars[] = "--tix-faq-padding: {$pv}px {$ph}px";
        }
        if ($ph !== 18) {
            $faq_vars[] = "--tix-faq-padding-h: {$ph}px";
        }
        // Schrift
        if ((string)$s['faq_title_size'] !== '1.3')      $faq_vars[] = '--tix-faq-title-size: ' . $s['faq_title_size'] . 'rem';
        if ((string)$s['faq_question_size'] !== '1')      $faq_vars[] = '--tix-faq-question-size: ' . $s['faq_question_size'] . 'rem';
        if ((string)$s['faq_answer_size'] !== '0.95')     $faq_vars[] = '--tix-faq-answer-size: ' . $s['faq_answer_size'] . 'rem';
        if ((string)$s['faq_answer_opacity'] !== '0.75')  $faq_vars[] = '--tix-faq-answer-opacity: ' . $s['faq_answer_opacity'];

        if (!empty($faq_vars)) {
            echo ".tix-faq {\n    " . implode(";\n    ", $faq_vars) . ";\n}\n";
        }

        // ── Ticket Selector Styles ──
        $sel_vars = [];
        if (!empty($s['sel_bg']))            $sel_vars[] = '--tix-sel-cat-bg: ' . $s['sel_bg'];
        if (!empty($s['sel_border_color'])) {
            $sel_vars[] = '--tix-cat-border: ' . $s['sel_border_color'];
        }
        if (!empty($s['sel_active_border'])) $sel_vars[] = '--tix-sel-active-border: ' . $s['sel_active_border'];
        if (!empty($s['sel_active_bg']))     $sel_vars[] = '--tix-sel-active-bg: ' . $s['sel_active_bg'];
        if (!empty($s['sel_hover_text']))    $sel_vars[] = '--tix-sel-hover-text: ' . $s['sel_hover_text'];
        if ((int)$s['sel_radius'] !== (int)$d['sel_radius']) {
            $sel_vars[] = '--tix-sel-radius: ' . intval($s['sel_radius']) . 'px';
        }
        if ((int)$s['sel_border_width'] !== 1) {
            $sel_vars[] = '--tix-sel-border-width: ' . intval($s['sel_border_width']) . 'px';
        }
        $spv = intval($s['sel_padding_v']);
        $sph = intval($s['sel_padding_h']);
        if ($spv !== 14 || $sph !== 16) {
            $sel_vars[] = "--tix-sel-padding: {$spv}px {$sph}px";
        }
        if ((int)$s['sel_max_width'] > 0) {
            $sel_vars[] = '--tix-sel-max-width: ' . intval($s['sel_max_width']) . 'px';
        }
        if (!empty($sel_vars)) {
            echo ".tix-sel {\n    " . implode(";\n    ", $sel_vars) . ";\n}\n";
        }
        // ── Express-Checkout Modal Styles (nur Modal + Kategorien, Buttons via Varianten) ──
        $ec_vars = [];
        // Modal
        if (!empty($s['ec_modal_bg']))         $ec_vars[] = '--tix-ec-modal-bg: ' . $s['ec_modal_bg'];
        $ec_border = !empty($s['ec_modal_border']) ? $s['ec_modal_border'] : $s['color_border'];
        if ($ec_border !== $d['color_border']) {
            $ec_vars[] = '--tix-ec-modal-border: ' . $ec_border;
        }
        if ((int)$s['ec_modal_radius'] !== 8)     $ec_vars[] = '--tix-ec-modal-radius: ' . intval($s['ec_modal_radius']) . 'px';
        if ((int)$s['ec_modal_max_width'] !== 520) $ec_vars[] = '--tix-ec-modal-max-width: ' . intval($s['ec_modal_max_width']) . 'px';
        // Kategorien
        $ec_cat_border = !empty($s['ec_cat_border']) ? $s['ec_cat_border'] : $s['color_border'];
        if ($ec_cat_border !== $d['color_border']) {
            $ec_vars[] = '--tix-ec-cat-border: ' . $ec_cat_border;
        }
        $ec_cat_active = !empty($s['ec_cat_active']) ? $s['ec_cat_active'] : $s['color_focus'];
        if ($ec_cat_active !== $d['color_focus']) {
            $ec_vars[] = '--tix-ec-cat-active: ' . $ec_cat_active;
        }
        if ((int)$s['ec_cat_radius'] !== 8) $ec_vars[] = '--tix-ec-cat-radius: ' . intval($s['ec_cat_radius']) . 'px';

        if (!empty($ec_vars)) {
            echo ".tix-ec-trigger, .tix-ec-overlay {\n    " . implode(";\n    ", $ec_vars) . ";\n}\n";
        }

        // ── Shortcode-Wrapper-Hintergrund ──
        if (!empty($s['shortcode_bg'])) {
            $sc_r = intval($s['radius_general'] ?: $d['radius_general']);
            echo ".tix-sel, .tix-co, .tix-up, .tix-faq { background: {$s['shortcode_bg']}; padding: 20px; border-radius: {$sc_r}px; }\n";
        }

        // ── Event-Seite Styles ──
        $ep_vars = [];
        if ((int)$s['ep_max_width'] !== 1100) $ep_vars[] = '--ep-max-w: ' . intval($s['ep_max_width']) . 'px';
        if ((int)$s['ep_gap'] !== 32)        $ep_vars[] = '--ep-gap: ' . intval($s['ep_gap']) . 'px';
        if ((int)$s['ep_radius'] !== 12)     $ep_vars[] = '--ep-radius: ' . intval($s['ep_radius']) . 'px';
        if (!empty($s['ep_bg']))             $ep_vars[] = '--ep-bg: ' . $s['ep_bg'];
        if (!empty($s['ep_text']))           $ep_vars[] = '--ep-text: ' . $s['ep_text'];
        if (!empty($s['ep_muted']))          $ep_vars[] = '--ep-muted: ' . $s['ep_muted'];
        if (!empty($s['ep_border']))         $ep_vars[] = '--ep-border: ' . $s['ep_border'];
        if (!empty($ep_vars)) {
            echo ".tix-ep {\n    " . implode(";\n    ", $ep_vars) . ";\n}\n";
        }

        // ── Meine Tickets Styles ──
        $mt_vars = [];
        if (!empty($s['mt_bg']) && $s['mt_bg'] !== $d['mt_bg']) {
            $mt_vars[] = '--tix-mt-bg: ' . $s['mt_bg'];
        }
        if (!empty($s['mt_card_bg'])) {
            $mt_vars[] = '--tix-mt-card-bg: ' . $s['mt_card_bg'];
        }
        // Muted-Farbe aus globaler Textfarbe ableiten (45% Deckkraft)
        if (!empty($s['color_text'])) {
            $rgb = self::color_to_rgb($s['color_text']);
            if ($rgb) {
                $mt_vars[] = "--tix-mt-muted: rgba({$rgb['r']},{$rgb['g']},{$rgb['b']},0.45)";
            }
        }
        if (!empty($s['mt_border_color'])) {
            $mt_vars[] = '--tix-mt-border: ' . $s['mt_border_color'];
        }
        if (!empty($s['mt_ticket_bg'])) {
            $mt_vars[] = '--tix-mt-ticket-bg: ' . $s['mt_ticket_bg'];
        }
        $mt_accent = !empty($s['mt_accent_color']) ? $s['mt_accent_color'] : $s['color_accent'];
        if ($mt_accent !== $d['color_accent']) {
            $mt_vars[] = '--tix-mt-accent: ' . $mt_accent;
        }
        if (!empty($mt_vars)) {
            echo ".tix-mt {\n    " . implode(";\n    ", $mt_vars) . ";\n}\n";
        }
        // Wrapper-Hintergrund für Meine Tickets
        $mt_bg_val = !empty($s['mt_bg']) ? $s['mt_bg'] : (!empty($s['shortcode_bg']) ? $s['shortcode_bg'] : '');
        if (!empty($mt_bg_val) && $mt_bg_val !== $d['mt_bg']) {
            $mt_r = intval($s['radius_general'] ?: $d['radius_general']);
            echo ".tix-mt { background: {$mt_bg_val}; padding: 20px; border-radius: {$mt_r}px; }\n";
        }

        // ── Check-in Styles ──
        $ci_vars = [];
        $ci_map = [
            'ci_bg'          => '--ci-bg',
            'ci_surface'     => '--ci-surface',
            'ci_border'      => '--ci-border',
            'ci_text'        => '--ci-text',
            'ci_muted'       => '--ci-muted',
            'ci_accent'      => '--ci-accent',
            'ci_accent_text' => '--ci-accent-text',
            'ci_ok'          => '--ci-ok',
            'ci_warn'        => '--ci-warn',
            'ci_err'         => '--ci-err',
        ];
        foreach ($ci_map as $key => $var) {
            if (!empty($s[$key]) && $s[$key] !== $d[$key]) {
                $ci_vars[] = "$var: {$s[$key]}";
            }
        }
        if (!empty($ci_vars)) {
            echo ".tix-ci {\n    " . implode(";\n    ", $ci_vars) . ";\n}\n";
        }

        echo "</style>\n";
    }

    /**
     * Settings-Seite rendern
     */
    public static function render_page() {
        $s  = self::get();
        $ok = self::OPTION_KEY;
        ?>
        <div class="wrap tix-settings-wrap">
            <h1>Tixomat – Einstellungen <span style="font-size:12px;font-weight:400;opacity:0.5;">v<?php echo TIXOMAT_VERSION; ?></span></h1>

            <form method="post" action="options.php" id="tix-settings-form">
                <?php settings_fields('tix_settings_group'); ?>

                <div class="tix-settings-grid">

                    <?php // ═════════════ LEFT: TABBED SETTINGS ═════════════ ?>
                    <div class="tix-app tix-settings-app">

                        <?php // ── TAB NAVIGATION ── ?>
                        <nav class="tix-nav">
                            <button type="button" class="tix-nav-tab active" data-tab="design">
                                <span class="dashicons dashicons-art"></span>
                                <span class="tix-nav-label">Design</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="buttons">
                                <span class="dashicons dashicons-button"></span>
                                <span class="tix-nav-label">Buttons</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="selector">
                                <span class="dashicons dashicons-tickets-alt"></span>
                                <span class="tix-nav-label">Ticket Selector</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="faq">
                                <span class="dashicons dashicons-editor-help"></span>
                                <span class="tix-nav-label">FAQ</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="checkout">
                                <span class="dashicons dashicons-cart"></span>
                                <span class="tix-nav-label">Checkout</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="express">
                                <span class="dashicons dashicons-performance"></span>
                                <span class="tix-nav-label">Express Checkout</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="my-tickets">
                                <span class="dashicons dashicons-id"></span>
                                <span class="tix-nav-label">Meine Tickets</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="newsletter">
                                <span class="dashicons dashicons-email-alt"></span>
                                <span class="tix-nav-label">Newsletter</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="checkin">
                                <span class="dashicons dashicons-clipboard"></span>
                                <span class="tix-nav-label">Check-in</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="ticket-template">
                                <span class="dashicons dashicons-media-document"></span>
                                <span class="tix-nav-label">Ticket-Template</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="advanced">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <span class="tix-nav-label">Erweitert</span>
                            </button>

                            <?php // ── "Mehr" Button + versteckte Tabs ── ?>
                            <button type="button" class="tix-settings-more-toggle" id="tix-settings-more-btn">
                                <span class="dashicons dashicons-ellipsis"></span>
                                <span class="tix-nav-label">Mehr</span>
                            </button>
                            <div class="tix-settings-more-tabs" id="tix-settings-more-tabs">
                                <button type="button" class="tix-nav-tab" data-tab="data-sync">
                                    <span class="dashicons dashicons-cloud-saved"></span>
                                    <span class="tix-nav-label">Daten-Sync</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="event-page">
                                    <span class="dashicons dashicons-welcome-widgets-menus"></span>
                                    <span class="tix-nav-label">Event-Seite</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="share">
                                    <span class="dashicons dashicons-share"></span>
                                    <span class="tix-nav-label">Share</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="marketing">
                                    <span class="dashicons dashicons-megaphone"></span>
                                    <span class="tix-nav-label">Marketing</span>
                                </button>
                            </div>
                        </nav>

                        <div class="tix-content">

                            <?php // ═══ PANE: DESIGN ═══ ?>
                            <div class="tix-pane active" data-pane="design">

                                <?php // ── Card: Farbpalette ── ?>
                                <div class="tix-card tix-card-preset tix-card-palette">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-color-picker"></span>
                                        <h3>Farbpalette</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Definiere eigene Farben, die dann als Schnellauswahl in allen Farbfeldern erscheinen.</p>

                                        <div class="tix-palette-presets">
                                            <label class="tix-field-label">Palette-Preset laden</label>
                                            <select id="tix-palette-preset-select" class="tix-palette-preset-select">
                                                <option value="">-- Preset wählen --</option>
                                                <option value="festival">Festival</option>
                                                <option value="corporate">Corporate</option>
                                                <option value="elegant">Elegant</option>
                                                <option value="neon">Neon / Dark</option>
                                            </select>
                                        </div>

                                        <div id="tix-palette-repeater" class="tix-palette-repeater">
                                            <?php
                                            $palette = $s['color_palette'] ?? [];
                                            if (!empty($palette)):
                                                foreach ($palette as $i => $entry):
                                                    $pval    = $entry['color'] ?? '#000000';
                                                    $pparsed = self::parse_color($pval);
                                            ?>
                                            <div class="tix-palette-row" data-index="<?php echo $i; ?>">
                                                <input type="text" name="<?php echo $ok; ?>[color_palette][<?php echo $i; ?>][name]"
                                                       value="<?php echo esc_attr($entry['name']); ?>"
                                                       class="tix-palette-name" placeholder="Name (z.B. Primary)">
                                                <div class="tix-color-wrap tix-palette-color-wrap">
                                                    <div class="tix-color-swatch tix-palette-swatch-preview">
                                                        <span class="tix-color-fill" style="background:<?php echo esc_attr($pval); ?>"></span>
                                                        <input type="color" value="<?php echo esc_attr($pparsed['hex']); ?>" tabindex="-1">
                                                    </div>
                                                    <input type="text" name="<?php echo $ok; ?>[color_palette][<?php echo $i; ?>][color]"
                                                           value="<?php echo esc_attr($pval); ?>"
                                                           class="tix-color-hex tix-palette-color-input" placeholder="#000000" maxlength="30">
                                                </div>
                                                <button type="button" class="tix-palette-remove" title="Entfernen">&times;</button>
                                            </div>
                                            <?php
                                                endforeach;
                                            endif;
                                            ?>
                                        </div>

                                        <button type="button" id="tix-palette-add" class="button tix-palette-add-btn">
                                            <span class="dashicons dashicons-plus-alt2"></span> Farbe hinzufügen
                                        </button>
                                    </div>
                                </div>

                                <?php // ── Card: Theme-Umschalter (universell) ── ?>
                                <div class="tix-card tix-card-preset">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-image-rotate"></span>
                                        <h3>Theme-Preset</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Schnellumschalter: setzt alle Farben des Plugins (Design, Selector, FAQ, Check-in, Meine Tickets) auf das gewählte Preset. Einzelne Farben können danach manuell angepasst werden.</p>
                                        <div class="tix-ci-theme-toggle">
                                            <input type="hidden" name="<?php echo $ok; ?>[theme_mode]" id="tix-theme-mode" value="<?php echo esc_attr($s['theme_mode'] ?: 'light'); ?>">
                                            <button type="button" class="tix-ci-theme-btn<?php echo ($s['theme_mode'] ?? 'light') === 'light' ? ' active' : ''; ?>" data-theme="light">
                                                <span class="dashicons dashicons-admin-appearance"></span> Light
                                            </button>
                                            <button type="button" class="tix-ci-theme-btn<?php echo ($s['theme_mode'] ?? 'light') === 'dark' ? ' active' : ''; ?>" data-theme="dark">
                                                <span class="dashicons dashicons-welcome-view-site"></span> Dark
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Farben ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                        <h3>Farben</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('color_text',          'Textfarbe', $s, true);
                                            self::color_row('color_border',        'Rahmenfarbe', $s);
                                            self::color_row('color_input_border',  'Input-Rahmen', $s);
                                            self::color_row('color_focus',         'Fokus- / Auswahlfarbe', $s);
                                            self::color_row('color_sale',          'Sale-Preis', $s);
                                            self::color_row('save_badge_bg',       'Spar-Badge Hintergrund', $s, true);
                                            self::color_row('save_badge_text',     'Spar-Badge Textfarbe', $s, true);
                                            self::color_row('color_success',       'Erfolgs-Farbe', $s);
                                            self::color_row('color_card_bg',       'Karten-Hintergrund', $s, true);
                                            self::color_row('color_input_bg',      'Input-Hintergrund', $s, true);
                                            self::color_row('shortcode_bg',        'Shortcode-Hintergrund', $s, true);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Ecken & Rahmen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-image-crop"></span>
                                        <h3>Ecken &amp; Rahmen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::range_row('radius_general', 'Eckenradius allgemein', $s, 0, 24, 'px');
                                            self::range_row('radius_input',   'Eckenradius Inputs', $s, 0, 20, 'px');
                                            self::range_row('radius_qty_btn', '+/- Button Rundung', $s, 0, 50, '%');
                                            self::range_row('border_width',   'Rahmenbreite', $s, 0, 4, 'px');
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Größen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-editor-expand"></span>
                                        <h3>Gr&ouml;&szlig;en</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::range_row('qty_btn_size',    '+/- Button Größe', $s, 24, 48, 'px');
                                            self::range_row('gap',             'Abstand zwischen Elementen', $s, 4, 24, 'px');
                                            self::range_row('checkout_width',  'Checkout Maximalbreite', $s, 400, 1600, 'px', 10);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Schriftgrößen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-editor-textcolor"></span>
                                        <h3>Schriftgr&ouml;&szlig;en</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::range_row('font_price', 'Preis', $s, 0.7, 2, 'rem', 0.05, true);
                                            self::range_row('font_name',  'Kategorie-Name', $s, 0.7, 2, 'rem', 0.05, true);
                                            self::range_row('font_total', 'Gesamtpreis', $s, 0.8, 2.5, 'rem', 0.05, true);
                                            self::range_row('font_desc',  'Beschreibung', $s, 0.6, 1.5, 'rem', 0.05, true);
                                            self::range_row('font_vat',   'MwSt.-Hinweis', $s, 0.5, 1.5, 'rem', 0.05, true);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: BUTTONS ═══ ?>
                            <div class="tix-pane" data-pane="buttons">

                                <?php // ── Card: Variante 1 (Primär) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-button"></span>
                                        <h3>Variante 1 &ndash; Prim&auml;r (CTA)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint">Wird f&uuml;r Ticket-Kauf, Modal-Trigger, Raffle und alle Haupt-CTAs verwendet. Shortcode: <code>variant="1"</code></p>
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('btn1_bg',          'Hintergrund', $s);
                                            self::color_row('btn1_color',       'Textfarbe', $s);
                                            self::color_row('btn1_hover_bg',    'Hover-Hintergrund', $s, true);
                                            self::color_row('btn1_hover_color', 'Hover-Textfarbe', $s, true);
                                            self::range_row('btn1_radius',      'Eckenradius', $s, 0, 50, 'px');
                                            self::text_row('btn1_border',       'Rahmen (CSS)', $s, 'z.B. 2px solid #000');
                                            self::range_row('btn1_font_size',   'Schriftgr&ouml;&szlig;e', $s, 0.7, 2, 'rem', 0.05, true);
                                            ?>
                                        </div>
                                        <p class="tix-settings-hint" style="margin-top:12px;">Schriftart: inherit (Theme) &middot; Schriftgewicht: 700</p>
                                    </div>
                                </div>

                                <?php // ── Card: Variante 2 (Sekundär) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-customizer"></span>
                                        <h3>Variante 2 &ndash; Sekund&auml;r (Outline)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint">Wird f&uuml;r Kalender-Button, Coupon-Button und sekund&auml;re Aktionen verwendet. Shortcode: <code>variant="2"</code></p>
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('btn2_bg',          'Hintergrund', $s, true);
                                            self::color_row('btn2_color',       'Textfarbe', $s, true);
                                            self::color_row('btn2_hover_bg',    'Hover-Hintergrund', $s, true);
                                            self::color_row('btn2_hover_color', 'Hover-Textfarbe', $s, true);
                                            self::range_row('btn2_radius',      'Eckenradius', $s, 0, 50, 'px');
                                            self::text_row('btn2_border',       'Rahmen (CSS)', $s, 'z.B. 1px solid currentColor');
                                            self::range_row('btn2_font_size',   'Schriftgr&ouml;&szlig;e', $s, 0.7, 2, 'rem', 0.05, true);
                                            ?>
                                        </div>
                                        <p class="tix-settings-hint" style="margin-top:12px;">Schriftart: inherit (Theme) &middot; Schriftgewicht: 700</p>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: TICKET SELECTOR ═══ ?>
                            <div class="tix-pane" data-pane="selector">

                                <?php // ── Card: Texte & Optionen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-edit"></span>
                                        <h3>Texte &amp; Optionen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::text_row('btn_text_buy', 'Button-Text', $s);
                                            self::text_row('vat_text_selector', 'MwSt.-Hinweis', $s, 'inkl. MwSt.');
                                            ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('show_coupon_selector', 'Gutscheincode-Eingabe auf der Event-Seite anzeigen', $s); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Farben ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                        <h3>Farben</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('sel_bg',            'Kategorie-Hintergrund', $s, true);
                                            self::color_row('sel_border_color',  'Rahmenfarbe', $s, true);
                                            self::color_row('sel_active_border', 'Aktiver Rahmen', $s, true);
                                            self::color_row('sel_active_bg',     'Aktiver Hintergrund', $s, true);
                                            self::color_row('sel_hover_text',    'Hover-Textfarbe', $s, true);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Layout ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-layout"></span>
                                        <h3>Layout</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::range_row('sel_border_width', 'Rahmenbreite', $s, 0, 4, 'px');
                                            self::range_row('sel_radius',       'Eckenradius', $s, 0, 24, 'px');
                                            self::range_row('sel_padding_v',    'Padding vertikal', $s, 4, 32, 'px');
                                            self::range_row('sel_padding_h',    'Padding horizontal', $s, 4, 40, 'px');
                                            self::range_row('sel_max_width',    'Max. Breite (0 = keine)', $s, 0, 1600, 'px', 10);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: FAQ ═══ ?>
                            <div class="tix-pane" data-pane="faq">

                                <?php // ── Card: Farben ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                        <h3>Farben</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('faq_bg',            'Frage-Hintergrund', $s, true);
                                            self::color_row('faq_list_bg',       'Listen-Hintergrund', $s, true);
                                            self::color_row('faq_hover_bg',      'Hover-Hintergrund', $s, true);
                                            self::color_row('faq_hover_text',    'Hover-Textfarbe', $s, true);
                                            self::color_row('faq_active_bg',     'Aktiver Eintrag', $s, true);
                                            self::color_row('faq_border_color',  'Rahmenfarbe', $s, true);
                                            self::color_row('faq_divider_color', 'Trennlinien-Farbe', $s, true);
                                            self::color_row('faq_accent_color',  'Akzentlinie', $s, true);
                                            self::color_row('faq_icon_color',    'Icon-Farbe', $s, true);
                                            self::color_row('faq_link_color',    'Link-Farbe', $s, true);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Layout & Typografie ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-editor-textcolor"></span>
                                        <h3>Layout &amp; Typografie</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::range_row('faq_radius',         'Eckenradius', $s, 0, 24, 'px');
                                            self::range_row('faq_border_width',   'Rahmenbreite', $s, 0, 4, 'px');
                                            self::range_row('faq_accent_width',   'Akzentlinie Breite', $s, 0, 8, 'px');
                                            self::range_row('faq_icon_size',      'Icon Größe', $s, 12, 48, 'px');
                                            self::range_row('faq_max_width',      'Maximale Breite', $s, 300, 1600, 'px', 10);
                                            self::range_row('faq_padding_v',      'Padding vertikal', $s, 4, 32, 'px');
                                            self::range_row('faq_padding_h',      'Padding horizontal', $s, 8, 40, 'px');
                                            self::range_row('faq_title_size',     'Titel-Schriftgröße', $s, 0.8, 2.5, 'rem', 0.05, true);
                                            self::range_row('faq_question_size',  'Frage-Schriftgröße', $s, 0.7, 2, 'rem', 0.05, true);
                                            self::range_row('faq_answer_size',    'Antwort-Schriftgröße', $s, 0.7, 1.5, 'rem', 0.05, true);
                                            self::range_row('faq_answer_opacity', 'Antwort-Deckkraft', $s, 0.3, 1, '', 0.05, true);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: CHECKOUT ═══ ?>
                            <div class="tix-pane" data-pane="checkout">

                                <?php // ── Card: Texte ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-edit"></span>
                                        <h3>Texte</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::text_row('btn_text_checkout', 'Button-Text', $s);
                                            self::text_row('vat_text_checkout', 'MwSt.-Hinweis', $s, 'inkl. MwSt.');
                                            self::text_row('shipping_text',    'Versand-Hinweis', $s);
                                            self::text_row('empty_text',       'Leerer Warenkorb: Text', $s);
                                            self::text_row('empty_link_text',  'Leerer Warenkorb: Link', $s);
                                            self::text_row('terms_url',       'Nutzungsbedingungen URL', $s, '/agb');
                                            self::text_row('privacy_url',     'Datenschutz URL', $s, '/datenschutz');
                                            self::text_row('revocation_url',  'Widerrufsbelehrung URL', $s, '/widerruf');
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Verhalten ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-settings"></span>
                                        <h3>Verhalten</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('skip_cart', 'Nach Ticket-Auswahl direkt zum Checkout', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('force_email_shipping', 'Tickets per E-Mail versenden (kostenloser Versand)', $s, 'Wenn deaktiviert, greift das Standard-WooCommerce-Versandsystem.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('show_company_field', 'Firma-Feld im Checkout anzeigen (optional aufklappbar)', $s); ?>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-field-label">Button-Variante</label>
                                                <select name="<?php echo self::OPTION_KEY; ?>[checkout_btn_variant]" class="tix-select-input">
                                                    <option value="1" <?php selected($s['checkout_btn_variant'] ?? '1', '1'); ?>>Variante 1 (Primär)</option>
                                                    <option value="2" <?php selected($s['checkout_btn_variant'] ?? '1', '2'); ?>>Variante 2 (Sekundär)</option>
                                                </select>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('checkout_steps', 'Checkout als 3-Schritte-Prozess', $s, '1) Ticket-Übersicht → 2) Rechnungsadresse → 3) Zahlung & Abschluss'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('checkout_countdown', 'Countdown-Timer im Checkout anzeigen', $s, 'Nach Ablauf wird der Warenkorb automatisch geleert.'); ?>
                                            </div>
                                            <?php self::range_row('checkout_countdown_minutes', 'Countdown-Zeit', $s, 1, 30, ' Min.'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('group_booking', '„Gemeinsam buchen" auf Event-Seiten anzeigen', $s, 'Ermöglicht Gruppenbestellungen: Organisator teilt Link, Freunde wählen eigene Tickets, eine Bestellung.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: EXPRESS CHECKOUT ═══ ?>
                            <div class="tix-pane" data-pane="express">

                                <?php // ── Card: Modal ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-editor-expand"></span>
                                        <h3>Modal</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('ec_modal_bg',     'Hintergrund', $s, true);
                                            self::color_row('ec_modal_border', 'Rahmenfarbe', $s, true);
                                            self::range_row('ec_modal_radius',    'Eckenradius', $s, 0, 24, 'px');
                                            self::range_row('ec_modal_max_width', 'Max. Breite', $s, 320, 800, 'px', 10);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Ticket-Kategorien ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-tickets-alt"></span>
                                        <h3>Ticket-Kategorien</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('ec_cat_border',  'Rahmenfarbe', $s, true);
                                            self::color_row('ec_cat_active',  'Aktiver Rahmen', $s, true);
                                            self::range_row('ec_cat_radius',  'Eckenradius', $s, 0, 24, 'px');
                                            ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: NEWSLETTER ═══ ?>
                            <div class="tix-pane" data-pane="newsletter">

                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-settings"></span>
                                        <h3>Einstellungen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('newsletter_enabled', 'Newsletter-Anmeldung im Checkout anzeigen', $s, 'Zeigt eine Opt-in-Checkbox mit Kontaktfeld am Ende des Checkouts.'); ?>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-label">Typ</label>
                                                <select name="<?php echo self::OPTION_KEY; ?>[newsletter_type]" class="tix-input">
                                                    <option value="email" <?php selected($s['newsletter_type'], 'email'); ?>>E-Mail</option>
                                                    <option value="whatsapp" <?php selected($s['newsletter_type'], 'whatsapp'); ?>>WhatsApp</option>
                                                </select>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-label">Label (optional)</label>
                                                <input type="text" name="<?php echo self::OPTION_KEY; ?>[newsletter_label]" value="<?php echo esc_attr($s['newsletter_label']); ?>" class="tix-input" placeholder="Ich möchte den Newsletter erhalten">
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Webhook URL (optional)</label>
                                                <input type="url" name="<?php echo self::OPTION_KEY; ?>[newsletter_webhook]" value="<?php echo esc_attr($s['newsletter_webhook']); ?>" class="tix-input" placeholder="https://hooks.example.com/newsletter">
                                                <p class="description">Bei jeder Anmeldung wird ein POST-Request mit den Daten an diese URL gesendet.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-media-text"></span>
                                        <h3>Rechtlicher Hinweis</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Text unter der Newsletter-Checkbox</label>
                                                <textarea name="<?php echo self::OPTION_KEY; ?>[newsletter_legal]" class="tix-input" rows="4" placeholder="Mit der Anmeldung stimmst du zu, regelmäßig Informationen zu erhalten. Du kannst dich jederzeit abmelden."><?php echo esc_textarea($s['newsletter_legal'] ?? ''); ?></textarea>
                                                <p class="description">Wird im Checkout unter der Newsletter-Checkbox als kleiner Hinweistext angezeigt. Leer = kein Hinweis.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: MEINE TICKETS ═══ ?>
                            <div class="tix-pane" data-pane="my-tickets">

                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                        <h3>Farben</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('mt_bg',           'Seitenhintergrund', $s);
                                            self::color_row('mt_card_bg',      'Karten-Hintergrund', $s);
                                            self::color_row('mt_border_color', 'Rahmenfarbe', $s);
                                            self::color_row('mt_ticket_bg',    'Ticket-Karten Hintergrund', $s);
                                            self::color_row('mt_accent_color', 'Akzentfarbe (leer = global)', $s, true);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: CHECK-IN ═══ ?>
                            <div class="tix-pane" data-pane="checkin">

                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                        <h3>Farben</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('ci_bg',          'Hintergrund', $s);
                                            self::color_row('ci_surface',     'Karten / Oberfläche', $s);
                                            self::color_row('ci_border',      'Rahmenfarbe', $s);
                                            self::color_row('ci_text',        'Textfarbe', $s);
                                            self::color_row('ci_muted',       'Sekundärtext', $s);
                                            self::color_row('ci_accent',      'Akzent / Buttons', $s);
                                            self::color_row('ci_accent_text', 'Button-Textfarbe', $s);
                                            self::color_row('ci_ok',          'Erfolg (Check-in OK)', $s);
                                            self::color_row('ci_warn',        'Warnung (bereits eingecheckt)', $s);
                                            self::color_row('ci_err',         'Fehler (nicht gefunden)', $s);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-clock"></span>
                                        <h3>Scan-Feedback</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Erfolgsmeldung Anzeigedauer</label>
                                                <div class="tix-field-input">
                                                    <input type="number" name="<?php echo $ok; ?>[ci_popup_duration]" value="<?php echo esc_attr($s['ci_popup_duration']); ?>" min="1" max="30" step="1" style="width:70px;"> Sekunden
                                                    <p class="tix-field-desc">Wie lange die Meldung nach einem Scan sichtbar bleibt (1–30 Sekunden).</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-info-outline"></span>
                                        <h3>Hinweis</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint">
                                            Diese Farben gelten für die Check-in-Seite (<code>[tix_checkin]</code> Shortcode) und die Gast-QR-Seite.<br>
                                            Die Check-in-Seite ist für mobile Geräte optimiert (Türpersonal am Einlass).<br>
                                            Der Check-in erkennt automatisch Gästelisten-Codes (<code>GL-*</code>) und Ticket-Codes (<code>TIX-*</code>).
                                        </p>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: TICKET-TEMPLATE ═══ ?>
                            <div class="tix-pane" data-pane="ticket-template">

                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-media-document"></span>
                                        <h3>Ticket-Vorlage</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Lade ein JPG/PNG-Bild als Ticket-Hintergrund hoch und positioniere die Felder per Drag &amp; Drop. Dieses Template wird als globaler Standard verwendet. Einzelne Events können ein eigenes Template verwenden.</p>
                                        <?php
                                        $gd_info = TIX_Ticket_Template::check_gd_support();
                                        if (!$gd_info['gd']): ?>
                                            <div class="tix-tte-gd-warning">
                                                <strong>⚠ PHP GD-Extension nicht geladen.</strong> Das Template-System benötigt die GD-Extension mit FreeType-Support. Bitte aktiviere <code>extension=gd</code> in deiner php.ini.
                                            </div>
                                        <?php elseif (!$gd_info['freetype']): ?>
                                            <div class="tix-tte-gd-warning">
                                                <strong>⚠ FreeType nicht verfügbar.</strong> Textfelder werden mit einfacher Schrift gerendert. Für optimale Ergebnisse compiliere PHP mit <code>--with-freetype</code>.
                                            </div>
                                        <?php endif; ?>

                                        <div id="tix-tte-settings-editor" class="tix-tte-wrap"></div>
                                        <input type="hidden" name="<?php echo $ok; ?>[ticket_template]" id="tix-tte-settings-input" value="<?php echo esc_attr($s['ticket_template'] ?: ''); ?>">
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: DATEN-SYNC ═══ ?>
                            <div class="tix-pane" data-pane="data-sync">

                                <?php // ── Card: Custom Datenbank ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-database"></span>
                                        <h3>Custom Ticket-Datenbank</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Status</label>
                                                <div class="tix-field-input">
                                                    <label class="tix-toggle">
                                                        <input type="checkbox" name="<?php echo $ok; ?>[ticket_db_enabled]" value="1" <?php checked($s['ticket_db_enabled']); ?>>
                                                        <span>Aktivieren</span>
                                                    </label>
                                                    <p class="tix-field-desc">Alle Ticketdaten (inkl. Käuferdaten, Newsletter-Optin) in einer eigenen Datenbanktabelle speichern.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Supabase ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-cloud-upload"></span>
                                        <h3>Supabase</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Status</label>
                                                <div class="tix-field-input">
                                                    <label class="tix-toggle">
                                                        <input type="checkbox" name="<?php echo $ok; ?>[supabase_enabled]" value="1" <?php checked($s['supabase_enabled']); ?>>
                                                        <span>Supabase-Sync aktivieren</span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Project URL</label>
                                                <div class="tix-field-input">
                                                    <input type="url" name="<?php echo $ok; ?>[supabase_url]" value="<?php echo esc_attr($s['supabase_url']); ?>" class="regular-text" placeholder="https://xxxxx.supabase.co">
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">API Key</label>
                                                <div class="tix-field-input">
                                                    <input type="password" name="<?php echo $ok; ?>[supabase_api_key]" value="<?php echo esc_attr($s['supabase_api_key']); ?>" class="regular-text" autocomplete="off">
                                                    <p class="tix-field-desc">Supabase <code>anon</code> oder <code>service_role</code> Key.</p>
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Tabelle</label>
                                                <div class="tix-field-input">
                                                    <input type="text" name="<?php echo $ok; ?>[supabase_table]" value="<?php echo esc_attr($s['supabase_table']); ?>" class="regular-text" placeholder="tickets">
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">&nbsp;</label>
                                                <div class="tix-field-input">
                                                    <button type="button" class="button tix-sync-test" data-service="supabase">Verbindung testen</button>
                                                    <button type="button" class="button tix-sync-all" data-service="supabase">Alle jetzt synchronisieren</button>
                                                    <span class="tix-sync-status" data-service="supabase"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Airtable ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-cloud-upload"></span>
                                        <h3>Airtable</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Status</label>
                                                <div class="tix-field-input">
                                                    <label class="tix-toggle">
                                                        <input type="checkbox" name="<?php echo $ok; ?>[airtable_enabled]" value="1" <?php checked($s['airtable_enabled']); ?>>
                                                        <span>Airtable-Sync aktivieren</span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">API Key (PAT)</label>
                                                <div class="tix-field-input">
                                                    <input type="password" name="<?php echo $ok; ?>[airtable_api_key]" value="<?php echo esc_attr($s['airtable_api_key']); ?>" class="regular-text" autocomplete="off">
                                                    <p class="tix-field-desc">Airtable Personal Access Token.</p>
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Base ID</label>
                                                <div class="tix-field-input">
                                                    <input type="text" name="<?php echo $ok; ?>[airtable_base_id]" value="<?php echo esc_attr($s['airtable_base_id']); ?>" class="regular-text" placeholder="appXXXXXXXXXXXXXX">
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">Tabelle</label>
                                                <div class="tix-field-input">
                                                    <input type="text" name="<?php echo $ok; ?>[airtable_table]" value="<?php echo esc_attr($s['airtable_table']); ?>" class="regular-text" placeholder="Tickets">
                                                </div>
                                            </div>
                                            <div class="tix-field-row">
                                                <label class="tix-field-label">&nbsp;</label>
                                                <div class="tix-field-input">
                                                    <button type="button" class="button tix-sync-test" data-service="airtable">Verbindung testen</button>
                                                    <button type="button" class="button tix-sync-all" data-service="airtable">Alle jetzt synchronisieren</button>
                                                    <span class="tix-sync-status" data-service="airtable"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: ERWEITERT ═══ ?>
                            <?php // ═══ PANE: EVENT-SEITE ═══ ?>
                            <div class="tix-pane" data-pane="event-page">

                                <?php // ── Card: Layout ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-align-wide"></span>
                                        <h3>Layout</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Einstellungen f&uuml;r den <code>[tix_event_page]</code> Shortcode. Steuert Layout, Farben und sichtbare Sektionen der Event-Detailseite.</p>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Layout-Modus</label>
                                                <div style="display:flex;gap:12px;margin-top:6px;">
                                                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px 16px;border:2px solid <?php echo $s['ep_layout'] === '2col' ? 'var(--tix-admin-accent,#FF5500)' : '#e5e7eb'; ?>;border-radius:8px;background:<?php echo $s['ep_layout'] === '2col' ? 'rgba(255,85,0,.06)' : '#fff'; ?>;">
                                                        <input type="radio" name="tix_settings[ep_layout]" value="2col" <?php checked($s['ep_layout'], '2col'); ?> style="margin:0;">
                                                        <span><strong>2 Spalten</strong><br><small style="color:#64748b;">Content links, Sidebar rechts (sticky)</small></span>
                                                    </label>
                                                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px 16px;border:2px solid <?php echo $s['ep_layout'] === '1col' ? 'var(--tix-admin-accent,#FF5500)' : '#e5e7eb'; ?>;border-radius:8px;background:<?php echo $s['ep_layout'] === '1col' ? 'rgba(255,85,0,.06)' : '#fff'; ?>;">
                                                        <input type="radio" name="tix_settings[ep_layout]" value="1col" <?php checked($s['ep_layout'], '1col'); ?> style="margin:0;">
                                                        <span><strong>1 Spalte</strong><br><small style="color:#64748b;">Alles untereinander, zentriert</small></span>
                                                    </label>
                                                </div>
                                            </div>
                                            <?php self::range_row('ep_max_width', 'Max. Breite', $s, 400, 1600, 'px', 10); ?>
                                            <?php self::range_row('ep_gap', 'Sektions-Abstand', $s, 12, 48, 'px'); ?>
                                            <?php self::range_row('ep_radius', 'Eckenradius', $s, 0, 24, 'px'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Farben ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                        <h3>Farben</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::color_row('ep_bg', 'Hintergrund', $s, true); ?>
                                            <?php self::color_row('ep_text', 'Textfarbe', $s, true); ?>
                                            <?php self::color_row('ep_muted', 'Ged&auml;mpfte Farbe', $s, true); ?>
                                            <?php self::color_row('ep_border', 'Rahmenfarbe', $s, true); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Sektionen ein-/ausblenden ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-visibility"></span>
                                        <h3>Sichtbare Sektionen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">W&auml;hle aus, welche Sektionen auf der Event-Seite angezeigt werden sollen. Sektionen ohne Daten werden automatisch ausgeblendet.</p>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Ticket-Darstellung</label>
                                                <select name="<?php echo $ok; ?>[ep_ticket_mode]" class="tix-input" style="max-width:280px;">
                                                    <option value="selector" <?php selected($s['ep_ticket_mode'] ?? 'selector', 'selector'); ?>>Ticket-Selektor (Standard)</option>
                                                    <option value="modal" <?php selected($s['ep_ticket_mode'] ?? 'selector', 'modal'); ?>>Modal-Checkout</option>
                                                    <option value="both" <?php selected($s['ep_ticket_mode'] ?? 'selector', 'both'); ?>>Beides (Selektor + Modal-Button)</option>
                                                </select>
                                                <p class="tix-settings-hint">Modal-Checkout: Kompletter Ticket-Kauf inkl. Bezahlung im Modal ohne Seitenwechsel.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_hero', 'Hero-Bild (Beitragsbild im 16:9 Format)', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_gallery', 'Galerie', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_video', 'Video', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_faq', 'FAQ', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_location', 'Veranstaltungsort', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_organizer', 'Veranstalter', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_series', 'Serientermine', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_charity', 'Soziales Projekt', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_upsell', '&Auml;hnliche Events (Upsell)', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_calendar', 'Kalender-Button', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_phases', 'Preisphasen im Ticket-Selektor anzeigen', $s, 'Zeigt alle Preisphasen (Early Bird, Regular, etc.) als Timeline unter jeder Ticket-Kategorie an, zusammen mit dem regul&auml;ren Hauptpreis.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_raffle', 'Gewinnspiel', $s, 'Zeigt das Gewinnspiel-Formular auf der Event-Seite an, wenn f&uuml;r das Event ein Gewinnspiel aktiviert ist.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_share', 'Share-Buttons', $s, 'Zeigt Social-Sharing-Buttons (WhatsApp, Facebook, X, E-Mail, Link kopieren) auf der Event-Seite an.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_show_timetable', 'Programm / Timetable', $s, 'Zeigt das mehrt&auml;gige Programm mit B&uuml;hnen-Grid auf der Event-Seite an (Shortcode: [tix_timetable]).'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('waitlist_enabled', 'Warteliste / Presale-Benachrichtigung', $s, 'Erm&ouml;glicht E-Mail-Benachrichtigungen: Countdown vor Vorverkaufsstart + Warteliste bei ausverkauften Tickets.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('feedback_enabled', 'Post-Event Feedback', $s, 'Sterne-Bewertung + Kommentar nach dem Event. Wird in der Follow-Up E-Mail eingebettet und als Shortcode [tix_feedback] verf&uuml;gbar.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label" for="tix_low_stock_threshold">&bdquo;Letzte X Tickets&ldquo;-Anzeige</label>
                                                <div style="display:flex;align-items:center;gap:8px;">
                                                    <input type="number" id="tix_low_stock_threshold" name="tix_settings[low_stock_threshold]"
                                                           value="<?php echo intval($s['low_stock_threshold'] ?? 10); ?>"
                                                           min="0" max="999" step="1" class="tix-input" style="width:80px;">
                                                    <span class="tix-hint" style="margin:0;">Zeigt &bdquo;Nur noch X verf&uuml;gbar!&ldquo; wenn der Bestand unter diesen Wert f&auml;llt. 0 = deaktiviert.</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: SHARE ═══ ?>
                            <div class="tix-pane" data-pane="share">

                                <?php // ── Card: Kanäle ── ?>
                                <div class="tix-card">
                                    <h3 class="tix-card-title">Share-Kanäle</h3>
                                    <p class="tix-settings-hint" style="margin-bottom:12px;">Wähle die Kanäle aus, die standardmäßig angezeigt werden sollen. Per Shortcode-Attribut <code>channels="wa,copy"</code> lässt sich die Auswahl individuell überschreiben.</p>
                                    <?php
                                    $share_channels_active = array_map('trim', explode(',', $s['share_channels'] ?? ''));
                                    $all_share_channels = [
                                        'wa'     => 'WhatsApp',
                                        'tg'     => 'Telegram',
                                        'fb'     => 'Facebook',
                                        'x'      => 'X (Twitter)',
                                        'li'     => 'LinkedIn',
                                        'pi'     => 'Pinterest',
                                        'rd'     => 'Reddit',
                                        'email'  => 'E-Mail',
                                        'sms'    => 'SMS',
                                        'copy'   => 'Link kopieren',
                                        'native' => 'Teilen… (System-Dialog)',
                                    ];
                                    ?>
                                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin-bottom:16px;">
                                        <?php foreach ($all_share_channels as $ch_key => $ch_label): ?>
                                            <label style="display:flex;align-items:center;gap:6px;font-size:.9rem;cursor:pointer;">
                                                <input type="checkbox" class="tix-share-ch-toggle" data-channel="<?php echo esc_attr($ch_key); ?>"
                                                       <?php checked(in_array($ch_key, $share_channels_active)); ?>>
                                                <?php echo esc_html($ch_label); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="<?php echo self::OPTION_KEY; ?>[share_channels]" id="tix-share-channels-hidden"
                                           value="<?php echo esc_attr($s['share_channels'] ?? ''); ?>">
                                </div>

                                <?php // ── Card: Darstellung ── ?>
                                <div class="tix-card">
                                    <h3 class="tix-card-title">Darstellung</h3>
                                    <div class="tix-fields">
                                        <?php self::text_row('share_label', 'Label-Text', $s, 'Teilen'); ?>
                                        <div class="tix-field">
                                            <label class="tix-field-label">Stil</label>
                                            <select name="<?php echo self::OPTION_KEY; ?>[share_style]" class="tix-select-input">
                                                <option value="icon" <?php selected($s['share_style'] ?? 'icon', 'icon'); ?>>Nur Icons</option>
                                                <option value="label" <?php selected($s['share_style'] ?? 'icon', 'label'); ?>>Icons + Text</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Shortcode-Info ── ?>
                                <div class="tix-card">
                                    <h3 class="tix-card-title">Verwendung</h3>
                                    <p class="tix-settings-hint" style="margin:0 0 8px;">Shortcode: <code>[tix_share]</code></p>
                                    <p class="tix-settings-hint" style="margin:0 0 4px;">Optionale Attribute:</p>
                                    <ul class="tix-settings-hint" style="margin:0;padding-left:20px;font-size:.85rem;line-height:1.6;">
                                        <li><code>channels="wa,tg,copy"</code> – Nur bestimmte Kanäle anzeigen</li>
                                        <li><code>label="Jetzt teilen"</code> – Eigener Label-Text</li>
                                        <li><code>style="label"</code> – Icons mit Text</li>
                                        <li><code>id="123"</code> – Spezifische Post-ID</li>
                                    </ul>
                                </div>

                            </div>

                            <?php // ═══ PANE: MARKETING ═══ ?>
                            <div class="tix-pane" data-pane="marketing">

                                <?php // ── Card: VIP-Erkennung ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-awards"></span>
                                        <h3>VIP-Erkennung</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('vip_enabled',
                                                    'VIP-Erkennung aktivieren', $s,
                                                    'Wiederkehrende K&auml;ufer werden automatisch als VIP markiert.'); ?>
                                            </div>
                                            <?php self::text_row('vip_badge_label', 'Badge-Text', $s, 'VIP'); ?>
                                            <?php self::text_row('vip_min_tickets', 'Mindest-Tickets', $s, '5'); ?>
                                            <?php self::text_row('vip_min_orders', 'Mindest-Bestellungen', $s, '3'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">Kunde wird VIP wenn <strong>Tickets &ge; Mindest-Tickets</strong> ODER <strong>Bestellungen &ge; Mindest-Bestellungen</strong>.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('vip_discount_enabled',
                                                    'Automatischer VIP-Rabatt', $s,
                                                    'VIP-Kunden erhalten automatisch einen Rabatt im Warenkorb.'); ?>
                                            </div>
                                            <div class="tix-field">
                                                <label>Rabatt-Typ</label>
                                                <select name="tix_settings[vip_discount_type]">
                                                    <option value="percent" <?php selected($s['vip_discount_type'], 'percent'); ?>>Prozent (%)</option>
                                                    <option value="fixed" <?php selected($s['vip_discount_type'], 'fixed'); ?>>Festbetrag (&euro;)</option>
                                                </select>
                                            </div>
                                            <?php self::text_row('vip_discount_value', 'Rabatt-Wert', $s, '10'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Social Proof ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-groups"></span>
                                        <h3>Social Proof</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('social_proof_enabled',
                                                    'Social Proof aktivieren', $s,
                                                    'Zeigt die Anzahl aktueller Besucher auf Event-Seiten an.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::text_row('social_proof_text', 'Anzeigetext', $s,
                                                    '{count} Personen sehen sich dieses Event gerade an'); ?>
                                            </div>
                                            <?php self::text_row('social_proof_min_count', 'Minimum f&uuml;r Anzeige', $s, '2'); ?>
                                            <?php self::range_row('social_proof_multiplier', 'Multiplikator', $s, 1.0, 3.0, 0.1); ?>
                                            <?php self::text_row('social_proof_interval', 'Intervall (Sek.)', $s, '30'); ?>
                                            <div class="tix-field">
                                                <label>Position</label>
                                                <select name="tix_settings[social_proof_position]">
                                                    <option value="above_tickets" <?php selected($s['social_proof_position'], 'above_tickets'); ?>>Über Ticket-Selektor</option>
                                                    <option value="below_hero" <?php selected($s['social_proof_position'], 'below_hero'); ?>>Unter Hero-Bild</option>
                                                    <option value="floating" <?php selected($s['social_proof_position'], 'floating'); ?>>Schwebend (unten links)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Exit-Intent Popup ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-migrate"></span>
                                        <h3>Exit-Intent Popup</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('exit_intent_enabled',
                                                    'Exit-Intent Popup aktivieren', $s,
                                                    'Zeigt einen Rabattcode wenn der Besucher die Seite verlassen will.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::text_row('exit_intent_coupon_code', 'WooCommerce Coupon-Code', $s, 'z.B. BLEIB10'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::text_row('exit_intent_headline', '&Uuml;berschrift', $s, 'Warte! Hier ist dein Rabatt'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label>&Uuml;berschrift</label>
                                                <textarea name="tix_settings[exit_intent_text]" rows="2" class="large-text"><?php echo esc_textarea($s['exit_intent_text']); ?></textarea>
                                                <p class="tix-settings-hint">Platzhalter: <code>{discount}</code> wird durch den Coupon-Rabatt ersetzt.</p>
                                            </div>
                                            <?php self::text_row('exit_intent_button_text', 'Button-Text', $s, 'Jetzt einl&ouml;sen'); ?>
                                            <?php self::text_row('exit_intent_cookie_days', 'Nicht erneut zeigen (Tage)', $s, '7'); ?>
                                            <?php self::text_row('exit_intent_delay', 'Verz&ouml;gerung (Sek.)', $s, '5'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Marketing-Export ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-email-alt"></span>
                                        <h3>Marketing-Export (Mailchimp / Brevo)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('marketing_export_enabled',
                                                    'Marketing-Export aktivieren', $s,
                                                    'Erm&ouml;glicht den segmentierten CSV-Export von Kundendaten f&uuml;r Mailchimp, Brevo etc.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">Nach Aktivierung erscheint unter <strong>Tixomat &rarr; Marketing Export</strong> die Export-Seite mit Filter-Optionen.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Kampagnen-Tracking ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-chart-bar"></span>
                                        <h3>Kampagnen-Tracking</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('campaign_tracking_enabled',
                                                    'Kampagnen-Tracking aktivieren', $s,
                                                    'Trackt Besucher-Quellen per URL-Parameter (?tix_src=...) und ordnet Ticket-Verk&auml;ufe zu. DSGVO-konform, keine personenbezogenen Daten.'); ?>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-field-label">Cookie-Laufzeit (Tage)</label>
                                                <input type="number" name="tix_settings[campaign_cookie_days]"
                                                       value="<?php echo intval($s['campaign_cookie_days'] ?? 30); ?>"
                                                       class="small-text" min="1" max="365" step="1">
                                                <p class="tix-settings-hint">First-Touch Attribution: Der erste Kanal wird gespeichert.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    Nach Aktivierung erscheint unter <strong>Tixomat &rarr; Kampagnen</strong> das Analytics-Dashboard.
                                                    Im Event-Editor wird ein Tab &bdquo;Kampagnen&ldquo; mit fertigem Link-Generator angezeigt.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="tix-pane" data-pane="advanced">

                                <?php // ── Card: Google Places ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-location"></span>
                                        <h3>Google Places Autocomplete</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::text_row('google_api_key', 'Google API Key', $s, 'AIza…'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    Folgende APIs müssen in der <a href="https://console.cloud.google.com/apis/library" target="_blank">Google Cloud Console</a> aktiviert sein:<br>
                                                    <strong>1.</strong> Places API (New) &nbsp; <strong>2.</strong> Maps JavaScript API &nbsp; <strong>3.</strong> Geocoding API<br>
                                                    Der API-Key benötigt HTTP-Referrer-Beschr&auml;nkung auf deine Domain.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Upselling ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-megaphone"></span>
                                        <h3>Upselling</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('show_upsell', 'Verwandte Events auf der Danke-Seite anzeigen', $s); ?>
                                            </div>
                                            <?php self::range_row('upsell_count', 'Anzahl Events', $s, 1, 6, '', 1); ?>
                                            <?php self::text_row('upsell_heading', 'Überschrift', $s, 'Das könnte dich auch interessieren'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: E-Mail ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-email-alt"></span>
                                        <h3>E-Mail-Templates</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::text_row('email_logo_url', 'Logo-URL', $s, 'https://example.com/logo.png'); ?>
                                            <?php self::text_row('email_brand_name', 'Firmenname', $s, get_bloginfo('name')); ?>
                                            <?php self::text_row('email_footer_text', 'Footer-Text', $s, 'Du erhältst diese E-Mail, weil du eine Bestellung aufgegeben hast.'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('email_reminder', 'Erinnerungsmail 24h vor dem Event senden', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('email_followup', 'Nachbefragungsmail 24h nach dem Event senden', $s); ?>
                                            </div>
                                            <?php self::text_row('email_followup_url', 'Feedback-Link', $s, 'https://example.com/feedback'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    Alle WooCommerce-E-Mails werden automatisch im Tixomat Design versendet.<br>
                                                    Die Farben (Akzent, Rahmen) werden aus dem Design-Tab übernommen.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Abandoned Cart Recovery ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-cart"></span>
                                        <h3>Verlassene Warenkörbe</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('abandoned_cart_enabled', 'Abandoned Cart Recovery aktivieren', $s, 'Sendet automatisch eine Erinnerungsmail, wenn ein Checkout nicht abgeschlossen wird. Muss zusätzlich pro Event aktiviert werden.'); ?>
                                            </div>
                                            <?php self::range_row('abandoned_cart_delay', 'Verzögerung', $s, 5, 120, ' Min.', 5); ?>
                                            <?php self::text_row('abandoned_cart_subject', 'E-Mail-Betreff (optional)', $s, 'Du hast noch Tickets im Warenkorb'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Express Checkout ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-performance"></span>
                                        <h3>Express Checkout</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('express_checkout_enabled', '1-Klick-Kauf für eingeloggte User aktivieren', $s, 'Zeigt einen "Sofort kaufen"-Button für Nutzer mit gespeicherten Zahlungsmethoden. Muss zusätzlich pro Event aktiviert werden.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: KI-Schutz ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-shield"></span>
                                        <h3>KI-Schutz</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ai_guard_enabled', 'KI-Inhaltsprüfung beim Veröffentlichen aktivieren', $s, 'Prüft Event-Titel und -Texte automatisch auf verbotene, diskriminierende oder schädliche Inhalte, bevor sie veröffentlicht werden. Abgelehnte Events bleiben als Entwurf gespeichert und werden gekennzeichnet.'); ?>
                                            </div>
                                            <?php self::text_row('ai_guard_api_key', 'Anthropic API Key', $s, 'sk-ant-…'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    Benötigt einen <a href="https://console.anthropic.com/settings/keys" target="_blank">Anthropic API Key</a>.<br>
                                                    Verwendet Claude 3.5 Haiku (~0,001 € pro Prüfung). Ergebnisse werden gecacht – identische Inhalte werden nicht erneut geprüft.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Ticket-Umschreibung ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-randomize"></span>
                                        <h3>Ticket-Umschreibung</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ticket_transfer_enabled', 'Ticket-Umschreibung global aktivieren', $s, 'Erlaubt K&auml;ufern, ihre Tickets auf eine andere Person umzuschreiben. Muss zus&auml;tzlich pro Event aktiviert werden. Shortcode: <code>[tix_ticket_transfer]</code>'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Strichcode (Barcode) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-editor-code"></span>
                                        <h3>Strichcode (Barcode)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('barcode_enabled', 'Strichcode auf Tickets aktivieren', $s, 'F&uuml;gt ein Code128-Barcode-Feld zum Ticket-Template hinzu, das von Handscannern gelesen werden kann. Muss zus&auml;tzlich pro Event aktiviert werden.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Soziales Projekt (Charity) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-heart"></span>
                                        <h3>Soziales Projekt</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('charity_enabled', 'Charity-Funktion global aktivieren', $s, 'Erm&ouml;glicht es, pro Event ein soziales Projekt anzugeben, das mit dem Ticketverkauf unterst&uuml;tzt wird. Wird auf der Event-Seite und der Danke-Seite angezeigt.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Promoter-System ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-groups"></span>
                                        <h3>Promoter-System</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('promoter_enabled', 'Promoter-Portal aktivieren', $s, 'Aktiviert das Promoter-Management im Admin-Backend und das Frontend-Dashboard. Promoter k&ouml;nnen per Referral-Link oder Promo-Code Tickets verkaufen und erhalten Provisionen. Verwende den Shortcode <code>[tix_promoter_dashboard]</code> f&uuml;r das Frontend-Portal.'); ?>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-field-label">Cookie-Laufzeit (Tage)</label>
                                                <input type="number" name="<?php echo self::OPTION_KEY; ?>[promoter_cookie_days]"
                                                       value="<?php echo intval($s['promoter_cookie_days'] ?? 30); ?>"
                                                       class="small-text" min="1" max="365" step="1">
                                                <p class="tix-settings-hint">Wie lange ein Referral-Cookie g&uuml;ltig ist. Standard: 30 Tage. Je l&auml;nger, desto sicherer die Zuordnung &ndash; auch wenn der K&auml;ufer erst sp&auml;ter kauft.</p>
                                            </div>
                                            <div class="tix-field tix-field-full" style="border-top:1px solid #e5e7eb;padding-top:16px;margin-top:8px">
                                                <h4 style="margin:0 0 8px">Empfehlungsprogramm</h4>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('promoter_self_signup', 'Self-Signup erlauben', $s, 'K&auml;ufer k&ouml;nnen sich selbst als Promoter registrieren. Shortcode: <code>[tix_promoter_signup]</code>'); ?>
                                            </div>
                                            <div class="tix-field">
                                                <label>Standard-Provisions-Typ</label>
                                                <select name="tix_settings[promoter_signup_commission_type]">
                                                    <option value="fixed" <?php selected($s['promoter_signup_commission_type'] ?? 'fixed', 'fixed'); ?>>Festbetrag (&euro;)</option>
                                                    <option value="percent" <?php selected($s['promoter_signup_commission_type'] ?? 'fixed', 'percent'); ?>>Prozent (%)</option>
                                                </select>
                                            </div>
                                            <?php self::text_row('promoter_signup_commission_value', 'Provisions-Wert', $s, '2'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('promoter_signup_auto_events', 'Alle Events automatisch zuweisen', $s, 'Neue Self-Signup Promoter werden automatisch allen &ouml;ffentlichen Events zugewiesen.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('promoter_post_purchase_enabled', 'Empfehlungs-CTA auf Danke-Seite', $s, 'Zeigt nach dem Kauf einen Aufruf: &bdquo;Teile deinen Link und verdiene pro Verkauf&ldquo;.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('promoter_my_tickets_enabled', 'Referral-Link in Meine Tickets', $s, 'Zeigt den pers&ouml;nlichen Empfehlungslink im Meine-Tickets-Bereich.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Veranstalter-Dashboard ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-businessman"></span>
                                        <h3>Veranstalter-Dashboard</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('organizer_dashboard_enabled', 'Veranstalter-Dashboard aktivieren', $s, 'Aktiviert das Frontend-Dashboard f&uuml;r externe Veranstalter. Veranstalter k&ouml;nnen eigene Events erstellen und verwalten, ohne Zugang zu wp-admin. Verwende den Shortcode <code>[tix_organizer_dashboard]</code> auf einer beliebigen Seite.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('organizer_auto_publish', 'Events automatisch ver&ouml;ffentlichen', $s, 'Wenn aktiviert, werden vom Veranstalter erstellte Events sofort ver&ouml;ffentlicht. Andernfalls werden sie als Entwurf gespeichert und m&uuml;ssen vom Admin freigegeben werden.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('organizer_fullscreen', 'Fullscreen-Modus f&uuml;r Veranstalter-Dashboard', $s, 'Zeigt das Veranstalter-Dashboard im Fullscreen-Modus ohne WordPress-Theme-Elemente (Header, Footer, Sidebar). Das Dashboard &uuml;bernimmt die gesamte Seite mit eigenem Tixomat-Branding.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Support-System ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-format-chat"></span>
                                        <h3>Support-System</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('support_enabled', 'Support-System aktivieren', $s, 'Aktiviert das Admin-Dashboard f&uuml;r Kunden-Suche, Anfragen-Verwaltung und das Kunden-Portal. Verwende den Shortcode <code>[tix_support]</code> f&uuml;r das Frontend-Portal.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('support_chat_enabled', 'Floating Chat-Button anzeigen', $s, 'Zeigt einen schwebenden Chat-Button auf allen Seiten an. Kunden k&ouml;nnen direkt darüber Anfragen stellen, ohne zur Support-Seite navigieren zu m&uuml;ssen.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label" for="tix-support-categories">Support-Kategorien</label>
                                                <textarea id="tix-support-categories" name="tix_settings[support_categories]" class="tix-textarea" rows="5" placeholder="Ticket nicht erhalten&#10;Ticketinhaber &auml;ndern&#10;Stornierung / Erstattung&#10;Fragen zum Event&#10;Sonstiges"><?php echo esc_textarea($s['support_categories'] ?? ''); ?></textarea>
                                                <p class="tix-hint">Eine Kategorie pro Zeile. Leer lassen f&uuml;r Standard-Kategorien.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Specials / Zusatzprodukte ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-star-filled"></span>
                                        <h3>Specials / Zusatzprodukte</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('specials_enabled', 'Specials-System aktivieren', $s, 'Erm&ouml;glicht es, wiederverwendbare Zusatzprodukte (z.B. Getr&auml;nkepakete, Merch-Bundles) anzulegen und Events zuzuweisen. Specials erscheinen im Ticket-Selector und Checkout als Upsell-Angebot.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: POS / Abendkasse ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-store"></span>
                                        <h3>POS / Abendkasse</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('pos_enabled', 'POS-System aktivieren', $s, 'Tablet-optimiertes Fullscreen-Interface f&uuml;r Vor-Ort-Ticketverkauf. Verwende den Shortcode <code>[tix_pos]</code> auf einer beliebigen Seite.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('pos_pin_required', 'PIN-Login erforderlich', $s, 'Kassenpersonal muss sich per PIN anmelden. PINs werden im Benutzerprofil hinterlegt.'); ?>
                                            </div>
                                            <?php self::range_row('pos_auto_reset_seconds', 'Auto-Reset nach Verkauf', $s, 3, 60, ' Sek.', 1); ?>
                                            <div class="tix-field">
                                                <label class="tix-label">Standard-Zahlungsart</label>
                                                <select name="tix_settings[pos_default_payment]" class="tix-select">
                                                    <option value="cash" <?php selected($s['pos_default_payment'], 'cash'); ?>>Barzahlung</option>
                                                    <option value="card" <?php selected($s['pos_default_payment'], 'card'); ?>>EC-Karte</option>
                                                    <option value="free" <?php selected($s['pos_default_payment'], 'free'); ?>>Kostenlos</option>
                                                </select>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('pos_allow_free', 'Kostenlose Tickets erlauben', $s, 'Zeigt die Option &quot;Kostenlos&quot; als Zahlungsart im POS.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('pos_require_email', 'E-Mail-Adresse erforderlich', $s, 'Kunde muss eine E-Mail eingeben (f&uuml;r automatischen Ticket-Versand).'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('pos_require_name', 'Kundenname erforderlich', $s, 'Kunde muss einen Namen eingeben.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Akzentfarbe</label>
                                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                                                    <input type="color" id="pos-accent-picker" name="tix_settings[color_accent]" value="<?php echo esc_attr($s['color_accent'] ?? '#c8ff00'); ?>" style="width:48px;height:36px;border:1px solid #ccc;border-radius:6px;cursor:pointer;padding:2px;">
                                                    <input type="text" id="pos-accent-hex" value="<?php echo esc_attr($s['color_accent'] ?? '#c8ff00'); ?>" class="tix-input" style="max-width:120px;font-family:monospace;" oninput="document.getElementById('pos-accent-picker').value=this.value" onchange="document.getElementById('pos-accent-picker').value=this.value">
                                                </div>
                                                <?php
                                                $palette = $s['color_palette'] ?? [];
                                                if (!empty($palette)): ?>
                                                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                                    <?php foreach ($palette as $entry):
                                                        $hex = $entry['color'] ?? '';
                                                        $name = $entry['name'] ?? '';
                                                        if (empty($hex)) continue;
                                                        $light = in_array(strtolower($hex), ['#ffffff','#fff','#e2e8f0','#f1f5f9','#f8fafc']);
                                                        $border_style = $light ? '1px solid #999' : '2px solid transparent';
                                                    ?>
                                                    <button type="button" title="<?php echo esc_attr($name); ?>" onclick="document.getElementById('pos-accent-picker').value='<?php echo esc_attr($hex); ?>';document.getElementById('pos-accent-hex').value='<?php echo esc_attr($hex); ?>';" style="width:32px;height:32px;border-radius:8px;background:<?php echo esc_attr($hex); ?>;border:<?php echo $border_style; ?>;cursor:pointer;transition:transform .15s;flex-shrink:0;" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'"></button>
                                                    <?php endforeach; ?>
                                                </div>
                                                <?php endif; ?>
                                                <p class="tix-settings-hint" style="margin-top:8px;">Farben aus deiner <strong>Design-Palette</strong>. &Auml;ndert auch die globale Akzentfarbe.</p>
                                            </div>
                                        </div>

                                        <hr style="border:none;border-top:1px solid #e0e0e0;margin:18px 0 14px;">
                                        <h4 style="margin:0 0 12px;font-size:14px;font-weight:600;">SumUp Kartenzahlung</h4>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('pos_sumup_enabled', 'SumUp-Integration aktivieren', $s, 'EC-Kartenzahlung &uuml;ber SumUp-Terminal. Erstelle einen API-Key unter <a href="https://developer.sumup.com/" target="_blank">developer.sumup.com</a>.'); ?>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-label">SumUp API-Key</label>
                                                <input type="password" name="tix_settings[pos_sumup_api_key]" value="<?php echo esc_attr($s['pos_sumup_api_key'] ?? ''); ?>" class="tix-input" placeholder="sup_sk_..." autocomplete="new-password">
                                                <p class="tix-settings-hint">Beginnt mit <code>sup_sk_...</code>. Unter SumUp Dashboard &rarr; Developers &rarr; API Keys.</p>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-label">Merchant-Code</label>
                                                <input type="text" name="tix_settings[pos_sumup_merchant_code]" value="<?php echo esc_attr($s['pos_sumup_merchant_code'] ?? ''); ?>" class="tix-input" placeholder="MXXXXXXXXX">
                                                <p class="tix-settings-hint">Dein SumUp H&auml;ndler-Code. Unter SumUp Dashboard &rarr; Konto &rarr; Kontodaten.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Tischreservierung ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-food"></span>
                                        <h3>Tischreservierung</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('table_reservation_enabled', 'Tischreservierung aktivieren', $s, 'Erm&ouml;glicht Tischreservierungen &uuml;ber einen Kalender. Verwende <code>[tix_table_reservation]</code> f&uuml;r die Kalender-Ansicht und <code>[tix_table_button]</code> auf Event-Seiten.'); ?>
                                            </div>
                                        </div>

                                        <div id="tix-table-res-settings" style="<?php echo empty($s['table_reservation_enabled']) ? 'display:none;' : ''; ?>margin-top:16px">
                                            <h4 style="margin:0 0 12px;font-size:14px;font-weight:600">Farben (optional)</h4>
                                            <div class="tix-field-grid">
                                                <div class="tix-field">
                                                    <label class="tix-label">Hintergrund</label>
                                                    <div style="display:flex;gap:8px;align-items:center">
                                                        <input type="color" name="tix_settings[reservation_bg]" value="<?php echo esc_attr($s['reservation_bg'] ?: '#0f0f0f'); ?>" style="width:40px;height:32px;padding:0;border:1px solid #ddd;border-radius:4px;cursor:pointer">
                                                        <input type="text" name="tix_settings[reservation_bg]" value="<?php echo esc_attr($s['reservation_bg'] ?? ''); ?>" class="tix-input" style="width:100px" placeholder="leer = Standard">
                                                    </div>
                                                </div>
                                                <div class="tix-field">
                                                    <label class="tix-label">Oberfl&auml;che</label>
                                                    <div style="display:flex;gap:8px;align-items:center">
                                                        <input type="color" name="tix_settings[reservation_surface]" value="<?php echo esc_attr($s['reservation_surface'] ?: '#1a1a1a'); ?>" style="width:40px;height:32px;padding:0;border:1px solid #ddd;border-radius:4px;cursor:pointer">
                                                        <input type="text" name="tix_settings[reservation_surface]" value="<?php echo esc_attr($s['reservation_surface'] ?? ''); ?>" class="tix-input" style="width:100px" placeholder="leer = Standard">
                                                    </div>
                                                </div>
                                                <div class="tix-field">
                                                    <label class="tix-label">Karte</label>
                                                    <div style="display:flex;gap:8px;align-items:center">
                                                        <input type="color" name="tix_settings[reservation_card]" value="<?php echo esc_attr($s['reservation_card'] ?: '#252525'); ?>" style="width:40px;height:32px;padding:0;border:1px solid #ddd;border-radius:4px;cursor:pointer">
                                                        <input type="text" name="tix_settings[reservation_card]" value="<?php echo esc_attr($s['reservation_card'] ?? ''); ?>" class="tix-input" style="width:100px" placeholder="leer = Standard">
                                                    </div>
                                                </div>
                                                <div class="tix-field">
                                                    <label class="tix-label">Text</label>
                                                    <div style="display:flex;gap:8px;align-items:center">
                                                        <input type="color" name="tix_settings[reservation_text]" value="<?php echo esc_attr($s['reservation_text'] ?: '#ffffff'); ?>" style="width:40px;height:32px;padding:0;border:1px solid #ddd;border-radius:4px;cursor:pointer">
                                                        <input type="text" name="tix_settings[reservation_text]" value="<?php echo esc_attr($s['reservation_text'] ?? ''); ?>" class="tix-input" style="width:100px" placeholder="leer = Standard">
                                                    </div>
                                                </div>
                                            </div>

                                            <h4 style="margin:24px 0 8px;font-size:14px;font-weight:600">Standard-Tischkategorien</h4>
                                            <p class="tix-settings-hint" style="margin-bottom:12px">Diese werden automatisch &uuml;bernommen wenn Tischreservierung f&uuml;r ein neues Event aktiviert wird.</p>
                                            <div id="tix-default-cats">
                                                <?php
                                                $def_cats = json_decode($s['table_default_categories'] ?? '[]', true);
                                                if (!is_array($def_cats)) $def_cats = [];
                                                foreach ($def_cats as $di => $dc): ?>
                                                <div class="tix-default-cat-row" style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:8px">
                                                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                                                        <div style="flex:2;min-width:120px">
                                                            <label class="tix-mini-label">Name</label>
                                                            <input type="text" class="tix-input tix-dc-name" value="<?php echo esc_attr($dc['name'] ?? ''); ?>" placeholder="z.B. VIP Lounge">
                                                        </div>
                                                        <div style="flex:1;min-width:80px">
                                                            <label class="tix-mini-label">Mindestverzehr (&euro;)</label>
                                                            <input type="number" class="tix-input tix-dc-min-spend" value="<?php echo esc_attr($dc['min_spend'] ?? 0); ?>" step="0.01" min="0">
                                                        </div>
                                                        <div style="width:70px">
                                                            <label class="tix-mini-label">Tische</label>
                                                            <input type="number" class="tix-input tix-dc-quantity" value="<?php echo esc_attr($dc['quantity'] ?? 0); ?>" min="0">
                                                        </div>
                                                        <div style="width:60px">
                                                            <label class="tix-mini-label">Min G.</label>
                                                            <input type="number" class="tix-input tix-dc-min-guests" value="<?php echo esc_attr($dc['min_guests'] ?? 1); ?>" min="1">
                                                        </div>
                                                        <div style="width:60px">
                                                            <label class="tix-mini-label">Max G.</label>
                                                            <input type="number" class="tix-input tix-dc-max-guests" value="<?php echo esc_attr($dc['max_guests'] ?? 10); ?>" min="1">
                                                        </div>
                                                        <button type="button" class="button tix-remove-default-cat" style="color:#dc2626;border-color:#dc2626;flex-shrink:0" title="Entfernen">&#x2715;</button>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <input type="hidden" name="tix_settings[table_default_categories]" id="tix-default-cats-json" value="<?php echo esc_attr($s['table_default_categories'] ?? '[]'); ?>">
                                            <button type="button" class="button" id="tix-add-default-cat" style="margin-top:4px">+ Kategorie hinzuf&uuml;gen</button>
                                        </div>

                                        <script>
                                        jQuery(function($) {
                                            // Toggle table reservation settings visibility
                                            $('input[name="tix_settings[table_reservation_enabled]"]').on('change', function() {
                                                $('#tix-table-res-settings').toggle($(this).is(':checked'));
                                            });

                                            // Sync color pickers with text inputs
                                            $('#tix-table-res-settings input[type="color"]').on('input', function() {
                                                $(this).next('input[type="text"]').val($(this).val());
                                            });
                                            $('#tix-table-res-settings input[type="text"][name*="reservation_"]').on('input', function() {
                                                var v = $(this).val();
                                                if (/^#[0-9a-fA-F]{6}$/.test(v)) {
                                                    $(this).prev('input[type="color"]').val(v);
                                                }
                                            });

                                            // Default categories
                                            function syncDefaultCats() {
                                                var cats = [];
                                                $('#tix-default-cats .tix-default-cat-row').each(function() {
                                                    var name = $(this).find('.tix-dc-name').val().trim();
                                                    if (!name) return;
                                                    cats.push({
                                                        name: name,
                                                        min_spend: parseFloat($(this).find('.tix-dc-min-spend').val()) || 0,
                                                        quantity: parseInt($(this).find('.tix-dc-quantity').val()) || 0,
                                                        min_guests: parseInt($(this).find('.tix-dc-min-guests').val()) || 1,
                                                        max_guests: parseInt($(this).find('.tix-dc-max-guests').val()) || 10
                                                    });
                                                });
                                                $('#tix-default-cats-json').val(JSON.stringify(cats));
                                            }

                                            $('#tix-default-cats').on('input change', 'input', syncDefaultCats);

                                            $('#tix-add-default-cat').on('click', function() {
                                                var tpl = '<div class="tix-default-cat-row" style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:8px">' +
                                                    '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">' +
                                                    '<div style="flex:2;min-width:120px"><label class="tix-mini-label">Name</label><input type="text" class="tix-input tix-dc-name" placeholder="z.B. VIP Lounge"></div>' +
                                                    '<div style="flex:1;min-width:80px"><label class="tix-mini-label">Mindestverzehr (\u20ac)</label><input type="number" class="tix-input tix-dc-min-spend" value="0" step="0.01" min="0"></div>' +
                                                    '<div style="width:70px"><label class="tix-mini-label">Tische</label><input type="number" class="tix-input tix-dc-quantity" value="0" min="0"></div>' +
                                                    '<div style="width:60px"><label class="tix-mini-label">Min G.</label><input type="number" class="tix-input tix-dc-min-guests" value="1" min="1"></div>' +
                                                    '<div style="width:60px"><label class="tix-mini-label">Max G.</label><input type="number" class="tix-input tix-dc-max-guests" value="10" min="1"></div>' +
                                                    '<button type="button" class="button tix-remove-default-cat" style="color:#dc2626;border-color:#dc2626;flex-shrink:0" title="Entfernen">\u2715</button>' +
                                                    '</div></div>';
                                                $('#tix-default-cats').append(tpl);
                                                syncDefaultCats();
                                            });

                                            $(document).on('click', '.tix-remove-default-cat', function() {
                                                $(this).closest('.tix-default-cat-row').remove();
                                                syncDefaultCats();
                                            });
                                        });
                                        </script>
                                    </div>
                                </div>

                                <?php // ── Card: Geführter Modus ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-screenoptions"></span>
                                        <h3>Gef&uuml;hrter Modus</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('wizard_enabled', 'Gef&uuml;hrten Modus im Event-Editor anzeigen', $s, 'Wenn deaktiviert, wird der Event-Editor immer im Experten-Modus ge&ouml;ffnet. Der Modus-Toggle (Gef&uuml;hrt / Experte) wird ausgeblendet.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Mein-Konto Styling ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                        <h3>Mein-Konto Styling</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('myaccount_restyle', 'Tixomat-Design f&uuml;r WooCommerce &bdquo;Mein Konto&ldquo;', $s, '&Uuml;bernimmt das Tixomat-Design (Farben, Ecken, Schrift) f&uuml;r den gesamten WooCommerce &bdquo;Mein Konto&ldquo;-Bereich unter <code>/mein-konto/</code>.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Branding ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-heart"></span>
                                        <h3>Branding</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('branding_enabled', '&quot;mit ♥️ entwickelt von MDJ.events&quot; unter Shortcodes anzeigen', $s); ?>
                                            </div>
                                            <?php self::text_row('branding_url', 'Link-Ziel', $s, 'https://mdj.events'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Custom URLs ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-links"></span>
                                        <h3>Custom URLs</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::text_row('login_slug', 'Login-URL', $s, 'anmelden'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-hint" style="margin:-8px 0 8px;font-size:12px;color:#94a3b8;">
                                                    <?php if (!empty($s['login_slug'])) : ?>
                                                        Aktiv: <code><?php echo esc_html(home_url('/' . $s['login_slug'] . '/')); ?></code>
                                                    <?php else : ?>
                                                        Leer lassen f&uuml;r Standard-WordPress-Login (<code>/wp-login.php</code>).
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <?php self::text_row('organizer_slug', 'Veranstalter-URL', $s, 'veranstalter'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-hint" style="margin:-8px 0 8px;font-size:12px;color:#94a3b8;">
                                                    <?php if (!empty($s['organizer_slug'])) : ?>
                                                        Aktiv: <code><?php echo esc_html(home_url('/' . $s['organizer_slug'] . '/')); ?></code> &rarr; leitet Veranstalter zum Dashboard weiter.
                                                    <?php else : ?>
                                                        Leer lassen = kein spezieller Veranstalter-Slug.
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Admin-Ansicht ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-fullscreen-alt"></span>
                                        <h3>Admin-Ansicht</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('fullscreen_admin', 'Fullscreen-Modus f&uuml;r Tixomat-Seiten', $s, 'Blendet die WordPress-Oberfl&auml;che (Admin-Bar, Sidebar, Footer) aus und zeigt stattdessen eine eigene Tixomat-Navigation. Deaktivieren f&uuml;r die klassische WordPress-Ansicht.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Sponsor (Danke-Seite) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-format-image"></span>
                                        <h3>Sponsor (Danke-Seite)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('sponsor_enabled', 'Sponsor-Bereich auf der Danke-Seite anzeigen', $s); ?>
                                            </div>
                                            <?php self::text_row('sponsor_image_url', 'Sponsor-Logo URL', $s, 'https://example.com/sponsor-logo.png'); ?>
                                            <?php self::range_row('sponsor_logo_width', 'Logo-Breite', $s, 10, 80, '%'); ?>
                                        </div>
                                    </div>
                                </div>

                            </div>

                        </div><?php // .tix-content ?>

                        <div class="tix-settings-submit">
                            <?php submit_button('Einstellungen speichern'); ?>
                        </div>

                    </div><?php // .tix-app ?>

                </div>
            </form>
        </div>

        <script>
        (function() {
            'use strict';

            // ── Tab Navigation (Settings Page) ──
            var app   = document.querySelector('.tix-settings-app');
            if (!app) return;
            var tabs  = app.querySelectorAll('.tix-nav-tab');
            var panes = app.querySelectorAll('.tix-pane');

            tabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    var t = this.getAttribute('data-tab');
                    tabs.forEach(function(x) { x.classList.remove('active'); });
                    this.classList.add('active');
                    panes.forEach(function(p) { p.classList.remove('active'); });
                    var target = app.querySelector('[data-pane="' + t + '"]');
                    if (target) target.classList.add('active');
                    if (window.sessionStorage) sessionStorage.setItem('tix_settings_tab', t);
                }.bind(tab));
            });

            // Restore saved tab
            if (window.sessionStorage) {
                var saved = sessionStorage.getItem('tix_settings_tab');
                if (saved) {
                    var btn = app.querySelector('.tix-nav-tab[data-tab="' + saved + '"]');
                    if (btn) btn.click();
                }
            }

            // ══════════════════════════════════════
            // Share-Kanäle Checkboxen → Hidden-Field
            // ══════════════════════════════════════
            (function() {
                var hidden = document.getElementById('tix-share-channels-hidden');
                if (!hidden) return;
                var boxes = document.querySelectorAll('.tix-share-ch-toggle');
                function sync() {
                    var active = [];
                    boxes.forEach(function(cb) { if (cb.checked) active.push(cb.getAttribute('data-channel')); });
                    hidden.value = active.join(',');
                }
                boxes.forEach(function(cb) { cb.addEventListener('change', sync); });
            })();

            // ══════════════════════════════════════
            // RGBA Color Picker Helpers
            // ══════════════════════════════════════

            function tixParseColor(val) {
                if (!val) return null;
                val = val.trim();
                var m;
                // rgba(r,g,b,a) / rgb(r,g,b)
                if ((m = val.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+))?\s*\)$/))) {
                    var r = parseInt(m[1]), g = parseInt(m[2]), b = parseInt(m[3]);
                    var a = m[4] !== undefined ? parseFloat(m[4]) : 1;
                    return {hex:'#'+('0'+r.toString(16)).slice(-2)+('0'+g.toString(16)).slice(-2)+('0'+b.toString(16)).slice(-2), alpha:Math.round(a*100)};
                }
                // 8-digit hex
                if (/^#[0-9a-fA-F]{8}$/.test(val)) {
                    return {hex:val.substr(0,7), alpha:Math.round(parseInt(val.substr(7,2),16)/255*100)};
                }
                // 6-digit hex
                if (/^#[0-9a-fA-F]{6}$/.test(val)) {
                    return {hex:val, alpha:100};
                }
                return null;
            }

            function tixComposeColor(hex, alpha) {
                if (alpha >= 100) return hex;
                var r = parseInt(hex.substr(1,2),16);
                var g = parseInt(hex.substr(3,2),16);
                var b = parseInt(hex.substr(5,2),16);
                var a = alpha / 100;
                var aStr = a % 1 === 0 ? a.toString() : parseFloat(a.toFixed(2)).toString();
                return 'rgba('+r+','+g+','+b+','+aStr+')';
            }

            function tixUpdateWrap(wrap) {
                var hexInput = wrap.querySelector('.tix-color-hex');
                var picker   = wrap.querySelector('input[type="color"]');
                var fill     = wrap.querySelector('.tix-color-fill');
                var slider   = wrap.querySelector('.tix-alpha-slider');
                var alphaVal = wrap.querySelector('.tix-alpha-val');
                if (!picker || !slider) return;
                var composed = tixComposeColor(picker.value, parseInt(slider.value));
                hexInput.value = composed;
                if (fill) fill.style.background = composed;
                if (alphaVal) alphaVal.textContent = slider.value + '%';
            }

            // ── Color Picker (RGB) changed ──
            document.querySelectorAll('.tix-color-swatch input[type="color"]').forEach(function(picker) {
                picker.addEventListener('input', function() {
                    tixUpdateWrap(this.closest('.tix-color-wrap'));
                });
            });

            // ── Alpha Slider changed ──
            document.querySelectorAll('.tix-alpha-slider').forEach(function(slider) {
                slider.addEventListener('input', function() {
                    tixUpdateWrap(this.closest('.tix-color-wrap'));
                });
            });

            // ── Text Input changed (supports hex, rgba, 8-digit hex) ──
            document.querySelectorAll('.tix-color-hex').forEach(function(input) {
                input.addEventListener('input', function() {
                    var parsed = tixParseColor(this.value);
                    if (!parsed) return;
                    var wrap = this.closest('.tix-color-wrap');
                    var picker   = wrap.querySelector('input[type="color"]');
                    var fill     = wrap.querySelector('.tix-color-fill');
                    var slider   = wrap.querySelector('.tix-alpha-slider');
                    var alphaVal = wrap.querySelector('.tix-alpha-val');
                    if (picker) picker.value = parsed.hex;
                    if (slider) slider.value = parsed.alpha;
                    if (alphaVal) alphaVal.textContent = parsed.alpha + '%';
                    if (fill) fill.style.background = this.value;
                });
            });

            // ── Reset Color ──
            document.querySelectorAll('.tix-color-reset').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var def = this.dataset.default;
                    var wrap = this.closest('.tix-color-wrap');
                    var parsed = tixParseColor(def) || {hex:'#000000', alpha:100};
                    wrap.querySelector('.tix-color-hex').value = def;
                    wrap.querySelector('input[type="color"]').value = parsed.hex;
                    wrap.querySelector('.tix-alpha-slider').value = parsed.alpha;
                    wrap.querySelector('.tix-alpha-val').textContent = parsed.alpha + '%';
                    var fill = wrap.querySelector('.tix-color-fill');
                    if (fill) fill.style.background = def || '#000000';
                });
            });

            // ── Range Sync ──
            document.querySelectorAll('.tix-range-wrap input[type="range"]').forEach(function(range) {
                range.addEventListener('input', function() {
                    this.closest('.tix-range-wrap').querySelector('.tix-range-val').textContent = this.value + (this.dataset.unit || '');
                });
            });

            // ── Universal Theme Toggle ──
            var themePresets = {
                light: {
                    color_text:'',
                    btn1_bg:'#1e293b', btn1_color:'#ffffff', btn1_hover_bg:'#334155', btn1_hover_color:'',
                    btn2_bg:'', btn2_color:'', btn2_hover_bg:'#FAF8F4', btn2_hover_color:'',
                    color_border:'#EDE9E0', color_input_border:'#cbd5e1',
                    color_focus:'#3b82f6', color_sale:'#ef4444', save_badge_bg:'', save_badge_text:'',
                    color_success:'#22c55e',
                    color_card_bg:'#ffffff', color_input_bg:'#ffffff', shortcode_bg:'',
                    sel_bg:'#ffffff', sel_border_color:'#EDE9E0',
                    sel_active_border:'#3b82f6', sel_active_bg:'#eff6ff', sel_hover_text:'',
                    faq_bg:'#ffffff', faq_list_bg:'',
                    faq_hover_bg:'#FAF8F4', faq_hover_text:'', faq_active_bg:'#f1f5f9',
                    faq_border_color:'#EDE9E0', faq_divider_color:'#EDE9E0',
                    faq_accent_color:'#1e293b', faq_icon_color:'', faq_link_color:'#3b82f6',
                    ec_modal_bg:'#ffffff',
                    ec_modal_border:'#EDE9E0', ec_cat_border:'#EDE9E0', ec_cat_active:'#3b82f6',
                    mt_bg:'#FAF8F4', mt_card_bg:'#ffffff',
                    mt_border_color:'#EDE9E0', mt_ticket_bg:'#f0fdf4', mt_accent_color:'#1e293b',
                    ci_bg:'#FAF8F4', ci_surface:'#ffffff', ci_border:'#EDE9E0',
                    ci_text:'#1e293b', ci_muted:'#64748b', ci_accent:'#1e293b',
                    ci_accent_text:'#ffffff', ci_ok:'#22c55e', ci_warn:'#eab308', ci_err:'#ef4444'
                },
                dark: {
                    color_text:'#ffffff',
                    btn1_bg:'#c8ff00', btn1_color:'#000000', btn1_hover_bg:'#b8e600', btn1_hover_color:'',
                    btn2_bg:'', btn2_color:'', btn2_hover_bg:'', btn2_hover_color:'',
                    color_border:'#333333', color_input_border:'#555555',
                    color_focus:'#c8ff00', color_sale:'#ef5350', save_badge_bg:'', save_badge_text:'',
                    color_success:'#4caf50',
                    color_card_bg:'#1a1a1a', color_input_bg:'#111111', shortcode_bg:'#111111',
                    sel_bg:'#1a1a1a', sel_border_color:'#333333',
                    sel_active_border:'#c8ff00', sel_active_bg:'#1a2600', sel_hover_text:'',
                    faq_bg:'#1a1a1a', faq_list_bg:'#111111',
                    faq_hover_bg:'#222222', faq_hover_text:'', faq_active_bg:'#2a2a2a',
                    faq_border_color:'#333333', faq_divider_color:'#333333',
                    faq_accent_color:'#c8ff00', faq_icon_color:'#94a3b8', faq_link_color:'#c8ff00',
                    ec_modal_bg:'#1a1a1a',
                    ec_modal_border:'#333333', ec_cat_border:'#333333', ec_cat_active:'#c8ff00',
                    mt_bg:'#111111', mt_card_bg:'#1a1a1a',
                    mt_border_color:'#333333', mt_ticket_bg:'#1a2600', mt_accent_color:'#c8ff00',
                    ci_bg:'#111111', ci_surface:'#1a1a1a', ci_border:'#333333',
                    ci_text:'#ffffff', ci_muted:'#94a3b8', ci_accent:'#ffffff',
                    ci_accent_text:'#000000', ci_ok:'#22c55e', ci_warn:'#eab308', ci_err:'#ef4444'
                }
            };

            function applyColorToField(key, value) {
                var input = document.querySelector('[name="tix_settings[' + key + ']"]');
                if (!input) return;
                input.value = value;
                var wrap = input.closest('.tix-color-wrap');
                if (!wrap) return;
                var parsed = tixParseColor(value) || {hex: value || '#000000', alpha: 100};
                var fill = wrap.querySelector('.tix-color-fill');
                if (fill) fill.style.background = value || '#ffffff';
                var picker = wrap.querySelector('input[type="color"]');
                if (picker) picker.value = parsed.hex;
                var slider = wrap.querySelector('.tix-alpha-slider');
                if (slider) slider.value = parsed.alpha;
                var alphaVal = wrap.querySelector('.tix-alpha-val');
                if (alphaVal) alphaVal.textContent = parsed.alpha + '%';
            }

            document.querySelectorAll('.tix-ci-theme-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var theme = this.dataset.theme;
                    var preset = themePresets[theme];
                    if (!preset) return;
                    document.getElementById('tix-theme-mode').value = theme;
                    document.querySelectorAll('.tix-ci-theme-btn').forEach(function(b) { b.classList.remove('active'); });
                    this.classList.add('active');
                    for (var key in preset) {
                        applyColorToField(key, preset[key]);
                    }
                });
            });

            // ── Settings "Mehr" Tabs Toggle ──
            (function() {
                var moreBtn  = document.getElementById('tix-settings-more-btn');
                var moreTabs = document.getElementById('tix-settings-more-tabs');
                if (!moreBtn || !moreTabs) return;

                // Restore state from localStorage
                var isOpen = localStorage.getItem('tix_settings_more') === '1';
                if (isOpen) {
                    moreTabs.classList.add('tix-settings-more-open');
                    moreBtn.classList.add('active');
                }

                moreBtn.addEventListener('click', function() {
                    var open = moreTabs.classList.toggle('tix-settings-more-open');
                    moreBtn.classList.toggle('active', open);
                    localStorage.setItem('tix_settings_more', open ? '1' : '0');
                });
            })();

            // ══════════════════════════════════════
            // COLLAPSIBLE FEATURE CARDS (Erweitert-Tab)
            // ══════════════════════════════════════
            (function() {
                var advPane = app.querySelector('[data-pane="advanced"]');
                if (!advPane) return;

                // Skip these cards – they have no enable/disable toggle or are always-open config
                var skipTitles = ['Google Places Autocomplete', 'E-Mail-Templates'];

                advPane.querySelectorAll('.tix-card').forEach(function(card) {
                    var header = card.querySelector('.tix-card-header');
                    var body   = card.querySelector('.tix-card-body');
                    var h3     = header ? header.querySelector('h3') : null;
                    if (!header || !body || !h3) return;

                    // Skip cards without toggle logic
                    var title = h3.textContent.trim();
                    if (skipTitles.indexOf(title) !== -1) return;

                    // Find the first checkbox inside this card
                    var firstCb = body.querySelector('input[type="checkbox"]');

                    // Determine initial state: expanded if first checkbox is checked, or no checkbox
                    var isExpanded = !firstCb || firstCb.checked;

                    // Create arrow indicator
                    var arrow = document.createElement('span');
                    arrow.className = 'tix-feature-arrow dashicons dashicons-arrow-right-alt2';
                    header.insertBefore(arrow, header.firstChild);

                    // Wrap body content for animation
                    body.classList.add('tix-feature-body');
                    header.classList.add('tix-feature-toggle');

                    if (isExpanded) {
                        body.classList.add('tix-feature-expanded');
                        header.classList.add('tix-feature-open');
                    }

                    // Click header to toggle
                    header.addEventListener('click', function(e) {
                        // Don't toggle if clicking a link or button inside header
                        if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON') return;
                        body.classList.toggle('tix-feature-expanded');
                        header.classList.toggle('tix-feature-open');
                    });

                    // Auto-expand when checkbox is checked
                    if (firstCb) {
                        firstCb.addEventListener('change', function() {
                            if (this.checked && !body.classList.contains('tix-feature-expanded')) {
                                body.classList.add('tix-feature-expanded');
                                header.classList.add('tix-feature-open');
                            }
                        });
                    }
                });
            })();

            // ══════════════════════════════════════
            // FARBPALETTE MANAGER
            // ══════════════════════════════════════

            var palettePresets = {
                festival: [
                    {name: 'Primary',    color: '#FF5500'},
                    {name: 'Secondary',  color: '#1a1a2e'},
                    {name: 'Highlight',  color: '#e94560'},
                    {name: 'Deep Blue',  color: '#0f3460'},
                    {name: 'Success',    color: '#4caf50'},
                    {name: 'Light',      color: '#ffffff'}
                ],
                corporate: [
                    {name: 'Primary',    color: '#1e293b'},
                    {name: 'Blue',       color: '#3b82f6'},
                    {name: 'Sky',        color: '#0ea5e9'},
                    {name: 'Background', color: '#f8fafc'},
                    {name: 'Border',     color: '#e2e8f0'},
                    {name: 'Success',    color: '#22c55e'}
                ],
                elegant: [
                    {name: 'Dark',       color: '#2d2d2d'},
                    {name: 'Gold',       color: '#c9a84c'},
                    {name: 'Ivory',      color: '#f5f0e8'},
                    {name: 'Rose',       color: '#b76e79'},
                    {name: 'Slate',      color: '#64748b'},
                    {name: 'Black',      color: '#1a1a1a'}
                ],
                neon: [
                    {name: 'Neon Green', color: '#c8ff00'},
                    {name: 'Neon Pink',  color: '#ff00ff'},
                    {name: 'Electric',   color: '#00ffff'},
                    {name: 'Dark BG',    color: '#0a0a0a'},
                    {name: 'Surface',    color: '#1a1a1a'},
                    {name: 'Border',     color: '#333333'}
                ]
            };

            var repeater   = document.getElementById('tix-palette-repeater');
            var paletteAdd = document.getElementById('tix-palette-add');
            var optKey     = 'tix_settings';

            function createPaletteRow(name, color, index) {
                var row = document.createElement('div');
                row.className = 'tix-palette-row';
                row.dataset.index = index;
                var parsed = tixParseColor(color) || {hex: color || '#000000', alpha: 100};
                row.innerHTML =
                    '<input type="text" name="' + optKey + '[color_palette][' + index + '][name]" ' +
                    'value="' + (name || '').replace(/"/g, '&quot;') + '" class="tix-palette-name" placeholder="Name (z.B. Primary)">' +
                    '<div class="tix-color-wrap tix-palette-color-wrap">' +
                        '<div class="tix-color-swatch tix-palette-swatch-preview">' +
                            '<span class="tix-color-fill" style="background:' + (color || '#000000') + '"></span>' +
                            '<input type="color" value="' + parsed.hex + '" tabindex="-1">' +
                        '</div>' +
                        '<input type="text" name="' + optKey + '[color_palette][' + index + '][color]" ' +
                        'value="' + (color || '') + '" class="tix-color-hex tix-palette-color-input" placeholder="#000000" maxlength="30">' +
                    '</div>' +
                    '<button type="button" class="tix-palette-remove" title="Entfernen">&times;</button>';
                return row;
            }

            function reindexPaletteRows() {
                if (!repeater) return;
                repeater.querySelectorAll('.tix-palette-row').forEach(function(row, i) {
                    row.dataset.index = i;
                    var nameInput  = row.querySelector('.tix-palette-name');
                    var colorInput = row.querySelector('.tix-palette-color-input');
                    if (nameInput)  nameInput.name  = optKey + '[color_palette][' + i + '][name]';
                    if (colorInput) colorInput.name = optKey + '[color_palette][' + i + '][color]';
                });
            }

            function bindPaletteRow(row) {
                var picker   = row.querySelector('.tix-palette-swatch-preview input[type="color"]');
                var hexInput = row.querySelector('.tix-palette-color-input');
                var fill     = row.querySelector('.tix-palette-swatch-preview .tix-color-fill');

                if (picker) {
                    picker.addEventListener('input', function() {
                        hexInput.value = this.value;
                        if (fill) fill.style.background = this.value;
                        refreshAllPaletteSwatches();
                    });
                }
                if (hexInput) {
                    hexInput.addEventListener('input', function() {
                        var p = tixParseColor(this.value);
                        if (p && picker) picker.value = p.hex;
                        if (fill) fill.style.background = this.value || '#000000';
                        refreshAllPaletteSwatches();
                    });
                }

                row.querySelector('.tix-palette-remove').addEventListener('click', function() {
                    row.remove();
                    reindexPaletteRows();
                    refreshAllPaletteSwatches();
                });
            }

            // Add button
            if (paletteAdd) {
                paletteAdd.addEventListener('click', function() {
                    if (!repeater) return;
                    var rows = repeater.querySelectorAll('.tix-palette-row');
                    if (rows.length >= 12) return;
                    var idx = rows.length;
                    var row = createPaletteRow('', '#000000', idx);
                    repeater.appendChild(row);
                    bindPaletteRow(row);
                    refreshAllPaletteSwatches();
                    row.querySelector('.tix-palette-name').focus();
                });
            }

            // Bind existing server-rendered rows
            if (repeater) {
                repeater.querySelectorAll('.tix-palette-row').forEach(bindPaletteRow);
            }

            // ── Palette Preset Loading ──
            var presetSelect = document.getElementById('tix-palette-preset-select');
            if (presetSelect) {
                presetSelect.addEventListener('change', function() {
                    var key = this.value;
                    if (!key || !palettePresets[key] || !repeater) return;
                    var preset = palettePresets[key];
                    repeater.innerHTML = '';
                    preset.forEach(function(entry, i) {
                        var row = createPaletteRow(entry.name, entry.color, i);
                        repeater.appendChild(row);
                        bindPaletteRow(row);
                    });
                    refreshAllPaletteSwatches();
                    this.value = '';
                });
            }

            // ── Palette Swatches in Color Fields ──
            function getCurrentPalette() {
                var palette = [];
                if (!repeater) return palette;
                repeater.querySelectorAll('.tix-palette-row').forEach(function(row) {
                    var name  = row.querySelector('.tix-palette-name').value.trim();
                    var color = row.querySelector('.tix-palette-color-input').value.trim();
                    if (name && color) palette.push({name: name, color: color});
                });
                return palette;
            }

            function refreshAllPaletteSwatches() {
                var palette = getCurrentPalette();
                document.querySelectorAll('.tix-palette-swatches').forEach(function(container) {
                    var targetKey = container.dataset.targetKey;
                    container.innerHTML = '';
                    if (palette.length === 0) return;
                    palette.forEach(function(entry) {
                        var swatch = document.createElement('button');
                        swatch.type = 'button';
                        swatch.className = 'tix-palette-mini-swatch';
                        swatch.style.background = entry.color;
                        swatch.title = entry.name + ': ' + entry.color;
                        swatch.addEventListener('click', function() {
                            applyColorToField(targetKey, entry.color);
                        });
                        container.appendChild(swatch);
                    });
                });
            }

            // Initial render
            refreshAllPaletteSwatches();
        })();
        </script>

        <?php // ── Ticket-Template-Editor Init ── ?>
        <script>
        jQuery(function($) {
            // Preview-Daten für Placeholder
            window.tixPreviewData = <?php echo wp_json_encode(TIX_Ticket_Template::preview_data()); ?>;

            if (typeof TIX_TemplateEditor === 'undefined') return;
            var $el = $('#tix-tte-settings-editor');
            if (!$el.length) return;
            new TIX_TemplateEditor($el[0], {
                inputSelector: '#tix-tte-settings-input',
                nonce: '<?php echo wp_create_nonce('tix_template_preview'); ?>',
                ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
                fieldDefs: <?php echo wp_json_encode(array_map(function($d) { return ['label' => $d['label'], 'type' => $d['type']]; }, TIX_Ticket_Template::field_definitions())); ?>
            });
        });
        </script>

        <?php // ── Daten-Sync Buttons ── ?>
        <script>
        (function() {
            'use strict';
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var nonce   = '<?php echo wp_create_nonce('tix_sync_nonce'); ?>';

            document.querySelectorAll('.tix-sync-test').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var service = this.getAttribute('data-service');
                    var status  = document.querySelector('.tix-sync-status[data-service="' + service + '"]');
                    btn.disabled = true;
                    btn.textContent = 'Teste…';
                    if (status) status.textContent = '';

                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=tix_sync_test_connection&service=' + service + '&nonce=' + nonce
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        btn.textContent = 'Verbindung testen';
                        if (status) {
                            status.textContent = res.data && res.data.message ? res.data.message : (res.success ? '✓ OK' : '✕ Fehler');
                            status.style.color = res.success ? '#22c55e' : '#ef4444';
                        }
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.textContent = 'Verbindung testen';
                        if (status) { status.textContent = 'Netzwerkfehler'; status.style.color = '#ef4444'; }
                    });
                });
            });

            document.querySelectorAll('.tix-sync-all').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var service = this.getAttribute('data-service');
                    var status  = document.querySelector('.tix-sync-status[data-service="' + service + '"]');
                    btn.disabled = true;
                    btn.textContent = 'Synchronisiere…';
                    if (status) status.textContent = '';

                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=tix_sync_all&service=' + service + '&nonce=' + nonce
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        btn.disabled = false;
                        btn.textContent = 'Alle jetzt synchronisieren';
                        if (status && res.data) {
                            status.textContent = res.data.synced + ' gesynct, ' + res.data.failed + ' fehlgeschlagen' + (res.data.remaining > 0 ? ', ' + res.data.remaining + ' übrig' : '');
                            status.style.color = res.data.failed > 0 ? '#eab308' : '#22c55e';
                        }
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.textContent = 'Alle jetzt synchronisieren';
                        if (status) { status.textContent = 'Netzwerkfehler'; status.style.color = '#ef4444'; }
                    });
                });
            });
        })();
        </script>
        <?php
    }

    // ══════════════════════════════════════
    // Farb-Helfer (RGBA-Support)
    // ══════════════════════════════════════

    /**
     * Parse beliebigen Farbwert → {hex, alpha}
     * Unterstützt: #rrggbb, #rrggbbaa, rgba(r,g,b,a), rgb(r,g,b)
     */
    private static function parse_color($val) {
        $val = trim($val);
        if (!$val) return ['hex' => '#000000', 'alpha' => 100];

        // rgba(r,g,b,a) oder rgb(r,g,b)
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+))?\s*\)$/', $val, $m)) {
            $r = min(255, (int)$m[1]);
            $g = min(255, (int)$m[2]);
            $b = min(255, (int)$m[3]);
            $a = isset($m[4]) ? (float)$m[4] : 1;
            return ['hex' => sprintf('#%02x%02x%02x', $r, $g, $b), 'alpha' => round($a * 100)];
        }

        // 8-stelliger Hex #rrggbbaa
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $val, $m)) {
            return ['hex' => '#' . $m[1] . $m[2] . $m[3], 'alpha' => round(hexdec($m[4]) / 255 * 100)];
        }

        // Standard Hex #rrggbb
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
            return ['hex' => $val, 'alpha' => 100];
        }

        return ['hex' => '#000000', 'alpha' => 100];
    }

    /**
     * Farbwert sanitizen (hex, rgba, 8-digit hex)
     * Gibt hex zurück wenn alpha=1, sonst rgba()
     */
    private static function sanitize_color($val) {
        $val = trim($val);
        if ($val === '') return '';

        // Standard Hex #rrggbb
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) return $val;

        // 8-stelliger Hex → in rgba umwandeln wenn alpha < ff
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $val, $m)) {
            $a = hexdec($m[4]);
            if ($a >= 255) return '#' . $m[1] . $m[2] . $m[3];
            $r = hexdec($m[1]); $g = hexdec($m[2]); $b = hexdec($m[3]);
            $af = round($a / 255, 2);
            $as = rtrim(rtrim(number_format($af, 2), '0'), '.');
            return "rgba({$r},{$g},{$b},{$as})";
        }

        // rgba(r,g,b,a) oder rgb(r,g,b)
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+))?\s*\)$/', $val, $m)) {
            $r = max(0, min(255, (int)$m[1]));
            $g = max(0, min(255, (int)$m[2]));
            $b = max(0, min(255, (int)$m[3]));
            $a = isset($m[4]) ? max(0, min(1, (float)$m[4])) : 1;
            if ($a >= 1) return sprintf('#%02x%02x%02x', $r, $g, $b);
            $as = rtrim(rtrim(number_format($a, 2), '0'), '.');
            return "rgba({$r},{$g},{$b},{$as})";
        }

        // Fallback: WP sanitize_hex_color
        return sanitize_hex_color($val) ?: '';
    }

    /**
     * RGB-Komponenten aus beliebigem Farbwert extrahieren
     */
    private static function color_to_rgb($val) {
        $parsed = self::parse_color($val);
        $hex = ltrim($parsed['hex'], '#');
        if (strlen($hex) !== 6) return null;
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
            'a' => $parsed['alpha'] / 100,
        ];
    }

    // ══════════════════════════════════════
    // Feld-Helfer
    // ══════════════════════════════════════

    private static function color_row($key, $label, $s, $allow_empty = false) {
        $val     = $s[$key] ?: '';
        $default = self::defaults()[$key] ?: '#000000';
        $display = $val ?: $default;
        $name    = self::OPTION_KEY . "[$key]";
        $parsed  = self::parse_color($display);
        $rgb_hex = $parsed['hex'];
        $alpha   = $parsed['alpha'];
        ?>
        <div class="tix-field">
            <label class="tix-field-label"><?php echo esc_html($label); ?></label>
            <div class="tix-color-wrap">
                <div class="tix-color-swatch">
                    <span class="tix-color-fill" style="background:<?php echo esc_attr($display); ?>"></span>
                    <input type="color" value="<?php echo esc_attr($rgb_hex); ?>" tabindex="-1">
                </div>
                <input type="text" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($val); ?>"
                       class="tix-color-hex" placeholder="<?php echo esc_attr($default); ?>" maxlength="30">
                <input type="range" class="tix-alpha-slider" min="0" max="100" value="<?php echo $alpha; ?>">
                <span class="tix-alpha-val"><?php echo $alpha; ?>%</span>
                <?php if (!$allow_empty || $val): ?>
                    <a href="#" class="tix-color-reset" data-default="<?php echo esc_attr($default); ?>">Reset</a>
                <?php endif; ?>
            </div>
            <div class="tix-palette-swatches" data-target-key="<?php echo esc_attr($key); ?>"></div>
        </div>
        <?php
    }

    private static function range_row($key, $label, $s, $min = 0, $max = 50, $unit = 'px', $step = 1, $is_float = false) {
        $val  = $is_float ? floatval($s[$key]) : intval($s[$key]);
        $name = self::OPTION_KEY . "[$key]";
        ?>
        <div class="tix-field">
            <label class="tix-field-label"><?php echo esc_html($label); ?></label>
            <div class="tix-range-wrap">
                <input type="range" name="<?php echo esc_attr($name); ?>"
                       value="<?php echo esc_attr($val); ?>"
                       min="<?php echo $min; ?>" max="<?php echo $max; ?>"
                       step="<?php echo $step; ?>"
                       data-unit="<?php echo esc_attr($unit); ?>">
                <span class="tix-range-val"><?php echo $val . $unit; ?></span>
            </div>
        </div>
        <?php
    }

    private static function text_row($key, $label, $s, $placeholder = '') {
        $val  = $s[$key] ?? '';
        $name = self::OPTION_KEY . "[$key]";
        ?>
        <div class="tix-field">
            <label class="tix-field-label"><?php echo esc_html($label); ?></label>
            <input type="text" name="<?php echo esc_attr($name); ?>"
                   value="<?php echo esc_attr($val); ?>"
                   class="regular-text tix-text-input"
                   placeholder="<?php echo esc_attr($placeholder); ?>">
        </div>
        <?php
    }

    private static function checkbox_row($key, $label, $s, $desc = '') {
        $val = $s[$key] ?? 0;
        ?>
        <label class="tix-checkbox-label">
            <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked($val, 1); ?>>
            <span><?php echo esc_html($label); ?></span>
        </label>
        <?php if ($desc): ?>
            <p class="tix-settings-hint"><?php echo $desc; ?></p>
        <?php endif;
    }

    /**
     * WooCommerce Mein-Konto CSS bedingt laden
     */
    public static function enqueue_myaccount_css() {
        if (!function_exists('is_account_page') || !is_account_page()) return;
        if (empty(self::get()['myaccount_restyle'])) return;
        wp_enqueue_style('tix-my-account',
            TIXOMAT_URL . 'assets/css/my-account.css', [], TIXOMAT_VERSION);
    }
}
