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
            'login_redirect'      => '',       // Weiterleitung nach Login (leer = Backend)
            'organizer_slug'      => '',
            'my_tickets_slug'     => 'tickets', // Slug für Meine-Tickets-Seite
            'admin_logo_url'      => '',       // Custom Logo für Admin-Shell, Login, Dashboard
            'theme_mode'          => 'light',

            // ── Farbpalette ──
            'color_palette'       => [],   // Array of {name, color} (max 12)

            // ── Farben ──
            'color_primary'       => '#FF5500', // Primärfarbe (ersetzt Orange überall)
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
            'ep_template_enabled' => 0,       // Eigenes Template statt Breakdance
            'ep_hero_height'     => 380,
            'ep_layout'          => '2col',   // '1col' oder '2col'
            'ep_max_width'       => 1100,
            'ep_gap'             => 32,
            'ep_pad_x'           => 32,       // Padding links/rechts
            'ep_pad_y'           => 40,       // Padding oben/unten
            'ep_sticky_offset'   => 56,       // Abstand unter Sticky-Header (px)
            // ── Event-Karten Seite ──
            'ec_page_enabled'    => 0,        // Automatische /events/ Seite
            'ec_pad_x'           => 32,
            'ec_pad_y'           => 56,
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
            'enable_bacs'          => 0,
            'enable_cod'           => 0,
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

            // ── HTML-Ticket ──
            'ht_logo_height'     => 44,
            'ht_header_bg'       => '#222222',
            'ht_header_text'     => '#ffffff',
            'ht_body_bg'         => '#ffffff',
            'ht_text_color'      => '#1a1a1a',
            'ht_label_color'     => '#888888',
            'ht_border_color'    => '#222222',
            'ht_footer_color'    => '#888888',
            'ht_divider_color'   => '#cccccc',
            'ht_btn_bg'          => '#222222',
            'ht_btn_text'        => '#ffffff',
            'ht_border_radius'   => 12,
            'ht_footer_text'     => 'Bitte dieses Ticket ausgedruckt oder digital zum Einlass mitbringen.',
            'ht_logo_url'        => '',

            // ── E-Mail ──
            'email_logo_url'     => '',       // URL zum Logo-Bild
            'email_logo_height'  => 40,       // Logo-Höhe in px
            'email_brand_name'   => '',       // Firmenname (leer = Blogname)
            'email_footer_text'  => '',       // Footer-Text (leer = Default)
            'email_header_bg'    => '',       // leer = globaler Accent
            'email_header_text'  => '',       // leer = globaler Accent-Text
            'email_body_bg'      => '#ffffff',
            'email_text_color'   => '#1a1a1a',
            'email_muted_color'  => '#6b7280',
            'email_outer_bg'     => '#f3f4f6',
            'email_border_color' => '#e5e7eb',
            'email_btn_bg'       => '',       // leer = globaler Accent
            'email_btn_text'     => '',       // leer = globaler Accent-Text
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
            // ── KI / Künstliche Intelligenz ──
            'anthropic_api_key' => '',
            'openai_api_key'    => '',
            'ai_model'          => 'claude-sonnet-4-20250514',
            'ai_assistant_name' => 'Evendis-Assistent',
            'ai_guard_enabled'  => 0,
            'ai_guard_api_key'  => '', // Legacy – wird migriert zu anthropic_api_key
            // ── Gebühren / Provisionen ──
            'fee_fixed'            => 0,       // Fixbetrag pro Ticket in €
            'fee_percent'          => 0,       // Prozentualer Anteil am Ticketpreis
            'fee_mode'             => 'organizer', // organizer = unsichtbar abgezogen, customer = aufgeschlagen
            'fee_label'            => 'Servicegebühr', // Bezeichnung für den Kunden
            'fee_rounding'         => 'none',  // none, 0.90, 0.99, 0.50, 0.00, custom
            'fee_rounding_custom'  => '',      // Eigener Nachkomma-Wert z.B. 0.49
            'fee_max_per_ticket'   => 0,       // Max-Gebühr pro Ticket (0 = unbegrenzt)
            'fee_max_per_order'    => 0,       // Max-Gebühr pro Bestellung (0 = unbegrenzt, überschreibt pro Ticket)
            'fee_show_in_selector' => 0,       // Gebührenhinweis unter Ticket-Selektor anzeigen
            'gateway_fee_fixed'    => 0,       // Gateway-Fixkosten pro Transaktion
            'gateway_fee_percent'  => 0,       // Gateway-Prozent pro Transaktion
            'gateway_fee_mode'     => 'organizer', // organizer oder customer

            // ── Steuern ──
            'tax_enabled'       => 0,
            'tax_rate'          => 19,
            'tax_inclusive'     => 1, // German standard: prices include MwSt
            // ── Checkout-Modus ──
            'checkout_mode'     => 'auto', // auto (WC wenn vorhanden), woocommerce, native
            // ── Payment Gateways (nativer Checkout) ──
            'mollie_api_key'      => '',
            'mollie_test_mode'    => 0,
            'paypal_client_id'    => '',
            'paypal_secret'       => '',
            'paypal_sandbox'      => 0,
            'bank_transfer_enabled' => 0,
            'bank_name'           => '',
            'bank_iban'           => '',
            'bank_bic'            => '',
            'bank_holder'         => '',
            'bank_reference'      => 'Bestellnummer angeben',
            // ── Event-Karten ──
            'tix_card_color_signal'      => '#E8445A',
            'tix_card_color_signal_mid'  => '#F2899A',
            'tix_card_color_nacht'       => '#131020',
            'tix_card_color_nacht_soft'  => '#1C1831',
            'tix_card_color_spotlight'   => '#F5B731',
            'tix_card_color_entdecken'   => '#14B8A6',
            'tix_card_color_licht'       => '#FDFBF7',
            'tix_card_color_sand'        => '#F0ECE4',
            'tix_card_color_text'        => '#131020',
            'tix_card_color_text_muted'  => '#5C5A57',
            'tix_card_radius_card'       => 16,
            'tix_card_radius_badge'      => 8,
            'tix_card_radius_button'     => 10,
            'tix_card_radius_image'      => 12,
            'tix_card_gap_col'           => 20,
            'tix_card_gap_row'           => 24,
            'tix_card_font_display'      => 'Sora',
            'tix_card_font_body'         => 'DM Sans',
            'tix_card_font_size_title'   => '1.05',
            'tix_card_font_size_date'    => '0.8',
            'tix_card_font_size_price'   => '1.1',
            'tix_card_font_self_host'    => 0,
            'tix_card_columns_default'   => 4,
            'tix_card_cols_tablet_l'     => 3, // ≤1119px
            'tix_card_cols_tablet_p'     => 2, // ≤1023px
            'tix_card_cols_phone_l'      => 2, // ≤767px
            'tix_card_cols_phone_p'      => 1, // ≤479px
            'tix_card_image_ratio'       => '58',
            'tix_card_show_heart'        => 1,
            'tix_card_show_badges'       => 1,
            'tix_card_default_mode'      => 'light',
            // ── Globale Typografie ──
            'tix_typo_font_heading' => 'Sora',
            'tix_typo_font_body'    => 'DM Sans',
            'tix_typo_base_size'    => 15,
            'tix_typo_breakdance_sync' => 0,
            'tix_typo_classes'      => [],
            'tix_color_classes'     => [],
            // ── Syndication (Selfhosted → Evendis) ──
            'syndication_enabled'   => 0,
            'syndication_api_url'   => '',
            'syndication_api_key'   => '',
            'syndication_site_name' => '',
            // ── Syndication Empfang ──
            'syndication_receive_enabled' => 0,
            'syndication_receive_key'     => '',
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
            // ── Meta Ads ──
            'meta_pixel_enabled'       => 0,
            'meta_pixel_id'            => '',
            'meta_access_token'        => '',
            'meta_test_event_code'     => '',
            'meta_capi_enabled'        => 0,
            'meta_consent_mode'        => 'always',    // always | consent_required
            'meta_consent_cookie'      => 'cookie_consent',
            'meta_catalog_enabled'     => 0,
            'meta_feed_key'            => '',
            // ── Custom Order Numbers ──
            'order_number_enabled'     => 0,
            'order_number_prefix'      => 'TIX-',
            'order_number_digits'      => 5,        // Mindestanzahl Ziffern (z.B. 5 → 00001)
            'order_number_suffix'      => '',
            'order_number_start'       => 1,        // Startwert für den Zähler
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
            // (Wizard-Modus entfernt)
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
            // ── Rechnungen ──
            'invoice_company_name'    => '',
            'invoice_company_address' => '',
            'invoice_company_tax_id'  => '',
            'invoice_footer_text'     => '',
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
        add_action('admin_head', [__CLASS__, 'output_admin_primary_css'], 99);
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
        $clean['color_primary']  = self::sanitize_color($input['color_primary'] ?? '') ?: '#FF5500';
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
        $clean['ep_template_enabled'] = !empty($input['ep_template_enabled']) ? 1 : 0;
        $clean['ep_hero_height'] = max(150, min(600, intval($input['ep_hero_height'] ?? 380)));
        $clean['ep_layout'] = in_array($input['ep_layout'] ?? '', ['1col', '2col']) ? $input['ep_layout'] : '2col';
        $clean['ep_pad_x'] = max(0, min(80, intval($input['ep_pad_x'] ?? 32)));
        $clean['ep_pad_y'] = max(0, min(120, intval($input['ep_pad_y'] ?? 40)));
        $clean['ep_sticky_offset'] = max(0, min(200, intval($input['ep_sticky_offset'] ?? 56)));
        $clean['ec_page_enabled'] = !empty($input['ec_page_enabled']) ? 1 : 0;
        $clean['ec_pad_x'] = max(0, min(80, intval($input['ec_pad_x'] ?? 32)));
        $clean['ec_pad_y'] = max(0, min(120, intval($input['ec_pad_y'] ?? 56)));
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
        $clean['enable_bacs']          = !empty($input['enable_bacs']) ? 1 : 0;
        $clean['enable_cod']           = !empty($input['enable_cod']) ? 1 : 0;
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

        // HTML-Ticket Farben
        foreach (['ht_header_bg', 'ht_header_text', 'ht_body_bg', 'ht_text_color', 'ht_label_color', 'ht_border_color', 'ht_footer_color', 'ht_divider_color', 'ht_btn_bg', 'ht_btn_text'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: $d[$k];
        }
        $clean['ht_border_radius'] = max(0, min(30, intval($input['ht_border_radius'] ?? 12)));
        $clean['ht_logo_height']   = max(20, min(120, intval($input['ht_logo_height'] ?? 44)));
        $clean['ht_footer_text']   = sanitize_text_field($input['ht_footer_text'] ?? $d['ht_footer_text']);
        $clean['ht_logo_url']      = esc_url_raw($input['ht_logo_url'] ?? '');

        // E-Mail
        $clean['email_logo_url']     = esc_url_raw($input['email_logo_url'] ?? '');
        $clean['email_logo_height']  = max(20, min(120, intval($input['email_logo_height'] ?? 40)));
        $clean['email_brand_name']   = sanitize_text_field($input['email_brand_name'] ?? '');
        $clean['email_footer_text']  = sanitize_text_field($input['email_footer_text'] ?? '');
        foreach (['email_header_bg', 'email_header_text', 'email_body_bg', 'email_text_color', 'email_muted_color', 'email_outer_bg', 'email_border_color', 'email_btn_bg', 'email_btn_text'] as $k) {
            $clean[$k] = self::sanitize_color($input[$k] ?? '') ?: '';
        }
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

        // Gebühren / Provisionen
        $clean['fee_fixed']         = max(0, floatval($input['fee_fixed'] ?? 0));
        $clean['fee_percent']       = max(0, min(100, floatval($input['fee_percent'] ?? 0)));
        $clean['fee_mode']          = in_array($input['fee_mode'] ?? 'organizer', ['organizer', 'customer']) ? $input['fee_mode'] : 'organizer';
        $clean['fee_label']         = sanitize_text_field($input['fee_label'] ?? 'Servicegebühr');
        $valid_rounding = ['none', '0.90', '0.99', '0.50', '0.00', 'custom'];
        $clean['fee_rounding']      = in_array($input['fee_rounding'] ?? 'none', $valid_rounding) ? $input['fee_rounding'] : 'none';
        $clean['fee_rounding_custom'] = max(0, min(0.99, floatval($input['fee_rounding_custom'] ?? 0)));
        $clean['fee_max_per_ticket'] = max(0, floatval($input['fee_max_per_ticket'] ?? 0));
        $clean['fee_max_per_order']  = max(0, floatval($input['fee_max_per_order'] ?? 0));
        $clean['fee_show_in_selector'] = !empty($input['fee_show_in_selector']) ? 1 : 0;
        $clean['gateway_fee_fixed'] = max(0, floatval($input['gateway_fee_fixed'] ?? 0));
        $clean['gateway_fee_percent'] = max(0, min(100, floatval($input['gateway_fee_percent'] ?? 0)));
        $clean['gateway_fee_mode']  = in_array($input['gateway_fee_mode'] ?? 'organizer', ['organizer', 'customer']) ? $input['gateway_fee_mode'] : 'organizer';

        // Steuern
        $clean['tax_enabled']   = !empty($input['tax_enabled']) ? 1 : 0;
        $clean['tax_rate']      = max(0, min(100, floatval($input['tax_rate'] ?? 19)));
        $clean['tax_inclusive'] = !empty($input['tax_inclusive']) ? 1 : 0;

        // Checkout-Modus + Payment Gateways
        $clean['checkout_mode'] = in_array($input['checkout_mode'] ?? 'auto', ['auto', 'woocommerce', 'native']) ? $input['checkout_mode'] : 'auto';
        $clean['mollie_api_key']   = sanitize_text_field($input['mollie_api_key'] ?? '');
        $clean['mollie_test_mode'] = !empty($input['mollie_test_mode']) ? 1 : 0;
        $clean['paypal_client_id'] = sanitize_text_field($input['paypal_client_id'] ?? '');
        $clean['paypal_secret']    = sanitize_text_field($input['paypal_secret'] ?? '');
        $clean['paypal_sandbox']   = !empty($input['paypal_sandbox']) ? 1 : 0;
        $clean['bank_transfer_enabled'] = !empty($input['bank_transfer_enabled']) ? 1 : 0;
        $clean['bank_name']     = sanitize_text_field($input['bank_name'] ?? '');
        $clean['bank_iban']     = sanitize_text_field($input['bank_iban'] ?? '');
        $clean['bank_bic']      = sanitize_text_field($input['bank_bic'] ?? '');
        $clean['bank_holder']   = sanitize_text_field($input['bank_holder'] ?? '');
        $clean['bank_reference'] = sanitize_text_field($input['bank_reference'] ?? '');

        // Syndication
        $clean['syndication_enabled']        = !empty($input['syndication_enabled']) ? 1 : 0;
        $clean['syndication_api_url']        = esc_url_raw($input['syndication_api_url'] ?? '');
        $clean['syndication_api_key']        = sanitize_text_field($input['syndication_api_key'] ?? '');
        $clean['syndication_site_name']      = sanitize_text_field($input['syndication_site_name'] ?? '');
        $clean['syndication_receive_enabled'] = !empty($input['syndication_receive_enabled']) ? 1 : 0;
        $clean['syndication_receive_key']    = sanitize_text_field($input['syndication_receive_key'] ?? '');

        // Event-Karten
        foreach (['tix_card_color_signal', 'tix_card_color_signal_mid', 'tix_card_color_nacht', 'tix_card_color_nacht_soft', 'tix_card_color_spotlight', 'tix_card_color_entdecken', 'tix_card_color_licht', 'tix_card_color_sand', 'tix_card_color_text', 'tix_card_color_text_muted'] as $k) {
            $clean[$k] = sanitize_hex_color($input[$k] ?? '') ?: ($d[$k] ?? '');
        }
        foreach (['tix_card_radius_card', 'tix_card_radius_badge', 'tix_card_radius_button', 'tix_card_radius_image', 'tix_card_gap_col', 'tix_card_gap_row', 'tix_card_image_ratio', 'tix_card_columns_default', 'tix_card_cols_tablet_l', 'tix_card_cols_tablet_p', 'tix_card_cols_phone_l', 'tix_card_cols_phone_p'] as $k) {
            $clean[$k] = intval($input[$k] ?? $d[$k] ?? 0);
        }
        foreach (['tix_card_font_size_title', 'tix_card_font_size_date', 'tix_card_font_size_price'] as $k) {
            $clean[$k] = floatval($input[$k] ?? $d[$k] ?? 1);
        }
        $clean['tix_card_font_display']   = sanitize_text_field($input['tix_card_font_display'] ?? 'Sora');
        $clean['tix_card_font_body']      = sanitize_text_field($input['tix_card_font_body'] ?? 'DM Sans');
        $clean['tix_card_font_self_host'] = !empty($input['tix_card_font_self_host']) ? 1 : 0;
        $clean['tix_card_show_heart']     = !empty($input['tix_card_show_heart']) ? 1 : 0;
        $clean['tix_card_show_badges']    = !empty($input['tix_card_show_badges']) ? 1 : 0;
        $clean['tix_card_default_mode']   = in_array($input['tix_card_default_mode'] ?? 'light', ['light', 'dark']) ? $input['tix_card_default_mode'] : 'light';

        // Globale Typografie (3 Regler)
        $clean['tix_typo_font_heading'] = sanitize_text_field($input['tix_typo_font_heading'] ?? 'Sora');
        $clean['tix_typo_font_body']    = sanitize_text_field($input['tix_typo_font_body'] ?? 'DM Sans');
        $clean['tix_typo_base_size']    = max(10, min(22, intval($input['tix_typo_base_size'] ?? 15)));
        $clean['tix_typo_breakdance_sync'] = !empty($input['tix_typo_breakdance_sync']) ? 1 : 0;

        // Per-Class Typografie Overrides (via JSON hidden input — umgeht max_input_vars)
        $clean['tix_typo_classes'] = [];
        $typo_json = $input['tix_typo_classes_json'] ?? '';
        if (!empty($typo_json)) {
            $typo_data = json_decode(wp_unslash($typo_json), true);
            if (is_array($typo_data)) {
                $registry = self::typo_class_registry_flat();
                $allowed_fonts = ['Sora', 'DM Sans', 'Inter', 'Outfit', 'Poppins', 'Montserrat', 'Open Sans', 'Roboto', 'Lato', 'Nunito', 'Raleway', 'Playfair Display', 'Oswald', 'Source Sans 3', 'Work Sans', 'Manrope', 'Plus Jakarta Sans', 'Figtree', 'heading', 'body'];
                foreach ($typo_data as $cls => $vals) {
                    $cls = sanitize_text_field($cls);
                    if (!isset($registry[$cls]) || !is_array($vals)) continue;
                    $def = $registry[$cls];
                    $entry = [];
                    if (isset($vals['size'])) {
                        $size = intval($vals['size']);
                        if ($size !== $def['size'] && $size >= 8 && $size <= 60) $entry['size'] = $size;
                    }
                    // Responsive Breakpoint Sizes
                    foreach (['size_tl', 'size_tp', 'size_pl', 'size_pp'] as $bp_key) {
                        if (isset($vals[$bp_key]) && $vals[$bp_key] !== '') {
                            $bp_size = intval($vals[$bp_key]);
                            if ($bp_size >= 8 && $bp_size <= 60) $entry[$bp_key] = $bp_size;
                        }
                    }
                    if (isset($vals['font'])) {
                        $font = sanitize_text_field($vals['font']);
                        if ($font !== $def['font'] && in_array($font, $allowed_fonts, true)) $entry['font'] = $font;
                    }
                    if (isset($vals['weight'])) {
                        $weight = intval($vals['weight']);
                        if ($weight !== $def['weight'] && $weight >= 100 && $weight <= 900 && $weight % 100 === 0) $entry['weight'] = $weight;
                    }
                    if (!empty($entry)) $clean['tix_typo_classes'][$cls] = $entry;
                }
            }
        }
        // Wenn kein JSON gesendet wurde, bestehende Overrides aus DB beibehalten
        if (empty($typo_json)) {
            $existing = get_option('tix_settings', []);
            if (!empty($existing['tix_typo_classes']) && is_array($existing['tix_typo_classes'])) {
                $clean['tix_typo_classes'] = $existing['tix_typo_classes'];
            }
        }

        // Per-Class Farben Overrides (via JSON hidden input — umgeht max_input_vars)
        $clean['tix_color_classes'] = [];
        $color_json = $input['tix_color_classes_json'] ?? '';
        if (!empty($color_json)) {
            $color_data = json_decode(wp_unslash($color_json), true);
            if (is_array($color_data)) {
                $color_registry = self::color_class_registry_flat();
                foreach ($color_data as $cls => $vals) {
                    $cls = sanitize_text_field($cls);
                    if (!isset($color_registry[$cls]) || !is_array($vals)) continue;
                    $def = $color_registry[$cls];
                    $entry = [];
                    foreach (['color', 'bg', 'border'] as $prop) {
                        if (isset($vals[$prop]) && isset($def['props'][$prop])) {
                            $val = sanitize_text_field($vals[$prop]);
                            // Erlaube nur gültige Farbwerte
                            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $val) || preg_match('/^(rgb|hsl)a?\(/', $val)) {
                                if ($val !== $def['props'][$prop]) {
                                    $entry[$prop] = $val;
                                }
                            }
                        }
                    }
                    if (!empty($entry)) $clean['tix_color_classes'][$cls] = $entry;
                }
            }
        }
        if (empty($color_json)) {
            $existing = $existing ?? get_option('tix_settings', []);
            if (!empty($existing['tix_color_classes']) && is_array($existing['tix_color_classes'])) {
                $clean['tix_color_classes'] = $existing['tix_color_classes'];
            }
        }

        // KI / Künstliche Intelligenz
        $clean['ai_assistant_name'] = sanitize_text_field($input['ai_assistant_name'] ?? 'Evendis-Assistent');
        $clean['anthropic_api_key'] = sanitize_text_field($input['anthropic_api_key'] ?? '');
        $clean['openai_api_key'] = sanitize_text_field($input['openai_api_key'] ?? '');
        $clean['ai_model'] = sanitize_text_field($input['ai_model'] ?? 'claude-sonnet-4-20250514');
        $clean['ai_guard_enabled'] = !empty($input['ai_guard_enabled']) ? 1 : 0;
        // Legacy-Migration: alten Key übernehmen falls neuer leer
        if (empty($clean['anthropic_api_key']) && !empty($input['ai_guard_api_key'])) {
            $clean['anthropic_api_key'] = sanitize_text_field($input['ai_guard_api_key']);
        }
        $clean['ai_guard_api_key'] = $clean['anthropic_api_key']; // Sync für Rückwärtskompatibilität

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
        // Meta Ads
        $clean['meta_pixel_enabled']     = !empty($input['meta_pixel_enabled']) ? 1 : 0;
        $clean['meta_pixel_id']          = preg_replace('/[^0-9]/', '', $input['meta_pixel_id'] ?? '');
        $clean['meta_access_token']      = sanitize_text_field($input['meta_access_token'] ?? '');
        $clean['meta_test_event_code']   = sanitize_text_field($input['meta_test_event_code'] ?? '');
        $clean['meta_capi_enabled']      = !empty($input['meta_capi_enabled']) ? 1 : 0;
        $clean['meta_consent_mode']      = in_array($input['meta_consent_mode'] ?? '', ['always', 'consent_required']) ? $input['meta_consent_mode'] : 'always';
        $clean['meta_consent_cookie']    = sanitize_text_field($input['meta_consent_cookie'] ?? 'cookie_consent');
        $clean['meta_catalog_enabled']   = !empty($input['meta_catalog_enabled']) ? 1 : 0;
        // Feed-Key: beibehalten oder neu generieren
        $existing_key = $old['meta_feed_key'] ?? '';
        $clean['meta_feed_key'] = !empty($existing_key) ? $existing_key : wp_generate_password(32, false);

        // Custom Order Numbers
        $clean['order_number_enabled'] = !empty($input['order_number_enabled']) ? 1 : 0;
        $clean['order_number_prefix']  = sanitize_text_field($input['order_number_prefix'] ?? 'TIX-');
        $clean['order_number_digits']  = max(3, min(10, intval($input['order_number_digits'] ?? 5)));
        $clean['order_number_suffix']  = sanitize_text_field($input['order_number_suffix'] ?? '');
        $clean['order_number_start']   = max(1, intval($input['order_number_start'] ?? 1));

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

        // Admin-Ansicht
        $clean['fullscreen_admin'] = !empty($input['fullscreen_admin']) ? 1 : 0;

        // Custom URLs
        $clean['login_slug'] = sanitize_title(trim($input['login_slug'] ?? ''));
        $clean['login_redirect'] = sanitize_text_field(trim($input['login_redirect'] ?? ''));
        $clean['organizer_slug'] = sanitize_title(trim($input['organizer_slug'] ?? ''));
        $clean['my_tickets_slug'] = sanitize_title(trim($input['my_tickets_slug'] ?? '')) ?: 'tickets';

        // Custom Logo
        $clean['admin_logo_url'] = esc_url_raw($input['admin_logo_url'] ?? '');

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

        // Rechnungen
        $clean['invoice_company_name']    = sanitize_text_field($input['invoice_company_name'] ?? '');
        $clean['invoice_company_address'] = sanitize_textarea_field($input['invoice_company_address'] ?? '');
        $clean['invoice_company_tax_id']  = sanitize_text_field($input['invoice_company_tax_id'] ?? '');
        $clean['invoice_footer_text']     = sanitize_textarea_field($input['invoice_footer_text'] ?? '');

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
    /** Primärfarbe auch im Admin-Bereich ausgeben. */
    public static function output_admin_primary_css() {
        $primary = (self::get())['color_primary'] ?? '#FF5500';
        echo "<style>:root{--tix-primary:{$primary};}</style>\n";
    }

    public static function output_css() {
        $s = self::get();
        $d = self::defaults();

        $vars = [];

        // Primärfarbe + RGB/Hover immer ausgeben
        $primary = $s['color_primary'] ?? '#FF5500';
        $hex = ltrim($primary, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $vars[] = "--tix-primary: $primary";
        $vars[] = "--tix-primary-rgb: $r,$g,$b";
        $vars[] = sprintf("--tix-primary-hover: #%02x%02x%02x", max(0,$r-40), max(0,$g-40), max(0,$b-40));

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

        // Schriftgrößen (font_price, font_name etc.) jetzt über Typografie-Tab per Klasse
        $vat_css  = '';

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
            echo "<style id=\"tix-custom-vars\">\n:root, .tix-sel, .tix-co, .tix-faq, .tix-up, .tix-mt, .tix-mc-trigger, .tix-mc-overlay, .tix-cal, .tix-raffle, .tix-ec-trigger, .tix-ec-overlay, .tix-table-btn-wrap, #tix-table-res-app, .tr-modal-overlay, .tix-sp-portal, .tix-sp-chat-panel{$wc_scope} {\n    " . implode(";\n    ", $vars) . ";\n}\n";
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
        // Deckkraft (Schriftgrößen jetzt über Typografie-Tab)
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
        // Padding + Sticky-Vars
        $ep_vars[] = '--tse-pad-x: ' . intval($s['ep_pad_x'] ?? 32) . 'px';
        $ep_vars[] = '--tse-pad-y: ' . intval($s['ep_pad_y'] ?? 40) . 'px';
        $ep_vars[] = '--tse-sticky-offset: ' . intval($s['ep_sticky_offset'] ?? 56) . 'px';
        if (!empty($ep_vars)) {
            echo ".tix-ep, .tse-wrap {\n    " . implode(";\n    ", $ep_vars) . ";\n}\n";
        }
        // Event-Karten: Padding nur als CSS-Var (Breakdance steuert Hintergrund)
        // Padding wird nur im archive-event.php Template angewendet, nicht im Shortcode

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

        // ── Globale Typografie Variablen (proportionale Skala) ──
        $typo_heading = esc_attr($s['tix_typo_font_heading'] ?? 'Sora');
        $typo_body    = esc_attr($s['tix_typo_font_body'] ?? 'DM Sans');
        $base         = max(10, min(22, intval($s['tix_typo_base_size'] ?? 15)));

        // Breakdance Sync
        if (!empty($s['tix_typo_breakdance_sync']) && defined('__BREAKDANCE_VERSION')) {
            $bd_raw = get_option('breakdance_settings_json', '{}');
            $bd = json_decode($bd_raw, true);
            if (!empty($bd['settings']['globalStyles']['typography'])) {
                $bdt = $bd['settings']['globalStyles']['typography'];
                if (!empty($bdt['headingFontFamily'])) $typo_heading = sanitize_text_field($bdt['headingFontFamily']);
                if (!empty($bdt['bodyFontFamily']))    $typo_body = sanitize_text_field($bdt['bodyFontFamily']);
                if (!empty($bdt['body']['fontSize']))  $base = intval($bdt['body']['fontSize']);
            }
        }

        // Proportionale Skala: alles relativ zur Basisgröße
        $typo_vars = [];
        $typo_vars[] = "--tix-font-heading: '{$typo_heading}', sans-serif";
        $typo_vars[] = "--tix-font-body: '{$typo_body}', sans-serif";
        $typo_vars[] = '--tix-h1: ' . round($base * 1.87) . 'px';   // 15→28
        $typo_vars[] = '--tix-h2: ' . round($base * 1.47) . 'px';   // 15→22
        $typo_vars[] = '--tix-h3: ' . round($base * 1.20) . 'px';   // 15→18
        $typo_vars[] = '--tix-h4: ' . round($base * 1.07) . 'px';   // 15→16
        $typo_vars[] = '--tix-h5: ' . round($base * 0.93) . 'px';   // 15→14
        $typo_vars[] = '--tix-h6: ' . round($base * 0.87) . 'px';   // 15→13
        $typo_vars[] = '--tix-body: ' . $base . 'px';
        $typo_vars[] = '--tix-small: ' . round($base * 0.87) . 'px'; // 15→13
        $typo_vars[] = '--tix-btn: ' . round($base * 0.93) . 'px';   // 15→14
        $typo_vars[] = '--tix-label: ' . round($base * 0.80) . 'px'; // 15→12

        echo ":root {\n    " . implode(";\n    ", $typo_vars) . ";\n}\n";

        // ── Event-Karten Variablen ──
        $card_map = [
            'tix_card_color_signal'     => '--tix-card-signal',
            'tix_card_color_signal_mid' => '--tix-card-signal-mid',
            'tix_card_color_nacht'      => '--tix-card-nacht',
            'tix_card_color_nacht_soft' => '--tix-card-nacht-soft',
            'tix_card_color_spotlight'  => '--tix-card-spotlight',
            'tix_card_color_entdecken'  => '--tix-card-entdecken',
            'tix_card_color_licht'      => '--tix-card-licht',
            'tix_card_color_sand'       => '--tix-card-sand',
            'tix_card_color_text'       => '--tix-card-text',
            'tix_card_color_text_muted' => '--tix-card-text-muted',
        ];
        $card_vars = [];
        foreach ($card_map as $key => $var) {
            if (!empty($s[$key])) $card_vars[] = "{$var}: {$s[$key]}";
        }
        // Radius & Spacing
        $card_vars[] = '--tix-card-radius: ' . intval($s['tix_card_radius_card'] ?? 16) . 'px';
        $card_vars[] = '--tix-card-radius-badge: ' . intval($s['tix_card_radius_badge'] ?? 8) . 'px';
        $card_vars[] = '--tix-card-radius-btn: ' . intval($s['tix_card_radius_button'] ?? 10) . 'px';
        $card_vars[] = '--tix-card-radius-img: ' . intval($s['tix_card_radius_image'] ?? 12) . 'px';
        $card_vars[] = '--tix-card-gap-col: ' . intval($s['tix_card_gap_col'] ?? 20) . 'px';
        $card_vars[] = '--tix-card-gap-row: ' . intval($s['tix_card_gap_row'] ?? 24) . 'px';
        $card_vars[] = '--tix-card-gap: ' . intval($s['tix_card_gap_row'] ?? 24) . 'px ' . intval($s['tix_card_gap_col'] ?? 20) . 'px';
        $card_vars[] = '--tix-card-img-ratio: ' . intval($s['tix_card_image_ratio'] ?? 58) . '%';
        $card_vars[] = '--tix-card-cols: ' . intval($s['tix_card_columns_default'] ?? 4);
        // Fonts
        $card_vars[] = "--tix-card-font-d: '" . esc_attr($s['tix_card_font_display'] ?? 'Sora') . "'";
        $card_vars[] = "--tix-card-font-b: '" . esc_attr($s['tix_card_font_body'] ?? 'DM Sans') . "'";
        $card_vars[] = '--tix-card-font-title: ' . floatval($s['tix_card_font_size_title'] ?? 1.05) . 'rem';
        $card_vars[] = '--tix-card-font-date: ' . floatval($s['tix_card_font_size_date'] ?? 0.8) . 'rem';
        $card_vars[] = '--tix-card-font-price: ' . floatval($s['tix_card_font_size_price'] ?? 1.1) . 'rem';

        if (!empty($card_vars)) {
            echo ":root {\n    " . implode(";\n    ", $card_vars) . ";\n}\n";

            // Responsive Spalten per Breakpoint
            $bp = [
                1119 => intval($s['tix_card_cols_tablet_l'] ?? 3),
                1023 => intval($s['tix_card_cols_tablet_p'] ?? 2),
                767  => intval($s['tix_card_cols_phone_l'] ?? 2),
                479  => intval($s['tix_card_cols_phone_p'] ?? 1),
            ];
            foreach ($bp as $width => $cols) {
                echo "@media(max-width:{$width}px){:root{--tix-card-cols:{$cols};}}\n";
            }
        }

        // ── Per-Class Typografie Overrides ──
        $typo_classes = $s['tix_typo_classes'] ?? [];
        if (!empty($typo_classes) && is_array($typo_classes)) {
            $registry_flat = self::typo_class_registry_flat();

            // Breakpoint-Sammler: width => ['.cls { font-size: Xpx !important; }', ...]
            $bp_map = [
                'size_tl' => 1119,
                'size_tp' => 1023,
                'size_pl' => 767,
                'size_pp' => 479,
            ];
            $bp_rules = [];

            foreach ($typo_classes as $cls => $vals) {
                if (!isset($registry_flat[$cls]) || !is_array($vals)) continue;

                // Desktop-Regel (size, font, weight)
                $props = [];
                if (isset($vals['size'])) {
                    $props[] = 'font-size: ' . intval($vals['size']) . 'px !important';
                }
                if (isset($vals['font'])) {
                    $f = esc_attr($vals['font']);
                    if ($f === 'heading') {
                        $props[] = "font-family: '{$typo_heading}', sans-serif !important";
                    } elseif ($f === 'body') {
                        $props[] = "font-family: '{$typo_body}', sans-serif !important";
                    } else {
                        $props[] = "font-family: '{$f}', sans-serif !important";
                    }
                }
                if (isset($vals['weight'])) {
                    $props[] = 'font-weight: ' . intval($vals['weight']) . ' !important';
                }
                if (!empty($props)) {
                    echo '.' . $cls . " { " . implode('; ', $props) . "; }\n";
                }

                // Responsive Breakpoint font-sizes
                foreach ($bp_map as $bp_key => $width) {
                    if (isset($vals[$bp_key])) {
                        $bp_rules[$width][] = '.' . $cls . ' { font-size: ' . intval($vals[$bp_key]) . 'px !important; }';
                    }
                }
            }

            // Media-Queries ausgeben (absteigend sortiert, damit Kaskade stimmt)
            krsort($bp_rules);
            foreach ($bp_rules as $width => $rules) {
                echo "@media(max-width:{$width}px){\n" . implode("\n", $rules) . "\n}\n";
            }
        }

        // ── Per-Class Farben Overrides ──
        $color_classes = $s['tix_color_classes'] ?? [];
        if (!empty($color_classes) && is_array($color_classes)) {
            $color_registry_flat = self::color_class_registry_flat();
            $css_prop_map = ['color' => 'color', 'bg' => 'background-color', 'border' => 'border-color'];
            foreach ($color_classes as $cls => $vals) {
                if (!isset($color_registry_flat[$cls]) || !is_array($vals)) continue;
                $props = [];
                foreach ($css_prop_map as $key => $css_prop) {
                    if (isset($vals[$key])) {
                        $props[] = $css_prop . ': ' . esc_attr($vals[$key]) . ' !important';
                    }
                }
                if (!empty($props)) {
                    echo '.' . $cls . " { " . implode('; ', $props) . "; }\n";
                }
            }
        }

        echo "</style>\n";
    }

    /**
     * Google Fonts laden (globale Typografie + Event-Karten)
     */
    public static function enqueue_card_fonts() {
        $s = self::get();
        if (!empty($s['tix_card_font_self_host'])) return;

        $all_fonts = [];

        // Globale Typografie-Fonts
        $typo_h = $s['tix_typo_font_heading'] ?? 'Sora';
        $typo_b = $s['tix_typo_font_body'] ?? 'DM Sans';
        if ($typo_h) $all_fonts[$typo_h] = true;
        if ($typo_b) $all_fonts[$typo_b] = true;

        // Card-spezifische Fonts (falls abweichend)
        $card_d = $s['tix_card_font_display'] ?? 'Sora';
        $card_b = $s['tix_card_font_body'] ?? 'DM Sans';
        if ($card_d) $all_fonts[$card_d] = true;
        if ($card_b) $all_fonts[$card_b] = true;

        // Per-Class Typografie-Fonts
        $typo_classes = $s['tix_typo_classes'] ?? [];
        if (is_array($typo_classes)) {
            foreach ($typo_classes as $vals) {
                if (!empty($vals['font']) && $vals['font'] !== 'heading' && $vals['font'] !== 'body') {
                    $all_fonts[$vals['font']] = true;
                }
            }
        }

        $families = [];
        foreach (array_keys($all_fonts) as $f) {
            $families[] = str_replace(' ', '+', $f) . ':wght@100;200;300;400;500;600;700;800;900';
        }

        if (!empty($families)) {
            $url = 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $families) . '&display=swap';
            wp_enqueue_style('tix-card-fonts', $url, [], null);
        }
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
                            <button type="button" class="tix-nav-tab" data-tab="fees">
                                <span class="dashicons dashicons-money-alt"></span>
                                <span class="tix-nav-label">Gebühren</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="typography">
                                <span class="dashicons dashicons-editor-textcolor"></span>
                                <span class="tix-nav-label">Typografie</span>
                            </button>
                            <button type="button" class="tix-nav-tab" data-tab="colors">
                                <span class="dashicons dashicons-admin-appearance"></span>
                                <span class="tix-nav-label">Farben</span>
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
                                <button type="button" class="tix-nav-tab" data-tab="ticket-template">
                                    <span class="dashicons dashicons-media-document"></span>
                                    <span class="tix-nav-label">Ticket-Template</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="email-template">
                                    <span class="dashicons dashicons-email-alt"></span>
                                    <span class="tix-nav-label">E-Mail-Template</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="data-sync">
                                    <span class="dashicons dashicons-cloud-saved"></span>
                                    <span class="tix-nav-label">Daten-Sync</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="event-page">
                                    <span class="dashicons dashicons-welcome-widgets-menus"></span>
                                    <span class="tix-nav-label">Event-Seite</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="event-cards">
                                    <span class="dashicons dashicons-screenoptions"></span>
                                    <span class="tix-nav-label">Event-Karten</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="share">
                                    <span class="dashicons dashicons-share"></span>
                                    <span class="tix-nav-label">Share</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="marketing">
                                    <span class="dashicons dashicons-megaphone"></span>
                                    <span class="tix-nav-label">Marketing</span>
                                </button>
                                <button type="button" class="tix-nav-tab" data-tab="meta-ads">
                                    <span class="dashicons dashicons-facebook-alt"></span>
                                    <span class="tix-nav-label">Meta Ads</span>
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
                                            self::color_row('color_primary',       'Prim&auml;rfarbe', $s);
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
                                            ?>
                                        </div>
                                        <p class="tix-settings-hint" style="margin-top:12px;">Schriftgr&ouml;&szlig;e &amp; Schriftgewicht: &uuml;ber Typografie-Tab steuerbar</p>
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
                                            ?>
                                        </div>
                                        <p class="tix-settings-hint" style="margin-top:12px;">Schriftgr&ouml;&szlig;e &amp; Schriftgewicht: &uuml;ber Typografie-Tab steuerbar</p>
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

                                <?php // ── Card: Zahlungsmethoden ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-money-alt"></span>
                                        <h3>Zahlungsmethoden</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('enable_bacs', 'Banküberweisung erlauben', $s, 'Wenn deaktiviert, wird Banküberweisung im Checkout ausgeblendet.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('enable_cod', 'Barzahlung / Abendkasse erlauben', $s, 'Wenn deaktiviert, wird Nachnahme/Barzahlung im Checkout ausgeblendet.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Bestellnummern ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-settings"></span>
                                        <h3>Bestellnummern</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('order_number_enabled', 'Eigene Bestellnummern verwenden', $s, 'Ersetzt die Standard-WooCommerce-Bestellnummern durch ein konfigurierbares Format.'); ?>
                                            </div>
                                            <div id="tix-order-number-fields" <?php echo empty($s['order_number_enabled']) ? 'style="display:none;"' : ''; ?>>
                                                <div class="tix-field-grid" style="margin-top:12px;">
                                                    <?php self::text_row('order_number_prefix', 'Pr&auml;fix', $s, 'TIX-'); ?>
                                                    <?php self::text_row('order_number_suffix', 'Suffix', $s, ''); ?>
                                                    <?php self::range_row('order_number_digits', 'Mindest-Ziffern', $s, 3, 10, ''); ?>
                                                    <?php self::text_row('order_number_start', 'Startwert', $s, '1'); ?>
                                                </div>
                                                <div style="margin-top:12px;padding:12px 16px;background:#f8fafc;border-radius:10px;font-size:13px;color:#475569;">
                                                    <strong>Vorschau:</strong>
                                                    <code id="tix-order-number-preview" style="font-size:14px;font-weight:700;color:#FF5500;">
                                                        <?php
                                                        $p = $s['order_number_prefix'] ?? 'TIX-';
                                                        $d = intval($s['order_number_digits'] ?? 5);
                                                        $sf = $s['order_number_suffix'] ?? '';
                                                        $st = intval($s['order_number_start'] ?? 1);
                                                        echo esc_html($p . str_pad($st, $d, '0', STR_PAD_LEFT) . $sf);
                                                        ?>
                                                    </code>
                                                    <span style="margin-left:8px;color:#94a3b8;">&rarr;</span>
                                                    <code style="font-size:14px;color:#64748b;">
                                                        <?php echo esc_html($p . str_pad($st + 1, $d, '0', STR_PAD_LEFT) . $sf); ?>
                                                    </code>
                                                    <span style="margin-left:8px;color:#94a3b8;">&rarr;</span>
                                                    <code style="font-size:14px;color:#64748b;">
                                                        <?php echo esc_html($p . str_pad($st + 2, $d, '0', STR_PAD_LEFT) . $sf); ?>
                                                    </code>
                                                    &hellip;
                                                </div>
                                            </div>
                                            <script>
                                            jQuery(function($){
                                                $('[name="tix_settings[order_number_enabled]"]').on('change',function(){
                                                    $('#tix-order-number-fields').toggle(this.checked);
                                                });
                                            });
                                            </script>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Checkout-Modus ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-generic"></span>
                                        <h3>Checkout-Modus</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php $checkout_mode = $s['checkout_mode'] ?? 'auto'; $wc_active = function_exists('tix_has_wc') && tix_has_wc(); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Modus</label>
                                                <select name="tix_settings[checkout_mode]" style="width:100%;max-width:420px;">
                                                    <option value="auto" <?php selected($checkout_mode, 'auto'); ?>>Automatisch (WooCommerce wenn installiert, sonst nativ)</option>
                                                    <option value="woocommerce" <?php selected($checkout_mode, 'woocommerce'); ?>>WooCommerce <?php echo $wc_active ? '(aktiv)' : '(nicht installiert!)'; ?></option>
                                                    <option value="native" <?php selected($checkout_mode, 'native'); ?>>Nativ (ohne WooCommerce)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Zahlungsanbieter ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-money-alt"></span>
                                        <h3>Zahlungsanbieter</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint" style="margin-top:0;">Kostenlose Events funktionieren immer ohne Zahlungsanbieter.</p>
                                            </div>
                                            <?php self::text_row('mollie_api_key', 'Mollie API Key', $s, 'live_... oder test_...'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('mollie_test_mode', 'Mollie Test-Modus', $s); ?>
                                                <p class="tix-settings-hint"><a href="https://my.mollie.com/dashboard/developers/api-keys" target="_blank">Mollie Keys →</a></p>
                                            </div>
                                            <?php self::text_row('paypal_client_id', 'PayPal Client ID', $s, 'A...'); ?>
                                            <?php self::text_row('paypal_secret', 'PayPal Secret', $s, 'E...'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('paypal_sandbox', 'PayPal Sandbox', $s); ?>
                                                <p class="tix-settings-hint"><a href="https://developer.paypal.com/dashboard/applications" target="_blank">PayPal Dashboard →</a></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Banküberweisung ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-bank"></span>
                                        <h3>Banküberweisung (Vorkasse)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('bank_transfer_enabled', 'Banküberweisung aktivieren', $s, 'Kunden können per Vorkasse bezahlen. Tickets werden erst nach Zahlungseingang erstellt (Status: Wartend).'); ?>
                                            </div>
                                            <?php self::text_row('bank_holder', 'Kontoinhaber', $s, 'Max Mustermann GmbH'); ?>
                                            <?php self::text_row('bank_iban', 'IBAN', $s, 'DE89 3704 0044 0532 0130 00'); ?>
                                            <?php self::text_row('bank_bic', 'BIC', $s, 'COBADEFFXXX'); ?>
                                            <?php self::text_row('bank_name', 'Bank', $s, 'Commerzbank'); ?>
                                            <?php self::text_row('bank_reference', 'Verwendungszweck-Hinweis', $s, 'Bestellnummer angeben'); ?>
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

                                <?php // ── Card: HTML-Ticket Design ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-layout"></span>
                                        <h3>HTML-Ticket Design</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Gestalte das HTML-Ticket (Fallback ohne Bild-Template). Diese Farben werden beim direkten Download im Browser angezeigt.</p>
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('ht_header_bg',     'Header-Hintergrund', $s);
                                            self::color_row('ht_header_text',   'Header-Text', $s);
                                            self::color_row('ht_body_bg',       'Body-Hintergrund', $s);
                                            self::color_row('ht_text_color',    'Textfarbe', $s);
                                            self::color_row('ht_label_color',   'Label-Farbe', $s);
                                            self::color_row('ht_border_color',  'Ticket-Rahmen', $s);
                                            self::color_row('ht_footer_color',  'Footer-Text', $s);
                                            self::color_row('ht_divider_color', 'Trennlinie', $s);
                                            self::color_row('ht_btn_bg',        'Drucken-Button', $s);
                                            self::color_row('ht_btn_text',      'Button-Text', $s);
                                            self::range_row('ht_border_radius', 'Eckenradius', $s, 0, 30, 'px');
                                            self::range_row('ht_logo_height', 'Logo-H&ouml;he', $s, 20, 120, 'px');
                                            ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::text_row('ht_footer_text', 'Footer-Nachricht', $s, 'Text unter dem Ticket'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full" style="margin-top:12px;">
                                                <label class="tix-field-label">Ticket-Logo</label>
                                                <div style="display:flex;gap:12px;align-items:center;">
                                                    <input type="text" name="<?php echo $ok; ?>[ht_logo_url]" id="tix-ht-logo-url"
                                                           value="<?php echo esc_attr($s['ht_logo_url'] ?? ''); ?>"
                                                           class="regular-text" placeholder="Logo-URL eingeben oder Bild w&auml;hlen"
                                                           style="flex:1;">
                                                    <button type="button" class="button" id="tix-ht-logo-btn">Bild w&auml;hlen</button>
                                                </div>
                                                <?php if (!empty($s['ht_logo_url'])) : ?>
                                                    <div style="margin-top:8px;" id="tix-ht-logo-preview">
                                                        <img src="<?php echo esc_url($s['ht_logo_url']); ?>" style="max-height:40px;width:auto;background:#222;padding:8px 12px;border-radius:8px;">
                                                        <button type="button" class="button-link" style="color:#ef4444;margin-left:8px;" onclick="document.getElementById('tix-ht-logo-url').value='';document.getElementById('tix-ht-logo-preview').remove();">Entfernen</button>
                                                    </div>
                                                <?php endif; ?>
                                                <p class="tix-field-hint">Logo im Ticket-Header (links neben dem Titel). Empfohlen: transparentes PNG oder wei&szlig;es Logo, max. 200px breit.</p>
                                            </div>
                                        </div>
                                        <?php // ── Live-Vorschau ── ?>
                                        <div style="margin-top:20px;border-top:1px solid #e5e7eb;padding-top:20px;">
                                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                                <h4 style="margin:0;font-size:14px;font-weight:600;color:#374151;">Vorschau</h4>
                                                <button type="button" class="button button-small" id="tix-ht-refresh-preview">Aktualisieren</button>
                                            </div>
                                            <div id="tix-ht-preview" style="transform:scale(.75);transform-origin:top left;margin-bottom:-80px;"></div>
                                        </div>

                                        <script>
                                        jQuery(function($){
                                            $('#tix-ht-logo-btn').on('click',function(e){
                                                e.preventDefault();
                                                var frame = wp.media({title:'Ticket-Logo w\u00e4hlen',multiple:false,library:{type:'image'}});
                                                frame.on('select',function(){
                                                    var url = frame.state().get('selection').first().toJSON().url;
                                                    $('#tix-ht-logo-url').val(url);
                                                    renderHtPreview();
                                                });
                                                frame.open();
                                            });

                                            function v(id) {
                                                var el = document.querySelector('[name="tix_settings[' + id + ']"]');
                                                return el ? el.value : '';
                                            }

                                            function renderHtPreview() {
                                                var hbg = v('ht_header_bg') || '#222222',
                                                    htx = v('ht_header_text') || '#ffffff',
                                                    bbg = v('ht_body_bg') || '#ffffff',
                                                    tc  = v('ht_text_color') || '#1a1a1a',
                                                    lc  = v('ht_label_color') || '#888888',
                                                    bc  = v('ht_border_color') || '#222222',
                                                    fc  = v('ht_footer_color') || '#888888',
                                                    dc  = v('ht_divider_color') || '#cccccc',
                                                    br  = v('ht_border_radius') || '12',
                                                    lh  = v('ht_logo_height') || '44',
                                                    ft  = v('ht_footer_text') || 'Bitte dieses Ticket ausgedruckt oder digital zum Einlass mitbringen.',
                                                    logo = v('ht_logo_url');

                                                var plh = Math.round(parseInt(lh) * 0.75);
                                                var logoHtml = logo ? '<img src="' + logo + '" style="max-height:' + plh + 'px;width:auto;flex-shrink:0;">' : '';

                                                $('#tix-ht-preview').html(
                                                    '<div style="max-width:500px;border:2px solid ' + bc + ';border-radius:' + br + 'px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">' +
                                                        '<div style="background:' + hbg + ';color:' + htx + ';padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;">' +
                                                            logoHtml +
                                                            '<div style="text-align:right;">' +
                                                                '<div style="font-size:16px;font-weight:700;">Beispiel-Event</div>' +
                                                                '<div style="font-size:12px;opacity:.75;">VIP-Ticket \u2014 49,00 \u20ac</div>' +
                                                            '</div>' +
                                                        '</div>' +
                                                        '<div style="background:' + bbg + ';padding:20px;display:flex;gap:16px;">' +
                                                            '<div style="flex:1;">' +
                                                                '<div style="margin-bottom:10px;"><div style="font-size:9px;text-transform:uppercase;letter-spacing:1px;color:' + lc + ';margin-bottom:1px;">Datum</div><div style="font-size:13px;font-weight:600;color:' + tc + ';">Samstag, 15. M\u00e4rz 2026</div></div>' +
                                                                '<div style="margin-bottom:10px;"><div style="font-size:9px;text-transform:uppercase;letter-spacing:1px;color:' + lc + ';margin-bottom:1px;">Einlass / Beginn</div><div style="font-size:13px;font-weight:600;color:' + tc + ';">19:00 / 20:00 Uhr</div></div>' +
                                                                '<div><div style="font-size:9px;text-transform:uppercase;letter-spacing:1px;color:' + lc + ';margin-bottom:1px;">Location</div><div style="font-size:13px;font-weight:600;color:' + tc + ';">Musterhaus Berlin</div></div>' +
                                                            '</div>' +
                                                            '<div style="flex:0 0 100px;text-align:center;">' +
                                                                '<div style="width:100px;height:100px;background:#f3f3f3;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:10px;color:#999;">QR-Code</div>' +
                                                                '<div style="font-family:monospace;font-size:11px;font-weight:bold;margin-top:4px;letter-spacing:1px;color:' + tc + ';">ABC123XYZ</div>' +
                                                            '</div>' +
                                                        '</div>' +
                                                        '<div style="border-top:1px dashed ' + dc + ';padding:10px 20px;font-size:10px;color:' + fc + ';text-align:center;">' + $('<span>').text(ft).html() + '</div>' +
                                                    '</div>'
                                                );
                                            }

                                            // Initial rendern
                                            renderHtPreview();

                                            // Bei Klick auf Aktualisieren
                                            $('#tix-ht-refresh-preview').on('click', function(e) {
                                                e.preventDefault();
                                                renderHtPreview();
                                            });
                                        });
                                        </script>
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

                                <?php // ── Card: Template ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-layout"></span>
                                        <h3>Event-Template</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ep_template_enabled', 'Eigenes Template für Event-Einzelseiten', $s, 'Ersetzt das Breakdance/Theme-Template durch das Tixomat Event-Template. Header/Footer des Themes bleiben erhalten. Alternativ: <code>[tix_event_page]</code> Shortcode in Breakdance einbetten.'); ?>
                                            </div>
                                            <?php self::range_row('ep_hero_height', 'Hero-Bild-Höhe', $s, 150, 600, 'px'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Layout ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-align-wide"></span>
                                        <h3>Layout</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Steuert Layout, Farben und sichtbare Sektionen. Gilt für das eigene Template und den <code>[tix_event_page]</code> Shortcode.</p>
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
                                            <?php self::range_row('ep_pad_x', 'Padding seitlich', $s, 0, 80, 'px'); ?>
                                            <?php self::range_row('ep_pad_y', 'Padding oben/unten', $s, 0, 120, 'px'); ?>
                                            <?php self::range_row('ep_sticky_offset', 'Abstand unter Sticky-Header', $s, 0, 200, 'px'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">Farben, Schriften und Radius → <strong><a href="#" onclick="document.querySelector('[data-tab=event-cards]').click();return false;">Event-Karten</a></strong> Tab.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Design (Verweis auf Event-Karten) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-appearance"></span>
                                        <h3>Farben, Schriften & Radius</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin:0;">
                                            Das Event-Template nutzt die gleichen Design-Settings wie die Event-Karten.
                                            Farben, Schriften, Radius und Abstände werden im Tab
                                            <strong><a href="#" onclick="document.querySelector('[data-tab=event-cards]').click();return false;">Event-Karten</a></strong> eingestellt.
                                        </p>
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

                            <?php // ═══ PANE: EVENT-KARTEN ═══ ?>
                            <div class="tix-pane" data-pane="event-cards">

                                <?php // ── Card: Farben ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-art"></span>
                                        <h3>Farben</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('tix_card_color_signal', 'Signal (Primär-CTA)', $s);
                                            self::color_row('tix_card_color_signal_mid', 'Signal Mittel (Dark-Datum)', $s);
                                            self::color_row('tix_card_color_nacht', 'Nacht (Dunkel)', $s);
                                            self::color_row('tix_card_color_nacht_soft', 'Nacht Soft', $s);
                                            self::color_row('tix_card_color_spotlight', 'Spotlight (Gelb)', $s);
                                            self::color_row('tix_card_color_entdecken', 'Entdecken (Teal)', $s);
                                            self::color_row('tix_card_color_licht', 'Hintergrund Hell', $s);
                                            self::color_row('tix_card_color_sand', 'Sand (Neutral)', $s);
                                            self::color_row('tix_card_color_text', 'Text Dunkel', $s);
                                            self::color_row('tix_card_color_text_muted', 'Text Gedämpft', $s);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Radius & Abstände ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-image-crop"></span>
                                        <h3>Radius & Abstände</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            self::range_row('tix_card_radius_card', 'Karten-Radius', $s, 0, 30, 'px');
                                            self::range_row('tix_card_radius_badge', 'Badge-Radius', $s, 0, 20, 'px');
                                            self::range_row('tix_card_radius_button', 'Button-Radius', $s, 0, 20, 'px');
                                            self::range_row('tix_card_radius_image', 'Bild-Radius', $s, 0, 24, 'px');
                                            self::range_row('tix_card_gap_col', 'Spalten-Abstand', $s, 4, 40, 'px');
                                            self::range_row('tix_card_gap_row', 'Zeilen-Abstand', $s, 4, 60, 'px');
                                            self::range_row('tix_card_image_ratio', 'Bild-Höhe (%)', $s, 40, 80, '%');
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Typografie ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-editor-textcolor"></span>
                                        <h3>Typografie</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            $fonts = ['Sora', 'DM Sans', 'Inter', 'Outfit', 'Poppins', 'Montserrat', 'Open Sans', 'Roboto', 'Lato', 'Nunito', 'Raleway', 'Playfair Display', 'Oswald', 'Source Sans 3'];
                                            $display_font = $s['tix_card_font_display'] ?? 'Sora';
                                            $body_font = $s['tix_card_font_body'] ?? 'DM Sans';
                                            ?>
                                            <div class="tix-field">
                                                <label class="tix-label">Display-Schrift (Titel)</label>
                                                <select name="tix_settings[tix_card_font_display]" style="width:100%;">
                                                    <?php foreach ($fonts as $f): ?>
                                                        <option value="<?php echo esc_attr($f); ?>" <?php selected($display_font, $f); ?> style="font-family:'<?php echo esc_attr($f); ?>';"><?php echo esc_html($f); ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="custom" <?php selected(!in_array($display_font, $fonts) && $display_font !== ''); ?>>Eigene Schrift…</option>
                                                </select>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-label">Body-Schrift (Text)</label>
                                                <select name="tix_settings[tix_card_font_body]" style="width:100%;">
                                                    <?php foreach ($fonts as $f): ?>
                                                        <option value="<?php echo esc_attr($f); ?>" <?php selected($body_font, $f); ?> style="font-family:'<?php echo esc_attr($f); ?>';"><?php echo esc_html($f); ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="custom" <?php selected(!in_array($body_font, $fonts) && $body_font !== ''); ?>>Eigene Schrift…</option>
                                                </select>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">Schriftgr&ouml;&szlig;en: &uuml;ber Typografie-Tab steuerbar (Event Cards &amp; Suche)</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('tix_card_font_self_host', 'Schriften selbst hosten (DSGVO)', $s, 'Keine Verbindung zu Google Fonts. Du musst die Schriften dann selbst in dein Theme einbinden (z.B. per @font-face).'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Layout & Optionen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-grid-view"></span>
                                        <h3>Layout & Optionen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label" style="margin-bottom:8px;">Spalten pro Breakpoint</label>
                                                <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">
                                                    <?php
                                                    $breakpoints = [
                                                        ['key' => 'tix_card_columns_default', 'label' => 'Desktop', 'desc' => 'Alle', 'icon' => '🖥'],
                                                        ['key' => 'tix_card_cols_tablet_l',  'label' => 'Tablet L', 'desc' => '≤1119px', 'icon' => '📱'],
                                                        ['key' => 'tix_card_cols_tablet_p',  'label' => 'Tablet P', 'desc' => '≤1023px', 'icon' => '📱'],
                                                        ['key' => 'tix_card_cols_phone_l',   'label' => 'Phone L',  'desc' => '≤767px', 'icon' => '📱'],
                                                        ['key' => 'tix_card_cols_phone_p',   'label' => 'Phone P',  'desc' => '≤479px', 'icon' => '📱'],
                                                    ];
                                                    foreach ($breakpoints as $bp):
                                                        $val = intval($s[$bp['key']] ?? 4);
                                                    ?>
                                                        <div style="text-align:center;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 6px;">
                                                            <div style="font-size:11px;font-weight:600;color:#374151;"><?php echo $bp['label']; ?></div>
                                                            <div style="font-size:10px;color:#9ca3af;margin-bottom:6px;"><?php echo $bp['desc']; ?></div>
                                                            <select name="tix_settings[<?php echo $bp['key']; ?>]" style="width:100%;font-size:13px;padding:4px;border-radius:4px;border:1px solid #d1d5db;text-align:center;">
                                                                <?php for ($c = 1; $c <= 5; $c++): ?>
                                                                    <option value="<?php echo $c; ?>" <?php selected($val, $c); ?>><?php echo $c; ?></option>
                                                                <?php endfor; ?>
                                                            </select>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-label">Standard-Modus</label>
                                                <select name="tix_settings[tix_card_default_mode]" style="width:100%;">
                                                    <option value="light" <?php selected($s['tix_card_default_mode'] ?? 'light', 'light'); ?>>Hell</option>
                                                    <option value="dark" <?php selected($s['tix_card_default_mode'] ?? 'light', 'dark'); ?>>Dunkel</option>
                                                </select>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('tix_card_show_heart', 'Herz-Button (Favorisieren) anzeigen', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('tix_card_show_badges', 'Status-Badges anzeigen (Empfehlung, Heute, Letzte X, etc.)', $s); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Karten-Seite ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-page"></span>
                                        <h3>Automatische Event-Seite</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('ec_page_enabled', 'Automatische /events/ Seite erstellen', $s, 'Erstellt eine Archivseite unter /events/ die alle Events als Karten anzeigt. Alternativ: Shortcode [tix_events] manuell einbetten.'); ?>
                                            </div>
                                            <?php self::range_row('ec_pad_x', 'Padding seitlich', $s, 0, 80, 'px'); ?>
                                            <?php self::range_row('ec_pad_y', 'Padding oben/unten', $s, 0, 120, 'px'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Shortcode Generator ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-shortcode"></span>
                                        <h3>Shortcode Generator</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin:0 0 16px;">Konfiguriere deinen Shortcode und kopiere ihn.</p>
                                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                                            <div>
                                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Shortcode-Typ</label>
                                                <select id="tix-sc-type" style="width:100%;padding:6px;border-radius:6px;border:1px solid #d1d5db;">
                                                    <option value="tix_events">Event-Grid</option>
                                                    <option value="tix_search">Suchfeld</option>
                                                </select>
                                            </div>
                                            <div class="tix-sc-opt" data-for="tix_events">
                                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Anzahl</label>
                                                <input type="number" id="tix-sc-limit" value="8" min="1" max="50" style="width:100%;padding:6px;border-radius:6px;border:1px solid #d1d5db;">
                                            </div>
                                            <div class="tix-sc-opt" data-for="tix_events">
                                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Spalten</label>
                                                <select id="tix-sc-columns" style="width:100%;padding:6px;border-radius:6px;border:1px solid #d1d5db;">
                                                    <option value="2">2</option>
                                                    <option value="3">3</option>
                                                    <option value="4" selected>4</option>
                                                </select>
                                            </div>
                                            <div class="tix-sc-opt" data-for="tix_events">
                                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Kategorie</label>
                                                <select id="tix-sc-category" style="width:100%;padding:6px;border-radius:6px;border:1px solid #d1d5db;">
                                                    <option value="">Alle</option>
                                                    <?php
                                                    $cats = get_terms(['taxonomy' => 'event_category', 'hide_empty' => false]);
                                                    if (!is_wp_error($cats)):
                                                        foreach ($cats as $cat): ?>
                                                            <option value="<?php echo esc_attr($cat->slug); ?>"><?php echo esc_html($cat->name); ?></option>
                                                        <?php endforeach;
                                                    endif; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Modus</label>
                                                <select id="tix-sc-mode" style="width:100%;padding:6px;border-radius:6px;border:1px solid #d1d5db;">
                                                    <option value="light">Hell</option>
                                                    <option value="dark">Dunkel</option>
                                                </select>
                                            </div>
                                            <div class="tix-sc-opt" data-for="tix_events">
                                                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Optionen</label>
                                                <div style="display:flex;flex-direction:column;gap:4px;">
                                                    <label style="font-size:12px;display:flex;align-items:center;gap:4px;"><input type="checkbox" id="tix-sc-featured"> Nur Empfehlungen</label>
                                                    <label style="font-size:12px;display:flex;align-items:center;gap:4px;"><input type="checkbox" id="tix-sc-filter"> Filter anzeigen</label>
                                                    <label style="font-size:12px;display:flex;align-items:center;gap:4px;"><input type="checkbox" id="tix-sc-header"> Header anzeigen</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="margin-top:16px;display:flex;gap:8px;align-items:center;">
                                            <input type="text" id="tix-sc-output" value='[tix_events]' readonly style="flex:1;padding:10px 14px;font-family:monospace;font-size:14px;border:2px solid var(--tix-primary, #FF5500);border-radius:8px;background:#f8fafc;cursor:pointer;" onclick="this.select();document.execCommand('copy');">
                                            <button type="button" class="button" onclick="document.getElementById('tix-sc-output').select();document.execCommand('copy');this.textContent='Kopiert!';setTimeout(function(){document.querySelector('#tix-sc-output+button').textContent='Kopieren';},1500);">Kopieren</button>
                                        </div>
                                        <script>
                                        (function(){
                                            function updateSC(){
                                                var type = document.getElementById('tix-sc-type').value;
                                                var parts = [type];
                                                // Show/hide options
                                                document.querySelectorAll('.tix-sc-opt').forEach(function(el){
                                                    el.style.display = el.dataset.for === type ? '' : 'none';
                                                });
                                                if(type==='tix_events'){
                                                    var limit=document.getElementById('tix-sc-limit').value;
                                                    if(limit!=='8') parts.push('limit="'+limit+'"');
                                                    var cols=document.getElementById('tix-sc-columns').value;
                                                    if(cols!=='4') parts.push('columns="'+cols+'"');
                                                    var cat=document.getElementById('tix-sc-category').value;
                                                    if(cat) parts.push('category="'+cat+'"');
                                                    var mode=document.getElementById('tix-sc-mode').value;
                                                    if(mode!=='light') parts.push('mode="'+mode+'"');
                                                    if(document.getElementById('tix-sc-featured').checked) parts.push('featured="1"');
                                                    if(document.getElementById('tix-sc-filter').checked) parts.push('show_filter="1"');
                                                    if(document.getElementById('tix-sc-header').checked) parts.push('show_header="1"');
                                                } else {
                                                    var mode=document.getElementById('tix-sc-mode').value;
                                                    if(mode!=='light') parts.push('mode="'+mode+'"');
                                                }
                                                document.getElementById('tix-sc-output').value='['+parts.join(' ')+']';
                                            }
                                            document.querySelectorAll('#tix-sc-type,#tix-sc-limit,#tix-sc-columns,#tix-sc-category,#tix-sc-mode,#tix-sc-featured,#tix-sc-filter,#tix-sc-header').forEach(function(el){
                                                el.addEventListener('change',updateSC);
                                                el.addEventListener('input',updateSC);
                                            });
                                        })();
                                        </script>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: TYPOGRAFIE ═══ ?>
                            <div class="tix-pane" data-pane="typography">

                                <?php // ── Card: Globale Schriftarten ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-editor-textcolor"></span>
                                        <h3>Globale Schriftarten</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php
                                            $fonts = ['Sora', 'DM Sans', 'Inter', 'Outfit', 'Poppins', 'Montserrat', 'Open Sans', 'Roboto', 'Lato', 'Nunito', 'Raleway', 'Playfair Display', 'Oswald', 'Source Sans 3', 'Work Sans', 'Manrope', 'Plus Jakarta Sans', 'Figtree'];
                                            $hFont = $s['tix_typo_font_heading'] ?? 'Sora';
                                            $bFont = $s['tix_typo_font_body'] ?? 'DM Sans';
                                            ?>
                                            <div class="tix-field">
                                                <label class="tix-field-label">Überschriften-Font</label>
                                                <select name="tix_settings[tix_typo_font_heading]" style="width:100%;">
                                                    <?php foreach ($fonts as $f): ?>
                                                        <option value="<?php echo esc_attr($f); ?>" <?php selected($hFont, $f); ?>><?php echo esc_html($f); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="tix-field">
                                                <label class="tix-field-label">Text-Font</label>
                                                <select name="tix_settings[tix_typo_font_body]" style="width:100%;">
                                                    <?php foreach ($fonts as $f): ?>
                                                        <option value="<?php echo esc_attr($f); ?>" <?php selected($bFont, $f); ?>><?php echo esc_html($f); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <p class="tix-field-hint" style="margin-top:8px;">Standard-Fonts für Heading- und Body-Klassen. Einzelne Klassen können unten individuell überschrieben werden.</p>

                                        <?php if (defined('__BREAKDANCE_VERSION')): ?>
                                        <div style="margin-top:16px;padding:14px 18px;background:#f0f9ff;border-radius:10px;border:1px solid #bae6fd;">
                                            <?php self::checkbox_row('tix_typo_breakdance_sync', 'Von Breakdance Global Styles übernehmen', $s, 'Liest Schriftarten und Basisgröße aus Breakdance.'); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php // ── Per-Class Typography Accordions ── ?>
                                <?php
                                $typo_registry = self::typo_class_registry();
                                $typo_overrides = $s['tix_typo_classes'] ?? [];
                                $font_options = array_merge(['heading', 'body'], $fonts);
                                $weight_options = [100, 200, 300, 400, 500, 600, 700, 800, 900];
                                ?>

                                <style>
                                .tix-typo-accordion { border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; margin-bottom: 8px; background: #fff; }
                                .tix-typo-accordion-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; cursor: pointer; user-select: none; background: #fafbfc; border-bottom: 1px solid transparent; transition: background .15s; }
                                .tix-typo-accordion-header:hover { background: #f3f4f6; }
                                .tix-typo-accordion-header.open { border-bottom-color: #e5e7eb; }
                                .tix-typo-accordion-title { font-size: 13px; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 8px; }
                                .tix-typo-accordion-count { font-size: 11px; font-weight: 500; color: #9ca3af; background: #f3f4f6; padding: 2px 8px; border-radius: 10px; }
                                .tix-typo-accordion-modified { font-size: 11px; font-weight: 600; color: #f59e0b; background: #fffbeb; padding: 2px 8px; border-radius: 10px; }
                                .tix-typo-accordion-chevron { transition: transform .2s; color: #9ca3af; }
                                .tix-typo-accordion-header.open .tix-typo-accordion-chevron { transform: rotate(180deg); }
                                .tix-typo-accordion-body { display: none; padding: 0; overflow-x: auto; }
                                .tix-typo-accordion-header.open + .tix-typo-accordion-body { display: block; }
                                .tix-typo-row { display: grid; grid-template-columns: 170px repeat(5, 52px) 140px 76px 32px; gap: 6px; align-items: center; padding: 8px 18px; border-bottom: 1px solid #f3f4f6; font-size: 12px; min-width: 750px; }
                                .tix-typo-row:last-child { border-bottom: none; }
                                .tix-typo-row-head { background: #f9fafb; font-weight: 600; color: #6b7280; padding: 6px 18px; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #e5e7eb; }
                                .tix-typo-row-head div { text-align: center; line-height: 1.2; }
                                .tix-typo-row-head div:first-child { text-align: left; }
                                .tix-typo-label { display: flex; flex-direction: column; gap: 1px; }
                                .tix-typo-label-name { font-weight: 500; color: #374151; }
                                .tix-typo-label-cls { font-size: 10px; color: #9ca3af; font-family: monospace; }
                                .tix-typo-row input[type="number"] { width: 100%; padding: 4px 2px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; text-align: center; background: #fff; -moz-appearance: textfield; }
                                .tix-typo-row input[type="number"]::-webkit-inner-spin-button,
                                .tix-typo-row input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
                                .tix-typo-row input[type="number"]::placeholder { color: #c4c9d0; font-size: 11px; }
                                .tix-typo-row select { width: 100%; padding: 5px 4px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 11px; background: #fff; }
                                .tix-typo-row input:focus, .tix-typo-row select:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,.15); }
                                .tix-typo-row input.tix-typo-changed, .tix-typo-row select.tix-typo-changed { border-color: #f59e0b; background: #fffbeb; }
                                .tix-typo-reset { width: 28px; height: 28px; border: none; background: none; cursor: pointer; color: #d1d5db; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: color .15s, background .15s; padding: 0; }
                                .tix-typo-reset:hover { color: #ef4444; background: #fef2f2; }
                                .tix-typo-reset.has-changes { color: #f59e0b; }
                                @media (max-width: 900px) {
                                    .tix-typo-row { grid-template-columns: 1fr; gap: 4px; padding: 10px 14px; min-width: 0; }
                                    .tix-typo-row-head { display: none; }
                                }
                                </style>

                                <?php foreach ($typo_registry as $group_label => $group_classes): ?>
                                <?php
                                    $mod_count = 0;
                                    foreach ($group_classes as $cls => $def) {
                                        if (!empty($typo_overrides[$cls])) $mod_count++;
                                    }
                                ?>
                                <div class="tix-typo-accordion">
                                    <div class="tix-typo-accordion-header" onclick="this.classList.toggle('open');">
                                        <div class="tix-typo-accordion-title">
                                            <?php echo esc_html($group_label); ?>
                                            <span class="tix-typo-accordion-count"><?php echo count($group_classes); ?></span>
                                            <?php if ($mod_count > 0): ?>
                                                <span class="tix-typo-accordion-modified"><?php echo $mod_count; ?> geändert</span>
                                            <?php endif; ?>
                                        </div>
                                        <svg class="tix-typo-accordion-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </div>
                                    <div class="tix-typo-accordion-body">
                                        <div class="tix-typo-row tix-typo-row-head">
                                            <div style="text-align:left;">Element</div>
                                            <div title="Desktop / Alle">🖥</div>
                                            <div title="Tablet Landscape ≤1119px">TL</div>
                                            <div title="Tablet Portrait ≤1023px">TP</div>
                                            <div title="Phone Landscape ≤767px">PL</div>
                                            <div title="Phone Portrait ≤479px">PP</div>
                                            <div>Font</div>
                                            <div>Gewicht</div>
                                            <div></div>
                                        </div>
                                        <?php
                                        $bp_keys = ['size', 'size_tl', 'size_tp', 'size_pl', 'size_pp'];
                                        foreach ($group_classes as $cls => $def):
                                            $ov = $typo_overrides[$cls] ?? [];
                                            $cur_size   = $ov['size']   ?? $def['size'];
                                            $cur_font   = $ov['font']   ?? $def['font'];
                                            $cur_weight = $ov['weight'] ?? $def['weight'];
                                            $has_changes = !empty($ov);
                                        ?>
                                        <div class="tix-typo-row" data-cls="<?php echo esc_attr($cls); ?>" data-def-size="<?php echo esc_attr($def['size']); ?>" data-def-font="<?php echo esc_attr($def['font']); ?>" data-def-weight="<?php echo esc_attr($def['weight']); ?>">
                                            <div class="tix-typo-label">
                                                <span class="tix-typo-label-name"><?php echo esc_html($def['label']); ?></span>
                                                <span class="tix-typo-label-cls">.<?php echo esc_html($cls); ?></span>
                                            </div>
                                            <?php /* Desktop Size — immer Wert anzeigen */ ?>
                                            <input type="number" data-prop="size" value="<?php echo esc_attr($cur_size); ?>" min="8" max="60" step="1" class="<?php echo ($ov['size'] ?? null) !== null ? 'tix-typo-changed' : ''; ?>" title="Desktop">
                                            <?php /* Responsive Breakpoint Sizes — leer = vererbt */ ?>
                                            <?php foreach (['size_tl', 'size_tp', 'size_pl', 'size_pp'] as $bp_key):
                                                $bp_val = $ov[$bp_key] ?? '';
                                                $bp_labels = ['size_tl' => 'Tablet L ≤1119', 'size_tp' => 'Tablet P ≤1023', 'size_pl' => 'Phone L ≤767', 'size_pp' => 'Phone P ≤479'];
                                            ?>
                                            <input type="number" data-prop="<?php echo $bp_key; ?>" value="<?php echo esc_attr($bp_val); ?>" min="8" max="60" step="1" placeholder="" class="<?php echo $bp_val !== '' ? 'tix-typo-changed' : ''; ?>" title="<?php echo $bp_labels[$bp_key]; ?>">
                                            <?php endforeach; ?>
                                            <select data-prop="font" class="<?php echo ($ov['font'] ?? null) !== null ? 'tix-typo-changed' : ''; ?>">
                                                <option value="heading" <?php selected($cur_font, 'heading'); ?>>Standard (Heading)</option>
                                                <option value="body" <?php selected($cur_font, 'body'); ?>>Standard (Body)</option>
                                                <?php foreach ($fonts as $f): ?>
                                                    <option value="<?php echo esc_attr($f); ?>" <?php selected($cur_font, $f); ?>><?php echo esc_html($f); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <select data-prop="weight" class="<?php echo ($ov['weight'] ?? null) !== null ? 'tix-typo-changed' : ''; ?>">
                                                <?php foreach ($weight_options as $w): ?>
                                                    <option value="<?php echo $w; ?>" <?php selected($cur_weight, $w); ?>><?php echo $w; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="tix-typo-reset <?php echo $has_changes ? 'has-changes' : ''; ?>" title="Auf Standard zurücksetzen" onclick="tixTypoReset(this);">
                                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2 2v5h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.05 10A6 6 0 1 0 4.18 4.18L2 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <input type="hidden" name="tix_settings[tix_typo_classes_json]" id="tix-typo-json" value="">

                                <script>
                                var bpProps = ['size', 'size_tl', 'size_tp', 'size_pl', 'size_pp'];

                                /* Placeholder-Kaskade: Jeder leere BP erbt vom nächst-größeren */
                                function tixTypoUpdatePlaceholders(row) {
                                    var inputs = bpProps.map(function(p) { return row.querySelector('input[data-prop="' + p + '"]'); });
                                    var inherited = row.dataset.defSize;
                                    for (var i = 0; i < inputs.length; i++) {
                                        if (i === 0) {
                                            // Desktop hat immer einen Wert
                                            inherited = inputs[0].value || row.dataset.defSize;
                                        } else {
                                            inputs[i].placeholder = inherited;
                                            if (inputs[i].value !== '') inherited = inputs[i].value;
                                        }
                                    }
                                }

                                function tixTypoReset(btn) {
                                    var row = btn.closest('.tix-typo-row');
                                    var sizeInput = row.querySelector('input[data-prop="size"]');
                                    sizeInput.value = row.dataset.defSize;
                                    sizeInput.classList.remove('tix-typo-changed');
                                    // Responsive BPs leeren
                                    ['size_tl', 'size_tp', 'size_pl', 'size_pp'].forEach(function(p) {
                                        var inp = row.querySelector('input[data-prop="' + p + '"]');
                                        if (inp) { inp.value = ''; inp.classList.remove('tix-typo-changed'); }
                                    });
                                    var fontSelect = row.querySelectorAll('select')[0];
                                    var weightSelect = row.querySelectorAll('select')[1];
                                    fontSelect.value = row.dataset.defFont;
                                    weightSelect.value = row.dataset.defWeight;
                                    fontSelect.classList.remove('tix-typo-changed');
                                    weightSelect.classList.remove('tix-typo-changed');
                                    btn.classList.remove('has-changes');
                                    tixTypoUpdatePlaceholders(row);
                                }

                                document.querySelectorAll('.tix-typo-row:not(.tix-typo-row-head)').forEach(function(row) {
                                    // Initial: Placeholders setzen
                                    tixTypoUpdatePlaceholders(row);
                                    row.querySelectorAll('input, select').forEach(function(el) {
                                        el.addEventListener('change', function() {
                                            var r = this.closest('.tix-typo-row');
                                            var sizeInput = r.querySelector('input[data-prop="size"]');
                                            var fontSelect = r.querySelectorAll('select')[0];
                                            var weightSelect = r.querySelectorAll('select')[1];
                                            // Desktop size changed?
                                            sizeInput.classList.toggle('tix-typo-changed', sizeInput.value != r.dataset.defSize);
                                            fontSelect.classList.toggle('tix-typo-changed', fontSelect.value != r.dataset.defFont);
                                            weightSelect.classList.toggle('tix-typo-changed', weightSelect.value != r.dataset.defWeight);
                                            // Responsive BP inputs changed?
                                            var anyBpChanged = false;
                                            ['size_tl', 'size_tp', 'size_pl', 'size_pp'].forEach(function(p) {
                                                var inp = r.querySelector('input[data-prop="' + p + '"]');
                                                if (inp) {
                                                    var c = inp.value !== '';
                                                    inp.classList.toggle('tix-typo-changed', c);
                                                    if (c) anyBpChanged = true;
                                                }
                                            });
                                            var changed = sizeInput.value != r.dataset.defSize || fontSelect.value != r.dataset.defFont || weightSelect.value != r.dataset.defWeight || anyBpChanged;
                                            r.querySelector('.tix-typo-reset').classList.toggle('has-changes', changed);
                                            tixTypoUpdatePlaceholders(r);
                                        });
                                    });
                                });

                                /* Vor dem Absenden: Alle Typo-Overrides als 1 JSON-String senden (umgeht max_input_vars) */
                                var tixForm = document.getElementById('tix-settings-form');
                                if (tixForm) {
                                    tixForm.addEventListener('submit', function() {
                                        var overrides = {};
                                        document.querySelectorAll('.tix-typo-row:not(.tix-typo-row-head)').forEach(function(row) {
                                            var cls = row.dataset.cls;
                                            var sizeInput = row.querySelector('input[data-prop="size"]');
                                            var fontSelect = row.querySelectorAll('select')[0];
                                            var weightSelect = row.querySelectorAll('select')[1];
                                            var entry = {};
                                            // Desktop size
                                            if (sizeInput.value != row.dataset.defSize) entry.size = parseInt(sizeInput.value, 10);
                                            // Responsive breakpoint sizes
                                            ['size_tl', 'size_tp', 'size_pl', 'size_pp'].forEach(function(p) {
                                                var inp = row.querySelector('input[data-prop="' + p + '"]');
                                                if (inp && inp.value !== '') entry[p] = parseInt(inp.value, 10);
                                            });
                                            // Font + Weight
                                            if (fontSelect.value != row.dataset.defFont) entry.font = fontSelect.value;
                                            if (weightSelect.value != row.dataset.defWeight) entry.weight = parseInt(weightSelect.value, 10);
                                            if (Object.keys(entry).length > 0) overrides[cls] = entry;
                                        });
                                        document.getElementById('tix-typo-json').value = JSON.stringify(overrides);
                                    });
                                }
                                </script>

                            </div>

                            <?php // ═══ PANE: COLORS (Per-Class) ═══ ?>
                            <div class="tix-pane" data-pane="colors">

                                <?php
                                $color_registry  = self::color_class_registry();
                                $color_overrides = $s['tix_color_classes'] ?? [];
                                $prop_labels = ['color' => 'Text', 'bg' => 'Hintergrund', 'border' => 'Rahmen'];
                                ?>

                                <style>
                                .tix-clr-accordion { border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; margin-bottom: 8px; background: #fff; }
                                .tix-clr-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; cursor: pointer; user-select: none; background: #fafbfc; border-bottom: 1px solid transparent; transition: background .15s; }
                                .tix-clr-header:hover { background: #f3f4f6; }
                                .tix-clr-header.open { border-bottom-color: #e5e7eb; }
                                .tix-clr-title { font-size: 13px; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 8px; }
                                .tix-clr-count { font-size: 11px; font-weight: 500; color: #9ca3af; background: #f3f4f6; padding: 2px 8px; border-radius: 10px; }
                                .tix-clr-modified { font-size: 11px; font-weight: 600; color: #f59e0b; background: #fffbeb; padding: 2px 8px; border-radius: 10px; }
                                .tix-clr-chevron { transition: transform .2s; color: #9ca3af; }
                                .tix-clr-header.open .tix-clr-chevron { transform: rotate(180deg); }
                                .tix-clr-body { display: none; padding: 0; }
                                .tix-clr-header.open + .tix-clr-body { display: block; }
                                .tix-clr-row { display: grid; grid-template-columns: 170px repeat(3, 120px) 32px; gap: 6px; align-items: center; padding: 8px 18px; border-bottom: 1px solid #f3f4f6; font-size: 12px; }
                                .tix-clr-row:last-child { border-bottom: none; }
                                .tix-clr-row-head { background: #f9fafb; font-weight: 600; color: #6b7280; padding: 6px 18px; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #e5e7eb; }
                                .tix-clr-label { display: flex; flex-direction: column; gap: 1px; }
                                .tix-clr-label-name { font-weight: 500; color: #374151; }
                                .tix-clr-label-cls { font-size: 10px; color: #9ca3af; font-family: monospace; }
                                .tix-clr-cell { display: flex; align-items: center; gap: 4px; }
                                .tix-clr-cell.tix-clr-empty { visibility: hidden; }
                                .tix-clr-swatch { width: 26px; height: 26px; border-radius: 6px; border: 1px solid #d1d5db; cursor: pointer; flex-shrink: 0; padding: 0; position: relative; }
                                .tix-clr-swatch input[type="color"] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; border: none; padding: 0; }
                                .tix-clr-hex { width: 72px; padding: 4px 6px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 11px; font-family: monospace; text-align: center; background: #fff; }
                                .tix-clr-hex:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,.15); }
                                .tix-clr-hex.tix-clr-changed { border-color: #f59e0b; background: #fffbeb; }
                                .tix-clr-reset { width: 28px; height: 28px; border: none; background: none; cursor: pointer; color: #d1d5db; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: color .15s, background .15s; padding: 0; }
                                .tix-clr-reset:hover { color: #ef4444; background: #fef2f2; }
                                .tix-clr-reset.has-changes { color: #f59e0b; }
                                @media (max-width: 900px) {
                                    .tix-clr-row { grid-template-columns: 1fr; gap: 4px; padding: 10px 14px; }
                                    .tix-clr-row-head { display: none; }
                                    .tix-clr-cell.tix-clr-empty { display: none; }
                                }
                                </style>

                                <?php foreach ($color_registry as $group_label => $group_classes): ?>
                                <?php
                                    $col_mod_count = 0;
                                    foreach ($group_classes as $cls => $def) {
                                        if (!empty($color_overrides[$cls])) $col_mod_count++;
                                    }
                                ?>
                                <div class="tix-clr-accordion">
                                    <div class="tix-clr-header" onclick="this.classList.toggle('open');">
                                        <div class="tix-clr-title">
                                            <?php echo esc_html($group_label); ?>
                                            <span class="tix-clr-count"><?php echo count($group_classes); ?></span>
                                            <?php if ($col_mod_count > 0): ?>
                                                <span class="tix-clr-modified"><?php echo $col_mod_count; ?> geändert</span>
                                            <?php endif; ?>
                                        </div>
                                        <svg class="tix-clr-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </div>
                                    <div class="tix-clr-body">
                                        <div class="tix-clr-row tix-clr-row-head">
                                            <div>Element</div>
                                            <div>Text</div>
                                            <div>Hintergrund</div>
                                            <div>Rahmen</div>
                                            <div></div>
                                        </div>
                                        <?php foreach ($group_classes as $cls => $def):
                                            $ov = $color_overrides[$cls] ?? [];
                                            $has_changes = !empty($ov);
                                        ?>
                                        <div class="tix-clr-row" data-cls="<?php echo esc_attr($cls); ?>"<?php
                                            foreach ($def['props'] as $pk => $pv) {
                                                echo ' data-def-' . $pk . '="' . esc_attr($pv) . '"';
                                            }
                                        ?>>
                                            <div class="tix-clr-label">
                                                <span class="tix-clr-label-name"><?php echo esc_html($def['label']); ?></span>
                                                <span class="tix-clr-label-cls">.<?php echo esc_html($cls); ?></span>
                                            </div>
                                            <?php foreach (['color', 'bg', 'border'] as $prop):
                                                $has_prop = isset($def['props'][$prop]);
                                                $def_val  = $def['props'][$prop] ?? '';
                                                $cur_val  = $ov[$prop] ?? $def_val;
                                                $is_changed = isset($ov[$prop]);
                                            ?>
                                            <div class="tix-clr-cell <?php echo !$has_prop ? 'tix-clr-empty' : ''; ?>">
                                                <?php if ($has_prop): ?>
                                                <div class="tix-clr-swatch" style="background:<?php echo esc_attr($cur_val); ?>;">
                                                    <input type="color" data-prop="<?php echo $prop; ?>" data-role="picker" value="<?php echo esc_attr($cur_val); ?>" tabindex="-1">
                                                </div>
                                                <input type="text" data-prop="<?php echo $prop; ?>" data-role="hex" value="<?php echo esc_attr($cur_val); ?>" class="tix-clr-hex <?php echo $is_changed ? 'tix-clr-changed' : ''; ?>" maxlength="9" spellcheck="false">
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                            <button type="button" class="tix-clr-reset <?php echo $has_changes ? 'has-changes' : ''; ?>" title="Auf Standard zurücksetzen" onclick="tixColorReset(this);">
                                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2 2v5h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.05 10A6 6 0 1 0 4.18 4.18L2 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <input type="hidden" name="tix_settings[tix_color_classes_json]" id="tix-color-json" value="">

                                <script>
                                function tixColorReset(btn) {
                                    var row = btn.closest('.tix-clr-row');
                                    ['color', 'bg', 'border'].forEach(function(p) {
                                        var hex = row.querySelector('input[data-role="hex"][data-prop="' + p + '"]');
                                        var picker = row.querySelector('input[data-role="picker"][data-prop="' + p + '"]');
                                        if (!hex) return;
                                        if (p === 'color') var def = row.dataset.defColor || '';
                                        else if (p === 'bg') var def = row.dataset.defBg || '';
                                        else var def = row.dataset.defBorder || '';
                                        hex.value = def;
                                        hex.classList.remove('tix-clr-changed');
                                        if (picker && def.match(/^#[0-9a-fA-F]{6}$/)) picker.value = def;
                                        var swatch = picker ? picker.closest('.tix-clr-swatch') : null;
                                        if (swatch) swatch.style.background = def;
                                    });
                                    btn.classList.remove('has-changes');
                                }

                                // Sync picker ↔ hex
                                document.querySelectorAll('.tix-clr-row:not(.tix-clr-row-head)').forEach(function(row) {
                                    row.querySelectorAll('input[data-role="picker"]').forEach(function(picker) {
                                        picker.addEventListener('input', function() {
                                            var prop = this.dataset.prop;
                                            var hex = row.querySelector('input[data-role="hex"][data-prop="' + prop + '"]');
                                            var swatch = this.closest('.tix-clr-swatch');
                                            if (swatch) swatch.style.background = this.value;
                                            if (hex) {
                                                hex.value = this.value;
                                                var defKey = 'def' + prop.charAt(0).toUpperCase() + prop.slice(1);
                                                hex.classList.toggle('tix-clr-changed', this.value !== (row.dataset[defKey] || ''));
                                                tixColorUpdateResetBtn(row);
                                            }
                                        });
                                    });
                                    row.querySelectorAll('input[data-role="hex"]').forEach(function(hex) {
                                        hex.addEventListener('input', function() {
                                            var prop = this.dataset.prop;
                                            var picker = row.querySelector('input[data-role="picker"][data-prop="' + prop + '"]');
                                            var swatch = row.querySelector('.tix-clr-swatch input[data-prop="' + prop + '"]');
                                            if (swatch) swatch.closest('.tix-clr-swatch').style.background = this.value;
                                            if (picker && this.value.match(/^#[0-9a-fA-F]{6}$/)) picker.value = this.value;
                                            var defKey = 'def' + prop.charAt(0).toUpperCase() + prop.slice(1);
                                            this.classList.toggle('tix-clr-changed', this.value !== (row.dataset[defKey] || ''));
                                            tixColorUpdateResetBtn(row);
                                        });
                                    });
                                });

                                function tixColorUpdateResetBtn(row) {
                                    var anyChanged = false;
                                    row.querySelectorAll('input[data-role="hex"]').forEach(function(hex) {
                                        if (hex.classList.contains('tix-clr-changed')) anyChanged = true;
                                    });
                                    row.querySelector('.tix-clr-reset').classList.toggle('has-changes', anyChanged);
                                }

                                // Submit: sammle alle Overrides als JSON
                                var tixColorForm = document.getElementById('tix-settings-form');
                                if (tixColorForm) {
                                    tixColorForm.addEventListener('submit', function() {
                                        var overrides = {};
                                        document.querySelectorAll('.tix-clr-row:not(.tix-clr-row-head)').forEach(function(row) {
                                            var cls = row.dataset.cls;
                                            if (!cls) return;
                                            var entry = {};
                                            ['color', 'bg', 'border'].forEach(function(p) {
                                                var hex = row.querySelector('input[data-role="hex"][data-prop="' + p + '"]');
                                                if (!hex) return;
                                                var defKey = 'def' + p.charAt(0).toUpperCase() + p.slice(1);
                                                var def = row.dataset[defKey] || '';
                                                if (hex.value !== def && hex.value.trim() !== '') {
                                                    entry[p] = hex.value.trim();
                                                }
                                            });
                                            if (Object.keys(entry).length > 0) overrides[cls] = entry;
                                        });
                                        document.getElementById('tix-color-json').value = JSON.stringify(overrides);
                                    });
                                }
                                </script>

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

                            <?php // ═══ PANE: E-MAIL-TEMPLATE ═══ ?>
                            <div class="tix-pane" data-pane="email-template">

                                <?php // ── Card: E-Mail Design ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-art"></span>
                                        <h3>E-Mail Design</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-settings-hint" style="margin-bottom:12px;">Farben f&uuml;r alle E-Mail-Templates (Best&auml;tigung, Erinnerung, Tickets etc.). Leere Felder &uuml;bernehmen die globale Akzentfarbe.</p>
                                        <div class="tix-field-grid">
                                            <?php
                                            self::color_row('email_header_bg',    'Header-Hintergrund', $s, true);
                                            self::color_row('email_header_text',  'Header-Text / Logo-Fallback', $s, true);
                                            self::color_row('email_body_bg',      'Body-Hintergrund', $s);
                                            self::color_row('email_text_color',   'Textfarbe', $s);
                                            self::color_row('email_muted_color',  'Sekund&auml;rtext', $s);
                                            self::color_row('email_outer_bg',     'Au&szlig;erer Hintergrund', $s);
                                            self::color_row('email_border_color', 'Rahmenfarbe', $s);
                                            self::color_row('email_btn_bg',       'Button-Hintergrund', $s, true);
                                            self::color_row('email_btn_text',     'Button-Text', $s, true);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: E-Mail Einstellungen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-email-alt"></span>
                                        <h3>E-Mail Einstellungen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::text_row('email_logo_url', 'Logo-URL', $s, 'https://example.com/logo.png'); ?>
                                            <?php self::range_row('email_logo_height', 'Logo-H&ouml;he', $s, 20, 120, 'px'); ?>
                                            <?php self::text_row('email_brand_name', 'Firmenname', $s, get_bloginfo('name')); ?>
                                            <?php self::text_row('email_footer_text', 'Footer-Text', $s, 'Du erhältst diese E-Mail, weil du eine Bestellung aufgegeben hast.'); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('email_reminder', 'Erinnerungsmail 24h vor dem Event senden', $s); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('email_followup', 'Nachbefragungsmail 24h nach dem Event senden', $s); ?>
                                            </div>
                                            <?php self::text_row('email_followup_url', 'Feedback-Link', $s, 'https://example.com/feedback'); ?>
                                        </div>

                                        <?php // ── Email-Vorschau ── ?>
                                        <?php
                                        $em_accent      = $s['color_accent']      ?: '#c8ff00';
                                        $em_accent_text = $s['color_accent_text'] ?: '#000000';
                                        $em_radius      = intval($s['radius_general'] ?: 8);
                                        $em_brand       = $s['email_brand_name']  ?: get_bloginfo('name');
                                        $em_logo        = $s['email_logo_url']    ?? '';
                                        $em_logo_h      = intval($s['email_logo_height'] ?? 40);
                                        $em_footer      = $s['email_footer_text'] ?: 'Du erh&auml;ltst diese E-Mail, weil du eine Bestellung aufgegeben hast.';
                                        ?>
                                        <div style="margin-top:20px;border-top:1px solid #e5e7eb;padding-top:20px;">
                                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                                                <h4 style="margin:0;font-size:14px;font-weight:600;color:#374151;">Vorschau</h4>
                                                <button type="button" class="button button-small" id="tix-email-refresh-preview">Aktualisieren</button>
                                            </div>
                                            <div id="tix-email-preview" style="transform:scale(.65);transform-origin:top left;margin-bottom:-140px;"></div>
                                        </div>
                                        <script>
                                        jQuery(function($){
                                            function ev(id) {
                                                var el = document.querySelector('[name="tix_settings[' + id + ']"]');
                                                return el ? el.value : '';
                                            }
                                            function renderEmailPreview() {
                                                var globalAccent = ev('color_accent') || '<?php echo esc_js($em_accent); ?>',
                                                    globalAccentText = ev('color_accent_text') || '<?php echo esc_js($em_accent_text); ?>',
                                                    headerBg   = ev('email_header_bg') || globalAccent,
                                                    headerText = ev('email_header_text') || globalAccentText,
                                                    bodyBg     = ev('email_body_bg') || '#ffffff',
                                                    textColor  = ev('email_text_color') || '#1a1a1a',
                                                    mutedColor = ev('email_muted_color') || '#6b7280',
                                                    outerBg    = ev('email_outer_bg') || '#f3f4f6',
                                                    borderCol  = ev('email_border_color') || '#e5e7eb',
                                                    btnBg      = ev('email_btn_bg') || globalAccent,
                                                    btnText    = ev('email_btn_text') || globalAccentText,
                                                    radius     = ev('radius_general') || '<?php echo $em_radius; ?>',
                                                    brand      = ev('email_brand_name') || '<?php echo esc_js($em_brand); ?>',
                                                    logo       = ev('email_logo_url'),
                                                    logoH      = ev('email_logo_height') || '40',
                                                    footer     = ev('email_footer_text') || '<?php echo esc_js($em_footer); ?>';

                                                var headerContent = logo
                                                    ? '<img src="' + logo + '" alt="Logo" style="max-height:' + logoH + 'px;width:auto;">'
                                                    : '<span style="font-size:18px;font-weight:700;color:' + headerText + ';letter-spacing:.02em;">' + $('<span>').text(brand).html() + '</span>';

                                                $('#tix-email-preview').html(
                                                    '<div style="max-width:500px;background:' + outerBg + ';padding:24px 12px;border-radius:8px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">' +
                                                        '<div style="max-width:460px;margin:0 auto;">' +
                                                            '<div style="background:' + headerBg + ';padding:16px 24px;border-radius:' + radius + 'px ' + radius + 'px 0 0;text-align:center;">' + headerContent + '</div>' +
                                                            '<div style="background:' + bodyBg + ';padding:24px;border-left:1px solid ' + borderCol + ';border-right:1px solid ' + borderCol + ';">' +
                                                                '<h2 style="margin:0 0 4px;font-size:18px;font-weight:700;color:' + textColor + ';">Bestellbest&auml;tigung</h2>' +
                                                                '<p style="margin:0 0 16px;font-size:11px;color:' + mutedColor + ';">Deine Bestellung #KK-000001 wurde erfolgreich aufgegeben.</p>' +
                                                                '<p style="margin:0;font-size:13px;color:' + textColor + ';line-height:1.6;">Vielen Dank f&uuml;r deinen Einkauf! Deine Tickets findest du im Anhang oder du kannst sie &uuml;ber den Button unten herunterladen.</p>' +
                                                                '<div style="margin:20px 0;text-align:center;"><span style="display:inline-block;padding:10px 24px;background:' + btnBg + ';color:' + btnText + ';border-radius:' + radius + 'px;font-weight:600;font-size:13px;">Tickets herunterladen</span></div>' +
                                                            '</div>' +
                                                            '<div style="background:' + bodyBg + ';padding:16px 24px;border:1px solid ' + borderCol + ';border-top:none;border-radius:0 0 ' + radius + 'px ' + radius + 'px;text-align:center;font-size:11px;color:' + mutedColor + ';">' +
                                                                $('<span>').text(footer).html() +
                                                            '</div>' +
                                                        '</div>' +
                                                    '</div>'
                                                );
                                            }
                                            renderEmailPreview();
                                            $('#tix-email-refresh-preview').on('click', function(e){ e.preventDefault(); renderEmailPreview(); });
                                        });
                                        </script>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: META ADS ═══ ?>
                            <div class="tix-pane" data-pane="meta-ads">

                                <?php // ── Card: Meta Pixel ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-visibility"></span>
                                        <h3>Meta Pixel</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('meta_pixel_enabled', 'Meta Pixel aktivieren', $s, 'Automatisches Tracking von PageView, ViewContent, AddToCart, InitiateCheckout und Purchase.'); ?>
                                            </div>
                                            <?php self::text_row('meta_pixel_id', 'Pixel-ID', $s, 'z.B. 1234567890123456'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Consent-Modus</label>
                                                <select name="<?php echo self::OPTION_KEY; ?>[meta_consent_mode]" class="tix-select-input">
                                                    <option value="always" <?php selected($s['meta_consent_mode'] ?? 'always', 'always'); ?>>Pixel immer laden</option>
                                                    <option value="consent_required" <?php selected($s['meta_consent_mode'] ?? '', 'consent_required'); ?>>Nur nach Cookie-Consent laden</option>
                                                </select>
                                                <p class="tix-field-hint">Bei "consent_required" wird der Pixel erst geladen, wenn ein Cookie-Consent erkannt wird.</p>
                                            </div>
                                            <?php self::text_row('meta_consent_cookie', 'Consent-Cookie Name', $s, 'cookie_consent'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Conversions API ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-cloud-saved"></span>
                                        <h3>Conversions API (CAPI)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-field-hint" style="margin-bottom:12px">Server-seitiges Tracking für zuverlässige Conversion-Messung — funktioniert auch bei Ad-Blockern.</p>
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('meta_capi_enabled', 'Conversions API aktivieren', $s, 'Sendet Purchase-Events direkt an Meta — unabhängig vom Browser.'); ?>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Access Token</label>
                                                <input type="password" name="<?php echo self::OPTION_KEY; ?>[meta_access_token]"
                                                       value="<?php echo esc_attr($s['meta_access_token'] ?? ''); ?>"
                                                       class="tix-text-input" placeholder="System User Access Token" autocomplete="off">
                                                <p class="tix-field-hint">Erstelle unter <a href="https://business.facebook.com/events_manager" target="_blank">Meta Events Manager</a> → Einstellungen → Conversions API → Access Token generieren.</p>
                                            </div>
                                            <?php self::text_row('meta_test_event_code', 'Test Event Code (optional)', $s, 'z.B. TEST12345'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Event-Katalog Feed ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-rss"></span>
                                        <h3>Event-Katalog (Dynamic Ads)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('meta_catalog_enabled', 'Event-Katalog Feed aktivieren', $s, 'Stellt eine XML-Feed-URL bereit, die du im Meta Commerce Manager als Produktkatalog hinzufügen kannst.'); ?>
                                            </div>
                                            <?php
                                            $feed_key = $s['meta_feed_key'] ?? '';
                                            if (empty($feed_key)) {
                                                $feed_key = wp_generate_password(32, false);
                                            }
                                            $feed_url = rest_url('tixomat/v1/meta-feed') . '?key=' . $feed_key;
                                            ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Feed-URL</label>
                                                <div style="display:flex;gap:8px;align-items:center">
                                                    <input type="text" value="<?php echo esc_url($feed_url); ?>" class="tix-text-input" readonly id="tix-meta-feed-url" style="flex:1">
                                                    <button type="button" class="tix-btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('tix-meta-feed-url').value).then(()=>{this.textContent='Kopiert!';setTimeout(()=>{this.textContent='Kopieren'},2000)})">Kopieren</button>
                                                </div>
                                                <p class="tix-field-hint">Diese URL im <a href="https://business.facebook.com/commerce" target="_blank">Meta Commerce Manager</a> → Katalog → Datenquelle → Feed-URL einfügen.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Dashboard Link ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-chart-area"></span>
                                        <h3>Meta Ads Dashboard</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p>Performance-Dashboard, Kampagnen-Wizard und UTM-Link-Generator findest du unter:</p>
                                        <a href="<?php echo admin_url('admin.php?page=tix-meta-ads'); ?>" class="button button-primary" style="margin-top:8px">
                                            <span class="dashicons dashicons-facebook-alt" style="margin-top:4px"></span>
                                            Meta Ads Dashboard öffnen
                                        </a>
                                    </div>
                                </div>

                            </div>

                            <?php // ═══ PANE: GEBÜHREN ═══ ?>
                            <div class="tix-pane" data-pane="fees">

                                <?php // ── Card: Plattform-Provision ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-money-alt"></span>
                                        <h3>Plattform-Provision</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-field-hint" style="margin:0 0 18px;color:#6b7280;font-size:13px;">
                                            Dein Verdienst pro verkauftem Ticket. Kann global definiert und pro Veranstalter überschrieben werden.
                                        </p>
                                        <div class="tix-field-grid">

                                            <div class="tix-field">
                                                <label>Fixbetrag pro Ticket</label>
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <input type="number" name="tix_settings[fee_fixed]"
                                                           value="<?php echo esc_attr($s['fee_fixed']); ?>"
                                                           step="0.01" min="0" style="width:100px;" />
                                                    <span style="color:#6b7280;">€</span>
                                                </div>
                                            </div>

                                            <div class="tix-field">
                                                <label>Prozentualer Anteil</label>
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <input type="number" name="tix_settings[fee_percent]"
                                                           value="<?php echo esc_attr($s['fee_percent']); ?>"
                                                           step="0.01" min="0" max="100" style="width:100px;" />
                                                    <span style="color:#6b7280;">%</span>
                                                </div>
                                            </div>

                                            <div class="tix-field">
                                                <label>Wer trägt die Gebühr?</label>
                                                <select name="tix_settings[fee_mode]" style="width:240px;">
                                                    <option value="organizer" <?php selected($s['fee_mode'], 'organizer'); ?>>Veranstalter (unsichtbar für Kunden)</option>
                                                    <option value="customer" <?php selected($s['fee_mode'], 'customer'); ?>>Kunde (wird aufgeschlagen)</option>
                                                </select>
                                            </div>

                                            <div class="tix-field" id="tix-fee-label-wrap" style="<?php echo $s['fee_mode'] === 'customer' ? '' : 'display:none;'; ?>">
                                                <label>Bezeichnung für Kunden</label>
                                                <input type="text" name="tix_settings[fee_label]"
                                                       value="<?php echo esc_attr($s['fee_label']); ?>"
                                                       placeholder="Servicegebühr" style="width:240px;" />
                                                <p class="tix-field-hint" style="color:#9ca3af;font-size:12px;margin:4px 0 0;">
                                                    Wird im Checkout als eigene Zeile angezeigt.
                                                </p>
                                            </div>

                                            <div class="tix-field" id="tix-fee-rounding-wrap" style="<?php echo $s['fee_mode'] === 'customer' ? '' : 'display:none;'; ?>">
                                                <label>Endpreis-Rundung</label>
                                                <select name="tix_settings[fee_rounding]" id="tix-fee-rounding" style="width:240px;">
                                                    <option value="none" <?php selected($s['fee_rounding'], 'none'); ?>>Keine Rundung</option>
                                                    <option value="0.90" <?php selected($s['fee_rounding'], '0.90'); ?>>Auf x,90 € aufrunden</option>
                                                    <option value="0.99" <?php selected($s['fee_rounding'], '0.99'); ?>>Auf x,99 € aufrunden</option>
                                                    <option value="0.50" <?php selected($s['fee_rounding'], '0.50'); ?>>Auf x,50 € aufrunden</option>
                                                    <option value="0.00" <?php selected($s['fee_rounding'], '0.00'); ?>>Auf volle € aufrunden</option>
                                                    <option value="custom" <?php selected($s['fee_rounding'], 'custom'); ?>>Eigener Wert</option>
                                                </select>
                                                <p class="tix-field-hint" style="color:#9ca3af;font-size:12px;margin:4px 0 0;">
                                                    Der Endpreis für den Kunden wird auf den nächsten passenden Betrag aufgerundet. Die Differenz geht an die Plattform.
                                                </p>
                                            </div>

                                            <div class="tix-field" id="tix-fee-rounding-custom-wrap" style="<?php echo ($s['fee_mode'] === 'customer' && $s['fee_rounding'] === 'custom') ? '' : 'display:none;'; ?>">
                                                <label>Eigener Nachkomma-Wert</label>
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <span style="color:#6b7280;">x,</span>
                                                    <input type="number" name="tix_settings[fee_rounding_custom]"
                                                           value="<?php echo esc_attr($s['fee_rounding_custom']); ?>"
                                                           step="0.01" min="0" max="0.99" style="width:80px;"
                                                           placeholder="0.49" />
                                                    <span style="color:#6b7280;">€</span>
                                                </div>
                                            </div>

                                        </div>

                                        <div style="margin-top:20px;padding:14px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:13px;color:#166534;">
                                            <strong>Beispiel:</strong>
                                            <span id="tix-fee-example">
                                            <?php
                                            $ex_fixed = floatval($s['fee_fixed']);
                                            $ex_pct   = floatval($s['fee_percent']);
                                            if ($ex_fixed > 0 && $ex_pct > 0) {
                                                printf('Bei einem Ticket à 50 € = %.2f € + %.1f%% = <strong>%.2f € Provision</strong>', $ex_fixed, $ex_pct, $ex_fixed + 50 * $ex_pct / 100);
                                            } elseif ($ex_fixed > 0) {
                                                printf('%.2f € pro Ticket', $ex_fixed);
                                            } elseif ($ex_pct > 0) {
                                                printf('%.1f%% vom Ticketpreis (bei 50 € = <strong>%.2f €</strong>)', $ex_pct, 50 * $ex_pct / 100);
                                            } else {
                                                echo 'Noch keine Provision konfiguriert.';
                                            }
                                            ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Zahlungsdienstleister-Gebühren ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-credit-card"></span>
                                        <h3>Zahlungsdienstleister-Gebühren</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-field-hint" style="margin:0 0 18px;color:#6b7280;font-size:13px;">
                                            Kosten deines Payment-Providers (z.B. Stripe 1,4% + 0,25 €, Mollie 1,8% + 0,25 €).
                                            Diese Kosten fallen immer an und müssen getragen werden.
                                        </p>
                                        <div class="tix-field-grid">

                                            <div class="tix-field">
                                                <label>Fixbetrag pro Transaktion</label>
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <input type="number" name="tix_settings[gateway_fee_fixed]"
                                                           value="<?php echo esc_attr($s['gateway_fee_fixed']); ?>"
                                                           step="0.01" min="0" style="width:100px;" />
                                                    <span style="color:#6b7280;">€</span>
                                                </div>
                                            </div>

                                            <div class="tix-field">
                                                <label>Prozentualer Anteil</label>
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <input type="number" name="tix_settings[gateway_fee_percent]"
                                                           value="<?php echo esc_attr($s['gateway_fee_percent']); ?>"
                                                           step="0.01" min="0" max="100" style="width:100px;" />
                                                    <span style="color:#6b7280;">%</span>
                                                </div>
                                            </div>

                                            <div class="tix-field">
                                                <label>Wer trägt die Gateway-Gebühr?</label>
                                                <select name="tix_settings[gateway_fee_mode]" style="width:240px;">
                                                    <option value="organizer" <?php selected($s['gateway_fee_mode'], 'organizer'); ?>>Veranstalter (von Auszahlung abgezogen)</option>
                                                    <option value="customer" <?php selected($s['gateway_fee_mode'], 'customer'); ?>>Kunde (wird aufgeschlagen)</option>
                                                </select>
                                            </div>

                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Gebühren-Limits ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-shield"></span>
                                        <h3>Gebühren-Limits &amp; Anzeige</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">

                                            <div class="tix-field">
                                                <label>Max. Gebühr pro Ticket</label>
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <input type="number" name="tix_settings[fee_max_per_ticket]"
                                                           value="<?php echo esc_attr($s['fee_max_per_ticket']); ?>"
                                                           step="0.01" min="0" style="width:100px;" placeholder="0" />
                                                    <span style="color:#6b7280;">€ <small>(0 = unbegrenzt)</small></span>
                                                </div>
                                            </div>

                                            <div class="tix-field">
                                                <label>Max. Gebühr pro Bestellung</label>
                                                <div style="display:flex;align-items:center;gap:6px;">
                                                    <input type="number" name="tix_settings[fee_max_per_order]"
                                                           value="<?php echo esc_attr($s['fee_max_per_order']); ?>"
                                                           step="0.01" min="0" style="width:100px;" placeholder="0" />
                                                    <span style="color:#6b7280;">€ <small>(0 = unbegrenzt)</small></span>
                                                </div>
                                                <p class="tix-field-hint" style="color:#9ca3af;font-size:12px;margin:4px 0 0;">
                                                    Falls gesetzt, überschreibt dies die Maximalgebühr pro Ticket.
                                                </p>
                                            </div>

                                            <div class="tix-field tix-field-full">
                                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                                    <input type="checkbox" name="tix_settings[fee_show_in_selector]" value="1" <?php checked($s['fee_show_in_selector']); ?> />
                                                    <strong>Gebührenhinweis im Ticket-Selektor anzeigen</strong>
                                                </label>
                                                <p class="tix-field-hint" style="color:#9ca3af;font-size:12px;margin:4px 0 0 26px;">
                                                    Zeigt unter dem Ticket-Selektor einen Hinweis wie <em>„zzgl. 2,50 € Servicegebühr"</em> an (nur wenn Kunde Gebühren trägt).
                                                </p>
                                            </div>

                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Veranstalter-Überschreibungen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-groups"></span>
                                        <h3>Veranstalter-Überschreibungen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <p class="tix-field-hint" style="margin:0 0 18px;color:#6b7280;font-size:13px;">
                                            Pro Veranstalter können individuelle Gebühren definiert werden, die die globalen Einstellungen überschreiben.
                                            Bearbeite dazu den jeweiligen Veranstalter unter <strong>Tixomat → Veranstalter</strong>.
                                        </p>
                                        <?php
                                        $organizers = get_posts([
                                            'post_type'      => 'tix_organizer',
                                            'posts_per_page' => -1,
                                            'orderby'        => 'title',
                                            'order'          => 'ASC',
                                        ]);
                                        if (!empty($organizers)) : ?>
                                        <table class="widefat striped" style="max-width:700px;">
                                            <thead>
                                                <tr>
                                                    <th>Veranstalter</th>
                                                    <th>Fixbetrag</th>
                                                    <th>Prozent</th>
                                                    <th>Träger</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($organizers as $org) :
                                                $override = get_post_meta($org->ID, '_tix_fee_override', true);
                                                $o_fixed  = get_post_meta($org->ID, '_tix_fee_fixed', true);
                                                $o_pct    = get_post_meta($org->ID, '_tix_fee_percent', true);
                                                $o_mode   = get_post_meta($org->ID, '_tix_fee_mode', true);
                                            ?>
                                                <tr>
                                                    <td>
                                                        <a href="<?php echo get_edit_post_link($org->ID); ?>">
                                                            <?php echo esc_html($org->post_title); ?>
                                                        </a>
                                                    </td>
                                                    <td><?php echo $override ? number_format_i18n(floatval($o_fixed), 2) . ' €' : '—'; ?></td>
                                                    <td><?php echo $override ? number_format_i18n(floatval($o_pct), 1) . ' %' : '—'; ?></td>
                                                    <td><?php echo $override ? ($o_mode === 'customer' ? 'Kunde' : 'Veranstalter') : '—'; ?></td>
                                                    <td>
                                                        <?php if ($override) : ?>
                                                            <span style="color:#16a34a;font-weight:600;">Individuell</span>
                                                        <?php else : ?>
                                                            <span style="color:#9ca3af;">Global</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <?php else : ?>
                                            <p style="color:#9ca3af;">Noch keine Veranstalter angelegt.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php // ── Card: Rechenbeispiel ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-calculator"></span>
                                        <h3>Rechenbeispiel</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div id="tix-fee-calc" style="font-size:13px;line-height:1.8;color:#374151;">
                                        <?php
                                        $calc_price = 50;
                                        $pf_fixed   = floatval($s['fee_fixed']);
                                        $pf_pct     = floatval($s['fee_percent']);
                                        $gw_fixed   = floatval($s['gateway_fee_fixed']);
                                        $gw_pct     = floatval($s['gateway_fee_percent']);
                                        $pf_fee     = $pf_fixed + ($calc_price * $pf_pct / 100);
                                        $fee_mode   = $s['fee_mode'];
                                        $gw_mode    = $s['gateway_fee_mode'];

                                        // Subtotal nach Plattform-Fee
                                        $charge_base = $calc_price;
                                        if ($fee_mode === 'customer') $charge_base += $pf_fee;

                                        // Gateway-Fee (mit Zirkularitäts-Auflösung)
                                        if ($gw_pct > 0 && $gw_mode === 'customer') {
                                            $total_with_gw = ($charge_base + $gw_fixed) / (1 - $gw_pct / 100);
                                            $gw_fee = $total_with_gw - $charge_base;
                                        } else {
                                            $gw_fee = $gw_fixed + ($charge_base * $gw_pct / 100);
                                        }

                                        $customer_total = $charge_base + ($gw_mode === 'customer' ? $gw_fee : 0);

                                        // Rundung anwenden
                                        $rounding_surplus = 0;
                                        $customer_fee_exists = ($fee_mode === 'customer' || $gw_mode === 'customer');
                                        if ($customer_fee_exists && $s['fee_rounding'] !== 'none' && class_exists('TIX_Fees')) {
                                            $rounded = TIX_Fees::round_up_to_target($customer_total, $s['fee_rounding'], floatval($s['fee_rounding_custom'] ?? 0));
                                            if ($rounded > $customer_total) {
                                                $rounding_surplus = round($rounded - $customer_total, 2);
                                                $customer_total = $rounded;
                                            }
                                        }

                                        $organizer_gets = $calc_price - ($fee_mode === 'organizer' ? $pf_fee : 0) - ($gw_mode === 'organizer' ? $gw_fee : 0);
                                        $platform_gets  = $pf_fee + $rounding_surplus;
                                        ?>
                                        <table style="border-collapse:collapse;width:100%;max-width:500px;">
                                            <tr><td style="padding:4px 12px 4px 0;">Ticket-Preis:</td><td style="padding:4px 0;font-weight:600;"><?php echo number_format_i18n($calc_price, 2); ?> €</td></tr>
                                            <tr><td style="padding:4px 12px 4px 0;">Plattform-Provision:</td><td style="padding:4px 0;"><?php echo number_format_i18n($pf_fee, 2); ?> € <span style="color:#9ca3af;">(<?php echo $fee_mode === 'customer' ? 'Kunde zahlt' : 'Veranstalter zahlt'; ?>)</span></td></tr>
                                            <tr><td style="padding:4px 12px 4px 0;">Gateway-Gebühr:</td><td style="padding:4px 0;"><?php echo number_format_i18n($gw_fee, 2); ?> € <span style="color:#9ca3af;">(<?php echo $gw_mode === 'customer' ? 'Kunde zahlt' : 'Veranstalter zahlt'; ?>)</span></td></tr>
                                            <?php if ($rounding_surplus > 0): ?>
                                            <tr><td style="padding:4px 12px 4px 0;">Rundungs-Aufschlag:</td><td style="padding:4px 0;"><?php echo number_format_i18n($rounding_surplus, 2); ?> € <span style="color:#9ca3af;">(→ Plattform)</span></td></tr>
                                            <?php endif; ?>
                                            <tr style="border-top:1px solid #e5e7eb;"><td style="padding:8px 12px 4px 0;font-weight:700;">Kunde zahlt:</td><td style="padding:8px 0 4px;font-weight:700;color:#0D0B09;"><?php echo number_format_i18n($customer_total, 2); ?> €</td></tr>
                                            <tr><td style="padding:4px 12px 4px 0;font-weight:700;">Veranstalter erhält:</td><td style="padding:4px 0;font-weight:700;color:#16a34a;"><?php echo number_format_i18n($organizer_gets, 2); ?> €</td></tr>
                                            <tr><td style="padding:4px 12px 4px 0;font-weight:700;">Plattform-Provision:</td><td style="padding:4px 0;font-weight:700;color:#FF5500;"><?php echo number_format_i18n($platform_gets, 2); ?> €</td></tr>
                                        </table>
                                        </div>
                                    </div>
                                </div>

                                <script>
                                (function(){
                                    var feeMode = document.querySelector('[name="tix_settings[fee_mode]"]');
                                    var labelWrap = document.getElementById('tix-fee-label-wrap');
                                    var roundWrap = document.getElementById('tix-fee-rounding-wrap');
                                    var roundSel  = document.getElementById('tix-fee-rounding');
                                    var customWrap = document.getElementById('tix-fee-rounding-custom-wrap');

                                    function updateVisibility() {
                                        var isCustomer = feeMode && feeMode.value === 'customer';
                                        if (labelWrap)  labelWrap.style.display  = isCustomer ? '' : 'none';
                                        if (roundWrap)  roundWrap.style.display  = isCustomer ? '' : 'none';
                                        if (customWrap) customWrap.style.display = (isCustomer && roundSel && roundSel.value === 'custom') ? '' : 'none';
                                    }

                                    if (feeMode) feeMode.addEventListener('change', updateVisibility);
                                    if (roundSel) roundSel.addEventListener('change', updateVisibility);
                                })();
                                </script>

                            </div>

                            <?php // ═══ PANE: ADVANCED ═══ ?>
                            <div class="tix-pane" data-pane="advanced">

                                <?php // ── Card: Steuern ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-money-alt"></span>
                                        <h3>Steuern</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('tax_enabled', 'Steuerberechnung aktivieren', $s, 'Berechnet automatisch die MwSt. bei jeder Bestellung.'); ?>
                                            </div>
                                            <?php self::range_row('tax_rate', 'Steuersatz', $s, 0, 100, ' %', 0.5, true); ?>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('tax_inclusive', 'Preise enthalten MwSt. (Bruttopreise)', $s, 'Wenn aktiviert, wird die MwSt. aus dem Preis herausgerechnet. Andernfalls wird die MwSt. auf den Preis aufgeschlagen.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

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

                                <?php // ── Card: Künstliche Intelligenz ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-admin-generic"></span>
                                        <h3>Künstliche Intelligenz</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::text_row('ai_assistant_name', 'Assistent-Name', $s, 'Evendis-Assistent'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint" style="margin-top:0;">
                                                    Name des KI-Assistenten im Event-Editor. Der API-Key wird für alle KI-Features verwendet.
                                                </p>
                                            </div>
                                            <?php self::text_row('anthropic_api_key', 'Anthropic API Key', $s, 'sk-ant-…'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    <a href="https://console.anthropic.com/settings/keys" target="_blank">Anthropic Key erstellen →</a>
                                                </p>
                                            </div>
                                            <?php self::text_row('openai_api_key', 'OpenAI API Key', $s, 'sk-…'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Key erstellen →</a>
                                                    Optional – nur nötig wenn ein OpenAI-Modell ausgewählt wird.
                                                </p>
                                            </div>
                                            <?php
                                            $models = [
                                                // Anthropic
                                                'claude-sonnet-4-20250514'    => 'Claude Sonnet 4 (Standard)',
                                                'claude-opus-4-20250514'      => 'Claude Opus 4 (Premium)',
                                                'claude-3-5-haiku-20241022'   => 'Claude 3.5 Haiku (Budget)',
                                                // OpenAI
                                                'gpt-4o'                      => 'GPT-4o (Standard)',
                                                'gpt-4o-mini'                 => 'GPT-4o Mini (Budget)',
                                                'o3-mini'                     => 'o3-mini (Reasoning)',
                                            ];
                                            $current_model = $s['ai_model'] ?? 'claude-sonnet-4-20250514';
                                            ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">KI-Modell</label>
                                                <select name="tix_settings[ai_model]" style="width:100%;max-width:420px;">
                                                    <optgroup label="Anthropic (Claude)">
                                                    <?php foreach (array_slice($models, 0, 3) as $val => $label): ?>
                                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($current_model, $val); ?>><?php echo esc_html($label); ?></option>
                                                    <?php endforeach; ?>
                                                    </optgroup>
                                                    <optgroup label="OpenAI">
                                                    <?php foreach (array_slice($models, 3) as $val => $label): ?>
                                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($current_model, $val); ?>><?php echo esc_html($label); ?></option>
                                                    <?php endforeach; ?>
                                                    </optgroup>
                                                </select>
                                                <p class="tix-settings-hint">Wird für Evendis-Assistent und Textgenerierung verwendet. KI-Schutz nutzt immer Claude Haiku (kostensparend).</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Syndication (Push) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-rss"></span>
                                        <h3>Event-Syndication (Push)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint" style="margin-top:0;">
                                                    Events automatisch an eine zentrale Plattform senden. Aktiviere pro Event die Checkbox "Auf Plattform veröffentlichen".
                                                </p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('syndication_enabled', 'Syndication aktivieren', $s, 'Events können an eine externe Plattform gesendet werden.'); ?>
                                            </div>
                                            <?php self::text_row('syndication_api_url', 'Plattform API-URL', $s, 'https://evendis.de/wp-json/tixomat/v1'); ?>
                                            <?php self::text_row('syndication_api_key', 'API Key', $s, 'tix_syn_...'); ?>
                                            <?php self::text_row('syndication_site_name', 'Anzeigename dieser Seite', $s, 'z.B. Kitchen Klub'); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // ── Card: Syndication (Empfang) ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-download"></span>
                                        <h3>Event-Syndication (Empfang)</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint" style="margin-top:0;">
                                                    Events von externen Tixomat-Installationen empfangen. Syndizierte Events werden automatisch erstellt und bei Klick auf "Tickets" zur Quellseite weitergeleitet.
                                                </p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <?php self::checkbox_row('syndication_receive_enabled', 'Empfang aktivieren', $s, 'Diese Seite kann syndizierte Events empfangen.'); ?>
                                            </div>
                                            <?php
                                            $receive_key = $s['syndication_receive_key'] ?? '';
                                            if (empty($receive_key)) {
                                                $receive_key = 'tix_syn_' . wp_generate_password(24, false);
                                            }
                                            ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-label">Empfangs-Key (an Sender weitergeben)</label>
                                                <input type="text" name="tix_settings[syndication_receive_key]" value="<?php echo esc_attr($receive_key); ?>" class="regular-text" style="width:100%;font-family:monospace;font-size:13px;" readonly onclick="this.select();">
                                                <p class="tix-settings-hint">Diesen Key dem Sender mitteilen. Er wird als <code>X-Tix-Syndication-Key</code> Header gesendet.</p>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    API-Endpoint: <code><?php echo esc_html(rest_url('tixomat/v1/syndicate')); ?></code>
                                                </p>
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
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
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

                                <?php // ── Card: Rechnungen ── ?>
                                <div class="tix-card">
                                    <div class="tix-card-header">
                                        <span class="dashicons dashicons-media-document"></span>
                                        <h3>Rechnungen</h3>
                                    </div>
                                    <div class="tix-card-body">
                                        <div class="tix-field-grid">
                                            <?php self::text_row('invoice_company_name', 'Firmenname', $s, 'Meine Firma GmbH'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Firmenadresse</label>
                                                <textarea name="<?php echo self::OPTION_KEY; ?>[invoice_company_address]" rows="3" class="large-text" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;resize:vertical;" placeholder="Musterstra&szlig;e 1&#10;12345 Berlin&#10;Deutschland"><?php echo esc_textarea($s['invoice_company_address'] ?? ''); ?></textarea>
                                            </div>
                                            <?php self::text_row('invoice_company_tax_id', 'USt-IdNr. / Steuernummer', $s, 'DE123456789'); ?>
                                            <div class="tix-field tix-field-full">
                                                <label class="tix-field-label">Fu&szlig;zeile</label>
                                                <textarea name="<?php echo self::OPTION_KEY; ?>[invoice_footer_text]" rows="2" class="large-text" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;resize:vertical;" placeholder="Gesch&auml;ftsf&uuml;hrer: Max Mustermann | Amtsgericht Berlin HRB 12345"><?php echo esc_textarea($s['invoice_footer_text'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-settings-hint">
                                                    Diese Daten erscheinen auf der Rechnung, die &uuml;ber die Bestelldetails generiert werden kann.
                                                </p>
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
                                                <?php self::checkbox_row('branding_enabled', '&quot;Ticketsystem von Tixomat&quot; unter Shortcodes anzeigen', $s); ?>
                                            </div>
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
                                            <?php self::text_row('login_redirect', 'Weiterleitung nach Login', $s, '/events/'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-hint" style="margin:-8px 0 8px;font-size:12px;color:#94a3b8;">
                                                    Nicht-Admins werden nach dem Login hierhin weitergeleitet. Leer = WordPress-Backend. Admins landen immer im Backend.
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
                                            <?php self::text_row('my_tickets_slug', 'Meine-Tickets-URL', $s, 'tickets'); ?>
                                            <div class="tix-field tix-field-full">
                                                <p class="tix-hint" style="margin:-8px 0 8px;font-size:12px;color:#94a3b8;">
                                                    Aktiv: <code><?php echo esc_html(home_url('/' . ($s['my_tickets_slug'] ?: 'tickets') . '/')); ?></code> &mdash; Link in Best&auml;tigungs-E-Mails.
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
                                            <div class="tix-field tix-field-full" style="margin-top:12px;">
                                                <label class="tix-field-label">Custom Logo</label>
                                                <div style="display:flex;gap:12px;align-items:center;">
                                                    <input type="text" name="tix_settings[admin_logo_url]" id="tix-admin-logo-url"
                                                           value="<?php echo esc_attr($s['admin_logo_url'] ?? ''); ?>"
                                                           class="regular-text" placeholder="Logo-URL eingeben oder Bild w&auml;hlen"
                                                           style="flex:1;">
                                                    <button type="button" class="button" id="tix-admin-logo-btn">Bild w&auml;hlen</button>
                                                </div>
                                                <?php if (!empty($s['admin_logo_url'])) : ?>
                                                    <div style="margin-top:8px;">
                                                        <img src="<?php echo esc_url($s['admin_logo_url']); ?>" style="max-height:40px;width:auto;background:#FAF8F4;padding:8px 12px;border-radius:8px;">
                                                        <button type="button" class="button-link" style="color:#ef4444;margin-left:8px;" onclick="document.getElementById('tix-admin-logo-url').value='';this.parentNode.remove();">Entfernen</button>
                                                    </div>
                                                <?php endif; ?>
                                                <p class="tix-field-hint">Ersetzt das Tixomat-Logo in Sidebar, Login-Seite und Dashboard. Empfohlen: transparentes PNG, max. 500px breit.</p>
                                            </div>
                                        </div>
                                        <script>
                                        jQuery(function($){
                                            $('#tix-admin-logo-btn').on('click',function(e){
                                                e.preventDefault();
                                                var frame = wp.media({title:'Logo w\u00e4hlen',multiple:false,library:{type:'image'}});
                                                frame.on('select',function(){
                                                    var url = frame.state().get('selection').first().toJSON().url;
                                                    $('#tix-admin-logo-url').val(url);
                                                });
                                                frame.open();
                                            });
                                        });
                                        </script>
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

    // ═══════════════════════════════════════════════════════════
    // Per-Class Typografie Registry
    // ═══════════════════════════════════════════════════════════

    /**
     * Vollständige Registry aller Frontend-Text-Klassen.
     * Gruppiert nach Komponente. Jeder Eintrag: label, size (px), font (heading|body), weight.
     */
    public static function typo_class_registry() {
        return [
            'Single Event' => [
                'tse-intro-title'      => ['label' => 'Event-Titel',          'size' => 28, 'font' => 'heading', 'weight' => 800],
                'tse-intro-date'       => ['label' => 'Datum-Label',          'size' => 12, 'font' => 'heading', 'weight' => 700],
                'tse-intro-loc'        => ['label' => 'Ort',                  'size' => 13, 'font' => 'body',    'weight' => 400],
                'tse-intro-excerpt'    => ['label' => 'Beschreibung',         'size' => 15, 'font' => 'body',    'weight' => 400],
                'tse-tab'              => ['label' => 'Tab-Navigation',       'size' => 13, 'font' => 'heading', 'weight' => 600],
                'tse-sec-label'        => ['label' => 'Abschnitts-Label',     'size' => 12, 'font' => 'heading', 'weight' => 700],
                'tse-sec-title'        => ['label' => 'Abschnitts-Titel',     'size' => 18, 'font' => 'heading', 'weight' => 800],
                'tse-sec-content'      => ['label' => 'Abschnitts-Text',      'size' => 15, 'font' => 'body',    'weight' => 400],
                'tse-info-label'       => ['label' => 'Info-Label',           'size' => 12, 'font' => 'body',    'weight' => 400],
                'tse-info-value'       => ['label' => 'Info-Wert',            'size' => 13, 'font' => 'heading', 'weight' => 600],
                'tse-info-sub'         => ['label' => 'Info-Zusatz',          'size' => 12, 'font' => 'body',    'weight' => 400],
                'tse-badge'            => ['label' => 'Badge',                'size' => 12, 'font' => 'heading', 'weight' => 700],
                'tse-cal-btn'          => ['label' => 'Kalender-Button',      'size' => 13, 'font' => 'heading', 'weight' => 600],
                'tse-share-btn'        => ['label' => 'Teilen-Button',        'size' => 12, 'font' => 'heading', 'weight' => 600],
                'tse-countdown-label'  => ['label' => 'Countdown-Label',      'size' => 12, 'font' => 'heading', 'weight' => 700],
                'tse-cd-val'           => ['label' => 'Countdown-Zahl',       'size' => 22, 'font' => 'heading', 'weight' => 800],
                'tse-cd-label'         => ['label' => 'Countdown-Einheit',    'size' => 12, 'font' => 'body',    'weight' => 400],
                'tse-location-address' => ['label' => 'Adresse',              'size' => 13, 'font' => 'body',    'weight' => 400],
                'tse-location-link'    => ['label' => 'Maps-Link',            'size' => 13, 'font' => 'heading', 'weight' => 600],
            ],
            'Ticket Selector' => [
                'tix-sel-presale-label' => ['label' => 'Vorverkauf-Label',       'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-sel-notify-text'   => ['label' => 'Benachrichtigungs-Text', 'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-sel-notify-btn'    => ['label' => 'Erinnern-Button',        'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-sel-group-header'  => ['label' => 'Gruppen-Überschrift',    'size' => 16, 'font' => 'heading', 'weight' => 700],
                'tix-sel-cat-name'      => ['label' => 'Ticket-Name',            'size' => 15, 'font' => 'heading', 'weight' => 700],
                'tix-sel-cat-desc'      => ['label' => 'Ticket-Beschreibung',    'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-sel-phase-badge'   => ['label' => 'Phasen-Badge',           'size' => 12, 'font' => 'heading', 'weight' => 700],
                'tix-sel-phase-name'    => ['label' => 'Phasen-Name',            'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-sel-phase-price'   => ['label' => 'Phasen-Preis',           'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-sel-phase-until'   => ['label' => 'Gültig bis',             'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-sel-price-sale'    => ['label' => 'Sale-Preis',             'size' => 18, 'font' => 'heading', 'weight' => 800],
                'tix-sel-price-old'     => ['label' => 'Alter Preis',            'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-sel-price-regular' => ['label' => 'Regulärer Preis',        'size' => 16, 'font' => 'heading', 'weight' => 700],
                'tix-sel-vat'           => ['label' => 'MwSt-Hinweis',           'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-sel-low-stock'     => ['label' => 'Wenig verfügbar',        'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-sel-soldout-label' => ['label' => 'Ausverkauft',            'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-sel-charity-badge' => ['label' => 'Charity-Badge',          'size' => 12, 'font' => 'heading', 'weight' => 600],
                'tix-sel-charity-desc'  => ['label' => 'Charity-Text',           'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-sel-bundle-save'   => ['label' => 'Bundle-Ersparnis',       'size' => 12, 'font' => 'heading', 'weight' => 700],
                'tix-countdown-num'     => ['label' => 'Countdown-Zahl',         'size' => 28, 'font' => 'heading', 'weight' => 800],
                'tix-countdown-lbl'     => ['label' => 'Countdown-Label',        'size' => 12, 'font' => 'body', 'weight' => 400],
            ],
            'Checkout' => [
                'tix-co-heading'          => ['label' => 'Abschnitts-Titel',    'size' => 16, 'font' => 'heading', 'weight' => 700],
                'tix-co-label'            => ['label' => 'Feld-Label',          'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-co-input'            => ['label' => 'Eingabefeld',         'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-co-item-name'        => ['label' => 'Artikel-Name',        'size' => 15, 'font' => 'body', 'weight' => 600],
                'tix-co-item-price'       => ['label' => 'Artikel-Preis',       'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-co-qty-val'          => ['label' => 'Mengen-Wert',         'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-co-coupon-btn'       => ['label' => 'Gutschein-Button',    'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-co-coupon-tag'       => ['label' => 'Gutschein-Tag',       'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-co-summary-row'      => ['label' => 'Zusammenfassung',     'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-co-summary-total'    => ['label' => 'Gesamt-Betrag',       'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-co-vat-note'         => ['label' => 'MwSt-Hinweis',        'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-co-gw-title'         => ['label' => 'Zahlungsart-Name',    'size' => 15, 'font' => 'body', 'weight' => 600],
                'tix-co-gw-desc'          => ['label' => 'Zahlungsart-Info',    'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-co-step-num'         => ['label' => 'Schritt-Nummer',      'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-co-step-label'       => ['label' => 'Schritt-Label',       'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-co-submit'           => ['label' => 'Bestellen-Button',    'size' => 16, 'font' => 'body', 'weight' => 700],
                'tix-co-submit-price'     => ['label' => 'Button-Preis',        'size' => 16, 'font' => 'body', 'weight' => 700],
                'tix-co-link-btn'         => ['label' => 'Link-Button',         'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-co-message'          => ['label' => 'Status-Meldung',      'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-co-countdown-label'  => ['label' => 'Countdown-Label',     'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-co-countdown-time'   => ['label' => 'Countdown-Zeit',      'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-co-specials-heading' => ['label' => 'Specials-Titel',      'size' => 16, 'font' => 'heading', 'weight' => 700],
                'tix-co-tables-heading'   => ['label' => 'Tisch-Titel',         'size' => 16, 'font' => 'heading', 'weight' => 700],
                'tix-co-shipping-info'    => ['label' => 'Versand-Info',        'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-co-legal-heading'    => ['label' => 'Rechtliches-Titel',   'size' => 14, 'font' => 'heading', 'weight' => 600],
            ],
            'Checkout Thank-You' => [
                'tix-co-ty-title'          => ['label' => 'Danke-Überschrift',    'size' => 22, 'font' => 'heading', 'weight' => 700],
                'tix-co-ty-subtitle'       => ['label' => 'Bestätigungs-Text',    'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-co-ty-ticket-type'    => ['label' => 'Ticket-Typ',           'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-co-ty-ticket-code'    => ['label' => 'Ticket-Code',          'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-co-ty-ticket-dl'      => ['label' => 'Download-Link',        'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-co-ty-ticket-event'   => ['label' => 'Event-Name',           'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-co-ty-ticket-pending' => ['label' => 'Zahlung ausstehend',   'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-co-ty-item-name'      => ['label' => 'Artikel-Name',         'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-co-ty-item-qty'       => ['label' => 'Artikel-Menge',        'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-co-ty-item-total'     => ['label' => 'Artikel-Preis',        'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-co-ty-vat'            => ['label' => 'MwSt-Hinweis',         'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-co-ty-detail-label'   => ['label' => 'Detail-Label',         'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-co-ty-detail-value'   => ['label' => 'Detail-Wert',          'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-co-ty-status'         => ['label' => 'Status-Badge',         'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-co-ty-bank-heading'   => ['label' => 'Bank-Überschrift',     'size' => 16, 'font' => 'heading', 'weight' => 700],
                'tix-co-ty-bank-label'     => ['label' => 'Bank-Label',           'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-co-ty-bank-value'     => ['label' => 'Bank-Wert',            'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-co-ty-charity-badge'  => ['label' => 'Charity-Badge',        'size' => 12, 'font' => 'body', 'weight' => 600],
            ],
            'Native Thank-You' => [
                'tix-ty-title'       => ['label' => 'Danke-Überschrift', 'size' => 22, 'font' => 'heading', 'weight' => 700],
                'tix-ty-text'        => ['label' => 'Bestätigungs-Text', 'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-ty-card-title'  => ['label' => 'Karten-Titel',      'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-ty-ticket-code' => ['label' => 'Ticket-Code',       'size' => 12, 'font' => 'body', 'weight' => 600],
            ],
            'Event Cards' => [
                'section-label'        => ['label' => 'Sektion-Label',   'size' => 12, 'font' => 'heading', 'weight' => 700],
                'section-title'        => ['label' => 'Sektion-Titel',   'size' => 22, 'font' => 'heading', 'weight' => 800],
                'section-link'         => ['label' => 'Mehr-Link',       'size' => 13, 'font' => 'heading', 'weight' => 600],
                'ev-badge'             => ['label' => 'Event-Badge',     'size' => 12, 'font' => 'heading', 'weight' => 700],
                'ev-date'              => ['label' => 'Datum',           'size' => 11, 'font' => 'heading', 'weight' => 700],
                'ev-title'             => ['label' => 'Event-Titel',     'size' => 15, 'font' => 'heading', 'weight' => 700],
                'ev-loc'               => ['label' => 'Ort',             'size' => 13, 'font' => 'body', 'weight' => 400],
                'ev-price'             => ['label' => 'Preis',           'size' => 15, 'font' => 'heading', 'weight' => 700],
                'ev-price-old'         => ['label' => 'Alter Preis',     'size' => 12, 'font' => 'body', 'weight' => 400],
                'ev-btn'               => ['label' => 'Ticket-Button',   'size' => 12, 'font' => 'heading', 'weight' => 700],
                'tix-search-item-title' => ['label' => 'Such-Titel',    'size' => 14, 'font' => 'heading', 'weight' => 700],
                'tix-search-item-meta'  => ['label' => 'Such-Meta',     'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-search-item-price' => ['label' => 'Such-Preis',    'size' => 13, 'font' => 'heading', 'weight' => 700],
            ],
            'Event Page' => [
                'tix-ep-title'            => ['label' => 'Seiten-Titel',        'size' => 28, 'font' => 'heading', 'weight' => 800],
                'tix-ep-hero-badge'       => ['label' => 'Hero-Badge',          'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-ep-status'           => ['label' => 'Status-Badge',        'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-ep-meta-row'         => ['label' => 'Meta-Zeile',          'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-ep-meta-label'       => ['label' => 'Meta-Label',          'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-ep-meta-value'       => ['label' => 'Meta-Wert',           'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-ep-meta-sub'         => ['label' => 'Meta-Zusatz',         'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-ep-price-badge'      => ['label' => 'Preis-Badge',         'size' => 14, 'font' => 'body', 'weight' => 700],
                'tix-ep-age-badge'        => ['label' => 'Alter-Badge',         'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-ep-section-title'    => ['label' => 'Abschnitts-Titel',    'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-ep-section-body'     => ['label' => 'Abschnitts-Text',     'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-ep-location-name'    => ['label' => 'Ort-Name',            'size' => 14, 'font' => 'body', 'weight' => 700],
                'tix-ep-location-address' => ['label' => 'Adresse',             'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-ep-organizer-name'   => ['label' => 'Veranstalter',        'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-ep-organizer-label'  => ['label' => 'Veranstalter-Label',  'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-ep-series-day'       => ['label' => 'Serien-Tag',          'size' => 18, 'font' => 'heading', 'weight' => 800],
                'tix-ep-series-month'     => ['label' => 'Serien-Monat',        'size' => 12, 'font' => 'body', 'weight' => 600],
            ],
            'My Tickets' => [
                'tix-mt-section-title'    => ['label' => 'Abschnitts-Titel',  'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-mt-card-title'       => ['label' => 'Karten-Titel',      'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-mt-meta-item'        => ['label' => 'Meta-Info',          'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-mt-event-badge'      => ['label' => 'Event-Badge',        'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-mt-ticket-type'      => ['label' => 'Ticket-Typ',         'size' => 14, 'font' => 'body', 'weight' => 700],
                'tix-mt-ticket-qty'       => ['label' => 'Ticket-Menge',       'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-mt-ticket-price'     => ['label' => 'Ticket-Preis',       'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-mt-tcard-code'       => ['label' => 'Ticket-Code',        'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-mt-tcard-num'        => ['label' => 'Ticket-Nummer',      'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-mt-tcard-type'       => ['label' => 'Ticket-Art',         'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-mt-tcard-event'      => ['label' => 'Event-Name',         'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-mt-tcard-date'       => ['label' => 'Ticket-Datum',       'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-mt-tcard-dl'         => ['label' => 'Download-Link',      'size' => 13, 'font' => 'body', 'weight' => 700],
                'tix-mt-tcard-pending'    => ['label' => 'Ausstehend',         'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-mt-pending-notice'   => ['label' => 'Pending-Hinweis',    'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-mt-bank-heading'     => ['label' => 'Bank-Überschrift',   'size' => 14, 'font' => 'body', 'weight' => 700],
                'tix-mt-bank-row'         => ['label' => 'Bank-Zeile',         'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-mt-pending-info'     => ['label' => 'Pending-Info',       'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-mt-order-ref'        => ['label' => 'Bestell-Nr',         'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-mt-login-title'      => ['label' => 'Login-Titel',        'size' => 22, 'font' => 'heading', 'weight' => 700],
                'tix-mt-login-text'       => ['label' => 'Login-Text',         'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-mt-login-register'   => ['label' => 'Registrieren-Link',  'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-mt-empty-title'      => ['label' => 'Leer-Titel',         'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-mt-empty-text'       => ['label' => 'Leer-Text',          'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-mt-combo-badge'      => ['label' => 'Kombi-Badge',        'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-mt-combo-event-name' => ['label' => 'Kombi-Event',        'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-mt-combo-event-meta' => ['label' => 'Kombi-Meta',         'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-mt-combo-price'      => ['label' => 'Kombi-Preis',        'size' => 13, 'font' => 'body', 'weight' => 400],
            ],
            'Feedback' => [
                'tix-fb-title'        => ['label' => 'Feedback-Titel',       'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-fb-subtitle'     => ['label' => 'Untertitel',           'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-fb-submit'       => ['label' => 'Absenden-Button',      'size' => 15, 'font' => 'body', 'weight' => 600],
                'tix-fb-thanks-title' => ['label' => 'Danke-Titel',          'size' => 18, 'font' => 'heading', 'weight' => 600],
                'tix-fb-thanks-text'  => ['label' => 'Danke-Text',           'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-fb-avg-value'    => ['label' => 'Bewertungs-Wert',      'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-fb-avg-count'    => ['label' => 'Anzahl Bewertungen',   'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-ep-rating'       => ['label' => 'Rating-Anzeige',       'size' => 13, 'font' => 'body', 'weight' => 600],
            ],
            'FAQ' => [
                'tix-faq-title'    => ['label' => 'FAQ-Titel', 'size' => 20, 'font' => 'heading', 'weight' => 700],
                'tix-faq-question' => ['label' => 'Frage',     'size' => 16, 'font' => 'body', 'weight' => 600],
                'tix-faq-a-inner'  => ['label' => 'Antwort',   'size' => 15, 'font' => 'body', 'weight' => 400],
            ],
            'Timetable' => [
                'tix-tt-day'         => ['label' => 'Tag',                 'size' => 14, 'font' => 'heading', 'weight' => 600],
                'tix-tt-filter-btn'  => ['label' => 'Filter-Button',       'size' => 12, 'font' => 'body', 'weight' => 500],
                'tix-tt-grid-header' => ['label' => 'Grid-Header',         'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-tt-time'        => ['label' => 'Uhrzeit',             'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-tt-slot-title'  => ['label' => 'Slot-Titel',          'size' => 15, 'font' => 'heading', 'weight' => 600],
                'tix-tt-slot-desc'   => ['label' => 'Slot-Beschreibung',   'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-tt-slot-time'   => ['label' => 'Slot-Zeit',           'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-tt-list-title'  => ['label' => 'Listen-Titel',        'size' => 15, 'font' => 'heading', 'weight' => 600],
                'tix-tt-list-time'   => ['label' => 'Listen-Zeit',         'size' => 13, 'font' => 'body', 'weight' => 700],
                'tix-tt-list-desc'   => ['label' => 'Listen-Beschreibung', 'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-tt-list-stage'  => ['label' => 'Bühne',               'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-tt-tba'         => ['label' => 'TBA-Label',           'size' => 13, 'font' => 'body', 'weight' => 400],
            ],
            'Checkin' => [
                'tix-ci-title'         => ['label' => 'Checkin-Titel',     'size' => 12, 'font' => 'heading', 'weight' => 650],
                'tix-ci-select'        => ['label' => 'Event-Auswahl',     'size' => 15, 'font' => 'body', 'weight' => 500],
                'tix-ci-pw-error'      => ['label' => 'Passwort-Fehler',   'size' => 13, 'font' => 'body', 'weight' => 500],
                'tix-ci-input'         => ['label' => 'Eingabefeld',       'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-ci-btn'           => ['label' => 'Checkin-Button',    'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-ci-result-title'  => ['label' => 'Ergebnis-Titel',    'size' => 22, 'font' => 'heading', 'weight' => 700],
                'tix-ci-result-details' => ['label' => 'Ergebnis-Details', 'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-ci-search'        => ['label' => 'Suchfeld',          'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-ci-guest-name'    => ['label' => 'Gast-Name',         'size' => 15, 'font' => 'body', 'weight' => 600],
                'tix-ci-guest-plus'    => ['label' => 'Plus-Eins',         'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-ci-guest-note'    => ['label' => 'Gast-Notiz',        'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-ci-guest-status'  => ['label' => 'Gast-Status',       'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-ci-guest-checkin' => ['label' => 'Einchecken-Btn',    'size' => 13, 'font' => 'body', 'weight' => 700],
                'tix-ci-counter-btn'   => ['label' => 'Counter-Button',    'size' => 16, 'font' => 'body', 'weight' => 700],
                'tix-ci-counter-val'   => ['label' => 'Counter-Wert',      'size' => 13, 'font' => 'body', 'weight' => 700],
                'tix-ci-filter-btn'    => ['label' => 'Filter-Button',     'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-ci-badge'         => ['label' => 'Checkin-Badge',     'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-ci-empty'         => ['label' => 'Leer-Text',         'size' => 14, 'font' => 'body', 'weight' => 400],
            ],
            'Share' => [
                'tix-share-label'     => ['label' => 'Teilen-Label',  'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-share-btn-label' => ['label' => 'Button-Label',  'size' => 13, 'font' => 'body', 'weight' => 500],
            ],
            'Calendar' => [
                'tix-cal-btn' => ['label' => 'Kalender-Button', 'size' => 14, 'font' => 'body', 'weight' => 700],
                'tix-cal-opt' => ['label' => 'Kalender-Option', 'size' => 14, 'font' => 'body', 'weight' => 500],
            ],
            'Upsell' => [
                'tix-up-heading'    => ['label' => 'Überschrift',   'size' => 18, 'font' => 'heading', 'weight' => 600],
                'tix-up-card-title' => ['label' => 'Karten-Titel',  'size' => 15, 'font' => 'body', 'weight' => 600],
                'tix-up-card-meta'  => ['label' => 'Meta-Info',     'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-up-card-price' => ['label' => 'Preis',         'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-up-card-badge' => ['label' => 'Badge',         'size' => 11, 'font' => 'body', 'weight' => 600],
            ],
            'Express Modal' => [
                'tix-ec-trigger-btn'   => ['label' => 'Trigger-Button',   'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-ec-title'         => ['label' => 'Modal-Titel',      'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-ec-event-name'    => ['label' => 'Event-Name',       'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-ec-cat-name'      => ['label' => 'Kategorie-Name',   'size' => 15, 'font' => 'body', 'weight' => 600],
                'tix-ec-cat-price'     => ['label' => 'Kategorie-Preis',  'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-ec-price-sale'    => ['label' => 'Sale-Preis',       'size' => 14, 'font' => 'body', 'weight' => 700],
                'tix-ec-vat'           => ['label' => 'MwSt',             'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-ec-qty-val'       => ['label' => 'Mengen-Wert',      'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-ec-vat-note'      => ['label' => 'MwSt-Hinweis',     'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-ec-total-price'   => ['label' => 'Gesamtpreis',      'size' => 20, 'font' => 'heading', 'weight' => 700],
                'tix-ec-terms'         => ['label' => 'AGB-Text',         'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-ec-buy'           => ['label' => 'Kaufen-Button',    'size' => 16, 'font' => 'body', 'weight' => 700],
                'tix-ec-note'          => ['label' => 'Hinweis',          'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-ec-message'       => ['label' => 'Meldung',          'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-ec-offer-heading' => ['label' => 'Angebots-Titel',   'size' => 13, 'font' => 'body', 'weight' => 700],
                'tix-ec-offer-desc'    => ['label' => 'Angebots-Text',    'size' => 12, 'font' => 'body', 'weight' => 400],
            ],
            'Modal Checkout' => [
                'tix-mc-title'           => ['label' => 'Modal-Titel',            'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-mc-event-name'      => ['label' => 'Event-Name',             'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-mc-event-date'      => ['label' => 'Event-Datum',            'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-mc-cat-name'        => ['label' => 'Kategorie-Name',         'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-mc-cat-desc'        => ['label' => 'Kategorie-Beschreibung', 'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-mc-cat-price'       => ['label' => 'Kategorie-Preis',        'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-mc-price-sale'      => ['label' => 'Sale-Preis',             'size' => 14, 'font' => 'body', 'weight' => 700],
                'tix-mc-vat'             => ['label' => 'MwSt',                   'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-mc-total-label'     => ['label' => 'Gesamt-Label',           'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-mc-total-price'     => ['label' => 'Gesamtpreis',            'size' => 20, 'font' => 'heading', 'weight' => 700],
                'tix-mc-vat-note'        => ['label' => 'MwSt-Hinweis',           'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-mc-next'            => ['label' => 'Weiter-Button',          'size' => 16, 'font' => 'body', 'weight' => 700],
                'tix-mc-back'            => ['label' => 'Zurück-Button',          'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-mc-section-heading' => ['label' => 'Abschnitts-Titel',       'size' => 14, 'font' => 'heading', 'weight' => 600],
                'tix-mc-label'           => ['label' => 'Feld-Label',             'size' => 13, 'font' => 'body', 'weight' => 600],
                'tix-mc-input'           => ['label' => 'Eingabefeld',            'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-mc-gw-title'        => ['label' => 'Zahlungsart-Name',       'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-mc-summary-row'     => ['label' => 'Zusammenfassung',        'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-mc-summary-total'   => ['label' => 'Gesamt',                 'size' => 16, 'font' => 'heading', 'weight' => 700],
                'tix-mc-submit'          => ['label' => 'Bestellen-Button',       'size' => 16, 'font' => 'body', 'weight' => 700],
                'tix-mc-offer-heading'   => ['label' => 'Angebots-Titel',         'size' => 13, 'font' => 'body', 'weight' => 700],
                'tix-mc-offer-save'      => ['label' => 'Ersparnis',              'size' => 12, 'font' => 'body', 'weight' => 700],
            ],
            'Minicart' => [
                'tix-minicart-count' => ['label' => 'Warenkorb-Zähler', 'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-mc-drawer-title' => ['label' => 'Warenkorb-Titel', 'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-mc-item-name'   => ['label' => 'Artikel-Name',     'size' => 14, 'font' => 'body', 'weight' => 700],
                'tix-mc-item-hint'   => ['label' => 'Artikel-Hinweis',  'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-mc-item-price'  => ['label' => 'Artikel-Preis',    'size' => 15, 'font' => 'body', 'weight' => 700],
                'tix-mc-total-row'   => ['label' => 'Gesamt-Zeile',     'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-mc-checkout-btn' => ['label' => 'Zur Kasse Button', 'size' => 16, 'font' => 'body', 'weight' => 700],
            ],
            'Raffle' => [
                'tix-raffle-title'         => ['label' => 'Gewinnspiel-Titel', 'size' => 22, 'font' => 'heading', 'weight' => 700],
                'tix-raffle-desc'          => ['label' => 'Beschreibung',      'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-raffle-prizes-title'  => ['label' => 'Preise-Titel',      'size' => 15, 'font' => 'body', 'weight' => 600],
                'tix-raffle-prize-badge'   => ['label' => 'Preis-Badge',       'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-raffle-countdown'     => ['label' => 'Countdown',         'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-raffle-consent'       => ['label' => 'Einwilligung',      'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-raffle-msg'           => ['label' => 'Meldung',           'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-raffle-count'         => ['label' => 'Teilnehmer-Zähler', 'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-raffle-success-title' => ['label' => 'Erfolgs-Titel',     'size' => 18, 'font' => 'heading', 'weight' => 600],
                'tix-raffle-success-text'  => ['label' => 'Erfolgs-Text',      'size' => 14, 'font' => 'body', 'weight' => 400],
            ],
            'Table Reservation' => [
                'tr-header-title'       => ['label' => 'Header-Titel',           'size' => 15, 'font' => 'heading', 'weight' => 700],
                'tr-back-btn'           => ['label' => 'Zurück-Button',          'size' => 13, 'font' => 'body', 'weight' => 400],
                'tr-calendar-month'     => ['label' => 'Kalender-Monat',         'size' => 22, 'font' => 'heading', 'weight' => 700],
                'tr-weekday-header'     => ['label' => 'Wochentag',              'size' => 12, 'font' => 'body', 'weight' => 600],
                'tr-day-number'         => ['label' => 'Tag-Nummer',             'size' => 13, 'font' => 'body', 'weight' => 600],
                'tr-day-event-title'    => ['label' => 'Tages-Event',            'size' => 10, 'font' => 'body', 'weight' => 600],
                'tr-no-events'          => ['label' => 'Keine Events',           'size' => 15, 'font' => 'body', 'weight' => 400],
                'tr-event-title'        => ['label' => 'Event-Titel',            'size' => 15, 'font' => 'heading', 'weight' => 700],
                'tr-event-meta'         => ['label' => 'Event-Meta',             'size' => 12, 'font' => 'body', 'weight' => 400],
                'tr-info-text'          => ['label' => 'Info-Text',              'size' => 13, 'font' => 'body', 'weight' => 400],
                'tr-categories-title'   => ['label' => 'Kategorien-Titel',       'size' => 14, 'font' => 'heading', 'weight' => 700],
                'tr-cat-name'           => ['label' => 'Kategorie-Name',         'size' => 14, 'font' => 'body', 'weight' => 600],
                'tr-cat-desc'           => ['label' => 'Kategorie-Beschreibung', 'size' => 12, 'font' => 'body', 'weight' => 400],
                'tr-cat-badge'          => ['label' => 'Kategorie-Badge',        'size' => 12, 'font' => 'body', 'weight' => 400],
                'tr-cat-price'          => ['label' => 'Kategorie-Preis',        'size' => 13, 'font' => 'heading', 'weight' => 700],
                'tr-cat-price-label'    => ['label' => 'Preis-Label',            'size' => 12, 'font' => 'body', 'weight' => 400],
                'tr-cat-avail'          => ['label' => 'Verfügbarkeit',          'size' => 12, 'font' => 'body', 'weight' => 400],
                'tr-form-summary-event' => ['label' => 'Zusammenfassung-Event',  'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tr-form-label'         => ['label' => 'Formular-Label',         'size' => 13, 'font' => 'body', 'weight' => 600],
                'tr-form-input'         => ['label' => 'Formular-Eingabe',       'size' => 14, 'font' => 'body', 'weight' => 400],
                'tr-price-total'        => ['label' => 'Gesamt-Preis',           'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tr-price-note'         => ['label' => 'Preis-Hinweis',          'size' => 12, 'font' => 'body', 'weight' => 400],
                'tr-success-title'      => ['label' => 'Erfolgs-Titel',          'size' => 28, 'font' => 'heading', 'weight' => 800],
                'tr-success-text'       => ['label' => 'Erfolgs-Text',           'size' => 15, 'font' => 'body', 'weight' => 400],
            ],
            'Seatmap Picker' => [
                'tix-sp-stage'            => ['label' => 'Bühne',              'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-sp-legend-item'      => ['label' => 'Legende',            'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-sp-section-header'   => ['label' => 'Sektions-Header',    'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-sp-section-avail'    => ['label' => 'Verfügbarkeit',      'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-sp-selection-header' => ['label' => 'Auswahl-Header',     'size' => 12, 'font' => 'body', 'weight' => 700],
                'tix-sp-selected-tag'     => ['label' => 'Auswahl-Tag',        'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-sp-timer'            => ['label' => 'Timer',              'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-sp-loading'          => ['label' => 'Laden-Text',         'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-sp-modal-header'     => ['label' => 'Modal-Titel',        'size' => 18, 'font' => 'heading', 'weight' => 700],
                'tix-sp-modal-summary'    => ['label' => 'Modal-Info',         'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-sp-modal-confirm'    => ['label' => 'Bestätigen-Button',  'size' => 14, 'font' => 'body', 'weight' => 600],
            ],
            'Exit Intent' => [
                'tix-ei-headline' => ['label' => 'Überschrift', 'size' => 22, 'font' => 'heading', 'weight' => 800],
                'tix-ei-text'     => ['label' => 'Text',        'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-ei-button'   => ['label' => 'Button',      'size' => 15, 'font' => 'body', 'weight' => 700],
            ],
            'Register Event' => [
                'tix-re-title'         => ['label' => 'Seiten-Titel',    'size' => 28, 'font' => 'heading', 'weight' => 800],
                'tix-re-subtitle'      => ['label' => 'Untertitel',      'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-re-step'          => ['label' => 'Schritt',         'size' => 14, 'font' => 'body', 'weight' => 500],
                'tix-re-step-num'      => ['label' => 'Schritt-Nummer',  'size' => 13, 'font' => 'body', 'weight' => 700],
                'tix-re-panel-title'   => ['label' => 'Panel-Titel',     'size' => 22, 'font' => 'heading', 'weight' => 700],
                'tix-re-bubble'        => ['label' => 'Chat-Blase',      'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-re-dropzone-text' => ['label' => 'Upload-Text',     'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-re-dropzone-hint' => ['label' => 'Upload-Hinweis',  'size' => 12, 'font' => 'body', 'weight' => 400],
                'tix-re-input'         => ['label' => 'Eingabefeld',     'size' => 15, 'font' => 'body', 'weight' => 400],
                'tix-re-preview-label' => ['label' => 'Preview-Label',   'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-re-preview-value' => ['label' => 'Preview-Wert',    'size' => 14, 'font' => 'body', 'weight' => 400],
                'tix-re-field label'   => ['label' => 'Feld-Label',      'size' => 12, 'font' => 'body', 'weight' => 600],
                'tix-re-legal'         => ['label' => 'Rechtliches',     'size' => 13, 'font' => 'body', 'weight' => 400],
                'tix-re-btn-primary'   => ['label' => 'Haupt-Button',    'size' => 14, 'font' => 'body', 'weight' => 600],
                'tix-re-error'         => ['label' => 'Fehler-Meldung',  'size' => 14, 'font' => 'body', 'weight' => 400],
            ],
        ];
    }

    /**
     * Flache Registry: Klasse => Defaults (ohne Gruppierung).
     */
    public static function typo_class_registry_flat() {
        $flat = [];
        foreach (self::typo_class_registry() as $classes) {
            foreach ($classes as $cls => $def) {
                $flat[$cls] = $def;
            }
        }
        return $flat;
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

    // ═══════════════════════════════════════════════════════════
    // Per-Class Farben Registry
    // ═══════════════════════════════════════════════════════════

    /**
     * Vollständige Registry aller Frontend-Klassen mit Farb-Properties.
     * Gruppiert nach Komponente. Jeder Eintrag: label, props => [color, bg, border].
     */
    public static function color_class_registry(): array {
        return [
            'Single Event' => [
                'tse-intro-title'      => ['label' => 'Event-Titel',           'props' => ['color' => '#1f2937']],
                'tse-intro-date'       => ['label' => 'Event-Datum',           'props' => ['color' => '#8C8985']],
                'tse-intro-loc'        => ['label' => 'Event-Ort',             'props' => ['color' => '#3A3937']],
                'tse-intro-excerpt'    => ['label' => 'Event-Kurztext',        'props' => ['color' => '#3A3937']],
                'tse-tab'              => ['label' => 'Tab',                   'props' => ['color' => '#8C8985', 'border' => '#E3DED4']],
                'tse-tabs'             => ['label' => 'Tab-Leiste',            'props' => ['bg' => '#FDFBF7']],
                'tse-sec-label'        => ['label' => 'Sektions-Label',        'props' => ['color' => '#8C8985']],
                'tse-sec-title'        => ['label' => 'Sektions-Titel',        'props' => ['color' => '#1f2937']],
                'tse-sec-content'      => ['label' => 'Sektions-Inhalt',       'props' => ['color' => '#3A3937']],
                'tse-sec'              => ['label' => 'Sektion',               'props' => ['bg' => '#ffffff', 'border' => '#E3DED4']],
                'tse-info-card'        => ['label' => 'Info-Karte',            'props' => ['bg' => '#ffffff', 'border' => '#E3DED4']],
                'tse-info-label'       => ['label' => 'Info-Label',            'props' => ['color' => '#8C8985']],
                'tse-info-value'       => ['label' => 'Info-Wert',             'props' => ['color' => '#1f2937']],
                'tse-info-sub'         => ['label' => 'Info-Zusatz',           'props' => ['color' => '#8C8985']],
                'tse-badge-available'  => ['label' => 'Badge Verfügbar',       'props' => ['bg' => '#14B8A6', 'color' => '#ffffff']],
                'tse-badge-soldout'    => ['label' => 'Badge Ausverkauft',     'props' => ['bg' => '#E8445A', 'color' => '#ffffff']],
                'tse-badge-age'        => ['label' => 'Badge Alter',           'props' => ['bg' => '#131020', 'color' => '#ffffff']],
                'tse-countdown-label'  => ['label' => 'Countdown-Label',       'props' => ['color' => '#8C8985']],
                'tse-cd-val'           => ['label' => 'Countdown-Wert',        'props' => ['color' => '#1f2937']],
                'tse-cd-label'         => ['label' => 'Countdown-Einheit',     'props' => ['color' => '#8C8985']],
                'tse-location-address' => ['label' => 'Adresse',               'props' => ['color' => '#3A3937']],
                'tse-location-link'    => ['label' => 'Ort-Link',              'props' => ['color' => '#E8445A']],
                'tse-cal-btn'          => ['label' => 'Kalender-Button',       'props' => ['color' => '#1f2937', 'border' => '#E3DED4']],
                'tse-share-btn'        => ['label' => 'Teilen-Button',         'props' => ['color' => '#1f2937', 'border' => '#E3DED4']],
                'tse-countdown'        => ['label' => 'Countdown-Box',         'props' => ['bg' => '#131020', 'color' => '#ffffff']],
                'tse-hero-placeholder' => ['label' => 'Hero-Platzhalter',      'props' => ['bg' => '#FF5500']],
            ],
            'Ticket Selector' => [
                'tix-sel'               => ['label' => 'Ticket-Selector',          'props' => ['color' => '#475569']],
                'tix-sel-group-header'  => ['label' => 'Gruppen-Header',           'props' => ['color' => '#475569']],
                'tix-sel-cat'           => ['label' => 'Kategorie',                'props' => ['border' => '#333333', 'bg' => '#ffffff']],
                'tix-sel-active'        => ['label' => 'Kategorie aktiv',          'props' => ['bg' => 'rgba(255,255,255,0.05)']],
                'tix-sel-cat-name'      => ['label' => 'Kategorie-Name',           'props' => ['color' => '#ffffff']],
                'tix-sel-cat-desc'      => ['label' => 'Kategorie-Beschreibung',   'props' => ['color' => '#aaaaaa']],
                'tix-sel-price-regular' => ['label' => 'Preis (regulär)',          'props' => ['color' => '#ffffff']],
                'tix-sel-price-sale'    => ['label' => 'Preis (Sale)',             'props' => ['color' => '#ef5350']],
                'tix-sel-price-old'     => ['label' => 'Preis (alt)',              'props' => ['color' => '#999999']],
                'tix-sel-vat'           => ['label' => 'MwSt.-Hinweis',            'props' => ['color' => '#999999']],
                'tix-sel-low-stock'     => ['label' => 'Wenige verfügbar',         'props' => ['color' => '#f59e0b']],
                'tix-sel-soldout-label' => ['label' => 'Ausverkauft-Label',        'props' => ['color' => '#ef5350']],
                'tix-sel-phase-badge'   => ['label' => 'Phasen-Badge',             'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-sel-phase-name'    => ['label' => 'Phasen-Name',              'props' => ['color' => '#aaaaaa']],
                'tix-sel-phase-price'   => ['label' => 'Phasen-Preis',             'props' => ['color' => '#ffffff']],
                'tix-sel-phase-until'   => ['label' => 'Gültig bis',               'props' => ['color' => '#999999']],
                'tix-sel-btn'           => ['label' => 'Ticket-Button',            'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-sel-buy'           => ['label' => 'Kaufen-Button',            'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-sel-charity-badge' => ['label' => 'Charity-Badge',            'props' => ['bg' => '#14B8A6', 'color' => '#ffffff']],
                'tix-sel-charity'       => ['label' => 'Charity-Box',              'props' => ['bg' => 'rgba(236,72,153,0.06)', 'border' => 'rgba(236,72,153,0.2)']],
                'tix-sel-charity-desc'  => ['label' => 'Charity-Text',             'props' => ['color' => '#ffffff']],
                'tix-sel-bundle-save'   => ['label' => 'Bundle-Ersparnis',         'props' => ['color' => '#c8ff00']],
                'tix-sel-bundle-applied'=> ['label' => 'Bundle aktiv',             'props' => ['color' => '#4caf50', 'bg' => 'rgba(76,175,80,0.1)']],
                'tix-sel-bundle-badge'  => ['label' => 'Bundle-Badge',             'props' => ['color' => '#ef5350']],
                'tix-sel-bundle-hint'   => ['label' => 'Bundle-Hinweis',           'props' => ['color' => '#ef5350']],
                'tix-sel-coupon-ok'     => ['label' => 'Gutschein gültig',         'props' => ['color' => '#4caf50']],
                'tix-sel-coupon-err'    => ['label' => 'Gutschein ungültig',       'props' => ['color' => '#ef5350']],
                'tix-sel-coupon-btn'    => ['label' => 'Gutschein-Button',         'props' => ['color' => '#ffffff']],
                'tix-sel-coupon-code'   => ['label' => 'Gutschein-Eingabe',        'props' => ['border' => '#333333']],
                'tix-sel-notify-btn'    => ['label' => 'Erinnern-Button',          'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-sel-notify-text'   => ['label' => 'Benachrichtigungs-Text',   'props' => ['color' => '#ffffff']],
                'tix-sel-notify-success'=> ['label' => 'Benachrichtigung OK',      'props' => ['color' => '#4caf50']],
                'tix-sel-presale-label' => ['label' => 'Vorverkauf-Label',         'props' => ['color' => '#ffffff']],
                'tix-sel-presale-timer' => ['label' => 'Vorverkauf-Timer',         'props' => ['color' => '#ffffff']],
                'tix-sel-express'       => ['label' => 'Express-Badge',            'props' => ['bg' => '#f59e0b', 'color' => '#ffffff']],
                'tix-sel-external-btn'  => ['label' => 'Externer-Button',          'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-sel-msg-success'   => ['label' => 'Erfolgs-Meldung',          'props' => ['color' => '#4caf50', 'bg' => 'rgba(46,125,50,0.15)']],
                'tix-sel-msg-error'     => ['label' => 'Fehler-Meldung',           'props' => ['color' => '#ef5350', 'bg' => 'rgba(211,47,47,0.15)']],
                'tix-sel-special-badge' => ['label' => 'Spezial-Badge',            'props' => ['color' => '#4caf50']],
                'tix-sel-group-discount'=> ['label' => 'Gruppenrabatt',            'props' => ['bg' => 'rgba(239,83,80,0.06)', 'border' => '#ef5350']],
                'tix-sel-gd-active'     => ['label' => 'Gruppenrabatt aktiv',      'props' => ['bg' => 'rgba(239,83,80,0.06)', 'border' => '#ef5350']],
                'tix-sel-gd-badge'      => ['label' => 'Gruppenrabatt-Badge',      'props' => ['bg' => '#ef5350', 'color' => '#ffffff']],
                'tix-group-create-btn'  => ['label' => 'Gruppe erstellen',         'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-group-checkout-btn'=> ['label' => 'Gruppen-Checkout',         'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-group-copy-btn'    => ['label' => 'Gruppen-Link kopieren',    'props' => ['color' => '#c8ff00', 'border' => '#c8ff00']],
                'tix-group-member-org'  => ['label' => 'Gruppen-Organisator',      'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-group-msg-success' => ['label' => 'Gruppen-Erfolg',           'props' => ['color' => '#4caf50', 'bg' => 'rgba(46,125,50,0.15)']],
                'tix-group-msg-error'   => ['label' => 'Gruppen-Fehler',           'props' => ['color' => '#ef5350', 'bg' => 'rgba(211,47,47,0.15)']],
                'tix-group-name-input'  => ['label' => 'Gruppen-Name-Eingabe',     'props' => ['border' => '#333333']],
                'tix-group-share-url'   => ['label' => 'Gruppen-URL',              'props' => ['border' => '#333333']],
            ],
            'Checkout' => [
                'tix-co'                    => ['label' => 'Checkout',                'props' => ['color' => '#ffffff']],
                'tix-co-heading'            => ['label' => 'Überschrift',             'props' => ['color' => '#ffffff', 'border' => '#333333']],
                'tix-co-label'              => ['label' => 'Label',                   'props' => ['color' => '#ffffff']],
                'tix-co-input'              => ['label' => 'Eingabefeld',             'props' => ['color' => '#ffffff', 'border' => '#333333', 'bg' => '#000000']],
                'tix-co-item'               => ['label' => 'Warenkorb-Artikel',       'props' => ['border' => '#333333', 'bg' => '#000000']],
                'tix-co-item-name'          => ['label' => 'Artikel-Name',            'props' => ['color' => '#ffffff']],
                'tix-co-item-price'         => ['label' => 'Artikel-Preis',           'props' => ['color' => '#ffffff']],
                'tix-co-item-price-sale'    => ['label' => 'Artikel-Preis (Sale)',    'props' => ['color' => '#ef5350']],
                'tix-co-link-btn'           => ['label' => 'Link-Button',             'props' => ['color' => '#c8ff00']],
                'tix-co-coupon-btn'         => ['label' => 'Gutschein-Button',        'props' => ['border' => '#333333', 'color' => '#ffffff']],
                'tix-co-coupon-tag'         => ['label' => 'Gutschein-Tag',           'props' => ['bg' => '#1a2e00', 'color' => '#c8ff00']],
                'tix-co-discount-row'       => ['label' => 'Rabatt-Zeile',            'props' => ['color' => '#4caf50']],
                'tix-co-summary-row'        => ['label' => 'Zusammenfassung Zeile',   'props' => ['color' => '#ffffff']],
                'tix-co-summary-total'      => ['label' => 'Zusammenfassung Gesamt',  'props' => ['color' => '#ffffff']],
                'tix-co-submit'             => ['label' => 'Bestellen-Button',        'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-co-btn-back'           => ['label' => 'Zurück-Button',           'props' => ['border' => '#ffffff', 'color' => '#ffffff']],
                'tix-co-qty-btn'            => ['label' => 'Mengen-Button',           'props' => ['color' => '#ffffff', 'border' => '#333333']],
                'tix-co-req'                => ['label' => 'Pflichtfeld-Stern',       'props' => ['color' => '#ef5350']],
                'tix-co-vat-note'           => ['label' => 'MwSt.-Hinweis',           'props' => ['color' => '#999999']],
                'tix-co-gw-title'           => ['label' => 'Gateway-Titel',           'props' => ['color' => '#ffffff']],
                'tix-co-step-num'           => ['label' => 'Schritt-Nummer',          'props' => ['color' => '#ffffff']],
                'tix-co-step-label'         => ['label' => 'Schritt-Label',           'props' => ['color' => '#ffffff']],
                'tix-co-msg-success'        => ['label' => 'Erfolgs-Meldung',         'props' => ['bg' => 'rgba(46,125,50,0.15)', 'color' => '#4caf50']],
                'tix-co-msg-error'          => ['label' => 'Fehler-Meldung',          'props' => ['bg' => 'rgba(211,47,47,0.15)', 'color' => '#ef5350']],
                'tix-co-ty-status-processing' => ['label' => 'Status: Bearbeitung',   'props' => ['bg' => 'rgba(76,175,80,0.15)', 'color' => '#4caf50']],
                'tix-co-ty-status-on-hold'  => ['label' => 'Status: Wartend',         'props' => ['bg' => 'rgba(255,152,0,0.15)', 'color' => '#ff9800']],
                'tix-co-ty-status-pending'  => ['label' => 'Status: Ausstehend',      'props' => ['bg' => 'rgba(158,158,158,0.15)', 'color' => '#9e9e9e']],
                'tix-co-ty-status-completed'=> ['label' => 'Status: Abgeschlossen',   'props' => ['bg' => 'rgba(76,175,80,0.15)', 'color' => '#4caf50']],
                'tix-co-ty-status-failed'   => ['label' => 'Status: Fehlgeschlagen',  'props' => ['bg' => 'rgba(239,83,80,0.15)', 'color' => '#ef5350']],
                'tix-co-ty-status-cancelled'=> ['label' => 'Status: Storniert',       'props' => ['bg' => 'rgba(239,83,80,0.15)', 'color' => '#ef5350']],
                'tix-co-login-msg'          => ['label' => 'Login-Meldung',            'props' => ['color' => '#ef5350']],
                'tix-co-coupon-msg'         => ['label' => 'Gutschein-Meldung',        'props' => ['color' => '#ef5350', 'bg' => 'rgba(211,47,47,0.12)']],
                'tix-co-btn-login'          => ['label' => 'Login-Button',             'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-co-login-section'      => ['label' => 'Login-Bereich',            'props' => ['border' => '#333333']],
                'tix-co-select'             => ['label' => 'Select-Feld',              'props' => ['border' => '#555555']],
                'tix-co-textarea'           => ['label' => 'Textarea',                 'props' => ['border' => '#555555']],
                'tix-co-field-error'        => ['label' => 'Feld-Fehler',              'props' => ['border' => '#ef5350']],
                'tix-co-check-custom'       => ['label' => 'Checkbox',                 'props' => ['border' => '#555555']],
                'tix-co-legal'              => ['label' => 'Rechtliches',              'props' => ['border' => '#ef5350']],
                'tix-co-newsletter'         => ['label' => 'Newsletter-Box',           'props' => ['border' => '#333333']],
                'tix-co-gateway'            => ['label' => 'Zahlungsart',              'props' => ['bg' => 'rgba(200,255,0,0.04)', 'border' => '#c8ff00']],
                'tix-co-gw-active'          => ['label' => 'Zahlungsart aktiv',        'props' => ['bg' => 'rgba(200,255,0,0.04)', 'border' => '#c8ff00']],
                'tix-co-gw-radio-custom'    => ['label' => 'Gateway-Radio',            'props' => ['border' => '#555555']],
                'tix-co-step-next'          => ['label' => 'Weiter-Button',            'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-co-step-back'          => ['label' => 'Schritt-Zurück',           'props' => ['border' => '#333333']],
                'tix-co-countdown'          => ['label' => 'Countdown-Box',            'props' => ['border' => '#333333']],
                'tix-co-countdown-bar'      => ['label' => 'Countdown-Balken',         'props' => ['bg' => '#ef5350']],
                'tix-co-countdown-track'    => ['label' => 'Countdown-Track',          'props' => ['bg' => 'rgba(255,255,255,0.08)']],
                'tix-co-countdown-warn'     => ['label' => 'Countdown-Warnung',        'props' => ['bg' => '#ff9800']],
                'tix-co-countdown-crit'     => ['label' => 'Countdown-Kritisch',       'props' => ['bg' => '#ef5350']],
                'tix-co-countdown-expired'  => ['label' => 'Countdown-Abgelaufen',     'props' => ['color' => '#ef5350']],
                'tix-co-combo-group'        => ['label' => 'Kombi-Gruppe',             'props' => ['border' => '#333333']],
                'tix-co-shipping-info'      => ['label' => 'Versand-Info',             'props' => ['border' => '#333333']],
                'tix-co-multi-event-notice' => ['label' => 'Multi-Event-Hinweis',      'props' => ['bg' => 'rgba(255,152,0,0.1)', 'border' => 'rgba(255,152,0,0.3)']],
                'tix-co-special-card'       => ['label' => 'Spezial-Karte',            'props' => ['border' => '#c8ff00']],
                'tix-co-special-add'        => ['label' => 'Spezial-Hinzufügen',      'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-co-special-in-cart'    => ['label' => 'Spezial im Warenkorb',     'props' => ['border' => '#c8ff00']],
                'tix-co-special-in-cart-badge' => ['label' => 'Spezial-Badge',         'props' => ['color' => '#4caf50']],
                'tix-co-special-savings'    => ['label' => 'Spezial-Ersparnis',        'props' => ['color' => '#4caf50']],
                'tix-co-table-card'         => ['label' => 'Tisch-Karte',              'props' => ['bg' => 'rgba(200,255,0,0.04)', 'border' => '#c8ff00']],
                'tix-co-table-active'       => ['label' => 'Tisch aktiv',              'props' => ['bg' => 'rgba(200,255,0,0.04)', 'border' => '#c8ff00']],
                'tix-co-table-radio-dot'    => ['label' => 'Tisch-Radio',              'props' => ['border' => '#555555']],
                'tix-co-table-avail'        => ['label' => 'Tisch verfügbar',          'props' => ['color' => '#4caf50']],
                'tix-co-table-status-ok'    => ['label' => 'Tisch-Status OK',          'props' => ['color' => '#4caf50', 'bg' => 'rgba(76,175,80,0.1)']],
                'tix-co-table-status-err'   => ['label' => 'Tisch-Status Fehler',      'props' => ['color' => '#ef5350', 'bg' => 'rgba(239,83,80,0.1)']],
                'tix-co-ty-icon'            => ['label' => 'Danke-Icon',               'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-co-ty-ticket'          => ['label' => 'Danke-Ticket',             'props' => ['border' => '#333333']],
                'tix-co-ty-ticket-dl'       => ['label' => 'Ticket-Download',          'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-co-ty-tickets-note'    => ['label' => 'Tickets-Hinweis',          'props' => ['border' => '#333333']],
                'tix-co-ty-payment-notice'  => ['label' => 'Zahlungs-Hinweis',         'props' => ['border' => '#e5e7eb']],
                'tix-co-ty-charity'         => ['label' => 'Danke-Charity',            'props' => ['bg' => 'rgba(236,72,153,0.06)', 'border' => 'rgba(236,72,153,0.2)']],
                'tix-co-ty-charity-badge'   => ['label' => 'Danke-Charity-Badge',      'props' => ['color' => '#be185d']],
                'tix-co-btn-more'           => ['label' => 'Mehr-Button',              'props' => ['color' => '#ffffff']],
                'tix-co-cost-split'         => ['label' => 'Kosten-Aufteiler',         'props' => ['bg' => '#ffffff', 'border' => '#e5e7eb']],
                'tix-co-sponsor-inner'      => ['label' => 'Sponsor-Box',              'props' => ['border' => '#e2e8f0']],
                'tix-co-terms'              => ['label' => 'AGB-Fehler',               'props' => ['bg' => 'rgba(239,68,68,0.04)', 'border' => '#ef4444']],
            ],
            'Check-In' => [
                'tix-ci'            => ['label' => 'Check-In',         'props' => ['bg' => '#f7f8fa', 'color' => '#0D0B09']],
                'tix-ci-select'     => ['label' => 'Select-Feld',     'props' => ['bg' => '#ffffff', 'border' => '#e8eaed', 'color' => '#0D0B09']],
                'tix-ci-input'      => ['label' => 'Eingabefeld',     'props' => ['bg' => '#ffffff', 'border' => '#e8eaed', 'color' => '#0D0B09']],
                'tix-ci-btn'        => ['label' => 'Button',           'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-ci-result-ok'  => ['label' => 'Ergebnis OK',     'props' => ['border' => '#10b981', 'bg' => '#e6f9f1']],
                'tix-ci-result-warn'=> ['label' => 'Ergebnis Warnung','props' => ['border' => '#f59e0b', 'bg' => '#fef6e6']],
                'tix-ci-result-err' => ['label' => 'Ergebnis Fehler', 'props' => ['border' => '#ef4444', 'bg' => '#fde8e8']],
                'tix-ci-guest-name'     => ['label' => 'Gast-Name',          'props' => ['color' => '#0D0B09']],
                'tix-ci-guest-plus'     => ['label' => 'Plus-Eins',           'props' => ['color' => '#FF5500', 'bg' => 'rgba(255,85,0,0.1)']],
                'tix-ci-guest-note'     => ['label' => 'Gast-Notiz',          'props' => ['color' => '#94a3b8']],
                'tix-ci-guest-status-ok'=> ['label' => 'Gast-Status OK',      'props' => ['color' => '#10b981']],
                'tix-ci-guest-checkin'  => ['label' => 'Einchecken-Btn',      'props' => ['color' => '#ffffff']],
                'tix-ci-guest-edit'     => ['label' => 'Gast-Bearbeiten',     'props' => ['color' => '#94a3b8']],
                'tix-ci-badge'          => ['label' => 'Check-In Badge',      'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-ci-badge-guest'    => ['label' => 'Gäste-Badge',        'props' => ['color' => '#10b981', 'bg' => 'rgba(16,185,129,0.1)']],
                'tix-ci-badge-ticket'   => ['label' => 'Ticket-Badge',        'props' => ['color' => '#FF5500', 'bg' => 'rgba(255,85,0,0.1)']],
                'tix-ci-title'          => ['label' => 'Titel',               'props' => ['color' => '#94a3b8']],
                'tix-ci-counter-btn'    => ['label' => 'Counter-Button',      'props' => ['color' => '#0D0B09']],
                'tix-ci-counter-val'    => ['label' => 'Counter-Wert',        'props' => ['color' => '#0D0B09']],
                'tix-ci-filter-btn'     => ['label' => 'Filter-Button',       'props' => ['color' => '#ffffff']],
                'tix-ci-pw-error'       => ['label' => 'Passwort-Fehler',     'props' => ['color' => '#ef4444']],
                'tix-ci-result-details' => ['label' => 'Ergebnis-Details',    'props' => ['color' => '#94a3b8']],
                'tix-ci-empty'          => ['label' => 'Leer-Text',           'props' => ['color' => '#94a3b8']],
                'tix-ci-ticket-checkin' => ['label' => 'Ticket-Einchecken',   'props' => ['color' => '#ffffff']],
                'tix-ci-ticket-toggle'  => ['label' => 'Ticket-Toggle',       'props' => ['color' => '#94a3b8']],
                'tix-ci-camera-wrap'    => ['label' => 'Kamera-Box',          'props' => ['bg' => '#0D0B09']],
            ],
            'My Tickets' => [
                'tix-mt'               => ['label' => 'Meine Tickets',           'props' => ['bg' => '#ffffff', 'color' => '#1a1a1a']],
                'tix-mt-section-title' => ['label' => 'Sektionstitel',           'props' => ['color' => '#1a1a1a', 'border' => '#e5e7eb']],
                'tix-mt-card'          => ['label' => 'Karte',                   'props' => ['border' => '#e5e7eb', 'bg' => '#ffffff']],
                'tix-mt-ticket'        => ['label' => 'Ticket',                  'props' => ['border' => '#e5e7eb', 'bg' => '#f8fdf0']],
                'tix-mt-action-btn'    => ['label' => 'Aktions-Button',          'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-mt-event-badge'       => ['label' => 'Event-Badge',             'props' => ['bg' => '#e5e7eb', 'color' => '#1a1a1a']],
                'tix-mt-event-cancelled'   => ['label' => 'Event abgesagt',          'props' => ['color' => '#ef5350', 'bg' => 'rgba(239,83,80,0.12)']],
                'tix-mt-event-postponed'   => ['label' => 'Event verschoben',        'props' => ['color' => '#ab47bc', 'bg' => 'rgba(123,31,162,0.12)']],
                'tix-mt-card-title'        => ['label' => 'Karten-Titel',            'props' => ['color' => '#1a1a1a']],
                'tix-mt-card-chevron'      => ['label' => 'Karten-Pfeil',            'props' => ['color' => '#1a1a1a']],
                'tix-mt-meta-item'         => ['label' => 'Meta-Info',               'props' => ['color' => '#666666']],
                'tix-mt-ticket-qty'        => ['label' => 'Ticket-Menge',            'props' => ['color' => '#666666']],
                'tix-mt-tcard-dl'          => ['label' => 'Download-Link',           'props' => ['color' => '#c8ff00']],
                'tix-mt-tcard-save'        => ['label' => 'Ticket-Speichern',        'props' => ['color' => '#1a1a1a']],
                'tix-mt-tcard-share'       => ['label' => 'Ticket-Teilen',           'props' => ['color' => '#1a1a1a']],
                'tix-mt-order-ref'         => ['label' => 'Bestell-Nr',              'props' => ['color' => '#666666']],
                'tix-mt-status-ok'         => ['label' => 'Status OK',               'props' => ['color' => '#4caf50', 'bg' => 'rgba(76,175,80,0.12)']],
                'tix-mt-status-wait'       => ['label' => 'Status Wartend',          'props' => ['color' => '#888888', 'bg' => 'rgba(158,158,158,0.12)']],
                'tix-mt-status-warn'       => ['label' => 'Status Warnung',          'props' => ['color' => '#e68900', 'bg' => 'rgba(255,152,0,0.12)']],
                'tix-mt-pending-notice'    => ['label' => 'Pending-Hinweis',         'props' => ['color' => '#b37400', 'bg' => 'rgba(255,152,0,0.08)', 'border' => 'rgba(255,152,0,0.2)']],
                'tix-mt-pending-info'      => ['label' => 'Pending-Info',            'props' => ['color' => '#666666', 'bg' => 'rgba(0,0,0,0.025)']],
                'tix-mt-bank-ref-value'    => ['label' => 'Bank-Referenz',           'props' => ['color' => '#1a1a1a']],
                'tix-mt-login-text'        => ['label' => 'Login-Text',              'props' => ['color' => '#666666']],
                'tix-mt-empty-text'        => ['label' => 'Leer-Text',               'props' => ['color' => '#666666']],
                'tix-mt-combo-badge'       => ['label' => 'Kombi-Badge',             'props' => ['color' => '#ffffff']],
                'tix-mt-combo-event-name'  => ['label' => 'Kombi-Event',             'props' => ['color' => '#1a1a1a']],
                'tix-mt-combo-event-meta'  => ['label' => 'Kombi-Meta',              'props' => ['color' => '#666666']],
                'tix-mt-combo-price'       => ['label' => 'Kombi-Preis',             'props' => ['color' => '#666666']],
            ],
            'Event Cards' => [
                'ev'                    => ['label' => 'Event-Karte',        'props' => ['bg' => '#ffffff', 'border' => '#F0ECE4']],
                'ev-badge-cat'          => ['label' => 'Kategorie-Badge',    'props' => ['bg' => '#000000', 'color' => '#ffffff']],
                'ev-save'               => ['label' => 'Merken-Button',      'props' => ['bg' => '#ffffff', 'color' => '#333333']],
                'ev-date'               => ['label' => 'Datum',              'props' => ['color' => '#E8445A']],
                'ev-title'              => ['label' => 'Titel',              'props' => ['color' => '#1f2937']],
                'ev-loc'                => ['label' => 'Ort',                'props' => ['color' => '#3A3937']],
                'ev-price'              => ['label' => 'Preis',              'props' => ['color' => '#1f2937']],
                'ev-price-free'         => ['label' => 'Preis (Kostenlos)',  'props' => ['color' => '#14B8A6']],
                'ev-btn'                => ['label' => 'Button',             'props' => ['bg' => '#E8445A', 'color' => '#ffffff']],
                'ev-btn-teal'           => ['label' => 'Button (Teal)',      'props' => ['bg' => '#14B8A6', 'color' => '#ffffff']],
                'ev-btn-outline'        => ['label' => 'Button (Outline)',   'props' => ['border' => '#E3DED4', 'color' => '#1f2937']],
                'ev-btn-disabled'       => ['label' => 'Button (Deaktiviert)','props' => ['bg' => '#8C8985']],
                'section-label'         => ['label' => 'Sektions-Label',     'props' => ['color' => '#E8445A']],
                'section-link'          => ['label' => 'Sektions-Link',      'props' => ['color' => '#E8445A']],
                'tix-search-input-wrap' => ['label' => 'Suchfeld',          'props' => ['bg' => '#ffffff', 'border' => '#E0ECE4']],
                'tix-search-results'    => ['label' => 'Suchergebnisse',    'props' => ['bg' => '#ffffff', 'border' => '#E0ECE4']],
                'tix-search-item'       => ['label' => 'Suchergebnis',       'props' => ['border' => 'rgba(0,0,0,0.04)']],
                'tix-search-item-meta'  => ['label' => 'Such-Meta',          'props' => ['color' => '#8C8985']],
                'tix-search-item-price' => ['label' => 'Such-Preis',         'props' => ['color' => '#E8445A']],
                'tix-search-item-img'   => ['label' => 'Such-Bild',          'props' => ['bg' => '#F0ECE4']],
                'tix-search-empty'      => ['label' => 'Suche leer',         'props' => ['color' => '#8C8985']],
                'tix-search-clear'      => ['label' => 'Suche löschen',     'props' => ['color' => '#999999']],
                'tix-search-all'        => ['label' => 'Alle anzeigen',      'props' => ['color' => '#E8445A']],
            ],
            'Event Page' => [
                'tix-ep'                => ['label' => 'Event-Seite',            'props' => ['color' => '#1f2937']],
                'tix-ep-header'         => ['label' => 'Header',                 'props' => ['bg' => '#FAF8F4']],
                'tix-ep-title'          => ['label' => 'Seiten-Titel',           'props' => ['color' => '#1f2937']],
                'tix-ep-hero-badge'     => ['label' => 'Hero-Badge',             'props' => ['bg' => 'rgba(0,0,0,0.55)', 'color' => '#ffffff']],
                'tix-ep-hero-badge--sold_out'    => ['label' => 'Hero: Ausverkauft',   'props' => ['bg' => 'rgba(220,38,38,0.85)']],
                'tix-ep-hero-badge--cancelled'   => ['label' => 'Hero: Abgesagt',      'props' => ['bg' => 'rgba(107,114,128,0.85)']],
                'tix-ep-hero-badge--postponed'   => ['label' => 'Hero: Verschoben',    'props' => ['bg' => 'rgba(234,179,8,0.85)', 'color' => '#1a1a1a']],
                'tix-ep-hero-badge--few_tickets' => ['label' => 'Hero: Wenige',        'props' => ['bg' => 'rgba(234,88,12,0.85)']],
                'tix-ep-status'         => ['label' => 'Status-Badge',           'props' => ['color' => '#ffffff']],
                'tix-ep-status--sold_out'    => ['label' => 'Status: Ausverkauft',   'props' => ['bg' => '#dc2626']],
                'tix-ep-status--cancelled'   => ['label' => 'Status: Abgesagt',      'props' => ['bg' => '#6b7280']],
                'tix-ep-status--postponed'   => ['label' => 'Status: Verschoben',    'props' => ['bg' => '#eab308', 'color' => '#1a1a1a']],
                'tix-ep-status--few_tickets' => ['label' => 'Status: Wenige Tickets','props' => ['bg' => '#ea580c']],
                'tix-ep-status--past'        => ['label' => 'Status: Vergangen',     'props' => ['bg' => '#9ca3af']],
                'tix-ep-meta-row'       => ['label' => 'Meta-Zeile',             'props' => ['bg' => '#FAF8F4', 'border' => '#e5e7eb']],
                'tix-ep-meta-label'     => ['label' => 'Meta-Label',             'props' => ['color' => '#64748b']],
                'tix-ep-meta-value'     => ['label' => 'Meta-Wert',              'props' => ['color' => '#1f2937']],
                'tix-ep-meta-sub'       => ['label' => 'Meta-Zusatz',            'props' => ['color' => '#64748b']],
                'tix-ep-price-badge'    => ['label' => 'Preis-Badge',            'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-ep-age-badge'      => ['label' => 'Alter-Badge',            'props' => ['bg' => '#f1f5f9', 'color' => '#64748b']],
                'tix-ep-section-title'  => ['label' => 'Abschnitts-Titel',       'props' => ['color' => '#1f2937', 'border' => '#e5e7eb']],
                'tix-ep-section-body'   => ['label' => 'Abschnitts-Text',        'props' => ['color' => '#374151']],
                'tix-ep-location-name'  => ['label' => 'Ort-Name',               'props' => ['color' => '#1f2937']],
                'tix-ep-location-address'=> ['label' => 'Adresse',               'props' => ['color' => '#64748b']],
                'tix-ep-organizer-name' => ['label' => 'Veranstalter',           'props' => ['color' => '#1f2937']],
                'tix-ep-organizer-label'=> ['label' => 'Veranstalter-Label',     'props' => ['color' => '#64748b']],
                'tix-ep-series-status--available'   => ['label' => 'Serie: Verfügbar',    'props' => ['bg' => '#dcfce7', 'color' => '#166534']],
                'tix-ep-series-status--sold_out'    => ['label' => 'Serie: Ausverkauft',  'props' => ['bg' => '#fee2e2', 'color' => '#991b1b']],
                'tix-ep-series-status--cancelled'   => ['label' => 'Serie: Abgesagt',     'props' => ['bg' => '#f3f4f6', 'color' => '#6b7280']],
                'tix-ep-series-status--few_tickets' => ['label' => 'Serie: Wenige',       'props' => ['bg' => '#fff7ed', 'color' => '#9a3412']],
                'tix-ep-rating'         => ['label' => 'Rating',                  'props' => ['color' => '#cbd5e1']],
                'tix-ep-meta-icon'      => ['label' => 'Meta-Icon',              'props' => ['color' => '#64748b']],
                'tix-ep-location-card'  => ['label' => 'Ort-Karte',              'props' => ['bg' => '#FAF8F4']],
                'tix-ep-location-icon'  => ['label' => 'Ort-Icon',               'props' => ['color' => '#000000']],
                'tix-ep-location-arrow' => ['label' => 'Ort-Pfeil',              'props' => ['color' => '#cbd5e1']],
                'tix-ep-organizer-card' => ['label' => 'Veranstalter-Karte',     'props' => ['bg' => '#FAF8F4']],
                'tix-ep-organizer-avatar'=> ['label' => 'Veranstalter-Avatar',   'props' => ['bg' => '#EDE9E0']],
                'tix-ep-gallery-item'   => ['label' => 'Galerie-Bild',           'props' => ['bg' => '#f1f5f9']],
                'tix-ep-lightbox'       => ['label' => 'Lightbox',               'props' => ['bg' => 'rgba(0,0,0,0.92)']],
                'tix-ep-lightbox-close' => ['label' => 'Lightbox-Schließen',    'props' => ['color' => '#ffffff', 'bg' => 'rgba(255,255,255,0.15)']],
                'tix-ep-lightbox-nav'   => ['label' => 'Lightbox-Navigation',    'props' => ['color' => '#ffffff', 'bg' => 'rgba(255,255,255,0.15)']],
                'tix-ep-lightbox-counter'=> ['label' => 'Lightbox-Zähler',      'props' => ['color' => 'rgba(255,255,255,0.7)']],
                'tix-ep-video-wrap'     => ['label' => 'Video-Box',              'props' => ['bg' => '#000000']],
                'tix-ep-charity-card'   => ['label' => 'Charity-Karte',          'props' => ['bg' => '#fdf2f8', 'border' => '#f9a8d4']],
                'tix-ep-charity-desc'   => ['label' => 'Charity-Text',           'props' => ['color' => '#9d174d']],
                'tix-ep-charity-percent'=> ['label' => 'Charity-Prozent',        'props' => ['color' => '#be185d']],
                'tix-ep-series-item'    => ['label' => 'Serien-Eintrag',         'props' => ['bg' => '#FAF8F4']],
                'tix-ep-series-item--current' => ['label' => 'Serien-Eintrag aktuell', 'props' => ['bg' => 'rgba(200,255,0,0.06)']],
                'tix-ep-series-month'   => ['label' => 'Serien-Monat',           'props' => ['color' => '#64748b']],
                'tix-ep-series-time'    => ['label' => 'Serien-Zeit',            'props' => ['color' => '#64748b']],
                'tix-ep-series-status--postponed' => ['label' => 'Serie: Verschoben', 'props' => ['bg' => '#fef9c3', 'color' => '#854d0e']],
            ],
            'Express Modal' => [
                'tix-ec-modal'          => ['label' => 'Modal',                  'props' => ['bg' => '#1a1a1a', 'color' => '#ffffff', 'border' => '#333333']],
                'tix-ec-trigger-btn'    => ['label' => 'Trigger-Button',         'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-ec-cat'            => ['label' => 'Kategorie',              'props' => ['border' => '#333333']],
                'tix-ec-cat-name'       => ['label' => 'Kategorie-Name',         'props' => ['color' => '#ffffff']],
                'tix-ec-price-sale'     => ['label' => 'Sale-Preis',             'props' => ['color' => '#ef5350']],
                'tix-ec-total-price'    => ['label' => 'Gesamtpreis',            'props' => ['color' => '#ffffff']],
                'tix-ec-buy'            => ['label' => 'Kaufen-Button',          'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-ec-note'           => ['label' => 'Hinweis',                'props' => ['color' => '#aaaaaa']],
                'tix-ec-vat'            => ['label' => 'MwSt.',                  'props' => ['color' => '#999999']],
                'tix-ec-message'        => ['label' => 'Meldung',                'props' => ['color' => '#ffffff']],
                'tix-ec-offer-heading'  => ['label' => 'Angebots-Titel',         'props' => ['color' => '#ffffff']],
                'tix-ec-offer-save'     => ['label' => 'Angebots-Ersparnis',     'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-ec-close'          => ['label' => 'Schließen-Button',      'props' => ['border' => '#333333']],
                'tix-ec-btn'            => ['label' => 'Mengen-Button',          'props' => ['border' => '#333333']],
                'tix-ec-overlay'        => ['label' => 'Overlay',                'props' => ['bg' => 'rgba(0,0,0,0.6)']],
                'tix-ec-terms-custom'   => ['label' => 'AGB-Checkbox',           'props' => ['border' => '#555555']],
                'tix-ec-offers-btn'     => ['label' => 'Angebote-Button',        'props' => ['border' => '#333333']],
                'tix-ec-offers-icon'    => ['label' => 'Angebote-Icon',          'props' => ['bg' => '#333333']],
                'tix-ec-msg-success'    => ['label' => 'Erfolgs-Meldung',        'props' => ['color' => '#4caf50', 'bg' => 'rgba(76,175,80,0.15)']],
                'tix-ec-msg-error'      => ['label' => 'Fehler-Meldung',         'props' => ['color' => '#ef5350', 'bg' => 'rgba(239,83,80,0.15)']],
                'tix-ec-gd-active'      => ['label' => 'Gruppenrabatt aktiv',    'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-ec-gd-badge'       => ['label' => 'Gruppenrabatt-Badge',    'props' => ['color' => '#4caf50', 'bg' => 'rgba(76,175,80,0.15)']],
            ],
            'Modal Checkout' => [
                'tix-mc-modal'          => ['label' => 'Modal',                  'props' => ['bg' => '#ffffff', 'color' => '#0D0B09', 'border' => '#EDE9E0']],
                'tix-mc-title'          => ['label' => 'Titel',                  'props' => ['color' => '#0D0B09']],
                'tix-mc-event-name'     => ['label' => 'Event-Name',             'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-event-date'     => ['label' => 'Event-Datum',            'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-cat'            => ['label' => 'Kategorie',              'props' => ['border' => '#EDE9E0']],
                'tix-mc-cat-name'       => ['label' => 'Kategorie-Name',         'props' => ['color' => '#0D0B09']],
                'tix-mc-cat-desc'       => ['label' => 'Kategorie-Beschreibung', 'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-cat-price'      => ['label' => 'Kategorie-Preis',        'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tix-mc-price-sale'     => ['label' => 'Sale-Preis',             'props' => ['color' => '#E53B3B']],
                'tix-mc-total-price'    => ['label' => 'Gesamtpreis',            'props' => ['color' => '#0D0B09']],
                'tix-mc-next'           => ['label' => 'Weiter-Button',          'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-mc-back'           => ['label' => 'Zurück-Button',          'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tix-mc-section-heading'=> ['label' => 'Abschnitts-Titel',       'props' => ['color' => '#0D0B09']],
                'tix-mc-label'          => ['label' => 'Feld-Label',             'props' => ['color' => '#0D0B09']],
                'tix-mc-input'          => ['label' => 'Eingabefeld',            'props' => ['border' => '#EDE9E0', 'color' => '#0D0B09']],
                'tix-mc-summary-total'  => ['label' => 'Gesamt',                 'props' => ['color' => '#0D0B09']],
                'tix-mc-submit'         => ['label' => 'Bestellen-Button',       'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-mc-vat-note'       => ['label' => 'MwSt.-Hinweis',          'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-trigger-btn'    => ['label' => 'Trigger-Button',         'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-mc-overlay'        => ['label' => 'Overlay',                'props' => ['bg' => 'rgba(0,0,0,0.55)']],
                'tix-mc-close'          => ['label' => 'Schließen-Button',      'props' => ['color' => 'rgba(13,11,9,0.40)', 'border' => '#EDE9E0']],
                'tix-mc-btn'            => ['label' => 'Mengen-Button',          'props' => ['color' => '#0D0B09', 'border' => '#EDE9E0']],
                'tix-mc-total-label'    => ['label' => 'Gesamt-Label',           'props' => ['color' => '#0D0B09']],
                'tix-mc-gw-title'       => ['label' => 'Zahlungsart-Name',       'props' => ['color' => '#0D0B09']],
                'tix-mc-gw-desc'        => ['label' => 'Zahlungsart-Info',       'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-gw-active'      => ['label' => 'Zahlungsart aktiv',      'props' => ['bg' => 'rgba(255,85,0,0.04)']],
                'tix-mc-gw-radio-custom'=> ['label' => 'Gateway-Radio',          'props' => ['border' => '#EDE9E0']],
                'tix-mc-gateway'        => ['label' => 'Zahlungsart',            'props' => ['bg' => 'rgba(255,85,0,0.04)', 'border' => '#FF5500']],
                'tix-mc-select'         => ['label' => 'Select-Feld',            'props' => ['color' => '#0D0B09', 'bg' => '#ffffff', 'border' => '#EDE9E0']],
                'tix-mc-check-custom'   => ['label' => 'Checkbox',               'props' => ['border' => '#EDE9E0']],
                'tix-mc-check-label'    => ['label' => 'Checkbox-Label',          'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tix-mc-req'            => ['label' => 'Pflichtfeld-Stern',      'props' => ['color' => '#E53B3B']],
                'tix-mc-field-error'    => ['label' => 'Feld-Fehler',            'props' => ['border' => '#E53B3B']],
                'tix-mc-legal'          => ['label' => 'Rechtliches',            'props' => ['border' => '#EDE9E0']],
                'tix-mc-legal-heading'  => ['label' => 'Rechtliches-Titel',      'props' => ['color' => '#0D0B09']],
                'tix-mc-legal-note'     => ['label' => 'Rechtliches-Hinweis',    'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-link-btn'       => ['label' => 'Link-Button',            'props' => ['color' => '#FF5500']],
                'tix-mc-login-msg'      => ['label' => 'Login-Meldung',          'props' => ['color' => '#E53B3B']],
                'tix-mc-login-section'  => ['label' => 'Login-Bereich',          'props' => ['border' => '#EDE9E0']],
                'tix-mc-login-toggle'   => ['label' => 'Login-Toggle',           'props' => ['color' => '#0D0B09']],
                'tix-mc-btn-login'      => ['label' => 'Login-Button',           'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-mc-coupon-code'    => ['label' => 'Gutschein-Eingabe',      'props' => ['color' => '#0D0B09', 'bg' => '#ffffff', 'border' => '#EDE9E0']],
                'tix-mc-coupon-btn'     => ['label' => 'Gutschein-Button',       'props' => ['color' => '#0D0B09', 'border' => '#EDE9E0']],
                'tix-mc-coupon-result'  => ['label' => 'Gutschein-Ergebnis',     'props' => ['color' => '#E53B3B', 'bg' => 'rgba(229,59,59,0.1)']],
                'tix-mc-msg-success'    => ['label' => 'Erfolgs-Meldung',        'props' => ['color' => '#1DB86A', 'bg' => 'rgba(29,184,106,0.1)']],
                'tix-mc-msg-error'      => ['label' => 'Fehler-Meldung',         'props' => ['color' => '#E53B3B', 'bg' => 'rgba(229,59,59,0.1)']],
                'tix-mc-checkout-loading'=> ['label' => 'Lade-Text',             'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-section-header' => ['label' => 'Sektions-Header',        'props' => ['color' => 'rgba(13,11,9,0.50)']],
                'tix-mc-offer-heading'  => ['label' => 'Angebots-Titel',         'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tix-mc-offer-desc'     => ['label' => 'Angebots-Text',          'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-offer-save'     => ['label' => 'Angebots-Ersparnis',     'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-mc-offers-btn'     => ['label' => 'Angebote-Button',        'props' => ['color' => 'rgba(13,11,9,0.70)', 'border' => '#EDE9E0']],
                'tix-mc-offers-icon'    => ['label' => 'Angebote-Icon',          'props' => ['color' => '#0D0B09', 'bg' => '#F3F0EA']],
                'tix-mc-gd-active'      => ['label' => 'Gruppenrabatt aktiv',    'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-mc-gd-badge'       => ['label' => 'Gruppenrabatt-Badge',    'props' => ['color' => '#1DB86A', 'bg' => 'rgba(29,184,106,0.12)']],
                'tix-mc-gd-tier'        => ['label' => 'Gruppenrabatt-Stufe',    'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tix-mc-special-badge'  => ['label' => 'Spezial-Badge',          'props' => ['color' => '#16a34a']],
                'tix-mc-summary-row'    => ['label' => 'Zusammenfassung',        'props' => ['color' => '#0D0B09']],
                'tix-mc-table-title'    => ['label' => 'Tisch-Titel',            'props' => ['color' => '#0D0B09']],
                'tix-mc-table-subtitle' => ['label' => 'Tisch-Untertitel',       'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-table-info'     => ['label' => 'Tisch-Info',             'props' => ['color' => 'rgba(13,11,9,0.70)', 'bg' => '#F3F0EA', 'border' => '#EDE9E0']],
                'tix-mc-table-cat'      => ['label' => 'Tisch-Kategorie',        'props' => ['border' => '#EDE9E0']],
                'tix-mc-table-cat-active'=> ['label' => 'Tisch-Kategorie aktiv', 'props' => ['border' => '#FF5500']],
                'tix-mc-table-cat-name' => ['label' => 'Tisch-Kategorie-Name',   'props' => ['color' => '#0D0B09']],
                'tix-mc-table-cat-desc' => ['label' => 'Tisch-Kategorie-Beschr.','props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-table-cat-meta' => ['label' => 'Tisch-Kategorie-Meta',   'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-table-cat-price'=> ['label' => 'Tisch-Kategorie-Preis',  'props' => ['color' => '#0D0B09']],
                'tix-mc-table-cat-price-label' => ['label' => 'Tisch-Preis-Label','props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tix-mc-table-cat-avail'=> ['label' => 'Tisch verfügbar',        'props' => ['color' => '#1DB86A']],
                'tix-mc-table-cat-avail-out' => ['label' => 'Tisch nicht verfügbar', 'props' => ['color' => '#E53B3B']],
                'tix-mc-table-label'    => ['label' => 'Tisch-Label',            'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tix-mc-table-guest-val'=> ['label' => 'Tisch-Gäste-Wert',     'props' => ['color' => '#0D0B09']],
                'tix-mc-table-selected-info' => ['label' => 'Tisch-Auswahl-Info','props' => ['color' => '#0D0B09']],
                'tix-mc-table-comments-input' => ['label' => 'Tisch-Kommentar', 'props' => ['color' => '#0D0B09', 'bg' => '#ffffff', 'border' => '#EDE9E0']],
                'tix-mc-table-confirm'  => ['label' => 'Tisch-Bestätigen',      'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-mc-table-skip'     => ['label' => 'Tisch-Überspringen',    'props' => ['color' => 'rgba(13,11,9,0.70)', 'border' => '#EDE9E0']],
            ],
            'Table Reservation' => [
                'tr-header-title'       => ['label' => 'Header-Titel',           'props' => ['color' => '#0D0B09']],
                'tr-back-btn'           => ['label' => 'Zurück-Button',          'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-calendar-month'     => ['label' => 'Kalender-Monat',         'props' => ['color' => '#0D0B09']],
                'tr-weekday-header'     => ['label' => 'Wochentag',              'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-day-number'         => ['label' => 'Tag-Nummer',             'props' => ['color' => '#0D0B09']],
                'tr-event-title'        => ['label' => 'Event-Titel',            'props' => ['color' => '#0D0B09']],
                'tr-event-meta'         => ['label' => 'Event-Meta',             'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-info-text'          => ['label' => 'Info-Text',              'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tr-categories-title'   => ['label' => 'Kategorien-Titel',       'props' => ['color' => '#0D0B09']],
                'tr-cat-name'           => ['label' => 'Kategorie-Name',         'props' => ['color' => '#0D0B09']],
                'tr-cat-desc'           => ['label' => 'Kategorie-Beschreibung', 'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-cat-price'          => ['label' => 'Kategorie-Preis',        'props' => ['color' => '#0D0B09']],
                'tr-cat-avail'          => ['label' => 'Verfügbarkeit',          'props' => ['color' => '#1DB86A']],
                'tr-form-summary-event' => ['label' => 'Formular-Event',         'props' => ['color' => '#0D0B09']],
                'tr-form-label'         => ['label' => 'Formular-Label',         'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tr-form-input'         => ['label' => 'Formular-Eingabe',       'props' => ['border' => '#EDE9E0', 'color' => '#0D0B09']],
                'tr-price-total'        => ['label' => 'Gesamt-Preis',           'props' => ['color' => '#0D0B09']],
                'tr-price-note'         => ['label' => 'Preis-Hinweis',          'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-success-title'      => ['label' => 'Erfolgs-Titel',          'props' => ['color' => '#0D0B09']],
                'tr-success-text'       => ['label' => 'Erfolgs-Text',           'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tr-success-details'    => ['label' => 'Erfolgs-Details',        'props' => ['bg' => '#F3F0EA', 'border' => '#EDE9E0']],
                'tr-success-row-label'  => ['label' => 'Erfolgs-Label',          'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-success-row-value'  => ['label' => 'Erfolgs-Wert',           'props' => ['color' => '#0D0B09']],
                'tr-calendar-grid'      => ['label' => 'Kalender-Grid',          'props' => ['bg' => '#EDE9E0']],
                'tr-calendar-nav'       => ['label' => 'Kalender-Navigation',    'props' => ['bg' => '#F3F0EA']],
                'tr-day-cell'           => ['label' => 'Tag-Zelle',              'props' => ['bg' => 'rgba(255,85,0,0.04)']],
                'tr-day-event-title'    => ['label' => 'Tages-Event',            'props' => ['color' => '#0D0B09']],
                'tr-no-events'          => ['label' => 'Keine Events',           'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-cat-card'           => ['label' => 'Kategorie-Karte',        'props' => ['bg' => '#ffffff', 'border' => '#EDE9E0']],
                'tr-cat-badge'          => ['label' => 'Kategorie-Badge',        'props' => ['color' => 'rgba(13,11,9,0.70)', 'bg' => '#F3F0EA', 'border' => '#EDE9E0']],
                'tr-cat-badge-accent'   => ['label' => 'Badge Akzent',           'props' => ['color' => '#FF5500', 'bg' => 'rgba(255,85,0,0.08)']],
                'tr-cat-arrow'          => ['label' => 'Kategorie-Pfeil',        'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-few'                => ['label' => 'Wenig verfügbar',        'props' => ['color' => '#f59e0b']],
                'tr-error'              => ['label' => 'Fehler',                 'props' => ['color' => '#E53B3B', 'bg' => 'rgba(229,59,59,0.08)', 'border' => 'rgba(229,59,59,0.2)']],
                'tr-empty'              => ['label' => 'Leer',                   'props' => ['bg' => '#F3F0EA']],
                'tr-loading'            => ['label' => 'Laden',                  'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-form-summary'       => ['label' => 'Zusammenfassung',        'props' => ['bg' => '#F3F0EA', 'border' => '#EDE9E0']],
                'tr-form-summary-title' => ['label' => 'Zusammenfassung-Titel',  'props' => ['color' => '#0D0B09']],
                'tr-form-summary-meta'  => ['label' => 'Zusammenfassung-Meta',   'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tr-form-summary-cat'   => ['label' => 'Zusammenfassung-Kategorie','props' => ['color' => '#FF5500']],
                'tr-form-select'        => ['label' => 'Formular-Select',        'props' => ['color' => '#0D0B09', 'bg' => '#ffffff', 'border' => '#EDE9E0']],
                'tr-form-textarea'      => ['label' => 'Formular-Textarea',      'props' => ['color' => '#0D0B09', 'bg' => '#ffffff', 'border' => '#EDE9E0']],
                'tr-price-row'          => ['label' => 'Preis-Zeile',            'props' => ['color' => 'rgba(13,11,9,0.70)']],
                'tr-modal-overlay'      => ['label' => 'Modal-Overlay',          'props' => ['bg' => 'rgba(0,0,0,0.55)']],
                'tr-modal-content'      => ['label' => 'Modal-Inhalt',           'props' => ['bg' => '#ffffff', 'border' => '#EDE9E0']],
                'tr-modal-close'        => ['label' => 'Modal-Schließen',       'props' => ['color' => 'rgba(13,11,9,0.40)', 'border' => '#EDE9E0']],
                'tr-floor-plan-toggle'  => ['label' => 'Raumplan-Toggle',        'props' => ['color' => 'rgba(13,11,9,0.40)']],
                'tr-marker-label'       => ['label' => 'Marker-Label',           'props' => ['color' => '#0D0B09', 'bg' => 'rgba(255,255,255,0.9)', 'border' => '#EDE9E0']],
                'tix-table-btn'         => ['label' => 'Tischreservierung-Btn',  'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
            ],
            'Seatmap Picker' => [
                'tix-sp-stage'          => ['label' => 'Bühne',                  'props' => ['bg' => '#1e293b', 'color' => '#94a3b8']],
                'tix-sp-legend-item'    => ['label' => 'Legende',                'props' => ['color' => '#94a3b8']],
                'tix-sp-section-header' => ['label' => 'Sektions-Header',        'props' => ['color' => '#ffffff']],
                'tix-sp-selection-header'=> ['label' => 'Auswahl-Header',        'props' => ['color' => '#ffffff']],
                'tix-sp-selected-tag'   => ['label' => 'Auswahl-Tag',            'props' => ['bg' => 'rgba(255,85,0,0.15)', 'color' => '#FF5500']],
                'tix-sp-timer'          => ['label' => 'Timer',                  'props' => ['color' => '#f59e0b']],
                'tix-sp-loading'        => ['label' => 'Laden-Text',             'props' => ['color' => '#94a3b8']],
                'tix-sp-modal-header'   => ['label' => 'Modal-Titel',            'props' => ['color' => '#ffffff']],
                'tix-sp-modal-summary'  => ['label' => 'Modal-Info',             'props' => ['color' => '#94a3b8']],
                'tix-sp-modal-confirm'  => ['label' => 'Bestätigen-Button',      'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-sp-modal'          => ['label' => 'Modal',                  'props' => ['bg' => '#111111', 'color' => '#ffffff']],
                'tix-sp-modal-close'    => ['label' => 'Modal-Schließen',       'props' => ['color' => '#666666']],
                'tix-sp-modal-overlay'  => ['label' => 'Modal-Overlay',          'props' => ['bg' => 'rgba(0,0,0,0.55)']],
                'tix-sp-modal-footer'   => ['label' => 'Modal-Footer',           'props' => ['bg' => '#0a0a0a']],
                'tix-sp-modal-total'    => ['label' => 'Modal-Gesamt',           'props' => ['color' => '#ffffff']],
                'tix-sp-legend-dot'     => ['label' => 'Legende-Punkt',          'props' => ['bg' => '#475569']],
                'tix-sp-legend-price'   => ['label' => 'Legende-Preis',          'props' => ['color' => '#ffffff']],
                'tix-sp-row-label'      => ['label' => 'Reihen-Label',           'props' => ['color' => '#94a3b8']],
                'tix-sp-seat'           => ['label' => 'Sitzplatz',              'props' => ['color' => '#64748b']],
                'tix-sp-overview'       => ['label' => 'Übersicht',             'props' => ['bg' => 'rgba(255,255,255,0.03)']],
                'tix-sp-ov-stage'       => ['label' => 'Übersicht-Bühne',      'props' => ['bg' => '#1e293b', 'color' => '#94a3b8']],
                'tix-sp-ov-block'       => ['label' => 'Übersicht-Block',       'props' => ['color' => '#ffffff']],
                'tix-sp-ov-selected'    => ['label' => 'Übersicht-Auswahl',     'props' => ['color' => '#ffffff', 'bg' => 'rgba(0,0,0,0.2)']],
                'tix-sp-ov-unused'      => ['label' => 'Übersicht-Ungenutzt',   'props' => ['border' => 'rgba(255,255,255,0.1)']],
                'tix-sp-spinner'        => ['label' => 'Spinner',                'props' => ['border' => '#333333']],
                'tix-sel-btn-seatmap'   => ['label' => 'Seatmap öffnen',        'props' => ['color' => '#FF5500', 'border' => '#FF5500']],
                'tix-sel-btn-best-available' => ['label' => 'Beste Plätze',     'props' => ['color' => '#f59e0b', 'border' => '#f59e0b']],
                'tix-sel-seatmap-selected' => ['label' => 'Seatmap-Auswahl',    'props' => ['bg' => '#1a1a1a', 'border' => '#333333']],
                'tix-sel-seatmap-selected-header' => ['label' => 'Seatmap-Header', 'props' => ['color' => '#ffffff']],
                'tix-sel-seatmap-selected-tag' => ['label' => 'Seatmap-Tag',    'props' => ['color' => '#FF5500', 'bg' => 'rgba(255,85,0,0.15)']],
            ],
            'FAQ' => [
                'tix-faq'          => ['label' => 'FAQ',          'props' => ['color' => '#ffffff']],
                'tix-faq-list'     => ['label' => 'FAQ-Liste',    'props' => ['border' => '#333333']],
                'tix-faq-item'     => ['label' => 'FAQ-Eintrag',  'props' => ['border' => '#333333']],
                'tix-faq-question' => ['label' => 'Frage',        'props' => ['bg' => '#1a1a1a', 'color' => '#ffffff']],
                'tix-faq-icon'     => ['label' => 'Icon',         'props' => ['color' => '#ffffff']],
                'tix-faq-answer'   => ['label' => 'Antwort',      'props' => ['color' => '#aaaaaa']],
                'tix-faq-a-inner'  => ['label' => 'Antwort-Text',  'props' => ['color' => '#ffffff']],
            ],
            'Timetable' => [
                'tix-tt-day'            => ['label' => 'Tag-Button',             'props' => ['bg' => '#ffffff', 'color' => '#1a1a1a', 'border' => '#e5e7eb']],
                'tix-tt-filter-btn'     => ['label' => 'Filter-Button',          'props' => ['bg' => '#ffffff', 'color' => '#1a1a1a', 'border' => '#e5e7eb']],
                'tix-tt-grid-header'    => ['label' => 'Grid-Header',            'props' => ['bg' => '#FAF8F4']],
                'tix-tt-time'           => ['label' => 'Uhrzeit',                'props' => ['color' => '#64748b']],
                'tix-tt-slot-title'     => ['label' => 'Slot-Titel',             'props' => ['color' => '#0D0B09']],
                'tix-tt-slot-desc'      => ['label' => 'Slot-Beschreibung',      'props' => ['color' => '#64748b']],
                'tix-tt-list-title'     => ['label' => 'Listen-Titel',           'props' => ['color' => '#0D0B09']],
                'tix-tt-list-time'      => ['label' => 'Listen-Zeit',            'props' => ['color' => '#64748b']],
                'tix-tt-list-desc'      => ['label' => 'Listen-Beschreibung',    'props' => ['color' => '#64748b']],
                'tix-tt-list-stage'     => ['label' => 'Bühne',                  'props' => ['color' => '#FF5500']],
                'tix-tt-tba'            => ['label' => 'TBA-Label',              'props' => ['color' => '#64748b']],
                'tix-tt-grid'           => ['label' => 'Grid',                   'props' => ['bg' => '#e5e7eb', 'border' => '#e5e7eb']],
                'tix-tt-slot'           => ['label' => 'Slot',                   'props' => ['bg' => '#FF5500']],
                'tix-tt-slot-time'      => ['label' => 'Slot-Zeit',              'props' => ['color' => '#FF5500']],
                'tix-tt-stage-header'   => ['label' => 'Bühnen-Header',         'props' => ['color' => '#FF5500', 'bg' => '#FAF8F4']],
                'tix-tt-time-header'    => ['label' => 'Zeit-Header',            'props' => ['bg' => '#FAF8F4']],
                'tix-tt-list-item'      => ['label' => 'Listen-Eintrag',         'props' => ['bg' => '#FF5500']],
            ],
            'Share' => [
                'tix-share-label'       => ['label' => 'Teilen-Label',           'props' => ['color' => '#1a1a1a']],
                'tix-share-btn'         => ['label' => 'Teilen-Button',          'props' => ['bg' => '#ffffff', 'color' => '#64748b', 'border' => '#e5e7eb']],
                'tix-share-btn--copied' => ['label' => 'Kopiert-Button',         'props' => ['color' => '#16a34a', 'bg' => '#f0fdf4', 'border' => '#16a34a']],
            ],
            'Raffle' => [
                'tix-raffle-title'          => ['label' => 'Titel',              'props' => ['color' => '#0D0B09']],
                'tix-raffle-desc'           => ['label' => 'Beschreibung',       'props' => ['color' => '#334155']],
                'tix-raffle-prizes-title'   => ['label' => 'Preise-Titel',       'props' => ['color' => '#FF5500']],
                'tix-raffle-prize-badge'    => ['label' => 'Preis-Badge',        'props' => ['bg' => '#dbeafe', 'color' => '#1d4ed8']],
                'tix-raffle-countdown'      => ['label' => 'Countdown',          'props' => ['bg' => '#fefce8', 'color' => '#854d0e']],
                'tix-raffle-consent'        => ['label' => 'Einwilligung',       'props' => ['color' => '#334155']],
                'tix-raffle-msg'            => ['label' => 'Meldung',            'props' => ['color' => '#334155']],
                'tix-raffle-count'          => ['label' => 'Teilnehmer-Zähler', 'props' => ['color' => '#64748b']],
                'tix-raffle-success-title'  => ['label' => 'Erfolgs-Titel',      'props' => ['color' => '#166534']],
                'tix-raffle-success-text'   => ['label' => 'Erfolgs-Text',       'props' => ['color' => '#15803d']],
                'tix-raffle'                => ['label' => 'Gewinnspiel-Box',    'props' => ['bg' => '#ffffff', 'border' => '#e5e7eb']],
                'tix-raffle-header'         => ['label' => 'Header',             'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-raffle-submit'         => ['label' => 'Absenden-Button',    'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-raffle-closed'         => ['label' => 'Geschlossen-Text',   'props' => ['color' => '#64748b']],
                'tix-raffle-msg--success'   => ['label' => 'Erfolgs-Meldung',    'props' => ['color' => '#065f46', 'bg' => '#ecfdf5', 'border' => '#a7f3d0']],
                'tix-raffle-msg--error'     => ['label' => 'Fehler-Meldung',     'props' => ['color' => '#991b1b', 'bg' => '#fef2f2', 'border' => '#fecaca']],
                'tix-raffle-prize-name'     => ['label' => 'Preis-Name',         'props' => ['color' => '#334155']],
                'tix-raffle-prize-qty'      => ['label' => 'Preis-Anzahl',       'props' => ['color' => '#FF5500']],
                'tix-raffle-winner'         => ['label' => 'Gewinner-Box',        'props' => ['bg' => '#f0fdf4', 'border' => '#bbf7d0']],
                'tix-raffle-winner-name'    => ['label' => 'Gewinner-Name',       'props' => ['color' => '#166534']],
                'tix-raffle-winner-prize'   => ['label' => 'Gewinner-Preis',      'props' => ['color' => '#15803d']],
                'tix-raffle-winners-title'  => ['label' => 'Gewinner-Titel',      'props' => ['color' => '#0D0B09']],
            ],
            'Exit Intent' => [
                'tix-ei-headline'       => ['label' => 'Überschrift',            'props' => ['color' => '#0D0B09']],
                'tix-ei-text'           => ['label' => 'Text',                   'props' => ['color' => 'rgba(0,0,0,0.55)']],
                'tix-ei-button'         => ['label' => 'Button',                 'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-ei-modal'          => ['label' => 'Modal',                  'props' => ['bg' => '#ffffff']],
                'tix-ei-overlay'        => ['label' => 'Overlay',                'props' => ['bg' => 'rgba(0,0,0,0.55)']],
                'tix-ei-close'          => ['label' => 'Schließen-Button',      'props' => ['color' => 'rgba(0,0,0,0.35)']],
                'tix-ei-code'           => ['label' => 'Code-Text',              'props' => ['color' => '#0D0B09']],
                'tix-ei-coupon'         => ['label' => 'Gutschein-Box',          'props' => ['bg' => 'rgba(0,0,0,0.04)', 'border' => '#cccccc']],
                'tix-ei-coupon--copied' => ['label' => 'Gutschein kopiert',      'props' => ['bg' => 'rgba(76,175,80,0.08)', 'border' => '#4caf50']],
                'tix-ei-copy-icon'      => ['label' => 'Kopieren-Icon',          'props' => ['color' => 'rgba(0,0,0,0.3)']],
            ],
            'Social Proof' => [
                'tix-sp'                => ['label' => 'Social Proof',           'props' => ['bg' => '#ffffff', 'color' => '#0D0B09', 'border' => '#e5e7eb']],
                'tix-sp__dot'           => ['label' => 'Live-Punkt',             'props' => ['bg' => '#4caf50']],
                'tix-sp__icon'          => ['label' => 'Icon',                   'props' => ['color' => '#FF5500']],
            ],
            'Register Event' => [
                'tix-re-title'          => ['label' => 'Titel',                  'props' => ['color' => '#ffffff']],
                'tix-re-subtitle'       => ['label' => 'Untertitel',             'props' => ['color' => '#94a3b8']],
                'tix-re-panel-title'    => ['label' => 'Panel-Titel',            'props' => ['color' => '#ffffff']],
                'tix-re-bubble'         => ['label' => 'Chat-Blase',             'props' => ['bg' => 'rgba(255,255,255,0.06)', 'color' => '#ffffff']],
                'tix-re-input'          => ['label' => 'Eingabefeld',            'props' => ['bg' => 'rgba(255,255,255,0.06)', 'color' => '#ffffff', 'border' => 'rgba(255,255,255,0.12)']],
                'tix-re-field-label'    => ['label' => 'Feld-Label',             'props' => ['color' => '#94a3b8']],
                'tix-re-preview-label'  => ['label' => 'Preview-Label',          'props' => ['color' => '#94a3b8']],
                'tix-re-preview-value'  => ['label' => 'Preview-Wert',           'props' => ['color' => '#ffffff']],
                'tix-re-legal'          => ['label' => 'Rechtliches',            'props' => ['color' => '#94a3b8']],
                'tix-re-btn-primary'    => ['label' => 'Haupt-Button',           'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-re-error'          => ['label' => 'Fehler-Meldung',         'props' => ['color' => '#f87171']],
                'tix-re'                => ['label' => 'Register Event',         'props' => ['color' => '#ffffff']],
                'tix-re-btn-back'       => ['label' => 'Zurück-Button',         'props' => ['color' => '#94a3b8']],
                'tix-re-btn-publish'    => ['label' => 'Veröffentlichen',       'props' => ['bg' => '#ff00aa', 'color' => '#ffffff']],
                'tix-re-btn-send'       => ['label' => 'Senden-Button',          'props' => ['color' => '#ffffff']],
                'tix-re-bubble-user'    => ['label' => 'User-Blase',             'props' => ['color' => '#ffffff']],
                'tix-re-dropzone'       => ['label' => 'Upload-Zone',            'props' => ['border' => '#FF5500']],
                'tix-re-dropzone-text'  => ['label' => 'Upload-Text',            'props' => ['color' => '#94a3b8']],
                'tix-re-dropzone-hint'  => ['label' => 'Upload-Hinweis',         'props' => ['color' => '#94a3b8']],
                'tix-re-mode'           => ['label' => 'Modus-Toggle',           'props' => ['color' => '#ffffff', 'border' => '#FF5500']],
                'tix-re-progress-fill'  => ['label' => 'Fortschritt-Balken',     'props' => ['bg' => '#ff00aa']],
                'tix-re-progress-step'  => ['label' => 'Fortschritt-Schritt',    'props' => ['color' => '#10b981']],
                'tix-re-step'           => ['label' => 'Schritt',                'props' => ['color' => '#10b981']],
                'tix-re-status-simple'  => ['label' => 'Status-Text',            'props' => ['color' => '#94a3b8']],
            ],
            'My Account' => [
                'wc-nav-link'           => ['label' => 'Navigation',             'props' => ['color' => '#374151']],
                'wc-content-h2'         => ['label' => 'Überschrift H2',         'props' => ['color' => '#1f2937']],
                'wc-content-h3'         => ['label' => 'Überschrift H3',         'props' => ['color' => '#374151']],
                'wc-table-th'           => ['label' => 'Tabellen-Header',        'props' => ['bg' => '#f9fafb', 'color' => '#374151']],
                'wc-table-td'           => ['label' => 'Tabellen-Zelle',         'props' => ['color' => '#374151', 'border' => '#e5e7eb']],
                'wc-order-status'       => ['label' => 'Bestell-Status',         'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'wc-button'             => ['label' => 'Button',                 'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'wc-label'              => ['label' => 'Formular-Label',         'props' => ['color' => '#374151']],
                'wc-input'              => ['label' => 'Eingabefeld',            'props' => ['border' => '#e5e7eb', 'color' => '#1f2937']],
                'wc-message'            => ['label' => 'Meldung',                'props' => ['color' => '#374151']],
            ],
            'Minicart' => [
                'tix-minicart-count'   => ['label' => 'Warenkorb-Zähler',  'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-mc-drawer'        => ['label' => 'Drawer',            'props' => ['bg' => '#ffffff', 'color' => '#1a1a1a']],
                'tix-mc-drawer-header' => ['label' => 'Drawer-Header',     'props' => ['border' => '#e5e5e5']],
                'tix-mc-drawer-title'  => ['label' => 'Drawer-Titel',      'props' => ['color' => '#1a1a1a']],
                'tix-mc-item'          => ['label' => 'Artikel',            'props' => ['border' => '#e5e5e5']],
                'tix-mc-item-name'     => ['label' => 'Artikel-Name',       'props' => ['color' => '#1a1a1a']],
                'tix-mc-item-price'    => ['label' => 'Artikel-Preis',      'props' => ['color' => '#1a1a1a']],
                'tix-mc-qty-btn'       => ['label' => 'Mengen-Button',      'props' => ['border' => '#e5e5e5']],
                'tix-mc-item-remove'   => ['label' => 'Entfernen-Button',   'props' => ['color' => '#999999']],
                'tix-mc-total-row'     => ['label' => 'Gesamt-Zeile',       'props' => ['color' => '#1a1a1a']],
                'tix-mc-footer'        => ['label' => 'Footer',             'props' => ['border' => '#e5e5e5']],
                'tix-mc-checkout-btn'  => ['label' => 'Checkout-Button',    'props' => ['bg' => '#c8ff00', 'color' => '#000000']],
                'tix-mc-drawer-close'  => ['label' => 'Drawer-Schließen',  'props' => ['color' => '#1a1a1a']],
                'tix-mc-item-hint'     => ['label' => 'Artikel-Hinweis',    'props' => ['color' => '#999999']],
                'tix-mc-qty-val'       => ['label' => 'Mengen-Wert',        'props' => ['color' => '#1a1a1a']],
                'tix-mc-overlay-wrap'  => ['label' => 'Overlay',             'props' => ['bg' => 'rgba(0,0,0,0.35)']],
            ],
            'Feedback' => [
                'tix-fb-title'         => ['label' => 'Titel',             'props' => ['color' => '#0D0B09']],
                'tix-fb-subtitle'      => ['label' => 'Untertitel',        'props' => ['color' => '#64748b']],
                'tix-fb-star'          => ['label' => 'Stern (inaktiv)',   'props' => ['color' => '#d1d5db']],
                'tix-fb-comment'       => ['label' => 'Kommentarfeld',     'props' => ['bg' => '#ffffff', 'border' => '#e5e7eb', 'color' => '#1a1a1a']],
                'tix-fb-submit'        => ['label' => 'Absenden-Button',   'props' => ['bg' => '#FF5500', 'color' => '#ffffff']],
                'tix-fb-thanks-title'  => ['label' => 'Danke-Titel',      'props' => ['color' => '#065f46']],
                'tix-fb-thanks-text'   => ['label' => 'Danke-Text',       'props' => ['color' => '#334155']],
                'tix-fb-avg-value'     => ['label' => 'Bewertungs-Wert',  'props' => ['color' => '#0D0B09']],
                'tix-fb-avg-count'     => ['label' => 'Anzahl',           'props' => ['color' => '#64748b']],
                'tix-fb-avg-star'      => ['label' => 'Stern (aktiv)',      'props' => ['color' => '#fbbf24']],
                'tix-fb-msg--error'    => ['label' => 'Fehler-Meldung',    'props' => ['color' => '#991b1b', 'bg' => '#fef2f2', 'border' => '#fecaca']],
                'tix-ep-rating-count'  => ['label' => 'Rating-Anzahl',     'props' => ['color' => '#64748b']],
            ],
            'Upsell' => [
                'tix-up-card'           => ['label' => 'Upsell-Karte',          'props' => ['border' => '#333333', 'bg' => '#1a1a1a']],
                'tix-up-heading'        => ['label' => 'Überschrift',            'props' => ['color' => '#ffffff']],
                'tix-up-card-title'     => ['label' => 'Karten-Titel',           'props' => ['color' => '#ffffff']],
                'tix-up-card-meta'      => ['label' => 'Meta-Info',              'props' => ['color' => 'rgba(255,255,255,0.2)']],
                'tix-up-card-price'     => ['label' => 'Preis',                  'props' => ['color' => '#8b5cf6']],
                'tix-up-card-badge'     => ['label' => 'Badge',                  'props' => ['bg' => 'rgba(245,158,11,0.85)', 'color' => '#000000']],
                'tix-up-badge-few_tickets' => ['label' => 'Badge: Wenige',      'props' => ['bg' => 'rgba(245,158,11,0.85)', 'color' => '#000000']],
                'tix-up-badge-postponed'   => ['label' => 'Badge: Verschoben',  'props' => ['bg' => 'rgba(59,130,246,0.85)']],
                'tix-up-card-img'       => ['label' => 'Karten-Bild',           'props' => ['bg' => 'rgba(255,255,255,0.03)']],
                'tix-up-card-noimg'     => ['label' => 'Kein-Bild-Icon',        'props' => ['color' => 'rgba(255,255,255,0.2)']],
            ],
        ];
    }

    public static function color_class_registry_flat(): array {
        $flat = [];
        foreach (self::color_class_registry() as $classes) {
            foreach ($classes as $cls => $def) {
                $flat[$cls] = $def;
            }
        }
        return $flat;
    }
}
